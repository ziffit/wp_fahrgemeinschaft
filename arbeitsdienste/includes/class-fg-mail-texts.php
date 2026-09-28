<?php
/**
 * The texts and subjects of the five messages of the plugin.
 *
 * @package Arbeitsdienste
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the wording of every message, and the placeholders that go into it.
 *
 * The wording lives in defaults() below and not in the database. A club that
 * changes something gets one row per message in `fg_mail_templates`; a club
 * that changes nothing gets no row at all, and the "back to the default"
 * button has a row to delete. If the defaults were stored too, a plugin update
 * could never reach a club again: the stored text would win over the new one
 * for ever.
 *
 * A placeholder is only replaced when the message allows it. The set is per
 * message, and that is the whole point of the set: the unregister link exists
 * for a duty and not for a contact request, and a template that asks for
 * {{Abmeldelink}} in a message that has no such link is refused when it is
 * saved rather than going out with a hole in it. A placeholder that this
 * version does not know can still reach a table — a club edits with a newer
 * version and then downgrades — and that case is caught at the moment of
 * sending, where the message is held back instead of going out half-empty.
 */
final class FG_Mail_Texts {
	/**
	 * The message that asks a new ride to be published.
	 *
	 * @var string
	 */
	const RIDE_PENDING = 'ride_pending';

	/**
	 * The message after a ride has been published.
	 *
	 * @var string
	 */
	const RIDE_PUBLISHED = 'ride_published';

	/**
	 * The message to the creator of a ride about an interested person.
	 *
	 * @var string
	 */
	const CONTACT_CREATOR = 'contact_creator';

	/**
	 * The message to the person who asked for contact.
	 *
	 * @var string
	 */
	const CONTACT_REQUESTER = 'contact_requester';

	/**
	 * The message that confirms a duty registration.
	 *
	 * @var string
	 */
	const DUTY_SIGNUP = 'duty_signup';

	/**
	 * The option that holds the notices of held-back messages.
	 *
	 * @var string
	 */
	const NOTICE_OPTION = 'fg_mail_text_notices';

	/**
	 * All five messages with their defaults and their placeholders.
	 *
	 * A message is a list rather than a single string because it is three
	 * things at once: what a reader sees, what a club may change, and what a
	 * club may not. The three are kept in one place so a message can never
	 * grow a placeholder that its own default does not use, or a default that
	 * its own description does not mention.
	 *
	 * Every placeholder appears three times: in `placeholders` with the text the
	 * screen shows, in `sample` with the invented data the preview uses, and in
	 * the value list that the sending code builds from the database. A
	 * placeholder the sending code has nothing for comes out empty, and that is
	 * the right answer for a name: a greeting that names nobody has to read as a
	 * sentence. That is also why {{Anrede}} is a placeholder of its own instead of a
	 * name with a "Hallo" written in front of it — the greeting is the one place
	 * where an empty name would leave a broken word behind. Since schema 1.4.0 no
	 * message about a ride reaches an address of nobody, so the fallback only still
	 * decides the wording and not a real case. It stays, because a member row that
	 * predates the validation of a first name is a row the code does not get to
	 * throw away.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function mails() {
		return array(
			self::RIDE_PENDING     => array(
				'label'        => __( 'Fahrgemeinschaft bestätigen', 'arbeitsdienste' ),
				'description'  => __( 'Geht an die E-Mail-Adresse des Mitglieds, das die Fahrgemeinschaft angeboten hat, direkt nach dem Absenden des Formulars. Sie enthält den Bestätigen- und den Verwerfen-Link.', 'arbeitsdienste' ),
				'subject'      => 'Fahrgemeinschaft bestätigen – {{Arbeitsdienst}}',
				'body'         => "{{Anrede}},\n\nDeine Eintragung wurde vorgemerkt, aber noch nicht veröffentlicht.\n\nDiese Angaben würden öffentlich erscheinen:\n\nArt: {{Art}}\nVorname: {{Vorname}}\nArbeitsdienst: {{Arbeitsdienstdetails}}\nAbfahrtsbereich: {{Abfahrtsbereich}}\n\nDein Vorname steht dabei öffentlich. Er stammt aus der Mitgliederverwaltung des Vereins, nicht aus deiner Eingabe. Der Abfahrtsbereich ist das Einzige, was du selbst einträgst: Gib dort keine genaue Adresse, keine Telefonnummer und kein Kennzeichen an.\n\nVERÖFFENTLICHUNG BESTÄTIGEN:\n{{Bestaetigungslink}}\n\nDu hast einen Fehler gemacht oder möchtest die Eintragung nicht veröffentlichen?\nEintrag löschen und nicht veröffentlichen:\n{{Verwerfungslink}}\n\nBitte leite diese E-Mail mit den enthaltenen Links nicht weiter.\n\nBei Fragen nutze das Kontaktformular auf der Webseite.",
				'placeholders' => array(
					'{{Anrede}}'           => __( 'Anrede mit Namen, zum Beispiel „Hallo Anton“. Die Mail geht an das Mitglied, das die Fahrgemeinschaft angeboten hat, und ein Mitglied hat immer einen Vornamen — hier steht also immer ein Name.', 'arbeitsdienste' ),
					'{{Vorname}}'          => __( 'Vorname des Mitglieds, aus der Mitgliederverwaltung. Dieser Name steht öffentlich in der Liste, und niemand kann einen anderen eintragen.', 'arbeitsdienste' ),
					'{{Name}}'             => __( 'Nachname des Mitglieds, aus der Mitgliederverwaltung. Ein Mitglied hat immer einen, der Platzhalter bleibt also nie leer.', 'arbeitsdienste' ),
					'{{Art}}'              => __( 'Ob gesucht oder geboten wird, als „Ich suche“ oder „Ich biete“.', 'arbeitsdienste' ),
					'{{Arbeitsdienst}}'    => __( 'Titel des Arbeitsdienstes, an den die Fahrgemeinschaft gehört.', 'arbeitsdienste' ),
					'{{Arbeitsdienstdetails}}' => __( 'Titel und Datum des Arbeitsdienstes in einer Zeile, zum Beispiel „Flussaktion (2027-06-12)“. Steht der Arbeitsdienst ohne Datum dort, steht hier nur der Titel.', 'arbeitsdienste' ),
					'{{Abfahrtsbereich}}'  => __( 'Der Ort oder das Gebiet, das das Mitglied angegeben hat.', 'arbeitsdienste' ),
					'{{Bestaetigungslink}}' => __( 'Link, mit dem die Eintragung veröffentlicht wird. Ohne diesen Link passiert nichts. Mit einem Doppelpunkt und eigenem Wortlaut dahinter wird daraus der Text des Links: {{Bestaetigungslink:Fahrgemeinschaft bestätigen}}}.', 'arbeitsdienste' ),
					'{{Verwerfungslink}}'  => __( 'Link, mit dem die Eintragung gelöscht wird, ohne veröffentlicht zu werden. Mit einem Doppelpunkt und eigenem Wortlaut dahinter wird daraus der Text des Links: {{Verwerfungslink:Eintragung verwerfen}}}.', 'arbeitsdienste' ),
				),
				'links'        => array(
					'{{Bestaetigungslink}}' => __( 'Fahrgemeinschaft bestätigen', 'arbeitsdienste' ),
					'{{Verwerfungslink}}'   => __( 'Eintragung verwerfen', 'arbeitsdienste' ),
				),
				'sample'       => array(
					'Anrede'              => 'Hallo Anton',
					'Vorname'             => 'Anton',
					'Name'                => 'Berger',
					'Art'                 => 'Ich biete',
					'Arbeitsdienst'       => 'Flussaktion',
					'Arbeitsdienstdetails' => 'Flussaktion (2027-06-12)',
					'Abfahrtsbereich'     => 'Innenstadt',
					'Bestaetigungslink'   => 'https://example.org/?fg_ride_action=view&ride_ref=8a1f0c2b&token=9d41b0c7a5e64f2f8c3b7a1e0d6f92b48',
					'Verwerfungslink'     => 'https://example.org/?fg_ride_action=view&ride_ref=8a1f0c2b&token=3c9e5a71b8d24f60ae5b1c7d93f28a04',
				),
			),
			self::RIDE_PUBLISHED   => array(
				'label'        => __( 'Fahrgemeinschaft veröffentlicht', 'arbeitsdienste' ),
				'description'  => __( 'Geht an die E-Mail-Adresse desselben Mitglieds, sobald die Veröffentlichung bestätigt wurde. Sie enthält den endgültigen Lösch-Link.', 'arbeitsdienste' ),
				'subject'      => 'Fahrgemeinschaft veröffentlicht – {{Arbeitsdienst}}',
				'body'         => "{{Anrede}},\n\ndanke für die Veröffentlichung deiner Eintragung.\n\nDu kannst deine Eintragung löschen, wenn du diesen Link aufrufst:\n{{Loeschlink}}\n\nAchtung: Beim endgültigen Löschen erfolgt keine weitere Rückfrage.\n\nWenn sich jemand zu deiner Eintragung meldet, erhältst du eine E-Mail.\nJetzt könnt ihr euch direkt austauschen, zum Beispiel auch über Telefonnummern.\n\nIst der Arbeitsdienst vorbei, wird dein Eintrag automatisch aus der öffentlichen Anzeige entfernt.\n\nBei Fragen nutze das Kontaktformular auf der Webseite.",
				'placeholders' => array(
					'{{Anrede}}'        => __( 'Anrede mit Namen, zum Beispiel „Hallo Anton“. Die Mail geht an dasselbe Mitglied, das die Fahrgemeinschaft angeboten hat, und ein Mitglied hat immer einen Vornamen.', 'arbeitsdienste' ),
					'{{Vorname}}'       => __( 'Vorname des Mitglieds, aus der Mitgliederverwaltung. Ein Mitglied hat immer einen, der Platzhalter bleibt also nie leer.', 'arbeitsdienste' ),
					'{{Name}}'          => __( 'Nachname des Mitglieds, aus der Mitgliederverwaltung. Ein Mitglied hat immer einen, der Platzhalter bleibt also nie leer.', 'arbeitsdienste' ),
					'{{Arbeitsdienst}}' => __( 'Titel des Arbeitsdienstes, an den die Fahrgemeinschaft gehört.', 'arbeitsdienste' ),
					'{{Loeschlink}}'    => __( 'Link, mit dem die Eintragung endgültig gelöscht wird. Das Löschen erfolgt ohne weitere Rückfrage. Mit einem Doppelpunkt und eigenem Wortlaut dahinter wird daraus der Text des Links: {{Loeschlink:Fahrgemeinschaft löschen}}}.', 'arbeitsdienste' ),
				),
				'links'        => array(
					'{{Loeschlink}}' => __( 'Fahrgemeinschaft löschen', 'arbeitsdienste' ),
				),
				'sample'       => array(
					'Anrede'        => 'Hallo Anton',
					'Vorname'       => 'Anton',
					'Name'          => 'Berger',
					'Arbeitsdienst' => 'Flussaktion',
					'Loeschlink'    => 'https://example.org/?fg_ride_action=view&ride_ref=8a1f0c2b&token=e57b0c93f2a148d6b8e0c37a1d94f652',
				),
			),
			self::CONTACT_CREATOR => array(
				'label'        => __( 'Kontaktanfrage an das Mitglied', 'arbeitsdienste' ),
				'description'  => __( 'Geht an das Mitglied, das die Fahrgemeinschaft angeboten hat, wenn sich jemand gemeldet hat. Sie trägt die Adresse der interessierten Person als Antwortadresse, damit ein Antworten ohne Rückfrage möglich ist.', 'arbeitsdienste' ),
				'subject'      => 'Interesse an deiner Fahrgemeinschaft – {{Arbeitsdienst}}',
				'body'         => "{{Anrede}},\n\ndu hast einen Interessenten für deine Eintragung.\n\nE-Mail-Adresse: {{Interessent}}\n\nSchreib der Person direkt eine E-Mail, damit ihr euch abstimmen könnt.\nWenn du möchtest, kannst du deine Telefonnummer direkt in deiner Antwort nennen.\n\nBitte melde dich auch bei dem Interessenten, wenn es nicht klappt. Die Person wartet auf eine Antwort.",
				'placeholders' => array(
					'{{Anrede}}'        => __( 'Anrede mit Namen, zum Beispiel „Hallo Anton“. Diese Mail geht nur an das Mitglied, das die Eintragung angeboten hat, hier steht also immer ein Name.', 'arbeitsdienste' ),
					'{{Vorname}}'       => __( 'Vorname des Mitglieds, aus der Mitgliederverwaltung.', 'arbeitsdienste' ),
					'{{Name}}'          => __( 'Nachname des Mitglieds, aus der Mitgliederverwaltung.', 'arbeitsdienste' ),
					'{{Arbeitsdienst}}' => __( 'Titel des Arbeitsdienstes, an den die Fahrgemeinschaft gehört.', 'arbeitsdienste' ),
					'{{Interessent}}'   => __( 'Die Adresse, unter der sich die interessierte Person gemeldet hat.', 'arbeitsdienste' ),
				),
				// No link to name here: the interested person answers by e-mail,
				// the address of the member who offered the ride stands in the reply field.
				'links'        => array(),
				'sample'       => array(
					'Anrede'        => 'Hallo Anton',
					'Vorname'       => 'Anton',
					'Name'          => 'Berger',
					'Arbeitsdienst' => 'Flussaktion',
					'Interessent'   => 'marie.kurz@example.org',
				),
			),
			self::CONTACT_REQUESTER => array(
				'label'        => __( 'Bestätigung an die anfragende Person', 'arbeitsdienste' ),
				'description'  => __( 'Geht an die Person, die den Kontaktknopf gedrückt hat — und nur, wenn die Mail an das Mitglied hat gehen können. Sie enthält keine Links, weil es nichts zu bestätigen gibt.', 'arbeitsdienste' ),
				'subject'      => 'Deine Kontaktanfrage wurde angenommen',
				'body'         => "{{Anrede}},\n\nWir haben das Mitglied benachrichtigt, das die Fahrgemeinschaft angeboten hat.\nHoffentlich meldet sich bald jemand bei dir.\n\nBitte prüfe auch deinen Spam-Ordner.",
				'placeholders' => array(
					'{{Anrede}}'        => __( 'Anrede mit Namen, zum Beispiel „Hallo Marie“. Das Kontaktformular fragt nur nach der Adresse, aber die muss zu einem Mitglied des Vereins gehören — hier steht also immer ein Name.', 'arbeitsdienste' ),
					'{{Vorname}}'       => __( 'Vorname der anfragenden Person, aus der Mitgliederverwaltung. Wie die Anrede: die Adresse muss zu einem Mitglied gehören, also steht hier immer einer.', 'arbeitsdienste' ),
					'{{Name}}'          => __( 'Nachname der anfragenden Person, aus der Mitgliederverwaltung.', 'arbeitsdienste' ),
					'{{Arbeitsdienst}}' => __( 'Titel des Arbeitsdienstes, an den die Fahrgemeinschaft gehört.', 'arbeitsdienste' ),
				),
				'links'        => array(),
				'sample'       => array(
					'Anrede'        => 'Hallo Marie',
					'Vorname'       => 'Marie',
					'Name'          => 'Kurz',
					'Arbeitsdienst' => 'Flussaktion',
				),
			),
			self::DUTY_SIGNUP     => array(
				'label'        => __( 'Anmeldung zu einem Arbeitsdienst', 'arbeitsdienste' ),
				'description'  => __( 'Geht an die Adresse des Mitglieds, sobald es sich für einen Arbeitsdienst eingetragen hat. Sie enthält den Abmeldelink.', 'arbeitsdienste' ),
				'subject'      => 'Angemeldet: {{Arbeitsdienst}}',
				'body'         => "{{Anrede}},\n\ndu bist für folgenden Arbeitsdienst angemeldet:\n\nArbeitsdienst: {{Arbeitsdienst}}\nDatum: {{Datum}}\nBeginn: {{Uhrzeit}}\n\nBitte prüfe, ob der Termin passt. Wenn nicht, meldest du dich mit diesem Link wieder ab:\n{{Abmeldelink}}\n\nDer Link führt zu einer Seite, auf der du das Löschen noch einmal bestätigen musst.\n\nWenn du dich für weitere Arbeitsdienste eintragen möchtest, findest du die Termine auf der Webseite.",
				'placeholders' => array(
					'{{Anrede}}'           => __( 'Anrede mit Namen, zum Beispiel „Hallo Anton“. Diese Mail geht nur an Mitglieder, hier steht also fast immer ein Name.', 'arbeitsdienste' ),
					'{{Vorname}}'          => __( 'Vorname des Mitglieds, um das es geht. Diese E-Mail geht nur an Mitglieder, hier steht also immer einer.', 'arbeitsdienste' ),
					'{{Name}}'             => __( 'Nachname des Mitglieds, um das es geht. Diese E-Mail geht nur an Mitglieder, hier steht also immer einer.', 'arbeitsdienste' ),
					'{{Arbeitsdienst}}'    => __( 'Titel des Arbeitsdienstes.', 'arbeitsdienste' ),
					'{{Arbeitsdienstdetails}}' => __( 'Titel, Datum und Beginn in einer Zeile, zum Beispiel „Flussaktion, Samstag, den 12.06.2027, 08:00“.', 'arbeitsdienste' ),
					'{{Datum}}'            => __( 'Das Datum des Arbeitsdienstes, ausgeschrieben, zum Beispiel „Samstag, den 12.06.2027“.', 'arbeitsdienste' ),
					'{{Uhrzeit}}'          => __( 'Die Beginnzeit, zum Beispiel „08:00“. Steht „unbekannt“, wenn der Arbeitsdienst keine trägt.', 'arbeitsdienste' ),
					'{{Abmeldelink}}'      => __( 'Link, mit dem sich das Mitglied wieder abmeldet. Ohne diesen Link kann es nicht zurück. Mit einem Doppelpunkt und eigenem Wortlaut dahinter wird daraus der Text des Links: {{Abmeldelink:Teilnahme am Arbeitsdienst abmelden}}}.', 'arbeitsdienste' ),
				),
				'links'        => array(
					'{{Abmeldelink}}' => __( 'Teilnahme am Arbeitsdienst abmelden', 'arbeitsdienste' ),
				),
				'sample'       => array(
					'Anrede'               => 'Hallo Anton',
					'Vorname'              => 'Anton',
					'Name'                 => 'Berger',
					'Arbeitsdienst'        => 'Flussaktion',
					'Arbeitsdienstdetails' => 'Flussaktion, Samstag, den 12.06.2027, 08:00',
					'Datum'                => 'Samstag, den 12.06.2027',
					'Uhrzeit'              => '08:00',
					'Abmeldelink'          => 'https://example.org/?fg_duty_action=view&ref=5c0b7a91e2d34f68a1b4c7d09e3f28a6&token=d82f1b4c7a0e93f5618c4b2d7a0e9f35',
				),
			),
		);
	}

	/**
	 * The keys of all messages, in the order the screen shows them.
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array_keys( self::mails() );
	}

	/**
	 * One message with its defaults, its stored text and its placeholders.
	 *
	 * The stored text is the default as long as no row exists, so a club that
	 * has never touched a message gets the wording of this version. That is the
	 * same sentence as "the defaults are not in the database", seen from the
	 * screen.
	 *
	 * @param string $key Message key.
	 * @return array<string, mixed>|null
	 */
	public static function get( $key ) {
		$mail = self::mails();
		if ( ! isset( $mail[ $key ] ) ) {
			return null;
		}

		$mail = $mail[ $key ];
		$row  = self::row( $key );

		if ( $row ) {
			$mail['subject'] = (string) $row->subject;
			$mail['body']    = (string) $row->body;
			$mail['changed'] = true;
			$mail['updated'] = (string) $row->updated_at;
		} else {
			$mail['changed'] = false;
			$mail['updated'] = '';
		}

		$mail['key'] = $key;

		return $mail;
	}

	/**
	 * The stored text of a message, or its default.
	 *
	 * @param string $key  Message key.
	 * @param string $field Either "subject" or "body".
	 * @return string
	 */
	public static function stored( $key, $field ) {
		$mail = self::get( $key );

		return $mail ? (string) $mail[ $field ] : '';
	}

	/**
	 * Whether a club has changed this message.
	 *
	 * @param string $key Message key.
	 * @return bool
	 */
	public static function is_changed( $key ) {
		$mail = self::get( $key );

		return $mail ? (bool) $mail['changed'] : false;
	}

	/**
	 * Store the wording of one message.
	 *
	 * Refuses a text with a placeholder that this message does not allow, and
	 * says which ones it does allow: a template that cannot be filled is not a
	 * template, and the place to find that out is here, while somebody is
	 * looking at the form.
	 *
	 * @param string $key     Message key.
	 * @param string $subject Subject with placeholders.
	 * @param string $body    Body with placeholders.
	 * @return true|string True, or the complaint to show.
	 */
	public static function save( $key, $subject, $body ) {
		global $wpdb;

		$mail = self::get( $key );
		if ( ! $mail ) {
			return __( 'Diese E-Mail gibt es nicht.', 'arbeitsdienste' );
		}

		if ( '' === trim( $subject ) ) {
			return __( 'Der Betreff darf nicht leer sein. Eine E-Mail ohne Betreff landet im Spam-Ordner.', 'arbeitsdienste' );
		}

		if ( '' === trim( $body ) ) {
			return __( 'Der Text darf nicht leer sein.', 'arbeitsdienste' );
		}

		$erlaubt = array_keys( $mail['placeholders'] );
		$links   = self::link_labels( $mail );

		foreach ( array( 'Betreff' => $subject, 'Text' => $body ) as $feld => $text ) {
			$unbekannt = self::unknown_placeholders( $text, $erlaubt, $links );

			if ( $unbekannt ) {
				return sprintf(
					/* translators: 1: field name, 2: the placeholders that are not allowed, 3: the placeholders that are. */
					__( 'Der %1$s wurde nicht gespeichert, weil er %2$s nennt. Für diese E-Mail sind nur diese Platzhalter möglich: %3$s', 'arbeitsdienste' ),
					$feld,
					implode( ', ', $unbekannt ),
					implode( ', ', self::allowed_list( $erlaubt, $links ) )
				);
			}
		}

		$table = FG_Schema::mail_templates_table();
		$now   = current_time( 'mysql' );

		// A row per message, and the key is unique. INSERT ... ON DUPLICATE KEY
		// UPDATE says which of the two happens in one statement, so a second save
		// of the same message cannot end in two rows for one message.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table (mail_key, subject, body, updated_at) VALUES (%s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), updated_at = VALUES(updated_at)",
				$key,
				$subject,
				$body,
				$now
			)
		);

		return true;
	}

	/**
	 * Give a message its default wording back.
	 *
	 * The row is deleted rather than filled with the default, because a club
	 * that changes a message twice wants the wording of the version it runs,
	 * not the wording of the version it edited with.
	 *
	 * @param string $key Message key.
	 * @return void
	 */
	public static function reset( $key ) {
		global $wpdb;

		if ( ! isset( self::mails()[ $key ] ) ) {
			return;
		}

		$table = FG_Schema::mail_templates_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE mail_key = %s", $key ) );
	}

	/**
	 * Build the message that goes out.
	 *
	 * @param string               $key    Message key.
	 * @param array<string, string> $values Values for the placeholders, by name without braces.
	 * @return array{subject: string, body: string, links: array<string, array{url: string, label: string}>}|false
	 *                False when a placeholder cannot be replaced.
	 */
	public static function compose( $key, array $values ) {
		$mail = self::get( $key );
		if ( ! $mail ) {
			return false;
		}

		$erlaubt = $mail['placeholders'];
		$links   = self::link_labels( $mail );
		$out     = array();

		foreach ( array( 'subject', 'body' ) as $field ) {
			$text = (string) $mail[ $field ];
			$unerlaubt = self::unknown_placeholders( $text, array_keys( $erlaubt ), $links );

			if ( $unerlaubt ) {
				self::hold_back( $key, $unerlaubt, $field );

				return false;
			}

			// A subject is one line of text, so a link placeholder is filled with
			// the address there. In the body the token stays: the text part needs
			// the address and the HTML part needs a link with a wording, and the
			// two are written by FG_Mail_Templates once it knows which links this
			// message carries.
			$out[ $field ] = self::replace( $text, $erlaubt, $values, $links, 'subject' === $field );
		}

		$out['links'] = self::links_of( $links, $values );

		return $out;
	}

	/**
	 * Build a message for the preview, with invented data.
	 *
	 * The names in the preview are not the last recipient. The preview is HTML
	 * in the browser of somebody who is looking at the admin screen, and it
	 * ends up in the page cache of the site, in the browser history and in a
	 * screenshot in a bug report. Nobody's member data belongs there, so every
	 * name in it is made up.
	 *
	 * @param string $key Message key.
	 * @return array{subject: string, body: string, links: array<string, array{url: string, label: string}>}|null
	 */
	public static function preview( $key ) {
		$mail = self::get( $key );
		if ( ! $mail ) {
			return null;
		}

		$links = self::link_labels( $mail );
		$out   = array();

		foreach ( array( 'subject', 'body' ) as $field ) {
			$unerlaubt = self::unknown_placeholders( (string) $mail[ $field ], array_keys( $mail['placeholders'] ), $links );

			if ( $unerlaubt ) {
				return null;
			}

			$out[ $field ] = self::replace( (string) $mail[ $field ], $mail['placeholders'], $mail['sample'], $links, 'subject' === $field );
		}

		$out['links'] = self::links_of( $links, $mail['sample'] );

		return $out;
	}

	/**
	 * The placeholders of a message, as a list of names without braces.
	 *
	 * @param string $key Message key.
	 * @return string[]
	 */
	public static function placeholder_names( $key ) {
		$mail = self::get( $key );

		return $mail ? array_map( static function ( $name ) {
			return trim( $name, '{}' );
		}, array_keys( $mail['placeholders'] ) ) : array();
	}

	/**
	 * The notices of messages that were held back, newest first.
	 *
	 * @return array<int, array{when: string, text: string}>
	 */
	public static function notices() {
		$stored = get_option( self::NOTICE_OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * The notices that are no longer true, because the wording was changed.
	 *
	 * A notice says that a message did not go out. Once the wording behind it is
	 * gone, the notice is history, and a history that is still on the screen
	 * tells the club that something is broken when it is not.
	 *
	 * @return void
	 */
	public static function clear_notices() {
		delete_option( self::NOTICE_OPTION );
	}

	/**
	 * Note that a message was held back, and say so where somebody looks.
	 *
	 * @param string   $key         Message key.
	 * @param string[] $unerlaubt   The placeholders that could not be replaced.
	 * @param string   $field       Either "subject" or "body".
	 * @return void
	 */
	private static function hold_back( $key, array $unerlaubt, $field ) {
		global $wpdb;

		$mail    = self::mails()[ $key ];
		$erlaubt = self::allowed_list( array_keys( $mail['placeholders'] ), self::link_labels( $mail ) );
		$zeile   = sprintf(
			/* translators: 1: message label, 2: field name, 3: the placeholders that are not allowed, 4: the placeholders that are. */
			__( 'Die E-Mail „%1$s“ wurde nicht verschickt, weil der %2$s %3$s nennt, was diese E-Mail nicht kennt. Erlaubt sind hier nur: %4$s. Der Eintrag selbst ist gespeichert, aber die E-Mail ist nicht angekommen — bitte den Text auf der Seite E-Mails korrigieren.', 'arbeitsdienste' ),
			$mail['label'],
			'Betreff' === $field ? __( 'Betreff', 'arbeitsdienste' ) : __( 'Text', 'arbeitsdienste' ),
			implode( ', ', $unerlaubt ),
			implode( ', ', $erlaubt )
		);

		// error_log() because the site owner is the one who has to fix it, and the
		// notice below because a club that does not read the log still sees it.
		error_log( 'arbeitsdienste: ' . $zeile ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A message that does not go out has to be findable.

		$notizen   = self::notices();
		$notizen[] = array(
			'when' => current_time( 'mysql' ),
			'text' => $zeile,
		);

		// Five is enough to see the pattern and small enough that the option does
		// not grow into a log file nobody reads.
		update_option( self::NOTICE_OPTION, array_slice( $notizen, -5 ), false );
	}

	/**
	 * The placeholders a text names that are not on the list.
	 *
	 * Only the double braces count. A text that talks about braces in another
	 * way — about a template, about code — is not a template that fails, and a
	 * check that cannot tell the two apart refuses messages a club has not
	 * broken.
	 *
	 * @param string               $text    Text with placeholders.
	 * @param string[]             $erlaubt Allowed placeholders, with braces.
	 * @param array<string, string> $links   Link placeholders by name without braces, with their default wording.
	 * @return string[]
	 */
	private static function unknown_placeholders( $text, array $erlaubt, array $links = array() ) {
		$gefunden = array();

		if ( preg_match_all( '/\{\{[^{}]*\}\}/', (string) $text, $treffer ) ) {
			foreach ( $treffer[0] as $platzhalter ) {
				if ( in_array( $platzhalter, $erlaubt, true ) || in_array( $platzhalter, $gefunden, true ) ) {
					continue;
				}

				// A link of this message may carry its own wording behind a
				// colon, "{{Loeschlink:Meine Eintragung loeschen}}". Only the links
				// of this message are allowed to do that, and a name that is not
				// one of them stays as unknown as it was before.
				if ( self::is_link_token( $platzhalter, $links ) ) {
					continue;
				}

				$gefunden[] = $platzhalter;
			}
		}

		return $gefunden;
	}

	/**
	 * The link placeholders of a message, by name without braces.
	 *
	 * The definition writes the names with braces, because that is how they stand
	 * in the list of placeholders beside them, and everything below this point
	 * works without braces, because that is how the values arrive.
	 *
	 * @param array<string, mixed> $mail One message of mails().
	 * @return array<string, string> Default wording by name.
	 */
	private static function link_labels( array $mail ) {
		$links = isset( $mail['links'] ) && is_array( $mail['links'] ) ? $mail['links'] : array();
		$out   = array();

		foreach ( $links as $platzhalter => $wortlaut ) {
			$out[ trim( (string) $platzhalter, '{}' ) ] = (string) $wortlaut;
		}

		return $out;
	}

	/**
	 * The links of a message, with the address they carry.
	 *
	 * Every link of the message is listed, not only the ones the text uses: the
	 * renderer decides per place which of the two parts needs what, and a link
	 * that is listed but not used is not in the mail at all.
	 *
	 * @param array<string, string> $links Link placeholders by name without braces, with their default wording.
	 * @param array<string, string> $werte Values by name without braces.
	 * @return array<string, array{url: string, label: string}>
	 */
	private static function links_of( array $links, array $werte ) {
		$out = array();

		foreach ( $links as $name => $wortlaut ) {
			$out[ $name ] = array(
				'url'   => isset( $werte[ $name ] ) ? (string) $werte[ $name ] : '',
				'label' => (string) $wortlaut,
			);
		}

		return $out;
	}

	/**
	 * Whether a placeholder is a link of this message, with or without wording.
	 *
	 * @param string               $platzhalter Placeholder with braces, possibly with a wording behind a colon.
	 * @param array<string, string> $links       Link placeholders by name without braces.
	 * @return bool
	 */
	private static function is_link_token( $platzhalter, array $links ) {
		if ( ! preg_match( '/^\{\{([^{}:]+)(?::[^{}]*)?\}\}$/', (string) $platzhalter, $treffer ) ) {
			return false;
		}

		return array_key_exists( $treffer[1], $links );
	}

	/**
	 * The placeholders a text may use, for the complaint about a wrong one.
	 *
	 * A link is listed twice, because both forms work: the bare name takes the
	 * default wording, the name with a colon takes the club's own. A club that
	 * wrote "Loeschlink:Meine Eintragung loeschen" and was told it was unknown
	 * would have learned from this list that the form with a colon is allowed.
	 *
	 * @param string[]             $erlaubt Allowed placeholders, with braces.
	 * @param array<string, string> $links   Link placeholders by name without braces.
	 * @return string[]
	 */
	private static function allowed_list( array $erlaubt, array $links ) {
		foreach ( array_keys( $links ) as $name ) {
			$erlaubt[] = '{{' . $name . ':Wortlaut}}';
		}

		return $erlaubt;
	}

	/**
	 * Put the values into the text.
	 *
	 * strtr() and not preg_replace(): a replacement value can carry a dollar or a
	 * backslash, which preg_replace() would read as a backreference and throw
	 * away, and a name like "Müller & Söhne" must arrive as it was typed.
	 *
	 * The keys carry their braces. Without them strtr() would replace the bare
	 * word as well, and the bare word is everywhere in a text about work duties:
	 * the line "Art: {{Art}}" came out as "Ich biete: {{Ich biete}}", because
	 * strtr() walks from left to right without overlapping and hit the label
	 * first. A placeholder that is only recognised with its braces is the one
	 * that cannot be found by accident in the sentence around it.
	 *
	 * A link placeholder is only left standing when the text is a body. In a
	 * subject it is filled with the address, because a subject is one line of
	 * text and an anchor cannot go into one.
	 *
	 * @param string                $text     Text with placeholders.
	 * @param array<string, string> $erlaubt  Allowed placeholders with their description, with braces.
	 * @param array<string, string> $werte    Values by name without braces.
	 * @param array<string, string> $links    Link placeholders by name without braces.
	 * @param bool                  $betreff  Whether the text is a subject.
	 * @return string
	 */
	private static function replace( $text, array $erlaubt, array $werte, array $links = array(), $betreff = false ) {
		$ersetze = array();

		foreach ( $erlaubt as $platzhalter => $beschreibung ) {
			$name = trim( $platzhalter, '{}' );

			if ( ! $betreff && array_key_exists( $name, $links ) ) {
				continue;
			}

			$ersetze[ $platzhalter ] = isset( $werte[ $name ] ) ? (string) $werte[ $name ] : '';
		}

		return strtr( $text, $ersetze );
	}

	/**
	 * The stored row of a message.
	 *
	 * @param string $key Message key.
	 * @return object|null
	 */
	private static function row( $key ) {
		global $wpdb;

		$table = FG_Schema::mail_templates_table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE mail_key = %s", $key ) );
	}
}
