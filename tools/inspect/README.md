# tools/inspect

Microscopes, not tests. Nothing here asserts, pins an expectation, or exits
non-zero — these answer "what is this plugin doing on this page of this site",
which is the question you have in front of a real install rather than in CI.

The other two directories are for checking:

- `tools/fixtures/` — this plugin against **seeded** content, with assertions.
- `tools/probes/` — the **upstream** GB Pro / GP Premium surface, with a canary.

## `state-map.php`

Bootstraps one real request and prints three things for it: the Detector's
resolved state map, every registered rule evaluated through GB Pro's own
`Registry::evaluate()`, and the body classes this plugin emits. The rule
verdicts are the verdicts an author's conditioned block would get, not a
reimplementation of them.

```bash
bin/wp.sh <site> eval-file /plugins/bws-generate-layout-conditions/tools/inspect/state-map.php id 1234
bin/wp.sh <site> eval-file .../state-map.php page 74082
bin/wp.sh <site> eval-file .../state-map.php cat 75
bin/wp.sh <site> eval-file .../state-map.php term site-section 1378
bin/wp.sh <site> eval-file .../state-map.php home
```

`id` resolves the post type for you.

### One page per process

Loop in the shell, never in PHP:

```bash
for a in "post 78063" "page 74082" "cat 75"; do
    bin/wp.sh hargrave eval-file .../state-map.php $a
done
```

Two reasons, and only the first is defended against in the file. The Detector
memoizes per request (V5) — handled by `reset_cache()`. But **hook state is
process-global and elements mutate it**: a Layout Element that removes GP's
featured-image callbacks does so for the rest of the process, so a second page
inspected in the same process inherits the first page's hooks.
`featured_image_slot_active` reads hook state and would report the previous
page's answer with nothing to indicate it. `verify.php` carries the same
constraint and for the same reason.

### What it cannot tell you

Every rule reports **configuration, never render** (V7). "Slot active" means GP
has its featured-image callbacks attached — not that an image appears. The sharp
case is a Content Template, which removes the *call site* rather than the
callback (V34 part 5c, GitHub #7): this tool will report the slot active on a
page showing no image at all.

So when these verdicts disagree with the page in front of you, that gap is the
first thing to check, and only the response body settles it. For claims about
what a page emits, use `tools/fixtures/layout-states/render-surface.sh` or fetch
the page — see the test-surface table in `CONTEXT.md` for which surface can see
what.

### Running against a real clone

Four things cost time on the issue #6 dry-run and none is discoverable from the
script:

- **Swap the plugin first.** A clone runs its *installed* copy, not the bind
  mount — `bin/dev-plugin.sh --site <site> bws-generate-layout-conditions --dev`.
  Without it the first run fails on `featured_image_slot_active is not
  registered`, which is the missing swap, not a bug. Revert with `--live`, which
  restores the pre-swap DB snapshot and takes any wiring you added with it.
- **`shortpixel-adaptive-images` breaks images on `.test` origins.** It ships
  active on at least one clone and rewrites every `src` to an inline base64 SVG,
  deferring the real file to a CDN that cannot reach the origin, so every image
  on every page is a placeholder. Deactivate it before a visual pass. With it
  active the placeholders keep the real `width`/`height`, so boxes and reflow
  stay honest and only "does the photo look right" is unavailable.
- **A realistic configuration is an over-determined one.** On the clone, a
  Content Template and a Layout Element both pointed the same way, so five green
  rows proved nothing about which one the rule was reading — V34 part 5c was
  found only when the Layout Element was deliberately trashed. Deployed sites are
  chosen for realism, and realistic configurations are the ones most likely to
  have two mechanisms agreeing. Remove one and re-read.
- **The interesting visual state is the hidden one.** In production no block
  carries these conditions, so the layout you are looking at is the site's
  existing layout and it renders whether the rule works or not. What a condition
  newly does is *remove* a block, so the check that carries information is the
  gap left behind, not the page as it stands.

### Where it came from

Written during the issue #6 clone dry-run (T21), where the acceptance criteria
required the resolved state map to be read for every page checked "so a visual
pass cannot mask a rule that is right by accident". It was the one thing no
existing tool did: `verify.php` reads seeded fixtures only, and the probes cover
upstream API shape.
