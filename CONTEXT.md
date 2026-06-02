# GP Layout Conditions Plugin

Standalone WordPress plugin that makes GeneratePress disable states (header/footer/nav/etc.) usable by GeneratePress Block Elements — so a Block Element that replaces a theme section respects the same disable settings the native section would.

## Language

**Disable state**:
A boolean reflecting whether a given GeneratePress section (header, footer, primary nav, secondary nav, top bar, featured image, content title) is suppressed for the current request. Sourced from per-post metabox meta and/or Layout Element hook changes.
_Avoid_: "hidden", "off" (ambiguous about mechanism)

**Active = not-disabled-by-config (not actual-render).** A component's "Active" state means GP configuration has not disabled it — *not* that it actually renders. "Featured Image Active" is true whenever the featured image is not disabled, even on a post with no thumbnail set. This is deliberate: it tracks the setting, is what the Detector reliably knows, and supports using a Featured Image Block Element with a **fallback image** — the element must render (Active = true) precisely when there is no thumbnail. A render-based semantic (`has_post_thumbnail()`) would wrongly hide the fallback. GP's native `featured-image-active` class differs (it requires a thumbnail); the plugin's condition intentionally does not.

**Block Element**:
A GeneratePress Premium `gp_elements` entry that renders block content onto a theme hook (e.g. `generate_header`), replacing the native section. Distinct from a **Layout Element**.
_Avoid_: "header element" / "footer element" used loosely

**Layout Element**:
A GeneratePress Premium `gp_elements` entry that applies layout settings (including disables) via display-rule conditions. Sets disable state through hook changes at `wp` priority 100. Distinct from a **Block Element**.

**The fix** (CSS-neutralize):
Pre-defining `generate_disable_elements()` to return `''`, suppressing GP's wholesale `display:none` on section wrappers. The load-bearing change — without it the CSS hides any Block Element inside the wrapper.
_Avoid_: "the override"

**Detector**:
The plugin's single source of truth for current disable state. Both the body-class consumer and the condition consumer read from it. Hybrid: hook-state for non-poisoned signals, config-replay for header/footer (see ADR-0001).

**Disable layer**:
One of three independent sources that can suppress a section. (1) **Customizer** — global defaults (granular only for sidebar config by page/archive/post; not relevant to the 7 disable states). (2) **Layout Element** — by location condition; the common case here. (3) **Post metabox** — per individual post; least common but must work. A layer can only *disable*, never re-enable — so combined disable state is a pure OR across layers.

**Detector compute model**:
Lazy + memoized. `states()` runs the full resolution (hook-checks + header/footer config-replay) on first call and caches the result in a static property for the rest of the request. The expensive part — the `gp_elements` query plus `show_data()` loop for header/footer — runs at most once per request regardless of how many blocks/rules ask. First call is always after `wp` (body-class consumer at `wp` 110; condition consumer at `render_block`), so timing is inherently safe; no invalidation. Build-time verification: confirm on a live page that the memoized value is correct when both consumers are active.

**Poisoned signal**:
A hook-state check that can't distinguish "GP disabled this section" from "a Block Element took over this hook." Applies to header/footer only: a header/footer Block Element unconditionally `remove_action`s the native construct to claim the hook, so `! has_action(...)` reads "disabled" on every page that has the Block Element. These two signals use config-replay instead.

**Condition** (`gp_layout_state`):
The custom GenerateBlocks Pro condition type. Makes a block render or not based on a **disable state** (boolean) or the **sidebar layout** (enum). The mechanism that actually makes a Block Element react to GP layout config. Requires GB Pro.

**Page-level (not loop-aware)**:
All disable states and the sidebar layout are properties of the current *page*, not of any post in a query loop. GB Pro hands the condition's `evaluate()` a loop-item `post_id` in `$context`, but `gp_layout_state` **discards** it and always reports page-level state via the Detector (which reads `get_queried_object_id()`). A `gp_layout_state` condition on a block inside a Query Loop answers about the page, not the item — correct for these states, since loop items have no header/footer/sidebar of their own.

**Sidebar layout**:
The resolved sidebar enum for the current page — `left-sidebar` | `right-sidebar` | `no-sidebar` | `both-sidebars`. Resolved by GP's own `generate_get_layout()`, which already folds all three disable layers in GP's precedence — so the Detector reads it with one guarded call (no replay, no poisoning; it's a value filter, not a hook-removal). The condition's primary justified use: the target site has sidebars on by default with per-page overrides, and blocks need to react to the resolved layout. GP already emits native body classes for this (`right-sidebar` etc.), so the plugin adds **no** sidebar body class — sidebar gets the *condition only*; CSS targets GP's native classes.

## Scope

In scope: the seven **disable states** plus **sidebar layout** (left/right/none/both).

Deferred (not built until a concrete need is scoped):
- **Container type** (full-width vs contained). GP already emits native body classes `full-width-content` / `contained-content` (singular pages only, and only when set explicitly — not for the Customizer default). Those serve the CSS padding-fix path today. A plugin layer is only justified if a GB Pro *condition* on container type is needed, or non-singular coverage, or detecting the Customizer-default case — none currently scoped.
- **Container width** (px value) — continuous value, poor fit for boolean conditions/classes; not the real axis (container *type* is).

## Principle: don't duplicate what GP already emits

Before the plugin emits a body class, check whether GP/GP Premium already provides one. Verified state:
- **Sidebar** — GP emits `right-sidebar` / `left-sidebar` / `no-sidebar` / `both-sidebars` natively → plugin emits no sidebar class.
- **Container type** — GP emits `full-width-content` / `contained-content` natively → deferred, no plugin class.
- **The seven disable states** — GP emits **nothing** positive (GP Premium only *removes* `featured-image-active` on featured-image disable) → plugin's `gp-no-*` classes fill a real gap and are kept.
- **Featured image specifically — do NOT reuse GP's `featured-image-active`.** That class is render-based (requires a thumbnail) and drops out on no-thumbnail-with-fallback pages even when the feature is enabled. The plugin emits its own **config-based** featured-image signal (negative body class + the "Featured Image Active" condition), driven by the Detector, never by GP's class or `has_post_thumbnail()`. The two must not be conflated or "optimized" into one.

Class names are **settled**: `gp-no-{component}` (e.g. `gp-no-header`, `gp-no-footer`, `gp-no-primary-nav`, `gp-no-secondary-nav`, `gp-no-top-bar`, `gp-no-featured-image`, `gp-no-content-title`). Rationale: state-describing not command (`no-` vs `disable-`), matches GP's native body-class idiom (`no-sidebar`), shortest option; `gp-` prefix kept for discoverability and GP-ecosystem semantics, accepting low future-collision risk over a neutral `bws-` namespace.

## Operator / rule model — follows GB Pro's Device condition

Modeled exactly on GB Pro's core **Device** condition (`class-condition-device.php`): rules are self-contained predicates, no value field, operators `is` / `is_not`.

- `get_rule_metadata()` → `needs_value => false`, `value_type => 'none'` for every rule (no value field).
- Operators `['is', 'is_not']`. `evaluate()` computes the per-rule boolean, then `return 'is_not' === $operator ? ! $match : $match;`.
- Each rule label names a concrete state, so the operator pick is a plain polarity choice — no double-negative, no value to interpret.

Rules are named by the **active/present** state so the common use case ("show this element when the component is showing") is a positive `Is`, never a double-negative. "Active" is GP's own vocabulary for this — the native body class `featured-image-active` and the editor's "Active Elements" panel both use it to mean "renders on this page."

Rules (11):
| Rule label | `evaluate` true when | common "Is" reads |
|---|---|---|
| Header Active | header NOT disabled | show element when header shows |
| Footer Active | footer NOT disabled | |
| Primary Nav Active | primary nav NOT disabled | |
| Secondary Nav Active | secondary nav NOT disabled | |
| Top Bar Active | top bar NOT disabled | |
| Featured Image Active | featured image NOT disabled | mirrors GP's `featured-image-active` |
| Content Title Active | content title NOT disabled | |
| No Sidebars Active | sidebar layout = none | |
| Left Sidebar Active | sidebar layout = left | |
| Right Sidebar Active | sidebar layout = right | |
| Both Sidebars Active | sidebar layout = both | |

Reads e.g. "GP Layout **Is** Header Active" → show the header element when the header is not disabled. The component rules' `evaluate()` returns `! disabled_state`; sidebar rules return an enum match. All rules carry the uniform "Active" suffix so every rule's common case is a positive `Is`. Sidebar labels pluralize by count: "No Sidebars Active" / "Both Sidebars Active" (plural, parallel) vs "Left Sidebar Active" / "Right Sidebar Active" (singular). "No Sidebars" also avoids the negation misread of "No Sidebar Active."

**Condition vs body-class vocabulary diverge on purpose.** The condition uses the positive "Active" (common case is `Is`, no double-negative). Body classes mark the *exception* — the disabled state — because that's the useful CSS hook (GP emits nothing for the disabled state). So body classes stay negative ("disabled"/"no"); they are not meant to match the condition labels. This resolves the earlier naming-pass question.

**Discovered GB constraint:** the operator selector cannot be preselected for custom condition types — the user always picks the operator manually after choosing the rule. The Device-style `is`/`is_not` on a named-state rule keeps that pick trivial. (Holds unless GB changes.)

## Known detection gaps (accepted)

- **Secondary nav** — detected via post metabox meta only (`_generate-disable-secondary-nav`, singular-only). A Layout-Element secondary-nav disable is **not** detected (no clean hook signal; Layout uses an array-callback on `has_nav_menu`). Accepted: the target site uses secondary nav but does not disable it via Layout Element. The header/footer config-replay can be extended to cover it later if that changes.
- **Customizer (Layer 1)** — header/footer disable set globally via Customizer is not detected. Rare; if set, the user would not have a header/footer Block Element anyway.

## Flagged ambiguities

**"Phase" = build order, not deploy milestone.** v1 (fix + body classes) and v2 (condition) are sequenced for implementation but **deploy together** on GB Pro sites. v1 alone on a GB Pro site is a regression: it removes the CSS hide without supplying the condition replacement, so a section that should be disabled would show. Body classes are the GP-only (no GB Pro) fallback path, not a standalone release.

**Dependency gating (ADR-0003).** GP Premium is hard-required via the `Requires Plugins: gp-premium` header (WordPress blocks activation without it and auto-deactivates this plugin if it is removed — proven mechanism). GB Pro is **soft**: the condition runtime-gates on `class_exists`, deliberately kept out of the header so a GP-Premium-only site still gets the fix + body classes. This is the chosen design (Design 1), preserving the GP-only fallback even though no current site needs it.
