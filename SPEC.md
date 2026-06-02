# SPEC — bws-generate-layout-conditions

> Caveman format. Authority for *why* = CONTEXT.md + docs/adr/. This = testable contract.
> §G goal · §C constraints · §I external surfaces · §V invariants · §T tasks · §B bug ledger.

## §G goal

Make GP disable states (7) + sidebar layout usable by GP Block Elements. Block Element replacing theme section respect same GP layout config native section would.

## §C constraints

- C1 PHP/WordPress plugin. No build step.
- C2 GP Premium hard-dep (`Requires Plugins: gp-premium`). GB Pro soft-dep (runtime `class_exists` gate).
- C3 No reimplement GP condition logic — reuse GP's own `show_data()` + `generate_get_layout()`.
- C4 Detector = single source of truth. Both consumers (body-class, condition) read it.
- C5 Don't duplicate body classes GP already emits (sidebar, container type).
- C6 Holds against beta: GB 2.3.0-beta.2 / GB Pro 2.6.0-beta.2 — core APIs stable (see memory baseline).

## §I external surfaces

| id | surface | shape |
|---|---|---|
| I.disable_fn | `generate_disable_elements()` | `function_exists`-guarded; pre-define => `''` win race |
| I.layout_fn | `generate_get_layout()` | guarded; returns sidebar enum, filters applied |
| I.show_data | `GeneratePress_Conditions::show_data($display,$exclude,$users)` | public standalone evaluator |
| I.gbp_register | action `generateblocks_register_conditions` | fires init pri 10; `Registry::register($type,$args,$class)` |
| I.gbp_abstract | `GenerateBlocks_Pro_Condition_Abstract` | supplies `sanitize_value`+`get_operators_for_rule`; impl only `evaluate`/`get_rules`/`get_rule_metadata` |
| I.render_block | filter `render_block` (in `do_blocks()`) | condition eval point; after `wp` |
| I.meta_post | `_generate-disable-{header,footer,secondary-nav}` | singular post metabox meta |
| I.meta_layout | `_generate_disable_site_header` / `_generate_disable_footer` | on `gp_elements` layout posts |
| I.meta_cond | `_generate_element_display_conditions` / `_exclude_conditions` / `_user_conditions` | layout post condition meta |
| I.puc | PUC v5p7 `YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker($url,__FILE__,$slug)` | bundled lib; entry `plugin-update-checker.php`→`load-v5p7.php`; versioned ns; GitHub-VCS via GitHubApi + ReleaseAssetSupport. Local source copy: `D:\...\Resources\Repos\plugin-update-checker` |

## §V invariants

- V1 Combined disable state = pure OR across layers (Customizer, Layout Element, post metabox). Layer can only disable, never re-enable. (ADR-0001)
- V2 Header + footer use **config-replay**, NOT hook-state. Hook signal poisoned: Block Element `remove_action`s native construct unconditionally → `! has_action` reads "disabled" on every page with the element. (ADR-0001)
- V3 Post-meta reads guard `is_singular()` AND read `get_queried_object_id()`, never `get_the_ID()` (drifts in loop inside `do_blocks()`). (ADR-0002)
- V4 Config-replay passes ALL three condition meta to `show_data()` (display + exclude + users) — else false positives on excluded pages.
- V5 Detector lazy + memoized: full resolution runs ≤1× per request, cached in static. First call always after `wp` → no invalidation needed.
- V6 Condition `evaluate()` discards `$context['post_id']` — always reports page-level state. Disable states + sidebar are page properties, not loop-item.
- V7 "Active" = not-disabled-by-config, NOT actual-render. Featured Image Active true when not-disabled even with no thumbnail. NEVER consult `has_post_thumbnail()` or GP's `featured-image-active` class.
- V8 Plugin emits NO sidebar body class, NO container body class — GP emits those natively. Plugin emits `gp-no-*` only for the 7 disable states (GP emits nothing positive there). (C5)
- V9 Body-class vocabulary negative (`gp-no-*`, disabled state); condition vocabulary positive ("Active"). Diverge on purpose — NOT meant to match.
- V10 Every condition rule: `needs_value => false`, `value_type => 'none'`, operators `['is','is_not']`. `evaluate` = `'is_not'===$op ? !$match : $match`. (GB constraint: operator selector not preselectable for custom types.)
- V11 11 rules, all "Active" suffix: 7 component (`! disabled`) + 4 sidebar (enum match). Sidebar plural by count: "No Sidebars"/"Both Sidebars" vs "Left Sidebar"/"Right Sidebar".
- V12 CSS-neutralize touches `generate_disable_elements()` (CSS path) ONLY. Do NOT touch `generate_disable_elements_setup()` (hook-removal path stays intact for native disabling).
- V13 GP Premium hard via header; GB Pro NOT in header — soft runtime gate preserves GP-only fallback (v1 runs without GB Pro). (ADR-0003)
- V14 v1 + v2 deploy together on GB Pro sites. v1-alone on GB Pro site = regression (removes CSS hide without condition replacement). v1-only valid only on GP-Premium-without-GB-Pro.
- V15 Body classes hook `body_class` from `wp` pri 110 (after Layout Elements at pri 100); `array_unique()` dedup.
- V16 readme.txt `Stable tag` == plugin header `Version`. Bump both together. PUC reads plugin header `Version` to compare against GitHub release tag → a stale header = clients never see update.
- V17 PUC bundled (NOT Composer) — no build step (C1); versioned namespace `v5` avoids cross-plugin collision. Update source = GitHub releases of this repo. Repo PUBLIC once published → no auth token in checker.
- V18 Release flow: git tag (`v{X.Y.Z}`) + GitHub release → PUC detects update. Tag version == plugin header `Version` == readme `Stable tag` (V16). Ship a built zip as the release asset (or let PUC use the auto source zip — must contain plugin at correct path, NOT nested in repo-name dir).
- V19 GB Pro condition registration must run at `plugins_loaded` pri ≥ 11 (GB Pro loads at pri 10). Core includes (disable-elements, detector, body-classes) may stay at pri 5 — they have no GB Pro dependency.
- V20 `is_featured_image_disabled()` returns false on non-singular pages. GP only adds `generate_blog_single_featured_image` to `generate_after_entry_header` on `is_singular()` — hook absence on archives is not a disable signal. Archive featured image state is not detectable via hook-state.
- V21 **Known ambiguity — Page Hero element-level disable toggles (featured image + title).** When a Page Hero Block Element has "Disable featured image" or "Disable title" on, it removes the same native hooks our detector reads — because the Hero *embeds* those elements itself. Detector reports the state as disabled, which is hook-accurate but semantically wrong: the element is active, just rendered by the Hero instead of the native location. v1 behavior: report disabled (hook-state wins). A future admin toggle could let users choose hook-state vs. config-replay. Do NOT silently "fix" without that toggle — both interpretations are valid depending on the site.
- V22 **Known gap — Layout Element "Disable featured image" on non-singular pages.** Layout Element fires `remove_action` for featured image without an `is_singular()` guard — it disables across all matched pages including archives. Our detector (B2 fix) always returns false on non-singular, so a Layout Element actively disabling featured image on an archive is not detected. Unlike V21 (ambiguous intent), this is a real miss — a Layout Element disable is unambiguously a user config decision. Not fixed in v1; requires a config-replay path for featured image on non-singular pages (parallel to the header/footer config-replay).

## §T tasks

| id | st | task | cites |
|---|---|---|---|
| T1 | x | Scaffold: headers + `Requires Plugins: gp-premium`, version/dir/url constants, bootstrap `plugins_loaded` pri 5 | V13,V16 |
| T6 | x | Copy PUC v5p7 from local `Resources\Repos\plugin-update-checker` into `plugin-update-checker/` (exclude .git); `require .../plugin-update-checker.php`; `PucFactory::buildUpdateChecker(<github-repo-url>, __FILE__, 'bws-generate-layout-conditions')`; GitHub-VCS via release tags; public repo (no token); set release-asset preference | V17,V18,I.puc |
| T2 | x | CSS-neutralize: define `generate_disable_elements()`=>`''` if undefined, `plugins_loaded` pri 5 | V12,I.disable_fn |
| T3 | x | Detector `states()`: hybrid per detection table; lazy-memoized static; OR; `is_singular()`+queried-object post-meta; header/footer config-replay via `show_data()` all-three-meta | V1,V2,V3,V4,V5,I.show_data,I.layout_fn,I.meta_post,I.meta_layout,I.meta_cond |
| T4 | x | Body classes: guard constant `BWS_GP_LAYOUT_STATE_BODY_CLASSES`; `wp` 110 → `body_class`; disabled→`gp-no-*`; `array_unique()` | V8,V9,V15 |
| T5 | x | Condition `BWS_GP_Layout_State_Condition`: extend abstract; impl `evaluate`/`get_rules`/`get_rule_metadata`; 11 rules; `is`/`is_not`; no value; delegate Detector, discard `$context`; self-gate `class_exists`; register on action | V6,V7,V10,V11,V13,I.gbp_register,I.gbp_abstract,I.render_block |

## §B bug ledger

| id | date | cause | fix |
|---|---|---|---|
| B1 | 2026-06-02 | `class_exists('GenerateBlocks_Pro_Conditions_Registry')` check ran at `plugins_loaded:5` — before GB Pro (pri 10) loaded — so `class-condition.php` never required, condition never registered | Split bootstrap: core at pri 5, condition+PUC load at pri 20 (V19) |
| B2 | 2026-06-02 | `is_featured_image_disabled()` used `! has_action('generate_after_entry_header','generate_blog_single_featured_image')` on archive pages — GP only adds that hook on `is_singular()`, so hook is always absent on archives → false positive `gp-no-featured-image` class | Guard with `is_singular()`; return false on non-singular (V20) |
