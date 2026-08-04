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
| V12 | **The neutralize must be defined at FILE SCOPE, not on a hook** (B5, fixed 2026-07-21). Both definitions of `generate_disable_elements()` are `function_exists`-guarded, so ownership is a load-order race — and GP Premium `require`s its Disable Elements module during plugin load (`gp-premium.php:69`), strictly before any hook fires. Defining ours on `plugins_loaded` (this plugin through 0.2.0) always LOST, silently, on every request. Winning also requires this plugin to precede `gp-premium` in `active_plugins`; that holds by name order but is not enforced, so `bws_glc_owns_disable_elements()` verifies ownership by comparing the declaring file and raises an admin notice when it fails. Never assert ownership by calling the function: GP's version also returns `''` on any non-singular request (and under wp-cli, where there is no `$post`), so a return-value check reports success even when GP owns the name — that false negative is exactly why B5 survived undetected. Executable: `tools/fixtures/layout-states/render-surface.sh` (T11). CSS-neutralize touches `generate_disable_elements()` (CSS path) ONLY. Do NOT touch `generate_disable_elements_setup()` (hook-removal path stays intact for native disabling). Scope is the GP Premium **per-post Disable Elements metabox** (`_generate-disable-*` meta), NOT Customizer global config — different option store, different code path (V24). |
| V13 | GP Premium hard via header; GB Pro NOT in header — soft runtime gate preserves GP-only fallback (v1 runs without GB Pro). (ADR-0003) |
| V14 | **CLOSED by T10 (2026-07-21) — the regression is dissolved, not mitigated.** The three exposed surfaces below are now removed in PHP keyed on the same `_generate-disable-*` meta (`class-disable-elements.php`, `wp:60`), so the toggles keep working with the CSS gone and need no replacement GB Pro condition. Verified on rendered HTTP output (`render-surface.sh`, 26 assertions, mutation-checked ×3). The v1-alone exposure described below therefore no longer applies to any version carrying T10. **Prior note (B5, 2026-07-21): this regression was also never actually live.** Through 0.2.0 the neutralize lost the `generate_disable_elements()` definition race and never ran, so no shipped version ever removed the CSS — the window in which V14 was both real and unclosed is empty. Historical detail retained below. v1 + v2 deploy together on GB Pro sites. v1-alone on GB Pro site = regression (removes CSS hide without condition replacement). v1-only valid only on GP-Premium-without-GB-Pro. **Regression surface is NARROW** (V24): per-post Featured Image + Secondary Nav (full, CSS-only) plus Primary Nav's `#mobile-header` wrapper (partial, V25) are the only exposed surfaces. Header/top-bar/footer/content-title metabox toggles are PHP-removed (CSS redundant) and Customizer global disables are PHP-gated — neutralize is a no-op for those, regardless of GB Pro. |
| V15 | Body classes hook `body_class` from `wp` pri 110 (after Layout Elements at pri 100); `array_unique()` dedup. |
| V16 | `readme.txt` `Stable tag` == plugin header `Version`. Bump both together. PUC reads plugin header `Version` to compare against GitHub release tag → a stale header = clients never see update. |
| V17 | PUC bundled (NOT Composer) — no build step; versioned namespace `v5` avoids cross-plugin collision. Update source = GitHub releases of this repo. Repo PUBLIC once published → no auth token in checker. |
| V18 | Release flow: git tag (`v{X.Y.Z}`) + GitHub release → PUC detects update. Tag version == plugin header `Version` == readme `Stable tag` (V16). Ship a built zip as the release asset (or let PUC use the auto source zip — must contain plugin at correct path, NOT nested in repo-name dir). |
| V19 | GB Pro condition registration must run at `plugins_loaded` pri ≥ 11 (GB Pro loads at pri 10). Core includes (disable-elements, detector, body-classes) may stay at pri 5. |
| V20 | `is_featured_image_disabled()` NEVER reads hook-state on non-singular pages. GP only adds `generate_blog_single_featured_image` to `generate_after_entry_header` on `is_singular()` — hook absence on archives is not a disable signal (B2). Since T8, non-singular uses config-replay (`_generate_disable_featured_image`, V22) instead of blanket false. |
| V21 | **CLOSED for content title by ADR-0005 (relocation is no longer read at all); still OPEN for featured image.** The content-title half is fixed by not consulting the poisoned hook: the signal replays the two genuine-disable configs instead, so a relocating element reports the title active. Featured image keeps hook-state on singular and the ambiguity below applies to it unchanged — it is **not** symmetric with content title (no template-tag writer), so only the Page Hero toggle row reaches it. Whether it wants the same treatment is not decided here and is not in ADR-0005's scope. Original text: **Known ambiguity — relocation reads as disable (content title + featured image).** When an element *embeds* the title/image itself, it removes the same native hooks the Detector reads, so the Detector reports disabled while the element is visibly active in another position. Hook-accurate, semantically wrong. v1 behavior: hook-state wins. **Scope is wider than "Page Hero toggles"** — surveyed against GP 3.6.1 + Premium 2.5.6, `generate_show_title` has six upstream writers and two of them have no toggle at all (relocation is inferred from a `{{post_title}}` template tag in element content). Full table below. Featured image is **not** symmetric: it has no template-tag writer (Page Header renders its own image via `has_post_thumbnail()` without touching the hooks), so only the toggle-driven rows apply to it. Do NOT silently fix — see the two-meanings analysis below; a single boolean cannot serve both consumers, and the fix shape is unresolved. |
| V22 | **Closed (T8, 2026-07-08) — Layout Element "Disable featured image" on non-singular pages detected via config-replay.** Layout Element fires `remove_action` for featured image without `is_singular()` guard (gp-premium `elements/class-layout.php:315`) — disables on archives too. Detector non-singular branch replays `_generate_disable_featured_image` through `layout_element_disables()` (same engine as header/footer). Singular keeps hook-state (V21 Page-Hero ambiguity unchanged). Post-metabox layer stays correctly absent off-singular (ADR-0002). |
| V23 | `GeneratePress_Conditions::show_data()` requires array-of-arrays for all three args. `get_post_meta(...,true)` returns `''` when meta unset — always normalize with `?: array()` before passing. Raw empty string → `in_array($val,'')` → fatal `TypeError`. |
| V24 | **Neutralize scope is exact.** It nulls ONLY `generate_disable_elements()` — GP Premium's per-post Disable-Elements metabox CSS path (`_generate-disable-*` post-meta). Customizer global element disables (`hide_title`, `hide_tagline`, `nav_position_setting=disable`, `footer_bar`, `footer_widgets`) are all PHP render-gates with ZERO frontend `display:none` — neutralize cannot affect them. Of the per-post metabox toggles, header/top-bar/footer/content-title(GP≥3.0) are PHP-removed so their CSS is redundant (no risk). Regression surface when neutralize runs without a replacement condition: **Featured Image** (`_generate-disable-post-image`, CSS-only, no PHP removal — full), **Secondary Nav** (`_generate-disable-secondary-nav`, CSS-only — full), and **Primary Nav's mobile-header wrapper** (partial — see V25). See "Neutralize scope" section. **Confirmed empirically 2026-07-21** (T11, `render-surface.sh`): with the neutralize live, featured-image markup and `#secondary-navigation` both survive their toggles (CSS-only, full surface) while `entry-header` is absent (PHP-removed, neutralize a no-op). Previously derived from reading GP's source only. **Surface CLOSED by T10 (2026-07-21):** the plugin now PHP-removes both full-surface toggles, and the same two assertions were inverted to prove it — featured-image and `#secondary-navigation` markup must now be ABSENT under their toggles. V24 still describes which toggles are CSS-only *upstream*, which is what makes the suppression necessary; it no longer describes live exposure. Note the featured-image assertion matches the `page-header-image` wrapper, not the attachment filename — the filename also appears in `og:image`/`twitter:image`/Yoast JSON-LD irrespective of render, and as an absence check it reports false failures. |
| V25 | **Primary Nav per-post disable is partially CSS-load-bearing.** `_generate-disable-nav` PHP (`_setup`) does `generate_navigation_location→__return_false` (kills source `#site-navigation`, which cascades to the JS sticky `.navigation-clone`) **plus** `generate_disable_mobile_header_menu→__return_true`. But that filter only empties the menu toggle INSIDE the mobile header — `generate_menu_plus_mobile_header()` still renders the `<nav id="mobile-header">` wrapper (gated only by `mobile_header!=='disable'`, generate-menu-plus.php:1082). The selection's `#mobile-header {display:none !important}` under `$disable_nav` is the ONLY thing hiding that wrapper. So on a Menu-Plus-mobile-header site with per-post Primary Nav disabled, neutralize re-exposes the `#mobile-header` bar (branding/logo, empty menu). NOT triggered by `_generate-disable-mobile-header` — that toggle `remove_action`s the wrapper outright (no CSS dependency). **Confirmed empirically 2026-07-21** (T11): on `ls-page-metabox-nav`, `#site-navigation` is absent (PHP path) while `#mobile-header` is PRESENT and no longer hidden. Until the layout-states blueprint reached v2 this had NEVER been observed — the seed wrote `generate_menu_plus_settings` via `set_theme_mod()` while GP reads it only via `get_option()`, so the mobile header was off and any V25 assertion would have passed vacuously. **Surface CLOSED by T10 (2026-07-21):** the plugin `remove_action`s `generate_menu_plus_mobile_header` (pri 5) when `_generate-disable-nav` is set, so the wrapper no longer survives; the assertion was inverted to require its absence. The baseline precondition (wrapper present with no toggle) is what keeps that honest — with the mobile header off it would pass while proving nothing. Mutation-checked: changing the `remove_action` priority to 10 fails this assertion alone, by name. **Visually confirmed at mobile width (2026-07-21)** — the only check the render harness cannot make, since curl has no viewport and this wrapper's whole purpose is a narrow one. Baseline shows two nav rows (Menu Plus grey bar + `#mobile-header`); with the toggle on, the second row is gone and content reflows up with no collapsed gap. The grey Menu Plus bar SURVIVES and must — it is a distinct element from the wrapper, and losing it would be over-suppression, not success. |
| V26 | Sidebar-present rule TRUE whenever that side renders, INCLUDING both-sidebars layout. `left_sidebar_active` = enum ∈ {left-sidebar, both-sidebars}; `right_sidebar_active` = enum ∈ {right-sidebar, both-sidebars}; `no_sidebars_active` = (`'no-sidebar'` === enum). NOT exclusive enum-match (B4). "Both"/"neither" composable via AND; only "no sidebars" keeps a convenience rule. Detector unchanged — `states()['sidebar']` exposes raw GP enum; membership is consumer-side in `evaluate()`. |
| V27 | Conditions split into SEPARATE registry slugs, never one umbrella — slug persisted in saved condition data, so post-release split forces a data migration; pre-release split is free. v1: `gp_theme_element` ("Theme Element Status", 7 component rules) + `gp_theme_sidebar` ("Theme Sidebar", 3 sidebar rules V26). Reserved future: `gp_theme_container` (container width, not built). "Theme" prefix mirrors GP "Site Options" scoping, clusters types in the condition-type dropdown. Each condition: operators `['is','is_not']` MANDATORY (registry contract — UI renders fixed Type→Rule→Operator, operator slot cannot be dropped), `needs_value=false` (V10). Supersedes the single-slug `gp_layout_state` registration. Executable: `tools/probes/upstream-surface.php` (T14) asserts both slugs register end-to-end against their expected classes with operators intact. |
| V28 | **Detector reads WP/GP only through the environment seam** (`BWS_GP_Environment`, T9). No direct `is_singular()`/`get_post_meta()`/`has_filter()`/`get_posts()`/`show_data()` calls inside the Detector — every read goes via `env()`. The seam exposes only the queried-object id (ADR-0002 structurally enforced) and has two adapters: `BWS_GP_WP_Environment` (prod) + in-memory fake (tests/). New signals must add their reads to the interface, not bypass it. Tests exercise `states()` through the fake — the Detector interface is the test surface. Executable: `seam-fidelity.php` (T12) pins the adapter to real WP/GP; `tools/probes/upstream-surface.php` (T14) pins the upstream API shape both sides assume. |
| V29 | **CLOSED by ADR-0005 — content-title detection no longer depends on GP Premium's redundant filter.** The Detector now reads `_generate-disable-headline` directly through `post_metabox_disables()`, so the coupling described below is gone. Historical detail retained. The theme's own `_generate-disable-headline` handling (`generate_disable_title`, generatepress `inc/general.php:225`) is a **named callback, registered unconditionally**, deciding at call time — so `has_filter('generate_show_title','__return_false')` could not see it. Under hook-state the metabox toggle was detected only because Premium's Disable Elements module *redundantly* adds `__return_false` for the same meta key (`disable-elements/functions/functions.php:286`). Premium is a hard requirement (`Requires Plugins`, V-req) so it was never a live bug, but it was load-bearing coupling: had that Premium module been disabled per-site while the theme stayed, `_generate-disable-headline` would have gone undetected with no other signal. Executable: `render-surface.sh` §6 asserts `gp-no-content-title` on the metabox-disabled fixture, which is the render-level proof the key is read directly. |
| V30 | **The title signal splits by page-structure ROLE, not by hook.** Two roles: the **page title** — one per page, at the top — and **item titles** — many, one inside each loop card. `generate_show_title` **straddles both**: on singular it gates the page title (`content-single.php:36`, `content-page.php:37`); on archives it gates the item titles (`content.php:35`, `content-link.php:35`). The archive's page title is a *different hook entirely* — `generate_archive_title` → `<h1 class="page-title">` (`inc/structure/archives.php:19`, fired from `archive.php:34`). So the page-title signal is `generate_show_title` on singular and `generate_archive_title` on archives, and one rule reading only the former gives incoherent answers across page types. **Per V6 this plugin reports page-level state only**, so `content_title_active` must mean the *page title* on both page types. Item titles are item-level and out of remit (a separate rule if ever needed; `evaluate()` discards `post_id` so it could only ever report a global suppression, not a per-item one). Consequences: a Layout Element "disable content title" kills item titles but leaves the archive **heading** intact (`class-layout.php:324` adds only the filter); rows 4/5 gate the `generate_show_title` removal on `is_singular()` while removing the archive heading **unguarded** (`class-block.php:296-302`). **Superseded in one part by ADR-0005:** V30's prescription was that the archive branch *read* the heading hook (`generate_archive_title`, ANDed with `generate_has_default_loop()` to catch Loop Templates). The structural facts above stand and are pinned by V31; the prescription does not. The rule is Meaning A on every page type, and off singular no writer reaches the page-title role at all, so the branch is a **constant**, not a hook read. Reading the heading hook would have re-opened the silent-failure direction Meaning A exists to close — a Loop Template is indistinguishable from a relocation without parsing block content. |
| V31 | **The page-title role has no writer off singular — the content-title signal short-circuits there.** Pinned against GP 3.6.1 + GP Premium 2.5.6. Four structural facts the ADR-0005 short-circuit rests on: (1) `generate_show_title` gates the **page title** only on singular (`content-single.php:36`, `content-page.php:37`) and gates **item titles** inside loop cards on archives (`content.php:35`, `content-link.php:35`); (2) `archive.php` is the ONLY template off singular that carries a page-title hook — `do_action('generate_archive_title')` at `archive.php:34`, hooked at `inc/structure/archives.php:13` and rendering `<h1 class="page-title">` at `:34`; (3) `index.php`, `search.php` and `404.php` contain no page-title hook at all (verified by grep, not inferred); (4) the Layout Element content-title toggle is a single unguarded `add_filter('generate_show_title','__return_false')` (`class-layout.php:324`) and therefore **cannot reach the archive heading**, while the post metabox layer is singular-only by construction (ADR-0002). Together: off singular, no configuration source can disable the page-title role, so `is_content_title_disabled()` returns false before consulting anything. A theme upgrade that moves any of the four breaks the short-circuit — fact (4) is the one a render can falsify, and `render-surface.sh` §6 does exactly that on a real archive. **Confirmed empirically on testbed 2026-08-04** (33/33, mutation-checked): heading present, loop-card titles absent, `gp-no-content-title` absent. Previously this was derived from reading GP's source only. |

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
| Content title (= **page title**, V30) | Config-replay on singular; constant off it (ADR-0005) | singular: post `_generate-disable-headline`, layout `_generate_disable_content_title`; off singular: none — returns not-disabled before any read (V31) | Relocation is deliberately **unread**, so Meaning B is unanswerable (accepted gap below); item titles are out of remit (V30) | Was hook-state on `generate_show_title` through 0.2.1 — poisoned by relocation writers, and answering about item titles on archives. Six upstream writers, three of them relocations; survey below is evidence, not a detection spec. No Customizer layer exists for this signal (unlike header/footer/nav): title is per-post-and-element only, upstream |
| Sidebar layout | GP resolver | `generate_get_layout()` | None | GP folds all layers; no replay needed. Rules use membership not exclusive match (V26) |

---

## Neutralize scope

What `includes/class-disable-elements.php` (the "fix") actually affects. It pre-defines `generate_disable_elements()` → `''`, winning the `function_exists` race against GP Premium so the per-post metabox CSS path emits nothing. (V12, V24)

**Two mechanisms, disjoint failure modes.** The definition race above is the primary. It is winnable only at file scope and only by whoever loads first, so it depends on plugin order — which nothing enforces. The fallback attacks the other end of the same pipe: `generate_disable_elements()` only returns a string, and exactly one registration prints it (`wp_enqueue_scripts` → `generate_de_scripts`, pri 50, functions.php:74-82). Removing an existing registration has no ordering problem, so `remove_action` from `wp_enqueue_scripts:1` works regardless of load order. Gated on `bws_glc_owns_disable_elements()`, it runs only when the primary lost.

Keeping both is deliberate, because each covers the other's failure:

| Mechanism | Fails when | Covered by |
|---|---|---|
| Pre-define `generate_disable_elements()` | GP Premium loads first | the `remove_action` fallback |
| `remove_action` on `generate_de_scripts` | GP adds work to `generate_de_scripts` beyond the one `wp_add_inline_style` | the pre-define, which leaves the callback intact |

The fallback's blast radius is the whole `generate_de_scripts` callback, which today is that single `wp_add_inline_style` line and nothing else (identical in GP Premium 2.5.5 and 2.5.6) — so today the two are equivalent in effect. The module's other registrations are separate callbacks and are untouched: `generate_disable_elements_setup` (`wp:50`, the PHP suppression), `generate_disable_elements_body_classes` (`body_class:20`), `generate_add_de_meta_box` (`add_meta_boxes:50`).

Render-harness assertions (`render-surface.sh` §1) test rule *absence*, not mechanism, so they hold for either path — mutate the load order and they stay green.

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

This **convert-to-PHP** approach is what the plugin now does (T10, landed 2026-07-21), replacing the V14 CSS re-emit: rather than neutralizing CSS and depending on a substitute GB Pro condition, it applies the equivalent PHP suppression keyed on the same `_generate-disable-*` meta at `wp:60`. The post-level toggle keeps working with no CSS and no required replacement Element — dissolving the V14 regression for these three rather than mitigating it.

Both risks that gated adoption were resolved empirically, not argued away:
- **Hook timing** — `wp:60` sits after GP's `generate_disable_elements_setup` (`wp:50`) and the Elements loader (`wp:10`), while all three targets bind for hooks that fire later during the template. GP's own Layout Element does its equivalent work at `wp:100`.
- **Composition with GB Pro conditions (OR per V1)** — safe by construction, no fixture needed. A second `remove_action` for an already-removed callback returns `false` with no warning or notice (verified on testbed), and two `has_nav_menu` filters both returning `false` are idempotent. Both layers converge on the same operation, so composing them is OR semantics for free.

One caveat worth carrying: the `$location` guard in the `has_nav_menu` filter is **defensive, not load-bearing**. `has_nav_menu` is called for exactly one location across all of GP + GP Premium (`'secondary'`, 12 sites; zero for `'primary'`, which renders unconditionally with a page-list fallback), so a `$location`-blind filter is currently unobservable in output and the render harness cannot falsify it. Keep the guard — it becomes load-bearing the moment upstream or a third-party plugin queries another location.

---

## Element toggle map

GP element toggles that affect the signals the Detector reads. Only two element types have relevant *toggles*: **Layout Element** and **Page Hero Block Element**. Toggles are not the only path to a signal — the legacy Page Hero (Header Element) and the Premium Page Header module both suppress the content title via template-tag inference with no toggle at all (V21 survey below).

| Element type | Toggle | Signal affected | Condition rule | Body class | GP native class | Notes |
|---|---|---|---|---|---|---|
| Layout Element | Disable site header | Header config-replay | Header Active | `gp-no-header` | — | |
| Layout Element | Disable footer | Footer config-replay | Footer Active | `gp-no-footer` | — | |
| Layout Element | Disable primary navigation | Primary nav hook-state | Primary Nav Active | `gp-no-primary-nav` | — | |
| Layout Element | Disable top bar | Top bar hook-state | Top Bar Active | `gp-no-top-bar` | — | |
| Layout Element | Disable featured image | Featured image hook-state | Featured Image Active | `gp-no-featured-image` | `featured-image-active` (render-based, different) | No `is_singular()` guard — disables on archives too; detected via config-replay off-singular since T8 (V22) |
| Layout Element | Disable content title | Content title config-replay (`_generate_disable_content_title`) | Content Title Active | `gp-no-content-title` | — | Singular only (V31): off singular the same toggle reaches the loop-card **item** titles, not the page-title role, and is deliberately not reported |
| Page Hero Block Element | Disable featured image | Featured image hook-state | Featured Image Active | `gp-no-featured-image` | — | V21 ambiguity: Hero embeds the image itself — hook absent but image active via Hero. Detector reports disabled. Hook-state wins in v1. |
| Page Hero Block Element | Disable title | **None — not read** (ADR-0005) | Content Title Active | `gp-no-content-title` | — | The relocation case V21 opened. Since ADR-0005 the Hero's `_generate_disable_title` is not consulted at all: it moves the title, so the rule reports the title **active** and blocks inside the Hero render. |

Layout Elements without any of the above toggles set, and all other Block Element types (post-meta-template, post-navigation-template, archive-navigation-template, content-template, sidebar), do not affect any tracked signal.

The table above covers **toggles only**. Two further upstream writers reach the content-title signal with no toggle involved — see the full survey below.

---

## Title signals: complete writer survey (V21, V29, V30)

GP has **two** independent title signals (V30). This section surveys both: the content title first, then the archive title.

### Content title

Surveyed against **GP 3.6.1 + GP Premium 2.5.6**, 2026-07-29. Every writer to the `generate_show_title` filter.

> **This survey is evidence, not a detection specification.** Since ADR-0005 the Detector reads **none** of these writers — it replays the two genuine-disable configs (rows 1–3's meta keys) and never consults the filter. The table is retained because it is what proves rows 4/5/6 are *relocations rather than disables*, which is the premise the whole simplification rests on. It outlives the code that used to detect them.

| # | Source | Mechanism | Trigger | Detected | Meaning |
|---|---|---|---|---|---|
| 1 | Theme core — `inc/general.php:225` | `add_filter(…, 'generate_disable_title')` | always registered; returns false when `_generate-disable-headline` set, `is_singular()` | ✗ **no** — named callback (V29) | genuine disable |
| 2 | Premium Disable Elements — `disable-elements/functions/functions.php:286` | `__return_false` | `_generate-disable-headline` post meta | ✓ | genuine disable |
| 3 | Layout Element — `elements/class-layout.php:325` | `__return_false` | **`_generate_disable_content_title`** on element (`class-layout.php:217`) — NOT the same key as row 4 | ✓ | genuine disable |
| 4 | Page Hero **Block** Element — `elements/class-block.php:298` | `__return_false`, `is_singular()`-guarded | `_generate_disable_title` on element | ✓ | **relocation** |
| 5 | Page Hero **legacy** (Header Element) — `elements/class-hero.php:889` | `__return_false`, `is_singular()`-guarded | **`{{post_title}}` in hero content** — no toggle | ✓ | **relocation** |
| 6 | Premium **Page Header** module — `page-header/functions/functions.php:32` | `__return_false` | **`{{post_title}}` in page-header content** — no toggle | ✓ | **relocation** |

Also present: a `__return_true` re-enabler at priority 20 (`inc/plugin-compat.php:845`) which would override every row above. **Inert on current installs** — gated on `GP_PREMIUM_VERSION < 1.12.0-alpha.1`. Recorded so a future reader does not rediscover it as a live override.

> **Trap — the two element types use DIFFERENT meta keys for the same signal.** Layout Element reads `_generate_disable_content_title` (`class-layout.php:217`); Page Hero Block Element reads `_generate_disable_title` (`class-block.php:292`). Featured image, by contrast, shares one key (`_generate_disable_featured_image`) across both. Any Meaning-A implementation that keys on element meta must read **both** title keys or it silently misses one element type — a probe written against `_generate_disable_title` alone reported `false` for Layout Elements literally named "disable title" (caught 2026-07-29 on the hargrave clone). **Live since ADR-0005:** the Detector now replays `_generate_disable_content_title`, so this is no longer a hazard for a future fix — it is the key the shipped code reads, and swapping it for the Block key would invert the answer on every Layout Element site. The fixture `ls-el-layout-title-archive` and the fake-environment tests both key on the Layout one. Detector's three replay keys — `_generate_disable_site_header`, `_generate_disable_footer`, `_generate_disable_featured_image` — were re-verified against `class-layout.php:193-222` on the same date and are correct.

**No Customizer layer exists for content title.** Unlike header / footer / nav / top bar, GP offers no global show-hide control — the signal is per-post meta and elements only. Confirmed by exhaustive grep, not inferred from the Customizer UI.

Rows 5 and 6 are the load-bearing discovery: relocation is inferred upstream from a **substring in element content**, not a meta flag. Any source-aware detection keyed on element meta (the obvious fix shape) would miss both. Row 6 is not a Page Hero at all — it is the separate Page Header module, so "Page Hero ambiguity" was always the wrong name for this class of problem.

### Rows 5 / 6: mechanism and detection cost

> **Do not build from this section.** The config-replay approach that landed with ADR-0005 means rows 5/6 need **no detection at all** — see "Meaning A is config-replay" below. What follows is retained because it is the evidence that these rows are *relocations rather than disables*, which is the premise the simplification rests on; the detection costing is superseded and the T14 canary line it proposed was never built.

Both decide suppression by `strpos()` on **raw stored content, pre-substitution**, at `wp`, while substitution happens later at render (`template_tags()`, `class-hero.php:924`). So suppression tracks *the literal string being present in meta*, not whether a title ultimately renders.

| | Row 5 — legacy Page Hero | Row 6 — Page Header module |
|---|---|---|
| Setup | `wp` pri **100** → `after_setup()` → `class-hero.php:687` | `wp` pri **10** → `functions.php:10` |
| Test | `strpos($options['content'], '{{post_title}}') !== false` | same, `functions.php:31` |
| Content source | `_generate_element_content` on the element post | `_meta-generate-page-header-content` on the resolved post |
| Post resolution | element display conditions (same posts `layout_element_disables()` already queries — an added meta read on an existing query) | `generate_page_header_get_options()` — CPT via `_generate-select-page-header`, term meta, or the `generate_page_header_global_locations` option, with blog / archive / search / 404 branches |
| Filter guard | `is_singular()` | none |

**`generate_page_header_get_options()` is safe to call for detection** (verified 2026-07-29, Premium 2.5.6). Pure read — `get_option` / `get_post_meta` / `get_term_meta` / `get_post_status` / `has_post_thumbnail` / conditional tags only. No writes, no hook registration, no output, no static memoization to poison; already called on every render (`functions.php:22`), so re-entrancy is a non-issue. Needs the main query (reads `get_the_ID()`), which the Detector already has. Called with no `$id` it resolves against the current queried object — the same resolution the render path uses, so detection cannot disagree with render.

**Do not reimplement that resolution.** Line 205 applies `apply_filters( 'generate_page_header_id', $id )`, so any third party can redirect which Page Header post applies; a reimplementation silently misses it. Calling the function honors it for free. This reverses an earlier estimate that row 6 was too costly to detect — with the resolver reused, row 6 is about as cheap as row 5:

```php
if ( function_exists( 'generate_page_header_get_options' ) ) {
    $opts = generate_page_header_get_options();
    $relocated = $opts && isset( $opts['content'] )
        && false !== strpos( $opts['content'], '{{post_title}}' );
}
```

The `function_exists` guard is **mandatory, not defensive**: the Page Header module is optional *and deprecated* — loaded only when `generatepress_is_module_active('generate_package_page_header', 'GENERATE_PAGE_HEADER')` (`gp-premium.php:103`, under a "Deprecated modules" heading; the loader is marked `@deprecated 1.7.0`). On a site that never activated it the function does not exist. Corollary: row 6 can only fire where the module is active, which bounds the surface. The `isset()` is V23 discipline — `get_post_meta(…, true)` yields `''` when unset, the exact shape that already caused one fatal here.

Unlike ~15 neighbours in the same file, `generate_page_header_get_options()` is **not** `function_exists`-wrapped at its own definition — no override seam exists for it.

**T14 canary:** this binds the plugin to a deprecated module's function signature. If row-6 detection is built, `tools/probes/upstream-surface.php` should pin `generate_page_header_get_options()` existence + no-arg callability + the `content` key, so removal of a deprecated module surfaces as a named failure rather than silently disabling detection.

### Deployment survey (2026-07-29)

Measured on local clones of both deployed sites (`portals`, `hargrave`) in the wp-litespeed env, not inferred:

| Site | Title-disabling elements | Writer | Meaning |
|---|---|---|---|
| portals | 2 × Page Hero Block (`50938`, `983`) | row 4 | **relocation** |
| hargrave | 3 × Layout Element (`76959`, `48122`, `45881`) | row 3 | **genuine disable** |

Zero overlap — no site mixes the two, and portals is the site that exposed the problem. **This clears the Meaning-A flip:** hargrave's elements report disabled under either meaning, portals' two flip to active (the desired fix). No deployed site depends on Meaning B implicitly.

Also measured: `generate_package_page_header` **does not exist** as an option on either site, so the Page Header module never loads and **row 6 cannot fire** — consistent with it being absent from the Modules screen (GP hides deprecated modules that were never activated; Sections, Typography and Hooks are hidden the same way). No element on either site contains `{{post_title}}`, so **row 5 is unexercised too**. Live writers across the whole deployment: **2, 3, 4 only**.

Bounding this properly: rows 5/6 remain reachable for *other* installs — a site that activated Page Headers before its 1.7.0 deprecation keeps the module loaded and hidden, and legacy Header Elements still exist in the wild. They are documented because the plugin ships beyond these two sites, not because they are live here.

### Sister-plugin interaction (bws-gb-dynamic-tags-extensions)

DTE ships its own title tag (`bws_post_title_core`, `includes/tags/content-tags.php:109`) and **never touches `generate_show_title`** (verified by grep, 2026-07-29). Its tag syntax is not the literal `{{post_title}}`, so it does **not** trigger rows 5 or 6. Consequence: a GB block using the DTE tag inside a Page Hero renders a title while GP leaves the native title **on** — a duplicate. This is the inverse of the row-4 toggle problem: GP's own template tag self-suppresses, DTE's cannot.

Two levers, if that gap is ever closed from the DTE side:

- `add_filter( 'generate_show_title', '__return_false', 20 )` — no filter exists on the suppression *decision* in either row (the `strpos` result feeds `add_filter` directly, unhookable), but all writers sit at default priority 10, so priority 20 wins. This is GP's own override slot (`inc/plugin-compat.php:845`). Register no earlier than `wp`:101 to land after both row 5 (`wp`:100) and row 6 (`wp`:10).
- `generate_page_hero_post_title` (`class-hero.php:947`) filters the *substituted value* — allows putting `{{post_title}}` in the hero for free suppression, then rewriting its output. Correct suppression at the cost of routing through GP's tag.

**Suppression must cover BOTH signals (V30).** GP's own tag handler does all of this together (`class-hero.php:888-895`):

```php
if ( is_singular() ) {
    add_filter( 'generate_show_title', '__return_false' );
}
remove_action( 'generate_archive_title', 'generate_archive_title' );
remove_filter( 'get_the_archive_title', 'generate_filter_the_archive_title' );
add_filter( 'post_class', /* remove_hentry */ );
```

Note the asymmetric guard — content title is `is_singular()`-gated, archive title is not. Any DTE-side suppression must mirror that shape or it will either leave a duplicate heading on archives or strip loop-item titles on singular.

### DTE `{{title}}` sitewide-template interop

DTE plans `{{title}}` as a single tag covering both the `{{post_title}}` and `{{archive_title}}` roles, so one Page Hero template can serve the whole site. Current DTE coverage (`traversal-pipeline.php:445-477`): post, term archive, author archive. Remaining per its FW-9: PTA, search, date, 404, blog-home — the contexts GB Pro's `{{archive_title}}` already covers.

**Suppression is already solved for the common case, and needs no coordination.** A Page Hero *Block* Element with the "Disable title" checkbox performs both removals with the correct guards (`class-block.php:296-302`), so a DTE `{{title}}` inside that hero gets clean suppression on every page type today. The FW-58 gap applies only where the tag is used *outside* an element carrying that checkbox (content-template, hook element, an ordinary block).

**The unresolved half was the condition, not the suppression** — and ADR-0005 answers it. **Correction (2026-08-04):** this passage previously said the pairing works because *both sides dispatch by page type*. The conclusion holds; the mechanism does not. DTE's `{{title}}` genuinely dispatches — it resolves a different value per page type. `content_title_active` does **not**: it is config-replay on singular and a constant off it (V31). They still compose with no extra rule and no author-side OR-composition, but for a different reason — the condition answers one question ("has the author disabled the page title?") that is meaningful on every page type, so a block carrying `{{title}}` inside a sitewide hero conditions on the one rule and behaves correctly on both. The same correction applies to the corresponding entry in the sister plugin's future-work tracker (separate repository, separate commit).

The former open call — on an archive a Layout Element "disable content title" suppresses **item** titles but leaves GP's heading **intact** (`class-layout.php:324`), so should the rule honour author intent or report the rendered heading? — is answered by ADR-0005: neither branch reads the archive at all, because that toggle does not reach the page-title role. It is an item-title setting, and item titles are out of remit (V30).

**No API handoff is required between the plugins.** The seam is clean: GLC answers "has the author disabled the title for this page?" (a condition), DTE answers "what does `{{title}}` resolve to here?" (a value). They compose in the block editor — condition the DTE-tag block on GLC's rule — with no filter contract, no shared state, and no load-order coupling.

### Archive branch of the page-title signal (V30, T15)

Surveyed 2026-07-29, same GP versions. The archive heading is gated by `add_action( 'generate_archive_title', 'generate_archive_title' )` (`inc/structure/archives.php:13`) plus the `get_the_archive_title` filter. Suppressors:

| Source | Removes archive title | Removes content title | Guard |
|---|---|---|---|
| Layout Element (row 3) | **✗ — leaves it intact** | ✓ | — |
| Page Hero Block (row 4) | ✓ `class-block.php:301-302` | ✓ | archive removal **unguarded**; content removal `is_singular()` |
| legacy Page Hero (row 5) | ✓ `class-hero.php:892-893` | ✓ | same asymmetry |
| Page Header module (row 6) | ✓ `page-header/functions/functions.php:33` | ✓ | — |
| **Loop Template Block Element** | ✓ **indirectly** — `generate_has_default_loop => __return_false` (`class-block.php:182`) removes the whole `archive.php` block | ✗ | — |

The last row is the one a `remove_action` survey misses entirely: the hook is never removed, the `do_action` simply never runs. Both deployed sites run 2 loop templates each, so it is live.

Detection (Meaning B) is a clean hook-state read plus GP's own resolver:

```php
$native_archive_title = $env->has_hook( 'generate_archive_title', 'generate_archive_title' )
    && generate_has_default_loop();
```

No config-replay, and no V29-style blindness — `generate_archive_title` is a named function on a same-named action.

**The open call here was SETTLED by ADR-0005: neither. The archive branch reads nothing.** The observation below still holds — on archives there is no genuine-disable writer at all: row 3 does not touch the heading, and the post metabox is singular-only so it cannot reach an archive. Every archive-heading suppressor is a *relocation* (rows 4/5/6) or a *structural loop replacement*. The earlier reading of that fact was that Meaning A is "vacuous" on archives and Meaning B should win there. The decision inverts the emphasis: a signal that is *uniformly true* is not vacuous, it is **correct and cheap** — it is the honest answer to "has the author disabled the page title here?", and it makes one sitewide hero behave identically on a post and on a category archive. Reading the heading hook instead would have bought precision about the native slot at the cost of re-opening silent failure (a Loop Template deletes the heading and is indistinguishable from a relocation without parsing block content), and it would have put Meaning A on singular and Meaning B on archives inside one rule. The two branches are now the same meaning; the archive one just has nothing to consult. Rationale + rejected alternatives: ADR-0005. The Meaning-B detection sketch above is retained for the accepted gap, not for this rule.

GB Pro registers a `{{archive_title}}` dynamic tag (`generateblocks-pro/includes/extend/dynamic-tags/class-register.php:39`) resolving through `single_cat_title()` / `single_term_title()` / `post_type_archive_title()` — **not** through `get_the_archive_title`, so GP's `remove_filter` never affects it. Archive-side twin of the DTE gap (FW-58): renders independently, suppresses nothing.

### Meaning A is config-replay, not relocation-detection (landed — ADR-0005)

Framing V21 as "detect the relocations and subtract them" led to an over-built plan (parse element content, call `generate_page_header_get_options()`, pin a deprecated module in the canary). **That is unnecessary.** Meaning A = "a title renders somewhere" = `NOT( genuinely disabled )`, and there are exactly **two** genuine-disable sources:

| Genuine disable | Meta | Existing helper |
|---|---|---|
| Post metabox (writers 1 + 2 collapse — same key) | `_generate-disable-headline` | `post_metabox_disables()` |
| Layout Element (writer 3) | `_generate_disable_content_title` | `layout_element_disables()` |

Relocations (rows 4/5/6) are simply **never read** — you stop consulting the poisoned hook rather than compensating for it.

The resulting `is_content_title_disabled()` (`includes/class-detector.php`) is a V31 singular gate over the two rows above, byte-identical in shape to `is_header_disabled()` / `is_footer_disabled()` otherwise. The gate is explicit because `layout_element_disables()` has no singular guard of its own (`post_metabox_disables()` does). The Detector's internal state map is **disable**-polarity throughout; the condition layer inverts it to the "Active" label. Read the function, not a copy of it — this file quotes *upstream* source as evidence and deliberately keeps no copy of our own, which would drift silently on the next edit. **This is the same problem as V2/ADR-0001** — hook-state poisoned by an element that removes the native hook for its own reasons — and it takes the same fix. Consequences:

- Rows 5/6 need **no detection at all**. The `strpos`-on-content machinery, the `generate_page_header_get_options()` call, and its T14 canary line all dropped from scope and were never built. (The survey above stays — it is what proves those rows are relocations rather than disables, which is the premise this simplification rests on.)
- **V29 closes as a side effect**: reading `_generate-disable-headline` directly removes the dependency on GP Premium's redundant `__return_false`.
- Accepted residue: a Hero that disables the title and then does not render one reports active. Unavoidable without parsing block content, and the author chose it by leaving the title out.

### Accepted gap: Meaning B has no signal, on any page type

After ADR-0005 nothing reports "does the **native theme slot** render the title?" A block placed *outside* a hero — doing duplicate-avoidance, or compensating spacing for a heading that is not there — has no condition to key on. Accepted rather than overlooked:

- **No known consumer.** The deployment survey above found zero sites relying on the old meaning.
- **The failure direction is the safe one.** A missing Meaning-B signal shows up as a visible duplicate title; the reverse failure is invisible (blocks that never render).
- **The detection work is not lost.** The writer survey above *is* the specification a future rule would need, and the slug space is free. Shape, if ever scoped: a separate rule reading `has_filter('generate_show_title','__return_false')` on singular and `has_action('generate_archive_title','generate_archive_title') && generate_has_default_loop()` on archives — see the archive-branch section for why the archive half needs that second conjunct. Roadmap: "Meaning-B rule for the native title slot".

The same gap applies to `gp-no-content-title`, which has one name and no room to split — CSS wanting Meaning B has nowhere to go either.

### The title axis is page-structure role, not hook (V30)

Grouping these by which hook gates them is the wrong cut — it puts a page-level element and a set of item-level elements in the same bucket purely because GP reuses one filter. Group by **what the thing is on the page**:

| Role | Count per page | Singular | Archive |
|---|---|---|---|
| **Page title** — top of page | 1 | entry title — `generate_show_title` (`content-single.php:36`, `content-page.php:37`) | heading `<h1 class="page-title">` — `generate_archive_title` (`archive.php:34`) |
| **Item titles** — inside each loop card | N | — | `generate_show_title` (`content.php:35`, `content-link.php:35`) |

`generate_show_title` straddles the two roles: page-level on singular, item-level on archives. That is why a rule that only reads it answers a *different structural question* depending on page type — and why the fix is not "add a second rule" but "make the page-title signal read the right hook for the page type".

**V6 settles which role this plugin models.** Conditions report page-level state; `evaluate()` discards `post_id` by design. So:

- `content_title_active` = **the page title**, on both page types. It is *not* a dispatch: ADR-0005 makes it config-replay on singular and a **constant** off singular, because no configuration source reaches the page-title role there (V31).
- Item titles are out of remit. The plugin could only ever report "are loop titles globally suppressed", never a per-item answer — a separate rule if a need is ever scoped, not part of this one.

This collapses the earlier two-rule plan. **No new rule, no new body class, no naming problem**: the rule count stays 10, `gp-no-content-title` keeps its name, and the persisted slug `content_title_active` is untouched (V27 — no migration). Only the archive **branch** of the existing signal changes, from reporting loop cards to reporting the heading.

Behaviour change to flag on land: on an archive `gp-no-content-title` currently reflects loop-card suppression and will start reflecting the heading. **Cleared for the deployed sites 2026-07-29** — neither keys any CSS on that class, so nothing consumes the old meaning. Still a changelog item, since the class ships to any future install.

### Two meanings of "Content Title Active" — RESOLVED (ADR-0005 chose A)

**Outcome: candidate 3, flip to A.** Candidates 1 and 2 below were rejected; the decision, both rejections and their specific failure modes are recorded in ADR-0005. The analysis is retained because it is why the choice is not obvious. The "unresolved" framing below is historical.

The relocation rows make the rule name ambiguous between two questions that are both legitimate and give **opposite answers on the same page**:

- **Meaning A — "renders anywhere."** Consumer: a block *inside* the Hero, conditioned to show only when a title should exist. Relocation is not a disable.
- **Meaning B — "renders in the native theme slot."** Consumer: a block *outside* the Hero avoiding a duplicate title; spacing compensation. Relocation **is** a disable. This is v1's shipped meaning.

One boolean cannot serve both. The failure modes are asymmetric: shipping A-only breaks Meaning-B consumers **visibly** (duplicate titles), while shipping B-only breaks Meaning-A consumers **invisibly** (blocks that never render, with nothing on screen to notice). Silent failure argues against B-only — but B is already the released meaning, so flipping it is a behavior change on existing sites, not a new capability.

Candidate shapes, unresolved:

1. **Two rules** — keep `content_title_active` = B (no migration, V27 slug untouched), add a second rule for A. Cost: naming two dropdown entries that are self-explanatory without the docs, and the same split likely doubles onto featured image. Four rules where two stood.
2. **Global admin toggle** (the original ROADMAP framing) — **rejected.** A site can carry a Layout Element disable *and* a Hero relocation simultaneously on the same page load, needing A and B at once. A global switch cannot express that.
3. **Flip to A, document the break** — cheapest, defensible only if nothing live consumes B semantics yet.

Deciding between 1 and 3 required knowing whether any live site consumes `content_title_active` under Meaning B. Not determinable from source — so it was **measured** instead, against clones of both deployed sites (survey above): zero Meaning-B consumers, which selects 3.

The `gp-no-content-title` / `gp-no-featured-image` body classes encode a meaning too, and have **one name each with no room to split** — whatever resolves at the rule layer must answer for the class layer separately. For content title the class simply inherits Meaning A; the residue is the accepted gap recorded above.

---

## Bug ledger

Permanent record. Do not delete entries.

| ID | Date | Cause | Fix |
|---|---|---|---|
| B1 | 2026-06-02 | `class_exists('GenerateBlocks_Pro_Conditions_Registry')` check ran at `plugins_loaded:5` — before GB Pro (pri 10) loaded — so `class-condition.php` never required, condition never registered | Split bootstrap: core at pri 5, condition+PUC at pri 20 (V19) |
| B2 | 2026-06-02 | `is_featured_image_disabled()` used `! has_action(...)` on archive pages — GP only adds that hook on `is_singular()`, so hook always absent on archives → false positive `gp-no-featured-image` class | Guard with `is_singular()`; return false on non-singular (V20) |
| B3 | 2026-06-02 | `layout_element_disables()` passed raw `get_post_meta()` return values to `show_data()` — `get_post_meta` returns `''` when meta unset; `show_data` expects array-of-arrays; `in_array($val,'')` → fatal `TypeError` on any Layout Element with no display/exclude/user conditions set | Normalize with `?: array()` before passing (V4, V23) |
| B5 | 2026-07-21 | **The CSS-neutralize never ran on any request.** `class-disable-elements.php` was required from `bws_glc_bootstrap()` on `plugins_loaded:5`, but GP Premium `require`s its Disable Elements module at file scope during plugin load — earlier than any hook. Both definitions of `generate_disable_elements()` are `function_exists`-guarded, so GP always won and its `display:none` rules kept being emitted. Shipped through 0.2.0. Invisible to every existing test: GP's implementation returns `''` on non-singular requests and there is no `$post` under `wp eval`, so CLI checks could not distinguish the two definitions, and no render-level test existed yet | Require at FILE SCOPE from the main plugin file, before any hook. Added `bws_glc_owns_disable_elements()` (compares the declaring file, never the return value) plus an admin notice, since winning also depends on unenforced `active_plugins` order. Caught by T11's render harness; V12 rewritten (B5, V12) |
| B6 | 2026-08-04 | **Both archive fixtures were inert from blueprint v1 through v3.** `ls-el-layout-featured-archive` stored its display-condition `object` as the term **slug** (`'sales'`). GP resolves a taxonomy archive to rule `taxonomy:{taxonomy}` with object = `$queried_object->term_id` (`class-conditions.php:225-231`) and compares with a non-strict `in_array()`; under PHP 8 `7 == 'sales'` is false, so the element never applied to `/department/sales/` on any request. V22/T8's *code* path was covered by the unit suite against the fake, but the fixture that was supposed to pin it to reality did nothing. Invisible to every existing check: the element existed, was published, carried the right disable meta, and answered `layout_element_ids()` correctly — nothing evaluated its **conditions** against a real archive query. Found only when the new V31 render assertion (§6) failed on a live archive. Same shape as the v1→v2 `set_theme_mod` bug: a fixture written in a form the consumer never matches, silent about it, self-verifying | Manifest objects use a `{{term:taxonomy:slug}}` placeholder resolved to the term ID in `seed.php`; `verify.php` §6 bootstraps an archive query and asserts both archive elements actually apply, so a recurrence fails by name. Blueprint v4 |
| B4 | 2026-06-03 | Sidebar rules did exclusive enum-match: `left_sidebar_active` = (`'left-sidebar'` === enum) → FALSE on a both-sidebars page even though the left sidebar renders. "Show when left present" failed on both-sidebars layout. `both_sidebars_active` rule was redundant and masked the gap | Membership semantics: left/right TRUE when enum ∈ {own, both-sidebars}; drop `both_sidebars_active`; "both" composed via AND (V26). Split sidebar rules into their own `gp_theme_sidebar` condition (V27) |

---

Deferred work and in-flight build tasks live in `docs/ROADMAP.md` (until the repo is public and they move to GitHub Issues). This file holds only the permanent contract: invariants, bug ledger, signal map, neutralize scope.
