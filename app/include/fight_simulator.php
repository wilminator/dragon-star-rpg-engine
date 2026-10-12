<?php
require_once INCLUDE_GAME . 'database.php';
require_once INCLUDE_GAME . 'fight.php';
require_once INCLUDE_GAME . 'fight_communicator.php';
require_once INCLUDE_GAME . 'fight_save.php';
require_once INCLUDE_GAME . 'character.php';
require_once INCLUDE_GAME . 'job.php';
require_once INCLUDE_GAME . 'personality.php';
require_once INCLUDE_GAME . 'monster.php';
require_once INCLUDE_GAME . 'group.php';
require_once INCLUDE_GAME . 'party.php';

/**
 * FightSimulator is the shared core behind two different needs: a
 * bulk seed-data generator (random simulated fights, stored for real
 * and tagged via FightSave) and a stress test (the same generation,
 * comparing a live in-memory fight against a database reconstruction
 * after every round). Both consume the same random generation and the
 * same simulate-to-completion loop; only what happens with each
 * fight's result differs, and that's left to the caller.
 *
 * Every generated fight and every round of it runs through
 * FightCommunicator's real, live-traffic methods
 * (start_fight()/simulate_round()) -- nothing here is a parallel or
 * simplified path. A bug in real gameplay processing would surface
 * here too, which is exactly the point of using this for the
 * stress-test side.
 */
class FightSimulator {
    // Small, unremarkable pool -- good enough to make generated
    // parties look like a roster rather than a wall of "Hero1",
    // "Hero2"; not meant to be exhaustive or lore-accurate.
    private const NAMES = [
        'Aldric', 'Branwen', 'Corin', 'Dessa', 'Eamon', 'Faelan', 'Gwyneth', 'Halvard',
        'Isolde', 'Joran', 'Kestrel', 'Liora', 'Maren', 'Nyssa', 'Orin', 'Perrin',
        'Quinlan', 'Roswen', 'Saoirse', 'Torvald', 'Una', 'Varric', 'Wren', 'Yorick',
    ];

    private static function random_name(): string {
        return self::NAMES[array_rand(self::NAMES)];
    }

    // Every currently-active job -- the pool random_hero() picks
    // from. Cached per-request (JOB::load_all_from_cache() already
    // caches at the file level, but filtering is repeated work not
    // worth doing once per generated character).
    private static ?array $active_jobs = null;
    private static function active_jobs(): array {
        if (self::$active_jobs === null) {
            self::$active_jobs = array_values(array_filter(
                JOB::load_all_from_cache(),
                fn($job) => $job->state === ObjectState::ACTIVE
            ));
        }
        return self::$active_jobs;
    }

    private static ?array $active_personalities = null;
    private static function active_personalities(): array {
        if (self::$active_personalities === null) {
            // The shared test fixture set has no personalities.php at
            // all (confirmed: no generator logic, no README entry for
            // it, unlike abilities/items/jobs) -- create_hero() already
            // accepts a null personalityid gracefully, so this falls
            // back to an empty pool rather than fail outright in that
            // environment.
            try {
                self::$active_personalities = array_values(array_filter(
                    PERSONALITY::load_all_from_cache(),
                    fn($p) => $p->state === ObjectState::ACTIVE
                ));
            } catch (\Throwable $e) {
                self::$active_personalities = [];
            }
        }
        return self::$active_personalities;
    }

    // One random hero: random name, a random active job, a random
    // personality (or none -- create_hero() itself accepts null), a
    // random level in [$min_level, $max_level]. Throws if there are no
    // active jobs at all to build one from -- a genuinely unusable
    // environment for this, not a case worth papering over.
    public static function random_hero(int $min_level, int $max_level, int $teamid): CHARACTER {
        $jobs = self::active_jobs();
        if (count($jobs) === 0) {
            throw new \Exception("No active jobs available to generate a random hero from.");
        }
        $job = $jobs[array_rand($jobs)];
        $personalities = self::active_personalities();
        $personalityid = count($personalities) > 0 ? $personalities[array_rand($personalities)]->personalityid : null;
        $level = random_int($min_level, $max_level);

        $character = CHARACTER::create_hero(self::random_name(), $job->jobid, $level, $personalityid);
        $character->teamid = $teamid;
        return $character;
    }

    // One random party of heroes: $character_count characters, spread
    // across groups of up to GROUP::MAX_COUNT each (never more than
    // PARTY_MAX_COUNT groups -- a party genuinely can't hold more).
    public static function random_party(int $character_count, int $min_level, int $max_level, int $teamid): PARTY {
        $party = new PARTY();
        $group = new GROUP();
        for ($i = 0; $i < $character_count; $i++) {
            if ($group->count() >= GROUP::MAX_COUNT) {
                $party->add($group);
                if (count($party->groups) >= PARTY_MAX_COUNT) {
                    break; // party is genuinely full; excess characters are simply not added
                }
                $group = new GROUP();
            }
            $group->add_hero(self::random_hero($min_level, $max_level, $teamid));
        }
        if ($group->count() > 0) {
            $party->add($group);
        }
        return $party;
    }

    // Assembles one whole random fight: 2-3 hero parties (PvP) or 1-2
    // hero parties against a monster party (PvPvE), chosen randomly
    // each time so a generated batch naturally covers both. Party
    // sizes and levels vary within modest, playable ranges rather than
    // spanning the entire possible space -- this is about generating
    // representative fights, not edge-case stress on party assembly
    // itself (do_combat()/set_PC_action() already have their own
    // dedicated coverage for that).
    //
    // Returns ['fight' => FIGHT, 'players' => array] -- $players in
    // the same shape start_fight() takes, one entry per hero party
    // (teamid = the party's own teamid, sequential
    // player_party/playerid so each generated party is distinguishable
    // -- these playerids are never real accounts, purely bookkeeping
    // for FightCommunicator's own party-ownership checks during
    // simulation).
    public static function random_fight(): array {
        $fight = new FIGHT('bg.png', 'music.mp3');
        $players = [];
        $pvp = random_int(0, 1) === 1;
        $hero_party_count = $pvp ? random_int(2, 3) : random_int(1, 2);

        for ($i = 0; $i < $hero_party_count; $i++) {
            $teamid = 1000 + $i; // never a real team's id
            $character_count = random_int(1, 4);
            $level = random_int(1, 10);
            $party = self::random_party($character_count, max(1, $level - 2), $level + 2, $teamid);
            $fight->add($party);
            $players[] = ['playerid' => -(1000 + $i), 'teamid' => $teamid, 'player_party' => $i]; // negative: never collides with a real playerid
        }

        if (!$pvp) {
            $player_pxp = 0;
            $player_qty = 0;
            foreach ($fight->parties as $party) {
                $player_pxp += $party->get_pxp();
                $player_qty += count($party->get_character_list(null));
            }
            $monster_qty = random_int(1, max(1, $player_qty + 2));
            $fight->add(MONSTER::create_monster_party(max(1, $player_pxp), max(1, $player_qty), $monster_qty));
        }

        return ['fight' => $fight, 'players' => $players];
    }

    // Runs simulate_round() until the fight resolves or $max_rounds is
    // hit (a safety valve against a pathological, never-resolving
    // generated matchup -- e.g. every character reduced to 0 damage
    // output against the other side -- rather than looping forever).
    // Returns fightid, rounds actually played, whether it resolved
    // within the cap, and which party index survived (null if every
    // party was wiped in the same round -- rare, but a real possible
    // outcome of do_combat()'s sequential-not-simultaneous turn order,
    // not treated as an error).
    public static function simulate_to_completion(Database $db, FIGHT $fight, array $players, int $max_rounds = 50): array {
        $fightid = FightCommunicator::start_fight($db, $fight, $players);

        $rounds_played = 0;
        while ($rounds_played < $max_rounds) {
            FightCommunicator::simulate_round($db, $fightid);
            $rounds_played++;
            $semaphore = FightSemaphore::load($db, $fightid);
            if ($semaphore->state === FightStatus::Resolved) {
                break;
            }
        }

        $final = FIGHT::load_current($db, $fightid);
        $winning_party_index = null;
        foreach ($final->parties as $index => $party) {
            if (!$party->is_dead()) {
                $winning_party_index = $index;
                break;
            }
        }

        return [
            'fightid' => $fightid,
            'rounds_played' => $rounds_played,
            'resolved' => $final->count_live_parties() < 2,
            'winning_party_index' => $winning_party_index,
            'fight' => $final,
        ];
    }
}
