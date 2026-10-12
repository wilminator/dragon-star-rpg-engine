#!/bin/env bash
# No longer needed for the tests: they use the test-db service (see
# compose.yml), which sets up its own test user on every start. Kept for
# a test database on the game's own db, if you ever want one.
docker compose exec db mysql -uroot -prootpass -e "
CREATE DATABASE IF NOT EXISTS dragonstar_test;
CREATE USER IF NOT EXISTS 'dragonstar_test'@'%';
ALTER USER 'dragonstar_test'@'%' IDENTIFIED BY '$(grep DB_TEST_PASSWORD .env|cut -f2 -d=)';
GRANT ALL PRIVILEGES ON dragonstar_test.* TO 'dragonstar_test'@'%';
-- Parallel test runs (utils/unittest-parallel.sh) use dragonstar_test_1, _2, ...
GRANT ALL PRIVILEGES ON \`dragonstar\\_test\\_%\`.* TO 'dragonstar_test'@'%';
FLUSH PRIVILEGES;
"