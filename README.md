# Dragon Star RPG Engine

A browser RPG with turn-based, multiplayer fights, written in PHP. It began in the mid-2000s as the engine behind [wilminator.com](https://wilminator.com) and was revived and modernized in 2026. Its ideas carry on in [TerraForma](https://github.com/wilminator/terraforma).

## Play it locally

You need Docker.

```sh
cp .env.template .env      # then set your own passwords
utils/new_encryption_key.sh
docker compose up
```

Then open the game in your browser, log in as `admin`, go to `/admin/init_db.php` and choose **"Yes, but use the existing variable files."** That loads the heroes, monsters, items, abilities and jobs from `app/include/cache/`. See [DOCKER_README.md](DOCKER_README.md) for accounts, mail and the rest of the setup.

## Tests

```sh
utils/unittest-all.sh
```

## Assets

Every image, sound and font in this repo is original, generated for this project, or openly licensed. See [CREDITS.md](CREDITS.md). The scripts that generated the replacement art and audio are in `utils/asset-generators/`.

## License

The code is licensed under the GNU Affero General Public License, version 3 (see [LICENSE](LICENSE)). Assets carry the licenses listed in [CREDITS.md](CREDITS.md).

Copyright (C) Michael Wilmes.
