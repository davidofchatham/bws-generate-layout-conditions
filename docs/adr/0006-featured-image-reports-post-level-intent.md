# Featured Image Active reports post-level intent

## Context

`featured_image_active` shipped in v1 reading one hook/callback pair on singular — `has_action( 'generate_after_entry_header', 'generate_blog_single_featured_image' )` — and gained a non-singular config-replay branch on the Layout Element key `_generate_disable_featured_image` in 0.2.0 (V22/T8). Surveys against GP 3.6.1 + GP Premium 2.5.6, and measurements on clones of both deployed sites, show that both halves answer a different question than the rule's name implies.

**The relocation problem, the same shape as the content title's.** A Content Template or Page Hero draws the featured image itself — usually through a dynamic tag with a fallback image, so something renders even on a thumbnail-less post. GeneratePress will also draw its native copy, so the native one must be suppressed or the page shows the image twice. The only per-page controls for that are the Page Hero's "Disable featured image" toggle, a Layout Element's featured-image setting, or the Customizer. All three read as "disabled". Every block conditioned on Featured Image Active then stops rendering — including the block that was drawing the image. The author moved the image; the plugin reports it absent, and the failure is silent.

**The hook is narrower than the signal it stands for, independent of relocation (V33).** GP registers that callback on **one of three hooks** selected by a Customizer image-position setting (`blog/functions/images.php:169-181`), and neither shipped default is the position v1 probes: `defaults.php:28,35` ship `single_post_image_position => 'inside-content'` and `page_post_image_position => 'above-content'`. So on a stock install the rule reports "disabled" on every singular page while the image renders. Measured live on the `hargrave` clone 2026-08-05, and reproduced in the render harness here — before this change, `gp-no-featured-image` appeared on the testbed **baseline**, a page with nothing configured. The same read also goes false whenever GP Premium's Blog module is deactivated, since the callback it looks for is defined by that module and by nothing in the theme.

**The non-singular replay reads a contaminated key where nobody can see it.** The 0.2.0 branch replays the Layout Element key on archives. It is the same key the relocation problem is about, evaluated on the page type where an author is least likely to notice a block failing to render.

## Decision

**Featured Image Active answers one question, the same way on every page type: has the editor switched the featured image off for this post?**

**1. The per-post Disable Elements metabox is the only source.** `_generate-disable-post-image`, read through the existing `post_metabox_disables()` helper. Nothing else is consulted.

**2. No hook is read, on any page type.** Not the probed pair, not the other two positions. The hook read is removed rather than widened — ORing the three positions would close V33's first direction while leaving the relocation problem and the Blog-module dependency untouched, and it would keep a render-derived signal behind a config-shaped name.

**3. The Layout Element featured-image key is not read either — on any page type.** This is where the signal **diverges** from the content title, which does replay its element key (ADR-0005), and the divergence is deliberate and evidence-backed rather than habitual:

- On the deployed sites, every Layout Element carrying the image key pairs with a Page Hero or Content Template that draws the image. Reading it reports "disabled" on pages that visibly show one.
- The asymmetry is structural. A Content Template replaces the whole template part and so swallows the native title without any toggle, while the image is drawn from hooks *outside* that part and must be suppressed explicitly. And the Page Hero's own image toggle is gated to `is_singular()` (`class-block.php:317`), so a Layout Element is the **only** per-page way to suppress the image on an archive. The key is contaminated with relocation intent by construction, in a way the title's element key is not.

**4. Off singular the rule is a constant.** The metabox layer cannot apply there (ADR-0002), so the signal short-circuits before reading anything. Not an archive special case — uniformly correct for archives, search, 404 and the blog posts page. This **removes** the non-singular replay shipped in 0.2.0 (V22, reversed).

**5. The label gains a qualifier: "Featured Image Active (post setting)".** The persisted rule slug is frozen (V27) and unchanged; labels are not persisted, so the qualifier is free. It earns its place because the rule now sits beside a sibling reporting GeneratePress' own image slot, which it must be told apart from.

`gp-no-featured-image` keeps its name and follows the same meaning. No new rule here, no new class, no migration.

**Nothing consults `has_post_thumbnail()`** (V7). The fallback-image pattern is the concrete reason: on a thumbnail-less post the author's template still renders an image, so a render-aware rule would report "nothing here" while something is plainly on screen.

## Alternatives rejected

**Keep the hook read but OR the three Customizer positions.** The cheap interim recorded in the ROADMAP. Rejected: it fixes one of V33's two directions and none of the relocation problem, and it leaves the last known-wrong hook-state read in place behind a name that promises configuration.

**Replay the Layout Element key on singular as well, mirroring the content title.** The symmetric-looking shape, and the one a future reader is most likely to re-add as an obvious omission. Rejected on the evidence above: on real sites that key is how a relocation is performed, so replaying it reproduces the exact failure this change exists to remove — silently, and on the page type where it matters most.

**Keep the non-singular replay and change only the singular branch.** Rejected because it would leave the rule meaning two different things by page type — author intent on singular, element configuration off it — which is the inconsistency ADR-0005 removed from the sibling signal. The replay's only source is the contaminated key regardless.

**Read the Blog module's `$settings[$location.'_post_image']` on/off flag as a second genuine-disable source.** This is the one real cost of the change, and it was weighed rather than waved away: on `hargrave` the Customizer stores `single_post_image => false`, so hook-state currently answers *correctly* on single posts there and this change makes the rule report active while nothing renders. Rejected for this rule because it is a **theme-mod** layer with no config-replay equivalent, undetected for primary nav since v1 and for secondary nav since T17 (V24) — three signals, one missing layer, worth closing once for all three if ever, not per-signal. It is also the wrong home: "is GP drawing an image?" is a question about the theme's slot, not about the author's post setting, and it belongs to the sibling rule.

## Consequences

- **V33 closes and V21 closes for the featured image.** The last hook-state signal with a known-wrong direction is gone. The remaining hook reads are primary nav and top bar, neither of which has a known false direction.
- **V22 is reversed, not merely superseded.** T8's premise — that GP's Layout Element removes the image on archives unguarded — is still true; what changed is that this rule no longer treats that removal as a disable. The reasoning is retained in the invariant rather than deleted, because the fact still bears on any future rule that reads the theme's slot.
- **Behaviour change to disclose, in two directions.** Blocks conditioned on this rule inside a Page Hero or Content Template now render, which is the fix. And the archive behaviour shipped in 0.2.0 is removed: a Layout Element covering an archive no longer makes the rule report disabled there, and `gp-no-featured-image` stops appearing on archives. Both are changelog items; no CSS on either deployed site keys on that class.
- **Accepted residue: the Customizer layer is now undetected for this rule.** A site that switches featured images off globally, or runs a Page Header with content, reports the image active. The workaround is the sibling slot rule, which reads exactly that. Consistent with every other signal (V24), and traded against a read that was already wrong out of the box.
- **Accepted residue: a post whose template draws no image still reports active** when the toggle is off. Config, not render (V7) — the author chose it by leaving the image out.
- **The rule now fails toward rendering rather than toward hiding.** A mistake shows up on screen instead of vanishing without trace — the same direction ADR-0005 chose, for the same reason.
- **The per-post toggle became a genuine disable independent of module state only just before this change.** B8 fixed the suppression half to remove all five callbacks, so `_generate-disable-post-image` now hides the image on both render paths. Without that, reading the metabox as the single source of truth would have been reading a toggle that did nothing on Blog-module sites. Both ship in one release.
