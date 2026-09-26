<?php
/**
 * Privacy policy text and WordPress personal-data hooks.
 *
 * @package Fahrgemeinschaften
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provides data export/erasure integration for WordPress privacy tools.
 */
final class FG_Privacy {
	/**
	 * Records handled per privacy-tool page.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 10;

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

		add_action( 'admin_init', array( $this, 'add_privacy_policy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );
	}

	/**
	 * Add a suggested policy section to WordPress' privacy guide.
	 *
	 * @return void
	 */
	public function add_privacy_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text  = '<p>' . esc_html__( 'Fahrgemeinschaften für Arbeitsdienste: Die Website speichert den von der angemeldeten Person angegebenen Vornamen oder Spitznamen, die Art des Angebots, den ungefähren Abfahrtsbereich sowie den zugehörigen Arbeitsdienst. Vorname oder Spitzname, Angebotsart, Abfahrtsbereich und Arbeitsdienst werden in der öffentlichen Liste angezeigt; wer nicht mit Namen auftreten möchte, gibt einen Spitznamen an. Die private E-Mail-Adresse wird nicht öffentlich ausgegeben. Eine Eintragung wird erst nach Bestätigung per E-Mail veröffentlicht.', 'fahrgemeinschaften' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Für die Kontaktvermittlung kann eine Person ihre E-Mail-Adresse an den Ersteller einer veröffentlichten Fahrgemeinschaft senden. Die Anfrage wird nur zur Weiterleitung dieser Kontaktaufnahme verwendet; es werden keine Kontaktverläufe gespeichert. Die öffentliche Antwort ist unabhängig von der Gültigkeit einer Adresse gleich.', 'fahrgemeinschaften' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Bestätigungs- und Löschlinks enthalten zufällige Tokens. Diese werden nur als Hash gespeichert und laufen nach 48 Stunden beziehungsweise nach dem Ablauf des Arbeitsdienstes plus 30 Tagen ab. Die tägliche Missbrauchsstatistik enthält nur aggregierte Zähler ohne E-Mail-Adressen, Namen, IP-Adressen oder Rohformulare und wird nach 90 Tagen gelöscht.', 'fahrgemeinschaften' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Fahrgemeinschaften', 'fahrgemeinschaften' ), wp_kses_post( $text ) );
	}

	/**
	 * Register the personal-data exporter.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public function register_exporters( $exporters ) {
		$exporters['fahrgemeinschaften'] = array(
			'exporter_friendly_name' => __( 'Fahrgemeinschaften', 'fahrgemeinschaften' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the personal-data eraser.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public function register_erasers( $erasers ) {
		$erasers['fahrgemeinschaften'] = array(
			'eraser_friendly_name' => __( 'Fahrgemeinschaften', 'fahrgemeinschaften' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export public ride data associated with one participant e-mail address.
	 *
	 * @param string $email Requested e-mail address.
	 * @param int    $page  Privacy-tool page.
	 * @return array{data: array, done: bool}
	 */
	public function export( $email, $page = 1 ) {
		$email = $this->repository->normalize_email( $email );
		$page  = max( 1, absint( $page ) );

		if ( false === $email ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$rides = $this->repository->get_rides_by_email( $email, self::BATCH_SIZE, ( $page - 1 ) * self::BATCH_SIZE );

		$data = array();
		foreach ( $rides as $ride ) {
			$ride_data = $this->repository->get_ride_display_data( $ride );
			$data[]    = array(
				'group_id'    => 'fahrgemeinschaften-rides',
				'group_label' => __( 'Fahrgemeinschaften', 'fahrgemeinschaften' ),
				'item_id'     => 'fg-ride-' . $ride->id,
				'data'        => array(
					array( 'name' => __( 'Status', 'fahrgemeinschaften' ), 'value' => $ride->status ),
					array( 'name' => __( 'Art', 'fahrgemeinschaften' ), 'value' => $ride_data['mode'] ),
					array( 'name' => __( 'Vorname oder Spitzname', 'fahrgemeinschaften' ), 'value' => $ride->alias ),
					array( 'name' => __( 'Arbeitsdienst', 'fahrgemeinschaften' ), 'value' => $ride_data['event_label'] ),
					array( 'name' => __( 'Datum', 'fahrgemeinschaften' ), 'value' => $ride_data['event_date'] ),
					array( 'name' => __( 'Abfahrtsbereich', 'fahrgemeinschaften' ), 'value' => $ride_data['origin'] ),
					array( 'name' => __( 'E-Mail', 'fahrgemeinschaften' ), 'value' => $email ),
				),
			);
		}

		$event_data = $this->export_event_memberships( $email, $page );
		$data       = array_merge( $data, $event_data );

		return array(
			'data' => $data,
			'done' => count( $rides ) < self::BATCH_SIZE && count( $event_data ) < self::BATCH_SIZE,
		);
	}

	/**
	 * Export event participation records for a participant.
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	private function export_event_memberships( $email, $page ) {
		$matches = array();
		foreach ( $this->repository->get_all_events() as $event ) {
			if ( in_array( $email, $event->participants, true ) ) {
				$matches[] = $event;
			}
		}

		$matches = array_slice( $matches, ( $page - 1 ) * self::BATCH_SIZE, self::BATCH_SIZE );
		$data    = array();
		foreach ( $matches as $event ) {
			$data[] = array(
				'group_id'    => 'fahrgemeinschaften-events',
				'group_label' => __( 'Arbeitsdienst-Teilnahmen', 'fahrgemeinschaften' ),
				'item_id'     => 'fg-event-' . $event->id,
				'data'        => array(
					array( 'name' => __( 'Arbeitsdienst', 'fahrgemeinschaften' ), 'value' => $event->title ),
					array( 'name' => __( 'Datum', 'fahrgemeinschaften' ), 'value' => $this->repository->format_event_date( $event ) ),
				),
			);
		}

		return $data;
	}

	/**
	 * Erase a participant's rides and remove the address from event lists.
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  Privacy-tool page (kept for the core API).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email, $page = 1 ) {
		$email = $this->repository->normalize_email( $email );
		unset( $page );

		if ( false === $email ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$batch     = self::BATCH_SIZE;
		$max_loops = 25;
		$loops     = 0;
		$done      = true;
		$removed   = 0;

		// Rows are drained in batches instead of paginated, because deleting
		// rows while walking an offset would silently skip records.
		while ( true ) {
			$rides = $this->repository->get_rides_by_email( $email, $batch, 0 );

			if ( empty( $rides ) ) {
				break;
			}

			if ( $loops >= $max_loops ) {
				$done = false;
				break;
			}

			foreach ( $rides as $ride ) {
				if ( $this->repository->delete_ride( $ride->id ) ) {
					++$removed;
				}
			}
			++$loops;
		}

		foreach ( $this->repository->get_all_events() as $event ) {
			if ( ! in_array( $email, $event->participants, true ) ) {
				continue;
			}

			$this->repository->set_event_participants(
				$event->id,
				array_values( array_diff( $event->participants, array( $email ) ) )
			);
			++$removed;
		}

		$messages = array();
		if ( $removed > 0 ) {
			$messages[] = __( 'Die zugehörigen Fahrgemeinschaften wurden gelöscht und die E-Mail-Adresse aus den Arbeitsdienst-Teilnehmerlisten entfernt.', 'fahrgemeinschaften' );
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => $done,
		);
	}
}
