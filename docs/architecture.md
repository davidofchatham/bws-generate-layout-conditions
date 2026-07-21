# Architecture — bws-generate-layout-conditions

Supplements the ADRs in `docs/adr/`. ADRs record *decisions and rationale*; this file records the *contract* (invariants), *bug history*, and *signal map* (how each disable state is detected and surfaced).

---

## Invariants

These are the testable rules the codebase must uphold. Numbered monotonically; never reuse an ID.

| ID | Invariant |
|---|---|
| V1 | Combined disable state = pure OR across layers (Customizer, Layout Element, post metabox). A layer can only disable, never re-enable. |
| V2 | Header + footer use **config-replay**, not hook-state. Hook signal poisoned: Block Element `remove_action`s native construct unconditionally → `! has_action` reads "disabled" on every page with the element. (ADR-0001) Executable: `tools/fixtures/layout-states/poisoned-signal.php` (T13) reproduces the poisoning and asserts config-replay routes around it. |
| V3 | Post-meta reads guard `is_singular()` AND read `get_queried_object_id()`, never `get_the_ID()` (drifts in loop inside `do_blocks()`). (ADR-0002) |
| V4 | Config-replay passes ALL three condition meta to `show_data()` (display + exclude + users) — else false positives on excluded pages. |
| V5 | Detector lazy + memoized: full resolution runs ≤1× per request, cached in static. First call always after `wp` → no invalidation needed. |
| V6 | Condition `evaluate()` discards `$context['post_id']` — always reports page-level state. Disable states + sidebar are page properties, not loop-item. |
| V7 | "Active" = not-disabled-by-config, NOT actual-render. Featured Image Active true when not-disabled even with no thumbnail. NEVER consult `has_post_thumbnail()` or GP's `featured-image-active` class. |
| V8 | Plugin emits NO sidebar body class, NO container body class — GP emits those natively. Plugin emits `gp-no-*` only for the 7 disable states (GP emits nothing positive there). |
| V9 | Body-class vocabulary negative (`gp-no-*`, disabled state); condition vocabulary positive ("Active"). Diverge on purpose — NOT meant to match. |
| V10 | Every condition rule: `needs_value => false`, `value_type => 'none'`, operators `['is','is_not']`. `evaluate` = `'is_not'===$op ? !$match : $match`. |
| V11 | 10 rules, all "Active" suffix: 7 component (`! disabled`) + 3 sidebar (membership, V26). Sidebar rules: "Left Sidebar Active", "Right Sidebar Active", "No Sidebars Active" (plural by count). `both_sidebars_active` REMOVED (B4) — "both" composed via `Left Active is AND Right Active is`. |
| V12 | CSS-neutralize touches `generate_disable_elements()` (CSS path) ONLY. Do NOT touch `generate_disable_elements_setup()` (hook-removal path stays intact for native disabling). Scope is the GP Premium **per-post Disable Elements metabox** (`_generate-disable-*` meta), NOT Customizer global config — different option store, different code path (V24). |
| V13 | GP Premium hard via header; GB Pro NOT in header — soft runtime gate preserves GP-only fallback (v1 runs without GB Pro). (ADR-0003) |
| V14 | v1 + v2 deploy together on GB Pro sites. v1-alone on GB Pro site = regression (removes CSS hide without condition replacement). v1-only valid only on GP-Premium-without-GB-Pro. **Regression surface is NARROW** (V24): per-post Featured Image + Secondary Nav (full, CSS-only) plus Primary Nav's `#mobile-header` wrapper (partial, V25) are the only exposed surfaces. Header/top-bar/footer/content-title metabox toggles are PHP-removed (CSS redundant) and Customizer global disables are PHP-gated — neutralize is a no-op for those, regardless of GB Pro. |
| V15 | Body classes hook `body_class` from `wp` pri 110 (after Layout Elements at pri 100); `array_unique()` dedup. |
| V16 | `readme.txt` `Stable tag` == plugin header `Version`. Bump both together. PUC reads plugin header `Version` to compare against GitHub release tag → a stale header = clients never see update. |
| V17 | PUC bundled (NOT Composer) — no build step; versioned namespace `v5` avoids cross-plugin collision. Update source = GitHub releases of this repo. Repo PUBLIC once published → no auth token in checker. |
| V18 | Release flow: git tag (`v{X.Y.Z}`) + GitHub release → PUC detects update. Tag version == plugin header `Version` == readme `Stable tag` (V16). Ship a built zip as the release asset (or let PUC use the auto source zip — must contain plugin at correct path, NOT nested in repo-name dir). |
| V19 | GB Pro condition registration must run at `plugins_loaded` pri ≥ 11 (GB Pro loads at pri 10). Core includes (disable-elements, detector, body-classes) may stay at pri 5. |
| V20 | `is_featured_image_disabled()` NEVER reads hook-state on non-singular pages. GP only adds `generate_blog_single_featured_image` to `generate_after_entry_header` on `is_singular()` — hook absence on archives is not a disable signal (B2). Since T8, non-singular uses config-replay (`_generate_disable_featured_image`, V22) instead of blanket false. |
| V21 | **Known ambiguity — Page Hero element-level disable toggles (featured image + title).** When a Page Hero Block Element has "Disable featured image" or "Disable title" on, it removes the same native hooks the Detector reads — because the Hero *embeds* those elements itself. Detector reports disabled (hook-accurate, semantically wrong: element is active via Hero). v1 behavior: hook-state wins. Future admin toggle: hook-state vs. config-replay. Do NOT silently fix without that toggle. |
| V22 | **Closed (T8, 2026-07-08) — Layout Element "Disable featured image" on non-singular pages detected via config-replay.** Layout Element fires `remove_action` for featured image without `is_singular()` guard (gp-premium `elements/class-layout.php:315`) — disables on archives too. Detector non-singular branch replays `_generate_disable_featured_image` through `layout_element_disables()` (same engine as header/footer). Singular keeps hook-state (V21 Page-Hero ambiguity unchanged). Post-metabox layer stays correctly absent off-singular (ADR-0002). |
| V23 | `GeneratePress_Conditions::show_data()` requires array-of-arrays for all three args. `get_post_meta(...,true)` returns `''` when meta unset — always normalize with `?: array()` before passing. Raw empty string → `in_array($val,'')` → fatal `TypeError`. |
| V24 | **Neutralize scope is exact.** It nulls ONLY `generate_disable_elements()` — GP Premium's per-post Disable-Elements metabox CSS path (`_generate-disable-*` post-meta). Customizer global element disables (`hide_title`, `hide_tagline`, `nav_position_setting=disable`, `footer_bar`, `footer_widgets`) are all PHP render-gates with ZERO frontend `display:none` — neutralize cannot affect them. Of the per-post metabox toggles, header/top-bar/footer/content-title(GP≥3.0) are PHP-removed so their CSS is redundant (no risk). Regression surface when neutralize runs without a replacement condition: **Featured Image** (`_generate-disable-post-image`, CSS-only, no PHP removal — full), **Secondary Nav** (`_generate-disable-secondary-nav`, CSS-only — full), and **Primary Nav's mobile-header wrapper** (partial — see V25). See "Neutralize scope" section. |
| V25 | **Primary Nav per-post disable is partially CSS-load-bearing.** `_generate-disable-nav` PHP (`_setup`) does `generate_navigation_location→__return_false` (kills source `#site-navigation`, which cascades to the JS sticky `.navigation-clone`) **plus** `generate_disable_mobile_header_menu→__return_true`. But that filter only empties the menu toggle INSIDE the mobile header — `generate_menu_plus_mobile_header()` still renders the `<nav id="mobile-header">` wrapper (gated only by `mobile_header!=='disable'`, generate-menu-plus.php:1082). The selection's `#mobile-header {display:none !important}` under `$disable_nav` is the ONLY thing hiding that wrapper. So on a Menu-Plus-mobile-header site with per-post Primary Nav disabled, neutralize re-exposes the `#mobile-header` bar (branding/logo, empty menu). NOT triggered by `_generate-disable-mobile-header` — that toggle `remove_action`s the wrapper outright (no CSS dependency). |
| V26 | Sidebar-present rule TRUE whenever that side renders, INCLUDING both-sidebars layout. `left_sidebar_active` = enum ∈ {left-sidebar, both-sidebars}; `right_sidebar_active` = enum ∈ {right-sidebar, both-sidebars}; `no_sidebars_active` = (`'no-sidebar'` === enum). NOT exclusive enum-match (B4). "Both"/"neither" composable via AND; only "no sidebars" keeps a convenience rule. Detector unchanged — `states()['sidebar']` exposes raw GP enum; membership is consumer-side in `evaluate()`. |
| V27 | Conditions split into SEPARATE registry slugs, never one umbrella — slug persisted in saved condition data, so post-release split forces a data migration; pre-release split is free. v1: `gp_theme_element` ("Theme Element Status", 7 component rules) + `gp_theme_sidebar` ("Theme Sidebar", 3 sidebar rules V26). Reserved future: `gp_theme_container` (container width, not built). "Theme" prefix mirrors GP "Site Options" scoping, clusters types in the condition-type dropdown. Each condition: operators `['is','is_not']` MANDATORY (registry contract — UI renders fixed Type→Rule→Operator, operator slot cannot be dropped), `needs_value=false` (V10). Supersedes the single-slug `gp_layout_state` registration. |
| V28 | **Detector reads WP/GP only through the environment seam** (`BWS_GP_Environment`, T9). No direct `is_singular()`/`get_post_meta()`/`has_filter()`/`get_posts()`/`show_data()` calls inside the Detector — every read goes via `env()`. The seam exposes only the queried-object id (ADR-0002 structurally enforced) and has two adapters: `BWS_GP_WP_Environment` (prod) + in-memory fake (tests/). New signals must add their reads to the interface, not bypass it. Tests exercise `states()` through the fake — the Detector interface is the test surface. |

---

## Signal map

How each of the seven disable states is detected. "Hook-state" = reads the live hook/filter state at call time. "Config-replay" = queries `gp_elements` posts and calls `GeneratePress_Conditions::show_data()`.

| Component | Detection method | Hook / meta key | Layer gaps | Notes |
|---|---|---|---|---|
| Header | Config-replay (post-meta + Layout Element) | post: `_generate-disable-header`; layout: `_generate_disable_site_header` | None — GP core has no Customizer header-disable | Hook signal poisoned by Block Element (ADR-0001) |
| Footer | Config-replay (post-meta + Layout Element) | post: `_generate-disable-footer`; layout: `_generate_disable_footer` | None — footer-bar/footer-widgets Customizer controls are PHP-gated (V24), not disable-state layers | Same poisoning reason as header |
| Primary nav | Hook-state | `has_filter('generate_navigation_location','__return_false')` | None known | Both Layout Element and post metabox set this filter |
| Secondary nav | Post-meta only | `_generate-disable-secondary-nav` | Layout Element not detected | No clean hook; array-callback on `has_nav_menu` not checkable |
| Top bar | Hook-state | `has_action('generate_before_header','generate_top_bar')` | None known | |
| Featured image | Hybrid: hook-state (singular) + config-replay (non-singular) | singular: `has_action('generate_after_entry_header','generate_blog_single_featured_image')`; non-singular: layout `_generate_disable_featured_image` | Page Hero ambiguity (V21); post-metabox layer absent off-singular by design (ADR-0002) | GP only adds hook on `is_singular()` (V20/B2); archive detection via replay since T8 (V22) |
| Content title | Hook-state | `has_filter('generate_show_title','__return_false')` | Page Hero ambiguity (V21) | Page Hero "Disable title" sets this filter while title is still active via Hero |
| Sidebar layout | GP resolver | `generate_get_layout()` | None | GP folds all layers; no replay needed. Rules use membership not exclusive match (V26) |

---

## Neutralize scope

What `includes/class-disable-elements.php` (the "fix") actually affects. It pre-defines `generate_disable_elements()` → `''`, winning the `function_exists` race against GP Premium so the per-post metabox CSS path emits nothing. (V12, V24)

**Three independent GP disable systems. Neutralize touches exactly one.**

| System | Option store | Disable mechanism | Touched by neutralize? |
|---|---|---|---|
| Customizer global config | `generate_settings` theme-mods | PHP render-gates only | **No** — different code path, no CSS to null |
| Per-post Disable Elements metabox | `_generate-disable-*` post-meta | PHP `remove_action` (`generate_disable_elements_setup`) **+** inline CSS (`generate_disable_elements`) | **Yes** — only the CSS half |
| Layout Element / Block Element | `_generate_disable_*` (underscores) + element conditions | PHP filters / `remove_action` | **No** |

**Customizer global disables — all PHP-gated, neutralize-safe:**

| Setting | Render gate |
|---|---|
| `hide_title` | `header.php` — `$disable_title` skips output |
| `hide_tagline` | `header.php` — `$disable_tagline` skips output |
| `nav_position_setting` = `disable` | `navigation.php` — `generate_navigation_position()` never hooked |
| `footer_bar` (footer-bar widget area) | `footer.php` — `is_active_sidebar('footer-bar')` gate |
| `footer_widgets` | `footer.php` — widget-count + `is_active_sidebar` gate |

Distinguish two codebases — both emit frontend CSS, only one is disable-keyed:

- **GP core theme** (the Customizer→frontend path). Its CSS generator `inc/css-output.php` emits zero `display:none`, and its static `assets/css/style.css` `display:none` rules (~13 — menu-toggle responsive states, `screen-reader-text`, etc.) are layout/responsive/a11y, **not** keyed to any element-disable setting. So every Customizer global disable in the table above gates in PHP. None of this is touched by neutralize.
- **GP Premium Disable-Elements module** — `generate_disable_elements()` (the selection) DOES output real frontend `display:none`, keyed to per-post `_generate-disable-*` meta, enqueued via `wp_add_inline_style('generate-style', …)` (functions.php:80). This inline CSS is exactly and only what neutralize nulls (V12, V24).

Net: a site relying on standard Customizer global config is unaffected by neutralize, because those disables never used the CSS path neutralize targets — they PHP-gate in the core theme, while the CSS path lives in the Premium per-post module.

**Per-post metabox toggles — PHP-vs-CSS suppression matrix.**

The two halves of this module are NOT mirror images. `generate_disable_elements_setup()` (PHP, `wp` pri 50) and `generate_disable_elements()` (CSS, the selection) each cover a *different subset* of the toggles. The PHP list is broader than the CSS list (e.g. top bar PHP-removes but has no CSS rule at all), and two toggles have CSS but no PHP. Neutralize removes only the CSS column — so a toggle is at risk exactly when CSS is its *only* suppressor.

| Toggle (post-meta) | PHP suppress (`_setup`) | CSS suppress (`generate_disable_elements`) | Coverage | Neutralize risk |
|---|---|---|---|---|
| Header (`_generate-disable-header`) | ✅ `remove_action` construct-header | ✅ `.site-header` | both (CSS redundant) | none |
| Footer (`_generate-disable-footer`) | ✅ `remove_action` footer | ✅ `.site-footer` | both (CSS redundant) | none |
| Primary nav (`_generate-disable-nav`) | ✅ `generate_navigation_location→__return_false` (kills source nav + sticky clone) | ✅ `#site-navigation,.navigation-clone,#mobile-header !important` | both for source nav (CSS redundant); **CSS-only for `#mobile-header` wrapper** | **partial** — `#mobile-header` bar reappears on Menu-Plus sites (V25) |
| Top bar (`_generate-disable-top-bar`) | ✅ `remove_action` top-bar | ❌ no rule | PHP-only | none |
| Mobile header (`_generate-disable-mobile-header`) | ✅ `remove_action` `generate_menu_plus_mobile_header` | ❌ no rule | PHP-only | none |
| Content title (`_generate-disable-headline`) | ✅ GP≥3.0 `generate_show_title→__return_false` | only on GP<3.0 (`.entry-header`) | PHP-only on modern GP | none on GP≥3.0 |
| **Featured Image** (`_generate-disable-post-image`) | ❌ none — `generate_featured_page_header_area()` outputs whenever `has_post_thumbnail()` | ✅ `.generate-page-header,.page-header-image,.page-header-image-single` | **CSS-only** | **full regression** — reappears without replacement condition |
| **Secondary Nav** (`_generate-disable-secondary-nav`) | ❌ none in `_setup` | ✅ `#secondary-navigation` | **CSS-only** | **full regression** — reappears without replacement condition |

Read the coverage column, not a "both except N" rule — the redundancy is patchy:
- **Both (CSS redundant):** Header, Footer, Primary-nav (source nav only).
- **PHP-only (no CSS at all):** Top bar, Mobile-header toggle, Content-title (modern GP).
- **CSS-only (PHP absent):** Featured Image, Secondary Nav — plus Primary-nav's `#mobile-header` sub-element, which PHP misses.

Net regression surface of neutralize-without-replacement: **Featured Image** (full), **Secondary Nav** (full), **Primary Nav's `#mobile-header` wrapper** (partial — Menu Plus mobile header active + per-post Primary Nav disabled; V25). Everything else is either PHP-suppressed (CSS redundant) or in an untouched system.

**Why GP left these CSS-only is not "because it's hard."** Clean PHP suppression already exists for both full-risk toggles — GP simply didn't wire it into the legacy metabox module:
- Secondary Nav — GP's **own** Layout Element disables it via `add_filter('has_nav_menu', …)` returning `false` for the `'secondary'` location (`class-layout.php:534`); the render gate is `if ( has_nav_menu('secondary') )` (`secondary-nav/functions.php:702`). The metabox module just never adopted this filter.
- Featured Image — a plain `remove_action('generate_after_header','generate_featured_page_header',10)` + `remove_action('generate_before_content','generate_featured_page_header_inside_single',10)` suppresses it (hooks at `featured-images.php:96,114`).
- `#mobile-header` wrapper — no dedicated filter, but `remove_action('generate_after_header','generate_menu_plus_mobile_header',5)` removes it (`generate-menu-plus.php:1070`).

This means a **convert-to-PHP** alternative to the V14 CSS re-emit exists: instead of neutralizing CSS and depending on a substitute GB Pro condition, the plugin can add the equivalent PHP suppression keyed on the same `_generate-disable-*` meta (on `wp`, before render hooks). The post-level toggle then keeps working with no CSS and no required replacement Element — dissolving the V14 regression for these three. Open risks before adopting: hook timing vs. `_setup` (pri 50) and composition with GB Pro conditions (must stay OR per V1). Tracked in Deferred work.

---

## Element toggle map

GP element toggles that affect the signals the Detector reads. Only two element types have relevant toggles: **Layout Element** and **Page Hero Block Element**.

| Element type | Toggle | Signal affected | Condition rule | Body class | GP native class | Notes |
|---|---|---|---|---|---|---|
| Layout Element | Disable site header | Header config-replay | Header Active | `gp-no-header` | — | |
| Layout Element | Disable footer | Footer config-replay | Footer Active | `gp-no-footer` | — | |
| Layout Element | Disable primary navigation | Primary nav hook-state | Primary Nav Active | `gp-no-primary-nav` | — | |
| Layout Element | Disable top bar | Top bar hook-state | Top Bar Active | `gp-no-top-bar` | — | |
| Layout Element | Disable featured image | Featured image hook-state | Featured Image Active | `gp-no-featured-image` | `featured-image-active` (render-based, different) | No `is_singular()` guard — disables on archives too; detected via config-replay off-singular since T8 (V22) |
| Layout Element | Disable content title | Content title hook-state | Content Title Active | `gp-no-content-title` | — | |
| Page Hero Block Element | Disable featured image | Featured image hook-state | Featured Image Active | `gp-no-featured-image` | — | V21 ambiguity: Hero embeds the image itself — hook absent but image active via Hero. Detector reports disabled. Hook-state wins in v1. |
| Page Hero Block Element | Disable title | Content title hook-state | Content Title Active | `gp-no-content-title` | — | V21 ambiguity: same pattern as featured image. Title active via Hero; hook-state reports disabled. |

Layout Elements without any of the above toggles set, and all other Block Element types (post-meta-template, post-navigation-template, archive-navigation-template, content-template, sidebar), do not affect any tracked signal.

---

## Bug ledger

Permanent record. Do not delete entries.

| ID | Date | Cause | Fix |
|---|---|---|---|
| B1 | 2026-06-02 | `class_exists('GenerateBlocks_Pro_Conditions_Registry')` check ran at `plugins_loaded:5` — before GB Pro (pri 10) loaded — so `class-condition.php` never required, condition never registered | Split bootstrap: core at pri 5, condition+PUC at pri 20 (V19) |
| B2 | 2026-06-02 | `is_featured_image_disabled()` used `! has_action(...)` on archive pages — GP only adds that hook on `is_singular()`, so hook always absent on archives → false positive `gp-no-featured-image` class | Guard with `is_singular()`; return false on non-singular (V20) |
| B3 | 2026-06-02 | `layout_element_disables()` passed raw `get_post_meta()` return values to `show_data()` — `get_post_meta` returns `''` when meta unset; `show_data` expects array-of-arrays; `in_array($val,'')` → fatal `TypeError` on any Layout Element with no display/exclude/user conditions set | Normalize with `?: array()` before passing (V4, V23) |
| B4 | 2026-06-03 | Sidebar rules did exclusive enum-match: `left_sidebar_active` = (`'left-sidebar'` === enum) → FALSE on a both-sidebars page even though the left sidebar renders. "Show when left present" failed on both-sidebars layout. `both_sidebars_active` rule was redundant and masked the gap | Membership semantics: left/right TRUE when enum ∈ {own, both-sidebars}; drop `both_sidebars_active`; "both" composed via AND (V26). Split sidebar rules into their own `gp_theme_sidebar` condition (V27) |

---

Deferred work and in-flight build tasks live in `docs/ROADMAP.md` (until the repo is public and they move to GitHub Issues). This file holds only the permanent contract: invariants, bug ledger, signal map, neutralize scope.
