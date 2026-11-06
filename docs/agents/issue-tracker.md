# Issue tracker: hybrid, local specs and GitHub bugs

Two homes, split by ONE question: **must the record outlive the change?**

| Work | Home | Why |
|---|---|---|
| Bugs (a claim that is wrong, behavior that fails); anything someone outside waits on | GitHub Issues (`gh` CLI) | Durable, externally visible. |
| A spec (PRD) for one in-flight piece of work | `.scratch/<feature-slug>/spec.md` | Rewritable in place; readable during a GitHub outage; dies at merge |
| The build tickets it breaks into | `.scratch/<feature-slug>/issues/<NN>-<slug>.md` | Numbered per feature from `01`, so they never collide with `#N` |
| Non-bug future work | `docs/future-work.md` (committed) over a detail home | The visible index; see its own intro |
| PUBLICATION of in-flight work | the pull request body, or the commit body when there is no PR | One durable record, at the moment it is reviewable |

`.scratch/` is gitignored. Nothing under it is ever committed. Two things live there and they are not the same shape: `.scratch/<feature-slug>/` is one piece of in-flight work and dies at merge, while `.scratch/plans/` holds long-lived design plans that can outlast many features.

Issues opened before this split (#8, #9, #10, #11) stay on GitHub, each with a `FW-N` item in `docs/future-work.md` naming the issue as its detail home.

## The local half

- One feature per directory: `.scratch/<feature-slug>/`
- Spec at `.scratch/<feature-slug>/spec.md`
- Tickets one file each at `issues/<NN>-<slug>.md`, `01` up, dependency order, never one combined file
- Triage state is a `Status:` line near the top (role strings in `triage-labels.md`)
- Blocking is a `Blocked by: NN, NN` line near the top
- Conversation appends under a `## Comments` heading
- **Cite a local ticket as `<feature-slug>/NN`, never a bare integer**

## The GitHub half

- Create: `gh issue create --title "..." --body "..."` (heredoc for multi-line)
- Read: `gh issue view <number> --comments`
- List: `gh issue list --state open --json number,title,body,labels,comments --jq '[.[] | {number, title, body, labels: [.labels[].name], comments: [.comments[].body]}]'`
- Comment: `gh issue comment <number> --body "..."`
- Label: `gh issue edit <number> --add-label "..."` / `--remove-label "..."`
- Close: `gh issue close <number> --comment "..."`
- Repo is inferred from `git remote -v`.

## IDs never appear bare

Three sequences coexist. Every citation carries its marker, always:

- `FW-7`: a `docs/future-work.md` tracker item
- `#7`: a GitHub issue
- `<feature-slug>/03`: a local ticket

A bare integer is ambiguous between all three. A citation of another repo's id names the repo too, since every project here has its own `FW-N` sequence. `T` ids in `docs/future-work.md` are a closed historical sequence.

## A committed file never cites a private path

A path under `.scratch/` or `.claude/` resolves for one person and fails silently for everyone else. Cite a live plan through its `FW-N` item and a finished spec through its `docs/design-history/` path. `docs/future-work.md` is exempt, because holding those paths is its job. A path with a `<placeholder>` or a `*` names the convention and is not a citation. `tools/guards/check-private-citations.sh` enforces this.

## Publication

A finished spec takes two records at merge:

- **The PR body** holds the review conversation and what changed under challenge.
- **`docs/design-history/`** holds the spec, because its rejected alternatives and unbuilt reasoning have no other home. Lifting is the normal path. The directory is created at the first lift.

A lifted file moves; no copy stays behind. Sanitize it as part of the lift, because tracked files face the PII guard. It is then frozen: an incorrect identifier is repaired in place, and a stale claim is not. Never lift an unbuilt plan. Keep a spec out of the tree only when the other homes fully carry a thin slice.

## When a skill says "publish to the issue tracker"

- A **spec** → write `.scratch/<feature-slug>/spec.md`.
- **Build tickets** → write one file each under `.scratch/<feature-slug>/issues/`.
- A **bug**, or anything whose record must outlive the change → `gh issue create`.
- Unsure → ask. Do not default to GitHub.

**Text that will be POSTED is not hard-wrapped.** GitHub renders issue comments, issue bodies, PR bodies and review comments with GFM's line-break extension, where a single newline becomes a `<br>`. One paragraph, one line; blank lines separate blocks; tables and list items keep their own lines.

## When a skill says "fetch the relevant ticket"

A path → read the file. A bare `#42` → `gh issue view 42 --comments`.

## Pull requests as a triage surface

**PRs as a request surface: no.** _(Set to `yes` if this repo treats external PRs as feature requests; `/triage` reads this flag.)_

When set to `yes`, PRs run through the same labels and states as issues, using the `gh pr` equivalents:

- **Read a PR**: `gh pr view <number> --comments` and `gh pr diff <number>` for the diff.
- **List external PRs for triage**: `gh pr list --state open --json number,title,body,labels,author,authorAssociation,comments` then keep only `authorAssociation` of `CONTRIBUTOR`, `FIRST_TIME_CONTRIBUTOR`, or `NONE` (drop `OWNER`/`MEMBER`/`COLLABORATOR`).
- **Comment / label / close**: `gh pr comment`, `gh pr edit --add-label`/`--remove-label`, `gh pr close`.

GitHub shares one number space across issues and PRs, so a bare `#42` may be either: resolve with `gh pr view 42` and fall back to `gh issue view 42`.

## Wayfinding operations

Used by `/wayfinder`. The **map** is a file with one **child** per ticket. Local only; wayfinding never runs against GitHub Issues here.

- **Map**: `.scratch/<effort>/map.md`, with Notes / Decisions-so-far / Fog.
- **Child**: `.scratch/<effort>/issues/NN-<slug>.md`, from `01`, question in the body. `Type:` records `research`/`prototype`/`grilling`/`task`; `Status:` records `claimed`/`resolved`.
- **Blocking**: `Blocked by: NN, NN`. Unblocked when every listed file is `resolved`.
- **Frontier**: open, unblocked, unclaimed; lowest number wins.
- **Claim**: set `Status: claimed` and save before any work.
- **Resolve**: append the answer under `## Answer`, set `Status: resolved`, then append a context pointer to Decisions-so-far in `map.md`.
