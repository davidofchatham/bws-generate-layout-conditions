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
		$this->env             = new BWS_GP_Fake_Environment();
		$this->env->singular   = true;
		$this->env->queried_id = 10;
		// A request where every rule reads Active: GP's render callbacks attached,
		// no meta, no elements.
		$this->env->hooks = array(
			'generate_before_header|generate_top_bar',
			// The featured-image SLOT rule reads hook state across both of GP's
			// render paths (issue #4). Deliberately the `inside-content` position
			// and NOT `generate_after_entry_header|generate_blog_single_featured_image`
			// — that pair is the one the featured_image *signal* probed before
			// ADR-0006, so a Detector reverted to reading it for that signal still
			// fails test_active_rules_true_when_nothing_disabled_v7 here. It is also
			// the position the render harness pins (render-surface.sh §0).
			'generate_before_content|generate_blog_single_featured_image',
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
				// Qualified label (ADR-0006). The SLUG is frozen (V27); the label is
				// not persisted, and the qualifier earns its place beside the sibling
				// slot rule this signal now sits next to.
				'featured_image_active' => 'Featured Image Active (post setting)',
				'content_title_active'  => 'Content Title Active',
				// The one rule that is not a signal (issue #4). Slug carries no
				// prefix — the condition type already names the theme (V27).
				'featured_image_slot_active' => 'Featured Image Slot Active (theme)',
			),
			( new BWS_GP_Theme_Element_Condition() )->get_rules()
		);
	}

	/**
	 * The rule count itself (V11). Asserted as a count as well as a map because
	 * the count is what the invariant states and what a reader checks against the
	 * docs — a rule added without updating V11 fails here by name.
	 */
	public function test_rule_count_is_eleven_v11(): void {
		$element = ( new BWS_GP_Theme_Element_Condition() )->get_rules();
		$sidebar = ( new BWS_GP_Theme_Sidebar_Condition() )->get_rules();

		$this->assertCount( 8, $element, '7 signal rules + the theme slot rule (V11)' );
		$this->assertCount( 3, $sidebar, 'sidebar membership rules (V26)' );
		$this->assertCount( 11, array_merge( $element, $sidebar ), 'total rule count (V11)' );
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

	// --- Issue #4: the slot rule's polarity, which lives only here -----------
	//
	// Seven rules read a DISABLE-polarity state and invert it; this one reads a
	// state already stored the way it is reported and must not be inverted. The
	// Detector cannot catch a mistake here — its state map is right either way — so
	// this is the only place the non-inversion is checked, and it must fail if the
	// seven signals' polarity is applied to the row.

	public function test_slot_rule_is_not_inverted_by_the_condition_layer_issue4(): void {
		$element = new BWS_GP_Theme_Element_Condition();

		// setUp attaches one of GP's image callbacks: the slot IS live, and the
		// state map says so. Under the signals' polarity this reads false.
		$this->assertTrue(
			BWS_GP_Layout_Detector::states()['featured_image_slot_active'],
			'precondition: the state map reports the slot live'
		);
		$this->assertTrue(
			$element->evaluate( 'featured_image_slot_active', 'is', null ),
			'the slot state is stored positive and must NOT be inverted by the condition layer (issue #4)'
		);

		// Nothing drawing the image — a relocation, a kill switch, or the
		// Customizer's global toggle. Both halves are needed: an unconditionally
		// true rule would pass the assertion above for the wrong reason.
		$this->env->hooks = array( 'generate_before_header|generate_top_bar' );
		BWS_GP_Layout_Detector::reset_cache();

		$this->assertFalse(
			BWS_GP_Layout_Detector::states()['featured_image_slot_active'],
			'precondition: the state map reports the slot not live'
		);
		$this->assertFalse(
			$element->evaluate( 'featured_image_slot_active', 'is', null ),
			'with no image callback attached the rule must report not-active (issue #4)'
		);
	}

	public function test_slot_rule_is_not_inverted_off_singular_issue4(): void {
		$this->env->singular = false;
		BWS_GP_Layout_Detector::reset_cache();

		$this->assertFalse(
			( new BWS_GP_Theme_Element_Condition() )->evaluate( 'featured_image_slot_active', 'is', null ),
			'off singular the native slot does not exist, so the rule reports not-active (issue #4)'
		);
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
