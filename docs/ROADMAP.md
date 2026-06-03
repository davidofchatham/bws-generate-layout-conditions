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
| Split condition registration | V27 | Two classes in `class-condition.php`: `BWS_GP_Theme_Element_Condition` (`gp_theme_element`, 7 rules) + `BWS_GP_Theme_Sidebar_Condition` (`gp_theme_sidebar`, 3 rules). Operators `['is','is_not']` + `needs_value=false` on each. | done |
| Sidebar membership semantics | V26, B4 | `evaluate()`: left/right = `in_array(enum, [own,'both-sidebars'])`; no = `'no-sidebar' === enum`. `both_sidebars_active` dropped. Detector unchanged. | done |
| Sync CONTEXT.md + readme to V26/V27 | V26, V27 | Updated CONTEXT.md (condition defs, rule table 11→10 membership, page-level + sidebar language), readme.txt (condition slugs, rule table, deps), README.md (file map slug). | done |
| Refresh SPEC.md pointer | — | SPEC.md repurposed as the in-flight working doc. | done |

---

## Deferred work

Not built until a concrete need is scoped.

| Item | Invariant | Description |
|---|---|---|
| Page Hero toggle: hook-state vs config-replay choice | V21 | Add admin toggle so users can choose whether Page Hero "Disable featured image" / "Disable title" toggles are treated as disable signals or ignored. Both interpretations are valid per-site. |
| Layout Element featured image on non-singular | V22 | Config-replay path for featured image on archive/non-singular pages, parallel to header/footer config-replay. Requires querying Layout Elements with `_generate_disable_featured_image` and calling `show_data()`. |
| CSS-neutralize ("the fix") disable toggle | V14, V24, V25 | Add setting or filter to disable the CSS-neutralize independently. Per V24/V25 the regression surface is Featured Image + Secondary Nav (full) and Primary Nav's `#mobile-header` wrapper (partial), so a blanket "fix OFF when GB Pro absent" default is coarser than needed — a finer option is to keep neutralize ON but re-emit only the load-bearing rules from the original `generate_disable_elements()` body: `.generate-page-header, .page-header-image, .page-header-image-single` (featured image), `#secondary-navigation` (secondary nav), and `#mobile-header` (only when `$disable_nav` and Menu Plus mobile header active), while nulling the redundant header/footer/site-navigation rules. Default: fix OFF when GB Pro absent (safe, simple); fix ON when GB Pro present. Fix-only mode becomes a user-acknowledged opt-in rather than a hard regression. V14 needs updating when implemented. |
| Container type condition | V8 | `gp_theme_container` condition (reserved slug, V27) for full-width vs contained. GP emits native body classes already; plugin layer justified only if a GB Pro condition on container type, non-singular coverage, or Customizer-default detection is needed. None currently scoped. |
