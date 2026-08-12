# Roadmap — bws-generate-layout-conditions

A ledger of what has landed, one line each. **Open work lives in GitHub issues**, not here.

- **Open work** → `gh issue list` (conventions: `docs/agents/issue-tracker.md`)
- **Permanent contract** (invariants, scope, bug ledger, signal map) → `architecture.md`
- **Decisions + rationale** → `adr/`
- **Landed work, one line each** → this file

Each landed task's full reasoning was backpropped on land into the invariants, the bug ledger and the ADRs — those are the live documents. Where a task's detail is not carried by any of them, the full prose is in this file's git history (before 2026-08-12).

---

## Open work

| # | Title | State |
|---|---|---|
| [#2](../../../issues/2) | Featured Image Active: report post-level intent, and add a rule for GP's own image slot | Spec, open — #7 is the last child outstanding |
| [#7](../../../issues/7) | Featured Image Slot Active: a Content Template hides the call site, not the callback | V34 part 5c. Silent-failure direction, candidate for closing |
| [#8](../../../issues/8) | Pin V34 part 5's Blog-module-off false-active directions | Needs a second harness run with the module off, not a fixture |
| [#9](../../../issues/9) | Customizer global element disables are unread — primary nav, secondary nav | V24. One `theme_mod` read, or `wontfix` — decide, don't defer |
| [#10](../../../issues/10) | Sidebar membership: Detector or consumer-side | V26 revisit, unblocked by T7. May end in a decision, not code |
| [#11](../../../issues/11) | Container type condition (`gp_theme_container`) | Reserved slug, unscoped, no consumer |

Accepted gaps carrying no issue live in `architecture.md`: **Meaning B for the native title slot** ("Accepted gap: Meaning B has no signal, on any page type") and **V34 part 5(a)/(b)**, whose observability — not their behaviour — is #8.

---

## Landed

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

**Verification baseline.** T12–T14 and everything after were run against **GB Pro 2.7.0-beta.1 / GP Premium 2.5.6**. The testbed [tracks GB/GP betas](../tools/probes/README.md) deliberately — it is a lookahead environment, not a production mirror, which is what the T14 drift canary exists for.

Operational lessons from T11/T21 that belong to no invariant — opcache staleness, one page per process, running against a real clone — are in `tools/fixtures/layout-states/README.md` and `tools/inspect/README.md`.

---

## Retired deferred items

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
