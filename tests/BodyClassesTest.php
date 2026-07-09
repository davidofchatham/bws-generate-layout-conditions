<?php
use PHPUnit\Framework\TestCase;

/**
 * Body-class emitter tests — gp-no-* names frozen (ADR-0004), negative
 * polarity (V9), no sidebar/container class (V8), dedupe (V15).
 */
class BodyClassesTest extends TestCase {

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

	public function test_no_classes_when_nothing_disabled(): void {
		$this->assertSame( array( 'existing' ), bws_glc_add_body_classes( array( 'existing' ) ) );
	}

	public function test_class_names_are_frozen_adr0004(): void {
		$expected = array(
			'header'         => 'gp-no-header',
			'footer'         => 'gp-no-footer',
			'primary_nav'    => 'gp-no-primary-nav',
			'secondary_nav'  => 'gp-no-secondary-nav',
			'top_bar'        => 'gp-no-top-bar',
			'featured_image' => 'gp-no-featured-image',
			'content_title'  => 'gp-no-content-title',
		);

		$actual = array();
		foreach ( BWS_GP_Layout_Detector::signals() as $key => $signal ) {
			$actual[ $key ] = $signal['class'];
		}

		$this->assertSame( $expected, $actual );
	}

	public function test_disabled_states_emit_their_class_v9(): void {
		$this->env->singular   = true;
		$this->env->queried_id = 10;
		$this->env->meta[10]   = array(
			'_generate-disable-header'        => 'true',
			'_generate-disable-secondary-nav' => 'true',
		);

		$classes = bws_glc_add_body_classes( array() );

		$this->assertSame( array( 'gp-no-header', 'gp-no-secondary-nav' ), $classes );
	}

	public function test_no_sidebar_class_ever_v8(): void {
		$this->env->sidebar = 'both-sidebars';

		$this->assertSame(
			array(),
			bws_glc_add_body_classes( array() ),
			'sidebar gets the condition only, never a class (V8)'
		);
	}

	public function test_classes_deduped_v15(): void {
		$this->env->singular   = true;
		$this->env->queried_id = 10;
		$this->env->meta[10]   = array( '_generate-disable-header' => 'true' );

		$classes = bws_glc_add_body_classes( array( 'gp-no-header' ) );

		$this->assertSame( array( 'gp-no-header' ), array_values( $classes ) );
	}
}
