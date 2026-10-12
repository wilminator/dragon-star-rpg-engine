#!/bin/env bash
docker compose exec -T db bash -c "mysql -uroot -p\$MYSQL_ROOT_PASSWORD  dse" < $(find . -name 'backup_*.sql'|sort|tail -n1)