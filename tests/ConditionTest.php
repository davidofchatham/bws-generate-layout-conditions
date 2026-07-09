<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Condition tests — persisted surface (V27 slugs, ADR-0004 names) and
 * evaluate() semantics (V7 active-polarity, V10 operator, V26 membership),
 * driven through the fake environment.
 */
class ConditionTest extends TestCase {

	/** @var BWS_GP_Fake_Environment */
	private $env;

	protected function setUp(): void {
		$this->env        = new BWS_GP_Fake_Environment();
		$this->env->hooks = array(
			'generate_before_header|generate_top_bar',
			'generate_after_entry_header|generate_blog_single_featured_image',
		);
		BWS_GP_Layout_Detector::set_environment( $this->env );
		BWS_GP_Layout_Detector::reset_cache();
	}

	protected function tearDown(): void {
		BWS_GP_Layout_Detector::set_environment( null );
		BWS_GP_Layout_Detector::reset_cache();
	}

	// --- Persisted names: byte-exact regression guard (V27, ADR-0004) ------

	public function test_element_rule_slugs_and_labels_are_frozen_v27(): void {
		$this->assertSame(
			array(
				'header_active'         => 'Header Active',
				'footer_active'         => 'Footer Active',
				'primary_nav_active'    => 'Primary Nav Active',
				'secondary_nav_active'  => 'Secondary Nav Active',
				'top_bar_active'        => 'Top Bar Active',
				'featured_image_active' => 'Featured Image Active',
				'content_title_active'  => 'Content Title Active',
			),
			( new BWS_GP_Theme_Element_Condition() )->get_rules()
		);
	}

	public function test_sidebar_rule_slugs_and_labels_are_frozen_v27(): void {
		$this->assertSame(
			array(
				'left_sidebar_active'  => 'Left Sidebar Active',
				'right_sidebar_active' => 'Right Sidebar Active',
				'no_sidebars_active'   => 'No Sidebars Active',
			),
			( new BWS_GP_Theme_Sidebar_Condition() )->get_rules()
		);
	}

	public function test_registration_uses_frozen_slugs_and_operators_v27(): void {
		GenerateBlocks_Pro_Conditions_Registry::$registered = array();
		bws_glc_register_conditions();
		$registered = GenerateBlocks_Pro_Conditions_Registry::$registered;

		$this->assertSame( array( 'gp_theme_element', 'gp_theme_sidebar' ), array_keys( $registered ) );
		$this->assertSame( 'BWS_GP_Theme_Element_Condition', $registered['gp_theme_element']['class'] );
		$this->assertSame( 'BWS_GP_Theme_Sidebar_Condition', $registered['gp_theme_sidebar']['class'] );
		foreach ( $registered as $slug => $entry ) {
			$this->assertSame( array( 'is', 'is_not' ), $entry['args']['operators'], "$slug operators (V27)" );
		}
	}

	public function test_no_rule_needs_a_value_v10(): void {
		$element = new BWS_GP_Theme_Element_Condition();
		$sidebar = new BWS_GP_Theme_Sidebar_Condition();

		foreach ( array_keys( $element->get_rules() ) as $rule ) {
			$this->assertSame( array( 'needs_value' => false, 'value_type' => 'none' ), $element->get_rule_metadata( $rule ) );
		}
		foreach ( array_keys( $sidebar->get_rules() ) as $rule ) {
			$this->assertSame( array( 'needs_value' => false, 'value_type' => 'none' ), $sidebar->get_rule_metadata( $rule ) );
		}
	}

	// --- V7: "Active" = not-disabled-by-config, positive polarity ----------

	public function test_active_rules_true_when_nothing_disabled_v7(): void {
		$element = new BWS_GP_Theme_Element_Condition();

		foreach ( array_keys( $element->get_rules() ) as $rule ) {
			$this->assertTrue( $element->evaluate( $rule, 'is', null ), "$rule must be Active on a bare request" );
		}
	}

	public function test_disabled_component_flips_only_its_rule(): void {
		$this->env->singular   = true;
		$this->env->queried_id = 10;
		$this->env->meta[10]   = array( '_generate-disable-header' => 'true' );

		$element = new BWS_GP_Theme_Element_Condition();

		$this->assertFalse( $element->evaluate( 'header_active', 'is', null ) );
		$this->assertTrue( $element->evaluate( 'footer_active', 'is', null ) );
	}

	// --- V10: one operator formula, is_not inverts --------------------------

	public function test_is_not_inverts_every_rule_v10(): void {
		$element = new BWS_GP_Theme_Element_Condition();

		foreach ( array_keys( $element->get_rules() ) as $rule ) {
			$this->assertSame(
				! $element->evaluate( $rule, 'is', null ),
				$element->evaluate( $rule, 'is_not', null ),
				"$rule is_not must invert is (V10)"
			);
		}
	}

	public function test_unknown_rule_never_matches(): void {
		$element = new BWS_GP_Theme_Element_Condition();

		$this->assertFalse( $element->evaluate( 'bogus_rule', 'is', null ) );
		$this->assertTrue( $element->evaluate( 'bogus_rule', 'is_not', null ) );
	}

	// --- V6: loop-item post_id in $context is discarded — page-level state --

	public function test_context_post_id_is_discarded_v6(): void {
		$this->env->singular   = true;
		$this->env->queried_id = 10;
		$this->env->meta[99]   = array( '_generate-disable-header' => 'true' ); // loop item, not the page

		$element = new BWS_GP_Theme_Element_Condition();

		$this->assertTrue(
			$element->evaluate( 'header_active', 'is', null, array( 'post_id' => 99 ) ),
			'condition must answer about the page, not the loop item (V6)'
		);
	}

	// --- V26: sidebar membership, not exclusive enum-match ------------------

	#[DataProvider( 'sidebar_membership_provider' )]
	public function test_sidebar_membership_v26( string $enum, array $expected ): void {
		$this->env->sidebar = $enum;
		$sidebar            = new BWS_GP_Theme_Sidebar_Condition();

		foreach ( $expected as $rule => $verdict ) {
			$this->assertSame( $verdict, $sidebar->evaluate( $rule, 'is', null ), "$rule on $enum" );
		}
	}

	public static function sidebar_membership_provider(): array {
		return array(
			'left only'  => array( 'left-sidebar', array( 'left_sidebar_active' => true, 'right_sidebar_active' => false, 'no_sidebars_active' => false ) ),
			'right only' => array( 'right-sidebar', array( 'left_sidebar_active' => false, 'right_sidebar_active' => true, 'no_sidebars_active' => false ) ),
			'none'       => array( 'no-sidebar', array( 'left_sidebar_active' => false, 'right_sidebar_active' => false, 'no_sidebars_active' => true ) ),
			// The B4 case: membership means both sides read Active on both-sidebars.
			'both'       => array( 'both-sidebars', array( 'left_sidebar_active' => true, 'right_sidebar_active' => true, 'no_sidebars_active' => false ) ),
		);
	}
}
