<?php
/**
 * Admin screens, statistics and the delete handler.
 *
 * @package Arbeitsdienste
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin menus and owns everything that is shared between the
 * two object screens.
 *
 * The plugin adds no capability of its own. It uses the WordPress core
 * capabilities: `edit_posts` for reading and editing the lists, `delete_posts`
 * for permanent deletion.
 */
final class FG_Admin {
	/**
	 * Rows per page in the object lists.
	 *
	 * @var int
	 */
	const PER_PAGE = 20;

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
	 * Event screens.
	 *
	 * @var FG_Admin_Events
	 */
	private $events;

	/**
	 * Member screens.
	 *
	 * @var FG_Admin_Members
	 */
	private $members;

	/**
	 * Ride screens.
	 *
	 * @var FG_Admin_Rides
	 */
	private $rides;

	/**
	 * Settings screen.
	 *
	 * @var FG_Admin_Settings
	 */
	private $settings;

	/**
	 * Mail texts screen.
	 *
	 * @var FG_Admin_Mails
	 */
	private $mails;

	/**
	 * Constructor.
	 *
	 * @param FG_Repository|null $repository Optional repository.
	 * @param FG_Stats|null      $stats      Optional statistics.
	 */
	public function __construct( FG_Repository $repository = null, FG_Stats $stats = null ) {
		$this->repository = $repository ? $repository : new FG_Repository();
		$this->stats      = $stats ? $stats : new FG_Stats();
		$this->events     = new FG_Admin_Events( $this->repository );
		$this->members    = new FG_Admin_Members( $this->repository );
		$this->rides      = new FG_Admin_Rides( $this->repository );
		$this->settings   = new FG_Admin_Settings();
		$this->mails      = new FG_Admin_Mails();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_fg_delete_record', array( $this, 'delete_record' ) );
		add_action( 'admin_notices', array( $this, 'render_delete_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_tls_notice' ) );
	}

	/**
	 * Register the top-level menu and its object screens.
	 *
	 * @return void
	 */
	public function register_menu() {
		// The top level entry carries the name of the tool and links to
		// whatever the first submenu is, so a click on it lands on the work
		// duty list. The work duties are what the tool is about now, and that is
		// what the entry says.
		add_menu_page(
			__( 'Statistik', 'arbeitsdienste' ),
			__( 'Arbeitsdienste', 'arbeitsdienste' ),
			'edit_posts',
			FG_ADMIN_MENU_SLUG,
			array( $this, 'render_stats' ),
			'dashicons-groups',
			26
		);

		// The order of the submenu entries is set through the position
		// argument, not through the order of these calls.
		//
		// WordPress prepends a link to the top level page to the first
		// add_submenu_page() call whose slug differs from the parent, and
		// labels it like the parent. Registering the statistics screen first,
		// under the parent slug, suppresses that entry: the guard in
		// add_submenu_page() skips it when the slugs are equal. The five
		// screens that carry their own slug are then placed in front of it, which
		// yields the order Arbeitsdienste, Mitglieder, Fahrgemeinschaften,
		// Einstellungen, E-Mails, Statistik.
		//
		// The member list stands between the two object screens because it is
		// what the duties are made of: a duty says how many people it needs, and
		// the list of who is in the club answers that. The rides keep their old
		// place at the end.
		//
		// The top level entry links to that first submenu, so a click on the
		// name of the tool lands on the work duty list. It also means the word
		// "Arbeitsdienste" stands twice, once in the top level and once in the
		// submenu. That is deliberate: the top level names the tool, the
		// submenu names the screen. Many plugins do the same, and dropping the
		// submenu entry would change the address of the work duty list.
		add_submenu_page(
			FG_ADMIN_MENU_SLUG,
			__( 'Statistik', 'arbeitsdienste' ),
			__( 'Statistik', 'arbeitsdienste' ),
			'edit_posts',
			FG_ADMIN_MENU_SLUG,
			array( $this, 'render_stats' )
		);

		add_submenu_page(
			FG_ADMIN_MENU_SLUG,
			__( 'Arbeitsdienste', 'arbeitsdienste' ),
			__( 'Arbeitsdienste', 'arbeitsdienste' ),
			'edit_posts',
			FG_EVENTS_PAGE_SLUG,
			array( $this->events, 'render' ),
			0
		);

		add_submenu_page(
			FG_ADMIN_MENU_SLUG,
			__( 'Mitglieder', 'arbeitsdienste' ),
			__( 'Mitglieder', 'arbeitsdienste' ),
			'edit_posts',
			FG_MEMBERS_PAGE_SLUG,
			array( $this->members, 'render' ),
			1
		);

		add_submenu_page(
			FG_ADMIN_MENU_SLUG,
			__( 'Fahrgemeinschaften', 'arbeitsdienste' ),
			__( 'Fahrgemeinschaften', 'arbeitsdienste' ),
			'edit_posts',
			FG_RIDES_PAGE_SLUG,
			array( $this->rides, 'render' ),
			2
		);

		add_submenu_page(
			FG_ADMIN_MENU_SLUG,
			__( 'Einstellungen', 'arbeitsdienste' ),
			__( 'Einstellungen', 'arbeitsdienste' ),
			'edit_posts',
			FG_SETTINGS_PAGE_SLUG,
			array( $this->settings, 'render' ),
			3
		);

		// The wording of the messages is not a setting of the club but a text it
		// writes, and it is the longest of the six screens. It stands behind the
		// settings because the settings decide what goes into every one of the
		// mails — logo and footer — and a club reads them in that order.
		add_submenu_page(
			FG_ADMIN_MENU_SLUG,
			__( 'E-Mails', 'arbeitsdienste' ),
			__( 'E-Mails', 'arbeitsdienste' ),
			'edit_posts',
			FG_MAILS_PAGE_SLUG,
			array( $this->mails, 'render' ),
			4
		);
	}

	/**
	 * Render the read-only statistics overview.
	 *
	 * @return void
	 */
	public function render_stats() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diesen Bereich.', 'arbeitsdienste' ) );
		}

		$labels  = FG_Stats::labels();
		$periods = array(
			1  => __( 'Heute', 'arbeitsdienste' ),
			7  => __( 'Letzte 7 Tage', 'arbeitsdienste' ),
			30 => __( 'Letzte 30 Tage', 'arbeitsdienste' ),
		);
		$values = array();
		foreach ( $periods as $days => $label ) {
			$values[ $days ] = $this->stats->get_recent( $days );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Statistik', 'arbeitsdienste' ); ?></h1>
			<p><?php esc_html_e( 'Aggregierte Zähler ohne Namen, E-Mail-Adressen, IP-Adressen, Labels oder Rohformulare. Die Tageswerte werden nach 90 Tagen entfernt.', 'arbeitsdienste' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Kennzahl', 'arbeitsdienste' ); ?></th>
						<?php foreach ( $periods as $label ) : ?>
							<th><?php echo esc_html( $label ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $labels as $key => $label ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<?php foreach ( $periods as $days => $period_label ) : ?>
								<td><?php echo esc_html( number_format_i18n( $values[ $days ][ $key ] ) ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Permanently delete an event, a ride, a member or a registration after a
	 * nonce and capability check.
	 *
	 * Deleting a work service deletes its rides and its registrations in the same
	 * operation, so no ride and no registration can be left behind without an
	 * owner. Deleting a member deletes its registrations and nothing else: a ride
	 * is its own public entry and survives a member who is gone.
	 *
	 * @return void
	 */
	public function delete_record() {
		$type      = self::query_key( 'type' );
		$record_id = self::query_int( 'record' );

		check_admin_referer( 'fg_delete_record_' . $type . '_' . $record_id );

		if ( ! in_array( $type, array( 'event', 'ride', 'member', 'registration' ), true ) || ! $record_id ) {
			wp_die( esc_html__( 'Die Eintragung konnte nicht gelöscht werden.', 'arbeitsdienste' ) );
		}

		if ( ! current_user_can( 'delete_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung, Eintragungen zu löschen.', 'arbeitsdienste' ) );
		}

		$count = 0;

		if ( 'event' === $type ) {
			$result = $this->repository->delete_event( $record_id );
			$count  = (int) $result['rides'];
			$target = FG_EVENTS_PAGE_SLUG;
		} elseif ( 'member' === $type ) {
			$result = $this->repository->delete_member( $record_id );
			$target = FG_MEMBERS_PAGE_SLUG;
		} elseif ( 'registration' === $type ) {
			$result = array( 'deleted' => $this->repository->delete_registration( $record_id ) );
			$target = FG_EVENTS_PAGE_SLUG;
		} else {
			$result = array(
				'deleted' => $this->repository->delete_ride( $record_id ),
				'rides'   => 0,
			);
			$target = FG_RIDES_PAGE_SLUG;
		}

		if ( ! $result['deleted'] ) {
			wp_die( esc_html__( 'Die Eintragung konnte nicht gelöscht werden.', 'arbeitsdienste' ) );
		}

		set_transient(
			'fg_delete_notice_' . get_current_user_id(),
			array( 'count' => $count ),
			MINUTE_IN_SECONDS
		);

		$redirect = add_query_arg(
			array(
				'page'       => $target,
				'fg_message' => 'deleted',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect, 303 );
		exit;
	}

	/**
	 * Build a nonce-protected permanent delete link.
	 *
	 * @param string $type      `event`, `ride`, `member` or `registration`.
	 * @param int    $record_id Record ID.
	 * @param string $question  Confirmation question.
	 * @return string
	 */
	public static function delete_link( $type, $record_id, $question ) {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'fg_delete_record',
					'type'   => $type,
					'record' => (int) $record_id,
				),
				admin_url( 'admin-post.php' )
			),
			'fg_delete_record_' . $type . '_' . (int) $record_id
		);

		return sprintf(
			'<a href="%1$s" class="submitdelete fg-delete" onclick="%2$s">%3$s</a>',
			esc_url( $url ),
			// Without JSON_UNESCAPED_UNICODE the question reaches the browser as
			// "Fahrgemeinschaft endg\u00fcltig l\u00f6schen?". The dialog shows it
			// correctly, because \u00fc is what a JavaScript string literal says
			// for ü, but the page source stops being readable and a question that
			// is only ever checked in the source can no longer be read either.
			esc_attr( 'return confirm(' . wp_json_encode( $question, JSON_UNESCAPED_UNICODE ) . ');' ),
			esc_html__( 'Endgültig löschen', 'arbeitsdienste' )
		);
	}

	/**
	 * Print a delete link and keep the question it asks.
	 *
	 * kses does not allow event handlers. A link that is filtered through
	 * wp_kses_post() therefore loses its onclick, and with it the whole question
	 * the link was built to ask: the person in front of the screen sees a bare
	 * "Endgültig löschen" and is not told that the click also takes two rides,
	 * a member's place in this duty, or the member themselves with it. The link
	 * is assembled with esc_url(), esc_attr() and esc_html() already, so the
	 * value of the attribute needs nothing further — only its name has to be
	 * allowed, and only here, where the reason is written down.
	 *
	 * @param string $type      Record type: event, ride, member, registration.
	 * @param int    $record_id Record ID.
	 * @param string $question  Question the link asks before it deletes.
	 * @return void
	 */
	public static function echo_delete_link( $type, $record_id, $question ) {
		$allowed            = wp_kses_allowed_html( 'post' );
		$allowed['a']       = isset( $allowed['a'] ) ? $allowed['a'] : array();
		$allowed['a']['onclick'] = true;

		echo wp_kses( self::delete_link( $type, $record_id, $question ), $allowed ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside delete_link(), see above.
	}

	/**
	 * Show the cascade result after deletion.
	 *
	 * @return void
	 */
	public function render_delete_notice() {
		$notice = get_transient( 'fg_delete_notice_' . get_current_user_id() );
		if ( ! is_array( $notice ) || 'deleted' !== self::query_key( 'fg_message' ) ) {
			return;
		}

		delete_transient( 'fg_delete_notice_' . get_current_user_id() );
		$message = $notice['count'] > 0
			? sprintf(
				/* translators: %d: number of cascaded rides. */
				_n(
					'Arbeitsdienst und %d zugehörige Fahrgemeinschaft wurden gelöscht.',
					'Arbeitsdienst und %d zugehörige Fahrgemeinschaften wurden gelöscht.',
					$notice['count'],
					'arbeitsdienste'
				),
				$notice['count']
			)
			: __( 'Die Eintragung wurde endgültig gelöscht.', 'arbeitsdienste' );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Store a short admin notice for the current user.
	 *
	 * Notices are collected instead of replaced, because one save can produce
	 * several messages.
	 *
	 * @param string $message Message.
	 * @param string $type    Notice type.
	 * @return void
	 */
	public static function store_notice( $message, $type = 'success' ) {
		$key     = 'fg_admin_notice_' . get_current_user_id();
		$stored  = get_transient( $key );
		$notices = is_array( $stored ) ? array_values( $stored ) : array();

		$notices[] = array(
			'message' => (string) $message,
			'type'    => sanitize_key( $type ),
		);

		if ( count( $notices ) > 5 ) {
			$notices = array_slice( $notices, -5 );
		}

		set_transient( $key, $notices, MINUTE_IN_SECONDS );
	}

	/**
	 * Render stored admin notices.
	 *
	 * @return void
	 */
	public function render_notice() {
		$key     = 'fg_admin_notice_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) || array() === $notices ) {
			return;
		}

		delete_transient( $key );

		foreach ( $notices as $notice ) {
			if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
				continue;
			}

			$type  = isset( $notice['type'] ) ? $notice['type'] : 'success';
			$class = 'warning' === $type ? 'notice-warning' : ( 'error' === $type ? 'notice-error' : 'notice-success' );
			printf(
				'<div class="notice %s is-dismissible"><p>%s</p></div>',
				esc_attr( $class ),
				esc_html( $notice['message'] )
			);
		}
	}

	/**
	 * The screen id that WordPress gives one of the plugin's own screens.
	 *
	 * The id of a plugin submenu is built from the *menu title* of the parent,
	 * not from its slug: WordPress stores sanitize_title( $menu_title ) under
	 * the parent slug and assembles "<that>_page_<menu slug>" from it. The two
	 * only read the same when the menu title happens to match the slug, which
	 * this code took for granted until the menu was renamed from
	 * "Fahrgemeinschaften" to "Arbeitsdienste" and the ids quietly changed with
	 * it. Asking WordPress is shorter than repeating its rule, and it cannot go
	 * stale at the next rename.
	 *
	 * @param string $menu_slug   Slug of the screen.
	 * @param string $parent_slug Slug of the parent menu, empty for the top level.
	 * @return string
	 */
	public static function screen_id( $menu_slug, $parent_slug = FG_ADMIN_MENU_SLUG ) {
		if ( function_exists( 'get_plugin_page_hookname' ) ) {
			return get_plugin_page_hookname( $menu_slug, $parent_slug );
		}

		// Without the WordPress function the slug is all that is left. That is
		// the same value WordPress would build as long as the menu title reads
		// like the slug, and it is only a fallback for a WordPress that does not
		// offer the function at all. The top level is spelled out separately,
		// because there WordPress does not use the parent at all.
		if ( '' === $parent_slug ) {
			return 'toplevel_page_' . $menu_slug;
		}

		return $parent_slug . '_page_' . $menu_slug;
	}

	/**
	 * Warn administrators when the site is not served over HTTPS.
	 *
	 * The plugin cannot send a visitor to a secure page variant that the server
	 * does not offer, so it serves the public pages as they are instead of
	 * breaking them. The missing TLS setup is reported here, on the screens the
	 * plugin owns, so the requirement does not silently disappear.
	 *
	 * @return void
	 */
	public function render_tls_notice() {
		if ( FG_Security::site_uses_https() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof WP_Screen ) {
			return;
		}

		$is_plugin_screen = in_array(
			$screen->id,
			array(
				self::screen_id( FG_ADMIN_MENU_SLUG, '' ),
				self::screen_id( FG_EVENTS_PAGE_SLUG ),
				self::screen_id( FG_MEMBERS_PAGE_SLUG ),
				self::screen_id( FG_RIDES_PAGE_SLUG ),
				self::screen_id( FG_SETTINGS_PAGE_SLUG ),
				self::screen_id( FG_MAILS_PAGE_SLUG ),
			),
			true
		);
		if ( ! $is_plugin_screen ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Diese Website ist nicht über HTTPS erreichbar. Die öffentlichen Fahrgemeinschafts-Seiten übertragen E-Mail-Adressen und sollten nur mit TLS betrieben werden.', 'arbeitsdienste' )
		);
	}

	/**
	 * Read a scalar integer query value.
	 *
	 * @param string $key Query key.
	 * @return int
	 */
	public static function query_int( $key ) {
		if ( ! isset( $_GET[ $key ] ) || is_array( $_GET[ $key ] ) || is_object( $_GET[ $key ] ) ) {
			return 0;
		}

		return absint( wp_unslash( $_GET[ $key ] ) );
	}

	/**
	 * Read a scalar key query value.
	 *
	 * @param string $key Query key.
	 * @return string
	 */
	public static function query_key( $key ) {
		if ( ! isset( $_GET[ $key ] ) || is_array( $_GET[ $key ] ) || is_object( $_GET[ $key ] ) ) {
			return '';
		}

		return sanitize_key( wp_unslash( $_GET[ $key ] ) );
	}
}
