=== BWS GP Layout Conditions ===
Contributors: bridgewebsolutions
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Makes GeneratePress disable states and sidebar layout usable by GeneratePress Block Elements.

== Description ==

Standalone plugin so a GeneratePress Block Element that replaces a theme section (header, footer, nav, etc.) respects the same GeneratePress layout config the native section would.

Three layers:

1. **The fix** — neutralizes GP Premium's CSS that hides Block Elements inside disabled section wrappers.
2. **Body classes** — emits `gp-no-{component}` for each disabled state (GeneratePress emits nothing here). GP-only fallback path and CSS hook.
3. **Condition** (`gp_layout_state`) — a GenerateBlocks Pro custom condition so blocks render based on GP layout state. The mechanism that makes a Block Element react to GP layout config.

Detects seven disable states (header, footer, primary nav, secondary nav, top bar, featured image, content title) plus the resolved sidebar layout (left / right / none / both).

== Dependencies ==

* **GeneratePress Premium** — required. Plugin will not activate without it (`Requires Plugins: gp-premium`).
* **GenerateBlocks Pro** — optional. The `gp_layout_state` condition self-gates on GB Pro; the fix and body classes run without it.

Note: on a GenerateBlocks Pro site, deploy the condition together with the fix — the fix alone removes GP's CSS hide without supplying the condition replacement.

== Changelog ==

= 0.1.0 =
* Initial release.
