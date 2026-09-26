<?php
/**
 * Admin screens, statistics and the delete handler.
 *
 * @package Fahrgemeinschaften
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
	 * Constructor.
	 *
	 * @param FG_Repository|null $repository Optional repository.
	 * @param FG_Stats|null      $stats      Optional statistics.
	 */
	public function __construct( FG_Repository $repository = null, FG_Stats $stats = null ) {
		$this->repository = $repository ? $repository : new FG_Repository();
		$this->stats      = $stats ? $stats : new FG_Stats();
		$this->events     = new FG_Admin_Events( $this->repository );
		$this->rides      = new FG_Admin_Rides( $this->repository );
		$this->settings   = new FG_Admin_Settings();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_fg_delete_record', array( $this, 'delete_record' ) );
		add_action( 'admin_notices', array( $this, 'render_delete_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_tls_notice' ) );
	}

	/**
	 * Register the top-level menu and its two object screens.
	 *
	 * @return void
	 */
	public function register_menu() {
		// The top level entry carries the plugin name and opens the statistics
		// screen, so its slug and the slug of the statistics screen are the
		// same.
		add_menu_page(
			__( 'Statistik', 'fahrgemeinschaften' ),
			__( 'Fahrgemeinschaften', 'fahrgemeinschaften' ),
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
		// add_submenu_page() skips it when the slugs are equal. The three
		// screens that carry their own slug are then placed in front of it, which
		// yields the order Arbeitsdienste, Fahrgemeinschaften, Einstellungen,
		// Statistik.
		add_submenu_page(
			FG_ADMIN_MENU_SLUG,
			__( 'Statistik', 'fahrgemeinschaften' ),
			__( 'Statistik', 'fahrgemeinschaften' ),
			'edit_posts',
			FG_ADMIN_MENU_SLUG,
			array( $this, 'render_stats' )
		);

		add_submenu_page(
			FG_ADMIN_MENU_SLUG,
			__( 'Arbeitsdienste', 'fahrgemeinschaften' ),
			__( 'Arbeitsdienste', 'fahrgemeinschaften' ),
			'edit_posts',
			FG_EVENTS_PAGE_SLUG,
			array( $this->events, 'render' ),
			0
		);

		add_submenu_page(
			FG_ADMIN_MENU_SLUG,
			__( 'Fahrgemeinschaften', 'fahrgemeinschaften' ),
			__( 'Fahrgemeinschaften', 'fahrgemeinschaften' ),
			'edit_posts',
			FG_RIDES_PAGE_SLUG,
			array( $this->rides, 'render' ),
			1
		);

		add_submenu_page(
			FG_ADMIN_MENU_SLUG,
			__( 'Einstellungen', 'fahrgemeinschaften' ),
			__( 'Einstellungen', 'fahrgemeinschaften' ),
			'edit_posts',
			FG_SETTINGS_PAGE_SLUG,
			array( $this->settings, 'render' ),
			2
		);
	}

	/**
	 * Render the read-only statistics overview.
	 *
	 * @return void
	 */
	public function render_stats() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diesen Bereich.', 'fahrgemeinschaften' ) );
		}

		$labels  = FG_Stats::labels();
		$periods = array(
			1  => __( 'Heute', 'fahrgemeinschaften' ),
			7  => __( 'Letzte 7 Tage', 'fahrgemeinschaften' ),
			30 => __( 'Letzte 30 Tage', 'fahrgemeinschaften' ),
		);
		$values = array();
		foreach ( $periods as $days => $label ) {
			$values[ $days ] = $this->stats->get_recent( $days );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Statistik', 'fahrgemeinschaften' ); ?></h1>
			<p><?php esc_html_e( 'Aggregierte Zähler ohne Namen, E-Mail-Adressen, IP-Adressen, Labels oder Rohformulare. Die Tageswerte werden nach 90 Tagen entfernt.', 'fahrgemeinschaften' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Kennzahl', 'fahrgemeinschaften' ); ?></th>
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
	 * Permanently delete an event or a ride after a nonce and capability check.
	 *
	 * Deleting a work service deletes its rides in the same operation, so no
	 * ride can be left behind without an owner.
	 *
	 * @return void
	 */
	public function delete_record() {
		$type      = self::query_key( 'type' );
		$record_id = self::query_int( 'record' );

		check_admin_referer( 'fg_delete_record_' . $type . '_' . $record_id );

		if ( ! in_array( $type, array( 'event', 'ride' ), true ) || ! $record_id ) {
			wp_die( esc_html__( 'Die Eintragung konnte nicht gelöscht werden.', 'fahrgemeinschaften' ) );
		}

		if ( ! current_user_can( 'delete_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung, Eintragungen zu löschen.', 'fahrgemeinschaften' ) );
		}

		if ( 'event' === $type ) {
			$result = $this->repository->delete_event( $record_id );
			$target = FG_EVENTS_PAGE_SLUG;
		} else {
			$result = array(
				'deleted' => $this->repository->delete_ride( $record_id ),
				'rides'   => 0,
			);
			$target = FG_RIDES_PAGE_SLUG;
		}

		if ( ! $result['deleted'] ) {
			wp_die( esc_html__( 'Die Eintragung konnte nicht gelöscht werden.', 'fahrgemeinschaften' ) );
		}

		set_transient(
			'fg_delete_notice_' . get_current_user_id(),
			array( 'count' => (int) $result['rides'] ),
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
	 * @param string $type     `event` or `ride`.
	 * @param int    $record_id Record ID.
	 * @param string $question Confirmation question.
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
			esc_attr( 'return confirm(' . wp_json_encode( $question ) . ');' ),
			esc_html__( 'Endgültig löschen', 'fahrgemeinschaften' )
		);
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
					'fahrgemeinschaften'
				),
				$notice['count']
			)
			: __( 'Die Eintragung wurde endgültig gelöscht.', 'fahrgemeinschaften' );

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
				'toplevel_page_' . FG_ADMIN_MENU_SLUG,
				FG_ADMIN_MENU_SLUG . '_page_' . FG_EVENTS_PAGE_SLUG,
				FG_ADMIN_MENU_SLUG . '_page_' . FG_RIDES_PAGE_SLUG,
				FG_ADMIN_MENU_SLUG . '_page_' . FG_SETTINGS_PAGE_SLUG,
			),
			true
		);
		if ( ! $is_plugin_screen ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Diese Website ist nicht über HTTPS erreichbar. Die öffentlichen Fahrgemeinschafts-Seiten übertragen E-Mail-Adressen und sollten nur mit TLS betrieben werden.', 'fahrgemeinschaften' )
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
