<?php
/**
 * Data access and domain rules.
 *
 * @package Fahrgemeinschaften
 */

defined( 'ABSPATH' ) || exit;

/**
 * Domain layer for events and rides.
 *
 * This is the only API the rest of the plugin uses. It translates between the
 * stored rows and the two record types, and it decides what may be shown
 * publicly. No caller talks to the database or to WordPress posts directly.
 */
final class FG_Repository {
	/**
	 * Storage layer.
	 *
	 * @var FG_Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param FG_Store|null $store Optional store.
	 */
	public function __construct( FG_Store $store = null ) {
		$this->store = $store ? $store : new FG_Store();
	}

	/**
	 * Normalize and validate an e-mail address for matching.
	 *
	 * @param mixed $email Raw address.
	 * @return string|false
	 */
	public function normalize_email( $email ) {
		if ( ! is_string( $email ) ) {
			return false;
		}

		$email = strtolower( trim( $email ) );
		if ( '' === $email || strlen( $email ) > 254 || ! is_email( $email ) ) {
			return false;
		}

		return $email;
	}

	/**
	 * Check whether a value is a usable event date.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function is_valid_date( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}

		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		$errors = DateTimeImmutable::getLastErrors();

		return $date instanceof DateTimeImmutable
			&& $date->format( 'Y-m-d' ) === $value
			&& ( false === $errors || ( 0 === (int) $errors['warning_count'] && 0 === (int) $errors['error_count'] ) );
	}

	/**
	 * Check whether a value is a usable cut-off time.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function is_valid_time( $value ) {
		return is_string( $value ) && (bool) preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value );
	}

	/**
	 * Create a random, non-secret public reference.
	 *
	 * The reference appears in public URLs and forms, so it must not be
	 * guessable and must not be reused for another row.
	 *
	 * @param string $table_key `events` or `rides`.
	 * @return string
	 */
	public function create_public_reference( $table_key ) {
		do {
			$reference = bin2hex( random_bytes( 16 ) );
		} while ( $this->store->reference_exists( $reference, $table_key ) );

		return $reference;
	}

	/**
	 * Get all events that may be offered publicly right now.
	 *
	 * @return FG_Event[]
	 */
	public function get_active_events() {
		$timezone = wp_timezone();
		$today    = current_datetime()->setTimezone( $timezone )->format( 'Y-m-d' );
		$now      = current_datetime()->setTimezone( $timezone )->format( 'H:i:s' );

		$events = $this->store->query_active_events( $today, $now );

		return array_values(
			array_filter(
				$events,
				function ( $event ) {
					// A record without a reference can never be addressed in a
					// form, so it must not appear publicly.
					return '' !== $event->public_ref && self::is_valid_date( $event->event_date );
				}
			)
		);
	}

	/**
	 * Get every event, ordered by date.
	 *
	 * @return FG_Event[]
	 */
	public function get_all_events() {
		return $this->store->query_events( false, 'date' );
	}

	/**
	 * Get a page of events for the admin list.
	 *
	 * @param int $offset Rows to skip.
	 * @param int $limit  Maximum rows.
	 * @return FG_Event[]
	 */
	public function get_events_page( $offset, $limit ) {
		return $this->store->query_events( false, 'date', (int) $limit, (int) $offset );
	}

	/**
	 * Count all events.
	 *
	 * @return int
	 */
	public function count_events() {
		return $this->store->count_events();
	}

	/**
	 * Get an event by its internal ID.
	 *
	 * @param int $event_id Event ID.
	 * @return FG_Event|null
	 */
	public function get_event( $event_id ) {
		$event_id = absint( $event_id );

		return $event_id ? $this->store->find_event( $event_id ) : null;
	}

	/**
	 * Get an event by its public reference.
	 *
	 * @param string $reference Public reference.
	 * @return FG_Event|null
	 */
	public function get_event_by_reference( $reference ) {
		$reference = sanitize_key( $reference );

		return '' === $reference ? null : $this->store->find_event_by_ref( $reference );
	}

	/**
	 * Store a new event.
	 *
	 * Reference, UUID and creation time are filled in here so no caller can
	 * create an event that is unreachable or has no identity.
	 *
	 * @param array $fields Event fields: title, event_date, event_time, participants, is_active.
	 * @return int New event ID, 0 on failure.
	 */
	public function insert_event( array $fields ) {
		$title     = isset( $fields['title'] ) ? (string) $fields['title'] : '';
		$date      = isset( $fields['event_date'] ) ? (string) $fields['event_date'] : '';
		$time      = isset( $fields['event_time'] ) ? (string) $fields['event_time'] : '';
		$active    = ! empty( $fields['is_active'] );

		if ( '' === $title || ! self::is_valid_date( $date ) || ( '' !== $time && ! self::is_valid_time( $time ) ) ) {
			return 0;
		}

		// An event without a valid date can never be active, so an active flag
		// is never silently dropped: the caller is told to correct the input.
		if ( $active && '' === $date ) {
			return 0;
		}

		return $this->store->insert_event(
			array(
				'title'        => $title,
				'event_date'   => $date,
				'event_time'   => '' === $time ? null : $time . ':00',
				'participants' => $this->participants_to_text( isset( $fields['participants'] ) ? $fields['participants'] : array() ),
				'event_uuid'   => $this->create_uuid(),
				'public_ref'   => $this->create_public_reference( 'events' ),
				'is_active'    => $active ? 1 : 0,
				'created_at'   => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Update an existing event.
	 *
	 * @param int   $event_id Event ID.
	 * @param array $fields   Event fields: title, event_date, event_time, participants, is_active.
	 * @return bool
	 */
	public function update_event( $event_id, array $fields ) {
		$event = $this->get_event( $event_id );
		if ( ! $event ) {
			return false;
		}

		$title = array_key_exists( 'title', $fields ) ? (string) $fields['title'] : $event->title;
		$date  = array_key_exists( 'event_date', $fields ) ? (string) $fields['event_date'] : $event->event_date;
		$time  = array_key_exists( 'event_time', $fields ) ? (string) $fields['event_time'] : $event->event_time;
		$active = array_key_exists( 'is_active', $fields ) ? (bool) $fields['is_active'] : $event->is_active;

		if ( '' === $title || ! self::is_valid_date( $date ) || ( '' !== $time && ! self::is_valid_time( $time ) ) ) {
			return false;
		}

		$data = array(
			'title'      => $title,
			'event_date' => $date,
			'event_time' => '' === $time ? null : $time . ':00',
			'is_active'  => $active ? 1 : 0,
		);

		if ( array_key_exists( 'participants', $fields ) ) {
			$data['participants'] = $this->participants_to_text( $fields['participants'] );
		}

		return $this->store->update_event( $event->id, $data );
	}

	/**
	 * Delete an event together with all of its rides.
	 *
	 * @param int $event_id Event ID.
	 * @return array{deleted: bool, rides: int}
	 */
	public function delete_event( $event_id ) {
		$event = $this->get_event( $event_id );
		if ( ! $event ) {
			return array(
				'deleted' => false,
				'rides'   => 0,
			);
		}

		// Rides go first: without database-level cascade rules the order is the
		// only thing that keeps the tables consistent.
		$rides    = $this->store->delete_rides_for_event( $event->id );
		$deleted  = $this->store->delete_event( $event->id );

		return array(
			'deleted' => $deleted,
			'rides'   => $rides,
		);
	}

	/**
	 * Get the pre-registered participant addresses of an event.
	 *
	 * @param int $event_id Event ID.
	 * @return string[]
	 */
	public function get_event_participants( $event_id ) {
		$event = $this->get_event( $event_id );

		return $event ? $event->participants : array();
	}

	/**
	 * Replace the participant addresses of an event.
	 *
	 * @param int      $event_id Event ID.
	 * @param string[] $emails   Addresses.
	 * @return bool
	 */
	public function set_event_participants( $event_id, array $emails ) {
		$event = $this->get_event( $event_id );
		if ( ! $event ) {
			return false;
		}

		return $this->store->update_event(
			$event->id,
			array( 'participants' => $this->participants_to_text( $emails ) )
		);
	}

	/**
	 * Check whether an e-mail address belongs to a specific event.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $email    E-mail address.
	 * @return bool
	 */
	public function is_event_participant( $event_id, $email ) {
		$email = $this->normalize_email( $email );
		if ( false === $email ) {
			return false;
		}

		return in_array( $email, $this->get_event_participants( $event_id ), true );
	}

	/**
	 * Determine whether an event may be shown publicly right now.
	 *
	 * An optional time is the public cut-off. Without a time the event stays
	 * available until the end of its date.
	 *
	 * @param FG_Event|int $event Event or event ID.
	 * @return bool
	 */
	public function is_event_active( $event ) {
		$event = $this->resolve_event( $event );
		if ( ! $event || ! $event->is_active || ! self::is_valid_date( $event->event_date ) ) {
			return false;
		}

		$timezone = wp_timezone();
		$today    = current_datetime()->setTimezone( $timezone )->format( 'Y-m-d' );
		$date     = $event->event_date;

		if ( $date < $today ) {
			return false;
		}

		if ( $date > $today ) {
			return true;
		}

		if ( '' === $event->event_time ) {
			return true;
		}

		if ( ! self::is_valid_time( $event->event_time ) ) {
			return false;
		}

		$cutoff = DateTimeImmutable::createFromFormat(
			'!Y-m-d H:i',
			$date . ' ' . $event->event_time,
			$timezone
		);

		return $cutoff instanceof DateTimeImmutable
			&& current_datetime()->setTimezone( $timezone ) <= $cutoff;
	}

	/**
	 * Format an event date for display.
	 *
	 * @param FG_Event|int $event Event or event ID.
	 * @return string
	 */
	public function format_event_date( $event ) {
		$event = $this->resolve_event( $event );
		if ( ! $event || ! self::is_valid_date( $event->event_date ) ) {
			return '';
		}

		$local_date = DateTimeImmutable::createFromFormat( '!Y-m-d', $event->event_date, wp_timezone() );
		if ( ! $local_date instanceof DateTimeImmutable ) {
			return '';
		}

		return wp_date( get_option( 'date_format' ), $local_date->getTimestamp(), wp_timezone() );
	}

	/**
	 * Get all published rides of an event, newest first.
	 *
	 * @param int $event_id Event ID.
	 * @return FG_Ride[]
	 */
	public function get_published_rides( $event_id ) {
		$event_id = absint( $event_id );
		if ( ! $event_id ) {
			return array();
		}

		return $this->store->query_rides(
			array(
				'event_id'  => $event_id,
				'status'    => FG_RIDE_STATUS_PUBLISHED,
				'confirmed' => true,
			)
		);
	}

	/**
	 * Get a page of rides for the admin list.
	 *
	 * @param array $args   Filters: event_id, status.
	 * @param int   $offset Rows to skip.
	 * @param int   $limit  Maximum rows.
	 * @return FG_Ride[]
	 */
	public function get_rides_page( array $args, $offset, $limit ) {
		$args['limit']  = (int) $limit;
		$args['offset'] = (int) $offset;

		return $this->store->query_rides( $args );
	}

	/**
	 * Count rides matching the given filters.
	 *
	 * @param array $args Filters: event_id, status.
	 * @return int
	 */
	public function count_rides( array $args = array() ) {
		return $this->store->count_rides( $args );
	}

	/**
	 * Get a ride by its internal ID.
	 *
	 * @param int $ride_id Ride ID.
	 * @return FG_Ride|null
	 */
	public function get_ride( $ride_id ) {
		$ride_id = absint( $ride_id );

		return $ride_id ? $this->store->find_ride( $ride_id ) : null;
	}

	/**
	 * Get a ride by its public reference.
	 *
	 * @param string $reference Public reference.
	 * @return FG_Ride|null
	 */
	public function get_ride_by_reference( $reference ) {
		$reference = sanitize_key( $reference );

		return '' === $reference ? null : $this->store->find_ride_by_ref( $reference );
	}

	/**
	 * Store a new ride in the pending state and return its tokens.
	 *
	 * A public submission never becomes visible on its own. It starts as
	 * pending with two one-time tokens: one to confirm, one to discard. The
	 * raw tokens are returned once so the caller can put them into the
	 * confirmation mail; only their hashes are stored.
	 *
	 * @param array $fields Ride fields: event_id, mode, alias, origin, contact_email, source_url.
	 * @return array{id: int, confirm_token: string, discard_token: string}
	 */
	public function create_pending_ride( array $fields ) {
		$failed = array(
			'id'            => 0,
			'confirm_token' => '',
			'discard_token' => '',
		);

		$event_id = isset( $fields['event_id'] ) ? absint( $fields['event_id'] ) : 0;
		$mode     = isset( $fields['mode'] ) ? (string) $fields['mode'] : '';
		$alias    = isset( $fields['alias'] ) ? (string) $fields['alias'] : '';
		$origin   = isset( $fields['origin'] ) ? (string) $fields['origin'] : '';
		$email    = $this->normalize_email( isset( $fields['contact_email'] ) ? $fields['contact_email'] : '' );
		$now      = current_time( 'mysql' );

		if (
			! $event_id
			|| ! in_array( $mode, array( FG_RIDE_MODE_OFFER, FG_RIDE_MODE_SEARCH ), true )
			|| '' === trim( $alias )
			|| '' === trim( $origin )
			|| false === $email
		) {
			return $failed;
		}

		$confirm_token = FG_Security::create_token();
		$discard_token = FG_Security::create_token();
		$expires       = time() + FG_PENDING_TOKEN_TTL;

		$ride_id = $this->store->insert_ride(
			array(
				'event_id'                => $event_id,
				'status'                  => FG_RIDE_STATUS_PENDING,
				'mode'                    => $mode,
				'alias'                   => $alias,
				'origin'                  => $origin,
				'contact_email'           => (string) $email,
				'public_ref'              => $this->create_public_reference( 'rides' ),
				'confirmed_at'            => null,
				'consent_version'         => FG_CONSENT_VERSION,
				'consented_at'            => $now,
				'created_at'              => $now,
				'pending_confirm_hash'    => FG_Security::hash_token( $confirm_token ),
				'pending_confirm_expires' => $expires,
				'pending_discard_hash'    => FG_Security::hash_token( $discard_token ),
				'pending_discard_expires' => $expires,
				'delete_hash'             => '',
				'delete_expires'          => 0,
				'source_url'              => FG_Security::safe_source_url( isset( $fields['source_url'] ) ? $fields['source_url'] : '' ),
			)
		);

		if ( ! $ride_id ) {
			return $failed;
		}

		return array(
			'id'            => $ride_id,
			'confirm_token' => $confirm_token,
			'discard_token' => $discard_token,
		);
	}

	/**
	 * Update selected ride fields.
	 *
	 * @param int   $ride_id Ride ID.
	 * @param array $fields  Column values.
	 * @return bool
	 */
	public function update_ride( $ride_id, array $fields ) {
		$ride = $this->get_ride( $ride_id );
		if ( ! $ride ) {
			return false;
		}

		return $this->store->update_ride( $ride->id, $fields );
	}

	/**
	 * Delete one ride.
	 *
	 * @param int $ride_id Ride ID.
	 * @return bool
	 */
	public function delete_ride( $ride_id ) {
		$ride = $this->get_ride( $ride_id );
		if ( ! $ride ) {
			return false;
		}

		return $this->store->delete_ride( $ride->id );
	}

	/**
	 * Consume a token with a compare-and-swap on its stored hash.
	 *
	 * @param int    $ride_id        Ride ID.
	 * @param string $hash_column    Hash column.
	 * @param string $expires_column Expiry column.
	 * @param string $expected_hash  Hash that must still be stored.
	 * @return bool
	 */
	public function claim_token( $ride_id, $hash_column, $expires_column, $expected_hash ) {
		$ride = $this->get_ride( $ride_id );
		if ( ! $ride ) {
			return false;
		}

		return $this->store->claim_token( $ride->id, $hash_column, $expires_column, $expected_hash );
	}

	/**
	 * Check whether a ride has all data required for public display.
	 *
	 * @param FG_Ride $ride Ride record.
	 * @return bool
	 */
	public function is_valid_public_ride( $ride ) {
		return $ride instanceof FG_Ride
			&& FG_RIDE_STATUS_PUBLISHED === $ride->status
			&& '' !== $ride->public_ref
			&& in_array( $ride->mode, array( FG_RIDE_MODE_OFFER, FG_RIDE_MODE_SEARCH ), true )
			&& '' !== trim( $ride->origin )
			&& false !== $this->normalize_email( $ride->contact_email );
	}

	/**
	 * Count all rides of an event.
	 *
	 * @param int $event_id Event ID.
	 * @return int
	 */
	public function count_event_rides( $event_id ) {
		$event_id = absint( $event_id );

		return $event_id ? $this->store->count_rides( array( 'event_id' => $event_id ) ) : 0;
	}

	/**
	 * Get all ride IDs of an event.
	 *
	 * @param int $event_id Event ID.
	 * @return int[]
	 */
	public function get_event_ride_ids( $event_id ) {
		$event_id = absint( $event_id );

		return $event_id ? $this->store->ride_ids( $event_id ) : array();
	}

	/**
	 * Return public display data for a ride.
	 *
	 * @param FG_Ride $ride Ride record.
	 * @return array<string, mixed>
	 */
	public function get_ride_display_data( FG_Ride $ride ) {
		$event = $this->get_event( $ride->event_id );

		return array(
			'ride'          => $ride,
			'event'         => $event,
			'public_ref'    => $ride->public_ref,
			'mode'          => $ride->mode,
			'origin'        => $ride->origin,
			'contact_email' => $ride->contact_email,
			'event_label'   => $event ? $event->title : '',
			'event_date'    => $event ? $this->format_event_date( $event ) : '',
		);
	}

	/**
	 * Return the safe source URL stored for a ride.
	 *
	 * @param int $ride_id Ride ID.
	 * @return string
	 */
	public function get_ride_source_url( $ride_id ) {
		$ride = $this->get_ride( $ride_id );

		return $ride ? FG_Security::safe_source_url( $ride->source_url ) : '';
	}

	/**
	 * Get the contact addresses of all rides created by one address.
	 *
	 * @param string $email  Contact address.
	 * @param int    $limit  Batch size.
	 * @param int    $offset Offset.
	 * @return FG_Ride[]
	 */
	public function get_rides_by_email( $email, $limit = 10, $offset = 0 ) {
		$email = $this->normalize_email( $email );
		if ( false === $email ) {
			return array();
		}

		return $this->store->rides_by_email( $email, (int) $limit, (int) $offset );
	}

	/**
	 * Count the rides created by one address.
	 *
	 * @param string $email Contact address.
	 * @return int
	 */
	public function count_rides_by_email( $email ) {
		$email = $this->normalize_email( $email );
		if ( false === $email ) {
			return 0;
		}

		return $this->store->count_rides_by_email( $email );
	}

	/**
	 * Find pending rides whose confirmation window has ended.
	 *
	 * @return int[]
	 */
	public function get_expired_pending_ride_ids() {
		return $this->store->expired_pending_ids( time() );
	}

	/**
	 * Find published rides whose deletion link has expired.
	 *
	 * @return int[]
	 */
	public function get_expired_published_token_ride_ids() {
		return $this->store->expired_published_token_ids( time() );
	}

	/**
	 * Accept an event record or an event ID.
	 *
	 * @param FG_Event|int $event Event or event ID.
	 * @return FG_Event|null
	 */
	private function resolve_event( $event ) {
		if ( $event instanceof FG_Event ) {
			return $event;
		}

		return $this->get_event( $event );
	}

	/**
	 * Store a participant list as one address per line.
	 *
	 * @param mixed $emails Addresses as string, list or newline separated text.
	 * @return string
	 */
	private function participants_to_text( $emails ) {
		if ( is_string( $emails ) ) {
			$emails = preg_split( '/[\r\n,;]+/u', $emails );
		}

		$clean = array();
		foreach ( (array) $emails as $email ) {
			$email = $this->normalize_email( $email );
			if ( false !== $email ) {
				$clean[ $email ] = $email;
			}
		}

		return implode( "\n", array_values( $clean ) );
	}

	/**
	 * Create a version 4 UUID.
	 *
	 * @return string
	 */
	private function create_uuid() {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0x0fff ) | 0x4000,
			wp_rand( 0, 0x3fff ) | 0x8000,
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff )
		);
	}
}
