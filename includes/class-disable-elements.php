<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Pre-define generate_disable_elements() to return '' so GP Premium's CSS path
 * emits no display:none on section wrappers. The function is function_exists-guarded
 * in GP Premium so the first definition wins the race (V12).
 *
 * MUST be required at FILE SCOPE from the main plugin file, never from a hook.
 * GP Premium requires its Disable Elements module during plugin load, before any
 * hook fires — so a plugins_loaded require (this plugin's behaviour through
 * 0.2.0) always lost, and the neutralize never ran on any request. See the note
 * in bws-generate-layout-conditions.php.
 *
 * Do NOT touch generate_disable_elements_setup() — the hook-removal path must stay
 * intact so GP's native section disabling still works.
 */
if ( ! function_exists( 'generate_disable_elements' ) ) {
	function generate_disable_elements() {
		return '';
	}
}

/*
 * PHP suppression for the toggles the neutralize left CSS-only (T10 / V14, V24, V25).
 *
 * The neutralize above deletes GP's display:none rules. For toggles GP also
 * removes in PHP that costs nothing — the markup was already gone. For three it
 * is a regression: the CSS was the ONLY thing hiding them, so neutralizing it
 * re-exposes content the user asked to hide (V24, V25). This closes that by
 * removing the markup instead of hiding it, which is what the toggles should
 * have done in the first place.
 *
 * Keyed on the same `_generate-disable-*` metabox meta GP's own CSS path reads,
 * so the toggle semantics are unchanged — only the mechanism differs.
 *
 * Mechanism per surface, each matching what GP itself does elsewhere:
 *   - Secondary nav   -> has_nav_menu filter. GP's Layout Element uses this exact
 *                        filter with an identical body (class-layout.php:312,534).
 *                        Preferred over remove_action on the render: the same
 *                        gate gores the enqueue (secondary-nav/functions.php:40),
 *                        body classes (:837) and color scripts (:1181), so
 *                        filtering cleans up the satellite CSS/classes too rather
 *                        than orphaning them.
 *   - Featured image  -> remove_action x2 (featured-images.php:96 page header,
 *                        :114 inside-single). GP's metabox module has no PHP path
 *                        for this at all — hence the V24 regression.
 *   - #mobile-header  -> remove_action (generate-menu-plus.php:1070). V25: the
 *                        primary-nav toggle PHP-kills the SOURCE nav but leaves
 *                        the <nav id="mobile-header"> wrapper, gated only on
 *                        mobile_header !== 'disable' (:1082) and hidden by CSS.
 *
 * Priority 60 on `wp`: after GP's own metabox setup (generate_disable_elements_setup,
 * wp:50) and after the Elements loader (wp:10), while every target renders later
 * during the template (generate_after_header / generate_before_content). GP's
 * Layout Element does its equivalent work at wp:100, which is also later than the
 * render hooks bind but earlier than they fire.
 *
 * Composing with a GB Pro / GP Layout Element on the same surface is safe and
 * gives OR semantics for free: a second remove_action for an already-removed
 * callback returns false with no warning, and two has_nav_menu filters both
 * returning false are idempotent. Verified on testbed, not assumed.
 */
add_action( 'wp', 'bws_glc_suppress_css_only_disables', 60 );

function bws_glc_suppress_css_only_disables() {
	// V3: get_queried_object_id(), never get_the_ID() — this fires outside the
	// loop, where get_the_ID() reports whatever post was last touched.
	if ( is_admin() || ! is_singular() ) {
		return;
	}

	$id = get_queried_object_id();

	if ( ! $id ) {
		return;
	}

	if ( get_post_meta( $id, '_generate-disable-secondary-nav', true ) ) {
		add_filter( 'has_nav_menu', 'bws_glc_disable_secondary_nav', 10, 2 );
	}

	if ( get_post_meta( $id, '_generate-disable-post-image', true ) ) {
		remove_action( 'generate_after_header', 'generate_featured_page_header', 10 );
		remove_action( 'generate_before_content', 'generate_featured_page_header_inside_single', 10 );
	}

	if ( get_post_meta( $id, '_generate-disable-nav', true ) ) {
		remove_action( 'generate_after_header', 'generate_menu_plus_mobile_header', 5 );
	}
}

/**
 * Report the secondary nav location as unassigned, so GP skips rendering it.
 *
 * Mirrors GeneratePress_Premium_Layout::disable_secondary_navigation().
 *
 * @param bool   $has_nav_menu The existing value.
 * @param string $location     The location being checked.
 * @return bool
 */
function bws_glc_disable_secondary_nav( $has_nav_menu, $location ) {
	if ( 'secondary' === $location ) {
		return false;
	}

	return $has_nav_menu;
}

/**
 * Verify the neutralize actually took effect, and say so when it did not.
 *
 * Winning the definition race depends on this plugin appearing before gp-premium
 * in active_plugins. That is true by name order today, but nothing enforces it:
 * a folder rename, a must-use loader, or a plugin-order manager can reverse it.
 * When that happens the plugin keeps working in every other respect while
 * silently emitting the CSS it exists to suppress — the exact failure this file
 * already shipped once (invisible because CLI checks cannot see it).
 *
 * So: assert ownership rather than assume it. Admin-only, and only for users who
 * could act on it.
 */
add_action( 'admin_notices', 'bws_glc_disable_elements_notice' );

function bws_glc_disable_elements_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	if ( bws_glc_owns_disable_elements() ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
		esc_html__( 'GP Layout Conditions:', 'bws-generate-layout-conditions' ),
		esc_html__(
			'the CSS-neutralize is not active — GP Premium defined generate_disable_elements() first, so per-post Disable Elements toggles are still hiding content with CSS. This plugin must load before GP Premium. Check for a plugin-order override.',
			'bws-generate-layout-conditions'
		)
	);
}

/**
 * Whether OUR definition of generate_disable_elements() is the live one.
 *
 * Compares the declaring file rather than calling the function: GP's version
 * returns '' on any non-singular request (and on CLI, where there is no $post),
 * so a return-value check reports success even when GP owns the name. That false
 * negative is why the load-order bug survived undetected.
 *
 * @return bool
 */
function bws_glc_owns_disable_elements() {
	if ( ! function_exists( 'generate_disable_elements' ) ) {
		return false;
	}

	try {
		$reflection = new ReflectionFunction( 'generate_disable_elements' );
	} catch ( ReflectionException $e ) {
		return false;
	}

	return __FILE__ === $reflection->getFileName();
}
