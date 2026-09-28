<?php
/**
 * Privacy policy text and WordPress personal-data hooks.
 *
 * @package Arbeitsdienste
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

		$text  = '<p>' . esc_html__( 'Fahrgemeinschaften für Arbeitsdienste: Die Website speichert zu einer Eintragung das Mitglied, das sie angeboten hat, die Art des Angebots, den ungefähren Abfahrtsbereich sowie den zugehörigen Arbeitsdienst. In der öffentlichen Liste stehen der Vorname aus der Mitgliederverwaltung, die Angebotsart, der Abfahrtsbereich und der Arbeitsdienst; eine andere Bezeichnung kann nicht eingegeben werden. Die Mitgliedsnummer und die E-Mail-Adresse des Mitglieds werden an der Eintragung nicht gespeichert und nicht öffentlich ausgegeben — die E-Mail-Adresse steht nur in der Mitgliederverwaltung. Die Eintragung steht sofort in der Liste und wird nicht erst nach einer Bestätigung veröffentlicht.', 'arbeitsdienste' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Für die Kontaktvermittlung fragt das Kontaktformular die Mitgliedsnummer und die E-Mail-Adresse ab. Beide müssen zu einem Mitglied des Vereins passen, das sich für den Arbeitsdienst der Fahrgemeinschaft eingetragen hat. Die Anfrage wird nur zur Weiterleitung dieser Kontaktaufnahme verwendet; es werden keine Kontaktverläufe gespeichert. Die öffentliche Antwort ist unabhängig von der Gültigkeit der Angaben gleich.', 'arbeitsdienste' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Die Löschlinks in den E-Mails enthalten zufällige Tokens. Diese werden nur als Hash gespeichert und laufen nach dem Ablauf des Arbeitsdienstes plus 30 Tagen ab. Die tägliche Missbrauchsstatistik enthält nur aggregierte Zähler ohne E-Mail-Adressen, Namen, IP-Adressen oder Rohformulare und wird nach 90 Tagen gelöscht.', 'arbeitsdienste' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Fahrgemeinschaften', 'arbeitsdienste' ), wp_kses_post( $text ) );
	}

	/**
	 * Register the personal-data exporter.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public function register_exporters( $exporters ) {
		$exporters['fahrgemeinschaften'] = array(
			'exporter_friendly_name' => __( 'Fahrgemeinschaften', 'arbeitsdienste' ),
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
			'eraser_friendly_name' => __( 'Fahrgemeinschaften', 'arbeitsdienste' ),
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

		// Everything the plugin holds for this address, and the number of members
		// behind it. Since schema 1.6.0 that can be two: a married pair with one
		// mailbox, which WordPress cannot tell apart either, because it only ever
		// asks for the address. The report therefore says how many members it
		// covers, so a reader who sees the data of two people also sees why.
		$zaehler = count( $this->repository->get_members_by_email( $email ) );
		$rides   = $this->repository->get_rides_by_email( $email, self::BATCH_SIZE, ( $page - 1 ) * self::BATCH_SIZE );

		// The members and work services of the whole batch in one query each.
		// The export runs over the rides of the members behind the address, so
		// they can point at more than one member — but the work services differ
		// per ride, and one query per row here would be one query per exported
		// line.
		$members = $this->repository->get_members_for_rides( $rides );
		$events  = $this->repository->get_events_by_ids(
			array_map(
				static function ( $ride ) {
					return $ride->event_id;
				},
				$rides
			)
		);

		$data = array();
		if ( $zaehler > 1 ) {
			$data[] = array(
				'group_id'    => 'fahrgemeinschaften-rides',
				'group_label' => __( 'Fahrgemeinschaften', 'arbeitsdienste' ),
				'item_id'     => 'fg-geteilte-adresse',
				'data'        => array(
					array(
						'name'  => __( 'Umfang', 'arbeitsdienste' ),
						'value' => sprintf(
							/* translators: %d: number of members sharing one e-mail address. */
							_n(
								'Diese E-Mail-Adresse gehört %d Mitglied des Vereins; dieser Bericht umfasst dessen Daten.',
								'Diese E-Mail-Adresse gehört %d Mitgliedern des Vereins; dieser Bericht umfasst deren Daten.',
								$zaehler,
								'arbeitsdienste'
							),
							(int) $zaehler
						),
					),
				),
			);
		}

		foreach ( $rides as $ride ) {
			$member     = isset( $members[ $ride->member_id ] ) ? $members[ $ride->member_id ] : null;
			$event      = isset( $events[ $ride->event_id ] ) ? $events[ $ride->event_id ] : null;
			$ride_data  = $this->repository->get_ride_display_data( $ride, $member );
			$data[]     = array(
				'group_id'    => 'fahrgemeinschaften-rides',
				'group_label' => __( 'Fahrgemeinschaften', 'arbeitsdienste' ),
				'item_id'     => 'fg-ride-' . $ride->id,
				'data'        => array(
					array( 'name' => __( 'Status', 'arbeitsdienste' ), 'value' => $ride->status ),
					array( 'name' => __( 'Art', 'arbeitsdienste' ), 'value' => $ride_data['mode'] ),
					array( 'name' => __( 'Vorname', 'arbeitsdienste' ), 'value' => $member ? $member->first_name : '' ),
					array( 'name' => __( 'Nachname', 'arbeitsdienste' ), 'value' => $member ? $member->last_name : '' ),
					array( 'name' => __( 'Arbeitsdienst', 'arbeitsdienste' ), 'value' => $event ? $event->title : '' ),
					array( 'name' => __( 'Datum', 'arbeitsdienste' ), 'value' => $ride_data['event_date'] ),
					array( 'name' => __( 'Abfahrtsbereich', 'arbeitsdienste' ), 'value' => $ride_data['origin'] ),
					array( 'name' => __( 'E-Mail', 'arbeitsdienste' ), 'value' => $email ),
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
	 * Export the work service registrations of one member.
	 *
	 * A registration is found the same way the public page finds it: through the
	 * member that owns the e-mail address. The member record itself belongs to
	 * the club's member administration and is not part of the export; what is
	 * exported is what this plugin stored because of the registrations.
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	private function export_event_memberships( $email, $page ) {
		// Every member behind the address, not the first of them: the question
		// WordPress asks is what the plugin holds for this address, and the
		// registrations of the other half of a shared mailbox belong to the same
		// answer.
		$members = $this->repository->get_members_by_email( $email );
		if ( empty( $members ) ) {
			return array();
		}

		// The duties are read once and the registrations are asked per pair, which
		// is the same shape the loop had before: one member gave one pass over the
		// list, two members would have given two, and the number of duties is not
		// something that should be walked twice for every member.
		$matches = array();
		foreach ( $this->repository->get_all_events() as $event ) {
			foreach ( $members as $member ) {
				if ( $this->repository->has_registration( $event->id, $member->id ) ) {
					$matches[] = $event;
					break;
				}
			}
		}

		$matches = array_slice( $matches, ( $page - 1 ) * self::BATCH_SIZE, self::BATCH_SIZE );
		$data    = array();
		foreach ( $matches as $event ) {
			$data[] = array(
				'group_id'    => 'fahrgemeinschaften-events',
				'group_label' => __( 'Anmeldungen zu Arbeitsdiensten', 'arbeitsdienste' ),
				'item_id'     => 'fg-event-' . $event->id,
				'data'        => array(
					array( 'name' => __( 'Arbeitsdienst', 'arbeitsdienste' ), 'value' => $event->title ),
					array( 'name' => __( 'Datum', 'arbeitsdienste' ), 'value' => $this->repository->format_event_date( $event ) ),
				),
			);
		}

		return $data;
	}

	/**
	 * Erase a member's rides and work service registrations.
	 *
	 * The member record itself stays. It is a record of the club's membership,
	 * held by the member administration and not created by this plugin, and a
	 * request to this tool says that the registrations made here should go. A
	 * person who has left the club is removed by the club, through the member
	 * list, and there the registrations go with it.
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

		// The registrations of every member behind the address, not of the first
		// one. The rides above were drained the same way, and a report that names
		// only one of the two while the other keeps its registrations would be
		// worse than either all or nothing.
		$signups = 0;
		$members = $this->repository->get_members_by_email( $email );
		if ( ! empty( $members ) ) {
			foreach ( $this->repository->get_all_events() as $event ) {
				foreach ( $members as $member ) {
					if ( $this->repository->has_registration( $event->id, $member->id )
						&& $this->repository->delete_registration_by_pair( $event->id, $member->id ) ) {
						++$signups;
					}
				}
			}
		}

		$messages = array();
		if ( $removed > 0 ) {
			$messages[] = __( 'Die zugehörigen Fahrgemeinschaften wurden gelöscht.', 'arbeitsdienste' );
		}
		if ( $signups > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of work service registrations. */
				_n( 'Die Anmeldung für %d Arbeitsdienst wurde gelöscht.', 'Die Anmeldungen für %d Arbeitsdienste wurden gelöscht.', $signups, 'arbeitsdienste' ),
				$signups
			);
		}
		if ( ! empty( $members ) && ( $removed > 0 || $signups > 0 ) ) {
			$messages[] = 1 === count( $members )
				? __( 'Der Stammdatensatz des Mitglieds in der Mitgliederverwaltung des Vereins wurde nicht angetastet.', 'arbeitsdienste' )
				: sprintf(
					/* translators: %d: number of members sharing one e-mail address. */
					_n(
						'Diese Adresse gehört %d Mitglied des Vereins. Sein Stammdatensatz in der Mitgliederverwaltung wurde nicht angetastet.',
						'Diese Adresse gehört %d Mitgliedern des Vereins. Ihre Stammdatensätze in der Mitgliederverwaltung wurden nicht angetastet.',
						count( $members ),
						'arbeitsdienste'
					),
					count( $members )
				);
		}

		return array(
			'items_removed'  => $removed > 0 || $signups > 0,
			'items_retained' => ! empty( $members ),
			'messages'       => $messages,
			'done'           => $done,
		);
	}
}
