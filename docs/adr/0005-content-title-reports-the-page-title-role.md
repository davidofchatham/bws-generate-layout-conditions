# Content Title Active reports the page-title role, under Meaning A

## Context

`content_title_active` shipped in v1 reading one hook: `has_filter( 'generate_show_title', '__return_false' )`. Two separate surveys (GP 3.6.1 + GP Premium 2.5.6, full tables in `docs/architecture.md`) showed that hook answers a different question than the rule's name implies, in two independent ways.

**The relocation problem.** Six upstream writers add that filter. Three are genuine disables (theme core and Premium's Disable Elements module, both keyed on the same `_generate-disable-headline` post meta; and the Layout Element's `_generate_disable_content_title`). Three are **relocations** — a Page Hero Block Element, a legacy Page Hero, and the deprecated Page Header module each suppress the native title precisely *because they render one themselves*. Hook-state cannot tell "moved" from "removed", so a site author who builds a hero containing a title is forced into a trap: GP's only control for the resulting duplicate is the disable toggle, and using it makes every block conditioned on Content Title Active stop rendering. The title is on the page; the plugin reports it absent. The failure is silent — the blocks simply never appear.

This is structurally the same problem as V2/ADR-0001: a hook read as a config signal, poisoned by an element that mutates it for its own reasons.

**The wrong-role problem.** `generate_show_title` straddles two page-structure roles. On singular it gates the **page title** (`content-single.php:36`, `content-page.php:37`). On archives it gates the **item titles** inside loop cards (`content.php:35`, `content-link.php:35`) — one per card — while the archive's own heading is a different hook entirely (`generate_archive_title` → `<h1 class="page-title">`, `archive.php:34`). So on an archive the v1 rule reported whether the loop cards show their titles, which is not what an author reasoning about "the content title" is looking at. GP Premium's own help text for the Layout Element toggle ("the content title of the current post/taxonomy") actively encourages the misreading.

The two compound for the most common real layout — one sitewide Page Hero used on both singular pages and archives — which produced a different, and differently wrong, answer on each page type.

## Decision

**Content Title Active answers one question, the same way on every page type: has the author's configuration disabled the page title?**

Two things follow.

**1. Detection moves from hook-state to config-replay (Meaning A).** The signal joins header and footer in the hybrid scheme (ADR-0001). It is derived from the only two genuine-disable sources, through the two replay helpers the Detector already owns:

| Genuine disable | Meta key | Helper |
|---|---|---|
| Post metabox (theme core + Premium collapse to one key) | `_generate-disable-headline` | `post_metabox_disables()` |
| Layout Element | `_generate_disable_content_title` | `layout_element_disables()` |

The relocation writers are not detected, not compensated for, and **not read**. You stop consulting the poisoned hook rather than subtracting from it — which is why detecting element content, resolving the deprecated Page Header module's options, and pinning that module in the version canary all drop from scope.

**2. The rule reports the page-title role, so off singular it is a constant.** No writer targets that role off singular: the Layout Element toggle reaches only item titles, and the post metabox cannot apply to an archive. The signal short-circuits before consulting anything and reports not-disabled. This is not an archive special case — it is uniformly correct for archives, search, 404 and the blog posts page (V31).

Item titles inside loop cards are a different page-structure element and are out of remit. Per V6 this plugin models page-level state and `evaluate()` discards `$context['post_id']`, so it could only ever answer "are loop titles globally suppressed", never a per-item question.

Nothing is renamed. The condition slug is persisted in saved condition data and is frozen (V27); the label stays aligned with GeneratePress' own checkbox wording, because matching the control the author actually clicked is the discoverability win and the precise definition belongs in the glossary. `gp-no-content-title` keeps its name. No new rule, no new class, no migration.

## Alternatives rejected

**Meaning B — keep "renders in the native theme slot."** This is v1's shipped meaning, and it is precise and detectable. It was rejected on failure-mode asymmetry: A-only breaks Meaning-B consumers **visibly** (a duplicate title appears on screen, and the author fixes it), while B-only breaks Meaning-A consumers **invisibly** (blocks inside the hero silently never render, with nothing on the page to indicate why). Silent failure loses. The empirical gate was cleared against clones of both deployed sites: one carries Page Hero relocations only, the other Layout Element disables only, with zero overlap — so the flip fixes the first and leaves the second byte-identical, and no site depends on Meaning B.

**Two rules — keep `content_title_active` as B and add a second rule for A.** Rejected as the more expensive shape for a problem that turned out not to need it. It exists only to protect unknown Meaning-B consumers, and the deployment survey showed there are none. It costs two dropdown entries that are not self-explanatory without the docs, and the same split would likely double onto the featured image signal — four rules where two stood. It also does not address the wrong-role problem at all.

**A global admin toggle choosing the meaning site-wide** (the original ROADMAP framing). Rejected outright: one page load can carry a Layout Element disable (genuine) *and* a hero relocation (not) simultaneously, needing both meanings at once. A global switch cannot express that.

**Dispatching the archive branch onto `generate_archive_title` hook-state** (the earlier T15 plan, recorded in V30 before this ADR). Rejected because it re-opens the direction Meaning A exists to close. It would have required ANDing in `generate_has_default_loop()` to catch Loop Template Block Elements, and a Loop Template is structurally indistinguishable from a relocation without parsing block content — so an author who placed a title block in their own loop template would find it silently hidden by a condition guessing at their markup. It also made the rule asymmetric: Meaning A on singular, Meaning B on archives, inside one rule.

## Consequences

- **V29 closes as a side effect.** Reading `_generate-disable-headline` directly removes the dependency on GP Premium's redundant `__return_false` registration, which was the only way the theme's own named-callback handling was ever visible.
- **Behaviour change to disclose.** On archives `gp-no-content-title` previously reflected loop-card suppression and now reflects the page-title state — in practice, it stops appearing there. Cleared for both deployed sites (neither keys CSS on that class), but the class ships to any future install, so it is a changelog item.
- **Meaning B becomes unanswerable by any rule.** Recorded as an accepted gap: a block placed *outside* a hero on an archive, doing duplicate-avoidance or spacing compensation, has no signal. The relocation-writer survey is retained as the evidence for a future Meaning-B rule, should one ever be scoped.
- **One accepted residue,** unavoidable without parsing block content: a hero that disables the title and then does not render one reports the title as active. The author chose that by leaving the title out of the hero.
- **The rule now fails toward rendering rather than toward hiding.** A mistake shows up on screen instead of vanishing without trace.
- **The DTE `{{title}}` pairing still needs no extra rule,** but for a different reason than previously recorded — see the correction in `docs/architecture.md`. DTE's tag dispatches by page type; this rule does not, it is a constant off singular. The two still compose correctly, since a block carrying `{{title}}` inside a sitewide hero wants "active" on both page types.
