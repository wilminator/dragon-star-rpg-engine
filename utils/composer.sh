#!/bin/env bash
docker pull composer/composer
docker run --rm -it -v "$(pwd):/app" composer/composer "$@"