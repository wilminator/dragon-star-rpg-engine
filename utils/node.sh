#!/bin/env bash
# Runs node in a throwaway container (removed when it exits), with the
# project mounted as the working directory. Same image as the js-test
# service (compose.yml). Run from the project root. Examples:
#
#   utils/node.sh utils/animation_manifest.js
#   utils/node.sh --test tests/javascript/animation_manifest.test.js
#   utils/node.sh                      (an interactive node prompt)
#
# Files it writes belong to you, not root.
tty=()
[ -t 0 ] && [ -t 1 ] && tty=(-it)
docker run --rm "${tty[@]}" \
    --user "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/project" \
    -w /var/www/project \
    node:22 node "$@"
