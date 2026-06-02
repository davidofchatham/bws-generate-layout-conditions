<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Pre-define generate_disable_elements() to return '' so GP Premium's CSS path
 * emits no display:none on section wrappers. The function is function_exists-guarded
 * in GP Premium so the first definition wins the race (V12).
 *
 * Do NOT touch generate_disable_elements_setup() — the hook-removal path must stay
 * intact so GP's native section disabling still works.
 */
if ( ! function_exists( 'generate_disable_elements' ) ) {
	function generate_disable_elements() {
		return '';
	}
}
