# Architecture decision records

There is no `docs/adr/` to read. Every decision lives with the thing it justifies, and an old `ADR-NNNN` citation anywhere in the tree resolves through the map below. Do not mint a new ADR during ordinary work. The practice, and the reasons behind it, are in the shared reference `practices/adr.md`.

## Where a decision lives

| Decision about | Home |
|---|---|
| A term, and why it beat the names that lost | the entry in `CONTEXT.md`, as a `Rejected:` line |
| An invariant, and why it is the rule | the V-id in `docs/architecture.md` that owns it, as a **Rejected** list |
| A per-class enforced invariant | PHPDoc on the enforcing class |
| A pattern with no rejected alternative | PHPDoc on the code that enforces it, and a pointer from the invariant |
| A build or integration recipe | a comment at the config or call it explains |
| A proposal not yet accepted | a row in `docs/future-work.md` |
| A decision accepted on work not yet built | its `docs/future-work.md` row, with its **Rejected** list; at ship it moves to an invariant or glossary entry |
| An accepted debt | its existing `docs/future-work.md` item |
| A process decision | `AGENTS.md` and `docs/agents/`; `git log` on them is the record |
| A removal or a behavior change | the changelog in `readme.txt` |

The one thing the ADR format buys is the rejected alternative: what stops an agent re-proposing it next week. That payload belongs where a reader looking up the rule meets it. Cite the invariant, not an ADR number.

## Redirect map

| ADR | Home | Reason |
|---|---|---|
| 0001 Hybrid disable-state detection | V1, V2 | closed set: internal code; a later signal moved off its snapshot, so the signal map is the live contract |
| 0002 Post-meta reads use the queried object | V3 | closed set: one line of code; never reopened |
| 0003 Dependency gating | V13 | closed set: a header line; no evidence it was reopened |
| 0004 Body-class and condition naming | V7, V8, V9, V27, the Naming rationale entry in `CONTEXT.md` | vocabulary: a naming decision; V27 already owns the frozen slug |
| 0005 Content title reports the page-title role | V21 (decision and **Rejected** list), V29, V30, V31 | closed set: one rule's meaning |
| 0006 Featured image reports post-level intent | V21 (decision and **Rejected** list), V22, V33 | closed set: one rule's meaning |

Retired text is in git history before the commit that removed it.
