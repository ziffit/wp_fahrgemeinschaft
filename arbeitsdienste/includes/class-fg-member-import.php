<?php
/**
 * Member list import from a CSV file.
 *
 * @package Arbeitsdienste
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads a member list from CSV text and applies it to the member table.
 *
 * The member number is the identity. A number that is not in the table yet is
 * created, a number that is in it has its name and address brought up to date,
 * and a member whose number is not in the file at all is only listed in the
 * report. Nobody is deleted by an import: a file that is a week old must not
 * take a member with it.
 *
 * Every file is checked completely before a single row is written. An import of
 * three hundred members where the last row is wrong must not leave the first two
 * hundred and ninety-nine in the table, so a file that is refused has changed
 * nothing at all.
 */
final class FG_Member_Import {
	/**
	 * The file holds no usable row.
	 */
	const ERROR_EMPTY = 'empty';

	/**
	 * A required column is not in the header.
	 */
	const ERROR_MISSING_COLUMN = 'missing_column';

	/**
	 * The file has more rows than one import may carry.
	 */
	const ERROR_TOO_MANY_ROWS = 'too_many_rows';

	/**
	 * A row has fewer cells than the header has columns.
	 */
	const ERROR_RAGGED_ROW = 'ragged_row';

	/**
	 * A row has no member number.
	 */
	const ERROR_EMPTY_NUMBER = 'empty_number';

	/**
	 * A row has no first name or no last name.
	 */
	const ERROR_MISSING_NAME = 'missing_name';

	/**
	 * A row has an e-mail address that is not a valid address.
	 */
	const ERROR_INVALID_EMAIL = 'invalid_email';

	/**
	 * The same member number stands in the file twice.
	 */
	const ERROR_DUPLICATE_NUMBER = 'duplicate_number';

	/**
	 * The same e-mail address stands in the file twice.
	 */
	const ERROR_DUPLICATE_EMAIL = 'duplicate_email';

	/**
	 * A value does not fit the column it belongs in.
	 */
	const ERROR_TOO_LONG = 'too_long';

	/**
	 * A member number in the file belongs to a different member than its address.
	 */
	const ERROR_EMAIL_TAKEN = 'email_taken';

	/**
	 * A row that passed every check could not be written.
	 */
	const ERROR_WRITE_FAILED = 'write_failed';

	/**
	 * Header names that are accepted for each field.
	 *
	 * The list is short on purpose. Every entry is a name that member
	 * administrations actually write, and a club whose export uses something
	 * else is told which column is missing instead of watching four of its
	 * columns be ignored. When a real file arrives, this is the one list that
	 * has to be extended.
	 *
	 * @var array<string, string[]>
	 */
	private static $header_aliases = array(
		'member_no'  => array( 'mitgliedsnummer', 'mitglieds-nr', 'mitglieds-nummer', 'mitglied-nr', 'nummer', 'nr', 'member_no', 'member number' ),
		'email'      => array( 'e-mail', 'email', 'e-mail-adresse', 'email-adresse', 'mail', 'member_email' ),
		'first_name' => array( 'vorname', 'first name', 'first_name' ),
		'last_name'  => array( 'nachname', 'familienname', 'last name', 'last_name', 'surname' ),
	);

	/**
	 * Field labels as the club knows them, used in messages and the header check.
	 *
	 * @var array<string, string>
	 */
	private static $field_labels = array(
		'member_no'  => 'Mitgliedsnummer',
		'email'      => 'E-Mail-Adresse',
		'first_name' => 'Vorname',
		'last_name'  => 'Nachname',
	);

	/**
	 * Repository.
	 *
	 * @var FG_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param FG_Repository $repository Repository.
	 */
	public function __construct( FG_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Read a member list out of CSV text.
	 *
	 * The result is a list of rows in the order of the file, each with the four
	 * fields under their own names. Nothing here knows the database; a file that
	 * cannot be read is refused with a reason, never repaired.
	 *
	 * @param string $raw File contents.
	 * @return array{ok: bool, error: array, rows: array<int, array<string, string>>}
	 */
	public function parse( $raw ) {
		$text = $this->to_utf8( $raw );
		if ( is_array( $text ) ) {
			return $this->failure( self::ERROR_EMPTY, array( 'hinweis' => $text['hinweis'] ) );
		}

		$delimiter = ';';
		$lines     = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $text ) );

		// A file that ends with a line break leaves one empty element behind. Only
		// trailing ones are dropped: an empty line in the middle of the file is a
		// row without a member number, and that is worth a complaint.
		while ( ! empty( $lines ) && '' === trim( (string) end( $lines ) ) ) {
			array_pop( $lines );
		}

		if ( empty( $lines ) ) {
			return $this->failure( self::ERROR_EMPTY );
		}

		// Spreadsheet programs in the German locale write a declaration line that
		// names the separator, and then every other program on earth ignores it.
		// The line is counted, because a complaint about "line 3" that names line
		// 2 of the file is a complaint the club cannot act on.
		$first        = array_shift( $lines );
		$offset       = 1;
		if ( preg_match( '/^sep\s*=\s*(.)$/iu', trim( (string) $first ), $found ) ) {
			$delimiter = $found[1];
			$offset    = 2;
		} else {
			array_unshift( $lines, $first );
			$delimiter = $this->guess_delimiter( (string) $first );
		}

		if ( empty( $lines ) ) {
			return $this->failure( self::ERROR_EMPTY );
		}

		$header_cells = $this->split_line( array_shift( $lines ), $delimiter );
		$columns      = $this->map_header( $header_cells );

		$missing = array();
		foreach ( array_keys( self::$field_labels ) as $field ) {
			if ( ! isset( $columns[ $field ] ) ) {
				$missing[] = self::$field_labels[ $field ];
			}
		}

		if ( ! empty( $missing ) ) {
			return $this->failure(
				self::ERROR_MISSING_COLUMN,
				array( 'spalten' => $missing, 'erwartet' => array_values( self::$field_labels ) )
			);
		}

		if ( count( $lines ) > FG_Schema::MEMBER_IMPORT_MAX ) {
			return $this->failure(
				self::ERROR_TOO_MANY_ROWS,
				array(
					'zeilen' => count( $lines ),
					'limit'  => FG_Schema::MEMBER_IMPORT_MAX,
				)
			);
		}

		$rows           = array();
		$seen_numbers   = array();
		$seen_emails    = array();
		$expected_cells = count( $header_cells );

		// The line number is the line in the file as a person reading it in a
		// spreadsheet counts it: the header is line 1, the first data row is the
		// line after it, plus one more if a declaration line stood in front.
		foreach ( $lines as $index => $line ) {
			$zeile = $index + $offset + 1;
			$cells = $this->split_line( $line, $delimiter );

			// A line with nothing on it is not a short line, it is a member
			// without a number. It is checked before the cell count, because
			// "this line has 1 of 4 fields" sends the club looking for a column
			// that was left out instead of for the empty line in front of them.
			if ( empty( $cells ) || ( 1 === count( $cells ) && '' === trim( (string) $cells[0] ) ) ) {
				return $this->failure( self::ERROR_EMPTY_NUMBER, array( 'zeile' => $zeile ) );
			}

			if ( count( $cells ) < $expected_cells ) {
				return $this->failure(
					self::ERROR_RAGGED_ROW,
					array(
						'zeile'   => $zeile,
						'erwartet' => $expected_cells,
						'gefunden' => count( $cells ),
					)
				);
			}

			$row = array(
				'member_no'  => $this->clean( $cells[ $columns['member_no'] ] ),
				'email'      => $this->clean( $cells[ $columns['email'] ] ),
				'first_name' => $this->clean( $cells[ $columns['first_name'] ] ),
				'last_name'  => $this->clean( $cells[ $columns['last_name'] ] ),
			);

			if ( '' === $row['member_no'] ) {
				return $this->failure( self::ERROR_EMPTY_NUMBER, array( 'zeile' => $zeile ) );
			}

			$email = $this->repository->normalize_email( $row['email'] );
			if ( false === $email ) {
				return $this->failure(
					self::ERROR_INVALID_EMAIL,
					array(
						'zeile' => $zeile,
						'wert'  => $row['email'],
					)
				);
			}
			$row['email'] = (string) $email;

			// A member number is compared without regard to case, because the
			// column is compared that way too. Two rows that differ only in case
			// are the same member twice, and the second one would quietly undo
			// the first.
			$key_number = $this->fold( $row['member_no'] );
			if ( isset( $seen_numbers[ $key_number ] ) ) {
				return $this->failure(
					self::ERROR_DUPLICATE_NUMBER,
					array(
						'zeile'        => $zeile,
						'nummer'       => $row['member_no'],
						'erste_zeile'  => $seen_numbers[ $key_number ],
					)
				);
			}
			$seen_numbers[ $key_number ] = $zeile;

			if ( isset( $seen_emails[ $row['email'] ] ) ) {
				return $this->failure(
					self::ERROR_DUPLICATE_EMAIL,
					array(
						'zeile'       => $zeile,
						'email'       => $row['email'],
						'erste_zeile' => $seen_emails[ $row['email'] ],
					)
				);
			}
			$seen_emails[ $row['email'] ] = $zeile;

			foreach ( array( 'member_no' => FG_Schema::MEMBER_NO_MAX, 'first_name' => FG_Schema::MEMBER_NAME_MAX, 'last_name' => FG_Schema::MEMBER_NAME_MAX ) as $field => $limit ) {
				if ( $this->string_length( $row[ $field ] ) > $limit ) {
					return $this->failure(
						self::ERROR_TOO_LONG,
						array(
							'zeile' => $zeile,
							'feld'  => self::$field_labels[ $field ],
							'limit' => $limit,
						)
					);
				}
			}

			if ( '' === $row['first_name'] || '' === $row['last_name'] ) {
				return $this->failure( self::ERROR_MISSING_NAME, array( 'zeile' => $zeile ) );
			}

			$row['zeile'] = $zeile;
			$rows[]       = $row;
		}

		if ( empty( $rows ) ) {
			return $this->failure( self::ERROR_EMPTY );
		}

		return array(
			'ok'    => true,
			'error' => array(),
			'rows'  => $rows,
		);
	}

	/**
	 * Apply a parsed member list to the member table.
	 *
	 * The rows are worked out against the stored members first and only then
	 * written, so a row that turns out to collide with a member in the database
	 * stops the whole import while nothing has been changed yet.
	 *
	 * @param array $rows Rows as read by parse().
	 * @return array Report: ok, error, created, updated, unchanged, missing, rows.
	 */
	public function apply( array $rows ) {
		$stored_by_number = array();
		$stored_by_email  = array();

		foreach ( $this->repository->get_members_page() as $member ) {
			$stored_by_number[ $this->fold( $member->member_no ) ] = $member;
			$stored_by_email[ $member->email ]                   = $member;
		}

		$file_numbers = array();
		$create       = array();
		$change       = array();
		$unchanged    = 0;

		foreach ( $rows as $row ) {
			$key   = $this->fold( $row['member_no'] );
			$email = $row['email'];
			$file_numbers[ $key ] = true;

			$existing = isset( $stored_by_number[ $key ] ) ? $stored_by_number[ $key ] : null;

			// The address must be free, or it must be the one this member already
			// has. Anything else would leave two members with one address, and the
			// registration form identifies a member by that pair.
			$owner = isset( $stored_by_email[ $email ] ) ? $stored_by_email[ $email ] : null;
			if ( $owner && ( ! $existing || $owner->id !== $existing->id ) ) {
				return $this->failure(
					self::ERROR_EMAIL_TAKEN,
					array(
						'zeile'      => isset( $row['zeile'] ) ? (int) $row['zeile'] : 0,
						'nummer'     => $row['member_no'],
						'email'      => $email,
						'gehoert_zu' => $owner->member_no,
					)
				);
			}

			if ( ! $existing ) {
				$create[] = $row;
				continue;
			}

			if (
				$existing->email === $email
				&& $existing->first_name === $row['first_name']
				&& $existing->last_name === $row['last_name']
			) {
				++$unchanged;
				continue;
			}

			$change[] = $row;
		}

		$missing = array();
		foreach ( $stored_by_number as $key => $member ) {
			if ( ! isset( $file_numbers[ $key ] ) ) {
				$missing[] = $member;
			}
		}

		$created = 0;
		foreach ( $create as $row ) {
			if ( ! $this->repository->insert_member( $row ) ) {
				return $this->failure(
					self::ERROR_WRITE_FAILED,
					array( 'zeile' => isset( $row['zeile'] ) ? (int) $row['zeile'] : 0 )
				);
			}
			++$created;
		}

		$updated = 0;
		foreach ( $change as $row ) {
			$member = $this->repository->get_member_by_number( $row['member_no'] );
			if ( ! $member || ! $this->repository->update_member( $member->id, $row ) ) {
				return $this->failure(
					self::ERROR_WRITE_FAILED,
					array( 'zeile' => isset( $row['zeile'] ) ? (int) $row['zeile'] : 0 )
				);
			}
			++$updated;
		}

		return array(
			'ok'        => true,
			'error'     => array(),
			'created'   => $created,
			'updated'   => $updated,
			'unchanged' => $unchanged,
			'missing'   => $missing,
			'rows'      => count( $rows ),
		);
	}

	/**
	 * The sentence that goes with a refusal.
	 *
	 * Every refusal names what is wrong and where, and none of them is a
	 * sentence that leaves the club guessing which row to look at.
	 *
	 * @param array $error Error as returned by parse() or apply().
	 * @return string
	 */
	public static function error_message( array $error ) {
		$code = isset( $error['code'] ) ? (string) $error['code'] : '';
		$get  = static function ( $key, $default = '' ) use ( $error ) {
			return isset( $error[ $key ] ) ? $error[ $key ] : $default;
		};

		switch ( $code ) {
			case self::ERROR_EMPTY:
				return isset( $error['hinweis'] )
					? sprintf(
						/* translators: %s: reason the file could not be read. */
						__( 'Die Datei konnte nicht gelesen werden (%s). Es wurde nichts importiert.', 'arbeitsdienste' ),
						(string) $error['hinweis']
					)
					: __( 'Die Datei enthält keine Datenzeile. Es wurde nichts importiert.', 'arbeitsdienste' );

			case self::ERROR_MISSING_COLUMN:
				return sprintf(
					/* translators: 1: list of missing columns, 2: list of expected columns. */
					__( 'In der Kopfzeile fehlt: %1$s. Erwartet werden die Spalten %2$s. Es wurde nichts importiert.', 'arbeitsdienste' ),
					implode( ', ', (array) $get( 'spalten' ) ),
					implode( ', ', (array) $get( 'erwartet' ) )
				);

			case self::ERROR_TOO_MANY_ROWS:
				return sprintf(
					/* translators: 1: number of rows in the file, 2: largest accepted number. */
					__( 'Die Datei hat %1$d Datenzeilen, ein Import nimmt höchstens %2$d auf. Es wurde nichts importiert.', 'arbeitsdienste' ),
					(int) $get( 'zeilen' ),
					(int) $get( 'limit' )
				);

			case self::ERROR_RAGGED_ROW:
				return sprintf(
					/* translators: 1: line number, 2: expected cell count, 3: found cell count. */
					__( 'Zeile %1$d hat %2$d Felder, die Kopfzeile hat %3$d. Es wurde nichts importiert.', 'arbeitsdienste' ),
					(int) $get( 'zeile' ),
					(int) $get( 'gefunden' ),
					(int) $get( 'erwartet' )
				);

			case self::ERROR_EMPTY_NUMBER:
				return sprintf(
					/* translators: %d: line number. */
					__( 'In Zeile %d fehlt die Mitgliedsnummer. Es wurde nichts importiert.', 'arbeitsdienste' ),
					(int) $get( 'zeile' )
				);

			case self::ERROR_MISSING_NAME:
				return sprintf(
					/* translators: %d: line number. */
					__( 'In Zeile %d fehlt der Vorname oder der Nachname. Es wurde nichts importiert.', 'arbeitsdienste' ),
					(int) $get( 'zeile' )
				);

			case self::ERROR_INVALID_EMAIL:
				return sprintf(
					/* translators: 1: line number, 2: the value that was there. */
					__( 'In Zeile %1$d steht keine gültige E-Mail-Adresse: „%2$s“. Es wurde nichts importiert.', 'arbeitsdienste' ),
					(int) $get( 'zeile' ),
					(string) $get( 'wert' )
				);

			case self::ERROR_DUPLICATE_NUMBER:
				return sprintf(
					/* translators: 1: member number, 2: second line number, 3: first line number. */
					__( 'Die Mitgliedsnummer %1$s steht zweimal in der Datei, in Zeile %2$d und in Zeile %3$d. Es wurde nichts importiert.', 'arbeitsdienste' ),
					(string) $get( 'nummer' ),
					(int) $get( 'zeile' ),
					(int) $get( 'erste_zeile' )
				);

			case self::ERROR_DUPLICATE_EMAIL:
				return sprintf(
					/* translators: 1: e-mail address, 2: second line number, 3: first line number. */
					__( 'Die E-Mail-Adresse %1$s steht zweimal in der Datei, in Zeile %2$d und in Zeile %3$d. Jede E-Mail-Adresse gehört zu genau einem Mitglied. Es wurde nichts importiert.', 'arbeitsdienste' ),
					(string) $get( 'email' ),
					(int) $get( 'zeile' ),
					(int) $get( 'erste_zeile' )
				);

			case self::ERROR_TOO_LONG:
				return sprintf(
					/* translators: 1: field label, 2: line number, 3: largest accepted length. */
					__( '%1$s in Zeile %2$d ist zu lang, es sind höchstens %3$d Zeichen erlaubt. Es wurde nichts importiert.', 'arbeitsdienste' ),
					(string) $get( 'feld' ),
					(int) $get( 'zeile' ),
					(int) $get( 'limit' )
				);

			case self::ERROR_EMAIL_TAKEN:
				return sprintf(
					/* translators: 1: line number, 2: member number from the file, 3: e-mail address, 4: member number that already owns the address. */
					__( 'In Zeile %1$d gehört die E-Mail-Adresse %3$s zu einem anderen Mitglied, nämlich zu Nummer %4$s. Mitgliedsnummer %2$s kann sie nicht übernehmen. Es wurde nichts importiert.', 'arbeitsdienste' ),
					(int) $get( 'zeile' ),
					(string) $get( 'nummer' ),
					(string) $get( 'email' ),
					(string) $get( 'gehoert_zu' )
				);

			case self::ERROR_WRITE_FAILED:
				return sprintf(
					/* translators: %d: line number. */
					__( 'Zeile %d konnte nicht gespeichert werden. Der Import wurde abgebrochen.', 'arbeitsdienste' ),
					(int) $get( 'zeile' )
				);

			default:
				// A reason this class does not know must not be dressed up as a
				// write failure: that sentence sends the club looking at the
				// database, and the real problem is somewhere in the code. It
				// says so instead, and names the code it was handed.
				return sprintf(
					/* translators: %s: internal reason code. */
					__( 'Der Import wurde aus einem unbekannten Grund abgebrochen (%s). Es wurde nichts importiert.', 'arbeitsdienste' ),
					'' === $code ? '(ohne Angabe)' : $code
				);
		}
	}

	/**
	 * Build a refusal result.
	 *
	 * @param string $code   Reason code.
	 * @param array  $detail Reason details.
	 * @return array
	 */
	private function failure( $code, array $detail = array() ) {
		return array(
			'ok'    => false,
			'error' => array_merge( array( 'code' => (string) $code ), $detail ),
			'rows'  => array(),
		);
	}

	/**
	 * Bring file contents into UTF-8.
	 *
	 * A German member administration still exports ISO-8859-1 now and then. Such
	 * a file is converted rather than refused, because the conversion is exact
	 * for that encoding. Anything that is neither UTF-8 nor readable as UTF-8 is
	 * refused with a reason, because guessing an encoding is how a name comes
	 * back as three different names.
	 *
	 * @param mixed $raw File contents.
	 * @return string|array Text, or a hint why the file was refused.
	 */
	private function to_utf8( $raw ) {
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array( 'hinweis' => __( 'die Datei ist leer', 'arbeitsdienste' ) );
		}

		// A byte order mark is a sign of a UTF-8 file and is not part of the first
		// header name, so it has to go before anything is read.
		if ( 0 === strncmp( $raw, "\xEF\xBB\xBF", 3 ) ) {
			$raw = substr( $raw, 3 );
		}

		if ( function_exists( 'mb_check_encoding' ) && mb_check_encoding( $raw, 'UTF-8' ) ) {
			return $raw;
		}

		if ( function_exists( 'mb_check_encoding' ) && function_exists( 'mb_convert_encoding' )
			&& mb_check_encoding( $raw, 'ISO-8859-1' ) ) {
			return (string) mb_convert_encoding( $raw, 'UTF-8', 'ISO-8859-1' );
		}

		return array( 'hinweis' => __( 'die Datei ist weder UTF-8 noch ISO-8859-1', 'arbeitsdienste' ) );
	}

	/**
	 * Guess the field separator from the header line.
	 *
	 * A German spreadsheet writes a semicolon, an export written by a program
	 * writes a comma. Whichever appears more often in the header wins, and a
	 * header with neither is a single-column file that is refused further down
	 * for missing columns.
	 *
	 * @param string $line Header line.
	 * @return string
	 */
	private function guess_delimiter( $line ) {
		$semikolon = substr_count( $line, ';' );
		$komma     = substr_count( $line, ',' );

		if ( 0 === $semikolon && 0 === $komma ) {
			return ';';
		}

		return $komma > $semikolon ? ',' : ';';
	}

	/**
	 * Split one line into its cells, honouring quoted fields.
	 *
	 * @param string $line      One line.
	 * @param string $delimiter Field separator.
	 * @return string[]
	 */
	private function split_line( $line, $delimiter ) {
		if ( '' === trim( (string) $line ) ) {
			return array();
		}

		// The escape mechanism is switched off on purpose. With a backslash as the
		// escape character, a field like "C:\Temp" loses a character, and this
		// format has no use for backslash escapes at all.
		$cells = str_getcsv( (string) $line, (string) $delimiter, '"', '' );

		return array_map( array( $this, 'clean' ), (array) $cells );
	}

	/**
	 * Trim a cell and drop the quotes a program left around it.
	 *
	 * @param mixed $cell Raw cell.
	 * @return string
	 */
	private function clean( $cell ) {
		$text = trim( (string) $cell );
		$text = preg_replace( '/^"(.*)"$/s', '$1', $text );

		return trim( (string) $text );
	}

	/**
	 * Map the header cells onto the four fields.
	 *
	 * @param string[] $cells Header cells.
	 * @return array<string, int> Field name to cell index.
	 */
	private function map_header( array $cells ) {
		$columns = array();

		foreach ( $cells as $index => $cell ) {
			$name = $this->fold( $this->clean( $cell ) );

			foreach ( self::$header_aliases as $field => $aliases ) {
				if ( isset( $columns[ $field ] ) ) {
					continue;
				}

				if ( in_array( $name, $aliases, true ) ) {
					$columns[ $field ] = (int) $index;
					break;
				}
			}
		}

		return $columns;
	}

	/**
	 * Bring a value into the form used for comparing names of things.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function fold( $value ) {
		$value = preg_replace( '/\s+/u', ' ', trim( (string) $value ) );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
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
}
