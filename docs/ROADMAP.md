# Roadmap — bws-generate-layout-conditions

In-flight build tasks and deferred work.

- **Permanent contract** (invariants, bug ledger, signal map) → `architecture.md`
- **Decisions + rationale** → `adr/`
- **Working build state** → this file

---

## In-flight build tasks

Spec is ahead of code. These invariants are recorded but not yet implemented.

| Task | Cites | Description | Status |
|---|---|---|---|
| Split condition registration | V27 | Replace single `gp_layout_state` registration in `class-condition.php` with two: `gp_theme_element` ("Theme Element Status", 7 component rules) + `gp_theme_sidebar` ("Theme Sidebar", 3 sidebar rules). One class serving both registrations, or two classes. Operators `['is','is_not']` + `needs_value=false` on each. | not started |
| Sidebar membership semantics | V26, B4 | In `evaluate()`: `left_sidebar_active` = `in_array(enum, ['left-sidebar','both-sidebars'])`; `right_sidebar_active` = `in_array(enum, ['right-sidebar','both-sidebars'])`; `no_sidebars_active` = `'no-sidebar' === enum`. Drop `both_sidebars_active` rule + case. Detector unchanged. | not started |
| Sync CONTEXT.md to V26/V27 | V26, V27 | CONTEXT.md still describes single `gp_layout_state` condition, "Rules (11)" table with exclusive-match sidebar rows, and `Both Sidebars Active`. Update the Condition definition, the rule table (11→10, membership), and sidebar-layout language. | not started |
| Refresh SPEC.md pointer | — | SPEC.md still reads "Build complete. Placeholder." — now false (V26/V27 pending). Repurposed as the in-flight working doc. | done — see SPEC.md |

---

## Deferred work

Not built until a concrete need is scoped.

| Item | Invariant | Description |
|---|---|---|
| Page Hero toggle: hook-state vs config-replay choice | V21 | Add admin toggle so users can choose whether Page Hero "Disable featured image" / "Disable title" toggles are treated as disable signals or ignored. Both interpretations are valid per-site. |
| Layout Element featured image on non-singular | V22 | Config-replay path for featured image on archive/non-singular pages, parallel to header/footer config-replay. Requires querying Layout Elements with `_generate_disable_featured_image` and calling `show_data()`. |
| CSS-neutralize ("the fix") disable toggle | V14, V24, V25 | Add setting or filter to disable the CSS-neutralize independently. Per V24/V25 the regression surface is Featured Image + Secondary Nav (full) and Primary Nav's `#mobile-header` wrapper (partial), so a blanket "fix OFF when GB Pro absent" default is coarser than needed — a finer option is to keep neutralize ON but re-emit only the load-bearing rules from the original `generate_disable_elements()` body: `.generate-page-header, .page-header-image, .page-header-image-single` (featured image), `#secondary-navigation` (secondary nav), and `#mobile-header` (only when `$disable_nav` and Menu Plus mobile header active), while nulling the redundant header/footer/site-navigation rules. Default: fix OFF when GB Pro absent (safe, simple); fix ON when GB Pro present. Fix-only mode becomes a user-acknowledged opt-in rather than a hard regression. V14 needs updating when implemented. |
| Container type condition | V8 | `gp_theme_container` condition (reserved slug, V27) for full-width vs contained. GP emits native body classes already; plugin layer justified only if a GB Pro condition on container type, non-singular coverage, or Customizer-default detection is needed. None currently scoped. |
