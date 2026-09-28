<?php
/**
 * Public shortcode listing the upcoming work services.
 *
 * @package Arbeitsdienste
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders [arbeitsdienste], the list of the work duties still to come.
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
							<?php // The description is the one field that may carry more than one line. The text is escaped first, so no markup from the admin reaches the page. ?>
							<td<?php echo ! empty( $row['klasse'] ) ? ' class="' . esc_attr( $row['klasse'] ) . '"' : ''; ?>><?php echo ! empty( $row['zeilenumbruch'] ) ? nl2br( esc_html( $row['wert'] ) ) : esc_html( $row['wert'] ); ?></td>
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
							<label for="fg-member-no-<?php echo esc_attr( $ref ); ?>"><?php esc_html_e( 'Mitgliedsnummer', 'arbeitsdienste' ); ?></label>
							<input type="text" id="fg-member-no-<?php echo esc_attr( $ref ); ?>" name="fg_member_no" maxlength="<?php echo esc_attr( FG_Schema::MEMBER_NO_MAX ); ?>" required>
						</div>
						<div class="fg-field">
							<label for="fg-member-email-<?php echo esc_attr( $ref ); ?>"><?php esc_html_e( 'E-Mail-Adresse', 'arbeitsdienste' ); ?></label>
							<input type="email" id="fg-member-email-<?php echo esc_attr( $ref ); ?>" name="fg_member_email" maxlength="<?php echo esc_attr( FG_Schema::MEMBER_EMAIL_MAX ); ?>" autocomplete="email" required>
						</div>
					</div>
					<?php // The name is never asked for and never shown. Both values are looked up in the member administration, and a pair that does not belong to one member is refused. ?>
					<p class="fg-hint">
						<?php esc_html_e( 'Beide Angaben müssen zu einem Mitglied des Vereins passen. Vorname und Nachname tragen wir für dich ein.', 'arbeitsdienste' ); ?>
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
