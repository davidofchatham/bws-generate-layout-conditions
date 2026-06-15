<?php
/**
 * THROWAWAY PROTOTYPE — Approach B (convert CSS-only disable toggles to PHP suppression).
 * Deploy via Code Snippets (or mu-plugin) on STAGING only. Not part of the plugin.
 *
 * Tests whether the 3 CSS-only / partially-CSS metabox toggles can be suppressed in PHP
 * keyed on `_generate-disable-*` post-meta, replacing the neutralized inline CSS.
 *   - Secondary Nav      (_generate-disable-secondary-nav)  -> has_nav_menu filter (GP's own pattern)
 *   - Featured Image     (_generate-disable-post-image)     -> remove_action x2
 *   - #mobile-header     (_generate-disable-nav)            -> remove_action (Menu Plus wrapper)
 *
 * Refs: ROADMAP "Approach B"; architecture.md V14/V24/V25; V3 (queried-object id, singular guard).
 *
 * HOW TO READ RESULTS:
 *   Each suppression emits an HTML comment marker into the page source (View Source / Ctrl-U).
 *   - "BWS-PROTO-B: secondary-nav suppressed"  => filter applied
 *   - "BWS-PROTO-B: featured-image suppressed"  => actions removed
 *   - "BWS-PROTO-B: mobile-header suppressed"   => action removed
 *   Then visually confirm the element is GONE while the corresponding metabox toggle is ON,
 *   AND that it still RENDERS when the toggle is OFF (no over-suppression).
 *
 * IMPORTANT: To isolate Approach B you must let GP's CSS NOT hide these.
 *   Option 1 (cleanest): also enable the plugin's neutralize on staging so the CSS is gone,
 *                        proving PHP alone suppresses.
 *   Option 2: temporarily, set BWS_PROTO_B_ASSUME_NEUTRALIZED false and just confirm markers
 *             fire + nothing double-breaks; CSS will still hide, so you can't see re-show.
 *
 * GB PRO COMPOSITION CHECK (the risk that needs a live site):
 *   On a page that ALSO has a GB Pro Layout Element condition targeting one of these elements,
 *   confirm: no PHP warning, no double-removed-action notice, element resolves once (OR semantics).
 */

if ( ! defined( 'BWS_PROTO_B' ) ) {
	define( 'BWS_PROTO_B', true ); // master gate — set false to disable entirely
}

if ( BWS_PROTO_B ) {

	add_action(
		'wp',
		function () {
			if ( is_admin() || ! is_singular() ) {
				return;
			}

			$id = get_queried_object_id(); // V3: never get_the_ID() in this context
			if ( ! $id ) {
				return;
			}

			$markers = array();

			// --- Secondary Nav ------------------------------------------------
			// GP's own Layout Element uses exactly this filter (class-layout.php:534).
			// Render gate is `if ( has_nav_menu('secondary') )` (secondary-nav/functions.php:702).
			if ( get_post_meta( $id, '_generate-disable-secondary-nav', true ) ) {
				add_filter(
					'has_nav_menu',
					function ( $has, $location ) {
						return 'secondary' === $location ? false : $has;
					},
					10,
					2
				);
				$markers[] = 'secondary-nav suppressed';
			}

			// --- Featured Image ----------------------------------------------
			// No PHP path in GP's metabox module; these are the render hooks
			// (featured-images.php:96 single page header, :114 inside single).
			if ( get_post_meta( $id, '_generate-disable-post-image', true ) ) {
				remove_action( 'generate_after_header', 'generate_featured_page_header', 10 );
				remove_action( 'generate_before_content', 'generate_featured_page_header_inside_single', 10 );
				$markers[] = 'featured-image suppressed';
			}

			// --- Mobile-header wrapper (the Primary-nav CSS-load-bearing case, V25) -
			// _setup only empties the toggle inside; the <nav id="mobile-header"> wrapper
			// survives. remove_action drops the wrapper outright (generate-menu-plus.php:1070).
			if ( get_post_meta( $id, '_generate-disable-nav', true ) ) {
				remove_action( 'generate_after_header', 'generate_menu_plus_mobile_header', 5 );
				$markers[] = 'mobile-header suppressed';
			}

			if ( $markers ) {
				add_action(
					'wp_head',
					function () use ( $markers ) {
						foreach ( $markers as $m ) {
							echo "\n<!-- BWS-PROTO-B: " . esc_html( $m ) . " -->\n";
						}
					},
					0
				);
			}
		},
		60 // after generate_disable_elements_setup() at wp:50
	);
}
