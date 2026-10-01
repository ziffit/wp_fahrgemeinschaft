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
	const VERSION = '1.9.0';

	/**
	 * Option name holding the installed schema version.
	 *
	 * @var string
	 */
	const OPTION = 'fg_schema_version';

	/**
	 * Option name holding the number of rides the migrations removed.
	 *
	 * The option is not read by the plugin afterwards. It exists so the club is
	 * told once how many public entries disappeared, and it is deleted the moment
	 * that notice has been shown. Its existence is therefore the marker for "not
	 * yet seen", and a stale number is better than a number nobody ever saw.
	 *
	 * Two migrations can add to it, and an install() runs both, so the steps add
	 * their numbers up instead of the first one winning the option.
	 *
	 * @var string
	 */
	const DROPPED_RIDES_OPTION = 'fg_rides_dropped_in_1_4_0';

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
	 * Longest meeting point of a duty, in characters.
	 *
	 * A meeting point is a name somebody reads while standing somewhere:
	 * "📍Parkplatz Westbad, Nürnberg". The pin belongs to the text, because the club
	 * types it and may leave it out — the markup does not draw one. That way the
	 * stored text is the thing the club meant, and the same text goes into the mail
	 * without a second pin standing in front of it. 100 characters is the bound of
	 * the group name too, and for the same reason: two words and a street.
	 *
	 * @var int
	 */
	const MEETING_POINT_MAX = 100;

	/**
	 * Longest web address of a meeting point, in characters.
	 *
	 * 500 and not 255, because the address of a map service is long before it is
	 * short: "https://www.google.com/maps/search/?api=1&query=Parkplatz+Westbad" is
	 * 72 characters, and a club that puts a second place into the query is over 255.
	 * Checked and refused with a message rather than cut off in half.
	 *
	 * @var int
	 */
	const MEETING_POINT_URL_MAX = 500;

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
	 * Longest accepted work group of a member, in characters.
	 *
	 * A work group is a word or two — "Gartenbau", "Strand", "Küche Samstag" —
	 * and 80 is the same bound as the two name columns. It is checked and refused
	 * with a message rather than cut off, for the reason the address bound gives:
	 * a value that is shortened without a word is a value the club did not write.
	 *
	 * @var int
	 */
	const MEMBER_WORK_GROUP_MAX = 80;

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
	 * The same number as `MEMBER_EMAIL_MAX`, and for the same reason: the widest
	 * address that can be indexed is the widest address worth accepting. It has
	 * its own name so that neither of the two can be read for the other. Since
	 * schema 1.4.0 the address of a ride creator is no longer stored on the ride
	 * at all — it is looked up through the member — and this bound belongs to the
	 * address of the person who writes to somebody else's ride.
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
	 * Fully qualified unregistration token table name.
	 *
	 * One row per token, and a registration can have several of them: the token
	 * only exists in clear text in the mail it was written for, so a second mail
	 * to the same member needs a token of its own. Until schema 1.7.0 the hash
	 * stood in the registration itself, which allowed exactly one link per
	 * registration and made every link before the last one useless. This table
	 * is the second place, and it is the only one that is read.
	 *
	 * @return string
	 */
	public static function event_member_tokens_table() {
		global $wpdb;

		return $wpdb->prefix . 'fg_event_member_tokens';
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
	 * Run dbDelta for all six tables.
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
		$tokens         = self::event_member_tokens_table();
		$mail_templates = self::mail_templates_table();

		// dbDelta parses this statement; keep one column or key per line and
		// two spaces after the primary key definition. The columns behind
		// created_at stand at the end of the list on purpose: an update appends
		// missing columns at the end of the existing table, so this way a fresh
		// installation and an upgrade end up in the same column order. That is
		// why member_id is the last column of the rides table and not next to
		// event_id, which is where it would be read.
		//
		// The two columns alias and contact_email in the rides table have no
		// reader and no writer left since schema 1.4.0: a ride belongs to a
		// member now, and the name shown publicly is the first name of that
		// member. They stay in this statement for the same reason participants
		// stays in the events table — a WordPress rolled back to an older
		// version of this plugin must not fail on a field it does not know. The
		// migration empties them; see clear_legacy_ride_contacts().
		//
		// The four columns pending_confirm_hash, pending_confirm_expires,
		// pending_discard_hash and pending_discard_expires lost their reader and
		// their writer in 1.15.0, when the pending state went away: a ride is
		// published when the form is sent, so there is nothing to confirm and
		// nothing to discard before publication. They keep their place for the
		// same reason as above. Unlike alias and contact_email they need no
		// migration — the migration of 1.4.0 already wrote every row, and a
		// ride published by an older version has never carried a pending token
		// in these columns while being published.
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
	meeting_point varchar(100) NOT NULL DEFAULT '',
	meeting_point_url varchar(500) NOT NULL DEFAULT '',
	PRIMARY KEY  (id),
	UNIQUE KEY public_ref (public_ref),
	KEY active_date (is_active,event_date)
) $charset;",
			"CREATE TABLE $rides (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	event_id bigint(20) unsigned NOT NULL DEFAULT 0,
	status varchar(10) NOT NULL DEFAULT 'published',
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
	member_id bigint(20) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	UNIQUE KEY public_ref (public_ref),
	KEY event (event_id),
	KEY status (status),
	KEY member (member_id)
) $charset;",
			"CREATE TABLE $members (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	member_no varchar(40) NOT NULL DEFAULT '',
	email varchar(190) NOT NULL DEFAULT '',
	first_name varchar(80) NOT NULL DEFAULT '',
	last_name varchar(80) NOT NULL DEFAULT '',
	updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	work_group varchar(80) NOT NULL DEFAULT '',
	PRIMARY KEY  (id),
	UNIQUE KEY member_no (member_no),
	KEY email (email)
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
	notified_count smallint(5) unsigned NOT NULL DEFAULT 0,
	added_by_admin tinyint(1) NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	UNIQUE KEY public_ref (public_ref),
	UNIQUE KEY event_member (event_id,member_id),
	KEY member (member_id)
) $charset;",
			"CREATE TABLE $tokens (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	registration_id bigint(20) unsigned NOT NULL DEFAULT 0,
	token_hash char(64) NOT NULL DEFAULT '',
	expires bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY token_hash (token_hash),
	KEY registration (registration_id)
) $charset;",
		);

		foreach ( $statements as $statement ) {
			dbDelta( $statement );
		}

		self::clear_legacy_participants();
		self::adopt_ride_members();
		self::clear_legacy_ride_contacts();
		self::clear_pending_rides();
		self::relax_member_email_index();
		self::copy_unregister_tokens();

		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * Copy every unregistration token that still stands in its registration.
	 *
	 * Until schema 1.7.0 the hash of the unregistration token stood in
	 * `event_members` together with its expiry, one per registration, and a
	 * second mail to the same member could not be given a link of its own. The
	 * tokens now stand in their own table, and a copy is the whole migration.
	 *
	 * It is not optional. Without it, every link that a club has already sent
	 * out stops working the moment the update runs — and nothing on the site
	 * would say so, because those mails have been sitting in inboxes for weeks
	 * and nobody clicks them again. A member who wants out after the update gets
	 * the page "Link nicht gültig" and has to ask the club by other means.
	 *
	 * The statement is idempotent and is written so that it can be repeated: the
	 * first run may fail halfway, and the next one has to finish the job rather
	 * than insert the same row twice. A registration without a token produces no
	 * row, which is the state it was already in.
	 *
	 * @return void
	 */
	public static function copy_unregister_tokens() {
		global $wpdb;

		$event_members = self::event_members_table();
		$tokens        = self::event_member_tokens_table();

		// No prepare() on either name: both are table names this class built, and
		// a statement without a placeholder would only earn a notice per request.
		$wpdb->query(
			"INSERT INTO $tokens (registration_id, token_hash, expires, created_at)
			SELECT r.id, r.unregister_hash, r.unregister_expires, r.registered_at
			FROM $event_members r
			WHERE r.unregister_hash <> ''
			AND NOT EXISTS (
				SELECT 1 FROM $tokens t
				WHERE t.registration_id = r.id AND t.token_hash = r.unregister_hash
			)"
		);
	}

	/**
	 * Turn the unique index on the member address into an ordinary one.
	 *
	 * Until schema 1.6.0 the address was a key beside the member number, and a
	 * second member could not be given an address that was already in use. The
	 * usual case for that is a married pair with one mailbox, and it is a real
	 * case rather than an exotic one: the member number is the key of everything
	 * in this plugin, and every public form asks for the number and the address
	 * together. Two members with one address are therefore two people in the
	 * member list and not one person counted twice, and a club that has such a
	 * pair in its own administration could not enter it at all — the import
	 * refused the whole file.
	 *
	 * The index is not simply dropped. It is replaced by an ordinary index,
	 * because the queries behind the privacy export and the erasure look the
	 * members up by address, and those would walk the table without it.
	 *
	 * dbDelta neither converts a unique key into an ordinary one nor drops one,
	 * so the statement below is the only thing that can do it. It is guarded by
	 * the index itself: on a fresh installation the key is already an ordinary
	 * one, and on a second run there is nothing left to change. No value from
	 * outside reaches the statement, only the table name this class built, so
	 * prepare() has no placeholder to bind and would be a notice on every call.
	 *
	 * @return void
	 */
	public static function relax_member_email_index() {
		global $wpdb;

		$table  = self::members_table();
		$indexe = $wpdb->get_results(
			$wpdb->prepare( "SHOW INDEX FROM $table WHERE Key_name = %s", 'email' ),
			ARRAY_A
		);

		if ( empty( $indexe ) || 0 !== (int) $indexe[0]['Non_unique'] ) {
			return;
		}

		$wpdb->query( "ALTER TABLE $table DROP INDEX email, ADD INDEX email (email)" ); // phpcs:ignore WordPress.DB
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
	 * Attach every existing ride to the member whose address it stored.
	 *
	 * Until schema 1.4.0 a ride carried the contact address of its creator in
	 * `contact_email` and nothing else that named a person. The address was
	 * checked against the member administration at every step — the form, the
	 * confirmation and the contact request — so a ride that got that far belongs
	 * to a member, and the join below finds that member without guessing.
	 *
	 * The match is a join and not a lookup per row because a club may have a few
	 * hundred rides and this runs on every request until the stored version
	 * matches. `member_id = 0` is the guard, so running this twice adopts
	 * nothing twice and cannot overwrite a member that is already set.
	 *
	 * @return void
	 */
	public static function adopt_ride_members() {
		global $wpdb;

		$rides   = self::rides_table();
		$members = self::members_table();

		// A member address is unique in the club's own table, so the join
		// matches at most one member per ride. An address of nobody matches no
		// row at all, which is what the next step is about.
		//
		// No prepare(): the statement carries no value from outside, only the
		// two table names this class built, and prepare() with a statement that
		// has no placeholder is a notice on every request.
		$wpdb->query( // phpcs:ignore WordPress.DB
			"UPDATE $rides r INNER JOIN $members m ON m.email = r.contact_email
			SET r.member_id = m.id
			WHERE r.member_id = 0 AND r.contact_email <> ''"
		);
	}

	/**
	 * Remove the rides that no member claims, then empty the two dead columns.
	 *
	 * A ride whose `member_id` is still 0 and which still carries an address
	 * belongs to an address the club does not know, or to one it has since
	 * changed. It cannot be kept: the public list would show a name that belongs
	 * to nobody, the deletion link would no longer reach a member, and the
	 * contact request would have nobody to answer. There is no way to attach it
	 * to a member without guessing which of them meant it, and a wrong entry in
	 * a public list is worse than a missing one.
	 *
	 * The address in the WHERE is what makes this the migration and not a
	 * blunt instrument. Every ride the code before 1.4.0 wrote carries an
	 * address, so this catches all of them. A row without an address and without
	 * a member is one this version has no writer for; leaving it alone lets
	 * is_valid_public_ride() leave it out of the public list, which is the same
	 * answer without deleting a row on a guess.
	 *
	 * The number goes into an option, because a club that loses a public entry
	 * from its site without being told has no way of finding out. The admin
	 * screen Fahrgemeinschaften shows that notice once and then deletes the
	 * option, so it does not stand there for ever.
	 *
	 * @return void
	 */
	public static function clear_legacy_ride_contacts() {
		global $wpdb;

		$table = self::rides_table();

		$dropped = (int) $wpdb->query( "DELETE FROM $table WHERE member_id = 0 AND contact_email <> ''" ); // phpcs:ignore WordPress.DB

		// The two columns keep their place in the table, as participants does, and
		// are emptied here for the same reason: they hold the personal data of
		// people who may not be members any more, and a dead column keeps that
		// data in the database for ever.
		$wpdb->query( "UPDATE $table SET alias = '', contact_email = '' WHERE alias <> '' OR contact_email <> ''" ); // phpcs:ignore WordPress.DB

		self::count_dropped_rides( $dropped );
	}

	/**
	 * Remove the rides that are still waiting for a confirmation.
	 *
	 * Until 1.15.0 a public entry was stored as pending and became visible only
	 * after the person had clicked a link in a mail. Since 1.15.0 a ride is
	 * published when the form is sent, so a row in the pending state is one that
	 * nobody ever confirmed — and there is no longer a code path that could turn
	 * it into a published one. Left alone it would stay in the table for ever:
	 * the daily cleanup that used to expire it is gone with the state itself, and
	 * without a status column in the list it would look like an ordinary entry.
	 *
	 * They are removed and not published. A pending row is a submission whose
	 * author was asked to confirm and never did, and a club has no way of reading
	 * that as consent to a public entry; the person who wanted one offered it
	 * again with one form. The number goes into the same option as the rides the
	 * migration of 1.4.0 removed, so the club is told once about both.
	 *
	 * The status is compared as a literal and not through FG_RIDE_STATUS_PENDING,
	 * because that constant is gone with the state it named. The value is what is
	 * in the rows written by every version up to and including 1.14.0.
	 *
	 * @return void
	 */
	public static function clear_pending_rides() {
		global $wpdb;

		// No prepare() here: the statement carries no value from outside, only
		// the table name this class built and a literal this class wrote.
		$table   = self::rides_table();
		$dropped = (int) $wpdb->query( "DELETE FROM $table WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB

		self::count_dropped_rides( $dropped );
	}

	/**
	 * Add removed rides to the number the club is shown once.
	 *
	 * add_option() would be wrong here: it does nothing when the option already
	 * exists, and an install() runs both removing steps, so the second number
	 * would be lost without a word. update_option() adds the option when it is
	 * missing and writes it when it is there.
	 *
	 * @param int $count Number of rides removed by the calling step.
	 * @return void
	 */
	private static function count_dropped_rides( $count ) {
		$count = (int) $count;

		if ( $count > 0 ) {
			update_option( self::DROPPED_RIDES_OPTION, (int) get_option( self::DROPPED_RIDES_OPTION, 0 ) + $count, false );
		}
	}

	/**
	 * How many rides the migrations removed, and clear the number.
	 *
	 * The option is deleted as it is read, which is what makes the notice appear
	 * once. The number is returned rather than printed so that the caller decides
	 * where it appears.
	 *
	 * @return int Number of removed rides, zero when there was nothing to report.
	 */
	public static function take_dropped_rides_notice() {
		$stored = get_option( self::DROPPED_RIDES_OPTION, false );

		if ( false === $stored ) {
			return 0;
		}

		delete_option( self::DROPPED_RIDES_OPTION );

		return (int) $stored;
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
