<?php
/**
 * Public shortcode and read-only ride listing.
 *
 * @package Arbeitsdienste
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the public page and its forms.
 */
final class FG_Public {
	/**
	 * Anchor of the offer form, target of the link at the top of the page.
	 *
	 * The same name is written into the id of the heading and into the href of
	 * the link, so a typo cannot silently produce a link that goes nowhere.
	 *
	 * @var string
	 */
	const ANCHOR_OFFER = 'fg-angebot';

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
		add_shortcode( 'fahrgemeinschaften', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Put the front-end stylesheet on the page.
	 *
	 * Two shortcodes use it, and both must arrive at the same rules. Registering
	 * the same handle twice is harmless in WordPress, but a second copy of the
	 * path would be a second place to forget the version number, so the call
	 * lives in one place and both callers use it.
	 *
	 * @return void
	 */
	public static function enqueue_style() {
		wp_register_style(
			'arbeitsdienste-public',
			plugins_url( 'assets/css/arbeitsdienste.css', dirname( __DIR__ ) . '/arbeitsdienste.php' ),
			array(),
			FG_VERSION
		);

		wp_enqueue_style( 'arbeitsdienste-public' );
	}

	/**
	 * Render [fahrgemeinschaften].
	 *
	 * @return string
	 */
	public function render_shortcode() {
		// On a site that is configured for HTTPS the form must not be served
		// over plain HTTP. A site that has no TLS at all cannot offer a secure
		// page to send the visitor to, so the form is rendered as it is and
		// administrators are warned about the missing encryption instead.
		if ( ! is_ssl() && FG_Security::site_uses_https() ) {
			return '<div class="fg-notice fg-notice-error" role="alert">' . esc_html__( 'Diese Seite ist nur über eine sichere HTTPS-Verbindung verfügbar.', 'arbeitsdienste' ) . '</div>';
		}

		self::enqueue_style();

		$events    = $this->repository->get_active_events();
		$source    = $this->current_source_url();
		$admin_url = admin_url( 'admin-post.php' );
		$privacy   = get_privacy_policy_url();

		ob_start();
		?>
		<div class="fg-wrapper">
			<?php $this->render_notice(); ?>

			<?php // The list is what most visitors come for, so it comes first. The link is the way back down to the form for everyone else. ?>
			<p class="fg-toplink">
				<a href="#<?php echo esc_attr( self::ANCHOR_OFFER ); ?>"><?php esc_html_e( 'Eintrag anlegen', 'arbeitsdienste' ); ?></a>
			</p>

			<?php $this->render_ride_list( $events, $admin_url, $source ); ?>

			<section class="fg-section" aria-labelledby="<?php echo esc_attr( self::ANCHOR_OFFER ); ?>">
				<h2 id="<?php echo esc_attr( self::ANCHOR_OFFER ); ?>" class="fg-jump"><?php esc_html_e( 'Fahrgemeinschaft anbieten oder suchen', 'arbeitsdienste' ); ?></h2>
				<?php if ( empty( $events ) ) : ?>
					<p class="fg-empty"><?php esc_html_e( 'Aktuell steht kein Arbeitsdienst an, deshalb kannst du dich noch nicht eintragen. Sobald die nächsten Termine feststehen, kannst du hier wieder eine Fahrgemeinschaft anbieten oder suchen.', 'arbeitsdienste' ); ?></p>
				<?php else : ?>
					<p class="fg-hint"><?php esc_html_e( 'Andere private Angaben gehören nicht in die öffentliche Anzeige: genaue Adressen, Telefonnummern und E-Mail-Adressen werden von der Serverseite zurückgewiesen.', 'arbeitsdienste' ); ?></p>
					<form action="<?php echo esc_url( $admin_url ); ?>" method="post">
						<input type="hidden" name="action" value="fg_submit_ride">
						<input type="hidden" name="source_url" value="<?php echo esc_url( $source ); ?>">
						<input type="hidden" name="form_started_at" value="<?php echo esc_attr( time() ); ?>">
						<?php wp_nonce_field( 'fg_submit_ride', 'fg_submit_nonce', false ); ?>
						<div class="fg-honeypot" aria-hidden="true">
							<label for="fg-website"><?php esc_html_e( 'Bitte dieses Feld leer lassen', 'arbeitsdienste' ); ?></label>
							<input type="text" id="fg-website" name="fg_website" value="" tabindex="-1" autocomplete="off">
						</div>

						<div class="fg-form-grid">
							<fieldset class="fg-field fg-field-full">
								<legend><?php esc_html_e( 'Ich …', 'arbeitsdienste' ); ?></legend>
								<label class="fg-choice"><input type="radio" name="fg_mode" value="offer" checked> <?php esc_html_e( 'biete eine Fahrgemeinschaft an', 'arbeitsdienste' ); ?></label>
								<label class="fg-choice"><input type="radio" name="fg_mode" value="search"> <?php esc_html_e( 'suche eine Fahrgemeinschaft', 'arbeitsdienste' ); ?></label>
							</fieldset>

							<div class="fg-field fg-field-full">
								<label for="fg-event"><?php esc_html_e( 'Arbeitsdienst', 'arbeitsdienste' ); ?></label>
								<select id="fg-event" name="fg_event_ref" required>
									<option value=""><?php esc_html_e( 'Bitte auswählen', 'arbeitsdienste' ); ?></option>
									<?php foreach ( $events as $event ) : ?>
										<option value="<?php echo esc_attr( $event->public_ref ); ?>">
											<?php echo esc_html( $event->title . ' – ' . $this->repository->format_event_date( $event ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>

							<?php // The pickup area gets a line of its own. It is the only free text in the form and the text a visitor has to think about, and a half-width box cuts the three examples in the hint in half. The two fields below it share one row, because they are one question. ?>
							<div class="fg-field fg-field-full">
								<label for="fg-origin"><?php esc_html_e( 'Abfahrtsbereich', 'arbeitsdienste' ); ?></label>
								<input type="text" id="fg-origin" name="fg_origin" maxlength="100" required>
								<?php // The three examples are the ones the server also accepts. Each of them was checked against the personal data filter; see the note in PRUEFUMGEBUNG.md about values that end in a street word. ?>
								<span class="fg-hint"><?php esc_html_e( 'Abfahrtsort, Stadtteil, z. B. Langwasser, Nürnberg Nord, S-Bahnstation Ostring.', 'arbeitsdienste' ); ?></span>
							</div>

							<div class="fg-field fg-field-full">
								<label class="fg-consent" for="fg-consent">
									<input id="fg-consent" type="checkbox" name="fg_consent" value="1" required>
									<span>
										<?php // The consent names what becomes public. It has to name the first name from the member administration, because that is what appears in the list and nobody is asked for it here. Naming the source of the name is what makes the sentence checkable: a member who does not recognise the name in the list can see that it came from the club's own records. The box stands above the two fields of the member, not below them, because its own last sentence is about those two: a member who reads before typing knows what the form does with the pair, and the two values are the only ones in this form that are not published. The word "oben" in the sentence is still right — everything the consent lists is still above the box. ?>
										<?php esc_html_e( 'Ich möchte die oben gemachten Angaben zur Organisation der Fahrgemeinschaft öffentlich anzeigen lassen. Dazu gehören mein Vorname aus der Mitgliederverwaltung, die Art des Angebots, der Abfahrtsbereich und der Arbeitsdienst. Meine E-Mail-Adresse und meine Mitgliedsnummer werden dabei nicht öffentlich angezeigt.', 'arbeitsdienste' ); ?>
										<?php if ( $privacy ) : ?>
											<a href="<?php echo esc_url( $privacy ); ?>"><?php esc_html_e( 'Datenschutzerklärung', 'arbeitsdienste' ); ?></a>
										<?php endif; ?>
									</span>
								</label>
							</div>

							<div class="fg-field">
								<label for="fg-ride-member-no"><?php esc_html_e( 'Mitgliedsnummer', 'arbeitsdienste' ); ?></label>
								<input type="text" id="fg-ride-member-no" name="fg_member_no" maxlength="<?php echo esc_attr( FG_Schema::MEMBER_NO_MAX ); ?>" required>
							</div>

							<div class="fg-field">
								<label for="fg-ride-member-email"><?php esc_html_e( 'E-Mail-Adresse', 'arbeitsdienste' ); ?></label>
								<input type="email" id="fg-ride-member-email" name="fg_member_email" maxlength="<?php echo esc_attr( FG_Schema::MEMBER_EMAIL_MAX ); ?>" autocomplete="email" required>
							</div>
						</div>

						<?php // The note about the pair stands on its own line, over the full width of the form and above the button. In the two other forms of the plugin it is a paragraph under the pair, and the same question gets the same place: a note that hangs in a half-width cell under one of the two fields reads as if it belonged to that field alone, and the two fields are one question. The sentence names what becomes public, because the consent above names the same things in the same words. ?>
						<p class="fg-hint fg-hint-row"><?php esc_html_e( 'Beide Angaben müssen zu einem Mitglied des Vereins passen, das sich für diesen Arbeitsdienst eingetragen hat. Dein Vorname steht in der Liste öffentlich; E-Mail-Adresse und Mitgliedsnummer nicht. Du bekommst eine E-Mail als Bestätigung. Prüfe deinen Spam-Ordner, wenn du keine erhältst.', 'arbeitsdienste' ); ?></p>

						<div class="fg-actions">
						<?php // The button says what the press does. Until version 1.16.0 it said "Eintragung vormerken" and promised a step in front of the entry that the code no longer takes; a visitor who read that was told their entry was not yet in the list, and since 1.15.0 it is in the list the moment the page comes back. ?>
						<button class="fg-button" type="submit"><?php esc_html_e( 'Fahrgemeinschaft eintragen', 'arbeitsdienste' ); ?></button>
						</div>
					</form>
				<?php endif; ?>
			</section>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render the published ride list grouped by event.
	 *
	 * @param FG_Event[] $events   Active events.
	 * @param string    $admin_url Form endpoint.
	 * @param string    $source    Source URL.
	 * @return void
	 */
	private function render_ride_list( $events, $admin_url, $source ) {
		// A list needs something to list. With no active work duty there is
		// nothing to group by, and the form section below says why in one
		// sentence. A second message about missing entries would not only repeat
		// that, it would also name work duties that do not exist.
		if ( empty( $events ) ) {
			return;
		}
		$has_rides   = false;
		$privacy_url = get_privacy_policy_url();
		?>
		<section aria-labelledby="fg-list-heading">
			<h2 id="fg-list-heading"><?php esc_html_e( 'Aktuelle Fahrgemeinschaften', 'arbeitsdienste' ); ?></h2>
			<?php foreach ( $events as $event ) : ?>
				<?php
				// One query for all rides of the duty and one for all their
				// members, not one member per line of the list. A ride whose
				// member has been removed from the club is left out: it could
				// neither be named nor answered.
				$alle    = $this->repository->get_published_rides( $event->id );
				$members = $this->repository->get_members_for_rides( $alle );
				$rides   = array();
				foreach ( $alle as $kandidat ) {
					if (
						$this->repository->is_valid_public_ride( $kandidat )
						&& $this->repository->is_displayable_member(
							isset( $members[ $kandidat->member_id ] ) ? $members[ $kandidat->member_id ] : null
						)
					) {
						$rides[] = $kandidat;
					}
				}
				if ( empty( $rides ) ) {
					continue;
				}
				$has_rides = true;
				?>
				<div class="fg-section">
					<?php // Title and date share one heading. The date stays inside it so that it is still read out. ?>
					<h3>
						<?php echo esc_html( $event->title ); ?>
						<span class="fg-date">· <?php echo esc_html( $this->repository->format_event_date( $event ) ); ?></span>
					</h3>
					<div class="fg-rides">
						<?php foreach ( $rides as $ride ) : ?>
							<?php $this->render_ride( $ride, $admin_url, $source, $members ); ?>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
			<?php if ( ! $has_rides ) : ?>
				<p class="fg-empty"><?php esc_html_e( 'Aktuell hat sich für die anstehenden Arbeitsdienste niemand eingetragen. Du kannst unten eine Mitfahrgelegenheit anbieten oder selbst eine suchen.', 'arbeitsdienste' ); ?></p>
			<?php else : ?>
				<?php // The note says the same thing for every entry, so it is stated once for the whole list. ?>
				<p class="fg-hint fg-list-hint">
					<?php esc_html_e( 'Deine Mitgliedsnummer und deine E-Mail-Adresse werden nur an das Mitglied gesendet, das die Fahrgemeinschaft angeboten hat, sofern beide zu einem Mitglied gehören, das sich für den Arbeitsdienst dieses Eintrags eingetragen hat. Angezeigt wird nur der Vorname aus der Mitgliederverwaltung.', 'arbeitsdienste' ); ?>
					<?php if ( $privacy_url ) : ?>
						<a href="<?php echo esc_url( $privacy_url ); ?>"><?php esc_html_e( 'Datenschutzerklärung', 'arbeitsdienste' ); ?></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render a single published ride and contact form.
	 *
	 * An entry is meant to be read in two lines: one heading with mode, origin
	 * and name, and the button that opens the contact form. The form itself only
	 * appears when it is asked for, so a list with many entries stays short. The
	 * reveal is a native details element and needs no script; a form that only a
	 * script could open would be unreachable without it.
	 *
	 * @param FG_Ride               $ride      Ride record.
	 * @param string                $admin_url Form endpoint.
	 * @param string                $source    Source URL.
	 * @param array<int, FG_Member> $members   Members of the whole list, keyed by ID.
	 * @return void
	 */
	private function render_ride( FG_Ride $ride, $admin_url, $source, array $members = array() ) {
		$member     = isset( $members[ $ride->member_id ] ) ? $members[ $ride->member_id ] : null;
		$data       = $this->repository->get_ride_display_data( $ride, $member );
		$mode_label = FG_RIDE_MODE_SEARCH === $data['mode'] ? __( 'Ich suche', 'arbeitsdienste' ) : __( 'Ich biete', 'arbeitsdienste' );
		?>
		<article class="fg-ride">
			<h4 class="fg-ride-title"><span class="fg-badge"><?php echo esc_html( $mode_label ); ?></span> · <span class="fg-origin"><?php echo esc_html( $data['origin'] ); ?></span> · <?php echo esc_html( $data['first_name'] ); ?></h4>
			<details class="fg-contact">
				<?php // As on the signup form: one label, no closing button, and a plain
				// line of text stands in for the control once the form stands open. A
				// summary cannot be made inert with CSS, so the element has to change. ?>
				<summary class="fg-button fg-contact-toggle"><?php esc_html_e( 'Kontaktieren', 'arbeitsdienste' ); ?></summary>
				<span class="fg-contact-latch"><?php esc_html_e( 'Kontaktieren', 'arbeitsdienste' ); ?></span>
				<form class="fg-contact-form" action="<?php echo esc_url( $admin_url ); ?>" method="post">
					<input type="hidden" name="action" value="fg_contact_ride">
					<input type="hidden" name="ride_ref" value="<?php echo esc_attr( $data['public_ref'] ); ?>">
					<input type="hidden" name="source_url" value="<?php echo esc_url( $source ); ?>">
					<input type="hidden" name="form_started_at" value="<?php echo esc_attr( time() ); ?>">
					<?php wp_nonce_field( 'fg_contact_ride', 'fg_contact_nonce', false ); ?>
					<div class="fg-honeypot" aria-hidden="true">
						<label for="fg-contact-website-<?php echo esc_attr( $data['public_ref'] ); ?>"><?php esc_html_e( 'Bitte dieses Feld leer lassen', 'arbeitsdienste' ); ?></label>
						<input type="text" id="fg-contact-website-<?php echo esc_attr( $data['public_ref'] ); ?>" name="fg_website" value="" tabindex="-1" autocomplete="off">
					</div>
					<?php // The same pair of fields as in the two other forms of the plugin, the same row class and the same rule: a member number and an address, and both have to belong to the same member. The field names keep the fg_contact_ prefix because this form stands inside the list while the offer form stands above it on the same page — the question is the same, the name tells the two apart. ?>
					<div class="fg-member-row">
						<div class="fg-field">
							<label for="fg-contact-no-<?php echo esc_attr( $data['public_ref'] ); ?>"><?php esc_html_e( 'Mitgliedsnummer', 'arbeitsdienste' ); ?></label>
							<input type="text" id="fg-contact-no-<?php echo esc_attr( $data['public_ref'] ); ?>" name="fg_contact_member_no" maxlength="<?php echo esc_attr( FG_Schema::MEMBER_NO_MAX ); ?>" required>
						</div>
						<div class="fg-field">
							<label for="fg-contact-email-<?php echo esc_attr( $data['public_ref'] ); ?>"><?php esc_html_e( 'E-Mail-Adresse', 'arbeitsdienste' ); ?></label>
							<input type="email" id="fg-contact-email-<?php echo esc_attr( $data['public_ref'] ); ?>" name="fg_contact_email" maxlength="<?php echo esc_attr( FG_Schema::CONTACT_EMAIL_MAX ); ?>" autocomplete="email" required>
						</div>
					</div>
					<?php // The note stands between the two fields and the button, and so it does in the two other forms. Until version 1.17.0 it stood below the button, and a sentence that explains a question is read after the button that answers it, not before. The button therefore leaves the row of the two fields and gets its own line like the buttons of the other two forms; what it loses is a size, and what it gains is the same place in the same order. The note itself says that neither of the two values is published, and that the mail to the member who offered the ride is the only thing that comes out of them. ?>
					<p class="fg-hint fg-hint-row"><?php esc_html_e( 'Beide Angaben müssen zu einem Mitglied des Vereins passen, das sich für diesen Arbeitsdienst eingetragen hat. Deine Mitgliedsnummer und deine E-Mail-Adresse stehen nirgends öffentlich. Du bekommst eine E-Mail als Bestätigung. Prüfe deinen Spam-Ordner, wenn du keine erhältst.', 'arbeitsdienste' ); ?></p>
					<div class="fg-actions">
						<button class="fg-button" type="submit"><?php esc_html_e( 'Absenden', 'arbeitsdienste' ); ?></button>
					</div>
				</form>
			</details>
		</article>
		<?php
	}

	/**
	 * Render a safe public notice.
	 *
	 * @return void
	 */
	private function render_notice() {
		$notice_key = '';
		if ( isset( $_GET['fg_notice'] ) && ! is_array( $_GET['fg_notice'] ) && ! is_object( $_GET['fg_notice'] ) ) {
			$notice_key = sanitize_key( wp_unslash( $_GET['fg_notice'] ) );
		}
		$notices    = array(
			'published'        => array( __( 'Deine Fahrgemeinschaft steht in der Liste. Die E-Mail dazu enthält den Link, mit dem du sie wieder löschen kannst.', 'arbeitsdienste' ), false ),
			'deleted'          => array( __( 'Die Eintragung wurde gelöscht.', 'arbeitsdienste' ), false ),
			// Both of these name the pair and not the address alone, in the same words the
			// signup form uses for its own refusal. The page asks two values in
			// both cases, and a message that talked about one of them would send
			// the reader looking for a mistake in the wrong field. Which of the two
			// was wrong stays unsaid in all three, and that is the same decision.
			'contact_received' => array( __( 'Vielen Dank für deine Anfrage. Wir informieren das Mitglied, das die Fahrgemeinschaft angeboten hat, sofern Mitgliedsnummer und E-Mail-Adresse zu einem Mitglied des Vereins passen, das sich für den gewählten Arbeitsdienst eingetragen hat.', 'arbeitsdienste' ), false ),
			// This one is the refusal of the offer form, and it names the rule and the next
			// step. It must never name a finding — which of the two conditions was
			// wrong — because a sentence that says the number was right only appears
			// when it was, and its appearance tells a passer-by that a guessed member
			// number exists. The rule it names is printed under the form anyway, so
			// saying it here gives away nothing, and the next step is true for every
			// reader: one who is not on the duty's list can act on it, and one who
			// mistyped the number learns nothing from a sentence that fits everybody.
			//
			// Five places lead here: a post without HTTPS, the honeypot, one block of
			// eight conditions (class-fg-actions.php), a member who is not on the duty's
			// list, and a write that failed. A sentence that fits all five cannot be
			// specific, and the rule is the one thing all five share. Until 1.25.2 it
			// named only the pair, and a member whose pair was right was sent to look
			// for a mistake in the number or the address that was not there.
			'not_created'      => array( __( 'Die Eintragung konnte nicht angelegt werden. Sie ist nur möglich, wenn Mitgliedsnummer und E-Mail-Adresse zu einem Mitglied des Vereins passen, das für diesen Arbeitsdienst angemeldet ist. Bist du noch nicht angemeldet, trag dich zuerst für diesen Dienst ein.', 'arbeitsdienste' ), true ),
			'email_failed'     => array( __( 'Die Eintragung konnte nicht angelegt werden, weil die E-Mail mit dem Lösch-Link nicht zugestellt werden konnte. Bitte versuche es später erneut.', 'arbeitsdienste' ), true ),
			'invalid_token'    => array( __( 'Der Link ist ungültig oder abgelaufen. Bitte kontaktiere uns, falls du Unterstützung benötigst.', 'arbeitsdienste' ), true ),
			'form_expired'     => array( __( 'Das Formular ist nicht mehr gültig. Bitte lade die Seite neu und sende das Formular erneut ab.', 'arbeitsdienste' ), true ),
		);

		if ( ! isset( $notices[ $notice_key ] ) ) {
			return;
		}

		// The id is what the redirect after a submission points at. fg-jump leaves
		// room above, so a theme with a header that stays in place does not push
		// the message under it.
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
