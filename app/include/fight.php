<?php
require_once INCLUDE_GAME . 'location.php';
require_once INCLUDE_GAME . 'party.php';
require_once INCLUDE_GAME . 'pointer_index.php';
require_once INCLUDE_GAME . 'action_target.php';
require_once INCLUDE_GAME . 'ai.php';
require_once INCLUDE_GAME . 'fight_action.php';
require_once INCLUDE_GAME . 'pending_action.php';

/**
 * fight.php
 * fight object
 * @version 0.1.0
 * @copyright 2003 Mike Wilmes
 **/

// Fights don't retire the way other DbStoreTrait entities do (active/
// inactive via ObjectState) -- they move through three real states:
// waiting on a player's command, a turn actively being computed, or
// concluded. Specific to FIGHT, not shared with ObjectState's users.
enum FightStatus: int {
    case WaitingForInput = 0;
    case Processing = 1;
    case Resolved = 2;
}

class FIGHT implements ActionScope, DehydrateInterface {
    use DehydrateTrait {
        hydrate as private hydrate_common;
    }
    use DbStoreTrait {
        save as private save_row;
    }

    public ?int $id;
    public array $parties;
    public array $location;
    public int $counter;
    public string $background;
    public string $music;
    // Set once, at creation, and never touched again after -- FIGHT's
    // own dehydrated base state. Nullable because it isn't known (and
    // couldn't be, before an id exists) until the very first save();
    // null on any FIGHT that predates this column, until re-saved.
    public ?array $initial_state = null;
    // Set once, at construction, and included in $save_columns below
    // so it's written on insert and never touched again -- confirmed
    // needed directly: nothing anywhere recorded when a fight was
    // created, which the admin fight-browser's own timestamp filter
    // needs. A plain Unix timestamp (int), matching the convention
    // FightSemaphore's own timeout/lock_expires_at already use,
    // rather than a SQL datetime/timestamp column.
    public int $created_at;
    // Set once, at construction, and included in $save_columns below --
    // an unguessable public identifier for this fight, distinct from
    // its sequential id (which would let a share link be walked to
    // enumerate every other fight in the system just by changing a
    // number in the URL). Used for init_fight.php's own guid-bypass
    // playback path: a login-free, shareable link to watch one
    // specific fight, keyed by this rather than id.
    public string $guid;
    // Whether non-admins may spectate this fight: live, and as a replay
    // seated with any hero party. start_fight() also hands it to the
    // fight's semaphore, which live viewing checks; the semaphore is
    // purged once the fight resolves, so it's saved with the fight too
    // (in initial_state -- see dehydrate_nested()/hydrate()), where
    // replays read it. Fights saved before it was recorded read as not
    // spectatable.
    public bool $spectatable = false;
    // A practice fight (see PracticeFight): AI heroes cloned from real
    // teams, fought for the monster player's sake. The heroes' teams get
    // nothing from it and nothing about them is saved.
    public bool $practice = false;
    // Round time, as a multiple of the usual command-entry time: the
    // highest any player in the fight asked for (settings), fixed when
    // the fight starts. See FightSetup::apply_time_settings().
    public float $time_multiplier = 1.0;

    function __construct(string $background, string $music, bool $spectatable = false) {
        $this->spectatable = $spectatable;
        $this->parties = array();
        $this->location = array();
        $this->counter = 0;
        $this->id = null;
        $this->background = $background;
        $this->music = $music;
        $this->created_at = time();
        $this->guid = bin2hex(random_bytes(16));
    }

    function add(PARTY $party) {
        $this->parties[] = $party;
        if ($party->name == '')
            $party->name = $party->groups[0]->characters[0]->name . "'s party";
        //Clear our xp debts.
        foreach (array_keys($party->groups) as $group)
            foreach (array_keys($party->groups[$group]->characters) as $character) {
                $party->groups[$group]->characters[$character]->xp_debts = array();
                $party->groups[$group]->characters[$character]->old_exp = $party->groups[$group]->characters[$character]->exp;
            }
        return false;
    }

    function count(): int {
        return count($this->parties);
    }

    function to_js(): string {
        return json_encode($this);
    }

    const DEHYDRATE_VERSION = 1;

    // counter excluded: no longer FIGHT's own progress record (that's
    // the semaphore/lock-management class's sequence now, the actual
    // source of truth for how many turns a fight has had) -- counter
    // is purely a local, in-memory check that process_fight_action()
    // is being fed FightActions in the right order during one replay
    // pass. Never persisted, never round-tripped: hydrate() below
    // always starts it at 0 regardless of what a hydrated FIGHT is
    // reconstructed from, and nothing reads it once the object it
    // belongs to goes away.
    private const DEHYDRATE_PROPERTIES = ['id', 'location', 'background', 'music'];

    // spectatable rides along here rather than in DEHYDRATE_PROPERTIES,
    // which would make it required: fights saved before it existed
    // don't carry it (see hydrate()).
    protected function dehydrate_nested(): array {
        return [
            'parties' => array_map(fn($party) => $party->dehydrate(), $this->parties),
            'spectatable' => $this->spectatable,
            'practice' => $this->practice,
            'time_multiplier' => $this->time_multiplier,
        ];
    }

    protected static function hydrate_nested(self $object, array $data): void {
        $object->parties = array_map(fn($d) => PARTY::hydrate($d), $data['parties']);
    }

    // hydrate_common() (the trait's own hydrate(), aliased in above)
    // bypasses the constructor entirely; since counter isn't in
    // DEHYDRATE_PROPERTIES, it'd otherwise be left genuinely
    // uninitialized rather than reset to a sensible starting point.
    // Unconditional -- no version branch needed here the way
    // CHARACTER's command/using/target once needed one, since no
    // legacy data ever had a meaningful stored counter value worth
    // preserving in the first place.
    static function hydrate(array $data): static {
        $object = static::hydrate_common($data);
        $object->counter = 0;
        $object->spectatable = (bool) ($data['spectatable'] ?? false);
        $object->practice = (bool) ($data['practice'] ?? false);
        $object->time_multiplier = (float) ($data['time_multiplier'] ?? 1.0);
        return $object;
    }

    protected static string $table_name = 'fights';
    protected static string $table_key = 'id';
    protected static string $drop_sql = "DROP TABLE IF EXISTS fights";
    // id only: fight_data/sequence/state used to ride alongside it,
    // but all three are gone now -- fight_data because a stored
    // current-state snapshot is exactly what initial_state + the
    // FightAction log made redundant in the first place (see
    // load_current() below, which reconstructs current state by
    // replay instead of ever storing it a second time); sequence and
    // state because they moved to FightSemaphore, coordination
    // concerns FIGHT itself no longer needs to track at all.
    protected static array $save_columns = ['id', 'created_at', 'guid'];

    protected static function create_query(Database $db): string {
        $id_column = $db->auto_increment_primary_key('id');
        return <<<QUERY
CREATE TABLE IF NOT EXISTS fights (
  $id_column,
  initial_state text NULL,
  created_at int NOT NULL DEFAULT 0,
  guid varchar(32) NOT NULL DEFAULT ''
);
QUERY;
    }

    // Pure reformatting, per DbStoreTrait's own convention -- but note
    // what this reconstructs: initial_state, FIGHT's starting
    // snapshot, never replayed forward. That's the correct, and only,
    // meaning load() (this method's caller, inherited from
    // DbStoreTrait) can have now that there's no stored current-state
    // column left to read instead. Anything needing the fight as it
    // actually stands right now -- FightCommunicator included -- wants
    // load_current() below, not this.
    protected static function translate_and_build(array $data): static {
        $fight = static::hydrate(json_decode($data['initial_state'], true));
        $fight->id = (int)$data['id'];
        $fight->initial_state = $data['initial_state'] === null ? null : json_decode($data['initial_state'], true);
        $fight->created_at = (int) $data['created_at'];
        $fight->guid = $data['guid'];
        return $fight;
    }

    public function translate_to_store(): array {
        return [$this->id, $this->created_at, $this->guid];
    }

    // initial_state is deliberately not part of $save_columns/
    // translate_to_store()'s normal per-column save path: unlike every
    // other column, it must be written exactly once and never touched
    // again, and the trait's own upsert mechanism has no concept of
    // "only on insert, never on update." Delegates to the trait's own
    // save() (aliased to save_row() above) for everything else, then,
    // only when this was a brand-new FIGHT (id was null before that
    // call), records initial_state separately via one direct, targeted
    // UPDATE -- using the id save_row() just assigned, since it wasn't
    // known before that call returned.
    // Confirmed directly (the only real call site in the live
    // codebase is start_fight(), on a brand-new FIGHT) plus
    // import_db.php (restoring an already-populated, unserialized
    // FIGHT): nothing ever calls save() on a fight it just loaded, so
    // there's no path where re-writing initial_state with its own
    // existing value could do any harm -- at worst a wasted UPDATE,
    // never a corruption risk. The "written once, never touched
    // again" invariant isn't enforced by checking id's nullness at
    // all, really -- it's enforced by there being no other caller.
    //
    // Written whenever it's not already present, not gated on whether
    // id was null going in: for a brand-new fight, initial_state is
    // null until dehydrate() computes it here, using the id save_row()
    // just assigned. For an imported fight, initial_state is already
    // set (embedded in the unserialized object, with its own original
    // id already baked in) -- the old id-nullness check silently
    // skipped writing it in that case, since an imported object's id
    // is never null, leaving every imported fight's initial_state
    // NULL in the freshly-created row and load_current() unable to
    // reconstruct it at all. Confirmed by direct reproduction.
    public function save(Database $db): void {
        $this->save_row($db);
        if ($this->initial_state === null) {
            $this->initial_state = $this->dehydrate();
        }
        $stmt = $db->prepare("UPDATE fights SET initial_state = ? WHERE id = ?");
        $stmt->execute([json_encode($this->initial_state), $this->id]);
    }

    // The reconstruction anything needing a fight's CURRENT state
    // actually wants, load() (see translate_and_build() above) having
    // been reduced to "the starting snapshot only" now that there's no
    // stored current-state column to read instead: hydrates that same
    // starting snapshot, then replays every FightAction recorded for
    // it, in sequence, via process_fight_action() -- bringing it
    // forward to wherever the fight actually stands right now. Returns
    // null if there's no row for this fightid, or no initial_state yet
    // to reconstruct from (a fight mid-way through its own first
    // save(), between save_row() assigning its id and the follow-up
    // UPDATE that sets initial_state -- narrow, but a real possibility
    // this checks for rather than assumes away).
    // Public share-link lookup: resolves a fight's guid back to its
    // id, or null if the guid is unrecognized -- a guessed or stale
    // guid is a normal, expected case here, not an error. Returns just
    // the id, not a loaded FIGHT: callers (init_fight.php's own
    // guid-bypass path) only need the id to stash into the session,
    // and load_current() below is the actual reconstruction anything
    // needing the fight itself wants.
    public static function find_id_by_guid(Database $db, string $guid): ?int {
        $stmt = $db->prepare("SELECT id FROM fights WHERE guid = ?");
        $stmt->execute([$guid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : (int) $row['id'];
    }

    public static function load_current(Database $db, int $fightid): ?FIGHT {
        $stmt = $db->prepare("SELECT id, initial_state, created_at FROM fights WHERE id = ?");
        $stmt->execute([$fightid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['initial_state'] === null) {
            return null;
        }
        $fight = static::hydrate(json_decode($row['initial_state'], true));
        $fight->id = (int) $row['id'];
        $fight->initial_state = json_decode($row['initial_state'], true);
        $fight->created_at = (int) $row['created_at'];
        foreach (FightAction::load_all_by_fightid($db, $fightid) as $fight_action) {
            $fight->process_fight_action($fight_action);
        }
        return $fight;
    }

    // The trait's default load_all_from_database() goes through
    // Database::get_all(), which unconditionally filters WHERE state =
    // 0 unless told otherwise -- a convention built for entities where
    // active-vs-retired is a real, ongoing distinction (ABILITY, ITEM,
    // JOB, and so on). FIGHT has no state column of its own at all
    // anymore (see $save_columns above), so that filter is simply
    // wrong here, not just inapplicable -- confirmed directly, it
    // throws outright ("no such column: state") rather than silently
    // matching everything or nothing. Overridden with a plain,
    // unfiltered SELECT * instead; $include_inactive has nothing left
    // to distinguish, so it's accepted but unused.
    public static function load_all_from_database(Database $db, bool $include_inactive = false): array {
        $stmt = $db->prepare("SELECT * FROM fights");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $objects = array_combine(
            array_column($rows, 'id'),
            array_map(fn($row) => static::translate_and_build($row), $rows)
        );
        foreach ($objects as $object) {
            static::load_related($db, $object);
        }
        return $objects;
    }

    // Deletes this fight's own row outright -- id and initial_state
    // both gone. Every real, player-owned fight instead just sits
    // Resolved forever (there's no retirement/change_object_state()
    // concept for FIGHT at all); this exists for system-generated
    // simulated fights specifically, which really do get thrown away
    // and regenerated. See FightCommunicator::delete_fight(), which
    // coordinates this alongside every other table a fight touches
    // (FightAction/FightPlayerState/FightSemaphore/PendingAction/
    // FightSave all have their own matching delete-by-fightid method).
    // Client-sanitized projection of the whole fight, scoped to
    // $own_teamid -- the actual wiring CHARACTER::to_client() itself
    // was built for but was never connected to anything (confirmed
    // directly: nothing anywhere called it). Walks every party/group/
    // character, projecting each character through to_client(),
    // $is_own_team true only for $own_teamid's own characters -- see
    // CHARACTER::to_client()'s own docblock for exactly what that
    // does and doesn't include for either case, and why.
    //
    // This is what a fight sent to the client should always go
    // through, never the raw FIGHT object directly: a raw CHARACTER,
    // freshly hydrated via replay, only has command/using/target set
    // for whichever characters happen to have a PendingAction row for
    // the current round (see apply_pending_actions()) -- every other
    // character's target is genuinely uninitialized at that point
    // (hydrate() bypasses the constructor entirely), which is exactly
    // what crashed the client's own display_target() reading
    // person.target[0] on a property that was never there to begin
    // with. to_client() sidesteps this rather than papering over
    // it: own-team characters always get a real, always-present
    // command/using/target (CHARACTER::hydrate()'s own override
    // already guarantees this, PendingAction or not), and non-own-team
    // characters never carry target at all, by design -- the client
    // was already relying on a value this method's own docblock never
    // promised for anyone but a player's own team.
    // $viewed_party is the party the viewer sits in or is watching, from
    // their fight_players row. Its heroes' classes are shown even to a
    // replay viewer, whose null team ID owns nothing -- so a team
    // replaying its own fight sees its heroes' jobs instead of "Monster".
    // $own_teamids: the viewer's team, or all of them (an alliance).
    function to_client(int|array|null $own_teamid, ?int $viewed_party = null): array {
        $parties = [];
        foreach ($this->parties as $party_index => $party) {
            $parties[$party_index] = $party->to_client($own_teamid, $party_index === $viewed_party);
        }
        return [
            'id' => $this->id,
            'location' => $this->location,
            'background' => $this->background,
            'music' => $this->music,
            'parties' => $parties,
        ];
    }

    public static function delete_by_id(Database $db, int $fightid): void {
        $stmt = $db->prepare("DELETE FROM fights WHERE id = ?");
        $stmt->execute([$fightid]);
    }

    function set_PC_action(int $player_party, int $player_group, int $player_character, int|array $player_teams, array $action): bool {
        # Validate indexes
        if (!isset($this->parties[$player_party]))
            return false;
        $party = $this->parties[$player_party];
        if (!isset($party->groups[$player_group]))
            return false;
        $group = $party->groups[$player_group];
        if (!isset($group->characters[$player_character]))
            return false;
        $fighter = $group->characters[$player_character];

        // One ownership rule for heroes and monsters alike: the
        // character must carry one of the team IDs the submitting player
        // controls (several with an alliance, never another player's --
        // see FightCommunicator::own_teamids()). Monsters
        // carry CHARACTER::MONSTER_TEAMID, which is exactly what a
        // monster-controlling player's team ID is. Which PARTY the player
        // may command is enforced by the caller against the player's own
        // player_party from fight_players.
        if (!in_array($fighter->teamid, (array) $player_teams, true))
            return false;

        //validate_action()/validate_target() only read from the
        //character's own command/target/using properties, not from
        //$action directly -- so the proposed action has to be written
        //in before it can be validated, and rolled back if it turns
        //out invalid.
        $original = [$fighter->command, $fighter->target, $fighter->using];
        list($fighter->command, $fighter->target, $fighter->using) = $action;

        if (!$fighter->validate_action($this)) {
            list($fighter->command, $fighter->target, $fighter->using) = $original;
            return false;
        }

        $effect_spec = $fighter->determine_effect_spec();
        //validate_target()'s polarity is inverted: true means abort.
        if (!is_null($effect_spec) && $fighter->validate_target($this, $effect_spec)) {
            list($fighter->command, $fighter->target, $fighter->using) = $original;
            return false;
        }

        return true;
    }

    /**
     * The mechanical half of giving one character an AI-driven action:
     * ask ai() what it should do, then apply that to the character.
     * Which characters get called this way is a decision the caller
     * makes -- init_fight.php's admin-only "play as the enemy party"
     * mode and process_fight()'s per-turn NPC pass (functions.php)
     * each select differently, but previously duplicated this same
     * apply-the-result mechanics inline in both places.
     */
    function set_npc_action(int $party_index, int $group_index, int $char_index): void {
        $character = $this->parties[$party_index]->groups[$group_index]->characters[$char_index];
        $command_key = ai($this, $party_index, $group_index, $char_index);
        list($cmd, $use, $pty, $grp, $chr) = split_index_key($command_key);
        $character->command = $cmd;
        $character->using = $use;
        $character->target = array($pty, $grp, $chr);
    }


    /*
    OK, the meat and potatoes of this- combat simulation!
    What we are doing here-
    1. Generate a list of participants (characters with more than 0 HP,) ordered
        most to least by their speed+/-15% less focus/MP if a spell or skill
        is being used.
    2. Loop through the list, giving each character an opportunity to perform
        their action.
    3. For each action, record the outcomes so the client can play them back.
    4. If any character dies, remove them from the list.
    5. Return the outcomes to the caller.
    */

    function do_combat(): array {
        //Make the fight order list.
        $participants = $this->generate_participant_list();

        //Prep the playback action list!
        $action_list = [];

        //We are ready to rumble.
        //While someone has a turn let them go!
        //Stop early if the party is only one left.
        while (
            count($participants) > 0
            && $this->count_live_parties() > 1
        ) {
            //Get the lucky person!
            $fighter_target = array_shift($participants);
            $fighter = $fighter_target->get_character($this);

            //If the person is dead, then skip.
            if ($fighter->get_current('HP') == 0)
                continue;

            # Have the fighter perform their action.
            $fighter->perform_action($this, $action_list);
        }
        return $action_list;
    }

    function generate_participant_list(): array {
        //Make the pool.  Exclude defenders and the dead.
        $participant_pool = array();
        foreach (array_keys($this->parties) as $party_index) {
            $party = $this->parties[$party_index];
            foreach (array_keys($party->groups) as $group_index) {
                $group = $party->groups[$group_index];
                foreach (array_keys($group->characters) as $char_index) {
                    $character = $group->characters[$char_index];
                    if ($character->current['HP'] > 0 && $character->command != 6) {
                        $speed = randomize($character->get_current('Speed'));
                        if ($character->command == 4 || $character->command == 5) //Using magic
                        {
                            $focus = max(0, randomize($character->get_current('Focus')));
                            $mp_used = ABILITY::get($character->abilities[$character->using])->mp_used + 1;
                            $mp_summoned = sqrt($focus);
                            $penalty_fraction = 1 / ($mp_summoned / $mp_used + 2);
                            $speed -= (int)round($speed * $penalty_fraction);
                        }
                        //If a participant uses a weapon that allows for multiple attacks,
                        //that participant will have more than one appearence.
                        if ($character->command == 0 && !is_null($character->equipment['lhand'])) //Left hand attack.
                            $times = ITEM::get($character->inventory[$character->equipment['lhand']]['item'])->attack_count;
                        elseif ($character->command == 1 && !is_null($character->equipment['rhand'])) //Right hand attack.
                            $times = ITEM::get($character->inventory[$character->equipment['rhand']]['item'])->attack_count;
                        else
                            $times = 1;
                        for ($count = 1; $count <= $times; $count++)
                            $participant_pool[(int)floor($speed * $count / $times)][] = new ActionTarget($party_index, $group_index, $char_index);
                    }
                }
            }
        }
        krsort($participant_pool);

        //The pool is now sorted from fastest to slowest.
        //Make the participants list.
        $participants = array();
        foreach ($participant_pool as $speed => $rung) {
            //A rung has characters that had the same speed rating.  Randomly
            //insert them into the list.
            shuffle($rung);
            foreach ($rung as $index)
                $participants[] = $index;
        }

        return $participants;
    }

    function test_own_party_dead(int $party): bool {
        if (!array_key_exists($party, $this->parties)) {
            throw new Exception("There was no party with index $party.");
        }
        return $this->parties[$party]->is_dead();
    }

    function test_other_parties_dead(int $party): bool {
        if (!array_key_exists($party, $this->parties)) {
            throw new Exception("There was no party with index $party.");
        }
        foreach ($this->parties as $index => $aparty)
            if ($index != $party && !$aparty->is_dead())
                return false;
        return true;
    }

    function count_live_parties(): int {
        $sum = 0;
        foreach ($this->parties as $index => $aparty)
            if (!$aparty->is_dead())
                $sum++;
        return $sum;
    }

    function get_character_list(?bool $living, array $parties): array {
        $retval = array();
        foreach (array_keys($parties) as $index) {
            $result = $this->parties[$index]->get_character_list($living);
            foreach ($result as $char)
                $retval[] = make_index_key($index, $char);
        }
        return $retval;
    }

    function get_character_key(CHARACTER $character): ?string {
        foreach (array_keys($this->parties) as $index) {
            $result = $this->parties[$index]->get_character_key($character);
            if (!is_null($result))
                return make_index_key($index, ...split_index_key($result));
        }
        return null;
    }

    function get_character(string $key): CHARACTER {
        $parts = split_index_key($key);
        if (count($parts) != 3) {
            log_error("Unexpected number of parts in key '$key'");
            throw new \Exception("Unexpected number of parts in key '$key'");
        }
        $party_index = array_shift($parts);
        $sub_key = make_index_key(...$parts);
        return $this->parties[$party_index]->get_character($sub_key);
    }

    function distribute_party_xp(int $exiting_party, array $parties_already_exited, array &$exp_debts, array &$exp_credits): void {
        foreach (array_keys($this->parties) as $party_index) {
            if (!in_array($party_index, $parties_already_exited)) {
                $party = $this->parties[$party_index];
                foreach (array_keys($party->groups) as $group_index) {
                    $non_allies = $party->get_non_ally_parties($this);
                    $non_enemies = $party->get_non_enemy_parties($this);
                    $group = $party->groups[$group_index];
                    foreach (array_keys($group->characters) as $char_index) {
                        $character = $group->characters[$char_index];
                        foreach ($character->xp_debts as $debt) {
                            list($target_party_index, $target_group_index, $target_char_index) = $debt[0];
                            //Only give credit for good actions
                            #Attacking non allies
                            #Healing non enemies
                            if (
                                !in_array($target_party_index, $parties_already_exited)
                                && ($target_party_index == $exiting_party || $party_index == $exiting_party)
                                && (($debt[1] > 0 && in_array($target_party_index, $non_allies))
                                    || ($debt[1] < 0 && in_array($target_party_index, $non_enemies)))
                            ) {
                                $xp = round(abs($debt[1]) * $debt[2] * $exp_debts[$party_index][$group_index][$char_index][1]);
                                $exp_credits[$target_party_index]['total'] += $xp;
                                $exp_credits[$target_party_index][$target_group_index][$target_char_index] += $xp;
                                #$target=$this->get_character(make_index_key($party,$group,$character));
                                #$fighter=$this->get_character(make_index_key($tparty,$tgroup,$tcharacter));
                                #echo "{$fighter->name} earned $xp of credit from {$target->name} ($debt[1]/$debt[2]).<br>";
                            }
                        }
                    }
                }
            }
        }
    }

    function process_exp_tree(): bool {
        //This function does a few things:
        //When a party is completely destroyed, all experience is distributed
        //that this party gave and took.
        //That party is then removed from this fight.
        //The fight is over when there is only one party left.

        //Step one- calculate the exp debt and credit trees
        //Initialize the debts tree
        $exp_debts = array();
        foreach (array_keys($this->parties) as $party_index) {
            $party = $this->parties[$party_index];
            foreach (array_keys($party->groups) as $group_index) {
                $group = $party->groups[$group_index];
                foreach (array_keys($group->characters) as $character_index) {
                    $character = $group->characters[$character_index];
                    //Calculate the debts
                    $debt_earned = array_sum(array_map(fn($debt) => abs($debt[1]), $character->xp_debts));
                    //Define the scale if debt_earned is greater than 1.  This will be used to scale down the debts to a maximum of 1.
                    $scale = 1;
                    if ($debt_earned > 1)
                        $scale = 1.0 / $debt_earned;
                    $exp_debts[$party_index][$group_index][$character_index] = array($debt_earned, $scale);
                }
            }
        }
        log_debug('xp', 'Experience debts', fn() => $exp_debts);

        //Initialize the credits tree
        $exp_credits = [];
        foreach (array_keys($this->parties) as $party_index) {
            $party_credits = [
                'total' => 0,
                'count' => 0,
            ];
            $party = $this->parties[$party_index];
            foreach (array_keys($party->groups) as $group_index) {
                $group = $party->groups[$group_index];
                foreach (array_keys($group->characters) as $character_index) {
                    $party_credits[$group_index][$character_index] = 0;
                    $party_credits['count']++;
                }
            }
            $exp_credits[$party_index] = $party_credits;
        }

        //Step two: look for dead parties
        $winners = array_keys($this->parties);
        $losers = [];
        foreach (array_keys($this->parties) as $party_index)
            if ($this->test_own_party_dead($party_index)) {
                //This party is dead.  Process and distribute all exp to and from this party.
                $this->distribute_party_xp($party_index, $losers, $exp_debts, $exp_credits);
                //This party is a loser, and will not have their exp pulled from future passes.
                $losers[] = $party_index;
                //Remove it from the winner's circle.
                $winners = array_filter($winners, function ($a) use ($party_index) {
                    return $a != $party_index;
                });
            }
        log_debug('xp', 'Experience credits', fn() => ['credits' => $exp_credits, 'winners' => $winners, 'losers' => $losers]);

        //Step three: If all paries alive are not enemies, then end this fight.
        $all_friends = true;
        foreach ($winners as $party_index) {
            $allies = $this->parties[$party_index]->get_ally_parties($this);
            $allies[] = $party_index;
            if (count(array_intersect($winners, $allies)) != count($winners))
                $all_friends = false;
        }
        if ($all_friends)
            foreach ($winners as $party_index) {
                //This party is dead.  Process and distribute all exp to and from this party.
                $this->distribute_party_xp($party_index, $losers, $exp_debts, $exp_credits);
                //Treat party as a loser, and will not have their exp pulled from future passes.
                $losers[] = $party_index;
            }

        //Step four: award the experience points
        foreach (array_keys($this->parties) as $party_index) {
            $party = $this->parties[$party_index];
            //Party-wide pool: 20% of everyone's earned credit, split
            //evenly across every party member, including NPCs (who
            //never receive their own share below, but still count
            //toward this divisor -- their contribution dilutes the
            //team pools' relative value as a form of payment for
            //their assistance).
            $party_member_count = $exp_credits[$party_index]['count'];
            $party_pool_share = round($exp_credits[$party_index]['total'] * .2 / $party_member_count);

            //Per-team pool: 30% of that team's own earned credit
            //(not the whole party's), split evenly among that team's
            //own members. A stronger team's pool grows with its own
            //performance only -- it can't be inflated by partying
            //with an unrelated, higher-performing team.
            $team_totals = [];
            foreach (array_keys($party->groups) as $group_index) {
                $group = $party->groups[$group_index];
                foreach (array_keys($group->characters) as $char_index) {
                    $fighter = $group->characters[$char_index];
                    foreach ($party->teams as $teamid => $team) {
                        if (in_array($fighter->charid, $team)) {
                            $team_totals[$teamid] = ($team_totals[$teamid] ?? 0) + $exp_credits[$party_index][$group_index][$char_index];
                        }
                    }
                }
            }
            $team_pool_shares = [];
            foreach ($party->teams as $teamid => $team)
                $team_pool_shares[$teamid] = round(($team_totals[$teamid] ?? 0) * .3 / count($team));

            foreach (array_keys($party->groups) as $group_index) {
                $group = $party->groups[$group_index];
                foreach (array_keys($group->characters) as $char_index) {
                    //Get this character
                    $fighter = $group->characters[$char_index];
                    //Find the team this character belongs to, if any.
                    $this_teamid = false;
                    foreach ($party->teams as $teamid => $team)
                        if (in_array($fighter->charid, $team))
                            $this_teamid = $teamid;
                    //NPCs (not on any team -- either a monster, or an
                    //NPC party member) technically never receive XP.
                    //Their share was still computed into the pools
                    //above, as intended.
                    if ($this_teamid === false)
                        continue;
                    $own_share = round($exp_credits[$party_index][$group_index][$char_index] * .5);
                    $xp_earned = $own_share + $party_pool_share + $team_pool_shares[$this_teamid];
                    //Add the XP.
                    $fighter->exp += $xp_earned;
                    #echo "{$fighter->name} earned {$xp_earned} XP.<br>";
                }
            }
        }

        //Step five: discharge the dead parties
        foreach (array_keys($this->parties) as $party)
            if ($this->test_own_party_dead($party))
                unset($this->parties[$party]);

        //return true if we have one (or none) live parties left.
        return ($all_friends || count($winners) < 2);
    }


    // Server-only snapshot of every character's xp_debts, in the same
    // positional shape dehydrate() already uses (parties -> groups ->
    // characters) rather than any keyed lookup -- deliberately not
    // part of dehydrate()/DEHYDRATE_PROPERTIES itself (xp_debts is
    // excluded from CHARACTER's own dehydrate() output for the same
    // reason: it's never meant for the client, and doesn't belong in
    // the action log). Written to fights.xpdebt_json wherever a turn
    // resolves; see fight-schema-v4.php for the column and the
    // rationale in full.
    // The other half of set_PC_action() persisting through
    // fight_pending_actions now instead of riding along inside
    // fight_data: applies each player's most recently submitted
    // command/using/target onto the matching character. Meant to run
    // once, right after hydrating a FIGHT for a new request -- nothing
    // in dehydrate()/hydrate() carries this state as of CHARACTER
    // version 2 (see CHARACTER::hydrate()), so a freshly-hydrated
    // FIGHT has every character's command/using/target at the same
    // just-created defaults __construct() itself uses until this runs.
    // $pending_actions: PendingAction[], typically
    // PendingAction::load_all_by_fightid()'s own return value.
    function apply_pending_actions(array $pending_actions): void {
        foreach ($pending_actions as $pending_action) {
            if (!isset($this->parties[$pending_action->party_index])) continue;
            $party = $this->parties[$pending_action->party_index];
            if (!isset($party->groups[$pending_action->group_index])) continue;
            $group = $party->groups[$pending_action->group_index];
            if (!isset($group->characters[$pending_action->char_index])) continue;
            $character = $group->characters[$pending_action->char_index];

            $character->command = $pending_action->command;
            $character->using = $pending_action->using;
            $character->target = $pending_action->target;
        }
    }

    function process_fight_action(FightAction $fight_action): void {
        if ($fight_action->fightid != $this->id) {
            throw new Exception("Fight action id {$fight_action->fightid} does not match fight id {$this->id}.");
        }
        if ($fight_action->sequence != $this->counter + 1) {
            throw new Exception("Fight action sequence {$fight_action->sequence} is not next in sequence for fight, counter {$this->counter}.");
        }
        $actor = null;
        $target = null;
        foreach ($fight_action->action_data as $message) {
            list($type, $data) = $message;
            switch ($type) {
                case 'Turn':
                    list($party_index, $group_index, $char_index) = $data;
                    $actor = $this->parties[$party_index]->groups[$group_index]->characters[$char_index];
                    break;
                case 'Target':
                    list($party_index, $group_index, $char_index, $range) = $data;
                    $target = $this->parties[$party_index]->groups[$group_index]->characters[$char_index];
                    break;
                case 'Attack':
                    # Does nothing.
                    break;
                case 'Item':
                    # Does nothing.
                    break;
                case 'Skill':
                    # Does nothing.
                    break;
                case 'Spell':
                    # Does nothing.
                    break;
                case 'Equip':
                    # Does nothing.
                    break;
                case 'Unequip':
                    # Does nothing.
                    break;
                case 'Defend':
                    # Does nothing.
                    break;
                case 'Run':
                    # Does nothing.
                    break;
                case 'NoMP':
                    # Does nothing.
                    break;
                case 'NoAmmo':
                    # Does nothing.
                    break;
                case 'Give':
                    // Field action only.
                    break;
                case 'Receive':
                    // Field action only.
                    break;
                case 'Miss':
                    # Do nothing
                    break;
                case 'Damage':
                    list($target_party_index, $target_group_index, $target_char_index, $amount, $critical) = $data;
                    $target = $this->parties[$target_party_index]->groups[$target_group_index]->characters[$target_char_index];
                    $target->current['HP'] = $target->current['HP'] - $amount;
                    break;
                case 'Restore':
                    list($target_party_index, $target_group_index, $target_char_index, $stat, $amount) = $data;
                    $target = $this->parties[$target_party_index]->groups[$target_group_index]->characters[$target_char_index];
                    $target->current[$stat] += $amount;
                    break;
                case 'Died':
                    list($target_party_index, $target_group_index, $target_char_index, $amount) = $data;
                    $target = $this->parties[$target_party_index]->groups[$target_group_index]->characters[$target_char_index];
                    $target->current['HP'] = 0;
                    // Its overkill, if recorded (a fifth field), is tallied from
                    // the messages: see FightAction::overkill_taken().
                    break;
                case 'XpDebt':
                    // The other half of CHARACTER::add_debt_to_xp_tree()
                    // -- this is how xp_debts survives a FIGHT
                    // reconstruction: replayed here the same way Damage
                    // replays HP loss above, not restored from any
                    // separate snapshot. $fighter_array stays a nested
                    // sub-array (not further destructured), matching
                    // xp_debts' own stored shape exactly.
                    list($target_party_index, $target_group_index, $target_char_index, $fighter_array, $ratio, $pxp) = $data;
                    $target = $this->parties[$target_party_index]->groups[$target_group_index]->characters[$target_char_index];
                    $target->xp_debts[] = [$fighter_array, $ratio, $pxp];
                    break;
                case 'NoEffect':
                    # No effect here.
                    break;
                case 'AlterStat':
                    list($target_party_index, $target_group_index, $target_char_index, $stat, $amount) = $data;
                    $target = $this->parties[$target_party_index]->groups[$target_group_index]->characters[$target_char_index];
                    $target->current[$stat] += $amount;
                    break;
                case 'UseItem':
                    list($item_used) = $data;
                    $actor->remove_item($item_used, 1);
                    break;
                case 'ExpendAmmo':
                    list($ammo_used) = $data;
                    $actor->remove_item($ammo_used, 1);
                    break;
                case 'EquipSlot':
                    list($inventory_slot, $slot) = $data;
                    $actor->equipment[$slot] = $inventory_slot;
                    break;
                case 'UnequipSlot':
                    list($slot) = $data;
                    $actor->equipment[$slot] = null;
                    break;
                case 'NewParty':
                    // Scaffolding: nothing emits this yet (no
                    // server-side mechanism for a party actually
                    // joining mid-fight exists) -- but replay needs to
                    // reconstruct it correctly once something does, the
                    // same way Damage/Died/etc. replay their own
                    // effects here rather than relying on a separate
                    // snapshot. $dehydrated_party is a raw PARTY::dehydrate()
                    // payload (the same shape initial_state/final_state
                    // use), not yet client-sanitized -- that happens in
                    // FightAction::filter_for_client(), which needs the
                    // requesting client's own teamid to do it, not here
                    // (this method reconstructs real server state, and
                    // has no notion of "whose client" at all).
                    list($dehydrated_party) = $data;
                    $this->parties[] = PARTY::hydrate($dehydrated_party);
                    break;
            }
        }
        $this->counter = $fight_action->sequence;
    }


    // Every ability/item id any character in this fight currently
    // has available -- current roster state, not initial_state, so
    // this stays correct if called again later (a party joining
    // mid-fight, say, once that exists). The client already has
    // abilities[]/items[] fully cached as static game data, loaded
    // independently of any one fight -- this only needs to say which
    // ids are actually in play, not send sound/image URLs itself.
    //
    // Exists because CHARACTER::to_client()'s abilities/inventory
    // fields are withheld from any non-own-team character, for every
    // viewer (a real participant's own opponent included, not just a
    // spectator) -- so the client's own preload_hero_images()/
    // cache_fight_sounds(), which read those fields directly to know
    // what to cache, silently skip anything belonging to a
    // non-owning party. For a spectator (client_teamid null,
    // matching no team at all) that's every character in the fight, not
    // just the opponent's side -- confirmed as a real crash directly:
    // an uncached impact sound throwing when an animation tried to
    // play it mid-replay.
    function collect_resource_ids(): array {
        $ability_ids = [];
        $item_ids = [];
        foreach ($this->parties as $party) {
            foreach ($party->groups as $group) {
                foreach ($group->characters as $character) {
                    foreach ($character->abilities as $ability_id) {
                        $ability_ids[$ability_id] = true;
                    }
                    foreach ($character->inventory as $slot) {
                        $item_ids[$slot['item']] = true;
                    }
                }
            }
        }
        return ['abilities' => array_keys($ability_ids), 'items' => array_keys($item_ids)];
    }

    // Called exactly once, from FightCommunicator::process_round(), at
    // the moment a fight transitions to Resolved -- the fight's XP,
    // leveling, HP-MP restoration and gold distribution (once done by
    // an after-fight page a player visited, with no protection beyond
    // session state against running twice; that page is gone) in the
    // same place record_user_history()
    // already runs, which only ever executes on the one real
    // transition to Resolved (guarded by the semaphore's own claim/
    // release mechanism).
    //
    // Generalized from the old after-fight page's hardcoded 1v1
    // assumption ($eparty = parties[1 - player_party]) to any number
    // of parties, using each party's own $enemies list (see
    // PARTY::find_party_alignment() -- confirmed directly that
    // process_exp_tree() below doesn't remove dead parties from
    // $this->parties despite its own stale docblock comment claiming
    // it does, so every party's $enemies list is still walkable
    // afterward) rather than assuming there's exactly one other party.
    //
    // Returns a single dialog-tree-tagged string (see dialog_text.js's
    // own documented lexicon), not a per-team map: each team's section
    // is wrapped in a `team,teamid,next_label` tag, so the SAME string
    // goes to every viewer, and each client's own render_dialog()
    // -- reading its own local teamid -- naturally shows only its own
    // team's lines and skips past everyone else's, without needing a
    // separate per-viewer filtering step server-side.
    // $xp_bonus: [party index => share] -- extra XP, as a share of what
    // each of that party's characters earned, for beating monsters a
    // player controlled (see FightCommunicator::xp_bonus_shares()).
    function process_fight_conclusion(Database $db, array $xp_bonus = []): string {
        // Snapshotted before process_exp_tree() runs, not after: it
        // removes a defeated party from $this->parties entirely
        // (unset($this->parties[$party])), confirmed directly earlier
        // this session -- without this snapshot, a losing party is
        // already gone by the time this method's own loops run, so
        // (a) that party's own section would never get generated at
        // all, and (b) any OTHER party whose $enemies list points at
        // it -- for gold/XP-ratio calculation -- would hit a null
        // dereference (the exact production error this was rebuilt
        // to fix: get_pxp() on null). The PARTY/CHARACTER objects
        // themselves stay fully valid through this snapshot even once
        // removed from $this->parties's own array -- process_exp_tree()
        // mutates their properties (exp, xp_debts) in place, it
        // doesn't replace the objects.
        $parties = $this->parties;
        // Captured before the HP-restoration loop below runs (applies
        // to every party as a consolation heal, win or lose) --
        // confirmed directly that a losing party with any nonzero pxp
        // relative to the winner gets revived to at least 1 HP by
        // that restoration, so checking is_dead() afterward (for the
        // win/lose music and text) would give the wrong answer on
        // most close fights, not just a rare edge case.
        $party_was_dead = array_map(fn($party) => $party->is_dead(), $parties);
        $this->process_exp_tree();

        $team_sections = [];

        foreach ($parties as $party_index => $party) {
            if (count($party->teams) === 0) {
                continue; // no real player teams in this party -- nothing to report
            }
            if ($this->practice) {
                continue; // cloned AI heroes: their teams get nothing, and nothing is saved
            }

            $enemy_pxp = 0;
            foreach ($party->enemies as $enemy_index) {
                $enemy_pxp += $parties[$enemy_index]->get_pxp();
            }
            $pxp = $party->get_pxp();

            $team_lines = [];
            foreach (array_keys($party->teams) as $teamid) {
                $team_lines[$teamid] = [];
            }

            // XP gains, one line per character, routed to that
            // character's own team -- not every team sharing the party.
            foreach ($party->groups as $group) {
                foreach ($group->characters as $character) {
                    if ($character->teamid === null || $character->teamid <= 0) {
                        continue; // not a real player-controlled character
                    }
                    $diff = $character->exp - $character->old_exp;
                    $team_lines[$character->teamid][] = "{$character->name} has earned $diff XP.";
                    $bonus = (int) floor(max(0, $diff) * ($xp_bonus[$party_index] ?? 0));
                    if ($bonus > 0) {
                        $character->exp += $bonus;
                        $team_lines[$character->teamid][] = "{$character->name} earns $bonus more for beating a player's monsters.";
                    }
                }
            }

            // leveling, one line per character, routed to that
            // character's own team -- not every team sharing the party.
            foreach ($party->groups as $group) {
                foreach ($group->characters as $character) {
                    if ($character->teamid === null || $character->teamid <= 0) {
                        continue; // not a real player-controlled character
                    }
                    if ($character->jobid > 0) {
                        $result = $character->level();
                        if ($result['leveled']) {
                            $promotion = implode("\n", $result['data']);
                            $team_lines[$character->teamid][] = "`ack``sound,LevelUp.mp3`{$promotion}";
                        }
                    }
                }
            }

            // HP/MP restoration -- the old after-fight page's ratio
            // formula, applied fight-wide per party; not narrated per
            // character.
            // TODO: This will be removed when maps are implemented.
            if ($pxp > 0) {
                $ratio = $enemy_pxp / 4.0 / $pxp;
                foreach ($party->groups as $group) {
                    foreach ($group->characters as $character) {
                        if ($character->current['HP'] == 0) {
                            $character->current['HP'] += floor($character->base['HP'] * $ratio * 2);
                        } else {
                            $character->current['HP'] += floor($character->base['HP'] * $ratio);
                            $character->current['MP'] += floor($character->base['MP'] * $ratio * 2);
                        }
                        if ($character->current['HP'] > $character->base['HP']) {
                            $character->current['HP'] = $character->base['HP'];
                        }
                        if ($character->current['MP'] > $character->base['MP']) {
                            $character->current['MP'] = $character->base['MP'];
                        }
                    }
                }
            }
            $party->store_party($db);

            // Gold, from dead characters across every enemy party, not
            // just one -- distribute_gold() itself already splits
            // evenly across this party's own teams.
            $gold = 0;
            foreach ($party->enemies as $enemy_index) {
                foreach ($parties[$enemy_index]->groups as $group) {
                    foreach ($group->characters as $enemy_character) {
                        if ($enemy_character->is_dead()) {
                            $gold += $enemy_character->gold;
                        }
                    }
                }
            }
            $take = $party->distribute_gold($db, $gold);
            foreach (array_keys($party->teams) as $teamid) {
                $team_lines[$teamid][] = "Your team received $take gold.";
            }

            foreach ($team_lines as $teamid => $lines) {
                $team_sections[$teamid] = implode("\n", $lines);
            }
        }

        return $this->format_conclusion_dialog($team_sections, $parties, $party_was_dead);
    }

    // Builds the dialog-tree-tagged string from a teamid => text map --
    // separated out from process_fight_conclusion() above so it can be
    // tested against the actual message content independently of the
    // XP/gold computation that produces it. $parties is the same
    // pre-process_exp_tree() snapshot process_fight_conclusion() took
    // above, not $this->parties -- by the time this runs,
    // process_exp_tree() has already removed any defeated party from
    // $this->parties entirely. $party_was_dead is that same method's
    // own pre-restoration is_dead() snapshot, keyed by party index --
    // not called fresh here, since by this point every party's HP has
    // already been through the consolation-heal restoration, which can
    // revive a losing party past the is_dead() threshold.
    //
    // Each team's section: a label (its own jump target), immediately
    // followed by a `team,teamid,next_label` check -- next_label is
    // the FOLLOWING team's own label, or 'end' for the last team, so a
    // non-matching viewer skips straight to the next check rather than
    // seeing any of this team's lines. A trailing `jump,end` after
    // every team except the last skips past the remaining teams' checks
    // once a real match was found and shown, rather than falling
    // through into them.
    private function format_conclusion_dialog(array $team_sections, array $parties, array $party_was_dead): string {
        $teamids = array_keys($team_sections);
        $parts = [];

        // Party outputs (for all viewers based on player_party)
        foreach ($parties as $position => $party) {
            $next_label = $position + 1 < count($parties) ? 'party_' . ($position + 1) : 'party_end';
            $text = $party_was_dead[$position] ? '`music,Funeral March.mp3`The party has perished.' : "`music,Fight End.mp3`The party has won!\n";
            $parts[] = "`label,party_{$position}``party,{$position},{$next_label}`{$text}\n`ack``jump,party_end`";
        }

        // Emit party_end label to collect party outputs.
        $parts[] = "`label,party_end`";

        // Team outputs. A viewer sees the section of each of their own
        // teams -- one, or several with an alliance. Two chains of the
        // same sections: team_* while nothing has been shown yet (the
        // last skip goes to quiet_end: nothing to acknowledge), and
        // more_* once something has (the last goes to end, which waits
        // for the player to dismiss it).
        foreach (['team' => 'quiet_end', 'more' => 'end'] as $chain => $last) {
            foreach ($teamids as $position => $teamid) {
                $next_label = $position + 1 < count($teamids) ? "{$chain}_{$teamids[$position + 1]}" : $last;
                $after_label = $position + 1 < count($teamids) ? "more_{$teamids[$position + 1]}" : 'end';
                $parts[] = "`label,{$chain}_{$teamid}``team,{$teamid},{$next_label}`" . $team_sections[$teamid] . "\n`jump,{$after_label}`";
            }
        }

        // Shared exit point every branch above falls through to (or
        // jumps to directly): waits for the player to actually
        // dismiss the dialog before it closes, rather than vanishing
        // the instant typing finishes.
        $parts[] = '`label,end``ack``label,quiet_end``mute`';
        return implode('', $parts);
    }

    #[Override]
    public function get_parties(): array {
        return $this->parties;
    }

    #[Override]
    public function get_scope_type(): ScopeType {
        return ScopeType::COMBAT;
    }
}
