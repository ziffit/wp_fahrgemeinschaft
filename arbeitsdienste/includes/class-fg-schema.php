<?php
/**
 * Database schema of the plugin.
 *
 * @package Arbeitsdienste
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates, verifies and removes the four plugin tables.
 *
 * The tables are the only place where plugin content is stored. They are
 * created with dbDelta on activation and whenever the schema version changes,
 * so an update never needs manual database work.
 */
final class FG_Schema {
	/**
	 * Schema version stored in the options table.
	 *
	 * @var string
	 */
	const VERSION = '1.3.0';

	/**
	 * Option name holding the installed schema version.
	 *
	 * @var string
	 */
	const OPTION = 'fg_schema_version';

	/**
	 * Longest group text, in characters.
	 *
	 * The limit is also the column width, so the two cannot drift apart. The
	 * form carries the same number in `maxlength`, but that only binds a
	 * browser; the server checks it again, because a post does not have to come
	 * from the form.
	 *
	 * @var int
	 */
	const GROUP_MAX = 100;

	/**
	 * Longest description, in characters.
	 *
	 * @var int
	 */
	const DESCRIPTION_MAX = 500;

	/**
	 * Largest accepted head count and duration.
	 *
	 * The columns are `int unsigned`, which reaches far beyond any work duty.
	 * The bound exists for the arithmetic: an unfiltered digit string that
	 * overflows the column would be stored as something else without a word,
	 * because WordPress removes the strict mode from the database session.
	 *
	 * @var int
	 */
	const COUNT_MAX = 99999;

	/**
	 * Longest member number, in characters.
	 *
	 * The number is the key of a member, so it is stored as text and never as a
	 * number. A club number may have a leading zero, and a leading zero that
	 * `absint()` or an `int` column would swallow would quietly make a
	 * different member out of a different person. The column is the limit, so
	 * the two cannot drift apart.
	 *
	 * @var int
	 */
	const MEMBER_NO_MAX = 40;

	/**
	 * Longest first or last name, in characters.
	 *
	 * @var int
	 */
	const MEMBER_NAME_MAX = 80;

	/**
	 * Longest accepted e-mail address of a member.
	 *
	 * The column is 190 characters wide, which is the longest address MySQL can
	 * index comfortably. The bound is the column, not the address: an address of
	 * 254 characters is legal in RFC 5321, and it would be checked, accepted and
	 * then refused by the column, leaving the visitor with a form that says
	 * nothing went wrong. 190 is checked and refused with a message instead.
	 *
	 * @var int
	 */
	const MEMBER_EMAIL_MAX = 190;

	/**
	 * Longest accepted e-mail address of a contact for a ride.
	 *
	 * The same number as `MEMBER_EMAIL_MAX`, because `fg_rides.contact_email` is
	 * the same width and for the same reason. It has its own name so that neither
	 * of the two can be read for the other: one of the two is a member, the other
	 * is somebody offering a ride, and the two are answered differently.
	 *
	 * @var int
	 */
	const CONTACT_EMAIL_MAX = 190;

	/**
	 * Most data rows one member import may carry.
	 *
	 * The bound keeps a file that is not a member list at all — an address book
	 * export with ten thousand rows, or a file that was split at the wrong
	 * place — from being read into memory in one piece. A club roster of several
	 * hundred members is far below it.
	 *
	 * @var int
	 */
	const MEMBER_IMPORT_MAX = 2000;

	/**
	 * Fully qualified events table name.
	 *
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;

		return $wpdb->prefix . 'fg_events';
	}

	/**
	 * Fully qualified rides table name.
	 *
	 * @return string
	 */
	public static function rides_table() {
		global $wpdb;

		return $wpdb->prefix . 'fg_rides';
	}

	/**
	 * Fully qualified members table name.
	 *
	 * @return string
	 */
	public static function members_table() {
		global $wpdb;

		return $wpdb->prefix . 'fg_members';
	}

	/**
	 * Fully qualified event-members table name.
	 *
	 * @return string
	 */
	public static function event_members_table() {
		global $wpdb;

		return $wpdb->prefix . 'fg_event_members';
	}

	/**
	 * Fully qualified mail templates table name.
	 *
	 * The texts of the five messages live here, one row per message. The
	 * defaults stay in the code, FG_Mail_Texts::defaults(), so a row is only
	 * there when a club has changed something and the "back to the default"
	 * button has something to delete.
	 *
	 * @return string
	 */
	public static function mail_templates_table() {
		global $wpdb;

		return $wpdb->prefix . 'fg_mail_templates';
	}

	/**
	 * Create or update all tables when the stored version differs.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( self::VERSION === (string) get_option( self::OPTION, '' ) && self::tables_exist() ) {
			return;
		}

		self::install();
	}

	/**
	 * Run dbDelta for all five tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset        = $wpdb->get_charset_collate();
		$events         = self::events_table();
		$rides          = self::rides_table();
		$members        = self::members_table();
		$event_members  = self::event_members_table();
		$mail_templates = self::mail_templates_table();

		// dbDelta parses this statement; keep one column or key per line and
		// two spaces after the primary key definition. The four columns behind
		// created_at stand at the end of the list on purpose: an update appends
		// missing columns at the end of the existing table, so this way a fresh
		// installation and an upgrade end up in the same column order.
		$statements = array(
			"CREATE TABLE $events (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	title varchar(200) NOT NULL DEFAULT '',
	event_date date NOT NULL DEFAULT '1970-01-01',
	event_time time NULL DEFAULT NULL,
	participants text NULL,
	event_uuid char(36) NOT NULL DEFAULT '',
	public_ref char(32) NOT NULL DEFAULT '',
	is_active tinyint(1) NOT NULL DEFAULT 0,
	created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	group_name varchar(100) NOT NULL DEFAULT '',
	demand int unsigned NOT NULL DEFAULT 0,
	duration_hours int unsigned NOT NULL DEFAULT 0,
	description text NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY public_ref (public_ref),
	KEY active_date (is_active,event_date)
) $charset;",
			"CREATE TABLE $rides (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	event_id bigint(20) unsigned NOT NULL DEFAULT 0,
	status varchar(10) NOT NULL DEFAULT 'pending',
	mode varchar(10) NOT NULL DEFAULT '',
	alias varchar(80) NOT NULL DEFAULT '',
	origin varchar(100) NOT NULL DEFAULT '',
	contact_email varchar(190) NOT NULL DEFAULT '',
	public_ref char(32) NOT NULL DEFAULT '',
	confirmed_at datetime NULL DEFAULT NULL,
	consent_version varchar(10) NOT NULL DEFAULT '',
	consented_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	pending_confirm_hash char(64) NOT NULL DEFAULT '',
	pending_confirm_expires bigint(20) unsigned NOT NULL DEFAULT 0,
	pending_discard_hash char(64) NOT NULL DEFAULT '',
	pending_discard_expires bigint(20) unsigned NOT NULL DEFAULT 0,
	delete_hash char(64) NOT NULL DEFAULT '',
	delete_expires bigint(20) unsigned NOT NULL DEFAULT 0,
	source_url varchar(255) NOT NULL DEFAULT '',
	PRIMARY KEY  (id),
	UNIQUE KEY public_ref (public_ref),
	KEY event (event_id),
	KEY status (status)
) $charset;",
			"CREATE TABLE $members (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	member_no varchar(40) NOT NULL DEFAULT '',
	email varchar(190) NOT NULL DEFAULT '',
	first_name varchar(80) NOT NULL DEFAULT '',
	last_name varchar(80) NOT NULL DEFAULT '',
	updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY member_no (member_no),
	UNIQUE KEY email (email)
) $charset;",
			"CREATE TABLE $mail_templates (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	mail_key varchar(40) NOT NULL DEFAULT '',
	subject text NULL,
	body longtext NOT NULL,
	updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY mail_key (mail_key)
) $charset;",
			"CREATE TABLE $event_members (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	event_id bigint(20) unsigned NOT NULL DEFAULT 0,
	member_id bigint(20) unsigned NOT NULL DEFAULT 0,
	registered_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	unregister_hash char(64) NOT NULL DEFAULT '',
	unregister_expires bigint(20) unsigned NOT NULL DEFAULT 0,
	public_ref char(32) NOT NULL DEFAULT '',
	source_url varchar(255) NOT NULL DEFAULT '',
	PRIMARY KEY  (id),
	UNIQUE KEY public_ref (public_ref),
	UNIQUE KEY event_member (event_id,member_id),
	KEY member (member_id)
) $charset;",
		);

		foreach ( $statements as $statement ) {
			dbDelta( $statement );
		}

		self::clear_legacy_participants();

		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * Empty the participant column that the member registration replaced.
	 *
	 * Until schema 1.2.0 every work duty carried a hand-typed list of e-mail
	 * addresses, one per line, in `participants`. The registrations of the
	 * members themselves take its place, so the list has no reader and no writer
	 * left. It is emptied here rather than merely abandoned, because the addresses
	 * in it are the personal data of people who may not even be members any more,
	 * and a dead column keeps them in the database for ever.
	 *
	 * dbDelta never drops a column, so the column itself stays. That is deliberate:
	 * the tables are also created from these statements on a fresh installation,
	 * where the column then never holds anything.
	 *
	 * @return void
	 */
	public static function clear_legacy_participants() {
		global $wpdb;

		// No prepare() here: the statement carries no value from outside, only
		// the table name this class built, and prepare() with a statement that
		// has no placeholder is a notice on every request.
		$table = self::events_table();
		$wpdb->query( "UPDATE $table SET participants = '' WHERE participants IS NOT NULL AND participants <> ''" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Check whether all five tables are present.
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;

		$tables = array(
			self::events_table(),
			self::rides_table(),
			self::members_table(),
			self::event_members_table(),
			self::mail_templates_table(),
		);

		foreach ( $tables as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Remove all five tables. Only called from the uninstall routine.
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		// The rows that point at another row go first, so the order stays safe even
		// without foreign keys: registrations before members and rides, rides
		// before events. The mail texts point at nothing, so they go wherever is
		// readable. The table names are configuration, not input, but they
		// are still bound through prepare instead of concatenated.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS `%s`', self::mail_templates_table() ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS `%s`', self::event_members_table() ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS `%s`', self::members_table() ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS `%s`', self::rides_table() ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS `%s`', self::events_table() ) );

		delete_option( self::OPTION );
	}
}
