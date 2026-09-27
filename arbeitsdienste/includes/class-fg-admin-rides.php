<?php
/**
 * Admin screens for rides.
 *
 * @package Arbeitsdienste
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read-only list and detail screen for rides.
 *
 * A ride is created by a member through the public form and can only be
 * published or withdrawn by the person who created it, through the link in the
 * confirmation mail. That is why there is no edit form here: the plugin offers
 * an administrator no way to rewrite what someone else published, only to view
 * it and to delete it.
 */
final class FG_Admin_Rides {
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
	 * Render the detail screen or the list, depending on the request.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diesen Bereich.', 'arbeitsdienste' ) );
		}

		$ride = $this->repository->get_ride( FG_Admin::query_int( 'ride' ) );

		if ( $ride ) {
			$this->render_detail( $ride );
			return;
		}

		$this->render_list();
	}

	/**
	 * Render the list of all rides.
	 *
	 * @return void
	 */
	private function render_list() {
		$filters = $this->filters();
		$total   = $this->repository->count_rides( $filters );
		$page    = max( 1, FG_Admin::query_int( 'paged' ) );
		$rides   = $this->repository->get_rides_page( $filters, ( $page - 1 ) * FG_Admin::PER_PAGE, FG_Admin::PER_PAGE );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Fahrgemeinschaften', 'arbeitsdienste' ); ?></h1>
			<p><?php esc_html_e( 'Eintragungen werden von den Beteiligten selbst bestätigt oder zurückgezogen. Hier lassen sie sich nur ansehen und löschen.', 'arbeitsdienste' ); ?></p>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( FG_RIDES_PAGE_SLUG ); ?>">
				<label class="screen-reader-text" for="fg-event-filter"><?php esc_html_e( 'Nach Arbeitsdienst filtern', 'arbeitsdienste' ); ?></label>
				<select name="fg_event_filter" id="fg-event-filter">
					<option value="0"><?php esc_html_e( 'Alle Arbeitsdienste', 'arbeitsdienste' ); ?></option>
					<?php foreach ( $this->repository->get_all_events() as $event ) : ?>
						<option value="<?php echo esc_attr( (string) $event->id ); ?>" <?php selected( $filters['event_id'], $event->id ); ?>>
							<?php echo esc_html( $event->title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="fg-status-filter"><?php esc_html_e( 'Nach Status filtern', 'arbeitsdienste' ); ?></label>
				<select name="fg_status_filter" id="fg-status-filter">
					<option value=""><?php esc_html_e( 'Alle Status', 'arbeitsdienste' ); ?></option>
					<option value="<?php echo esc_attr( FG_RIDE_STATUS_PUBLISHED ); ?>" <?php selected( $filters['status'], FG_RIDE_STATUS_PUBLISHED ); ?>><?php esc_html_e( 'Veröffentlicht', 'arbeitsdienste' ); ?></option>
					<option value="<?php echo esc_attr( FG_RIDE_STATUS_PENDING ); ?>" <?php selected( $filters['status'], FG_RIDE_STATUS_PENDING ); ?>><?php esc_html_e( 'Vorgemerkt', 'arbeitsdienste' ); ?></option>
				</select>
				<?php submit_button( __( 'Filtern', 'arbeitsdienste' ), 'secondary', '', false ); ?>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Vorname oder Spitzname', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Art', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Arbeitsdienst', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Abfahrtsbereich', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Kontakt-E-Mail', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Status', 'arbeitsdienste' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rides ) ) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'Es liegen keine Eintragungen vor.', 'arbeitsdienste' ); ?></td>
						</tr>
					<?php endif; ?>
					<?php foreach ( $rides as $ride ) : ?>
						<?php $data = $this->repository->get_ride_display_data( $ride ); ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $this->detail_url( $ride->id ) ); ?>"><strong><?php echo esc_html( $ride->alias ); ?></strong></a>
							</td>
							<td><?php echo esc_html( $this->mode_label( $ride->mode ) ); ?></td>
							<td><?php echo esc_html( $data['event_label'] ); ?></td>
							<td><?php echo esc_html( $ride->origin ); ?></td>
							<td><?php echo esc_html( $ride->contact_email ); ?></td>
							<td><?php echo esc_html( $this->status_label( $ride->status ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php $this->render_pagination( $total, $page ); ?>
		</div>
		<?php
	}

	/**
	 * Render the read-only detail screen of one ride.
	 *
	 * @param FG_Ride $ride Ride record.
	 * @return void
	 */
	private function render_detail( FG_Ride $ride ) {
		$data = $this->repository->get_ride_display_data( $ride );

		$rows = array(
			array( __( 'Status', 'arbeitsdienste' ), $this->status_label( $ride->status ) ),
			array( __( 'Art', 'arbeitsdienste' ), $this->mode_label( $ride->mode ) ),
			array( __( 'Vorname oder Spitzname', 'arbeitsdienste' ), $ride->alias ),
			array( __( 'Arbeitsdienst', 'arbeitsdienste' ), $data['event_label'] ),
			array( __( 'Datum', 'arbeitsdienste' ), $data['event_date'] ),
			array( __( 'Abfahrtsbereich', 'arbeitsdienste' ), $ride->origin ),
			array( __( 'Kontakt-E-Mail', 'arbeitsdienste' ), $ride->contact_email ),
			array( __( 'Angemeldet am', 'arbeitsdienste' ), $ride->created_at ),
			array( __( 'Bestätigt am', 'arbeitsdienste' ), '' !== $ride->confirmed_at ? $ride->confirmed_at : __( 'noch nicht', 'arbeitsdienste' ) ),
			array( __( 'Einwilligung', 'arbeitsdienste' ), $ride->consent_version . ' (' . $ride->consented_at . ')' ),
			array( __( 'Öffentliche Referenz', 'arbeitsdienste' ), $ride->public_ref ),
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $ride->alias ); ?></h1>
			<table class="widefat striped">
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $row[0] ); ?></th>
							<td><?php echo esc_html( $row[1] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Gefährliche Aktion', 'arbeitsdienste' ); ?></h2>
			<p>
				<?php
				FG_Admin::echo_delete_link(
					'ride',
					$ride->id,
					__( 'Diese Fahrgemeinschaft endgültig löschen?', 'arbeitsdienste' )
				);
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( $this->list_url() ); ?>"><?php esc_html_e( 'Zurück zur Übersicht', 'arbeitsdienste' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Collect the validated list filters from the request.
	 *
	 * @return array{event_id: int, status: string}
	 */
	private function filters() {
		$status = FG_Admin::query_key( 'fg_status_filter' );

		return array(
			'event_id' => FG_Admin::query_int( 'fg_event_filter' ),
			'status'   => in_array( $status, array( FG_RIDE_STATUS_PENDING, FG_RIDE_STATUS_PUBLISHED ), true ) ? $status : '',
		);
	}

	/**
	 * Human-readable status.
	 *
	 * @param string $status Stored status.
	 * @return string
	 */
	private function status_label( $status ) {
		if ( FG_RIDE_STATUS_PUBLISHED === $status ) {
			return __( 'Veröffentlicht', 'arbeitsdienste' );
		}

		if ( FG_RIDE_STATUS_PENDING === $status ) {
			return __( 'Vorgemerkt', 'arbeitsdienste' );
		}

		return (string) $status;
	}

	/**
	 * Human-readable mode.
	 *
	 * @param string $mode Stored mode.
	 * @return string
	 */
	private function mode_label( $mode ) {
		return FG_RIDE_MODE_SEARCH === $mode
			? __( 'Ich suche', 'arbeitsdienste' )
			: __( 'Ich biete', 'arbeitsdienste' );
	}

	/**
	 * Build the URL of the ride list.
	 *
	 * @return string
	 */
	private function list_url() {
		return add_query_arg(
			array( 'page' => FG_RIDES_PAGE_SLUG ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build the detail URL of one ride.
	 *
	 * @param int $ride_id Ride ID.
	 * @return string
	 */
	private function detail_url( $ride_id ) {
		return add_query_arg(
			array(
				'page' => FG_RIDES_PAGE_SLUG,
				'ride' => (int) $ride_id,
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
				esc_html__( '‹ Zurück', 'arbeitsdienste' )
			);
		}
		printf(
			/* translators: 1: current page, 2: total pages. */
			esc_html__( 'Seite %1$d von %2$d', 'arbeitsdienste' ),
			(int) $page,
			(int) $pages
		);
		if ( $page < $pages ) {
			printf(
				' <a class="next-page button" href="%s">%s</a>',
				esc_url( add_query_arg( 'paged', $page + 1, $this->list_url() ) ),
				esc_html__( 'Weiter ›', 'arbeitsdienste' )
			);
		}
		echo '</p>';
	}
}
