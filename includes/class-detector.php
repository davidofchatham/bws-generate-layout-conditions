<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for GP disable states + sidebar layout.
 *
 * Hybrid detection: hook-state for most signals; config-replay for header, footer
 * and content title (whose hook signals are poisoned by elements that take over
 * the hook for their own reasons — ADR-0001, ADR-0005).
 *
 * Lazy + memoized: full resolution runs ≤1× per request (V5). First call is always
 * after `wp` (body-class consumer at wp:110; condition consumer at render_block).
 *
 * OR semantics: any layer can disable, none can re-enable (V1).
 */
class BWS_GP_Layout_Detector {

	/** @var array|null Cached result — null until first call to states(). */
	private static $cache = null;

	/** @var BWS_GP_Environment|null Injected environment — WP adapter unless a test sets a fake (T9). */
	private static $environment = null;

	/**
	 * The environment adapter behind the seam (T9). Lazy default: production WP adapter.
	 *
	 * @return BWS_GP_Environment
	 */
	private static function env() {
		if ( null === self::$environment ) {
			self::$environment = new BWS_GP_WP_Environment();
		}

		return self::$environment;
	}

	/**
	 * Swap the environment adapter — test seam (T9). Pass null to restore the WP adapter.
	 * Callers should also reset_cache(); states() memoizes across swaps otherwise (V5).
	 *
	 * @param BWS_GP_Environment|null $environment
	 */
	public static function set_environment( $environment ) {
		self::$environment = $environment;
	}

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
				// The PAGE-TITLE role, not the item titles in loop cards (V30/V31).
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
		if ( self::post_metabox_disables( '_generate-disable-header' ) ) {
			return true;
		}

		// Layout-Element branch: query layout posts; replay conditions + disable meta.
		return self::layout_element_disables( '_generate_disable_site_header' );
	}

	// -----------------------------------------------------------------------
	// Footer — config-replay (same poisoning reason as header)
	// -----------------------------------------------------------------------

	private static function is_footer_disabled() {
		if ( self::post_metabox_disables( '_generate-disable-footer' ) ) {
			return true;
		}

		return self::layout_element_disables( '_generate_disable_footer' );
	}

	// -----------------------------------------------------------------------
	// Content title — config-replay of the PAGE-TITLE role (V30, V31, ADR-0005)
	//
	// Same poisoning as header/footer, same fix: `generate_show_title` carries a
	// __return_false from any element that RELOCATES the title (Page Hero Block,
	// legacy Page Hero, the deprecated Page Header module), so hook-state cannot
	// tell "moved" from "removed". Those writers are not detected, not
	// compensated for, and not read — the signal is derived from the only two
	// genuine-disable sources instead (ADR-0005 Meaning A).
	// -----------------------------------------------------------------------

	private static function is_content_title_disabled() {
		// Off singular NO writer targets the page-title role (V31): the archive
		// heading is a different hook (generate_archive_title), the Layout Element
		// toggle reaches only the item titles inside loop cards, and the metabox
		// layer cannot apply to an archive (ADR-0002). Uniformly correct for
		// archives, search, 404 and the blog posts page — not an archive special
		// case. Explicit because layout_element_disables() has no singular gate of
		// its own (post_metabox_disables() does).
		if ( ! self::env()->is_singular() ) {
			return false;
		}

		// Writers 1+2 collapse to one key: the theme's own named callback and GP
		// Premium's redundant __return_false read the same metabox meta. Reading it
		// directly is what closes V29.
		if ( self::post_metabox_disables( '_generate-disable-headline' ) ) {
			return true;
		}

		// Layout Element key — NOT the Page Hero Block key (_generate_disable_title).
		// The two element types differ by more than a prefix, and probing the wrong
		// one reports the opposite of the truth (survey trap, docs/architecture.md).
		return self::layout_element_disables( '_generate_disable_content_title' );
	}

	// -----------------------------------------------------------------------
	// Hook-state signals — metabox + Layout Element both set the same hook
	// -----------------------------------------------------------------------

	private static function is_primary_nav_disabled() {
		return self::env()->has_hook( 'generate_navigation_location', '__return_false' );
	}

	private static function is_top_bar_disabled() {
		return ! self::env()->has_hook( 'generate_before_header', 'generate_top_bar' );
	}

	private static function is_featured_image_disabled() {
		// Hook is only added on is_singular() — absence on archives is meaningless (B2),
		// so non-singular uses config-replay instead (T8, closes the V22 gap): Layout
		// Element "disable_featured_image" fires remove_action WITHOUT an is_singular()
		// guard (gp-premium elements/class-layout.php:315), so it disables on archives
		// too. Same engine as header/footer. Post-metabox layer stays correctly absent
		// off-singular (ADR-0002).
		if ( ! self::env()->is_singular() ) {
			return self::layout_element_disables( '_generate_disable_featured_image' );
		}

		// Config-based, NOT render-based (V7). Never consult has_post_thumbnail().
		//
		// V21 ambiguity: Page Hero "Disable featured image" removes this hook because the
		// Hero embeds the image itself — hook absent but element active in a different
		// position. Hook-state wins in v1 on singular.
		//
		// NOT symmetric with content title: featured image has no template-tag writer
		// (the Page Header module renders its own image via has_post_thumbnail() without
		// touching these hooks), so only toggle-driven relocation applies here. Content
		// title left this hook for config-replay (ADR-0005); featured image has not, and
		// V21 stays open for it — see the V21 survey before changing that.
		return ! self::env()->has_hook( 'generate_after_entry_header', 'generate_blog_single_featured_image' );
	}

	// -----------------------------------------------------------------------
	// Secondary nav — config-replay both layers (T17, closes the V32 gap)
	//
	// There is no readable hook signal at all here: GP's Layout Element disables
	// the location with an ARRAY callback on `has_nav_menu`
	// (class-layout.php:312) and this plugin's own suppression uses a named
	// function on the same filter — neither is what has_hook() would look for,
	// and a location-blind probe cannot tell "secondary unassigned" from
	// "secondary disabled" anyway. Config-replay sidesteps the question: it
	// reads what was configured, never what the filter stack looks like.
	//
	// Note the Layout Element key is *_navigation, not the *-nav of the metabox
	// key — they are not the same string with a different separator
	// (class-layout.php:209 vs disable-elements/functions/functions.php:32).
	//
	// No is_singular() gate on the replay branch: GP adds the filter without one
	// (class-layout.php:311), so a Layout Element disables the secondary nav on
	// archives too. Same asymmetry as featured image (V22). The metabox layer
	// stays singular-only through post_metabox_disables() (ADR-0002).
	// -----------------------------------------------------------------------

	private static function is_secondary_nav_disabled() {
		if ( self::post_metabox_disables( '_generate-disable-secondary-nav' ) ) {
			return true;
		}

		return self::layout_element_disables( '_generate_disable_secondary_navigation' );
	}

	// -----------------------------------------------------------------------
	// Sidebar — GP's own resolver folds all layers; one guarded call
	// -----------------------------------------------------------------------

	private static function get_sidebar_layout() {
		return self::env()->sidebar_layout();
	}

	// -----------------------------------------------------------------------
	// Post-metabox helper: singular-only read from the queried object (ADR-0002)
	// -----------------------------------------------------------------------

	/**
	 * Returns true if the queried post's Disable-Elements metabox sets $meta_key.
	 * Off-singular the metabox layer contributes nothing (ADR-0002).
	 *
	 * @param string $meta_key e.g. '_generate-disable-header'
	 * @return bool
	 */
	private static function post_metabox_disables( $meta_key ) {
		if ( ! self::env()->is_singular() ) {
			return false;
		}

		return (bool) self::env()->post_meta( self::env()->queried_object_id(), $meta_key );
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
		$env = self::env();

		if ( ! $env->can_replay_conditions() ) {
			return false;
		}

		$layout_posts = $env->layout_element_ids( $disable_meta_key );

		if ( empty( $layout_posts ) ) {
			return false;
		}

		foreach ( $layout_posts as $element_id ) {
			if ( ! $env->post_meta( $element_id, $disable_meta_key ) ) {
				continue;
			}

			// Pass all three condition meta to conditions_pass() (V4).
			// Normalize: post_meta returns '' when unset; show_data expects array-of-arrays (V23).
			$display  = $env->post_meta( $element_id, '_generate_element_display_conditions' ) ?: array();
			$exclude  = $env->post_meta( $element_id, '_generate_element_exclude_conditions' ) ?: array();
			$users    = $env->post_meta( $element_id, '_generate_element_user_conditions' ) ?: array();

			if ( $env->conditions_pass( $display, $exclude, $users ) ) {
				return true;
			}
		}

		return false;
	}

	/** Reset memoized states — test seam (pairs with set_environment), not for production use. */
	public static function reset_cache() {
		self::$cache = null;
	}
}
