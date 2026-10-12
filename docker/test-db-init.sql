-- Runs when the test-db service starts (its data lives in memory, so
-- every start is a fresh server). The entrypoint has already made the
-- dragonstar_test user and database from the service's environment;
-- this lets that user also make and use dragonstar_test_1, _2, ... --
-- one per process in a parallel run (utils/unittest-parallel.sh).
GRANT ALL PRIVILEGES ON `dragonstar\_test\_%`.* TO 'dragonstar_test'@'%';
FLUSH PRIVILEGES;
