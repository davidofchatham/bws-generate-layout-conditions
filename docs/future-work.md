# Future work tracker

**Not a roadmap.** No committed timeline on anything here. This is a single *visible* index of non-bug work: the tracked, reviewable surface over detail homes that are private (`.scratch/` is gitignored) or on GitHub. It duplicates no detail; open the linked home for the full design and rationale. One heading block per **`FW-N` id**.

- **Bugs do NOT go here** → GitHub Issues. See `docs/agents/issue-tracker.md` for the split and why.
- **Detail lives in its home**: a GitHub issue, a `.scratch/plans/*.md` file, or a wayfinder `map.md`. An item states only *that the work exists, what gates it, what it touches, where it stands, and where to read more*.
- **Cross-refs use ids, never prose**, so a reworded item never orphans a reference. `FW-7` is an item here · `#7` is a GitHub issue · `<slug>/03` is a local ticket. A bare integer is ambiguous between all three. An id from another repo's tracker names that repo.
- **Ids are permanent.** A shipped or cut item's id retires to the Closed / retired ledger, never reused or reassigned.

## Index

- [Item shape](#item-shape)
- [Progress, not status](#progress-not-status)
- [Trackers](#trackers)
  - [Test coverage](#test-coverage)
  - [Detection scope](#detection-scope)
- [Closed / retired](#closed--retired)
- [Maintenance](#maintenance)

## Item shape

Each item is a `#### FW-N — <title>` heading followed by a fixed set of labeled lines:

- A **description** paragraph (1-3 sentences): what the item IS. Stable, rarely re-edited; not current state, not history.
- **Detail home:** where the design/rationale and the implicit certainty (concept vs planned) live. Never duplicated here.
- **Progress:** where the work actually stands. Always present, even if just "Not started." Rules below.
- **Open:** what's still undecided or unbuilt, when there's a real done/open split. Omitted when there's nothing beyond Progress worth stating separately.
- **Blocked by:** hard prerequisite, typed (below). **Interacts with:** soft coupling (reshapes, or is reshaped by) as `FW-N` ids, never a gate. Both on one line; `—` means unblocked.

### Typed blockers

A blocker is written as `<type>:<referent>`, because this field is agent-maintained and the type is what tells an agent whether it may act on the cell:

| Type | Means | Clears when |
|---|---|---|
| `row:FW-N` | Another item must land first | That item reaches Closed / retired; **an agent may clear it unasked** |
| `ship:X.Y.Z` | A version must ship first | That version is released; **an agent may clear it unasked** |
| `decision:<what>` | An open human choice | A human makes it. **Never auto-cleared, never auto-started** |
| `code:<condition>` | A state elsewhere that does not hold yet | The condition holds. Re-derivable by grep, so an agent may clear it, but must state the check in Progress |
| `—` | Unblocked | — |

**A blocker states a CODE FACT, never a scheduling preference**: "this cannot land until X", not "do this after X". If the ordering is a preference, it belongs in the description, not here.

> **Agent pickup:** an item is startable when `Blocked by:` is `—` or every `row:`/`ship:`/`code:` gate it names is satisfied. **A `decision:` gate is human-resolved. Do not auto-start it**, and do not resolve the decision yourself to unblock it. `Interacts with:` never blocks. An item whose Progress says a build is live is already being worked; read its Detail home for real state before touching it.

## Progress, not status

A `Progress:` line is allowed, but only for statements that stay true FOREVER once true: a half shipped, a measurement taken, a condition met. "Phase 2 of 3, ~60% done" is stale the moment work continues, so live estimates, phase names, percent-done and remaining-task counts belong in the branch, the plan, or the commit that owns the real state. **Progress is a few lines, never a build log.**

**There is no In-flight section.** Lifecycle is two-valued: an item is in a tracker section, or it is in **Closed / retired**. Progress carries the "someone is building this" bit by naming the branch or commit that holds the real state.

## Trackers

Accepted gaps carrying no item live in `architecture.md`: **Meaning B for the native title slot** ("Accepted gap: Meaning B has no signal, on any page type") and **V34 part 5(a)/(b)**, whose observability — not their behavior — is #8.

### Test coverage

#### FW-1 — Pin V34 part 5's Blog-module-off false-active directions

Observe the two false-active directions of V34 part 5, which occur only with the Blog module off. This needs a second harness run with the module off, not another fixture.

Detail home: [#8](../../../issues/8)

Progress: Not started.

Blocked by: —  •  Interacts with: —

### Detection scope

#### FW-2 — Customizer global element disables for primary and secondary nav

The Customizer's global disables for the primary and secondary nav are not read (V24). Either one `theme_mod` read closes it, or it closes as `wontfix`.

Detail home: [#9](../../../issues/9)

Progress: Not started.

Blocked by: decision:add the `theme_mod` read or close as `wontfix`  •  Interacts with: —

#### FW-3 — Sidebar membership: Detector or consumer-side

Revisit where sidebar membership belongs (V26). This may end in a decision, not code.

Detail home: [#10](../../../issues/10)

Progress: Not started.

Blocked by: decision:whether membership stays in the Detector  •  Interacts with: —

#### FW-4 — Container type condition (`gp_theme_container`)

A condition for container type and width. The slug is reserved (V27), and the work is unscoped.

Detail home: [#11](../../../issues/11)

Progress: Not started.

Blocked by: code:a consumer needs the condition  •  Interacts with: —

## Closed / retired

Append-only ledger. Ids are **permanent**: a retired id is never reused or reassigned, and this ledger is the only record of the high-water mark. `T` ids are a closed historical sequence from before this file used `FW` ids; no new `T` id is minted. Where a landed task's detail is carried by no live document, the full prose is in the git history of this file (before 2026-08-12, when it was `docs/ROADMAP.md`).

### `FW` items

| ID | Item | Outcome | Landed / detail home |
|---|---|---|---|

### Landed tasks

| id | Landed | Task | Detail lives in |
|---|---|---|---|
| T7 | 2026-07 | Signal registry — canonical signal table in Detector; `evaluate()`, `get_rules()` and the body-class map become loops over it | V5, V8, V9, V27 |
| T8 | 2026-07 | Featured-image config-replay on non-singular — **reversed by ADR-0006 (2026-08-06)**, its only source marked a relocation | V22, ADR-0006 |
| T9 | 2026-07 | Environment seam + PHPUnit — WP/GP adapter and in-memory fake under the Detector; V1/V2/V5/V6 become executable | V1, V2, V5, V6 |
| T12 | 2026-07-21 | Integration test surface — `tools/fixtures/layout-states/` blueprint + `seam-fidelity.php`, 28 assertions proving the fake's assumptions against real WP/GP | V4, V22, V23, V26, V28 |
| T13 | 2026-07-21 | V2 poisoned-signal proof — `poisoned-signal.php`, 20 assertions; hook-state would report a false "disabled" where config-replay does not | V2, V20 |
| T14 | 2026-07-21 | Version-drift canary — `tools/probes/upstream-surface.php`; a non-conforming class drops both conditions from the UI with no diagnostic anywhere | V27, V28, `tools/probes/README.md` |
| T11 | 2026-07-21 | Render-level harness (HTTP) — `render-surface.sh`. **Found B5**: the CSS-neutralize had never run on any request, invisible to CLI | V24, V25, B5, fixtures README |
| T10 | 2026-07-21 | Approach B: PHP suppression for the CSS-only toggles, dissolving the V14 regression. Visual pass done | V14, V24, V25 |
| T15 | 2026-08-04 | Page-title signal, archive branch — no configuration source reaches the page-title role off singular, so the signal short-circuits there | ADR-0005, V30, V31 |
| T16 | 2026-08-04 | Content title → Meaning A (relocation ≠ disable), shipped with T15 as one change | ADR-0005, V21, V29, V31 |
| T17 | 2026-08-04 | Secondary nav: config-replay the Layout Element layer. Ungated on `is_singular()`, unlike the content title | V32 |
| T18 | 2026-08-05 | Render coverage for T17, blueprint v5. **Found B7**: additive meta writes made a mutation pass 39/39 falsely | V32, V33, B7 |
| T19 | 2026-08-06 | Featured image reports post-level intent. The Layout Element key is deliberately **not** replayed — do not restore the symmetry. **Found B9** | ADR-0006, [#3](../../../issues/3), V33, B9 |
| T20 | 2026-08-06 | Kill-switch fixture + conditioned-block render coverage, blueprint v8 — the first assertions to read the authoring chain end to end | V34, [#5](../../../issues/5), fixtures README |
| T21 | 2026-08-07 | Clone dry-run on real content, then release. Five rows green; the **visual pass** (2026-08-12) then found V34 part 5c, which five green rows could not | V34 part 5c, [#6](../../../issues/6), [#7](../../../issues/7), `tools/inspect/README.md` |

T19–T21 were the children of spec [#2](../../../issues/2), **released as v0.3.0 on 2026-08-12** and closed with it. #7 and #8 carry a parent line to that spec but are follow-ups rather than unmet scope — #7 names a direction the spec never claimed, #8 pins a residue the spec accepted explicitly.

### Retired deferred items

Items that sat in the deferred table and are now closed. Kept so a rejected framing is not re-proposed.

| Item | Outcome |
|---|---|
| Content title: relocation vs disable | Promoted to T15 + T16, landed as ADR-0005. The two-rule shape was rejected: it existed only to protect unknown Meaning-B consumers, and there are none |
| Layout Element featured image on non-singular | Promoted to T8, then **reversed** by ADR-0006 |
| CSS-neutralize disable toggle — Approach A (re-emit CSS) | Obsolete. It was the fallback for Approach B failing staging; it did not, and the V14 regression is dissolved rather than papered |
| Convert CSS-only toggles to PHP suppression — Approach B | Promoted to T10, landed |
| Featured Image Slot Active (theme) rule | Landed as [#4](../../../issues/4); render coverage closed by T20 |
| Featured image → config-replay | Landed as ADR-0006 / [#3](../../../issues/3), but **not** as config-replay — the element key is how a relocation is performed, so the rule reads the per-post metabox alone. The title and image signals deliberately diverge |
| Render-level fixture for the secondary-nav Layout Element | Promoted to T18, landed |
| Content Template swallows the featured-image call site | Now [#7](../../../issues/7) |
| Meaning-B rule for the native title slot | Not an issue by decision — accepted gap, recorded in `architecture.md`. The `generate_show_title` writer survey there **is** the detection spec if it is ever picked up |
| Customizer `theme_mod` layer | Now [#9](../../../issues/9) |
| Container type condition | Now [#11](../../../issues/11) |

## Maintenance

- **Order by id.** Items within a tracker section, and rows in the `FW` ledger, sit in ascending `FW-N` order. A new item goes at the end of its section; a closing row slots in by number.
- **New non-bug idea** → add a `#### FW-N` heading block under the right tracker section, using **(highest live `FW` id ∪ highest retired `FW` id) + 1**; never reuse a retired id. `T` ids do not count. Put the detail in a home, not here. Don't let work exist *only* in a hidden file with no item here.
- **Closing an issue retires its `FW` id.** An item whose Detail home is a GitHub issue moves to the ledger when that issue closes.
- **Build starts** → say so in `Progress:`, naming the branch or commit that owns the real state. Do not record phase, commit or remaining-task counts in the item.
- **Ships** → move the item to **Closed / retired** as a row, collapsing description + progress into one Outcome cell, and point Landed at the commit or file that holds the result. Then append ` (closed)` to its id wherever a live item's `Interacts with:` names it.
- **`Interacts with:` is recorded on both sides.** A one-sided link is invisible from the item that needed to see it.
- **An item may carry a COUNT only when the count IS the deliverable.** An argument count, cited as evidence for a claim, decays into a false statement that still reads as current.
- **Cut work retires too.** A withdrawn item moves to the ledger with its outcome; a deleted item reads as an id that never existed.

<!--
Detail-home paths under `.scratch/` are gitignored and unreadable to anyone but
the author -- that is the point of this file, not a defect in it. A committed
file elsewhere in the repo must cite the FW id, never the path.
-->
