<?php
/**
 * layout-states blueprint — seed applier.
 *
 * Idempotent: reads manifest.php, upserts by fixture slug (post_name). Safe to
 * re-run.
 *
 * Compose order: run core-structures seed FIRST — the V22 archive fixture
 * asserts against its `department` taxonomy (manifest `foreign_dependencies`).
 *
 * Run (from the wp-litespeed env; path shown is the container mount):
 *   bin/wp.sh <site> eval-file /plugins/bws-generate-layout-conditions/tools/fixtures/layout-states/seed.php
 *
 * No schema.php and no mu-plugin stub: every post type and meta key this
 * blueprint writes is registered by GP Premium, so there is nothing of our own
 * to keep alive across a snapshot restore.
 *
 * REQUIRES the GP Premium Elements module active. GP Premium ships every
 * module OFF (gated on `generate_package_*` === 'activated'), and with Elements
 * off `GeneratePress_Conditions` never loads, config-replay (V2) no-ops, and
 * every element seeded below is inert. Asserted in step 0 — seeding into that
 * state produces green tests that verify nothing.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "Run via wp-cli eval-file.\n";
	exit( 1 );
}

define( 'BWS_FIXTURE_SEEDING', true );

$manifest = require __DIR__ . '/manifest.php';
$log      = function ( $msg ) {
	WP_CLI::log( '[layout-states] ' . $msg );
};

// ---------------------------------------------------------------------------
// 0. Compose check + environment preconditions.
// ---------------------------------------------------------------------------
if ( ! taxonomy_exists( 'department' ) ) {
	WP_CLI::error( 'core-structures blueprint not loaded (department taxonomy missing). Seed it first.' );
}
$core_manifest_path = dirname( __DIR__, 4 ) . '/bws-gb-dynamic-tags-extensions/tools/fixtures/core-structures/manifest.php';
if ( ! file_exists( $core_manifest_path ) ) {
	WP_CLI::error( 'core-structures manifest not found at ' . $core_manifest_path );
}
$core_manifest = require $core_manifest_path;
$min_core      = (int) ( $manifest['composes_on']['min_version'] ?? 0 );
if ( (int) $core_manifest['version'] < $min_core ) {
	WP_CLI::error( sprintf(
		'core-structures manifest v%d < pinned min v%d — update the pin or reseed against a newer core.',
		$core_manifest['version'],
		$min_core
	) );
}

// GP Premium modules. Hard error, not a warning: a fixture set that silently
// does nothing is worse than no fixture set.
$inactive = array();
foreach ( $manifest['requires_modules'] as $module ) {
	if ( 'activated' !== get_option( $module ) ) {
		$inactive[] = $module;
	}
}
if ( $inactive ) {
	WP_CLI::error( sprintf(
		"GP Premium module(s) not activated: %s\n"
			. "GP Premium gates each module on its own option and ships them OFF. With Elements\n"
			. "inactive, GeneratePress_Conditions never loads and every element below is inert.\n"
			. "Activate with:  wp option update %s activated",
		implode( ', ', $inactive ),
		$inactive[0]
	) );
}
if ( ! post_type_exists( 'gp_elements' ) ) {
	WP_CLI::error( 'gp_elements post type missing — GP Premium Elements module did not load.' );
}
$log( 'preconditions OK (core-structures v' . $core_manifest['version'] . ', GP Premium modules active)' );

// ---------------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------------

/**
 * Upsert a post by post_name within a post type. Returns the post ID.
 *
 * Keyed on post_name rather than title so a fixture can be retitled without
 * orphaning the row it seeded last run.
 */
$upsert = function ( $post_type, $post_name, array $args ) {
	$existing = get_posts( array(
		'post_type'        => $post_type,
		'name'             => $post_name,
		'post_status'      => 'any',
		'posts_per_page'   => 1,
		'fields'           => 'ids',
		'suppress_filters' => false,
	) );

	$args = array_merge( $args, array(
		'post_type'   => $post_type,
		'post_name'   => $post_name,
		'post_status' => $args['post_status'] ?? 'publish',
	) );

	if ( $existing ) {
		$args['ID'] = $existing[0];
		$id         = wp_update_post( $args, true );
	} else {
		$id = wp_insert_post( $args, true );
	}

	if ( is_wp_error( $id ) ) {
		WP_CLI::error( sprintf( 'upsert failed for %s/%s: %s', $post_type, $post_name, $id->get_error_message() ) );
	}

	return (int) $id;
};

/**
 * Write element meta, honouring GP's unset convention.
 *
 * GP's admin metabox DELETES a disable-meta row rather than storing a falsy
 * value (class-metabox.php:1872-1876), and the layout consumer only does a
 * truthy check. Writing '' would therefore produce a row the admin UI can
 * never create — so null/'' means delete here too, and fixtures stay
 * byte-identical to what a human clicking the metabox would leave behind.
 */
$write_meta = function ( $post_id, array $meta ) {
	foreach ( $meta as $key => $value ) {
		if ( null === $value || '' === $value ) {
			delete_post_meta( $post_id, $key );
			continue;
		}
		update_post_meta( $post_id, $key, $value );
	}
};

// ---------------------------------------------------------------------------
// 1. Pages first — elements reference them by ID in display conditions.
// ---------------------------------------------------------------------------
$page_ids = array();

foreach ( $manifest['pages'] as $slug => $page ) {
	$id = $upsert( 'page', $page['post_name'], array(
		'post_title'   => $page['post_title'],
		'post_content' => $page['post_content'] ?? '',
	) );

	$page_ids[ $slug ] = $id;

	if ( ! empty( $page['disable_meta'] ) ) {
		$write_meta( $id, $page['disable_meta'] );
	}

	if ( ! empty( $page['sidebar_layout'] ) ) {
		update_post_meta( $id, '_generate-sidebar-layout-meta', $page['sidebar_layout'] );
	}

	$log( sprintf( 'page %-32s #%d', $slug, $id ) );
}

// ---------------------------------------------------------------------------
// 2. Elements.
//
// Display/exclude condition `object` values may carry a {{page-slug}}
// placeholder — page IDs are not knowable until step 1 has run, and hardcoding
// them would make the manifest environment-specific.
// ---------------------------------------------------------------------------

/**
 * Resolve {{fixture-slug}} placeholders to seeded page IDs.
 *
 * GP stores `object` as a STRING (sanitize_key() in the metabox save handler),
 * and show_data() compares with a non-strict in_array(), so an int would still
 * match at runtime — but a string is what the admin UI writes, and fixtures
 * that diverge from the UI stop being evidence about production.
 */
$resolve_object = function ( $object ) use ( $page_ids ) {
	if ( ! is_string( $object ) || ! preg_match( '/^\{\{(.+)\}\}$/', $object, $m ) ) {
		return $object;
	}

	$slug = $m[1];
	if ( ! isset( $page_ids[ $slug ] ) ) {
		WP_CLI::error( sprintf( 'display condition references unknown page fixture "%s"', $slug ) );
	}

	return (string) $page_ids[ $slug ];
};

$element_ids = array();

foreach ( $manifest['elements'] as $slug => $element ) {
	$id = $upsert( 'gp_elements', $element['post_name'], array(
		'post_title'   => $element['post_title'],
		'post_content' => $element['post_content'] ?? '',
		'post_status'  => $element['post_status'],
	) );

	$element_ids[ $slug ] = $id;

	$write_meta( $id, $element['meta'] );

	// Conditions. Display/exclude are lists of array( rule, object ); user
	// conditions are a FLAT list of strings (metabox save, ll.1901-1980).
	foreach ( array(
		'display_conditions' => '_generate_element_display_conditions',
		'exclude_conditions' => '_generate_element_exclude_conditions',
	) as $manifest_key => $meta_key ) {
		if ( empty( $element[ $manifest_key ] ) ) {
			continue;
		}

		$rules = array();
		foreach ( $element[ $manifest_key ] as $rule ) {
			$rules[] = array(
				'rule'   => $rule['rule'],
				'object' => $resolve_object( $rule['object'] ),
			);
		}

		update_post_meta( $id, $meta_key, $rules );
	}

	if ( ! empty( $element['user_conditions'] ) ) {
		update_post_meta( $id, '_generate_element_user_conditions', $element['user_conditions'] );
	}

	$log( sprintf( 'element %-32s #%d (%s)', $slug, $id, $element['meta']['_generate_element_type'] ) );
}

// ---------------------------------------------------------------------------
// 3. Theme mods.
//
// Merged, not replaced: GP's Menu Plus settings carry defaults this blueprint
// has no opinion on, and clobbering them would make the fixture set responsible
// for GP's entire settings schema.
// ---------------------------------------------------------------------------
foreach ( $manifest['theme_mods'] as $mod => $value ) {
	if ( is_array( $value ) ) {
		$existing = get_theme_mod( $mod, array() );
		$value    = array_merge( is_array( $existing ) ? $existing : array(), $value );
	}

	set_theme_mod( $mod, $value );
	$log( 'theme_mod ' . $mod . ' merged' );
}

// ---------------------------------------------------------------------------
// 4. Foreign-dependency check.
//
// V22 needs a POPULATED archive: an empty term archive 404s, and the featured
// -image config-replay test would then pass without ever running. Warn rather
// than error — this blueprint does not own the fixture and cannot repair it.
// ---------------------------------------------------------------------------
$sales = get_term_by( 'slug', 'sales', 'department' );
if ( ! $sales ) {
	WP_CLI::warning( 'department:sales term missing — V22 archive fixture will not resolve. Reseed core-structures.' );
} elseif ( 0 === (int) $sales->count ) {
	WP_CLI::warning( 'department:sales has 0 posts — /department/sales/ will 404 and the V22 test would vacuously pass.' );
} else {
	$log( sprintf( 'foreign dep OK — department:sales carries %d post(s)', $sales->count ) );
}

$log( sprintf( 'DONE — blueprint %s v%d (%d pages, %d elements)', $manifest['blueprint'], $manifest['version'], count( $page_ids ), count( $element_ids ) ) );
