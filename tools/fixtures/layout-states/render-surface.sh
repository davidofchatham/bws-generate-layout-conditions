#!/usr/bin/env bash
#
# layout-states — render-level test harness (T11 + T10 + T15/T16 + T17/T18 +
# ADR-0006 / V14, V24, V25, V31, V32).
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
# WHAT IT STILL CANNOT SEE: layout. Every assertion here is a string match on a
# response body, so it proves an element is absent from the MARKUP — never that
# its removal left the page looking right. curl has no viewport, which matters
# most for V25, whose entire subject is a wrapper that only appears at mobile
# width. That gap is closed by eye, not here (done 2026-07-21; see V25).
#
# Usage:
#   tools/fixtures/layout-states/render-surface.sh --site testbed
#
# Run from the wp-litespeed env root (it shells out to docker compose there), or
# pass --env-root. Preconditions: layout-states seeded at blueprint v8 — earlier
# fixtures cannot support these assertions (v1: no featured image, no nav menus,
# Menu Plus mobile header never enabled; v2: no thumbnail on the two nav-toggle
# pages, which makes T10's over-suppression checks vacuous; v3: no archive
# content-title element, which makes section 6 vacuous; v4: no secondary-nav
# Layout Element, which makes section 7 vacuous; v5: the GP Premium Blog module
# unpinned, so the featured-image assertions ran against whichever of the two
# render paths that testbed happened to have live — B8; v6: the Page Hero element
# carried no _generate_hook and was never loaded, making section 8's relocation
# assertion vacuous — B9; v7: no conditioned blocks and no kill-switch fixture, so
# section 9 has nothing to read). The script verifies all of that rather than
# trusting it, and every one of those checks HARD-ABORTS: a stale seed must stop
# the run, not fail an assertion, because each shortfall turns a specific absence
# check into a pass.
#
# TWO ERAS OF ASSERTION, and the difference matters when reading a failure:
#   * T11 assertions CHARACTERIZE the pre-T10 surface — which toggles GP leaves
#     CSS-only, and that the neutralize is live.
#   * T10 assertions (sections 2, 3 and 5) are the INVERSE of what T11 originally
#     asserted for the three CSS-only surfaces. T11 proved the markup SURVIVED —
#     that was the V14 regression. T10 removes it in PHP, so the same fixtures now
#     prove it is GONE. A failure there means the suppression did not run; section
#     5 is what distinguishes "did not run" from "ran too broadly".
#   * Section 6 (T15/T16) is a third era and the first to assert on an ARCHIVE.
#     Its gp-no-content-title check is likewise an inversion: hook-state emitted
#     that class there, Meaning A does not.
#   * Section 7 (T17) is neither characterization nor inversion — it is the first
#     section covering a layer that had NO signal at all before it, so both its
#     positive assertions were red for the whole life of the plugin until T17.
#   * Section 8 (ADR-0006) is a second inversion pass, on the featured image. The
#     hero page and the archive both used to emit gp-no-featured-image and must
#     not now — and so, on this testbed, did the baseline, which is V33 showing
#     up in the harness rather than a fixture quirk. See the section header.
#   * Section 9 (issue #5) is a fifth era and a different KIND of assertion. Every
#     section above reads markup this plugin or GP emits; section 9 reads the
#     chain an author actually relies on — tick a toggle, and a block conditioned
#     on the rule stops rendering. It is the first render-level coverage of the
#     two featured-image rules answering, and the first of the GB Pro condition
#     surface at all.
#
set -euo pipefail

# Git Bash (MSYS2) rewrites POSIX-looking args into Windows paths before exec,
# mangling container paths. Disable for this script. Same reason as smoke.sh.
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

SITE=''
ENV_ROOT="${WP_LITESPEED_ROOT:-${HOME}/wp-litespeed}"
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

# Blueprint v6 precondition (B8) — WHICH featured-image path is live.
#
# The image has two render paths and only one runs at a time. The theme's
# page-header path fires on `generate_after_header`, before <div id="page">, so
# its markup lands OUTSIDE #content. The GP Premium Blog module's path fires on
# `generate_before_content` at the pinned `inside-content` position, inside
# content-page.php, so its markup lands INSIDE #content. Position is therefore a
# reliable discriminator here where the class names are not — both wrappers carry
# `page-header-image` on a page.
#
# This matters because the module CHOOSES the path: when active it removes both
# theme actions unconditionally at wp:50 (blog/functions/images.php:164-165). With
# it inactive — testbed's state through blueprint v5 — section 2 exercised only
# the theme half, and the plugin's suppression covered only that half and passed
# green while the toggle did nothing at all on a Blog-module-active site (B8).
case "${BASELINE}" in
    *'page-header-image'*) : ;;
    *) err 'baseline renders no page-header-image wrapper — the path check below would read the whole body and pass for the wrong reason. Reseed layout-states at v2+.' ;;
esac

case "${BASELINE%%page-header-image*}" in
    *'id="content"'*) ok 'baseline featured image renders on the BLOG-module path, inside #content (v6)' ;;
    *) err 'baseline featured image renders OUTSIDE #content — that is the theme page-header path, so the Blog module is inactive or its image position is not inside-content. Section 2 would then exercise half the surface B8 is about, exactly as it did through v5. Activate generate_package_blog and reseed layout-states at v6+.' ;;
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
# 1. The CSS suppression is live (V12).
#
# The plugin pre-defines generate_disable_elements() to return '' so GP's CSS
# path emits nothing. Both definitions are function_exists-guarded, so this is a
# load-order race — and through 0.2.0 the plugin lost it, silently, on every
# request. These assertions are the render-level proof that the CSS is gone.
#
# They assert the OUTPUT, not the mechanism, so they hold for either path: the
# neutralize when it wins the definition race, or the generate_de_scripts
# removal that takes over when it loses. That is deliberate — mutate the load
# order so GP wins the race and these should stay green. Ownership itself is
# reported separately by bws_glc_owns_disable_elements(); a lost race is a
# degradation to the fallback, not a failure of suppression.
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

# Blueprint v3 precondition. T10's over-suppression checks (section 5) assert the
# featured image STILL renders on the two nav-toggle pages; at v2 neither carried
# a thumbnail, so both passed against pages that render no image under any
# condition. Checked here rather than trusted, same as the v2 preconditions above.
case "${SECNAV}" in
    *'page-header-image'*) ok 'secondary-nav fixture renders a featured image (v3)' ;;
    *) err 'ls-page-metabox-secondary-nav renders no featured image — reseed layout-states at v3+. The over-suppression assertion in section 5 would pass vacuously.' ;;
esac

case "${NAV}" in
    *'page-header-image'*) ok 'primary-nav fixture renders a featured image (v3)' ;;
    *) err 'ls-page-metabox-nav renders no featured image — reseed layout-states at v3+. The over-suppression assertion in section 5 would pass vacuously.' ;;
esac

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

# Featured Image + Secondary Nav were the CSS-only surfaces (V24). Since T10 the
# plugin removes them in PHP, so the markup must now be GONE with the toggle ON.
#
# These two assertions are INVERTED relative to the pre-T10 baseline, deliberately.
# Before T10 they asserted the markup SURVIVED — that was the regression surface
# being characterized. T10 closes it, so the same fixtures now prove the opposite.
# A failure here means the suppression did not run; see section 5 for whether it
# ran too broadly.
# Matched on the `page-header-image` WRAPPER, not the attachment filename.
#
# The filename is the obvious marker and it is wrong for an ABSENCE check: the
# thumbnail URL also appears in og:image, twitter:image and Yoast's JSON-LD
# ImageObject, all emitted from post meta whether or not the image renders. A
# filename grep therefore reports "still present" against a page where the image
# is provably gone — which it did, as the first red run of this assertion.
#
# The wrapper class is emitted only by the render path itself
# (featured-images.php generate_featured_page_header_area), so it discriminates.
#
# Since blueprint v6 this is also B8's assertion. Section 0 proves the live path
# is the Blog module's, so a suppression that removes only the theme's two
# page-header actions fails HERE — which is precisely what it did not do while
# the module was off and the theme path was the only one being tested.
case "${FEATURED}" in
    *'page-header-image'*) bad 'featured image markup still present with toggle ON — the PHP suppression did not remove the live path. Section 0 says that path is the Blog module (generate_blog_single_featured_image, three possible hooks); check all three remove_action calls at wp:60, not just the theme page-header pair (B8), and that _generate-disable-post-image is set on this fixture.' ;;
    *) ok 'T10/B8: featured image PHP-removed with toggle ON, on the blog-module path (V24 regression closed)' ;;
esac

case "${SECNAV}" in
    *'id="secondary-navigation"'*) bad '#secondary-navigation still present with toggle ON — T10 has_nav_menu filter did not apply.' ;;
    *) ok 'T10: #secondary-navigation PHP-removed with toggle ON (V24 regression closed)' ;;
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

# INVERTED by T10, same as the two above. V25's wrapper survived GP's PHP path
# and was hidden by CSS alone; the plugin now removes it outright.
#
# The V25 claim itself is NOT retired by this — it is still what makes the
# suppression necessary. Section 0 proves the wrapper renders on baseline (so the
# mobile header is genuinely on), which is what keeps this assertion honest: if
# the mobile header were simply disabled, this would pass while proving nothing,
# and the baseline precondition is what rules that out.
case "${NAV}" in
    *'id="mobile-header"'*) bad '#mobile-header wrapper still present with the primary-nav toggle ON — T10 remove_action on generate_menu_plus_mobile_header did not apply. This is the V25 regression, still open.' ;;
    *) ok 'T10: #mobile-header wrapper PHP-removed with toggle ON (V25 regression closed)' ;;
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

for marker in 'id="site-navigation"' 'id="secondary-navigation"' 'id="mobile-header"' 'page-header-image' 'entry-header'; do
    case "${BASELINE}" in
        *"${marker}"*) ok "baseline renders ${marker}" ;;
        *) bad "baseline is MISSING ${marker} — the absence assertions above prove nothing if the control does not render it" ;;
    esac
done

# ---------------------------------------------------------------------------
# 5. T10 over-suppression — each toggle removes ONLY its own surface.
#
# Section 4's control is a page with NO toggles set, which proves the suppression
# is not unconditional. It does NOT catch the likelier bug: a toggle that fires
# but removes too much on the page where it legitimately applies — a mis-keyed
# meta read, or a has_nav_menu filter that forgets to check $location and so
# reports EVERY location unassigned, taking the primary nav with it.
#
# So: on each single-toggle fixture, assert the OTHER two surfaces still render.
# The secondary-nav page is the sharpest of these — #site-navigation surviving
# there is exactly what a $location-blind filter would break.
# ---------------------------------------------------------------------------
echo ""
echo "5. T10 — no over-suppression (each toggle hits only its own surface)"

# Secondary-nav toggle ON: primary nav and featured image must be untouched.
#
# NOTE ON WHAT THIS DOES *NOT* PROVE. The obvious reading is that it verifies the
# has_nav_menu filter checks $location. It does not, and a mutation test says so:
# deleting the $location guard entirely — returning false for EVERY location —
# still passes this suite 26/26.
#
# The reason is upstream. `has_nav_menu` is called for exactly one location in all
# of GeneratePress + GP Premium: 'secondary' (12 call sites, verified by grep;
# zero for 'primary' or any other). The primary nav does not consult it at all —
# it renders unconditionally with a page-list fallback — so a $location-blind
# filter is currently UNOBSERVABLE in rendered output.
#
# The guard is therefore defensive rather than load-bearing: correct, and required
# the moment GP or any third-party plugin asks about another location, but not
# something this harness can currently falsify. Keeping the assertion because it
# pins the upstream coupling — if GP ever does gate the primary nav on
# has_nav_menu, a blind filter starts failing here and the guard becomes testable.
case "${SECNAV}" in
    *'id="site-navigation"'*) ok 'secondary-nav toggle leaves #site-navigation intact' ;;
    *) bad '#site-navigation is GONE on the secondary-nav page — over-suppression. If the has_nav_menu $location guard was removed, upstream now gates the primary nav on it too; restore the guard.' ;;
esac

case "${SECNAV}" in
    *'id="mobile-header"'*) ok 'secondary-nav toggle leaves #mobile-header intact' ;;
    *) bad '#mobile-header is GONE on the secondary-nav page — over-suppression.' ;;
esac

# Featured-image toggle ON: both navs must be untouched.
case "${FEATURED}" in
    *'id="site-navigation"'*) ok 'featured-image toggle leaves #site-navigation intact' ;;
    *) bad '#site-navigation is GONE on the featured-image page — over-suppression.' ;;
esac

case "${FEATURED}" in
    *'id="secondary-navigation"'*) ok 'featured-image toggle leaves #secondary-navigation intact' ;;
    *) bad '#secondary-navigation is GONE on the featured-image page — over-suppression.' ;;
esac

# Primary-nav toggle ON: featured image and secondary nav must be untouched.
# (#site-navigation is legitimately absent here — GP's own PHP path, asserted in 3.)
case "${NAV}" in
    *'page-header-image'*) ok 'primary-nav toggle leaves the featured image intact' ;;
    *) bad 'featured image is GONE on the primary-nav page — over-suppression.' ;;
esac

case "${NAV}" in
    *'id="secondary-navigation"'*) ok 'primary-nav toggle leaves #secondary-navigation intact' ;;
    *) bad '#secondary-navigation is GONE on the primary-nav page — over-suppression.' ;;
esac

# ---------------------------------------------------------------------------
# 6. V31 / ADR-0005 — content title reports the PAGE-TITLE role.
#
# The one claim the PHPUnit fake structurally cannot test. The fake can encode
# the belief that GP leaves the archive HEADING standing when a Layout Element
# disables the content title; only a rendered archive can falsify it. If that
# belief is wrong, the whole off-singular short-circuit is wrong with it.
#
# The mechanism under test (class-layout.php:324) is one unguarded
# add_filter( 'generate_show_title', '__return_false' ). On an archive that
# filter gates the ITEM titles inside loop cards (content.php:35), while the
# <h1 class="page-title"> heading comes from a different hook entirely
# (generate_archive_title, archive.php:34). So one element produces both halves:
# item titles gone, heading intact.
#
# The gp-no-content-title assertion is INVERTED relative to pre-ADR-0005
# behaviour, exactly like the T10 rows above: hook-state saw that __return_false
# and emitted the class on this archive. It must now be absent.
#
# Marker discipline, same lesson as the featured-image filename (section 2):
#   * 'class="page-title"' is emitted only by generate_archive_title().
#   * 'class="entry-title"' is emitted only by the title render itself
#     (theme-functions.php:600/609) — the surrounding entry-header renders
#     regardless, so matching the header would report a title that is not there.
# ---------------------------------------------------------------------------
echo ""
echo "6. V31 — content title = the page-title role, not the loop cards"

ARCHIVE=$(fetch 'department/sales' || true)

[ -n "${ARCHIVE}" ] || err "empty response for /department/sales/ — every assertion in this section is presence/absence against the body and would pass vacuously."

case "${ARCHIVE}" in
    *'</html>'*) ok "archive response is a complete HTML document ($(printf '%s' "${ARCHIVE}" | wc -c) bytes)" ;;
    *) err 'archive response is not complete HTML — refusing to assert against a truncated body' ;;
esac

# An EMPTY archive 404s, and a 404 satisfies both absence checks below for the
# wrong reason. Prove the loop rendered at least one post first.
case "${ARCHIVE}" in
    *'id="post-'*) ok 'archive renders the default loop (>=1 post) — core-structures department:sales is populated' ;;
    *) err '/department/sales/ renders no posts — reseed core-structures. The absence assertions below would pass against a 404.' ;;
esac

# Blueprint v4 precondition, and it hard-aborts for the same reason as the ones in
# section 0. If ls-el-layout-title-archive is missing (or its display condition
# misses this archive) then NOTHING is disabled on this page — and the
# gp-no-content-title absence check below passes trivially, for a reason that has
# nothing to do with the behaviour under test. Suppressed loop-card titles are the
# proof that the element is live.
case "${ARCHIVE}" in
    *'class="entry-title"'*) err 'loop-card titles still render on the archive — ls-el-layout-title-archive is not applying, so the gp-no-content-title check below would pass vacuously. Reseed layout-states at blueprint v4+ and check its taxonomy:department/sales display condition.' ;;
    *) ok 'loop-card item titles ARE suppressed (element live, blueprint v4+) — the two roles are genuinely split' ;;
esac

case "${ARCHIVE}" in
    *'class="page-title"'*) ok 'archive HEADING survives the Layout Element content-title disable (the V31 premise)' ;;
    *) bad 'no <h1 class="page-title"> on the archive — GP no longer leaves the heading standing under a Layout Element content-title disable. The off-singular short-circuit rests on this; V31 needs revisiting, not the code.' ;;
esac

case "${ARCHIVE}" in
    *'gp-no-content-title'*) bad 'gp-no-content-title still emitted on the archive — the Detector is reading item-title suppression as a page-title disable. This is the pre-ADR-0005 behaviour; the off-singular short-circuit did not run.' ;;
    *) ok 'ADR-0005: no gp-no-content-title on the archive (item-title suppression is not a page-title disable)' ;;
esac

# Control for the two absence checks above. Without it, a Detector that never
# emitted the class — or a body-class filter that stopped running — would pass
# them both. This page carries _generate-disable-headline, the metabox key the
# Detector now reads DIRECTLY rather than inheriting from GP Premium's redundant
# __return_false, so it is also the render-level proof that V29 is closed.
case "${PHPREM}" in
    *'gp-no-content-title'*) ok 'control: gp-no-content-title IS emitted for the per-post metabox disable (V29 closed — key read directly)' ;;
    *) bad 'gp-no-content-title MISSING on the metabox-disabled page — a genuine disable is no longer detected. The archive absence assertions above prove nothing without this.' ;;
esac

case "${BASELINE}" in
    *'gp-no-content-title'*) bad 'gp-no-content-title emitted on the baseline, where nothing is disabled' ;;
    *) ok 'control: no gp-no-content-title on the baseline' ;;
esac

# ---------------------------------------------------------------------------
# 7. V32 / T17 — secondary nav replays the Layout Element layer, on both page
#    types.
#
# T17 shipped covered by the PHPUnit fake only, and the fake cannot check either
# of the two things that would break it in production:
#
#   * THE KEY. The Layout Element writes _generate_disable_secondary_navigation;
#     the per-post metabox writes _generate-disable-secondary-nav. Different
#     words, not one word with two separators. The fake returns whatever key it
#     is handed, so a wrong key is invisible there and silently inert here. B6
#     is the precedent: a fixture can carry the right shape and match nothing.
#   * THE UNGATED CLAIM. GP adds its has_nav_menu filter with no is_singular()
#     guard (class-layout.php:311), so ONE element must reach a singular page and
#     an archive. ls-el-layout-secondary-nav carries both display conditions, so
#     a regression that re-gates either side fails here by name.
#
# ASYMMETRY, deliberate: on the singular page both halves are asserted — the
# markup is gone AND the body class is emitted — because ls-page-baseline is the
# control proving #secondary-navigation renders at all (section 0). On the
# ARCHIVE there is no such control: the element is what disables it, and a second
# archive with the nav intact does not exist in this blueprint. So only the body
# class is asserted there. It is the discriminating half anyway — it is the
# Detector's own output, whereas the markup absence would also be satisfied by an
# archive that never rendered a secondary nav in the first place.
# ---------------------------------------------------------------------------
echo ""
echo "7. V32 — secondary nav config-replay on singular + archive"

ELSECNAV=$(fetch 'ls-page-layout-secondary-nav' || true)

[ -n "${ELSECNAV}" ] || err "empty response for ls-page-layout-secondary-nav — reseed layout-states at blueprint v5+. Every assertion in this section would pass vacuously."

case "${ELSECNAV}" in
    *'</html>'*) ok "ls-page-layout-secondary-nav is a complete HTML document ($(printf '%s' "${ELSECNAV}" | wc -c) bytes)" ;;
    *) err 'ls-page-layout-secondary-nav response is not complete HTML — refusing to assert against a truncated body' ;;
esac

case "${ELSECNAV}" in
    *'id="secondary-navigation"'*) bad '#secondary-navigation STILL RENDERS under a Layout Element secondary-nav disable — GP is not applying the element (check the _generate_disable_secondary_navigation key) or the fixture does not match this page.' ;;
    *) ok 'Layout Element removes #secondary-navigation on a singular page (GP side)' ;;
esac

case "${ELSECNAV}" in
    *'gp-no-secondary-nav'*) ok 'gp-no-secondary-nav emitted for the Layout Element layer on singular (V32 — the layer that had no signal before T17)' ;;
    *) bad 'gp-no-secondary-nav MISSING on the Layout-Element page — config-replay is not reading _generate_disable_secondary_navigation. This is the pre-T17 behaviour.' ;;
esac

case "${ARCHIVE}" in
    *'gp-no-secondary-nav'*) ok 'gp-no-secondary-nav emitted on the ARCHIVE — the replay branch is ungated, matching GP (class-layout.php:311)' ;;
    *) bad 'gp-no-secondary-nav MISSING on /department/sales/ — the replay branch is gated on is_singular() while GP is not, so a Layout Element disables the nav there while the rule reports it active.' ;;
esac

# Controls. Without the first, a Detector that emitted this class everywhere
# would pass both checks above; without the second, one that had stopped reading
# the metabox layer entirely would still look green.
case "${BASELINE}" in
    *'gp-no-secondary-nav'*) bad 'gp-no-secondary-nav emitted on the baseline, where nothing is disabled' ;;
    *) ok 'control: no gp-no-secondary-nav on the baseline' ;;
esac

case "${SECNAV}" in
    *'gp-no-secondary-nav'*) ok 'control: gp-no-secondary-nav still emitted for the per-post metabox layer (T17 added a layer, it did not replace one)' ;;
    *) bad 'gp-no-secondary-nav MISSING on the metabox page — T17 broke the layer it was meant to extend.' ;;
esac

# ---------------------------------------------------------------------------
# 8. ADR-0006 — Featured Image Active reports the POST SETTING, nothing else.
#
# A fourth era, and the second inversion pass. Sections 6 and 7 assert the
# Detector's output for the title and secondary-nav signals; this one does the
# same for the featured image, whose rule stopped reading BOTH the hook and the
# Layout Element key.
#
# The two inverted assertions are the hero page and the archive. Under the
# pre-ADR-0006 rule:
#
#   * ls-page-hero carries a Page Hero Block Element with "Disable featured
#     image" — a RELOCATION. The Hero draws the image itself and removes GP's
#     callback to avoid a duplicate, so hook-state read "disabled" and the class
#     was emitted on a page that shows an image (V21).
#   * /department/sales/ carries ls-el-layout-featured-archive, and the
#     non-singular replay branch (V22/T8) read its key, so the class was emitted
#     on every page that element covers.
#
# Both must now be ABSENT. Neither source is read on any page type.
#
# Worth naming because it is easy to misread as an over-broad control: on this
# testbed the pre-ADR-0006 rule emitted gp-no-featured-image on the BASELINE too.
# Section 0 pins the blog image position to `inside-content`, so GP's callback
# sits on `generate_before_content` while the old rule probed
# `generate_after_entry_header` — absent everywhere, on every singular page,
# which is V33's out-of-the-box misread showing up in the harness. The baseline
# control below is therefore a third inversion in substance, and the strongest
# single guard here: a revert to hook-state fails it on a page with nothing
# configured at all.
# ---------------------------------------------------------------------------
echo ""
echo "8. ADR-0006 — featured image = the per-post toggle, on every page type"

HERO=$(fetch 'ls-page-hero' || true)

[ -n "${HERO}" ] || err "empty response for ls-page-hero — every assertion in this section is presence/absence against the body and would pass vacuously."

case "${HERO}" in
    *'</html>'*) ok "ls-page-hero is a complete HTML document ($(printf '%s' "${HERO}" | wc -c) bytes)" ;;
    *) err 'ls-page-hero response is not complete HTML — refusing to assert against a truncated body' ;;
esac

# Liveness precondition, same discipline as the loop-card check in section 6 and
# for the same reason: if the Page Hero element is not applying to this page then
# nothing relocates the image, and the absence check below passes for a reason
# that has nothing to do with the behaviour under test. The element's own block
# content is the marker — it renders only when the element is live.
case "${HERO}" in
    *'ls-page-hero-element'*) ok 'Page Hero element is live on ls-page-hero (its block content renders)' ;;
    *) err 'ls-el-page-hero is not applying to ls-page-hero — the relocation under test is not happening, so the assertion below would pass vacuously. Most likely the element carries no _generate_hook, which makes GP return before registering it at all (inert from v1 to v6). Reseed layout-states at v7+.' ;;
esac

case "${HERO}" in
    *'gp-no-featured-image'*) bad 'gp-no-featured-image emitted on the Page Hero page — a relocation is being read as a disable. This is the pre-ADR-0006 hook-state behaviour: the Hero removes GP'"'"'s image callback because it draws the image itself, and the rule must not read that hook on any page type.' ;;
    *) ok 'ADR-0006: no gp-no-featured-image under a Page Hero relocation (inverted — hook-state emitted it here)' ;;
esac

# Liveness for THIS one is proven in a different suite, deliberately: `verify.php`
# §6 bootstraps a real archive query and asserts ls-el-layout-featured-archive
# applies to /department/sales/. There is no marker for it in the response —
# the element carries only a disable toggle and renders nothing of its own, so
# unlike the hero above it cannot announce itself here. Run the two together;
# this absence check is only meaningful downstream of that one. B6 is why the
# §6 check exists at all — that fixture matched no request for three blueprint
# versions.
case "${ARCHIVE}" in
    *'gp-no-featured-image'*) bad 'gp-no-featured-image emitted on /department/sales/ — the non-singular Layout Element replay is still running. ADR-0006 removes it: off singular the rule is a constant, because its only source is the per-post metabox and that layer cannot apply to an archive.' ;;
    *) ok 'ADR-0006: no gp-no-featured-image on the archive (inverted — the V22/T8 replay branch emitted it)' ;;
esac

# Controls. The first is the ONLY thing that can still emit this class, so
# without it a Detector that had stopped reporting the signal entirely would pass
# both absence checks above. The second is where a revert to hook-state lands:
# nothing is configured on the baseline, and section 0 has pinned the image
# position such that the old probe missed on every singular page (V33).
case "${FEATURED}" in
    *'gp-no-featured-image'*) ok 'control: gp-no-featured-image IS emitted for the per-post metabox toggle (the rule'"'"'s only source)' ;;
    *) bad 'gp-no-featured-image MISSING on ls-page-metabox-featured — the per-post toggle is no longer detected, and it is the rule'"'"'s only source. The absence assertions above prove nothing without this.' ;;
esac

case "${BASELINE}" in
    *'gp-no-featured-image'*) bad 'gp-no-featured-image emitted on the baseline, where nothing is disabled. Pre-ADR-0006 this was the SHIPPED behaviour on a default install (V33): the probed hook is only used at the `below-title` image position, and section 0 pins this site to `inside-content`. A hook-state read lands here.' ;;
    *) ok 'control: no gp-no-featured-image on the baseline (inverted — the hook-state read emitted it on every singular page here)' ;;
esac

# ---------------------------------------------------------------------------
# 9. Issue #5 — the two featured-image rules, through the authoring workflow.
#
# A fifth era, and the first section that asserts on the CHAIN AN AUTHOR USES
# rather than on markup this plugin controls. Sections 1-8 all read output the
# plugin emits directly (its CSS suppression, its body classes) or output GP
# emits; none of them touches the thing the plugin exists for — tick a toggle,
# and a block conditioned on the rule stops rendering.
#
# Every fixture in the table below carries the same three blocks, verbatim:
#
#   ls-marker-control        no condition        — proves the content rendered
#   ls-marker-image-active   featured_image_active         is
#   ls-marker-slot-active    featured_image_slot_active    is
#
# so a difference between two rows is attributable to the RULE and to nothing
# else. On the four singular rows they live in the page's own post_content; on
# the archive, which has none, they come from ls-el-block-archive-markers on
# `generate_before_main_content`. Both are workflows an author has (a block in a
# page, a hook Block Element on an archive), which is the point.
#
# THE COMBINATION TABLE. Two rules about different subjects, and the whole
# reason the slot rule exists (V34) is that they come apart:
#
#   fixture                     post setting   theme slot
#   ls-page-baseline            active         active
#   ls-page-metabox-featured    DISABLED       not active
#   ls-page-hero                active         not active   (relocation)
#   ls-page-featured-kill       active         not active   (kill switch)
#   /department/sales/          active         not active   (off singular)
#
# ls-page-featured-kill is new at v8 and is the case nothing covered: a Layout
# Element switches the image off on a singular page and NOTHING draws one in its
# place. Every other route to "slot not active" pairs the removal with a hero or
# a Content Template, so until now the rule's reason for existing was untested.
# That is also why the GP-side assertion below matters — the page carries a
# thumbnail, so the absence of a page-header-image wrapper is the observation
# that GP really does leave the slot empty there, rather than the default state
# of a page with no image.
#
# The row that is MISSING from the table is asserted too, not omitted: "post
# setting disabled, slot active" is unreachable by construction (this plugin
# removes the five callbacks the slot rule reads at wp:60 whenever the toggle is
# set, and the Detector first resolves later). ls-page-metabox-featured is the
# only page where the toggle is set, so it is the only place that combination
# could appear, and the slot marker's absence there is what says it does not.
#
# CONTROLS, and there are three kinds, because this section has three ways to
# pass while proving nothing:
#   * The unconditioned marker. If the page's content never reached the response
#     — a lost fixture, a Content Template, a 404 — every absence check below is
#     satisfied for a reason that has nothing to do with any rule. HARD-ABORTS.
#     This is B9's lesson applied before the fact.
#   * The baseline row. Both markers must be PRESENT there, or a condition
#     system that hid everything would pass all eight absence checks.
#   * The thumbnail. Proven from the response, not assumed: the featured image's
#     URL leaks into og:image/twitter:image from post meta whether or not the
#     image renders (the same fact that made a filename grep wrong for section
#     2's absence check — here it is exactly what is wanted).
# ---------------------------------------------------------------------------
echo ""
echo "9. issue #5 — post setting vs theme slot, via conditioned blocks"

KILL=$(fetch 'ls-page-featured-kill' || true)

[ -n "${KILL}" ] || err "empty response for ls-page-featured-kill — reseed layout-states at blueprint v8+. Every assertion in this section would pass vacuously."

case "${KILL}" in
    *'</html>'*) ok "ls-page-featured-kill is a complete HTML document ($(printf '%s' "${KILL}" | wc -c) bytes)" ;;
    *) err 'ls-page-featured-kill response is not complete HTML — refusing to assert against a truncated body' ;;
esac

# The thumbnails, read from the response rather than trusted. GP renders no
# page-header-image on a page with no featured image under ANY configuration, so
# without this the two "GP draws nothing here" observations below would be true of
# pages the Hero and the kill switch never touched. Both are checked, because both
# assert the absence of a wrapper GP would otherwise draw.
#
# The marker is the attachment filename, which reaches the response through the
# SEO plugin's og:image/twitter:image from post meta whether or not the image
# renders. That is the same fact that made a filename grep the WRONG marker for
# section 2's absence check — here it is exactly what is wanted, and it is the
# only way to see a thumbnail on a page that is not rendering it. verify.php §8
# checks the cause (has_post_thumbnail); this checks it reached the request.
for pair in 'KILL:ls-page-featured-kill' 'HERO:ls-page-hero'; do
    var="${pair%%:*}"
    label="${pair#*:}"
    body="${!var}"

    case "${body}" in
        *'ls-fixture-image'*) ok "${label} has a featured image in its post meta (SEO tags carry the URL) — GP would draw one here if nothing removed the callbacks" ;;
        *) err "${label} carries no featured image — reseed layout-states at v8+. The \"GP draws nothing here\" assertion below would pass against a page that renders no image under any configuration." ;;
    esac
done

# Liveness for all five rows. Hard-abort, not FAIL: a row whose content never
# rendered invalidates that row entirely rather than producing one bad result.
for pair in 'BASELINE:ls-page-baseline' 'FEATURED:ls-page-metabox-featured' 'HERO:ls-page-hero' 'KILL:ls-page-featured-kill' 'ARCHIVE:/department/sales/'; do
    var="${pair%%:*}"
    label="${pair#*:}"
    body="${!var}"

    case "${body}" in
        *'ls-marker-control'*) ok "${label}: unconditioned marker renders (the row's content reached the response)" ;;
        *) err "${label} does not render ls-marker-control, so nothing on this page is conditioned on anything and every absence check below would pass vacuously. On the four pages the markers live in post_content — reseed layout-states at v8+. On the archive they come from ls-el-block-archive-markers; check its _generate_hook and its taxonomy:department/sales display condition." ;;
    esac
done

# --- Row 1: baseline. Both rules active. The control for all eight absences. ---
case "${BASELINE}" in
    *'ls-marker-image-active'*) ok 'baseline: block conditioned on featured_image_active RENDERS (nothing is switched off)' ;;
    *) bad 'baseline: the featured_image_active block is MISSING on a page with nothing configured. Every absence assertion below is read against this — if the marker cannot render here, they prove nothing.' ;;
esac

case "${BASELINE}" in
    *'ls-marker-slot-active'*) ok 'baseline: block conditioned on featured_image_slot_active RENDERS (GP is drawing the image)' ;;
    *) bad 'baseline: the featured_image_slot_active block is MISSING while GP demonstrably draws the image here (section 0 pins the blog path live). The slot rule is reading false on a page where the slot is active — check the five callbacks in is_featured_image_slot_active() against the live render path.' ;;
esac

# --- Row 2: the per-post toggle. The authoring workflow, end to end. --------
# This pair IS the acceptance test for the plugin's stated purpose: the same
# block, on a page differing from the baseline only by the Disable Elements
# checkbox, stops rendering.
case "${FEATURED}" in
    *'ls-marker-image-active'*) bad 'metabox page: the featured_image_active block STILL RENDERS with the per-post toggle ON. This is the plugin failing at its stated job — the toggle is set, the rule reports the image active, and a block conditioned on it is not hidden. Check is_featured_image_disabled() and that _generate-disable-post-image is set on this fixture.' ;;
    *) ok 'metabox page: the featured_image_active block is HIDDEN by the per-post toggle (the authoring workflow, end to end)' ;;
esac

# The unreachable combination, asserted rather than omitted (V34).
case "${FEATURED}" in
    *'ls-marker-slot-active'*) bad 'metabox page: the featured_image_slot_active block renders while the per-post toggle is ON — that is the combination V34 documents as UNREACHABLE. The nesting is enforced by mechanism, not by a guard: this plugin removes the five image callbacks at wp:60 when the toggle is set, and the Detector resolves later. If this fires, the wp:60 suppression did not run (see section 2, which reads the same failure as a rendered image).' ;;
    *) ok 'unreachable combination confirmed: post setting disabled => slot NOT active (V34 nesting holds on a real request)' ;;
esac

# --- Row 3: Page Hero relocation. The two rules come apart. -----------------
case "${HERO}" in
    *'ls-marker-image-active'*) ok 'hero page: featured_image_active block RENDERS — a relocation is not a disable (ADR-0006)' ;;
    *) bad 'hero page: the featured_image_active block is hidden under a Page Hero. The rule is reading the relocation as a disable, which is the pre-ADR-0006 hook-state behaviour one layer out — a block that should render is being conditioned away.' ;;
esac

case "${HERO}" in
    *'ls-marker-slot-active'*) bad 'hero page: the featured_image_slot_active block renders while the Page Hero has removed GP'"'"'s image callbacks. The slot rule is reporting a slot that is not there — which is the duplicate-image case it exists to prevent.' ;;
    *) ok 'hero page: featured_image_slot_active block is HIDDEN — GP is not drawing the image, the Hero is' ;;
esac

# The GP-side half. Without it the row above rests on the assumption that the
# Hero really removed the callbacks; this is the observation.
case "${HERO}" in
    *'page-header-image'*) bad 'hero page renders a page-header-image wrapper — the Page Hero did not remove GP'"'"'s five image callbacks, so the slot IS active and the assertion above passed for the wrong reason.' ;;
    *) ok 'hero page: no page-header-image wrapper — GP genuinely draws nothing here' ;;
esac

# --- Row 4: the kill switch. The case nothing covered. ----------------------
case "${KILL}" in
    *'ls-marker-image-active'*) ok 'kill-switch page: featured_image_active block RENDERS — the Layout Element key is not read as a post-level disable (ADR-0006)' ;;
    *) bad 'kill-switch page: the featured_image_active block is hidden. The rule is reading _generate_disable_featured_image from a Layout Element, which ADR-0006 removed: that key is a relocation mechanism, so reading it conditions blocks away on pages whose author never switched anything off.' ;;
esac

case "${KILL}" in
    *'ls-marker-slot-active'*) bad 'kill-switch page: the featured_image_slot_active block renders while a Layout Element has removed all five of GP'"'"'s image callbacks. This is the ONE case the slot rule exists for, and it is answering wrong.' ;;
    *) ok 'kill-switch page: featured_image_slot_active block is HIDDEN — the case the slot rule exists for, observed for the first time (issue #5)' ;;
esac

# The GP-side half, and the ticket's central new observation: GP really does
# leave the slot empty when a Layout Element switches the image off and nothing
# replaces it. The thumbnail precondition above is what makes this falsifiable.
case "${KILL}" in
    *'page-header-image'*) bad 'kill-switch page renders a page-header-image wrapper on a page whose Layout Element disables the featured image. Either the element is not applying (verify.php §8 checks its display condition and its meta key) or GP no longer removes the callbacks at class-layout.php:316-320 — in which case the slot rule'"'"'s premise is wrong, not its code.' ;;
    *) ok 'kill-switch page: GP leaves the featured-image slot EMPTY with nothing drawing in its place (the fixture the slot rule was missing)' ;;
esac

# ADR-0006 regression guard on a SINGULAR page. Section 8 asserts the Layout
# Element key is not read on an archive; this is the same claim where the rule
# does not short-circuit, so it is the stronger of the two.
case "${KILL}" in
    *'gp-no-featured-image'*) bad 'gp-no-featured-image emitted on the kill-switch page — the featured-image signal is reading the Layout Element key on a singular page. ADR-0006 removed that read entirely: the key marks a relocation, and nothing on this page sets the per-post toggle.' ;;
    *) ok 'ADR-0006: no gp-no-featured-image under a Layout Element kill switch (singular — the case section 8 can only test off-singular)' ;;
esac

# --- Row 5: the archive. Off singular the slot is a constant false. ---------
case "${ARCHIVE}" in
    *'ls-marker-image-active'*) ok 'archive: featured_image_active block RENDERS — the post-setting rule is a constant true off singular (ADR-0006)' ;;
    *) bad 'archive: the featured_image_active block is hidden on /department/sales/. The per-post metabox layer cannot apply off singular (ADR-0002), so the rule must report active there; this is the reversed V22/T8 replay branch coming back.' ;;
esac

case "${ARCHIVE}" in
    *'ls-marker-slot-active'*) bad 'archive: the featured_image_slot_active block renders off singular. GP'"'"'s singular image slot does not exist on an archive, so the rule is a constant false there (V34 part 3) — item images inside loop cards are a different, item-level surface and out of remit.' ;;
    *) ok 'archive: featured_image_slot_active block is HIDDEN — the singular slot does not exist off singular (V34)' ;;
esac

# ---------------------------------------------------------------------------
echo ""
if [ "${FAIL}" -gt 0 ]; then
    echo -e "\033[31mError: ${PASS} passed, ${FAIL} FAILED.\033[0m" >&2
    exit 1
fi

echo -e "\033[32mSuccess: ${PASS} passed, 0 failed.\033[0m"
