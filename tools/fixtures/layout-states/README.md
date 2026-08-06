# layout-states blueprint

GP **theme-structure** fixtures: `gp_elements` (Block / Layout / Page Hero),
per-post Disable-Elements metabox meta, sidebar layouts, and the Menu Plus
mobile header — plus, since v8, the GB Pro `gblocks_condition` posts and the
blocks that reference them, which is where the *authoring workflow* becomes
observable rather than only the plugin's own output. Composes on
[`core-structures`](../../../../bws-gb-dynamic-tags-extensions/tools/fixtures/core-structures/)
(pins v4+) and redefines nothing it owns.

## Why a separate blueprint

core-structures owns **content** — CPTs, ACF groups, field values. This
blueprint owns **theme structure**. Different axis, no overlap, so composition
is by reference: it reuses core's posts and taxonomy and adds only `gp_elements`
and GP meta on its own `ls-`-prefixed pages.

That is also why there is **no `schema.php`** here, breaking the usual 5-file
shape: every post type and meta key this blueprint writes is registered by GP
Premium or (since v8) by GB Pro. There is nothing of our own to keep alive across
a snapshot restore, so there is no mu-plugin stub either.

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
| `ls-el-layout-featured-archive` | Since ADR-0006 this is a **regression guard**, not a detection fixture: the featured-image rule must report the image ACTIVE on `/department/sales/` despite this element, because the non-singular replay (V22/T8) is reversed and the element's key is a relocation mechanism. Asserted by `render-surface.sh` §8 — an inverted assertion; the same fixture used to prove the opposite. **Inert from v1 to v3** — its condition object was the term slug where GP compares the term ID, so it never applied to any request (B6). Fixed at v4; `verify.php` §6 proves it applies, which is what keeps the §8 absence check honest. |
| `ls-el-layout-excluded` | V4 — replay must pass all **three** condition metas to `show_data()`. Verified discriminating: display-only `true`, all-three `false`. A two-arg replay would report this page disabled. |
| `ls-el-page-hero` | V21 — the Page Hero **Block Element** relocation (Hero embeds title/image, removing the hooks the Detector used to read). Since ADR-0005 and ADR-0006 it is a **regression guard for both halves**: title and image must both report active here, and `render-surface.sh` §8 asserts the image half on rendered output. **Inert from v1 to v6 (B9)** — it carried no `_generate_hook`, and `page-hero` is not one of the types GP resolves a hook for, so the loader returned before registering the element at all. It rendered nothing and removed nothing; the ambiguity it was written to characterize never occurred. Fixed at v7 with the value GP's own editor writes. Nothing caught it for six blueprint versions because no assertion read this page — existence, publish status and meta shape all passed. The other five content-title writers are **deliberately unread**, not uncovered — both ADRs stopped consulting the hook rather than detecting the writers, so the two toggle-less relocation paths (legacy Page Hero, the deprecated Page Header module) need no fixture. They would be needed only for a future Meaning-B rule, and the survey in `architecture.md` is the spec for that. |
| `ls-el-layout-secondary-nav` | V32/T17 — the two claims under the secondary-nav config-replay that the fake structurally cannot check: that `_generate_disable_secondary_navigation` is the key GP's Layout Element actually writes (the metabox layer's key is `_generate-disable-secondary-nav` — different words, and a wrong one here is silently inert, B6's shape), and that one **unguarded** element reaches a singular page *and* an archive. Carries both display conditions for that reason. Added v5. |
| `ls-el-layout-title-archive` | V31 — the one claim the PHPUnit fake structurally cannot test: that GP leaves the archive **heading** standing when a Layout Element disables the content title. The fake can encode that belief; only a rendered archive can falsify it. Targets `/department/sales/`, the same archive as the featured-image fixture. Asserted by `render-surface.sh` §6, never from wp-cli. |
| `ls-page-metabox-*` | V24/V25 — the CSS-neutralize regression surface. Featured Image and Secondary Nav are CSS-only (full surface); Primary Nav is partial via the `#mobile-header` wrapper. The featured-image half also pins B8 since v6: with the Blog module required ON, the image these pages render is the blog path, so a suppression that removes only the theme path fails here instead of passing. |
| `ls-page-sidebar-*` | V26 — all four sidebar enum values, including `both-sidebars`, the only case that catches a regression to exclusive enum-matching. |
| `ls-el-layout-featured-kill` / `ls-page-featured-kill` | V34 — the case the slot rule exists for, and the one nothing covered until v8. A Layout Element switches the featured image off on a **singular** page with **nothing drawing one in its place**: no Page Hero, no Content Template, no second element. Every other route to "slot not active" pairs the removal with something that draws an image, and no deployed site in the survey exhibited this one, so the rule's reason for existing was untested. GP removes the same five callbacks here as the Hero does (`class-layout.php:316-320`), so it exercises the both-paths read (V34 part 2) rather than one branch. The page carries a **thumbnail** — without one, "no page-header-image in the response" would be true of a page the element never touched. Asserted by `render-surface.sh` §9; `verify.php` §8 proves the element applies to that page and *not* to the baseline. |
| `ls-cond-image-active` / `ls-cond-slot-active` | The two rules, as GB Pro conditions (v8). `gblocks_condition` posts holding `_gb_conditions`, referenced from block attributes by ID. First fixture surface in this blueprint belonging to GB Pro rather than GP Premium, and it carries a **third** silent-inertness mode on top of B6's and B9's: a condition post GB Pro finds but this plugin's registry cannot answer (unregistered `type`, unknown `rule`) evaluates to **false**, so the fixture inverts into "always hidden" rather than erroring. `verify.php` §8 checks both slugs against the live registry. |
| `ls-el-block-archive-markers` | The archive row of the combination table (v8). An archive has no `post_content`, so the conditioned markers get there the way an author would put them there — a hook Block Element on `generate_before_main_content`. Scoped to the archive, never site-wide: a second copy of each marker on the singular pages would make "the marker is present" stop saying which surface produced it. Same `_generate_hook` requirement as the Page Hero (B9) and checked by the same assertion. |

## The featured-image combination table (v8, issue #5)

Two rules, about different subjects, and the whole reason the second exists
(V34) is that they come apart. Every reachable combination now has a fixture,
and every fixture is read by at least one assertion that can go red because of
it:

| Fixture | Post setting | Theme slot | Carries the markers via |
|---|---|---|---|
| `ls-page-baseline` | active | active | page content |
| `ls-page-metabox-featured` | **disabled** | not active | page content |
| `ls-page-hero` | active | not active (relocation) | page content |
| `ls-page-featured-kill` | active | not active (kill switch) | page content |
| `/department/sales/` | active | not active (off singular) | `ls-el-block-archive-markers` |

The fourth combination — **post setting disabled, slot active** — is unreachable
by construction: this plugin removes the five callbacks the slot rule reads at
`wp:60` whenever the toggle is set, and the Detector first resolves later. It is
**asserted rather than omitted**. `ls-page-metabox-featured` is the only page
where the toggle is set, so it is the only place that combination could appear,
and the slot marker's absence there is what says it does not. A missing row in a
table reads as an oversight; a named assertion does not.

## The four test files, and the difference

`verify.php` asserts the **fixtures** landed and discriminate — so a suite
failure means "the Detector regressed", not "the fixture seeded nothing". 67
assertions. Its §7 (added v6) pins the one thing this blueprint had left as an
inherited environment variable: the GP Premium **Blog** module, which decides
*which* of the two featured-image render paths is live. Testbed had it off, so
every featured-image assertion here tested the theme path only and a suppression
covering half the surface passed green (B8). Its §6 (added v4) is the one that closes B6: it bootstraps a real
**archive** query and asserts all four archive elements actually apply there
(the third added v5 — and it is the only one carrying *two* display conditions,
so it is also what would catch `show_data()` regressing the display list from OR
to AND; the fourth is v8's marker element).
Its §2 gained one more shape check at v7: a Page Hero element must carry
`_generate_hook`, without which GP returns before registering it (B9) — at v8
that check covers the archive marker element too, since `hook` is in the same
set of block types the loader's switch does not name.
Existence, publish status and meta shape were never the weak link — a fixture
can pass all three and still match no request, or never be looked at.

Its **§8** (added v8) covers the GB Pro surface, which has failure modes the GP
ones do not. Five things, in the order they can break:

* **The feature flag.** GB Pro gates the whole `render_block` filter on
  `enable_block_conditions`. Off, the conditions are stored, readable, and never
  consulted — so every conditioned block renders and the combination table goes
  green without evaluating a rule. Pinned, in the same spirit as the Blog module.
* **The stored shape.** `_gb_conditions` is registered meta carrying GB Pro's own
  `sanitize_callback`, so the check is a round-trip: re-running the sanitizer over
  the stored value must be a no-op. A difference means the fixture holds something
  the REST path would have rewritten, i.e. a shape the admin UI cannot produce.
* **The slugs, against the live registry.** `type` must be registered with GB Pro
  and `rule` must be a key of that type's `get_rules()`. Both are plain strings
  the consumer looks up and **silently no-ops on**: an unregistered type makes
  `evaluate_single_condition()` return false, an unknown rule makes
  `evaluate()` fall through to `$match = false`. Neither errors — the fixture
  inverts into "always hidden" and every absence assertion passes for the wrong
  reason. B6's shape on a new consumer.
* **The block attributes.** Every fixture in the combination table is parsed and
  its `gbBlockCondition` attributes compared against the seeded condition post
  IDs. The failure this catches is an unresolved `{{condition:…}}` placeholder:
  `absint()` makes it `0`, GB Pro reads `0` as "no condition set" and returns the
  block untouched, so the marker renders everywhere.
* **That they answer, and answer differently.** Everything above is still
  presence and shape, and the standing rule here is that a fixture is only real
  once something asserts it CHANGED an outcome. So both conditions are evaluated
  under a bootstrapped query on `ls-page-featured-kill`, where the two rules
  genuinely disagree: the post setting is untouched (true) while GP's five
  callbacks are gone (false). **One request yields both verdicts**, which is what
  makes this safe from the CLI — neither an always-true nor an always-false
  evaluator can produce that pair, so no second page and therefore no ordering
  hazard is needed.

§8 also proves the kill-switch element applies to `ls-page-featured-kill` **and
not** to `ls-page-baseline` — one arm is not enough, because an always-true
`show_data()` would disable the image on the control page that every absence
assertion in the render harness is read against. It runs **last** in the file for
the same reason `poisoned-signal.php` runs last among the suites: bootstrapping
that page fires `wp`, GP applies the element, and its five `remove_action()`
calls persist process-globally. Nothing below it may read featured-image hook
state.

Note that §8 is not where all 25 of v8's new `verify.php` assertions live — the
existence loops in §1, the `_generate_hook` check in §2 and the fourth archive
element in §6 carry seven of them, because the new fixtures belong to the same
categories those sections already cover.

**Mutation-checked ×6, one per fixture the ticket added or newly reads.**
Dropping the condition from a seeded marker block fails 4 render assertions and
5 verify ones by name; pointing the kill switch at the per-post metabox key fails
2 and 1; removing the Page Hero's image toggle fails the hero row's two; removing
the per-post toggle fails 4 (two of them in §1/§2, which is the point — the rows
are not independent); stripping the archive marker element's `_generate_hook`,
and dropping the baseline's marker blocks, each **hard-abort** the render suite.
The `_generate_hook` one is worth reading twice: `verify.php` §6 still reports
that element "applies on /department/sales/", because `show_data()` knows nothing
about whether GP ever registered the element. That is B9's exact shape, and it is
why the render-side liveness control exists rather than being redundant with §6.

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

`poisoned-signal.php` asserts the **premise** the other two take for granted:
that the hook signal for header/footer really is poisoned, and that config-replay
really does route around it. It is the only file that touches both the seam and
the Detector, because the point it proves spans them. 20 assertions:

| Section | Pins |
|---|---|
| preconditions | Fixtures present, Elements module on, and — load-bearing — that the two Block Elements carry **no** disable meta. If someone adds any, the poisoning becomes indistinguishable from a real disable and the file would pass while proving nothing. |
| unpoisoned control | `generate_construct_header` / `_footer` are attached on `ls-page-baseline`, and the Detector reports both active. Without this arm, a global bug that strips the constructs would make the next section pass for the wrong reason. |
| the poison | Visiting `ls-page-poisoned` instantiates the Block Elements, whose unconditional `remove_action()` (`class-block.php:169-175`) strips both constructs. Reproduces V2's observation as an executable fact. |
| hook-state vs config-replay | States the false positive as an assertion — hook-state *would* report DISABLED, the Detector reports ACTIVE — then proves config-replay still discriminates on `ls-page-layout-disabled`, so "always active" can't fake a pass. |

**Order is load-bearing.** `remove_action()` is process-global and this runs in
one process, so the control arm must be asserted before anything visits
`ls-page-poisoned`. The file re-checks the hooks immediately before poisoning
them, so a reordering fails loudly instead of turning the control into a
tautology. The header explains this at length — read it before editing.

Mutation-checked: reverting `is_header_disabled()` to read hook state fails
exactly one assertion, with a message naming the cause.

`render-surface.sh` is the only one that is **not** wp-cli — it asserts on real
HTTP responses, because several invariants here are structurally invisible from
the CLI. 67 assertions:

| Section | Pins |
|---|---|
| preconditions | The response is complete HTML, and the baseline renders a featured image, both nav wrappers, and `#mobile-header`. Hard-aborts otherwise: every assertion below is presence/absence against a body, so an empty or truncated response would make the absence checks pass vacuously. Since v6 it also pins **which featured-image path is live**, by position: the blog path renders inside `#content`, the theme path never does. Both wrappers carry `page-header-image`, so position is the only render-level discriminator — and without it §2 silently tests whichever path the site happens to have (B8). |
| V12 neutralize | GP's three literal `display:none` rule strings are ABSENT on pages whose toggle is on. Matching the exact upstream strings, not a generic `display:none` — theme and block CSS emit unrelated ones on every page including the control. |
| V24 | Both directions: CSS-only toggles (featured image, secondary nav) leave markup fully present; the PHP-removed one (content title) does not. Asserting only one direction would let "PHP-removed everything" or "removed nothing" pass half the suite. |
| V25 | On the primary-nav page, `#site-navigation` is gone (PHP path) while `#mobile-header` survives — the documented partial-CSS regression, observed for the first time at blueprint v2. |
| control | The baseline renders every marker the sections above assert the absence of. Without it, a change that removed these elements everywhere would pass the whole suite. |
| V32 / T17 | The Layout Element secondary-nav layer, on **both** page types from one element. Asymmetric by necessity: on the singular page both halves are asserted (markup gone, body class emitted) because the baseline is a control for the marker rendering at all; on the archive only the body class is, since the element *is* what disables it and no nav-intact archive exists here — and the body class is the discriminating half anyway, being the Detector's own output rather than something a nav-less archive would satisfy for free. Two controls: the baseline must not carry the class, and the metabox page must still carry it (T17 added a layer, it did not replace one). |
| ADR-0006 | The featured-image rule's inversion pass, and the section whose mutation check confirmed V33 on a second site. Three of its four assertions used to read the other way: `gp-no-featured-image` was emitted on the Page Hero page (a relocation read as a disable), on the archive (the reversed V22 replay), and — because §0 pins the image position to `inside-content` while the old probe watched `below-title` — on the **baseline**, a page with nothing configured. The metabox page is the one remaining positive, and it is the only control that can distinguish "reports the post setting" from "stopped reporting anything". The hero page's liveness precondition hard-aborts, which is how B9 was found. |
| issue #5 (§9) | **The first section that reads the chain an author uses**, rather than markup this plugin or GP emits. Every fixture in the combination table below carries the same three blocks verbatim — one unconditioned, one on `featured_image_active`, one on `featured_image_slot_active` — so a difference between two rows is attributable to the *rule* and nothing else. On the four singular rows they live in the page's own content; on the archive they come from a hook Block Element, because an archive has none. The unconditioned marker is a **hard-abort** precondition on all five: a row whose content never reached the response satisfies every absence check for a reason unrelated to any rule (B9's lesson applied before the fact). Two assertions are not about the rules at all but about GP: no `page-header-image` wrapper renders on the hero page or the kill-switch page, which is what turns "the slot rule says no slot" from a claim about the Detector into an observation about the theme. |
| V31 / ADR-0005 | The only assertions on an **archive**, and the only place the page-title-vs-item-title split is observable: with a Layout Element content-title disable on `/department/sales/`, the `<h1 class="page-title">` heading is PRESENT, the loop-card `entry-title`s are GONE, and `gp-no-content-title` is absent. The controls are what keep it honest — the loop-card absence proves the element is actually live (otherwise the body-class check passes for the wrong reason), and `gp-no-content-title` must still appear on the metabox-disabled page, which is also the render-level proof V29 is closed. |

**Two caches will lie to you**, and both produce false GREENS rather than
noisy failures:

* **LiteSpeed** serves `x-litespeed-cache: hit` freely. Every request here
  carries a nonce query arg.
* **opcache**, the nastier one: `opcache.revalidate_freq=120` means PHP
  re-checks mtimes at most every 2 minutes, so a page fetched shortly after an
  edit is rendered by the *previous* bytecode. It is asymmetric —
  `opcache.enable_cli=Off`, so wp-cli reads fresh source and a CLI check can
  contradict a render, with the CLI correct. A mutation test of this harness
  passed 18/18 against stale bytecode while the plugin was demonstrably broken.
  The script now recycles lsphp before asserting; do not remove that step.

Mutation-checked: reverting the neutralize to a hook-time load fails exactly the
three V12 assertions, each naming the cause.

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

**GB Pro must be active, with block conditions enabled** (v8). `seed.php` checks
both — the `gblocks_condition` post type must exist, and
`generateblocks_pro_block_conditions_enabled()` must be true. The second is the
one worth naming: with the feature off, GB Pro never registers the `render_block`
filter, so every conditioned marker block renders unconditionally. The conditions
are stored, readable and never consulted — the self-verifying inertness this
blueprint keeps rediscovering, this time on a plugin-level switch rather than on
a fixture.

## Seeding

```bash
bin/wp.sh <site> eval-file /plugins/bws-generate-layout-conditions/tools/fixtures/layout-states/seed.php
bin/wp.sh <site> eval-file /plugins/bws-generate-layout-conditions/tools/fixtures/layout-states/verify.php
bin/wp.sh <site> eval-file /plugins/bws-generate-layout-conditions/tools/fixtures/layout-states/seam-fidelity.php
bin/wp.sh <site> eval-file /plugins/bws-generate-layout-conditions/tools/fixtures/layout-states/poisoned-signal.php
```

Plus the render harness, which is bash rather than eval-file and takes the site
by name:

```bash
tools/fixtures/layout-states/render-surface.sh --site <site>
```

`poisoned-signal.php` mutates process-global hook state by design, so it runs
**last** among the eval-file suites and in its own process. Do not fold its
assertions into another file.

**Blueprint v8 is required** for the render harness. Earlier versions cannot
support it, and each shortfall turns a specific assertion into a vacuous pass —
which is why the harness checks them before asserting anything, and why every one
of those checks hard-aborts rather than recording a FAIL. v1: no page
carried a featured image, no menu was assigned to either nav location, and
`generate_menu_plus_settings` was written with `set_theme_mod()` while GP Premium
reads it exclusively via `get_option()` (~20 call sites, zero `get_theme_mod`),
so the mobile header was never enabled. v2: no thumbnail on the two nav-toggle
pages, making T10's over-suppression checks vacuous. v3: no archive
content-title element, so §6 had nothing to observe. v4: no secondary-nav Layout
Element, so §7 had nothing to observe. v5: the GP Premium Blog module unpinned,
so the featured-image assertions ran against whichever of the two render paths
the site happened to have live (B8). v6: the Page Hero element carried no
`_generate_hook` and so was never loaded at all, which makes §8's relocation
assertion vacuous — it hard-aborts rather than passing (B9). v7: no conditioned
blocks, no condition posts and no kill-switch fixture, so §9 has nothing to read
at all — the unconditioned marker's absence hard-aborts on the first row it hits.

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

## Seeding into storage nothing reads

The v1→v2 repair was one bug with one shape, and it will recur: **a fixture
written to the wrong storage backend is invisible to the consumer and silent
about it.** `generate_menu_plus_settings` was seeded with `set_theme_mod()`; GP
Premium reads it only via `get_option()` (~20 call sites, zero `get_theme_mod`).
The write succeeded, the value was readable, and the setting had no effect —
so the mobile header stayed off and V25 could never be observed.

Nothing catches this by itself. The seed reports success, `verify.php` can
re-read exactly what it wrote, and the assertion that depends on it passes
*because the surface it checks renders nothing either way.*

**It recurred at v5, in a third form** (B7) — and this time the fixture was
correct while the *seeder* was not. `$upsert` reuses the post by `post_name` and
`$write_meta` only ever wrote the keys currently in the manifest, so a key that
was dropped or renamed stayed on the post forever. Changing a manifest key
therefore did not change the fixture: both rows were present and GP read the old
one. A deliberate wrong-key mutation passed **39/39**. The fix makes the write
authoritative — keys under an owned prefix that are absent from the manifest are
deleted first — and the same mutation now fails 3 assertions by name. Generalise
it further: **a fixture is only real once something asserts it CHANGED an
outcome, and only if the seeder can be shown to produce exactly what the
manifest says.** The second clause is new; v4's lesson covered the first.

**It recurred at v7, in a fourth form** (B9) — and this one is the purest, because
the fixture was never *read* rather than read wrongly. `ls-el-page-hero` carried
no `_generate_hook`, and GP resolves a block element's hook from that key for
every type its switch does not name — `page-hero` among them. With the key
absent the loader returns before it registers anything, including the `wp:100`
callback that performs the relocation. So the element rendered nothing and
removed nothing, from v1 through v6. What let it survive six versions is not
subtle and is worth stating flatly: **no assertion in any suite touched that
page.** Every check it passed was a check about the fixture — it exists, it is
published, its meta has the right shape — and none of those can notice a
consumer that discarded the whole thing. Fixed at v7 with the value GP's editor
itself writes; `verify.php` §2 now asserts the key and names the early return,
and `render-surface.sh` §8 hard-aborts unless the element's own content renders.
Third clause for the generalisation below: **a fixture nothing asserts against
is not covered, however thoroughly it is verified to exist.**

**It recurred at v4, in a second form** (B6): both archive elements stored their
display-condition `object` as the term **slug**, while GP resolves a taxonomy
archive to `taxonomy:{taxonomy}` with object = the term **ID**
(`class-conditions.php:225-231`) and compares non-strictly — and under PHP 8
`7 == 'sales'` is false. So the elements matched no request at all, from v1
through v3. Not a storage-backend mistake this time but the same shape: a value
written in a form the consumer never matches, silent about it. Both times the
fixture was readable, well-formed, and provably useless. The generalisation:
**a fixture is only real once something asserts it CHANGED an outcome** —
existence, meta shape and query-visibility checks all passed throughout.

When seeding anything that is not post meta, confirm which API the CONSUMER
reads before choosing how to write it:

```bash
grep -rn "get_option( 'the_key'\|get_theme_mod( 'the_key'" path/to/gp-premium/
```

Same class of trap as the meta-value shapes below: writing a value the admin UI
could never produce, or to a place the reader never looks, yields a fixture that
verifies itself and proves nothing about production.

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
- **GB Pro conditions** (`_gb_conditions`, v8) → nested
  `{ logic, groups[ { logic, conditions[ { type, rule, operator, value } ] } ] }`.
  Written with plain `update_post_meta`, deliberately: the key is **registered**
  meta carrying GB Pro's own `sanitize_callback`, so the CLI path produces the
  same stored value the REST path would, and `verify.php` §8 proves it by
  re-running that sanitizer over what landed. Referenced from a block as
  `gbBlockCondition` — the condition post's ID, as a **string** (the attribute is
  registered `type => 'string'` and the editor writes a string). GB Pro applies
  it on `render_block` for **every** block type, so a core paragraph exercises
  exactly the same evaluation path a GenerateBlocks block would.

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
