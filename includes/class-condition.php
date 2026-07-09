<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * GB Pro custom conditions: gp_theme_element + gp_theme_sidebar (V27).
 *
 * Split from the former single gp_layout_state slug — the slug is persisted in
 * saved condition data, so the split is done pre-release to avoid a migration.
 * Reserved future slug: gp_theme_container (container width — not built).
 *
 * Both modeled on class-condition-device.php (V10): no value field, operators
 * is/is_not. evaluate() discards $context['post_id'] — page-level state (V6).
 * "Active" = not-disabled-by-config, NOT actual-render. Never consult
 * has_post_thumbnail() or GP's featured-image-active class (V7).
 *
 * Self-gated: this file is only required() when GB Pro is present (see bootstrap).
 * Runtime class_exists guard below is an extra safety net (V13).
 */

if ( ! class_exists( 'GenerateBlocks_Pro_Conditions_Registry' ) ) {
	return;
}

add_action( 'generateblocks_register_conditions', 'bws_glc_register_conditions' );

function bws_glc_register_conditions() {
	GenerateBlocks_Pro_Conditions_Registry::register(
		'gp_theme_element',
		array(
			'label'     => __( 'Theme Element Status', 'bws-generate-layout-conditions' ),
			'operators' => array( 'is', 'is_not' ),
		),
		'BWS_GP_Theme_Element_Condition'
	);

	GenerateBlocks_Pro_Conditions_Registry::register(
		'gp_theme_sidebar',
		array(
			'label'     => __( 'Theme Sidebar', 'bws-generate-layout-conditions' ),
			'operators' => array( 'is', 'is_not' ),
		),
		'BWS_GP_Theme_Sidebar_Condition'
	);
}

/**
 * Shared base for both condition types — Device-condition pattern (V10):
 * no value field on any rule, operators limited to is/is_not.
 */
abstract class BWS_GP_No_Value_Condition extends GenerateBlocks_Pro_Condition_Abstract {

	/**
	 * No value field for any rule (V10).
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

	/**
	 * The one operator formula for every rule (V10).
	 *
	 * @param string $operator 'is' or 'is_not'.
	 * @param bool   $match    Raw rule result.
	 * @return bool
	 */
	protected function apply_operator( $operator, $match ) {
		return 'is_not' === $operator ? ! $match : $match;
	}
}

/**
 * Theme Element Status — the 7 component disable states (V11, V27).
 *
 * Each rule true when the component is NOT disabled by config ("Active", V7).
 * Rules, labels, and state keys all come from the Detector's signal registry (T7)
 * — this class holds no signal enumeration of its own.
 */
class BWS_GP_Theme_Element_Condition extends BWS_GP_No_Value_Condition {

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

		foreach ( BWS_GP_Layout_Detector::signals() as $key => $signal ) {
			if ( $signal['rule'] === $rule ) {
				// "Active" = not disabled by config (V7) — never render state.
				$match = ! $states[ $key ];
				break;
			}
		}

		return $this->apply_operator( $operator, $match );
	}

	/**
	 * Rule keys → display labels (V11: 7 component rules, all "Active" suffix).
	 *
	 * @return array
	 */
	public function get_rules() {
		$rules = array();

		foreach ( BWS_GP_Layout_Detector::signals() as $signal ) {
			$rules[ $signal['rule'] ] = $signal['label'];
		}

		return $rules;
	}
}

/**
 * Theme Sidebar — sidebar-present membership rules (V11, V26, V27).
 *
 * Membership not exclusive enum-match (B4): left/right are true whenever that
 * side renders, INCLUDING the both-sidebars layout. "Both" and "neither" are
 * composable via AND; only "no sidebars" keeps a convenience rule.
 */
class BWS_GP_Theme_Sidebar_Condition extends BWS_GP_No_Value_Condition {

	/**
	 * Evaluate the condition.
	 *
	 * @param string $rule     Rule key (e.g. 'left_sidebar_active').
	 * @param string $operator 'is' or 'is_not'.
	 * @param mixed  $value    Ignored — no value field (V10).
	 * @param array  $context  Ignored — page-level state, not loop-item (V6).
	 * @return bool
	 */
	public function evaluate( $rule, $operator, $value, $context = array() ) {
		$sidebar = BWS_GP_Layout_Detector::states()['sidebar'];
		$match   = false;

		switch ( $rule ) {
			case 'left_sidebar_active':
				// True whenever left renders — left-only OR both (V26, B4).
				$match = in_array( $sidebar, array( 'left-sidebar', 'both-sidebars' ), true );
				break;

			case 'right_sidebar_active':
				$match = in_array( $sidebar, array( 'right-sidebar', 'both-sidebars' ), true );
				break;

			case 'no_sidebars_active':
				$match = ( 'no-sidebar' === $sidebar );
				break;
		}

		return $this->apply_operator( $operator, $match );
	}

	/**
	 * Rule keys → display labels (V11: 3 sidebar rules, membership V26).
	 *
	 * "Both Sidebars Active" removed (B4) — compose via Left Active AND Right Active.
	 * Sidebar plural by count: "No Sidebars" vs singular "Left/Right Sidebar" (V11).
	 *
	 * @return array
	 */
	public function get_rules() {
		return array(
			'left_sidebar_active'  => __( 'Left Sidebar Active', 'bws-generate-layout-conditions' ),
			'right_sidebar_active' => __( 'Right Sidebar Active', 'bws-generate-layout-conditions' ),
			'no_sidebars_active'   => __( 'No Sidebars Active', 'bws-generate-layout-conditions' ),
		);
	}
}
