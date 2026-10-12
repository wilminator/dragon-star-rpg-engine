<?php
require_once INCLUDE_GAME . 'fight.php';
require_once INCLUDE_GAME . 'fight_action.php';
require_once INCLUDE_GAME . 'pending_action.php';
require_once INCLUDE_GAME . 'database.php';

class FIGHT_STORE {
    private Database $db;

    //This constructor (tries to) initialize the fight table.
    function __construct($reset = false) {
        $this->db = get_database();

        $this->db->ensure_table_exists(
            "DROP TABLE IF EXISTS fights",
            "
CREATE TABLE IF NOT EXISTS fights (
  id int unsigned NOT NULL,
  fight_data text NOT NULL,
  sequence mediumint unsigned NOT NULL,
  state int NOT NULL,
  timeout datetime NOT NULL,
  initial_state longtext,
  PRIMARY KEY (id)
) ",
            $reset
        );
        $this->db->ensure_table_exists(
            "DROP TABLE IF EXISTS fight_players",
            "
CREATE TABLE IF NOT EXISTS fight_players (
  fightid int unsigned NOT NULL,
  playerid int unsigned NOT NULL,
  teamid int NOT NULL,
  player_party tinyint unsigned NOT NULL,
  seen enum('yes','no')  NOT NULL,
  sequence mediumint unsigned NOT NULL,
  PRIMARY KEY  (fightid,playerid),
  KEY playerid (playerid)
) ",
            $reset
        );
        $this->db->ensure_table_exists(
            "DROP TABLE IF EXISTS fight_actions",
            "
CREATE TABLE IF NOT EXISTS fight_actions (
  fightid int unsigned NOT NULL,
  sequence mediumint unsigned NOT NULL,
  prefight_data text,
  action_data text NOT NULL,
  action_json longtext,
  final_state longtext,
  PRIMARY KEY  (fightid,sequence)
) ",
            $reset
        );
        $this->db->ensure_table_exists(
            "DROP TABLE IF EXISTS fight_saves",
            "
CREATE TABLE IF NOT EXISTS fight_saves (
  fightid int unsigned NOT NULL,
  playerid int unsigned NOT NULL,
  type enum('retain','user','underdog','overkill') NOT NULL,
  PRIMARY KEY  (fightid,playerid),
  KEY playerid (playerid,type)
) ",
            $reset
        );
    }

    function lock_db($write = false) {
        if ($write)
            $query = "LOCK TABLES fights WRITE, fights AS f1 WRITE, fights AS f2 WRITE, fight_players WRITE, fight_actions WRITE, fight_saves WRITE";
        else
            $query = "LOCK TABLES fights READ, fights AS f1 READ, fights AS f2 READ, fight_players READ, fight_actions READ, fight_saves READ";
        try {
            $this->db->prepare($query)->execute();
        } catch (\Throwable $e) {
            throw new \Exception("Error locking fights table\n" . $e->getMessage());
        }
    }

    function unlock_db() {
        $this->db->prepare("UNLOCK TABLES")->execute();
    }

    function get_fight_players(int $fightid) : array {
        $players = array();
        $query = "
            SELECT
                playerid, teamid, player_party, seen
            FROM fight_players
            WHERE fightid=?";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute([$fightid]);
        } catch (\Throwable $e) {
            throw new \Exception("Error querying fight_players for fightid $fightid\n" . $e->getMessage());
        }
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC))
            $players[$data['playerid']] = $data;

        return $players;
    }

    // Action lists now come from FightAction rather than raw
    // unserialize() of the legacy action_data column -- this query
    // only needs prefight_data itself anymore.
    function get_fight_actions(int $fightid) :array {
        $actions = array();
        $query = "
            SELECT
                sequence, prefight_data
            FROM fight_actions
            WHERE fightid=?
            ORDER BY sequence";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute([$fightid]);
        } catch (\Throwable $e) {
            throw new \Exception("Error querying fight_actiuons for fightid $fightid\n" . $e->getMessage());
        }
        $prefight_by_sequence = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $prefight_by_sequence[(int) $data['sequence']] = unserialize($data['prefight_data']);
        }

        foreach (FightAction::load_all_by_fightid($this->db, $fightid) as $fight_action) {
            $actions[$fight_action->sequence] = array(
                'prefight' => $prefight_by_sequence[$fight_action->sequence] ?? null,
                'actions' => $fight_action->action_data,
            );
        }

        return $actions;
    }

    function get_fight_data(int $fightid) : array|bool {
        //Lock the db
        $this->lock_db(true);
        $query = "
            SELECT
                id, sequence,
                unix_timestamp(timeout)-unix_timestamp(now()) timeout,
                fight_data
            FROM fights
            WHERE id=?";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$fightid]);
        if (($data = $stmt->fetch(PDO::FETCH_ASSOC)) === false) {
            $this->unlock_db();
            return false;
        }
        extract($data);
        //Collect player data
        $players = $this->get_fight_players($data['id']);
        //Collect fight history
        $actions = $this->get_fight_actions($data['id']);
        //Unlock the db
        $this->unlock_db();
        //Generate the fight data for reference
        $fight = unserialize($fight_data);
        //Add to retval
        return array(
            'fightid' => $fightid,
            'sequence' => $sequence,
            'timeout' => $timeout,
            'fight' => $fight,
            'players' => $players
        );
    }

    function get_all_fights() {
        //See what fights are active.
        $retval = array();
        $query = "
            SELECT id
            FROM fights";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute();
        } catch (\Throwable $e) {
            throw new \Exception("Error querying all fights.\n" . $e->getMessage());
        }
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $retval[$data['id']] = $this->get_fight_data($data['id']);
        }
        return $retval;
    }

    function set_fight_data(int $fightid, array $data):void {
        //insert main fight data.
        extract($data);
        $fight_data = serialize($fight);
        // $active may still carry an old export's string value
        // ('yes'/'no'/'processing') -- translated to the new
        // FightStatus-backed integer state column here.
        $state = match (true) {
            $active instanceof FightStatus => $active->value,
            is_int($active) => $active,
            $active === 'yes' => FightStatus::WaitingForInput->value,
            $active === 'processing' => FightStatus::Processing->value,
            $active === 'no' => FightStatus::Resolved->value,
            default => throw new \Exception("Unrecognized active/state value: " . var_export($active, true)),
        };
        $query = "INSERT INTO fights (id,fight_data,sequence,state,timeout)
            VALUES(?,?,?,?,?)";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute([$fightid, $fight_data, $sequence, $state, $timeout]);
        } catch (\Throwable $e) {
            throw new \Exception("Error inserting new fight $fightid\n" . $e->getMessage());
        }
        //insert player data.
        foreach ($players as $player) {
            extract($player);
            $query = "INSERT INTO fight_players(fightid,playerid,teamid,player_party,seen,sequence)
                VALUES(?,?,?,?,?,?)";
            try {
                $stmt = $this->db->prepare($query);
                $stmt->execute([$fightid, $playerid, $teamid, $player_party, $seen, $sequence]);
            } catch (\Throwable $e) {
                throw new \Exception("Error inserting new fight_players fightid $fightid playerid $playerid\n" . $e->getMessage());
            }
        }
        //insert action data.
        foreach ($actions as $action) {
            extract($action);
            $prefight_data = serialize($prefight);
            $action_data = serialize($action_data);
            $query = "INSERT INTO fight_actions(fightid,sequence,prefight_data,action_data)
                VALUES(?,?,?,?)";
            try {
                $stmt = $this->db->prepare($query);
                $stmt->execute([$fightid, $sequence, $prefight_data, $action_data]);
            } catch (\Throwable $e) {
                throw new \Exception("Error inserting new fight_actions fightid $fightid sequence $sequence\n" . $e->getMessage());
            }
        }
    }

    function get_active_fights() {
        //Lock the db
        $this->lock_db(true);
        //See what fights are active.
        $retval = array();
        $query = "
            SELECT
                id, sequence,
                unix_timestamp(timeout)-unix_timestamp(now()) timeout,
                fight_data
            FROM fights
            WHERE state<>?";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute([FightStatus::Resolved->value]);
        } catch (\Throwable $e) {
            throw new \Exception("Error listing active fightids.\n" . $e->getMessage());
        }
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            extract($data);
            //Collect player data
            $players = $this->get_fight_players($data['id']);

            //Generate the fight data for reference
            $fight = unserialize($fight_data);
            //Add to retval
            $retval[$id] = array(
                'fightid' => $id,
                'sequence' => $sequence,
                'timeout' => $timeout,
                'fight' => $fight,
                'players' => $players
            );
        }

        //Unlock the db
        $this->unlock_db();

        return $retval;
    }

    function get_fight(int $playerid, int $fightid = 0) {
        log_error("Loading fight $fightid for playerid $playerid");
        //See if this is cached.
        if ($fightid === 0 && isset($GLOBALS['__GET_FIGHT_DATA'])){
            return $GLOBALS['__GET_FIGHT_DATA'];
        }
        log_error("Starting db query for fight $fightid for playerid $playerid");
        //Lock the db
        $this->lock_db(true);
        //See if this player has a fight.
        $query = "
            SELECT
                fights.id fightid, state active, teamid, player_party, fight_data,
                fight_players.sequence, seen, unix_timestamp(timeout)-unix_timestamp(now()) timeout
            FROM fights
            LEFT JOIN fight_players
                ON fights.id=fight_players.fightid AND playerid=?
            WHERE ";
        $params = [$playerid];
        if ($fightid) {
            $query .= "fights.id=?";
            $params[] = $fightid;
        } else {
            $query .= "state<>? and playerid=?";
            $params[] = FightStatus::Resolved->value;
            $params[] = $playerid;
        }

        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
        } catch (\Throwable $e) {
            throw new \Exception("Error selecting fight fightid $fightid playerid $playerid.\n" . $e->getMessage());
        }
        //If there is more than one active fight data, then bail.
        // No, kill off the older fight and run with the latest.
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $active_fights = count($rows);
        if ($active_fights > 1) {
            log_error("$active_fights active fights(?!?!)\n$query");
            $this->unlock_db();
            while ($active_fights > 1) {
                $data = array_shift($rows);
                $upd_query = "UPDATE fights SET state=? WHERE id=?";
                try {
                    $upd = $this->db->prepare($upd_query);
                    $upd->execute([FightStatus::Resolved->value, $data['fightid']]);
                } catch (\Throwable $e) {
                    throw new \Exception("Error deactivating old fight fightid $fightid playerid $playerid.\n" . $e->getMessage());
                }
                $active_fights --;
            }
        }
        $data = $rows ? array_shift($rows) : false;
        //If there is no active fight data, then bail.
        if ($data === false) {
            log_error("No active fight for player $playerid");
            $this->unlock_db();
            return false;
        }
        extract($data);

        if ($active != FightStatus::Resolved->value) {
            $query = "UPDATE fight_players SET seen='yes' WHERE playerid=? AND fightid=?";
            try {
                $stmt = $this->db->prepare($query);
                $stmt->execute([$playerid, $fightid]);
            } catch (\Throwable $e) {
                throw new \Exception("Error updating fight seen for player fightid $fightid playerid $playerid.\n" . $e->getMessage());
            }
        }

        //If this player has a fight, highest sequence and prefight data.
        //If this data is empty, then there are no sequences to the fight.
        if ($sequence == 0) {
            $prefight_data = $fight_data;
            $action_list = [];
        }
        //Otherwise load the data
        else {
            $query = "select prefight_data from fight_actions where fightid=? and sequence=?";
            try {
                $stmt = $this->db->prepare($query);
                $stmt->execute([$fightid, $sequence]);
            } catch (\Throwable $e) {
                throw new \Exception("Error loading fight_actions fightid $fightid sequence $sequence.\n" . $e->getMessage());
            }
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($data === false) {
                // This is a true fail scenario.
                log_error("There is no data for a fight sequence $fightid-$sequence");
                // Nuke the fight.
                $query = "UPDATE fights SET state=? WHERE id=?";
                try {
                    $upd = $this->db->prepare($query);
                    $upd->execute([FightStatus::Resolved->value, $fightid]);
                } catch (\Throwable $e) {
                    throw new \Exception("Error nuking fight missing current sequence fightid $fightid sequence $sequence.\n" . $e->getMessage());
                }
                //Unlock the db
                $this->unlock_db();
                //Bail with fail.
                return false;
            }

            $prefight_data = $data['prefight_data'];
            $fight_action = FightAction::load($this->db, $fightid, $sequence);
            $action_list = $fight_action !== null ? $fight_action->action_data : [];
        }

        //Get the players list
        $players = $this->get_fight_players($fightid);

        //Unlock the db
        $this->unlock_db();

        //For efficiency, cache the data in a global.
        $GLOBALS['__GET_FIGHT_DATA'] = array(
            'teamid' => $teamid,
            'player_party' => $player_party,
            'sequence' => $sequence,
            'prefight' => $prefight_data ? unserialize($prefight_data) : unserialize($fight_data),
            'action_list' => $action_list,
            'fight' => $fight_data ? unserialize($fight_data) : [],
            'seen' => $seen,
            'timeout' => $timeout,
            'players' => $players,
            'fightid' => $fightid,
            'active' => $active
        );

        //Return the data as an array of humungous proportions.
        return $GLOBALS['__GET_FIGHT_DATA'];
    }

    function get_full_fight(int $fightid) : array {
        $data = $this->get_fight_data($fightid);
        $full_fight = array();
        foreach ($data['actions'] as $action)
            $full_fight += $action['actions'];

        return array('prefight' => $data['actions'][0]['prefight'], 'actions' => $full_fight, 'fight' => $data['fight']);
    }

    function init_fight(FIGHT $fight, $players) {
        //Lock the db
        $this->lock_db(true);

        //Get the next available fight slot.
        $query = "
            select f1.id+1 fightid
            from fights f1
            left join fights f2 on f1.id+1=f2.id
            where f2.id is null
            order by f1.id DESC";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute();
        } catch (\Throwable $e) {
            $this->unlock_db();
            throw new \Exception("Error getting next available fight id number.\n" . $e->getMessage());
        }
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($data == false)
            $fightid = 1;
        else
            extract($data);

        //Set the fight's fightid
        $fight->id = $fightid;
        //Find the initial timeout value in minutes.
        $timeout = $fight->count() + 30;

        //Store the fight.
        $fight_data = serialize($fight);
        $query = "
            INSERT INTO fights (id,fight_data,state,timeout,sequence)
            VALUES (?,?,?,adddate(now(),INTERVAL ? MINUTE),0)";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute([$fightid, $fight_data, FightStatus::WaitingForInput->value, $timeout]);
        } catch (\Throwable $e) {
            throw new \Exception("Unable to add new fight fightid $fightid.\n" . $e->getMessage());
        }

        // Additive, incremental validation of the new FIGHT/FightAction
        // mechanism against real gameplay data -- doesn't touch or
        // depend on anything above, which stays the sole, authoritative
        // persistence path this whole method already uses. A failure
        // here must never block a player from actually starting a
        // fight, hence the catch-and-log rather than letting it
        // propagate. Uses $this->db -- the same connection/lock session
        // as everything else in this method, not a second one competing
        // for the same LOCK TABLES fights holds until unlock_db() below.
        try {
            $stmt2 = $this->db->prepare("UPDATE fights SET initial_state = ? WHERE id = ?");
            $stmt2->execute([json_encode($fight->dehydrate()), $fightid]);
        } catch (\Throwable $e) {
            log_error("Non-fatal: failed to record initial_state for fightid $fightid: " . $e->getMessage());
        }

        //The fight was addedd successfully.  Now add the fight players data.
        $values = array();
        $params = array();
        foreach ($players as $player) {
            $values[] = "(?,?,?,?,0,'yes')";
            $params[] = $fightid;
            $params[] = $player['playerid'];
            $params[] = $player['teamid'];
            $params[] = $player['player_party'];
        }
        $query = "INSERT INTO fight_players (fightid,playerid,teamid,player_party,sequence,seen) VALUES " . implode(',', $values);
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
        } catch (\Throwable $e) {
            throw new \Exception("Unable to add player data to new fight fightid $fightid.\n" . $e->getMessage());
        }

        //Unlock the db
        $this->unlock_db();

        return $fightid;
    }

    function update_player(int $fightid, int $playerid, int $new_sequence) : ?bool {        
        //Get the current fight and sequence for this
        $fight_data = $this->get_fight($playerid, fightid:$fightid);
        if ($fight_data === false){
            log_error("Fight data came back null for player id");
            return null;
        }
        extract($fight_data);

        log_error("$sequence!=$new_sequence");
        //If new_sequence is <> sequence+1, then ignore
        if ($sequence != $new_sequence) {
            log_error ("Mismatched sequence number playerid $playerid sequence $sequence new_sequence $new_sequence");
            return null;
        }

        //Increment the player sequence
        $new_sequence++;

        log_error("Advancing fight");

        //Lock the db
        $this->lock_db(true);

        //Update the sequence for this player
        $fightid = $fight->id;
        $query = "UPDATE fight_players SET sequence=? WHERE fightid=? and playerid=?";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute([$new_sequence, $fightid, $playerid]);
        } catch (\Throwable $e) {
            throw new \Exception("Unable to update player sequence fightid $fightid playerid $playerid.\n" . $e->getMessage());
        }

        log_error("Player sequence updated.");

        //Now see if there are any other players waiting to get in on the action.
        $query = "SELECT count(*) count from fight_players WHERE sequence=? AND fightid=?";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute([$sequence, $fightid]);
        } catch (\Throwable $e) {
            throw new \Exception("Unable to query for players that are not ready fightid $fightid old sequence $sequence.\n" . $e->getMessage());
        }
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        log_error ("There are {$data['count']} players that have not signaled ready.");

        //If count is 0, then set the fight to processing.
        //This thread will be responsible for the fight processing.
        $count = (int)$data['count'];
        if ($count == 0) {
            $query = "UPDATE fights SET state=? WHERE id=?";
            try {
                $stmt = $this->db->prepare($query);
                $stmt->execute([FightStatus::Processing->value, $fightid]);
            } catch (\Throwable $e) {
                throw new \Exception("Unable to set fight to processing fightid $fightid.\n" . $e->getMessage());
            }
        }

        log_error("Fight $fightid marked for processing by player $playerid.");

        //Unlock the db
        $this->unlock_db();

        //Return true if count is 0 or false if not.
        return ($count == 0);
    }

    function has_timed_out(int $fightid, int $sequence_number) {
        //Lock the db
        $this->lock_db(true);

        //Check the timeout and active fields
        $query = "SELECT IF(NOW()>=timeout,1,0) process,unix_timestamp(timeout)-unix_timestamp(now()) timeout, state active, sequence FROM fights WHERE id=?";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute([$fightid]);
        } catch (\Throwable $e) {
            throw new \Exception("Unable to query if fight has timed out fightid $fightid.\n" . $e->getMessage());
        }
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        extract($data);

        //If sequences do not match, return null to indicate a fight reload.
        if ($sequence != $sequence_number)
            $retval = null;
        //The deal: if process==1 and active=WaitingForInput then we will process the fight.
        elseif ($process == 1 and $active == FightStatus::WaitingForInput->value) {
            $query = "UPDATE fights SET state=? WHERE id=?";
            try {
                $stmt = $this->db->prepare($query);
                $stmt->execute([FightStatus::Processing->value, $fightid]);
            } catch (\Throwable $e) {
                throw new \Exception("Unable to set timed out fight ot processing fightid $fightid.\n" . $e->getMessage());
            }
            $retval = true;
        }
        //Otherwise we won't
        else
            $retval = false;

        //Unlock the db
        $this->unlock_db();

        //Return the result.
        return array('expired' => $retval, 'timeout' => $timeout);
    }

    function update_timeout(FIGHT $fight, int $delay):int  //Delay is # of minutes
    {
        $fightid = $fight->id;

        //Lock the db
        $this->lock_db(true);

        //Update the fight timeout to the larger of timeout and now()+delay
        $query = "UPDATE fights SET timeout=LEAST(timeout,adddate(now(),INTERVAL ? MINUTE)) WHERE id=?";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute([$delay, $fightid]);
        } catch (\Throwable $e) {
            throw new \Exception("Unable to change fight timeout fightid $fightid.\n" . $e->getMessage());
        }
        $no_waiting = $stmt->rowCount();

        //Unlock the db
        $this->unlock_db();

        return $no_waiting;
    }

    function update_fight_action(FIGHT $fight, int $current_sequence) {
        //Setup the data.
        $fightid = $fight->id;
        $fight_data = serialize($fight);

        //Lock the db
        $this->lock_db(true);

        //Now verify the fight sequence data.
        $query = "SELECT sequence from fights WHERE id=? AND state=?";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute([$fightid, FightStatus::WaitingForInput->value]);
        } catch (\Throwable $e) {
            throw new \Exception("Unable to query the current fight sequence fightid $fightid.\n" . $e->getMessage());
        }
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($data === false || $data['sequence'] != $current_sequence) {
            # Not active or sequence does not match.
            log_error("Fight $fightid does not match current sequence or is inactive.");
            $this->unlock_db();            
            return false;
        }

        //Update the sequence for this fight
        $query = "UPDATE fights SET fight_data=? WHERE id=?";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute([$fight_data, $fightid]);
        } catch (\Throwable $e) {
            throw new \Exception("Unable to update current fight data fightid $fightid.\n" . $e->getMessage());
        }

        //Unlock the db
        $this->unlock_db();

        //Return true that the update completed.
        return true;
    }

    // Additive, incremental validation of the new fight_pending_actions
    // mechanism against real gameplay -- the FIGHT_STORE-level
    // counterpart to FIGHT::set_PC_action(), called by whoever handles
    // a player's command submission right after set_PC_action()
    // succeeds. Persists exactly the command/using/target that call
    // just validated and set on $character, from $fight itself (not
    // taken as separate parameters) so there's no way for this to
    // record something other than what set_PC_action() actually
    // applied. Same non-fatal catch-and-log as every other additive
    // write in this class: a player's command submission must still
    // succeed via the existing fight_data path even if this fails, and
    // uses $this->db, not a second connection.
    function save_pending_action(FIGHT $fight, int $party_index, int $group_index, int $char_index, int $playerid): void {
        try {
            $character = $fight->parties[$party_index]->groups[$group_index]->characters[$char_index];
            $pending_action = new PendingAction(
                $fight->id,
                $party_index,
                $group_index,
                $char_index,
                $playerid,
                $character->command,
                $character->using,
                $character->target,
            );
            $pending_action->save($this->db);
        } catch (\Throwable $e) {
            log_error("Non-fatal: failed to record PendingAction for fightid {$fight->id} party $party_index group $group_index char $char_index: " . $e->getMessage());
        }
    }

    function update_fight($prefight, $actions, $fight, $new_sequence) {
        //Setup the data.
        $fightid = $fight->id;
        $prefight_data = serialize($prefight);
        $action_data = serialize($actions);
        $fight_data = serialize($fight);
        //Find the initial timeout value, too.
        $timeout = $fight->count() * 2 + 30;

        //PS if there are less thatn two live parties, then set state to Resolved!!!
        $state = ($fight->count_live_parties() < 2) ? FightStatus::Resolved->value : FightStatus::WaitingForInput->value;

        //Lock the db
        $this->lock_db(true);

        //Now verify the fight sequence data.
        $query = "SELECT sequence from fights WHERE id=?";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute([$fightid]);
        } catch (\Throwable $e) {
            throw new \Exception('Search for fight $fightid failed.\n' . $e->getMessage());
        }
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($data === false) {
            throw new \Exception('Search for fight $fightid found nothing.');
        }
        if ($data['sequence'] + 1 != $new_sequence) {
            log_error("Sequence mismatch {$data['sequence']} $new_sequence");
            $this->unlock_db();
            return array('result' => false, 'timeout' => $timeout);
        }

        //Create an action entry/
        $query = "
            INSERT INTO fight_actions (fightid,sequence,prefight_data,action_data)
            VALUES (?,?,?,?)";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute([$fightid, $new_sequence, $prefight_data, $action_data]);
        } catch (\Throwable $e) {
            throw new \Exception("Insert for fight_actions $fightid sequence $new_sequence failed.\n" . $e->getMessage());
        }

        //Update the sequence for this fight
        $query = "UPDATE fights SET sequence=?, state=?, timeout=adddate(now(),INTERVAL ? MINUTE), fight_data=? WHERE id=?";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute([$new_sequence, $state, $timeout, $fight_data, $fightid]);
        } catch (\Throwable $e) {
            throw new \Exception("Update for fight $fightid to sequence $new_sequence failed.\n" . $e->getMessage());
        }

        // Additive, incremental validation of the new FightAction
        // mechanism against real gameplay data -- doesn't touch or
        // depend on anything above, which stays the sole, authoritative
        // persistence path this whole method already uses. $fight is
        // already the confirmed post-turn state at this point (it's
        // what $fight_data above was serialized from), so it's exactly
        // final_state's definition -- the dehydrated state immediately
        // after this action. Same catch-and-log as init_fight()'s
        // injection: never block an actual turn from completing. Uses
        // $this->db, the same connection/lock session as everything
        // else in this method.
        try {
            $fight_action = new FightAction($fightid, $new_sequence, $actions, $fight->dehydrate());
            $fight_action->save($this->db);
        } catch (\Throwable $e) {
            log_error("Non-fatal: failed to record FightAction for fightid $fightid sequence $new_sequence: " . $e->getMessage());
        }

        // Additive, same reasoning and same $this->db as the
        // FightAction write just above: xpdebt_json is server-only
        // bookkeeping (see fight-schema-v4.php), overwritten each turn
        // rather than appended, so this simply replaces it with
        // $fight's current, post-turn xp_debts snapshot.
        try {
            $stmt = $this->db->prepare("UPDATE fights SET xpdebt_json = ? WHERE id = ?");
            $stmt->execute([json_encode($fight->dehydrate_xp_debts()), $fightid]);
        } catch (\Throwable $e) {
            log_error("Non-fatal: failed to record xpdebt_json for fightid $fightid sequence $new_sequence: " . $e->getMessage());
        }

        //Update the sequence for players in this fight
        $query = "UPDATE fight_players SET sequence=?, seen='no' WHERE fightid=?";
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute([$new_sequence, $fightid]);
        } catch (\Throwable $e) {
            throw new \Exception("Update for fight_players $fightid to sequence $new_sequence failed.\n" . $e->getMessage());
        }

        //Unlock the db
        $this->unlock_db();

        //Return true that the update completed.
        return array('result' => true, 'timeout' => $timeout * 60);
    }
}
