# Roadmap — bws-generate-layout-conditions

In-flight build tasks and deferred work.

- **Permanent contract** (invariants, bug ledger, signal map) → `architecture.md`
- **Decisions + rationale** → `adr/`
- **Working build state** → this file

---

## In-flight build tasks

Active change set 2026-07-08 (see `SPEC.md`). Order = row order. Prior set (condition split + membership, 2026-06-03) landed and trimmed.

| id | st | Task | Cites | Description |
|---|---|---|---|---|
| T7 | x | Signal registry | V5, V8, V9, V27 | Canonical signal table in Detector: key → detector method, rule slug, positive label, `gp-no-*` class. `Condition::evaluate()`/`get_rules()` + body-class `$map` become loops over it. Persisted slugs + class names byte-identical before/after. |
| T8 | x | Featured-image config-replay non-singular | V22 | Extend `layout_element_disables()` to `_generate_disable_featured_image`; OR into featured-image signal when `! is_singular()`. Closes V22 gap; update architecture.md signal map + V22 on land. |
| T9 | . | Environment seam + tests | V1, V2, V5, V6 | Internal seam under Detector (~6 reads), WP/GP adapter + in-memory fake. New `composer.json` + PHPUnit. Tests exercise `states()` only. V1/V2/V5/V6 become executable. |
| T10 | . | Approach B: PHP suppression for CSS-only toggles | V14, V24, V25 | Run `docs/proto/approach-b-snippet.php` on staging (markers + visual + GB Pro composition checks), then port into plugin: `has_nav_menu` filter (secondary nav), `remove_action` ×2 (featured image), `remove_action` (`#mobile-header`), keyed on `_generate-disable-*`, `wp:60`. Gated on staging pass. Backprop V14; Approach A obsolete. |

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
