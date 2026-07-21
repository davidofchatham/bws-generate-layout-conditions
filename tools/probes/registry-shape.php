<?php
/**
 * Drift probe — GB Pro / GP Premium surface the plugin binds to.
 *
 * Reports the shape of every upstream API this plugin depends on, so a GP/GB
 * update that moves one of them shows up here rather than in a support ticket.
 * Read-only: registers nothing, writes nothing.
 *
 * Run: bin/wp.sh <site> eval-file /plugins/bws-generate-layout-conditions/tools/probes/registry-shape.php
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "Run via wp-cli eval-file.\n";
	exit( 1 );
}

$log = function ( $m ) { WP_CLI::log( '[probe] ' . $m ); };

$log( 'GB Pro version:  ' . ( defined( 'GENERATEBLOCKS_PRO_VERSION' ) ? GENERATEBLOCKS_PRO_VERSION : '?' ) );
$log( 'GP Premium ver:  ' . ( defined( 'GP_PREMIUM_VERSION' ) ? GP_PREMIUM_VERSION : '?' ) );

foreach ( array(
	'GenerateBlocks_Pro_Conditions_Registry',
	'GenerateBlocks_Pro_Condition_Abstract',
	'GeneratePress_Conditions',
	'BWS_GP_Layout_Detector',
	'BWS_GP_WP_Environment',
) as $c ) {
	$log( sprintf( '%-42s %s', $c . ':', class_exists( $c ) ? 'PRESENT' : 'ABSENT' ) );
}
$log( sprintf( '%-42s %s', 'BWS_GP_Environment (interface):', interface_exists( 'BWS_GP_Environment' ) ? 'PRESENT' : 'ABSENT' ) );

// Registry shape — the plugin calls ::register( $slug, $args, $class ).
if ( class_exists( 'GenerateBlocks_Pro_Conditions_Registry' ) ) {
	$rc = new ReflectionClass( 'GenerateBlocks_Pro_Conditions_Registry' );

	foreach ( $rc->getMethods( ReflectionMethod::IS_PUBLIC ) as $m ) {
		$params = array_map(
			function ( $p ) { return '$' . $p->getName(); },
			$m->getParameters()
		);
		$log( sprintf( 'registry method: %s%s(%s)', $m->isStatic() ? 'static ' : '', $m->getName(), implode( ', ', $params ) ) );
	}

	foreach ( $rc->getProperties() as $p ) {
		$p->setAccessible( true );
		$val = $p->isStatic() ? $p->getValue() : null;
		$log( sprintf(
			'registry prop: $%s%s%s',
			$p->getName(),
			$p->isStatic() ? ' (static)' : '',
			is_array( $val ) ? ' => keys[' . implode( ', ', array_keys( $val ) ) . ']' : ''
		) );
	}
}

// show_data() signature — config-replay depends on arity + arg order (V4).
if ( class_exists( 'GeneratePress_Conditions' ) && method_exists( 'GeneratePress_Conditions', 'show_data' ) ) {
	$m      = new ReflectionMethod( 'GeneratePress_Conditions', 'show_data' );
	$params = array_map(
		function ( $p ) {
			return '$' . $p->getName() . ( $p->isDefaultValueAvailable() ? ' = ' . var_export( $p->getDefaultValue(), true ) : '' );
		},
		$m->getParameters()
	);
	$log( 'show_data(' . implode( ', ', $params ) . ')' );
}

// generate_get_layout() — the sidebar enum source (V26).
$log( 'generate_get_layout(): ' . ( function_exists( 'generate_get_layout' ) ? 'PRESENT' : 'ABSENT' ) );
