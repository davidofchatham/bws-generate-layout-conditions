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

	// Class names come from the Detector's signal registry (T7) — negative
	// vocabulary stored there alongside the condition labels (V9).
	foreach ( BWS_GP_Layout_Detector::signals() as $key => $signal ) {
		if ( ! empty( $states[ $key ] ) ) {
			$classes[] = $signal['class'];
		}
	}

	return array_unique( $classes );
}
