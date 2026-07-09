<?php
/**
 * Test bootstrap — stubs the minimal WP/GB Pro surface the plugin files touch
 * at load time, then requires the plugin's includes. All runtime WP/GP reads
 * go through the environment seam (T9) and are served by the in-memory fake;
 * the stubs below only satisfy file-scope loading (guards, registration calls).
 */

define( 'ABSPATH', __DIR__ . '/' );

// Disable the body-class feature's file-scope add_action; the filter function
// itself is still tested directly.
define( 'BWS_GP_LAYOUT_STATE_BODY_CLASSES', false );

function __( $text, $domain = 'default' ) {
	return $text;
}

function add_action( ...$args ) {}
function add_filter( ...$args ) {}

/**
 * GB Pro stubs — enough for class-condition.php to load and register.
 */
class GenerateBlocks_Pro_Conditions_Registry {
	public static $registered = array();

	public static function register( $slug, $args, $class ) {
		self::$registered[ $slug ] = array(
			'args'  => $args,
			'class' => $class,
		);
	}
}

abstract class GenerateBlocks_Pro_Condition_Abstract {}

$plugin_dir = dirname( __DIR__ );

require $plugin_dir . '/includes/class-environment.php';
require $plugin_dir . '/includes/class-detector.php';
require $plugin_dir . '/includes/class-condition.php';
require $plugin_dir . '/includes/class-body-classes.php';

require __DIR__ . '/class-fake-environment.php';
