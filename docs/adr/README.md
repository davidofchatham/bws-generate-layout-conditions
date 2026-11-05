# Architecture decision records

Two ADRs survive here. Every other decision lives with the thing it justifies. An old `ADR-NNNN` citation anywhere in the tree resolves through the map below.

## Where a decision lives

| Decision about | Home |
|---|---|
| A term, and why it beat the names that lost | the entry in `CONTEXT.md`, as a `Rejected:` line |
| An invariant, and why it is the rule | the V-id in `docs/architecture.md` that owns it, as a **Rejected** list |
| A per-class enforced invariant | PHPDoc on the enforcing class |
| A build or integration recipe | a comment at the config or call it explains |
| A proposal not yet accepted | a row in `docs/future-work.md` |
| A process decision | `AGENTS.md` and `docs/agents/`; `git log` on them is the record |
| A removal or a behavior change | the changelog in `readme.txt` |

The one thing the ADR format buys is the rejected alternative: what stops an agent re-proposing it next week. That payload belongs where a reader looking up the rule meets it.

## The bar

An ADR is the exception and argues for itself. All six must hold.

1. **Hard to reverse.** Changing your mind costs real work: a storage shape, a wire format, a taxonomy slug. Not a naming or layout preference.
2. **Surprising without context.** A reader asks "why this way?", not "what is this called?"
3. **A real trade-off.** Genuine alternatives existed, and you can name the one you rejected and why it lost.
4. **Not same-session.** The decision has survived at least one attempt to reopen it, by you, by an agent or by a later ticket.
5. **Shipped.** No `proposed` ADRs. Unbuilt intent is a row in `docs/future-work.md`.
6. **Never vocabulary.** A naming decision is a glossary entry with a `Rejected:` line.

Process decisions never qualify. Clearing the bar makes an ADR eligible to stay, not required to. A file stays only when no single anchor can hold the decision, for example when it spans several anchors. Do not mint one during ordinary work.

## Redirect map

| ADR | Status | Home | Reason |
|---|---|---|---|
| 0001 Hybrid disable-state detection | retired | V1, V2 | internal code; a later signal moved off its snapshot, so the signal map is the live contract |
| 0002 Post-meta reads use the queried object | retired | V3 | one line of code; never reopened |
| 0003 Dependency gating | retired | V13 | a header line; no evidence it was reopened |
| 0004 Body-class and condition naming | retired | V7, V8, V9, V27, the Naming rationale entry in `CONTEXT.md` | a naming decision (rule 6); V27 already owns the frozen slug |
| [0005 Content title reports the page-title role](0005-content-title-reports-the-page-title-role.md) | stays | here | changes what a stored condition means, and spans V21, V30, V31 and the signal map |
| [0006 Featured image reports post-level intent](0006-featured-image-reports-post-level-intent.md) | stays | here | changes what a stored condition means, and spans V7, V21, V33 and V34 |

0005 and 0006 cleared all six rules. They stay because the decision spans several anchors, so no single Rejected list can carry it. Retired text is in git history before the commit that removed it.
