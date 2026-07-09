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
	 * Canonical signal registry — the single enumeration of the 7 element signals (T7).
	 *
	 * Every consumer derives its per-signal surface from this table:
	 * - `states()` (detection)         → 'method'
	 * - condition `evaluate()`/`get_rules()` → 'rule' + 'label'
	 * - body-class emitter             → 'class'
	 *
	 * 'rule' slugs are persisted in saved condition data (V27) and 'class' names are
	 * public CSS surface (ADR-0004) — both must stay byte-identical across releases.
	 * Positive labels vs negative classes diverge on purpose (V9): both vocabularies
	 * are stored here side by side, never derived from one another.
	 *
	 * Sidebar is NOT a row — different shape (enum not bool), no body class (V8),
	 * membership semantics live in the sidebar condition (V26).
	 *
	 * @return array key => { method: detector method, rule: condition rule slug,
	 *                        label: translated rule label, class: gp-no-* body class }
	 */
	public static function signals() {
		return array(
			'header'         => array(
				'method' => 'is_header_disabled',
				'rule'   => 'header_active',
				'label'  => __( 'Header Active', 'bws-generate-layout-conditions' ),
				'class'  => 'gp-no-header',
			),
			'footer'         => array(
				'method' => 'is_footer_disabled',
				'rule'   => 'footer_active',
				'label'  => __( 'Footer Active', 'bws-generate-layout-conditions' ),
				'class'  => 'gp-no-footer',
			),
			'primary_nav'    => array(
				'method' => 'is_primary_nav_disabled',
				'rule'   => 'primary_nav_active',
				'label'  => __( 'Primary Nav Active', 'bws-generate-layout-conditions' ),
				'class'  => 'gp-no-primary-nav',
			),
			'secondary_nav'  => array(
				'method' => 'is_secondary_nav_disabled',
				'rule'   => 'secondary_nav_active',
				'label'  => __( 'Secondary Nav Active', 'bws-generate-layout-conditions' ),
				'class'  => 'gp-no-secondary-nav',
			),
			'top_bar'        => array(
				'method' => 'is_top_bar_disabled',
				'rule'   => 'top_bar_active',
				'label'  => __( 'Top Bar Active', 'bws-generate-layout-conditions' ),
				'class'  => 'gp-no-top-bar',
			),
			'featured_image' => array(
				// Config-based NOT render-based (V7) — see is_featured_image_disabled().
				'method' => 'is_featured_image_disabled',
				'rule'   => 'featured_image_active',
				'label'  => __( 'Featured Image Active', 'bws-generate-layout-conditions' ),
				'class'  => 'gp-no-featured-image',
			),
			'content_title'  => array(
				'method' => 'is_content_title_disabled',
				'rule'   => 'content_title_active',
				'label'  => __( 'Content Title Active', 'bws-generate-layout-conditions' ),
				'class'  => 'gp-no-content-title',
			),
		);
	}

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

		$states = array();
		foreach ( self::signals() as $key => $signal ) {
			$states[ $key ] = self::{$signal['method']}();
		}
		$states['sidebar'] = self::get_sidebar_layout();

		self::$cache = $states;

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
		// V21 ambiguity: Page Hero "Disable title" adds this same filter because the Hero
		// embeds the title itself — filter present but title is active via the Hero.
		// Hook-state wins in v1. Same future toggle as featured image will apply here.
		return (bool) has_filter( 'generate_show_title', '__return_false' );
	}

	private static function is_top_bar_disabled() {
		return ! has_action( 'generate_before_header', 'generate_top_bar' );
	}

	private static function is_featured_image_disabled() {
		// Hook is only added on is_singular() — absence on archives is meaningless (B2).
		if ( ! is_singular() ) {
			return false;
		}

		// Config-based, NOT render-based (V7). Never consult has_post_thumbnail().
		//
		// V21 ambiguity: Page Hero "Disable featured image" (and "Disable title" for
		// is_content_title_disabled) removes this hook because the Hero embeds the element
		// itself — hook absent but element is active in a different position. Hook-state
		// wins in v1. A future toggle should let users choose hook-state vs config-replay.
		// Do not change without that toggle — both interpretations are valid per-site.
		//
		// V22 gap: Layout Element "disable_featured_image" fires remove_action without
		// is_singular() guard, so it disables on archives too. We always return false on
		// non-singular (B2), missing that signal. Fix requires config-replay for featured
		// image on non-singular — not implemented in v1.
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
			// Normalize: get_post_meta returns '' when unset; show_data expects array-of-arrays.
			$display  = get_post_meta( $element_id, '_generate_element_display_conditions', true ) ?: array();
			$exclude  = get_post_meta( $element_id, '_generate_element_exclude_conditions', true ) ?: array();
			$users    = get_post_meta( $element_id, '_generate_element_user_conditions', true ) ?: array();

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
