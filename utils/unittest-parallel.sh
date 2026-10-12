#!/bin/env bash
# Runs the PHP tests across several processes with ParaTest -- by default
# one per CPU core; set PROCESSES to choose. Each process gets its own
# MySQL and Postgres test database (dragonstar_test_<n>, made on first use)
# and its own fixture cache, so they can't disturb each other. Takes the
# same arguments as utils/unittest.sh (--filter and so on).
docker compose run --rm test vendor/bin/paratest --processes="${PROCESSES:-auto}" "$@"
