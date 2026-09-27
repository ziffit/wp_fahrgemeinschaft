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
	return fg_render_standalone( 'render', $get );
}

/**
 * Render the unregistration page of a duty registration in a child process.
 */
function fg_render_duty_action_page( array $get ) {
	return fg_render_standalone( 'render-duty', $get );
}

/**
 * Run one rendering step of this file in a child process and read its output.
 *
 * A standalone page ends the request, which is what exit() does in the handler.
 * The document is caught by an output buffer callback and written to a file,
 * because the buffer is flushed after that callback has run.
 *
 * @param string $step Child step to run.
 * @param array  $get  Query arguments for the page.
 * @return array{0: string, 1: string, 2: int, 3: string} HTML, error text, exit code, output.
 */
function fg_render_standalone( $step, array $get ) {
	@unlink( FG_HTML_FILE );

	$query = http_build_query( $get );
	$cmd   = 'php ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $step ) . ' ' . escapeshellarg( $query ) . ' 2>/tmp/fgtests/render.err';
	exec( $cmd, $out, $code );
	$html = file_exists( FG_HTML_FILE ) ? (string) file_get_contents( FG_HTML_FILE ) : '';
	$err  = file_exists( '/tmp/fgtests/render.err' ) ? (string) file_get_contents( '/tmp/fgtests/render.err' ) : '';

	return array( $html, $err, $code, implode( "\n", $out ) );
}

function fg_make_event( $title, $date, $time = '', $active = true, array $details = array() ) {
	global $fg_repo;

	$event_id = $fg_repo->insert_event(
		array_merge(
			array(
				'title'      => $title,
				'event_date' => $date,
				'event_time' => $time,
				'is_active'  => $active,
			),
			$details
		)
	);
	if ( ! $event_id ) {
		throw new Exception( 'event insert failed' );
	}

	return (int) $event_id;
}

/**
 * Create a member, or return the one that is already there.
 *
 * The fixtures are created twice by a run of the whole file, and a member
 * number is unique, so a second insert of the same fixture is a refusal and not
 * a new member. Returning the existing one keeps the helper usable from every
 * section without every section having to know which one ran first.
 *
 * @param string $number Member number.
 * @param string $email  E-mail address.
 * @param string $first  First name.
 * @param string $last   Last name.
 * @return FG_Member
 */
function fg_make_member( $number, $email, $first = 'Test', $last = 'Mitglied' ) {
	global $fg_repo;

	$found = $fg_repo->get_member_by_number( $number );
	if ( $found ) {
		return $found;
	}

	$id = $fg_repo->insert_member(
		array(
			'member_no'  => $number,
			'email'      => $email,
			'first_name' => $first,
			'last_name'  => $last,
		)
	);
	if ( ! $id ) {
		throw new Exception( 'member insert failed: ' . $number );
	}

	$member = $fg_repo->get_member( $id );
	if ( ! $member ) {
		throw new Exception( 'member read back failed: ' . $number );
	}

	return $member;
}

/**
 * Register a member for a work service and return the raw token.
 *
 * The public form is the way a member really gets in, but most of the checks
 * below are about what is in the table and not about the form that filled it.
 * This helper goes through the repository and hands back the token, which the
 * form would have put into the confirmation mail.
 *
 * @param int $event_id  Work service ID.
 * @param int $member_id Member ID.
 * @return array{id: int, public_ref: string, unregister_token: string}
 */
function fg_register( $event_id, $member_id ) {
	global $fg_repo;

	$created = $fg_repo->create_registration( $event_id, $member_id );
	if ( ! $created['id'] ) {
		throw new Exception( 'registration failed: ' . $event_id . '/' . $member_id );
	}

	return $created;
}

/**
 * Register the three fixture members for a work service.
 *
 * @param int $event_id Work service ID.
 * @return void
 */
function fg_register_fixture_trio( $event_id ) {
	foreach ( array( fg_member_id( 'teilnehmer' ), fg_member_id( 'anbieter' ), fg_member_id( 'sucher' ) ) as $member_id ) {
		fg_register( $event_id, $member_id );
	}
}

/**
 * The ID of one of the three fixture members.
 *
 * @param string $which One of teilnehmer, anbieter, sucher.
 * @return int
 */
function fg_member_id( $which ) {
	$members = array(
		'teilnehmer' => '100',
		'anbieter'   => '200',
		'sucher'     => '300',
	);

	if ( ! isset( $members[ $which ] ) ) {
		throw new Exception( 'unknown fixture member: ' . $which );
	}

	$member = fg_make_member(
		$members[ $which ],
		$which . '@example.org',
		ucfirst( $which ),
		'Probe'
	);

	return (int) $member->id;
}

/**
 * Offer one set of fields to the store and undo it right away.
 *
 * The refusal is the thing under test in [2a], so the record that a mistake
 * would create is removed again. Otherwise one failed check would leave a work
 * duty behind that the counts of the later sections would trip over.
 *
 * @param array $fields Fields for the insert.
 * @return bool Whether the store accepted the fields.
 */
function fg_event_accepted( array $fields ) {
	global $fg_repo;

	$id = $fg_repo->insert_event( $fields );
	if ( ! $id ) {
		return false;
	}

	$fg_repo->delete_event( $id );
	return true;
}

/**
 * Reduce rendered HTML to one line of plain text.
 *
 * The cards are written across several lines on purpose, so the wording of a
 * row cannot be looked for in the markup directly. Stripping the tags and
 * folding the whitespace leaves "Datum Montag, den 5. Oktober 2026", which can
 * be compared with a sentence.
 *
 * @param string $html Rendered page.
 * @return string
 */
function fg_text_of( $html ) {
	return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $html ) ) );
}

/**
 * Cut out the card of one work duty from the rendered list.
 *
 * The checks are about one duty and not about the whole list. The list holds
 * the work duties of whoever is using the installation, so a row belonging to
 * another duty could make a check pass that has nothing to do with the one
 * under test.
 *
 * @param string $html  Rendered list.
 * @param string $title Title of the duty.
 * @return string The card, or an empty string when there is none.
 */
function fg_card_of( $html, $title ) {
	$start = strpos( (string) $html, '<h3 class="fg-event-title">' . esc_html( $title ) . '</h3>' );
	if ( false === $start ) {
		return '';
	}

	$end = strpos( (string) $html, '</article>', $start );
	return substr( (string) $html, $start, false === $end ? strlen( (string) $html ) : $end - $start );
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

/**
 * Find the rides whose work service is gone, and remove them.
 *
 * Deleting a work service takes its rides with it, so a ride without one means
 * a hole in that cascade — or a row a run of before left behind when the
 * service was removed by other means. Either way such a row poisons checks that
 * have nothing to do with it: the suite counts the rides in the whole table,
 * so one leftover turns "no rides left" and "no row of the deleted ride is left
 * behind" into two failures whose cause is nowhere near their own output. They
 * are therefore named here, before the rest of the run measures anything, and
 * removed so the run can still measure what it came to measure.
 *
 * @return array List of the orphaned rows, as id and the service they point at.
 */
function fg_purge_orphan_rides() {
	global $wpdb;

	$table  = FG_Schema::rides_table();
	$events = FG_Schema::events_table();
	$rows   = $wpdb->get_results( "SELECT r.id, r.event_id FROM {$table} r LEFT JOIN {$events} e ON e.id = r.event_id WHERE e.id IS NULL", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- a fixture, once per run, names built from the schema.

	foreach ( $rows as $row ) {
		$wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );
	}

	return $rows;
}

/**
 * Remove every member, and with them every registration.
 *
 * A member that is deleted takes its registrations with it, so this leaves the
 * registration table empty as well. Doing it the other way round would leave
 * rows pointing at a member that is not there.
 */
function fg_purge_members() {
	global $fg_repo;

	foreach ( $fg_repo->get_members_page() as $member ) {
		$fg_repo->delete_member( $member->id );
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

if ( 'render' === $step || 'render-duty' === $step ) {
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
		if ( 'render-duty' === $step ) {
			$fg_actions_child->maybe_render_duty_action_page();
		} else {
			$fg_actions_child->maybe_render_action_page();
		}
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
fg_purge_members();
$waisen = fg_purge_orphan_rides();
fg_ok( empty( $waisen ), 'no ride is left without its work service', wp_json_encode( $waisen ) );
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
fg_purge_members();
fg_ok( 0 === $fg_repo->count_events(), 'plugin data removed' );
fg_ok( 0 === fg_count_rides(), 'no rides left' );
fg_ok( 0 === $fg_repo->count_members(), 'no members left' );

/* ------------------------------------------------------------------ 1 */
echo "[1] Bootstrap and database schema\n";
fg_ok( FG_Schema::tables_exist(), 'all four tables exist' );
// The stored schema version has to be the one the code carries, so a pending
// migration cannot pass unnoticed. It used to be compared against FG_VERSION,
// the version of the plugin: the two numbers mean different things and have
// never been equal since the plugin left 1.0.0, so the check could only ever
// have passed by accident.
fg_ok( FG_Schema::VERSION === (string) get_option( FG_Schema::OPTION ), 'the stored schema version is the one the code carries', (string) get_option( FG_Schema::OPTION ) );
fg_ok( ! post_type_exists( 'fg_arbeitsdienst' ) && ! post_type_exists( 'fg_fahrgemeinschaft' ), 'no custom post types' );
fg_ok( ! defined( 'FG_EVENT_POST_TYPE' ) && ! defined( 'FG_RIDE_POST_TYPE' ), 'no post type constants' );
$granted_before = array();
foreach ( wp_roles()->get_names() as $role_name => $label ) {
	$granted_before[ $role_name ] = (array) get_role( $role_name )->capabilities;
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
deactivate_plugins( 'my-plugin/arbeitsdienste.php', true );
$activation = activate_plugin( 'my-plugin/arbeitsdienste.php' );
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

// The three addresses the other sections use. They are members now, so they
// have a number and a name, and being a member is what lets them sign in for a
// work service and offer a ride for it.
$teilnehmer = fg_make_member( '100', 'teilnehmer@example.org', 'Teilnehmer', 'Probe' );
$anbieter   = fg_make_member( '200', 'anbieter@example.org', 'Anbieter', 'Probe' );
$sucher     = fg_make_member( '300', 'sucher@example.org', 'Sucher', 'Probe' );
fg_ok( 'teilnehmer@example.org' === $teilnehmer->email, 'the address of a member is stored lower-cased', $teilnehmer->email );

$event_id    = fg_make_event( 'Testarbeit', $soon );
$event_record = $fg_repo->get_event( $event_id );
fg_ok( 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $event_record->event_uuid ), 'UUID generated on insert', $event_record->event_uuid );
$event_ref = $event_record->public_ref;
fg_ok( is_string( $event_ref ) && '' !== $event_ref, 'public reference generated', var_export( $event_ref, true ) );
fg_ok( $fg_repo->is_event_active( $event_id ), 'future event is active' );
fg_ok( 0 === $fg_repo->count_event_registrations( $event_id ), 'a new duty has nobody signed in for it' );

// Who may offer a ride for this duty is decided by the registration and by
// nothing else, so the three fixture members are signed in for it here.
fg_register_fixture_trio( $event_id );
fg_ok( 3 === $fg_repo->count_event_registrations( $event_id ), 'three members signed in', (string) $fg_repo->count_event_registrations( $event_id ) );

$registrierte = array();
foreach ( $fg_repo->get_event_registrations( $event_id ) as $row ) {
	$registrierte[] = $row['member']->email;
}
sort( $registrierte );
fg_ok(
	array( 'anbieter@example.org', 'sucher@example.org', 'teilnehmer@example.org' ) === $registrierte,
	'the registrations of a duty are read back with the member behind them',
	wp_json_encode( $registrierte )
);

$today_event = fg_make_event( 'Heutiger Dienst', $today );
fg_ok( $fg_repo->is_event_active( $today_event ), 'date-only event stays active for the whole day' );
$past_event = fg_make_event( 'Vergangener Dienst', $past );
fg_ok( ! $fg_repo->is_event_active( $past_event ), 'past event is not active' );
$inactive_event = fg_make_event( 'Nicht sichtbar', $soon, '', false );
fg_ok( ! $fg_repo->is_event_active( $inactive_event ), 'event without visibility is not active' );
$timed_event = fg_make_event( 'Geplant', $soon, '08:00' );
fg_ok( $fg_repo->is_event_active( $timed_event ), 'future event with a cut-off time counts as active' );
$overdue_event = fg_make_event( 'Heute vorbei', $today, '00:01' );
fg_ok( ! $fg_repo->is_event_active( $overdue_event ), 'a cut-off time in the past ends the visibility' );

$GLOBALS['fg_state']['event_id']      = $event_id;
$GLOBALS['fg_state']['event_ref']     = $event_ref;
$GLOBALS['fg_state']['today_event']   = $today_event;
$GLOBALS['fg_state']['past_event']    = $past_event;
$GLOBALS['fg_state']['draft_event']   = $inactive_event;

/* ----------------------------------------------------------------- 2a */
/* The four optional details of a work duty.
 *
 * Two things are proved here that a rendered page could never show: that the
 * four columns really came into a table that already existed, and that a value
 * the server does not accept is refused instead of stored in a corrected form.
 * A page can only show what was stored; it cannot show what was thrown away.
 */
echo "[2a] The four optional details of a work duty\n";

global $wpdb;
$event_columns_seen = array();
foreach ( (array) $wpdb->get_results( 'SHOW COLUMNS FROM ' . FG_Schema::events_table(), ARRAY_A ) as $column ) {
	$event_columns_seen[] = $column['Field'];
}
fg_ok(
	array( 'group_name', 'demand', 'duration_hours', 'description' ) === array_slice( $event_columns_seen, -4 ),
	'the four columns stand at the end of a table that already existed',
	implode( ',', $event_columns_seen )
);

$detail_event = fg_make_event(
	'Dienst mit Angaben',
	$soon,
	'08:00',
	true,
	array(
		'group_name'     => 'Gartenpflege Nord',
		'demand'         => 8,
		'duration_hours' => 4,
		'description'    => "Bitte festes Schuhwerk mitbringen.\nHandschuhe sind vorhanden.",
	)
);
$details      = $fg_repo->get_event( $detail_event );
fg_ok( 'Gartenpflege Nord' === $details->group_name, 'group stored and read back', $details->group_name );
fg_ok( 8 === $details->demand, 'head count stored as a number', var_export( $details->demand, true ) );
fg_ok( 4 === $details->duration_hours, 'duration stored as a number', var_export( $details->duration_hours, true ) );
fg_ok( false !== strpos( $details->description, "\n" ), 'line break kept inside the description' );

// A duty without the four fields keeps the column defaults, which is what makes
// the rows on the public list disappear rather than show a zero.
$plain_event = fg_make_event( 'Dienst ohne Angaben', $soon );
$plain       = $fg_repo->get_event( $plain_event );
fg_ok(
	'' === $plain->group_name && '' === $plain->description && 0 === $plain->demand && 0 === $plain->duration_hours,
	'a duty without the fields has them empty, not zero-filled text',
	wp_json_encode( array( $plain->group_name, $plain->description, $plain->demand, $plain->duration_hours ) )
);

fg_ok( $fg_repo->update_event( $detail_event, array( 'demand' => 3 ) ), 'an update of one field is accepted' );
$updated = $fg_repo->get_event( $detail_event );
fg_ok( 3 === $updated->demand, 'the head count changed', var_export( $updated->demand, true ) );
fg_ok(
	'Gartenpflege Nord' === $updated->group_name && 4 === $updated->duration_hours,
	'the fields that were not submitted keep their value',
	wp_json_encode( array( $updated->group_name, $updated->duration_hours ) )
);

$events_before = $fg_repo->count_events();

// The limits are counted in characters, not in bytes. 100 umlauts are 200 bytes
// and still exactly the 100 characters the column holds.
//
// The description is the one that carries the weight: it lives in a text column
// that would take far more than 500 characters without a word, so only the
// check of the plugin holds it to the limit. The group is refused by the column
// as well, so that check pins the behaviour down and not the place it comes
// from.
fg_ok(
	fg_event_accepted( array( 'title' => 'Grenzfall', 'event_date' => $soon, 'is_active' => true, 'group_name' => str_repeat( 'ä', FG_Schema::GROUP_MAX ) ) ),
	'a group of exactly the allowed number of characters is stored'
);
fg_ok(
	! fg_event_accepted( array( 'title' => 'Grenzfall', 'event_date' => $soon, 'is_active' => true, 'group_name' => str_repeat( 'ä', FG_Schema::GROUP_MAX + 1 ) ) ),
	'a group of one character too much is refused'
);
fg_ok(
	fg_event_accepted( array( 'title' => 'Grenzfall', 'event_date' => $soon, 'is_active' => true, 'description' => str_repeat( 'ö', FG_Schema::DESCRIPTION_MAX ) ) ),
	'a description of exactly the allowed number of characters is stored'
);
fg_ok(
	! fg_event_accepted( array( 'title' => 'Grenzfall', 'event_date' => $soon, 'is_active' => true, 'description' => str_repeat( 'ö', FG_Schema::DESCRIPTION_MAX + 1 ) ) ),
	'a description of one character too much is refused'
);

// A description that the column would take without complaint, refused all the
// same. Without this the check above would also pass on a column that happens
// to be narrow enough, and the limit would live in the wrong place.
global $wpdb;
$wpdb->suppress_errors( true );
$wpdb->last_error     = '';
$langer_text         = str_repeat( 'ö', FG_Schema::DESCRIPTION_MAX + 1 );
$wpdb->insert(
	FG_Schema::events_table(),
	array(
		'title'          => 'Spaltenprobe',
		'event_date'     => $soon,
		'event_time'     => null,
		'participants'   => '',
		'event_uuid'     => '00000000-0000-4000-8000-0000000000ff',
		'public_ref'     => 'spaltenprobe' . substr( md5( (string) microtime( true ) ), 0, 19 ),
		'is_active'      => 1,
		'created_at'     => current_time( 'mysql' ),
		'group_name'     => '',
		'demand'         => 0,
		'duration_hours' => 0,
		'description'    => $langer_text,
	),
	array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s' )
);
$spaltenprobe = (int) $wpdb->insert_id;
$wpdb->suppress_errors( false );
fg_ok(
	$spaltenprobe > 0 && FG_Schema::DESCRIPTION_MAX + 1 === mb_strlen( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT description FROM ' . FG_Schema::events_table() . ' WHERE id = %d', $spaltenprobe ) ), 'UTF-8' ),
	'the column would have taken the long description, so the refusal is the plugin\'s',
	$spaltenprobe > 0 ? 'column accepted it' : 'column refused it: ' . $wpdb->last_error
);
if ( $spaltenprobe > 0 ) {
	$fg_repo->delete_event( $spaltenprobe );
}

// A number that is not a plain count is refused. `absint()` would have stored 3
// for "-3" and 0 for a digit string that overflows the column, and both would
// have stood on the public page as a claim the club never made.
$bad_numbers = array( '-3', '3.5', 'eight', '1e3', '+4', '0x10', str_repeat( '9', 30 ), FG_Schema::COUNT_MAX + 1 );
$accepted_bad = array();
foreach ( $bad_numbers as $bad ) {
	if ( fg_event_accepted( array( 'title' => 'Zahlprobe', 'event_date' => $soon, 'is_active' => true, 'demand' => $bad ) ) ) {
		$accepted_bad[] = $bad;
	}
}
fg_ok( empty( $accepted_bad ), 'a head count that is not a plain count is never stored', implode( ' | ', $accepted_bad ) );
fg_ok(
	$events_before === $fg_repo->count_events(),
	'nothing was left behind by the refused values',
	$events_before . ' -> ' . $fg_repo->count_events()
);

// An empty field and a field holding nothing but spaces both mean "not stated".
// They are not errors, because a browser sends an empty string for a field the
// visitor never touched.
foreach ( array( '', '   ' ) as $leer ) {
	$probe = fg_event_accepted(
		array( 'title' => 'Zahlprobe', 'event_date' => $soon, 'is_active' => true, 'demand' => $leer, 'duration_hours' => $leer )
	);
	fg_ok( $probe, 'an empty head count is stored as no statement', var_export( $leer, true ) );
}

// A refused update changes nothing. The record that carried a good value keeps
// it, instead of losing it to a mistyped one.
$before_refused = $fg_repo->get_event( $detail_event );
fg_ok( ! $fg_repo->update_event( $detail_event, array( 'group_name' => str_repeat( 'a', FG_Schema::GROUP_MAX + 1 ) ) ), 'an over-long group is refused on update' );
fg_ok( ! $fg_repo->update_event( $detail_event, array( 'demand' => 'viele' ) ), 'a head count that is not a number is refused on update' );
$after_refused = $fg_repo->get_event( $detail_event );
fg_ok(
	$before_refused->group_name === $after_refused->group_name && $before_refused->demand === $after_refused->demand,
	'the refused update left the stored values alone',
	wp_json_encode( array( $after_refused->group_name, $after_refused->demand ) )
);

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
 * Renders a shortcode while one table of the plugin looks empty.
 *
 * @param string        $table  Table name without prefix.
 * @param callable|null $render Renderer to call; the ride page by default.
 * @return string Rendered page.
 */
function fg_render_with_empty( $table, $render = null ) {
	$filter = fg_leeren_filter( $table );
	add_filter( 'query', $filter );
	$html = $render
		? call_user_func( $render )
		: ( new FG_Public( new FG_Repository() ) )->render_shortcode();
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

/* ----------------------------------------------------------------- 3c */
/* The list of work duties behind [arbeitsdienste].
 *
 * Read-only in the same sense as [3b]: the empty state is reached with the
 * `query` filter rather than by taking the work duties offline. The duties that
 * the ordering and the wording are shown with are the run's own, and are
 * removed again before the counts are taken, so the counts describe the duties
 * of the installation and nothing else.
 */
echo "[3c] The list of work duties\n";

fg_ok( shortcode_exists( 'arbeitsdienste' ), 'the shortcode is registered' );

// A duty of this section carries every value the list is asked to show, so the
// wording below does not depend on what an earlier section left in the database.
$voll_event = fg_make_event(
	'Dienst mit Angaben',
	$soon,
	'08:00',
	true,
	array(
		'group_name'     => 'Gartenpflege Nord',
		'demand'         => 8,
		'duration_hours' => 4,
		'description'    => "Bitte festes Schuhwerk mitbringen.\nHandschuhe sind vorhanden.",
	)
);
$voll = $fg_repo->get_event( $voll_event );

$dienste      = new FG_Public_Events( new FG_Repository() );
$dienste_html = $dienste->render_shortcode();

fg_contains( 'id="fg-dienste"', $dienste_html, 'the list has a heading with an anchor' );
fg_contains( 'Kommende Arbeitsdienste', $dienste_html, 'the list says what it lists' );
fg_ok(
	substr_count( $dienste_html, '<table class="fg-event-data">' ) === substr_count( $dienste_html, '<article class="fg-event-card"' )
		&& substr_count( $dienste_html, '<table class="fg-event-data">' ) > 0,
	'every card holds exactly one table of details',
	substr_count( $dienste_html, '<article class="fg-event-card"' ) . ' cards, ' . substr_count( $dienste_html, '<table class="fg-event-data">' ) . ' tables'
);
fg_contains( '<th scope="row">Datum</th>', $dienste_html, 'the fields stand as row headers' );

// The date is built from the translation files of the site, not from the date
// format of PHP, so the weekday reads the same on every server.
global $wp_locale;
$datum     = DateTimeImmutable::createFromFormat( '!Y-m-d', $voll->event_date, wp_timezone() );
$wochentag  = $wp_locale->get_weekday( (int) $datum->format( 'w' ) );
$erwartet   = $wochentag . ', den ' . wp_date( get_option( 'date_format' ), $datum->getTimestamp(), wp_timezone() );
fg_contains( 'Datum ' . $erwartet, fg_text_of( $dienste_html ), 'the date reads weekday, den, then the date format of the site' );

// The weekday must come from the translation files and not from the locale of
// the server. The container happens to run a German locale, so the difference
// only shows when the process is switched to the C locale a plain server has.
$locale_vorher = setlocale( LC_TIME, '0' );
setlocale( LC_TIME, 'C' );
$c_text = fg_text_of( $dienste->render_shortcode() );
setlocale( LC_TIME, $locale_vorher );
fg_contains( $wochentag, $c_text, 'the weekday stays German on a server with the C locale' );
fg_not_contains( 'Monday', $c_text, 'no weekday of the server locale leaks in' );

// Without the stylesheet the cards are unframed blocks of text.
fg_ok( wp_style_is( 'arbeitsdienste-public', 'enqueued' ), 'the list brings the stylesheet with it' );

// The wording of a number is checked with a letter behind it that must not
// follow. Without it the check for the singular would also pass on "Personen".
$dienste_text = fg_text_of( $dienste_html );
fg_ok( 1 === preg_match( '/Bedarf 8 Personen(?![a-zäöüß])/u', $dienste_text ), 'the head count reads as eight persons' );
fg_ok( 1 === preg_match( '/Dauer 4 Stunden(?![a-zäöüß])/u', $dienste_text ), 'the duration reads as four hours' );
fg_contains( 'Gruppe Gartenpflege Nord', $dienste_text, 'the group stands in its own row' );

$einzel_event = fg_make_event( 'Einzelwerte', $soon, '', true, array( 'demand' => 1, 'duration_hours' => 1 ) );
$einzel_text  = fg_text_of( $dienste->render_shortcode() );
fg_ok( 1 === preg_match( '/Bedarf 1 Person(?![a-zäöüß])/u', $einzel_text ), 'one person is one person, not persons' );
fg_ok( 1 === preg_match( '/Dauer 1 Stunde(?![a-zäöüß])/u', $einzel_text ), 'one hour is one hour, not hours' );

// A field the club left empty has no row at all. "0 Personen" would be read as a
// claim about the duty, so the row is left out rather than filled with a zero.
$leer_karte = fg_card_of( $dienste->render_shortcode(), 'Dienst ohne Angaben' );
fg_ok( '' !== $leer_karte, 'the duty without details has a card of its own' );
fg_not_contains( '<th scope="row">Bedarf</th>', $leer_karte, 'no head count row without a number' );
fg_not_contains( 'Dauer', $leer_karte, 'no duration row without a number' );
fg_not_contains( 'Gruppe', $leer_karte, 'no group row without a text' );
fg_not_contains( 'Beschreibung', $leer_karte, 'no description row without a text' );
fg_not_contains( 'Beginn', $leer_karte, 'no start row without a time' );
fg_contains( 'Datum', $leer_karte, 'the date is there even when nothing else is' );

$null_event = fg_make_event(
	'Ausdruecklich ohne Zahl',
	$soon,
	'',
	true,
	array( 'group_name' => '', 'description' => '', 'demand' => 0, 'duration_hours' => 0 )
);
$null_karte = fg_card_of( $dienste->render_shortcode(), 'Ausdruecklich ohne Zahl' );
fg_ok( '' !== $null_karte, 'the duty with zeroes has a card of its own' );
fg_not_contains( '<th scope="row">Bedarf</th>', $null_karte, 'a head count of zero states nothing' );
fg_not_contains( 'Dauer', $null_karte, 'a duration of zero states nothing' );
fg_not_contains( '0 ', $null_karte, 'no zero is shown in the card' );

$beginn_karte = fg_card_of( $dienste->render_shortcode(), 'Dienst mit Angaben' );
fg_contains( 'Beginn', $beginn_karte, 'a duty with a time has a start row' );
fg_contains( '<br />', $beginn_karte, 'the line break in the description stays a line break' );

// Markup typed into the description reaches the page as the text that was typed.
// The store is not the place that strips it, the renderer is.
$markup_event = fg_make_event( 'Mit Markup', $soon, '', true, array( 'description' => '<b>fett</b> & mehr' ) );
$markup_html  = $dienste->render_shortcode();
$markup_karte = fg_card_of( $markup_html, 'Mit Markup' );
fg_ok( '' !== $markup_karte, 'the duty with markup has a card of its own' );
fg_not_contains( '<b>', $markup_karte, 'no markup from the description reaches the page' );
fg_contains( '&lt;b&gt;fett&lt;/b&gt;', $markup_karte, 'the markup is shown as the text that was typed' );

// Within one day the start time decides, and a duty without a time has no place
// in the order of the day, so it stands last.
$order_tag    = 'Sortierprobe';
$sort_tag     = current_datetime()->modify( '+20 days' )->format( 'Y-m-d' );
$spaeter_tag  = current_datetime()->modify( '+21 days' )->format( 'Y-m-d' );
$sort_ids     = array(
	fg_make_event( $order_tag . ' spaet', $sort_tag, '16:00' ),
	fg_make_event( $order_tag . ' frueh', $sort_tag, '07:00' ),
	fg_make_event( $order_tag . ' ganztags', $sort_tag ),
	fg_make_event( $order_tag . ' naechster tag', $spaeter_tag, '07:00' ),
);
$sort_html      = $dienste->render_shortcode();
$sort_positionen = array();
$sort_fehlt     = false;
foreach ( $sort_ids as $sort_id ) {
	$sort_titel  = $fg_repo->get_event( $sort_id )->title;
	$sort_pos    = strpos( $sort_html, '<h3 class="fg-event-title">' . esc_html( $sort_titel ) . '</h3>' );
	$sort_fehlt  = $sort_fehlt || false === $sort_pos;
	$sort_positionen[ false === $sort_pos ? PHP_INT_MAX : $sort_pos ] = $sort_titel;
}
ksort( $sort_positionen );
fg_ok( ! $sort_fehlt, 'every duty of the ordering probe is on the list' );
$sort_titel = array_map(
	function ( $titel ) use ( $order_tag ) {
		return substr( $titel, strlen( $order_tag ) + 1 );
	},
	array_values( $sort_positionen )
);
fg_ok(
	array( 'frueh', 'spaet', 'ganztags', 'naechster tag' ) === $sort_titel,
	'the duties come by day, and within the day by start time with the day-long one last',
	implode( ',', $sort_titel )
);
foreach ( $sort_ids as $sort_id ) {
	$fg_repo->delete_event( $sort_id );
}

// Past and unpublished duties are not part of what is coming up. The two duties
// from [2] serve, so no duty has to be taken offline for this.
$dienste_html = $dienste->render_shortcode();
fg_not_contains( 'Vergangener Dienst', $dienste_html, 'a past duty is not listed' );
fg_not_contains( 'Nicht sichtbar', $dienste_html, 'a duty that is not public is not listed' );
fg_not_contains( '<script', $dienste_html, 'the list needs no script' );

$erwartete_karten = count( $fg_repo->get_active_events() );
fg_ok(
	$erwartete_karten === substr_count( $dienste_html, '<article class="fg-event-card"' ),
	'one card for every upcoming public duty',
	$erwartete_karten . ' expected, ' . substr_count( $dienste_html, '<article class="fg-event-card"' ) . ' shown'
);

$ohne_dienste_liste = fg_render_with_empty( FG_Schema::events_table(), array( $dienste, 'render_shortcode' ) );
fg_ok( 1 === substr_count( $ohne_dienste_liste, 'class="fg-empty"' ), 'exactly one message when no duty is coming up', substr_count( $ohne_dienste_liste, 'class="fg-empty"' ) . ' messages' );
fg_not_contains( '<article', $ohne_dienste_liste, 'no card when no duty is coming up' );
fg_contains( 'Kommende Arbeitsdienste', $ohne_dienste_liste, 'the heading stays, so the page is not blank' );

$wieder_dienste = $dienste->render_shortcode();
fg_contains( 'Dienst mit Angaben', $wieder_dienste, 'the duties are back once the filter is gone' );
fg_ok(
	substr_count( $dienste_html, '<article class="fg-event-card"' ) === substr_count( $wieder_dienste, '<article class="fg-event-card"' ),
	'every card is still there after the empty render',
	substr_count( $dienste_html, '<article class="fg-event-card"' ) . ' -> ' . substr_count( $wieder_dienste, '<article class="fg-event-card"' )
);

/* ----------------------------------------------------------------- 3d */
/* The registration form under a work duty.
 *
 * The button is the whole of what a member sees first, and the form behind it
 * asks for two values. What the page must not do is ask for a name: it is in the
 * member administration, and a name typed a second time is a name that can be
 * typed differently.
 */
echo "[3d] The registration form under a work duty\n";

$offen_event = fg_make_event( 'Anmeldung offen', $soon, '08:00', true, array( 'demand' => 4 ) );
fg_register( $offen_event, fg_member_id( 'sucher' ) );
$offen_ref     = $fg_repo->get_event( $offen_event )->public_ref;
$offen_karte   = fg_card_of( $dienste->render_shortcode(), 'Anmeldung offen' );
$offen_ohne    = fg_card_of( $dienste->render_shortcode(), 'Ausdruecklich ohne Zahl' );

fg_contains( '<details class="fg-signup">', $offen_karte, 'a duty with free places carries a form behind a details element' );
fg_contains( '>Eintragen<', $offen_karte, 'the control that opens the form says Eintragen' );
fg_contains( 'verbindlich anmelden', $offen_karte, 'the button that sends the form says what it does' );
fg_contains( 'name="action" value="fg_register_member"', $offen_karte, 'the form posts to the registration endpoint' );
fg_contains( 'name="fg_event_ref" value="' . esc_attr( $offen_ref ) . '"', $offen_karte, 'the duty travels by its public reference' );
fg_contains( 'name="fg_register_nonce"', $offen_karte, 'the form carries a nonce' );
fg_contains( 'name="fg_website"', $offen_karte, 'the form carries a honeypot' );
fg_contains( 'name="form_started_at"', $offen_karte, 'the form carries its own start time' );
fg_contains( 'Mitgliedsnummer', $offen_karte, 'the first field is the member number' );
fg_contains( 'name="fg_member_no"', $offen_karte, 'the member number has a name of its own' );
fg_contains( 'name="fg_member_email"', $offen_karte, 'the address has a name of its own' );
fg_not_contains( 'name="fg_first_name"', $offen_karte, 'the first name must not be asked for' );
fg_not_contains( 'name="fg_last_name"', $offen_karte, 'the last name must not be asked for' );
fg_ok( '' !== get_privacy_policy_url() ? false !== strpos( $offen_karte, esc_url( get_privacy_policy_url() ) ) : true, 'the form links to the privacy policy if the site has one' );

// The free places are the one row that is always there, because a page that
// could not say a duty is full would also not be able to say it is not.
fg_contains( '<th scope="row">Verfügbare freie Plätze</th>', $offen_karte, 'every duty states its free places' );
fg_contains( '<th scope="row">Verfügbare freie Plätze</th>', $offen_ohne, 'a duty without a demand states them too' );
fg_contains( 'Verfügbare freie Plätze 3', fg_text_of( $offen_karte ), 'four places minus one registration is three' );
fg_contains( 'Verfügbare freie Plätze 0', fg_text_of( $offen_ohne ), 'a duty without a demand has none free' );

// A full duty and a duty without a demand are two different facts and get two
// different sentences. "Ausgebucht" would be a claim about the second that is
// not true.
$voll2_event = fg_make_event( 'Anmeldung voll', $soon, '', true, array( 'demand' => 1 ) );
fg_register( $voll2_event, fg_member_id( 'anbieter' ) );
$voll2_karte = fg_card_of( $dienste->render_shortcode(), 'Anmeldung voll' );
fg_contains( 'Dieser Arbeitsdienst ist vollständig belegt.', fg_text_of( $voll2_karte ), 'a full duty says it is full' );
fg_not_contains( '<details class="fg-signup">', $voll2_karte, 'a full duty has no form at all' );
fg_contains( 'kein Bedarf eingetragen', fg_text_of( $offen_ohne ), 'a duty without a demand says so in its own words' );
fg_not_contains( 'vollständig belegt', fg_text_of( $offen_ohne ), 'a duty without a demand is not called full' );
fg_not_contains( '<details class="fg-signup">', $offen_ohne, 'a duty without a demand has no form at all' );

// A member who is already signed in sees the same form as everybody else. There
// is no state in which the page tells one visitor that they are already in,
// because the page cannot know who is reading it. Only the free places change.
$bereits = fg_make_event( 'Anmeldung bereits drin', $soon, '', true, array( 'demand' => 3 ) );
fg_register( $bereits, fg_member_id( 'teilnehmer' ) );
$bereits_karte = fg_card_of( $dienste->render_shortcode(), 'Anmeldung bereits drin' );
fg_ok(
	1 === substr_count( $bereits_karte, '<details class="fg-signup">' ) && 1 === substr_count( $bereits_karte, 'name="fg_member_no"' ),
	'a duty somebody is already signed in for still offers exactly one form',
	substr_count( $bereits_karte, '<details class="fg-signup">' ) . ' forms'
);
fg_contains( 'Verfügbare freie Plätze 2', fg_text_of( $bereits_karte ), 'and its free places are one lower' );
fg_not_contains( 'Teilnehmer', $bereits_karte, 'and it does not say who is in' );

// An address that belongs to a member must not appear anywhere in the markup.
$mitglieder_namen = array( 'Teilnehmer', 'Anbieter', 'Sucher', 'teilnehmer@example.org', 'sucher@example.org' );
$gefunden         = array();
foreach ( $mitglieder_namen as $name ) {
	if ( false !== strpos( $dienste_html, $name ) ) {
		$gefunden[] = $name;
	}
}
fg_ok( empty( $gefunden ), 'no name and no address of a member reaches the public page', implode( ', ', $gefunden ) );

// The notice of a refused registration is printed once, at the top, and the
// page it lands on is the page the form was sent from.
$_GET = array( 'fg_notice' => 'duty_full' );
fg_contains( 'keine freien Plätze', $dienste->render_shortcode(), 'a refusal is explained on the list' );
$_GET = array( 'fg_notice' => 'registered' );
fg_contains( 'Abmeldelink', $dienste->render_shortcode(), 'a success names the e-mail that is on its way' );
$_GET = array( 'fg_notice' => 'gibtesnicht' );
fg_not_contains( 'fg-notice', $dienste->render_shortcode(), 'an unknown notice prints nothing' );
$_GET = array();

$fg_repo->delete_event( $voll2_event );
$fg_repo->delete_event( $bereits );
$fg_repo->delete_event( $offen_event );

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
$admin_event = fg_make_event( 'Admin-Dienst', $soon );
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
			'title'      => 'Kaputter Dienst',
			'event_date' => '2026-13-45',
			'is_active'  => true,
		)
	),
	'an event with an invalid date is never stored'
);
fg_ok(
	0 === $fg_repo->insert_event(
		array(
			'title'      => 'Kaputte Zeit',
			'event_date' => $soon,
			'event_time' => '99:99',
			'is_active'  => true,
		)
	),
	'an event with an invalid time is never stored'
);

$bad_event = fg_make_event( 'Kaputter Dienst', $soon );
fg_ok( 0 === $fg_repo->count_event_registrations( $bad_event ), 'a duty holds no list of addresses of its own any more', (string) $fg_repo->count_event_registrations( $bad_event ) );
fg_ok( ! $fg_repo->update_event( $bad_event, array( 'event_date' => '2026-13-45' ) ), 'an invalid date is not written over a valid one' );
fg_ok( $soon === $fg_repo->get_event( $bad_event )->event_date, 'valid date of a previous save is kept', (string) $fg_repo->get_event( $bad_event )->event_date );
fg_ok( ! $fg_repo->update_event( $bad_event, array( 'is_active' => true, 'event_date' => '' ) ), 'an event cannot be published without a date' );
fg_ok( $fg_repo->update_event( $bad_event, array( 'is_active' => false ) ), 'an event can be withdrawn' );
fg_ok( ! $fg_repo->is_event_active( $bad_event ), 'withdrawn event is no longer offered' );

$cascade_event = fg_make_event( 'Kaskade', $soon );
fg_register( $cascade_event, fg_member_id( 'sucher' ) );
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
$cascade_signups = $fg_repo->get_event_registrations( $cascade_event );
fg_ok( 1 === count( $cascade_rides ), 'cascade source found' );
$cascade_result = $fg_repo->delete_event( $cascade_event );
fg_ok( $cascade_result['deleted'] && 1 === $cascade_result['rides'], 'cascade reports what it removed', wp_json_encode( $cascade_result ) );
fg_ok( 1 === (int) $cascade_result['registrations'], 'cascade reports the registrations it removed too', wp_json_encode( $cascade_result ) );
$remaining = 0;
foreach ( $cascade_rides as $cascade_ride ) {
	if ( $fg_repo->get_ride( $cascade_ride ) ) {
		++$remaining;
	}
}
fg_ok( 0 === $remaining, 'deleting an event deletes its rides' );
$left_signups = 0;
foreach ( $cascade_signups as $row ) {
	if ( $fg_repo->get_registration( $row['registration']->id ) ) {
		++$left_signups;
	}
}
fg_ok( 0 === $left_signups, 'deleting an event deletes its registrations' );
fg_ok( null === $fg_repo->get_event( $cascade_event ), 'work duty removed' );
fg_ok( null !== $fg_repo->get_member_by_number( '300' ), 'the member itself survives the loss of the duty' );

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
$bulk_event = fg_make_event( 'Datenschutz', $soon, '', true, array( 'demand' => 10 ) );

// The data subject has to be a member, because a registration belongs to a
// member. An address that is not in the member list has no registrations and
// nothing for the exporter to find beyond the rides.
$opfer = fg_make_member( '900', 'opfer@example.org', 'Opfer', 'Probe' );
$ander = fg_make_member( '901', 'ander@example.org', 'Ander', 'Probe' );
fg_register( $bulk_event, $opfer->id );
fg_register( $bulk_event, $ander->id );

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
fg_ok( 11 === count( $export['data'] ), 'export page is limited to ten rides plus the registration', (string) count( $export['data'] ) );
$export_all = 0;
$page       = 1;
do {
	$result    = $privacy->export( 'opfer@example.org', $page );
	$export_all += count( $result['data'] );
	++$page;
} while ( ! $result['done'] && $page < 10 );
fg_ok( 26 === $export_all, 'all records exported', (string) $export_all );

$foreign_export = $privacy->export( 'gibtesnicht@example.org', 1 );
fg_ok( empty( $foreign_export['data'] ), 'an address without a member has nothing to export', wp_json_encode( $foreign_export['data'] ) );

// Mirror the WordPress core loop over the eraser.
$page = 1;
do {
	$result = $privacy->erase( 'opfer@example.org', $page );
	++$page;
} while ( ! $result['done'] && $page < 20 );

$left = $fg_repo->get_event_ride_ids( $bulk_event );
fg_ok( 0 === count( $left ), 'all rides of the data subject removed', (string) count( $left ) );
fg_ok( ! $fg_repo->has_registration( $bulk_event, $opfer->id ), 'the registration of the data subject is gone' );
fg_ok( $fg_repo->has_registration( $bulk_event, $ander->id ), 'other registrations untouched' );
fg_ok( null !== $fg_repo->get_member_by_number( '900' ), 'the member record of the club itself is left alone' );
fg_ok( ! $fg_repo->is_event_participant( $bulk_event, 'opfer@example.org' ), 'an erased member can no longer offer a ride' );

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
$http_event = fg_make_event( 'Testdienst ohne TLS', gmdate( 'Y-m-d', time() + 6 * DAY_IN_SECONDS ), '', true, array( 'demand' => 3 ) );
fg_register( $http_event, fg_member_id( 'teilnehmer' ) );
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

/*
 * The one file that is not scanned for unprepared SQL is the schema. dbDelta
 * takes a CREATE TABLE statement and hands it to the database as it is, so
 * there is nothing there to prepare; and the single statement that empties the
 * retired participant column has no value from outside in it, only the table
 * name the class built itself. That is the same pattern the store uses for its
 * table names, and the file is four CREATE statements long enough to be read.
 * The name is repeated in the check below, so a reader sees that the file was
 * left out and why.
 */
$sql_ausgenommen = array( 'class-fg-schema.php' );

$leaks     = array();
$gesehen   = array();
$scanned  = 0;
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_dir ) ) as $file ) {
	if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
		++$scanned;
		$source = (string) file_get_contents( $file->getPathname() );
		if ( preg_match( '/\b(wp_remote_get|wp_remote_post|curl_exec|file_get_contents\(\s*[\'"]https?:)/i', $source ) ) {
			$leaks[] = 'remote request in ' . $file->getFilename();
		}
		if ( in_array( $file->getFilename(), $sql_ausgenommen, true ) ) {
			continue;
		}
		if ( preg_match( '/\$wpdb\s*->\s*(query|get_results|get_row|get_var|get_col)\s*\(\s*["\']/', $source ) ) {
			$leaks[] = 'unprepared SQL in ' . $file->getFilename();
		}
	}
}
/*
 * The floor is the number of PHP files the delivery has as of schema 1.2.0. It
 * is a floor and not an equality, so a file added later does not fail here; its
 * job is to catch a scan that found no or too little because the directory was
 * not what it should be. It is therefore a number that has to be raised when a
 * file is added, and not a number that breaks when one is.
 */
fg_ok( $scanned >= 20, 'every delivered PHP file is scanned', (string) $scanned );
fg_ok( empty( $leaks ), 'no remote requests and no unprepared SQL outside ' . implode( ' and ', $sql_ausgenommen ), implode( ', ', $leaks ) );
$uninstall = (string) file_get_contents( $plugin_dir . '/uninstall.php' );
fg_ok( false !== strpos( $uninstall, 'DROP TABLE' ), 'uninstall removes the tables' );

/* ------------------------------------------------------------------ 17 */
/* The member administration behind the registration.
 *
 * A member is four fields and the number is the key. Everything the public form
 * does is decided by what this table holds, so the refusals are proved here and
 * not on the page: a page can only show what was stored.
 */
echo "[17] The member administration\n";

$neu = $fg_repo->insert_member(
	array(
		'member_no'  => '  0042  ',
		'email'      => 'Neu.Probe@Example.ORG',
		'first_name' => 'Neu',
		'last_name'  => 'Probe',
	)
);
fg_ok( (bool) $neu, 'a member is created', var_export( $neu, true ) );
$neu_row = $fg_repo->get_member( $neu );
fg_ok( '0042' === $neu_row->member_no, 'spaces around the number are not part of it', var_export( $neu_row->member_no, true ) );
fg_ok( 'neu.probe@example.org' === $neu_row->email, 'the address is stored lower-cased', $neu_row->email );
fg_ok( null !== $fg_repo->get_member_by_number( '0042' ), 'the member is found by the number it is given with' );
fg_ok( null !== $fg_repo->get_member_by_number( ' 0042 ' ), 'the number is found whatever spaces are typed around it' );
fg_ok( null !== $fg_repo->get_member_by_email( 'Neu.Probe@Example.ORG' ), 'the address is found whatever case it is typed with' );

// A leading zero is a part of the number and not a digit that was lost. This is
// the reason the column is text and not a number.
$nullprobe = fg_make_member( '007', 'nullprobe@example.org', 'Null', 'Probe' );
fg_ok( '007' === $nullprobe->member_no, 'a leading zero survives the round trip', var_export( $nullprobe->member_no, true ) );
fg_ok( null === $fg_repo->get_member_by_number( '7' ), 'and the number without it is a different member' );

// The two keys are unique. Both refusals are the database speaking, not PHP
// counting rows, so they hold under two requests at the same moment too.
fg_ok( ! $fg_repo->insert_member( array( 'member_no' => '0042', 'email' => 'anders@example.org', 'first_name' => 'A', 'last_name' => 'B' ) ), 'a number belongs to one member only' );
fg_ok( ! $fg_repo->insert_member( array( 'member_no' => '0055', 'email' => 'neu.probe@example.org', 'first_name' => 'A', 'last_name' => 'B' ) ), 'an address belongs to one member only' );

// All four fields are required on an update too. A partial set would have to be
// completed from the stored row, and then a mistyped field name in the form
// would keep the old value without anybody noticing.
fg_ok( ! $fg_repo->update_member( $neu, array( 'email' => 'anders@example.org' ) ), 'an update with only one of the four fields is refused' );
fg_ok( 'neu.probe@example.org' === $fg_repo->get_member( $neu )->email, 'and it left the stored address alone', $fg_repo->get_member( $neu )->email );

$neu_felder = array(
	'member_no'  => '0042',
	'email'      => 'anders@example.org',
	'first_name' => 'Neu',
	'last_name'  => 'Probe',
);
fg_ok( $fg_repo->update_member( $neu, $neu_felder ), 'a member may hand its address on' );
fg_ok( null !== $fg_repo->get_member_by_email( 'anders@example.org' ), 'and the new address finds the same member' );
fg_ok( $fg_repo->update_member( $neu, array_merge( $neu_felder, array( 'email' => 'neu.probe@example.org' ) ) ), 'and take it back' );

// The member number may be changed too, but not onto one that is spoken for.
fg_ok( ! $fg_repo->update_member( $neu, array_merge( $neu_felder, array( 'member_no' => '100' ) ) ), 'a member number cannot be moved onto one that is taken' );
fg_ok( '0042' === $fg_repo->get_member( $neu )->member_no, 'and the stored number is unchanged', $fg_repo->get_member( $neu )->member_no );
fg_ok( $fg_repo->update_member( $neu, array_merge( $neu_felder, array( 'member_no' => '0043' ) ) ), 'a member number may be changed' );
fg_ok( null === $fg_repo->get_member_by_number( '0042' ), 'and the old number finds nobody any more' );
fg_ok( $fg_repo->update_member( $neu, array_merge( $neu_felder, array( 'member_no' => '0042' ) ) ), 'and changed back' );

// A change to the same values is not a change, and is not written.
$updated_at_vorher = $fg_repo->get_member( $neu )->updated_at;
usleep( 1100000 );
fg_ok( $fg_repo->update_member( $neu, $neu_felder ), 'writing the same values again is accepted' );
fg_ok( $updated_at_vorher === $fg_repo->get_member( $neu )->updated_at, 'but nothing was written', $fg_repo->get_member( $neu )->updated_at );
fg_ok( ! $fg_repo->update_member( 999999, $neu_felder ), 'an update of a member that is not there is refused' );

// Four fields, and each of them is required. An empty one is a row that cannot
// be found again on the public page, so it is not stored. Every case gets its
// own number and its own address, so a refusal is never a second member
// colliding with the first one.
$vollstaendig = array(
	'member_no'  => '0600',
	'email'      => 'voll@example.org',
	'first_name' => 'Voll',
	'last_name'  => 'Probe',
);
foreach ( array( 'member_no', 'email', 'first_name', 'last_name' ) as $leer ) {
	$fall             = $vollstaendig;
	$fall['member_no'] = '06' . ( false !== strpos( $leer, 'name' ) ? '0' : '1' ) . substr( md5( $leer ), 0, 1 );
	$fall[ $leer ]    = '';
	fg_ok( ! $fg_repo->insert_member( $fall ), 'a member without ' . $leer . ' is refused' );
}
fg_ok( (bool) $fg_repo->insert_member( $vollstaendig ), 'a complete member is accepted after the refusals' );
$voll_id = (int) $fg_repo->get_member_by_number( '0600' )->id;
$vor_der_leeren_zeile = $voll_id;

// A field that is nothing but spaces is the same as an empty one, because that
// is what a browser sends for a field nobody touched.
$mit_leerem_feld = $vollstaendig;
$mit_leerem_feld['member_no'] = '0602';
$mit_leerem_feld['email']     = 'nurLeer@example.org';
$mit_leerem_feld['first_name'] = '   ';
fg_ok( ! $fg_repo->insert_member( $mit_leerem_feld ), 'a name of nothing but spaces is refused' );

// A value that would not fit is refused rather than cut off, because a cut-off
// member number is a different member to everyone who reads it back.
$lang_fall = array(
	'member_no'  => '0700',
	'email'      => 'lang@example.org',
	'first_name' => 'Lang',
	'last_name'  => 'Probe',
);
$lang_fall['member_no'] = str_repeat( '9', FG_Schema::MEMBER_NO_MAX + 1 );
fg_ok( ! $fg_repo->insert_member( $lang_fall ), 'an over-long number is refused' );

$lang_fall['member_no']  = '0700';
$lang_fall['first_name'] = str_repeat( 'a', FG_Schema::MEMBER_NAME_MAX + 1 );
fg_ok( ! $fg_repo->insert_member( $lang_fall ), 'an over-long first name is refused' );

$lang_fall['first_name'] = 'Lang';
$lang_fall['last_name']  = str_repeat( 'a', FG_Schema::MEMBER_NAME_MAX + 1 );
fg_ok( ! $fg_repo->insert_member( $lang_fall ), 'an over-long last name is refused' );

$lang_fall['last_name'] = 'Probe';
$lang_fall['email']     = str_repeat( 'a', FG_Schema::MEMBER_EMAIL_MAX + 1 ) . '@example.org';
fg_ok( ! $fg_repo->insert_member( $lang_fall ), 'an over-long address is refused' );

// The limits are the sizes the columns really have. A limit that does not match
// the column would either refuse good data or store a truncated value.
$rand_number = str_repeat( '7', FG_Schema::MEMBER_NO_MAX );
$rand        = $fg_repo->insert_member( array( 'member_no' => $rand_number, 'email' => 'rand@example.org', 'first_name' => str_repeat( 'r', FG_Schema::MEMBER_NAME_MAX ), 'last_name' => str_repeat( 's', FG_Schema::MEMBER_NAME_MAX ) ) );
fg_ok( (bool) $rand, 'a member with the longest number and the longest names is stored', var_export( $rand, true ) );
$rand_row = $rand ? $fg_repo->get_member( $rand ) : null;
fg_ok(
	$rand_row && FG_Schema::MEMBER_NO_MAX === strlen( $rand_row->member_no ),
	'the number came back with every character',
	$rand_row ? (string) strlen( $rand_row->member_no ) : 'no row'
);
fg_ok( ! $fg_repo->insert_member( array( 'member_no' => $rand_number . '7', 'email' => 'rand2@example.org', 'first_name' => 'Rand', 'last_name' => 'Probe' ) ), 'one character more is refused' );
if ( $rand ) {
	$fg_repo->delete_member( $rand );
}

// The same limits hold on an update, so a long value cannot get in through the
// back door of the form.
$zu_lang_update = $vollstaendig;
$zu_lang_update['last_name'] = str_repeat( 'a', FG_Schema::MEMBER_NAME_MAX + 1 );
fg_ok( ! $fg_repo->update_member( $vor_der_leeren_zeile, $zu_lang_update ), 'an over-long name is refused on update too' );
fg_ok( 'Probe' === $fg_repo->get_member( $vor_der_leeren_zeile )->last_name, 'and the stored name is unchanged', $fg_repo->get_member( $vor_der_leeren_zeile )->last_name );

// A member may be in any number of duties, and removing a member takes its
// registrations with it.
$viel_event_a = fg_make_event( 'Mitglied viel A', $soon, '', true, array( 'demand' => 5 ) );
$viel_event_b = fg_make_event( 'Mitglied viel B', $soon, '', true, array( 'demand' => 5 ) );
$viel         = fg_make_member( '1234', 'viel@example.org', 'Viel', 'Probe' );
fg_register( $viel_event_a, $viel->id );
fg_register( $viel_event_b, $viel->id );
fg_ok( 2 === $fg_repo->count_member_registrations( $viel->id ), 'a member can be signed in for two duties', (string) $fg_repo->count_member_registrations( $viel->id ) );
$mitglied_zaehler = $fg_repo->get_registration_counts_for_members( array( $viel, $teilnehmer ) );
fg_ok(
	2 === count( $mitglied_zaehler )
	&& 2 === (int) $mitglied_zaehler[ $viel->id ]
	&& 1 === (int) $mitglied_zaehler[ $teilnehmer->id ],
	'the counts of a page of members come in one query',
	wp_json_encode( $mitglied_zaehler )
);

$viel_ride = $fg_repo->create_pending_ride(
	array(
		'event_id'      => $viel_event_a,
		'mode'          => FG_RIDE_MODE_OFFER,
		'alias'         => 'Bleibt',
		'origin'        => 'Innenstadt',
		'contact_email' => 'viel@example.org',
	)
);
$weg = $fg_repo->delete_member( $viel->id );
fg_ok( $weg['deleted'] && 2 === (int) $weg['registrations'], 'removing a member reports what it removed', wp_json_encode( $weg ) );
fg_ok( 0 === $fg_repo->count_event_registrations( $viel_event_a ) && 0 === $fg_repo->count_event_registrations( $viel_event_b ), 'the registrations of a removed member go with it' );
fg_ok( null !== $fg_repo->get_ride( $viel_ride['id'] ), 'the ride the member offered is its own entry and survives' );
fg_ok( null === $fg_repo->get_member( $viel->id ), 'the member is gone' );
$nochmal = $fg_repo->delete_member( $viel->id );
fg_ok( ! $nochmal['deleted'] && 0 === (int) $nochmal['registrations'], 'removing a member twice reports nothing to remove', wp_json_encode( $nochmal ) );

$fg_repo->delete_event( $viel_event_a );
$fg_repo->delete_event( $viel_event_b );

/* ------------------------------------------------------------------ 18 */
/* The CSV import.
 *
 * The report has to answer three questions at once: what is new, what has
 * changed, and who is in the club but not in the file. The import never deletes,
 * so the third list is the one a club needs to look at by hand.
 */
echo "[18] The CSV import\n";

$import = new FG_Member_Import( $fg_repo );

$kopf = "Mitgliedsnummer;E-Mail-Adresse;Vorname;Nachname\n";
$gut  = $import->parse( $kopf . "700;import1@example.org;Import;Eins\n701;import2@example.org;Import;Zwei\n" );
fg_ok( $gut['ok'], 'a clean file is read', wp_json_encode( $gut['error'] ) );
fg_ok( 2 === count( $gut['rows'] ), 'both rows are read', (string) count( $gut['rows'] ) );
fg_ok( '700' === $gut['rows'][0]['member_no'], 'the number is taken from the column it is named in', wp_json_encode( $gut['rows'][0] ) );
fg_ok( 2 === $gut['rows'][0]['zeile'], 'the first data row is line 2 of the file', (string) $gut['rows'][0]['zeile'] );

$erster = $import->apply( $gut['rows'] );
fg_ok( $erster['ok'] && 2 === $erster['created'] && 0 === $erster['updated'] && 0 === $erster['unchanged'], 'two new members are reported as new', wp_json_encode( $erster ) );
fg_ok( count( $erster['missing'] ) > 0, 'everything already in the club is reported as missing from the file', (string) count( $erster['missing'] ) );
$fehlende_nummern = array();
foreach ( $erster['missing'] as $fehlend ) {
	$fehlende_nummern[] = $fehlend->member_no;
}
fg_ok( in_array( '0042', $fehlende_nummern, true ) && in_array( '100', $fehlende_nummern, true ), 'the list names the members that are in the file nowhere', implode( ',', $fehlende_nummern ) );

// A second run of the same file changes nothing and says so, instead of
// reporting every member as updated.
$zweiter = $import->apply( $gut['rows'] );
fg_ok( $zweiter['ok'] && 0 === $zweiter['created'] && 0 === $zweiter['updated'] && 2 === $zweiter['unchanged'], 'the same file a second time changes nothing', wp_json_encode( $zweiter ) );

// One changed name in an otherwise identical file: one change, one new, and the
// rest untouched.
$dritter = $import->apply(
	$import->parse(
		$kopf
		. "700;import1@example.org;Import;EinsGeaendert\n"
		. "701;import2@example.org;Import;Zwei\n"
		. "702;import3@example.org;Import;Drei\n"
	)['rows']
);
fg_ok( $dritter['ok'] && 1 === $dritter['created'] && 1 === $dritter['updated'] && 1 === $dritter['unchanged'], 'one change is told apart from one new member and one untouched', wp_json_encode( $dritter ) );
fg_ok( 'EinsGeaendert' === $fg_repo->get_member_by_number( '700' )->last_name, 'the changed name is the one that is stored', $fg_repo->get_member_by_number( '700' )->last_name );
fg_ok( null !== $fg_repo->get_member_by_number( '702' ), 'the new member is in the club' );

// An import never removes anybody. A file of one member is the smallest file
// there is and still must not empty the list.
$ohne_neue = $import->apply( $import->parse( $kopf . "700;import1@example.org;Import;EinsGeaendert\n" )['rows'] );
fg_ok( $ohne_neue['ok'] && 0 === $ohne_neue['created'] && 0 === $ohne_neue['updated'], 'a file with one member deletes nobody', wp_json_encode( $ohne_neue ) );
fg_ok( null !== $fg_repo->get_member_by_number( '701' ) && null !== $fg_repo->get_member_by_number( '702' ), 'the members missing from the file are still there' );
$fehlend_nach_einem = count( $ohne_neue['missing'] );
fg_ok( $fehlend_nach_einem > 0, 'and they are named in the report', (string) $fehlend_nach_einem );

/* The refusals. Each one names the line, and each one leaves the club exactly
 * as it was: the file is read and checked completely before the first row is
 * written.
 */
$stand_vorher = $fg_repo->count_members();

$ablehnungen = array(
	'a missing column'            => array( "Mitgliedsnummer;E-Mail-Adresse;Vorname\n800;a@example.org;Ohne\n", 'fehlt: Nachname' ),
	'a header that is not one'    => array( "800;a@example.org;Ohne;Nachname\n", 'fehlt' ),
	'an empty file'               => array( '', 'konnte nicht gelesen werden' ),
	'a file of nothing but spaces' => array( "   \n  \n", 'konnte nicht gelesen werden' ),
	'a header and nothing else'   => array( $kopf, 'keine Datenzeile' ),
	'no number'                   => array( $kopf . ";leer@example.org;Leer;Probe\n", 'Zeile 2' ),
	'a blank line in the middle'  => array( $kopf . "800;eins@example.org;Eins;Probe\n\n801;zwei@example.org;Zwei;Probe\n", 'Zeile 3' ),
	'an address that is not one'  => array( $kopf . "800;keine-mail;Ungueltig;Probe\n", 'Zeile 2' ),
	'an address without a name'   => array( $kopf . "800;anonym@example.org;;Probe\n", 'Zeile 2' ),
	'a last name missing'         => array( $kopf . "800;ohneNachname@example.org;Ohne;\n", 'Zeile 2' ),
	'one number twice'            => array( $kopf . "800;eins@example.org;Eins;Probe\n800;zwei@example.org;Zwei;Probe\n", 'Zeile 3' ),
	'one number twice, once padded with a space' => array( $kopf . "800;eins@example.org;Eins;Probe\n 800 ;zwei@example.org;Zwei;Probe\n", 'Zeile 3' ),
	'one number twice in a different case' => array( $kopf . "abc;eins@example.org;Eins;Probe\nABC;zwei@example.org;Zwei;Probe\n", 'Zeile 3' ),
	'one address twice'           => array( $kopf . "800;gleich@example.org;Eins;Probe\n801;GLEICH@example.org;Zwei;Probe\n", 'Zeile 3' ),
	'a row with too few fields'   => array( $kopf . "800;eins@example.org;Eins\n", 'Zeile 2' ),
	'too many rows'               => array( $kopf . str_repeat( "1;eins@example.org;Eins;Probe\n", FG_Schema::MEMBER_IMPORT_MAX + 1 ), 'höchstens' ),
	'an over-long number'         => array( $kopf . str_repeat( '8', FG_Schema::MEMBER_NO_MAX + 1 ) . ";lang@example.org;Lang;Probe\n", 'zu lang' ),
);

// A row may carry more columns than the plugin reads: an export of the club's
// member administration very likely does. That is no reason for a complaint.
$mit_zusatz = $import->parse( $kopf . "810;zusatz@example.org;Zusatz;Probe;Strasse 3;Ort\n" );
fg_ok( $mit_zusatz['ok'] && 1 === count( $mit_zusatz['rows'] ), 'a row with more columns than needed is read', wp_json_encode( $mit_zusatz ) );

// A leading zero is part of the number, so 800 and 0800 are two members and a
// file holding both is no reason for a complaint.
$mit_null = $import->parse( $kopf . "800;acht@example.org;Acht;Probe\n0800;achtNull@example.org;Acht;ProbeNull\n" );
fg_ok( $mit_null['ok'] && 2 === count( $mit_null['rows'] ), '800 and 0800 are two members, not one', wp_json_encode( $mit_null ) );

foreach ( $ablehnungen as $bezeichnung => $fall ) {
	$ergebnis = $import->parse( $fall[0] );
	fg_ok( ! $ergebnis['ok'], 'the file is refused: ' . $bezeichnung );
	fg_ok( false !== strpos( FG_Member_Import::error_message( $ergebnis['error'] ), $fall[1] ), 'the refusal names what is wrong: ' . $bezeichnung, FG_Member_Import::error_message( $ergebnis['error'] ) );
	fg_ok( false !== strpos( FG_Member_Import::error_message( $ergebnis['error'] ), 'nichts importiert' ), 'the refusal says that nothing was written: ' . $bezeichnung );
}

fg_ok( $stand_vorher === $fg_repo->count_members(), 'a refused file leaves the member list untouched', $stand_vorher . ' -> ' . $fg_repo->count_members() );

// A file that reads cleanly but claims an address that belongs to somebody else
// is refused by the same rule, and this one is caught while applying, not while
// reading.
$vor_der_anwendung = $fg_repo->count_members();
$kollision          = $import->apply( $import->parse( $kopf . "810;import1@example.org;Kollision;Probe\n" )['rows'] );
fg_ok( ! $kollision['ok'], 'a file that takes the address of another member is refused' );
fg_ok( FG_Member_Import::ERROR_EMAIL_TAKEN === $kollision['error']['code'], 'and it is refused by name', wp_json_encode( $kollision['error'] ) );
fg_ok( null === $fg_repo->get_member_by_number( '810' ), 'the member that would have taken the address is not there' );
fg_ok( $vor_der_anwendung === $fg_repo->count_members(), 'a refused application writes nothing at all', $vor_der_anwendung . ' -> ' . $fg_repo->count_members() );

/* The shapes of a real export: a comma instead of a semicolon, a declaration
 * line, an upper-case header, a byte order mark, more columns than needed and
 * a name with an umlaut.
 */
$komma = $import->parse( "\xEF\xBB\xBFMitgliedsnummer,E-Mail,First Name,Last Name,Strasse\r\n960,komma@example.org,Komma,Probe,Hauptstr. 3\r\n" );
fg_ok( $komma['ok'], 'a comma file with a mark and a declaration-free header is read', wp_json_encode( $komma['error'] ) );
fg_ok( 1 === count( $komma['rows'] ) && 'Komma' === $komma['rows'][0]['first_name'], 'and the names land in the right columns', wp_json_encode( $komma['rows'] ) );

$mit_sep = $import->parse( "sep=;\n" . $kopf . "970;umlaut@example.org;Müller;Ötzi\n" );
fg_ok( $mit_sep['ok'], 'a declaration line is understood', wp_json_encode( $mit_sep['error'] ) );
fg_ok( 1 === count( $mit_sep['rows'] ) && 'Müller' === $mit_sep['rows'][0]['first_name'], 'and the umlauts survive', wp_json_encode( $mit_sep['rows'] ) );
fg_ok( 3 === (int) $mit_sep['rows'][0]['zeile'], 'and the line it is on counts the declaration line', wp_json_encode( $mit_sep['rows'][0] ) );

$latein = $import->parse( "sep=;\n" . $kopf . "971;iso@example.org;M\xfcller;Probe\n" );
fg_ok( $latein['ok'], 'a file in the old German encoding is read', wp_json_encode( $latein['error'] ) );
fg_ok( 1 === count( $latein['rows'] ) && 'Müller' === $latein['rows'][0]['first_name'], 'and converted rather than guessed at', wp_json_encode( $latein['rows'] ) );

// A complaint about a line has to name the line of the file, and a declaration
// line in front pushes everything one down.
$mit_sep_fehler = $import->parse( "sep=;\n" . $kopf . "972;ok@example.org;Ok;Probe\n;keine-nummer@example.org;Leer;Probe\n" );
fg_ok( ! $mit_sep_fehler['ok'], 'a file with a declaration line is refused all the same', wp_json_encode( $mit_sep_fehler ) );
fg_ok( 4 === (int) $mit_sep_fehler['error']['zeile'], 'and the line it names is the line of the file', wp_json_encode( $mit_sep_fehler['error'] ) );

$gross = $import->parse( "MITGLIEDSNUMMER;E-MAIL-ADRESSE;VORNAME;NACHNAME\n950;gross@example.org;Gross;Schreibung\n" );
fg_ok( $gross['ok'], 'a header in capitals is understood', wp_json_encode( $gross['error'] ) );

$mit_leerzeile = $import->parse( $kopf . "980;mitLeer@example.org;Mit;Leerzeile\n\n981;nachLeer@example.org;Nach;Leerzeile\n" );
fg_ok( ! $mit_leerzeile['ok'] && FG_Member_Import::ERROR_EMPTY_NUMBER === $mit_leerzeile['error']['code'], 'a blank line in the middle of the file is a row without a number, not a short row', wp_json_encode( $mit_leerzeile['error'] ) );
fg_ok( 3 === (int) $mit_leerzeile['error']['zeile'], 'and the line it is on is the real one', wp_json_encode( $mit_leerzeile['error'] ) );

$abschluss = $import->apply( $komma['rows'] );
fg_ok( $abschluss['ok'] && 1 === $abschluss['created'], 'a file read with all of that can still be applied', wp_json_encode( $abschluss ) );

/* ------------------------------------------------------------------ 19 */
/* The registration itself, from the POST to the e-mail and back.
 *
 * The page decides whether a button is there. This section decides whether the
 * server lets the registration through anyway, because that is the only question
 * that matters for a duty that is full.
 */
echo "[19] Registering for a work duty\n";

function fg_signup_post( array $overrides = array() ) {
	global $fg_repo;

	$base = array(
		'action'           => 'fg_register_member',
		'fg_event_ref'     => $fg_repo->get_event( (int) $GLOBALS['fg_state']['event_id'] )->public_ref,
		'fg_member_no'     => '100',
		'fg_member_email'  => 'teilnehmer@example.org',
		'fg_website'       => '',
		'form_started_at'  => (string) ( time() - 30 ),
		'source_url'       => home_url( '/arbeitsdienste/' ),
		'fg_register_nonce' => wp_create_nonce( 'fg_register_member' ),
	);

	return array_merge( $base, $overrides );
}

$anmelde_event = fg_make_event( 'Anmeldung Server', $soon, '', true, array( 'demand' => 2 ) );
$anmelde_ref   = $fg_repo->get_event( $anmelde_event )->public_ref;
$ander         = fg_make_member( '500', 'ander500@example.org', 'Ander', 'Probe' );

fg_mail_reset();
list( $ort ) = fg_call(
	array( $fg_actions, 'register_member' ),
	fg_signup_post( array( 'fg_event_ref' => $anmelde_ref ) )
);
fg_ok( 'registered' === fg_notice_of( $ort ), 'a correct pair is registered', (string) $ort );
fg_ok( 1 === $fg_repo->count_event_registrations( $anmelde_event ), 'and the row is there', (string) $fg_repo->count_event_registrations( $anmelde_event ) );
fg_ok( 1 === count( fg_mail_recipients() ), 'exactly one mail is sent', implode( ',', fg_mail_recipients() ) );
fg_ok( 'teilnehmer@example.org' === fg_mail_recipients()[0], 'and it goes to the address of the member', implode( ',', fg_mail_recipients() ) );

$anmeldung = $fg_repo->get_registration( $fg_repo->get_event_registrations( $anmelde_event )[0]['registration']->id );
fg_contains( $anmeldung->public_ref, fg_mail_bodies(), 'the mail carries the way back out' );
fg_contains( 'fg_duty_action=view', fg_mail_bodies(), 'and it is the two-step link, not a bare token in the mail' );
fg_contains( 'Anmeldung Server', fg_mail_bodies(), 'the mail names the duty' );
fg_not_contains( 'Teilnehmer Probe', fg_mail_bodies(), 'the name of the member is not in the mail' );

// A second press of the button writes nothing and sends no second mail. Two
// mails would mean two links in one inbox, and only the newer one would work.
fg_mail_reset();
list( $ort ) = fg_call(
	array( $fg_actions, 'register_member' ),
	fg_signup_post( array( 'fg_event_ref' => $anmelde_ref ) )
);
fg_ok( 'already_registered' === fg_notice_of( $ort ), 'a member who is already in is told so', (string) $ort );
fg_ok( 1 === $fg_repo->count_event_registrations( $anmelde_event ), 'and no second row appears', (string) $fg_repo->count_event_registrations( $anmelde_event ) );
fg_ok( 0 === count( fg_mail_recipients() ), 'a second mail is not sent', implode( ',', fg_mail_recipients() ) );

/* A pair that does not belong together. The page must not say which half was
 * wrong, because that would tell a passer-by whether a guessed number exists.
 */
$paare = array(
	'unknown number'        => array( 'fg_member_no' => '999999', 'fg_member_email' => 'teilnehmer@example.org' ),
	'number of another'     => array( 'fg_member_no' => '500', 'fg_member_email' => 'teilnehmer@example.org' ),
	'address of another'    => array( 'fg_member_no' => '100', 'fg_member_email' => 'ander500@example.org' ),
	'unknown address'       => array( 'fg_member_no' => '100', 'fg_member_email' => 'gibtesnicht@example.org' ),
	'no number at all'      => array( 'fg_member_no' => '' ),
	'no address'            => array( 'fg_member_email' => '' ),
	'address that is not one' => array( 'fg_member_email' => 'keine-mail' ),
	'unknown duty'          => array( 'fg_event_ref' => 'gibtesnicht' ),
	'no duty'               => array( 'fg_event_ref' => '' ),
);

foreach ( $paare as $bezeichnung => $paar ) {
	fg_mail_reset();
	list( $ort ) = fg_call( array( $fg_actions, 'register_member' ), fg_signup_post( $paar ) );
	$hinweis = fg_notice_of( $ort );
	fg_ok( 'not_registered' === $hinweis, 'the registration is refused: ' . $bezeichnung, (string) $ort );
	fg_ok( 0 === count( fg_mail_recipients() ), 'and no mail goes out: ' . $bezeichnung );
}
fg_ok( 1 === $fg_repo->count_event_registrations( $anmelde_event ), 'no refused pair left a row behind', (string) $fg_repo->count_event_registrations( $anmelde_event ) );

// A withdrawn duty cannot be signed in for either.
$zurueck = fg_make_event( 'Anmeldung zu Ende', $soon, '', false, array( 'demand' => 5 ) );
list( $ort ) = fg_call(
	array( $fg_actions, 'register_member' ),
	fg_signup_post( array( 'fg_event_ref' => $fg_repo->get_event( $zurueck )->public_ref ) )
);
fg_ok( 'not_registered' === fg_notice_of( $ort ), 'a duty that is not offered cannot be signed in for', (string) $ort );
fg_ok( 0 === $fg_repo->count_event_registrations( $zurueck ), 'and nothing is written for it' );
$fg_repo->delete_event( $zurueck );

/* The last place. The button on the page is already gone, so this is the check
 * that the server says no on its own. A check that only looked at the button
 * would pass on a page that has no button for a reason nobody knows.
 */
fg_call( array( $fg_actions, 'register_member' ), fg_signup_post( array( 'fg_event_ref' => $anmelde_ref, 'fg_member_no' => '500', 'fg_member_email' => 'ander500@example.org' ) ) );
fg_ok( 2 === $fg_repo->count_event_registrations( $anmelde_event ), 'the second place is taken', (string) $fg_repo->count_event_registrations( $anmelde_event ) );
fg_ok( ! $fg_repo->can_register( $fg_repo->get_event( $anmelde_event ), 2 ), 'the duty reports itself as full' );

fg_mail_reset();
list( $ort ) = fg_call(
	array( $fg_actions, 'register_member' ),
	fg_signup_post( array( 'fg_event_ref' => $anmelde_ref, 'fg_member_no' => '300', 'fg_member_email' => 'sucher@example.org' ) )
);
fg_ok( 'duty_full' === fg_notice_of( $ort ), 'a full duty refuses the registration on the server', (string) $ort );
fg_ok( 2 === $fg_repo->count_event_registrations( $anmelde_event ), 'and the refusal wrote nothing', (string) $fg_repo->count_event_registrations( $anmelde_event ) );
fg_ok( 0 === count( fg_mail_recipients() ), 'and sent no mail', implode( ',', fg_mail_recipients() ) );
fg_ok( 0 === $fg_repo->free_places( $fg_repo->get_event( $anmelde_event ), 2 ), 'a full duty has no free place' );
fg_ok( 0 === $fg_repo->free_places( $fg_repo->get_event( $anmelde_event ), 9 ), 'and never a negative one either' );

// A honeypot and a missing nonce are both refused before anything is looked up.
$honig_event = fg_make_event( 'Anmeldung Honig', $soon, '', true, array( 'demand' => 5 ) );
fg_mail_reset();
list( $ort ) = fg_call(
	array( $fg_actions, 'register_member' ),
	fg_signup_post( array( 'fg_event_ref' => $fg_repo->get_event( $honig_event )->public_ref, 'fg_member_no' => '500', 'fg_member_email' => 'ander500@example.org', 'fg_website' => 'http://spam.example' ) )
);
fg_ok( 'not_registered' === fg_notice_of( $ort ), 'a filled honeypot is refused', (string) $ort );
fg_ok( 0 === $fg_repo->count_event_registrations( $honig_event ), 'and writes nothing' );
fg_ok( 0 === count( fg_mail_recipients() ), 'and sends no mail' );

$stats = (array) get_option( FG_STATS_OPTION, array() );
fg_ok( ! empty( $stats[ gmdate( 'Y-m-d' ) ]['bot_honeypot'] ), 'the honeypot is counted', wp_json_encode( $stats[ gmdate( 'Y-m-d' ) ] ) );

/* ------------------------------------------------------------------ 20 */
/* The way back out.
 *
 * The link in the mail opens a page and asks. A mail program that follows every
 * link it reads would otherwise sign members out of duties nobody asked them to
 * leave, so the POST is a second, deliberate step with its own nonce.
 */
echo "[20] The unregistration link\n";

$abmelde_event = fg_make_event( 'Anmeldung Abmeldung', $soon, '', true, array( 'demand' => 2 ) );
$abmelde       = fg_register( $abmelde_event, $ander->id );
$abmelde_row   = $fg_repo->get_registration( $abmelde['id'] );

fg_ok( $fg_repo->valid_unregister_token( $abmelde_row, $abmelde['unregister_token'] ), 'the token of a fresh registration is valid' );
fg_ok( ! $fg_repo->valid_unregister_token( $abmelde_row, $abmelde['unregister_token'] . 'x' ), 'a token that is one character off is not' );
fg_ok( ! $fg_repo->valid_unregister_token( $abmelde_row, '' ), 'an empty token is not' );
fg_ok( '' !== $abmelde['public_ref'], 'the registration has a reference of its own' );
fg_ok( null !== $fg_repo->get_registration_by_reference( $abmelde['public_ref'] ), 'and is found by it' );

/* The landing page runs in a child process, because it ends the request. It
 * must remove nothing: a GET is what a mail program does.
 */
list( $html, $err, $code, $out ) = fg_render_duty_action_page(
	array(
		'fg_duty_action' => 'view',
		'signup_ref'     => $abmelde['public_ref'],
		'intent'         => 'unregister',
		'token'          => $abmelde['unregister_token'],
	)
);
fg_ok( false !== strpos( $html, 'Anmeldung löschen' ), 'the link opens a page that asks before it deletes', trim( $err . ' ' . $out ) );
fg_contains( 'Anmeldung Abmeldung', $html, 'the page names the duty' );
fg_contains( '500', $html, 'the page names the member number' );
fg_contains( 'name="token_nonce"', $html, 'the page carries a nonce of its own' );
fg_contains( 'name="token"', $html, 'the page carries the token' );
fg_contains( 'name="action" value="fg_process_duty_token"', $html, 'the page posts to the handler of the registration' );
fg_ok( 1 === $fg_repo->count_event_registrations( $abmelde_event ), 'opening the link removes nothing', (string) $fg_repo->count_event_registrations( $abmelde_event ) );

// A link with a token that does not fit is not a page that asks, it is a page
// that says the link is no good. It must not name the duty on the way out.
list( $falsch_html ) = fg_render_duty_action_page(
	array(
		'fg_duty_action' => 'view',
		'signup_ref'     => $abmelde['public_ref'],
		'intent'         => 'unregister',
		'token'          => 'nichtdasrichtigetoken',
	)
);
fg_contains( 'Link nicht gültig', $falsch_html, 'a wrong token leads to the refusal' );
fg_not_contains( 'Anmeldung Abmeldung', $falsch_html, 'and the refusal names no duty' );

list( $ref_html ) = fg_render_duty_action_page(
	array(
		'fg_duty_action' => 'view',
		'signup_ref'     => 'gibtesnicht',
		'intent'         => 'unregister',
		'token'          => $abmelde['unregister_token'],
	)
);
fg_contains( 'Link nicht gültig', $ref_html, 'an unknown reference leads to the refusal' );

// The nonce of the page is what the POST has to carry, and it is read out of
// the page rather than computed, so the check cannot pass on a nonce the
// handler does not ask for.
preg_match( '/name="token_nonce" value="([^"]+)"/', $html, $nonce_treffer );
$abmelde_nonce = isset( $nonce_treffer[1] ) ? $nonce_treffer[1] : '';
fg_ok( '' !== $abmelde_nonce, 'the page hands out a nonce' );

$abmelde_post = array(
	'action'      => 'fg_process_duty_token',
	'signup_ref'  => $abmelde['public_ref'],
	'intent'      => 'unregister',
	'token'       => $abmelde['unregister_token'],
	'token_nonce' => $abmelde_nonce,
	'source_url'  => home_url( '/arbeitsdienste/' ),
);

// Without the nonce of the page nothing is removed, whatever else is right. The
// answer is form_expired and not invalid_token: the token in this POST is the
// right one, and what is wrong is the age of the page. Telling somebody their
// link is dead when it is only their tab that is old would send them to the
// club instead of back to the link they were already on.
list( $ort ) = fg_call(
	array( $fg_actions, 'process_duty_token' ),
	array_merge( $abmelde_post, array( 'token_nonce' => 'irgendetwasanderes' ) )
);
fg_ok( 'form_expired' === fg_notice_of( $ort ), 'a POST with the wrong nonce removes nothing and says the form is old', (string) $ort );
fg_ok( 1 === $fg_repo->count_event_registrations( $abmelde_event ), 'and the registration is still there', (string) $fg_repo->count_event_registrations( $abmelde_event ) );

// The nonce belongs to this one token, so it is no good on another one. Both
// tokens are live, so this is a case of the form and not of the link.
$zweite = fg_register( $abmelde_event, $sucher->id );
list( $ort ) = fg_call(
	array( $fg_actions, 'process_duty_token' ),
	array_merge( $abmelde_post, array( 'signup_ref' => $zweite['public_ref'], 'token' => $zweite['unregister_token'] ) )
);
fg_ok( 'form_expired' === fg_notice_of( $ort ), 'the nonce of one link does not open another one', (string) $ort );
fg_ok( 2 === $fg_repo->count_event_registrations( $abmelde_event ), 'and both registrations are still there', (string) $fg_repo->count_event_registrations( $abmelde_event ) );

// A POST is a POST. A GET on the handler is not allowed at all.
$get_die = fg_expect_die( array( $fg_actions, 'process_duty_token' ), 'GET', array() );
fg_ok( is_array( $get_die ) && 405 === (int) $get_die['response'], 'GET on the unregistration endpoint is refused', wp_json_encode( $get_die ) );
fg_ok( 2 === $fg_repo->count_event_registrations( $abmelde_event ), 'and the refused request changed nothing' );

/* The deliberate POST. */
list( $ort ) = fg_call( array( $fg_actions, 'process_duty_token' ), $abmelde_post );
fg_ok( 'unregistered' === fg_notice_of( $ort ), 'the deliberate POST removes the registration', (string) $ort );
fg_ok( 1 === $fg_repo->count_event_registrations( $abmelde_event ), 'the row is gone', (string) $fg_repo->count_event_registrations( $abmelde_event ) );
fg_ok( ! $fg_repo->has_registration( $abmelde_event, $ander->id ), 'and the pair no longer exists' );
fg_ok( null !== $fg_repo->get_member_by_number( '500' ), 'the member stays in the club' );
fg_ok( 1 === $fg_repo->free_places( $fg_repo->get_event( $abmelde_event ), 1 ), 'and the place it held is free again' );

/* A link that is used twice removes nothing the second time. The removal is one
 * statement that matches the row and the hash together, so a replay matches
 * nothing and the page says the truth: the entry is gone.
 */
list( $ort ) = fg_call( array( $fg_actions, 'process_duty_token' ), $abmelde_post );
fg_ok( 'invalid_token' === fg_notice_of( $ort ), 'the same link a second time removes nothing', (string) $ort );
fg_ok( 1 === $fg_repo->count_event_registrations( $abmelde_event ), 'and the other registration is untouched', (string) $fg_repo->count_event_registrations( $abmelde_event ) );

list( $erneut_html ) = fg_render_duty_action_page(
	array(
		'fg_duty_action' => 'view',
		'signup_ref'     => $abmelde['public_ref'],
		'intent'         => 'unregister',
		'token'          => $abmelde['unregister_token'],
	)
);
fg_contains( 'Link nicht gültig', $erneut_html, 'the link is dead once it has been used' );

/* The last member of a full duty, unregistering by mail, gives the place back
 * and the page offers it again. That is the whole point of the link.
 */
list( $ort ) = fg_call( array( $fg_actions, 'process_duty_token' ), array_merge( $abmelde_post, array( 'signup_ref' => $zweite['public_ref'], 'token' => $zweite['unregister_token'], 'token_nonce' => '' ) ) );
fg_ok( 'form_expired' === fg_notice_of( $ort ), 'a link without its own nonce is refused even for a live entry', (string) $ort );
fg_ok( 1 === $fg_repo->count_event_registrations( $abmelde_event ), 'and the entry is still there', (string) $fg_repo->count_event_registrations( $abmelde_event ) );

$fg_repo->delete_event( $abmelde_event );

/* The expired token is not a token any more, and the entry stays: a duty that
 * is over is a fact about the past, and who did it is part of that fact.
 */
$verfallen_event = fg_make_event( 'Anmeldung verfallen', $soon, '', true, array( 'demand' => 3 ) );
$verfallen       = fg_register( $verfallen_event, $ander->id );

// The expiry is a column, and a test has to be able to put a date in the past.
// That is a store operation, so it goes to the store and not through the
// domain layer, which has no reason to offer "make this link older".
$fg_store = new FG_Store();
$fg_store->update_event_member( $verfallen['id'], array( 'unregister_expires' => time() - 10 ) );
$verfallen_row = $fg_repo->get_registration( $verfallen['id'] );
fg_ok( ! $fg_repo->valid_unregister_token( $verfallen_row, $verfallen['unregister_token'] ), 'an expired token is refused' );
fg_ok( in_array( $verfallen['id'], $fg_repo->get_expired_unregister_registration_ids(), true ), 'and the cleanup finds the row', wp_json_encode( $fg_repo->get_expired_unregister_registration_ids() ) );

$stats_option = (array) get_option( FG_STATS_OPTION, array() );
$stats_option[ gmdate( 'Y-m-d' ) ]['publish_form_total'] = 1;
update_option( FG_STATS_OPTION, $stats_option, false );
$fg_actions->daily_cleanup();
fg_ok( null !== $fg_repo->get_registration( $verfallen['id'] ), 'the registration itself is kept after the cleanup' );
fg_ok( '' === $fg_repo->get_registration( $verfallen['id'] )->unregister_hash, 'only the token is dropped', var_export( $fg_repo->get_registration( $verfallen['id'] )->unregister_hash, true ) );

$stats = (array) get_option( FG_STATS_OPTION, array() );
$heute = isset( $stats[ gmdate( 'Y-m-d' ) ] ) ? (array) $stats[ gmdate( 'Y-m-d' ) ] : array();
fg_ok( ! empty( $heute['signup_created'] ), 'a registration is counted', wp_json_encode( $heute ) );
fg_ok( ! empty( $heute['signup_unregistered'] ), 'an unregistration is counted', wp_json_encode( $heute ) );
fg_ok( ! empty( $heute['signup_refused_full'] ), 'a refusal of a full duty is counted', wp_json_encode( $heute ) );
fg_ok( ! empty( $heute['signup_invalid'] ), 'a refused pair is counted', wp_json_encode( $heute ) );

$fg_repo->delete_event( $verfallen_event );
$fg_repo->delete_event( $honig_event );
$fg_repo->delete_event( $anmelde_event );

/* ------------------------------------------------------------------ done */
fg_state_save();
$failed = count( $GLOBALS['fg_fail'] );
echo "\n== {$GLOBALS['fg_pass']} passed, {$failed} failed ==\n";
foreach ( $GLOBALS['fg_fail'] as $failure ) {
	echo " - {$failure}\n";
}
exit( $failed > 0 ? 1 : 0 );
