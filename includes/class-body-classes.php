<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Emit gp-no-* body classes for the 7 disabled states (V8).
 *
 * GP emits nothing positive for these states — the plugin fills the gap.
 * No sidebar class, no container class — GP emits those natively (C5, V8).
 *
 * Vocabulary: negative (disabled state) — intentionally diverges from
 * condition's positive "Active" vocabulary (V9).
 *
 * Hooked at wp:110 so Layout Elements (wp:100) have already mutated hook state
 * before the Detector is first called (V15).
 *
 * Define BWS_GP_LAYOUT_STATE_BODY_CLASSES as false before plugins_loaded
 * to disable this feature entirely.
 */

if ( ! defined( 'BWS_GP_LAYOUT_STATE_BODY_CLASSES' ) ) {
	define( 'BWS_GP_LAYOUT_STATE_BODY_CLASSES', true );
}

if ( BWS_GP_LAYOUT_STATE_BODY_CLASSES ) {
	add_action( 'wp', 'bws_glc_schedule_body_classes', 110 );
}

function bws_glc_schedule_body_classes() {
	add_filter( 'body_class', 'bws_glc_add_body_classes' );
}

/**
 * @param string[] $classes
 * @return string[]
 */
function bws_glc_add_body_classes( $classes ) {
	$states = BWS_GP_Layout_Detector::states();

	$map = array(
		'header'         => 'gp-no-header',
		'footer'         => 'gp-no-footer',
		'primary_nav'    => 'gp-no-primary-nav',
		'secondary_nav'  => 'gp-no-secondary-nav',
		'top_bar'        => 'gp-no-top-bar',
		'featured_image' => 'gp-no-featured-image',
		'content_title'  => 'gp-no-content-title',
	);

	foreach ( $map as $key => $class ) {
		if ( ! empty( $states[ $key ] ) ) {
			$classes[] = $class;
		}
	}

	return array_unique( $classes );
}
