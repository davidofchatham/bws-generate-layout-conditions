<?php
/**
 * layout-states blueprint — post-seed smoke test.
 *
 * Asserts the fixtures LANDED and DISCRIMINATE. Not a replacement for the
 * env test-suite: it checks that the ground truth those tests stand on is
 * real, so a suite failure means "the Detector regressed" rather than "the
 * fixture silently seeded nothing".
 *
 * Run:
 *   bin/wp.sh <site> eval-file /plugins/bws-generate-layout-conditions/tools/fixtures/layout-states/verify.php
 *
 * NOTE ON QUERY STATE — the thing that makes this file necessary.
 * GeneratePress_Conditions::show_data() evaluates against the CURRENT REQUEST.
 * Under `wp eval-file` no main query has run: is_singular() is false and
 * get_queried_object_id() is 0, so every location rule misses and show_data()
 * returns false for BOTH arms of the V4 check — a vacuous pass that looks
 * green. `--url` does not fix this; it sets site context without running the
 * query. The fix is to bootstrap the query explicitly with wp( 'page_id=N' ),
 * which is what with_page() below does.
 *
 * THIS FILE TAKES NO --url, DELIBERATELY. It resolves its own fixture IDs and
 * bootstraps a different query per assertion (V4 wants ls-page-excluded; the
 * sidebar and archive checks will want other targets), restoring $wp_query
 * afterwards. One external --url cannot serve many targets. So the absence of
 * a VERIFY_PATH entry in the env's bin/seed-all.sh is intentional, not an
 * oversight — contrast core-structures, which calls bare wp() and does require
 * one. Confirmed working: the V4 check reports discriminating results under
 * seed-all with no --url passed.
 *
 * WHAT THIS FILE CANNOT COVER: rendered output. Whether GP actually emits
 * `#mobile-header`, or whether CSS-neutralize re-exposes it (V24/V25), is not
 * observable from wp-cli at any query state — those need a real HTTP request
 * (see the dcurl helper in the env's bin/smoke.sh).
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "Run via wp-cli eval-file.\n";
	exit( 1 );
}

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
		'name'           => $name,
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );

	return $ids ? (int) $ids[0] : 0;
};

/**
 * Run $fn with the main query bootstrapped to $page_id, then restore.
 *
 * Without this, every conditional below silently evaluates against an empty
 * query — see the file header.
 */
$with_page = function ( $page_id, callable $fn ) {
	global $wp_query, $wp_the_query;
	$saved_query = $wp_query;
	$saved_the   = $wp_the_query;

	wp( 'page_id=' . (int) $page_id );
	$result = $fn();

	$wp_query     = $saved_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	$wp_the_query = $saved_the;   // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	wp_reset_postdata();

	return $result;
};

WP_CLI::log( '' );
WP_CLI::log( 'layout-states verify' );
WP_CLI::log( '' );

// ---------------------------------------------------------------------------
// 1. Every fixture exists.
// ---------------------------------------------------------------------------
WP_CLI::log( '1. Fixtures present' );

$page_ids    = array();
$element_ids = array();

foreach ( $manifest['pages'] as $slug => $page ) {
	$id = $by_name( 'page', $page['post_name'] );
	$page_ids[ $slug ] = $id;
	$id ? $ok( "page {$slug} (#{$id})" ) : $bad( "page {$slug} MISSING" );
}

foreach ( $manifest['elements'] as $slug => $element ) {
	$id = $by_name( 'gp_elements', $element['post_name'] );
	$element_ids[ $slug ] = $id;

	if ( ! $id ) {
		$bad( "element {$slug} MISSING" );
		continue;
	}

	// publish is not cosmetic: GP's element loader queries publish-only
	// (elements.php:36), so a draft fixture is invisible to the whole system.
	if ( 'publish' !== get_post_status( $id ) ) {
		$bad( "element {$slug} (#{$id}) is not published — GP will never load it" );
		continue;
	}

	$ok( "element {$slug} (#{$id})" );
}

// ---------------------------------------------------------------------------
// 2. Meta shapes — the layer most likely to drift silently.
// ---------------------------------------------------------------------------
WP_CLI::log( '' );
WP_CLI::log( '2. Meta shapes' );

// Layout disables are the literal string 'true'. A bool or 1 here would still
// be truthy at runtime but would not match what the admin metabox writes.
$layout_id = $element_ids['ls-el-layout-header-footer'];
if ( $layout_id ) {
	$v = get_post_meta( $layout_id, '_generate_disable_site_header', true );
	'true' === $v
		? $ok( "layout disable meta is string 'true'" )
		: $bad( sprintf( "layout disable meta is %s (%s) — expected string 'true'", var_export( $v, true ), gettype( $v ) ) );
}

// Unset means ROW ABSENT, never ''. GP's metabox deletes rather than storing
// a falsy value, so an empty-string row is a state the UI cannot produce.
if ( $layout_id ) {
	$absent = get_post_meta( $layout_id, '_generate_disable_top_bar', true );
	'' === $absent && ! metadata_exists( 'post', $layout_id, '_generate_disable_top_bar' )
		? $ok( 'unset disable meta has NO row (matches GP delete-on-unset)' )
		: $bad( 'unset disable meta wrote a row — fixture diverges from admin UI' );
}

// Conditions must be arrays of array( rule, object ), object a STRING.
$exc_id = $element_ids['ls-el-layout-excluded'];
if ( $exc_id ) {
	$disp = get_post_meta( $exc_id, '_generate_element_display_conditions', true );

	if ( is_array( $disp ) && isset( $disp[0]['rule'], $disp[0]['object'] ) ) {
		$ok( 'display conditions shaped array( rule, object )' );
		is_string( $disp[0]['object'] )
			? $ok( 'condition object is a string (matches sanitize_key output)' )
			: $bad( 'condition object is ' . gettype( $disp[0]['object'] ) . ' — admin UI writes a string' );
	} else {
		$bad( 'display conditions malformed: ' . wp_json_encode( $disp ) );
	}

	$users = get_post_meta( $exc_id, '_generate_element_user_conditions', true );
	is_array( $users ) && isset( $users[0] ) && is_string( $users[0] )
		? $ok( 'user conditions are a flat string list' )
		: $bad( 'user conditions malformed: ' . wp_json_encode( $users ) );
}

// ---------------------------------------------------------------------------
// 3. The prod adapter's query finds the layout elements (seam fidelity).
//
// BWS_GP_WP_Environment::layout_element_ids() uses compare '!=' against ''.
// GP DELETES unset rows, so this is really an existence test — worth pinning,
// because a GP change to delete-on-unset would break it silently.
// ---------------------------------------------------------------------------
WP_CLI::log( '' );
WP_CLI::log( '3. Adapter query (seam fidelity)' );

$found = get_posts( array(
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
			'key'     => '_generate_disable_site_header',
			'value'   => '',
			'compare' => '!=',
		),
	),
) );

in_array( $layout_id, $found, true )
	? $ok( 'layout_element_ids() finds the header-disabling element' )
	: $bad( 'layout_element_ids() MISSED the header-disabling element' );

// The featured-image element must NOT appear — it disables a different key.
$fa_id = $element_ids['ls-el-layout-featured-archive'];
! in_array( $fa_id, $found, true )
	? $ok( 'layout_element_ids() correctly excludes non-matching keys' )
	: $bad( 'layout_element_ids() returned an element with no header disable' );

// ---------------------------------------------------------------------------
// 4. V4 — exclude conditions actually discriminate.
//
// THE fixture that justifies passing all three metas to show_data(). If both
// arms agree, the fixture proves nothing and a two-arg replay would ship
// undetected.
// ---------------------------------------------------------------------------
WP_CLI::log( '' );
WP_CLI::log( '4. V4 — exclude discriminates' );

if ( ! class_exists( 'GeneratePress_Conditions' ) ) {
	$bad( 'GeneratePress_Conditions ABSENT — GP Premium Elements module inactive; config-replay cannot be verified' );
} elseif ( $exc_id && $page_ids['ls-page-excluded'] ) {
	$display = get_post_meta( $exc_id, '_generate_element_display_conditions', true ) ?: array();
	$exclude = get_post_meta( $exc_id, '_generate_element_exclude_conditions', true ) ?: array();
	$users   = get_post_meta( $exc_id, '_generate_element_user_conditions', true ) ?: array();

	$result = $with_page( $page_ids['ls-page-excluded'], function () use ( $display, $exclude, $users ) {
		return array(
			'display_only' => (bool) GeneratePress_Conditions::show_data( $display, array(), array() ),
			'all_three'    => (bool) GeneratePress_Conditions::show_data( $display, $exclude, $users ),
		);
	} );

	if ( true === $result['display_only'] && false === $result['all_three'] ) {
		$ok( 'exclude flips the verdict (display-only=true, all-three=false)' );
	} else {
		$bad( sprintf(
			'exclude does NOT discriminate (display-only=%s, all-three=%s) — V4 test would pass vacuously. Did the main query bootstrap?',
			var_export( $result['display_only'], true ),
			var_export( $result['all_three'], true )
		) );
	}
}

// ---------------------------------------------------------------------------
// 5. Foreign dependency — V22 needs a POPULATED archive.
// ---------------------------------------------------------------------------
WP_CLI::log( '' );
WP_CLI::log( '5. Foreign dependency (core-structures)' );

$sales = get_term_by( 'slug', 'sales', 'department' );
if ( ! $sales ) {
	$bad( 'department:sales missing — reseed core-structures' );
} elseif ( 0 === (int) $sales->count ) {
	$bad( 'department:sales has 0 posts — /department/sales/ 404s and the V22 test would pass vacuously' );
} else {
	$ok( sprintf( 'department:sales carries %d post(s)', $sales->count ) );
}

WP_CLI::log( '' );
WP_CLI::log( sprintf( 'Result: %d passed, %d failed', $pass, $fail ) );
WP_CLI::log( '' );

if ( $fail > 0 ) {
	WP_CLI::error( sprintf( '%d verification(s) failed', $fail ) );
}
