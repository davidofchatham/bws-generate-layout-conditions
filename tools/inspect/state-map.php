<?php
/**
 * state-map.php — what this plugin resolves on ONE real request.
 *
 * Neither a fixture suite nor an upstream probe: it asserts nothing and pins
 * nothing. It answers the question you actually have in front of a site — "why
 * is this block hiding on this page?" — by printing, for a single bootstrapped
 * request: the Detector's resolved state map, every registered rule evaluated
 * through GB Pro's own path, and the body classes this plugin emits.
 *
 * Usage (one page per invocation — see the warning below):
 *   bin/wp.sh <site> eval-file /plugins/bws-generate-layout-conditions/tools/inspect/state-map.php id 1234
 *   bin/wp.sh <site> eval-file .../state-map.php page 74082
 *   bin/wp.sh <site> eval-file .../state-map.php post 78063
 *   bin/wp.sh <site> eval-file .../state-map.php cat 75
 *   bin/wp.sh <site> eval-file .../state-map.php term site-section 1378
 *   bin/wp.sh <site> eval-file .../state-map.php home
 *   bin/wp.sh <site> eval-file .../state-map.php front
 *
 * `id` resolves the post type for you; the rest are explicit.
 *
 * ---------------------------------------------------------------------------
 * ONE PAGE PER PROCESS. This is not tidiness, and getting it wrong produces
 * confident wrong answers rather than errors:
 *
 *   1. The Detector memoizes its resolution for the request (V5). Handled here
 *      by reset_cache(), so this alone would be survivable.
 *   2. Hook state is PROCESS-GLOBAL and elements mutate it. A Layout Element
 *      that removes GP's featured-image callbacks does so for the rest of the
 *      process, so a second page inspected afterwards inherits the first page's
 *      hooks. `featured_image_slot_active` reads hook state and would report the
 *      previous page's answer, with nothing to indicate it.
 *
 * Loop in the shell, not in PHP:
 *   for a in "post 78063" "page 74082"; do bin/wp.sh site eval-file .../state-map.php $a; done
 *
 * ---------------------------------------------------------------------------
 * WHAT IT CANNOT TELL YOU. Every rule here reports CONFIGURATION, never render
 * (V7). "Slot active" means GP has its featured-image callbacks attached, not
 * that an image appears — a Content Template can remove the call site while
 * leaving the callbacks in place (V34 part 5c), and this tool will report the
 * slot active on a page with no image. When the verdicts here disagree with the
 * page in front of you, that gap is the first thing to check, and the response
 * body is the only place to settle it.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

$kind = isset( $args[0] ) ? $args[0] : '';

if ( '' === $kind ) {
	WP_CLI::error( 'usage: state-map.php <id|post|page|cat|term|home|front> [<id>|<taxonomy> <term_id>]' );
}

// ---------------------------------------------------------------------------
// Bootstrap a real main query. Without this every conditional evaluates against
// an empty query: is_singular() is false and get_queried_object_id() is 0, so
// the whole report is about no page at all — and reads as if it were about
// yours. --url does not fix it.
// ---------------------------------------------------------------------------
switch ( $kind ) {
	case 'id':
		$id   = (int) ( $args[1] ?? 0 );
		$type = $id ? get_post_type( $id ) : '';
		if ( ! $type ) {
			WP_CLI::error( "no post with ID {$id}" );
		}
		wp( ( 'page' === $type ? 'page_id=' : 'p=' ) . $id . ( 'page' === $type || 'post' === $type ? '' : '&post_type=' . $type ) );
		break;

	case 'page':
		wp( 'page_id=' . (int) ( $args[1] ?? 0 ) );
		break;

	case 'post':
		wp( 'p=' . (int) ( $args[1] ?? 0 ) );
		break;

	case 'cat':
		wp( 'cat=' . (int) ( $args[1] ?? 0 ) );
		break;

	case 'term':
		$taxonomy = (string) ( $args[1] ?? '' );
		$needle   = (string) ( $args[2] ?? '' );
		// ID or slug — both are what you have to hand, depending on whether you
		// got here from a database row or from a URL.
		$term = ctype_digit( $needle )
			? get_term( (int) $needle, $taxonomy )
			: get_term_by( 'slug', $needle, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			WP_CLI::error( "no term '{$needle}' in taxonomy '{$taxonomy}'" );
		}
		wp( $taxonomy . '=' . $term->slug );
		break;

	case 'home':
	case 'front':
		wp( '' );
		break;

	default:
		WP_CLI::error( "unknown target '{$kind}'" );
}

$queried = get_queried_object_id();

// A bootstrap that produced nothing is the failure this file exists to avoid
// reporting silently — on a singular target it means the ID is wrong or the
// post is not public, and every line below would describe the empty query.
if ( in_array( $kind, array( 'id', 'page', 'post' ), true ) && ! is_singular() ) {
	WP_CLI::error( sprintf(
		'target %s %s did not produce a singular request (queried object %d). Everything below would describe an empty query.',
		$kind,
		$args[1] ?? '?',
		$queried
	) );
}

WP_CLI::log( '' );
WP_CLI::log( str_repeat( '=', 76 ) );
WP_CLI::log( sprintf( 'REQUEST  %s', implode( ' ', $args ) ) );
WP_CLI::log( sprintf(
	'  singular=%s archive=%s home=%s front=%s search=%s 404=%s',
	var_export( is_singular(), true ),
	var_export( is_archive(), true ),
	var_export( is_home(), true ),
	var_export( is_front_page(), true ),
	var_export( is_search(), true ),
	var_export( is_404(), true )
) );
WP_CLI::log( sprintf(
	'  queried object #%d%s',
	$queried,
	$queried && is_singular() ? sprintf( ' [%s] %s', get_post_type( $queried ), get_the_title( $queried ) ) : ''
) );

// The per-post layer, printed in full rather than summarised: when a rule's
// answer is surprising this is usually where the answer lives, and an unset key
// looks identical to a false one unless you can see the whole set.
if ( $queried && is_singular() ) {
	WP_CLI::log( '' );
	WP_CLI::log( '  post metabox (_generate-disable-*), unset keys omitted:' );
	$found = false;
	foreach ( array( 'header', 'nav', 'secondary-nav', 'top-bar', 'footer', 'post-image', 'headline', 'mobile-header' ) as $suffix ) {
		$value = get_post_meta( $queried, '_generate-disable-' . $suffix, true );
		if ( '' !== $value && false !== $value ) {
			WP_CLI::log( sprintf( '    _generate-disable-%-16s %s', $suffix, var_export( $value, true ) ) );
			$found = true;
		}
	}
	if ( ! $found ) {
		WP_CLI::log( '    (none set)' );
	}
	WP_CLI::log( sprintf( '  has_post_thumbnail: %s', var_export( has_post_thumbnail( $queried ), true ) ) );
}

// ---------------------------------------------------------------------------
// The Detector's resolved state map.
//
// Polarity is NOT uniform and the report says so per line, because reading it
// wrong inverts every conclusion drawn from it: the seven signals are stored
// DISABLE-polarity (true = suppressed) while featured_image_slot_active is
// stored positive, keyed to its own rule slug (V34 part 1).
// ---------------------------------------------------------------------------
if ( ! class_exists( 'BWS_GP_Layout_Detector' ) ) {
	WP_CLI::error( 'BWS_GP_Layout_Detector is absent — this plugin is not active on this site.' );
}

BWS_GP_Layout_Detector::reset_cache();
$states = BWS_GP_Layout_Detector::states();

WP_CLI::log( '' );
WP_CLI::log( '  RESOLVED STATE MAP' );
foreach ( $states as $key => $value ) {
	if ( 'sidebar' === $key ) {
		WP_CLI::log( sprintf( '    %-28s %-8s  (enum)', $key, var_export( $value, true ) ) );
		continue;
	}

	$positive = 'featured_image_slot_active' === $key;

	WP_CLI::log( sprintf(
		'    %-28s %-8s  (%s)',
		$key,
		var_export( $value, true ),
		$positive
			? ( $value ? 'positive polarity: slot IS active' : 'positive polarity: slot NOT active' )
			: ( $value ? 'disable polarity: DISABLED' : 'disable polarity: not disabled' )
	) );
}

// ---------------------------------------------------------------------------
// Every registered rule, evaluated through GB Pro's own path.
//
// Registry::evaluate() is what a saved condition on a block goes through, so
// these verdicts are the ones an author's block would get — not a
// reimplementation of them. Operator 'is', because that is the direction the
// rule names; 'is_not' is its negation and needs no separate line.
// ---------------------------------------------------------------------------
WP_CLI::log( '' );

if ( ! class_exists( 'GenerateBlocks_Pro_Conditions_Registry' ) ) {
	WP_CLI::warning( 'GB Pro is not active — the conditions are self-gated off, so no rules are registered. The state map above still applies to the body classes below.' );
} else {
	WP_CLI::log( '  RULE VERDICTS — "is" operator, as GB Pro evaluates them on a block' );

	foreach ( array( 'gp_theme_element', 'gp_theme_sidebar' ) as $type ) {
		$instance = GenerateBlocks_Pro_Conditions_Registry::get_instance( $type );

		if ( ! $instance ) {
			WP_CLI::log( sprintf( '    %s: NOT REGISTERED', $type ) );
			continue;
		}

		WP_CLI::log( sprintf( '    [%s]', $type ) );

		foreach ( $instance->get_rules() as $rule => $label ) {
			$verdict = GenerateBlocks_Pro_Conditions_Registry::evaluate( $type, $rule, 'is', '' );
			WP_CLI::log( sprintf(
				'      %-28s %-5s  %s',
				$rule,
				$verdict ? 'true' : 'false',
				( $verdict ? 'block RENDERS' : 'block HIDDEN' ) . ' — ' . $label
			) );
		}
	}
}

// ---------------------------------------------------------------------------
// Body classes. GP's own featured-image-active is included deliberately: it is
// render-based (it requires a thumbnail) and is the class most often mistaken
// for this plugin's featured-image reporting.
// ---------------------------------------------------------------------------
$classes = array_values( array_filter(
	apply_filters( 'body_class', array(), array() ),
	function ( $class ) {
		return 0 === strpos( $class, 'gp-no-' ) || 'featured-image-active' === $class;
	}
) );

WP_CLI::log( '' );
WP_CLI::log( '  BODY CLASSES (this plugin\'s gp-no-*, plus GP\'s render-based featured-image-active)' );
WP_CLI::log( '    ' . ( $classes ? implode( ' ', $classes ) : '(none)' ) );
WP_CLI::log( '' );

BWS_GP_Layout_Detector::reset_cache();
