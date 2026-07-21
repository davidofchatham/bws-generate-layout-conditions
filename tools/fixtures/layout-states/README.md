# layout-states blueprint

GP **theme-structure** fixtures: `gp_elements` (Block / Layout / Page Hero),
per-post Disable-Elements metabox meta, sidebar layouts, and the Menu Plus
mobile header. Composes on
[`core-structures`](../../../../bws-gb-dynamic-tags-extensions/tools/fixtures/core-structures/)
(pins v4+) and redefines nothing it owns.

## Why a separate blueprint

core-structures owns **content** — CPTs, ACF groups, field values. This
blueprint owns **theme structure**. Different axis, no overlap, so composition
is by reference: it reuses core's posts and taxonomy and adds only `gp_elements`
and GP meta on its own `ls-`-prefixed pages.

That is also why there is **no `schema.php`** here, breaking the usual 5-file
shape: every post type and meta key this blueprint writes is registered by GP
Premium. There is nothing of our own to keep alive across a snapshot restore,
so there is no mu-plugin stub either.

## What the unit suite cannot cover

The plugin's PHPUnit suite runs the Detector against an in-memory fake
(`BWS_GP_Environment`, V28). That covers the *logic* well and runs in
milliseconds — this blueprint deliberately does not duplicate it.

What the fake cannot tell you is whether its own assumptions about GP Premium
and GB Pro are still true. Every invariant in `docs/architecture.md` is a claim
about upstream internals; the fake encodes those claims rather than verifying
them. These fixtures make them falsifiable:

| Fixture | Pins |
|---|---|
| `ls-el-header-block` / `ls-el-footer-block` | V2 — a Block Element on `generate_header`/`generate_footer` unconditionally `remove_action`s the native construct, so hook-state reads "disabled" on every page carrying it. The poisoned signal config-replay exists to route around. |
| `ls-el-layout-header-footer` | V2 — the config-replay layer itself. |
| `ls-el-layout-featured-archive` | V22/T8 — featured-image disable on a **non-singular** archive, where hook-state is meaningless (V20/B2). Targets `/department/sales/`. |
| `ls-el-layout-excluded` | V4 — replay must pass all **three** condition metas to `show_data()`. Verified discriminating: display-only `true`, all-three `false`. A two-arg replay would report this page disabled. |
| `ls-el-page-hero` | V21 — characterizes the Page Hero ambiguity (Hero embeds title/image, removing the hooks the Detector reads). Records current behaviour; does not assert it is correct. |
| `ls-page-metabox-*` | V24/V25 — the CSS-neutralize regression surface. Featured Image and Secondary Nav are CSS-only (full surface); Primary Nav is partial via the `#mobile-header` wrapper. |
| `ls-page-sidebar-*` | V26 — all four sidebar enum values, including `both-sidebars`, the only case that catches a regression to exclusive enum-matching. |

## The two test files, and the difference

`verify.php` asserts the **fixtures** landed and discriminate — so a suite
failure means "the Detector regressed", not "the fixture seeded nothing".

`seam-fidelity.php` asserts the **production adapter**
(`BWS_GP_WP_Environment`) reads them correctly. It is the only thing pinning
`BWS_GP_Fake_Environment` to reality: the fake encodes assumptions about
`meta_query` semantics, `show_data()` arity, and the `generate_get_layout()`
enum, and if one is wrong the unit suite still passes green while the plugin
breaks. It tests the adapter, never the Detector. 28 assertions:

| Section | Pins |
|---|---|
| `layout_element_ids()` | The real `compare => '!='` clause matches a set key and — the part that matters — does **not** match a **deleted** row. GP deletes the row when a toggle is unset, and there is no JOIN row to compare, so an element with only `_generate_disable_featured_image` must not leak into the header query. Also: layout-type-only, publish-only, unknown key → empty. |
| `conditions_pass()` | Real `show_data()` under a real main query. V4's three-arg replay proven discriminating both ways, plus an on-target/off-target control so an always-false `show_data()` can't make V4 pass for the wrong reason. Return type asserted `is_bool`. |
| V23 (inside the above) | Proves raw `''` meta really is fatal — currently `TypeError: in_array(): Argument #2 ($haystack) must be of type array, string given` — and that `?: array()` fixes it. If upstream ever tolerates `''`, the first arm stops throwing and this **reports** it, rather than the normalization quietly becoming dead code. |
| `sidebar_layout()` | All four seeded layouts return exactly the documented enum. A value outside it is flagged distinctly from a wrong-but-valid value: V26's membership math is unsafe in the first case, merely wrong in the second. |
| request-state | `is_singular()` / `queried_object_id()` on both a page **and** the `department:sales` archive — the non-singular branch (V22/T8) is unreachable if that goes true. Plus `post_meta()` returning `''` for unset (the V23 premise) and `has_hook()` against a known core callback. |

Run by `bin/seed-all.sh` automatically when present; other blueprints without
the file are unaffected.

## Preconditions

**GP Premium modules must be activated.** GP Premium gates every module behind
its own option and ships them **off**:

```bash
wp option update generate_package_elements activated
wp option update generate_package_disable_elements activated
wp option update generate_package_secondary_nav activated
wp option update generate_package_menu_plus activated
```

With Elements inactive, `GeneratePress_Conditions` never loads, config-replay
(V2) silently no-ops, and every element here is inert. `seed.php` hard-errors on
this rather than seeding into a dead environment — a fixture set that quietly
does nothing is worse than none.

This is a **reachable production state**, not just a test-env quirk: any site
running GP Premium with the Elements module off hits the same
`can_replay_conditions() === false` path.

## Seeding

```bash
bin/wp.sh <site> eval-file /plugins/bws-generate-layout-conditions/tools/fixtures/layout-states/seed.php
bin/wp.sh <site> eval-file /plugins/bws-generate-layout-conditions/tools/fixtures/layout-states/verify.php
bin/wp.sh <site> eval-file /plugins/bws-generate-layout-conditions/tools/fixtures/layout-states/seam-fidelity.php
```

Or via the orchestrator, which runs the whole family in compose order:
`bin/seed-all.sh <site>`.

Idempotent — upserts by `post_name`, safe to re-run.

## Query state: the trap

`GeneratePress_Conditions::show_data()` evaluates against the **current
request**. Under `wp eval-file` no main query has run — `is_singular()` is
false, `get_queried_object_id()` is `0` — so every location rule misses and
`show_data()` returns `false` for *both* arms of a comparison. The V4 test then
passes while verifying nothing.

`--url` does **not** fix this: it sets site context without running the query.

Bootstrap the query explicitly:

```php
wp( 'page_id=' . $page_id );   // now is_singular() is true and rules evaluate
```

`verify.php`'s `with_page()` helper does this and restores `$wp_query`
afterwards. Any test asserting on conditions must do the same.

Consequence for the test harness: config-replay **is** testable under WP-CLI
once the query is bootstrapped. Real HTTP is only needed for assertions about
`remove_action` side effects and rendered CSS.

## Meta value shapes

Not uniform, and the differences are load-bearing:

- **Layout element disables** → literal string `'true'`. GP's metabox writes
  `value="true"`; "off" means the **row is deleted**, never an empty string.
  Fixtures omit the key rather than setting `''`.
- **Block element disables** (Page Hero) → registered as bool via
  `register_meta`, but `rest_sanitize_boolean` only runs on the REST path.
  `update_post_meta( ..., true )` stores `'1'`. Both truthy; every consumer does
  a truthy check.
- **Display / exclude conditions** → list of `array( 'rule' => ..., 'object' => ... )`,
  `object` a **string** (`sanitize_key`). Real admin-written data is
  **sparse-indexed** (the save handler skips empty rules without reindexing) —
  `show_data()` iterates with `foreach` and does not care, but exact-equality
  assertions would.
- **User conditions** → flat list of strings.

## Constraints worth knowing

- `post_status` must be exactly `publish` — GP's loader queries publish-only.
- Only **one** Header element renders per request; a second is silently ignored.
- Non-block elements with **no** display conditions render nowhere.
- Elements only fire on `wp` / `current_screen`.

## Foreign dependency

One, deliberate: `department:sales` from core-structures, used as the populated
non-singular archive for V22. Re-seeding a private archive would duplicate their
surface for no gain. Both `seed.php` and `verify.php` check the term still
carries posts — an empty archive 404s and the V22 test would pass vacuously.

Registered in core-structures' consumer table so a `version` bump pings us.
