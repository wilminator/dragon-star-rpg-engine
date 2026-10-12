#!/bin/env bash
docker compose run --rm test vendor/bin/phpunit "$@"