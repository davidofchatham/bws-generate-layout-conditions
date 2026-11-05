# Domain Docs

How the engineering skills should consume this repo's domain documentation when exploring the codebase.

## Before exploring, read these

- **`CONTEXT.md`** at the repo root: the glossary.
- **`docs/architecture.md`**: the architecture home, with the invariants (V ids), signal map and bug ledger.

If a listed file does not exist, **proceed silently**. Don't flag its absence or suggest creating it upfront.

## Who owns what

| Concern | Single owner |
|---|---|
| Vocabulary | `CONTEXT.md` |
| Invariants, signal map, bug ledger | `docs/architecture.md` |
| Per-class enforced invariants | PHPDoc on the enforcing class |
| Bugs | GitHub Issues |
| Non-bug work, and the ledger of what has landed | `docs/future-work.md` |
| Version history | the changelog in `readme.txt` |
| Finished specs | `docs/design-history/`, created at the first lift |
| Upstream verification baseline | `tools/probes/README.md` |
| What tracked files must not hold | `tools/guards/check-tracked-pii.sh` |

## Where a decision lives

A decision lives with the thing it justifies: on the invariant that owns it, or on the glossary entry for a term. Cite that anchor, never a spec coordinate.

## ADRs

`docs/adr/` holds two ADRs (0005 and 0006), which cleared the six-rule bar and stay because each spans several invariants. Read them when you touch the content-title or featured-image signals. Do not mint a new ADR during ordinary work. `docs/adr/README.md` owns the bar, the decision-homes table and the map from every older `ADR-NNNN` citation to its new home.

## Use the glossary's vocabulary

When your output names a domain concept (in an issue title, a refactor proposal, a hypothesis, a test name), use the term as defined in `CONTEXT.md`. Don't drift to synonyms the glossary explicitly avoids.

If the concept you need isn't in the glossary yet, that's a signal: either you're inventing language the project doesn't use (reconsider) or there's a real gap (note it for `/domain-modeling`).

## Flag conflicts

If your output contradicts an invariant or a glossary entry, say so explicitly rather than silently overriding it. Check the anchor's **Rejected** list or the entry's `Rejected:` line first: proposing a listed alternative needs an argument against the reason it lost, not a restatement.

> _Contradicts V9 (vocabulary diverges on purpose), but worth reopening because…_
