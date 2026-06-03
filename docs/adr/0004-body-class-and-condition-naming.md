# Body-class and condition naming: negative `gp-no-*` classes, positive "Active" conditions, no duplication of GP-native classes

## Context

The plugin surfaces GP layout state two ways: body classes (CSS hook) and GB Pro conditions (render gate). Both need names. Two recurring questions drove this decision:

1. Should the plugin emit a body class for a given state at all, or does GP/GP Premium already emit one?
2. What polarity and vocabulary should each surface use — "disabled"/"no" vs "active"?

GP and GP Premium already emit native body classes for some layout axes (sidebar, container type) but **nothing positive** for the seven element disable states (GP Premium only *removes* `featured-image-active` on featured-image disable).

## Decision

**1. Don't duplicate what GP already emits.** Before emitting a body class, check for a native one.

- **Sidebar** — GP emits `right-sidebar` / `left-sidebar` / `no-sidebar` / `both-sidebars` → plugin emits **no** sidebar class. Sidebar gets the *condition only*; CSS targets GP's native classes.
- **Container type** — GP emits `full-width-content` / `contained-content` → deferred, no plugin class.
- **Seven disable states** — GP emits nothing positive → plugin's `gp-no-*` classes fill a real gap and are kept.

**2. Body-class names are `gp-no-{component}`** (`gp-no-header`, `gp-no-footer`, `gp-no-primary-nav`, `gp-no-secondary-nav`, `gp-no-top-bar`, `gp-no-featured-image`, `gp-no-content-title`). State-describing not command (`no-` vs `disable-`), matches GP's native body-class idiom (`no-sidebar`), shortest option. `gp-` prefix kept for discoverability and GP-ecosystem semantics, accepting low future-collision risk over a neutral `bws-` namespace.

**3. Condition vocabulary is positive ("Active"); body-class vocabulary is negative ("no"). They diverge on purpose.** The condition's common case ("show this element when the component is showing") must be a positive `Is`, never a double-negative — so rules are named by the active/present state. Body classes mark the *exception* (the disabled state), because that's the useful CSS hook (GP emits nothing for the disabled state). The two vocabularies are **not** meant to match.

**4. Featured image specifically — do NOT reuse GP's `featured-image-active`.** That class is render-based (requires a thumbnail) and drops out on no-thumbnail-with-fallback pages even when the feature is enabled. The plugin emits its own **config-based** featured-image signal (negative body class + "Featured Image Active" condition), driven by the Detector, never by GP's class or `has_post_thumbnail()`. The two must not be conflated or "optimized" into one. (See V7.)

## Consequences

- Class names are settled; renaming later is a breaking change for any site CSS targeting them.
- The deliberate vocabulary divergence (positive condition, negative class) is a standing answer to the recurring "why don't these match?" question — do not "harmonize" them.
- Enforced by invariants V8 (no sidebar/container class, only `gp-no-*`), V9 (vocabulary divergence), V7 (config-based featured image). This ADR is the rationale; those invariants are the testable contract.
