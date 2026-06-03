# GP Layout Conditions Plugin

Standalone WordPress plugin that makes GeneratePress disable states (header/footer/nav/etc.) usable by GeneratePress Block Elements — so a Block Element that replaces a theme section respects the same disable settings the native section would.

This file is the **ubiquitous language** — what each term means, what to call it, what to avoid. The permanent *contract* (invariants, bug ledger, signal map) lives in `docs/architecture.md`; *decisions and rationale* in `docs/adr/`; *in-flight work* in `SPEC.md` and `docs/ROADMAP.md`.

## Language

**Disable state**:
A boolean reflecting whether a given GeneratePress section (header, footer, primary nav, secondary nav, top bar, featured image, content title) is suppressed for the current request. Sourced from per-post metabox meta and/or Layout Element hook changes.
_Avoid_: "hidden", "off" (ambiguous about mechanism)

**Active = not-disabled-by-config (not actual-render).** A component's "Active" state means GP configuration has not disabled it — *not* that it actually renders. "Featured Image Active" is true whenever the featured image is not disabled, even on a post with no thumbnail set. This is deliberate: it tracks the setting, is what the Detector reliably knows, and supports using a Featured Image Block Element with a **fallback image** — the element must render (Active = true) precisely when there is no thumbnail. A render-based semantic (`has_post_thumbnail()`) would wrongly hide the fallback. GP's native `featured-image-active` class differs (it requires a thumbnail); the plugin's condition intentionally does not. (Contract: V7. Rationale: ADR-0004.)

**Block Element**:
A GeneratePress Premium `gp_elements` entry that renders block content onto a theme hook (e.g. `generate_header`), replacing the native section. Distinct from a **Layout Element**.
_Avoid_: "header element" / "footer element" used loosely

**Layout Element**:
A GeneratePress Premium `gp_elements` entry that applies layout settings (including disables) via display-rule conditions. Sets disable state through hook changes at `wp` priority 100. Distinct from a **Block Element**.

**Disabling display:none** (a.k.a. CSS-neutralize):
Pre-defining `generate_disable_elements()` to return `''`, suppressing the `display:none` GP Premium emits for the **per-post Disable Elements metabox** (`.site-header`/`.site-footer`/etc.). A Layout Element disables by hook-removal, not CSS, so it is not the target. Not a standalone fix — it only stops GP's over-broad blanket hide (which would catch the top bar inside a header Block Element, the legal section inside a footer Block Element, etc.); the conditions then do the precise hiding. Without it the CSS hides any Block Element inside a suppressed wrapper. (Scope detail: architecture.md "Neutralize scope".)
_Avoid_: "the fix" (fixes nothing alone), "the override".
_Avoid_: "the override"

**Detector**:
The plugin's single source of truth for current disable state. Both the body-class consumer and the condition consumer read from it. Hybrid: hook-state for non-poisoned signals, config-replay for header/footer. Lazy + memoized — full resolution runs at most once per request (V5). (Mechanism: ADR-0001, V5.)

**Disable layer**:
One of three independent sources that can suppress a section. (1) **Customizer** — global defaults (granular only for sidebar config by page/archive/post; not relevant to the 7 disable states). (2) **Layout Element** — by location condition; the common case here. (3) **Post metabox** — per individual post; least common but must work. A layer can only *disable*, never re-enable — so combined disable state is a pure OR across layers (V1).

**Poisoned signal**:
A hook-state check that can't distinguish "GP disabled this section" from "a Block Element took over this hook." Applies to header/footer only: a header/footer Block Element unconditionally `remove_action`s the native construct to claim the hook, so `! has_action(...)` reads "disabled" on every page that has the Block Element. These two signals use config-replay instead (V2, ADR-0001).

**Condition** (`gp_theme_element`, `gp_theme_sidebar`):
The custom GenerateBlocks Pro condition types. "Theme Element Status" (`gp_theme_element`) gates a block on element disable state; "Theme Sidebar" (`gp_theme_sidebar`) on the resolved sidebar layout. Split into separate registry slugs because the slug is persisted in saved condition data (V27). Reserved future slug: `gp_theme_container`. Requires GB Pro. (Rules + counts: architecture.md V10/V11/V26.)

**Page-level (not loop-aware)**:
All disable states and the sidebar layout are properties of the current *page*, not of any post in a query loop. GB Pro hands `evaluate()` a loop-item `post_id` in `$context`, but both conditions **discard** it and report page-level state via the Detector (which reads `get_queried_object_id()`). A theme-layout condition inside a Query Loop answers about the page, not the item — correct, since loop items have no header/footer/sidebar of their own (V6).

**Sidebar layout**:
The resolved sidebar enum for the current page — `left-sidebar` | `right-sidebar` | `no-sidebar` | `both-sidebars`. Resolved by GP's own `generate_get_layout()`, which already folds all three disable layers in GP's precedence — so the Detector reads it with one guarded call (no replay, no poisoning; it's a value filter, not a hook-removal). GP emits native body classes for this, so the plugin adds **no** sidebar body class — sidebar gets the *condition only* (ADR-0004).

## Naming rationale

The two surfaces (body classes, conditions) use opposite polarity **on purpose**. The decision and class-name list are in **ADR-0004**; the language reasons are here:

- **Conditions are named by the active/present state** ("Header Active", "Left Sidebar Active") so the common use case — "show this element when the component is showing" — is a positive `Is`, never a double-negative. "Active" is GP's own vocabulary: the native `featured-image-active` class and the editor's "Active Elements" panel both use it to mean "renders on this page."
- **Body classes are negative** (`gp-no-{component}`) because they mark the *exception* (the disabled state) — the useful CSS hook, since GP emits nothing positive there.
- **They are not meant to match.** This is the standing answer to "why don't the condition labels and body classes line up?" — they answer different questions (positive render-intent vs negative CSS-exception).
- **Sidebar rules read as membership, not exclusive** ("Left Sidebar Active" is true on a both-sidebars page too) so "show when the left sidebar is present" works regardless of whether the right one is also present. "Both" is composed via AND; "No Sidebars" (plural) avoids the "No Sidebar Active" negation misread (V26).

**GB constraint (language-adjacent):** the operator selector cannot be preselected for custom condition types — the user always picks the operator after the rule. Device-style `is`/`is_not` on a named-state rule keeps that pick trivial.

## Pointers

- **Invariants, bug ledger, signal map, neutralize scope** → `docs/architecture.md`
- **Decisions + rationale** (hybrid detection, post-meta reads, dependency gating, naming) → `docs/adr/`
- **Accepted detection gaps** (secondary nav, Customizer layer, archive featured image, Page Hero ambiguity) → architecture.md V20–V22 + signal map
- **Deploy-together constraint** (v1 fix without v2 condition is a regression on GB Pro sites) → V14, ADR-0003
- **In-flight work + deferred items** → `SPEC.md`, `docs/ROADMAP.md`
