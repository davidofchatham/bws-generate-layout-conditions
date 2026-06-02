# Post-meta disable reads use the queried object, guarded by is_singular()

## Context

GeneratePress's per-post disable meta (`_generate-disable-*`) applies only on singular views — GP itself guards every read with `if ( ! is_singular() ) return;` and reads from the queried `global $post`. The Detector must mirror this, because two facts collide:

1. Off-singular (archive, search, home), there is no "current post" whose disable meta is meaningful — Layer 3 contributes nothing.
2. The Detector is shared by both consumers, and the condition consumer runs at `render_block` inside `do_blocks()`, where the loop context can make `get_the_ID()` return a *loop item* rather than the page's queried object. The original snippet used `get_the_ID()`, which is fine at `body_class` time but drifts to the wrong post when reused inside a Block Element's render.

A drifted read would apply one loop post's disable setting to the whole page — a silent correctness bug on archives.

## Decision

The post-meta branch of the Detector:

- Guards `is_singular()` before reading any `_generate-disable-*` meta, matching GP's own behaviour. Off-singular it contributes nothing; the Detector relies on hook-state and the header/footer Layout-Element replay instead.
- Reads meta from `get_queried_object_id()` (the query-level post), never `get_the_ID()` (the loop-level post), so the value is stable regardless of loop context inside `do_blocks()`.

## Consequences

- Fixes a latent loop-context bug inherited from the original `gp-layout-body-classes.php` snippet.
- Hook-state signals and the header/footer config-replay remain valid off-singular (Layout Elements target archives too), so off-singular detection is not lost — only the per-post layer is correctly absent.
