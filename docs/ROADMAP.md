# Roadmap — bws-generate-layout-conditions

In-flight build tasks and deferred work.

- **Permanent contract** (invariants, bug ledger, signal map) → `architecture.md`
- **Decisions + rationale** → `adr/`
- **Working build state** → this file

---

## In-flight build tasks

Active change set 2026-07-08 (see `SPEC.md`), extended 2026-07-21 with the test-surface work (T12–T14) enabled by the wp-litespeed env. Order = row order. Prior set (condition split + membership, 2026-06-03) landed and trimmed.

T11 is sequenced before T10 deliberately: it characterizes the *current* V24/V25 render surface, which is the baseline T10's suppression is judged against. Doing T10 first leaves no before-state to compare to.

| id | st | Task | Cites | Description |
|---|---|---|---|---|
| T7 | x | Signal registry | V5, V8, V9, V27 | Canonical signal table in Detector: key → detector method, rule slug, positive label, `gp-no-*` class. `Condition::evaluate()`/`get_rules()` + body-class `$map` become loops over it. Persisted slugs + class names byte-identical before/after. |
| T8 | x | Featured-image config-replay non-singular | V22 | Extend `layout_element_disables()` to `_generate_disable_featured_image`; OR into featured-image signal when `! is_singular()`. Closes V22 gap; update architecture.md signal map + V22 on land. |
| T9 | x | Environment seam + tests | V1, V2, V5, V6 | Internal seam under Detector (~6 reads), WP/GP adapter + in-memory fake. New `composer.json` + PHPUnit. Tests exercise `states()` only. V1/V2/V5/V6 become executable. |
| T12 | x | Integration test surface (fixtures + seam fidelity) | V4, V22, V23, V26, V28 | T9 covers the Detector against the **fake**, by design — so nothing verified the fake's own assumptions about GP/GB internals. Adds `tools/fixtures/layout-states/` (13 pages + 6 elements, composing on core-structures v4) and `seam-fidelity.php`, 28 assertions against `BWS_GP_WP_Environment` on real WP/GP: `meta_query` `!=` semantics incl. deleted-row behaviour, V4 three-arg replay proven discriminating both ways, V23's `''` fatal proven real *and* its remedy, the V26 sidebar enum, and non-singular request state (V22/T8). Landed 2026-07-21 (`1be570b`), verified on GB Pro 2.7.0-beta.1 / GP Premium 2.5.6. |
| T13 | x | V2 poisoned-signal proof | V2, V20 | Adds `poisoned-signal.php`, 20 assertions making V2 executable rather than documented. Proves the Block Element's unconditional `remove_action` (`class-block.php:169-175`) really does strip `generate_construct_header`/`_footer` on `ls-page-poisoned`, that hook-state *would* therefore report a false "disabled", and that config-replay reports active anyway — with an `ls-page-layout-disabled` control proving config-replay still discriminates. Order is load-bearing (`remove_action` is process-global, one process per run): control arm first, re-checked immediately before poisoning so a reorder fails loudly. Mutation-checked — reverting `is_header_disabled()` to hook-state fails exactly one assertion by name. Landed 2026-07-21, verified on GB Pro 2.7.0-beta.1 / GP Premium 2.5.6. |
| T14 | . | Version-drift canary | V27, V28 | `tools/probes/registry-shape.php` reports the upstream surface (registry method signatures, registered condition slugs, `show_data()` arity) but must be run by hand and read by eye. Testbed auto-updates to betas, so drift is silent until something fails for a reason that looks local. Convert to a pass/fail assertion against a recorded expected shape, so a GB/GP release that moves the surface fails loudly. See the `testbed-tracks-betas` note on why this env is lookahead rather than a production mirror. |
| T11 | . | Render-level test harness (HTTP) | V24, V25 | Assert on **rendered output**, unreachable from wp-cli at any query state. Build on the env's `bin/smoke.sh` `fetch` helper (curl through the container, `--resolve` to 127.0.0.1) against the seeded `ls-page-metabox-*` fixtures, which already carry the `_generate-disable-*` keys. Characterizes the current V24/V25 surface: Featured Image + Secondary Nav are CSS-only (full regression surface), Primary Nav partial via `#mobile-header`. Prerequisite for T10 — establishes the before-baseline T10's after-state is judged against. |
| T10 | . | Approach B: PHP suppression for CSS-only toggles | V14, V24, V25 | Run `docs/proto/approach-b-snippet.php` on **testbed** (markers + visual + GB Pro composition checks), then port into plugin: `has_nav_menu` filter (secondary nav), `remove_action` ×2 (featured image), `remove_action` (`#mobile-header`), keyed on `_generate-disable-*`, `wp:60`. Backprop V14; Approach A obsolete. **Unblocked 2026-07-21** — the wp-litespeed env is the staging this was gated on: the `ls-page-metabox-*` fixtures supply toggle-ON cases and `ls-page-baseline` the toggle-OFF control, so both the suppression and the over-suppression check are mechanizable. The prototype's markers (`BWS-PROTO-B: * suppressed`) are grep-able from `fetch`, replacing the View-Source step. Two parts still need a human: the visual confirmation, and the GB Pro composition check (a page carrying both a metabox toggle and a GB Pro Layout Element condition on the same element — no such fixture exists yet; add one to layout-states). |

---

## Deferred work

Not built until a concrete need is scoped.

| Item | Invariant | Description |
|---|---|---|
| Page Hero toggle: hook-state vs config-replay choice | V21 | Add admin toggle so users can choose whether Page Hero "Disable featured image" / "Disable title" toggles are treated as disable signals or ignored. Both interpretations are valid per-site. |
| ~~Layout Element featured image on non-singular~~ | V22 | **Promoted to T8** (in-flight, 2026-07-08). |
| CSS-neutralize ("the fix") disable toggle — **Approach A: re-emit CSS** | V14, V24, V25 | Add setting or filter to disable the CSS-neutralize independently. Per V24/V25 the regression surface is Featured Image + Secondary Nav (full) and Primary Nav's `#mobile-header` wrapper (partial), so a blanket "fix OFF when GB Pro absent" default is coarser than needed — a finer option is to keep neutralize ON but re-emit only the load-bearing rules from the original `generate_disable_elements()` body: `.generate-page-header, .page-header-image, .page-header-image-single` (featured image), `#secondary-navigation` (secondary nav), and `#mobile-header` (only when `$disable_nav` and Menu Plus mobile header active), while nulling the redundant header/footer/site-navigation rules. Default: fix OFF when GB Pro absent (safe, simple); fix ON when GB Pro present. Fix-only mode becomes a user-acknowledged opt-in rather than a hard regression. V14 needs updating when implemented. **Fallback only — obsolete if T10 (Approach B) passes staging.** |
| ~~Convert CSS-only toggles to PHP suppression — **Approach B (preferred)**~~ | V14, V24, V25 | **Promoted to T10** (in-flight, 2026-07-08; gated on staging validation). Mechanism detail preserved in T10 + `docs/proto/approach-b-snippet.php`. Original analysis: PHP suppression keyed on `_generate-disable-*` meta dissolves the V14 regression rather than papering it — Secondary Nav via `has_nav_menu` filter (GP's own pattern, `class-layout.php:534`), Featured Image via `remove_action` (`featured-images.php:96,114`), `#mobile-header` via `remove_action` (`generate-menu-plus.php:1070`). Only the 3 CSS-only surfaces — don't double-suppress header/footer/top-bar. |
| Container type condition | V8 | `gp_theme_container` condition (reserved slug, V27) for full-width vs contained. GP emits native body classes already; plugin layer justified only if a GB Pro condition on container type, non-singular coverage, or Customizer-default detection is needed. None currently scoped. |
