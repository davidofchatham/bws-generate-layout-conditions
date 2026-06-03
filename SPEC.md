# SPEC — bws-generate-layout-conditions (in-flight)

Working doc for the current build. Permanent material lives elsewhere:

- **Invariants, bug ledger, signal map, neutralize scope** → `docs/architecture.md`
- **Decisions + rationale** → `docs/adr/`
- **Deferred work + build-task table** → `docs/ROADMAP.md`
- **Domain language** → `CONTEXT.md`

This file tracks what is *in flight right now* — open questions and the active change set. Trim as tasks land.

## Scope

In scope: the seven **disable states** (header, footer, primary nav, secondary nav, top bar, featured image, content title) plus **sidebar layout** (left/right/none/both). **Container type / width** are deferred — see `docs/ROADMAP.md`.

---

## Active change set — condition split + sidebar membership (2026-06-03)

**Status: implemented.** Spec backpropped (V26, V27, B4); code split + docs synced. See `docs/ROADMAP.md` (all tasks done).

**Decided this session:**
- Split the single `gp_layout_state` condition into separate registry slugs — `gp_theme_element` + `gp_theme_sidebar` now, `gp_theme_container` reserved (V27). Reason: condition slug is persisted in saved condition data; splitting post-release forces a migration, pre-release is free.
- Sidebar rules move from exclusive enum-match to membership: left/right TRUE whenever that side renders, including the both-sidebars layout (V26, fixes B4). Drop `both_sidebars_active`; "both" composed via `Left Active is AND Right Active is`. 11 rules → 10.
- Labels: "Theme Element Status" (element condition), "Theme Sidebar" (sidebar condition). "Theme" prefix mirrors GP's "Site Options" scoping.
- Constraints confirmed: operators `['is','is_not']` mandatory at registration (UI renders fixed Type→Rule→Operator); `needs_value=false` stays; positive "Active" rule framing stays.

**Resolved:**
- Two classes — one per slug (`BWS_GP_Theme_Element_Condition`, `BWS_GP_Theme_Sidebar_Condition`), each self-contained, matching GB Pro's one-class-per-condition pattern.
- `gp_theme_container` reserved only — no stub registration; build when container-type need is scoped.

**Next:** implement the two ROADMAP build tasks (split + membership), then sync CONTEXT.md.
