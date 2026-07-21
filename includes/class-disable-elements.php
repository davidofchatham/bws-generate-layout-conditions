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
