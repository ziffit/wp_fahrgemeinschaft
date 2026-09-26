<?php
/**
 * Functional smoke tests for the Fahrgemeinschaften plugin.
 *
 * Runs inside the WordPress container:
 *   php /tmp/fgtests/smoke.php
 *
 * Steps that end in exit()/die (standalone token pages) run in a child
 * process and hand their output back through a file.
 */

define( 'WP_USE_THEMES', false );
require '/var/www/html/wp-load.php';

$_SERVER['HTTPS']       = 'on';
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/fahrgemeinschaften/';

const FG_STATE_FILE = '/tmp/fgtests/state.json';
const FG_HTML_FILE  = '/tmp/fgtests/page.html';

$GLOBALS['fg_pass']  = 0;
$GLOBALS['fg_fail']  = array();
$GLOBALS['fg_mail']  = array();
$GLOBALS['fg_state'] = array();

add_filter(
	'pre_wp_mail',
	function ( $short_circuit, $atts ) {
		$GLOBALS['fg_mail'][] = $atts;
		return true;
	},
	10,
	2
);

class FG_Redirect extends Exception {
	public $location;
	public $status;

	public function __construct( $location, $status ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
		$this->status   = $status;
	}
}

class FG_Died extends Exception {
	public $args;

	public function __construct( $args ) {
		parent::__construct( 'died' );
		$this->args = $args;
	}
}

/**
 * Run a handler while answering a wp_die() call with an exception.
 */
function fg_expect_die( callable $callback, $method = 'GET', array $post = array() ) {
	$previous = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;
	$_SERVER['REQUEST_METHOD'] = $method;
	$_POST                     = $post;

	$handler = function ( $message = '', $title = '', $args = array() ) {
		throw new FG_Died( is_array( $args ) ? $args : array( 'message' => (string) $message ) );
	};
	$chooser = function () use ( $handler ) {
		return $handler;
	};
	add_filter( 'wp_die_handler', $chooser );

	$result = null;
	try {
		call_user_func( $callback );
	} catch ( FG_Died $died ) {
		$result = $died->args;
	} finally {
		remove_filter( 'wp_die_handler', $chooser );
		$_SERVER['REQUEST_METHOD'] = $previous;
		$_POST                     = array();
	}

	return $result;
}

function fg_ok( $condition, $label, $detail = '' ) {
	if ( $condition ) {
		++$GLOBALS['fg_pass'];
		echo "  ok   {$label}\n";
		return true;
	}

	$GLOBALS['fg_fail'][] = $label . ( '' !== $detail ? ' :: ' . $detail : '' );
	echo "  FAIL {$label}" . ( '' !== $detail ? " :: {$detail}" : '' ) . "\n";
	return false;
}

function fg_contains( $needle, $haystack, $label ) {
	return fg_ok( false !== strpos( (string) $haystack, $needle ), $label, 'missing: ' . $needle );
}

function fg_not_contains( $needle, $haystack, $label ) {
	return fg_ok( false === strpos( (string) $haystack, $needle ), $label, 'found: ' . $needle );
}

function fg_mail_reset() {
	$GLOBALS['fg_mail'] = array();
}

function fg_last_mail( $index = 0 ) {
	return isset( $GLOBALS['fg_mail'][ $index ] ) ? $GLOBALS['fg_mail'][ $index ] : array();
}

function fg_mail_bodies() {
	$out = array();
	foreach ( $GLOBALS['fg_mail'] as $mail ) {
		$out[] = (string) ( isset( $mail['message'] ) ? $mail['message'] : '' );
	}
	return implode( "\n-----\n", $out );
}

function fg_mail_recipients() {
	$out = array();
	foreach ( $GLOBALS['fg_mail'] as $mail ) {
		$out[] = (string) $mail['to'];
	}
	return $out;
}

/**
 * Collect all token action links of a mail body, keyed by intent.
 */
function fg_extract_links( $body ) {
	$links = array();
	if ( preg_match_all( '#https?://\S+#', (string) $body, $matches ) ) {
		foreach ( $matches[0] as $url ) {
			$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
			$args  = array();
			parse_str( $query, $args );
			if ( isset( $args['fg_ride_action'], $args['intent'] ) ) {
				$links[ $args['intent'] ] = $url;
			}
		}
	}
	return $links;
}

function fg_state_load() {
	if ( file_exists( FG_STATE_FILE ) ) {
		$GLOBALS['fg_state'] = (array) json_decode( (string) file_get_contents( FG_STATE_FILE ), true );
	}
}

function fg_state_save() {
	file_put_contents( FG_STATE_FILE, json_encode( $GLOBALS['fg_state'] ) );
}

/**
 * Call a plugin handler with a simulated request and capture the redirect.
 */
function fg_call( callable $callback, array $post, array $get = array() ) {
	$_POST                       = $post;
	$_GET                        = $get;
	$_REQUEST                    = array_merge( $get, $post );
	$_SERVER['REQUEST_METHOD']   = 'POST';

	$catcher = function ( $location, $status ) {
		throw new FG_Redirect( (string) $location, (int) $status );
	};
	add_filter( 'wp_redirect', $catcher, 10, 2 );

	$location = null;
	$status   = null;
	try {
		call_user_func( $callback );
	} catch ( FG_Redirect $redirect ) {
		$location = $redirect->location;
		$status   = $redirect->status;
	} catch ( Throwable $error ) {
		remove_filter( 'wp_redirect', $catcher, 10 );
		$_POST = $_GET = $_REQUEST = array();
		throw $error;
	}

	remove_filter( 'wp_redirect', $catcher, 10 );
	$_POST   = array();
	$_GET    = array();
	$_REQUEST = array();

	return array( $location, $status );
}

function fg_notice_of( $location ) {
	if ( ! is_string( $location ) ) {
		return '';
	}
	$query = wp_parse_url( $location, PHP_URL_QUERY );
	if ( ! is_string( $query ) ) {
		return '';
	}
	$args = array();
	parse_str( $query, $args );
	return isset( $args['fg_notice'] ) ? (string) $args['fg_notice'] : '';
}

/**
 * Render a standalone token page in a child process and return its HTML.
 */
function fg_render_action_page( array $get ) {
	@unlink( FG_HTML_FILE );

	$query = http_build_query( $get );
	$cmd   = 'php ' . escapeshellarg( __FILE__ ) . ' render ' . escapeshellarg( $query ) . ' 2>/tmp/fgtests/render.err';
	exec( $cmd, $out, $code );
	$html = file_exists( FG_HTML_FILE ) ? (string) file_get_contents( FG_HTML_FILE ) : '';
	$err  = file_exists( '/tmp/fgtests/render.err' ) ? (string) file_get_contents( '/tmp/fgtests/render.err' ) : '';

	return array( $html, $err, $code, implode( "\n", $out ) );
}

function fg_make_event( $title, $date, $participants, $time = '', $active = true ) {
	global $fg_repo;

	$event_id = $fg_repo->insert_event(
		array(
			'title'        => $title,
			'event_date'   => $date,
			'event_time'   => $time,
			'participants' => $participants,
			'is_active'    => $active,
		)
	);
	if ( ! $event_id ) {
		throw new Exception( 'event insert failed' );
	}

	return (int) $event_id;
}

function fg_newest_ride() {
	global $fg_repo;

	$rides = $fg_repo->get_rides_page( array(), 0, 1 );

	return $rides ? $rides[0] : null;
}

function fg_count_rides( $status = '' ) {
	global $fg_repo;

	$args = array();
	if ( '' !== $status ) {
		$args['status'] = $status;
	}

	return $fg_repo->count_rides( $args );
}

function fg_purge_events() {
	global $fg_repo;

	foreach ( $fg_repo->get_all_events() as $event ) {
		$fg_repo->delete_event( $event->id );
	}
}

function fg_submission_post( array $overrides = array() ) {
	global $fg_repo;

	$event_ref = $GLOBALS['fg_state']['event_ref'];
	$base      = array(
		'action'           => 'fg_submit_ride',
		'fg_event_ref'     => $event_ref,
		'fg_mode'          => 'offer',
		'fg_alias'         => 'Test-Fahrgemeinschaft Nord',
		'fg_origin'        => 'Innenstadt',
		'fg_contact_email' => 'teilnehmer@example.org',
		'fg_consent'       => '1',
		'fg_website'       => '',
		'form_started_at'  => (string) ( time() - 30 ),
		'source_url'       => home_url( '/fahrgemeinschaften/' ),
		'fg_submit_nonce'  => wp_create_nonce( 'fg_submit_ride' ),
	);

	return array_merge( $base, $overrides );
}

$fg_repo    = new FG_Repository();
$fg_actions = new FG_Actions( $fg_repo );
$fg_public  = new FG_Public( $fg_repo );

$step = isset( $argv[1] ) ? $argv[1] : 'run';

if ( 'render' === $step ) {
	// Child process: render one standalone token page and exit.
	$query = array();
	parse_str( (string) $argv[2], $query );

	$_GET  = $query;
	// The output buffer callback runs before the buffer is flushed on exit,
	// so the fully rendered document can be handed back to the parent.
	ob_start(
		function ( $buffer ) {
			file_put_contents( FG_HTML_FILE, (string) $buffer );
			return '';
		}
	);

	try {
		$fg_actions_child = new FG_Actions( new FG_Repository() );
		$fg_actions_child->maybe_render_action_page();
		echo "NO-EXIT";
	} catch ( Throwable $error ) {
		echo 'EXCEPTION: ' . $error->getMessage();
	}

	exit( 0 );
}

if ( 'all' !== $step ) {
	// Single-step mode is not used; keep the guard explicit.
	fg_ok( false, 'unknown step' );
	exit( 1 );
}

@unlink( FG_STATE_FILE );
fg_state_load();
echo "== Fahrgemeinschaften smoke tests ==\n";

/* ------------------------------------------------------------------ 0 */
echo "[0] Clean slate\n";
fg_purge_events();
delete_option( FG_STATS_OPTION );
delete_option( FG_CLEANUP_OPTION );

$admin_user = get_user_by( 'login', 'fg_admin' );
if ( ! $admin_user ) {
	$admin_id = wp_insert_user(
		array(
			'user_login' => 'fg_admin',
			'user_pass'  => 'Test1234!',
			'user_email' => 'admin@example.org',
			'role'       => 'administrator',
		)
	);
	$admin_user = get_user_by( 'id', $admin_id );
}
wp_set_current_user( (int) $admin_user->ID );
fg_ok( current_user_can( 'edit_posts' ), 'administrator session available' );
fg_purge_events();
fg_ok( 0 === $fg_repo->count_events(), 'plugin data removed' );
fg_ok( 0 === fg_count_rides(), 'no rides left' );

/* ------------------------------------------------------------------ 1 */
echo "[1] Bootstrap and database schema\n";
fg_ok( FG_Schema::tables_exist(), 'both tables exist' );
fg_ok( FG_VERSION === (string) get_option( FG_Schema::OPTION ), 'schema version stored', (string) get_option( FG_Schema::OPTION ) );
fg_ok( ! post_type_exists( 'fg_arbeitsdienst' ) && ! post_type_exists( 'fg_fahrgemeinschaft' ), 'no custom post types' );
fg_ok( ! defined( 'FG_EVENT_POST_TYPE' ) && ! defined( 'FG_RIDE_POST_TYPE' ), 'no post type constants' );
$granted_before = array();
foreach ( wp_roles()->get_names() as $role_name => $label ) {
	$granted_before[ $role_name ] = (array) get_role( $role_name )->capabilities;
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
deactivate_plugins( 'my-plugin/fahrgemeinschaften.php', true );
$activation = activate_plugin( 'my-plugin/fahrgemeinschaften.php' );
$granted_after = array();
foreach ( wp_roles()->get_names() as $role_name => $label ) {
	$granted_after[ $role_name ] = (array) get_role( $role_name )->capabilities;
}
fg_ok( ! is_wp_error( $activation ), 'the plugin can be switched off and on again', is_wp_error( $activation ) ? $activation->get_error_message() : '' );
fg_ok(
	$granted_before === $granted_after,
	'activation changes no role',
	implode( ',', array_keys( array_diff_assoc( $granted_before, $granted_after ) ) )
);
$granted = (array) get_role( 'administrator' )->capabilities;
fg_ok(
	! array_intersect(
		array_keys( $granted ),
		array( 'manage_fahrgemeinschaften', 'edit_fahrgemeinschaften', 'delete_fahrgemeinschaften', 'publish_fahrgemeinschaften' )
	),
	'no plugin capabilities on the administrator role'
);
$scheduled = wp_next_scheduled( 'fg_daily_cleanup' );
fg_ok( ! empty( $scheduled ), 'daily cleanup scheduled', var_export( $scheduled, true ) );

/* ------------------------------------------------------------------ 2 */
echo "[2] Event creation, identity metadata and activity\n";
$soon      = current_datetime()->modify( '+10 days' )->format( 'Y-m-d' );
$today     = current_datetime()->format( 'Y-m-d' );
$past      = current_datetime()->modify( '-3 days' )->format( 'Y-m-d' );
$participants = array( 'Teilnehmer@example.org', 'Anbieter@example.org', 'Sucher@example.org' );

$event_id    = fg_make_event( 'Testarbeit', $soon, $participants );
$event_record = $fg_repo->get_event( $event_id );
fg_ok( 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $event_record->event_uuid ), 'UUID generated on insert', $event_record->event_uuid );
$event_ref = $event_record->public_ref;
fg_ok( is_string( $event_ref ) && '' !== $event_ref, 'public reference generated', var_export( $event_ref, true ) );
fg_ok( $fg_repo->is_event_active( $event_id ), 'future event is active' );
fg_ok(
	array( 'teilnehmer@example.org', 'anbieter@example.org', 'sucher@example.org' ) === $fg_repo->get_event_participants( $event_id ),
	'participant addresses normalized',
	wp_json_encode( $fg_repo->get_event_participants( $event_id ) )
);

$today_event = fg_make_event( 'Heutiger Dienst', $today, $participants );
fg_ok( $fg_repo->is_event_active( $today_event ), 'date-only event stays active for the whole day' );
$past_event = fg_make_event( 'Vergangener Dienst', $past, $participants );
fg_ok( ! $fg_repo->is_event_active( $past_event ), 'past event is not active' );
$inactive_event = fg_make_event( 'Nicht sichtbar', $soon, $participants, '', false );
fg_ok( ! $fg_repo->is_event_active( $inactive_event ), 'event without visibility is not active' );
$timed_event = fg_make_event( 'Geplant', $soon, $participants, '08:00' );
fg_ok( $fg_repo->is_event_active( $timed_event ), 'future event with a cut-off time counts as active' );
$overdue_event = fg_make_event( 'Heute vorbei', $today, $participants, '00:01' );
fg_ok( ! $fg_repo->is_event_active( $overdue_event ), 'a cut-off time in the past ends the visibility' );

$GLOBALS['fg_state']['event_id']      = $event_id;
$GLOBALS['fg_state']['event_ref']     = $event_ref;
$GLOBALS['fg_state']['today_event']   = $today_event;
$GLOBALS['fg_state']['past_event']    = $past_event;
$GLOBALS['fg_state']['draft_event']   = $inactive_event;

/* ------------------------------------------------------------------ 3 */
echo "[3] Public page markup\n";
$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Fahrgemeinschaften',
		'post_content' => '[fahrgemeinschaften]',
	)
);
$GLOBALS['post'] = get_post( $page_id );
setup_postdata( $GLOBALS['post'] );

$html = $fg_public->render_shortcode();
fg_contains( 'Fahrgemeinschaft anbieten', $html, 'submission form rendered' );
fg_contains( 'Aktuelle Fahrgemeinschaften', $html, 'ride list rendered' );
fg_contains( $event_ref, $html, 'event public reference used in form' );
fg_not_contains( 'Teilnehmer@example.org', $html, 'no participant address in public markup' );
fg_not_contains( $event_record->event_uuid, $html, 'no event UUID in public markup' );
fg_not_contains( 'Vergangener Dienst', $html, 'past event not listed' );
fg_not_contains( 'Nicht sichtbar', $html, 'invisible event not listed' );
fg_contains( 'Geplant', $html, 'event with a cut-off time is selectable' );
$without_source = preg_replace( '/name="source_url" value="[^"]*"/', 'name="source_url" value=""', $html );
fg_not_contains( 'page_id=', $without_source, 'no page id outside the source url field' );
fg_not_contains( '_fg_', $html, 'no internal meta keys in markup' );
fg_not_contains( 'token', $html, 'no token in markup' );

/* ---------------------------------------------------------------- 3b */
/* Both empty states of the public page, without touching the data.
 *
 * A message that only appears when a table is empty cannot be reached by
 * writing to the tables: the run would have to take the work duties of
 * whoever is using the installation offline and put them back, and a run that
 * dies half way through leaves the data broken. So the one SELECT that fills a
 * state is answered with no rows instead. Nothing is written, and the filter is
 * removed again right away, which the control at the end proves.
 */
echo "[3b] Both empty states of the public page\n";

/**
 * Returns a query filter that makes one table of the plugin look empty.
 *
 * The condition goes in front of the WHERE clause rather than at the end,
 * because a query may end in a LIMIT, where an appended AND would not parse.
 *
 * @param string $table Table name without prefix.
 * @return callable Filter for the `query` action.
 */
function fg_leeren_filter( $table ) {
	return function ( $sql ) use ( $table ) {
		if ( false !== strpos( $sql, $table ) && preg_match( '/\bWHERE\b/i', $sql ) ) {
			return preg_replace( '/\bWHERE\b/i', 'WHERE 1=0 AND', $sql, 1 );
		}
		return $sql;
	};
}

/**
 * Renders the shortcode while one table of the plugin looks empty.
 *
 * @param string $table Table name without prefix.
 * @return string Rendered page.
 */
function fg_render_with_empty( $table ) {
	$filter = fg_leeren_filter( $table );
	add_filter( 'query', $filter );
	$html = ( new FG_Public( new FG_Repository() ) )->render_shortcode();
	remove_filter( 'query', $filter );
	return $html;
}

$ohne_dienste = fg_render_with_empty( FG_Schema::events_table() );
fg_contains( 'steht kein Arbeitsdienst an', $ohne_dienste, 'without a work duty the page says so' );
fg_not_contains( 'fg-list-heading', $ohne_dienste, 'without a work duty there is no empty list' );
fg_not_contains( 'fg_submit_ride', $ohne_dienste, 'without a work duty there is no form' );
fg_contains( 'Eintrag anlegen', $ohne_dienste, 'the link to the form stays in place' );
// Counted and not read out: a check that looks for a wording only proves that
// this wording is absent. The old state carried a second message in the list
// that named work duties which did not exist, and the number of messages is
// what actually has to be one.
fg_ok( 1 === substr_count( $ohne_dienste, 'class="fg-empty"' ), 'exactly one message without a work duty', substr_count( $ohne_dienste, 'class="fg-empty"' ) . ' messages' );

$ohne_fahrten = fg_render_with_empty( FG_Schema::rides_table() );
fg_contains( 'hat sich für die anstehenden', $ohne_fahrten, 'with a work duty but no entry the page says so' );
fg_contains( 'fg-list-heading', $ohne_fahrten, 'with a work duty the list is still there' );
fg_not_contains( 'steht kein Arbeitsdienst an', $ohne_fahrten, 'the message about missing work duties stays away' );
fg_contains( 'fg_submit_ride', $ohne_fahrten, 'with a work duty the form is still there' );
fg_contains( $event_ref, $ohne_fahrten, 'the work duty is still selectable' );
fg_ok( 1 === substr_count( $ohne_fahrten, 'class="fg-empty"' ), 'exactly one message without an entry', substr_count( $ohne_fahrten, 'class="fg-empty"' ) . ' messages' );

$wieder = $fg_public->render_shortcode();
fg_contains( $event_record->title, $wieder, 'the work duties are back once the filter is gone' );
// The pages cannot be compared as a whole: the form carries a nonce and the
// second it was started, so two renders a second apart differ by design. The
// number of entries is the part that must not move.
fg_ok(
	substr_count( $html, '<article class="fg-ride">' ) === substr_count( $wieder, '<article class="fg-ride">' ),
	'every entry is still there after both renders',
	substr_count( $html, '<article class="fg-ride">' ) . ' -> ' . substr_count( $wieder, '<article class="fg-ride">' )
);

/* ------------------------------------------------------------------ 4 */
echo "[4] Submission with a valid participant address\n";
fg_mail_reset();
list( $location ) = fg_call( array( $fg_actions, 'submit_ride' ), fg_submission_post() );
fg_ok( 'pending' === fg_notice_of( $location ), 'submission redirects with pending notice', (string) $location );

$ride    = fg_newest_ride();
$ride_id = $ride ? $ride->id : 0;
fg_ok( $ride_id > 0, 'ride created' );
fg_ok( FG_RIDE_STATUS_PENDING === $ride->status, 'ride is pending', (string) $ride->status );
fg_ok( 'Test-Fahrgemeinschaft Nord' === $ride->alias, 'alias stored' );
fg_ok( FG_CONSENT_VERSION === $ride->consent_version, 'consent recorded' );
fg_ok( '' !== $ride->public_ref, 'ride reference created' );
$ride_ref = $ride->public_ref;
fg_ok( '' === $ride->confirmed_at, 'not marked as confirmed' );
fg_ok( '' !== $ride->pending_confirm_hash && '' !== $ride->pending_discard_hash, 'both pending tokens stored as hash' );
fg_ok( count( $fg_repo->get_published_rides( $event_id ) ) === 0, 'pending ride is not public' );

fg_ok( 1 === count( $GLOBALS['fg_mail'] ), 'exactly one mail sent', wp_json_encode( fg_mail_recipients() ) );
$pending_mail = fg_last_mail();
fg_ok( 'teilnehmer@example.org' === $pending_mail['to'], 'mail sent to the submitter', $pending_mail['to'] );
$body = fg_mail_bodies();
fg_contains( 'vorgemerkt', $body, 'pending mail text' );
fg_contains( 'noch nicht veröffentlicht', $body, 'pending mail explains the private phase' );
$links = fg_extract_links( $body );
fg_ok( isset( $links['confirm'] ), 'confirm link present in mail', wp_json_encode( array_keys( $links ) ) );
fg_ok( isset( $links['discard'] ), 'discard link present in mail', wp_json_encode( array_keys( $links ) ) );
$confirm_url = isset( $links['confirm'] ) ? $links['confirm'] : '';
$discard_url = isset( $links['discard'] ) ? $links['discard'] : '';

$GLOBALS['fg_state']['ride_id']    = $ride_id;
$GLOBALS['fg_state']['ride_ref']   = $ride_ref;
$GLOBALS['fg_state']['confirm_url'] = $confirm_url;
$GLOBALS['fg_state']['discard_url'] = $discard_url;
$GLOBALS['fg_state']['page_id']    = $page_id;

/* ------------------------------------------------------------------ 5 */
echo "[5] Token landing page (GET must not publish)\n";
$parts = array();
parse_str( (string) wp_parse_url( $confirm_url, PHP_URL_QUERY ), $parts );
fg_ok( 'view' === $parts['fg_ride_action'] && 'confirm' === $parts['intent'], 'link uses view intent' );

list( $html, $err ) = fg_render_action_page( $parts );
fg_ok( '' !== $html, 'standalone page rendered', $err );
fg_not_contains( 'Fatal error', $html, 'no fatal error on token page' );
fg_not_contains( 'Warning:', $html, 'no PHP warning on token page' );
fg_contains( 'Veröffentlichung bestätigen', $html, 'confirm page heading' );
fg_contains( 'Test-Fahrgemeinschaft Nord', $html, 'confirm page shows the alias' );
fg_not_contains( 'teilnehmer@example.org', $html, 'token page hides the address' );
fg_contains( 'name="token_nonce"', $html, 'token form carries its verifier' );
fg_contains( 'name="intent" value="confirm"', $html, 'token form carries the intent' );
fg_ok( FG_RIDE_STATUS_PENDING === $fg_repo->get_ride( $ride_id )->status, 'GET did not change the status' );

list( $bad_html ) = fg_render_action_page(
	array(
		'fg_ride_action' => 'view',
		'ride_ref'       => $ride_ref,
		'intent'         => 'confirm',
		'token'          => 'not-a-real-token',
	)
);
fg_contains( 'Link nicht gültig', $bad_html, 'invalid token page' );

/* ------------------------------------------------------------------ 6 */
echo "[6] Confirmation (POST)\n";
$token        = $parts['token'];
$token_nonce  = hash_hmac( 'sha256', 'confirm|' . $token, wp_salt( 'nonce' ) );
fg_mail_reset();

list( $location ) = fg_call(
	array( $fg_actions, 'process_ride_token' ),
	array(
		'action'      => 'fg_process_ride_token',
		'ride_ref'    => $ride_ref,
		'intent'      => 'confirm',
		'token'       => $token,
		'token_nonce' => $token_nonce,
		'source_url'  => home_url( '/fahrgemeinschaften/' ),
	)
);
fg_ok( 'published' === fg_notice_of( $location ), 'confirmation succeeds', (string) $location );
fg_ok( FG_RIDE_STATUS_PUBLISHED === $fg_repo->get_ride( $ride_id )->status, 'ride is published' );
fg_ok( '' !== $fg_repo->get_ride( $ride_id )->confirmed_at, 'confirmation marker set' );
fg_ok( 1 === count( $GLOBALS['fg_mail'] ), 'published mail sent', wp_json_encode( fg_mail_recipients() ) );

$body = fg_mail_bodies();
fg_contains( 'Löschen', $body, 'delete link mentioned' );
fg_contains( 'Ist der Arbeitsdienst vorbei, wird dein Eintrag automatisch aus der öffentlichen Anzeige entfernt.', $body, 'exact required sentence present' );
$links = fg_extract_links( $body );
fg_ok( isset( $links['delete'] ), 'delete link present in mail', wp_json_encode( array_keys( $links ) ) );
$delete_url = isset( $links['delete'] ) ? $links['delete'] : '';
fg_ok( '' === $fg_repo->get_ride( $ride_id )->pending_confirm_hash, 'pending confirm token consumed' );
fg_ok( '' === $fg_repo->get_ride( $ride_id )->pending_discard_hash, 'pending discard token consumed' );
fg_ok( '' !== $fg_repo->get_ride( $ride_id )->delete_hash, 'deletion token stored as hash' );

$GLOBALS['fg_state']['delete_url'] = $delete_url;

$html = $fg_public->render_shortcode();
fg_contains( 'Test-Fahrgemeinschaft Nord', $html, 'published ride is listed' );
fg_not_contains( 'teilnehmer@example.org', $html, 'published list hides the address' );
fg_not_contains( $event_record->event_uuid, $html, 'no event UUID in the published list' );
fg_not_contains( 'fg_fahrgemeinschaft=', $html, 'no ride permalink in the published list' );
fg_not_contains( 'fg_arbeitsdienst=', $html, 'no event permalink in the published list' );

echo "[6b] Replayed confirm token must be refused\n";
list( $location ) = fg_call(
	array( $fg_actions, 'process_ride_token' ),
	array(
		'action'      => 'fg_process_ride_token',
		'ride_ref'    => $ride_ref,
		'intent'      => 'confirm',
		'token'       => $token,
		'token_nonce' => $token_nonce,
		'source_url'  => home_url( '/fahrgemeinschaften/' ),
	)
);
fg_ok( 'invalid_token' === fg_notice_of( $location ), 'replay rejected', (string) $location );
fg_ok( FG_RIDE_STATUS_PUBLISHED === $fg_repo->get_ride( $ride_id )->status, 'ride still published after replay' );

/* ------------------------------------------------------------------ 7 */
echo "[7] Contact requests\n";
fg_mail_reset();
list( $location ) = fg_call(
	array( $fg_actions, 'contact_ride' ),
	array(
		'action'           => 'fg_contact_ride',
		'ride_ref'         => $ride_ref,
		'fg_contact_email' => 'fremd@example.com',
		'fg_website'       => '',
		'form_started_at'  => (string) ( time() - 30 ),
		'source_url'       => home_url( '/fahrgemeinschaften/' ),
		'fg_contact_nonce' => wp_create_nonce( 'fg_contact_ride' ),
	)
);
fg_ok( 'contact_received' === fg_notice_of( $location ), 'neutral answer for a foreign address', (string) $location );
fg_ok( 0 === count( $GLOBALS['fg_mail'] ), 'no mail for a non-participant', wp_json_encode( fg_mail_recipients() ) );

list( $location ) = fg_call(
	array( $fg_actions, 'contact_ride' ),
	array(
		'action'           => 'fg_contact_ride',
		'ride_ref'         => $ride_ref,
		'fg_contact_email' => 'sucher@example.org',
		'fg_website'       => '',
		'form_started_at'  => (string) ( time() - 30 ),
		'source_url'       => home_url( '/fahrgemeinschaften/' ),
		'fg_contact_nonce' => wp_create_nonce( 'fg_contact_ride' ),
	)
);
fg_ok( 'contact_received' === fg_notice_of( $location ), 'participant request accepted' );
fg_ok( 2 === count( $GLOBALS['fg_mail'] ), 'creator and requester notified', wp_json_encode( fg_mail_recipients() ) );
$body = fg_mail_bodies();
fg_contains( 'Wir haben den Ersteller der Fahrgemeinschaft benachrichtigt.', $body, 'exact required sentence present' );
fg_contains( 'Reply-To: sucher@example.org', implode( "\n", array_map( function ( $m ) { return implode( "\n", (array) $m['headers'] ); }, $GLOBALS['fg_mail'] ) ), 'reply-to set' );

echo "[7b] Creator as requester and honeypot stay neutral\n";
fg_mail_reset();
list( $location ) = fg_call(
	array( $fg_actions, 'contact_ride' ),
	array(
		'action'           => 'fg_contact_ride',
		'ride_ref'         => $ride_ref,
		'fg_contact_email' => 'anbieter@example.org',
		'fg_website'       => 'https://spam.example',
		'form_started_at'  => (string) ( time() - 30 ),
		'source_url'       => home_url( '/fahrgemeinschaften/' ),
		'fg_contact_nonce' => wp_create_nonce( 'fg_contact_ride' ),
	)
);
fg_ok( 'contact_received' === fg_notice_of( $location ), 'honeypot answer is identical' );
fg_ok( 0 === count( $GLOBALS['fg_mail'] ), 'honeypot sends nothing' );

/* ------------------------------------------------------------------ 8 */
echo "[8] Self-service deletion\n";
$parts = array();
parse_str( (string) wp_parse_url( $GLOBALS['fg_state']['delete_url'], PHP_URL_QUERY ), $parts );
list( $html ) = fg_render_action_page( $parts );
fg_contains( 'löschen', $html, 'delete page rendered' );
fg_ok( FG_RIDE_STATUS_PUBLISHED === $fg_repo->get_ride( $ride_id )->status, 'GET does not delete' );

list( $location ) = fg_call(
	array( $fg_actions, 'process_ride_token' ),
	array(
		'action'      => 'fg_process_ride_token',
		'ride_ref'    => $ride_ref,
		'intent'      => 'delete',
		'token'       => $parts['token'],
		'token_nonce' => hash_hmac( 'sha256', 'delete|' . $parts['token'], wp_salt( 'nonce' ) ),
		'source_url'  => home_url( '/fahrgemeinschaften/' ),
	)
);
fg_ok( 'deleted' === fg_notice_of( $location ), 'delete succeeds', (string) $location );
fg_ok( null === $fg_repo->get_ride( $ride_id ), 'ride permanently removed' );
fg_ok( 0 === fg_count_rides(), 'no row of the deleted ride is left behind' );

/* ------------------------------------------------------------------ 9 */
echo "[9] Discard of a pending entry\n";
fg_mail_reset();
fg_call( array( $fg_actions, 'submit_ride' ), fg_submission_post( array( 'fg_alias' => 'Wegwerf-Eintrag' ) ) );
$discard_ride = fg_newest_ride();
$discard_id   = $discard_ride ? $discard_ride->id : 0;
$body         = fg_mail_bodies();
$discard_ref  = $discard_ride ? $discard_ride->public_ref : '';
$links        = fg_extract_links( $body );
$discard_token = '';
if ( isset( $links['discard'] ) ) {
	$discard_args = array();
	parse_str( (string) wp_parse_url( $links['discard'], PHP_URL_QUERY ), $discard_args );
	$discard_token = isset( $discard_args['token'] ) ? $discard_args['token'] : '';
}
fg_ok( '' !== $discard_token, 'discard token extracted' );

list( $location ) = fg_call(
	array( $fg_actions, 'process_ride_token' ),
	array(
		'action'      => 'fg_process_ride_token',
		'ride_ref'    => $discard_ref,
		'intent'      => 'discard',
		'token'       => $discard_token,
		'token_nonce' => hash_hmac( 'sha256', 'discard|' . $discard_token, wp_salt( 'nonce' ) ),
		'source_url'  => home_url( '/fahrgemeinschaften/' ),
	)
);
fg_ok( 'deleted' === fg_notice_of( $location ), 'discard succeeds', (string) $location );
fg_ok( null === $fg_repo->get_ride( $discard_id ), 'pending entry removed' );

/* ------------------------------------------------------------------ 10 */
echo "[10] Rejected submissions\n";
$cases = array(
	'foreign address'   => array( 'fg_contact_email' => 'nicht-teilnehmer@example.com' ),
	'invalid address'   => array( 'fg_contact_email' => 'keine-mail' ),
	'missing consent'   => array( 'fg_consent' => '0' ),
	'empty alias'       => array( 'fg_alias' => '' ),
	'unknown event'     => array( 'fg_event_ref' => 'gibtesnicht' ),
	'bad mode'          => array( 'fg_mode' => 'egal' ),
	'mail in alias'     => array( 'fg_alias' => 'Max Mustermann (max@example.org)' ),
	'phone in origin'   => array( 'fg_origin' => '0176 12345678' ),
	'address in origin' => array( 'fg_origin' => 'Hauptstraße 12' ),
	'honeypot'          => array( 'fg_website' => 'x' ),
	'oversized alias'   => array( 'fg_alias' => str_repeat( 'a', 81 ) ),
);

foreach ( $cases as $label => $overrides ) {
	fg_mail_reset();
	$before = fg_count_rides( FG_RIDE_STATUS_PENDING );
	list( $location ) = fg_call( array( $fg_actions, 'submit_ride' ), fg_submission_post( $overrides ) );
	$after = fg_count_rides( FG_RIDE_STATUS_PENDING );
	fg_ok(
		'not_created' === fg_notice_of( $location ) && $before === $after,
		"rejected: {$label}",
		(string) $location
	);
}

list( $location ) = fg_call(
	array( $fg_actions, 'submit_ride' ),
	fg_submission_post( array( 'fg_submit_nonce' => 'invalid' ) )
);
fg_ok( 'form_expired' === fg_notice_of( $location ), 'expired form gets a reload hint', (string) $location );

$stats = ( new FG_Stats() )->get_recent( 1 );
fg_ok( $stats['publish_personal_data'] >= 3, 'personal data rejections counted', (string) $stats['publish_personal_data'] );

echo "[10b] Only form submissions are accepted\n";
$pending_before = fg_count_rides( FG_RIDE_STATUS_PENDING );
$die_args       = fg_expect_die( array( $fg_actions, 'submit_ride' ), 'GET' );
fg_ok( is_array( $die_args ) && 405 === (int) $die_args['response'], 'GET on the submit endpoint is refused', wp_json_encode( $die_args ) );

$die_args = fg_expect_die( array( $fg_actions, 'contact_ride' ), 'GET' );
fg_ok( is_array( $die_args ) && 405 === (int) $die_args['response'], 'GET on the contact endpoint is refused', wp_json_encode( $die_args ) );

$die_args = fg_expect_die(
	array( $fg_actions, 'process_ride_token' ),
	'HEAD',
	array(
		'ride_ref' => $ride_ref,
		'intent'   => 'delete',
		'token'    => 'irrelevant',
	)
);
fg_ok( is_array( $die_args ) && 405 === (int) $die_args['response'], 'HEAD on the token endpoint is refused', wp_json_encode( $die_args ) );
fg_ok( $pending_before === fg_count_rides( FG_RIDE_STATUS_PENDING ), 'refused requests change nothing' );

/* ------------------------------------------------------------------ 11 */
echo "[11] Rides are read-only for administrators\n";
$admin_event = fg_make_event( 'Admin-Dienst', $soon, array( 'admin@example.org', 'mitfahrer@example.org' ) );
$admin_ride  = $fg_repo->create_pending_ride(
	array(
		'event_id'      => $admin_event,
		'mode'          => FG_RIDE_MODE_SEARCH,
		'alias'         => 'Admin-Eintrag',
		'origin'        => 'Sued',
		'contact_email' => 'admin@example.org',
	)
);
$admin_row = $fg_repo->get_ride( $admin_ride['id'] );
fg_ok( '' !== $admin_row->public_ref, 'every ride gets a reference' );
fg_ok( FG_RIDE_STATUS_PENDING === $admin_row->status, 'a new ride is never public before confirmation' );
fg_ok( ! $fg_repo->is_valid_public_ride( $admin_row ), 'unconfirmed ride is not valid' );
fg_ok( false === has_action( 'admin_post_fg_save_ride' ), 'no endpoint can change a ride' );
fg_ok( null !== $fg_repo->get_ride_by_reference( $admin_row->public_ref ), 'a ride can be addressed by its reference' );
fg_ok( null === $fg_repo->get_ride_by_reference( 'gibtesnicht' ), 'an unknown reference resolves to nothing' );

$fg_repo->update_ride(
	$admin_ride['id'],
	array(
		'status'       => FG_RIDE_STATUS_PUBLISHED,
		'confirmed_at' => current_time( 'mysql' ),
	)
);
fg_ok( $fg_repo->is_valid_public_ride( $fg_repo->get_ride( $admin_ride['id'] ) ), 'a complete published ride is public' );

// A published ride that loses a required field must disappear from the public
// list instead of going out with half a record.
$fg_repo->update_ride( $admin_ride['id'], array( 'contact_email' => 'keine-mail' ) );
fg_ok( ! $fg_repo->is_valid_public_ride( $fg_repo->get_ride( $admin_ride['id'] ) ), 'a ride with an unusable address is not public' );
$public_html = $fg_public->render_shortcode();
fg_not_contains( 'Admin-Eintrag', $public_html, 'incomplete ride stays out of the public list' );

/* ------------------------------------------------------------------ 12 */
echo "[12] Event validation and cascade delete\n";
fg_ok(
	0 === $fg_repo->insert_event(
		array(
			'title'        => 'Kaputter Dienst',
			'event_date'   => '2026-13-45',
			'participants' => array( 'kaputt@example.org' ),
			'is_active'    => true,
		)
	),
	'an event with an invalid date is never stored'
);
fg_ok(
	0 === $fg_repo->insert_event(
		array(
			'title'        => 'Kaputte Zeit',
			'event_date'   => $soon,
			'event_time'   => '99:99',
			'participants' => array( 'kaputt@example.org' ),
			'is_active'    => true,
		)
	),
	'an event with an invalid time is never stored'
);

$bad_event = fg_make_event( 'Kaputter Dienst', $soon, "gut@example.org\nungueltig\n" );
fg_ok( array( 'gut@example.org' ) === $fg_repo->get_event_participants( $bad_event ), 'valid participants stored, invalid ones dropped', wp_json_encode( $fg_repo->get_event_participants( $bad_event ) ) );
fg_ok( ! $fg_repo->update_event( $bad_event, array( 'event_date' => '2026-13-45' ) ), 'an invalid date is not written over a valid one' );
fg_ok( $soon === $fg_repo->get_event( $bad_event )->event_date, 'valid date of a previous save is kept', (string) $fg_repo->get_event( $bad_event )->event_date );
fg_ok( ! $fg_repo->update_event( $bad_event, array( 'is_active' => true, 'event_date' => '' ) ), 'an event cannot be published without a date' );
fg_ok( $fg_repo->update_event( $bad_event, array( 'is_active' => false ) ), 'an event can be withdrawn' );
fg_ok( ! $fg_repo->is_event_active( $bad_event ), 'withdrawn event is no longer offered' );

$cascade_event = fg_make_event( 'Kaskade', $soon, array( 'kette@example.org' ) );
$fg_repo->create_pending_ride(
	array(
		'event_id'      => $cascade_event,
		'mode'          => FG_RIDE_MODE_OFFER,
		'alias'         => 'Kaskadenfahrt',
		'origin'        => 'Innenstadt',
		'contact_email' => 'kette@example.org',
	)
);
$cascade_rides = $fg_repo->get_event_ride_ids( $cascade_event );
fg_ok( 1 === count( $cascade_rides ), 'cascade source found' );
$cascade_result = $fg_repo->delete_event( $cascade_event );
fg_ok( $cascade_result['deleted'] && 1 === $cascade_result['rides'], 'cascade reports what it removed', wp_json_encode( $cascade_result ) );
$remaining = 0;
foreach ( $cascade_rides as $cascade_ride ) {
	if ( $fg_repo->get_ride( $cascade_ride ) ) {
		++$remaining;
	}
}
fg_ok( 0 === $remaining, 'deleting an event deletes its rides' );
fg_ok( null === $fg_repo->get_event( $cascade_event ), 'work duty removed' );

/* ------------------------------------------------------------------ 13 */
echo "[13] Daily cleanup and statistics retention\n";
$expired = $fg_repo->create_pending_ride(
	array(
		'event_id'      => $event_id,
		'mode'          => FG_RIDE_MODE_OFFER,
		'alias'         => 'Abgelaufen',
		'origin'        => 'Innenstadt',
		'contact_email' => 'abgelaufen@example.org',
	)
);
$expired_ride = $expired['id'];
$fg_repo->update_ride(
	$expired_ride,
	array(
		'pending_confirm_expires' => time() - 10,
		'pending_discard_expires' => time() - 10,
	)
);
$expired_published = $fg_repo->create_pending_ride(
	array(
		'event_id'      => $event_id,
		'mode'          => FG_RIDE_MODE_OFFER,
		'alias'         => 'Veralteter Löschlink',
		'origin'        => 'Innenstadt',
		'contact_email' => 'abgelaufen@example.org',
	)
);
$fg_repo->update_ride(
	$expired_published['id'],
	array(
		'status'         => FG_RIDE_STATUS_PUBLISHED,
		'confirmed_at'   => current_time( 'mysql' ),
		'delete_hash'    => hash( 'sha256', 'z' ),
		'delete_expires' => time() - 10,
	)
);
$fg_repo->update_ride( $expired['id'], array( 'pending_confirm_hash' => hash( 'sha256', 'x' ), 'pending_discard_hash' => hash( 'sha256', 'y' ) ) );

$stats_option = (array) get_option( FG_STATS_OPTION, array() );
$old_date     = gmdate( 'Y-m-d', time() - ( FG_STATISTICS_RETENTION_DAYS + 5 ) * DAY_IN_SECONDS );
$stats_option[ $old_date ] = array( 'publish_form_total' => 7 );
$stats_option[ gmdate( 'Y-m-d' ) ] = array( 'publish_form_total' => 1 );
update_option( FG_STATS_OPTION, $stats_option, false );

$fg_actions->daily_cleanup();
fg_ok( null === $fg_repo->get_ride( $expired_ride ), 'expired pending ride removed' );
$kept_ride = $fg_repo->get_ride( $expired_published['id'] );
fg_ok( null !== $kept_ride, 'published ride itself is kept' );
fg_ok( '' === $kept_ride->delete_hash && 0 === $kept_ride->delete_expires, 'expired deletion token cleared', wp_json_encode( array( $kept_ride->delete_hash, $kept_ride->delete_expires ) ) );
$stats_option = (array) get_option( FG_STATS_OPTION, array() );
fg_ok( ! isset( $stats_option[ $old_date ] ), 'statistics older than the retention period pruned' );
fg_ok( ! empty( get_option( FG_CLEANUP_OPTION ) ), 'cleanup marker written' );

echo "[13b] Cleanup fallback without WP-Cron\n";
delete_option( FG_CLEANUP_OPTION );
do_action( 'admin_init' );
fg_ok( ! empty( get_option( FG_CLEANUP_OPTION ) ), 'admin fallback runs the cleanup' );

/* ------------------------------------------------------------------ 14 */
echo "[14] Privacy export and erasure\n";
$privacy = new FG_Privacy( $fg_repo );
$bulk_event = fg_make_event( 'Datenschutz', $soon, array( 'opfer@example.org', 'ander@example.org' ) );
for ( $i = 0; $i < 25; $i++ ) {
	$bulk = $fg_repo->create_pending_ride(
		array(
			'event_id'      => $bulk_event,
			'mode'          => FG_RIDE_MODE_OFFER,
			'alias'         => 'Datenschutztest ' . $i,
			'origin'        => 'Innenstadt',
			'contact_email' => 'opfer@example.org',
		)
	);
	$fg_repo->update_ride(
		$bulk['id'],
		array(
			'status'       => FG_RIDE_STATUS_PUBLISHED,
			'confirmed_at' => current_time( 'mysql' ),
		)
	);
}
fg_ok( 25 === count( $fg_repo->get_event_ride_ids( $bulk_event ) ), 'bulk rides created' );

$export = $privacy->export( 'opfer@example.org', 1 );
fg_ok( ! empty( $export['data'] ), 'export returns data' );
fg_ok( 11 === count( $export['data'] ), 'export page is limited to ten rides plus the work duty', (string) count( $export['data'] ) );
$export_all = 0;
$page       = 1;
do {
	$result    = $privacy->export( 'opfer@example.org', $page );
	$export_all += count( $result['data'] );
	++$page;
} while ( ! $result['done'] && $page < 10 );
fg_ok( 26 === $export_all, 'all records exported', (string) $export_all );

// Mirror the WordPress core loop over the eraser.
$page = 1;
do {
	$result = $privacy->erase( 'opfer@example.org', $page );
	++$page;
} while ( ! $result['done'] && $page < 20 );

$left = $fg_repo->get_event_ride_ids( $bulk_event );
fg_ok( 0 === count( $left ), 'all rides of the data subject removed', (string) count( $left ) );
fg_ok( ! $fg_repo->is_event_participant( $bulk_event, 'opfer@example.org' ), 'address removed from the participant list' );
fg_ok( $fg_repo->is_event_participant( $bulk_event, 'ander@example.org' ), 'other participants untouched' );

/* ------------------------------------------------------------------ 15 */
echo "[15] HTTPS enforcement\n";
$_SERVER['HTTPS'] = '';
$_GET             = array();
$GLOBALS['post']  = get_post( $page_id );
setup_postdata( $GLOBALS['post'] );
$location = null;
$catcher  = function ( $url, $status ) {
	throw new FG_Redirect( (string) $url, (int) $status );
};
add_filter( 'wp_redirect', $catcher, 10, 2 );
try {
	$fg_actions->maybe_require_https();
} catch ( FG_Redirect $redirect ) {
	$location = $redirect->location;
}
remove_filter( 'wp_redirect', $catcher, 10 );
fg_ok( is_string( $location ) && 0 === strpos( $location, 'https://' ), 'public page redirects to HTTPS', (string) $location );

// A site that is not configured for HTTPS has no secure variant to redirect
// to. Upgrading the request there would break the page, so nothing happens and
// the administrator is warned instead.
$https_home = get_option( 'home' );
update_option( 'home', 'http://localhost:8443' );
$_SERVER['HTTPS'] = '';
$location          = null;
add_filter( 'wp_redirect', $catcher, 10, 2 );
try {
	$fg_actions->maybe_require_https();
} catch ( FG_Redirect $redirect ) {
	$location = $redirect->location;
}
remove_filter( 'wp_redirect', $catcher, 10 );
fg_ok( null === $location, 'no HTTPS redirect on a plain HTTP installation', (string) $location );
fg_ok( false === FG_Security::site_uses_https(), 'plain HTTP site is detected as such' );
update_option( 'home', $https_home );
fg_ok( FG_Security::site_uses_https(), 'HTTPS site is detected as such' );

// A submission is not redirected to a nonexistent HTTPS either, it is processed.
$https_home = get_option( 'home' );
update_option( 'home', 'http://localhost:8443' );
$http_event = fg_make_event( 'Testdienst ohne TLS', gmdate( 'Y-m-d', time() + 6 * DAY_IN_SECONDS ), array( 'teilnehmer@example.org' ) );
$http_ref   = $fg_repo->get_event( $http_event )->public_ref;
fg_mail_reset();
$_SERVER['HTTPS'] = '';
list( $location )   = fg_call(
	array( $fg_actions, 'submit_ride' ),
	fg_submission_post(
		array(
			'fg_event_ref' => $http_ref,
			'source_url'   => 'http://localhost:8443/fahrgemeinschaften/',
		)
	)
);
fg_ok( 'pending' === fg_notice_of( $location ), 'submission is processed instead of redirected', (string) $location );
fg_ok( count( fg_mail_recipients() ) > 0, 'confirmation mail is sent on a plain HTTP site' );
$fg_repo->delete_event( $http_event );

$shortcode_html = $fg_public->render_shortcode();
fg_ok( false !== strpos( $shortcode_html, 'fg-wrapper' ), 'form is not blocked without TLS' );
update_option( 'home', $https_home );
$shortcode_html = $fg_public->render_shortcode();
fg_ok( false === strpos( $shortcode_html, 'fg-wrapper' ), 'form is refused over plain HTTP on an HTTPS site' );

$_SERVER['HTTPS'] = 'on';

list( $location ) = fg_call(
	array( $fg_actions, 'submit_ride' ),
	fg_submission_post( array( 'source_url' => 'https://evil.example.net/steal' ) )
);
fg_ok( false !== strpos( (string) $location, (string) home_url( '/' ) ), 'source url cannot point elsewhere', (string) $location );

/* ------------------------------------------------------------------ 16 */
echo "[16] Public markup hygiene of the delivered plugin\n";
$plugin_dir = WP_PLUGIN_DIR . '/my-plugin';
$leaks      = array();
$scanned    = 0;
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_dir ) ) as $file ) {
	if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
		++$scanned;
		$source = (string) file_get_contents( $file->getPathname() );
		if ( preg_match( '/\b(wp_remote_get|wp_remote_post|curl_exec|file_get_contents\(\s*[\'"]https?:)/i', $source ) ) {
			$leaks[] = 'remote request in ' . $file->getFilename();
		}
		if ( preg_match( '/\$wpdb\s*->\s*(query|get_results|get_row|get_var|get_col)\s*\(\s*["\']/', $source ) ) {
			$leaks[] = 'unprepared SQL in ' . $file->getFilename();
		}
	}
}
fg_ok( $scanned >= 14, 'every delivered PHP file is scanned', (string) $scanned );
fg_ok( empty( $leaks ), 'no remote requests and no unprepared SQL', implode( ', ', $leaks ) );
$uninstall = (string) file_get_contents( $plugin_dir . '/uninstall.php' );
fg_ok( false !== strpos( $uninstall, 'DROP TABLE' ), 'uninstall removes the tables' );

/* ------------------------------------------------------------------ done */
fg_state_save();
$failed = count( $GLOBALS['fg_fail'] );
echo "\n== {$GLOBALS['fg_pass']} passed, {$failed} failed ==\n";
foreach ( $GLOBALS['fg_fail'] as $failure ) {
	echo " - {$failure}\n";
}
exit( $failed > 0 ? 1 : 0 );
