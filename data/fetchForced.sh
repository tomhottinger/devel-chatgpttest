#!/usr/bin/env bash
pushd $(dirname "${BASH_SOURCE[0]:-${(%):-%x}}")
git fetch --all
git reset --hard origin/main
popd

