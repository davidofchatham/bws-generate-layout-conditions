<?php
/**
 * layout-states blueprint — manifest (the data contract).
 *
 * Composes on core-structures (bws-gb-dynamic-tags-extensions). Reuses its
 * posts/terms BY REFERENCE and redefines nothing in its `defines`.
 *
 * This blueprint's axis is the GP THEME STRUCTURE surface — `gp_elements`
 * (Block / Layout / Hero), per-post Disable-Elements metabox meta, and theme
 * mods. core-structures owns CONTENT (CPTs, ACF groups, field values); the two
 * do not overlap, which is why there is no schema.php here: every post type and
 * meta key below is registered by GP Premium, not by this blueprint.
 *
 * Manifest owns DATA; the PHPUnit env-suite owns ASSERTIONS. Invariant refs
 * (V-numbers) in comments are provenance pointers to docs/architecture.md,
 * not expectations.
 *
 * REQUIRES the GP Premium Elements + Disable Elements modules to be ACTIVE
 * (option `generate_package_elements` === 'activated'). GP Premium gates every
 * module behind its own option and ships them OFF; with Elements off,
 * `GeneratePress_Conditions` never loads and config-replay (V2) silently
 * no-ops. seed.php asserts this rather than seeding into a dead environment.
 */

return array(
	'blueprint' => 'layout-states',
	'version'   => 3,

	'composes_on' => array(
		'blueprint'   => 'core-structures',
		'min_version' => 4,
	),

	// Keys THIS blueprint defines (later blueprints must not redefine).
	// `gp_elements` is GP Premium's CPT — listed as a claim on the fixture
	// NAMESPACE (the `ls-` prefix), not on the post type itself.
	'defines' => array(
		'post_types'    => array(),
		'acf_groups'    => array(),
		'slug_prefix'   => 'ls-',
		'options'       => array( 'generate_menu_plus_settings' ),
		// Shared with any blueprint that assigns menus. Claimed here because a
		// blueprint that replaces (rather than merges) this map would silently
		// unassign the fixture nav and make the V24 nav surfaces vacuous again.
		'nav_locations' => array( 'primary', 'secondary' ),
	),

	// GP Premium module options that must be 'activated' for the seeded
	// fixtures to have any effect. seed.php hard-errors when one is missing —
	// a silent no-op here would produce green tests that assert nothing.
	'requires_modules' => array(
		'generate_package_elements',
		'generate_package_disable_elements',
		'generate_package_secondary_nav',
		'generate_package_menu_plus',
	),

	// -----------------------------------------------------------------------
	// Elements (`gp_elements`). post_status MUST be 'publish' — GP's element
	// loader queries publish-only (elements.php:36).
	//
	// Meta value shapes are NOT uniform across element types, and the
	// difference is load-bearing:
	//   - Layout element disables  => string 'true'   (checkbox value= in the
	//                                 admin metabox; GP DELETES the row when
	//                                 unset, so "off" means KEY ABSENT, never
	//                                 an empty string)
	//   - Block element disables   => registered as bool (register_meta +
	//                                 rest_sanitize_boolean), but that
	//                                 sanitizer only runs on the REST path.
	//                                 update_post_meta( ..., true ) stores the
	//                                 string '1' — VERIFIED on testbed, not
	//                                 assumed. Both are truthy and every
	//                                 consumer does a truthy check, so '1' is
	//                                 correct for a CLI-seeded fixture; a
	//                                 fixture written over REST would hold a
	//                                 real bool instead.
	// Seeding a bool where GP writes 'true' produces a fixture that passes
	// this blueprint's own verify but does not match what the admin UI stores.
	// -----------------------------------------------------------------------
	'elements' => array(

		// --- V2 poisoned-signal generators -------------------------------
		// A Block Element on generate_header/generate_footer unconditionally
		// remove_action()s the native construct to claim the hook
		// (class-block.php:169-190 — keyed on the RESOLVED HOOK NAME, with no
		// opt-out meta). So `! has_action(...)` reads "disabled" on every page
		// carrying the element, whether or not anything is disabled. That is
		// the poisoned signal V2/ADR-0001 exists to route around, and these
		// two fixtures are what make it reproducible.
		//
		// Deliberately NO disable meta and a site-wide display condition: any
		// "disabled" reading taken off these pages is a FALSE POSITIVE by
		// construction.
		'ls-el-header-block' => array(
			'post_title'  => 'LS: Header Block Element',
			'post_name'   => 'ls-el-header-block',
			'post_status' => 'publish',
			'meta'        => array(
				'_generate_element_type' => 'block',
				'_generate_block_type'   => 'site-header', // forces hook generate_header
			),
			// Scoped to ONE page so other fixtures keep an unpoisoned header
			// signal. Site-wide here would poison every assertion below.
			'display_conditions' => array(
				array( 'rule' => 'post:page', 'object' => '{{ls-page-poisoned}}' ),
			),
			'post_content' => '<!-- wp:paragraph --><p>ls-header-block-element</p><!-- /wp:paragraph -->',
		),

		'ls-el-footer-block' => array(
			'post_title'  => 'LS: Footer Block Element',
			'post_name'   => 'ls-el-footer-block',
			'post_status' => 'publish',
			'meta'        => array(
				'_generate_element_type' => 'block',
				'_generate_block_type'   => 'site-footer', // forces hook generate_footer
			),
			'display_conditions' => array(
				array( 'rule' => 'post:page', 'object' => '{{ls-page-poisoned}}' ),
			),
			'post_content' => '<!-- wp:paragraph --><p>ls-footer-block-element</p><!-- /wp:paragraph -->',
		),

		// --- Layout Element: header + footer disable (config-replay, V2) ---
		// The layer config-replay actually reads. Scoped to one page so the
		// replay query has a discriminating case.
		'ls-el-layout-header-footer' => array(
			'post_title'  => 'LS: Layout — disable header + footer',
			'post_name'   => 'ls-el-layout-header-footer',
			'post_status' => 'publish',
			'meta'        => array(
				'_generate_element_type'        => 'layout',
				'_generate_disable_site_header' => 'true',
				'_generate_disable_footer'      => 'true',
			),
			'display_conditions' => array(
				array( 'rule' => 'post:page', 'object' => '{{ls-page-layout-disabled}}' ),
			),
		),

		// --- Layout Element: featured image on a NON-SINGULAR archive (V22) -
		// The T8 case. GP's layout element fires remove_action for the
		// featured image with NO is_singular() guard
		// (class-layout.php:315) — so it disables on archives too, where the
		// hook-state signal is meaningless (V20/B2). Detector's non-singular
		// branch replays this meta instead.
		//
		// Targets the core-structures `department` taxonomy archive. See
		// `foreign_dependencies` below — this is the one place this blueprint
		// asserts against a fixture it does not own.
		'ls-el-layout-featured-archive' => array(
			'post_title'  => 'LS: Layout — disable featured image (archive)',
			'post_name'   => 'ls-el-layout-featured-archive',
			'post_status' => 'publish',
			'meta'        => array(
				'_generate_element_type'            => 'layout',
				'_generate_disable_featured_image'  => 'true',
			),
			'display_conditions' => array(
				array( 'rule' => 'taxonomy:department', 'object' => 'sales' ),
			),
		),

		// --- Layout Element with EXCLUDE + USER conditions (V4) ------------
		// V4: config-replay must pass all THREE condition metas to
		// show_data(). An element that matches on display but is knocked out
		// by exclude is the only fixture that can catch a two-arg replay —
		// display-only would report this page as disabled.
		//
		// VERIFIED discriminating on testbed:
		//   show_data( $display, array(), array() ) === true   (would disable)
		//   show_data( $display, $exclude, $users )  === false  (stays active)
		//
		// Asserting this requires a REAL MAIN QUERY: show_data() evaluates
		// conditionals against the current request, and under `wp eval-file`
		// nothing is queried (is_singular() false, queried id 0), so both arms
		// return false and the test passes vacuously. Bootstrap the query
		// first — `wp( 'page_id=' . $id )` — then assert. `--url` alone is NOT
		// enough: it sets site context without running the query.
		'ls-el-layout-excluded' => array(
			'post_title'  => 'LS: Layout — display site-wide, EXCLUDE one page',
			'post_name'   => 'ls-el-layout-excluded',
			'post_status' => 'publish',
			'meta'        => array(
				'_generate_element_type'        => 'layout',
				'_generate_disable_site_header' => 'true',
			),
			'display_conditions' => array(
				array( 'rule' => 'post:page', 'object' => '{{ls-page-excluded}}' ),
			),
			'exclude_conditions' => array(
				array( 'rule' => 'post:page', 'object' => '{{ls-page-excluded}}' ),
			),
			'user_conditions'    => array( 'general:logged_out' ),
		),

		// --- Page Hero (V21 characterization) -----------------------------
		// V21 names a "Page Hero Block Element" carrying BOTH a featured-image
		// and a title disable. Only the BLOCK implementation
		// (_generate_block_type = 'page-hero') has both toggles: the legacy
		// Header Element (_generate_element_type='header') has
		// _generate_hero_disable_featured_image but NO title toggle at all.
		// So V21 is about this element, and these two keys are real PHP
		// booleans (register_meta, class-block-elements.php:1435+).
		//
		// The ambiguity being pinned: the Hero EMBEDS the image/title itself,
		// so it removes the same hooks the Detector reads. Detector reports
		// "disabled" while both are visibly active via the Hero. v1 behavior
		// is hook-state-wins; this fixture exists to CHARACTERIZE that, not to
		// assert it is correct. Do not "fix" it without the ADR toggle.
		'ls-el-page-hero' => array(
			'post_title'  => 'LS: Page Hero — disable title + featured image',
			'post_name'   => 'ls-el-page-hero',
			'post_status' => 'publish',
			'meta'        => array(
				'_generate_element_type'           => 'block',
				'_generate_block_type'             => 'page-hero',
				// Registered as bool; stored as '1' via update_post_meta (the
				// REST bool sanitizer does not run on the CLI path). Truthy
				// either way — see the shape note at the top of `elements`.
				'_generate_disable_title'          => true,
				'_generate_disable_featured_image' => true,
			),
			'display_conditions' => array(
				array( 'rule' => 'post:page', 'object' => '{{ls-page-hero}}' ),
			),
			'post_content' => '<!-- wp:paragraph --><p>ls-page-hero-element</p><!-- /wp:paragraph -->',
		),
	),

	// -----------------------------------------------------------------------
	// Pages. Own namespace (`ls-`) so a core-structures reseed can never
	// collide with, or silently reshape, a theme-structure assertion.
	//
	// `disable_meta` is the GP Premium per-post Disable-Elements METABOX layer
	// (`_generate-disable-*`, note the HYPHENS — distinct from the underscored
	// element meta above). This is the layer CSS-neutralize touches (V12/V24).
	//
	// `featured_image => true` (v2) attaches the shared fixture attachment as the
	// page thumbnail. Only needed where a render assertion looks at the featured
	// -image surface — for the toggle page and its control. Everywhere else the
	// thumbnail is irrelevant and omitted.
	// -----------------------------------------------------------------------
	'pages' => array(

		// Baseline: nothing disabled anywhere. Every signal must read active.
		// The control case — without it, an always-"disabled" bug looks green.
		'ls-page-baseline' => array(
			'post_title' => 'LS: Baseline (nothing disabled)',
			'post_name'  => 'ls-page-baseline',
			// Carries a thumbnail so it is a real CONTROL for the featured-image
			// surface: the image must be present here and absent (or hidden) on
			// ls-page-metabox-featured. Without it both pages render no image and
			// the comparison is vacuous. See `featured_image` below.
			'featured_image' => true,
		),

		// Carries the header+footer Block Elements (V2). Nothing is actually
		// disabled here — any "disabled" read is the poisoned signal firing.
		'ls-page-poisoned' => array(
			'post_title' => 'LS: Poisoned signal (header + footer Block Elements)',
			'post_name'  => 'ls-page-poisoned',
		),

		// Layout Element disables header + footer via config-replay.
		'ls-page-layout-disabled' => array(
			'post_title' => 'LS: Layout Element disables header + footer',
			'post_name'  => 'ls-page-layout-disabled',
		),

		// V4: display matches but exclude knocks it out → header stays ACTIVE.
		'ls-page-excluded' => array(
			'post_title' => 'LS: Layout Element excluded (header stays active)',
			'post_name'  => 'ls-page-excluded',
		),

		// V21 Page Hero ambiguity.
		'ls-page-hero' => array(
			'post_title' => 'LS: Page Hero (title + featured image ambiguity)',
			'post_name'  => 'ls-page-hero',
		),

		// --- Per-post metabox layer (V24/V25 CSS-neutralize surface) -------
		// V24 pins the neutralize regression surface as exactly three toggles.
		// One page each, so a render assertion can name its cause.

		// V24 — CSS-only, no PHP removal. Full regression surface.
		'ls-page-metabox-featured' => array(
			'post_title'   => 'LS: Metabox — disable featured image (CSS-only)',
			'post_name'    => 'ls-page-metabox-featured',
			'disable_meta' => array( '_generate-disable-post-image' => 'true' ),
			// Load-bearing (added v2). The toggle only has an observable effect
			// on a page that HAS a featured image — with no thumbnail, GP renders
			// no .page-header-image-single either way and the V24 assertion
			// passes without testing anything. This is the primary CSS-only
			// regression surface, so a vacuous pass here is the worst case.
			'featured_image' => true,
		),

		// V24 — CSS-only. Full regression surface.
		'ls-page-metabox-secondary-nav' => array(
			'post_title'   => 'LS: Metabox — disable secondary nav (CSS-only)',
			'post_name'    => 'ls-page-metabox-secondary-nav',
			'disable_meta' => array( '_generate-disable-secondary-nav' => 'true' ),
			// Added v3 for T10's over-suppression check: that assertion looks for
			// the featured image STILL rendering under a different toggle, so
			// without a thumbnail here it passes against a page that renders no
			// image either way — vacuous in the same manner as the v1 nav bug.
			'featured_image' => true,
		),

		// V25 — PARTIAL. `_generate-disable-nav` PHP-kills the source nav, but
		// the `<nav id="mobile-header">` WRAPPER is hidden by CSS alone
		// (generate-menu-plus.php:1082 renders it gated only on
		// mobile_header !== 'disable'). Neutralize re-exposes that bar. Needs
		// the Menu Plus mobile header ON — see theme_mods below.
		'ls-page-metabox-nav' => array(
			'post_title'   => 'LS: Metabox — disable primary nav (V25 mobile-header)',
			'post_name'    => 'ls-page-metabox-nav',
			'disable_meta' => array( '_generate-disable-nav' => 'true' ),
			// Added v3 — see the note on ls-page-metabox-secondary-nav. Same
			// vacuous-pass risk for T10's over-suppression assertion.
			'featured_image' => true,
		),

		// PHP-removed toggles — CSS redundant, so neutralize is a NO-OP here.
		// V24 claims these are risk-free; a fixture makes that falsifiable
		// instead of merely asserted.
		'ls-page-metabox-php-removed' => array(
			'post_title'   => 'LS: Metabox — header + footer + title (PHP-removed)',
			'post_name'    => 'ls-page-metabox-php-removed',
			'disable_meta' => array(
				'_generate-disable-headline' => 'true', // content title
				'_generate-disable-top-bar'  => 'true',
			),
		),

		// --- Sidebar enum coverage (V26) -----------------------------------
		// Membership rules are NOT exclusive enum-match: left_sidebar_active
		// must be TRUE on a both-sidebars page. Only a both-sidebars fixture
		// can catch a regression to exclusive matching, so all four values
		// need to be reachable.
		//
		// `sidebar_layout` is written to the per-post metabox key
		// `_generate-sidebar-layout-meta` (hyphenated; GP's own layout element
		// defers to it — class-layout.php:285). Distinct from the Layout
		// Element key `_generate_sidebar_layout` (underscored).
		'ls-page-sidebar-left' => array(
			'post_title'    => 'LS: Sidebar — left',
			'post_name'     => 'ls-page-sidebar-left',
			'sidebar_layout' => 'left-sidebar',
		),
		'ls-page-sidebar-right' => array(
			'post_title'    => 'LS: Sidebar — right',
			'post_name'     => 'ls-page-sidebar-right',
			'sidebar_layout' => 'right-sidebar',
		),
		'ls-page-sidebar-both' => array(
			'post_title'    => 'LS: Sidebar — both (V26 membership case)',
			'post_name'     => 'ls-page-sidebar-both',
			'sidebar_layout' => 'both-sidebars',
		),
		'ls-page-sidebar-none' => array(
			'post_title'    => 'LS: Sidebar — none',
			'post_name'     => 'ls-page-sidebar-none',
			'sidebar_layout' => 'no-sidebar',
		),
	),

	// -----------------------------------------------------------------------
	// Site OPTIONS this blueprint sets.
	//
	// Was `theme_mods` through v1, and that was a real bug, not a naming
	// preference: GP Premium reads generate_menu_plus_settings exclusively via
	// get_option() (~20 call sites across menu-plus, elements, disable-elements
	// and the customizer; ZERO get_theme_mod calls). set_theme_mod() writes to
	// theme_mods[...] in a different row, which GP never reads — so the setting
	// below silently did nothing from v1 until v2.
	//
	// The consequence was exactly what the comment below warned about: with
	// mobile_header defaulting to 'disable' (generate_menu_plus_get_defaults),
	// <nav id="mobile-header"> never rendered, so V25 had never once been
	// observed on this testbed. It was documented from reading GP's source, not
	// from seeing the wrapper. Treat the invariant as unconfirmed until a render
	// assertion has actually seen it.
	// -----------------------------------------------------------------------
	'options' => array(
		// V25 requires the Menu Plus mobile header ACTIVE — the whole
		// invariant is about the `<nav id="mobile-header">` wrapper surviving
		// the PHP disable path and being hidden by CSS alone. With this off,
		// a V25 test vacuously passes.
		'generate_menu_plus_settings' => array(
			'mobile_header'      => 'enable',
			'mobile_header_logo' => '',
			'sticky_menu'        => 'false',
		),
	),

	// -----------------------------------------------------------------------
	// Nav menus. Added in v2 for the render harness (T11).
	//
	// GP renders <nav id="site-navigation"> and <nav id="secondary-navigation">
	// only when a menu is ASSIGNED to that location. Through v1 no menu existed,
	// so both wrappers were absent from every page — and a render assertion
	// looking for their absence under a disable toggle would have passed on
	// every page including the control, proving nothing.
	//
	// One shared menu assigned to both locations is enough: these fixtures care
	// whether the wrapper renders, never what is inside it.
	// -----------------------------------------------------------------------
	'nav_menus' => array(
		'ls-nav' => array(
			'name'      => 'LS: Fixture Nav',
			'locations' => array( 'primary', 'secondary' ),
			'items'     => array( 'LS Nav Item' ),
		),
	),

	// -----------------------------------------------------------------------
	// Fixtures owned by ANOTHER blueprint that this one asserts against.
	// Listed explicitly so a core-structures change that breaks a test here
	// is traceable to its cause instead of looking like a Detector regression.
	//
	// Only ONE such dependency, and it is deliberate: V22 needs a real
	// non-singular archive with posts, and core-structures already seeds a
	// populated `department` taxonomy. Re-seeding a private archive would
	// duplicate their surface for no gain.
	// -----------------------------------------------------------------------
	'foreign_dependencies' => array(
		'core-structures' => array(
			'department:sales' => 'Non-singular archive for V22 featured-image config-replay (/department/sales/). Needs >=1 published post assigned, else the archive 404s and the test vacuously passes.',
		),
	),
);
