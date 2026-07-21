<?php
/**
 * Version-drift canary — GB Pro / GP Premium surface the plugin binds to (T14, V27/V28).
 *
 * The sibling `registry-shape.php` REPORTS this surface; a human has to read the
 * output and notice a change. That is fine for exploration and useless as a
 * guard: this testbed auto-updates to GB/GP betas, so a release that moves the
 * surface is silent until something fails downstream for a reason that looks
 * local. This file asserts the surface instead, so drift fails loudly and names
 * itself.
 *
 * Scope: ONLY the upstream contract, and only the parts this plugin actually
 * binds to. It asserts nothing about the plugin's own behaviour — the Detector
 * has unit tests, the adapter has seam-fidelity.php, V2 has poisoned-signal.php.
 * Every assertion here should be readable as "upstream still provides X".
 *
 * Read-only: registers nothing, writes nothing, mutates no global state. Safe to
 * run in any order relative to the other test files.
 *
 * Run:
 *   bin/wp.sh <site> eval-file /plugins/bws-generate-layout-conditions/tools/probes/upstream-surface.php
 *
 * WHEN THIS FAILS: an upstream release moved something the plugin depends on.
 * Do NOT edit the expectation to match. Diff the new upstream source, decide
 * whether the plugin must adapt, fix the plugin, and only then update the
 * expectation here — with the version that changed it recorded in the comment.
 *
 * Expectations recorded against: GB Pro 2.7.0-beta.1, GP Premium 2.5.6.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "Run via wp-cli eval-file.\n";
	exit( 1 );
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

/** Assert a callable's parameter names, in order. Arity AND order are load-bearing (V4). */
$params_of = function ( ReflectionFunctionAbstract $m ) {
	return array_map(
		function ( $p ) { return $p->getName(); },
		$m->getParameters()
	);
};

WP_CLI::log( '' );
WP_CLI::log( 'Upstream versions under test:' );
WP_CLI::log( '  GB Pro     ' . ( defined( 'GENERATEBLOCKS_PRO_VERSION' ) ? GENERATEBLOCKS_PRO_VERSION : '(absent)' ) );
WP_CLI::log( '  GP Premium ' . ( defined( 'GP_PREMIUM_VERSION' ) ? GP_PREMIUM_VERSION : '(absent)' ) );

/* ---------------------------------------------------------------------------
 * 0. Preconditions.
 *
 * Absent upstream is not drift, it is a broken environment — and every
 * assertion below would "fail" for the wrong reason. Abort instead.
 * ------------------------------------------------------------------------- */

WP_CLI::log( '' );
WP_CLI::log( '0. preconditions' );

foreach ( array(
	'GenerateBlocks_Pro_Conditions_Registry',
	'GenerateBlocks_Pro_Condition_Abstract',
	'GeneratePress_Conditions',
) as $required ) {
	if ( ! class_exists( $required ) ) {
		WP_CLI::error( $required . ' absent — GB Pro / GP Premium not active, or the Elements module is off. Fix the environment; this is not drift.' );
	}
}

if ( ! interface_exists( 'GenerateBlocks_Pro_Condition_Interface' ) ) {
	WP_CLI::error( 'GenerateBlocks_Pro_Condition_Interface absent — GB Pro conditions API not loaded.' );
}

$ok( 'GB Pro conditions API and GeneratePress_Conditions both present' );

/* ---------------------------------------------------------------------------
 * 1. Registry::register() — the entry point (class-condition.php:29,38).
 *
 * The plugin calls it statically with exactly ($type, $args, $classname). A
 * reordering or an added required param breaks registration silently: register()
 * returns false rather than throwing, so both our conditions would just vanish
 * from the GB Pro UI with no error anywhere.
 * ------------------------------------------------------------------------- */

WP_CLI::log( '' );
WP_CLI::log( '1. Registry::register() call shape' );

$rc = new ReflectionClass( 'GenerateBlocks_Pro_Conditions_Registry' );

if ( ! $rc->hasMethod( 'register' ) ) {
	WP_CLI::error( 'Registry::register() gone. The plugin has no other registration path.' );
}

$m_register = $rc->getMethod( 'register' );

$m_register->isStatic()
	? $ok( 'register() is static' )
	: $bad( 'register() no longer static — the plugin calls it as ::register()' );

$m_register->isPublic()
	? $ok( 'register() is public' )
	: $bad( 'register() no longer public' );

$expected_register = array( 'type', 'args', 'classname' );
$actual_register   = $params_of( $m_register );

$actual_register === $expected_register
	? $ok( 'register() params: (' . implode( ', ', $actual_register ) . ')' )
	: $bad( sprintf(
		'register() params changed: expected (%s), got (%s). Argument ORDER matters — the plugin passes slug, args, class positionally.',
		implode( ', ', $expected_register ),
		implode( ', ', $actual_register )
	) );

// Required-param count: an added required 4th arg makes every existing call fatal.
$m_register->getNumberOfRequiredParameters() === 3
	? $ok( 'register() requires exactly 3 args' )
	: $bad( 'register() now requires ' . $m_register->getNumberOfRequiredParameters() . ' args — existing 3-arg calls will be fatal' );

/* ---------------------------------------------------------------------------
 * 2. The interface contract.
 *
 * register() rejects any class not implementing GenerateBlocks_Pro_Condition_Interface
 * (class-conditions-registry.php:55-58) — and rejects it by RETURNING FALSE, not
 * by erroring. So a method added to the interface removes both our conditions
 * from the UI with no diagnostic at all. This is the highest-value assertion in
 * the file.
 * ------------------------------------------------------------------------- */

WP_CLI::log( '' );
WP_CLI::log( '2. Condition interface contract' );

$ri = new ReflectionClass( 'GenerateBlocks_Pro_Condition_Interface' );

$expected_interface = array(
	'evaluate'               => array( 'rule', 'operator', 'value', 'context' ),
	'get_rules'              => array(),
	'get_rule_metadata'      => array( 'rule' ),
	'get_operators_for_rule' => array( 'rule' ),
	'sanitize_value'         => array( 'value', 'rule' ),
);

$actual_interface = array();
foreach ( $ri->getMethods() as $m ) {
	$actual_interface[ $m->getName() ] = $params_of( $m );
}

$added = array_diff( array_keys( $actual_interface ), array_keys( $expected_interface ) );
$gone  = array_diff( array_keys( $expected_interface ), array_keys( $actual_interface ) );

empty( $added )
	? $ok( 'interface has no new methods' )
	: $bad( 'interface gained method(s): ' . implode( ', ', $added ) . ' — our condition classes no longer satisfy it, so register() will silently return false' );

empty( $gone )
	? $ok( 'interface retains all ' . count( $expected_interface ) . ' methods' )
	: $bad( 'interface lost method(s): ' . implode( ', ', $gone ) );

foreach ( $expected_interface as $name => $expected_params ) {
	if ( ! isset( $actual_interface[ $name ] ) ) {
		continue; // already reported as missing above.
	}

	$actual_interface[ $name ] === $expected_params
		? $ok( sprintf( '%s(%s)', $name, implode( ', ', $expected_params ) ) )
		: $bad( sprintf(
			'%s() params changed: expected (%s), got (%s)',
			$name,
			implode( ', ', $expected_params ),
			implode( ', ', $actual_interface[ $name ] )
		) );
}

// The plugin extends the abstract and inherits get_operators_for_rule() and
// sanitize_value() from it. If the abstract stops implementing the interface,
// or stops supplying those two, our classes become abstract-by-inheritance and
// instantiation fatals.
$ra = new ReflectionClass( 'GenerateBlocks_Pro_Condition_Abstract' );

$ra->implementsInterface( 'GenerateBlocks_Pro_Condition_Interface' )
	? $ok( 'abstract implements the condition interface' )
	: $bad( 'abstract no longer implements GenerateBlocks_Pro_Condition_Interface' );

foreach ( array( 'get_operators_for_rule', 'sanitize_value' ) as $inherited ) {
	$ra->hasMethod( $inherited ) && ! $ra->getMethod( $inherited )->isAbstract()
		? $ok( 'abstract supplies ' . $inherited . '() (plugin inherits it)' )
		: $bad( 'abstract no longer supplies a concrete ' . $inherited . '() — the plugin does not define its own, so its condition classes become uninstantiable' );
}

/* ---------------------------------------------------------------------------
 * 3. The registration hook.
 *
 * class-condition.php:26 attaches to generateblocks_register_conditions. Renamed
 * upstream, our callback never fires and both conditions disappear.
 * ------------------------------------------------------------------------- */

WP_CLI::log( '' );
WP_CLI::log( '3. registration hook' );

$conditions_src = '';
$rcond          = new ReflectionClass( 'GenerateBlocks_Pro_Conditions' );
$fn             = $rcond->getFileName();

if ( $fn && is_readable( $fn ) ) {
	$conditions_src = (string) file_get_contents( $fn ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

false !== strpos( $conditions_src, "do_action( 'generateblocks_register_conditions' )" )
	? $ok( "GenerateBlocks_Pro_Conditions fires do_action( 'generateblocks_register_conditions' )" )
	: $bad( "do_action( 'generateblocks_register_conditions' ) not found in " . basename( (string) $fn ) . ' — the hook the plugin registers on may have been renamed' );

/* ---------------------------------------------------------------------------
 * 4. Our two slugs actually landed.
 *
 * Everything above is structural. This is the end-to-end check: after a real
 * boot, does the registry hold our conditions, pointing at our classes? It
 * catches failures the reflection checks cannot — a load-order change, a
 * capability gate, a silent register() rejection for a reason not modelled here.
 *
 * The slugs are PERSISTED in saved condition data (V27), so a rename is a
 * migration, not a refactor. That is why they are asserted literally.
 * ------------------------------------------------------------------------- */

WP_CLI::log( '' );
WP_CLI::log( '4. plugin conditions registered end-to-end' );

$expected_slugs = array(
	'gp_theme_element' => 'BWS_GP_Theme_Element_Condition',
	'gp_theme_sidebar' => 'BWS_GP_Theme_Sidebar_Condition',
);

$all = GenerateBlocks_Pro_Conditions_Registry::get_all();

foreach ( $expected_slugs as $slug => $class ) {
	if ( ! isset( $all[ $slug ] ) ) {
		$bad( sprintf(
			'slug %s not registered. Either the plugin is inactive, or register() rejected it — check the interface assertions above first.',
			$slug
		) );
		continue;
	}

	$ok( 'slug ' . $slug . ' registered' );

	$all[ $slug ]['class'] === $class
		? $ok( $slug . ' → ' . $class )
		: $bad( sprintf( '%s points at %s, expected %s', $slug, $all[ $slug ]['class'], $class ) );

	// The plugin passes operators is/is_not only (V10). If the registry ever
	// normalizes or filters them, the UI offers operators evaluate() ignores.
	$operators = isset( $all[ $slug ]['operators'] ) ? $all[ $slug ]['operators'] : array();

	$operators === array( 'is', 'is_not' )
		? $ok( $slug . ' operators preserved as [is, is_not]' )
		: $bad( sprintf(
			'%s operators came back as [%s] — the plugin registers [is, is_not] and apply_operator() handles only those',
			$slug,
			implode( ', ', (array) $operators )
		) );

	// An instance must be constructible — get_instance() is what GB Pro calls at
	// evaluation time, and it news up the class with no arguments.
	$instance = GenerateBlocks_Pro_Conditions_Registry::get_instance( $slug );

	$instance instanceof GenerateBlocks_Pro_Condition_Interface
		? $ok( $slug . ' instantiates and satisfies the interface' )
		: $bad( $slug . ' did not instantiate into a GenerateBlocks_Pro_Condition_Interface' );
}

/* ---------------------------------------------------------------------------
 * 5. GP Premium surface: show_data() and generate_get_layout().
 *
 * show_data() is config-replay's entire mechanism (V2/V4). Its ARITY is the
 * invariant that bites: a two-arg replay reports excluded pages as disabled,
 * which is exactly the V4 bug. seam-fidelity.php proves the adapter passes three
 * args and that this discriminates; this proves upstream still ACCEPTS three.
 * ------------------------------------------------------------------------- */

WP_CLI::log( '' );
WP_CLI::log( '5. GP Premium surface' );

if ( ! method_exists( 'GeneratePress_Conditions', 'show_data' ) ) {
	$bad( 'GeneratePress_Conditions::show_data() gone — config-replay (V2) has no mechanism' );
} else {
	$m_show = new ReflectionMethod( 'GeneratePress_Conditions', 'show_data' );

	$m_show->isStatic()
		? $ok( 'show_data() is static' )
		: $bad( 'show_data() no longer static — the adapter calls it as ::show_data()' );

	$actual_show = $params_of( $m_show );

	// Names are upstream's; only count and order are contractual for us, but
	// asserting names too makes a semantic reshuffle visible rather than silent.
	count( $actual_show ) >= 3
		? $ok( 'show_data() accepts at least 3 params: (' . implode( ', ', $actual_show ) . ')' )
		: $bad( sprintf(
			'show_data() now takes %d param(s): (%s). V4 requires display, exclude AND users — a two-arg replay reports excluded pages as disabled.',
			count( $actual_show ),
			implode( ', ', $actual_show )
		) );

	$m_show->getNumberOfRequiredParameters() <= 3
		? $ok( 'show_data() requires no more than 3 args' )
		: $bad( 'show_data() now requires ' . $m_show->getNumberOfRequiredParameters() . ' args — the adapter passes 3' );
}

// V26's enum source. Absent, sidebar_layout() falls back to 'no-sidebar' for
// every request — the sidebar condition silently reports "no sidebars" sitewide.
function_exists( 'generate_get_layout' )
	? $ok( 'generate_get_layout() present (V26 enum source)' )
	: $bad( 'generate_get_layout() absent — sidebar_layout() would report no-sidebar sitewide, with no error' );

/* ------------------------------------------------------------------------- */

WP_CLI::log( '' );

if ( $fail > 0 ) {
	WP_CLI::error( sprintf(
		'%d passed, %d FAILED. Upstream surface drifted. Read the header before touching the expectations in this file.',
		$pass,
		$fail
	) );
}

WP_CLI::success( sprintf( '%d passed, 0 failed. Upstream surface unchanged.', $pass ) );
