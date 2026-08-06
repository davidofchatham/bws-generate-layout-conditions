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

// GB Pro conditions (v8). Publish status is not cosmetic here either, and it
// fails in the DANGEROUS direction: check_block_conditions() returns the block
// content UNTOUCHED for any status other than publish
// (block-conditions.php:77), so a draft condition is not a failed condition —
// it is no condition, and every conditioned marker renders unconditionally.
$condition_ids = array();

foreach ( $manifest['conditions'] as $slug => $condition ) {
	$id = $by_name( 'gblocks_condition', $condition['post_name'] );
	$condition_ids[ $slug ] = $id;

	if ( ! $id ) {
		$bad( "condition {$slug} MISSING" );
		continue;
	}

	if ( 'publish' !== get_post_status( $id ) ) {
		$bad( "condition {$slug} (#{$id}) is not published — GB Pro treats a non-published condition as NO condition and renders the block unconditionally" );
		continue;
	}

	$ok( "condition {$slug} (#{$id})" );
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

// Block elements whose type GP's switch does not name need _generate_hook.
// `page-hero` and `hook` are both in that set (class-block.php:110-140), so with
// the key absent the loader returns at `if ( ! $hook )` — before registering
// either the render callback or the wp:100 remove_elements that performs the
// relocation. ls-el-page-hero was in exactly that state from v1 to v6 and was
// invisible: it existed, was published, and carried both toggle keys. Third of
// the B6/B7 family. The archive marker element (v8) is checked by the same rule
// because it fails the same silent way, and its failure would take the whole
// archive row of the combination table down with it.
foreach ( array( 'ls-el-page-hero', 'ls-el-block-archive-markers' ) as $block_slug ) {
	$block_id = isset( $element_ids[ $block_slug ] ) ? $element_ids[ $block_slug ] : 0;

	if ( ! $block_id ) {
		continue;
	}

	$block_hook = get_post_meta( $block_id, '_generate_hook', true );
	'' !== $block_hook
		? $ok( "{$block_slug} carries _generate_hook ({$block_hook}) — GP will load it" )
		: $bad( "{$block_slug} has NO _generate_hook — GP returns before registering it, so the element renders nothing and removes nothing. It is inert, silently. Reseed layout-states at v8+." );
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

// ---------------------------------------------------------------------------
// 6. Archive elements actually APPLY on the archive (added v4).
//
// The gap this closes bit for three blueprint versions. Both archive elements
// stored their display-condition `object` as the term SLUG. GP resolves a
// taxonomy archive to rule `taxonomy:{taxonomy}` with object = the term **ID**
// (class-conditions.php:225-231) and compares with a non-strict in_array(), so
// under PHP 8 `7 == 'sales'` is false and neither element ever applied to
// /department/sales/. Every existing check still passed: the elements existed,
// were published, carried the right meta, and answered the right meta_query.
// Nothing evaluated their conditions against a real archive query — the exact
// "fixture that verifies itself and proves nothing" shape the README warns of.
//
// Requires a bootstrapped ARCHIVE query, not a page one: show_data() reads the
// current request, and with_page() cannot produce is_tax().
// ---------------------------------------------------------------------------
WP_CLI::log( '' );
WP_CLI::log( '6. Archive elements apply on the archive' );

if ( ! class_exists( 'GeneratePress_Conditions' ) ) {
	$bad( 'GeneratePress_Conditions ABSENT — cannot verify archive element conditions' );
} elseif ( ! $sales ) {
	$bad( 'department:sales missing — archive element conditions unverifiable' );
} else {
	global $wp_query, $wp_the_query;
	$saved_query = $wp_query;
	$saved_the   = $wp_the_query;

	wp( 'department=sales' );

	$on_archive = is_tax( 'department', 'sales' );

	$archive_results = array();
	// ls-el-layout-secondary-nav added v5. It carries TWO display conditions
	// (a page and this archive) where the other two carry one, so it is also the
	// only fixture proving a multi-condition element still matches the archive
	// arm — show_data() ORs the display list, but a fixture written as one
	// condition would never catch a regression to AND.
	// ls-el-block-archive-markers added v8. It is the ONLY carrier of the
	// combination table's archive row — an archive has no post_content, so if
	// this element misses the request, all three markers are absent there and
	// the two absence assertions in render-surface.sh §9 pass vacuously. The
	// render harness has its own control for that (the unconditioned marker),
	// but this is the check that names the CAUSE rather than the symptom.
	foreach ( array( 'ls-el-layout-featured-archive', 'ls-el-layout-title-archive', 'ls-el-layout-secondary-nav', 'ls-el-block-archive-markers' ) as $slug ) {
		$eid = isset( $element_ids[ $slug ] ) ? $element_ids[ $slug ] : 0;

		if ( ! $eid ) {
			$archive_results[ $slug ] = null;
			continue;
		}

		$archive_results[ $slug ] = (bool) GeneratePress_Conditions::show_data(
			get_post_meta( $eid, '_generate_element_display_conditions', true ) ?: array(),
			get_post_meta( $eid, '_generate_element_exclude_conditions', true ) ?: array(),
			get_post_meta( $eid, '_generate_element_user_conditions', true ) ?: array()
		);
	}

	$wp_query     = $saved_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	$wp_the_query = $saved_the;   // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	wp_reset_postdata();

	// Without this the two checks below would pass vacuously on a query that
	// never became an archive.
	$on_archive
		? $ok( 'archive query bootstrapped (is_tax department:sales)' )
		: $bad( 'wp( "department=sales" ) did not produce a taxonomy archive — the checks below prove nothing' );

	foreach ( $archive_results as $slug => $applies ) {
		if ( null === $applies ) {
			$bad( "{$slug} MISSING — cannot check whether it applies on the archive" );
			continue;
		}

		$applies
			? $ok( "{$slug} applies on /department/sales/" )
			: $bad( "{$slug} does NOT apply on /department/sales/ — its display-condition object must be the term ID, not the slug (GP compares term_id; PHP 8 makes 7 == 'sales' false). Reseed at blueprint v4+." );
	}
}

// ---------------------------------------------------------------------------
// 7. Featured-image render path is PINNED, not inherited (added v6, B8).
//
// The featured image has two render paths. The GP Premium Blog module picks:
// active, it removes the theme's page-header actions unconditionally at wp:50
// (blog/functions/images.php:164-165) and renders its own callback instead. The
// module state was never pinned here, and testbed had it OFF — so every
// featured-image assertion in this blueprint ran against the theme path alone,
// and a suppression covering only that path passed green while doing nothing on
// a Blog-module-active site. That is B8, and it was live on `hargrave`.
//
// Checked here rather than only in the render harness because the harness reads
// the CONSEQUENCE (where the image lands in the body) and this reads the CAUSE.
// A failure here names the setting; a failure there names the symptom.
// ---------------------------------------------------------------------------
WP_CLI::log( '' );
WP_CLI::log( '7. Featured-image path pinned (B8)' );

'activated' === get_option( 'generate_package_blog' )
	? $ok( 'GP Premium Blog module ACTIVE — the blog render path is the live one' )
	: $bad( 'GP Premium Blog module inactive — the theme page-header path is live instead, and every featured-image assertion here and in render-surface.sh tests only half the surface (B8). wp option update generate_package_blog activated' );

$blog_settings = wp_parse_args(
	get_option( 'generate_blog_settings', array() ),
	function_exists( 'generate_blog_get_defaults' ) ? generate_blog_get_defaults() : array()
);

// Every fixture page here is a `page`, so page_* is the live pair. Both halves
// matter: with the flag off the module renders nothing, which is indistinguishable
// from a working suppression.
! empty( $blog_settings['page_post_image'] )
	? $ok( 'blog setting page_post_image ON — the image actually renders on fixture pages' )
	: $bad( 'blog setting page_post_image is OFF — the module renders no image at all, so the suppression assertions cannot fail. Reseed layout-states at v6+.' );

'inside-content' === ( $blog_settings['page_post_image_position'] ?? '' )
	? $ok( 'blog setting page_post_image_position = inside-content — renders inside #content, where the theme path never does' )
	: $bad( sprintf(
		'blog setting page_post_image_position = %s, expected inside-content. The render harness tells the two paths apart by POSITION (both wrappers carry page-header-image on a page), and at above-content the blog path lands on generate_after_header — the same hook as the theme path. Reseed layout-states at v6+.',
		var_export( $blog_settings['page_post_image_position'] ?? null, true )
	) );

// ---------------------------------------------------------------------------
// 8. The v8 fixtures — GB Pro conditions, the blocks that reference them, and
//    the kill-switch element (issue #5).
//
// RUNS LAST, and that is load-bearing. The last block bootstraps a query on
// ls-page-featured-kill, which fires `wp` — so GP's element loader applies the
// kill-switch Layout Element and its five remove_action() calls take effect
// process-globally for everything after it. Nothing below reads featured-image
// hook state, and nothing must be added below that does. (§6 already mutates
// hook state the same way via the archive elements; poisoned-signal.php exists
// as a separate process because ITS mutations are the point rather than a side
// effect.)
//
// What this section is for: the ticket's bar is that a fixture is proven to
// APPLY TO THE REQUEST IT TARGETS, never merely to exist. B6 was a fixture that
// matched no request; B9 was one nothing looked at. The new surfaces here have a
// third failure shape available to them — a condition post that GB Pro finds but
// this plugin's registry cannot answer — so all three are checked: the value is
// stored in the shape the consumer reads (sanitizer round-trip), the type and
// rule slugs resolve against the LIVE registry, and the block attribute holds a
// real post ID rather than an unresolved placeholder.
// ---------------------------------------------------------------------------
WP_CLI::log( '' );
WP_CLI::log( '8. GB Pro conditions + the kill switch (v8, issue #5)' );

// The feature flag, not just the plugin. GB Pro gates the entire render_block
// filter on it (block-conditions.php:22): off, the conditions are stored,
// readable, and never consulted — so every marker renders and every presence
// assertion in render-surface.sh §9 goes green while no rule is evaluated.
if ( ! function_exists( 'generateblocks_pro_block_conditions_enabled' ) ) {
	$bad( 'generateblocks_pro_block_conditions_enabled() ABSENT — GB Pro is not loaded, so no marker block is conditioned on anything.' );
} else {
	generateblocks_pro_block_conditions_enabled()
		? $ok( 'GB Pro block conditions ENABLED — the render_block filter that reads gbBlockCondition is live' )
		: $bad( 'GB Pro block conditions are DISABLED — every conditioned marker block renders unconditionally, so the combination table would pass without evaluating a rule.' );
}

if ( ! class_exists( 'GenerateBlocks_Pro_Conditions' ) ) {
	$bad( 'GenerateBlocks_Pro_Conditions ABSENT — condition shape and registry cannot be verified' );
} else {
	$gb_types = GenerateBlocks_Pro_Conditions::get_condition_types();

	foreach ( $manifest['conditions'] as $slug => $condition ) {
		$cid = $condition_ids[ $slug ];

		if ( ! $cid ) {
			$bad( "condition {$slug} MISSING — cannot verify its shape" );
			continue;
		}

		$stored = get_post_meta( $cid, '_gb_conditions', true );

		// Sanitizer round-trip. `_gb_conditions` is registered meta carrying GB
		// Pro's own sanitize_callback, so re-running it must be a no-op on a
		// correctly stored value. A difference means the fixture holds something
		// the REST path would have rewritten — i.e. a shape the admin UI cannot
		// produce, which is the standing disqualifier here.
		$resanitized = GenerateBlocks_Pro_Conditions::get_instance()->sanitize_conditions( $stored );

		$stored === $resanitized
			? $ok( "{$slug}: _gb_conditions is in GB Pro's own sanitized shape (REST-identical)" )
			: $bad( sprintf(
				'%s: _gb_conditions is NOT what GB Pro would store. stored=%s sanitized=%s',
				$slug,
				wp_json_encode( $stored ),
				wp_json_encode( $resanitized )
			) );

		$rule_row = $stored['groups'][0]['conditions'][0] ?? array();

		if ( empty( $rule_row['type'] ) || empty( $rule_row['rule'] ) || empty( $rule_row['operator'] ) ) {
			$bad( "{$slug}: condition row is missing type/rule/operator — evaluate_single_condition() returns false for any of these, so the block would never render" );
			continue;
		}

		// B6's shape on a new consumer. Both slugs are plain strings GB Pro looks
		// up and silently no-ops on: an unregistered type makes
		// evaluate_single_condition() return false, and an unknown rule makes
		// BWS_GP_Theme_Element_Condition::evaluate() fall through to
		// $match = false. Neither is an error — the fixture simply inverts into
		// "always hidden" and every absence assertion in §9 passes for the wrong
		// reason. Checked against the LIVE registry, not against a literal.
		if ( ! isset( $gb_types[ $rule_row['type'] ] ) ) {
			$bad( sprintf(
				'%s: condition type "%s" is NOT registered — GB Pro evaluates it to false, so the block is hidden everywhere. Registered: %s',
				$slug,
				$rule_row['type'],
				implode( ', ', array_keys( $gb_types ) )
			) );
			continue;
		}

		$ok( "{$slug}: condition type {$rule_row['type']} is registered with GB Pro" );

		$type_rules = GenerateBlocks_Pro_Conditions::get_condition_rules( $rule_row['type'] );

		isset( $type_rules[ $rule_row['rule'] ] )
			? $ok( "{$slug}: rule {$rule_row['rule']} is answerable by {$rule_row['type']}" )
			: $bad( sprintf(
				'%s: rule "%s" is not one of %s\'s rules — evaluate() falls through to false, so the block never renders. Available: %s',
				$slug,
				$rule_row['rule'],
				$rule_row['type'],
				implode( ', ', array_keys( $type_rules ) )
			) );

		in_array( $rule_row['operator'], (array) ( $gb_types[ $rule_row['type'] ]['operators'] ?? array() ), true )
			? $ok( "{$slug}: operator {$rule_row['operator']} is offered by {$rule_row['type']}" )
			: $bad( sprintf( '%s: operator "%s" is not one this condition type registers', $slug, $rule_row['operator'] ) );
	}
}

// The block attributes actually carry the condition IDs.
//
// The failure this catches is the one that fails GREEN in every direction: an
// unresolved {{condition:...}} placeholder makes absint() return 0, GB Pro reads
// 0 as "no condition set" and returns the block content untouched
// (block-conditions.php:70), so the marker renders on every page and the
// combination table's presence assertions all pass while its absence assertions
// all fail — which reads like a Detector regression, not a seeding one.
//
// Checked on the STORED post_content of every fixture in the table, page and
// element alike, because a seeder that resolved one and not the other is exactly
// the sort of thing that has happened here before (B7).
$expected_condition_ids = array_values( array_filter( $condition_ids ) );

$marker_carriers = array(
	'page:ls-page-baseline'            => $page_ids['ls-page-baseline'] ?? 0,
	'page:ls-page-metabox-featured'    => $page_ids['ls-page-metabox-featured'] ?? 0,
	'page:ls-page-hero'                => $page_ids['ls-page-hero'] ?? 0,
	'page:ls-page-featured-kill'       => $page_ids['ls-page-featured-kill'] ?? 0,
	'element:ls-el-block-archive-markers' => $element_ids['ls-el-block-archive-markers'] ?? 0,
);

foreach ( $marker_carriers as $label => $carrier_id ) {
	if ( ! $carrier_id ) {
		$bad( "{$label} MISSING — cannot verify its conditioned marker blocks" );
		continue;
	}

	$content = get_post_field( 'post_content', $carrier_id );

	if ( false !== strpos( $content, '{{condition:' ) ) {
		$bad( "{$label} still holds an UNRESOLVED {{condition:...}} placeholder — absint() makes it 0, GB Pro reads 0 as 'no condition' and the marker renders unconditionally. Reseed at v8+." );
		continue;
	}

	$attached = array();
	foreach ( parse_blocks( $content ) as $block ) {
		if ( ! empty( $block['attrs']['gbBlockCondition'] ) ) {
			$attached[] = (int) $block['attrs']['gbBlockCondition'];
		}
	}

	sort( $attached );
	$wanted = $expected_condition_ids;
	sort( $wanted );

	$attached === $wanted
		? $ok( sprintf( '%s carries one block per condition (#%s)', $label, implode( ', #', $attached ) ) )
		: $bad( sprintf(
			'%s attaches conditions %s, expected %s — the marker blocks and the seeded condition posts have come apart.',
			$label,
			wp_json_encode( $attached ),
			wp_json_encode( $wanted )
		) );
}

// BOTH "slot not active" singular pages need a real thumbnail. Without one GP
// renders no page-header-image there under ANY configuration, and §9's central
// observation — that the slot really is left empty — would be true of a page the
// element never touched. Same vacuous-pass shape as the v2 thumbnail bug on
// ls-page-metabox-featured, and it applies to the Hero exactly as it does to the
// kill switch: both assert the absence of a wrapper GP would otherwise draw.
$kill_page_id = $page_ids['ls-page-featured-kill'] ?? 0;

foreach ( array( 'ls-page-featured-kill', 'ls-page-hero' ) as $thumb_slug ) {
	$thumb_id = $page_ids[ $thumb_slug ] ?? 0;

	if ( ! $thumb_id ) {
		$bad( "{$thumb_slug} MISSING — reseed layout-states at v8+" );
		continue;
	}

	has_post_thumbnail( $thumb_id )
		? $ok( "{$thumb_slug} carries a thumbnail — GP would draw an image here if nothing removed its callbacks" )
		: $bad( "{$thumb_slug} has NO thumbnail — the \"GP leaves the slot empty\" assertion in render-surface.sh §9 would pass against a page that renders no image under any configuration. Reseed layout-states at v8+." );
}

// Does the kill switch apply to the request it targets — and only to it?
//
// One arm is not enough. An always-true show_data() (or a condition list GP
// treats as site-wide) would satisfy the on-target check while quietly disabling
// the featured image everywhere, including the baseline that every absence
// assertion in §9 is read against. So both arms, same discipline as §4.
//
// LAST, deliberately: bootstrapping ls-page-featured-kill fires `wp`, GP applies
// the element, and its five remove_action() calls persist for the rest of this
// process.
if ( ! class_exists( 'GeneratePress_Conditions' ) ) {
	$bad( 'GeneratePress_Conditions ABSENT — cannot verify the kill-switch element applies' );
} else {
	$kill_id = $element_ids['ls-el-layout-featured-kill'] ?? 0;

	if ( ! $kill_id ) {
		$bad( 'ls-el-layout-featured-kill MISSING — reseed layout-states at v8+' );
	} else {
		$kill_meta = get_post_meta( $kill_id, '_generate_disable_featured_image', true );

		'true' === $kill_meta
			? $ok( "kill switch carries _generate_disable_featured_image = 'true' (the LAYOUT element key)" )
			: $bad( sprintf(
				'kill switch _generate_disable_featured_image is %s — expected the string \'true\'. GP reads only this key here; the metabox key (_generate-disable-post-image) is a different layer and leaves all five callbacks attached.',
				var_export( $kill_meta, true )
			) );

		$kill_conditions = function () use ( $kill_id ) {
			return (bool) GeneratePress_Conditions::show_data(
				get_post_meta( $kill_id, '_generate_element_display_conditions', true ) ?: array(),
				get_post_meta( $kill_id, '_generate_element_exclude_conditions', true ) ?: array(),
				get_post_meta( $kill_id, '_generate_element_user_conditions', true ) ?: array()
			);
		};

		$off_target = $page_ids['ls-page-baseline']
			? $with_page( $page_ids['ls-page-baseline'], $kill_conditions )
			: null;

		$on_target = $kill_page_id
			? $with_page( $kill_page_id, $kill_conditions )
			: null;

		false === $off_target
			? $ok( 'kill switch does NOT apply on ls-page-baseline (the control every §9 absence check is read against)' )
			: $bad( 'kill switch applies on ls-page-baseline — it would disable the featured image on the control page, and §9 could not tell "the rule works" from "nothing draws an image anywhere".' );

		true === $on_target
			? $ok( 'kill switch APPLIES on ls-page-featured-kill (GP will remove all five image callbacks there)' )
			: $bad( 'kill switch does NOT apply on ls-page-featured-kill — its display condition misses the page it targets, so nothing is disabled and every §9 assertion about that row is vacuous. Same shape as B6.' );
	}
}

// Finally: the two conditions ANSWER, and answer DIFFERENTLY.
//
// Everything above about them is presence and shape — they exist, they are
// published, their stored value is what GB Pro would write, their slugs resolve
// against the live registry. None of that shows either one producing a verdict,
// and the standing rule here is that a fixture is only real once something
// asserts it CHANGED an outcome.
//
// ONE request answers it, which is what makes this safe to do from the CLI. On
// ls-page-featured-kill the two rules genuinely disagree — the post setting is
// untouched (image condition true) while GP's five callbacks are gone (slot
// condition false) — so a single bootstrap yields both verdicts. Neither an
// always-true nor an always-false evaluator can produce that pair, so no second
// page, and therefore no ordering hazard, is needed.
//
// This is the LAST thing in the file and must stay that way. It reads hook state
// through the Detector on a request where the kill switch has already stripped
// those hooks process-globally; anything added after it inherits that. The
// rendered-response half of the same claim is render-surface.sh §9, which gets a
// clean process per page and can therefore cover all five rows.
if ( ! class_exists( 'GenerateBlocks_Pro_Conditions' ) || ! class_exists( 'BWS_GP_Layout_Detector' ) ) {
	$bad( 'GB Pro conditions or the Detector are ABSENT — cannot verify the seeded conditions actually evaluate' );
} elseif ( ! $kill_page_id ) {
	$bad( 'ls-page-featured-kill MISSING — cannot verify the seeded conditions evaluate' );
} else {
	$verdicts = $with_page( $kill_page_id, function () use ( $condition_ids ) {
		// states() memoizes per request (V5) and this process has already
		// resolved it for other pages. Without the reset the verdicts below
		// would describe whichever page happened to be queried first.
		BWS_GP_Layout_Detector::reset_cache();

		$out = array();
		foreach ( $condition_ids as $slug => $cid ) {
			$out[ $slug ] = $cid
				? (bool) GenerateBlocks_Pro_Conditions::show( get_post_meta( $cid, '_gb_conditions', true ) )
				: null;
		}

		BWS_GP_Layout_Detector::reset_cache();

		return $out;
	} );

	true === ( $verdicts['ls-cond-image-active'] ?? null )
		? $ok( 'ls-cond-image-active EVALUATES TRUE on ls-page-featured-kill (the post setting is untouched there)' )
		: $bad( sprintf(
			'ls-cond-image-active evaluated %s on ls-page-featured-kill, expected true — nothing on that page sets the per-post toggle, so a block conditioned on it must render. If both conditions answer the same way, the fixtures prove nothing.',
			var_export( $verdicts['ls-cond-image-active'] ?? null, true )
		) );

	false === ( $verdicts['ls-cond-slot-active'] ?? null )
		? $ok( 'ls-cond-slot-active EVALUATES FALSE on the same request — the two rules genuinely disagree, which is what the slot rule exists for' )
		: $bad( sprintf(
			'ls-cond-slot-active evaluated %s on ls-page-featured-kill, expected false — the Layout Element has removed all five of GP\'s image callbacks there. If this matches the image condition\'s verdict, one of the two is not being evaluated at all.',
			var_export( $verdicts['ls-cond-slot-active'] ?? null, true )
		) );
}

WP_CLI::log( '' );
WP_CLI::log( sprintf( 'Result: %d passed, %d failed', $pass, $fail ) );
WP_CLI::log( '' );

if ( $fail > 0 ) {
	WP_CLI::error( sprintf( '%d verification(s) failed', $fail ) );
}
