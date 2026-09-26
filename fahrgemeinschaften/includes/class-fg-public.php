<?php
/**
 * Public shortcode and read-only ride listing.
 *
 * @package Fahrgemeinschaften
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
	 * Register the front-end stylesheet.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			'fahrgemeinschaften-public',
			plugins_url( 'assets/css/fahrgemeinschaften.css', dirname( __DIR__ ) . '/fahrgemeinschaften.php' ),
			array(),
			FG_VERSION
		);
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
			return '<div class="fg-notice fg-notice-error" role="alert">' . esc_html__( 'Diese Seite ist nur über eine sichere HTTPS-Verbindung verfügbar.', 'fahrgemeinschaften' ) . '</div>';
		}

		$this->register_assets();
		wp_enqueue_style( 'fahrgemeinschaften-public' );

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
				<a href="#<?php echo esc_attr( self::ANCHOR_OFFER ); ?>"><?php esc_html_e( 'Eintrag anlegen', 'fahrgemeinschaften' ); ?></a>
			</p>

			<?php $this->render_ride_list( $events, $admin_url, $source ); ?>

			<section class="fg-section" aria-labelledby="<?php echo esc_attr( self::ANCHOR_OFFER ); ?>">
				<h2 id="<?php echo esc_attr( self::ANCHOR_OFFER ); ?>" class="fg-jump"><?php esc_html_e( 'Fahrgemeinschaft anbieten oder suchen', 'fahrgemeinschaften' ); ?></h2>
				<?php if ( empty( $events ) ) : ?>
					<p class="fg-empty"><?php esc_html_e( 'Aktuell sind keine Arbeitsdienste für Fahrgemeinschaften verfügbar.', 'fahrgemeinschaften' ); ?></p>
				<?php else : ?>
					<p class="fg-hint"><?php esc_html_e( 'Andere private Angaben gehören nicht in die öffentliche Anzeige: genaue Adressen, Telefonnummern und E-Mail-Adressen werden von der Serverseite zurückgewiesen.', 'fahrgemeinschaften' ); ?></p>
					<form action="<?php echo esc_url( $admin_url ); ?>" method="post">
						<input type="hidden" name="action" value="fg_submit_ride">
						<input type="hidden" name="source_url" value="<?php echo esc_url( $source ); ?>">
						<input type="hidden" name="form_started_at" value="<?php echo esc_attr( time() ); ?>">
						<?php wp_nonce_field( 'fg_submit_ride', 'fg_submit_nonce', false ); ?>
						<div class="fg-honeypot" aria-hidden="true">
							<label for="fg-website"><?php esc_html_e( 'Bitte dieses Feld leer lassen', 'fahrgemeinschaften' ); ?></label>
							<input type="text" id="fg-website" name="fg_website" value="" tabindex="-1" autocomplete="off">
						</div>

						<div class="fg-form-grid">
							<fieldset class="fg-field fg-field-full">
								<legend><?php esc_html_e( 'Ich …', 'fahrgemeinschaften' ); ?></legend>
								<label class="fg-choice"><input type="radio" name="fg_mode" value="offer" checked> <?php esc_html_e( 'biete eine Fahrgemeinschaft an', 'fahrgemeinschaften' ); ?></label>
								<label class="fg-choice"><input type="radio" name="fg_mode" value="search"> <?php esc_html_e( 'suche eine Fahrgemeinschaft', 'fahrgemeinschaften' ); ?></label>
							</fieldset>

							<div class="fg-field fg-field-full">
								<label for="fg-event"><?php esc_html_e( 'Arbeitsdienst', 'fahrgemeinschaften' ); ?></label>
								<select id="fg-event" name="fg_event_ref" required>
									<option value=""><?php esc_html_e( 'Bitte auswählen', 'fahrgemeinschaften' ); ?></option>
									<?php foreach ( $events as $event ) : ?>
										<option value="<?php echo esc_attr( $event->public_ref ); ?>">
											<?php echo esc_html( $event->title . ' – ' . $this->repository->format_event_date( $event ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>

							<div class="fg-field">
								<label for="fg-alias"><?php esc_html_e( 'Vorname oder Spitzname', 'fahrgemeinschaften' ); ?></label>
								<input type="text" id="fg-alias" name="fg_alias" maxlength="80" required>
								<?php // The name is the one thing that is meant to be read by people who know the member. ?>
								<span class="fg-hint"><?php esc_html_e( 'Steht in der Liste öffentlich. Wer nicht mit Namen auftreten möchte, tritt unter einem Spitznamen auf.', 'fahrgemeinschaften' ); ?></span>
							</div>

							<div class="fg-field">
								<label for="fg-origin"><?php esc_html_e( 'Abfahrtsbereich', 'fahrgemeinschaften' ); ?></label>
								<input type="text" id="fg-origin" name="fg_origin" maxlength="100" required>
								<?php // The three examples are the ones the server also accepts. Each of them was checked against the personal data filter; see the note in PRUEFUMGEBUNG.md about values that end in a street word. ?>
								<span class="fg-hint"><?php esc_html_e( 'Abfahrtsort, Stadtteil, z. B. Langwasser, Nürnberg Nord, S-Bahnstation Ostring.', 'fahrgemeinschaften' ); ?></span>
							</div>

							<div class="fg-field fg-field-full">
								<label for="fg-contact-email"><?php esc_html_e( 'E-Mail für die Kontaktaufnahme', 'fahrgemeinschaften' ); ?></label>
								<input type="email" id="fg-contact-email" name="fg_contact_email" maxlength="254" autocomplete="email" required>
								<span class="fg-hint"><?php esc_html_e( 'Die E-Mail-Adresse wird nicht öffentlich angezeigt und muss für den gewählten Arbeitsdienst hinterlegt sein.', 'fahrgemeinschaften' ); ?></span>
							</div>

							<div class="fg-field fg-field-full">
								<label class="fg-consent" for="fg-consent">
									<input id="fg-consent" type="checkbox" name="fg_consent" value="1" required>
									<span>
										<?php // The consent names what becomes public. "Meine persönlichen Kontaktdaten" was ambiguous: it could be read as covering a name, and a name is expected in the public list. ?>
										<?php esc_html_e( 'Ich möchte die oben gemachten Angaben zur Organisation der Fahrgemeinschaft öffentlich anzeigen lassen. Dazu gehören mein Vorname oder Spitzname, die Art des Angebots, der Abfahrtsbereich und der Arbeitsdienst. Meine E-Mail-Adresse und meine Telefonnummer werden dabei nicht öffentlich angezeigt.', 'fahrgemeinschaften' ); ?>
										<?php if ( $privacy ) : ?>
											<a href="<?php echo esc_url( $privacy ); ?>"><?php esc_html_e( 'Datenschutzerklärung', 'fahrgemeinschaften' ); ?></a>
										<?php endif; ?>
									</span>
								</label>
							</div>
						</div>

						<div class="fg-actions">
							<button class="fg-button" type="submit"><?php esc_html_e( 'Eintragung vormerken', 'fahrgemeinschaften' ); ?></button>
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
		$has_rides   = false;
		$privacy_url = get_privacy_policy_url();
		?>
		<section aria-labelledby="fg-list-heading">
			<h2 id="fg-list-heading"><?php esc_html_e( 'Aktuelle Fahrgemeinschaften', 'fahrgemeinschaften' ); ?></h2>
			<?php foreach ( $events as $event ) : ?>
				<?php
				$rides = array_values(
					array_filter(
						$this->repository->get_published_rides( $event->id ),
						array( $this->repository, 'is_valid_public_ride' )
					)
				);
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
							<?php $this->render_ride( $ride, $admin_url, $source ); ?>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
			<?php if ( ! $has_rides ) : ?>
				<p class="fg-empty"><?php esc_html_e( 'Für die aktuellen Arbeitsdienste sind noch keine Fahrgemeinschaften veröffentlicht.', 'fahrgemeinschaften' ); ?></p>
			<?php else : ?>
				<?php // The note says the same thing for every entry, so it is stated once for the whole list. ?>
				<p class="fg-hint fg-list-hint">
					<?php esc_html_e( 'Deine E-Mail-Adresse wird nur an den Ersteller der Fahrgemeinschaft gesendet, sofern sie für den Arbeitsdienst dieses Eintrags hinterlegt ist.', 'fahrgemeinschaften' ); ?>
					<?php if ( $privacy_url ) : ?>
						<a href="<?php echo esc_url( $privacy_url ); ?>"><?php esc_html_e( 'Datenschutzerklärung', 'fahrgemeinschaften' ); ?></a>
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
	 * @param FG_Ride $ride      Ride record.
	 * @param string  $admin_url Form endpoint.
	 * @param string  $source    Source URL.
	 * @return void
	 */
	private function render_ride( FG_Ride $ride, $admin_url, $source ) {
		$data       = $this->repository->get_ride_display_data( $ride );
		$mode_label = FG_RIDE_MODE_SEARCH === $data['mode'] ? __( 'Ich suche', 'fahrgemeinschaften' ) : __( 'Ich biete', 'fahrgemeinschaften' );
		?>
		<article class="fg-ride">
			<h4 class="fg-ride-title"><span class="fg-badge"><?php echo esc_html( $mode_label ); ?></span> · <span class="fg-origin"><?php echo esc_html( $data['origin'] ); ?></span> · <?php echo esc_html( $ride->alias ); ?></h4>
			<details class="fg-contact">
				<?php // One control with two labels: the stylesheet shows the one that fits the state. A label that is not shown is not read out either. ?>
				<summary class="fg-button fg-contact-toggle"><span class="fg-label-closed"><?php esc_html_e( 'Kontaktieren', 'fahrgemeinschaften' ); ?></span><span class="fg-label-open"><?php esc_html_e( 'Schließen', 'fahrgemeinschaften' ); ?></span></summary>
				<form class="fg-contact-form" action="<?php echo esc_url( $admin_url ); ?>" method="post">
					<input type="hidden" name="action" value="fg_contact_ride">
					<input type="hidden" name="ride_ref" value="<?php echo esc_attr( $data['public_ref'] ); ?>">
					<input type="hidden" name="source_url" value="<?php echo esc_url( $source ); ?>">
					<input type="hidden" name="form_started_at" value="<?php echo esc_attr( time() ); ?>">
					<?php wp_nonce_field( 'fg_contact_ride', 'fg_contact_nonce', false ); ?>
					<div class="fg-honeypot" aria-hidden="true">
						<label for="fg-contact-website-<?php echo esc_attr( $data['public_ref'] ); ?>"><?php esc_html_e( 'Bitte dieses Feld leer lassen', 'fahrgemeinschaften' ); ?></label>
						<input type="text" id="fg-contact-website-<?php echo esc_attr( $data['public_ref'] ); ?>" name="fg_website" value="" tabindex="-1" autocomplete="off">
					</div>
					<?php // Label, field and button in one row. The label keeps its for, only its wording is short. ?>
					<div class="fg-contact-row">
						<div class="fg-field">
							<label for="fg-contact-email-<?php echo esc_attr( $data['public_ref'] ); ?>"><?php esc_html_e( 'E-Mail', 'fahrgemeinschaften' ); ?></label>
							<input type="email" id="fg-contact-email-<?php echo esc_attr( $data['public_ref'] ); ?>" name="fg_contact_email" maxlength="254" autocomplete="email" required>
						</div>
						<button class="fg-button" type="submit"><?php esc_html_e( 'Absenden', 'fahrgemeinschaften' ); ?></button>
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
			'pending'          => array( __( 'Deine Eintragung wurde vorgemerkt. Bitte prüfe deine E-Mail und bestätige die Veröffentlichung über den enthaltenen Link.', 'fahrgemeinschaften' ), false ),
			'published'        => array( __( 'Deine Fahrgemeinschaft wurde erfolgreich veröffentlicht.', 'fahrgemeinschaften' ), false ),
			'deleted'          => array( __( 'Die Eintragung wurde gelöscht.', 'fahrgemeinschaften' ), false ),
			'contact_received' => array( __( 'Vielen Dank für deine Anfrage. Wir informieren den Ersteller, sofern die angegebene E-Mail-Adresse für den gewählten Arbeitsdienst hinterlegt ist.', 'fahrgemeinschaften' ), false ),
			'not_created'      => array( __( 'Die Eintragung konnte nicht angelegt werden. Bitte prüfe die Eingaben und verwende die im Verein hinterlegte E-Mail-Adresse.', 'fahrgemeinschaften' ), true ),
			'email_failed'     => array( __( 'Die Eintragung konnte nicht angelegt werden, weil die Bestätigungs-E-Mail nicht zugestellt werden konnte. Bitte versuche es später erneut.', 'fahrgemeinschaften' ), true ),
			'publish_failed'   => array( __( 'Die Veröffentlichung wurde zurückgenommen, weil die E-Mail mit dem Lösch-Link nicht zugestellt werden konnte. Bitte bestätige die Veröffentlichung über den Link der ersten E-Mail erneut.', 'fahrgemeinschaften' ), true ),
			'invalid_token'    => array( __( 'Der Link ist ungültig oder abgelaufen. Bitte kontaktiere uns, falls du Unterstützung benötigst.', 'fahrgemeinschaften' ), true ),
			'form_expired'     => array( __( 'Das Formular ist nicht mehr gültig. Bitte lade die Seite neu und sende das Formular erneut ab.', 'fahrgemeinschaften' ), true ),
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
