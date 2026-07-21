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

/**
 * The shared fixture attachment, used as a featured image (v2). Created once and
 * reused; returns its ID.
 *
 * Generates its own 1x1 PNG rather than depending on a file in the repo or on
 * core-structures' media: the render harness only needs a thumbnail to EXIST so
 * GP emits .page-header-image-single. What the pixels are is irrelevant, and a
 * self-contained fixture cannot be broken by another blueprint's reseed.
 *
 * Keyed on post_name like every other fixture here, so re-running upserts rather
 * than piling up attachments.
 */
$ensure_attachment = function () use ( $log ) {
	$existing = get_posts( array(
		'post_type'      => 'attachment',
		'name'           => 'ls-fixture-image',
		'post_status'    => 'inherit',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );

	if ( $existing ) {
		return (int) $existing[0];
	}

	// Minimal valid 1x1 PNG. Inline so the fixture carries no binary asset.
	$png = base64_decode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
	);

	$uploaded = wp_upload_bits( 'ls-fixture-image.png', null, $png );

	if ( ! empty( $uploaded['error'] ) ) {
		WP_CLI::error( 'could not write fixture image: ' . $uploaded['error'] );
	}

	$id = wp_insert_attachment(
		array(
			'post_title'     => 'LS: Fixture Image',
			'post_name'      => 'ls-fixture-image',
			'post_mime_type' => 'image/png',
			'post_status'    => 'inherit',
		),
		$uploaded['file']
	);

	if ( is_wp_error( $id ) || ! $id ) {
		WP_CLI::error( 'could not insert fixture attachment' );
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $uploaded['file'] ) );

	$log( sprintf( 'attachment %-32s #%d (fixture image)', 'ls-fixture-image', $id ) );

	return (int) $id;
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

	// Featured image (v2). Only where a render assertion needs the surface to
	// exist — see the manifest note on why the CONTROL page needs one too.
	if ( ! empty( $page['featured_image'] ) ) {
		set_post_thumbnail( $id, $ensure_attachment() );
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
// 3. Site options.
//
// v1 wrote these with set_theme_mod(). That was a BUG, not a style choice: GP
// Premium reads generate_menu_plus_settings only through get_option() (~20 call
// sites, zero get_theme_mod), so the value landed in a row nothing reads and the
// mobile header stayed at its 'disable' default. V25's subject — the
// <nav id="mobile-header"> wrapper — therefore never rendered on this testbed,
// and any V25 assertion written before v2 would have passed vacuously.
//
// Merged, not replaced: GP's Menu Plus settings carry defaults this blueprint
// has no opinion on, and clobbering them would make the fixture set responsible
// for GP's entire settings schema.
// ---------------------------------------------------------------------------
foreach ( $manifest['options'] as $option => $value ) {
	if ( is_array( $value ) ) {
		$existing = get_option( $option, array() );
		$value    = array_merge( is_array( $existing ) ? $existing : array(), $value );
	}

	update_option( $option, $value );
	$log( 'option ' . $option . ' merged' );
}

// Clean up the v1 theme_mod so a site seeded by both versions does not keep a
// stale row that looks authoritative but is read by nothing.
if ( false !== get_theme_mod( 'generate_menu_plus_settings', false ) ) {
	remove_theme_mod( 'generate_menu_plus_settings' );
	$log( 'removed stale v1 theme_mod generate_menu_plus_settings (never read by GP)' );
}

// ---------------------------------------------------------------------------
// 3b. Nav menus (v2).
//
// GP renders <nav id="site-navigation"> / <nav id="secondary-navigation"> only
// when a menu is ASSIGNED to that location. With none assigned, both wrappers
// are absent everywhere — so a render assertion checking that a disable toggle
// removes one would pass on the control page too, proving nothing.
// ---------------------------------------------------------------------------
foreach ( $manifest['nav_menus'] as $slug => $menu ) {
	$term = wp_get_nav_menu_object( $slug );

	if ( ! $term ) {
		$menu_id = wp_create_nav_menu( $menu['name'] );

		if ( is_wp_error( $menu_id ) ) {
			WP_CLI::error( 'could not create nav menu ' . $slug . ': ' . $menu_id->get_error_message() );
		}

		// wp_create_nav_menu() names the term from the label; force the slug so
		// the lookup above is stable across re-runs and retitles.
		wp_update_term( (int) $menu_id, 'nav_menu', array( 'slug' => $slug ) );
		$term = wp_get_nav_menu_object( (int) $menu_id );
	}

	$menu_id = (int) $term->term_id;

	// Items: only seeded when the menu is empty. A nav location with a menu that
	// has NO items still renders nothing in some themes, so at least one item is
	// required for the wrapper to be observable.
	if ( ! wp_get_nav_menu_items( $menu_id ) ) {
		foreach ( $menu['items'] as $title ) {
			wp_update_nav_menu_item( $menu_id, 0, array(
				'menu-item-title'  => $title,
				'menu-item-url'    => home_url( '/' ),
				'menu-item-status' => 'publish',
			) );
		}
	}

	// Merge into existing locations rather than replacing the whole map: other
	// blueprints on this site may own locations this one has no opinion about.
	$locations = get_nav_menu_locations();
	foreach ( $menu['locations'] as $location ) {
		$locations[ $location ] = $menu_id;
	}
	set_theme_mod( 'nav_menu_locations', $locations );

	$log( sprintf(
		'nav_menu %-32s #%d → %s',
		$slug,
		$menu_id,
		implode( ', ', $menu['locations'] )
	) );
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
