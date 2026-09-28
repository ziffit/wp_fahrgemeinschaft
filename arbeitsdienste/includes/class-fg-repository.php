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

		// The length is the width of the address columns, not the 254 characters
		// RFC 5321 allows. An address of 200 characters passes every check below,
		// reaches `fg_members.email` and is refused there by the schema —
		// silently, after the visitor has been told nothing went wrong. The
		// member table is the only one with an address in it since schema 1.4.0,
		// but the empty column `fg_rides.contact_email` is still 190 wide, so the
		// number is the one both columns carry.
		if ( '' === $email || strlen( $email ) > FG_Schema::CONTACT_EMAIL_MAX || ! is_email( $email ) ) {
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
	 * @param string $table_key `events`, `rides` or `event_members`.
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
	 * Get several work services at once, keyed by event ID.
	 *
	 * A list of rides names a work service on every line, and reading one per
	 * line would be one query per line. Same shape as get_members_by_ids().
	 *
	 * @param int[] $event_ids Work service IDs.
	 * @return array<int, FG_Event> Work service per ID, absent for unknown IDs.
	 */
	public function get_events_by_ids( array $event_ids ) {
		return $this->store->find_events( $event_ids );
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
	 * @param array $fields Event fields: title, event_date, event_time, is_active, and optionally group_name, demand, duration_hours, description.
	 * @return int New event ID, 0 on failure.
	 */
	public function insert_event( array $fields ) {
		$title     = isset( $fields['title'] ) ? (string) $fields['title'] : '';
		$date      = isset( $fields['event_date'] ) ? (string) $fields['event_date'] : '';
		$time      = isset( $fields['event_time'] ) ? (string) $fields['event_time'] : '';
		$active    = ! empty( $fields['is_active'] );
		$details   = $this->read_event_details( $fields );

		if ( '' === $title || ! self::is_valid_date( $date ) || ( '' !== $time && ! self::is_valid_time( $time ) ) || null === $details ) {
			return 0;
		}

		// An event without a valid date can never be active, so an active flag
		// is never silently dropped: the caller is told to correct the input.
		if ( $active && '' === $date ) {
			return 0;
		}

		return $this->store->insert_event(
			array_merge(
				$details,
				array(
					'title'        => $title,
					'event_date'   => $date,
					'event_time'   => '' === $time ? null : $time . ':00',
					'event_uuid'   => $this->create_uuid(),
					'public_ref'   => $this->create_public_reference( 'events' ),
					'is_active'    => $active ? 1 : 0,
					'created_at'   => current_time( 'mysql' ),
				)
			)
		);
	}

	/**
	 * Update an existing event.
	 *
	 * @param int   $event_id Event ID.
	 * @param array $fields   Event fields: title, event_date, event_time, is_active, and optionally group_name, demand, duration_hours, description.
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

		// The four optional fields are read the same way for a new and for an
		// existing record, so a value can never pass a check on one path and
		// not on the other. What was not submitted keeps the stored value.
		$details = $this->read_event_details(
			array_merge(
				array(
					'group_name'     => $event->group_name,
					'description'    => $event->description,
					'demand'         => (string) $event->demand,
					'duration_hours' => (string) $event->duration_hours,
				),
				array_intersect_key( $fields, array_flip( array( 'group_name', 'description', 'demand', 'duration_hours' ) ) )
			)
		);

		if ( null === $details ) {
			return false;
		}

		$data = array_merge(
			$details,
			array(
				'title'      => $title,
				'event_date' => $date,
				'event_time' => '' === $time ? null : $time . ':00',
				'is_active'  => $active ? 1 : 0,
			)
		);

		return $this->store->update_event( $event->id, $data );
	}

	/**
	 * Delete an event together with all of its rides and registrations.
	 *
	 * @param int $event_id Event ID.
	 * @return array{deleted: bool, rides: int, registrations: int}
	 */
	public function delete_event( $event_id ) {
		$event = $this->get_event( $event_id );
		if ( ! $event ) {
			return array(
				'deleted'       => false,
				'rides'         => 0,
				'registrations' => 0,
			);
		}

		// The rows that point at the event go first: without database-level
		// cascade rules the order is the only thing that keeps the tables
		// consistent.
		$registrations = $this->store->delete_event_members_for_event( $event->id );
		$rides         = $this->store->delete_rides_for_event( $event->id );
		$deleted       = $this->store->delete_event( $event->id );

		return array(
			'deleted'       => $deleted,
			'rides'         => $rides,
			'registrations' => $registrations,
		);
	}

	/**
	 * Check whether a member is registered for an event.
	 *
	 * This is the gate for the ride form. Only a person who actually does the
	 * work service may organise a car to it. The form asks for the member number
	 * and the address and looks up the member from the pair, so what arrives here
	 * is a member of the club and the question is only whether that member is in
	 * the list of this duty.
	 *
	 * Until schema 1.2.0 this compared against a list of addresses the club typed
	 * into the work service by hand. An address in that list could be anyone's,
	 * and nobody could ride to a duty for which no list had been maintained. The
	 * comparison is the same question, asked of data that is true by construction.
	 *
	 * @param int $event_id  Event ID.
	 * @param int $member_id Member row ID.
	 * @return bool
	 */
	public function is_event_participant( $event_id, $member_id ) {
		$event_id  = absint( $event_id );
		$member_id = absint( $member_id );

		if ( ! $event_id || ! $member_id ) {
			return false;
		}

		return $this->store->event_has_participant( $event_id, $member_id );
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
	 * Format an event date with its weekday, for display.
	 *
	 * The weekday is taken from the translation files of the site and not from
	 * the date function of PHP. `date()` and `DateTime::format()` know no
	 * language and hand out English names unless the process itself was started
	 * with a matching locale, which is not something a plugin can rely on. The
	 * translation files are the dependable source, and they are read here.
	 *
	 * The date behind it keeps the format the site has chosen, so a site with a
	 * different date format does not suddenly get a mixture of two.
	 *
	 * @param FG_Event|int $event Event or event ID.
	 * @return string
	 */
	public function format_event_date_long( $event ) {
		$event = $this->resolve_event( $event );
		if ( ! $event || ! self::is_valid_date( $event->event_date ) ) {
			return '';
		}

		$local_date = DateTimeImmutable::createFromFormat( '!Y-m-d', $event->event_date, wp_timezone() );
		if ( ! $local_date instanceof DateTimeImmutable ) {
			return '';
		}

		global $wp_locale;

		return sprintf(
			/* translators: 1: weekday, 2: date, both in the language of the site. */
			__( '%1$s, den %2$s', 'arbeitsdienste' ),
			$wp_locale->get_weekday( (int) $local_date->format( 'w' ) ),
			wp_date( get_option( 'date_format' ), $local_date->getTimestamp(), wp_timezone() )
		);
	}

	/**
	 * Format the start time of an event, or an empty string when it has none.
	 *
	 * @param FG_Event|int $event Event or event ID.
	 * @return string
	 */
	public function format_event_time( $event ) {
		$event = $this->resolve_event( $event );
		if ( ! $event || ! self::is_valid_time( $event->event_time ) ) {
			return '';
		}

		$local = DateTimeImmutable::createFromFormat(
			'!Y-m-d H:i',
			$event->event_date . ' ' . $event->event_time,
			wp_timezone()
		);

		if ( ! $local instanceof DateTimeImmutable ) {
			return '';
		}

		return wp_date( get_option( 'time_format' ), $local->getTimestamp(), wp_timezone() );
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
	 * @param array $args   Filters: event_id.
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
	 * @param array $args Filters: event_id.
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
	 * Store a new ride and return its deletion token.
	 *
	 * A public submission is published when it is written. There is no state
	 * between the form and the public list that a person has to confirm, and no
	 * second mail: the one mail that goes out carries the deletion link and
	 * serves as the receipt.
	 *
	 * The status, the publication time and the deletion token are written in a
	 * single statement, so there is no moment in which a visible ride exists
	 * without a working self-service deletion link. That was the same reason the
	 * old confirmation step wrote status and token in one go; the guarantee
	 * simply moved to the creation.
	 *
	 * The raw token is returned once so the caller can put it into the mail; only
	 * its hash is stored.
	 *
	 * @param array $fields Ride fields: event_id, mode, member_id, origin, source_url.
	 * @return array{id: int, delete_token: string}
	 */
	public function create_ride( array $fields ) {
		$failed = array(
			'id'           => 0,
			'delete_token' => '',
		);

		$event_id  = isset( $fields['event_id'] ) ? absint( $fields['event_id'] ) : 0;
		$mode      = isset( $fields['mode'] ) ? (string) $fields['mode'] : '';
		$member_id = isset( $fields['member_id'] ) ? absint( $fields['member_id'] ) : 0;
		$origin    = isset( $fields['origin'] ) ? (string) $fields['origin'] : '';
		$now       = current_time( 'mysql' );

		if (
			! $event_id
			|| ! in_array( $mode, array( FG_RIDE_MODE_OFFER, FG_RIDE_MODE_SEARCH ), true )
			|| ! $member_id
			|| '' === trim( $origin )
		) {
			return $failed;
		}

		$delete_token = FG_Security::create_token();

		$ride_id = $this->store->insert_ride(
			array(
				'event_id'        => $event_id,
				'status'          => FG_RIDE_STATUS_PUBLISHED,
				'mode'            => $mode,
				'origin'          => $origin,
				'member_id'       => $member_id,
				'public_ref'      => $this->create_public_reference( 'rides' ),
				'confirmed_at'    => $now,
				'consent_version' => FG_CONSENT_VERSION,
				'consented_at'    => $now,
				'created_at'      => $now,
				'delete_hash'     => FG_Security::hash_token( $delete_token ),
				'delete_expires'  => $this->published_delete_expiry( $event_id ),
				'source_url'      => FG_Security::safe_source_url( isset( $fields['source_url'] ) ? $fields['source_url'] : '' ),
			)
		);

		if ( ! $ride_id ) {
			return $failed;
		}

		return array(
			'id'           => $ride_id,
			'delete_token' => $delete_token,
		);
	}

	/**
	 * Return an expiry at least 30 days after the event display cut-off.
	 *
	 * The deletion link of a published ride stays usable until long after the
	 * work duty is over. Someone who reads the mail a month later must still be
	 * able to take the entry down; a link that dies on the day of the duty would
	 * leave the entry in the list of the next run of the same duty with no way to
	 * remove it.
	 *
	 * @param int $event_id Event ID.
	 * @return int
	 */
	public function published_delete_expiry( $event_id ) {
		$event = $this->get_event( $event_id );
		$now   = time() + DAY_IN_SECONDS;

		if ( ! $event || ! self::is_valid_date( $event->event_date ) ) {
			return $now + FG_PUBLISHED_DELETE_TOKEN_TTL;
		}

		$time      = self::is_valid_time( $event->event_time ) ? $event->event_time : '23:59';
		$zone      = wp_timezone();
		$date_time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $event->event_date . ' ' . $time, $zone );
		if ( ! $date_time instanceof DateTimeImmutable ) {
			return $now + FG_PUBLISHED_DELETE_TOKEN_TTL;
		}

		return max( $now, $date_time->getTimestamp() + FG_PUBLISHED_DELETE_TOKEN_TTL );
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
			&& $ride->member_id > 0;
	}

	/**
	 * Check whether a member may be named and written to in public.
	 *
	 * A ride is public and a member is a person, so the two questions are asked
	 * separately: the row of the ride is checked by is_valid_public_ride(), the
	 * row of the member by this. The caller resolves the members of a whole list
	 * in one query and pairs the two answers, because a lookup per ride would be
	 * a lookup per line of a public page.
	 *
	 * The address is checked even though it is not shown. It is the address a
	 * contact request is sent to, and a member row without a valid one would
	 * leave the two other people of that ride writing to nobody.
	 *
	 * @param FG_Member|null $member Member record, null when the ID is unknown.
	 * @return bool
	 */
	public function is_displayable_member( $member ) {
		return $member instanceof FG_Member
			&& '' !== trim( (string) $member->first_name )
			&& false !== $this->normalize_email( $member->email );
	}

	/**
	 * Resolve the members of several rides at once.
	 *
	 * @param FG_Ride[] $rides Ride records.
	 * @return array<int, FG_Member> Member per ride ID, absent for unknown members.
	 */
	public function get_members_for_rides( array $rides ) {
		$member_ids = array();
		foreach ( $rides as $ride ) {
			if ( $ride instanceof FG_Ride && $ride->member_id > 0 ) {
				$member_ids[] = $ride->member_id;
			}
		}

		return $this->get_members_by_ids( $member_ids );
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
	 * Count the rides of several work services at once.
	 *
	 * @param int[] $event_ids Work service IDs.
	 * @return array<int, int> Ride count per event ID, absent for zero.
	 */
	public function get_ride_counts_for_events( array $event_ids ) {
		return $this->store->count_rides_for_events( $event_ids );
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
	 * The member is passed in rather than looked up, because this is called once
	 * per ride in a list and the list resolves all its members in one query. A
	 * caller with a single ride passes the member it has or leaves it out.
	 *
	 * `first_name` is what the public list shows. It comes from the member
	 * administration, not from the ride: a ride cannot name a person who is not in
	 * the club, and a member who changes their first name does not leave an old
	 * name in a public list behind.
	 *
	 * @param FG_Ride       $ride   Ride record.
	 * @param FG_Member|null $member Member behind the ride, null when unknown.
	 * @return array<string, mixed>
	 */
	public function get_ride_display_data( FG_Ride $ride, $member = null ) {
		$event  = $this->get_event( $ride->event_id );
		$member = $member instanceof FG_Member ? $member : $this->get_member( $ride->member_id );

		return array(
			'ride'        => $ride,
			'event'       => $event,
			'member'      => $member,
			'public_ref'  => $ride->public_ref,
			'mode'        => $ride->mode,
			'origin'      => $ride->origin,
			'first_name'  => $member ? trim( (string) $member->first_name ) : '',
			'event_label' => $event ? $event->title : '',
			'event_date'  => $event ? $this->format_event_date( $event ) : '',
		);
	}

	/**
	 * Return the address a contact request for this ride is sent to.
	 *
	 * The address is read through the member, which is where it lives. It is
	 * never written onto the ride: an address stored there would be a second
	 * copy of the same personal data, and a member who changes their address
	 * would have to remember to change it in two places.
	 *
	 * @param FG_Ride       $ride   Ride record.
	 * @param FG_Member|null $member Member behind the ride, null when unknown.
	 * @return string|false Normalised address, or false when there is none.
	 */
	public function get_ride_contact_email( FG_Ride $ride, $member = null ) {
		$member = $member instanceof FG_Member ? $member : $this->get_member( $ride->member_id );

		return $member ? $this->normalize_email( $member->email ) : false;
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
	 * Get the rides of the members behind one e-mail address.
	 *
	 * The privacy tools hand in an e-mail address, because that is what WordPress
	 * passes them, and the members behind it are looked up here. Until schema
	 * 1.4.0 the rides table held the address itself, so this was a direct
	 * comparison; the lookup is the same question one step further along. Since
	 * schema 1.6.0 there can be more than one member behind the address, and the
	 * question is asked of all of them: a person who writes from a shared mailbox
	 * is answered about what the plugin holds for that mailbox, not about
	 * whichever of the two the database happened to return first.
	 *
	 * @param string $email  Contact address of the members.
	 * @param int    $limit  Batch size.
	 * @param int    $offset Offset.
	 * @return FG_Ride[]
	 */
	public function get_rides_by_email( $email, $limit = 10, $offset = 0 ) {
		$email  = $this->normalize_email( $email );
		$members = false === $email ? array() : $this->get_members_by_email( $email );

		return $this->store->rides_by_members( $this->member_ids( $members ), (int) $limit, (int) $offset );
	}

	/**
	 * Count the rides of the members behind one e-mail address.
	 *
	 * @param string $email Contact address of the members.
	 * @return int
	 */
	public function count_rides_by_email( $email ) {
		$email   = $this->normalize_email( $email );
		$members = false === $email ? array() : $this->get_members_by_email( $email );

		return $this->store->count_rides_by_members( $this->member_ids( $members ) );
	}

	/**
	 * The row IDs of a list of members.
	 *
	 * @param FG_Member[] $members Members.
	 * @return int[]
	 */
	private function member_ids( array $members ) {
		return array_map(
			static function ( FG_Member $member ) {
				return $member->id;
			},
			$members
		);
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
	 * Get a member by internal ID.
	 *
	 * @param int $member_id Member ID.
	 * @return FG_Member|null
	 */
	public function get_member( $member_id ) {
		$member_id = absint( $member_id );

		return $member_id ? $this->store->find_member( $member_id ) : null;
	}

	/**
	 * Get several members at once, keyed by member ID.
	 *
	 * @param int[] $member_ids Member IDs.
	 * @return array<int, FG_Member> Member per ID, absent for unknown IDs.
	 */
	public function get_members_by_ids( array $member_ids ) {
		return $this->store->find_members( $member_ids );
	}

	/**
	 * Get a member by member number.
	 *
	 * @param string $member_no Member number.
	 * @return FG_Member|null
	 */
	public function get_member_by_number( $member_no ) {
		$member_no = $this->read_member_no( $member_no );

		return '' === $member_no ? null : $this->store->find_member_by_no( $member_no );
	}

	/**
	 * The members behind one e-mail address.
	 *
	 * The address is not a key since schema 1.6.0: two members may stand behind
	 * one address, and a reader that returned a single row would decide which of
	 * the two that is without being asked. The list is therefore what this
	 * method returns, and every caller has to say what it does with more than
	 * one member.
	 *
	 * @param string $email E-mail address.
	 * @return FG_Member[] Zero or more members, oldest row first.
	 */
	public function get_members_by_email( $email ) {
		$email = $this->normalize_email( $email );

		return false === $email ? array() : $this->store->find_members_by_email( $email );
	}

	/**
	 * Look up the member a public registration claims to be.
	 *
	 * Both values have to belong to the same member, and that member has to
	 * exist. A number on its own is not enough and an address on its own is not
	 * enough: the two together are what a person knows about themselves, and the
	 * pair is checked against the club's member administration rather than
	 * accepted on its own.
	 *
	 * @param string $member_no Member number.
	 * @param string $email     E-mail address.
	 * @return FG_Member|null The member, or null when the pair does not fit.
	 */
	public function find_member_for_registration( $member_no, $email ) {
		$email = $this->normalize_email( $email );
		$member = $this->get_member_by_number( $member_no );

		if ( false === $email || ! $member || $member->email !== $email ) {
			return null;
		}

		return $member;
	}

	/**
	 * Get a page of members for the admin list.
	 *
	 * @param string $search Free text to look for, empty for all.
	 * @param int    $offset Rows to skip.
	 * @param int    $limit  Maximum rows.
	 * @return FG_Member[]
	 */
	public function get_members_page( $search = '', $offset = 0, $limit = 0 ) {
		return $this->store->query_members( $search, (int) $limit, (int) $offset );
	}

	/**
	 * Count members matching the given text.
	 *
	 * @param string $search Free text to look for, empty for all.
	 * @return int
	 */
	public function count_members( $search = '' ) {
		return $this->store->count_members( $search );
	}

	/**
	 * Count how many work services a set of members is registered for.
	 *
	 * @param FG_Member[] $members Members.
	 * @return array<int, int> Count per member ID.
	 */
	public function get_registration_counts_for_members( array $members ) {
		$ids = array();
		foreach ( $members as $member ) {
			if ( $member instanceof FG_Member ) {
				$ids[] = $member->id;
			}
		}

		return $this->store->count_member_registrations_for_members( $ids );
	}

	/**
	 * Count how many work services one member is registered for.
	 *
	 * @param int $member_id Member ID.
	 * @return int
	 */
	public function count_member_registrations( $member_id ) {
		$member_id = absint( $member_id );

		return $member_id ? $this->store->count_member_registrations( $member_id ) : 0;
	}

	/**
	 * Store a new member.
	 *
	 * A number that is already taken and an address that is already taken are
	 * both refused, and the caller is told which of the two it was. The check is
	 * made here and not left to the unique keys, because a duplicate key would
	 * arrive as a database error that names no field and no row.
	 *
	 * @param array $fields Member fields: member_no, email, first_name, last_name.
	 * @return int Member ID, 0 on failure.
	 */
	public function insert_member( array $fields ) {
		$prepared = $this->read_member_fields( $fields );
		if ( null === $prepared ) {
			return 0;
		}

		// Only the number is checked here. The address may stand at two members,
		// and the database says so as much since schema 1.6.0; a second check in
		// this layer would be a rule the table does not share.
		if ( $this->member_number_taken( $prepared['member_no'] ) ) {
			return 0;
		}

		$now = current_time( 'mysql' );

		return $this->store->insert_member(
			array_merge(
				$prepared,
				array(
					'created_at' => $now,
					'updated_at' => $now,
				)
			)
		);
	}

	/**
	 * Update an existing member.
	 *
	 * All four fields have to be there. A partial set is refused rather than
	 * completed from the stored row, because the one caller that could mean it
	 * is the one that got a field name wrong, and a silent "keep the old value"
	 * would hide that until somebody notices the wrong name in the list.
	 *
	 * The member number may be changed, because the club's member administration
	 * does that too. It is checked against the other rows, so it cannot be moved
	 * onto a number that already belongs to somebody else.
	 *
	 * @param int   $member_id Member ID.
	 * @param array $fields    All four fields: member_no, email, first_name, last_name.
	 * @return bool
	 */
	public function update_member( $member_id, array $fields ) {
		$member = $this->get_member( $member_id );
		if ( ! $member ) {
			return false;
		}

		$prepared = $this->read_member_fields( $fields );
		if ( null === $prepared ) {
			return false;
		}

		// As on insert: the number is the key and the address is not. A member may
		// be given the address of somebody else, and that is a state the club
		// decides about, not a mistake this layer may refuse.
		$by_number = $this->store->find_member_by_no( $prepared['member_no'] );

		if ( $by_number && $by_number->id !== $member->id ) {
			return false;
		}

		if (
			$prepared['member_no'] === $member->member_no
			&& $prepared['email'] === $member->email
			&& $prepared['first_name'] === $member->first_name
			&& $prepared['last_name'] === $member->last_name
		) {
			// Nothing differs, so there is nothing to write. Returning true here
			// keeps the caller from telling the club a change was saved when no
			// column was touched.
			return true;
		}

		$prepared['updated_at'] = current_time( 'mysql' );

		return $this->store->update_member( $member->id, $prepared );
	}

	/**
	 * Delete a member together with all of its registrations.
	 *
	 * Rides are not touched. A ride is its own public entry with its own contact
	 * address and its own history, and it was never owned by the member record; a
	 * member who also offered a car does not lose that record because the member
	 * was deleted. Rides are removed by the privacy tool, which is where a
	 * deletion request belongs.
	 *
	 * @param int $member_id Member ID.
	 * @return array{deleted: bool, registrations: int}
	 */
	public function delete_member( $member_id ) {
		$member = $this->get_member( $member_id );
		if ( ! $member ) {
			return array(
				'deleted'       => false,
				'registrations' => 0,
			);
		}

		$registrations = $this->store->delete_event_members_for_member( $member->id );
		$deleted       = $this->store->delete_member( $member->id );

		return array(
			'deleted'       => $deleted,
			'registrations' => $registrations,
		);
	}

	/**
	 * Count the members that are not registered for any work service.
	 *
	 * @return int
	 */
	public function count_members_without_registration() {
		return $this->store->count_members_without_registration();
	}

	/**
	 * Read the members that are not registered for any work service.
	 *
	 * @return FG_Member[]
	 */
	public function get_members_without_registration() {
		return $this->store->query_members_without_registration();
	}

	/**
	 * Delete every member that is not registered for any work service.
	 *
	 * Rides are kept, like in delete_member(), and so is every member that is
	 * registered for at least one work service. The condition is asked of the
	 * database at the moment of the delete, not taken from a list that was read
	 * for the overview: a registration made after the overview was read keeps
	 * its member.
	 *
	 * @return int Number of deleted members.
	 */
	public function delete_members_without_registration() {
		return $this->store->delete_members_without_registration();
	}

	/**
	 * Read and check the four fields of a member.
	 *
	 * Nothing is trimmed away beyond the surrounding whitespace of a form, and
	 * nothing is repaired: a number that is too long is refused instead of cut
	 * off, because a cut-off member number is a different member to everyone who
	 * looks it up afterwards.
	 *
	 * @param array $fields Supplied fields.
	 * @return array|null Prepared fields, or null when one of them is invalid.
	 */
	private function read_member_fields( array $fields ) {
		$member_no   = $this->read_member_no( isset( $fields['member_no'] ) ? $fields['member_no'] : '' );
		$email       = $this->normalize_email( isset( $fields['email'] ) ? $fields['email'] : '' );
		$first_name  = isset( $fields['first_name'] ) ? trim( (string) $fields['first_name'] ) : '';
		$last_name   = isset( $fields['last_name'] ) ? trim( (string) $fields['last_name'] ) : '';

		if (
			'' === $member_no
			|| false === $email
			|| '' === $first_name
			|| '' === $last_name
			|| $this->string_length( $member_no ) > FG_Schema::MEMBER_NO_MAX
			|| $this->string_length( $first_name ) > FG_Schema::MEMBER_NAME_MAX
			|| $this->string_length( $last_name ) > FG_Schema::MEMBER_NAME_MAX
		) {
			return null;
		}

		return array(
			'member_no'  => $member_no,
			'email'      => (string) $email,
			'first_name' => $first_name,
			'last_name'  => $last_name,
		);
	}

	/**
	 * Read a submitted member number as text.
	 *
	 * The value is never turned into a number. A club number is an identifier,
	 * not a quantity, and a leading zero or a letter is part of it.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	private function read_member_no( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		// Whitespace inside the number is a typing slip, not part of the
		// identifier, and it would keep the club from finding the member again.
		return trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
	}

	/**
	 * Check whether a member number is already in use.
	 *
	 * @param string $member_no Member number.
	 * @return bool
	 */
	private function member_number_taken( $member_no ) {
		return null !== $this->store->find_member_by_no( $member_no );
	}

	/**
	 * Free places of a work service.
	 *
	 * The number is what the club asked for, less what is already registered,
	 * and never below zero: a duty can be oversubscribed, because the check that
	 * refuses the last registration is a race between two visitors and one of
	 * them may get in first. What the public page shows is the truth, so it says
	 * zero and not a negative number.
	 *
	 * A duty without a demand has zero free places. The club states no number
	 * then, and an open registration that cannot be counted against anything is
	 * not something this plugin offers. The page says why in a sentence of its
	 * own, because "ausgebucht" would be a claim about the duty that is not
	 * true.
	 *
	 * @param FG_Event $event         Work service.
	 * @param int      $registrations Registrations already held.
	 * @return int
	 */
	public function free_places( FG_Event $event, $registrations = 0 ) {
		return max( 0, (int) $event->demand - (int) $registrations );
	}

	/**
	 * Determine whether one more member may register for a work service.
	 *
	 * @param FG_Event $event         Work service.
	 * @param int      $registrations Registrations already held.
	 * @return bool
	 */
	public function can_register( FG_Event $event, $registrations = 0 ) {
		return $this->free_places( $event, $registrations ) > 0;
	}

	/**
	 * Count the registrations of several work services at once.
	 *
	 * @param int[] $event_ids Work service IDs.
	 * @return array<int, int>
	 */
	public function get_registration_counts_for_events( array $event_ids ) {
		return $this->store->count_event_members_for_events( $event_ids );
	}

	/**
	 * Count the registrations of one work service.
	 *
	 * @param int $event_id Work service ID.
	 * @return int
	 */
	public function count_event_registrations( $event_id ) {
		$event_id = absint( $event_id );

		return $event_id ? $this->store->count_event_members( $event_id ) : 0;
	}

	/**
	 * Get the registered members of a work service, with their member data.
	 *
	 * @param int $event_id Work service ID.
	 * @return array<int, array{registration: FG_Event_Member, member: FG_Member}>
	 */
	public function get_event_registrations( $event_id ) {
		$event_id = absint( $event_id );

		return $event_id ? $this->store->query_event_member_rows( $event_id ) : array();
	}

	/**
	 * Check whether a member is already registered for a work service.
	 *
	 * @param int $event_id  Work service ID.
	 * @param int $member_id Member ID.
	 * @return bool
	 */
	public function has_registration( $event_id, $member_id ) {
		$event_id  = absint( $event_id );
		$member_id = absint( $member_id );

		if ( ! $event_id || ! $member_id ) {
			return false;
		}

		return null !== $this->store->find_event_member_pair( $event_id, $member_id );
	}

	/**
	 * Register a member for a work service and return the unregistration token.
	 *
	 * The registration is made at once, with no pending state and no second
	 * confirmation. The two values the visitor typed are checked against the
	 * member administration before this is reached, so there is nothing left to
	 * confirm; the e-mail to the member confirms it and carries the way back out.
	 *
	 * The token is returned once so the caller can put it into that e-mail. Only
	 * its hash is stored.
	 *
	 * @param int    $event_id   Work service ID.
	 * @param int    $member_id  Member ID.
	 * @param string $source_url Page the registration was made from.
	 * @return array{id: int, public_ref: string, unregister_token: string}
	 */
	public function create_registration( $event_id, $member_id, $source_url = '' ) {
		$failed = array(
			'id'               => 0,
			'public_ref'       => '',
			'unregister_token' => '',
		);

		$event_id  = absint( $event_id );
		$member_id = absint( $member_id );

		if ( ! $event_id || ! $member_id || $this->has_registration( $event_id, $member_id ) ) {
			return $failed;
		}

		$token = FG_Security::create_token();
		$ref   = $this->create_public_reference( 'event_members' );

		$id = $this->store->insert_event_member(
			array(
				'event_id'           => $event_id,
				'member_id'          => $member_id,
				'registered_at'      => current_time( 'mysql' ),
				'unregister_hash'    => FG_Security::hash_token( $token ),
				'unregister_expires' => $this->unregister_expiry( $event_id ),
				'public_ref'         => $ref,
				'source_url'         => FG_Security::safe_source_url( $source_url ),
			)
		);

		if ( ! $id ) {
			return $failed;
		}

		return array(
			'id'               => $id,
			'public_ref'       => $ref,
			'unregister_token' => $token,
		);
	}

	/**
	 * Get a registration by its public reference.
	 *
	 * @param string $reference Public reference.
	 * @return FG_Event_Member|null
	 */
	public function get_registration_by_reference( $reference ) {
		$reference = sanitize_key( $reference );

		return '' === $reference ? null : $this->store->find_event_member_by_ref( $reference );
	}

	/**
	 * Get a registration by its row ID.
	 *
	 * @param int $registration_id Registration ID.
	 * @return FG_Event_Member|null
	 */
	public function get_registration( $registration_id ) {
		$registration_id = absint( $registration_id );

		return $registration_id ? $this->store->find_event_member( $registration_id ) : null;
	}

	/**
	 * The duty list a registration was made from, for the way back out.
	 *
	 * The unregistration link opens its own page on the site root, so without
	 * this the member would be sent to a front page after removing their entry
	 * and would never learn that it worked. An address that is not on the site
	 * yields the empty string, and the caller falls back to the site root.
	 *
	 * @param FG_Event_Member $registration Registration.
	 * @return string
	 */
	public function get_registration_source_url( FG_Event_Member $registration ) {
		return FG_Security::safe_source_url( $registration->source_url );
	}

	/**
	 * Check whether a token still entitles to undo a registration.
	 *
	 * @param FG_Event_Member $registration Registration.
	 * @param string          $token        Raw token from a request.
	 * @return bool
	 */
	public function valid_unregister_token( FG_Event_Member $registration, $token ) {
		return $registration->unregister_expires >= time()
			&& FG_Security::token_valid( $registration->unregister_hash, $token );
	}

	/**
	 * Remove a registration while the given token is still the stored one.
	 *
	 * @param int    $registration_id Registration ID.
	 * @param string $token          Raw token.
	 * @return bool True when this call removed the row.
	 */
	public function delete_registration_with_token( $registration_id, $token ) {
		$registration = $this->get_registration( $registration_id );
		if ( ! $registration ) {
			return false;
		}

		return $this->store->delete_event_member_with_token(
			$registration->id,
			FG_Security::hash_token( $token )
		);
	}

	/**
	 * Remove a registration without a token, for the backend.
	 *
	 * @param int $registration_id Registration ID.
	 * @return bool
	 */
	public function delete_registration( $registration_id ) {
		$registration = $this->get_registration( $registration_id );
		if ( ! $registration ) {
			return false;
		}

		return $this->store->delete_event_member( $registration->id );
	}

	/**
	 * Remove the registration of one member for one work service.
	 *
	 * Used by the privacy eraser, which knows a person by an e-mail address and
	 * not by a registration ID. The pair is the key of the table, so a second
	 * call finds nothing and returns false instead of removing a second time.
	 *
	 * @param int $event_id  Work service ID.
	 * @param int $member_id Member ID.
	 * @return bool True when this call removed the row.
	 */
	public function delete_registration_by_pair( $event_id, $member_id ) {
		$pair = $this->store->find_event_member_pair( absint( $event_id ), absint( $member_id ) );

		return $pair ? $this->store->delete_event_member( $pair->id ) : false;
	}

	/**
	 * Find registrations whose unregistration link has expired.
	 *
	 * @return int[]
	 */
	public function get_expired_unregister_registration_ids() {
		return $this->store->expired_unregister_ids( time() );
	}

	/**
	 * Drop the expired unregistration token of a registration.
	 *
	 * @param int $registration_id Registration ID.
	 * @return bool
	 */
	public function clear_unregister_token( $registration_id ) {
		$registration_id = absint( $registration_id );

		return $registration_id
			&& $this->store->update_event_member(
				$registration_id,
				array(
					'unregister_hash'    => '',
					'unregister_expires' => 0,
				)
			);
	}

	/**
	 * Expiry of an unregistration token.
	 *
	 * The same rule the published delete link of a ride uses: the link lives
	 * until thirty days after the work service is over, and at least until
	 * tomorrow, so that a member who registers for a duty only a few hours away
	 * does not receive a link that is already dead.
	 *
	 * @param int $event_id Work service ID.
	 * @return int
	 */
	private function unregister_expiry( $event_id ) {
		$event = $this->get_event( $event_id );
		$now   = time() + DAY_IN_SECONDS;

		if ( ! $event || ! self::is_valid_date( $event->event_date ) ) {
			return $now + FG_PUBLISHED_DELETE_TOKEN_TTL;
		}

		$time      = self::is_valid_time( $event->event_time ) ? $event->event_time : '23:59';
		$zone      = wp_timezone();
		$date_time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $event->event_date . ' ' . $time, $zone );
		if ( ! $date_time instanceof DateTimeImmutable ) {
			return $now + FG_PUBLISHED_DELETE_TOKEN_TTL;
		}

		return max( $now, $date_time->getTimestamp() + FG_PUBLISHED_DELETE_TOKEN_TTL );
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
	 * Read and check the four optional fields of a work duty.
	 *
	 * All four are optional, and an empty value or a zero means the club states
	 * nothing. A value that is not a plain count within the accepted range is
	 * not repaired: the whole write is refused instead, because a silent
	 * correction would store something other than what was sent.
	 *
	 * A text that is too long is refused here for a reason of its own. The
	 * column would catch a too-long group on its own, and it does, but it
	 * catches it as a database error that names no field and no limit; the
	 * description lives in a text column that holds a great deal more than the
	 * 500 characters this form allows, so nothing but this check holds it to
	 * the limit. Refusing in the one place keeps one message for both fields
	 * and one number that decides how long they may be.
	 *
	 * @param array $fields Submitted fields; missing keys count as empty.
	 * @return array|null Normalised fields, or null when one of them is invalid.
	 */
	private function read_event_details( array $fields ) {
		$group       = isset( $fields['group_name'] ) ? (string) $fields['group_name'] : '';
		$description = isset( $fields['description'] ) ? (string) $fields['description'] : '';
		$demand      = $this->read_count( isset( $fields['demand'] ) ? $fields['demand'] : '' );
		$duration    = $this->read_count( isset( $fields['duration_hours'] ) ? $fields['duration_hours'] : '' );

		if ( null === $demand || null === $duration ) {
			return null;
		}

		if ( $this->string_length( $group ) > FG_Schema::GROUP_MAX ) {
			return null;
		}

		if ( $this->string_length( $description ) > FG_Schema::DESCRIPTION_MAX ) {
			return null;
		}

		return array(
			'group_name'     => $group,
			'demand'         => $demand,
			'duration_hours' => $duration,
			'description'    => $description,
		);
	}

	/**
	 * Read a submitted number that may be left empty.
	 *
	 * Anything that is not a plain count within the accepted range is refused.
	 * `absint()` would be the shorter answer, but it turns a negative number
	 * and an overflowing digit string into a different number without saying
	 * so, and both columns are unsigned.
	 *
	 * @param mixed $value Submitted value.
	 * @return int|null The number, 0 for an empty field, or null when invalid.
	 */
	private function read_count( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}

		if ( ! preg_match( '/^[0-9]+$/', $value ) ) {
			return null;
		}

		$number = (int) $value;

		return $number > FG_Schema::COUNT_MAX ? null : $number;
	}

	/**
	 * UTF-8-aware string length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function string_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
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
