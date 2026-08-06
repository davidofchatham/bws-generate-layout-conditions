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
 * has_post_thumbnail() or GP's featured-image-active class (V7) — including in
 * `featured_image_slot_active`, which reports whether GP's render callbacks are
 * attached and says nothing about whether a thumbnail exists (issue #4).
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
 * Theme Element Status — the 7 component disable states, plus the theme's own
 * featured-image slot (V11, V27, issue #4). 8 rules.
 *
 * Each signal rule is true when the component is NOT disabled by config
 * ("Active", V7). Their rules, labels and state keys all come from the Detector's
 * signal registry (T7) — this class holds no signal enumeration of its own. The
 * slot rule is not a signal: it is reported positive, carries no body class, and
 * is declared in rule_table() below.
 */
class BWS_GP_Theme_Element_Condition extends BWS_GP_No_Value_Condition {

	/**
	 * The condition's rule table: rule slug => { state, label, invert }.
	 *
	 * Both consumers read it — `get_rules()` takes the labels, `evaluate()` takes
	 * the state key and the polarity — so a rule cannot appear in the dropdown
	 * without an evaluation path, or vice versa.
	 *
	 * 'invert' is the load-bearing column, and the reason this is a table rather
	 * than a loop over the signal registry. The Detector's state map is
	 * DISABLE-polarity for the seven signals, so those rows invert to read as
	 * "Active"; a row whose state is already stored the way its rule reads it must
	 * not be. The sidebar condition already carries rules that are not signals;
	 * this is the element condition's equivalent seam.
	 *
	 * 'state' indexes `BWS_GP_Layout_Detector::states()`. For signal rows that is
	 * the registry key (`featured_image`), which is deliberately NOT the rule slug
	 * (`featured_image_active`) — V9 vocabulary divergence.
	 *
	 * @return array rule slug => { state: states() key, label: translated label,
	 *                              invert: bool — true when the state is disable-polarity }
	 */
	private static function rule_table() {
		$rules = array();

		foreach ( BWS_GP_Layout_Detector::signals() as $key => $signal ) {
			$rules[ $signal['rule'] ] = array(
				'state'  => $key,
				'label'  => $signal['label'],
				'invert' => true,
			);
		}

		/*
		 * The theme's own featured-image slot (issue #4) — the one row that is not
		 * a signal, and the only rule read straight from the state map.
		 *
		 * It answers "is GeneratePress itself drawing a featured image here?",
		 * beside `featured_image_active`, which answers "has the editor switched
		 * the image off for this post?". Two questions about different subjects, so
		 * the label qualifies both by layer (V11) rather than either being renamed.
		 *
		 * `invert => false` is the whole reason rule_table() exists. The Detector
		 * stores this state positive, so inverting it here would report the exact
		 * opposite of the truth while the state map stayed correct — a mistake no
		 * Detector test can see.
		 *
		 * No body class, deliberately (V8/ADR-0004): a third featured-image-related
		 * class on one body element — the existing negative `gp-no-featured-image`,
		 * GP's own render-based `featured-image-active`, plus one more — is a
		 * confusion hazard, and class names are permanent public surface once
		 * shipped. The class layer stays at the seven signal names, which is why
		 * this row lives here rather than in the signal registry.
		 *
		 * Nested inside `featured_image_active` by mechanism: the plugin removes
		 * GP's five image callbacks when the per-post toggle is set, so this rule is
		 * false whenever that one is. "Post setting disabled, slot active" is
		 * unreachable; see is_featured_image_slot_active().
		 */
		$rules['featured_image_slot_active'] = array(
			'state'  => 'featured_image_slot_active',
			'label'  => __( 'Featured Image Slot Active (theme)', 'bws-generate-layout-conditions' ),
			'invert' => false,
		);

		return $rules;
	}

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
		$rules = self::rule_table();
		$match = false;

		if ( isset( $rules[ $rule ] ) ) {
			$entry  = $rules[ $rule ];
			$states = BWS_GP_Layout_Detector::states();
			$state  = $states[ $entry['state'] ];

			// Disable-polarity states invert to "Active" (V7) — never render state.
			$match = $entry['invert'] ? ! $state : (bool) $state;
		}

		return $this->apply_operator( $operator, $match );
	}

	/**
	 * Rule keys → display labels (V11: every label built on an "Active" stem).
	 *
	 * @return array
	 */
	public function get_rules() {
		$rules = array();

		foreach ( self::rule_table() as $rule => $entry ) {
			$rules[ $rule ] = $entry['label'];
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
