<?php
define ('INCLUDE_GAME','include/');

require_once INCLUDE_GAME.'job.php';
require_once INCLUDE_GAME.'item.php';
require_once INCLUDE_GAME.'ability.php';
require_once INCLUDE_GAME.'personality.php';

// Export effects file.
$file = fopen('effects.txt','wt');
$ID = 1;
fwrite($file, "#ID, Name, Type, Effect Modifier, Stat1, Stat2, Stat3\n");
foreach (ABILITY::load_all_from_cache() as $ability) {
  $name = "{$ability->name} Ability";
  $ability->EffectID = $ID;
  fwrite($file, "{$ID},{$name},{$ability->effect},{$ability->attribute},{$ability->base},{$ability->added},0\n");
  $ID++;
}
fclose($file);
?>