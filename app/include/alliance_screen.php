<?php
require_once INCLUDE_GAME . 'party_state.php';
require_once INCLUDE_GAME . 'alliance.php';
require_once INCLUDE_GAME . 'fight_communicator.php';

/**
 * The hub's Alliance dialog: the old setup_fight.php's alliance section as data
 * (what the player has, and what they can buy with tokens), and the
 * purchases themselves -- Alliance's own rules and prices throughout.
 *
 *   view()  ['balance', 'status' (a sentence, or ''), 'hint' (or ''),
 *            'choices' => [['action', 'label', 'teamid' (for 'add')], ...],
 *            'formable' => [[teamid, name], ...], 'max_join', 'minutes']
 *   act()   'form' (with the teams to bring), 'add', 'extend' or 'end'
 */
final class AllianceScreen {
    // The player's active teams, id => name.
    private static function own_teams(Database $db, int $playerid): array {
        $teams = array_filter(TEAM::load_all_by_playerid($db, $playerid), fn($t) => $t->state === ObjectState::ACTIVE);
        return array_map(fn($t) => $t->name, $teams);
    }

    public static function view(Database $db, int $playerid): ?array {
        $lead = PartyState::lead_teamid($db, $playerid);
        if ($lead === null) {
            return null;
        }
        $names = self::own_teams($db, $playerid);
        $owned = array_map('intval', array_keys($names));
        $options = Alliance::options($db, $playerid, $owned, $lead);
        $alliance = $options['alliance'];
        $name_of = fn(int $id) => $names[$id] ?? "team $id";
        $minutes = fn(int $seconds) => (int) ceil($seconds / 60);
        $list = fn(array $ids) => implode(', ', array_map($name_of, $ids));

        $status = '';
        if ($alliance !== null && $alliance->is_active() && in_array($lead, $alliance->teamids, true)) {
            $status = 'Allied: ' . $list($alliance->teamids) . ' — ' . $minutes($alliance->seconds_left)
                . ' minutes left. Only time in fights counts; your allies stay until the end of a fight.';
        } elseif ($alliance !== null && $alliance->is_active()) {
            $status = 'Your alliance (' . $list($alliance->teamids) . ') has ' . $minutes($alliance->seconds_left)
                . ' minutes left. Pick one of those teams to fight together.';
        } elseif ($alliance !== null && $alliance->state === Alliance::EXPIRED) {
            $status = 'Your alliance ran out of time: its last fight ran ' . $minutes($alliance->overtime)
                . ' minutes over, and your allies have left.';
        }

        $choices = [];
        $hint = '';
        if ($options['can_form'] !== [] && $options['balance'] > 0) {
            $choices[] = ['action' => 'form', 'label' => 'Form an alliance', 'teamid' => null,
                'desc' => 'Bring more of your teams along, 1 token each, for ' . $minutes($options['extend_seconds']) . ' minutes.'];
        } elseif ($options['can_form'] !== [] && $alliance === null) {
            $hint = 'Earn tokens fighting as the monsters to bring your other teams along.';
        }
        if ($options['balance'] > 0) {
            foreach ($options['can_add'] as $id) {
                $choices[] = ['action' => 'add', 'label' => 'Bring ' . $name_of($id) . ' too', 'teamid' => $id,
                    'desc' => '1 token, for the time already left.'];
            }
        }
        if ($options['can_extend']) {
            $choices[] = $alliance->is_active()
                ? ['action' => 'extend', 'label' => 'Add an hour', 'teamid' => null, 'desc' => '1 token.']
                : ['action' => 'extend', 'label' => 'Bring them back', 'teamid' => null,
                    'desc' => '1 token, for ' . $minutes($options['extend_seconds']) . ' minutes.'];
        }
        if ($alliance !== null && $alliance->state === Alliance::EXPIRED) {
            $choices[] = ['action' => 'end', 'label' => 'Let them go', 'teamid' => null, 'desc' => 'Any overtime is taken off your next alliance.'];
        }
        return [
            'balance' => (int) $options['balance'],
            'status' => $status,
            'hint' => $hint,
            'choices' => $choices,
            'formable' => array_map(fn($id) => [$id, $name_of($id)], $options['can_form']),
            'max_join' => Alliance::MAX_TEAMS - 1,
            'minutes' => $minutes($options['extend_seconds']),
        ];
    }

    // [problem or null, message]
    public static function act(Database $db, int $playerid, string $action, array $teamids): array {
        if ((new FightCommunicator($db, $playerid))->is_in_active_fight()) {
            return ['Finish your fight first.', ''];
        }
        $lead = PartyState::lead_teamid($db, $playerid);
        if ($lead === null) {
            return ['Pick a team first.', ''];
        }
        $owned = array_map('intval', array_keys(self::own_teams($db, $playerid)));
        $teamids = array_map('intval', $teamids);
        $problem = match ($action) {
            'form' => Alliance::form($db, $playerid, $owned, $lead, $teamids),
            'add' => Alliance::add_team($db, $playerid, $owned, $teamids[0] ?? 0),
            'extend' => Alliance::extend($db, $playerid, $owned),
            'end' => (function () use ($db, $playerid) { Alliance::end($db, $playerid); return null; })(),
            default => 'Do what?',
        };
        if ($problem !== null) {
            return [$problem, ''];
        }
        return [null, match ($action) {
            'form' => 'Your alliance is formed.',
            'add' => 'Another team joins you.',
            'extend' => 'Your alliance has more time.',
            'end' => 'Your allies have gone their own way.',
        }];
    }
}
