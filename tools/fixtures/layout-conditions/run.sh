#!/usr/bin/env bash
#
# layout-conditions — run the whole suite against a site, in order, stopping at
# the first failing step. Adds no assertions of its own.
#
#   bash /mnt/d/Dev/Plugins/bws-generate-layout-conditions/tools/fixtures/layout-conditions/run.sh --site layout-conditions
#
# The upstream surface probe runs first so upstream drift is named before it
# shows up as local failures. The poisoned signal proof strips hooks, so it runs
# in its own process after the other eval-file suites. Each step also runs on its
# own; see README.md.
#
set -euo pipefail

SITE=''
ENV_ROOT="${WP_LITESPEED_ROOT:-${HOME}/wp-litespeed}"

err(){ echo -e "\033[31m[X]\033[0m ${*}" >&2; exit 1; }

while [ ${#} -gt 0 ]; do
    case "${1}" in
        -s|--site)  shift; SITE="${1:-}" ;;
        --env-root) shift; ENV_ROOT="${1:-}" ;;
        -h|--help)  echo "Usage: run.sh --site <site> [--env-root <path>]"; exit 0 ;;
        *) err "Unknown option: ${1}" ;;
    esac
    shift
done

[ -n "${SITE}" ]     || err "--site is required"
[ -d "${ENV_ROOT}" ] || err "env root not found: ${ENV_ROOT} (pass --env-root or set WP_LITESPEED_ROOT)"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Container path of the same working tree: eval-file runs inside the container.
TOOLS=/plugins/bws-generate-layout-conditions/tools

cd "${ENV_ROOT}"

step(){ echo -e "\n=== ${1} ==="; }
suite(){ step "${1}"; bash bin/wp.sh "${SITE}" eval-file "${TOOLS}/${1}"; }

suite probes/upstream-surface.php
suite fixtures/layout-conditions/seed.php
suite fixtures/layout-conditions/verify.php
suite fixtures/layout-conditions/seam-fidelity.php
suite fixtures/layout-conditions/poisoned-signal.php

step fixtures/layout-conditions/render-surface.sh
bash "${HERE}/render-surface.sh" --site "${SITE}" --env-root "${ENV_ROOT}"

echo -e "\n\033[32mSuccess: all six steps passed on ${SITE}.\033[0m"
