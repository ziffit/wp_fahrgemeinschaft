<?php
/**
 * All database access of the plugin.
 *
 * @package Fahrgemeinschaften
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prepared statements for events and rides.
 *
 * This is the only place in the plugin that writes SQL. Column names come from
 * hard-coded whitelists, every value is bound, and no caller can pass a
 * column name or a fragment of a statement.
 */
final class FG_Store {
	/**
	 * Sort order of a listing by date.
	 *
	 * Within one day the start time decides, because that is the order in which
	 * the duties happen. A duty without a time has no place in the order of the
	 * day, so it comes last instead of first: `event_time IS NULL` is 0 for a
	 * time that is there and 1 for one that is missing.
	 *
	 * @var string
	 */
	const SORT_DATE = 'event_date ASC, (event_time IS NULL) ASC, event_time ASC, title ASC';

	/**
	 * Writable event columns.
	 *
	 * @var string[]
	 */
	private static $event_columns = array(
		'title',
		'event_date',
		'event_time',
		'participants',
		'event_uuid',
		'public_ref',
		'is_active',
		'created_at',
		'group_name',
		'demand',
		'duration_hours',
		'description',
	);

	/**
	 * Writable ride columns.
	 *
	 * @var string[]
	 */
	private static $ride_columns = array(
		'event_id',
		'status',
		'mode',
		'alias',
		'origin',
		'contact_email',
		'public_ref',
		'confirmed_at',
		'consent_version',
		'consented_at',
		'created_at',
		'pending_confirm_hash',
		'pending_confirm_expires',
		'pending_discard_hash',
		'pending_discard_expires',
		'delete_hash',
		'delete_expires',
		'source_url',
	);

	/**
	 * Database handle.
	 *
	 * @var wpdb
	 */
	private $db;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;

		$this->db = $wpdb;
	}

	/**
	 * Insert an event and return its ID.
	 *
	 * @param array $fields Column values.
	 * @return int
	 */
	public function insert_event( array $fields ) {
		$data   = $this->filter( $fields, self::$event_columns );
		$result = $this->db->insert( FG_Schema::events_table(), $data );

		return $result ? (int) $this->db->insert_id : 0;
	}

	/**
	 * Update selected event columns.
	 *
	 * @param int   $id     Event ID.
	 * @param array $fields Column values.
	 * @return bool
	 */
	public function update_event( $id, array $fields ) {
		$data = $this->filter( $fields, self::$event_columns );
		if ( empty( $data ) ) {
			return false;
		}

		return false !== $this->db->update(
			FG_Schema::events_table(),
			$data,
			array( 'id' => (int) $id ),
			$this->formats( array_keys( $data ) ),
			array( '%d' )
		);
	}

	/**
	 * Read one event.
	 *
	 * @param int $id Event ID.
	 * @return FG_Event|null
	 */
	public function find_event( $id ) {
		$table = FG_Schema::events_table();
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ),
			ARRAY_A
		);

		return $row ? $this->to_event( $row ) : null;
	}

	/**
	 * Read one event by its public reference.
	 *
	 * @param string $reference Public reference.
	 * @return FG_Event|null
	 */
	public function find_event_by_ref( $reference ) {
		$table = FG_Schema::events_table();
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM $table WHERE public_ref = %s", (string) $reference ),
			ARRAY_A
		);

		return $row ? $this->to_event( $row ) : null;
	}

	/**
	 * Read events ordered by date or title.
	 *
	 * @param bool   $active_only Restrict to publicly offered events.
	 * @param string $order       `date` or `title`.
	 * @param int    $limit       Maximum rows, 0 for all.
	 * @param int    $offset      Rows to skip.
	 * @return FG_Event[]
	 */
	public function query_events( $active_only, $order = 'date', $limit = 0, $offset = 0 ) {
		$table  = FG_Schema::events_table();
		$where  = $active_only ? 'WHERE is_active = 1' : '';
		$sort   = 'title' === $order ? 'title ASC, event_date ASC' : self::SORT_DATE;
		$clause = '';

		if ( $limit > 0 ) {
			$clause = $this->db->prepare( 'LIMIT %d OFFSET %d', (int) $limit, max( 0, (int) $offset ) );
		}

		$sql  = "SELECT * FROM $table $where ORDER BY $sort $clause";
		$rows = $this->db->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'to_event' ), (array) $rows );
	}

	/**
	 * Read the events that may be offered publicly right now.
	 *
	 * The date and the time are computed in PHP from the site timezone and
	 * passed in as bound values, so the comparison itself stays free of any
	 * timezone knowledge. An event without a time counts until the end of its
	 * date.
	 *
	 * @param string $today Current site date as `Y-m-d`.
	 * @param string $now   Current site time as `H:i:s`.
	 * @return FG_Event[]
	 */
	public function query_active_events( $today, $now ) {
		$table = FG_Schema::events_table();
		$sql   = $this->db->prepare(
			"SELECT * FROM $table
			WHERE is_active = 1
			AND ( event_date > %s OR ( event_date = %s AND ( event_time IS NULL OR event_time >= %s ) ) )
			ORDER BY " . self::SORT_DATE,
			(string) $today,
			(string) $today,
			(string) $now
		);

		$rows = $this->db->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'to_event' ), (array) $rows );
	}

	/**
	 * Count all events.
	 *
	 * @return int
	 */
	public function count_events() {
		$table = FG_Schema::events_table();

		$sql = "SELECT COUNT(*) FROM $table";

		return (int) $this->db->get_var( $sql ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Delete an event row. Rides are removed by the caller.
	 *
	 * @param int $id Event ID.
	 * @return bool
	 */
	public function delete_event( $id ) {
		return (bool) $this->db->delete( FG_Schema::events_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Check whether a public reference is already taken.
	 *
	 * @param string $reference  Public reference.
	 * @param string $table_key  `events` or `rides`.
	 * @return bool
	 */
	public function reference_exists( $reference, $table_key ) {
		$table = 'rides' === $table_key ? FG_Schema::rides_table() : FG_Schema::events_table();
		$found = $this->db->get_var(
			$this->db->prepare( "SELECT COUNT(*) FROM $table WHERE public_ref = %s", (string) $reference )
		);

		return (int) $found > 0;
	}

	/**
	 * Insert a ride and return its ID.
	 *
	 * @param array $fields Column values.
	 * @return int
	 */
	public function insert_ride( array $fields ) {
		$data   = $this->filter( $fields, self::$ride_columns );
		$result = $this->db->insert( FG_Schema::rides_table(), $data );

		return $result ? (int) $this->db->insert_id : 0;
	}

	/**
	 * Update selected ride columns.
	 *
	 * @param int   $id     Ride ID.
	 * @param array $fields Column values.
	 * @return bool
	 */
	public function update_ride( $id, array $fields ) {
		$data = $this->filter( $fields, self::$ride_columns );
		if ( empty( $data ) ) {
			return false;
		}

		return false !== $this->db->update(
			FG_Schema::rides_table(),
			$data,
			array( 'id' => (int) $id ),
			$this->formats( array_keys( $data ) ),
			array( '%d' )
		);
	}

	/**
	 * Read one ride.
	 *
	 * @param int $id Ride ID.
	 * @return FG_Ride|null
	 */
	public function find_ride( $id ) {
		$table = FG_Schema::rides_table();
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ),
			ARRAY_A
		);

		return $row ? $this->to_ride( $row ) : null;
	}

	/**
	 * Read one ride by its public reference.
	 *
	 * @param string $reference Public reference.
	 * @return FG_Ride|null
	 */
	public function find_ride_by_ref( $reference ) {
		$table = FG_Schema::rides_table();
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM $table WHERE public_ref = %s", (string) $reference ),
			ARRAY_A
		);

		return $row ? $this->to_ride( $row ) : null;
	}

	/**
	 * Read rides, newest first.
	 *
	 * @param array $args Query arguments: event_id, status, confirmed, limit, offset.
	 * @return FG_Ride[]
	 */
	public function query_rides( array $args = array() ) {
		$where = $this->ride_where( $args );
		$table = FG_Schema::rides_table();
		$clause = '';

		if ( isset( $args['limit'] ) && (int) $args['limit'] > 0 ) {
			$clause = $this->db->prepare(
				'LIMIT %d OFFSET %d',
				(int) $args['limit'],
				max( 0, isset( $args['offset'] ) ? (int) $args['offset'] : 0 )
			);
		}

		$rows = $this->db->get_results(
			"SELECT * FROM $table $where ORDER BY created_at DESC, id DESC $clause",
			ARRAY_A
		);

		return array_map( array( $this, 'to_ride' ), (array) $rows );
	}

	/**
	 * Count rides matching the same filters as query_rides().
	 *
	 * @param array $args Query arguments.
	 * @return int
	 */
	public function count_rides( array $args = array() ) {
		$where = $this->ride_where( $args );
		$table = FG_Schema::rides_table();

		$sql = "SELECT COUNT(*) FROM $table $where";

		return (int) $this->db->get_var( $sql ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Delete one ride.
	 *
	 * @param int $id Ride ID.
	 * @return bool
	 */
	public function delete_ride( $id ) {
		return (bool) $this->db->delete( FG_Schema::rides_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Read the IDs of all rides of an event.
	 *
	 * @param int $event_id Event ID.
	 * @return int[]
	 */
	public function ride_ids( $event_id ) {
		$table = FG_Schema::rides_table();
		$ids   = $this->db->get_col(
			$this->db->prepare( "SELECT id FROM $table WHERE event_id = %d", (int) $event_id )
		);

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Delete all rides of an event.
	 *
	 * @param int $event_id Event ID.
	 * @return int Number of removed rows.
	 */
	public function delete_rides_for_event( $event_id ) {
		return (int) $this->db->delete(
			FG_Schema::rides_table(),
			array( 'event_id' => (int) $event_id ),
			array( '%d' )
		);
	}

	/**
	 * Consume a token with a compare-and-swap on its stored hash.
	 *
	 * The statement only matches while the hash is still the expected one, so a
	 * link that is opened twice is processed once even under parallel requests.
	 * The caller re-reads the row afterwards to detect a competing request.
	 *
	 * @param int    $ride_id       Ride ID.
	 * @param string $hash_column   Hash column.
	 * @param string $expires_column Expiry column.
	 * @param string $expected_hash Stored hash that must still match.
	 * @return bool
	 */
	public function claim_token( $ride_id, $hash_column, $expires_column, $expected_hash ) {
		if ( ! in_array( $hash_column, array( 'pending_confirm_hash', 'pending_discard_hash', 'delete_hash' ), true )
			|| ! in_array( $expires_column, array( 'pending_confirm_expires', 'pending_discard_expires', 'delete_expires' ), true ) ) {
			return false;
		}

		$table  = FG_Schema::rides_table();
		$result = $this->db->query(
			$this->db->prepare(
				"UPDATE $table SET $hash_column = '', $expires_column = 0 WHERE id = %d AND $hash_column = %s",
				(int) $ride_id,
				(string) $expected_hash
			)
		);

		return 1 === (int) $result;
	}

	/**
	 * Find pending rides whose confirmation window has ended.
	 *
	 * @param int $now Unix timestamp.
	 * @return int[]
	 */
	public function expired_pending_ids( $now ) {
		$table = FG_Schema::rides_table();
		$ids   = $this->db->get_col(
			$this->db->prepare(
				"SELECT id FROM $table WHERE status = %s AND pending_confirm_expires > 0 AND pending_confirm_expires < %d",
				FG_RIDE_STATUS_PENDING,
				(int) $now
			)
		);

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Find published rides whose deletion link has expired.
	 *
	 * @param int $now Unix timestamp.
	 * @return int[]
	 */
	public function expired_published_token_ids( $now ) {
		$table = FG_Schema::rides_table();
		$ids   = $this->db->get_col(
			$this->db->prepare(
				"SELECT id FROM $table WHERE status = %s AND delete_expires > 0 AND delete_expires < %d",
				FG_RIDE_STATUS_PUBLISHED,
				(int) $now
			)
		);

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Read rides created by one contact address, for the privacy tools.
	 *
	 * @param string $email  Contact address.
	 * @param int    $limit  Batch size.
	 * @param int    $offset Offset.
	 * @return FG_Ride[]
	 */
	public function rides_by_email( $email, $limit, $offset ) {
		$table = FG_Schema::rides_table();
		$clause = $this->db->prepare(
			'LIMIT %d OFFSET %d',
			max( 1, (int) $limit ),
			max( 0, (int) $offset )
		);
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM $table WHERE contact_email = %s ORDER BY id ASC $clause",
				(string) $email
			),
			ARRAY_A
		);

		return array_map( array( $this, 'to_ride' ), (array) $rows );
	}

	/**
	 * Count rides of one contact address.
	 *
	 * @param string $email Contact address.
	 * @return int
	 */
	public function count_rides_by_email( $email ) {
		$table = FG_Schema::rides_table();

		return (int) $this->db->get_var(
			$this->db->prepare( "SELECT COUNT(*) FROM $table WHERE contact_email = %s", (string) $email )
		);
	}

	/**
	 * Build the shared WHERE clause for ride queries.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	private function ride_where( array $args ) {
		$parts = array();

		if ( ! empty( $args['event_id'] ) ) {
			$parts[] = $this->db->prepare( 'event_id = %d', (int) $args['event_id'] );
		}

		if ( ! empty( $args['status'] ) && in_array( $args['status'], array( FG_RIDE_STATUS_PENDING, FG_RIDE_STATUS_PUBLISHED ), true ) ) {
			$parts[] = $this->db->prepare( 'status = %s', (string) $args['status'] );
		}

		if ( isset( $args['confirmed'] ) && true === $args['confirmed'] ) {
			$parts[] = 'confirmed_at IS NOT NULL';
		}

		return $parts ? 'WHERE ' . implode( ' AND ', $parts ) : '';
	}

	/**
	 * Keep only known columns.
	 *
	 * @param array    $fields   Supplied values.
	 * @param string[] $columns  Allowed columns.
	 * @return array
	 */
	private function filter( array $fields, array $columns ) {
		$data = array();

		foreach ( $columns as $column ) {
			if ( array_key_exists( $column, $fields ) ) {
				$data[ $column ] = $fields[ $column ];
			}
		}

		return $data;
	}

	/**
	 * Build a format array matching the given column names.
	 *
	 * @param string[] $columns Column names.
	 * @return string[]
	 */
	private function formats( array $columns ) {
		$formats = array();

		foreach ( $columns as $column ) {
			switch ( $column ) {
				case 'id':
				case 'event_id':
				case 'is_active':
				case 'demand':
				case 'duration_hours':
				case 'pending_confirm_expires':
				case 'pending_discard_expires':
				case 'delete_expires':
					$formats[] = '%d';
					break;
				default:
					$formats[] = '%s';
					break;
			}
		}

		return $formats;
	}

	/**
	 * Convert a database row into an event record.
	 *
	 * @param array $row Raw row.
	 * @return FG_Event
	 */
	private function to_event( array $row ) {
		$event            = new FG_Event();
		$event->id        = (int) $row['id'];
		$event->title     = (string) $row['title'];
		$event->event_date = (string) $row['event_date'];
		$event->event_time = $this->normalize_time( isset( $row['event_time'] ) ? $row['event_time'] : '' );
		$event->participants = $this->split_participants( isset( $row['participants'] ) ? $row['participants'] : '' );
		$event->event_uuid = (string) $row['event_uuid'];
		$event->public_ref = (string) $row['public_ref'];
		$event->is_active  = (bool) (int) $row['is_active'];
		$event->created_at = (string) $row['created_at'];
		$event->group_name = (string) $row['group_name'];
		$event->demand     = (int) $row['demand'];
		$event->duration_hours = (int) $row['duration_hours'];
		$event->description = (string) $row['description'];

		return $event;
	}

	/**
	 * Convert a database row into a ride record.
	 *
	 * @param array $row Raw row.
	 * @return FG_Ride
	 */
	private function to_ride( array $row ) {
		$ride                    = new FG_Ride();
		$ride->id                = (int) $row['id'];
		$ride->event_id          = (int) $row['event_id'];
		$ride->status            = (string) $row['status'];
		$ride->mode              = (string) $row['mode'];
		$ride->alias             = (string) $row['alias'];
		$ride->origin            = (string) $row['origin'];
		$ride->contact_email     = (string) $row['contact_email'];
		$ride->public_ref        = (string) $row['public_ref'];
		$ride->confirmed_at      = null === $row['confirmed_at'] ? '' : (string) $row['confirmed_at'];
		$ride->consent_version   = (string) $row['consent_version'];
		$ride->consented_at      = (string) $row['consented_at'];
		$ride->created_at        = (string) $row['created_at'];
		$ride->pending_confirm_hash    = (string) $row['pending_confirm_hash'];
		$ride->pending_confirm_expires = (int) $row['pending_confirm_expires'];
		$ride->pending_discard_hash    = (string) $row['pending_discard_hash'];
		$ride->pending_discard_expires = (int) $row['pending_discard_expires'];
		$ride->delete_hash             = (string) $row['delete_hash'];
		$ride->delete_expires          = (int) $row['delete_expires'];
		$ride->source_url              = (string) $row['source_url'];

		return $ride;
	}

	/**
	 * Reduce a TIME column to `H:i` and reject malformed values.
	 *
	 * @param mixed $value Raw column value.
	 * @return string
	 */
	private function normalize_time( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		$time = substr( trim( $value ), 0, 5 );

		return preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '';
	}

	/**
	 * Split a stored participant list into normalized addresses.
	 *
	 * @param mixed $value Raw column value.
	 * @return string[]
	 */
	private function split_participants( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		$emails = array();
		foreach ( (array) preg_split( '/[\r\n,;]+/u', $value ) as $part ) {
			$email = sanitize_email( trim( (string) $part ) );
			$email = strtolower( (string) $email );

			if ( false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$emails[ $email ] = $email;
			}
		}

		return array_values( $emails );
	}
}
