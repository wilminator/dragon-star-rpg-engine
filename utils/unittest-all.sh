#!/bin/env bash
./utils/unittest-js.sh && PROCESSES=14 utils/unittest-parallel.sh