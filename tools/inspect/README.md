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

### Where it came from

Written during the issue #6 clone dry-run (T21), where the acceptance criteria
required the resolved state map to be read for every page checked "so a visual
pass cannot mask a rule that is right by accident". It was the one thing no
existing tool did: `verify.php` reads seeded fixtures only, and the probes cover
upstream API shape.
