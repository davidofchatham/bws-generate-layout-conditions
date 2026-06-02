<?php
/**
 * Plugin Name:       BWS GP Layout Conditions
 * Plugin URI:        https://github.com/bridgewebsolutions/bws-generate-layout-conditions
 * Description:       Makes GeneratePress disable states and sidebar layout usable by GenerateBlocks Pro Block Elements.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Bridge Web Solutions
 * Author URI:        https://bridgewebsolutions.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins:  gp-premium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BWS_GLC_VERSION',  '0.1.0' );
define( 'BWS_GLC_DIR',      plugin_dir_path( __FILE__ ) );
define( 'BWS_GLC_URL',      plugin_dir_url( __FILE__ ) );
define( 'BWS_GLC_BASENAME', plugin_basename( __FILE__ ) );

add_action( 'plugins_loaded', 'bws_glc_bootstrap', 5 );
add_action( 'plugins_loaded', 'bws_glc_load_condition', 20 );

function bws_glc_bootstrap() {
	require_once BWS_GLC_DIR . 'includes/class-disable-elements.php';
	require_once BWS_GLC_DIR . 'includes/class-detector.php';
	require_once BWS_GLC_DIR . 'includes/class-body-classes.php';
}

function bws_glc_load_condition() {
	if ( class_exists( 'GenerateBlocks_Pro_Conditions_Registry' ) ) {
		require_once BWS_GLC_DIR . 'includes/class-condition.php';
	}

	// PUC update checker — only in admin/CLI contexts to avoid front-end overhead.
	if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		require_once BWS_GLC_DIR . 'plugin-update-checker/plugin-update-checker.php';

		$update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/bridgewebsolutions/bws-generate-layout-conditions',
			__FILE__,
			'bws-generate-layout-conditions'
		);

		$update_checker->getVcsApi()->enableReleaseAssets();
	}
}
