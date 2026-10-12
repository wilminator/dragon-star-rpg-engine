<?php

/**
 * job.php
 * jobs/classes object
 * @version 0.1.0
 * @copyright 2003 Mike Wilmes
 **/

require_once INCLUDE_GAME . 'common.php';
require_once INCLUDE_GAME . 'character.php';
require_once INCLUDE_GAME . 'ability.php';

class JOB implements DbStoreInterface, FileCacheInterface {
    use DbStoreTrait;
    use FileCacheTrait;

    protected static string $table_name = 'jobs';
    protected static string $table_key = 'jobid';
    protected static string $cache_path = CACHE_DIR . 'jobs.php';
    protected static bool $cache_includes_inactive = true;

    protected static string $drop_sql = "drop table if exists jobs";
    protected static function create_query(Database $db): string {
        $id_column = $db->auto_increment_primary_key('jobid');
        return <<<QUERY
CREATE TABLE IF NOT EXISTS jobs (
  $id_column,
  name varchar(64) NOT NULL default '',
  need int NOT NULL default 0,
  upgrade JSON NOT NULL default ('{"HP":0.0,"MP":0.0,"Speed":0.0,"Accuracy":0.0,"Strength":0.0,"Dodge":0.0,"Block":0.0,"Power":0.0,"Resistance":0.0,"Focus":0.0}'),
  abilities JSON NOT NULL default ('[]'),
  state int NOT NULL default 0
);
QUERY;
    }
    // Order must match translate_to_store()'s return order exactly.
    protected static array $save_columns = ['jobid', 'name', 'need', 'upgrade', 'abilities', 'state'];

    public ?int $jobid = null;
    public ObjectState $state;
    public string $name;
    public int $need;
    public array $upgrade;
    public array $abilities;

    function __construct(string $name, int $need, array $upgrade, array $abilities) {
        $this->name = $name;
        $this->need = $need;
        $this->upgrade = $upgrade;
        $this->abilities = $abilities;
    }

    public function don(CHARACTER $character, bool $no_reset = false) : void {
        if ($character->level == 0) {
            foreach ($character->base as $index => $val) {
                $character->base[$index] = round($this->upgrade[$index] * (90 + rand(0, 55 - $this->upgrade[$index])) / 100.0);
                $character->current[$index] = $character->base[$index];
            }
        } else {
            if ($character->level < 100 && !$no_reset) {
                $perc = $character->level / 100.0;
                foreach ($character->base as $index => $val) {
                    $character->base[$index] = round($character->base[$index] * $perc);
                    $character->current[$index] = round($character->current[$index] * $perc);
                }
            }
        }
        $character->level = 1;
        $character->exp = 0;
        $character->need = $this->need;
        $this->grant_abilities($character);
        $character->reset_stats(true); // Sets current to base
    }

    public function level(CHARACTER $character) : array {
        foreach ($character->base as $index => $val)
            $character->base[$index] += floor($this->upgrade[$index] * (75 + rand(0, 25)) / 100.0);
        $character->level++;
        $retval = $this->grant_abilities($character);
        $character->reset_stats();

        return $retval;
    }

    public function grant_abilities(CHARACTER $character) : array {
        $retval = array();
        //Add earned abilities
        $active_ids = ABILITY::filter_active(array_column($this->abilities, 'ability'));
        foreach ($this->abilities as $data)
            if (in_array($data['ability'], $active_ids)) {
                $ability = ABILITY::get($data['ability']);            
                if ($character->level >= $data['level']) {
                    if ($character->add_ability($data['ability'])) {                    
                        $retval[] = "{$character->name} has learned {$ability->name}.";
                    }
                }
            }
        uasort($character->abilities, 'ability_sort');
        return $retval;
    }

    public function describe_stats() : string{
        $response = array();
        foreach ($this->upgrade as $index => $stat)
            if ($stat != 0)
                $response[] = "$index $stat";
        return implode(' ', $response);
    }

    protected static function translate_and_build(array $data): JOB {
        $jobid = $data['jobid'];
        unset($data['jobid']);
        $state_value = $data['state'];
        unset($data['state']);
        $data['upgrade'] = is_null($data['upgrade']) ? ["HP"=>0.0,"MP"=>0.0,"Speed"=>0.0,"Accuracy"=>0.0,"Strength"=>0.0,"Dodge"=>0.0,"Block"=>0.0,"Power"=>0.0,"Resistance"=>0.0,"Focus"=>0.0] : json_decode($data['upgrade'], true);
        $data['abilities'] = is_null($data['abilities']) ? [] : json_decode($data['abilities'], true);
        $job =  new JOB(...$data);
        $job->jobid = $jobid;
        $job->state = ObjectState::from($state_value);
        return $job;
    }

    public function translate_to_store(): array {
        $upgrade = json_encode($this->upgrade);
        $abilities = json_encode($this->abilities);
        $state = $this->state->value;
        return [
            $this->jobid,
            $this->name,
            $this->need,
            $upgrade,
            $abilities,
            $state
        ];
    }
}
