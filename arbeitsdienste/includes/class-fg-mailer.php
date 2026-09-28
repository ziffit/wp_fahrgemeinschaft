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
	 * Send the mail that belongs to a published ride.
	 *
	 * One ride means one mail. It says the entry is in the list, repeats what
	 * became public, and carries the link that takes it down again. There is no
	 * second mail and nothing to confirm: the form is the consent, the entry is
	 * visible at once, and this mail is the receipt that also works as the way
	 * out.
	 *
	 * @param int    $ride_id    Ride ID.
	 * @param string $delete_url Deletion action URL.
	 * @return bool
	 */
	public function send_published_confirmation( $ride_id, $delete_url ) {
		$ride = $this->repository->get_ride( $ride_id );
		if ( ! $ride ) {
			return false;
		}

		$data   = $this->repository->get_ride_display_data( $ride );
		$member = $data['member'];
		$to     = $this->repository->get_ride_contact_email( $ride, $member );
		if ( ! $to || ! $member ) {
			return false;
		}

		$mail = FG_Mail_Texts::compose(
			FG_Mail_Texts::RIDE_PUBLISHED,
			array_merge(
				$this->person_from_member( $member ),
				array(
					'Art'                  => $this->mode_label( $data['mode'] ),
					'Arbeitsdienst'        => (string) $data['event_label'],
					'Arbeitsdienstdetails' => $data['event_label'] . ( $data['event_date'] ? ' (' . $data['event_date'] . ')' : '' ),
					'Abfahrtsbereich'     => (string) $data['origin'],
					'Loeschlink'           => (string) $delete_url,
				)
			)
		);

		if ( ! $mail ) {
			return false;
		}

		return $this->send( $to, $mail['subject'], $mail['body'], null, $mail['links'] );
	}

	/**
	 * Notify both sides of a valid contact request.
	 *
	 * @param int $ride_id     Ride ID.
	 * @param int $asker_id    Row ID of the member who wrote in.
	 * @return array{creator: bool, requester: bool}
	 */
	public function send_contact_notifications( $ride_id, $asker_id ) {
		$ride = $this->repository->get_ride( $ride_id );
		if ( ! $ride ) {
			return array(
				'creator'  => false,
				'requester' => false,
			);
		}

		$data          = $this->repository->get_ride_display_data( $ride );
		$owner         = $data['member'];
		$asker         = $this->repository->get_member( $asker_id );
		$creator_email = $this->repository->get_ride_contact_email( $ride, $owner );
		if ( ! $creator_email || ! $owner || ! $asker ) {
			return array(
				'creator'  => false,
				'requester' => false,
			);
		}

		$creator = FG_Mail_Texts::compose(
			FG_Mail_Texts::CONTACT_CREATOR,
			array_merge(
				$this->person_from_member( $owner ),
				array(
					'Arbeitsdienst' => (string) $data['event_label'],
					'Interessent'   => (string) $asker->email,
				)
			)
		);

		$requester = FG_Mail_Texts::compose(
			FG_Mail_Texts::CONTACT_REQUESTER,
			array_merge(
				// Both parties are members of the club and both are named from the
				// member administration, so neither greeting has to fall back.
				$this->person_from_member( $asker ),
				array(
					'Arbeitsdienst' => (string) $data['event_label'],
				)
			)
		);

		$creator_sent = false;
		if ( $creator ) {
			$creator_sent = $this->send(
				$creator_email,
				$creator['subject'],
				$creator['body'],
				array( 'Reply-To: ' . $asker->email ),
				$creator['links']
			);
		}

		$requester_sent = false;
		if ( $creator_sent && $requester ) {
			$requester_sent = $this->send(
				$asker->email,
				$requester['subject'],
				$requester['body'],
				null,
				$requester['links']
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
	 * The member is already in hand, so the greeting comes from that record and
	 * not from a second query for the address that record carries.
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
		$time = $this->repository->format_event_time( $event );

		// A duty without a time used to lose the whole line, because the line was
		// only added when a time was there. A template cannot lose a line, so the
		// line stands and says that there is no time. "Beginn:" on its own would
		// read as a form that was not filled in.
		$uhrzeit = '' === $time ? __( 'unbekannt', 'arbeitsdienste' ) : $time;

		$mail = FG_Mail_Texts::compose(
			FG_Mail_Texts::DUTY_SIGNUP,
			array_merge(
				$this->person_from_member( $member ),
				array(
					'Arbeitsdienst'        => (string) $event->title,
					'Arbeitsdienstdetails' => trim( $event->title . ', ' . $date . ( '' === $time ? '' : ', ' . $time ), ', ' ),
					'Datum'                => $date,
					'Uhrzeit'              => $uhrzeit,
					'Abmeldelink'          => (string) $unregister_url,
				)
			)
		);

		if ( ! $mail ) {
			return false;
		}

		return $this->send( $to, $mail['subject'], $mail['body'], null, $mail['links'] );
	}

	/**
	 * The name fields of the member a message goes to.
	 *
	 * Three values out of one record, and the record is required: every message
	 * of this plugin goes to a member, and the type says so. Until version
	 * 1.14.0 the greeting could fall back to a bare "Hallo" for a recipient that
	 * belonged to nobody, because the ride messages were addressed by an address
	 * that did not have to be in the member list. It does not any more, and a
	 * ride that has no member behind it is deleted by the migration rather than
	 * mailed.
	 *
	 * The three values belong together: {{Anrede}} is not "Hallo" plus a name
	 * written in the template, it is the greeting as a whole. Otherwise the mail
	 * would carry a comma with no word in front of it, which is the one case
	 * where a missing name is not a missing name but a broken sentence.
	 *
	 * @param FG_Member $member Member record. Never empty.
	 * @return array<string, string>
	 */
	private function person_from_member( FG_Member $member ) {
		$vorname = trim( (string) $member->first_name );
		$name    = trim( (string) $member->last_name );

		// Both names, and not a fallback chain. FG_Repository refuses a member row
		// without a first name and without a last name, so a record that comes
		// from there always carries both and there is nothing to decide here. The
		// public list shows the first name alone, and that is a separate question:
		// {{Vorname}} stands for it, while the greeting names the person.
		$ansprache = trim( $vorname . ' ' . $name );

		return array(
			'Vorname' => $vorname,
			'Name'    => $name,
			'Anrede'  => 'Hallo ' . $ansprache,
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
	 * @param array<string, array{url: string, label: string}> $links The links of the message.
	 * @return bool
	 */
	private function send( $to, $subject, $body, $headers = null, $links = array() ) {
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
		//
		// What goes into wp_mail() is the finished text part and not the text
		// with the link placeholders in it. Every filter on wp_mail() reads that
		// string — this plugin's own recorder, a mail log, the plugin that puts
		// its own footer underneath — and a placeholder in it is a message that
		// has not been written yet. The text with the placeholders in it is what
		// the html part is written from, because that is where they become links.
		$message = FG_Mail_Templates::text_part( $body, $links );

		$alternative = static function ( &$phpmailer ) use ( $subject, $body, $links ) {
			FG_Mail_Templates::apply_alternative( $phpmailer, $subject, $body, $links );
		};

		add_filter( 'wp_mail_from', $from_filter );
		add_filter( 'wp_mail_from_name', $name_filter );
		add_action( 'phpmailer_init', $alternative );

		try {
			$sent = wp_mail( $to, $subject, $message, $headers, array(), FG_Mail_Templates::get_logo_embed() );
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
