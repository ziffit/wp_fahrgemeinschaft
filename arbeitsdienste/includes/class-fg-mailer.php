<?php
/**
 * Plain-text transactional e-mails.
 *
 * @package Arbeitsdienste
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates all e-mail messages used by the plugin.
 *
 * Every message of the plugin is built here and sent through send(), which is
 * the only place that talks to wp_mail(). A new kind of message is a new method
 * above that one and nothing else.
 */
final class FG_Mailer {
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
	 * Send the publication confirmation for a pending ride.
	 *
	 * @param int    $ride_id       Ride ID.
	 * @param string $confirm_url   Confirmation action URL.
	 * @param string $discard_url   Discard action URL.
	 * @return bool
	 */
	public function send_pending_confirmation( $ride_id, $confirm_url, $discard_url ) {
		$ride = $this->repository->get_ride( $ride_id );
		if ( ! $ride ) {
			return false;
		}

		$data = $this->repository->get_ride_display_data( $ride );
		$to   = $this->repository->normalize_email( $data['contact_email'] );
		if ( ! $to ) {
			return false;
		}

		$mode_label = $this->mode_label( $data['mode'] );
		$body       = array(
			sprintf( 'Hallo %s,', $data['ride']->alias ),
			'',
			'Deine Eintragung wurde vorgemerkt, aber noch nicht veröffentlicht.',
			'',
			'Diese Angaben würden öffentlich erscheinen:',
			'',
			'Art: ' . $mode_label,
			'Vorname oder Spitzname: ' . $data['ride']->alias,
			'Arbeitsdienst: ' . $data['event_label'] . ( $data['event_date'] ? ' (' . $data['event_date'] . ')' : '' ),
			'Abfahrtsbereich: ' . $data['origin'],
			'',
			'Bitte prüfe die Angaben sorgfältig. Vorname oder Spitzname stehen öffentlich. Veröffentliche keine privaten Angaben wie vollständige Namen, genaue Adressen, Telefonnummern oder Kennzeichen.',
			'',
			'VERÖFFENTLICHUNG BESTÄTIGEN:',
			$confirm_url,
			'',
			'Du hast einen Fehler gemacht oder möchtest die Eintragung nicht veröffentlichen?',
			'Eintrag löschen und nicht veröffentlichen:',
			$discard_url,
			'',
			'Bitte leite diese E-Mail mit den enthaltenen Links nicht weiter.',
			'',
			'Bei Fragen nutze das Kontaktformular auf der Webseite.',
		);

		return $this->send(
			$to,
			'Fahrgemeinschaft bestätigen – ' . $data['event_label'],
			implode( "\n", $body )
		);
	}

	/**
	 * Send the successful publication e-mail with the final delete link.
	 *
	 * @param int    $ride_id     Ride ID.
	 * @param string $delete_url  Final delete action URL.
	 * @return bool
	 */
	public function send_published_confirmation( $ride_id, $delete_url ) {
		$ride = $this->repository->get_ride( $ride_id );
		if ( ! $ride ) {
			return false;
		}

		$data = $this->repository->get_ride_display_data( $ride );
		$to   = $this->repository->normalize_email( $data['contact_email'] );
		if ( ! $to ) {
			return false;
		}

		$body = array(
			sprintf( 'Hallo %s,', $ride->alias ),
			'',
			'danke für die Veröffentlichung deiner Fahrgemeinschaft.',
			'',
			'Du kannst deine Eintragung löschen, wenn du diesen Link aufrufst:',
			$delete_url,
			'',
			'Achtung: Beim endgültigen Löschen erfolgt keine weitere Rückfrage.',
			'',
			'Wenn sich jemand zu deiner Eintragung meldet, erhältst du eine E-Mail.',
			'Jetzt könnt ihr euch direkt austauschen, zum Beispiel auch über Telefonnummern.',
			'',
			'Ist der Arbeitsdienst vorbei, wird dein Eintrag automatisch aus der öffentlichen Anzeige entfernt.',
			'',
			'Bei Fragen nutze das Kontaktformular auf der Webseite.',
		);

		return $this->send(
			$to,
			'Fahrgemeinschaft veröffentlicht – ' . $data['event_label'],
			implode( "\n", $body )
		);
	}

	/**
	 * Notify both sides of a valid contact request.
	 *
	 * @param int    $ride_id        Ride ID.
	 * @param string $requester_email Verified participant address.
	 * @return array{creator: bool, requester: bool}
	 */
	public function send_contact_notifications( $ride_id, $requester_email ) {
		$ride = $this->repository->get_ride( $ride_id );
		if ( ! $ride ) {
			return array(
				'creator'  => false,
				'requester' => false,
			);
		}

		$data          = $this->repository->get_ride_display_data( $ride );
		$creator_email = $this->repository->normalize_email( $data['contact_email'] );
		$requester_email = $this->repository->normalize_email( $requester_email );
		if ( ! $creator_email || ! $requester_email ) {
			return array(
				'creator'  => false,
				'requester' => false,
			);
		}

		$creator_body  = array(
			sprintf( 'Hallo %s,', $ride->alias ),
			'',
			'du hast einen Interessenten für deine Fahrgemeinschaft.',
			'',
			'E-Mail-Adresse: ' . $requester_email,
			'',
			'Schreib der Person direkt eine E-Mail, damit ihr euch abstimmen könnt.',
			'Wenn du möchtest, kannst du deine Telefonnummer direkt in deiner Antwort nennen.',
			'',
			'Bitte melde dich auch bei dem Interessenten, wenn es nicht klappt. Die Person wartet auf eine Antwort.',
		);
		$requester_body = array(
			'Hallo,',
			'',
			'Wir haben den Ersteller der Fahrgemeinschaft benachrichtigt.',
			'Hoffentlich meldet sich bald jemand bei dir.',
			'',
			'Bitte prüfe auch deinen Spam-Ordner.',
		);

		$creator_sent = $this->send(
			$creator_email,
			'Interesse an deiner Fahrgemeinschaft – ' . $data['event_label'],
			implode( "\n", $creator_body ),
			array( 'Reply-To: ' . $requester_email )
		);

		$requester_sent = false;
		if ( $creator_sent ) {
			$requester_sent = $this->send(
				$requester_email,
				'Deine Kontaktanfrage wurde angenommen',
				implode( "\n", $requester_body )
			);
		}

		return array(
			'creator'   => $creator_sent,
			'requester' => $requester_sent,
		);
	}

	/**
	 * Confirm a duty registration to the member and hand out the way back out.
	 *
	 * The message goes through the same path as every other one, so it carries
	 * the same layout, the same logo and the same footer. The logo and the layout
	 * are the HTML part, the footer is in both parts. There is no separate
	 * template for a duty: what changes is only the text, and a second template
	 * would be a second place where a club's logo or its contact data has to be
	 * kept correct.
	 *
	 * The names are not in the message. The member knows who they are, and a name
	 * in a forwarded message is a piece of personal data that has left the club.
	 * The duty is named, because that is what the member has to recognise.
	 *
	 * @param int    $registration_id Registration ID.
	 * @param string $unregister_url  Unregistration link.
	 * @return bool
	 */
	public function send_duty_signup( $registration_id, $unregister_url ) {
		$registration = $this->repository->get_registration( $registration_id );
		$member       = $registration ? $this->repository->get_member( $registration->member_id ) : null;
		$event        = $registration ? $this->repository->get_event( $registration->event_id ) : null;

		if ( ! $member || ! $event ) {
			return false;
		}

		$to = $this->repository->normalize_email( $member->email );
		if ( ! $to ) {
			return false;
		}

		$date = $this->repository->format_event_date_long( $event );
		$body = array(
			'Hallo,',
			'',
			'du bist für folgenden Arbeitsdienst angemeldet:',
			'',
			'Arbeitsdienst: ' . $event->title,
			'Datum: ' . $date,
		);

		$time = $this->repository->format_event_time( $event );
		if ( '' !== $time ) {
			$body[] = 'Beginn: ' . $time;
		}

		$body[] = '';
		$body[] = 'Bitte prüfe, ob der Termin passt. Wenn nicht, meldest du dich mit diesem Link wieder ab:';
		$body[] = $unregister_url;
		$body[] = '';
		$body[] = 'Der Link führt zu einer Seite, auf der du das Löschen noch einmal bestätigen musst.';
		$body[] = '';
		$body[] = 'Wenn du dich für weitere Arbeitsdienste eintragen möchtest, findest du die Termine auf der Webseite.';

		return $this->send(
			$to,
			'Angemeldet: ' . $event->title,
			implode( "\n", $body )
		);
	}

	/**
	 * Send a message with a controlled sender.
	 *
	 * The message goes out as multipart/alternative: the text as plain text in
	 * the first part, the layout of this plugin as HTML in the second. The
	 * configured logo is embedded, so no address of the recipient is passed to
	 * another host.
	 *
	 * @param string       $to Recipient.
	 * @param string       $subject Subject.
	 * @param string       $body Body as plain text.
	 * @param string[]|null $headers Optional headers.
	 * @return bool
	 */
	private function send( $to, $subject, $body, $headers = null ) {
		$from_email = get_option( 'admin_email' );
		if ( ! is_string( $from_email ) || ! is_email( $from_email ) ) {
			$host       = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			$from_email = 'wordpress@' . ( $host ? $host : 'localhost' );
		}
		$from_name = sanitize_text_field( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$subject   = sanitize_text_field( $subject );

		$from_filter = static function () use ( $from_email ) {
			return $from_email;
		};
		$name_filter = static function () use ( $from_name ) {
			return $from_name;
		};

		// wp_mail() cannot express the plain-text alternative of a message, so
		// the text is handed over as the message and the layout is added while
		// PHPMailer is being set up. See FG_Mail_Templates::apply_alternative().
		$alternative = static function ( &$phpmailer ) use ( $subject, $body ) {
			FG_Mail_Templates::apply_alternative( $phpmailer, $subject, $body );
		};

		add_filter( 'wp_mail_from', $from_filter );
		add_filter( 'wp_mail_from_name', $name_filter );
		add_action( 'phpmailer_init', $alternative );

		try {
			$sent = wp_mail( $to, $subject, $body, $headers, array(), FG_Mail_Templates::get_logo_embed() );
		} finally {
			remove_filter( 'wp_mail_from', $from_filter );
			remove_filter( 'wp_mail_from_name', $name_filter );
			remove_action( 'phpmailer_init', $alternative );
		}

		return (bool) $sent;
	}

	/**
	 * Human-readable mode label.
	 *
	 * @param string $mode Stored mode.
	 * @return string
	 */
	private function mode_label( $mode ) {
		return FG_RIDE_MODE_SEARCH === $mode ? 'Ich suche' : 'Ich biete';
	}
}
