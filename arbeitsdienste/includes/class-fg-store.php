<?php
/**
 * All database access of the plugin.
 *
 * @package Arbeitsdienste
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
	 * `participants` is not in the list any more. The hand-typed list of e-mail
	 * addresses it stood for was replaced by the registrations of the members
	 * themselves, and the column is emptied by the migration. Leaving it out here
	 * means no code path can write it again.
	 *
	 * @var string[]
	 */
	private static $event_columns = array(
		'title',
		'event_date',
		'event_time',
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
	 * Writable member columns.
	 *
	 * @var string[]
	 */
	private static $member_columns = array(
		'member_no',
		'email',
		'first_name',
		'last_name',
		'created_at',
		'updated_at',
	);

	/**
	 * Writable registration columns.
	 *
	 * @var string[]
	 */
	private static $event_member_columns = array(
		'event_id',
		'member_id',
		'registered_at',
		'unregister_hash',
		'unregister_expires',
		'public_ref',
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
	 * @param string $table_key  `events`, `rides` or `event_members`.
	 * @return bool
	 */
	public function reference_exists( $reference, $table_key ) {
		$tables = array(
			'events'       => FG_Schema::events_table(),
			'rides'        => FG_Schema::rides_table(),
			'event_members' => FG_Schema::event_members_table(),
		);

		if ( ! isset( $tables[ $table_key ] ) ) {
			return false;
		}

		$table = $tables[ $table_key ];
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
	 * Count the rides of several work services at once.
	 *
	 * The duty overview prints one row per duty and every row has a ride count.
	 * Counting them one duty at a time would send a query per row.
	 *
	 * @param int[] $event_ids Event IDs.
	 * @return array<int, int> Ride count per event ID, absent for zero.
	 */
	public function count_rides_for_events( array $event_ids ) {
		$event_ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );

		if ( empty( $event_ids ) ) {
			return array();
		}

		$table   = FG_Schema::rides_table();
		$columns = implode( ', ', array_fill( 0, count( $event_ids ), '%d' ) );
		$rows    = $this->db->get_results(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the placeholder list is built from a count, not from input.
				"SELECT event_id, COUNT(*) AS anzahl FROM $table WHERE event_id IN ($columns) GROUP BY event_id",
				$event_ids
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['event_id'] ] = (int) $row['anzahl'];
		}

		return $counts;
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
	 * Insert a member and return its ID.
	 *
	 * @param array $fields Column values.
	 * @return int
	 */
	public function insert_member( array $fields ) {
		$data   = $this->filter( $fields, self::$member_columns );
		$result = $this->db->insert( FG_Schema::members_table(), $data );

		return $result ? (int) $this->db->insert_id : 0;
	}

	/**
	 * Update selected member columns.
	 *
	 * @param int   $id     Member ID.
	 * @param array $fields Column values.
	 * @return bool
	 */
	public function update_member( $id, array $fields ) {
		$data = $this->filter( $fields, self::$member_columns );
		if ( empty( $data ) ) {
			return false;
		}

		return false !== $this->db->update(
			FG_Schema::members_table(),
			$data,
			array( 'id' => (int) $id ),
			$this->formats( array_keys( $data ) ),
			array( '%d' )
		);
	}

	/**
	 * Read one member.
	 *
	 * @param int $id Member ID.
	 * @return FG_Member|null
	 */
	public function find_member( $id ) {
		$table = FG_Schema::members_table();
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ),
			ARRAY_A
		);

		return $row ? $this->to_member( $row ) : null;
	}

	/**
	 * Read one member by member number.
	 *
	 * The comparison follows the collation of the column, so it does not depend on
	 * the case a number was typed in.
	 *
	 * @param string $member_no Member number.
	 * @return FG_Member|null
	 */
	public function find_member_by_no( $member_no ) {
		$table = FG_Schema::members_table();
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM $table WHERE member_no = %s", (string) $member_no ),
			ARRAY_A
		);

		return $row ? $this->to_member( $row ) : null;
	}

	/**
	 * Read one member by e-mail address.
	 *
	 * @param string $email E-mail address.
	 * @return FG_Member|null
	 */
	public function find_member_by_email( $email ) {
		$table = FG_Schema::members_table();
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM $table WHERE email = %s", (string) $email ),
			ARRAY_A
		);

		return $row ? $this->to_member( $row ) : null;
	}

	/**
	 * Read all members, ordered by member number.
	 *
	 * The order is the one a member list is read in, and it puts a purely numeric
	 * roster in the order people expect.
	 *
	 * @param string $search Free text to look for, empty for all.
	 * @param int    $limit  Maximum rows, 0 for all.
	 * @param int    $offset Rows to skip.
	 * @return FG_Member[]
	 */
	public function query_members( $search = '', $limit = 0, $offset = 0 ) {
		$table  = FG_Schema::members_table();
		$where  = '';
		$clause = '';

		if ( '' !== trim( (string) $search ) ) {
			$like  = '%' . $this->db->esc_like( trim( (string) $search ) ) . '%';
			$where = $this->db->prepare(
				'WHERE member_no LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s',
				$like,
				$like,
				$like,
				$like
			);
		}

		if ( $limit > 0 ) {
			$clause = $this->db->prepare( 'LIMIT %d OFFSET %d', (int) $limit, max( 0, (int) $offset ) );
		}

		$rows = $this->db->get_results(
			"SELECT * FROM $table $where ORDER BY member_no ASC, id ASC $clause",
			ARRAY_A
		);

		return array_map( array( $this, 'to_member' ), (array) $rows );
	}

	/**
	 * Count members, optionally matching the same text as query_members().
	 *
	 * @param string $search Free text to look for, empty for all.
	 * @return int
	 */
	public function count_members( $search = '' ) {
		$table = FG_Schema::members_table();

		if ( '' === trim( (string) $search ) ) {
			$sql = "SELECT COUNT(*) FROM $table";

			return (int) $this->db->get_var( $sql ); // phpcs:ignore WordPress.DB
		}

		$like = '%' . $this->db->esc_like( trim( (string) $search ) ) . '%';

		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM $table
				WHERE member_no LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s",
				$like,
				$like,
				$like,
				$like
			)
		);
	}

	/**
	 * Delete one member row. Registrations are removed by the caller.
	 *
	 * @param int $id Member ID.
	 * @return bool
	 */
	public function delete_member( $id ) {
		return (bool) $this->db->delete( FG_Schema::members_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Insert a registration and return its ID.
	 *
	 * @param array $fields Column values.
	 * @return int
	 */
	public function insert_event_member( array $fields ) {
		$data   = $this->filter( $fields, self::$event_member_columns );
		$result = $this->db->insert( FG_Schema::event_members_table(), $data );

		return $result ? (int) $this->db->insert_id : 0;
	}

	/**
	 * Update selected registration columns.
	 *
	 * @param int   $id     Registration ID.
	 * @param array $fields Column values.
	 * @return bool
	 */
	public function update_event_member( $id, array $fields ) {
		$data = $this->filter( $fields, self::$event_member_columns );
		if ( empty( $data ) ) {
			return false;
		}

		return false !== $this->db->update(
			FG_Schema::event_members_table(),
			$data,
			array( 'id' => (int) $id ),
			$this->formats( array_keys( $data ) ),
			array( '%d' )
		);
	}

	/**
	 * Read one registration.
	 *
	 * @param int $id Registration ID.
	 * @return FG_Event_Member|null
	 */
	public function find_event_member( $id ) {
		$table = FG_Schema::event_members_table();
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ),
			ARRAY_A
		);

		return $row ? $this->to_event_member( $row ) : null;
	}

	/**
	 * Read one registration by its public reference.
	 *
	 * @param string $reference Public reference.
	 * @return FG_Event_Member|null
	 */
	public function find_event_member_by_ref( $reference ) {
		$table = FG_Schema::event_members_table();
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM $table WHERE public_ref = %s", (string) $reference ),
			ARRAY_A
		);

		return $row ? $this->to_event_member( $row ) : null;
	}

	/**
	 * Read the registration of one member for one work service.
	 *
	 * @param int $event_id  Work service ID.
	 * @param int $member_id Member ID.
	 * @return FG_Event_Member|null
	 */
	public function find_event_member_pair( $event_id, $member_id ) {
		$table = FG_Schema::event_members_table();
		$row   = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM $table WHERE event_id = %d AND member_id = %d",
				(int) $event_id,
				(int) $member_id
			),
			ARRAY_A
		);

		return $row ? $this->to_event_member( $row ) : null;
	}

	/**
	 * Read the registrations of one work service, joined with their members.
	 *
	 * The member columns come along in the same row so the admin list needs no
	 * second query per registration. The join is an inner one on purpose: a
	 * registration without a member row cannot be shown and should not exist,
	 * but if it ever did it must not appear as a line of empty cells.
	 *
	 * @param int $event_id Work service ID.
	 * @return array<int, array{registration: FG_Event_Member, member: FG_Member}>
	 */
	public function query_event_member_rows( $event_id ) {
		$registrations = FG_Schema::event_members_table();
		$members       = FG_Schema::members_table();
		$rows          = $this->db->get_results(
			$this->db->prepare(
				"SELECT r.*, m.member_no, m.email, m.first_name, m.last_name
				FROM $registrations r
				INNER JOIN $members m ON m.id = r.member_id
				WHERE r.event_id = %d
				ORDER BY m.member_no ASC, r.id ASC",
				(int) $event_id
			),
			ARRAY_A
		);

		$result = array();
		foreach ( (array) $rows as $row ) {
			$member = new FG_Member();
			$member->id         = (int) $row['member_id'];
			$member->member_no  = (string) $row['member_no'];
			$member->email      = (string) $row['email'];
			$member->first_name = (string) $row['first_name'];
			$member->last_name  = (string) $row['last_name'];

			$result[] = array(
				'registration' => $this->to_event_member( $row ),
				'member'       => $member,
			);
		}

		return $result;
	}

	/**
	 * Check whether a registered member of an event owns an e-mail address.
	 *
	 * One joined query, because the question needs both tables and the caller asks
	 * it once per submitted form.
	 *
	 * @param int    $event_id Work service ID.
	 * @param string $email    E-mail address.
	 * @return bool
	 */
	public function event_has_participant_email( $event_id, $email ) {
		$registrations = FG_Schema::event_members_table();
		$members       = FG_Schema::members_table();

		$found = $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*)
				FROM $registrations r
				INNER JOIN $members m ON m.id = r.member_id
				WHERE r.event_id = %d AND m.email = %s",
				(int) $event_id,
				(string) $email
			)
		);

		return (int) $found > 0;
	}

	/**
	 * Count the registrations of one work service.
	 *
	 * @param int $event_id Work service ID.
	 * @return int
	 */
	public function count_event_members( $event_id ) {
		$table = FG_Schema::event_members_table();

		return (int) $this->db->get_var(
			$this->db->prepare( "SELECT COUNT(*) FROM $table WHERE event_id = %d", (int) $event_id )
		);
	}

	/**
	 * Count the registrations of several work services at once.
	 *
	 * The public list of work services asks for the number of registrations of
	 * every card it prints. Counting them one card at a time would send a query
	 * per card; this sends one for the whole page.
	 *
	 * @param int[] $event_ids Work service IDs.
	 * @return array<int, int> Registration count per event ID, absent for zero.
	 */
	public function count_event_members_for_events( array $event_ids ) {
		$event_ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );

		if ( empty( $event_ids ) ) {
			return array();
		}

		$table   = FG_Schema::event_members_table();
		$columns = implode( ', ', array_fill( 0, count( $event_ids ), '%d' ) );
		$rows    = $this->db->get_results(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the placeholder list is built from a count, not from input.
				"SELECT event_id, COUNT(*) AS anzahl FROM $table WHERE event_id IN ($columns) GROUP BY event_id",
				$event_ids
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['event_id'] ] = (int) $row['anzahl'];
		}

		return $counts;
	}

	/**
	 * Count how many work services one member is registered for.
	 *
	 * @param int $member_id Member ID.
	 * @return int
	 */
	public function count_member_registrations( $member_id ) {
		$table = FG_Schema::event_members_table();

		return (int) $this->db->get_var(
			$this->db->prepare( "SELECT COUNT(*) FROM $table WHERE member_id = %d", (int) $member_id )
		);
	}

	/**
	 * Count how many work services several members are registered for.
	 *
	 * @param int[] $member_ids Member IDs.
	 * @return array<int, int> Registration count per member ID, absent for zero.
	 */
	public function count_member_registrations_for_members( array $member_ids ) {
		$member_ids = array_values( array_unique( array_filter( array_map( 'absint', $member_ids ) ) ) );

		if ( empty( $member_ids ) ) {
			return array();
		}

		$table   = FG_Schema::event_members_table();
		$columns = implode( ', ', array_fill( 0, count( $member_ids ), '%d' ) );
		$rows    = $this->db->get_results(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the placeholder list is built from a count, not from input.
				"SELECT member_id, COUNT(*) AS anzahl FROM $table WHERE member_id IN ($columns) GROUP BY member_id",
				$member_ids
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['member_id'] ] = (int) $row['anzahl'];
		}

		return $counts;
	}

	/**
	 * Delete one registration.
	 *
	 * @param int $id Registration ID.
	 * @return bool
	 */
	public function delete_event_member( $id ) {
		return (bool) $this->db->delete( FG_Schema::event_members_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Delete a registration only while it still carries the expected token hash.
	 *
	 * This is the compare-and-delete that replaces the claim-and-restore dance
	 * the ride flow needs. A registration is undone by removing the row, so a
	 * second attempt at the same link matches no row and changes nothing. The
	 * check and the removal are one statement, so two parallel requests from the
	 * same mail client cannot both succeed.
	 *
	 * @param int    $id            Registration ID.
	 * @param string $expected_hash Hash that must still be stored.
	 * @return bool True when this call removed the row.
	 */
	public function delete_event_member_with_token( $id, $expected_hash ) {
		$table = FG_Schema::event_members_table();

		$result = $this->db->query(
			$this->db->prepare(
				"DELETE FROM $table WHERE id = %d AND unregister_hash = %s",
				(int) $id,
				(string) $expected_hash
			)
		);

		return 1 === (int) $result;
	}

	/**
	 * Delete all registrations of one member.
	 *
	 * @param int $member_id Member ID.
	 * @return int Number of removed rows.
	 */
	public function delete_event_members_for_member( $member_id ) {
		return (int) $this->db->delete(
			FG_Schema::event_members_table(),
			array( 'member_id' => (int) $member_id ),
			array( '%d' )
		);
	}

	/**
	 * Delete all registrations of one work service.
	 *
	 * @param int $event_id Work service ID.
	 * @return int Number of removed rows.
	 */
	public function delete_event_members_for_event( $event_id ) {
		return (int) $this->db->delete(
			FG_Schema::event_members_table(),
			array( 'event_id' => (int) $event_id ),
			array( '%d' )
		);
	}

	/**
	 * Find registrations whose unregistration link has expired.
	 *
	 * The rows themselves stay: a registration belongs to a work service that is
	 * over, and the count of who did the duty is a fact about the past. What the
	 * expiry ends is the ability to undo it from a mail that sat in an inbox for
	 * a year.
	 *
	 * @param int $now Unix timestamp.
	 * @return int[]
	 */
	public function expired_unregister_ids( $now ) {
		$table = FG_Schema::event_members_table();
		$ids   = $this->db->get_col(
			$this->db->prepare(
				"SELECT id FROM $table WHERE unregister_expires > 0 AND unregister_expires < %d",
				(int) $now
			)
		);

		return array_map( 'absint', (array) $ids );
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
				case 'member_id':
				case 'is_active':
				case 'demand':
				case 'duration_hours':
				case 'pending_confirm_expires':
				case 'pending_discard_expires':
				case 'delete_expires':
				case 'unregister_expires':
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
	 * Convert a database row into a member record.
	 *
	 * @param array $row Raw row.
	 * @return FG_Member
	 */
	private function to_member( array $row ) {
		$member            = new FG_Member();
		$member->id        = (int) $row['id'];
		$member->member_no = (string) $row['member_no'];
		$member->email     = (string) $row['email'];
		$member->first_name = (string) $row['first_name'];
		$member->last_name  = (string) $row['last_name'];
		$member->created_at = (string) $row['created_at'];
		$member->updated_at = (string) $row['updated_at'];

		return $member;
	}

	/**
	 * Convert a database row into a registration record.
	 *
	 * @param array $row Raw row.
	 * @return FG_Event_Member
	 */
	private function to_event_member( array $row ) {
		$registration                   = new FG_Event_Member();
		$registration->id               = (int) $row['id'];
		$registration->event_id         = (int) $row['event_id'];
		$registration->member_id        = (int) $row['member_id'];
		$registration->registered_at    = (string) $row['registered_at'];
		$registration->unregister_hash  = (string) $row['unregister_hash'];
		$registration->unregister_expires = (int) $row['unregister_expires'];
		$registration->public_ref       = (string) $row['public_ref'];
		$registration->source_url       = (string) $row['source_url'];

		return $registration;
	}
}
