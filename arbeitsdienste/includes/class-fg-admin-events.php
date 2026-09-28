<?php
/**
 * Admin screens for work-service events.
 *
 * @package Arbeitsdienste
 */

defined( 'ABSPATH' ) || exit;

/**
 * List and form screen for work-service events.
 *
 * A work service is a small record: a title, a date, an optional start time, a
 * visibility flag, and four optional details for the public list of work
 * services: group, number of people needed, length in hours and a description.
 * There is no draft state: an event either may be offered publicly or it may not.
 *
 * Who is in the duty is not a field of the duty. The members register themselves
 * on the public page, so the duty screen shows the resulting list and offers to
 * remove one entry, and it never shows a form that would type the list by hand.
 */
final class FG_Admin_Events {
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
	 * Constructor.
	 *
	 * @param FG_Repository $repository Repository.
	 * @param FG_Stats|null $stats      Optional statistics.
	 */
	public function __construct( FG_Repository $repository, FG_Stats $stats = null ) {
		$this->repository = $repository;
		$this->stats      = $stats ? $stats : new FG_Stats();

		add_action( 'admin_post_fg_save_event', array( $this, 'save' ) );
		add_action( 'admin_post_fg_assign_participant', array( $this, 'assign_participant' ) );
		add_action( 'admin_post_fg_notify_participant', array( $this, 'notify_participant' ) );
	}

	/**
	 * Render the list or the form, depending on the request.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diesen Bereich.', 'arbeitsdienste' ) );
		}

		// `event` in the query means a form is wanted: an ID opens that record,
		// the explicit 0 opens the empty form for a new one. Without the
		// parameter the overview is shown.
		if ( isset( $_GET['event'] ) && ! is_array( $_GET['event'] ) ) {
			$requested = FG_Admin::query_int( 'event' );
			$event     = $requested ? $this->repository->get_event( $requested ) : null;

			if ( $requested && ! $event ) {
				FG_Admin::store_notice( __( 'Dieser Arbeitsdienst wurde nicht gefunden.', 'arbeitsdienste' ), 'error' );
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

		// One query for the whole page. Bedarf, Teilnehmer and freie Plätze are
		// three numbers about the same duties, and asking per row would send three
		// queries per row.
		$ids    = array_map(
			static function ( FG_Event $event ) {
				return $event->id;
			},
			$events
		);
		$signed = $this->repository->get_registration_counts_for_events( $ids );
		$rides  = $this->repository->get_ride_counts_for_events( $ids );

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Arbeitsdienste', 'arbeitsdienste' ); ?></h1>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Neuer Arbeitsdienst', 'arbeitsdienste' ); ?></a>
			<hr class="wp-header-end">
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Titel', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Datum', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Gruppe', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Bedarf', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Teilnehmer', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Freie Plätze', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Öffentlich sichtbar', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Fahrgemeinschaften', 'arbeitsdienste' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $events ) ) : ?>
						<tr>
							<td colspan="8"><?php esc_html_e( 'Es wurde noch kein Arbeitsdienst angelegt.', 'arbeitsdienste' ); ?></td>
						</tr>
					<?php endif; ?>
					<?php foreach ( $events as $event ) : ?>
						<?php
						$registered = isset( $signed[ $event->id ] ) ? (int) $signed[ $event->id ] : 0;
						$free       = $this->repository->free_places( $event, $registered );
						?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( $this->edit_url( $event->id ) ); ?>"><?php echo esc_html( $event->title ); ?></a></strong>
							</td>
							<td><?php echo esc_html( $this->repository->format_event_date( $event ) ); ?></td>
							<td><?php echo esc_html( $event->group_name ); ?></td>
							<td><?php echo esc_html( $event->demand > 0 ? (string) $event->demand : '—' ); ?></td>
							<td><?php echo esc_html( (string) $registered ); ?></td>
							<td><?php echo esc_html( (string) $free ); ?></td>
							<td>
								<?php
								echo $event->is_active
									? esc_html__( 'Ja', 'arbeitsdienste' )
									: esc_html__( 'Nein', 'arbeitsdienste' );
								?>
							</td>
							<td><?php echo esc_html( (string) ( isset( $rides[ $event->id ] ) ? (int) $rides[ $event->id ] : 0 ) ); ?></td>
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
		$group    = $is_new ? '' : $event->group_name;
		$demand   = $is_new ? '' : (string) $event->demand;
		$duration = $is_new ? '' : (string) $event->duration_hours;
		$text     = $is_new ? '' : $event->description;
		$ride_count = $is_new ? 0 : $this->repository->count_event_rides( $event->id );
		$registered = $is_new ? array() : $this->repository->get_event_registrations( $event->id );
		?>
		<div class="wrap">
			<h1><?php echo $is_new
				? esc_html__( 'Neuer Arbeitsdienst', 'arbeitsdienste' )
				: esc_html__( 'Arbeitsdienst bearbeiten', 'arbeitsdienste' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="fg_save_event">
				<input type="hidden" name="fg_event_id" value="<?php echo esc_attr( $is_new ? 0 : $event->id ); ?>">
				<?php wp_nonce_field( 'fg_save_event_' . ( $is_new ? 0 : $event->id ), 'fg_event_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fg-title"><?php esc_html_e( 'Titel', 'arbeitsdienste' ); ?></label></th>
						<td>
							<input type="text" id="fg-title" name="fg_title" class="regular-text" maxlength="200" value="<?php echo esc_attr( $title ); ?>" required>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fg-date"><?php esc_html_e( 'Datum', 'arbeitsdienste' ); ?></label></th>
						<td>
							<input type="date" id="fg-date" name="fg_event_date" value="<?php echo esc_attr( $date ); ?>" required>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fg-time"><?php esc_html_e( 'Beginn (Uhrzeit, optional)', 'arbeitsdienste' ); ?></label></th>
						<td>
							<input type="time" id="fg-time" name="fg_event_time" value="<?php echo esc_attr( $time ); ?>">
							<span class="description"><?php esc_html_e( 'Ohne Uhrzeit gilt der ganze Tag. Mit Uhrzeit endet die Anmeldung zu Fahrgemeinschaften mit diesem Zeitpunkt, weil der Dienst dann beginnt.', 'arbeitsdienste' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fg-group"><?php esc_html_e( 'Gruppe', 'arbeitsdienste' ); ?></label></th>
						<td>
							<input type="text" id="fg-group" name="fg_group_name" class="regular-text" maxlength="<?php echo esc_attr( FG_Schema::GROUP_MAX ); ?>" value="<?php echo esc_attr( $group ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fg-demand"><?php esc_html_e( 'Bedarf an Personen', 'arbeitsdienste' ); ?></label></th>
						<td>
							<input type="number" id="fg-demand" name="fg_demand" class="small-text" min="0" max="<?php echo esc_attr( FG_Schema::COUNT_MAX ); ?>" step="1" value="<?php echo esc_attr( $demand ); ?>">
							<span class="description"><?php esc_html_e( 'Leer lassen, wenn der Verein keine Zahl angibt. Der Wert wird öffentlich als „8 Personen“ angezeigt.', 'arbeitsdienste' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fg-duration"><?php esc_html_e( 'Dauer in Stunden', 'arbeitsdienste' ); ?></label></th>
						<td>
							<input type="number" id="fg-duration" name="fg_duration_hours" class="small-text" min="0" max="<?php echo esc_attr( FG_Schema::COUNT_MAX ); ?>" step="1" value="<?php echo esc_attr( $duration ); ?>">
							<span class="description"><?php esc_html_e( 'Ganze Stunden, leer lassen, wenn die Dauer nicht bekannt ist.', 'arbeitsdienste' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fg-description"><?php esc_html_e( 'Beschreibung', 'arbeitsdienste' ); ?></label></th>
						<td>
							<textarea id="fg-description" name="fg_description" rows="5" class="large-text" maxlength="<?php echo esc_attr( FG_Schema::DESCRIPTION_MAX ); ?>"><?php echo esc_textarea( $text ); ?></textarea>
							<span class="description"><?php esc_html_e( 'Zeilenumbrüche bleiben auf der öffentlichen Seite erhalten. HTML ist hier nicht möglich; eine Angabe wie „<b>fett</b>“ erscheint genau so, wie sie eingegeben wurde.', 'arbeitsdienste' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fg-active"><?php esc_html_e( 'Öffentlich sichtbar', 'arbeitsdienste' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="fg-active" name="fg_event_active" value="1" <?php checked( $active ); ?>>
								<?php esc_html_e( 'Dieser Arbeitsdienst darf öffentlich angeboten werden.', 'arbeitsdienste' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'UUID', 'arbeitsdienste' ); ?></th>
						<td>
							<input type="text" class="regular-text" value="<?php echo esc_attr( $uuid ); ?>" readonly>
							<span class="description"><?php esc_html_e( 'Wird beim Anlegen einmalig erzeugt, ist nicht öffentlich und kann nicht geändert werden.', 'arbeitsdienste' ); ?></span>
						</td>
					</tr>
				</table>

				<?php submit_button( $is_new ? __( 'Arbeitsdienst anlegen', 'arbeitsdienste' ) : __( 'Änderungen speichern', 'arbeitsdienste' ) ); ?>
			</form>

			<?php if ( ! $is_new ) : ?>
				<?php $this->render_registrations( $event, $registered ); ?>
				<?php $this->render_assign_form( $event, $registered ); ?>

				<h2><?php esc_html_e( 'Gefährliche Aktion', 'arbeitsdienste' ); ?></h2>
				<p>
					<?php
					// Two counts, two nouns, and the two numbers have nothing to do
					// with each other: a duty can have one member in it and five
					// rides, or none and one. A single _n() on one of the two numbers
					// would put "1 Anmeldungen" or "1 zugehörige Fahrgemeinschaften" in
					// front of the person who has to read whether the click is safe.
					printf(
						/* translators: 1: the registrations and rides of this work service, each with its own singular and plural. */
						esc_html__( 'Diesen Arbeitsdienst, %1$s und %2$s endgültig löschen?', 'arbeitsdienste' ),
						sprintf(
							/* translators: %d: number of registered members. */
							esc_html( _n( '%d Anmeldung', '%d Anmeldungen', count( $registered ), 'arbeitsdienste' ) ),
							count( $registered )
						),
						sprintf(
							/* translators: %d: number of associated rides. */
							esc_html( _n( '%d zugehörige Fahrgemeinschaft', '%d zugehörige Fahrgemeinschaften', $ride_count, 'arbeitsdienste' ) ),
							(int) $ride_count
						)
					);
					?>
				</p>
				<p>
					<?php
					FG_Admin::echo_delete_link(
						'event',
						$event->id,
						__( 'Diesen Arbeitsdienst mit allen Anmeldungen und zugehörigen Fahrgemeinschaften endgültig löschen?', 'arbeitsdienste' )
					);
					?>
				</p>
				<p>
					<a href="<?php echo esc_url( $this->list_url() ); ?>"><?php esc_html_e( 'Zurück zur Übersicht', 'arbeitsdienste' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the form that enters a member into this work service.
	 *
	 * The search runs over number, first name, last name and address — the same
	 * question the member list asks, with the same answer, so that a name which
	 * finds somebody there finds them here. What it produces is one dropdown and
	 * one button: a member is entered one at a time, because the decision is made
	 * per member anyway. The checkbox below decides whether that member hears
	 * about it right now, and it is off, because entering somebody and writing to
	 * them are two different acts and a club that plans a duty in September and
	 * announces it in August has both orders to run.
	 *
	 * Members who are already in the duty are named under the form instead of
	 * standing in the list: a search that found somebody and then showed nothing
	 * looks like a mistake, and the editor would type the same term again.
	 *
	 * @param FG_Event $event         Work service.
	 * @param array    $registrations Rows already in the list.
	 * @return void
	 */
	private function render_assign_form( FG_Event $event, array $registrations ) {
		$search     = isset( $_GET['fg_event_member_search'] ) && ! is_array( $_GET['fg_event_member_search'] )
			? sanitize_text_field( wp_unslash( $_GET['fg_event_member_search'] ) )
			: '';
		$drin       = array();
		$wahlen     = array();
		$treffer    = 0;

		if ( '' !== trim( $search ) ) {
			$suchergebnisse = $this->repository->get_members_page( $search, 0, 50 );

			foreach ( $suchergebnisse as $gefunden ) {
				++$treffer;

				if ( $this->repository->is_event_participant( $event->id, $gefunden->id ) ) {
					$drin[] = $gefunden;
				} else {
					$wahlen[] = $gefunden;
				}
			}
		}

		$such_url = add_query_arg( 'fg_event_member_search', $search, $this->edit_url( $event->id ) );
		?>
		<h2><?php esc_html_e( 'Mitglied zuweisen', 'arbeitsdienste' ); ?></h2>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( FG_EVENTS_PAGE_SLUG ); ?>">
			<input type="hidden" name="event" value="<?php echo esc_attr( (int) $event->id ); ?>">
			<label class="screen-reader-text" for="fg-event-member-search"><?php esc_html_e( 'Mitglieder suchen', 'arbeitsdienste' ); ?></label>
			<input type="search" name="fg_event_member_search" id="fg-event-member-search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Name oder Mitgliedsnummer', 'arbeitsdienste' ); ?>">
			<?php submit_button( __( 'Suchen', 'arbeitsdienste' ), 'secondary', '', false ); ?>
		</form>

		<?php if ( '' !== trim( $search ) ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: the search term. */
					esc_html__( 'Ergebnisse für „%s“:', 'arbeitsdienste' ),
					esc_html( $search )
				);
				?>
			</p>
			<?php if ( ! $treffer ) : ?>
				<p><?php esc_html_e( 'Dazu wurde niemand gefunden. Die Suche kennt Mitgliedsnummer, Vorname, Nachname und E-Mail-Adresse.', 'arbeitsdienste' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fg_assign_participant">
			<input type="hidden" name="fg_event_id" value="<?php echo esc_attr( (int) $event->id ); ?>">
			<input type="hidden" name="fg_event_member_search" value="<?php echo esc_attr( $search ); ?>">
			<?php wp_nonce_field( 'fg_assign_participant_' . (int) $event->id, 'fg_assign_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fg-assign-member"><?php esc_html_e( 'Mitglied', 'arbeitsdienste' ); ?></label></th>
					<td>
						<?php if ( ! $wahlen ) : ?>
							<span class="description"><?php esc_html_e( 'Ohne Suchergebnis gibt es hier nichts auszuwählen.', 'arbeitsdienste' ); ?></span>
						<?php else : ?>
							<select id="fg-assign-member" name="fg_member_id" required>
								<option value=""><?php esc_html_e( 'Bitte auswählen', 'arbeitsdienste' ); ?></option>
								<?php foreach ( $wahlen as $gefunden ) : ?>
									<option value="<?php echo esc_attr( (int) $gefunden->id ); ?>">
										<?php
										printf(
											/* translators: 1: member number, 2: name, 3: e-mail address. */
											esc_html__( '%1$s — %2$s (%3$s)', 'arbeitsdienste' ),
											esc_html( $gefunden->member_no ),
											esc_html( trim( $gefunden->first_name . ' ' . $gefunden->last_name ) ),
											esc_html( $gefunden->email )
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'E-Mail', 'arbeitsdienste' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="fg-notify-member" name="fg_notify_member" value="1">
							<?php esc_html_e( 'Dieses Mitglied jetzt benachrichtigen. Ohne dieses Häkchen geht keine E-Mail raus, und der Zähler in der Liste bleibt stehen.', 'arbeitsdienste' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Zuweisen', 'arbeitsdienste' ), 'primary', 'fg_assign_submit', false ); ?>
		</form>

		<?php if ( $drin ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: number of members, 2: the list of them. */
					esc_html__( 'Bereits für diesen Arbeitsdienst eingetragen (%1$d): %2$s', 'arbeitsdienste' ),
					count( $drin ),
					esc_html(
						implode(
							', ',
							array_map(
								static function ( FG_Member $mitglied ) {
									return $mitglied->member_no . ' ' . trim( $mitglied->first_name . ' ' . $mitglied->last_name );
								},
								$drin
							)
						)
					)
				);
				?>
			</p>
		<?php endif; ?>
		<p class="description">
			<?php esc_html_e( 'Der Arbeitsdienst kann dabei mehr Mitglieder bekommen, als der Bedarf ankündigt. Auf der öffentlichen Seite ist er dann als vollständig belegt zu sehen.', 'arbeitsdienste' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the members registered for one work service.
	 *
	 * This list is the answer to the question the duty asks: how many people it
	 * still needs. It is shown after the save button and not inside the form,
	 * because it is not part of what the form writes. A member appears in it
	 * because they registered themselves with their member number, and the only
	 * thing that can be changed from here is the removal of one entry, which
	 * leaves the member in the club and only frees the place in this duty.
	 *
	 * @param FG_Event $event         Work service.
	 * @param array    $registrations Rows from the repository.
	 * @return void
	 */
	private function render_registrations( FG_Event $event, array $registrations ) {
		$count        = count( $registrations );
		$ueber_bedarf = (int) $event->demand > 0 && $count > (int) $event->demand;
		?>
		<h2><?php esc_html_e( 'Angemeldete Mitglieder', 'arbeitsdienste' ); ?></h2>
		<?php if ( 0 === (int) $event->demand ) : ?>
			<p class="description">
				<?php esc_html_e( 'Für diesen Arbeitsdienst ist kein Bedarf eingetragen. Deshalb steht unter der öffentlichen Seite keine Anmeldung zur Verfügung. Vom Redakteur eingetragene Mitglieder stehen trotzdem in dieser Liste.', 'arbeitsdienste' ); ?>
			</p>
		<?php endif; ?>
		<?php if ( $ueber_bedarf ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					// The demand is what the club announces to its members, not a
					// limit for the editor: an editor who enters a thirteenth member
					// into a duty for twelve knows something the number does not. The
					// sentence says what the public page makes of it, because that is
					// what the editor cannot see from here.
					printf(
						/* translators: 1: number of registered members, 2: number of announced places. */
						esc_html__( '%1$d Mitglieder sind für %2$d angekündigte Plätze eingetragen. Auf der öffentlichen Seite ist dieser Arbeitsdienst damit als vollständig belegt zu sehen.', 'arbeitsdienste' ),
						(int) $count,
						(int) $event->demand
					);
					?>
				</p>
			</div>
		<?php endif; ?>
		<?php if ( 0 === $count ) : ?>
			<p><?php esc_html_e( 'Für diesen Arbeitsdienst hat sich noch kein Mitglied eingetragen.', 'arbeitsdienste' ); ?></p>
		<?php else : ?>
			<p>
				<?php
				if ( $ueber_bedarf ) {
					printf(
						/* translators: %d: number of registered members. */
						esc_html__( '%d Mitglieder eingetragen.', 'arbeitsdienste' ),
						(int) $count
					);
				} else {
					printf(
						/* translators: 1: number of registered members, 2: number of free places. */
						esc_html__( '%1$d von höchstens %2$d Plätzen belegt.', 'arbeitsdienste' ),
						(int) $count,
						(int) $event->demand
					);
				}
				?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Mitgliedsnummer', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Name', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'E-Mail-Adresse', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Angemeldet am', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Eingetragen von', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'E-Mails', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Aktion', 'arbeitsdienste' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $registrations as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['member']->member_no ); ?></td>
							<td><?php echo esc_html( trim( $row['member']->first_name . ' ' . $row['member']->last_name ) ); ?></td>
							<td><?php echo esc_html( $row['member']->email ); ?></td>
							<td><?php echo esc_html( $row['registration']->registered_at ); ?></td>
							<td>
								<?php
								// Who put this member in this duty decides how the number
								// beside it is to be read: a member who signed up has
								// heard about it, a member an editor entered has not.
								echo esc_html(
									$row['registration']->added_by_admin
										? __( 'Redaktion', 'arbeitsdienste' )
										: __( 'Mitglied selbst', 'arbeitsdienste' )
								);
								?>
							</td>
							<td>
								<?php echo esc_html( number_format_i18n( (int) $row['registration']->notified_count ) ); ?>
							</td>
							<td>
								<?php $this->render_notify_button( $row ); ?>
								<?php
								FG_Admin::echo_delete_link(
									'registration',
									$row['registration']->id,
									sprintf(
										/* translators: %s: member number. */
										__( 'Die Anmeldung von Mitglied %s für diesen Arbeitsdienst löschen?', 'arbeitsdienste' ),
										$row['member']->member_no
									)
								);
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php
				printf(
					/* translators: %s: the wording of the counter column. */
					esc_html__( 'Die Spalte E-Mails zählt, wie oft dieses Mitglied für diesen Arbeitsdienst eine E-Mail bekommen hat (%s). Ein Versand, der nicht geklappt hat, zählt nicht mit.', 'arbeitsdienste' ),
					esc_html__( 'nur zugestellte', 'arbeitsdienste' )
				);
				?>
			</p>
			<p class="description"><?php esc_html_e( 'Das Löschen einer Anmeldung gibt nur den Platz in diesem Arbeitsdienst frei. Das Mitglied bleibt im Verein und für andere Arbeitsdienste angemeldet.', 'arbeitsdienste' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the form that sends the duty mail to one member again.
	 *
	 * A form and not a link: sending a mail is something one does, and a link
	 * that sends as soon as it is fetched would also do it when something walks
	 * the admin page. The nonce carries the registration, so a form that belongs
	 * to one member cannot send a mail for another one.
	 *
	 * @param array{registration: FG_Event_Member, member: FG_Member} $row One row of the list.
	 * @return void
	 */
	private function render_notify_button( array $row ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fg_notify_participant">
			<input type="hidden" name="fg_event_id" value="<?php echo esc_attr( (int) $row['registration']->event_id ); ?>">
			<input type="hidden" name="fg_registration_id" value="<?php echo esc_attr( (int) $row['registration']->id ); ?>">
			<?php wp_nonce_field( 'fg_notify_participant_' . (int) $row['registration']->id, 'fg_notify_nonce' ); ?>
			<button type="submit" class="button">
				<?php esc_html_e( 'Benachrichtigung senden', 'arbeitsdienste' ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Enter a member into this work service from the backend.
	 *
	 * Two things are different from the public form, and both of them are the
	 * point of this screen. The mail is optional: an editor often knows the duty
	 * before the club has said anything about it, and entering a member without
	 * telling them is a normal way to fill a list. And a mail that does not go
	 * out changes nothing here — on the public side a registration without its
	 * mail is removed again, because there the member has no other way out. Here
	 * the entry stays, the counter stays where it was, and the notice names the
	 * failure, so the editor can try again.
	 *
	 * @return void
	 */
	public function assign_participant() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diesen Bereich.', 'arbeitsdienste' ) );
		}

		$event_id = $this->post_int( 'fg_event_id' );
		check_admin_referer( 'fg_assign_participant_' . $event_id, 'fg_assign_nonce' );

		$search   = $this->post_text( 'fg_event_member_search' );
		$event    = $event_id ? $this->repository->get_event( $event_id ) : null;
		$member   = $this->post_int( 'fg_member_id' ) ? $this->repository->get_member( $this->post_int( 'fg_member_id' ) ) : null;
		$notify   = '1' === $this->post_text( 'fg_notify_member' );

		if ( ! $event || ! $member ) {
			FG_Admin::store_notice( __( 'Arbeitsdienst oder Mitglied nicht gefunden. Es wurde niemand eingetragen.', 'arbeitsdienste' ), 'error' );
			$this->redirect_back( $event_id, $search );
		}

		if ( $this->repository->has_registration( $event->id, $member->id ) ) {
			FG_Admin::store_notice(
				sprintf(
					/* translators: 1: member number, 2: name of the member. */
					esc_html__( 'Mitglied %1$s (%2$s) ist für diesen Arbeitsdienst schon eingetragen.', 'arbeitsdienste' ),
					esc_html( $member->member_no ),
					esc_html( trim( $member->first_name . ' ' . $member->last_name ) )
				),
				'warning'
			);
			$this->redirect_back( $event_id, $search );
		}

		$registration = $this->repository->create_registration(
			$event->id,
			$member->id,
			'',
			true
		);

		if ( ! $registration['id'] ) {
			FG_Admin::store_notice( __( 'Die Anmeldung konnte nicht gespeichert werden.', 'arbeitsdienste' ), 'error' );
			$this->redirect_back( $event_id, $search );
		}

		$this->stats->increment( 'admin_participant_added' );

		if ( ! $notify ) {
			// The token that came out of the registration belongs to a mail that
			// is not going out, and a token nobody ever received is a row that says
			// nothing. It goes, and the notification that may come later gets one of
			// its own. A row in that table then means a mail and nothing else.
			$this->repository->clear_unregister_token( $registration['id'] );

			FG_Admin::store_notice(
				sprintf(
					/* translators: 1: member number, 2: name of the member. */
					esc_html__( 'Mitglied %1$s (%2$s) ist eingetragen. Es ist keine E-Mail verschickt worden.', 'arbeitsdienste' ),
					esc_html( $member->member_no ),
					esc_html( trim( $member->first_name . ' ' . $member->last_name ) )
				)
			);
			$this->redirect_back( $event_id, $search );
		}

		$this->notify_one_participant( $event, $registration );
		$this->redirect_back( $event_id, $search );
	}

	/**
	 * Send the duty mail to one member of this duty again.
	 *
	 * The link in the new mail is one of its own: a token is only ever readable
	 * in the mail it was written for, and every mail that goes out gets a token
	 * of its own. The links already sent keep working until they expire.
	 *
	 * @return void
	 */
	public function notify_participant() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diesen Bereich.', 'arbeitsdienste' ) );
		}

		$registration_id = $this->post_int( 'fg_registration_id' );
		check_admin_referer( 'fg_notify_participant_' . $registration_id, 'fg_notify_nonce' );

		$registration = $registration_id ? $this->repository->get_registration( $registration_id ) : null;
		$event        = $registration ? $this->repository->get_event( $registration->event_id ) : null;
		$event_id     = $event ? $event->id : 0;

		// The posted event id is not trusted for anything: the registration names
		// its own duty, and a nonce for one registration must not send a mail for
		// another one.
		$eingetragen = $this->post_int( 'fg_event_id' );
		if ( $event && $eingetragen && (int) $eingetragen !== (int) $event->id ) {
			$event = null;
		}

		if ( ! $registration || ! $event ) {
			FG_Admin::store_notice( __( 'Diese Anmeldung wurde nicht gefunden. Es ist keine E-Mail verschickt worden.', 'arbeitsdienste' ), 'error' );
			$this->redirect_back( $event_id );
		}

		$this->notify_one_participant(
			$event,
			array(
				'id'               => (int) $registration->id,
				'public_ref'       => (string) $registration->public_ref,
				'unregister_token' => '',
			)
		);
		$this->redirect_back( $event->id );
	}

	/**
	 * Send the duty mail to one member and report what happened.
	 *
	 * The token in the mail is the one the registration handed out, if there was
	 * one that has not been used yet; otherwise a new one is issued, because a
	 * token is readable only in the mail it was written for. The tokens that went
	 * out before are not touched, so their links keep working.
	 *
	 * @param FG_Event $event        Work service.
	 * @param array{id: int, public_ref: string, unregister_token: string} $registration
	 *                              What create_registration() returned, or the
	 *                              same three values with an empty token.
	 * @return void
	 */
	private function notify_one_participant( FG_Event $event, array $registration ) {
		$token = (string) $registration['unregister_token'];

		if ( '' === $token ) {
			$token = $this->repository->unregister_token_for_registration( (int) $registration['id'] );
		}

		if ( '' === $token ) {
			FG_Admin::store_notice( __( 'Der Abmeldelink für die Mail konnte nicht erzeugt werden. Es ist keine E-Mail verschickt worden.', 'arbeitsdienste' ), 'error' );
			return;
		}

		$url = add_query_arg(
			array(
				'fg_duty_action' => 'view',
				'signup_ref'     => (string) $registration['public_ref'],
				'intent'         => 'unregister',
				'token'          => $token,
			),
			home_url( '/' )
		);

		$mailer = new FG_Mailer( $this->repository );

		if ( ! $mailer->send_duty_signup( (int) $registration['id'], $url ) ) {
			// The token went into a mail that did not arrive, and a row that
			// claims a link was sent out when it was not is a wrong statement in
			// the table. Only this one token goes; the links of the mails that did
			// arrive keep working, and that is the whole point of having more than
			// one.
			$this->repository->drop_unregister_token( (int) $registration['id'], $token );

			FG_Admin::store_notice( __( 'Die E-Mail konnte nicht verschickt werden. Die Anmeldung steht weiter in der Liste und kann später erneut versendet werden.', 'arbeitsdienste' ), 'error' );
			return;
		}

		$this->stats->increment( 'admin_participant_notified' );
		FG_Admin::store_notice( __( 'Die E-Mail ist verschickt worden. Der Zähler in der Teilnehmerliste ist um eins gestiegen.', 'arbeitsdienste' ) );
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
			wp_die( esc_html__( 'Du hast keine Berechtigung für diesen Bereich.', 'arbeitsdienste' ) );
		}

		$event_id = isset( $_POST['fg_event_id'] ) && ! is_array( $_POST['fg_event_id'] )
			? absint( wp_unslash( $_POST['fg_event_id'] ) )
			: 0;

		check_admin_referer( 'fg_save_event_' . $event_id, 'fg_event_nonce' );

		$title        = isset( $_POST['fg_title'] ) ? sanitize_text_field( wp_unslash( $_POST['fg_title'] ) ) : '';
		$raw_date     = isset( $_POST['fg_event_date'] ) ? sanitize_text_field( wp_unslash( $_POST['fg_event_date'] ) ) : '';
		$raw_time     = isset( $_POST['fg_event_time'] ) ? sanitize_text_field( wp_unslash( $_POST['fg_event_time'] ) ) : '';
		$active       = isset( $_POST['fg_event_active'] ) && '1' === (string) wp_unslash( $_POST['fg_event_active'] );
		$group        = isset( $_POST['fg_group_name'] ) ? str_replace( "\n", ' ', $this->read_text( wp_unslash( $_POST['fg_group_name'] ) ) ) : '';
		$raw_demand   = isset( $_POST['fg_demand'] ) ? sanitize_text_field( wp_unslash( $_POST['fg_demand'] ) ) : '';
		$raw_duration = isset( $_POST['fg_duration_hours'] ) ? sanitize_text_field( wp_unslash( $_POST['fg_duration_hours'] ) ) : '';
		$text         = isset( $_POST['fg_description'] ) ? $this->read_text( wp_unslash( $_POST['fg_description'] ) ) : '';

		if ( '' === $title ) {
			FG_Admin::store_notice( __( 'Bitte einen Titel für den Arbeitsdienst eingeben.', 'arbeitsdienste' ), 'error' );
			$this->redirect_back( $event_id );
		}

		if ( ! FG_Repository::is_valid_date( $raw_date ) ) {
			FG_Admin::store_notice( __( 'Bitte ein gültiges Datum für den Arbeitsdienst eingeben. Es wurde nichts gespeichert.', 'arbeitsdienste' ), 'error' );
			$this->redirect_back( $event_id );
		}

		if ( '' !== $raw_time && ! FG_Repository::is_valid_time( $raw_time ) ) {
			FG_Admin::store_notice( __( 'Die Uhrzeit wurde nicht gespeichert, weil sie ungültig ist.', 'arbeitsdienste' ), 'error' );
			$this->redirect_back( $event_id );
		}

		// The browser stops a long text in the field, but a post does not have
		// to come from the form. Nothing is cut off silently here either: the
		// field is named and the save is refused, so what the club typed is
		// either stored whole or not at all.
		foreach ( $this->text_fields_over_limit( $group, $text ) as $too_long ) {
			FG_Admin::store_notice(
				sprintf(
					/* translators: 1: field label, 2: number of characters allowed. */
					__( '%1$s ist zu lang, es sind höchstens %2$s Zeichen erlaubt. Es wurde nichts gespeichert.', 'arbeitsdienste' ),
					$too_long['label'],
					$too_long['limit']
				),
				'error'
			);
			$this->redirect_back( $event_id );
		}

		$demand   = $this->count_or_error( $raw_demand, __( 'Bedarf an Personen', 'arbeitsdienste' ), $event_id );
		$duration = $this->count_or_error( $raw_duration, __( 'Dauer', 'arbeitsdienste' ), $event_id );

		$fields = array(
			'title'          => $title,
			'event_date'     => $raw_date,
			'event_time'     => $raw_time,
			'is_active'      => $active,
			'group_name'     => $group,
			'demand'         => $demand,
			'duration_hours' => $duration,
			'description'    => $text,
		);

		if ( $event_id ) {
			if ( ! $this->repository->update_event( $event_id, $fields ) ) {
				FG_Admin::store_notice( __( 'Der Arbeitsdienst konnte nicht gespeichert werden.', 'arbeitsdienste' ), 'error' );
				$this->redirect_back( $event_id );
			}

			FG_Admin::store_notice( __( 'Der Arbeitsdienst wurde gespeichert.', 'arbeitsdienste' ) );
			$this->redirect_back( $event_id );
		}

		$new_id = $this->repository->insert_event( $fields );
		if ( ! $new_id ) {
			FG_Admin::store_notice( __( 'Der Arbeitsdienst konnte nicht angelegt werden.', 'arbeitsdienste' ), 'error' );
			$this->redirect_back( 0 );
		}

		FG_Admin::store_notice( __( 'Der Arbeitsdienst wurde angelegt.', 'arbeitsdienste' ) );
		$this->redirect_back( $new_id );
	}

	/**
	 * Name every submitted text field that is longer than its column allows.
	 *
	 * @param string $group Submitted group text.
	 * @param string $text  Submitted description.
	 * @return array List of fields to complain about, empty when all of them fit.
	 */
	private function text_fields_over_limit( $group, $text ) {
		$felder = array(
			array(
				'label' => __( 'Gruppe', 'arbeitsdienste' ),
				'wert'  => $group,
				'limit' => FG_Schema::GROUP_MAX,
			),
			array(
				'label' => __( 'Beschreibung', 'arbeitsdienste' ),
				'wert'  => $text,
				'limit' => FG_Schema::DESCRIPTION_MAX,
			),
		);

		$zu_lang = array();
		foreach ( $felder as $feld ) {
			if ( $this->string_length( $feld['wert'] ) > $feld['limit'] ) {
				$zu_lang[] = array(
					'label' => $feld['label'],
					'limit' => $feld['limit'],
				);
			}
		}

		return $zu_lang;
	}

	/**
	 * Turn a submitted number into a count, or refuse the save.
	 *
	 * An empty field is not an error: it means the club states no number. A
	 * value that is not a plain count is refused rather than repaired, so a
	 * mistyped number never becomes a different number in public.
	 *
	 * @param string $raw      Submitted value.
	 * @param string $label    Field label for the message.
	 * @param int    $event_id Record to return to.
	 * @return int
	 */
	private function count_or_error( $raw, $label, $event_id ) {
		$wert = trim( $raw );

		if ( '' === $wert ) {
			return 0;
		}

		if ( ! preg_match( '/^[0-9]+$/', $wert ) || (int) $wert > FG_Schema::COUNT_MAX ) {
			FG_Admin::store_notice(
				sprintf(
					/* translators: 1: field label, 2: largest accepted number. */
					__( '%1$s muss eine ganze Zahl ohne Vorzeichen bis %2$s sein. Es wurde nichts gespeichert.', 'arbeitsdienste' ),
					$label,
					FG_Schema::COUNT_MAX
				),
				'error'
			);
			$this->redirect_back( $event_id );
		}

		return (int) $wert;
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
	 * Read a submitted text the way it was written.
	 *
	 * Nothing is filtered out of these two fields. A text field holds text and
	 * nothing else, and the only thing that decides what becomes a tag is the
	 * one that writes the page: the card escapes what it prints. Filtering here
	 * as well would cost the club its own words without making the page any
	 * safer — a sentence like "unter 5 Euro" or a size written as "< 2 m" would
	 * come back changed, and in the case of the group it would come back
	 * changed twice, because a filter that writes "&lt;" and a page that
	 * escapes again show "&amp;lt;".
	 *
	 * A browser sends the lines of a textarea separated by CRLF. Only that is
	 * straightened out here, so the text has one and the same form whether it
	 * came from Windows, from a phone or from a script.
	 *
	 * The order of the two replacements is the whole point, and the wrong order
	 * was in here until version 1.19.0: `str_replace( "\r", "\n", … )` turns
	 * every CRLF into two line breaks, because it replaces the CR and leaves the
	 * LF of the pair alone. A description with three lines then had five, the
	 * next save eight, the next sixteen — the text grew at every save and the
	 * public page showed the empty lines, because it prints the description with
	 * nl2br(). What the older tests did not notice is that they sent a bare LF,
	 * which is a payload no browser produces.
	 *
	 * @param string $value Submitted text.
	 * @return string
	 */
	private function read_text( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return trim( str_replace( array( "\r\n", "\r" ), "\n", $value ) );
	}

	/**
	 * Return to the form of an event, or to the empty form.
	 *
	 * @param int $event_id Event ID.
	 * @return void
	 */
	private function redirect_back( $event_id, $search = '' ) {
		$args = array();

		// The search term goes along on the way back. Without it the list of hits
		// is gone after the one click that used it, and an editor who entered ten
		// members would have to type the same term ten times.
		if ( '' !== $search ) {
			$args['fg_event_member_search'] = $search;
		}

		$url = $event_id
			? add_query_arg( $args, $this->edit_url( $event_id ) )
			: $this->list_url( array( 'event' => 0 ) );

		wp_safe_redirect( $url, 303 );
		exit;
	}

	/**
	 * Read one integer field of the last POST.
	 *
	 * Three handlers read the same five fields, and each of them asks for the
	 * same three things about them: that the value is there, that it is a scalar
	 * and that it is an integer. That question is asked once here.
	 *
	 * @param string $key Field name.
	 * @return int
	 */
	private function post_int( $key ) {
		return isset( $_POST[ $key ] ) && ! is_array( $_POST[ $key ] )
			? absint( wp_unslash( $_POST[ $key ] ) )
			: 0;
	}

	/**
	 * Read one text field of the last POST.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private function post_text( $key ) {
		return isset( $_POST[ $key ] ) && ! is_array( $_POST[ $key ] )
			? sanitize_text_field( wp_unslash( $_POST[ $key ] ) )
			: '';
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
