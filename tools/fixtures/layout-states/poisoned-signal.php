<?php
/**
 * layout-states — V2 poisoned-signal proof (T13).
 *
 * Proves the observation ADR-0001 rests on: a Block Element assigned to
 * generate_header / generate_footer UNCONDITIONALLY remove_action()s the native
 * construct to claim the hook (class-block.php:169-175, verified on GP Premium
 * 2.5.6). The removal is keyed on the RESOLVED HOOK NAME and reads no disable
 * meta whatsoever — so `! has_action( 'generate_header', 'generate_construct_header' )`
 * reports "header disabled" on every page carrying such an element, regardless
 * of whether the user disabled anything.
 *
 * That is why is_header_disabled() / is_footer_disabled() use config-replay
 * while every other signal reads hook state (V2, ADR-0001). Until this file
 * existed the invariant was documented, not executable: nothing failed if
 * upstream made the removal conditional, or if the Detector regressed to
 * reading hook state for header/footer.
 *
 * Run:
 *   bin/wp.sh <site> eval-file /plugins/bws-generate-layout-conditions/tools/fixtures/layout-states/poisoned-signal.php
 *
 * Preconditions: layout-states seeded. Takes no --url — bootstraps its own
 * query per arm, same as verify.php / seam-fidelity.php.
 *
 * ---------------------------------------------------------------------------
 * ORDER IS LOAD-BEARING. READ BEFORE EDITING.
 *
 * remove_action() mutates process-global state and this file runs in ONE
 * process. Once the poisoned arm instantiates the Block Elements, the native
 * constructs are gone for the remainder of the run — there is no per-request
 * teardown under wp-cli the way there is under HTTP.
 *
 * So the unpoisoned control MUST be asserted first, before anything visits
 * ls-page-poisoned. Reordering the sections silently turns the control arm into
 * a tautology (it would observe the already-removed hook and "pass" the wrong
 * way). Section 1 therefore runs first, and section 2 re-asserts the hooks were
 * still present immediately before it poisons them, so an ordering mistake
 * fails loudly instead of passing green.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "Run via wp-cli eval-file.\n";
	exit( 1 );
}

require_once dirname( dirname( dirname( __DIR__ ) ) ) . '/includes/class-environment.php';

// Unlike seam-fidelity.php, this file asserts on the DETECTOR as well as the
// seam, and the Detector only exists once the plugin has bootstrapped
// (plugins_loaded:5). If the plugin is deactivated on this site every states()
// call below would fatal — say so plainly instead.
if ( ! class_exists( 'BWS_GP_Layout_Detector' ) ) {
	WP_CLI::error( 'BWS_GP_Layout_Detector not loaded — activate bws-generate-layout-conditions on this site first.' );
}

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

/**
 * Run $fn with the main query bootstrapped to $args, then restore $wp_query.
 *
 * Note what this does NOT restore: anything the `wp` action did as a side
 * effect. wp() calls $wp->main(), which fires `wp`, which is where
 * generate_premium_do_elements() instantiates elements (elements.php:25) and
 * therefore where the remove_action() fires. That side effect is the subject of
 * this test, so it is deliberately left in place — see the ORDER note above.
 */
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

/** The two native constructs a header/footer Block Element displaces. */
$constructs = array(
	'generate_header' => 'generate_construct_header',
	'generate_footer' => 'generate_construct_footer',
);

WP_CLI::log( '' );
WP_CLI::log( 'layout-states V2 poisoned-signal proof (T13)' );
WP_CLI::log( '' );

// ---------------------------------------------------------------------------
// 0. Preconditions.
//
// Every assertion below is vacuous if the fixtures are missing or if the
// Elements module is off, so fail hard here rather than reporting green on an
// empty site.
// ---------------------------------------------------------------------------
WP_CLI::log( '0. Preconditions' );

$page_baseline = $by_name( 'page', 'ls-page-baseline' );
$page_poisoned = $by_name( 'page', 'ls-page-poisoned' );
$el_header     = $by_name( 'gp_elements', 'ls-el-header-block' );
$el_footer     = $by_name( 'gp_elements', 'ls-el-footer-block' );

foreach ( array(
	'ls-page-baseline'  => $page_baseline,
	'ls-page-poisoned'  => $page_poisoned,
	'ls-el-header-block' => $el_header,
	'ls-el-footer-block' => $el_footer,
) as $slug => $id ) {
	$id
		? $ok( "{$slug} present (#{$id})" )
		: $bad( "{$slug} MISSING — seed layout-states first; every assertion below is vacuous" );
}

if ( $fail ) {
	WP_CLI::error( "{$pass} passed, {$fail} FAILED — preconditions unmet, aborting" );
}

// The poisoning fixtures must carry NO disable meta. That is the whole point:
// any "disabled" reading taken off ls-page-poisoned is a false positive by
// construction. If someone adds disable meta to these elements the test would
// still go green while proving nothing.
foreach ( array( $el_header, $el_footer ) as $el_id ) {
	$stray = array();

	foreach ( array( '_generate_disable_site_header', '_generate_disable_footer' ) as $key ) {
		if ( '' !== get_post_meta( $el_id, $key, true ) ) {
			$stray[] = $key;
		}
	}

	$stray
		? $bad( "element #{$el_id} carries disable meta (" . implode( ',', $stray ) . ') — fixture no longer isolates the poison' )
		: $ok( "element #{$el_id} carries no disable meta (false positive is by construction)" );
}

$env->can_replay_conditions()
	? $ok( 'can_replay_conditions() true (GP Elements module present)' )
	: $bad( 'can_replay_conditions() FALSE — Elements module off; elements never instantiate and nothing below is meaningful' );

// ---------------------------------------------------------------------------
// 1. THE CONTROL — must run before anything touches ls-page-poisoned.
//
// On a page carrying no header/footer Block Element, the native constructs are
// attached. Without this arm a bug that removes them globally (or an upstream
// change that never attaches them) would make section 2 pass for entirely the
// wrong reason.
// ---------------------------------------------------------------------------
WP_CLI::log( '' );
WP_CLI::log( '1. Unpoisoned control (ls-page-baseline) — native constructs attached' );

$baseline_hooks = $with_query( 'page_id=' . $page_baseline, function () use ( $env, $constructs ) {
	$seen = array();

	foreach ( $constructs as $hook => $callback ) {
		$seen[ $hook ] = $env->has_hook( $hook, $callback );
	}

	return $seen;
} );

foreach ( $constructs as $hook => $callback ) {
	true === $baseline_hooks[ $hook ]
		? $ok( "{$callback} attached to {$hook} on the baseline page" )
		: $bad( "{$callback} NOT attached to {$hook} on the baseline page — control arm is broken; either the theme never attaches it or something removed it before this ran (check file order)" );
}

// The Detector must agree: nothing is disabled on the baseline page.
$baseline_states = $with_query( 'page_id=' . $page_baseline, function () {
	BWS_GP_Layout_Detector::reset_cache();

	return BWS_GP_Layout_Detector::states();
} );

false === $baseline_states['header']
	? $ok( 'Detector reports header ACTIVE on the baseline page' )
	: $bad( 'Detector reports header disabled on the baseline page — nothing disables it there' );

false === $baseline_states['footer']
	? $ok( 'Detector reports footer ACTIVE on the baseline page' )
	: $bad( 'Detector reports footer disabled on the baseline page — nothing disables it there' );

// ---------------------------------------------------------------------------
// 2. THE POISON.
//
// Visiting ls-page-poisoned instantiates the two Block Elements. Their display
// conditions resolve (the query is bootstrapped), the hook resolves to
// generate_header / generate_footer, and class-block.php:169-175 strips the
// native construct — with no disable meta anywhere in the picture.
//
// This is a one-way door within this process. Everything after it observes a
// site whose native header/footer constructs are gone.
// ---------------------------------------------------------------------------
WP_CLI::log( '' );
WP_CLI::log( '2. Poisoned request (ls-page-poisoned) — Block Element strips the native construct' );

// Re-assert immediately before poisoning. If sections were reordered, or an
// earlier edit visited the poisoned page, this catches it instead of letting
// section 2 "pass" against an already-empty hook.
$still_attached = true;

foreach ( $constructs as $hook => $callback ) {
	if ( ! $env->has_hook( $hook, $callback ) ) {
		$still_attached = false;
		$bad( "{$callback} was ALREADY detached before the poisoned arm ran — ordering violated, section 2 cannot prove anything (see the ORDER note in this file's header)" );
	}
}

if ( $still_attached ) {
	$ok( 'both native constructs still attached immediately before the poisoned arm (ordering intact)' );
}

$poisoned_hooks = $with_query( 'page_id=' . $page_poisoned, function () use ( $env, $constructs ) {
	$seen = array();

	foreach ( $constructs as $hook => $callback ) {
		$seen[ $hook ] = $env->has_hook( $hook, $callback );
	}

	return $seen;
} );

foreach ( $constructs as $hook => $callback ) {
	false === $poisoned_hooks[ $hook ]
		? $ok( "{$callback} REMOVED from {$hook} by the Block Element — the poisoned signal, reproduced" )
		: $bad( "{$callback} still attached to {$hook} — the unconditional remove_action() did NOT fire. Either the element did not display (check its display conditions resolve under a bootstrapped query), or upstream made the removal conditional. If upstream changed, V2/ADR-0001's premise is stale and config-replay may no longer be necessary — investigate before relaxing anything." );
}

// ---------------------------------------------------------------------------
// 3. WHY IT MATTERS — the false positive, stated as an assertion.
//
// A hook-state reading on the poisoned page says "disabled". Nothing is
// disabled. Config-replay says "active" and is correct. This pair is the
// entire justification for ADR-0001, and it is what regresses if someone
// "simplifies" is_header_disabled() to match the other signals.
// ---------------------------------------------------------------------------
WP_CLI::log( '' );
WP_CLI::log( '3. Hook-state would lie; config-replay does not (V2 / ADR-0001)' );

// What a hook-state reading WOULD have concluded, spelled out literally so the
// false positive is visible in the output rather than implied.
foreach ( $constructs as $hook => $callback ) {
	$hook_state_says_disabled = ! $poisoned_hooks[ $hook ];

	$hook_state_says_disabled
		? $ok( "hook-state on {$hook} would report DISABLED (the false positive V2 names)" )
		: $bad( "hook-state on {$hook} would report active — section 2 already failed; this is downstream of that" );
}

// What the Detector actually reports. Config-replay reads the Layout Element
// disable meta, finds none applying to this page, and correctly says active.
$poisoned_states = $with_query( 'page_id=' . $page_poisoned, function () {
	BWS_GP_Layout_Detector::reset_cache();

	return BWS_GP_Layout_Detector::states();
} );

false === $poisoned_states['header']
	? $ok( 'Detector reports header ACTIVE on the poisoned page — config-replay routed around the poison' )
	: $bad( 'Detector reports header DISABLED on the poisoned page — FALSE POSITIVE. is_header_disabled() is reading hook state instead of replaying config; V2/ADR-0001 has regressed.' );

false === $poisoned_states['footer']
	? $ok( 'Detector reports footer ACTIVE on the poisoned page — config-replay routed around the poison' )
	: $bad( 'Detector reports footer DISABLED on the poisoned page — FALSE POSITIVE. is_footer_disabled() is reading hook state instead of replaying config; V2/ADR-0001 has regressed.' );

// Positive control on the config-replay path itself. If config-replay had
// degraded to "always active" the two assertions above would pass for the wrong
// reason. ls-page-layout-disabled genuinely disables header + footer via a
// Layout Element, so the Detector must report BOTH disabled there.
//
// Safe to run after the poisoning: config-replay reads meta, not hook state,
// so the stripped constructs do not affect it. That independence is itself the
// point being demonstrated.
$page_disabled = $by_name( 'page', 'ls-page-layout-disabled' );

if ( $page_disabled ) {
	$disabled_states = $with_query( 'page_id=' . $page_disabled, function () {
		BWS_GP_Layout_Detector::reset_cache();

		return BWS_GP_Layout_Detector::states();
	} );

	true === $disabled_states['header']
		? $ok( 'Detector reports header DISABLED on ls-page-layout-disabled (config-replay still discriminates)' )
		: $bad( 'Detector reports header active on ls-page-layout-disabled — config-replay has degraded to always-active, so the assertions above prove nothing' );

	true === $disabled_states['footer']
		? $ok( 'Detector reports footer DISABLED on ls-page-layout-disabled (config-replay still discriminates)' )
		: $bad( 'Detector reports footer active on ls-page-layout-disabled — config-replay has degraded to always-active, so the assertions above prove nothing' );
} else {
	$bad( 'ls-page-layout-disabled MISSING — cannot prove config-replay still discriminates' );
}

// ---------------------------------------------------------------------------
WP_CLI::log( '' );

if ( $fail ) {
	WP_CLI::error( "{$pass} passed, {$fail} FAILED" );
}

WP_CLI::success( "{$pass} passed, 0 failed" );
