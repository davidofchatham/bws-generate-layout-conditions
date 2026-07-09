<?php
use PHPUnit\Framework\TestCase;

/**
 * Detector tests through its real interface — states() — with the fake
 * environment behind the seam (T9). Each test names the invariant it executes.
 */
class DetectorTest extends TestCase {

	/** @var BWS_GP_Fake_Environment */
	private $env;

	protected function setUp(): void {
		$this->env = self::all_active_env();
		BWS_GP_Layout_Detector::set_environment( $this->env );
		BWS_GP_Layout_Detector::reset_cache();
	}

	protected function tearDown(): void {
		BWS_GP_Layout_Detector::set_environment( null );
		BWS_GP_Layout_Detector::reset_cache();
	}

	/** A request where nothing is disabled: GP's render hooks are attached, no meta, no elements. */
	private static function all_active_env(): BWS_GP_Fake_Environment {
		$env        = new BWS_GP_Fake_Environment();
		$env->hooks = array(
			'generate_before_header|generate_top_bar',
			'generate_after_entry_header|generate_blog_single_featured_image',
		);
		return $env;
	}

	public function test_nothing_disabled_on_bare_singular_request(): void {
		$this->env->singular   = true;
		$this->env->queried_id = 10;

		$states = BWS_GP_Layout_Detector::states();

		foreach ( BWS_GP_Layout_Detector::signals() as $key => $signal ) {
			$this->assertFalse( $states[ $key ], "$key must not be disabled" );
		}
		$this->assertSame( 'no-sidebar', $states['sidebar'] );
	}

	// --- V5: lazy + memoized, full resolution at most once per request -----

	public function test_states_resolves_once_v5(): void {
		BWS_GP_Layout_Detector::states();
		$first = $this->env->calls;

		$again = BWS_GP_Layout_Detector::states();

		$this->assertSame( $first, $this->env->calls, 'second states() call must not touch the environment (V5)' );
		$this->assertSame( BWS_GP_Layout_Detector::states(), $again );
	}

	// --- V1: OR across layers — any layer disables, none re-enables --------

	public function test_metabox_layer_alone_disables_header_v1(): void {
		$this->env->singular   = true;
		$this->env->queried_id = 10;
		$this->env->meta[10]   = array( '_generate-disable-header' => 'true' );

		$this->assertTrue( BWS_GP_Layout_Detector::states()['header'] );
	}

	public function test_layout_element_layer_alone_disables_header_v1(): void {
		$this->env->layout_elements['_generate_disable_site_header'] = array( 42 );
		$this->env->meta[42] = array( '_generate_disable_site_header' => 'true' );

		$this->assertTrue( BWS_GP_Layout_Detector::states()['header'] );
	}

	public function test_both_layers_disable_header_no_interference_v1(): void {
		$this->env->singular   = true;
		$this->env->queried_id = 10;
		$this->env->meta[10]   = array( '_generate-disable-header' => 'true' );
		$this->env->layout_elements['_generate_disable_site_header'] = array( 42 );
		$this->env->meta[42] = array( '_generate_disable_site_header' => 'true' );

		$this->assertTrue( BWS_GP_Layout_Detector::states()['header'] );
	}

	// --- V2: header/footer never read hook-state (poisoned signals) --------

	public function test_header_footer_ignore_hook_state_v2(): void {
		// A Block Element "took over" the header/footer hooks — hook-state would
		// scream disabled. Config says nothing is disabled. Replay must win.
		$this->env->hooks = self::all_active_env()->hooks; // header/footer construct hooks NOT attached

		$states = BWS_GP_Layout_Detector::states();

		$this->assertFalse( $states['header'], 'header must come from config-replay, not hook-state (V2)' );
		$this->assertFalse( $states['footer'] );
	}

	// --- ADR-0002: metabox reads gated on singular, from the queried object -

	public function test_metabox_meta_ignored_off_singular_adr0002(): void {
		$this->env->singular   = false;
		$this->env->queried_id = 10;
		$this->env->meta[10]   = array(
			'_generate-disable-header'        => 'true',
			'_generate-disable-secondary-nav' => 'true',
		);

		$states = BWS_GP_Layout_Detector::states();

		$this->assertFalse( $states['header'], 'off-singular the metabox layer contributes nothing (ADR-0002)' );
		$this->assertFalse( $states['secondary_nav'] );
	}

	public function test_metabox_reads_queried_object_only_adr0002(): void {
		$this->env->singular   = true;
		$this->env->queried_id = 10;
		// Disable meta lives on a DIFFERENT post (a loop item, get_the_ID() drift).
		$this->env->meta[99] = array( '_generate-disable-header' => 'true' );

		$this->assertFalse( BWS_GP_Layout_Detector::states()['header'] );
	}

	// --- Hook-state signals -------------------------------------------------

	public function test_hook_state_signals(): void {
		$this->env->singular = true;
		$this->env->hooks    = array(
			// top bar + featured image render hooks REMOVED (disabled)…
			// …and the disable filters PRESENT for nav + title.
			'generate_navigation_location|__return_false',
			'generate_show_title|__return_false',
		);

		$states = BWS_GP_Layout_Detector::states();

		$this->assertTrue( $states['primary_nav'] );
		$this->assertTrue( $states['content_title'] );
		$this->assertTrue( $states['top_bar'] );
		$this->assertTrue( $states['featured_image'] );
	}

	// --- V20/V22/B2: featured image off-singular = replay, never hook-state -

	public function test_featured_image_archive_uses_replay_not_hook_v22(): void {
		$this->env->singular = false;
		$this->env->hooks    = array(); // hook absent — meaningless on archives (B2)

		$this->assertFalse(
			BWS_GP_Layout_Detector::states()['featured_image'],
			'hook absence on archives is not a disable signal (V20/B2)'
		);
	}

	public function test_featured_image_archive_detects_layout_element_v22(): void {
		$this->env->singular = false;
		$this->env->layout_elements['_generate_disable_featured_image'] = array( 42 );
		$this->env->meta[42] = array( '_generate_disable_featured_image' => 'true' );

		$this->assertTrue( BWS_GP_Layout_Detector::states()['featured_image'] );
	}

	// --- V4/V23: replay passes all three condition metas, normalized --------

	public function test_replay_normalizes_unset_condition_meta_v23(): void {
		$this->env->layout_elements['_generate_disable_site_header'] = array( 42 );
		// Only the disable meta is set; all three condition metas are unset ('').
		$this->env->meta[42] = array( '_generate_disable_site_header' => 'true' );

		BWS_GP_Layout_Detector::states();

		$this->assertCount( 1, $this->env->conditions_args );
		foreach ( $this->env->conditions_args[0] as $i => $arg ) {
			$this->assertIsArray( $arg, "conditions_pass arg $i must be normalized to an array (V23)" );
		}
	}

	public function test_replay_skips_elements_whose_conditions_fail(): void {
		$this->env->layout_elements['_generate_disable_site_header'] = array( 42 );
		$this->env->meta[42] = array( '_generate_disable_site_header' => 'true' );
		$this->env->conditions_result = false; // element does not apply to this page

		$this->assertFalse( BWS_GP_Layout_Detector::states()['header'] );
	}

	public function test_replay_unavailable_contributes_nothing(): void {
		$this->env->replay_available = false;
		$this->env->layout_elements['_generate_disable_site_header'] = array( 42 );
		$this->env->meta[42] = array( '_generate_disable_site_header' => 'true' );

		$this->assertFalse( BWS_GP_Layout_Detector::states()['header'] );
	}

	// --- Sidebar: raw GP enum passthrough (V26 — membership is consumer-side)

	public function test_sidebar_exposes_raw_gp_enum_v26(): void {
		$this->env->sidebar = 'both-sidebars';

		$this->assertSame( 'both-sidebars', BWS_GP_Layout_Detector::states()['sidebar'] );
	}
}
