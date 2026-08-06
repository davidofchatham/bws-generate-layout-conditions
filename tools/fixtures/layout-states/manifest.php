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
 *
 * SINCE v8 it also requires GB PRO, for the `gblocks_condition` posts and the
 * blocks that reference them. That is a second consumer with its own storage
 * (`_gb_conditions`) and its own silent-inertness modes — see `conditions`
 * below.
 */

/*
 * The conditioned marker blocks (v8), shared verbatim by every fixture in the
 * combination table so a difference in the rendered response is attributable to
 * the RULE and nothing else.
 *
 * Three blocks, and the first is not decoration. `ls-marker-control` carries no
 * condition, so its presence proves the content of this fixture reached the
 * response at all; without it every absence assertion below could pass because
 * the page rendered nothing — B9's failure mode, one layer out. The render
 * harness hard-aborts on it rather than recording a FAIL.
 *
 * `gbBlockCondition` is the GB Pro block attribute: the ID of a published
 * `gblocks_condition` post, as a STRING (registered `type => 'string'`,
 * `dist/block-conditions.js` writes a string). GB Pro reads it on `render_block`
 * for EVERY block type (`includes/extend/block-conditions.php:26`), so a core
 * paragraph exercises exactly the same evaluation path a GenerateBlocks block
 * would — with none of the markup-validity surface. The {{condition:slug}}
 * placeholder is resolved to the real post ID by seed.php, for the same reason
 * page IDs are: hardcoding one would make the manifest environment-specific.
 *
 * NO `gbBlockConditionInvert`: the invert flag flips the result AFTER the rule
 * is evaluated, so an inverted block would still be green with the rule
 * answering backwards. Both directions are covered by choosing fixtures where
 * the rule genuinely differs, never by inverting the reading.
 */
$ls_marker_blocks = '<!-- wp:paragraph --><p>ls-marker-control</p><!-- /wp:paragraph -->'
	. '<!-- wp:paragraph {"gbBlockCondition":"{{condition:ls-cond-image-active}}"} --><p>ls-marker-image-active</p><!-- /wp:paragraph -->'
	. '<!-- wp:paragraph {"gbBlockCondition":"{{condition:ls-cond-slot-active}}"} --><p>ls-marker-slot-active</p><!-- /wp:paragraph -->';

return array(
	'blueprint' => 'layout-states',
	'version'   => 8,

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
		// Covers the `gblocks_condition` posts added in v8 as well — same
		// prefix, same namespace claim, and GB Pro's CPT is no more ours than
		// `gp_elements` is.
		'slug_prefix'   => 'ls-',
		'options'       => array( 'generate_menu_plus_settings', 'generate_blog_settings' ),
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
		// Blog, added v6 for B8. NOT a new fixture surface — a pin on one that
		// was an unread variable. The featured image has two render paths and
		// the Blog module decides which: active, it removes the theme's
		// page-header actions unconditionally at wp:50 (blog/functions/
		// images.php:164-165) and renders its own callback instead. Testbed had
		// it OFF, so every featured-image assertion in this blueprint was
		// exercising the theme path only, and the plugin's suppression could
		// cover half the surface and still pass green (B8). Pinned ON because
		// that is the deployed shape (measured on `hargrave`, V33) and the
		// harder of the two — with it on, the theme path is provably dead, so
		// any image that renders is the blog path.
		'generate_package_blog',
	),

	// Non-GP consumers the v8 fixtures need. GB Pro registers the
	// `gblocks_condition` post type and the `render_block` filter that reads
	// `gbBlockCondition`; without it every conditioned marker block renders
	// unconditionally, which is indistinguishable from "the rule said yes" and
	// would turn the whole combination table green while proving nothing.
	// seed.php hard-errors, same as for the GP modules above.
	'requires_post_types' => array(
		'gblocks_condition' => 'GenerateBlocks Pro — block conditions (v8 marker blocks)',
	),

	// -----------------------------------------------------------------------
	// GB Pro conditions (`gblocks_condition`), added v8.
	//
	// This is the FIRST fixture surface in the blueprint that belongs to GB Pro
	// rather than GP Premium, and it is what makes the authoring workflow
	// observable: a block carries `gbBlockCondition => <this post's ID>`, GB Pro
	// looks the post up on `render_block`, reads `_gb_conditions`, and calls this
	// plugin's `evaluate()` through its registry. Every link in that chain is
	// silent when broken — an unpublished condition post, an unregistered type
	// slug, or a rule slug the type does not answer all make the block render
	// unconditionally, which looks exactly like "the rule said yes".
	//
	// post_status MUST be 'publish': `check_block_conditions()` returns the block
	// content untouched for any other status (block-conditions.php:77), so a
	// draft condition is not a failed condition — it is NO condition.
	//
	// `_gb_conditions` is stored in the shape GB Pro's own sanitizer produces
	// (`GenerateBlocks_Pro_Conditions::sanitize_conditions`), which runs on the
	// `update_post_meta` path too because the meta is registered with it
	// (class-conditions-post-type.php:242). So the fixture is byte-identical to
	// what the block editor would write over REST, and verify.php re-runs the
	// sanitizer over the stored value to prove it.
	//
	// One condition per rule, one rule per group, group logic AND, top-level OR:
	// the shape the UI produces for a single rule. Nothing here tests GB Pro's
	// group algebra — that is GB Pro's own surface, and folding both rules into
	// one condition post would make a single failure ambiguous between them.
	//
	// KEY TRAP, and it is B6's shape on a new consumer: `type` must be the
	// registry slug this plugin registers (`gp_theme_element`, V27 — persisted
	// data, frozen) and `rule` must be a key of that type's `get_rules()`. Both
	// are plain strings that GB Pro looks up and silently no-ops on when unknown:
	// `evaluate_single_condition()` returns false for an unregistered type, and
	// `BWS_GP_Theme_Element_Condition::evaluate()` returns `$match = false` for an
	// unknown rule — so a typo does not fail loudly, it inverts the fixture into
	// "always hidden". verify.php §8 checks both against the live registry.
	// -----------------------------------------------------------------------
	'conditions' => array(

		// The per-post Disable Elements toggle (ADR-0006). The author-facing
		// question: "has the editor switched the featured image off for this post?"
		'ls-cond-image-active' => array(
			'post_title' => 'LS: Featured Image Active (post setting)',
			'post_name'  => 'ls-cond-image-active',
			'gb_conditions' => array(
				'logic'  => 'OR',
				'groups' => array(
					array(
						'logic'      => 'AND',
						'conditions' => array(
							array(
								'type'     => 'gp_theme_element',
								'rule'     => 'featured_image_active',
								'operator' => 'is',
								'value'    => '',
							),
						),
					),
				),
			),
		),

		// The theme's own slot (V34, issue #4). Different subject, and the whole
		// point of the combination table is that these two answers come apart.
		'ls-cond-slot-active' => array(
			'post_title' => 'LS: Featured Image Slot Active (theme)',
			'post_name'  => 'ls-cond-slot-active',
			'gb_conditions' => array(
				'logic'  => 'OR',
				'groups' => array(
					array(
						'logic'      => 'AND',
						'conditions' => array(
							array(
								'type'     => 'gp_theme_element',
								'rule'     => 'featured_image_slot_active',
								'operator' => 'is',
								'value'    => '',
							),
						),
					),
				),
			),
		),
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
				array( 'rule' => 'taxonomy:department', 'object' => '{{term:department:sales}}' ),
			),
		),

		// --- Layout Element: featured-image KILL SWITCH on a singular page ---
		// Added v8 (issue #5), and it is the one scenario nothing covered.
		//
		// The slot rule (V34) exists to answer "is GeneratePress itself drawing a
		// featured image here?", and the interesting answer is NO on a singular
		// page where the post setting says the image is ACTIVE. Every other route
		// to that state pairs the removal with something that draws an image in
		// its place: a Page Hero (ls-page-hero) or a Content Template. This is the
		// bare case — the callbacks are gone and NOTHING replaces them, which is
		// what a Layout Element's "Disable featured image" toggle does on a real
		// site and what no deployed instance in the survey exhibited.
		//
		// Mechanically identical to the Page Hero's removal and to this plugin's
		// own suppression: GP's layout element removes the SAME five callbacks
		// (class-layout.php:316-320) — the three Blog-module positions plus the
		// theme page-header pair. So the fixture is a real test of the slot rule's
		// both-paths read (V34 part 2), not just of one branch.
		//
		// SEPARATE from ls-el-layout-featured-archive, deliberately. That one is a
		// regression guard on an ARCHIVE, where the post-setting rule short-circuits
		// before reading anything (ADR-0006) and the slot rule is a constant false.
		// Here both rules are live and must DISAGREE — post setting active, slot
		// not — which is the combination the whole ticket is about, and it needs a
		// singular target and a page of its own.
		//
		// The target page carries a thumbnail (see `ls-page-featured-kill`): without
		// one, "no page-header-image in the response" would be true whether or not
		// the element applied, and the GP-side half of this fixture would prove
		// nothing.
		'ls-el-layout-featured-kill' => array(
			'post_title'  => 'LS: Layout — disable featured image (singular kill switch)',
			'post_name'   => 'ls-el-layout-featured-kill',
			'post_status' => 'publish',
			'meta'        => array(
				'_generate_element_type'           => 'layout',
				// The LAYOUT element key. Not _generate-disable-post-image (the
				// per-post metabox key) — pointing this at that one leaves GP's
				// callbacks attached and the fixture silently stops disabling
				// anything, which is the mutation verify.php §8 and
				// render-surface.sh §9 are checked against.
				'_generate_disable_featured_image' => 'true',
			),
			'display_conditions' => array(
				array( 'rule' => 'post:page', 'object' => '{{ls-page-featured-kill}}' ),
			),
		),

		// --- Block Element: the conditioned markers, on the ARCHIVE (v8) -----
		// The archive is a row of the combination table like any other, and it is
		// the one page type with no post_content to carry the marker blocks. A
		// hook Block Element is how an author puts blocks on an archive, so this
		// is the real workflow rather than a test-only construct.
		//
		// `generate_before_main_content` fires on archive.php:22 (and page.php,
		// index.php, 404.php), and it is in GP's own hook dropdown
		// (class-elements-helper.php, 'content' group) — so this is a fixture the
		// admin UI can produce, which is the standing bar here.
		//
		// SCOPED TO THE ARCHIVE, not site-wide. Every singular row carries the
		// same three markers in its own post_content, and a site-wide element
		// would render a second copy of each on those pages — harmless to a
		// presence check, fatal to reading a failure, since "ls-marker-slot-active
		// is present" would no longer say WHICH surface produced it.
		//
		// `_generate_block_type => 'hook'` with `_generate_hook` set: the pair
		// GP's editor writes for a plain hook element (class-metabox.php:334).
		// Both keys matter — `hook` is not one of the types the loader's switch
		// resolves a hook for, so with `_generate_hook` absent it returns before
		// registering anything (B9).
		'ls-el-block-archive-markers' => array(
			'post_title'  => 'LS: Block — conditioned markers (archive)',
			'post_name'   => 'ls-el-block-archive-markers',
			'post_status' => 'publish',
			'meta'        => array(
				'_generate_element_type' => 'block',
				'_generate_block_type'   => 'hook',
				'_generate_hook'         => 'generate_before_main_content',
			),
			'display_conditions' => array(
				array( 'rule' => 'taxonomy:department', 'object' => '{{term:department:sales}}' ),
			),
			'post_content' => $ls_marker_blocks,
		),

		// --- Layout Element: content title on a NON-SINGULAR archive (V31) --
		// Added v4 for the one claim the in-memory fake structurally cannot
		// test: that GP genuinely leaves the archive HEADING standing when a
		// Layout Element disables the content title. The fake can encode that
		// belief; only a rendered archive can falsify it.
		//
		// The mechanism (class-layout.php:324) is a single unguarded
		// add_filter( 'generate_show_title', '__return_false' ) — and on an
		// archive that filter gates the ITEM titles inside loop cards
		// (content.php:35), not the <h1 class="page-title"> heading, which is a
		// different hook entirely (generate_archive_title, archive.php:34). So
		// one element produces both halves of the assertion: item titles gone,
		// heading intact.
		//
		// Targets the SAME core-structures archive as the featured-image fixture
		// above rather than seeding another — see `foreign_dependencies`. Kept as
		// its own element, not folded into that one, so the two archive claims
		// fail independently and name their own cause.
		//
		// KEY TRAP (survey rows 3 vs 4): this is the LAYOUT element key,
		// _generate_disable_content_title. The Page Hero Block element uses
		// _generate_disable_title — a different key for what the UI presents as
		// the same toggle, and a fixture written against the wrong one reports
		// the opposite of the truth.
		'ls-el-layout-title-archive' => array(
			'post_title'  => 'LS: Layout — disable content title (archive)',
			'post_name'   => 'ls-el-layout-title-archive',
			'post_status' => 'publish',
			'meta'        => array(
				'_generate_element_type'          => 'layout',
				'_generate_disable_content_title' => 'true',
			),
			'display_conditions' => array(
				array( 'rule' => 'taxonomy:department', 'object' => '{{term:department:sales}}' ),
			),
		),

		// --- Layout Element: SECONDARY NAV, singular + archive (V32/T17) ---
		// Added v5. T17 moved secondary nav from post-meta-only to config-replay,
		// and it shipped covered by the PHPUnit fake alone. Two claims live under
		// that fake and neither is checkable from inside it:
		//
		//   1. The KEY. GP's Layout Element metabox writes
		//      _generate_disable_secondary_navigation (class-metabox.php:1211,
		//      sanitize map :1851) while the per-post metabox layer writes
		//      _generate-disable-secondary-nav. Different WORDS, not one word with
		//      two separators — `navigation` vs `nav`. The fake answers whatever
		//      key it is handed, so a wrong key there is invisible; in production
		//      it makes the whole layer silently inert. Exactly B6's shape.
		//   2. The UNGATED claim. GP adds its has_nav_menu filter with no
		//      is_singular() guard (class-layout.php:311), so the element must
		//      disable on an ARCHIVE too. The Detector's replay branch is
		//      deliberately ungated to match.
		//
		// ONE element covering BOTH page types, rather than two: the point is that
		// a single unguarded element reaches both, and two elements would prove
		// only that two elements work. Targets the same core-structures archive as
		// the other two archive fixtures (see `foreign_dependencies`).
		//
		// The singular target is its OWN page and must stay that way. Pointing
		// this at ls-page-baseline would strip #secondary-navigation from the
		// control, and section 0 of render-surface.sh hard-aborts on exactly that
		// — the baseline is the proof the marker renders at all.
		'ls-el-layout-secondary-nav' => array(
			'post_title'  => 'LS: Layout — disable secondary nav (singular + archive)',
			'post_name'   => 'ls-el-layout-secondary-nav',
			'post_status' => 'publish',
			'meta'        => array(
				'_generate_element_type'                 => 'layout',
				// NOT _generate-disable-secondary-nav. See note 1 above.
				'_generate_disable_secondary_navigation' => 'true',
			),
			'display_conditions' => array(
				array( 'rule' => 'post:page', 'object' => '{{ls-page-layout-secondary-nav}}' ),
				array( 'rule' => 'taxonomy:department', 'object' => '{{term:department:sales}}' ),
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
		// Still true as of the 2026-07-29 writer survey, with one addition: the
		// legacy Header Element has no title TOGGLE, but it does suppress the
		// title by another route — a literal {{post_title}} in its content
		// (class-hero.php:889, survey row 5). Same for the Page Header module
		// (row 6). Neither is fixtured; see the fixtures README.
		//
		// Key trap (survey row 3 vs 4): the LAYOUT element uses a DIFFERENT key
		// for this same signal — _generate_disable_content_title
		// (class-layout.php:217) — while the BLOCK element below uses
		// _generate_disable_title. Featured image shares one key across both.
		//
		// What this pins, and it has INVERTED since it was written: the Hero
		// EMBEDS the image/title itself, so it removes the same hooks the
		// Detector used to read, and v1 reported both as "disabled" while both
		// were visibly active via the Hero. The fixture existed to CHARACTERIZE
		// that. Both meanings are now resolved — title by ADR-0005, image by
		// ADR-0006 — and neither signal reads a hook, so this is a REGRESSION
		// GUARD for both halves: `gp-no-content-title` and `gp-no-featured-image`
		// must both be ABSENT on ls-page-hero. render-surface.sh §8 asserts the
		// image half on rendered output.
		// INERT FROM v1 TO v6 (found 2026-08-06, third of the B6/B7 family).
		// A block element resolves its hook from `_generate_hook` for every type
		// the switch does not name, and `page-hero` is NOT in that switch
		// (class-block.php:110-140). With the key absent the loader hits
		// `if ( ! $hook ) return;` at :165 — BEFORE it registers either
		// `build_hook` or the `wp:100` `remove_elements` callback. So this fixture
		// rendered nothing and removed nothing: the V21 ambiguity it was written
		// to characterize never once occurred on `ls-page-hero`. Nothing caught it
		// because nothing asserted against the page — the fixture existed, was
		// published, carried both real toggle keys, and was invisible.
		//
		// `generate_after_header` is what the editor itself writes: selecting the
		// Page Hero block type sets `_generate_hook: 'generate_after_header'` in
		// the same update (dist/block-elements.js). Seeding any other hook would
		// be a fixture the admin UI cannot produce.
		'ls-el-page-hero' => array(
			'post_title'  => 'LS: Page Hero — disable title + featured image',
			'post_name'   => 'ls-el-page-hero',
			'post_status' => 'publish',
			'meta'        => array(
				'_generate_element_type'           => 'block',
				'_generate_block_type'             => 'page-hero',
				// Load-bearing, not cosmetic — see the note above.
				'_generate_hook'                   => 'generate_after_header',
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
			// v8: the only row of the combination table where BOTH rules answer
			// true, so it is the control proving each conditioned marker renders
			// at all. Every absence assertion in render-surface.sh §9 is read
			// against this page's presences.
			'post_content'   => $ls_marker_blocks,
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

		// V32/T17 — the singular half of the secondary-nav element's reach.
		// Its own page, not baseline: see the note on ls-el-layout-secondary-nav.
		'ls-page-layout-secondary-nav' => array(
			'post_title' => 'LS: Layout Element disables secondary nav',
			'post_name'  => 'ls-page-layout-secondary-nav',
		),

		// V21 Page Hero ambiguity.
		'ls-page-hero' => array(
			'post_title' => 'LS: Page Hero (title + featured image ambiguity)',
			'post_name'  => 'ls-page-hero',
			// Added v8, and load-bearing for the same reason it is on the metabox
			// page. This is the "relocation" row of the combination table — post
			// setting active, theme slot not — and the GP-side half of that claim
			// is that no page-header-image renders here. With no thumbnail that is
			// true on a page where the Hero never ran either, so the assertion
			// would pass for the wrong reason.
			'featured_image' => true,
			'post_content'   => $ls_marker_blocks,
		),

		// --- The kill switch (v8, issue #5) ---------------------------------
		// A singular page where a Layout Element switches the featured image off
		// and NOTHING draws one in its place — no Page Hero, no Content Template,
		// no second element. The one combination no fixture and no deployed site
		// exhibited, and the case the slot rule exists to serve.
		//
		// The thumbnail is what makes it falsifiable: GP would render a
		// page-header-image here if the element were not applying, so the absence
		// of that wrapper in the response is a real observation rather than the
		// default state of a page with no image. Its presence in the post's own
		// meta still leaks into the response through the SEO plugin's og:image, so
		// the render harness can prove the thumbnail is seeded without being able
		// to see the image render — see render-surface.sh §9.
		//
		// Nothing else is set on this page: no disable_meta, so the per-post rule
		// must still report the image ACTIVE here. That divergence — post setting
		// active, slot not — is the whole content of the row.
		'ls-page-featured-kill' => array(
			'post_title'     => 'LS: Layout Element kill switch (featured image, nothing replaces it)',
			'post_name'      => 'ls-page-featured-kill',
			'featured_image' => true,
			'post_content'   => $ls_marker_blocks,
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
			// v8: the "post setting disabled" row. Both markers must be ABSENT
			// here — the image one because the toggle is set, the slot one
			// because this plugin's own wp:60 suppression removed the five
			// callbacks the slot rule reads. The second is the render-level proof
			// that "post setting disabled, slot active" is unreachable (V34).
			'post_content'   => $ls_marker_blocks,
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

		// Blog module image settings (v6, B8). Every fixture page here is a
		// `page`, so `page_post_image*` is the live pair; the `single_*` pair is
		// pinned too so a stray Customizer change on the testbed cannot move the
		// surface without a reseed noticing.
		//
		// `inside-content` is chosen, not inherited. GP ships `above-content` for
		// pages (blog/functions/defaults.php:35), which puts the blog image on
		// `generate_after_header` — the SAME hook the theme's page-header path
		// uses, and with near-identical markup. The two paths would then be
		// indistinguishable in the response body, and the render harness could
		// not tell which one it was asserting against. At `inside-content` the
		// blog path renders on `generate_before_content`, inside `#content`,
		// where the theme path never appears — so a positional check on the
		// baseline proves the blog path is the live one (render-surface.sh §0).
		//
		// Both `*_post_image` flags must stay TRUE: false makes the module render
		// nothing, which would look exactly like a working suppression.
		'generate_blog_settings' => array(
			'page_post_image'            => true,
			'page_post_image_position'   => 'inside-content',
			'single_post_image'          => true,
			'single_post_image_position' => 'inside-content',
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
	// duplicate their surface for no gain. Since v4 the V31 page-title check
	// reuses the same archive, for the same reason.
	// -----------------------------------------------------------------------
	'foreign_dependencies' => array(
		'core-structures' => array(
			'department:sales' => 'Non-singular archive for V22 featured-image config-replay, the V31 page-title render check, and (v8) the archive row of the featured-image combination table (/department/sales/). Needs >=1 published post assigned, else the archive 404s and ALL THREE tests vacuously pass — the V31 and v8 assertions are absence checks against the response body, so a 404 satisfies them for the wrong reason. The v8 row is the one with a live control: ls-el-block-archive-markers renders an unconditioned marker there, so a 404 hard-aborts the render harness instead of passing.',
		),
	),
);
