=== BWS GP Layout Conditions ===
Contributors: bridgewebsolutions
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds Theme condition types to GB Pro so blocks can be hidden when a corresponding theme element is disabled via Layout or post-level settings.

== Description ==

GeneratePress **Disable Element** settings don't integrate well with Block Elements that replace your theme sections: the settings in a Layout Element don't apply at all, and the post-level metabox uses `display:none` on whole wrappers like `.site-header`, which may hide more than you intend.

This plugin detects the applicable settings from both Layout Elements and posts at render time, and gives you two options for better integration:

* **Conditions** — two GenerateBlocks Pro condition types, **Theme Element Status** and **Theme Sidebar**. Add one to a block inside an Element to hide it when the matching theme element is disabled. *Nothing is hidden automatically;* you configure conditions yourself, per block.
* **`body` classes** — `gp-no-{component}` for each disabled state, allowing for a custom CSS approach.

It also disables GeneratePress's post-level `display:none`, leaving the PHP hook-based disabling in place.

Additional documentation, including the condition/body-class reference table and known limitations, are in the project README on GitHub: https://github.com/davidofchatham/bws-generate-layout-conditions

== Dependencies ==

* **GeneratePress Premium** — required. Plugin will not activate without it.
* **GenerateBlocks Pro** — optional. The `gp_theme_element` / `gp_theme_sidebar` conditions require GB Pro; disabling the post-level `display:none` and adding the `body` classes work without it.

== Installation ==

1. Ensure **GeneratePress Premium** is installed and active — this plugin will not activate without it.
2. Upload the plugin folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New → Upload.
3. Activate the plugin through the Plugins screen.
4. For the conditions, ensure **GenerateBlocks Pro** is active.
5. Add a **Theme Element Status** or **Theme Sidebar** condition to the blocks inside your Block Elements that should hide when the matching theme element is disabled.

== Changelog ==

= 0.2.0 =
* Featured Image disable is now detected on archive and other non-singular pages, matching how Layout Elements disable it there (previously singular-only).
* Internal: single canonical signal table drives conditions, body classes, and detection; environment seam under the detector with a PHPUnit test suite. No change to condition names, rules, or body-class names.

= 0.1.0 =
* Initial release.
