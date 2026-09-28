<?php
/**
 * Public form and token action handlers.
 *
 * @package Arbeitsdienste
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles ride submission, confirmation, deletion and contact requests, and the
 * registration of a member for a work service together with its reversal.
 *
 * The two flows are kept apart on purpose. A ride is a public entry that has to
 * be confirmed and can be deleted again, so it carries status, two pending
 * tokens and one deletion token. A registration is made against the club's
 * member administration, so there is nothing left to confirm and only one way
 * back out. They share the helpers below and nothing else.
 */
final class FG_Actions {
	/**
	 * Repository.
	 *
	 * @var FG_Repository
	 */
	private $repository;

	/**
	 * Statistics.
	 *
	 * @var FG_Stats
	 */
	private $stats;

	/**
	 * Mailer.
	 *
	 * @var FG_Mailer
	 */
	private $mailer;

	/**
	 * Constructor.
	 *
	 * @param FG_Repository|null $repository Optional repository.
	 * @param FG_Stats|null      $stats      Optional statistics.
	 * @param FG_Mailer|null     $mailer     Optional mailer.
	 */
	public function __construct( FG_Repository $repository = null, FG_Stats $stats = null, FG_Mailer $mailer = null ) {
		$this->repository = $repository ? $repository : new FG_Repository();
		$this->stats      = $stats ? $stats : new FG_Stats();
		$this->mailer     = $mailer ? $mailer : new FG_Mailer( $this->repository );

		add_action( 'admin_post_nopriv_fg_submit_ride', array( $this, 'submit_ride' ) );
		add_action( 'admin_post_fg_submit_ride', array( $this, 'submit_ride' ) );
		add_action( 'admin_post_nopriv_fg_contact_ride', array( $this, 'contact_ride' ) );
		add_action( 'admin_post_fg_contact_ride', array( $this, 'contact_ride' ) );
		add_action( 'admin_post_nopriv_fg_process_ride_token', array( $this, 'process_ride_token' ) );
		add_action( 'admin_post_fg_process_ride_token', array( $this, 'process_ride_token' ) );
		add_action( 'admin_post_nopriv_fg_register_member', array( $this, 'register_member' ) );
		add_action( 'admin_post_fg_register_member', array( $this, 'register_member' ) );
		add_action( 'admin_post_nopriv_fg_process_duty_token', array( $this, 'process_duty_token' ) );
		add_action( 'admin_post_fg_process_duty_token', array( $this, 'process_duty_token' ) );
		add_action( 'template_redirect', array( $this, 'maybe_require_https' ), -1 );
		add_action( 'template_redirect', array( $this, 'maybe_render_action_page' ), 0 );
		add_action( 'template_redirect', array( $this, 'maybe_render_duty_action_page' ), 0 );
		add_action( 'fg_daily_cleanup', array( $this, 'daily_cleanup' ) );
	}

	/**
	 * Store a new pending ride and send its confirmation e-mail.
	 *
	 * @return void
	 */
	public function submit_ride() {
		$this->require_post_method();
		$this->require_https_post( 'not_created' );
		$this->verify_nonce( 'fg_submit_nonce', 'fg_submit_ride' );

		$source = FG_Security::safe_source_url( $this->post_value( 'source_url' ) );
		$this->record_speed_signal( absint( $this->post_value( 'form_started_at' ) ) );
		$this->stats->increment( 'publish_form_total' );

		if ( ! empty( $_POST['fg_website'] ) ) {
			$this->stats->increment( 'bot_honeypot' );
			FG_Security::redirect_with_notice( 'not_created', $source );
		}

		$mode   = sanitize_key( $this->post_value( 'fg_mode' ) );
		$ref    = sanitize_key( $this->post_value( 'fg_event_ref' ) );
		$origin = sanitize_text_field( $this->post_value( 'fg_origin' ) );
		$event  = $this->repository->get_event_by_reference( $ref );

		// The form asks for the member number and the address, and both have to
		// belong to the same member. The pair is the same one the work service
		// form uses, and it is refused without saying which half was wrong: the
		// page is public, and a hint would tell a passer-by whether a guessed
		// member number exists.
		$member = $this->repository->find_member_for_registration(
			$this->post_value( 'fg_member_no' ),
			$this->post_value( 'fg_member_email' )
		);

		if ( ! $member ) {
			$this->stats->increment( 'publish_invalid_email' );
		}

		// Only the pickup area is free text now. The name in the public list is
		// the first name of the member, which the club maintains, so nothing the
		// visitor types can carry an address or a telephone number into a list.
		$personal_data = $this->contains_personal_data( $origin );
		if ( $personal_data ) {
			$this->stats->increment( 'publish_personal_data' );
		}

		if (
			! in_array( $mode, array( FG_RIDE_MODE_OFFER, FG_RIDE_MODE_SEARCH ), true )
			|| ! $event
			|| ! $this->repository->is_event_active( $event->id )
			|| ! $member
			|| '' === $origin
			|| $this->string_length( $origin ) > 100
			|| $personal_data
			|| '1' !== $this->post_value( 'fg_consent' )
		) {
			FG_Security::redirect_with_notice( 'not_created', $source );
		}

		if ( ! $this->repository->is_event_participant( $event->id, $member->id ) ) {
			$this->stats->increment( 'publish_invalid_email' );
			FG_Security::redirect_with_notice( 'not_created', $source );
		}

		$this->stats->increment( 'publish_valid_email' );

		$ride = $this->repository->create_pending_ride(
			array(
				'event_id'   => $event->id,
				'mode'       => $mode,
				'member_id'  => $member->id,
				'origin'     => $origin,
				'source_url' => $source,
			)
		);

		if ( ! $ride['id'] ) {
			FG_Security::redirect_with_notice( 'not_created', $source );
		}

		$ride_id     = $ride['id'];
		$public_ref  = $this->repository->get_ride( $ride_id )->public_ref;
		$confirm_url = $this->action_url( $public_ref, 'confirm', $ride['confirm_token'] );
		$discard_url = $this->action_url( $public_ref, 'discard', $ride['discard_token'] );

		if ( ! $this->mailer->send_pending_confirmation( $ride_id, $confirm_url, $discard_url ) ) {
			$this->repository->delete_ride( $ride_id );
			$this->stats->increment( 'mail_send_failed' );
			FG_Security::redirect_with_notice( 'email_failed', $source );
		}

		$this->stats->increment( 'publish_pending' );
		FG_Security::redirect_with_notice( 'pending', $source );
	}

	/**
	 * Process a contact request without disclosing participant membership.
	 *
	 * @return void
	 */
	public function contact_ride() {
		$this->require_post_method();
		$this->require_https_post( 'contact_received' );
		$this->verify_nonce( 'fg_contact_nonce', 'fg_contact_ride' );

		$source = FG_Security::safe_source_url( $this->post_value( 'source_url' ) );
		$this->record_speed_signal( absint( $this->post_value( 'form_started_at' ) ) );
		$this->stats->increment( 'contact_total' );

		if ( ! empty( $_POST['fg_website'] ) ) {
			$this->stats->increment( 'bot_honeypot' );
			FG_Security::redirect_with_notice( 'contact_received', $source );
		}

		$ref   = sanitize_key( $this->post_value( 'ride_ref' ) );
		$email = $this->repository->normalize_email( $this->post_value( 'fg_contact_email' ) );
		$ride  = $this->repository->get_ride_by_reference( $ref );
		$owner = $ride ? $this->repository->get_member( $ride->member_id ) : null;

		$ride_visible = $ride
			&& $this->repository->is_valid_public_ride( $ride )
			&& $this->repository->is_displayable_member( $owner )
			&& '' !== $ride->confirmed_at
			&& $this->repository->is_event_active( $ride->event_id );

		// The one asking becomes a member, not an address that is passed on.
		// The check below asks whether a member is in the list of this duty, and
		// that question has been asked of a row ID since schema 1.4.0. Naming the
		// asker also means the greeting in the reply mail is written from the
		// member list, and that a member writing to their own entry is caught by
		// comparing two row IDs rather than two strings.
		$asker = false !== $email ? $this->repository->get_member_by_email( $email ) : null;

		if ( ! $ride_visible || ! $asker || ! $this->repository->is_event_participant( $ride->event_id, $asker->id ) ) {
			$this->stats->increment( 'contact_invalid_email' );
			FG_Security::redirect_with_notice( 'contact_received', $source );
		}

		$creator_email = $this->repository->get_ride_contact_email( $ride, $owner );
		if (
			false === $creator_email
			|| $asker->id === $ride->member_id
			|| ! $this->repository->is_event_participant( $ride->event_id, $ride->member_id )
		) {
			FG_Security::redirect_with_notice( 'contact_received', $source );
		}

		$this->stats->increment( 'contact_valid_email' );
		$result = $this->mailer->send_contact_notifications( $ride->id, $asker->id );

		if ( $result['creator'] ) {
			$this->stats->increment( 'contact_mail_sent' );
		} else {
			$this->stats->increment( 'mail_send_failed' );
		}
		if ( $result['requester'] ) {
			$this->stats->increment( 'contact_requester_mail' );
		} else {
			$this->stats->increment( 'mail_send_failed' );
		}

		FG_Security::redirect_with_notice( 'contact_received', $source );
	}

	/**
	 * Require HTTPS for the public shortcode and token pages.
	 *
	 * The redirect is only built when the site is configured for HTTPS. A site
	 * installed without TLS cannot answer https, so upgrading the request there
	 * would produce a browser protocol error instead of a protected page. The
	 * requirement itself stays visible as an admin notice.
	 *
	 * @return void
	 */
	public function maybe_require_https() {
		if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || is_ssl() ) {
			return;
		}

		if ( ! FG_Security::site_uses_https() ) {
			return;
		}

		$is_action_page = 'view' === $this->query_value( 'fg_ride_action' );
		$post           = get_post();
		// Only the ride list is upgraded. The duty list is a list first: a member
		// reads it on a phone, and the club runs its site over HTTPS anyway, so
		// an upgrade there would only cost somebody the page. A POST from the duty
		// form is still upgraded, because a write belongs in a request nobody can
		// read on the way.
		$is_shortcode   = $post && has_shortcode( $post->post_content, 'fahrgemeinschaften' );

		if ( ! $is_action_page && ! $is_shortcode ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? wp_unslash( $_SERVER['REQUEST_URI'] )
			: '/';

		// Rebuild the current request on HTTPS. The authority is taken from the
		// configured home URL (never from the Host header) and the request path
		// is reused verbatim so subdirectory installations stay intact.
		$home      = home_url( '/' );
		$home_host = wp_parse_url( $home, PHP_URL_HOST );
		$home_port = wp_parse_url( $home, PHP_URL_PORT );
		$authority = is_string( $home_host ) && '' !== $home_host
			? $home_host . ( $home_port ? ':' . (int) $home_port : '' )
			: '';

		$target = '' !== $authority && 0 === strpos( $request_uri, '/' )
			? 'https://' . $authority . $request_uri
			: set_url_scheme( $home, 'https' );

		if ( ! wp_safe_redirect( $target, 301 ) ) {
			wp_redirect( $target, 301 );
		}
		exit;
	}

	/**
	 * Render a GET-only token landing page.
	 *
	 * @return void
	 */
	public function maybe_render_action_page() {
		if ( 'view' !== $this->query_value( 'fg_ride_action' ) ) {
			return;
		}

		$reference = sanitize_key( $this->query_value( 'ride_ref' ) );
		$intent    = sanitize_key( $this->query_value( 'intent' ) );
		$token     = sanitize_text_field( $this->query_value( 'token' ) );
		$ride      = $this->repository->get_ride_by_reference( $reference );

		if ( ! $ride || ! $this->valid_token_action( $ride, $intent, $token ) ) {
			FG_Security::render_standalone_page(
				__( 'Link nicht gültig', 'arbeitsdienste' ),
				'<h1>' . esc_html__( 'Link nicht gültig', 'arbeitsdienste' ) . '</h1>'
				. '<p>' . esc_html__( 'Dieser Link ist abgelaufen, wurde bereits verwendet oder ist nicht korrekt.', 'arbeitsdienste' ) . '</p>'
				. '<p>' . esc_html__( 'Bitte kontaktiere uns, wenn du Unterstützung benötigst.', 'arbeitsdienste' ) . '</p>'
			);
		}

		$data      = $this->repository->get_ride_display_data( $ride );
		$mode      = 'search' === $data['mode'] ? __( 'Ich suche', 'arbeitsdienste' ) : __( 'Ich biete', 'arbeitsdienste' );
		$mitglied  = $data['member'];
		$is_delete = 'delete' === $intent;
		$heading   = $is_delete
			? __( 'Veröffentlichte Fahrgemeinschaft löschen', 'arbeitsdienste' )
			: ( 'discard' === $intent
				? __( 'Vorgemerkte Eintragung löschen', 'arbeitsdienste' )
				: __( 'Veröffentlichung bestätigen', 'arbeitsdienste' ) );
		$button    = $is_delete
			? __( 'Endgültig löschen', 'arbeitsdienste' )
			: ( 'discard' === $intent
				? __( 'Eintragung löschen und nicht veröffentlichen', 'arbeitsdienste' )
				: __( 'Veröffentlichung bestätigen', 'arbeitsdienste' ) );
		$warning   = 'confirm' === $intent
			? __( 'Mit der Bestätigung werden die unten stehenden Angaben öffentlich angezeigt. Bitte prüfe sie sorgfältig.', 'arbeitsdienste' )
			: __( 'Achtung: Diese Aktion ist sofort und ohne weitere Rückfrage wirksam.', 'arbeitsdienste' );

		$body  = '<h1>' . esc_html( $heading ) . '</h1>';
		$body .= '<div class="warning"><p>' . esc_html( $warning ) . '</p></div>';
		$body .= '<dl>';
		$body .= '<dt>' . esc_html__( 'Art', 'arbeitsdienste' ) . '</dt><dd>' . esc_html( $mode ) . '</dd>';
		// The member is named in one line or in none. A ride whose member has
		// been removed from the club in the meantime has no name and no number
		// left, and two empty rows would read like a form that was not filled in.
		if ( $this->repository->is_displayable_member( $mitglied ) ) {
			$body .= '<dt>' . esc_html__( 'Vorname', 'arbeitsdienste' ) . '</dt><dd>' . esc_html( $data['first_name'] ) . '</dd>';
			$body .= '<dt>' . esc_html__( 'Mitgliedsnummer', 'arbeitsdienste' ) . '</dt><dd>' . esc_html( $mitglied->member_no ) . '</dd>';
		} else {
			$body .= '<dt>' . esc_html__( 'Mitglied', 'arbeitsdienste' ) . '</dt><dd>' . esc_html__( 'nicht mehr im Verein', 'arbeitsdienste' ) . '</dd>';
		}
		$body .= '<dt>' . esc_html__( 'Arbeitsdienst', 'arbeitsdienste' ) . '</dt><dd>' . esc_html( $data['event_label'] . ( $data['event_date'] ? ' (' . $data['event_date'] . ')' : '' ) ) . '</dd>';
		$body .= '<dt>' . esc_html__( 'Abfahrtsbereich', 'arbeitsdienste' ) . '</dt><dd>' . esc_html( $data['origin'] ) . '</dd>';
		$body .= '</dl>';
		$body .= '<p><strong>' . esc_html__( 'Vorname, Mitgliedsnummer und E-Mail-Adresse werden nicht öffentlich angezeigt.', 'arbeitsdienste' ) . '</strong></p>';
		$body .= '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		$body .= '<input type="hidden" name="action" value="fg_process_ride_token">';
		$body .= '<input type="hidden" name="ride_ref" value="' . esc_attr( $data['public_ref'] ) . '">';
		$body .= '<input type="hidden" name="intent" value="' . esc_attr( $intent ) . '">';
		$body .= '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
		$body .= '<input type="hidden" name="token_nonce" value="' . esc_attr( $this->token_form_nonce( $intent, $token ) ) . '">';
		$body .= '<div class="actions"><button type="submit" class="' . ( $is_delete || 'discard' === $intent ? 'secondary' : '' ) . '">' . esc_html( $button ) . '</button></div>';
		$body .= '</form>';

		FG_Security::render_standalone_page( $heading, $body );
	}

	/**
	 * Process confirmation, discard or published-delete POST requests.
	 *
	 * @return void
	 */
	public function process_ride_token() {
		$this->require_post_method();
		$this->require_https_post( 'invalid_token' );

		$reference = sanitize_key( $this->post_value( 'ride_ref' ) );
		$intent    = sanitize_key( $this->post_value( 'intent' ) );
		$token     = sanitize_text_field( $this->post_value( 'token' ) );
		$ride      = $this->repository->get_ride_by_reference( $reference );
		$source    = $ride ? $this->repository->get_ride_source_url( $ride->id ) : home_url( '/' );

		// Two different problems, two different answers. A link that no longer
		// holds a valid token is dead, and only the club can help. A form whose
		// nonce has gone stale is a page that was opened a while ago: reloading
		// brings a new one, and telling the visitor to contact the club for that
		// would be advice that is wrong and expensive.
		if ( ! $ride || ! $this->valid_token_action( $ride, $intent, $token ) ) {
			FG_Security::redirect_with_notice( 'invalid_token', $source );
		}

		if ( ! $this->valid_token_form_nonce( $intent, $token, $this->post_value( 'token_nonce' ) ) ) {
			FG_Security::redirect_with_notice( 'form_expired', $source );
		}

		$pending_tokens = array(
			'confirm_hash'    => $ride->pending_confirm_hash,
			'confirm_expires' => $ride->pending_confirm_expires,
			'discard_hash'    => $ride->pending_discard_hash,
			'discard_expires' => $ride->pending_discard_expires,
		);

		if ( ! $this->claim_token( $ride, $intent, $token ) ) {
			FG_Security::redirect_with_notice( 'invalid_token', $source );
		}

		if ( 'confirm' === $intent ) {
			// The member is re-read here rather than trusted from the row. A
			// member who was removed from the club between submitting the form
			// and clicking the link must not publish an entry that names them,
			// and the address the mail goes to has to be one that still exists.
			$member = $this->repository->get_member( $ride->member_id );
			if (
				! $this->repository->is_event_active( $ride->event_id )
				|| ! $this->repository->is_displayable_member( $member )
				|| ! $this->repository->is_event_participant( $ride->event_id, $ride->member_id )
				|| '' === $ride->consent_version
			) {
				$this->restore_token( $ride, $intent, $token );
				FG_Security::redirect_with_notice( 'invalid_token', $source );
			}

			$delete_token   = FG_Security::create_token();
			$delete_expires = $this->published_delete_expiry( $ride->event_id );

			// A single write publishes the ride and hands out the deletion
			// token, so there is no state in which a published ride exists
			// without a working self-service deletion link.
			$published = $this->repository->update_ride(
				$ride->id,
				array(
					'status'                  => FG_RIDE_STATUS_PUBLISHED,
					'confirmed_at'            => current_time( 'mysql' ),
					'pending_confirm_hash'    => '',
					'pending_confirm_expires' => 0,
					'pending_discard_hash'    => '',
					'pending_discard_expires' => 0,
					'delete_hash'             => FG_Security::hash_token( $delete_token ),
					'delete_expires'          => $delete_expires,
				)
			);

			if ( ! $published ) {
				FG_Security::redirect_with_notice( 'invalid_token', $source );
			}

			$delete_url = $this->action_url( $ride->public_ref, 'delete', $delete_token );
			if ( ! $this->mailer->send_published_confirmation( $ride->id, $delete_url ) ) {
				$this->stats->increment( 'mail_send_failed' );
				$this->rollback_confirmation( $ride, $pending_tokens );
				FG_Security::redirect_with_notice( 'publish_failed', $source );
			}

			$this->stats->increment( 'publish_confirmed' );
			FG_Security::redirect_with_notice( 'published', $source );
		}

		$this->stats->increment( 'publish_deleted' );
		$this->repository->delete_ride( $ride->id );
		FG_Security::redirect_with_notice( 'deleted', $source );
	}

	/**
	 * Register a member for a work service and send the confirmation e-mail.
	 *
	 * The form carries a member number and an e-mail address. Both are looked up
	 * and have to belong to the same member of the club; a pair that does not is
	 * refused without saying which of the two was wrong, because the page is
	 * public and a hint about which half was right would tell a passer-by
	 * whether a guessed number exists.
	 *
	 * The registration is written first and the e-mail afterwards. An e-mail that
	 * cannot be delivered leaves the registration in place and says so, because
	 * the member asked for a place and got one: silently dropping it would show
	 * a free place that is already taken, and writing it and staying silent would
	 * leave somebody in a duty who never saw the message with the way out of it.
	 *
	 * @return void
	 */
	public function register_member() {
		$this->require_post_method();
		$this->require_https_post( 'not_registered' );
		$this->verify_nonce( 'fg_register_nonce', 'fg_register_member' );

		$source = FG_Security::safe_source_url( $this->post_value( 'source_url' ) );
		$this->record_speed_signal( absint( $this->post_value( 'form_started_at' ) ) );
		$this->stats->increment( 'signup_total' );

		if ( ! empty( $_POST['fg_website'] ) ) {
			$this->stats->increment( 'bot_honeypot' );
			FG_Security::redirect_with_notice( 'not_registered', $source );
		}

		$ref    = sanitize_key( $this->post_value( 'fg_event_ref' ) );
		$event  = $this->repository->get_event_by_reference( $ref );
		$member = $this->repository->find_member_for_registration(
			$this->post_value( 'fg_member_no' ),
			$this->post_value( 'fg_member_email' )
		);

		if ( ! $event || ! $this->repository->is_event_active( $event->id ) || ! $member ) {
			$this->stats->increment( 'signup_invalid' );
			FG_Security::redirect_with_notice( 'not_registered', $source );
		}

		// A member who is already in the list is told so and nothing is written.
		// Sending the second confirmation as well would put two e-mails with two
		// unregistration links in one inbox, and only the newer link would work.
		if ( $this->repository->has_registration( $event->id, $member->id ) ) {
			$this->stats->increment( 'signup_repeat' );
			FG_Security::redirect_with_notice( 'already_registered', $source );
		}

		// The places are counted again here and not only on the page. Two members
		// can press the button at the same moment, and the one who loses that race
		// must be refused by the server, not by a button that was out of date.
		if ( ! $this->repository->can_register( $event, $this->repository->count_event_registrations( $event->id ) ) ) {
			$this->stats->increment( 'signup_refused_full' );
			// Two different reasons lead here and they do not say the same thing
			// about the duty. The card on the page draws that distinction too, and a
			// refusal that contradicted the card would leave the member reading
			// "vollständig belegt" about a duty nobody ever asked anybody for.
			FG_Security::redirect_with_notice(
				$event->demand > 0 ? 'duty_full' : 'no_demand',
				$source
			);
		}

		$this->stats->increment( 'signup_valid' );

		$registration = $this->repository->create_registration( $event->id, $member->id, $source );
		if ( ! $registration['id'] ) {
			// Either the insert lost a race against the same member pressing the
			// button twice, or the storage refused it. Both mean the same thing to
			// the visitor, and neither has to be explained on a public page.
			$this->stats->increment( 'signup_repeat' );
			FG_Security::redirect_with_notice( 'already_registered', $source );
		}

		$unregister_url = $this->duty_action_url( $registration['public_ref'], $registration['unregister_token'] );

		if ( ! $this->mailer->send_duty_signup( $registration['id'], $unregister_url ) ) {
			// Without the mail there is no way out of the registration: the link
			// that would remove it was in that mail. A place that is taken and
			// cannot be given back is the one failure a member has to be saved
			// from, and the retry after it would only answer "already
			// registered" to somebody who never got a message.
			$this->repository->delete_registration_by_pair( $event->id, $member->id );
			$this->stats->increment( 'mail_send_failed' );
			FG_Security::redirect_with_notice( 'email_failed', $source );
		}

		$this->stats->increment( 'signup_created' );
		FG_Security::redirect_with_notice( 'registered', $source );
	}

	/**
	 * Render the landing page of an unregistration link from the duty list.
	 *
	 * A mail program that follows every link it finds would otherwise sign a
	 * member out of a duty nobody asked them to leave. The link therefore opens
	 * a page that names the duty and asks, and only a deliberate click on the
	 * button sends the POST that actually removes the entry.
	 *
	 * @return void
	 */
	public function maybe_render_duty_action_page() {
		if ( 'view' !== $this->query_value( 'fg_duty_action' ) ) {
			return;
		}

		$reference = sanitize_key( $this->query_value( 'signup_ref' ) );
		$intent    = sanitize_key( $this->query_value( 'intent' ) );
		$token     = sanitize_text_field( $this->query_value( 'token' ) );

		$registration = $this->repository->get_registration_by_reference( $reference );
		$member       = $registration ? $this->repository->get_member( $registration->member_id ) : null;
		$event        = $registration ? $this->repository->get_event( $registration->event_id ) : null;

		if (
			'unregister' !== $intent
			|| ! $registration
			|| ! $member
			|| ! $event
			|| ! $this->repository->valid_unregister_token( $registration, $token )
		) {
			FG_Security::render_standalone_page(
				__( 'Link nicht gültig', 'arbeitsdienste' ),
				'<h1>' . esc_html__( 'Link nicht gültig', 'arbeitsdienste' ) . '</h1>'
				. '<p>' . esc_html__( 'Dieser Link ist abgelaufen, wurde bereits verwendet oder ist nicht korrekt.', 'arbeitsdienste' ) . '</p>'
				. '<p>' . esc_html__( 'Bitte kontaktiere uns, wenn du Unterstützung benötigst.', 'arbeitsdienste' ) . '</p>'
			);
		}

		$heading = __( 'Anmeldung löschen', 'arbeitsdienste' );
		$body   = '<h1>' . esc_html( $heading ) . '</h1>';
		$body  .= '<div class="warning"><p>' . esc_html__( 'Achtung: Diese Aktion ist sofort und ohne weitere Rückfrage wirksam.', 'arbeitsdienste' ) . '</p></div>';
		$body  .= '<dl>';
		$body  .= '<dt>' . esc_html__( 'Arbeitsdienst', 'arbeitsdienste' ) . '</dt><dd>' . esc_html( $event->title ) . '</dd>';
		$body  .= '<dt>' . esc_html__( 'Datum', 'arbeitsdienste' ) . '</dt><dd>' . esc_html( $this->repository->format_event_date_long( $event ) ) . '</dd>';
		$body  .= '<dt>' . esc_html__( 'Mitgliedsnummer', 'arbeitsdienste' ) . '</dt><dd>' . esc_html( $member->member_no ) . '</dd>';
		$body  .= '</dl>';
		$body  .= '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		$body  .= '<input type="hidden" name="action" value="fg_process_duty_token">';
		$body  .= '<input type="hidden" name="source_url" value="' . esc_attr( $this->repository->get_registration_source_url( $registration ) ) . '">';
		$body  .= '<input type="hidden" name="signup_ref" value="' . esc_attr( $reference ) . '">';
		$body  .= '<input type="hidden" name="intent" value="' . esc_attr( $intent ) . '">';
		$body  .= '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
		$body  .= '<input type="hidden" name="token_nonce" value="' . esc_attr( $this->token_form_nonce( $intent, $token ) ) . '">';
		$body  .= '<div class="actions"><button type="submit" class="secondary">' . esc_html__( 'Anmeldung löschen', 'arbeitsdienste' ) . '</button></div>';
		$body  .= '</form>';

		FG_Security::render_standalone_page( $heading, $body );
	}

	/**
	 * Remove a registration after a deliberate confirmation.
	 *
	 * The removal is a single statement that matches the row and the token hash
	 * together. A link that is opened a second time therefore matches nothing and
	 * says the link is no longer valid, which is the truth: the entry is gone.
	 *
	 * @return void
	 */
	public function process_duty_token() {
		$this->require_post_method();
		$this->require_https_post( 'invalid_token' );

		$source       = FG_Security::safe_source_url( $this->post_value( 'source_url' ) );
		$reference    = sanitize_key( $this->post_value( 'signup_ref' ) );
		$intent       = sanitize_key( $this->post_value( 'intent' ) );
		$token        = sanitize_text_field( $this->post_value( 'token' ) );
		$registration = $this->repository->get_registration_by_reference( $reference );

		// The same two problems as on the ride page, answered the same way: a
		// link without a valid token is dead, a form with a stale nonce is an
		// old page and a reload brings a new one.
		if (
			'unregister' !== $intent
			|| ! $registration
			|| ! $this->repository->valid_unregister_token( $registration, $token )
		) {
			FG_Security::redirect_with_notice( 'invalid_token', $source );
		}

		if ( ! $this->valid_token_form_nonce( $intent, $token, $this->post_value( 'token_nonce' ) ) ) {
			FG_Security::redirect_with_notice( 'form_expired', $source );
		}

		if ( ! $this->repository->delete_registration_with_token( $registration->id, $token ) ) {
			FG_Security::redirect_with_notice( 'invalid_token', $source );
		}

		$this->stats->increment( 'signup_unregistered' );
		FG_Security::redirect_with_notice( 'unregistered', $source );
	}

	/**
	 * Undo a confirmation when the delete link could not be delivered.
	 *
	 * The pending tokens are restored so the same confirmation link still
	 * works; without them the entry is removed instead of being published
	 * without a working self-service deletion path.
	 *
	 * @param FG_Ride $ride    Ride record.
	 * @param array   $pending Stored pending token data.
	 * @return void
	 */
	private function rollback_confirmation( $ride, $pending ) {
		if ( '' === $pending['confirm_hash'] || '' === $pending['discard_hash'] ) {
			$this->repository->delete_ride( $ride->id );
			return;
		}

		$this->repository->update_ride(
			$ride->id,
			array(
				'status'                  => FG_RIDE_STATUS_PENDING,
				'confirmed_at'            => null,
				'delete_hash'             => '',
				'delete_expires'          => 0,
				'pending_confirm_hash'    => $pending['confirm_hash'],
				'pending_confirm_expires' => (int) $pending['confirm_expires'],
				'pending_discard_hash'    => $pending['discard_hash'],
				'pending_discard_expires' => (int) $pending['discard_expires'],
			)
		);
	}

	/**
	 * Delete expired pending rides and old aggregate statistics.
	 *
	 * @return void
	 */
	public function daily_cleanup() {
		foreach ( $this->repository->get_expired_pending_ride_ids() as $ride_id ) {
			$this->repository->delete_ride( $ride_id );
			$this->stats->increment( 'publish_deleted' );
		}

		foreach ( $this->repository->get_expired_published_token_ride_ids() as $ride_id ) {
			$this->repository->update_ride(
				$ride_id,
				array(
					'delete_hash'    => '',
					'delete_expires' => 0,
				)
			);
		}

		// The registration itself stays; only the ability to undo it by mail ends.
		// A duty that is over is a fact about the past, and who did it is part of
		// that fact. What is removed is a token that could be replayed out of an
		// inbox archive years later.
		foreach ( $this->repository->get_expired_unregister_registration_ids() as $registration_id ) {
			$this->repository->clear_unregister_token( $registration_id );
		}

		$this->stats->cleanup();

		update_option( FG_CLEANUP_OPTION, (string) time(), false );
	}

	/**
	 * Verify a public form nonce using a scalar field value.
	 *
	 * An expired form is answered with a neutral reload hint instead of an
	 * error page, because public nonces follow the short WordPress lifetime
	 * while a form may stay open for hours.
	 *
	 * @param string $field Field name.
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function verify_nonce( $field, $action ) {
		$nonce = $this->post_value( $field );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, $action ) ) {
			FG_Security::redirect_with_notice( 'form_expired', $this->post_value( 'source_url' ) );
		}
	}

	/**
	 * Read a scalar GET value without allowing array injection warnings.
	 *
	 * @param string $key Query key.
	 * @return string
	 */
	private function query_value( $key ) {
		if ( ! isset( $_GET[ $key ] ) || is_array( $_GET[ $key ] ) || is_object( $_GET[ $key ] ) ) {
			return '';
		}

		return (string) wp_unslash( $_GET[ $key ] );
	}

	/**
	 * Read a scalar POST value without allowing array injection warnings.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private function post_value( $key ) {
		if ( ! isset( $_POST[ $key ] ) || is_array( $_POST[ $key ] ) || is_object( $_POST[ $key ] ) ) {
			return '';
		}

		return (string) wp_unslash( $_POST[ $key ] );
	}

	/**
	 * Reject public form mutations received over plain HTTP.
	 *
	 * Like the page redirect this only happens on a site that is configured for
	 * HTTPS. A site without TLS has no secure variant to send the visitor to, so
	 * the request is processed as it is. Administrators are told about the
	 * missing TLS setup in the backend.
	 *
	 * @param string $notice Notice key for the secure redirect.
	 * @return void
	 */
	private function require_https_post( $notice ) {
		if ( is_ssl() ) {
			return;
		}

		if ( ! FG_Security::site_uses_https() ) {
			return;
		}

		$source = FG_Security::safe_source_url( $this->post_value( 'source_url' ) );
		$target = set_url_scheme( $source, 'https' );
		$target = remove_query_arg( array( 'fg_notice', 'fg_message' ), $target );
		$target = add_query_arg( 'fg_notice', sanitize_key( $notice ), explode( '#', $target )[0] );
		$target .= '#' . FG_NOTICE_ANCHOR;
		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Refuse everything that is not a form submission.
	 *
	 * All state changes of the plugin happen through POST. Requests that reach
	 * the handlers in another way (for example a link to admin-post.php) are
	 * answered with 405 and change nothing.
	 *
	 * @return void
	 */
	private function require_post_method() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: '';

		if ( 'POST' === $method ) {
			return;
		}

		if ( ! headers_sent() ) {
			header( 'Allow: POST' );
		}

		wp_die(
			esc_html__( 'Diese Aktion ist nur per POST möglich.', 'arbeitsdienste' ),
			esc_html__( 'Ungültige Anfrage', 'arbeitsdienste' ),
			array( 'response' => 405 )
		);
	}

	/**
	 * Create a form verifier tied to the secret token and action.
	 *
	 * This avoids coupling long-lived e-mail links to WordPress's shorter
	 * user-session nonce lifetime.
	 *
	 * @param string $intent Action intent.
	 * @param string $token  Raw token.
	 * @return string
	 */
	private function token_form_nonce( $intent, $token ) {
		return hash_hmac( 'sha256', $intent . '|' . $token, wp_salt( 'nonce' ) );
	}

	/**
	 * Verify the token-specific form verifier.
	 *
	 * @param string $intent Action intent.
	 * @param string $token  Raw token.
	 * @param string $value  Submitted verifier.
	 * @return bool
	 */
	private function valid_token_form_nonce( $intent, $token, $value ) {
		return is_string( $value ) && hash_equals( $this->token_form_nonce( $intent, $token ), $value );
	}

	/**
	 * Return required status and meta keys for a token intent.
	 *
	 * @param string $intent Action intent.
	 * @return array<int, string>|null
	 */
	private function token_definition( $intent ) {
		$map = array(
			'confirm' => array( FG_RIDE_STATUS_PENDING, 'pending_confirm_hash', 'pending_confirm_expires' ),
			'discard' => array( FG_RIDE_STATUS_PENDING, 'pending_discard_hash', 'pending_discard_expires' ),
			'delete'  => array( FG_RIDE_STATUS_PUBLISHED, 'delete_hash', 'delete_expires' ),
		);

		return isset( $map[ $intent ] ) ? $map[ $intent ] : null;
	}

	/**
	 * Validate a token and ensure it matches the current ride state.
	 *
	 * @param FG_Ride $ride   Ride record.
	 * @param string  $intent Action intent.
	 * @param string  $token  Raw token.
	 * @return bool
	 */
	private function valid_token_action( $ride, $intent, $token ) {
		$definition = $this->token_definition( $intent );

		if ( ! $definition || ! $ride instanceof FG_Ride || $ride->status !== $definition[0] ) {
			return false;
		}

		return $definition[2] >= time()
			&& FG_Security::token_valid( $ride->{$definition[1]}, $token );
	}

	/**
	 * Consume a token before acting on it.
	 *
	 * The conditional update is a compare-and-swap on the stored hash, so a
	 * link that is opened twice is only ever processed once. The row is read
	 * again afterwards: if a competing request already moved the ride on (a
	 * confirmation while a discard was still in flight), the claim is released
	 * again and the request is refused.
	 *
	 * @param FG_Ride $ride   Ride record.
	 * @param string  $intent Action intent.
	 * @param string  $token  Raw token.
	 * @return bool
	 */
	private function claim_token( $ride, $intent, $token ) {
		$definition = $this->token_definition( $intent );
		if ( ! $definition || ! $ride instanceof FG_Ride ) {
			return false;
		}

		$claimed = $this->repository->claim_token(
			$ride->id,
			$definition[1],
			$definition[2],
			FG_Security::hash_token( $token )
		);

		if ( ! $claimed ) {
			return false;
		}

		$fresh = $this->repository->get_ride( $ride->id );
		if ( ! $fresh || $fresh->status !== $definition[0] ) {
			$this->restore_token( $ride, $intent, $token );
			return false;
		}

		return true;
	}

	/**
	 * Hand a claimed token back to the ride.
	 *
	 * @param FG_Ride $ride   Ride record.
	 * @param string  $intent Action intent.
	 * @param string  $token  Raw token.
	 * @return void
	 */
	private function restore_token( $ride, $intent, $token ) {
		$definition = $this->token_definition( $intent );
		if ( ! $definition || ! $ride instanceof FG_Ride ) {
			return;
		}

		$this->repository->update_ride(
			$ride->id,
			array( $definition[1] => FG_Security::hash_token( $token ) )
		);
	}

	/**
	 * Build a public token action URL.
	 *
	 * @param string $reference Public ride reference.
	 * @param string $intent    Action.
	 * @param string $token     Raw token.
	 * @return string
	 */
	private function action_url( $reference, $intent, $token ) {
		return add_query_arg(
			array(
				'fg_ride_action' => 'view',
				'ride_ref'       => $reference,
				'intent'         => $intent,
				'token'          => $token,
			),
			home_url( '/' )
		);
	}

	/**
	 * Build the public unregistration URL of a duty registration.
	 *
	 * The name of the query argument is not the same as the one of a ride on
	 * purpose: the two flows have their own handler, and a link meant for one can
	 * never be replayed against the other.
	 *
	 * @param string $reference Public registration reference.
	 * @param string $token     Raw unregistration token.
	 * @return string
	 */
	private function duty_action_url( $reference, $token ) {
		return add_query_arg(
			array(
				'fg_duty_action' => 'view',
				'signup_ref'     => $reference,
				'intent'         => 'unregister',
				'token'          => $token,
			),
			home_url( '/' )
		);
	}

	/**
	 * Return an expiry at least 30 days after the event display cut-off.
	 *
	 * @param int $event_id Event ID.
	 * @return int
	 */
	private function published_delete_expiry( $event_id ) {
		$event = $this->repository->get_event( $event_id );
		$now   = time() + DAY_IN_SECONDS;

		if ( ! $event || ! FG_Repository::is_valid_date( $event->event_date ) ) {
			return $now + FG_PUBLISHED_DELETE_TOKEN_TTL;
		}

		$time      = FG_Repository::is_valid_time( $event->event_time ) ? $event->event_time : '23:59';
		$zone      = wp_timezone();
		$date_time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $event->event_date . ' ' . $time, $zone );
		if ( ! $date_time instanceof DateTimeImmutable ) {
			return $now + FG_PUBLISHED_DELETE_TOKEN_TTL;
		}

		return max( $now, $date_time->getTimestamp() + FG_PUBLISHED_DELETE_TOKEN_TTL );
	}

	/**
	 * Record an unusually fast submission without blocking it.
	 *
	 * @param int $started_at Client-provided start timestamp.
	 * @return void
	 */
	private function record_speed_signal( $started_at ) {
		if ( $started_at > 0 && absint( time() - $started_at ) < 3 ) {
			$this->stats->increment( 'bot_fast_submit' );
		}
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

	/**
	 * Detect contact details in a value that is published verbatim.
	 *
	 * A first name or a nickname is expected in the public list and is not
	 * touched here. What is rejected are the details that do not belong in a
	 * list anyone can read: e-mail addresses, phone numbers, and house numbers
	 * together with a street name. The server therefore does not rely on the
	 * hint text and the confirmation preview alone.
	 *
	 * A full name is not detected, because that cannot be done reliably. It
	 * stays a matter of the confirmation preview, where the person reads back
	 * exactly what becomes public before confirming.
	 *
	 * @param string $value Submitted value.
	 * @return bool
	 */
	private function contains_personal_data( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return false;
		}

		$patterns = array(
			// E-mail address.
			'/\S+@\S+\.\S+/',
			// Phone number with a trunk prefix, plus long unbroken digit runs.
			'/(?:\+49[\s\/\-]?0?\d|0\d{2,4}[\s\/\-]?)\d[\d\s\/\-]{4,}\d/',
			'/(?:\+49[\s\/\-]?)?\d{9,}/',
			// Street designation plus house number, also inside compound names
			// such as "Hauptstraße".
			'/(?:stra(?:ss|ß)e|str|gasse|weg|platz|allee|ring|damm|steig|ufer|markt|graben|rain|wall|zeile|gürtel|höhe)\b\D{0,12}\d/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $value ) ) {
				return true;
			}
		}

		return false;
	}
}
