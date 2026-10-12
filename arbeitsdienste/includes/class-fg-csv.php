<?php
/**
 * Writes the registration list of a work service as CSV text.
 *
 * This class does the one thing it says and nothing else: it turns rows into
 * text. It reads no database, it sends no header and it knows no screen, so the
 * part that can be wrong — how a value is written — can be checked without a
 * browser, and a claim about it does not depend on anything else being right.
 *
 * The export is built by hand and not with fputcsv(). Three reasons, and each of
 * them cost somebody an afternoon somewhere:
 *
 * 1. **The separator.** A German Excel opens a file with a semicolon, a
 *    semicolon in a field is therefore not unusual in this club's data, and
 *    fputcsv() only quotes when the *comma* appears. The same data with the
 *    comma as a separator is read by an English Excel and by LibreOffice without
 *    a dialog, and a club that has to click a dialog to see its own participant
 *    list will ask why the export "does not work". So: semicolon, and the
 *    quoting written out here.
 * 2. **A value may contain the separator, a quote or a line break.** A surname
 *    is typed by a person and all three are possible. A field that carries one
 *    of them is put in quotes and its own quotes are doubled; without that a
 *    second surname turns one member into two half-rows and shifts every column
 *    after it — a list that looks plausible and is wrong.
 * 3. **A field that begins with =, +, - or @ is a formula.** Excel and LibreOffice
 *    run the content of such a cell. The club opens a file it downloaded from
 *    its own website, and a member whose name was typed as `=HYPERLINK(...)`
 *    would have it run on the machine of the person who opens the list. A
 *    leading apostrophe is the mark every spreadsheet uses for "this is text"
 *    and it disappears from the visible cell.
 *
 * The text goes out with a UTF-8 mark at the front (BOM). Without it, Excel of
 * an older version reads "Müller" as "MÃ¼ller", and the club concludes that the
 * export is broken rather than that the program guessed the encoding.
 *
 * @package Arbeitsdienste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV text of the registration list of a work service.
 */
final class FG_Csv {

	/**
	 * Separator. A semicolon, because that is what a German Excel opens on a
	 * double click.
	 *
	 * @var string
	 */
	const TRENNER = ';';

	/**
	 * End of a line. CRLF is what RFC 4180 names and what Excel writes; a file
	 * with LF alone is read by every program here, and one with CRLF is read by
	 * the programs that are not from here as well.
	 *
	 * @var string
	 */
	const ZEILENENDE = "\r\n";

	/**
	 * The registration list of one work service as CSV text, mark at the front.
	 *
	 * @param FG_Event $event         Work service the rows belong to.
	 * @param array    $registrations Rows from FG_Repository::get_event_registrations().
	 * @return string
	 */
	public static function registrations( FG_Event $event, array $registrations ) {
		// The header goes through the same writer as the rows. Writing it as the
		// array it is would put a nested array into the list, and implode() would
		// then write the word "Array" into the file where a column name belongs.
		$zeilen = array( self::zeile( self::kopf( $event ) ) );

		foreach ( $registrations as $row ) {
			$zeilen[] = self::zeile(
				array(
					(string) $event->title,
					(string) $event->event_date,
					(string) $event->event_time,
					(string) $row['member']->member_no,
					(string) $row['member']->first_name,
					(string) $row['member']->last_name,
					(string) $row['member']->email,
					// An empty work group is written as an empty field and not as
					// the stroke the screen shows: in a file a stroke is a value,
					// and a club that counts groups would count the stroke with it.
					(string) $row['member']->work_group,
					(string) $row['registration']->registered_at,
					self::eingetragen_von( $row['registration']->added_by_admin ),
					(string) (int) $row['registration']->notified_count,
					(string) $row['registration']->source_url,
				)
			);
		}

		return "\xEF\xBB\xBF" . implode( self::ZEILENENDE, $zeilen ) . self::ZEILENENDE;
	}

	/**
	 * The header line.
	 *
	 * The columns are the ones the participant list on the screen shows, plus the
	 * duty itself and the page the registration came from. What the table of the
	 * registrations holds **besides** that is not in here, and that is a decision
	 * with a reason rather than an oversight:
	 *
	 * - `id`, `event_id` and `member_id` are numbers of two tables. They are of
	 *   no use outside this installation and they say more about the inside of
	 *   the plugin than about the club.
	 * - **`unregister_hash` and `unregister_expires` are the pair that cancels a
	 *   registration.** The hash *is* the token, in stored form: The removal link
	 *   of a member deletes exactly the row whose stored hash matches. A file
	 *   with this column is therefore a list of links, and anyone who has it can
	 *   take every person in it out of every duty they are in. These two columns
	 *   stay in the table and do not go into a file.
	 *
	 * @param FG_Event $event Work service.
	 * @return array<string>
	 */
	private static function kopf( FG_Event $event ) {
		return array(
			__( 'Arbeitsdienst', 'arbeitsdienste' ),
			__( 'Datum', 'arbeitsdienste' ),
			__( 'Beginn', 'arbeitsdienste' ),
			__( 'Mitgliedsnummer', 'arbeitsdienste' ),
			__( 'Vorname', 'arbeitsdienste' ),
			__( 'Nachname', 'arbeitsdienste' ),
			__( 'E-Mail-Adresse', 'arbeitsdienste' ),
			__( 'Arbeitsgruppe', 'arbeitsdienste' ),
			__( 'Angemeldet am', 'arbeitsdienste' ),
			__( 'Eingetragen von', 'arbeitsdienste' ),
			__( 'E-Mails', 'arbeitsdienste' ),
			__( 'Herkunftsseite', 'arbeitsdienste' ),
		);
	}

	/**
	 * Who entered the member, as a value of the file.
	 *
	 * The screen says "Redaktion" or "Mitglied selbst". Both are words in the
	 * interface language, and an export that changes with the language of the
	 * installation is a file that cannot be compared with an older one. So: two
	 * fixed values, and the words stay on the screen.
	 *
	 * @param mixed $added_by_admin Whether the club entered the member.
	 * @return string
	 */
	private static function eingetragen_von( $added_by_admin ) {
		return $added_by_admin ? 'Redaktion' : 'Mitglied selbst';
	}

	/**
	 * One line, without the line end.
	 *
	 * @param array $felder Values of the line.
	 * @return string
	 */
	private static function zeile( array $felder ) {
		$raus = array();

		foreach ( $felder as $feld ) {
			$raus[] = self::feld( (string) $feld );
		}

		return implode( self::TRENNER, $raus );
	}

	/**
	 * One field, written so that it comes back out of the file unchanged.
	 *
	 * @param string $wert Value.
	 * @return string
	 */
	private static function feld( $wert ) {
		$wert = (string) $wert;

		// The mark for a value that a spreadsheet would take for a formula. It
		// goes in front, inside the field, and not around the field: A quote
		// around a cell does not stop Excel from reading = inside it.
		if ( '' !== $wert && in_array( $wert[0], array( '=', '+', '-', '@' ), true ) ) {
			$wert = "'" . $wert;
		}

		$sonderzeichen = array( self::TRENNER, '"', "\r", "\n" );

		foreach ( $sonderzeichen as $zeichen ) {
			if ( false !== strpos( $wert, $zeichen ) ) {
				return '"' . str_replace( '"', '""', $wert ) . '"';
			}
		}

		// A space at the edge is eaten by some programs and kept by others, so
		// the value that comes back would not be the value that went in.
		if ( '' !== $wert && ( ' ' === $wert[0] || ' ' === substr( $wert, -1 ) ) ) {
			return '"' . str_replace( '"', '""', $wert ) . '"';
		}

		return $wert;
	}
}
