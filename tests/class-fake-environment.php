<?php
/**
 * In-memory fake — the second adapter at the environment seam (T9).
 *
 * Configure request state + content via public properties; counters record
 * how the Detector exercised the seam (memoization + replay assertions).
 */
class BWS_GP_Fake_Environment implements BWS_GP_Environment {

	/** @var bool */
	public $singular = false;

	/** @var int */
	public $queried_id = 0;

	/** @var array post_id => [ meta_key => value ] */
	public $meta = array();

	/** @var array "hook|callback" strings that are attached */
	public $hooks = array();

	/** @var array disable_meta_key => int[] layout element ids */
	public $layout_elements = array();

	/** @var bool */
	public $replay_available = true;

	/** @var bool|callable show_data verdict, or callable( $display, $exclude, $users ) */
	public $conditions_result = true;

	/** @var string */
	public $sidebar = 'no-sidebar';

	/** @var array method => call count */
	public $calls = array();

	/** @var array recorded conditions_pass() argument triples */
	public $conditions_args = array();

	private function tally( $method ) {
		$this->calls[ $method ] = isset( $this->calls[ $method ] ) ? $this->calls[ $method ] + 1 : 1;
	}

	public function is_singular() {
		$this->tally( 'is_singular' );
		return $this->singular;
	}

	public function queried_object_id() {
		$this->tally( 'queried_object_id' );
		return $this->queried_id;
	}

	public function post_meta( $post_id, $key ) {
		$this->tally( 'post_meta' );
		return isset( $this->meta[ $post_id ][ $key ] ) ? $this->meta[ $post_id ][ $key ] : '';
	}

	public function has_hook( $hook, $callback ) {
		$this->tally( 'has_hook' );
		return in_array( $hook . '|' . $callback, $this->hooks, true );
	}

	public function layout_element_ids( $disable_meta_key ) {
		$this->tally( 'layout_element_ids' );
		return isset( $this->layout_elements[ $disable_meta_key ] ) ? $this->layout_elements[ $disable_meta_key ] : array();
	}

	public function can_replay_conditions() {
		$this->tally( 'can_replay_conditions' );
		return $this->replay_available;
	}

	public function conditions_pass( $display, $exclude, $users ) {
		$this->tally( 'conditions_pass' );
		$this->conditions_args[] = array( $display, $exclude, $users );

		if ( is_callable( $this->conditions_result ) ) {
			return call_user_func( $this->conditions_result, $display, $exclude, $users );
		}

		return $this->conditions_result;
	}

	public function sidebar_layout() {
		$this->tally( 'sidebar_layout' );
		return $this->sidebar;
	}
}
