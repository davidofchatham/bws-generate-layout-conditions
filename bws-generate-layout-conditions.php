<?php
/**
 * Plugin Name:       GP Layout Conditions by BWS
 * Plugin URI:        https://github.com/davidofchatham/bws-generate-layout-conditions
 * Description:       Adds Theme condition types to GB Pro so blocks can be hidden if a corresponding theme element is disabled via Layout or post-level settings.
 * Version:           0.2.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            David Mitchell (Bridge Web Solutions) and Claude AI
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins:  gp-premium
 * Text Domain:       bws-generate-layout-conditions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BWS_GLC_VERSION',  '0.2.1' );
define( 'BWS_GLC_DIR',      plugin_dir_path( __FILE__ ) );
define( 'BWS_GLC_URL',      plugin_dir_url( __FILE__ ) );
define( 'BWS_GLC_BASENAME', plugin_basename( __FILE__ ) );

/*
 * CSS-neutralize — loaded at FILE SCOPE, deliberately, and this is the only
 * point at which it works (V12).
 *
 * generate_disable_elements() is claimed by whichever definition runs first:
 * both GP Premium's and ours are function_exists-guarded. GP Premium requires
 * its Disable Elements module at FILE SCOPE during plugin load
 * (gp-premium.php:69 -> disable-elements/generate-disable-elements.php ->
 * functions/functions.php), which is strictly earlier than ANY hook. So loading
 * this on plugins_loaded — as this plugin did through 0.2.0 — always lost the
 * race, and the neutralize silently never ran.
 *
 * That regression was invisible to CLI testing: under `wp eval` there is no
 * $post, so GP's implementation returns '' anyway and the wrong function looks
 * correct. Only a rendered HTTP response distinguishes them (T11).
 *
 * Winning the race depends on this plugin being loaded before gp-premium in
 * active_plugins. That holds today by name order but is not guaranteed, so
 * class-disable-elements.php verifies it took effect rather than assuming, and
 * reports in admin when it did not.
 */
require_once BWS_GLC_DIR . 'includes/class-disable-elements.php';

add_action( 'plugins_loaded', 'bws_glc_bootstrap', 5 );
add_action( 'plugins_loaded', 'bws_glc_load_condition', 20 );

function bws_glc_bootstrap() {
	require_once BWS_GLC_DIR . 'includes/class-environment.php';
	require_once BWS_GLC_DIR . 'includes/class-detector.php';
	require_once BWS_GLC_DIR . 'includes/class-body-classes.php';
}

function bws_glc_load_condition() {
	if ( class_exists( 'GenerateBlocks_Pro_Conditions_Registry' ) ) {
		require_once BWS_GLC_DIR . 'includes/class-condition.php';
	}

	// PUC update checker — only in admin/CLI contexts to avoid front-end overhead.
	if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		require_once BWS_GLC_DIR . 'libs/plugin-update-checker/plugin-update-checker.php';

		$update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/davidofchatham/bws-generate-layout-conditions',
			__FILE__,
			'bws-generate-layout-conditions'
		);

		$update_checker->getVcsApi()->enableReleaseAssets();
	}
}
