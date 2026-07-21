<?php
/**
 * layout-states — environment seam fidelity test (T9 / V28).
 *
 * This file tests BWS_GP_WP_Environment, the PRODUCTION adapter, against a real
 * WordPress + GP Premium. It does NOT test the Detector.
 *
 * Why it exists: the unit suite runs the Detector against BWS_GP_Fake_Environment.
 * That fake ENCODES ASSUMPTIONS about what real WP/GP return — meta_query
 * semantics, show_data() arity and argument order, the generate_get_layout()
 * enum. If an assumption is wrong, or upstream changes it, every unit test still
 * passes and the plugin still breaks. This file is the only thing that pins the
 * fake to reality.
 *
 * Run:
 *   bin/wp.sh <site> eval-file /plugins/bws-generate-layout-conditions/tools/fixtures/layout-states/seam-fidelity.php
 *
 * Preconditions: layout-states seeded (and core-structures, for the `department`
 * taxonomy the archive case needs). Takes no --url — bootstraps its own query
 * per assertion, same as verify.php. See that file's header for why --url is
 * insufficient.
 *
 * Contrast with verify.php: verify asserts the FIXTURES are real and
 * discriminating. This asserts the ADAPTER reads them correctly.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "Run via wp-cli eval-file.\n";
	exit( 1 );
}

// The seam is not loaded by the plugin bootstrap in isolation; require directly.
require_once dirname( dirname( dirname( __DIR__ ) ) ) . '/includes/class-environment.php';

$manifest = require __DIR__ . '/manifest.php';

$pass = 0;
$fail = 0;

$ok  = function ( $msg ) use ( &$pass ) {
	$pass++;
	WP_CLI::log( '  PASS  ' . $msg );
};
$bad = function ( $msg ) use ( &$fail ) {
	$fail++;
	WP_CLI::log( '  FAIL  ' . $msg );
};

$by_name = function ( $post_type, $name ) {
	$ids = get_posts( array(
		'post_type'      => $post_type,
		'post_name__in'  => array( $name ),
		'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );

	return $ids ? (int) $ids[0] : 0;
};

/** Run $fn with the main query bootstrapped to $args, then restore. */
$with_query = function ( $args, callable $fn ) {
	global $wp_query, $wp_the_query;
	$saved_query = $wp_query;
	$saved_the   = $wp_the_query;

	wp( $args );
	$result = $fn();

	$wp_query     = $saved_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	$wp_the_query = $saved_the;   // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	wp_reset_postdata();

	return $result;
};

$env = new BWS_GP_WP_Environment();

WP_CLI::log( '' );
WP_CLI::log( 'layout-states seam fidelity (BWS_GP_WP_Environment)' );
WP_CLI::log( '' );

// ---------------------------------------------------------------------------
// 1. layout_element_ids() — the real meta_query.
//
// The fake returns a hand-written array keyed by disable meta key. That is only
// faithful if the real compare => '!=' clause actually behaves as assumed:
// matching elements with the key set non-empty, and — critically — EXCLUDING
// elements where the row was DELETED rather than set to ''. GP deletes the row
// when a Layout Element toggle is unset, and in MySQL a '!=' meta_query on a
// missing row does not match (no JOIN row to compare). Assert both directions.
// ---------------------------------------------------------------------------
WP_CLI::log( '1. layout_element_ids() — meta_query semantics' );

$el_header_footer   = $by_name( 'gp_elements', 'ls-el-layout-header-footer' );
$el_featured        = $by_name( 'gp_elements', 'ls-el-layout-featured-archive' );
$el_excluded        = $by_name( 'gp_elements', 'ls-el-layout-excluded' );

$header_ids = $env->layout_element_ids( '_generate_disable_site_header' );

is_array( $header_ids )
	? $ok( 'returns array' )
	: $bad( 'returns ' . gettype( $header_ids ) . ', not array' );

in_array( $el_header_footer, array_map( 'intval', $header_ids ), true )
	? $ok( "header-disable element #{$el_header_footer} found" )
	: $bad( "header-disable element #{$el_header_footer} NOT found — meta_query '!=' missed a set key" );

// ls-el-layout-featured-archive sets _generate_disable_featured_image but NOT
// _generate_disable_site_header — its row is absent. It must not appear here.
! in_array( $el_featured, array_map( 'intval', $header_ids ), true )
	? $ok( "featured-only element #{$el_featured} correctly absent (deleted row does not match '!=')" )
	: $bad( "featured-only element #{$el_featured} LEAKED into header query — '!=' matched a missing row" );

// Elements are filtered by _generate_element_type = 'layout'. Block Elements
// carry no disable meta, but assert the type clause is doing work: the returned
// set must contain only layout elements.
$non_layout = array();
foreach ( $header_ids as $id ) {
	if ( 'layout' !== get_post_meta( (int) $id, '_generate_element_type', true ) ) {
		$non_layout[] = (int) $id;
	}
}
$non_layout
	? $bad( 'non-layout elements returned: ' . implode( ',', $non_layout ) )
	: $ok( 'every returned id is _generate_element_type=layout' );

// Publish-only. GP's own loader queries publish-only (elements.php:36); the
// adapter must agree or it reports elements GP will never run.
$non_published = array();
foreach ( $header_ids as $id ) {
	if ( 'publish' !== get_post_status( (int) $id ) ) {
		$non_published[] = (int) $id;
	}
}
$non_published
	? $bad( 'non-published elements returned: ' . implode( ',', $non_published ) )
	: $ok( 'every returned id is published' );

// The featured-image key resolves its own distinct set (V22 / T8 path).
$featured_ids = array_map( 'intval', $env->layout_element_ids( '_generate_disable_featured_image' ) );
in_array( $el_featured, $featured_ids, true )
	? $ok( "featured-disable element #{$el_featured} found under its own key" )
	: $bad( "featured-disable element #{$el_featured} NOT found under _generate_disable_featured_image" );

// A key no element sets must return empty, not everything.
$bogus = $env->layout_element_ids( '_generate_disable_nonexistent_key_xyz' );
array() === $bogus || empty( $bogus )
	? $ok( 'unset key returns empty set' )
	: $bad( 'unset key returned ' . count( $bogus ) . ' ids — clause is not filtering' );

// ---------------------------------------------------------------------------
// 2. conditions_pass() — real show_data(), and the V23 '' fatal.
//
// V23 claims raw '' meta fatals show_data() and that normalizing to array()
// fixes it. The Detector normalizes; the fake never sees the raw value. If V23
// is wrong — or upstream started tolerating '' — the normalization is dead code
// nobody would notice. Prove the fatal is real, then prove the fix.
// ---------------------------------------------------------------------------
WP_CLI::log( '' );
WP_CLI::log( '2. conditions_pass() — show_data() against real query state' );

$env->can_replay_conditions()
	? $ok( 'can_replay_conditions() true (GeneratePress_Conditions present)' )
	: $bad( 'can_replay_conditions() FALSE — GP Elements module off; rest of section is vacuous' );

$page_excluded = $by_name( 'page', 'ls-page-excluded' );
$page_disabled = $by_name( 'page', 'ls-page-layout-disabled' );

$display = get_post_meta( $el_excluded, '_generate_element_display_conditions', true );
$exclude = get_post_meta( $el_excluded, '_generate_element_exclude_conditions', true );
$users   = get_post_meta( $el_excluded, '_generate_element_user_conditions', true );

// V4: three-arg replay. Display alone matches this page; exclude knocks it out.
// A two-arg replay would report the page disabled. This is the only fixture
// that can catch that, and it only discriminates under a real main query.
$display_only = $with_query( 'page_id=' . $page_excluded, function () use ( $env, $display ) {
	return $env->conditions_pass( $display, array(), array() );
} );

$all_three = $with_query( 'page_id=' . $page_excluded, function () use ( $env, $display, $exclude, $users ) {
	return $env->conditions_pass( $display, $exclude, $users );
} );

true === $display_only
	? $ok( 'display-only arm returns true (element would apply)' )
	: $bad( 'display-only arm returned ' . var_export( $display_only, true ) . ' — fixture no longer discriminates' );

false === $all_three
	? $ok( 'three-arg arm returns false (exclude honored) — V4 holds' )
	: $bad( 'three-arg arm returned ' . var_export( $all_three, true ) . ' — exclude/user args ignored by show_data()' );

// Return type: the Detector branches on this. A truthy non-bool would still
// work today and break the moment someone uses ===.
is_bool( $all_three )
	? $ok( 'returns a real bool' )
	: $bad( 'returns ' . gettype( $all_three ) . ' — adapter cast is not holding' );

// Positive control on a different page: the header/footer element's own
// display condition must pass on its target and fail elsewhere. Guards against
// show_data() degrading to "always false", which would make the V4 assertion
// above pass for the wrong reason.
$hf_display = get_post_meta( $el_header_footer, '_generate_element_display_conditions', true );

$hf_on_target = $with_query( 'page_id=' . $page_disabled, function () use ( $env, $hf_display ) {
	return $env->conditions_pass( $hf_display, array(), array() );
} );
$hf_off_target = $with_query( 'page_id=' . $page_excluded, function () use ( $env, $hf_display ) {
	return $env->conditions_pass( $hf_display, array(), array() );
} );

true === $hf_on_target
	? $ok( 'header/footer element passes on its target page' )
	: $bad( 'header/footer element FAILED on its own target — show_data() may be always-false' );

false === $hf_off_target
	? $ok( 'header/footer element fails off-target' )
	: $bad( 'header/footer element passed off-target — location rules not evaluating' );

// --- V23: the '' fatal ---------------------------------------------------
// get_post_meta() returns '' for an unset key (WP convention). Call show_data()
// with the raw value and confirm it is fatal, then confirm `?: array()` fixes
// it. Both arms are wrapped: if upstream ever tolerates '', the first arm stops
// throwing and this reports it instead of silently passing.
$raw_unset = get_post_meta( $el_header_footer, '_generate_element_exclude_conditions', true );

'' === $raw_unset
	? $ok( "unset condition meta reads as '' (V23 premise holds)" )
	: $bad( 'unset condition meta reads as ' . var_export( $raw_unset, true ) . " — not '', V23 premise changed" );

$raw_fataled = $with_query( 'page_id=' . $page_disabled, function () use ( $env, $hf_display, $raw_unset ) {
	try {
		$env->conditions_pass( $hf_display, $raw_unset, $raw_unset );
		return false;
	} catch ( \Throwable $e ) {
		return $e;
	}
} );

if ( $raw_fataled instanceof \Throwable ) {
	$ok( "raw '' is fatal to show_data(): " . get_class( $raw_fataled ) . ' — ' . $raw_fataled->getMessage() );
} else {
	$bad( "raw '' did NOT fatal — V23 no longer holds upstream; normalization may now be dead code" );
}

$normalized = $with_query( 'page_id=' . $page_disabled, function () use ( $env, $hf_display, $raw_unset ) {
	return $env->conditions_pass( $hf_display, $raw_unset ?: array(), $raw_unset ?: array() );
} );

true === $normalized
	? $ok( "`?: array()` normalization fixes it (V23 remedy verified)" )
	: $bad( 'normalized call returned ' . var_export( $normalized, true ) . ' — expected true' );

// ---------------------------------------------------------------------------
// 3. sidebar_layout() — the real enum.
//
// V26 does membership math against a fixed set of layout strings. If GP ever
// returns a value outside it, that math dies silently. The fake just hands back
// whatever string a test set. Assert the real function returns exactly the
// documented enum for each seeded per-post layout.
// ---------------------------------------------------------------------------
WP_CLI::log( '' );
WP_CLI::log( '3. sidebar_layout() — generate_get_layout() enum' );

$expected_enum = array( 'left-sidebar', 'right-sidebar', 'no-sidebar', 'both-sidebars' );

function_exists( 'generate_get_layout' )
	? $ok( 'generate_get_layout() exists (adapter will not fall back)' )
	: $bad( 'generate_get_layout() MISSING — adapter silently returns no-sidebar for every request' );

$sidebar_cases = array(
	'ls-page-sidebar-left'  => 'left-sidebar',
	'ls-page-sidebar-right' => 'right-sidebar',
	'ls-page-sidebar-both'  => 'both-sidebars',
	'ls-page-sidebar-none'  => 'no-sidebar',
);

foreach ( $sidebar_cases as $slug => $expected ) {
	$pid = $by_name( 'page', $slug );

	if ( ! $pid ) {
		$bad( "{$slug} MISSING — cannot check sidebar enum" );
		continue;
	}

	$actual = $with_query( 'page_id=' . $pid, function () use ( $env ) {
		return $env->sidebar_layout();
	} );

	if ( $actual === $expected ) {
		$ok( "{$slug} → '{$actual}'" );
		continue;
	}

	in_array( $actual, $expected_enum, true )
		? $bad( "{$slug} → '{$actual}', expected '{$expected}' — per-post metabox not honored" )
		: $bad( "{$slug} → '{$actual}' — OUTSIDE the documented enum; V26 membership math is unsafe" );
}

// ---------------------------------------------------------------------------
// 4. The remaining seam methods, on a real request.
// ---------------------------------------------------------------------------
WP_CLI::log( '' );
WP_CLI::log( '4. Request-state methods' );

$page_baseline = $by_name( 'page', 'ls-page-baseline' );

$state = $with_query( 'page_id=' . $page_baseline, function () use ( $env ) {
	return array(
		'singular' => $env->is_singular(),
		'queried'  => $env->queried_object_id(),
	);
} );

true === $state['singular']
	? $ok( 'is_singular() true under a page query' )
	: $bad( 'is_singular() returned ' . var_export( $state['singular'], true ) . ' under a page query' );

$state['queried'] === $page_baseline
	? $ok( "queried_object_id() === #{$page_baseline}" )
	: $bad( "queried_object_id() returned {$state['queried']}, expected {$page_baseline}" );

// Non-singular control: the V22/T8 branch depends on is_singular() actually
// going false on an archive. Uses the core-structures `department:sales` term —
// this blueprint's one foreign dependency.
$sales = get_term_by( 'slug', 'sales', 'department' );

if ( $sales && ! is_wp_error( $sales ) ) {
	$arch = $with_query( 'department=sales', function () use ( $env ) {
		return array(
			'singular' => $env->is_singular(),
			'queried'  => $env->queried_object_id(),
		);
	} );

	false === $arch['singular']
		? $ok( 'is_singular() false on the department:sales archive' )
		: $bad( 'is_singular() TRUE on an archive — non-singular branch is unreachable' );

	$arch['queried'] === (int) $sales->term_id
		? $ok( 'queried_object_id() returns the term id on an archive' )
		: $bad( "queried_object_id() returned {$arch['queried']} on archive, expected term {$sales->term_id}" );
} else {
	$bad( 'department:sales term MISSING — seed core-structures first (foreign dependency)' );
}

// post_meta(): '' for unset is the contract the fake implements and V23 rests on.
'' === $env->post_meta( $page_baseline, '_generate_disable_nonexistent_key_xyz' )
	? $ok( "post_meta() returns '' for an unset key" )
	: $bad( 'post_meta() returned something other than \'\' for an unset key' );

// has_hook(): assert against a hook WP itself always has, so a false here means
// the adapter is broken rather than the fixture being wrong.
$env->has_hook( 'wp_head', 'wp_enqueue_scripts' )
	? $ok( 'has_hook() finds a known core callback' )
	: $bad( 'has_hook() missed a known core callback — has_filter() wrapper is broken' );

! $env->has_hook( 'wp_head', 'bws_gp_definitely_not_attached' )
	? $ok( 'has_hook() false for an unattached callback' )
	: $bad( 'has_hook() true for a callback that is not attached' );

// ---------------------------------------------------------------------------
WP_CLI::log( '' );

if ( $fail ) {
	WP_CLI::error( "{$pass} passed, {$fail} FAILED" );
}

WP_CLI::success( "{$pass} passed, 0 failed" );
