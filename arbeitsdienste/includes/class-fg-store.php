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
		// At the end of the list, and that is where the columns stand in the
		// schema file too: dbDelta appends a missing column at the end, and a
		// fresh installation and an upgrade have to come out in the same order.
		// A name and an address are two columns and not one, because the address
		// is nothing without the name — see FG_Event::$meeting_point_url.
		'meeting_point',
		'meeting_point_url',
	);

	/**
	 * Writable ride columns.
	 *
	 * The four pending_* columns are missing here on purpose. They are still in
	 * the table, but nothing writes or reads them since 1.15.0, so reading them
	 * into a record would be a claim that this code uses them.
	 *
	 * @var string[]
	 */
	private static $ride_columns = array(
		'event_id',
		'status',
		'mode',
		'origin',
		'member_id',
		'public_ref',
		'confirmed_at',
		'consent_version',
		'consented_at',
		'created_at',
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
		// At the end of the list, and that is where the column stands in the
		// schema file too: dbDelta appends a missing column at the end, and a
		// fresh installation and an upgrade have to come out in the same order.
		'work_group',
	);

	/**
	 * Writable registration columns.
	 *
	 * @var string[]
	 */
	// The two columns unregister_hash and unregister_expires have no writer left
	// since schema 1.7.0: the tokens stand in their own table. They stay in the
	// table of the schema file for the same reason as alias in the rides table —
	// a WordPress rolled back to an older version must not fail on a field it
	// does not know — and here they are simply not written any more.
	private static $event_member_columns = array(
		'event_id',
		'member_id',
		'registered_at',
		'public_ref',
		'source_url',
		'notified_count',
		'added_by_admin',
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
	 * Read several work services at once, keyed by event ID.
	 *
	 * @param int[] $ids Event IDs.
	 * @return array<int, FG_Event> Work service per ID, absent for unknown IDs.
	 */
	public function find_events( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$table   = FG_Schema::events_table();
		$columns = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$rows    = $this->db->get_results(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the placeholder list is built from a count, not from input.
				"SELECT * FROM $table WHERE id IN ($columns)",
				$ids
			),
			ARRAY_A
		);

		$events = array();
		foreach ( (array) $rows as $row ) {
			$events[ (int) $row['id'] ] = $this->to_event( $row );
		}

		return $events;
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
	 * Consume the deletion token with a compare-and-swap on its stored hash.
	 *
	 * The statement only matches while the hash is still the expected one, so a
	 * link that is opened twice is processed once even under parallel requests.
	 * The caller re-reads the row afterwards to detect a competing request.
	 *
	 * The column names are parameters for their own sake: with the pending state
	 * gone, the deletion token is the only token a ride has, and a name that a
	 * caller could pass in would be a name nobody needs any more. The check is
	 * therefore a refusal of everything that is not this one pair.
	 *
	 * @param int    $ride_id        Ride ID.
	 * @param string $hash_column    Hash column.
	 * @param string $expires_column Expiry column.
	 * @param string $expected_hash  Stored hash that must still match.
	 * @return bool
	 */
	public function claim_token( $ride_id, $hash_column, $expires_column, $expected_hash ) {
		if ( 'delete_hash' !== $hash_column || 'delete_expires' !== $expires_column ) {
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
	 * Read rides offered by one member, for the privacy tools.
	 *
	 * @param int $member_id Member row ID.
	 * @param int $limit     Batch size.
	 * @param int $offset    Offset.
	 * @return FG_Ride[]
	 */
	public function rides_by_member( $member_id, $limit, $offset ) {
		$table = FG_Schema::rides_table();
		$clause = $this->db->prepare(
			'LIMIT %d OFFSET %d',
			max( 1, (int) $limit ),
			max( 0, (int) $offset )
		);
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM $table WHERE member_id = %d ORDER BY id ASC $clause",
				(int) $member_id
			),
			ARRAY_A
		);

		return array_map( array( $this, 'to_ride' ), (array) $rows );
	}

	/**
	 * Count the rides of one member.
	 *
	 * @param int $member_id Member row ID.
	 * @return int
	 */
	public function count_rides_by_member( $member_id ) {
		$table = FG_Schema::rides_table();

		return (int) $this->db->get_var(
			$this->db->prepare( "SELECT COUNT(*) FROM $table WHERE member_id = %d", (int) $member_id )
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
	 * Read every member with an e-mail address.
	 *
	 * The address is not a key: it may stand at two members, and the usual case
	 * is a married pair with one mailbox. A single-row reader would return
	 * whichever row the database happened to give first, and every caller of it
	 * would quietly work on one of the two. The order is fixed so that a list of
	 * them is the same list on the next call.
	 *
	 * @param string $email E-mail address.
	 * @return FG_Member[] Zero or more members, oldest row first.
	 */
	public function find_members_by_email( $email ) {
		$table = FG_Schema::members_table();
		$rows  = $this->db->get_results(
			$this->db->prepare( "SELECT * FROM $table WHERE email = %s ORDER BY id ASC", (string) $email ),
			ARRAY_A
		);

		return array_map( array( $this, 'to_member' ), (array) $rows );
	}

	/**
	 * Read the rides of several members at once, oldest first.
	 *
	 * The list of members behind one e-mail address is read in one query and not
	 * one per member: the privacy export and the erasure both walk these rides in
	 * pages, and a query per member would multiply itself by the number of
	 * members that share the address. The pagination happens over the combined
	 * list, so a page is a page and not a page per member.
	 *
	 * @param int[] $member_ids Member row IDs.
	 * @param int   $limit      Batch size.
	 * @param int   $offset     Offset.
	 * @return FG_Ride[]
	 */
	public function rides_by_members( array $member_ids, $limit, $offset ) {
		$table = FG_Schema::rides_table();
		$ids   = array_values( array_unique( array_map( 'intval', $member_ids ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$spalten = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$clause  = $this->db->prepare(
			'LIMIT %d OFFSET %d',
			max( 1, (int) $limit ),
			max( 0, (int) $offset )
		);

		$rows = $this->db->get_results(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the placeholder list is built from a count, not from input.
				"SELECT * FROM $table WHERE member_id IN ($spalten) ORDER BY id ASC $clause",
				$ids
			),
			ARRAY_A
		);

		return array_map( array( $this, 'to_ride' ), (array) $rows );
	}

	/**
	 * Count the rides of several members at once.
	 *
	 * @param int[] $member_ids Member row IDs.
	 * @return int
	 */
	public function count_rides_by_members( array $member_ids ) {
		$table = FG_Schema::rides_table();
		$ids   = array_values( array_unique( array_map( 'intval', $member_ids ) ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		$spalten = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		return (int) $this->db->get_var(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the placeholder list is built from a count, not from input.
				"SELECT COUNT(*) FROM $table WHERE member_id IN ($spalten)",
				$ids
			)
		);
	}

	/**
	 * Read several members at once, keyed by member ID.
	 *
	 * A ride list needs the name behind every entry, and one query per ride would
	 * be one query per line of a public page. The list of IDs is the same shape
	 * the count helper above takes, and returns the same sparse array: an ID that
	 * is not in the table is simply absent, so the caller has to expect that.
	 *
	 * @param int[] $member_ids Member row IDs.
	 * @return array<int, FG_Member> Member per ID, absent for unknown IDs.
	 */
	public function find_members( array $member_ids ) {
		$member_ids = array_values( array_unique( array_filter( array_map( 'absint', $member_ids ) ) ) );

		if ( empty( $member_ids ) ) {
			return array();
		}

		$table   = FG_Schema::members_table();
		$columns = implode( ', ', array_fill( 0, count( $member_ids ), '%d' ) );
		$rows    = $this->db->get_results(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the placeholder list is built from a count, not from input.
				"SELECT * FROM $table WHERE id IN ($columns)",
				$member_ids
			),
			ARRAY_A
		);

		$members = array();
		foreach ( (array) $rows as $row ) {
			$members[ (int) $row['id'] ] = $this->to_member( $row );
		}

		return $members;
	}

	/**
	 * Build the WHERE clause of a member search.
	 *
	 * The search looks for number, first name, last name and address, and it
	 * looks for them **word by word**. One term per field is what a search box
	 * answers and not what a person types: somebody looking for "Kaputt Test"
	 * or "Müller Anton" types two words, and no single field of the member holds
	 * both of them. Every word has to appear somewhere in the row, and then the
	 * row is a hit — which is also what makes "0042 Mül" work.
	 *
	 * The words are combined with AND inside the row and the row is the only
	 * thing that has to match all of them. An OR between the words would find a
	 * member whose first name is "Test" for the term "Kaputt Test", which is
	 * nobody anybody is looking for.
	 *
	 * @param string $search Free text to look for, empty for all.
	 * @return string WHERE clause with its values, or an empty string for all.
	 */
	private function member_search_where( $search ) {
		$term = trim( (string) $search );

		if ( '' === $term ) {
			return '';
		}

		$words = preg_split( '/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY );
		$parts = array();

		foreach ( (array) $words as $wort ) {
			$like     = '%' . $this->db->esc_like( $wort ) . '%';
			$parts[]  = $this->db->prepare(
				'( member_no LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s )',
				$like,
				$like,
				$like,
				$like
			);
		}

		return $parts ? 'WHERE ' . implode( ' AND ', $parts ) : '';
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

		$where = $this->member_search_where( $search );

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

		$where = $this->member_search_where( $search );

		if ( '' === $where ) {
			return (int) $this->db->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB
		}

		return (int) $this->db->get_var( "SELECT COUNT(*) FROM $table $where" ); // phpcs:ignore WordPress.DB
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
	 * Count the members that are not registered for any work service.
	 *
	 * "Not registered" is the absence of a row and nothing else. A duty that has
	 * already taken place still counts as a link, because a member who once did
	 * a work service is not a leftover of the member administration; the club
	 * removes those by hand.
	 *
	 * @return int
	 */
	public function count_members_without_registration() {
		$members = FG_Schema::members_table();
		$links   = FG_Schema::event_members_table();

		// The subquery reads the registrations table and not the member table, so
		// the statement is not the "delete from a table that is also read in the
		// subquery" that MySQL refuses.
		return (int) $this->db->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no input: both names come from FG_Schema.
			"SELECT COUNT(*) FROM $members
			WHERE NOT EXISTS (SELECT 1 FROM $links r WHERE r.member_id = $members.id)"
		);
	}

	/**
	 * Read the members that are not registered for any work service.
	 *
	 * The order is the one of the member list, so the overview of a cleanup and
	 * the list it removes rows from are read in the same order.
	 *
	 * @return FG_Member[]
	 */
	public function query_members_without_registration() {
		$members = FG_Schema::members_table();
		$links   = FG_Schema::event_members_table();

		$rows = $this->db->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no input: both names come from FG_Schema.
			"SELECT * FROM $members
			WHERE NOT EXISTS (SELECT 1 FROM $links r WHERE r.member_id = $members.id)
			ORDER BY member_no ASC, id ASC",
			ARRAY_A
		);

		return array_map( array( $this, 'to_member' ), (array) $rows );
	}

	/**
	 * Delete every member that is not registered for any work service.
	 *
	 * The condition stands in the statement instead of a list of IDs that was
	 * read before it, so a registration made in the second between the overview
	 * and the click keeps its member. There is no second statement for the
	 * registrations: a member that the WHERE clause accepts has none by
	 * definition, and the same clause decides what the overview named.
	 *
	 * @return int Number of removed members.
	 */
	public function delete_members_without_registration() {
		$members = FG_Schema::members_table();
		$links   = FG_Schema::event_members_table();

		return (int) $this->db->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no input: both names come from FG_Schema.
			"DELETE FROM $members
			WHERE NOT EXISTS (SELECT 1 FROM $links r WHERE r.member_id = $members.id)"
		);
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
				"SELECT r.*, m.member_no, m.email, m.first_name, m.last_name, m.work_group
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
			// The member of a joined row is built by hand, and that is not an
			// accident: the row carries both tables, so its `id` belongs to the
			// registration. to_member() would read that as the member's ID and hand
			// out a record that points at the wrong row. The price of the hand is
			// that a member field is not copied over by itself — a field the
			// participant list shows has to be written here as well, and until
			// 1.25.1 the work group was not, so the list showed a stroke where a
			// club that had entered groups was looking for them.
			$member = new FG_Member();
			$member->id         = (int) $row['member_id'];
			$member->member_no  = (string) $row['member_no'];
			$member->email      = (string) $row['email'];
			$member->first_name = (string) $row['first_name'];
			$member->last_name  = (string) $row['last_name'];
			$member->work_group = (string) $row['work_group'];

			$result[] = array(
				'registration' => $this->to_event_member( $row ),
				'member'       => $member,
			);
		}

		return $result;
	}

	/**
	 * Check whether a member is registered for a work service.
	 *
	 * One query, and no join: the registration already names the member by its
	 * row ID. Until schema 1.4.0 this question was asked with an e-mail address
	 * and needed both tables, because a ride carried an address rather than a
	 * member. A member who changes their address keeps their rides and their
	 * registrations this way; with the address as the key, changing it would have
	 * thrown both away.
	 *
	 * @param int $event_id  Work service ID.
	 * @param int $member_id Member row ID.
	 * @return bool
	 */
	public function event_has_participant( $event_id, $member_id ) {
		$registrations = FG_Schema::event_members_table();

		$found = $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM $registrations WHERE event_id = %d AND member_id = %d",
				(int) $event_id,
				(int) $member_id
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
	 * Delete one registration together with its tokens.
	 *
	 * @param int $id Registration ID.
	 * @return bool
	 */
	public function delete_event_member( $id ) {
		$this->delete_event_member_tokens( array( (int) $id ) );

		return (bool) $this->db->delete( FG_Schema::event_members_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Delete a registration after its token has been proven.
	 *
	 * Until schema 1.7.0 this was a compare-and-delete: the statement carried the
	 * hash that had to still be stored, so a second attempt at the same link
	 * matched no row and changed nothing, and two parallel requests from the
	 * same mail client could not both succeed. The compare now happens against
	 * the token table before this call, and what protects the second attempt is
	 * the registration itself: once it is gone there is nothing left to remove,
	 * and the page answers with the same "link not valid" it always did.
	 *
	 * @param int $id Registration ID.
	 * @return bool True when this call removed the row.
	 */
	public function delete_event_member_with_token( $id ) {
		return $this->delete_event_member( $id );
	}

	/**
	 * Delete all registrations of one member together with their tokens.
	 *
	 * @param int $member_id Member ID.
	 * @return int Number of removed registration rows.
	 */
	public function delete_event_members_for_member( $member_id ) {
		$table = FG_Schema::event_members_table();
		$ids   = $this->db->get_col(
			$this->db->prepare( "SELECT id FROM $table WHERE member_id = %d", (int) $member_id )
		);

		$this->delete_event_member_tokens( array_map( 'absint', (array) $ids ) );

		return (int) $this->db->delete( $table, array( 'member_id' => (int) $member_id ), array( '%d' ) );
	}

	/**
	 * Delete all registrations of one work service together with their tokens.
	 *
	 * @param int $event_id Work service ID.
	 * @return int Number of removed registration rows.
	 */
	public function delete_event_members_for_event( $event_id ) {
		$table = FG_Schema::event_members_table();
		$ids   = $this->db->get_col(
			$this->db->prepare( "SELECT id FROM $table WHERE event_id = %d", (int) $event_id )
		);

		$this->delete_event_member_tokens( array_map( 'absint', (array) $ids ) );

		return (int) $this->db->delete( $table, array( 'event_id' => (int) $event_id ), array( '%d' ) );
	}

	/**
	 * Remove the tokens of the given registrations.
	 *
	 * Every path that removes a registration comes through here, so the rule
	 * "a registration without a row has no token" is written once instead of four
	 * times. A token left behind is not a way back into the registration — the
	 * link needs the registration as well — but it is a digest of a secret that
	 * a member once held, and the erasure request of this plugin is answered by
	 * removing what it stored, not by leaving it unreachable.
	 *
	 * @param int[] $registration_ids Registration IDs.
	 * @return int Number of removed token rows.
	 */
	public function delete_event_member_tokens( array $registration_ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $registration_ids ) ) ) );

		if ( ! $ids ) {
			return 0;
		}

		// A list of IDs is written as an IN clause and not handed to
		// $wpdb->delete() as an array: that method takes one value per column,
		// and an array in there does not fail loudly — it turns the condition
		// into `registration_id = 0` and reports nothing deleted. A delete that
		// removes nothing and says so is the kind that survives a review.
		$table     = FG_Schema::event_member_tokens_table();
		$platzhalter = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		return (int) $this->db->query(
			$this->db->prepare(
				"DELETE FROM $table WHERE registration_id IN ( $platzhalter )",
				$ids
			)
		);
	}

	/**
	 * Store one unregistration token of a registration.
	 *
	 * @param int    $registration_id Registration ID.
	 * @param string $token_hash      Hash of the token.
	 * @param int    $expires         Expiry as unix timestamp.
	 * @return int Inserted row ID, or 0.
	 */
	public function insert_event_member_token( $registration_id, $token_hash, $expires ) {
		$result = $this->db->insert(
			FG_Schema::event_member_tokens_table(),
			array(
				'registration_id' => (int) $registration_id,
				'token_hash'      => (string) $token_hash,
				'expires'         => (int) $expires,
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s' )
		);

		return $result ? (int) $this->db->insert_id : 0;
	}

	/**
	 * Look up the token of a registration by its hash.
	 *
	 * @param int    $registration_id Registration ID.
	 * @param string $token_hash      Hash of the token.
	 * @return int Expiry as unix timestamp, or 0 when the token is not stored.
	 */
	public function find_event_member_token_expiry( $registration_id, $token_hash ) {
		$table = FG_Schema::event_member_tokens_table();

		$expires = $this->db->get_var(
			$this->db->prepare(
				"SELECT expires FROM $table WHERE registration_id = %d AND token_hash = %s",
				(int) $registration_id,
				(string) $token_hash
			)
		);

		return null === $expires ? 0 : (int) $expires;
	}

	/**
	 * Raise the notification counter of a registration by one.
	 *
	 * One statement and not a read followed by a write: two notifications in the
	 * same moment would both read the same number, and the second write would
	 * answer for the first one. The counter is the only place where the plugin
	 * counts something per registration, and a count that can lose one is not a
	 * count.
	 *
	 * @param int $id Registration ID.
	 * @return bool
	 */
	public function increment_event_member_notified( $id ) {
		$table = FG_Schema::event_members_table();

		return false !== $this->db->query(
			$this->db->prepare(
				"UPDATE $table SET notified_count = notified_count + 1 WHERE id = %d",
				(int) $id
			)
		);
	}

	/**
	 * Put another expiry on one token of a registration.
	 *
	 * @param int    $registration_id Registration ID.
	 * @param string $token_hash      Hash of the token.
	 * @param int    $expires         Expiry as unix timestamp.
	 * @return bool
	 */
	public function update_event_member_token( $registration_id, $token_hash, $expires ) {
		$table = FG_Schema::event_member_tokens_table();

		return false !== $this->db->query(
			$this->db->prepare(
				"UPDATE $table SET expires = %d WHERE registration_id = %d AND token_hash = %s",
				(int) $expires,
				(int) $registration_id,
				(string) $token_hash
			)
		);
	}

	/**
	 * Remove one token of a registration.
	 *
	 * A mail that was not delivered carries a link nobody received, and a row
	 * that says a link was sent out when it was not is worse than no row. This is
	 * the way back for the one token of that one failed delivery — not for the
	 * tokens of the mails that did go out, which stay.
	 *
	 * @param int    $registration_id Registration ID.
	 * @param string $token_hash      Hash of the token.
	 * @return bool
	 */
	public function delete_event_member_token( $registration_id, $token_hash ) {
		$table = FG_Schema::event_member_tokens_table();

		return false !== $this->db->query(
			$this->db->prepare(
				"DELETE FROM $table WHERE registration_id = %d AND token_hash = %s",
				(int) $registration_id,
				(string) $token_hash
			)
		);
	}

	/**
	 * Count the tokens of a registration.
	 *
	 * @param int $registration_id Registration ID.
	 * @return int
	 */
	public function count_event_member_tokens( $registration_id ) {
		$table = FG_Schema::event_member_tokens_table();

		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM $table WHERE registration_id = %d",
				(int) $registration_id
			)
		);
	}

	/**
	 * Remove every token whose expiry has passed.
	 *
	 * The rows are worthless after that moment and nobody would notice them
	 * again, but they carry a digest of a secret and the table would grow with
	 * every notification for ever. The registrations themselves stay: a duty that
	 * is over is a fact about the past, and who did it belongs to that fact.
	 *
	 * @param int $now Unix timestamp.
	 * @return int Number of removed rows.
	 */
	public function delete_expired_event_member_tokens( $now ) {
		return (int) $this->db->query(
			$this->db->prepare(
				'DELETE FROM ' . FG_Schema::event_member_tokens_table() . ' WHERE expires > 0 AND expires < %d',
				(int) $now
			)
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

		// The status is still a usable condition even though the admin list no
		// longer offers it: the public list asks for published rides explicitly,
		// and a row written by an older version can still carry another value.
		if ( ! empty( $args['status'] ) && in_array( $args['status'], array( FG_RIDE_STATUS_PUBLISHED, 'pending' ), true ) ) {
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
				case 'delete_expires':
				case 'notified_count':
				case 'added_by_admin':
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
		$event->meeting_point = (string) $row['meeting_point'];
		$event->meeting_point_url = (string) $row['meeting_point_url'];

		return $event;
	}

	/**
	 * Convert a database row into a ride record.
	 *
	 * @param array $row Raw row.
	 * @return FG_Ride
	 */
	private function to_ride( array $row ) {
		$ride                  = new FG_Ride();
		$ride->id              = (int) $row['id'];
		$ride->event_id        = (int) $row['event_id'];
		$ride->status          = (string) $row['status'];
		$ride->mode            = (string) $row['mode'];
		$ride->origin          = (string) $row['origin'];
		$ride->member_id       = (int) $row['member_id'];
		$ride->public_ref      = (string) $row['public_ref'];
		$ride->confirmed_at    = null === $row['confirmed_at'] ? '' : (string) $row['confirmed_at'];
		$ride->consent_version = (string) $row['consent_version'];
		$ride->consented_at    = (string) $row['consented_at'];
		$ride->created_at      = (string) $row['created_at'];
		$ride->delete_hash     = (string) $row['delete_hash'];
		$ride->delete_expires  = (int) $row['delete_expires'];
		$ride->source_url      = (string) $row['source_url'];

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
		$member->work_group = isset( $row['work_group'] ) ? (string) $row['work_group'] : '';

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
		$registration->public_ref       = (string) $row['public_ref'];
		$registration->source_url       = (string) $row['source_url'];
		$registration->notified_count   = isset( $row['notified_count'] ) ? (int) $row['notified_count'] : 0;
		$registration->added_by_admin   = ! empty( $row['added_by_admin'] );

		return $registration;
	}
}
