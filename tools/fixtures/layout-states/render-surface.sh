#!/usr/bin/env bash
#
# layout-states — render-level test harness (T11 / V14, V24, V25).
#
# Asserts on RENDERED HTML. Everything else in this blueprint runs under wp-cli,
# and for these invariants wp-cli is structurally blind:
#
#   * generate_disable_elements() returns '' on any non-singular request, and
#     under `wp eval` there is no $post — so GP Premium's implementation and this
#     plugin's neutralize are INDISTINGUISHABLE from the CLI. Both return ''.
#     That false negative is exactly how the load-order bug fixed alongside this
#     file survived: every CLI check reported success while the neutralize had
#     never once run on a real request.
#   * The CSS-only surfaces (V24) are, by definition, only observable in output.
#     Nothing is removed from the DOM; a rule is emitted. Only the response body
#     shows whether it was.
#
# So this is not "an HTTP test for completeness" — it is the only place several
# documented invariants can be checked at all.
#
# Usage:
#   tools/fixtures/layout-states/render-surface.sh --site testbed
#
# Run from the wp-litespeed env root (it shells out to docker compose there), or
# pass --env-root. Preconditions: layout-states seeded at blueprint v2+ — v1
# fixtures cannot support these assertions (no featured image, no nav menus, and
# the Menu Plus mobile header never enabled; see the manifest's `options` note).
# The script verifies that rather than trusting it.
#
set -euo pipefail

# Git Bash (MSYS2) rewrites POSIX-looking args into Windows paths before exec,
# mangling container paths. Disable for this script. Same reason as smoke.sh.
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

SITE=''
ENV_ROOT="${WP_LITESPEED_ROOT:-/d/Environments/wp-litespeed}"
PASS=0
FAIL=0

ok(){  echo -e "  \033[32mPASS\033[0m ${*}"; PASS=$((PASS+1)); }
bad(){ echo -e "  \033[31mFAIL\033[0m ${*}"; FAIL=$((FAIL+1)); }
err(){ echo -e "\033[31m[X]\033[0m ${*}" >&2; exit 1; }

while [ ${#} -gt 0 ]; do
    case "${1}" in
        -s|--site)  shift; SITE="${1:-}" ;;
        --env-root) shift; ENV_ROOT="${1:-}" ;;
        -h|--help)  echo "Usage: render-surface.sh --site <site> [--env-root <path>]"; exit 0 ;;
        *) err "Unknown option: ${1}" ;;
    esac
    shift
done

[ -n "${SITE}" ]     || err "--site is required"
[ -d "${ENV_ROOT}" ] || err "env root not found: ${ENV_ROOT} (pass --env-root or set WP_LITESPEED_ROOT)"

cd "${ENV_ROOT}"

# Resolve the vhost domain the same way smoke.sh and seed-all.sh do — from the
# OLS config, so the URL can never drift from what the server actually serves.
DOMAINS=$(docker compose exec -T litespeed \
    bash -c "sed -n '/^  member ${SITE} {/,/^  }/p' /usr/local/lsws/conf/httpd_config.conf | grep vhDomain | awk '{print \$2}'" \
    2>/dev/null | tr -d '\r' || true)
[ -n "${DOMAINS}" ] || err "no vhost for site '${SITE}'"
# shellcheck disable=SC2206
DOMAIN_ARR=(${DOMAINS//,/ })
MAIN="${DOMAIN_ARR[0]}"

# ALL http goes through a container curl, never the host's.
#
# --network host is REQUIRED, not incidental: --resolve points at 127.0.0.1, and
# inside a bare container that is the container itself, so every request fails to
# connect. curl then writes nothing to stdout and, with -sS piped to a grep,
# that reads as "marker absent" — i.e. every absence assertion PASSES against an
# empty body. Cost me a full false-green pass while writing this file; the
# response-sanity check below exists so it cannot happen silently again.
#
# -k deliberate: this tests RENDERED OUTPUT, not certificate trust.
CURL_IMG='curlimages/curl:latest'

# Cache-bust every request. LiteSpeed serves x-litespeed-cache: hit aggressively
# here, and a cached body predates whatever change is being verified — the
# failure looks like "the fix did not work" when the fix was never fetched.
NONCE="t11-$$"

# OPCACHE. The nastier of the two caches, and the one that produces FALSE GREENS.
#
# This container runs opcache.revalidate_freq=120, so PHP re-checks file mtimes
# at most every 2 minutes. Edit a plugin file and fetch a page inside that
# window and the response is rendered by the PREVIOUS bytecode — the old
# behaviour, reported as if current.
#
# It is asymmetric in the worst way: opcache.enable_cli=Off, so wp-cli always
# reads fresh source. A CLI check and an HTTP check of the same edit can
# therefore DISAGREE, with the CLI correct and the render stale. Verified the
# hard way — a mutation test of this very file passed 18/18 against stale
# bytecode while the plugin was demonstrably broken.
#
# So: recycle the PHP workers before asserting, rather than hoping 120s elapsed.
# Cheap, and it makes every run independent of edit timing.
echo ""
echo "recycling lsphp workers (defeats opcache.revalidate_freq=120)"
docker compose exec -T litespeed bash -c 'killall lsphp 2>/dev/null; true' >/dev/null 2>&1 || true
sleep 2

fetch(){
    docker run --rm --network host "${CURL_IMG}" \
        -sS -k --resolve "${MAIN}:443:127.0.0.1" \
        "https://${MAIN}/${1}/?nocache=${NONCE}" 2>/dev/null
}

# ---------------------------------------------------------------------------
# 0. Preconditions.
#
# Every assertion below is an absence-or-presence check against a response body.
# An empty or error body makes the absence checks pass vacuously, so the body
# must be proven real BEFORE anything is asserted about it. Hard-abort, not a
# FAIL: a broken fetch invalidates the whole run, it is not one bad result.
# ---------------------------------------------------------------------------
echo ""
echo "0. preconditions (site: ${SITE}, domain: ${MAIN})"

BASELINE=$(fetch 'ls-page-baseline' || true)

[ -n "${BASELINE}" ] || err "empty response for ls-page-baseline — check --network host and that the stack is up. Every absence assertion would pass vacuously against this."

case "${BASELINE}" in
    *'</html>'*) ok "baseline response is a complete HTML document ($(printf '%s' "${BASELINE}" | wc -c) bytes)" ;;
    *) err "baseline response is not complete HTML — refusing to assert against a truncated body" ;;
esac

# Blueprint v2 preconditions. Each of these was ABSENT at v1, and each absence
# turns a specific assertion below into a vacuous pass. Checked on the baseline
# page, where all three must render regardless of any disable toggle.
case "${BASELINE}" in
    *'ls-fixture-image'*) ok 'baseline carries a featured image (v2 fixture)' ;;
    *) err 'baseline has no featured image — reseed layout-states at v2+. Without it the V24 featured-image assertions cannot fail.' ;;
esac

case "${BASELINE}" in
    *'id="site-navigation"'*) ok 'baseline renders #site-navigation (nav menu assigned, v2)' ;;
    *) err 'no #site-navigation on baseline — no menu assigned to the primary location. Reseed layout-states at v2+.' ;;
esac

case "${BASELINE}" in
    *'id="secondary-navigation"'*) ok 'baseline renders #secondary-navigation (v2)' ;;
    *) err 'no #secondary-navigation on baseline — reseed layout-states at v2+.' ;;
esac

case "${BASELINE}" in
    *'id="mobile-header"'*) ok 'baseline renders #mobile-header (Menu Plus mobile header ON, v2)' ;;
    *) err 'no #mobile-header on baseline — generate_menu_plus_settings mobile_header is not enabled. V25 cannot be observed. Reseed layout-states at v2+ (v1 wrote this as a theme_mod, which GP never reads).' ;;
esac

# ---------------------------------------------------------------------------
# 1. The neutralize is live (V12).
#
# The plugin pre-defines generate_disable_elements() to return '' so GP's CSS
# path emits nothing. Both definitions are function_exists-guarded, so this is a
# load-order race — and through 0.2.0 the plugin lost it, silently, on every
# request. These assertions are the render-level proof that it now wins.
#
# Asserted as the ABSENCE of GP's exact rule strings (functions.php:40-68) on
# pages whose toggle is ON. Matching the literal upstream CSS rather than a
# generic 'display:none' matters: the theme and blocks emit unrelated
# display:none rules on every page including baseline, so a generic count cannot
# discriminate and would fail on the control.
# ---------------------------------------------------------------------------
echo ""
echo "1. CSS-neutralize suppresses GP's per-post rules (V12)"

FEATURED=$(fetch 'ls-page-metabox-featured' || true)
SECNAV=$(fetch 'ls-page-metabox-secondary-nav' || true)
NAV=$(fetch 'ls-page-metabox-nav' || true)
PHPREM=$(fetch 'ls-page-metabox-php-removed' || true)

for name in FEATURED SECNAV NAV PHPREM; do
    body="${!name}"
    [ -n "${body}" ] || err "empty response for ${name} — aborting rather than asserting against nothing"
done

RULE_IMAGE='.generate-page-header, .page-header-image, .page-header-image-single {display:none}'
RULE_SECNAV='#secondary-navigation {display:none}'
RULE_NAV='#site-navigation,.navigation-clone, #mobile-header {display:none !important}'

case "${FEATURED}" in
    *"${RULE_IMAGE}"*) bad "featured-image disable rule IS emitted — neutralize not in effect. GP Premium likely won the generate_disable_elements() race; check plugin load order." ;;
    *) ok 'no featured-image display:none rule (neutralize won the definition race)' ;;
esac

case "${SECNAV}" in
    *"${RULE_SECNAV}"*) bad 'secondary-nav disable rule IS emitted — neutralize not in effect' ;;
    *) ok 'no secondary-nav display:none rule' ;;
esac

case "${NAV}" in
    *"${RULE_NAV}"*) bad 'primary-nav disable rule IS emitted — neutralize not in effect' ;;
    *) ok 'no primary-nav display:none rule' ;;
esac

# ---------------------------------------------------------------------------
# 2. V24 — which toggles are CSS-only, and which are PHP-removed.
#
# This is the invariant's actual content: with the CSS suppressed, a CSS-ONLY
# toggle leaves its markup fully present (that is the regression surface), while
# a PHP-REMOVED toggle still removes its markup (so neutralizing its CSS costs
# nothing). Both directions are asserted — checking only one would let a change
# that PHP-removed everything, or nothing, pass half the suite.
# ---------------------------------------------------------------------------
echo ""
echo "2. V24 — CSS-only vs PHP-removed"

# Featured Image: CSS-only. Markup must survive with the toggle ON.
case "${FEATURED}" in
    *'ls-fixture-image'*) ok 'CSS-only: featured image markup still present with toggle ON (V24 regression surface, confirmed)' ;;
    *) bad 'featured image markup is GONE with the toggle on — V24 says this toggle is CSS-only with no PHP removal. Upstream may have added a PHP path; re-read V24 before changing this expectation.' ;;
esac

# Secondary Nav: CSS-only. Wrapper must survive.
case "${SECNAV}" in
    *'id="secondary-navigation"'*) ok 'CSS-only: #secondary-navigation still present with toggle ON (V24 regression surface, confirmed)' ;;
    *) bad '#secondary-navigation is GONE with the toggle on — V24 says CSS-only. Upstream may have added PHP removal.' ;;
esac

# Content title: PHP-removed. Markup must be gone, so neutralize is a no-op.
case "${PHPREM}" in
    *'entry-header'*) bad 'entry-header still present with the content-title toggle ON — V24 claims this is PHP-removed, which is why neutralizing its CSS is safe. If it is actually CSS-only, the V14 regression surface is WIDER than documented.' ;;
    *) ok 'PHP-removed: entry-header absent with toggle ON (neutralize is a no-op here, as V24 claims)' ;;
esac

# ---------------------------------------------------------------------------
# 3. V25 — Primary Nav is PARTIALLY CSS-load-bearing.
#
# The subtlest claim in the set, and until blueprint v2 it had NEVER been
# observed: the mobile header was off (the setting was written as a theme_mod,
# which GP does not read), so <nav id="mobile-header"> never rendered and any
# V25 assertion would have passed vacuously. V25 was documented from reading
# GP's source, not from seeing output. These two assertions are its first
# empirical check.
#
# The claim: _generate-disable-nav PHP-kills the SOURCE nav (#site-navigation)
# via the generate_navigation_location filter, but the <nav id="mobile-header">
# WRAPPER is rendered gated only on mobile_header !== 'disable'
# (generate-menu-plus.php:1082) and is hidden by CSS alone. So with the CSS
# neutralized, the wrapper is re-exposed — a real regression, and precisely what
# T10's PHP suppression exists to close.
# ---------------------------------------------------------------------------
echo ""
echo "3. V25 — primary nav: PHP kills the source, CSS alone hid the wrapper"

case "${NAV}" in
    *'id="site-navigation"'*) bad '#site-navigation still present with the primary-nav toggle ON — V25 says the PHP path (generate_navigation_location => __return_false) removes it outright.' ;;
    *) ok 'PHP-removed: #site-navigation absent with toggle ON' ;;
esac

case "${NAV}" in
    *'id="mobile-header"'*) ok 'V25 CONFIRMED: #mobile-header wrapper SURVIVES the PHP path and is re-exposed once its CSS is neutralized (the documented regression)' ;;
    *) bad 'V25 NOT reproduced: #mobile-header is absent with the toggle on, so the wrapper is not CSS-load-bearing after all. Either upstream changed generate-menu-plus.php, or the mobile header is off. V25 needs rewriting — do not just delete this assertion.' ;;
esac

# ---------------------------------------------------------------------------
# 4. Control — the baseline must NOT look disabled.
#
# Without this, a change that removed these elements everywhere (or emitted no
# CSS at all because the whole module broke) would pass every absence assertion
# above. The control is what makes them mean something.
# ---------------------------------------------------------------------------
echo ""
echo "4. control — baseline renders everything"

for marker in 'id="site-navigation"' 'id="secondary-navigation"' 'id="mobile-header"' 'ls-fixture-image' 'entry-header'; do
    case "${BASELINE}" in
        *"${marker}"*) ok "baseline renders ${marker}" ;;
        *) bad "baseline is MISSING ${marker} — the absence assertions above prove nothing if the control does not render it" ;;
    esac
done

# ---------------------------------------------------------------------------
echo ""
if [ "${FAIL}" -gt 0 ]; then
    echo -e "\033[31mError: ${PASS} passed, ${FAIL} FAILED.\033[0m" >&2
    exit 1
fi

echo -e "\033[32mSuccess: ${PASS} passed, 0 failed.\033[0m"
