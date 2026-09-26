<?php
/**
 * Admin screens for work-service events.
 *
 * @package Fahrgemeinschaften
 */

defined( 'ABSPATH' ) || exit;

/**
 * List and form screen for work-service events.
 *
 * A work service is a small record: a title, a date, an optional cut-off time,
 * the pre-registered participant addresses and a visibility flag. There is no
 * draft state: an event either may be offered publicly or it may not.
 */
final class FG_Admin_Events {
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

		add_action( 'admin_post_fg_save_event', array( $this, 'save' ) );
	}

	/**
	 * Render the list or the form, depending on the request.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diesen Bereich.', 'fahrgemeinschaften' ) );
		}

		// `event` in the query means a form is wanted: an ID opens that record,
		// the explicit 0 opens the empty form for a new one. Without the
		// parameter the overview is shown.
		if ( isset( $_GET['event'] ) && ! is_array( $_GET['event'] ) ) {
			$requested = FG_Admin::query_int( 'event' );
			$event     = $requested ? $this->repository->get_event( $requested ) : null;

			if ( $requested && ! $event ) {
				FG_Admin::store_notice( __( 'Dieser Arbeitsdienst wurde nicht gefunden.', 'fahrgemeinschaften' ), 'error' );
			}

			$this->render_form( $event );
			return;
		}

		$this->render_list();
	}

	/**
	 * Render the list of all work services.
	 *
	 * @return void
	 */
	private function render_list() {
		$total   = $this->repository->count_events();
		$page    = max( 1, FG_Admin::query_int( 'paged' ) );
		$events  = $this->repository->get_events_page( ( $page - 1 ) * FG_Admin::PER_PAGE, FG_Admin::PER_PAGE );
		$new_url = add_query_arg(
			array(
				'page'  => FG_EVENTS_PAGE_SLUG,
				'event' => 0,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Arbeitsdienste', 'fahrgemeinschaften' ); ?></h1>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Neuer Arbeitsdienst', 'fahrgemeinschaften' ); ?></a>
			<hr class="wp-header-end">
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Titel', 'fahrgemeinschaften' ); ?></th>
						<th><?php esc_html_e( 'Datum', 'fahrgemeinschaften' ); ?></th>
						<th><?php esc_html_e( 'Öffentlich sichtbar', 'fahrgemeinschaften' ); ?></th>
						<th><?php esc_html_e( 'Teilnehmer', 'fahrgemeinschaften' ); ?></th>
						<th><?php esc_html_e( 'Fahrgemeinschaften', 'fahrgemeinschaften' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $events ) ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'Es wurde noch kein Arbeitsdienst angelegt.', 'fahrgemeinschaften' ); ?></td>
						</tr>
					<?php endif; ?>
					<?php foreach ( $events as $event ) : ?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( $this->edit_url( $event->id ) ); ?>"><?php echo esc_html( $event->title ); ?></a></strong>
							</td>
							<td><?php echo esc_html( $this->repository->format_event_date( $event ) ); ?></td>
							<td>
								<?php
								echo $event->is_active
									? esc_html__( 'Ja', 'fahrgemeinschaften' )
									: esc_html__( 'Nein', 'fahrgemeinschaften' );
								?>
							</td>
							<td><?php echo esc_html( (string) count( $event->participants ) ); ?></td>
							<td><?php echo esc_html( (string) $this->repository->count_event_rides( $event->id ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php $this->render_pagination( $total, $page ); ?>
		</div>
		<?php
	}

	/**
	 * Render the create and edit form.
	 *
	 * @param FG_Event|null $event Event to edit, or null to create one.
	 * @return void
	 */
	private function render_form( $event = null ) {
		$is_new   = ! $event instanceof FG_Event;
		$title    = $is_new ? '' : $event->title;
		$date     = $is_new ? '' : $event->event_date;
		$time     = $is_new ? '' : $event->event_time;
		$active   = $is_new ? true : $event->is_active;
		$uuid     = $is_new ? '' : $event->event_uuid;
		$attendees = $is_new ? '' : implode( "\n", $event->participants );
		$ride_count = $is_new ? 0 : $this->repository->count_event_rides( $event->id );
		?>
		<div class="wrap">
			<h1><?php echo $is_new
				? esc_html__( 'Neuer Arbeitsdienst', 'fahrgemeinschaften' )
				: esc_html__( 'Arbeitsdienst bearbeiten', 'fahrgemeinschaften' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="fg_save_event">
				<input type="hidden" name="fg_event_id" value="<?php echo esc_attr( $is_new ? 0 : $event->id ); ?>">
				<?php wp_nonce_field( 'fg_save_event_' . ( $is_new ? 0 : $event->id ), 'fg_event_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fg-title"><?php esc_html_e( 'Titel', 'fahrgemeinschaften' ); ?></label></th>
						<td>
							<input type="text" id="fg-title" name="fg_title" class="regular-text" maxlength="200" value="<?php echo esc_attr( $title ); ?>" required>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fg-date"><?php esc_html_e( 'Datum', 'fahrgemeinschaften' ); ?></label></th>
						<td>
							<input type="date" id="fg-date" name="fg_event_date" value="<?php echo esc_attr( $date ); ?>" required>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fg-time"><?php esc_html_e( 'Öffentlich sichtbar bis (Uhrzeit, optional)', 'fahrgemeinschaften' ); ?></label></th>
						<td>
							<input type="time" id="fg-time" name="fg_event_time" value="<?php echo esc_attr( $time ); ?>">
							<span class="description"><?php esc_html_e( 'Ohne Uhrzeit bleibt der Eintrag den ganzen Tag sichtbar.', 'fahrgemeinschaften' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fg-active"><?php esc_html_e( 'Öffentlich sichtbar', 'fahrgemeinschaften' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="fg-active" name="fg_event_active" value="1" <?php checked( $active ); ?>>
								<?php esc_html_e( 'Dieser Arbeitsdienst darf öffentlich angeboten werden.', 'fahrgemeinschaften' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fg-participants"><?php esc_html_e( 'Teilnehmer-E-Mails', 'fahrgemeinschaften' ); ?></label></th>
						<td>
							<textarea id="fg-participants" name="fg_event_participants" rows="12" class="large-text code"><?php echo esc_textarea( $attendees ); ?></textarea>
							<span class="description"><?php esc_html_e( 'Eine Adresse pro Zeile; Komma und Semikolon werden ebenfalls unterstützt. Nur diese Adressen können Eintragungen veröffentlichen oder Kontakt aufnehmen.', 'fahrgemeinschaften' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'UUID', 'fahrgemeinschaften' ); ?></th>
						<td>
							<input type="text" class="regular-text" value="<?php echo esc_attr( $uuid ); ?>" readonly>
							<span class="description"><?php esc_html_e( 'Wird beim Anlegen einmalig erzeugt, ist nicht öffentlich und kann nicht geändert werden.', 'fahrgemeinschaften' ); ?></span>
						</td>
					</tr>
				</table>

				<?php submit_button( $is_new ? __( 'Arbeitsdienst anlegen', 'fahrgemeinschaften' ) : __( 'Änderungen speichern', 'fahrgemeinschaften' ) ); ?>
			</form>

			<?php if ( ! $is_new ) : ?>
				<h2><?php esc_html_e( 'Gefährliche Aktion', 'fahrgemeinschaften' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %d: number of associated rides. */
						esc_html( _n( 'Diesen Arbeitsdienst und %d zugehörige Fahrgemeinschaft endgültig löschen?', 'Diesen Arbeitsdienst und %d zugehörige Fahrgemeinschaften endgültig löschen?', $ride_count, 'fahrgemeinschaften' ) ),
						(int) $ride_count
					);
					?>
				</p>
				<p>
					<?php
					echo wp_kses_post( // phpcs:ignore WordPress.Security.EscapeOutput
						FG_Admin::delete_link(
							'event',
							$event->id,
							__( 'Diesen Arbeitsdienst und alle zugehörigen Fahrgemeinschaften endgültig löschen?', 'fahrgemeinschaften' )
						)
					);
					?>
				</p>
				<p>
					<a href="<?php echo esc_url( $this->list_url() ); ?>"><?php esc_html_e( 'Zurück zur Übersicht', 'fahrgemeinschaften' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Validate and store the submitted event.
	 *
	 * An invalid date is never stored. Because there is no draft state that
	 * could hide a broken date, an event that is meant to be publicly visible
	 * has to carry a valid date at save time.
	 *
	 * @return void
	 */
	public function save() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diesen Bereich.', 'fahrgemeinschaften' ) );
		}

		$event_id = isset( $_POST['fg_event_id'] ) && ! is_array( $_POST['fg_event_id'] )
			? absint( wp_unslash( $_POST['fg_event_id'] ) )
			: 0;

		check_admin_referer( 'fg_save_event_' . $event_id, 'fg_event_nonce' );

		$title        = isset( $_POST['fg_title'] ) ? sanitize_text_field( wp_unslash( $_POST['fg_title'] ) ) : '';
		$raw_date     = isset( $_POST['fg_event_date'] ) ? sanitize_text_field( wp_unslash( $_POST['fg_event_date'] ) ) : '';
		$raw_time     = isset( $_POST['fg_event_time'] ) ? sanitize_text_field( wp_unslash( $_POST['fg_event_time'] ) ) : '';
		$participants = isset( $_POST['fg_event_participants'] ) ? sanitize_textarea_field( wp_unslash( $_POST['fg_event_participants'] ) ) : '';
		$active       = isset( $_POST['fg_event_active'] ) && '1' === (string) wp_unslash( $_POST['fg_event_active'] );

		if ( '' === $title ) {
			FG_Admin::store_notice( __( 'Bitte einen Titel für den Arbeitsdienst eingeben.', 'fahrgemeinschaften' ), 'error' );
			$this->redirect_back( $event_id );
		}

		if ( ! FG_Repository::is_valid_date( $raw_date ) ) {
			FG_Admin::store_notice( __( 'Bitte ein gültiges Datum für den Arbeitsdienst eingeben. Es wurde nichts gespeichert.', 'fahrgemeinschaften' ), 'error' );
			$this->redirect_back( $event_id );
		}

		if ( '' !== $raw_time && ! FG_Repository::is_valid_time( $raw_time ) ) {
			FG_Admin::store_notice( __( 'Die Uhrzeit wurde nicht gespeichert, weil sie ungültig ist.', 'fahrgemeinschaften' ), 'error' );
			$this->redirect_back( $event_id );
		}

		$fields = array(
			'title'        => $title,
			'event_date'   => $raw_date,
			'event_time'   => $raw_time,
			'participants' => $participants,
			'is_active'    => $active,
		);

		if ( $event_id ) {
			if ( ! $this->repository->update_event( $event_id, $fields ) ) {
				FG_Admin::store_notice( __( 'Der Arbeitsdienst konnte nicht gespeichert werden.', 'fahrgemeinschaften' ), 'error' );
				$this->redirect_back( $event_id );
			}

			FG_Admin::store_notice( __( 'Der Arbeitsdienst wurde gespeichert.', 'fahrgemeinschaften' ) );
			$this->redirect_back( $event_id );
		}

		$new_id = $this->repository->insert_event( $fields );
		if ( ! $new_id ) {
			FG_Admin::store_notice( __( 'Der Arbeitsdienst konnte nicht angelegt werden.', 'fahrgemeinschaften' ), 'error' );
			$this->redirect_back( 0 );
		}

		FG_Admin::store_notice( __( 'Der Arbeitsdienst wurde angelegt.', 'fahrgemeinschaften' ) );
		$this->redirect_back( $new_id );
	}

	/**
	 * Return to the form of an event, or to the empty form.
	 *
	 * @param int $event_id Event ID.
	 * @return void
	 */
	private function redirect_back( $event_id ) {
		$url = $event_id ? $this->edit_url( $event_id ) : $this->list_url( array( 'event' => 0 ) );

		wp_safe_redirect( $url, 303 );
		exit;
	}

	/**
	 * Build the URL of the event list.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	private function list_url( array $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => FG_EVENTS_PAGE_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build the edit URL of one event.
	 *
	 * @param int $event_id Event ID.
	 * @return string
	 */
	private function edit_url( $event_id ) {
		return add_query_arg(
			array(
				'page'  => FG_EVENTS_PAGE_SLUG,
				'event' => (int) $event_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render simple previous and next links.
	 *
	 * @param int $total Total number of rows.
	 * @param int $page  Current page.
	 * @return void
	 */
	private function render_pagination( $total, $page ) {
		$pages = (int) ceil( $total / FG_Admin::PER_PAGE );
		if ( $pages < 2 ) {
			return;
		}

		echo '<p class="tablenav">';
		if ( $page > 1 ) {
			printf(
				'<a class="prev-page button" href="%s">%s</a> ',
				esc_url( add_query_arg( 'paged', $page - 1, $this->list_url() ) ),
				esc_html__( '‹ Zurück', 'fahrgemeinschaften' )
			);
		}
		printf(
			/* translators: 1: current page, 2: total pages. */
			esc_html__( 'Seite %1$d von %2$d', 'fahrgemeinschaften' ),
			(int) $page,
			(int) $pages
		);
		if ( $page < $pages ) {
			printf(
				' <a class="next-page button" href="%s">%s</a>',
				esc_url( add_query_arg( 'paged', $page + 1, $this->list_url() ) ),
				esc_html__( 'Weiter ›', 'fahrgemeinschaften' )
			);
		}
		echo '</p>';
	}
}
