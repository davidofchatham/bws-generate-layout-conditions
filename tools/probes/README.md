# tools/probes

Read-only checks against the **upstream** surface — GB Pro and GP Premium — as
opposed to `tools/fixtures/`, which checks this plugin against seeded content.

Neither file here writes anything, registers anything, or mutates global state.
They can run in any order, before or after any fixture suite.

## The two files

`registry-shape.php` **reports**. It dumps the upstream shape — registry methods
and properties, `show_data()`'s signature, which classes are present — with no
expectations at all. For exploring a new GB/GP version, or answering "what does
this actually look like now", by hand.

`upstream-surface.php` **asserts** (T14). Same territory, but pinned to a
recorded expectation and exiting non-zero on drift. This is the guard;
registry-shape is the microscope.

Both exist on purpose. A report cannot fail a build, and an assertion tells you
only that something moved, not what the new shape is — when the canary fails,
`registry-shape.php` is the natural next command.

## Why the canary exists

This testbed [tracks GB/GP betas](../../docs/ROADMAP.md) — it is a lookahead
environment, not a production mirror. Upstream moves under it without warning.

The failure mode that motivated it: `GenerateBlocks_Pro_Conditions_Registry::register()`
validates that the class implements `GenerateBlocks_Pro_Condition_Interface` and,
if it does not, **returns `false`**. No exception, no notice, no log line. Add a
method to that interface upstream and both of this plugin's conditions simply
stop appearing in the GB Pro UI, with nothing anywhere saying why.

That class of change — silent, upstream, total — is what this file is for.

## What it pins

| Section | Pins |
|---|---|
| `Registry::register()` | Static, public, exactly 3 required params, in the order `(type, args, classname)`. The plugin passes them positionally, so order is contractual, not cosmetic. |
| condition interface | All 5 methods with exact parameter lists, **and that no method was added** — the silent-rejection case above. Plus that the abstract still implements the interface and still supplies concrete `get_operators_for_rule()` / `sanitize_value()`, which the plugin inherits rather than defines. |
| registration hook | `generateblocks_register_conditions` is still fired. Renamed, our callback never runs. |
| the two slugs | `gp_theme_element` / `gp_theme_sidebar` land in `get_all()`, point at our classes, keep `[is, is_not]`, and instantiate (V27). The end-to-end check: catches load-order and gating failures the reflection assertions cannot see. |
| GP Premium | `show_data()` static and accepting ≥3 args (V4 — a two-arg replay reports excluded pages as disabled), and `generate_get_layout()` present (V26 — absent, every request reports `no-sidebar` with no error). |

## When it fails

**Do not edit the expectation to match.** That converts a working alarm into a
comment.

1. Run `registry-shape.php` to see the new shape.
2. Diff the upstream source for the moved symbol.
3. Decide whether the plugin must adapt, and fix the plugin.
4. Only then update the expectation here — recording the version that changed it.

Mutation-checked: three simulated drifts (reordered `register()` params, an added
interface method, a slug that never registered) each failed in their own section
with a message naming the cause.

## Running

```bash
bin/wp.sh <site> eval-file /plugins/bws-generate-layout-conditions/tools/probes/upstream-surface.php
bin/wp.sh <site> eval-file /plugins/bws-generate-layout-conditions/tools/probes/registry-shape.php
```

`seed-all.sh` runs `upstream-surface.php` automatically, once per plugin, **before**
any blueprint. Order is deliberate: when upstream breaks the surface, the
blueprint suites fail too — but with local-looking messages. Seeing the canary
fail first names the real cause before anything downstream can mislead.

Preconditions: GB Pro and GP Premium active with the Elements module on. Absent
upstream is a broken environment, not drift, so the file hard-errors there rather
than reporting a wall of false failures.
