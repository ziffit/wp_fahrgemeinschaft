<?php
/**
 * Read and write plugin state from the command line.
 *
 * The shell suites must be able to look at the tables and build fixtures
 * without embedding long scripts in one-liners. Every value printed here is
 * either an identifier or a stored field value; nothing is interpreted.
 *
 * Usage: php state.php <command> [arguments]
 */
define( 'WP_USE_THEMES', false );
require '/var/www/html/wp-load.php';

$repo = new FG_Repository();

/**
 * Print a value without any formatting around it.
 *
 * @param mixed $value Value to print.
 * @return void
 */
/**
 * The greeting of a message.
 *
 * Since version 1.21.0 what reaches the recorder is the layout, not the plain
 * text, and in the layout the greeting is not the first line of the file: the
 * first line is `<!DOCTYPE html>`, and a member reads the subject in the
 * heading of the letterhead before the greeting. What the checks want is the
 * greeting, and the layout marks the block it is written in — `<!-- start copy -->`
 * to `<!-- end copy -->`. That block is taken, the tags come off, the entities
 * are read back, and the first line that carries something is the answer.
 *
 * A body that is plain text is read as it stands, because that is still what the
 * transport gets on a path where the layout is not built.
 *
 * Without the marks there is no way to tell the message from the letterhead,
 * and the subject would be read as the greeting. That is a wrong answer and not
 * a missing one, so it is said out loud instead of being returned as text.
 *
 * @param string $body Recorded message body.
 * @return string
 */
function fg_state_greeting( $body ) {
	$text = (string) $body;

	if ( false !== stripos( $text, '<html' ) || false !== stripos( $text, '<!doctype' ) ) {
		$anfang = stripos( $text, '<!-- start copy -->' );
		$ende   = stripos( $text, '<!-- end copy -->' );

		if ( false === $anfang || false === $ende || $ende < $anfang ) {
			return 'KEIN-TEXTBLOCK';
		}

		$text = html_entity_decode( wp_strip_all_tags( substr( $text, $anfang, $ende - $anfang ) ), ENT_QUOTES, 'UTF-8' );
	}

	foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $zeile ) {
		$zeile = trim( preg_replace( '/\s+/', ' ', $zeile ) );

		if ( '' !== $zeile ) {
			return $zeile;
		}
	}

	return '';
}

function fg_state_out( $value ) {
	if ( is_bool( $value ) ) {
		$value = $value ? '1' : '0';
	}

	if ( null === $value ) {
		$value = '';
	}

	if ( is_array( $value ) ) {
		$value = implode( ',', $value );
	}

	echo (string) $value, "\n";
}

/**
 * Write one row of the mail texts, without the check of the screen.
 *
 * The check belongs to the screen, not to the table: the screen refuses a text
 * that names a placeholder the message does not have, and a test needs the
 * state that a club can reach by going back to an older version. The statement
 * therefore repeats the one in FG_Mail_Texts::save() and skips the refusal on
 * purpose. Both are one ON DUPLICATE KEY UPDATE, so both produce the same row.
 *
 * @param string $table   Table name.
 * @param string $key     Message key.
 * @param string $subject Subject with placeholders.
 * @param string $body    Body with placeholders.
 * @return string
 */
function fg_mail_text_write( $table, $key, $subject, $body ) {
	global $wpdb;

	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO $table (mail_key, subject, body, updated_at) VALUES (%s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), updated_at = VALUES(updated_at)",
			(string) $key,
			(string) $subject,
			(string) $body,
			current_time( 'mysql' )
		)
	);

	return 'set';
}

$command = isset( $argv[1] ) ? (string) $argv[1] : '';
$args    = array_slice( $argv, 2 );

switch ( $command ) {
	case 'statuses':
		$statuses = array();
		foreach ( $repo->get_rides_page( array(), 0, 0 ) as $ride ) {
			$statuses[] = $ride->status;
		}
		sort( $statuses );
		fg_state_out( implode( ',', $statuses ) . ',' );
		break;

	case 'count':
		fg_state_out( $repo->count_rides( array( 'status' => isset( $args[0] ) ? $args[0] : '' ) ) );
		break;

	// How many work duties the public list is allowed to show at all: the ones
	// that are still ahead and are marked visible. The HTTP check compares this
	// number with the number of cards on the page, so a duty that silently
	// disappears from the list cannot pass unnoticed.
	case 'count-events':
		fg_state_out( count( $repo->get_active_events() ) );
		break;

	// The duty that stands at the top of the list, and the date the list is
	// expected to print for it. Both are read the same way the page reads them,
	// so the check compares two renderings of one rule and not a guess.
	case 'first-event':
		$erste = $repo->get_active_events();
		fg_state_out( $erste ? $erste[0]->id : 0 );
		break;

	case 'format-date':
		fg_state_out( $repo->format_event_date_long( isset( $args[0] ) ? $args[0] : 0 ) );
		break;

	// Sum of a daily counter over all days. The counters are what the server
	// counts on its own, so they are the only place to see whether a submitted
	// value was treated as a contact detail or as a name.
	case 'stat':
		$key    = isset( $args[0] ) ? (string) $args[0] : '';
		$stats  = get_option( FG_STATS_OPTION, array() );
		$sum    = 0;
		if ( is_array( $stats ) ) {
			foreach ( $stats as $day ) {
				if ( is_array( $day ) && isset( $day[ $key ] ) ) {
					$sum += (int) $day[ $key ];
				}
			}
		}
		fg_state_out( $sum );
		break;

	// Since 1.15.0 a ride is published when it is written, so there is no token
	// that waits for a confirmation. The command is kept as the opposite of the
	// check that a submission publishes something: a suite that wants to see a
	// ride refuse its own deletion link asks for the count of empty hashes.
	case 'count-delete-token':
		$found = 0;
		foreach ( $repo->get_rides_page( array(), 0, 0 ) as $ride ) {
			if ( '' !== $ride->delete_hash ) {
				++$found;
			}
		}
		fg_state_out( $found );
		break;

	case 'count-event-rides':
		fg_state_out( $repo->count_event_rides( isset( $args[0] ) ? $args[0] : 0 ) );
		break;

	case 'count-published':
		fg_state_out( $repo->count_rides( array( 'status' => FG_RIDE_STATUS_PUBLISHED ) ) );
		break;

	// Rides have no name of their own since schema 1.4.0, so the pickup area is
	// the only free text on a row and the only thing a caller can count by. The
	// suites give each submission its own area for that reason.
	case 'count-origin':
		$needle = isset( $args[0] ) ? (string) $args[0] : '';
		$found  = 0;
		foreach ( $repo->get_rides_page( array(), 0, 0 ) as $ride ) {
			if ( 0 === strpos( $ride->origin, $needle ) ) {
				++$found;
			}
		}
		fg_state_out( $found );
		break;

	case 'count-public-for-event':
		fg_state_out( count( $repo->get_published_rides( isset( $args[0] ) ? $args[0] : 0 ) ) );
		break;

	case 'event':
		$event = $repo->get_event( isset( $args[0] ) ? $args[0] : 0 );
		if ( ! $event ) {
			fg_state_out( 'missing' );
			break;
		}

		$fields = array(
			'id',
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
			// The same shape as the list in FG_Repository::update_event(): a field
			// that is not named here answers 'unknown-field', and a check that
			// reads that would be green about a field the tool cannot see at all.
			'meeting_point',
			'meeting_point_url',
		);
		$name = isset( $args[1] ) ? $args[1] : 'id';
		fg_state_out( in_array( $name, $fields, true ) ? $event->{$name} : 'unknown-field' );
		break;

	case 'ride':
		$ride = $repo->get_ride( isset( $args[0] ) ? $args[0] : 0 );
		if ( ! $ride ) {
			fg_state_out( 'missing' );
			break;
		}

		$fields = array(
			'id',
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
		$name = isset( $args[1] ) ? $args[1] : 'id';
		fg_state_out( in_array( $name, $fields, true ) ? $ride->{$name} : 'unknown-field' );
		break;

	case 'exists-event':
		fg_state_out( null !== $repo->get_event( isset( $args[0] ) ? $args[0] : 0 ) );
		break;

	case 'exists-ride':
		fg_state_out( null !== $repo->get_ride( isset( $args[0] ) ? $args[0] : 0 ) );
		break;

	// make-event <title> <date> [time] [active]
	//
	// The third argument used to be a list of participant addresses, which the
	// member administration replaced. What stands in its place is a demand, so
	// a duty can be built that has free places to give away. The argument after
	// it stays the visibility.
	case 'make-event':
		$event_id = $repo->insert_event(
			array(
				'title'      => isset( $args[0] ) ? $args[0] : 'Dienst',
				'event_date' => isset( $args[1] ) ? $args[1] : gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ),
				'event_time' => isset( $args[3] ) && '' !== $args[3] ? $args[3] : '',
				'demand'     => isset( $args[2] ) ? (int) $args[2] : 0,
				'is_active'  => ! isset( $args[4] ) || '0' !== $args[4],
			)
		);
		fg_state_out( $event_id );
		break;

	/* ---------------------------------------------------------------- members */

	// make-member <member_no> <email> <first_name> <last_name> [work_group]
	//
	// Prints the new member's ID, or 0 when the repository refused the values.
	// The refusal is not worked around here: a suite that has to build a member
	// that the real form would not accept is measuring the wrong thing.
	//
	// The fifth argument is the work group, and it goes through insert_member()
	// like the other four — the same way the import writes it. A fixture that
	// set the value in the database behind the repository's back could prove a
	// list shows a stored value, but not that a stored value arrives there.
	case 'make-member':
		fg_state_out(
			$repo->insert_member(
				array(
					'member_no'   => isset( $args[0] ) ? $args[0] : '',
					'email'       => isset( $args[1] ) ? $args[1] : '',
					'first_name'  => isset( $args[2] ) ? $args[2] : '',
					'last_name'   => isset( $args[3] ) ? $args[3] : '',
					'work_group'  => isset( $args[4] ) ? $args[4] : '',
				)
			)
		);
		break;

	// member <id> [field]
	case 'member':
		$member = $repo->get_member( isset( $args[0] ) ? $args[0] : 0 );
		if ( ! $member ) {
			fg_state_out( 'missing' );
			break;
		}

		$fields = array( 'id', 'member_no', 'email', 'first_name', 'last_name', 'created_at', 'updated_at' );
		$name   = isset( $args[1] ) ? $args[1] : 'id';
		fg_state_out( in_array( $name, $fields, true ) ? $member->{$name} : 'unknown-field' );
		break;

	// member-by-no <member_no> [field]
	case 'member-by-no':
		$member = $repo->get_member_by_number( isset( $args[0] ) ? $args[0] : '' );
		if ( ! $member ) {
			fg_state_out( 'missing' );
			break;
		}

		$fields = array( 'id', 'member_no', 'email', 'first_name', 'last_name', 'work_group' );
		$name   = isset( $args[1] ) ? $args[1] : 'id';
		fg_state_out( in_array( $name, $fields, true ) ? $member->{$name} : 'unknown-field' );
		break;

	case 'count-members':
		fg_state_out( count( $repo->get_members_page() ) );
		break;

	// members-csv: the whole member list as a CSV file with the header the
	// importer reads. It is the export the club's own administration would
	// hand over, so importing it has to change nothing at all. A suite that
	// only ever imports files it wrote itself would not notice an import that
	// rewrites every row.
	case 'members-csv':
		$zeilen = array( 'Mitgliedsnummer;E-Mail-Adresse;Vorname;Nachname' );
		foreach ( $repo->get_members_page() as $member ) {
			$zeilen[] = implode(
				';',
				array(
					$member->member_no,
					$member->email,
					$member->first_name,
					str_replace( array( ';', "\n" ), ' ', $member->last_name ),
				)
			);
		}
		echo implode( "\n", $zeilen ), "\n";
		break;

	case 'delete-member':
		$result = $repo->delete_member( isset( $args[0] ) ? $args[0] : 0 );
		fg_state_out( $result['deleted'] ? 'deleted:' . $result['registrations'] : 'missing' );
		break;

	// unlinked: every member who is not registered for a work service, one
	// "member_no|email" per line. The group deletion on the member screen claims
	// to remove exactly these people, so the suite compares the names on that
	// screen with the names here instead of counting rows on both sides and
	// hoping the two counts agree.
	case 'unlinked':
		$zeilen = array();
		foreach ( $repo->get_members_without_registration() as $member ) {
			$zeilen[] = $member->member_no . '|' . $member->email;
		}

		fg_state_out( implode( ' ', $zeilen ) );
		break;

	/* --------------------------------------------------------- registrations */

	// register <event_id> <member_no> <email> [source_url]
	//
	// Builds a registration the way the public form does, and prints its ID.
	// The token is not printed: it only exists once, in the mail, and a suite
	// that has to read it out of the database is not testing the link.
	case 'register':
		$member = $repo->find_member_for_registration(
			isset( $args[1] ) ? $args[1] : '',
			isset( $args[2] ) ? $args[2] : ''
		);
		if ( ! $member ) {
			fg_state_out( 0 );
			break;
		}
		$created = $repo->create_registration(
			isset( $args[0] ) ? $args[0] : 0,
			$member->id,
			isset( $args[3] ) ? $args[3] : ''
		);
		fg_state_out( (int) $created['id'] );
		break;

	case 'count-registrations':
		fg_state_out( $repo->count_event_registrations( isset( $args[0] ) ? $args[0] : 0 ) );
		break;

	// free-places <event_id>: what the public card has to print.
	case 'free-places':
		$event = $repo->get_event( isset( $args[0] ) ? $args[0] : 0 );
		if ( ! $event ) {
			fg_state_out( 'missing' );
			break;
		}
		fg_state_out( $repo->free_places( $event, $repo->count_event_registrations( $event->id ) ) );
		break;

	// registrations <event_id>: one line per registration as
	// "<member_no>|<email>|<first_name> <last_name>|<registration_id>".
	// The four values are separated by a bar because a member's name may
	// contain anything a person can type, and the shell has to be able to cut
	// the line apart again.
	case 'registrations':
		$event_id = isset( $args[0] ) ? $args[0] : 0;
		foreach ( $repo->get_event_registrations( $event_id ) as $registration ) {
			$member = $repo->get_member( $registration->member_id );
			fg_state_out(
				implode(
					'|',
					array(
						$member ? $member->member_no : '',
						$member ? $member->email : '',
						$member ? $member->first_name . ' ' . $member->last_name : '',
						$registration->id,
					)
				)
			);
		}
		break;

	// make-ride <event_id> <mode> <origin> <member_no>
	//
	// A ride belongs to a member since schema 1.4.0, so the name is looked up
	// from a member number and not passed in. Callers that used to hand in an
	// address of their own need a member row behind it now, which is the point:
	// a ride whose member does not exist is a case the plugin has to survive,
	// and the suites build it on purpose rather than by accident.
	//
	// The fifth argument used to publish the row. Since 1.15.0 there is nothing
	// to publish afterwards — the row is written as published — so the argument
	// is gone rather than kept and ignored.
	case 'make-ride':
		$mitglied = $repo->get_member_by_number( isset( $args[3] ) ? (string) $args[3] : '' );
		$ride     = $repo->create_ride(
			array(
				'event_id'  => isset( $args[0] ) ? $args[0] : 0,
				'mode'      => isset( $args[1] ) ? $args[1] : FG_RIDE_MODE_OFFER,
				'origin'    => isset( $args[2] ) ? $args[2] : 'Innenstadt',
				'member_id' => $mitglied ? $mitglied->id : 0,
			)
		);
		fg_state_out( $ride['id'] );
		break;

	case 'find-event':
		$found = 0;
		foreach ( $repo->get_all_events() as $event ) {
			if ( $event->title === ( isset( $args[0] ) ? $args[0] : '' ) ) {
				$found = $event->id;
				break;
			}
		}
		fg_state_out( $found );
		break;

	case 'delete-event':
		$result = $repo->delete_event( isset( $args[0] ) ? $args[0] : 0 );
		fg_state_out( $result['deleted'] ? 'deleted:' . $result['rides'] : 'missing' );
		break;

	// drop-registration <event_id> <member_no>: the one way a shell suite can
	// take a registration back out again without going through the mail link,
	// whose token is stored as a hash and therefore unreadable. It is a
	// test tool and does exactly what it says, including skipping the nonce.
	case 'drop-registration':
		$event_id = isset( $args[0] ) ? $args[0] : 0;
		$member   = $repo->get_member_by_number( isset( $args[1] ) ? $args[1] : '' );
		if ( ! $member ) {
			fg_state_out( 'missing' );
			break;
		}
		fg_state_out( $repo->delete_registration_by_pair( $event_id, $member->id ) ? 'dropped' : 'missing' );
		break;

	case 'delete-ride':
		fg_state_out( $repo->delete_ride( isset( $args[0] ) ? $args[0] : 0 ) );
		break;

	case 'purge':
		$removed = 0;
		foreach ( $repo->get_all_events() as $event ) {
			$result = $repo->delete_event( $event->id );
			$removed += (int) $result['rides'];
			++$removed;
		}
		fg_state_out( $removed );
		break;

	case 'settings':
		$settings = FG_Mail_Templates::get_settings();
		$name     = isset( $args[0] ) ? (string) $args[0] : '';
		fg_state_out( array_key_exists( $name, $settings ) ? $settings[ $name ] : 'unknown-field' );
		break;

	// The settings suite stores values of its own. It reads the option before
	// it starts and hands the result back afterwards, so a footer that was
	// configured by hand survives the run.
	case 'settings-json':
		fg_state_out( wp_json_encode( get_option( FG_SETTINGS_OPTION, null ) ) );
		break;

	case 'settings-restore':
		$stored = json_decode( isset( $args[0] ) ? (string) $args[0] : 'null', true );
		if ( null === $stored ) {
			delete_option( FG_SETTINGS_OPTION );
		} else {
			update_option( FG_SETTINGS_OPTION, $stored, false );
		}
		fg_state_out( 'restored' );
		break;


	// recorder <on|off>
	//
	// The mail recorder of the test site short-circuits wp_mail() and writes the
	// message into a table instead of handing it on. That is what the suites need
	// and what stops SureMail from receiving anything. A suite run by hand leaves
	// it switched on — only run-all.sh hands it back at the end — so the way to
	// switch it is a command here and not a php -r that has to be remembered.
	// "on" and "off" and nothing else: a name that is neither is answered with the
	// current state, so a mistyped call reports instead of doing nothing.
	case 'recorder':
		$schalter = isset( $args[0] ) ? (string) $args[0] : '';
		$an      = '1' === (string) get_option( 'fg_test_mail_enabled', '0' );

		if ( 'on' === $schalter ) {
			update_option( 'fg_test_mail_enabled', '1', false );
			$an = true;
		} elseif ( 'off' === $schalter ) {
			delete_option( 'fg_test_mail_enabled' );
			delete_option( 'fg_test_mail_fail' );
			$an = false;
		}

		fg_state_out( $an ? 'on, wp_mail wird in die Tabelle geschrieben' : 'off, wp_mail erreicht SureMail' );
		break;

	// mail-recorded-greeting
	//
	// The greeting of the message that was recorded last. http.sh asks the same
	// thing admin.sh asks for, and it gets it from the same helper: a second
	// implementation of "the first line of the message" in a suite is a second
	// rule, and the two drift apart without anybody noticing.
	case 'mail-recorded-greeting':
		global $wpdb;

		$zeile = (string) $wpdb->get_var( 'SELECT mail_body FROM ' . $wpdb->prefix . 'fg_test_mail_log ORDER BY id DESC LIMIT 1' );

		fg_state_out( '' === $zeile ? 'KEINE-MAIL' : fg_state_greeting( $zeile ) );
		break;

	// notified <event_id> <member_no>
	//
	// How many duty mails this member received for this duty, and how many
	// unregistration tokens the registration carries. Both are numbers a check
	// wants to read after a click in the admin area, and both live in tables that
	// only the plugin reads.
	case 'notified':
		$event_id  = isset( $args[0] ) ? (int) $args[0] : 0;
		$member_no = isset( $args[1] ) ? (string) $args[1] : '';
		$mitglied  = $repo->get_member_by_number( $member_no );
		$paar      = ( $event_id && $mitglied ) ? $repo->find_registration_pair( $event_id, $mitglied->id ) : null;

		// The registration ID comes with it. A check that reads the wrong row
		// cannot be told apart from a plugin that wrote to the wrong row, and
		// the number is what tells the two apart.
		fg_state_out(
			$paar
				? (int) $paar->notified_count . "\t" . $repo->count_registration_tokens( $paar->id ) . "\t" . ( $paar->added_by_admin ? 'redaktion' : 'mitglied' ) . "\t" . (int) $paar->id
				: "keine\t0\tkeine\t0"
		);
		break;

	// mail-count
	//
	// How many mails the recorder of the test environment holds. The counter of
	// the list is a claim about mails that went out, and this is the number it
	// has to be compared with.
	case 'mail-count':
		global $wpdb;

		fg_state_out( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'fg_test_mail_log' ) );
		break;

	// mail-bodies <n>
	//
	// The bodies of the newest recorded mails, one after the other, separated by
	// a marker line. The link of a duty mail stands in it as an address, and a
	// check needs the link that really went out rather than one it builds
	// itself.
	case 'mail-bodies':
		global $wpdb;

		$anzahl = isset( $args[0] ) ? max( 1, (int) $args[0] ) : 1;
		$zeilen = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT mail_body FROM ' . $wpdb->prefix . 'fg_test_mail_log ORDER BY id DESC LIMIT %d',
				$anzahl
			)
		);

		foreach ( (array) $zeilen as $index => $zeile ) {
			if ( $index > 0 ) {
				echo "###MAIL###", PHP_EOL;
			}

			echo $zeile, PHP_EOL;
		}
		break;

	// mail-recipients <n>
	//
	// Recipient and subject of the newest recorded mails, one per line. The
	// address of a member stands in the recipient field and not in the text of
	// the mail — the mail greets the member by name — so a check that looks for
	// the address in the body is looking in the wrong field.
	case 'mail-recipients':
		global $wpdb;

		$anzahl = isset( $args[0] ) ? max( 1, (int) $args[0] ) : 1;
		$zeilen = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT mail_to, mail_subject FROM ' . $wpdb->prefix . 'fg_test_mail_log ORDER BY id DESC LIMIT %d',
				$anzahl
			),
			ARRAY_A
		);

		foreach ( (array) $zeilen as $zeile ) {
			// Double quotes, because printf reads \\t in a single-quoted string as
			// two characters: the output was a line with a backslash in it, and a
			// cut -f1 on that line returned the whole thing.
			printf( "%s\t%s", (string) $zeile['mail_to'], (string) $zeile['mail_subject'] );
			echo PHP_EOL;
		}
		break;

	// duty-removed-mail <member_no> <event_id>: put one removal message into the
	// log for a duty of the caller's choosing, and nothing else. It exists for the
	// one claim that needs a duty WITHOUT a meeting point: on such a duty the two
	// placeholders of the message stand in a paragraph of their own, and a
	// paragraph without a line is not written. Nothing that goes out over HTTP can
	// show that, because a duty nobody can sign up for is the only duty here that
	// has no meeting point, and the message needs a member that was on it.
	case 'duty-removed-mail':
		$repo  = new FG_Repository();
		$mitgl = $repo->get_member_by_number( isset( $args[0] ) ? (string) $args[0] : '' );
		$dienst = $repo->get_event( isset( $args[1] ) ? (int) $args[1] : 0 );

		if ( ! $mitgl || ! $dienst ) {
			fg_state_out( 'missing' );
			break;
		}

		$mailer = new FG_Mailer( $repo );
		fg_state_out( $mailer->send_duty_removed( $mitgl->id, $dienst->id ) ? 'sent' : 'refused' );
		break;

	// mail-fail on|off
	//
	// Let the next delivery fail. A notification that does not go out has to
	// change nothing except the notice, and there is no other way to make a
	// delivery fail on purpose.
	case 'mail-fail':
		$schalter = isset( $args[0] ) ? (string) $args[0] : '';

		if ( 'on' === $schalter ) {
			update_option( 'fg_test_mail_fail', '1', false );
		} elseif ( 'off' === $schalter ) {
			delete_option( 'fg_test_mail_fail' );
		}

		fg_state_out( '1' === (string) get_option( 'fg_test_mail_fail', '' ) ? 'on, die Zustellung schlägt fehl' : 'off, die Zustellung klappt' );
		break;

	// schema <teil> <name>
	//
	// What the database actually holds: a table of this plugin, a column of one
	// of its tables, or the number of rows in the token table. The schema of a
	// release is a claim about the database and not about the code, and reading
	// the code would prove nothing.
	case 'schema':
		global $wpdb;

		$teil = isset( $args[0] ) ? (string) $args[0] : '';
		$name = isset( $args[1] ) ? (string) $args[1] : '';

		if ( 'tabelle' === $teil ) {
			$vorhanden = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $name ) );
			fg_state_out( $vorhanden ? 'da' : 'fehlt' );
		} elseif ( 'spalte' === $teil ) {
			$gefunden = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					$wpdb->prefix . $name,
					isset( $args[2] ) ? (string) $args[2] : ''
				)
			);
			fg_state_out( (int) $gefunden ? 'da' : 'fehlt' );
		} elseif ( 'zeilen' === $teil ) {
			fg_state_out( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . $name ) );
		} else {
			fg_state_out( 'unbekannt' );
		}
		break;

	// migrate-tokens <event_id>
	//
	// The migration of schema 1.7.0, run on a row built for it. A check that can
	// only be made once — on an instance that has not migrated yet — measures
	// nothing the second time it runs, so this one writes a registration whose
	// token still stands in the old column, runs the copy, and reports whether
	// the token arrived in its own table. Afterwards the row is removed again.
	case 'migrate-tokens':
		global $wpdb;

		$event_id  = isset( $args[0] ) ? (int) $args[0] : 0;
		$tabelle   = $wpdb->prefix . 'fg_event_members';
		$token     = $wpdb->prefix . 'fg_event_member_tokens';
		$hash      = hash( 'sha256', 'migrationsprobe-' . $event_id );
		$mitglied  = $repo->get_member_by_number( isset( $args[1] ) ? (string) $args[1] : '0000' );

		if ( ! $event_id || ! $mitglied ) {
			fg_state_out( 'kein-dienst' );
			break;
		}

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $tabelle (event_id, member_id, registered_at, public_ref, source_url, unregister_hash, unregister_expires)
				VALUES (%d, %d, %s, %s, '', %s, %d)",
				$event_id,
				$mitglied->id,
				current_time( 'mysql' ),
				'probe' . substr( md5( (string) $event_id ), 0, 20 ),
				$hash,
				time() + DAY_IN_SECONDS
			)
		);

		$id    = (int) $wpdb->insert_id;
		$vorher = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $token WHERE registration_id = %d", $id ) );

		FG_Schema::copy_unregister_tokens();

		$nachher = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $token WHERE registration_id = %d", $id ) );

		// The second call must not add a second row: a migration that can be
		// repeated has to be able to recognise its own work.
		FG_Schema::copy_unregister_tokens();
		$nochmal = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $token WHERE registration_id = %d", $id ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM $token WHERE registration_id = %d", $id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $tabelle WHERE id = %d", $id ) );

		fg_state_out( "$vorher\t$nachher\t$nochmal" );
		break;

	// mail-handler <art> [<name>]
	//
	// What FG_Mail_Templates::foreign_mail_handler() answers when a mail handler
	// is registered. The three shapes a handler can have are all here, because
	// only one of them is a plain function name and the other two are where a
	// reader of the name would be lost.
	//
	// The filter is registered in this process and nowhere else. The admin screen
	// that shows the notice is a different request and has to find the handler
	// there itself; what this measures is the decision, not the page.
	case 'mail-handler':
		switch ( isset( $args[0] ) ? (string) $args[0] : '' ) {
			case 'funktion':
				add_filter( 'pre_wp_mail', isset( $args[1] ) && '' !== $args[1] ? (string) $args[1] : 'fg_test_mail_anderer' );
				break;

			case 'closure':
				add_filter( 'pre_wp_mail', function () {} );
				break;

			case 'closure-klasse':
				// A closure in the scope of a class, which is how a plugin usually
				// writes it. The class of the plugin is used as the scope, because
				// a class that only exists for this test would have to be defined
				// first, and the shape is the same either way.
				add_filter( 'pre_wp_mail', Closure::bind( function () {}, null, 'FG_Mail_Templates' ) );
				break;

			case 'objekt':
				add_filter( 'pre_wp_mail', array( new class() { public function handle() {} }, 'handle' ) );
				break;

			case 'nichts':
				break;
		}

		fg_state_out( FG_Mail_Templates::foreign_mail_handler() );
		break;

	// --- the wording of the five messages
	//
	// These read and write the mail texts from the shell. The admin screen is
	// the way a club reaches them; the shell needs them because a check has to
	// count rows and read what is really in the table, not what a form sent.

	// mail-text-rows <key>: how many rows stand for this message. The design
	// says one or none, so a count above one is the finding and not a detail.
	case 'mail-text-rows':
		global $wpdb;
		$mail_table = FG_Schema::mail_templates_table();
		fg_state_out(
			(int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM $mail_table WHERE mail_key = %s", isset( $args[0] ) ? (string) $args[0] : '' )
			)
		);
		break;

	// mail-text-body <key> / mail-text-subject <key>: what is stored, untouched.
	case 'mail-text-body':
		fg_state_out( FG_Mail_Texts::stored( isset( $args[0] ) ? (string) $args[0] : '', 'body' ) );
		break;

	case 'mail-text-subject':
		fg_state_out( FG_Mail_Texts::stored( isset( $args[0] ) ? (string) $args[0] : '', 'subject' ) );
		break;

	// mail-placeholders <key>: the placeholders this version allows in this
	// message, one per line, taken from the message itself. A check that counts
	// the placeholders of a message needs this: a number written into the shell
	// is correct until the message grows, and then it fails while saying nothing
	// about which placeholder is missing.
	case 'mail-placeholders':
		$mail = FG_Mail_Texts::get( isset( $args[0] ) ? (string) $args[0] : '' );
		$namen = array();
		foreach ( isset( $mail['placeholders'] ) && is_array( $mail['placeholders'] ) ? $mail['placeholders'] : array() as $platzhalter => $erklaerung ) {
			$namen[] = trim( (string) $platzhalter, '{}' );
		}
		sort( $namen );
		fg_state_out( implode( "\n", $namen ) );
		break;

	// mail-text-set <key> <subject> <body>: write without the check of the
	// screen, which is the point. A club that edits with a newer version and
	// then goes back to an older one leaves a text behind that this version
	// refuses; no form can build that state, so no form can be used to test it.
	case 'mail-text-set':
		$mail_table = FG_Schema::mail_templates_table();
		fg_state_out( fg_mail_text_write( $mail_table, $args[0], $args[1], $args[2] ) );
		break;

	case 'mail-text-reset':
		FG_Mail_Texts::reset( isset( $args[0] ) ? (string) $args[0] : '' );
		fg_state_out( 'reset' );
		break;

	case 'mail-text-notices':
		fg_state_out( count( FG_Mail_Texts::notices() ) );
		break;

	case 'mail-text-clear':
		FG_Mail_Texts::clear_notices();
		fg_state_out( 'cleared' );
		break;

	// mail-holdback <event_id> <member_no>: put a text with a placeholder this
	// version does not know into the table, then let a real message go out to a
	// real ride and report what happened. Four numbers, because four different
	// things can fail on their own: the send can report success, the log can
	// grow anyway, the club can hear nothing about it, and the log can grow for
	// another reason.
	case 'mail-holdback':
		global $wpdb;

		$log          = $wpdb->prefix . 'fg_test_mail_log';
		$option_vor   = (string) get_option( 'fg_test_mail_enabled', '0' );
		$mail_table   = FG_Schema::mail_templates_table();

		update_option( 'fg_test_mail_enabled', '1', false );
		FG_Mail_Texts::clear_notices();

		fg_mail_text_write(
			$mail_table,
			FG_Mail_Texts::RIDE_PUBLISHED,
			'Ein Platzhalter aus der Zukunft',
			// {{Anrede}} is the greeting as a whole since version 1.15.0, so the
			// stored text starts with it and does not put a word in front of it.
			'{{Anrede}}, dein Eintrag ist da: {{Erfunden}}.'
		);

		// A member the caller names, so that the ride has a real address to go
		// to. The wording in the table is the thing under test, not the ride.
		$mitglied = $repo->get_member_by_number( isset( $args[1] ) ? (string) $args[1] : '' );

		$ride = $repo->create_ride(
			array(
				'event_id'  => isset( $args[0] ) ? (int) $args[0] : 0,
				'mode'      => FG_RIDE_MODE_OFFER,
				'origin'    => 'Innenstadt',
				'member_id' => $mitglied ? $mitglied->id : 0,
			)
		);

		$vorher = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $log" );

		$mailer = new FG_Mailer( $repo );
		$weg    = $mailer->send_published_confirmation(
			$ride['id'],
			'https://example.invalid/loeschen'
		);

		$nachher = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $log" );
		$notizen = count( FG_Mail_Texts::notices() );

		// The wording goes back, because a row that names a placeholder of
		// another version would stop every message of this one from going out
		// for as long as it stands there. The notice stays: it is what the club
		// has to see on the screen, and a command that cleaned it up would
		// leave the caller nothing to read. FG_Mail_Texts::clear_notices() is
		// the way out, and the suite checks that it is.
		FG_Mail_Texts::reset( FG_Mail_Texts::RIDE_PUBLISHED );
		$repo->delete_ride( $ride['id'] );

		if ( '1' === $option_vor ) {
			update_option( 'fg_test_mail_enabled', '1', false );
		} else {
			delete_option( 'fg_test_mail_enabled' );
		}

		// Tab, for the same reason as in mail-anrede(): a list is joined with a
		// comma there, and the caller has to be able to read the parts back.
		fg_state_out( implode( "\t", array( $weg ? 'sent' : 'held-back', $vorher, $nachher, $notizen ) ) );
		break;

	// mail-anrede <event_id> <creator_member_no> <asker_member_no> [geloescht]
	//
	// The two greetings of a contact request, from the messages that really
	// went out. Both sides are members since schema 1.4.0, so both greetings
	// carry both names of a member, and the fallback for an address of nobody is
	// not reachable on this path any more. What replaced it as the case worth a
	// test is the member who left the club after offering the ride: the ride
	// still points at the row ID, the row is gone, and there is no name and no
	// address to write to. The flag "geloescht" builds exactly that — it makes
	// the ride, deletes the member and then lets the notifier run.
	case 'mail-anrede':
		global $wpdb;

		$log        = $wpdb->prefix . 'fg_test_mail_log';
		$option_vor = (string) get_option( 'fg_test_mail_enabled', '0' );

		update_option( 'fg_test_mail_enabled', '1', false );

		$ersteller = $repo->get_member_by_number( isset( $args[1] ) ? (string) $args[1] : '' );
		$fragend   = $repo->get_member_by_number( isset( $args[2] ) ? (string) $args[2] : '' );
		$vorher_id = (int) $wpdb->get_var( "SELECT COALESCE( MAX( id ), 0 ) FROM $log" );

		$ride = $repo->create_ride(
			array(
				'event_id'  => isset( $args[0] ) ? (int) $args[0] : 0,
				'mode'      => FG_RIDE_MODE_OFFER,
				'origin'    => 'Innenstadt',
				'member_id' => $ersteller ? $ersteller->id : 0,
			)
		);

		if ( 'geloescht' === ( isset( $args[3] ) ? (string) $args[3] : '' ) && $ersteller ) {
			$repo->delete_member( $ersteller->id );
		}

		$mailer = new FG_Mailer( $repo );
		$wege   = $mailer->send_contact_notifications( $ride['id'], $fragend ? $fragend->id : 0 );

		$zeilen = $wpdb->get_col( $wpdb->prepare( "SELECT mail_body FROM $log WHERE id > %d ORDER BY id ASC", $vorher_id ) );

		$anrede = 'fg_state_greeting';

		// Two messages, creator first: send_contact_notifications() writes the
		// creator's before the interested person's.
		$an_kontrakt = $anrede( isset( $zeilen[0] ) ? $zeilen[0] : '' );
		$an_fragend  = $anrede( isset( $zeilen[1] ) ? $zeilen[1] : '' );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $log WHERE id > %d",
				$vorher_id
			)
		);
		$repo->delete_ride( $ride['id'] );

		if ( '1' === $option_vor ) {
			update_option( 'fg_test_mail_enabled', '1', false );
		} else {
			delete_option( 'fg_test_mail_enabled' );
		}

		// Tab, not the comma that fg_state_out() uses for a list: a greeting ends
		// in a comma of its own, and "Hallo Greta,,Hallo Frieda,,1,1" cannot be
		// read back without knowing in which order the parts were joined.
		fg_state_out(
			implode(
				"\t",
				array(
					'' === $an_kontrakt ? 'KEINE-MAIL' : $an_kontrakt,
					'' === $an_fragend ? 'KEINE-MAIL' : $an_fragend,
					$wege['creator'] ? 1 : 0,
					$wege['requester'] ? 1 : 0,
				)
			)
		);
		break;

	// mail-greeting <event_id> <member_no> <email> <first> <last>
	//
	// A real member, a real registration, a real message, and then the first
	// line of what actually went out. The greeting is the one place in a mail
	// where a missing name shows up as a broken sentence, so it is checked on
	// the message and not on the helper that builds it.
	case 'mail-greeting':
		global $wpdb;

		$log        = $wpdb->prefix . 'fg_test_mail_log';
		$option_vor = (string) get_option( 'fg_test_mail_enabled', '0' );

		update_option( 'fg_test_mail_enabled', '1', false );

		$event_id  = isset( $args[0] ) ? (int) $args[0] : 0;
		$member_no = isset( $args[1] ) ? (string) $args[1] : '7001';
		$email     = isset( $args[2] ) ? (string) $args[2] : '7001@angeln.example.org';
		$vorname   = isset( $args[3] ) ? (string) $args[3] : '';
		$name      = isset( $args[4] ) ? (string) $args[4] : '';

		// The newest row of the log is read only if this run put one there. A
		// command that sends nothing and reads the newest row anyway prints the
		// greeting of an earlier run, and every check built on it passes for a
		// reason that has nothing to do with the code under test.
		$vorher_id = (int) $wpdb->get_var( "SELECT COALESCE( MAX( id ), 0 ) FROM $log" );

		$vorhanden = $repo->get_member_by_number( $member_no );
		if ( $vorhanden ) {
			$repo->delete_member( $vorhanden->id );
		}

		$repo->insert_member(
			array(
				'member_no'  => $member_no,
				'email'      => $email,
				'first_name' => $vorname,
				'last_name'  => $name,
			)
		);

		$member = $repo->get_member_by_number( $member_no );
		$created = $member ? $repo->create_registration( $event_id, $member->id, '' ) : array( 'id' => 0 );

		$mailer = new FG_Mailer( $repo );
		$mailer->send_duty_signup( (int) $created['id'], 'https://example.invalid/abmelden' );

		$zeile = (string) $wpdb->get_var( $wpdb->prepare( "SELECT mail_body FROM $log WHERE id > %d ORDER BY id ASC LIMIT 1", $vorher_id ) );

		if ( '' === $zeile ) {
			// Said out loud rather than answered with an empty line, because an
			// empty line would be a greeting of its own and would be checked.
			$zeile = "KEINE-MAIL: die Anmeldung ging nicht raus";
		}

		$greeting = fg_state_greeting( $zeile );

		// Everything this command built is removed again, including the log row,
		// so the count other sections read is the count they left behind.
		$wpdb->query( "DELETE FROM $log WHERE mail_to = " . $wpdb->prepare( '%s', $email ) );
		$repo->delete_registration( (int) $created['id'] );
		if ( $member ) {
			$repo->delete_member( $member->id );
		}

		if ( '1' === $option_vor ) {
			update_option( 'fg_test_mail_enabled', '1', false );
		} else {
			delete_option( 'fg_test_mail_enabled' );
		}

		fg_state_out( $greeting );
		break;

	case 'tables':
		fg_state_out( FG_Schema::tables_exist() ? 'present' : 'missing' );
		break;

	// list-page-path <shortcode>
	//
	// Prints the query part of the path of the published page that carries the
	// shortcode, for example "?page_id=98". A suite that needs a real public
	// page — the admin suite does, because the signup nonce only exists there —
	// used to read the path out of the HTTP suite's fixture file. That is a
	// dependency between two suites for something that is a fact about the
	// installation, so the installation is asked instead.
	case 'list-page-path':
		$shortcode = isset( $args[0] ) ? (string) $args[0] : '[arbeitsdienste]';
		$found     = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'orderby'          => 'ID',
				'suppress_filters' => false,
			)
		);
		$path = '';
		foreach ( $found as $page_id ) {
			if ( false !== strpos( (string) get_post_field( 'post_content', $page_id ), $shortcode ) ) {
				$path = '?page_id=' . (int) $page_id;
				break;
			}
		}
		fg_state_out( $path );
		break;

	default:
		fg_state_out( 'unknown-command' );
		exit( 1 );
}
