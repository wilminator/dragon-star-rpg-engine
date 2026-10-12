# legacy/

Old pages kept for reference only. They're outside `app/`, so they're
never served or copied into an image, and they don't run from here (their
include paths expect `app/`).

- `setup_fight.php`, `setup_equip.php`, `store.php`, `hero_use_thing.php`:
  replaced by the hub (`app/app.php`); the files of the same name in `app/`
  just send old links there.
- `mapindex.php`, `make_map.php`, `maptest*.php`, `test_stats.php`: the
  overworld map experiments. Their server calls (`fetch_map_data`,
  `fetch_map_size`) are off the allow-list until maps return; when they
  do, accept known map names and numeric coordinates only.
- `export.php`: an old effects export (uses ability fields that no longer
  exist).

The after-fight page (`proc_fight.php`) was removed: fights now settle
XP, levels, HP/MP and gold themselves when they end (`FIGHT` in
`app/include/fight.php`).
