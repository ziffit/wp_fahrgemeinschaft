<?php
/**
 * Public shortcode listing the upcoming work services.
 *
 * @package Arbeitsdienste
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders [arbeitsdienste], the list of the work duties still to come, and
 * [anmeldung_pruefen], the form that answers "am I registered?".
 *
 * The list is built from the same work duties as the selection field of the
 * offer form, so the two pages cannot disagree about what is coming up.
 *
 * Under every duty stands a form that registers a member for it. The form asks
 * for the member number and the e-mail address and nothing else, because the
 * name is already in the member administration and must not be typed a second
 * time by someone who might type it differently. Both values are checked against
 * that administration; a pair that does not belong together is refused.
 *
 * The two shortcodes are meant for two pages and share one list of messages,
 * because they answer with the same words where they answer the same thing.
 *
 * The page carries no script. The form is opened by a native details element,
 * the same way the contact form of a ride is, so a member without JavaScript can
 * register just as well.
 */
final class FG_Public_Events {
	/**
	 * Anchor of the list, used as the id of its heading.
	 *
	 * @var string
	 */
	const ANCHOR_LIST = 'fg-dienste';

	/**
	 * Anchor of the status form, used as the id of its heading.
	 *
	 * @var string
	 */
	const ANCHOR_STATUS = 'fg-anmeldung';

	/**
	 * Repository.
	 *
	 * @var FG_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param FG_Repository|null $repository Optional repository.
	 */
	public function __construct( FG_Repository $repository = null ) {
		$this->repository = $repository ? $repository : new FG_Repository();
		add_shortcode( 'arbeitsdienste', array( $this, 'render_shortcode' ) );
		add_shortcode( 'anmeldung_pruefen', array( $this, 'render_status_shortcode' ) );
	}

	/**
	 * Render [arbeitsdienste].
	 *
	 * @return string
	 */
	public function render_shortcode() {
		FG_Public::enqueue_style();

		$events = $this->repository->get_active_events();

		// One query for the whole page: the free places of every card depend on
		// how many members are registered, and asking per card would send one
		// query per card.
		$counts = $this->repository->get_registration_counts_for_events(
			array_map(
				static function ( FG_Event $event ) {
					return $event->id;
				},
				$events
			)
		);

		ob_start();
		?>
		<div class="fg-wrapper">
			<?php $this->render_notice(); ?>
			<section class="fg-section" aria-labelledby="<?php echo esc_attr( self::ANCHOR_LIST ); ?>">
				<h2 id="<?php echo esc_attr( self::ANCHOR_LIST ); ?>"><?php esc_html_e( 'Kommende Arbeitsdienste', 'arbeitsdienste' ); ?></h2>
				<?php if ( empty( $events ) ) : ?>
					<p class="fg-empty"><?php esc_html_e( 'Aktuell ist kein Arbeitsdienst eingetragen. Sobald die nächsten Termine feststehen, stehen sie hier.', 'arbeitsdienste' ); ?></p>
				<?php else : ?>
					<?php foreach ( $events as $event ) : ?>
						<?php
						$registered = isset( $counts[ $event->id ] ) ? (int) $counts[ $event->id ] : 0;
						$this->render_card( $event, $registered );
						?>
					<?php endforeach; ?>
				<?php endif; ?>
			</section>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render [anmeldung_pruefen].
	 *
	 * A member who registered for a duty has no way to see that it arrived: the
	 * page names no participants, by the club's decision, and the only proof is
	 * the mail. This form is the way back to that proof. It asks the same pair
	 * as the three other forms of the plugin and sends the same mail again with
	 * a fresh unregistration link.
	 *
	 * The answer is a sentence and not a list of duties. A list would have to
	 * travel through the redirect that follows the form, and the one thing this
	 * plugin never puts into an address is the pair — a page that carried the
	 * result of a lookup would carry the lookup itself in the history of the
	 * browser that made it.
	 *
	 * @return void
	 */
	public function render_status_shortcode() {
		if ( ! is_ssl() && FG_Security::site_uses_https() ) {
			return '<div class="fg-notice fg-notice-error" role="alert">' . esc_html__( 'Diese Seite ist nur über eine sichere HTTPS-Verbindung verfügbar.', 'arbeitsdienste' ) . '</div>';
		}

		FG_Public::enqueue_style();

		$admin_url = admin_url( 'admin-post.php' );
		$source    = $this->current_source_url();
		$privacy   = get_privacy_policy_url();

		ob_start();
		?>
		<div class="fg-wrapper">
			<?php $this->render_notice(); ?>
			<section class="fg-section" aria-labelledby="<?php echo esc_attr( self::ANCHOR_STATUS ); ?>">
				<h2 id="<?php echo esc_attr( self::ANCHOR_STATUS ); ?>"><?php esc_html_e( 'Bin ich angemeldet?', 'arbeitsdienste' ); ?></h2>
				<p class="fg-hint"><?php esc_html_e( 'Prüfe hier, ob du angemeldet bist und lasse dir deine Bestätigungsmail erneut zusenden.', 'arbeitsdienste' ); ?></p>
				<form class="fg-status-form" action="<?php echo esc_url( $admin_url ); ?>" method="post">
					<input type="hidden" name="action" value="fg_resend_duty_mails">
					<input type="hidden" name="source_url" value="<?php echo esc_url( $source ); ?>">
					<input type="hidden" name="form_started_at" value="<?php echo esc_attr( time() ); ?>">
					<?php wp_nonce_field( 'fg_resend_duty_mails', 'fg_resend_nonce', false ); ?>
					<div class="fg-honeypot" aria-hidden="true">
						<label for="fg-status-website"><?php esc_html_e( 'Bitte dieses Feld leer lassen', 'arbeitsdienste' ); ?></label>
						<input type="text" id="fg-status-website" name="fg_website" value="" tabindex="-1" autocomplete="off">
					</div>
					<?php // The same pair in the same words as in the three other forms of the plugin. The field names carry their own prefix because this form is meant for a page of its own and a second form beside it must not answer for it. ?>
					<div class="fg-member-row">
						<div class="fg-field">
							<label for="fg-status-member-no"><?php esc_html_e( 'Mitgliedsnummer (4-stellig)', 'arbeitsdienste' ); ?></label>
							<input type="text" id="fg-status-member-no" name="fg_status_member_no" maxlength="<?php echo esc_attr( FG_Schema::MEMBER_NO_MAX ); ?>" required>
						</div>
						<div class="fg-field">
							<label for="fg-status-member-email"><?php esc_html_e( 'E-Mail-Adresse', 'arbeitsdienste' ); ?></label>
							<input type="email" id="fg-status-member-email" name="fg_status_member_email" maxlength="<?php echo esc_attr( FG_Schema::MEMBER_EMAIL_MAX ); ?>" autocomplete="email" required>
						</div>
					</div>
					<p class="fg-hint fg-hint-row"><?php esc_html_e( 'Beide Angaben müssen zu einem Mitglied des Vereins passen. Dann schicken wir dir die Bestätigung zu deinen kommenden Arbeitsdiensten noch einmal zu.', 'arbeitsdienste' ); ?></p>
					<div class="fg-actions">
						<button class="fg-button" type="submit"><?php esc_html_e( 'Anmeldung prüfen', 'arbeitsdienste' ); ?></button>
					</div>
					<?php if ( $privacy ) : ?>
						<p class="fg-hint">
							<a href="<?php echo esc_url( $privacy ); ?>"><?php esc_html_e( 'Datenschutzerklärung', 'arbeitsdienste' ); ?></a>
						</p>
					<?php endif; ?>
				</form>
			</section>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render one work duty as a card holding a table of its details.
	 *
	 * The details are a table because they are pairs of a name and a value, and
	 * the name stands in the first cell of its row with `scope`, so it is read
	 * out together with the value it belongs to. A description list would be the
	 * other way to write it; the table was asked for and is valid markup.
	 *
	 * A row appears only when the club has stated something, except for the free
	 * places. Those are a computed value and never "nothing stated": the page has
	 * to be able to say that a duty is full, and it can only say that if the
	 * number is on it.
	 *
	 * The card carries the public reference of its duty as its anchor. Two duties
	 * can carry the same title — the club enters them by hand, and a repeated
	 * date makes it easy — so a title names no card, while a link has to be able
	 * to point at one duty of a list. The reference is a value the page already
	 * prints in the signup form of every duty that can be signed up for, and it
	 * reaches nothing: naming a duty in a signup is all it is good for, and that
	 * still needs a member number and the matching address.
	 *
	 * @param FG_Event $event     Work duty.
	 * @param int      $registered Members registered so far.
	 * @return void
	 */
	private function render_card( FG_Event $event, $registered ) {
		$rows = array(
			array(
				'label' => __( 'Datum', 'arbeitsdienste' ),
				'wert'  => $this->repository->format_event_date_long( $event ),
			),
		);

		$beginn = $this->repository->format_event_time( $event );
		if ( '' !== $beginn ) {
			$rows[] = array(
				'label' => __( 'Beginn', 'arbeitsdienste' ),
				'wert'  => $beginn,
			);
		}

		if ( '' !== $event->group_name ) {
			$rows[] = array(
				'label' => __( 'Gruppe', 'arbeitsdienste' ),
				'wert'  => $event->group_name,
			);
		}

		if ( $event->demand > 0 ) {
			$rows[] = array(
				'label' => __( 'Bedarf', 'arbeitsdienste' ),
				'wert'  => sprintf(
					/* translators: %d: number of people. */
					_n( '%d Person', '%d Personen', $event->demand, 'arbeitsdienste' ),
					$event->demand
				),
			);
		}

		// The free places are the number the club asks about on the phone, and
		// they stand in the same card as the demand they come from: a reader who
		// sees "4 Personen" and "3" needs nothing else to know there is one place
		// left. A duty that states no demand has no places to give, and it says
		// so with a zero rather than leaving the row out.
		$freie = (int) $this->repository->free_places( $event, $registered );
		$rows[] = array(
			'label'  => __( 'Verfügbare freie Plätze', 'arbeitsdienste' ),
			'wert'   => (string) $freie,
			'klasse' => $freie > 0 ? 'fg-places' : 'fg-places fg-places-none',
		);

		if ( $event->duration_hours > 0 ) {
			$rows[] = array(
				'label' => __( 'Dauer', 'arbeitsdienste' ),
				'wert'  => sprintf(
					/* translators: %d: number of hours. */
					_n( '%d Stunde', '%d Stunden', $event->duration_hours, 'arbeitsdienste' ),
					$event->duration_hours
				),
			);
		}

		// The meeting point, before the description: a short line somebody can act
		// on, in front of the long text. Three cases, and the third is the reason the
		// first two are here at all — an address without a name is not shown, because
		// a link with an empty wording says nothing and leads nowhere.
		if ( '' !== $event->meeting_point ) {
			$rows[] = array(
				'label' => __( 'Treffpunkt', 'arbeitsdienste' ),
				'wert'  => $event->meeting_point,
				'link'  => $event->meeting_point_url,
			);
		}

		if ( '' !== $event->description ) {
			$rows[] = array(
				'label'         => __( 'Beschreibung', 'arbeitsdienste' ),
				'wert'          => $event->description,
				'zeilenumbruch' => true,
			);
		}
		?>
		<article class="fg-event-card" id="fg-dienst-<?php echo esc_attr( $event->public_ref ); ?>">
			<h3 class="fg-event-title"><?php echo esc_html( $event->title ); ?></h3>
			<table class="fg-event-data">
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
							<?php // The description is the one field that may carry more than one line. The text is escaped first, so no markup from the admin reaches the page. The meeting point is the one row whose value is a link: there the text is escaped and the address is run through esc_url(), and both end up inside one anchor. The pin is part of the text because the club typed it — the markup draws none, so a club that leaves it out has no pin. ?>
							<td<?php echo ! empty( $row['klasse'] ) ? ' class="' . esc_attr( $row['klasse'] ) . '"' : ''; ?>>
								<?php
								if ( ! empty( $row['link'] ) ) :
									?>
									<?php // A map opens in a new tab, and `rel` says to the browser that this page may not reach into the new one. Without the second half the opened page can change the address bar of ours, and a visitor who follows the back button then lands somewhere that looks like the club's page. ?>
									<a href="<?php echo esc_url( $row['link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['wert'] ); ?></a>
									<?php
								else :
									?>
									<?php echo ! empty( $row['zeilenumbruch'] ) ? nl2br( esc_html( $row['wert'] ) ) : esc_html( $row['wert'] ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php $this->render_signup( $event, $registered ); ?>
		</article>
		<?php
	}

	/**
	 * Render the registration form of one work duty, or the reason there is none.
	 *
	 * Two different reasons stop a registration, and they are not the same fact,
	 * so they do not share a sentence. A duty whose places are taken is full.
	 * A duty for which the club entered no demand states no number, and a
	 * registration that cannot be counted against anything is not offered.
	 *
	 * @param FG_Event $event     Work duty.
	 * @param int      $registered Members registered so far.
	 * @return void
	 */
	private function render_signup( FG_Event $event, $registered ) {
		if ( $this->repository->can_register( $event, $registered ) ) {
			// The public reference is the id of the form fields. It is unique per
			// duty, so a page with many duties has no id twice, and it holds
			// nothing a visitor could use to reach anything else.
			$ref       = $event->public_ref;
			$admin_url = admin_url( 'admin-post.php' );
			$source    = $this->current_source_url();
			$privacy   = get_privacy_policy_url();
			?>
			<details class="fg-signup">
				<?php /*
				 * One label, and no way back: a form that has been opened stays open,
				 * and several of them may stand open at the same time. That is why there
				 * is no "Schließen" beside "Eintragen" — a second button on one control
				 * for something nobody asked for.
				 *
				 * The closing affordance cannot be taken off the summary itself. A summary
				 * is the switch of a details element, and CSS cannot make one inert: the
				 * element would still be in the tab order and would still fold the form on
				 * Enter. So the control leaves the page once it has done its work, and a
				 * plain line of text stands in its place. Text is not focusable and not
				 * clickable, so nothing is left that could fold the form again — with the
				 * mouse or with the keyboard.
				 */ ?>
				<summary class="fg-button fg-signup-toggle"><?php esc_html_e( 'Eintragen', 'arbeitsdienste' ); ?></summary>
				<span class="fg-signup-latch"><?php esc_html_e( 'Eintragen', 'arbeitsdienste' ); ?></span>
				<form class="fg-signup-form" action="<?php echo esc_url( $admin_url ); ?>" method="post">
					<input type="hidden" name="action" value="fg_register_member">
					<?php // The duty travels by its public reference, never by its row ID, the same way the offer form names a duty. ?>
					<input type="hidden" name="fg_event_ref" value="<?php echo esc_attr( $event->public_ref ); ?>">
					<input type="hidden" name="source_url" value="<?php echo esc_url( $source ); ?>">
					<input type="hidden" name="form_started_at" value="<?php echo esc_attr( time() ); ?>">
					<?php wp_nonce_field( 'fg_register_member', 'fg_register_nonce', false ); ?>
					<div class="fg-honeypot" aria-hidden="true">
						<label for="fg-signup-website-<?php echo esc_attr( $ref ); ?>"><?php esc_html_e( 'Bitte dieses Feld leer lassen', 'arbeitsdienste' ); ?></label>
						<input type="text" id="fg-signup-website-<?php echo esc_attr( $ref ); ?>" name="fg_website" value="" tabindex="-1" autocomplete="off">
					</div>
					<?php // The same row class as the contact form of a ride, and the same
					// two fields. One question asked in three forms of the plugin is one
					// question with one answer on the server, and a visitor who has
					// already typed the pair into one form is not asked to learn that
					// another form wants it in a different order. ?>
					<div class="fg-member-row">
						<div class="fg-field">
							<label for="fg-member-no-<?php echo esc_attr( $ref ); ?>"><?php esc_html_e( 'Mitgliedsnummer (4-stellig)', 'arbeitsdienste' ); ?></label>
							<input type="text" id="fg-member-no-<?php echo esc_attr( $ref ); ?>" name="fg_member_no" maxlength="<?php echo esc_attr( FG_Schema::MEMBER_NO_MAX ); ?>" required>
						</div>
						<div class="fg-field">
							<label for="fg-member-email-<?php echo esc_attr( $ref ); ?>"><?php esc_html_e( 'E-Mail-Adresse', 'arbeitsdienste' ); ?></label>
							<input type="email" id="fg-member-email-<?php echo esc_attr( $ref ); ?>" name="fg_member_email" maxlength="<?php echo esc_attr( FG_Schema::MEMBER_EMAIL_MAX ); ?>" autocomplete="email" required>
						</div>
					</div>
					<?php // The note stands between the two fields and the button, the same place it has in the two other forms of the plugin, and the same class carries its distance in all three. Until version 1.17.0 it also said that the club enters the first and the last name; that sentence is gone, because the mail that goes out is the better place to see them, and it greets with both. The sentence about the mailbox is the one a member needs before they press the button: a registration whose mail did not arrive is a place that is taken and cannot be given back. ?>
					<p class="fg-hint fg-hint-row">
						<?php esc_html_e( 'Beide Angaben müssen zu einem Mitglied des Vereins passen. Du bekommst eine E-Mail als Bestätigung. Prüfe deinen Spam-Ordner, wenn du keine erhältst.', 'arbeitsdienste' ); ?>
					</p>
					<div class="fg-actions">
						<button class="fg-button" type="submit"><?php esc_html_e( 'verbindlich anmelden', 'arbeitsdienste' ); ?></button>
					</div>
					<?php if ( $privacy ) : ?>
						<p class="fg-hint">
							<a href="<?php echo esc_url( $privacy ); ?>"><?php esc_html_e( 'Datenschutzerklärung', 'arbeitsdienste' ); ?></a>
						</p>
					<?php endif; ?>
				</form>
			</details>
			<?php
			return;
		}

		$reason = $event->demand > 0
			? __( 'Dieser Arbeitsdienst ist vollständig belegt.', 'arbeitsdienste' )
			: self::no_demand_text();
		?>
		<p class="fg-signup-closed"><?php echo esc_html( $reason ); ?></p>
		<?php
	}

	/**
	 * The sentence for a duty that states no demand.
	 *
	 * Two places need it and they have to say the same thing: the closed card
	 * below, and the refusal the server sends when a form is posted for a duty
	 * that never had a place to give. A duty that asked for people and got them
	 * is full, which is a statement about the duty; a duty that never stated a
	 * demand was never open, which is a different statement, and "vollständig
	 * belegt" for the second one would be a claim that is not true.
	 *
	 * @return string Translated sentence.
	 */
	public static function no_demand_text() {
		return __( 'Für diesen Arbeitsdienst ist kein Bedarf eingetragen. Ohne diese Angabe ist keine Anmeldung möglich.', 'arbeitsdienste' );
	}

	/**
	 * Render a safe public notice.
	 *
	 * The messages are the ones the ride page knows plus the ones only a
	 * registration can produce. Both pages read the same query argument, so a
	 * notice that arrives on the page that is not its own is simply not printed.
	 *
	 * @return void
	 */
	private function render_notice() {
		$notice_key = '';
		if ( isset( $_GET['fg_notice'] ) && ! is_array( $_GET['fg_notice'] ) && ! is_object( $_GET['fg_notice'] ) ) {
			$notice_key = sanitize_key( wp_unslash( $_GET['fg_notice'] ) );
		}

		$notices = array(
			'registered'       => array( __( 'Du bist für diesen Arbeitsdienst angemeldet. Eine E-Mail mit dem Abmeldelink ist unterwegs.', 'arbeitsdienste' ), false ),
			'already_registered' => array( __( 'Du bist für diesen Arbeitsdienst bereits angemeldet.', 'arbeitsdienste' ), false ),
			'unregistered'     => array( __( 'Deine Anmeldung wurde gelöscht.', 'arbeitsdienste' ), false ),
			'not_registered'   => array( __( 'Die Anmeldung ist nicht möglich. Mitgliedsnummer und E-Mail-Adresse müssen zu einem Mitglied des Vereins passen.', 'arbeitsdienste' ), true ),
			'duty_full'        => array( __( 'Für diesen Arbeitsdienst ist keine Anmeldung möglich, es sind keine freien Plätze mehr.', 'arbeitsdienste' ), true ),
			'no_demand'        => array( self::no_demand_text(), true ),
			'email_failed'     => array( __( 'Die Anmeldung konnte nicht angelegt werden, weil die E-Mail mit dem Abmeldelink nicht zugestellt werden konnte. Bitte versuche es später erneut.', 'arbeitsdienste' ), true ),
			'invalid_token'    => array( __( 'Der Link ist ungültig oder abgelaufen.', 'arbeitsdienste' ), true ),
			'form_expired'     => array( __( 'Das Formular ist nicht mehr gültig. Bitte lade die Seite neu und sende das Formular erneut ab.', 'arbeitsdienste' ), true ),
			// The answer of the status form. It covers two cases with one
			// sentence on purpose: a pair that belongs to no member, and a member
			// who is on no upcoming duty. A sentence that told the two apart
			// would tell a passer-by whether a guessed member number exists, the
			// same rule the signup form follows.
			//
			// The second half is not padding. A member who worked a duty last
			// month and forgot about it reads the first half as "I am not
			// registered", and the second half says that this is a statement
			// about what is coming, not about what has been. It is also the
			// sentence that makes the answer true for a duty that is over: the
			// registration of such a duty is still in the table, and the form
			// does not look at it.
			'duty_none'        => array( __( 'Du bist für keinen zukünftigen Arbeitsdienst angemeldet. Das ist keine Aussage, ob du bereits einen Arbeitsdienst geleistet hast.', 'arbeitsdienste' ), false ),
			// Singular and plural apart, because "die Bestätigungsmail(s)" is
			// what a form letter writes and this is a sentence to a member. Which
			// of the two stands here is the number of mails that were handed
			// over, not the number of duties found: a member who reads that the
			// mails are coming and gets none would look in the wrong place.
			'duty_mail_resent' => array( __( 'Wir haben dir die Bestätigungsmail zu deinem Arbeitsdienst erneut zugesendet. Bitte prüfe deinen Posteingang und gegebenenfalls den Spam-Ordner.', 'arbeitsdienste' ), false ),
			'duty_mails_resent' => array( __( 'Wir haben dir die Bestätigungsmails zu deinen Arbeitsdiensten erneut zugesendet. Bitte prüfe deinen Posteingang und gegebenenfalls den Spam-Ordner.', 'arbeitsdienste' ), false ),
			'duty_mail_wait'   => array( __( 'Die Bestätigung wurde gerade verschickt. Bitte warte eine Minute und versuche es dann noch einmal.', 'arbeitsdienste' ), true ),
			// Its own sentence and not the one of the signup form: that one says
			// that the registration could not be written, and here the
			// registration stands — it is the mail that did not leave.
			'duty_mail_failed' => array( __( 'Die E-Mail konnte nicht zugestellt werden. Bitte versuche es später erneut.', 'arbeitsdienste' ), true ),
		);

		if ( ! isset( $notices[ $notice_key ] ) ) {
			return;
		}

		printf(
			'<div id="%s" class="fg-notice fg-jump%s" role="status">%s</div>',
			esc_attr( FG_NOTICE_ANCHOR ),
			$notices[ $notice_key ][1] ? ' fg-notice-error' : '',
			esc_html( $notices[ $notice_key ][0] )
		);
	}

	/**
	 * Determine the local page URL used for post-submit redirects.
	 *
	 * @return string
	 */
	private function current_source_url() {
		$post = get_post();
		if ( $post ) {
			$permalink = get_permalink( $post );
			if ( $permalink ) {
				return remove_query_arg( array( 'fg_notice', 'fg_message' ), $permalink );
			}
		}

		return home_url( '/' );
	}
}
