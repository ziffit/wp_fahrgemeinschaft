<?php
/**
 * Database schema of the plugin.
 *
 * @package Fahrgemeinschaften
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates, verifies and removes the two plugin tables.
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
	const VERSION = '1.0.0';

	/**
	 * Option name holding the installed schema version.
	 *
	 * @var string
	 */
	const OPTION = 'fg_schema_version';

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
	 * Create or update both tables when the stored version differs.
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
	 * Run dbDelta for both tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset = $wpdb->get_charset_collate();
		$events  = self::events_table();
		$rides   = self::rides_table();

		// dbDelta parses this statement; keep one column or key per line and
		// two spaces after the primary key definition.
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
		);

		foreach ( $statements as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * Check whether both tables are present.
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;

		foreach ( array( self::events_table(), self::rides_table() ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Remove both tables. Only called from the uninstall routine.
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		// Rides first so the order stays safe even without foreign keys. The
		// table name is configuration, not input, but it is still bound
		// through prepare instead of concatenated into the statement.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS `%s`', self::rides_table() ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS `%s`', self::events_table() ) );

		delete_option( self::OPTION );
	}
}
