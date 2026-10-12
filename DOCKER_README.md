# Running dse_0-6-0 under PHP 8.4 (Docker)

## Start it

```
docker compose up --build
```

App: http://localhost:8080/
(`web` container runs PHP 8.4 + Apache + mysqli/gd; `db` container is MySQL 8,
data persisted in the `db_data` volume across restarts.)

## Encryption keys (before the first start)

Secrets the site must read back -- authenticator-app secrets and the mail
OAuth refresh token -- are stored encrypted. The keys live outside the
database, in two Docker secrets (`secrets/dragonstar_key_1` and `_2`), which
must exist before `docker compose up`:

```
utils/new_encryption_key.sh
```

To change keys later, run the same command while the site is up: it puts a
new key in the slot not holding the current one. The next login attempt
re-encrypts everything with it; logins wait (a few seconds) while it runs.
Leave the old key where it is until

```
docker compose exec web php admin/encryption_keys.php status
```

shows it "retired" -- then that slot is the one the next change will use.
Never replace the key marked "current": anything encrypted with it would be
lost. The `secrets/` folder is kept out of git, the Docker image and the
project zip; back it up separately from the database.

Passwords aren't encrypted: they're hashed with Argon2id and can't be read
back by anyone.

## First run

Accounts live in the `users`/`permissions` tables, created on the first
request that touches them (e.g. loading `/login.php`). No account is made
automatically: create the first admin from the command line, then log in
with it:

```
utils/create_account.sh --username=you --email=you@example.com --admin
```

It asks for the password twice (or reads it from standard input with
`--password-stdin`). Leave out `--admin` for a plain player account, or give
`--rights=SPECTATE_FIGHTS,EDIT_ITEM` for particular rights. Other players can
sign up at `/register.php`; admins manage accounts and rights from the admin
menu.

Mail goes to the mailpit container in development: read it at
http://localhost:8025. See `app/include/mail.php` for sending real mail
(SMTP, Gmail or Outlook).

The actual game-content tables (characters, items, jobs, monsters, etc.)
are NOT auto-created — that's intentional, matching the original app's
design (accidental data loss on a content table is worse than on `users`).
After logging in as `admin`, go to `/admin/init_db.php` and choose
"start all over" to create them.

## What's still expected to break

With `display_errors`/`error_reporting` turned all the way up in
`docker/php-dev.ini`, these should surface directly in the browser rather
than as blank pages, which should make them easy to track down as you hit
them page by page.

== Claude AI help ==
A zip command that only grabs salient files
```bash
pushd .. && rm dragonstar.zip && zip -r dragonstar.zip dragonstar   -x "dragonstar/.git/*" -x "dragonstar/.git" -x "*/cache/*" -x "dragonstar/app/include/cache copy/*"   -x "dragonstar/app/music/*"   -x "dragonstar/app/sound/*"   -x "dragonstar/app/images/*"   -x "dragonstar/*.sql" -x "dragonstar/dse_dump" -x "*/errorlog.txt" && popd
```

Grab the PHP errors written to stdout
```bash
docker compose logs -f|grep PHP
```

Generate a database backup
```bash
docker compose exec db mysqldump -u root -p$DB_PASS --single-transaction --routines --triggers --events --add-drop-table dse > backup_$(date +%Y%m%d_%H%M%S).sql
```

Restore last database backup
```bash
docker compose exec -T db mysql -u root -p$DB_PASS dse < $(find . -name 'backup_*.sql'|sort|tail -n1)
```

Run a DB query
```bash
echo 'select * from team_members;'|docker compose exec -T db mysql -uroot -p$DB_PASS dse
```