# Hybrid disable-state detection: hook-state plus config-replay

## Context

The Detector must report, per request, whether each of seven GeneratePress sections (header, footer, primary nav, secondary nav, top bar, featured image, content title) is disabled. Disable can come from three layers — Customizer (global), Layout Element (by location), or post metabox (per post) — and a layer can only disable, never re-enable, so the combined result is a pure OR across layers.

The obvious approach is effect-based: read GP's resulting hook state at render time (`has_action` / `has_filter`). It is ~10 lines and resilient to GP version changes because it reads outcomes, not internals.

But the header and footer hook signals are **poisoned**: a header/footer Block Element unconditionally calls `remove_action('generate_header','generate_construct_header')` (gp-premium `elements/class-block.php:170`) to claim the hook — independent of any disable. So `! has_action(...)` reads "disabled" on every page that has a header/footer Block Element, which is exactly the audience this plugin serves. The signal is useless precisely where it is needed.

## Decision

The Detector is **hybrid**, OR-combining sources per signal:

- **Hook-state** for the four non-poisoned signals — nav, content title, top bar, featured image. Block Elements do not take those hooks, and both Layout Element and post metabox set identical hook signals, so one check covers both layers cheaply.
- **Config-replay** for header and footer — read post metabox meta (`_generate-disable-header` / `_generate-disable-footer`) OR enumerate `gp_elements` Layout posts, reading each one's `_generate_disable_site_header` / `_generate_disable_footer` meta and calling GP's own public `GeneratePress_Conditions::show_data($conditions, $exclude, $roles)` to test whether it applies to the current page.
- **Secondary nav**: post metabox only — no clean hook signal (Layout Element uses an array-callback on `has_nav_menu`), Layer-2 gap documented.
- **Customizer (Layer 1)** is excluded for all seven states: its only per-context granularity is sidebar config, which is not among the seven states.

OR semantics are not a design choice but a reflection of GP's behaviour: every layer's disable is an idempotent `remove_action`, with no precedence negotiation and no re-enable path.

## Consequences

- Config-replay reuses GP's own `show_data()` evaluator rather than reimplementing condition logic, so version risk is limited to meta-key names, not condition semantics.
- Header/footer detection costs a `gp_elements` query plus a `show_data()` loop; acceptable because the Detector is a single per-request surface and can cache.
- Known gaps, documented rather than chased: Customizer-driven header/footer disable, and Layout-Element-driven secondary-nav disable, are not detected.
