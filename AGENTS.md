# Agent instructions

## Agent skills

### Issue tracker

Hybrid. Bugs live in this repo's GitHub Issues (`davidofchatham/bws-generate-layout-conditions`), managed via the `gh` CLI. Specs and tickets live in a gitignored `.scratch/`, indexed with other non-bug work in `docs/future-work.md`. See `docs/agents/issue-tracker.md`.

### Triage labels

Default label vocabulary: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `CONTEXT.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.

## Spec lifecycle

A spec lives at `.scratch/<feature-slug>/spec.md` while the work is in flight. At merge, its load-bearing parts move out: invariants and decisions to `docs/architecture.md` or the `CONTEXT.md` entry that owns them, the delta to the `readme.txt` changelog, the cause to the commit body. Write the PR body, then lift the spec to `docs/design-history/`, sanitizing first. The rules are under Publication in `docs/agents/issue-tracker.md`.

## Committed content

Tracked prose is scanned by the guards in `tools/guards/`. `tools/guards/check-tracked-pii.sh` is the policy home for what tracked files must not hold: local paths, emails, unlisted domains and client names. Write a client as Site H or Site P, never by slug, and leave out IDs from a client site. The same rule applies to commit messages, issues and PR text, which the guard cannot see.
