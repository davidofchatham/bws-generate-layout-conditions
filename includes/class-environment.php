<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Environment seam under the Detector (T9).
 *
 * Everything the Detector reads from WordPress / GP Premium sits behind this
 * one small interface. Two adapters: BWS_GP_WP_Environment (production, below)
 * and the in-memory fake in tests/. The Detector's own interface (states())
 * is unchanged — this seam is internal, exercised by the Detector's tests.
 *
 * The seam deliberately exposes only the queried-object id, never a loop-item
 * id — post-meta reads cannot drift inside do_blocks() (ADR-0002, V3).
 */
interface BWS_GP_Environment {

	/**
	 * Whether the current request is a singular view. Mirrors is_singular() (ADR-0002).
	 *
	 * @return bool
	 */
	public function is_singular();

	/**
	 * The query-level post id. Mirrors get_queried_object_id() — NEVER get_the_ID() (ADR-0002).
	 *
	 * @return int
	 */
	public function queried_object_id();

	/**
	 * Single post-meta value. Mirrors get_post_meta( $post_id, $key, true ).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return mixed '' when unset (WP convention — callers normalize, V23).
	 */
	public function post_meta( $post_id, $key );

	/**
	 * Whether $callback is attached to $hook. Mirrors has_filter()/has_action()
	 * (one method — WP stores actions and filters in the same hook table).
	 *
	 * @param string $hook     Hook name.
	 * @param string $callback Callback to look for.
	 * @return bool
	 */
	public function has_hook( $hook, $callback );

	/**
	 * IDs of published Layout Elements whose $disable_meta_key is non-empty.
	 *
	 * @param string $disable_meta_key e.g. '_generate_disable_site_header'.
	 * @return int[]
	 */
	public function layout_element_ids( $disable_meta_key );

	/**
	 * Whether GP's condition evaluator is available for config-replay.
	 *
	 * @return bool
	 */
	public function can_replay_conditions();

	/**
	 * GP's own condition evaluator — GeneratePress_Conditions::show_data().
	 * Callers pass arrays only, already normalized from '' meta (V23).
	 *
	 * @param array $display Display conditions.
	 * @param array $exclude Exclude conditions.
	 * @param array $users   User/role conditions.
	 * @return bool
	 */
	public function conditions_pass( $display, $exclude, $users );

	/**
	 * The resolved sidebar layout enum, or 'no-sidebar' when GP is absent.
	 *
	 * @return string 'left-sidebar'|'right-sidebar'|'no-sidebar'|'both-sidebars'
	 */
	public function sidebar_layout();
}

/**
 * Production adapter — the WordPress / GP Premium environment.
 */
class BWS_GP_WP_Environment implements BWS_GP_Environment {

	public function is_singular() {
		return is_singular();
	}

	public function queried_object_id() {
		return get_queried_object_id();
	}

	public function post_meta( $post_id, $key ) {
		return get_post_meta( $post_id, $key, true );
	}

	public function has_hook( $hook, $callback ) {
		return false !== has_filter( $hook, $callback );
	}

	public function layout_element_ids( $disable_meta_key ) {
		return get_posts( array(
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
	}

	public function can_replay_conditions() {
		return class_exists( 'GeneratePress_Conditions' );
	}

	public function conditions_pass( $display, $exclude, $users ) {
		return (bool) GeneratePress_Conditions::show_data( $display, $exclude, $users );
	}

	public function sidebar_layout() {
		if ( function_exists( 'generate_get_layout' ) ) {
			return generate_get_layout();
		}

		return 'no-sidebar';
	}
}
