#!/bin/env bash
# Creates an account in the running site -- use it for the first admin:
#   utils/create_account.sh --username=you --email=you@example.com --admin
# See app/admin/create_account.php for every option.
docker compose exec web php admin/create_account.php "$@"
