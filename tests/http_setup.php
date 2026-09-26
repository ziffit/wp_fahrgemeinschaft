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

$soon   = current_datetime()->modify( '+9 days' )->format( 'Y-m-d' );
$people = array( 'Anton@angeln.example.org', 'Berta@angeln.example.org', 'Cem@angeln.example.org' );

$event_id = $repo->insert_event(
	array(
		'title'        => 'Arbeitsdienst Laber',
		'event_date'   => $soon,
		'event_time'   => '08:00',
		'participants' => $people,
		'is_active'    => true,
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
	'page_id'        => $page_id,
	'event_id'       => $event_id,
	'event_ref'      => $event->public_ref,
	'event_uuid'     => $event->event_uuid,
	'pending_id'     => $pending_id,
	'pending_ref'    => $repo->get_ride( $pending_id )->public_ref,
	'confirm_token'  => $confirm_token,
	'confirm_nonce'  => hash_hmac( 'sha256', 'confirm|' . $confirm_token, wp_salt( 'nonce' ) ),
	'discard_token'  => 'httpdiscardtoken0000000000000000000000B',
	'discard_nonce'  => hash_hmac( 'sha256', 'discard|httpdiscardtoken0000000000000000000000B', wp_salt( 'nonce' ) ),
	'published_id'   => $published_id,
	'published_ref'  => $repo->get_ride( $published_id )->public_ref,
	'delete_token'   => $delete_token,
	'delete_nonce'   => hash_hmac( 'sha256', 'delete|' . $delete_token, wp_salt( 'nonce' ) ),
	'home'           => home_url( '/' ),
	'admin_post'     => admin_url( 'admin-post.php' ),
);

echo wp_json_encode( $output ), "\n";
