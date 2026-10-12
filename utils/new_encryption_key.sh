#!/bin/env bash
# Puts a new encryption key in one of the two key slots (the Docker
# secrets secrets/dragonstar_key_1 and _2 -- see app/include/secret_box.php).
#
#   utils/new_encryption_key.sh          new install: makes both files, a key in slot 1
#                                        running site: a new key in the slot not holding the current key
#   utils/new_encryption_key.sh 2        a new key in slot 2
#
# On a running site, the next login attempt re-encrypts everything with the
# new key. Keep the old key in its slot until
#   docker compose exec web php admin/encryption_keys.php status
# shows it retired.
set -e
cd "$(dirname "$0")/.."
mkdir -p secrets
for n in 1 2; do [ -e "secrets/dragonstar_key_$n" ] || : > "secrets/dragonstar_key_$n"; done

slot="$1"
if [ -z "$slot" ]; then
    if docker compose ps --status running web 2>/dev/null | grep -q web; then
        slot=$(docker compose exec -T web php admin/encryption_keys.php free-slot)
    elif [ ! -s secrets/dragonstar_key_1 ]; then
        slot=1
    else
        echo "The site isn't running, so which slot is free is unknown. Name one: $0 1|2" >&2
        exit 1
    fi
fi
case "$slot" in 1|2) ;; *) echo "The slot is 1 or 2." >&2; exit 1;; esac

# Written in place, so a running container sees it at once.
head -c 32 /dev/urandom | base64 > "secrets/dragonstar_key_$slot"
# Readable by the web server (Compose file secrets keep the file's own
# permissions); the secrets folder itself is private to its owner.
chmod 644 "secrets/dragonstar_key_$slot"
chmod 711 secrets
echo "New key in slot $slot."
