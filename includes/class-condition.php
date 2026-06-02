<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * GB Pro custom condition: gp_layout_state
 *
 * Modeled on class-condition-device.php (V10): no value field, operators is/is_not.
 * 11 rules — 7 disable-state booleans + 4 sidebar-layout enum matches (V11).
 *
 * evaluate() discards $context['post_id'] — page-level state, not loop-item (V6).
 * "Active" = not-disabled-by-config, NOT actual-render. Never consult
 * has_post_thumbnail() or GP's featured-image-active class (V7).
 *
 * Self-gated: this file is only required() when GB Pro is present (see bootstrap).
 * Runtime class_exists guard below is an extra safety net (V13).
 */

if ( ! class_exists( 'GenerateBlocks_Pro_Conditions_Registry' ) ) {
	return;
}

add_action( 'generateblocks_register_conditions', 'bws_glc_register_condition' );

function bws_glc_register_condition() {
	GenerateBlocks_Pro_Conditions_Registry::register(
		'gp_layout_state',
		array(
			'label'     => __( 'GP Layout State', 'bws-generate-layout-conditions' ),
			'operators' => array( 'is', 'is_not' ),
		),
		'BWS_GP_Layout_State_Condition'
	);
}

class BWS_GP_Layout_State_Condition extends GenerateBlocks_Pro_Condition_Abstract {

	/**
	 * Evaluate the condition.
	 *
	 * @param string $rule     Rule key (e.g. 'header_active').
	 * @param string $operator 'is' or 'is_not'.
	 * @param mixed  $value    Ignored — no value field (V10).
	 * @param array  $context  Ignored — page-level state, not loop-item (V6).
	 * @return bool
	 */
	public function evaluate( $rule, $operator, $value, $context = array() ) {
		$states = BWS_GP_Layout_Detector::states();
		$match  = false;

		switch ( $rule ) {
			case 'header_active':
				$match = ! $states['header'];
				break;

			case 'footer_active':
				$match = ! $states['footer'];
				break;

			case 'primary_nav_active':
				$match = ! $states['primary_nav'];
				break;

			case 'secondary_nav_active':
				$match = ! $states['secondary_nav'];
				break;

			case 'top_bar_active':
				$match = ! $states['top_bar'];
				break;

			case 'featured_image_active':
				// Config-based NOT render-based (V7). Never consult has_post_thumbnail().
				$match = ! $states['featured_image'];
				break;

			case 'content_title_active':
				$match = ! $states['content_title'];
				break;

			case 'no_sidebars_active':
				$match = ( 'no-sidebar' === $states['sidebar'] );
				break;

			case 'left_sidebar_active':
				$match = ( 'left-sidebar' === $states['sidebar'] );
				break;

			case 'right_sidebar_active':
				$match = ( 'right-sidebar' === $states['sidebar'] );
				break;

			case 'both_sidebars_active':
				$match = ( 'both-sidebars' === $states['sidebar'] );
				break;
		}

		return 'is_not' === $operator ? ! $match : $match;
	}

	/**
	 * Rule keys → display labels (V11: 11 rules, all "Active" suffix).
	 *
	 * @return array
	 */
	public function get_rules() {
		return array(
			'header_active'         => __( 'Header Active', 'bws-generate-layout-conditions' ),
			'footer_active'         => __( 'Footer Active', 'bws-generate-layout-conditions' ),
			'primary_nav_active'    => __( 'Primary Nav Active', 'bws-generate-layout-conditions' ),
			'secondary_nav_active'  => __( 'Secondary Nav Active', 'bws-generate-layout-conditions' ),
			'top_bar_active'        => __( 'Top Bar Active', 'bws-generate-layout-conditions' ),
			'featured_image_active' => __( 'Featured Image Active', 'bws-generate-layout-conditions' ),
			'content_title_active'  => __( 'Content Title Active', 'bws-generate-layout-conditions' ),
			// Sidebar plural by count: "No Sidebars"/"Both Sidebars" vs singular (V11).
			'no_sidebars_active'    => __( 'No Sidebars Active', 'bws-generate-layout-conditions' ),
			'left_sidebar_active'   => __( 'Left Sidebar Active', 'bws-generate-layout-conditions' ),
			'right_sidebar_active'  => __( 'Right Sidebar Active', 'bws-generate-layout-conditions' ),
			'both_sidebars_active'  => __( 'Both Sidebars Active', 'bws-generate-layout-conditions' ),
		);
	}

	/**
	 * No value field for any rule — Device-condition pattern (V10).
	 *
	 * @param string $rule Rule key (unused — all rules share the same metadata).
	 * @return array
	 */
	public function get_rule_metadata( $rule ) {
		return array(
			'needs_value' => false,
			'value_type'  => 'none',
		);
	}
}
