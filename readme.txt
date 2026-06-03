=== BWS GP Layout Conditions ===
Contributors: bridgewebsolutions
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

[TODO: one-sentence tagline — what problem this solves, for whom]

== Description ==

[TODO: opening paragraph — the problem: Block Elements replacing theme sections don't respect GP layout config natively]

[TODO: paragraph — what the plugin does: three layers (fix, body classes, condition)]

[TODO: paragraph — who needs this: GP Premium + GB Pro sites using Block Elements to replace header/footer/nav/etc.]

=== What's included ===

**The fix** — [TODO: brief description of CSS-neutralize]

**Body classes** — Emits `gp-no-{component}` for each disabled state. GeneratePress emits no body class when a section is disabled; this fills that gap.

[TODO: list the seven classes: gp-no-header, gp-no-footer, gp-no-primary-nav, gp-no-secondary-nav, gp-no-top-bar, gp-no-featured-image, gp-no-content-title]

**Conditions** (`gp_theme_element`, `gp_theme_sidebar`) — Two GenerateBlocks Pro custom conditions. "Theme Element Status" lets blocks render or not based on GP element disable state (7 rules); "Theme Sidebar" based on the resolved sidebar layout (3 rules). The mechanism that makes a Block Element react to GP layout config.

[TODO: list the 10 condition rules with brief description of each]

=== Detection reference ===

What each condition rule detects and its corresponding body class:

[TODO: insert table — see docs/architecture.md element toggle map for source data]

| Condition rule | True when | Body class | GP native class |
|---|---|---|---|
| Header Active | Header not disabled | `gp-no-header` (when false) | — |
| Footer Active | Footer not disabled | `gp-no-footer` (when false) | — |
| Primary Nav Active | Primary nav not disabled | `gp-no-primary-nav` (when false) | — |
| Secondary Nav Active | Secondary nav not disabled (post metabox only) | `gp-no-secondary-nav` (when false) | — |
| Top Bar Active | Top bar not disabled | `gp-no-top-bar` (when false) | — |
| Featured Image Active | Featured image not disabled by config | `gp-no-featured-image` (when false) | `featured-image-active` (different — render-based) |
| Content Title Active | Content title not disabled | `gp-no-content-title` (when false) | — |
| Left Sidebar Active | Left sidebar renders (layout = left or both) | — | `left-sidebar` |
| Right Sidebar Active | Right sidebar renders (layout = right or both) | — | `right-sidebar` |
| No Sidebars Active | Sidebar layout = none | — | `no-sidebar` |

Note: sidebar body classes are emitted by GeneratePress natively; this plugin adds no sidebar class of its own. Sidebar rules are membership tests — "Left Sidebar Active" is true on a both-sidebars page too. To match the both-sidebars layout exactly, combine "Left Sidebar Active" and "Right Sidebar Active" with AND.

=== Known limitations ===

[TODO: plain-language summary of detection gaps — secondary nav Layout Element, Customizer layer, archive featured image, Page Hero toggle ambiguity]

== Dependencies ==

* **GeneratePress Premium** — required. Plugin will not activate without it.
* **GenerateBlocks Pro** — optional. The `gp_theme_element` / `gp_theme_sidebar` conditions require GB Pro; the fix and body classes run without it.

**Important:** On a GenerateBlocks Pro site, deploy this plugin together with your Block Elements. The fix alone (without the condition) removes GP's CSS hide but does not supply the condition replacement — a section that should be disabled would appear.

== Installation ==

[TODO: installation steps — upload, activate, dependencies note]

== Frequently Asked Questions ==

[TODO: add FAQ entries as questions arise]

== Changelog ==

= 0.1.0 =
* Initial release.
