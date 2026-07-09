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

## Active change set — deepening + V22 + Approach B (2026-07-08)

**Status: in flight.** Source: architecture review (2026-07-07) + ROADMAP deferred items. Task table → `docs/ROADMAP.md` (T7–T10). Order fixed: T7 → T8 → T9 → T10.

**Decided this session:**
- **T7 signal registry** — canonical signal table owned by Detector: key → detector method, condition rule slug, positive label, `gp-no-*` class. Condition `evaluate()`/`get_rules()` + body-class map become data-driven loops over it. Persisted names (rule slugs V27, class names ADR-0004) unchanged. V9 vocab divergence stays — registry stores both names per signal, no harmonize.
- **T8 V22 gap** — featured-image config-replay on non-singular. Extend `layout_element_disables()` engine to `_generate_disable_featured_image`, OR into featured-image signal off-singular. Same query + `show_data()` path as header/footer.
- **T9 environment seam** — internal seam under Detector (~6 reads: singular?, queried id, post meta, hook state, layout-element rows, show_data, layout enum). Two adapters: WP/GP (prod) + in-memory fake (tests). `composer.json` + PHPUnit new. Tests call `states()` — interface unchanged. Targets V1/V2/V5/V6 as executable checks.
- **T10 Approach B** — PHP suppression for 3 CSS-only toggles (secondary nav, featured image, `#mobile-header`), keyed on `_generate-disable-*` meta, hook `wp:60`. **Gated on staging validation** of `docs/proto/approach-b-snippet.php`: (a) GB Pro composition stays OR (V1), no double-remove; (b) PHP alone hides with CSS neutralized. Backprop V14 on land; Approach A row becomes obsolete.

**Open:**
- T10 staging run not yet done — port blocked until markers + visual checks pass.
- Sidebar membership into Detector (contradicts V26) deliberately NOT in set — revisit only after T7.
