# Dependency gating: Requires Plugins header for GP Premium, runtime gate for GB Pro

## Context

The plugin hard-depends on GP Premium (for `generate_get_layout()`, Layout Element classes, disable meta) and soft-depends on GenerateBlocks Pro (only the v2 condition needs it; v1's fix and body classes work without it).

WordPress 6.5+ offers a `Requires Plugins:` header. An earlier assumption held that this only resolves wordpress.org slugs and so could not enforce commercial off-repo plugins. That assumption is **false**: `Requires Plugins: generateblocks-pro` is proven working in a sibling plugin (BWS GB Dynamic Tag Extensions). WordPress matches the installed plugin's folder slug and blocks activation when the dependency is absent — and auto-deactivates dependents if the dependency is later deactivated. The wp.org limitation only affects the auto-install prompt, not enforcement of an already-installed dependency.

## Decision

- **GP Premium — hard, via header.** `Requires Plugins: gp-premium`. WordPress blocks activation without it and auto-deactivates this plugin if GP Premium is removed, so no activation hook and no runtime fatal-guard are needed.
- **GB Pro — soft, via runtime gate.** The v2 condition registers on `generateblocks_register_conditions` with an inner `class_exists('GenerateBlocks_Pro_Conditions_Registry')` check. Deliberately **not** added to the header, to preserve a GeneratePress-Premium-only deployment path: on a site without GB Pro, v1 (CSS-neutralize + body classes) still runs and is useful.
- T2 (CSS-neutralize), the Detector, and body classes load unconditionally past the header gate.

## Consequences

- The GP-only fallback is retained by choice: requiring GB Pro in the header would simplify the code but delete that path.
- Body classes keep their dual justification: CSS convenience on GB Pro sites, and the sole disable-state lever on GP-only sites.
- Header enforcement removes the need for the activation hook and runtime GP-Premium guard considered earlier.
