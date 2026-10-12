#!/bin/env bash
BACKUP_PATH=backup_$(date +%Y%m%d_%H%M%S).sql
docker compose exec db bash -c "mysqldump -u root -p\$MYSQL_ROOT_PASSWORD --single-transaction --routines --triggers --events --add-drop-table dse" > $BACKUP_PATH
if head -n1 $BACKUP_PATH|grep 'MySQL dump' >/dev/null; then
    echo Successful backup saved to $BACKUP_PATH
else 
    echo Backup failed.
    cat $BACKUP_PATH
    rm -f $BACKUP_PATH
fi