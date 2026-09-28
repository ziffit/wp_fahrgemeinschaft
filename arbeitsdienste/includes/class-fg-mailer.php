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

		$data   = $this->repository->get_ride_display_data( $ride );
		$member = $data['member'];
		$to     = $this->repository->get_ride_contact_email( $ride, $member );
		if ( ! $to || ! $member ) {
			return false;
		}

		$mail = FG_Mail_Texts::compose(
			FG_Mail_Texts::RIDE_PENDING,
			array_merge(
				$this->person_from_member( $member ),
				array(
					'Art'                  => $this->mode_label( $data['mode'] ),
					'Arbeitsdienst'        => (string) $data['event_label'],
					'Arbeitsdienstdetails' => $data['event_label'] . ( $data['event_date'] ? ' (' . $data['event_date'] . ')' : '' ),
					'Abfahrtsbereich'     => (string) $data['origin'],
					'Bestaetigungslink'   => (string) $confirm_url,
					'Verwerfungslink'     => (string) $discard_url,
				)
			)
		);

		if ( ! $mail ) {
			return false;
		}

		return $this->send( $to, $mail['subject'], $mail['body'], null, $mail['links'] );
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
					'Arbeitsdienst' => (string) $data['event_label'],
					'Loeschlink'    => (string) $delete_url,
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
	 * Up to version 1.13.0 the name of the member was not in the message, because
	 * a name in a forwarded message is a piece of personal data that has left the
	 * club. Since the club can read the wording of this mail, it can put the name
	 * in there or leave it out, and the member is greeted by name either way. The
	 * decision is therefore one the club makes per message, not one this code
	 * makes for it.
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
				$this->person( $to, '' ),
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
	 * The name fields of a recipient, looked up by their address.
	 *
	 * The three values belong together: {{Anrede}} is not "Hallo" plus a name
	 * written in the template, it is the greeting as a whole. Otherwise an
	 * address that belongs to no member leaves "Hallo ," standing in the mail,
	 * and that is the one case where a missing name is not a missing name but a
	 * broken sentence.
	 *
	 * The ride messages do not come through here any more, because they know
	 * their member; see person_from_member() below.
	 *
	 * @param string $email       Recipient address.
	 * @param string $ersatz_name Name to use when the address belongs to nobody.
	 * @return array<string, string>
	 */
	private function person( $email, $ersatz_name ) {
		return $this->person_from_member( $this->repository->get_member_by_email( $email ), $ersatz_name );
	}

	/**
	 * The name fields of a member that is already at hand.
	 *
	 * The same three values as person(), from a record the caller already has.
	 * Every ride message knows its member, because a ride points at one, and
	 * looking the address up again would be a second query for an answer that
	 * was in the row.
	 *
	 * @param FG_Member|null $member      Member record.
	 * @param string          $ersatz_name Name to use when there is no member.
	 * @return array<string, string>
	 */
	private function person_from_member( $member, $ersatz_name = '' ) {
		$vorname = $member ? trim( (string) $member->first_name ) : '';
		$name    = $member ? trim( (string) $member->last_name ) : '';

		// The first name alone, and not "first name, else last name": a member
		// row without a first name cannot be written, FG_Repository refuses it,
		// so the second name is never the only one there is. The fallback for a
		// recipient that belongs to nobody is a designation the caller passes in,
		// and where there is none the greeting stays a bare "Hallo".
		$ansprache = '' !== $vorname ? $vorname : trim( (string) $ersatz_name );

		return array(
			'Vorname' => $vorname,
			'Name'    => $name,
			'Anrede'  => '' === $ansprache ? 'Hallo' : 'Hallo ' . $ansprache,
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
