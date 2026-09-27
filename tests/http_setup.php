<?php
/**
 * Prepare deterministic data for the HTTP level test.
 *
 * Prints a JSON object with every value the curl test needs.
 */
define( 'WP_USE_THEMES', false );
require '/var/www/html/wp-load.php';

$repo = new FG_Repository();
foreach ( $repo->get_all_events() as $event ) {
	$repo->delete_event( $event->id );
}

// The members of a run of before are removed as well. The numbers below are
// fixed, so without this the second run of the suite would find them already
// there, the inserts would be refused, and every duty would be built without a
// single registration — silently, because a refused insert is a zero and a zero
// is a usable value everywhere else in this file.
foreach ( $repo->get_members_page() as $member ) {
	$repo->delete_member( $member->id );
}

delete_option( FG_STATS_OPTION );
delete_option( FG_CLEANUP_OPTION );

$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Fahrgemeinschaften',
		'post_content' => '[fahrgemeinschaften]',
	)
);

// The second page, carrying nothing but the list of work duties. It stands on
// its own so that the list is fetched the way a visitor finds it: over the
// site's own address, inside the theme, without the form around it.
//
// A page of every run of before is reused rather than a new one created. The
// URL of this page is written into the fixture and read by two suites, and a
// suite that pointed at a page from a run of before would be reading a fixture
// somebody else built. WordPress keeps the numbered leftovers of the runs
// that did create a page each; they carry the same shortcode and are inert,
// and they belong to the same pile of test pages as the ride pages above.
$list_pages = get_posts(
	array(
		'post_type'        => 'page',
		'post_status'      => 'any',
		'name'             => 'arbeitsdienste',
		'numberposts'      => 1,
		'suppress_filters' => true,
	)
);

if ( $list_pages ) {
	$list_page_id = $list_pages[0]->ID;
	wp_update_post(
		array(
			'ID'           => $list_page_id,
			'post_title'   => 'Arbeitsdienste',
			'post_content' => '[arbeitsdienste]',
			'post_status'  => 'publish',
		)
	);
} else {
	$list_page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Arbeitsdienste',
			'post_name'    => 'arbeitsdienste',
			'post_content' => '[arbeitsdienste]',
		)
	);
}

$soon = current_datetime()->modify( '+9 days' )->format( 'Y-m-d' );

$event_id = $repo->insert_event(
	array(
		'title'      => 'Arbeitsdienst Laber',
		'event_date' => $soon,
		'event_time' => '08:00',
		'demand'     => 4,
		'is_active'  => true,
	)
);

// The member administration the public signup form reads. Three members, each
// with a number that keeps its leading zero, because the number is a string
// and the club's own numbers have them.
$mitglieder = array(
	array( '0042', 'anton@angeln.example.org', 'Anton', 'Angeln' ),
	array( '0043', 'berta@angeln.example.org', 'Berta', 'Beispiel' ),
	array( '0044', 'cem@angeln.example.org', 'Cem', 'Cemu' ),
);

$mitglied_ids = array();
foreach ( $mitglieder as $felder ) {
	$mitglied_ids[ $felder[0] ] = $repo->insert_member(
		array(
			'member_no'  => $felder[0],
			'email'      => $felder[1],
			'first_name' => $felder[2],
			'last_name'  => $felder[3],
		)
	);
	// Loud, not silent. A member that could not be created takes every later
	// step with it, and the suite would then report on a fixture that is not
	// the one it wrote.
	if ( ! $mitglied_ids[ $felder[0] ] ) {
		fwrite( STDERR, "http_setup: member " . $felder[0] . " could not be created\n" );
		exit( 1 );
	}
}

// One member is already in. The page must not show a name, but it must show
// the free places one lower than the demand, and the list has to have
// something in it for the admin suite to look at.
$drin = $repo->create_registration( $event_id, $mitglied_ids['0042'], home_url( '/arbeitsdienste/' ) );
if ( ! $drin['id'] ) {
	fwrite( STDERR, "http_setup: the registration for the main duty could not be created\n" );
	exit( 1 );
}

// A second duty that is full to the last place, and a third that states no
// demand at all. Both refuse a registration, and the two refusals say
// different things, which is what the public page is checked for.
$voller_id = $repo->insert_event(
	array(
		'title'      => 'Arbeitsdienst voll',
		'event_date' => $soon,
		'demand'     => 1,
		'is_active'  => true,
	)
);
$voller_drin = $repo->create_registration( $voller_id, $mitglied_ids['0043'] );
if ( ! $voller_drin['id'] ) {
	fwrite( STDERR, "http_setup: the full duty could not be filled\n" );
	exit( 1 );
}

$ohne_bedarf_id = $repo->insert_event(
	array(
		'title'      => 'Arbeitsdienst ohne Bedarf',
		'event_date' => $soon,
		'demand'     => 0,
		'is_active'  => true,
	)
);

// Pending ride with a known confirmation token.
$confirm_token = 'httpconfirmtoken0000000000000000000000A';
$pending       = $repo->create_pending_ride(
	array(
		'event_id'      => $event_id,
		'mode'          => FG_RIDE_MODE_OFFER,
		'alias'         => 'Moewe-Trupp',
		'origin'        => 'Innenstadt',
		'contact_email' => 'anton@angeln.example.org',
	)
);
$pending_id = $pending['id'];
$repo->update_ride(
	$pending_id,
	array(
		'pending_confirm_hash'    => FG_Security::hash_token( $confirm_token ),
		'pending_confirm_expires' => time() + 3600,
		'pending_discard_hash'    => FG_Security::hash_token( 'httpdiscardtoken0000000000000000000000B' ),
		'pending_discard_expires' => time() + 3600,
	)
);

// Published ride with a known delete token.
$delete_token = 'httpdeletetoken00000000000000000000000C';
$published    = $repo->create_pending_ride(
	array(
		'event_id'      => $event_id,
		'mode'          => FG_RIDE_MODE_SEARCH,
		'alias'         => 'Amsel-Gruppe',
		'origin'        => 'Suedstadt',
		'contact_email' => 'berta@angeln.example.org',
	)
);
$published_id = $published['id'];
$repo->update_ride(
	$published_id,
	array(
		'status'                  => FG_RIDE_STATUS_PUBLISHED,
		'confirmed_at'            => current_time( 'mysql' ),
		'pending_confirm_hash'    => '',
		'pending_confirm_expires' => 0,
		'pending_discard_hash'    => '',
		'pending_discard_expires' => 0,
		'delete_hash'             => FG_Security::hash_token( $delete_token ),
		'delete_expires'          => time() + 30 * DAY_IN_SECONDS,
	)
);

$event = $repo->get_event( $event_id );

$output = array(
	'page_id'         => $page_id,
	'list_page_id'    => $list_page_id,
	'list_page_path'  => wp_make_link_relative( get_permalink( $list_page_id ) ),
	'event_id'        => $event_id,
	'event_ref'       => $event->public_ref,
	'event_uuid'      => $event->event_uuid,
	'pending_id'      => $pending_id,
	'pending_ref'     => $repo->get_ride( $pending_id )->public_ref,
	'confirm_token'   => $confirm_token,
	'confirm_nonce'   => hash_hmac( 'sha256', 'confirm|' . $confirm_token, wp_salt( 'nonce' ) ),
	'discard_token'   => 'httpdiscardtoken0000000000000000000000B',
	'discard_nonce'   => hash_hmac( 'sha256', 'discard|httpdiscardtoken0000000000000000000000B', wp_salt( 'nonce' ) ),
	'published_id'    => $published_id,
	'published_ref'   => $repo->get_ride( $published_id )->public_ref,
	'delete_token'    => $delete_token,
	'delete_nonce'    => hash_hmac( 'sha256', 'delete|' . $delete_token, wp_salt( 'nonce' ) ),
	'home'            => home_url( '/' ),
	'admin_post'      => admin_url( 'admin-post.php' ),
	'member_free_no'  => '0044',
	'member_free_mail' => 'cem@angeln.example.org',
	'member_taken_no' => '0042',
	'member_taken_mail' => 'anton@angeln.example.org',
	'full_event_id'   => $voller_id,
	'full_event_ref'  => $repo->get_event( $voller_id )->public_ref,
	'full_registrations' => (int) $repo->count_event_registrations( $voller_id ),
	'no_demand_event_id' => $ohne_bedarf_id,
	'no_demand_event_ref' => $repo->get_event( $ohne_bedarf_id )->public_ref,
	'demand'          => (int) $event->demand,
	// The titles travel with the fixture because the HTTP suite names the cards
	// by their title. A title typed into the suite would have to be changed
	// twice, in two files, every time the fixture is reworded, and a check that
	// quietly looks for a card that is not there passes as "no such card" in
	// half of its possible shapes.
	'event_title'     => $event->title,
	'full_event_title' => $repo->get_event( $voller_id )->title,
	'no_demand_title' => $repo->get_event( $ohne_bedarf_id )->title,
	'full_free_places' => $repo->free_places( $repo->get_event( $voller_id ), (int) $repo->count_event_registrations( $voller_id ) ),
	'no_demand_free_places' => $repo->free_places( $repo->get_event( $ohne_bedarf_id ), 0 ),
	'registrations'   => (int) $repo->count_event_registrations( $event_id ),
	'free_places'     => $repo->free_places( $event, $repo->count_event_registrations( $event_id ) ),
);

echo wp_json_encode( $output ), "\n";
