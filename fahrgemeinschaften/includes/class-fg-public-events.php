<?php
/**
 * Public shortcode listing the upcoming work services.
 *
 * @package Fahrgemeinschaften
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders [arbeitsdienste], a read-only list of the work duties still to come.
 *
 * The list is built from the same work duties as the selection field of the
 * offer form, so the two pages cannot disagree about what is coming up. It
 * carries no form and sends nothing anywhere, so a visitor does not have to
 * reach it over an encrypted connection: there is nothing on it to protect.
 */
final class FG_Public_Events {
	/**
	 * Anchor of the list, used as the id of its heading.
	 *
	 * @var string
	 */
	const ANCHOR_LIST = 'fg-dienste';

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
		add_shortcode( 'arbeitsdienste', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Render [arbeitsdienste].
	 *
	 * @return string
	 */
	public function render_shortcode() {
		FG_Public::enqueue_style();

		$events = $this->repository->get_active_events();

		ob_start();
		?>
		<div class="fg-wrapper">
			<section class="fg-section" aria-labelledby="<?php echo esc_attr( self::ANCHOR_LIST ); ?>">
				<h2 id="<?php echo esc_attr( self::ANCHOR_LIST ); ?>"><?php esc_html_e( 'Kommende Arbeitsdienste', 'fahrgemeinschaften' ); ?></h2>
				<?php if ( empty( $events ) ) : ?>
					<p class="fg-empty"><?php esc_html_e( 'Aktuell ist kein Arbeitsdienst eingetragen. Sobald die nächsten Termine feststehen, stehen sie hier.', 'fahrgemeinschaften' ); ?></p>
				<?php else : ?>
					<?php foreach ( $events as $event ) : ?>
						<?php $this->render_card( $event ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</section>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render one work duty as a card holding a table of its details.
	 *
	 * The details are a table because they are pairs of a name and a value, and
	 * the name stands in the first cell of its row with `scope`, so it is read
	 * out together with the value it belongs to. A description list would be the
	 * other way to write it; the table was asked for and is valid markup.
	 *
	 * A row appears only when the club has stated something. An empty field
	 * means "nothing stated", and it is left out rather than shown as a zero,
	 * because "0 Personen" would be read as a claim about the duty.
	 *
	 * @param FG_Event $event Work duty.
	 * @return void
	 */
	private function render_card( FG_Event $event ) {
		$rows = array(
			array(
				'label' => __( 'Datum', 'fahrgemeinschaften' ),
				'wert'  => $this->repository->format_event_date_long( $event ),
			),
		);

		$beginn = $this->repository->format_event_time( $event );
		if ( '' !== $beginn ) {
			$rows[] = array(
				'label' => __( 'Beginn', 'fahrgemeinschaften' ),
				'wert'  => $beginn,
			);
		}

		if ( '' !== $event->group_name ) {
			$rows[] = array(
				'label' => __( 'Gruppe', 'fahrgemeinschaften' ),
				'wert'  => $event->group_name,
			);
		}

		if ( $event->demand > 0 ) {
			$rows[] = array(
				'label' => __( 'Bedarf', 'fahrgemeinschaften' ),
				'wert'  => sprintf(
					/* translators: %d: number of people. */
					_n( '%d Person', '%d Personen', $event->demand, 'fahrgemeinschaften' ),
					$event->demand
				),
			);
		}

		if ( $event->duration_hours > 0 ) {
			$rows[] = array(
				'label' => __( 'Dauer', 'fahrgemeinschaften' ),
				'wert'  => sprintf(
					/* translators: %d: number of hours. */
					_n( '%d Stunde', '%d Stunden', $event->duration_hours, 'fahrgemeinschaften' ),
					$event->duration_hours
				),
			);
		}

		if ( '' !== $event->description ) {
			$rows[] = array(
				'label'         => __( 'Beschreibung', 'fahrgemeinschaften' ),
				'wert'          => $event->description,
				'zeilenumbruch' => true,
			);
		}
		?>
		<article class="fg-event-card">
			<h3 class="fg-event-title"><?php echo esc_html( $event->title ); ?></h3>
			<table class="fg-event-data">
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
							<?php // The description is the one field that may carry more than one line. The text is escaped first, so no markup from the admin reaches the page. ?>
							<td><?php echo ! empty( $row['zeilenumbruch'] ) ? nl2br( esc_html( $row['wert'] ) ) : esc_html( $row['wert'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</article>
		<?php
	}
}
