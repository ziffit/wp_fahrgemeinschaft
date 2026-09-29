<?php
/**
 * Admin screens for members.
 *
 * @package Arbeitsdienste
 */

defined( 'ABSPATH' ) || exit;

/**
 * List, form and import screen for the club's members.
 *
 * A member is four fields: member number, e-mail address, first name and last
 * name. The member number is the key, the address is unique, and the two
 * together are what a member types on the public page to sign in for a work
 * service.
 *
 * The list can be filled by hand or by importing the export of the club's member
 * administration. An import never deletes: a member whose number is missing from
 * the file is only listed in the report, because a file that is a week old must
 * not empty the member list.
 *
 * The same screen carries the one deletion that acts on a group: it removes every
 * member who is not registered for a work service, which is what clears the list
 * after a test or before a fresh import. It is a link that names how many members
 * it would take and shows them, and only the second click deletes. Who is
 * registered stays, and so does every ride: a ride is its own entry with its own
 * contact address and was never owned by the member record.
 */
final class FG_Admin_Members {
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

		add_action( 'admin_post_fg_save_member', array( $this, 'save' ) );
		add_action( 'admin_post_fg_import_members', array( $this, 'import' ) );
		add_action( 'admin_post_fg_prune_members', array( $this, 'prune' ) );
	}

	/**
	 * Render the list, the form or the import report, depending on the request.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diesen Bereich.', 'arbeitsdienste' ) );
		}

		// The first step of the group deletion only reads and only names what it
		// would take. It is a link and not a form because nothing is changed by
		// following it, and a link that a browser or a proxy fetches by itself
		// must never delete anything.
		if ( isset( $_GET['fg_prune'] ) && 'ask' === FG_Admin::query_key( 'fg_prune' ) ) {
			$this->render_prune();
			return;
		}

		// `member` in the query means a form is wanted: an ID opens that record,
		// the explicit 0 opens the empty form for a new one.
		if ( isset( $_GET['member'] ) && ! is_array( $_GET['member'] ) ) {
			$requested = FG_Admin::query_int( 'member' );
			$member    = $requested ? $this->repository->get_member( $requested ) : null;

			if ( $requested && ! $member ) {
				FG_Admin::store_notice( __( 'Dieses Mitglied wurde nicht gefunden.', 'arbeitsdienste' ), 'error' );
			}

			$this->render_form( $member );
			return;
		}

		$this->render_list();
	}

	/**
	 * Render the list of members.
	 *
	 * @return void
	 */
	private function render_list() {
		$search = isset( $_GET['fg_member_search'] ) && ! is_array( $_GET['fg_member_search'] )
			? sanitize_text_field( wp_unslash( $_GET['fg_member_search'] ) )
			: '';

		$total   = $this->repository->count_members( $search );
		$page    = max( 1, FG_Admin::query_int( 'paged' ) );
		$members = $this->repository->get_members_page( $search, ( $page - 1 ) * FG_Admin::PER_PAGE, FG_Admin::PER_PAGE );
		$counts  = $this->repository->get_registration_counts_for_members( $members );
		$new_url = add_query_arg(
			array(
				'page'   => FG_MEMBERS_PAGE_SLUG,
				'member' => 0,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Mitglieder', 'arbeitsdienste' ); ?></h1>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Neues Mitglied', 'arbeitsdienste' ); ?></a>
			<hr class="wp-header-end">

			<p><?php esc_html_e( 'Diese Liste ist die Grundlage der Anmeldung zu Arbeitsdiensten. Sie wird am besten aus dem Export der Mitgliederverwaltung eingelesen; einzelne Mitglieder können auch von Hand angelegt werden.', 'arbeitsdienste' ); ?></p>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( FG_MEMBERS_PAGE_SLUG ); ?>">
				<label class="screen-reader-text" for="fg-member-search"><?php esc_html_e( 'Mitglieder suchen', 'arbeitsdienste' ); ?></label>
				<input type="search" name="fg_member_search" id="fg-member-search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Nummer, Name oder E-Mail', 'arbeitsdienste' ); ?>">
				<?php submit_button( __( 'Suchen', 'arbeitsdienste' ), 'secondary', '', false ); ?>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Mitgliedsnummer', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Vorname', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Nachname', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Arbeitsgruppe', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'E-Mail-Adresse', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Angemeldete Dienste', 'arbeitsdienste' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $members ) ) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'Es sind keine Mitglieder erfasst.', 'arbeitsdienste' ); ?></td>
						</tr>
					<?php endif; ?>
					<?php foreach ( $members as $member ) : ?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( $this->edit_url( $member->id ) ); ?>"><?php echo esc_html( $member->member_no ); ?></a></strong>
							</td>
							<td><?php echo esc_html( $member->first_name ); ?></td>
							<td><?php echo esc_html( $member->last_name ); ?></td>
							<td><?php $this->echo_work_group( $member ); ?></td>
							<td><?php echo esc_html( $member->email ); ?></td>
							<td><?php echo esc_html( (string) ( isset( $counts[ $member->id ] ) ? (int) $counts[ $member->id ] : 0 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php $this->render_pagination( $total, $page, $search ); ?>

			<?php $this->render_import(); ?>

			<?php $this->render_cleanup(); ?>
		</div>
		<?php
	}

	/**
	 * Write the work group of one member into a cell of the list.
	 *
	 * An empty cell and a cell with a stroke say the same thing to a reader: the
	 * file of the club said nothing about this member. The stroke makes that
	 * visible without a word, and it does not look like a missing value.
	 *
	 * @param FG_Member $member Member.
	 * @return void
	 */
	private function echo_work_group( FG_Member $member ) {
		echo '' === $member->work_group
			? '<span aria-hidden="true">—</span>'
			: esc_html( $member->work_group );
	}

	/**
	 * Render the create and edit form of one member.
	 *
	 * @param FG_Member|null $member Member to edit, or null to create one.
	 * @return void
	 */
	private function render_form( $member = null ) {
		$is_new      = ! $member instanceof FG_Member;
		$member_no   = $is_new ? '' : $member->member_no;
		$email       = $is_new ? '' : $member->email;
		$first_name  = $is_new ? '' : $member->first_name;
		$last_name   = $is_new ? '' : $member->last_name;
		$work_group  = $is_new ? '' : $member->work_group;
		$member_id   = $is_new ? 0 : $member->id;
		$signups     = $is_new ? 0 : $this->repository->count_member_registrations( $member->id );
		?>
		<div class="wrap">
			<h1><?php echo $is_new
				? esc_html__( 'Neues Mitglied', 'arbeitsdienste' )
				: esc_html__( 'Mitglied bearbeiten', 'arbeitsdienste' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="fg_save_member">
				<input type="hidden" name="fg_member_id" value="<?php echo esc_attr( $member_id ); ?>">
				<?php wp_nonce_field( 'fg_save_member_' . $member_id, 'fg_member_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fg-member-no"><?php esc_html_e( 'Mitgliedsnummer', 'arbeitsdienste' ); ?></label></th>
						<td>
							<input type="text" id="fg-member-no" name="fg_member_no" class="regular-text" maxlength="<?php echo esc_attr( FG_Schema::MEMBER_NO_MAX ); ?>" value="<?php echo esc_attr( $member_no ); ?>" required>
							<span class="description"><?php esc_html_e( 'Das ist der Schlüssel des Mitglieds. Er wird als Text gespeichert, damit eine führende Null erhalten bleibt.', 'arbeitsdienste' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fg-member-email"><?php esc_html_e( 'E-Mail-Adresse', 'arbeitsdienste' ); ?></label></th>
						<td>
							<input type="email" id="fg-member-email" name="fg_member_email" class="regular-text" maxlength="<?php echo esc_attr( FG_Schema::MEMBER_EMAIL_MAX ); ?>" value="<?php echo esc_attr( $email ); ?>" required>
							<span class="description"><?php esc_html_e( 'Schlüssel ist die Mitgliedsnummer; eine E-Mail-Adresse darf bei mehreren Mitgliedern stehen, etwa bei einem Ehepaar mit einem gemeinsamen Postfach. Die Adresse wird nicht öffentlich angezeigt.', 'arbeitsdienste' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fg-member-first"><?php esc_html_e( 'Vorname', 'arbeitsdienste' ); ?></label></th>
						<td>
							<input type="text" id="fg-member-first" name="fg_first_name" class="regular-text" maxlength="<?php echo esc_attr( FG_Schema::MEMBER_NAME_MAX ); ?>" value="<?php echo esc_attr( $first_name ); ?>" required>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fg-member-last"><?php esc_html_e( 'Nachname', 'arbeitsdienste' ); ?></label></th>
						<td>
							<input type="text" id="fg-member-last" name="fg_last_name" class="regular-text" maxlength="<?php echo esc_attr( FG_Schema::MEMBER_NAME_MAX ); ?>" value="<?php echo esc_attr( $last_name ); ?>" required>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="fg-member-group"><?php esc_html_e( 'Arbeitsgruppe', 'arbeitsdienste' ); ?></label></th>
						<td>
							<input type="text" id="fg-member-group" name="fg_work_group" class="regular-text" maxlength="<?php echo esc_attr( FG_Schema::MEMBER_WORK_GROUP_MAX ); ?>" value="<?php echo esc_attr( $work_group ); ?>">
							<span class="description"><?php esc_html_e( 'Freiwillig. Das ist die stehende Gruppe oder der Arbeitskreis, in dem das Mitglied arbeitet — nicht die Gruppe eines Arbeitsdienstes, die dieser selbst trägt. Der Import kann das Feld aus der Mitgliederliste des Vereins übernehmen.', 'arbeitsdienste' ); ?></span>
						</td>
					</tr>
				</table>

				<?php submit_button( $is_new ? __( 'Mitglied anlegen', 'arbeitsdienste' ) : __( 'Änderungen speichern', 'arbeitsdienste' ) ); ?>
			</form>

			<p>
				<a href="<?php echo esc_url( $this->list_url() ); ?>"><?php esc_html_e( 'Zurück zur Übersicht', 'arbeitsdienste' ); ?></a>
			</p>

			<?php if ( ! $is_new ) : ?>
				<h2><?php esc_html_e( 'Gefährliche Aktion', 'arbeitsdienste' ); ?></h2>
				<p>
					<?php
					if ( $signups > 0 ) {
						printf(
							/* translators: %d: number of work services. */
							esc_html__( 'Mit diesem Mitglied werden %d Anmeldung(en) für Arbeitsdienste gelöscht. Das Mitglied selbst bleibt im Verein.', 'arbeitsdienste' ),
							(int) $signups
						);
					} else {
						esc_html_e( 'Dieses Mitglied ist für keinen Arbeitsdienst angemeldet. Fahrgemeinschaften, die das Mitglied angeboten hat, bleiben bestehen.', 'arbeitsdienste' );
					}
					?>
				</p>
				<p>
					<?php
					FG_Admin::echo_delete_link(
						'member',
						$member->id,
						__( 'Dieses Mitglied endgültig löschen?', 'arbeitsdienste' )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the import form and, after a run, its report.
	 *
	 * The report is stored in a transient, not in a query argument. It names
	 * members, and a list of names in a URL ends up in the browser history, in
	 * the log of any proxy in between and in the Referer of the next page.
	 *
	 * @return void
	 */
	private function render_import() {
		$report = get_transient( 'fg_import_report_' . get_current_user_id() );
		if ( is_array( $report ) ) {
			delete_transient( 'fg_import_report_' . get_current_user_id() );
		}
		?>
		<h2><?php esc_html_e( 'Mitglieder importieren', 'arbeitsdienste' ); ?></h2>
		<?php if ( is_array( $report ) ) : ?>
			<?php // Der Bericht steht in einem eigenen Rahmen. Er nennt Mitglieder, und auf derselben Seite steht die Liste aller Mitglieder: Ohne diesen Rahmen ist nicht unterscheidbar, ob eine Nummer aus dem Bericht oder aus der Liste gelesen wurde. ?>
			<div class="fg-import-report">
				<?php $this->render_report( $report ); ?>
			</div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Erwartet wird eine CSV-Datei mit einer Kopfzeile und den Spalten für Mitgliedsnummer, E-Mail-Adresse, Vorname und Nachname. Weitere Spalten werden ignoriert. Trennzeichen sind Semikolon und Komma.', 'arbeitsdienste' ); ?></p>
		<?php $this->render_import_columns(); ?>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fg_import_members">
			<?php wp_nonce_field( 'fg_import_members', 'fg_import_nonce' ); ?>
			<input type="file" name="fg_member_file" accept=".csv,text/csv,text/plain" required>
			<?php submit_button( __( 'Import starten', 'arbeitsdienste' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Render the header names the import accepts, next to the file field.
	 *
	 * The names come out of FG_Member_Import::accepted_columns(), which is the
	 * very list the import reads. A list written out here would be a second
	 * truth, and a second truth is the one that ages: a club would try a name it
	 * was shown on this page and be refused for a reason the page had already
	 * answered. Every alias stands in the list, including the one that is only
	 * the label in lower case, so that the reader sees it works either way.
	 *
	 * The three sentences above the table are not decoration. Each one answers a
	 * refusal the import would otherwise report as "column missing": that the
	 * case does not matter, that the name has to be the whole cell, and that the
	 * order of the columns is free. The first alias of E-Mail-Adresse is "e-mail"
	 * and not the label in lower case, so a reader who was shown only the
	 * label would miss the spelling a real export is most likely to use.
	 *
	 * It stands in a frame of its own so that a test can read it without the
	 * member list that is on the same screen: a number is answered there by the
	 * row of the first member whose number starts with it.
	 *
	 * @return void
	 */
	private function render_import_columns() {
		$spalten = FG_Member_Import::accepted_columns();
		?>
		<details class="fg-import-columns">
			<summary><?php esc_html_e( 'Wie die Spalten heißen dürfen', 'arbeitsdienste' ); ?></summary>
			<p><?php esc_html_e( 'Die Spalten dürfen in beliebiger Reihenfolge stehen. Die Kopfzeile muss genau einer der unten genannten Namen sein: Ein zusätzliches Wort in derselben Zelle, das Wort Pflicht etwa, macht sie zu einem unbekannten Namen. Groß- und Kleinschreibung spielt keine Rolle, Leerzeichen am Ende einer Zelle werden entfernt.', 'arbeitsdienste' ); ?></p>
			<p><?php esc_html_e( 'Die Arbeitsgruppe ist freiwillig: Eine Datei ohne diese Spalte wird gelesen, und die gespeicherten Arbeitsgruppen bleiben, wie sie sind. Steht die Spalte darin, gilt die Datei — auch wenn eine Zelle leer ist, denn das ist eine Aussage des Vereins und keine fehlende Angabe.', 'arbeitsdienste' ); ?></p>
			<table>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Feld', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Die Kopfzeile darf heißen', 'arbeitsdienste' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $spalten as $feld => $namen ) :
						$stuecke = array();
						foreach ( $namen as $name ) {
							$stuecke[] = '<code>' . esc_html( $name ) . '</code>';
						}
						?>
						<tr>
							<th scope="row">
								<?php
								// The one field a file does not have to carry is marked as such
								// in the same row. The sentence under the table says the same
								// thing, and this says it where the club is reading the names
								// of the columns.
								echo esc_html( $feld );

								if ( in_array( FG_Member_Import::field_name_of( $feld ), FG_Member_Import::optional_columns(), true ) ) {
									echo ' <span class="description">(' . esc_html__( 'freiwillig', 'arbeitsdienste' ) . ')</span>';
								}
								?>
							</th>
							<?php // Die Stuecke werden beim Bauen escaped und danach nur noch zusammengesetzt; ein zweites esc_html() wuerde die Tags mitnehmen. ?>
							<td><?php echo implode( ', ', $stuecke ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<?php
	}

	/**
	 * Render the report of one import run.
	 *
	 * @param array $report Report as returned by FG_Member_Import::apply().
	 * @return void
	 */
	private function render_report( array $report ) {
		$missing = isset( $report['missing'] ) ? (array) $report['missing'] : array();

		usort(
			$missing,
			static function ( FG_Member $a, FG_Member $b ) {
				// Compared as text, not as numbers. A club number may be "2" and
				// "10", and a list that puts "10" before "2" is a list nobody
				// reads.
				return strnatcasecmp( $a->member_no, $b->member_no );
			}
		);
		?>
		<div class="notice notice-info inline">
			<p>
				<?php
				printf(
					/* translators: 1: number of data rows, 2: number of new members, 3: number of changed members, 4: number of unchanged members. */
					esc_html__( 'Import abgeschlossen: %1$d Zeilen gelesen, %2$d neue Mitglieder angelegt, %3$d Mitglieder geändert, %4$d unverändert.', 'arbeitsdienste' ),
					(int) ( isset( $report['rows'] ) ? $report['rows'] : 0 ),
					(int) ( isset( $report['created'] ) ? $report['created'] : 0 ),
					(int) ( isset( $report['updated'] ) ? $report['updated'] : 0 ),
					(int) ( isset( $report['unchanged'] ) ? $report['unchanged'] : 0 )
				);
				?>
			</p>
		</div>
		<?php if ( empty( $missing ) ) : ?>
			<p><?php esc_html_e( 'Alle in der Datenbank vorhandenen Mitglieder standen auch in der Datei.', 'arbeitsdienste' ); ?></p>
		<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: %d: number of members. */
					esc_html( _n( '%d Mitglied steht in der Datenbank, aber nicht in der Datei:', '%d Mitglieder stehen in der Datenbank, aber nicht in der Datei:', count( $missing ), 'arbeitsdienste' ) ),
					count( $missing )
				);
				?>
			</p>
			<p class="description"><?php esc_html_e( 'Diese Mitglieder wurden nicht gelöscht. Ein Import entfernt nie jemanden; bitte prüfe die Liste von Hand, wenn jemand aus dem Verein ausgetreten ist.', 'arbeitsdienste' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Mitgliedsnummer', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'Name', 'arbeitsdienste' ); ?></th>
						<th><?php esc_html_e( 'E-Mail-Adresse', 'arbeitsdienste' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $missing as $member ) : ?>
						<tr>
							<td><?php echo esc_html( $member->member_no ); ?></td>
							<td><?php echo esc_html( trim( $member->first_name . ' ' . $member->last_name ) ); ?></td>
							<td><?php echo esc_html( $member->email ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the section that removes every member without a work service.
	 *
	 * The first step is a link and not a button that deletes: it opens the
	 * overview, and only the form on the overview sends the request that removes
	 * anything. Both numbers are printed here, so the person in front of the
	 * screen learns what the operation is about before starting it rather than
	 * after it.
	 *
	 * @return void
	 */
	private function render_cleanup() {
		$ohne = (int) $this->repository->count_members_without_registration();
		$mit  = (int) $this->repository->count_members() - $ohne;
		?>
		<h2><?php esc_html_e( 'Aufräumen', 'arbeitsdienste' ); ?></h2>
		<p><?php esc_html_e( 'Für einen Testlauf und vor einem neuen Import lässt sich die Liste in einem Zug leeren: gelöscht werden alle Mitglieder, die für keinen Arbeitsdienst angemeldet sind. Fahrgemeinschaften bleiben erhalten, denn eine Fahrt ist ein eigener Eintrag mit eigener Kontaktadresse.', 'arbeitsdienste' ); ?></p>
		<?php $this->render_prune_counts( $ohne, $mit ); ?>
		<?php if ( 0 === $ohne ) : ?>
			<p><?php esc_html_e( 'Zurzeit ist niemand ohne Anmeldung im Bestand; es gibt nichts zu löschen.', 'arbeitsdienste' ); ?></p>
		<?php else : ?>
			<p>
				<a class="button" href="<?php echo esc_url( $this->prune_url() ); ?>"><?php esc_html_e( 'Zeigen, was gelöscht würde', 'arbeitsdienste' ); ?></a>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the overview of the group deletion, with the form that carries it out.
	 *
	 * The overview names every member it would take. That is the point of the
	 * second step: the first step only says how many, and a count of a few
	 * hundred is no answer to "is my member in there?".
	 *
	 * @return void
	 */
	private function render_prune() {
		$ohne     = (int) $this->repository->count_members_without_registration();
		$mit      = (int) $this->repository->count_members() - $ohne;
		$betroffen = $ohne ? $this->repository->get_members_without_registration() : array();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Mitglieder ohne Arbeitsdienst löschen', 'arbeitsdienste' ); ?></h1>
			<a href="<?php echo esc_url( $this->list_url() ); ?>" class="page-title-action"><?php esc_html_e( 'Zurück zur Liste', 'arbeitsdienste' ); ?></a>
			<hr class="wp-header-end">

			<p><?php esc_html_e( 'Diese Übersicht zählt beim Aufruf dieser Seite. Zwischen ihr und dem Klick kann sich die Zahl geändert haben: gelöscht wird, wer in diesem Moment für keinen Arbeitsdienst angemeldet ist.', 'arbeitsdienste' ); ?></p>
			<?php $this->render_prune_counts( $ohne, $mit ); ?>

			<?php if ( $ohne ) : ?>
				<?php // Wie der Importbericht steht die Liste in einem eigenen Rahmen: darüber steht die Liste aller Mitglieder, und eine Nummer, die von hier gelesen wird, muss sich von einer Nummer aus jener Liste unterscheiden lassen. ?>
				<div class="fg-prune-report">
					<p>
						<?php
						printf(
							/* translators: %d: number of members. */
							esc_html( _n( 'Dieses Mitglied wird gelöscht:', 'Diese Mitglieder werden gelöscht:', count( $betroffen ), 'arbeitsdienste' ) ),
							count( $betroffen )
						);
						?>
					</p>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Mitgliedsnummer', 'arbeitsdienste' ); ?></th>
								<th><?php esc_html_e( 'Name', 'arbeitsdienste' ); ?></th>
								<th><?php esc_html_e( 'E-Mail-Adresse', 'arbeitsdienste' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $betroffen as $member ) : ?>
								<tr>
									<td><?php echo esc_html( $member->member_no ); ?></td>
									<td><?php echo esc_html( trim( $member->first_name . ' ' . $member->last_name ) ); ?></td>
									<td><?php echo esc_html( $member->email ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fg_prune_members">
					<?php wp_nonce_field( 'fg_prune_members', 'fg_prune_nonce' ); ?>
					<?php
					submit_button(
						sprintf(
							/* translators: %d: number of members. */
							esc_html( _n( '%d Mitglied endgültig löschen', '%d Mitglieder endgültig löschen', count( $betroffen ), 'arbeitsdienste' ) ),
							count( $betroffen )
						),
						'primary delete',
						'submit',
						false
					);
					?>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Es ist niemand mehr ohne Anmeldung im Bestand. Zurück zur Liste.', 'arbeitsdienste' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Print the two numbers the group deletion is decided on.
	 *
	 * They stand in a frame of their own for the same reason the import report
	 * does: above this section the screen prints the list of every member with
	 * their number and the count of duties each of them is in, and a number read
	 * over the whole screen could have come from any of them.
	 *
	 * @param int $ohne Members not registered for any work service.
	 * @param int $mit  Members registered for at least one work service.
	 * @return void
	 */
	private function render_prune_counts( $ohne, $mit ) {
		?>
		<div class="fg-prune-zahlen">
			<p>
				<?php
				printf(
					/* translators: 1: number of members without a work service, 2: that number in words. */
					'<span class="fg-prune-ohne">%1$s</span> %2$s',
					esc_html( (string) (int) $ohne ),
					esc_html( _n( 'Mitglied ist für keinen Arbeitsdienst angemeldet.', 'Mitglieder sind für keinen Arbeitsdienst angemeldet.', (int) $ohne ) )
				);
				?>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: number of registered members, 2: that number in words. */
					'<span class="fg-prune-mit">%1$s</span> %2$s',
					esc_html( (string) (int) $mit ),
					esc_html( _n( 'Mitglied ist für mindestens einen Arbeitsdienst angemeldet und bleibt erhalten.', 'Mitglieder sind für mindestens einen Arbeitsdienst angemeldet und bleiben erhalten.', (int) $mit ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Delete every member that is not registered for a work service.
	 *
	 * The number in the notice is what the delete statement actually removed,
	 * not what the overview named. The two can differ, and a notice that named
	 * the number from the overview would be a claim about the past.
	 *
	 * @return void
	 */
	public function prune() {
		if ( ! current_user_can( 'delete_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung, Eintragungen zu löschen.', 'arbeitsdienste' ) );
		}

		check_admin_referer( 'fg_prune_members', 'fg_prune_nonce' );

		$deleted = (int) $this->repository->delete_members_without_registration();
		$kept    = (int) $this->repository->count_members();

		FG_Admin::store_notice(
			sprintf(
				/* translators: 1: number of deleted members, 2: number of members that stayed. */
				esc_html( _n( '%1$d Mitglied gelöscht, %2$d Mitglieder bleiben angemeldt.', '%1$d Mitglieder gelöscht, %2$d Mitglieder bleiben angemeldt.', $deleted ) ),
				$deleted,
				$kept
			)
		);

		$this->redirect_back();
	}

	/**
	 * Read, check and apply an uploaded member list.
	 *
	 * The file is read in full into memory before anything is written, and the
	 * whole file is checked before the first row is stored. A refused import has
	 * therefore changed nothing at all.
	 *
	 * @return void
	 */
	public function import() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diesen Bereich.', 'arbeitsdienste' ) );
		}

		check_admin_referer( 'fg_import_members', 'fg_import_nonce' );

		$importer = new FG_Member_Import( $this->repository );
		$raw      = $this->read_upload();

		if ( false === $raw ) {
			$this->redirect_back();
		}

		$parsed = $importer->parse( $raw );
		if ( ! $parsed['ok'] ) {
			FG_Admin::store_notice( FG_Member_Import::error_message( $parsed['error'] ), 'error' );
			$this->redirect_back();
		}

		$report = $importer->apply( $parsed['rows'] );
		if ( ! $report['ok'] ) {
			FG_Admin::store_notice( FG_Member_Import::error_message( $report['error'] ), 'error' );
			$this->redirect_back();
		}

		// The report names members and stays out of the URL, so it goes into a
		// transient that the next page view reads and clears.
		set_transient( 'fg_import_report_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );

		FG_Admin::store_notice( __( 'Der Import wurde verarbeitet.', 'arbeitsdienste' ) );
		$this->redirect_back();
	}

	/**
	 * Read the uploaded file as text.
	 *
	 * A false return means the upload itself went wrong: there was no file, the
	 * transfer was cut short, or the name was not a data file. Each of those has
	 * its own sentence, and none of them is answered by reading a file that was
	 * never received. An empty string, on the other hand, is a file that arrived
	 * and holds nothing, and the parser has a better sentence for that.
	 *
	 * @return string|false File contents, or false when the upload failed.
	 */
	private function read_upload() {
		if ( ! isset( $_FILES['fg_member_file'] ) || ! is_array( $_FILES['fg_member_file'] ) ) {
			FG_Admin::store_notice( $this->upload_error( __( 'Es wurde keine Datei gewählt', 'arbeitsdienste' ) ), 'error' );

			return false;
		}

		$upload = $_FILES['fg_member_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read through the checks below.

		$error = isset( $upload['error'] ) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $error ) {
			$hinweis = UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error
				? __( 'die Datei ist zu groß für den Server', 'arbeitsdienste' )
				: __( 'die Datei wurde nicht vollständig übertragen', 'arbeitsdienste' );

			FG_Admin::store_notice( $this->upload_error( $hinweis ), 'error' );

			return false;
		}

		$name = isset( $upload['name'] ) ? (string) $upload['name'] : '';
		if ( '' !== $name && ! preg_match( '/\.(csv|txt|tsv)$/i', $name ) ) {
			FG_Admin::store_notice( $this->upload_error( __( 'die Datei ist keine CSV-Datei', 'arbeitsdienste' ) ), 'error' );

			return false;
		}

		$tmp = isset( $upload['tmp_name'] ) ? (string) $upload['tmp_name'] : '';
		if ( '' === $tmp || ! is_readable( $tmp ) ) {
			FG_Admin::store_notice( $this->upload_error( __( 'die Datei ist nicht lesbar', 'arbeitsdienste' ) ), 'error' );

			return false;
		}

		$raw = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading an uploaded file is the point.

		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Build the sentence for a failed upload.
	 *
	 * @param string $hinweis Reason.
	 * @return string
	 */
	private function upload_error( $hinweis ) {
		return FG_Member_Import::error_message(
			array(
				'code'    => FG_Member_Import::ERROR_EMPTY,
				'hinweis' => $hinweis,
			)
		);
	}

	/**
	 * Validate and store the submitted member.
	 *
	 * @return void
	 */
	public function save() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diesen Bereich.', 'arbeitsdienste' ) );
		}

		$member_id = isset( $_POST['fg_member_id'] ) && ! is_array( $_POST['fg_member_id'] )
			? absint( wp_unslash( $_POST['fg_member_id'] ) )
			: 0;

		check_admin_referer( 'fg_save_member_' . $member_id, 'fg_member_nonce' );

		$fields = array(
			'member_no'  => isset( $_POST['fg_member_no'] ) ? wp_unslash( $_POST['fg_member_no'] ) : '',
			'email'      => isset( $_POST['fg_member_email'] ) ? wp_unslash( $_POST['fg_member_email'] ) : '',
			'first_name' => isset( $_POST['fg_first_name'] ) ? wp_unslash( $_POST['fg_first_name'] ) : '',
			'last_name'  => isset( $_POST['fg_last_name'] ) ? wp_unslash( $_POST['fg_last_name'] ) : '',
			// Always there, also when the field was not posted: this form writes
			// the whole member, and a value that is not in the post was emptied by
			// the person who saved. The import is the other way round — there a
			// column that is not in the file means that nobody said anything.
			'work_group' => isset( $_POST['fg_work_group'] ) ? wp_unslash( $_POST['fg_work_group'] ) : '',
		);

		foreach ( $fields as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$this->refuse( $member_id, __( 'Die Eingaben des Formulars waren unvollständig. Es wurde nichts gespeichert.', 'arbeitsdienste' ) );
			}

			$fields[ $key ] = sanitize_text_field( (string) $value );
		}

		if ( $member_id ) {
			$member = $this->repository->get_member( $member_id );
			if ( ! $member ) {
				$this->refuse( 0, __( 'Dieses Mitglied wurde nicht gefunden.', 'arbeitsdienste' ) );
			}

			// The reason is asked for before the change, because a save that
			// changes nothing is not a failure and must not be reported as one.
			$reason = $this->conflict( $fields, $member_id );
			if ( $reason ) {
				$this->refuse( $member_id, $reason );
			}

			if ( ! $this->repository->update_member( $member_id, $fields ) ) {
				$this->refuse( $member_id, __( 'Das Mitglied konnte nicht gespeichert werden.', 'arbeitsdienste' ) );
			}

			FG_Admin::store_notice( __( 'Das Mitglied wurde gespeichert.', 'arbeitsdienste' ) );
			$this->shared_address_notice( $fields['email'], $member_id );
			$this->redirect_back( $member_id );
		}

		$reason = $this->conflict( $fields, 0 );
		if ( $reason ) {
			$this->refuse( 0, $reason );
		}

		$new_id = $this->repository->insert_member( $fields );
		if ( ! $new_id ) {
			$this->refuse( 0, $this->invalid_reason( $fields ) );
		}

		FG_Admin::store_notice( __( 'Das Mitglied wurde angelegt.', 'arbeitsdienste' ) );
		$this->shared_address_notice( $fields['email'], $new_id );
		$this->redirect_back( $new_id );
	}

	/**
	 * Find out whether a submitted member would take a number that
	 * another member already holds.
	 *
	 * @param array $fields   Submitted fields.
	 * @param int   $member_id Record being edited, 0 for a new one.
	 * @return string Message, or an empty string when the fields are free.
	 */
	private function conflict( array $fields, $member_id ) {
		$number = $this->repository->get_member_by_number( $fields['member_no'] );
		if ( $number && $number->id !== (int) $member_id ) {
			return sprintf(
				/* translators: %s: member number already in use. */
				__( 'Die Mitgliedsnummer %s ist bereits vergeben. Bitte wähle eine andere.', 'arbeitsdienste' ),
				$number->member_no
			);
		}

		// The address is deliberately not checked here. Since schema 1.6.0 it may
		// stand at two members — a married pair with one mailbox — and the member
		// number is the key of this plugin. A shared address is reported after the
		// save, in shared_address_notice(); refusing it here would keep the club
		// from entering what its own member administration holds.
		return '';
	}

	/**
	 * Say that an address belongs to more than one member.
	 *
	 * A pair of members on one mailbox is ordinary and usually intended, but it
	 * is also the state in which both of them receive every message of the other
	 * — the mails of an entry and of a signup are the one mail that goes out.
	 * That is worth one line after the save, and it is not a refusal.
	 *
	 * @param string $email    Address of the member just written.
	 * @param int    $member_id ID of that member, so it does not count itself.
	 * @return void
	 */
	private function shared_address_notice( $email, $member_id ) {
		$email = $this->repository->normalize_email( $email );

		if ( false === $email ) {
			return;
		}

		$andere = array();
		foreach ( $this->repository->get_members_by_email( $email ) as $member ) {
			if ( $member->id !== (int) $member_id ) {
				$andere[] = $member;
			}
		}

		if ( empty( $andere ) ) {
			return;
		}

		$namen = array();
		foreach ( $andere as $member ) {
			$namen[] = sprintf(
				/* translators: 1: member number, 2: first and last name. */
				__( 'Mitglied %1$s (%2$s)', 'arbeitsdienste' ),
				$member->member_no,
				trim( $member->first_name . ' ' . $member->last_name )
			);
		}

		FG_Admin::store_notice(
			sprintf(
				/* translators: 1: number of members, 2: the members with their numbers and names. */
				_n(
					'Hinweis: Diese E-Mail-Adresse steht auch bei %1$d weiterem Mitglied: %2$s. Beide bekommen dieselben Nachrichten.',
					'Hinweis: Diese E-Mail-Adresse steht auch bei %1$d weiteren Mitgliedern: %2$s. Sie alle bekommen dieselben Nachrichten.',
					count( $andere ),
					'arbeitsdienste'
				),
				count( $andere ),
				implode( ', ', $namen )
			),
			'warning'
		);
	}

	/**
	 * Explain which of the four fields a new member is missing or has wrong.
	 *
	 * @param array $fields Submitted fields.
	 * @return string
	 */
	private function invalid_reason( array $fields ) {
		$email = $this->repository->normalize_email( $fields['email'] );

		if ( '' === trim( (string) $fields['member_no'] ) ) {
			return __( 'Bitte eine Mitgliedsnummer eingeben. Es wurde nichts gespeichert.', 'arbeitsdienste' );
		}

		if ( false === $email ) {
			return __( 'Bitte eine gültige E-Mail-Adresse eingeben. Es wurde nichts gespeichert.', 'arbeitsdienste' );
		}

		if ( '' === trim( (string) $fields['first_name'] ) || '' === trim( (string) $fields['last_name'] ) ) {
			return __( 'Bitte Vor- und Nachnamen eingeben. Es wurde nichts gespeichert.', 'arbeitsdienste' );
		}

		$too_long = 0;
		foreach ( array( 'member_no' => FG_Schema::MEMBER_NO_MAX, 'first_name' => FG_Schema::MEMBER_NAME_MAX, 'last_name' => FG_Schema::MEMBER_NAME_MAX ) as $field => $limit ) {
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $fields[ $field ], 'UTF-8' ) : strlen( $fields[ $field ] );
			if ( $length > $limit ) {
				++$too_long;
			}
		}

		if ( $too_long > 0 ) {
			return sprintf(
				/* translators: %d: number of characters allowed. */
				__( 'Eine der Angaben ist zu lang, es sind höchstens %d Zeichen erlaubt. Es wurde nichts gespeichert.', 'arbeitsdienste' ),
				FG_Schema::MEMBER_NAME_MAX
			);
		}

		return __( 'Das Mitglied konnte nicht angelegt werden.', 'arbeitsdienste' );
	}

	/**
	 * Store an error message and return to the form.
	 *
	 * @param int    $member_id Record to return to, 0 for a new one.
	 * @param string $message   Message.
	 * @return void
	 */
	private function refuse( $member_id, $message ) {
		FG_Admin::store_notice( $message, 'error' );
		$this->redirect_back( $member_id );
	}

	/**
	 * Return to the member list or to a member form.
	 *
	 * @param int $member_id Member to return to, 0 for the list.
	 * @return void
	 */
	private function redirect_back( $member_id = 0 ) {
		$url = $member_id ? $this->edit_url( $member_id ) : $this->list_url();

		wp_safe_redirect( $url, 303 );
		exit;
	}

	/**
	 * Build the URL of the member list.
	 *
	 * @return string
	 */
	private function list_url() {
		return add_query_arg(
			array( 'page' => FG_MEMBERS_PAGE_SLUG ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build the URL of the edit form of one member.
	 *
	 * @param int $member_id Member ID.
	 * @return string
	 */
	private function edit_url( $member_id ) {
		return add_query_arg(
			array(
				'page'   => FG_MEMBERS_PAGE_SLUG,
				'member' => (int) $member_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build the URL of the overview of the group deletion.
	 *
	 * @return string
	 */
	private function prune_url() {
		return add_query_arg(
			array(
				'page'     => FG_MEMBERS_PAGE_SLUG,
				'fg_prune' => 'ask',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render simple previous and next links.
	 *
	 * @param int    $total  Total number of rows.
	 * @param int    $page   Current page.
	 * @param string $search Search term to carry along.
	 * @return void
	 */
	private function render_pagination( $total, $page, $search = '' ) {
		$pages = (int) ceil( $total / FG_Admin::PER_PAGE );
		if ( $pages < 2 ) {
			return;
		}

		$base = '' !== $search
			? add_query_arg( 'fg_member_search', $search, $this->list_url() )
			: $this->list_url();

		echo '<p class="tablenav">';
		if ( $page > 1 ) {
			printf(
				'<a class="prev-page button" href="%s">%s</a> ',
				esc_url( add_query_arg( 'paged', $page - 1, $base ) ),
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
				esc_url( add_query_arg( 'paged', $page + 1, $base ) ),
				esc_html__( 'Weiter ›', 'arbeitsdienste' )
			);
		}
		echo '</p>';
	}
}
