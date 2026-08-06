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

// GB Pro, added v8. The marker blocks are only conditioned while GB Pro is
// there to read `gbBlockCondition` on `render_block`; without it they render
// unconditionally and every row of the combination table passes its presence
// half while proving nothing about any rule.
foreach ( $manifest['requires_post_types'] as $post_type => $why ) {
	if ( ! post_type_exists( $post_type ) ) {
		WP_CLI::error( sprintf( 'post type %s missing — %s. Activate it before seeding.', $post_type, $why ) );
	}
}

// The feature flag, not just the plugin. GB Pro gates the whole `render_block`
// filter on this (includes/extend/block-conditions.php:22), and with it off the
// conditions are stored, readable and never consulted — the exact self-verifying
// inertness this blueprint keeps rediscovering (B6/B7/B9).
//
// A MISSING function is an error too, not a skip. Guarding this check on
// function_exists() would make it evaporate the moment GB Pro renames or moves
// it — a checker that silently stops checking is the same failure shape as the
// fixtures it is here to catch, one level up.
if ( ! function_exists( 'generateblocks_pro_block_conditions_enabled' ) ) {
	WP_CLI::error( 'generateblocks_pro_block_conditions_enabled() is absent — GB Pro is not loaded, or has moved the flag. Either way nothing here can confirm the render_block filter is live, and every conditioned marker block may render unconditionally.' );
}
if ( ! generateblocks_pro_block_conditions_enabled() ) {
	WP_CLI::error( 'GB Pro block conditions are DISABLED (generateblocks_get_option enable_block_conditions === false). Every conditioned marker block would render unconditionally.' );
}

$log( 'preconditions OK (core-structures v' . $core_manifest['version'] . ', GP Premium modules active, GB Pro conditions available)' );

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
 *
 * $owned_prefixes makes the write AUTHORITATIVE rather than additive: any
 * existing key matching one of them but absent from $meta is deleted first, so
 * the post ends up holding exactly what the manifest says and nothing else.
 *
 * NOT a tidiness measure — found by mutation, v5. `$upsert` is idempotent by
 * post_name, so a reseed reuses the post, and an additive write leaves behind
 * every key the manifest USED to carry. Changing a manifest key therefore did
 * not change the fixture: both the old and new rows were present, GP read the
 * old one, and the fixture kept working. That masked a deliberate wrong-key
 * mutation into a full green (39/39) — the harness reported a fixture that
 * pinned the real GP key while it in fact pinned nothing, because the row it
 * needed was left over from an earlier seed and nothing could ever remove it.
 *
 * The failure shape is B6's, one level up: not a fixture written in a form the
 * consumer never matches, but a fixture whose CURRENT definition was never what
 * the consumer read. Both are silent, both self-verify, and both are invisible
 * to any check that only reads what is there.
 *
 * Prefixes are safe to sweep because these posts are wholly fixture-owned:
 * `gp_elements` posts exist only for this blueprint, and on pages only the
 * `_generate-disable-*` metabox namespace is swept (never `_thumbnail_id`, and
 * never anything WordPress or another blueprint owns).
 */
$write_meta = function ( $post_id, array $meta, array $owned_prefixes = array() ) {
	foreach ( $owned_prefixes as $prefix ) {
		foreach ( get_post_meta( $post_id ) as $key => $unused ) {
			if ( 0 === strpos( $key, $prefix ) && ! array_key_exists( $key, $meta ) ) {
				delete_post_meta( $post_id, $key );
			}
		}
	}

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
// 0b. GB Pro conditions (v8) — BEFORE pages and elements.
//
// Ordering is a hard dependency, not a preference: the marker blocks embed a
// condition post ID in `gbBlockCondition`, so nothing carrying post_content can
// be written until these IDs exist.
// ---------------------------------------------------------------------------
$condition_ids = array();

foreach ( $manifest['conditions'] as $slug => $condition ) {
	$id = $upsert( 'gblocks_condition', $condition['post_name'], array(
		'post_title' => $condition['post_title'],
	) );

	$condition_ids[ $slug ] = $id;

	// update_post_meta directly, NOT $write_meta, and both halves of that are
	// deliberate. `_gb_conditions` is REGISTERED meta carrying GB Pro's own
	// sanitize_callback (class-conditions-post-type.php:242), which
	// update_post_meta runs — so the stored value is byte-identical to what a
	// REST write from the block editor produces, which is the standing bar for
	// fixtures here. And $write_meta's delete-on-empty convention encodes GP's
	// metabox behaviour, which has nothing to say about this key.
	update_post_meta( $id, '_gb_conditions', $condition['gb_conditions'] );

	$log( sprintf(
		'condition %-32s #%d (%s: %s)',
		$slug,
		$id,
		$condition['gb_conditions']['groups'][0]['conditions'][0]['type'],
		$condition['gb_conditions']['groups'][0]['conditions'][0]['rule']
	) );
}

/**
 * Resolve {{condition:fixture-slug}} placeholders in block content to a real ID.
 *
 * Same reason display-condition objects carry placeholders: a condition post ID
 * is not knowable until the post exists, and hardcoding one would make the
 * manifest environment-specific.
 *
 * Hard-errors on an unknown slug rather than leaving the literal in place. An
 * unresolved placeholder is the worst possible failure here — `absint('{{...}}')`
 * is 0, GB Pro treats 0 as "no condition" and returns the block content
 * untouched (block-conditions.php:70), so the marker renders unconditionally and
 * every presence assertion goes green while the rule is never consulted.
 */
$resolve_content = function ( $content ) use ( &$condition_ids ) {
	if ( ! is_string( $content ) || '' === $content ) {
		return $content;
	}

	return preg_replace_callback(
		'/\{\{condition:([a-z0-9-]+)\}\}/',
		function ( $m ) use ( $condition_ids ) {
			if ( ! isset( $condition_ids[ $m[1] ] ) ) {
				WP_CLI::error( sprintf( 'block content references unknown condition fixture "%s"', $m[1] ) );
			}

			return (string) $condition_ids[ $m[1] ];
		},
		$content
	);
};

// ---------------------------------------------------------------------------
// 1. Pages next — elements reference them by ID in display conditions.
// ---------------------------------------------------------------------------
$page_ids = array();

foreach ( $manifest['pages'] as $slug => $page ) {
	$id = $upsert( 'page', $page['post_name'], array(
		'post_title'   => $page['post_title'],
		'post_content' => $resolve_content( $page['post_content'] ?? '' ),
	) );

	$page_ids[ $slug ] = $id;

	// Called unconditionally, with the metabox namespace swept: a page that
	// LOSES its disable_meta in the manifest must lose the rows too, and the
	// `! empty()` guard this replaces meant a reseed could never take a toggle
	// back off. Scoped to `_generate-disable-` so `_generate-sidebar-layout-meta`
	// and `_thumbnail_id` are untouched.
	$write_meta( $id, $page['disable_meta'] ?? array(), array( '_generate-disable-' ) );

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
 * Resolve {{...}} placeholders in a condition `object` to a real ID.
 *
 * Two forms:
 *   {{fixture-slug}}            → the seeded page ID
 *   {{term:taxonomy:term-slug}} → the term ID
 *
 * GP stores `object` as a STRING (sanitize_key() in the metabox save handler),
 * and show_data() compares with a non-strict in_array(), so an int would still
 * match at runtime — but a string is what the admin UI writes, and fixtures
 * that diverge from the UI stop being evidence about production.
 *
 * THE TERM FORM IS NOT COSMETIC. `get_current_location()` resolves a taxonomy
 * archive to rule `taxonomy:{taxonomy}` with object = **`$queried_object->term_id`**
 * (class-conditions.php:225-231) — never the slug. show_data() then compares with
 * a non-strict `in_array()`, and under PHP 8 `7 == 'sales'` is FALSE, so a
 * slug-valued object silently never matches. That is exactly how the archive
 * fixtures shipped from v1 through v3: seeded, verified, readable, and inert —
 * `ls-el-layout-featured-archive` never once applied to /department/sales/, and
 * nothing noticed because no suite evaluated its conditions against a real
 * archive query. verify.php §6 now does (added v4).
 */
$resolve_object = function ( $object ) use ( $page_ids ) {
	if ( ! is_string( $object ) || ! preg_match( '/^\{\{(.+)\}\}$/', $object, $m ) ) {
		return $object;
	}

	$token = $m[1];

	if ( 0 === strpos( $token, 'term:' ) ) {
		list( , $taxonomy, $term_slug ) = array_pad( explode( ':', $token, 3 ), 3, '' );

		$term = get_term_by( 'slug', $term_slug, $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			WP_CLI::error( sprintf( 'display condition references unknown term "%s" in taxonomy "%s"', $term_slug, $taxonomy ) );
		}

		return (string) $term->term_id;
	}

	if ( ! isset( $page_ids[ $token ] ) ) {
		WP_CLI::error( sprintf( 'display condition references unknown page fixture "%s"', $token ) );
	}

	return (string) $page_ids[ $token ];
};

$element_ids = array();

foreach ( $manifest['elements'] as $slug => $element ) {
	$id = $upsert( 'gp_elements', $element['post_name'], array(
		'post_title'   => $element['post_title'],
		'post_content' => $resolve_content( $element['post_content'] ?? '' ),
		'post_status'  => $element['post_status'],
	) );

	$element_ids[ $slug ] = $id;

	// Both prefixes swept: GP uses underscores for element meta
	// (_generate_element_type, _generate_disable_*) and the condition metas
	// written just below share the `_generate_element_` prefix, so they are
	// deleted here and immediately rewritten — which is what lets an element
	// that DROPS a condition list in the manifest actually lose it.
	$write_meta( $id, $element['meta'], array( '_generate_', '_generate-' ) );

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

$log( sprintf(
	'DONE — blueprint %s v%d (%d pages, %d elements, %d conditions)',
	$manifest['blueprint'],
	$manifest['version'],
	count( $page_ids ),
	count( $element_ids ),
	count( $condition_ids )
) );
