<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for GP disable states + sidebar layout.
 *
 * Hybrid detection: hook-state for most signals; config-replay for header/footer
 * (whose hook signals are poisoned by Block Element takeover — ADR-0001).
 *
 * Lazy + memoized: full resolution runs ≤1× per request (V5). First call is always
 * after `wp` (body-class consumer at wp:110; condition consumer at render_block).
 *
 * OR semantics: any layer can disable, none can re-enable (V1).
 */
class BWS_GP_Layout_Detector {

	/** @var array|null Cached result — null until first call to states(). */
	private static $cache = null;

	/**
	 * Returns the resolved disable states for the current request.
	 *
	 * @return array {
	 *     @type bool   $header         Header disabled.
	 *     @type bool   $footer         Footer disabled.
	 *     @type bool   $primary_nav    Primary nav disabled.
	 *     @type bool   $secondary_nav  Secondary nav disabled.
	 *     @type bool   $top_bar        Top bar disabled.
	 *     @type bool   $featured_image Featured image disabled.
	 *     @type bool   $content_title  Content title disabled.
	 *     @type string $sidebar        'left-sidebar'|'right-sidebar'|'no-sidebar'|'both-sidebars'
	 * }
	 */
	public static function states() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		self::$cache = array(
			'header'         => self::is_header_disabled(),
			'footer'         => self::is_footer_disabled(),
			'primary_nav'    => self::is_primary_nav_disabled(),
			'secondary_nav'  => self::is_secondary_nav_disabled(),
			'top_bar'        => self::is_top_bar_disabled(),
			'featured_image' => self::is_featured_image_disabled(),
			'content_title'  => self::is_content_title_disabled(),
			'sidebar'        => self::get_sidebar_layout(),
		);

		return self::$cache;
	}

	// -----------------------------------------------------------------------
	// Header — config-replay (hook signal poisoned by Block Element, ADR-0001)
	// -----------------------------------------------------------------------

	private static function is_header_disabled() {
		// Post-meta branch (singular only, ADR-0002).
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			if ( get_post_meta( $post_id, '_generate-disable-header', true ) ) {
				return true;
			}
		}

		// Layout-Element branch: query layout posts; replay conditions + disable meta.
		return self::layout_element_disables( '_generate_disable_site_header' );
	}

	// -----------------------------------------------------------------------
	// Footer — config-replay (same poisoning reason as header)
	// -----------------------------------------------------------------------

	private static function is_footer_disabled() {
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			if ( get_post_meta( $post_id, '_generate-disable-footer', true ) ) {
				return true;
			}
		}

		return self::layout_element_disables( '_generate_disable_footer' );
	}

	// -----------------------------------------------------------------------
	// Hook-state signals — metabox + Layout Element both set the same hook
	// -----------------------------------------------------------------------

	private static function is_primary_nav_disabled() {
		return (bool) has_filter( 'generate_navigation_location', '__return_false' );
	}

	private static function is_content_title_disabled() {
		return (bool) has_filter( 'generate_show_title', '__return_false' );
	}

	private static function is_top_bar_disabled() {
		return ! has_action( 'generate_before_header', 'generate_top_bar' );
	}

	private static function is_featured_image_disabled() {
		// Config-based, NOT render-based (V7). Never consult has_post_thumbnail().
		return ! has_action( 'generate_after_entry_header', 'generate_blog_single_featured_image' );
	}

	// -----------------------------------------------------------------------
	// Secondary nav — post-meta only (no clean hook signal; Layout-Element
	// disable via has_nav_menu filter not detectable cleanly — accepted gap)
	// -----------------------------------------------------------------------

	private static function is_secondary_nav_disabled() {
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			if ( get_post_meta( $post_id, '_generate-disable-secondary-nav', true ) ) {
				return true;
			}
		}

		return false;
	}

	// -----------------------------------------------------------------------
	// Sidebar — GP's own resolver folds all layers; one guarded call
	// -----------------------------------------------------------------------

	private static function get_sidebar_layout() {
		if ( function_exists( 'generate_get_layout' ) ) {
			return generate_get_layout();
		}

		return 'no-sidebar';
	}

	// -----------------------------------------------------------------------
	// Config-replay helper: query Layout Elements, test conditions, check meta
	// -----------------------------------------------------------------------

	/**
	 * Returns true if any active Layout Element sets $disable_meta_key to a truthy value.
	 *
	 * "Active" = its display conditions pass for the current page (V4: all three
	 * condition meta keys passed to show_data() to avoid false positives on excluded pages).
	 *
	 * @param string $disable_meta_key e.g. '_generate_disable_site_header'
	 * @return bool
	 */
	private static function layout_element_disables( $disable_meta_key ) {
		if ( ! class_exists( 'GeneratePress_Conditions' ) ) {
			return false;
		}

		$layout_posts = get_posts( array(
			'post_type'      => 'gp_elements',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_generate_element_type',
					'value' => 'layout',
				),
				array(
					'key'     => $disable_meta_key,
					'value'   => '',
					'compare' => '!=',
				),
			),
		) );

		if ( empty( $layout_posts ) ) {
			return false;
		}

		foreach ( $layout_posts as $element_id ) {
			if ( ! get_post_meta( $element_id, $disable_meta_key, true ) ) {
				continue;
			}

			// Pass all three condition meta to show_data() (V4).
			$display  = get_post_meta( $element_id, '_generate_element_display_conditions', true );
			$exclude  = get_post_meta( $element_id, '_generate_element_exclude_conditions', true );
			$users    = get_post_meta( $element_id, '_generate_element_user_conditions', true );

			if ( GeneratePress_Conditions::show_data( $display, $exclude, $users ) ) {
				return true;
			}
		}

		return false;
	}

	/** Reset cache — test helper only, not for production use. */
	public static function reset_cache() {
		self::$cache = null;
	}
}
