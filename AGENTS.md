# Agent instructions

## Agent skills

### Issue tracker

Issues live in this repo's GitHub Issues (`davidofchatham/bws-generate-layout-conditions`), managed via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default label vocabulary: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `CONTEXT.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.

### Committed content

`tools/guards/check-tracked-pii.sh` is the policy home for what tracked files must not hold: local paths, emails, unlisted domains and client names. Write a client as Site H or Site P, never by slug, and leave out IDs from a client site. The same rule applies to commit messages, issues and PR text, which the guard cannot see.
