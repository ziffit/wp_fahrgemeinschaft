<?php
/**
 * Public form and token action handlers.
 *
 * @package Fahrgemeinschaften
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles ride submission, confirmation, deletion and contact requests.
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
		add_action( 'template_redirect', array( $this, 'maybe_require_https' ), -1 );
		add_action( 'template_redirect', array( $this, 'maybe_render_action_page' ), 0 );
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
		$alias  = sanitize_text_field( $this->post_value( 'fg_alias' ) );
		$origin = sanitize_text_field( $this->post_value( 'fg_origin' ) );
		$email  = $this->repository->normalize_email( $this->post_value( 'fg_contact_email' ) );
		$event  = $this->repository->get_event_by_reference( $ref );

		$invalid_email = false === $email;
		if ( $invalid_email ) {
			$this->stats->increment( 'publish_invalid_email' );
		}

		$personal_data = $this->contains_personal_data( $alias ) || $this->contains_personal_data( $origin );
		if ( $personal_data ) {
			$this->stats->increment( 'publish_personal_data' );
		}

		if (
			! in_array( $mode, array( FG_RIDE_MODE_OFFER, FG_RIDE_MODE_SEARCH ), true )
			|| ! $event
			|| ! $this->repository->is_event_active( $event->id )
			|| '' === $alias
			|| $this->string_length( $alias ) > 80
			|| '' === $origin
			|| $this->string_length( $origin ) > 100
			|| $personal_data
			|| $invalid_email
			|| '1' !== $this->post_value( 'fg_consent' )
		) {
			FG_Security::redirect_with_notice( 'not_created', $source );
		}

		if ( ! $this->repository->is_event_participant( $event->id, $email ) ) {
			$this->stats->increment( 'publish_invalid_email' );
			FG_Security::redirect_with_notice( 'not_created', $source );
		}

		$this->stats->increment( 'publish_valid_email' );

		$ride = $this->repository->create_pending_ride(
			array(
				'event_id'      => $event->id,
				'mode'          => $mode,
				'alias'         => $alias,
				'origin'        => $origin,
				'contact_email' => $email,
				'source_url'    => $source,
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

		$ref          = sanitize_key( $this->post_value( 'ride_ref' ) );
		$email        = $this->repository->normalize_email( $this->post_value( 'fg_contact_email' ) );
		$ride         = $this->repository->get_ride_by_reference( $ref );
		$ride_visible = $ride
			&& $this->repository->is_valid_public_ride( $ride )
			&& '' !== $ride->confirmed_at
			&& $this->repository->is_event_active( $ride->event_id );

		if ( ! $ride_visible || false === $email || ! $this->repository->is_event_participant( $ride->event_id, $email ) ) {
			$this->stats->increment( 'contact_invalid_email' );
			FG_Security::redirect_with_notice( 'contact_received', $source );
		}

		$creator_email = $this->repository->normalize_email( $ride->contact_email );
		if (
			false === $creator_email
			|| $creator_email === $email
			|| ! $this->repository->is_event_participant( $ride->event_id, $creator_email )
		) {
			FG_Security::redirect_with_notice( 'contact_received', $source );
		}

		$this->stats->increment( 'contact_valid_email' );
		$result = $this->mailer->send_contact_notifications( $ride->id, $email );

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
				__( 'Link nicht gültig', 'fahrgemeinschaften' ),
				'<h1>' . esc_html__( 'Link nicht gültig', 'fahrgemeinschaften' ) . '</h1>'
				. '<p>' . esc_html__( 'Dieser Link ist abgelaufen, wurde bereits verwendet oder ist nicht korrekt.', 'fahrgemeinschaften' ) . '</p>'
				. '<p>' . esc_html__( 'Bitte kontaktiere uns, wenn du Unterstützung benötigst.', 'fahrgemeinschaften' ) . '</p>'
			);
		}

		$data      = $this->repository->get_ride_display_data( $ride );
		$mode      = 'search' === $data['mode'] ? __( 'Ich suche', 'fahrgemeinschaften' ) : __( 'Ich biete', 'fahrgemeinschaften' );
		$is_delete = 'delete' === $intent;
		$heading   = $is_delete
			? __( 'Veröffentlichte Fahrgemeinschaft löschen', 'fahrgemeinschaften' )
			: ( 'discard' === $intent
				? __( 'Vorgemerkte Eintragung löschen', 'fahrgemeinschaften' )
				: __( 'Veröffentlichung bestätigen', 'fahrgemeinschaften' ) );
		$button    = $is_delete
			? __( 'Endgültig löschen', 'fahrgemeinschaften' )
			: ( 'discard' === $intent
				? __( 'Eintragung löschen und nicht veröffentlichen', 'fahrgemeinschaften' )
				: __( 'Veröffentlichung bestätigen', 'fahrgemeinschaften' ) );
		$warning   = 'confirm' === $intent
			? __( 'Mit der Bestätigung werden die unten stehenden Angaben öffentlich angezeigt. Bitte prüfe sie sorgfältig.', 'fahrgemeinschaften' )
			: __( 'Achtung: Diese Aktion ist sofort und ohne weitere Rückfrage wirksam.', 'fahrgemeinschaften' );

		$body  = '<h1>' . esc_html( $heading ) . '</h1>';
		$body .= '<div class="warning"><p>' . esc_html( $warning ) . '</p></div>';
		$body .= '<dl>';
		$body .= '<dt>' . esc_html__( 'Art', 'fahrgemeinschaften' ) . '</dt><dd>' . esc_html( $mode ) . '</dd>';
		$body .= '<dt>' . esc_html__( 'Bezeichnung', 'fahrgemeinschaften' ) . '</dt><dd>' . esc_html( $ride->alias ) . '</dd>';
		$body .= '<dt>' . esc_html__( 'Arbeitsdienst', 'fahrgemeinschaften' ) . '</dt><dd>' . esc_html( $data['event_label'] . ( $data['event_date'] ? ' (' . $data['event_date'] . ')' : '' ) ) . '</dd>';
		$body .= '<dt>' . esc_html__( 'Abfahrtsbereich', 'fahrgemeinschaften' ) . '</dt><dd>' . esc_html( $data['origin'] ) . '</dd>';
		$body .= '</dl>';
		$body .= '<p><strong>' . esc_html__( 'Die E-Mail-Adresse wird nicht öffentlich angezeigt.', 'fahrgemeinschaften' ) . '</strong></p>';
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

		if ( ! $ride || ! $this->valid_token_action( $ride, $intent, $token ) || ! $this->valid_token_form_nonce( $intent, $token, $this->post_value( 'token_nonce' ) ) ) {
			FG_Security::redirect_with_notice( 'invalid_token', $source );
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
			$email = $this->repository->normalize_email( $ride->contact_email );
			if (
				! $this->repository->is_event_active( $ride->event_id )
				|| false === $email
				|| ! $this->repository->is_event_participant( $ride->event_id, $email )
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
			esc_html__( 'Diese Aktion ist nur per POST möglich.', 'fahrgemeinschaften' ),
			esc_html__( 'Ungültige Anfrage', 'fahrgemeinschaften' ),
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
	 * Public fields must stay coarse. E-mail addresses, phone numbers and
	 * house numbers together with a street name are rejected server side, so
	 * the hint text and the confirmation preview are not the only guard.
	 * Plain personal names are not detected reliably and stay a matter of the
	 * confirmation preview.
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
