<?php
/**
 * Read and write plugin state from the command line.
 *
 * The shell suites must be able to look at the tables and build fixtures
 * without embedding long scripts in one-liners. Every value printed here is
 * either an identifier or a stored field value; nothing is interpreted.
 *
 * Usage: php state.php <command> [arguments]
 */
define( 'WP_USE_THEMES', false );
require '/var/www/html/wp-load.php';

$repo = new FG_Repository();

/**
 * Print a value without any formatting around it.
 *
 * @param mixed $value Value to print.
 * @return void
 */
function fg_state_out( $value ) {
	if ( is_bool( $value ) ) {
		$value = $value ? '1' : '0';
	}

	if ( null === $value ) {
		$value = '';
	}

	if ( is_array( $value ) ) {
		$value = implode( ',', $value );
	}

	echo (string) $value, "\n";
}

$command = isset( $argv[1] ) ? (string) $argv[1] : '';
$args    = array_slice( $argv, 2 );

switch ( $command ) {
	case 'statuses':
		$statuses = array();
		foreach ( $repo->get_rides_page( array(), 0, 0 ) as $ride ) {
			$statuses[] = $ride->status;
		}
		sort( $statuses );
		fg_state_out( implode( ',', $statuses ) . ',' );
		break;

	case 'count':
		fg_state_out( $repo->count_rides( array( 'status' => isset( $args[0] ) ? $args[0] : '' ) ) );
		break;

	case 'count-pending-token':
		$found = 0;
		foreach ( $repo->get_rides_page( array(), 0, 0 ) as $ride ) {
			if ( '' !== $ride->pending_confirm_hash ) {
				++$found;
			}
		}
		fg_state_out( $found );
		break;

	case 'count-event-rides':
		fg_state_out( $repo->count_event_rides( isset( $args[0] ) ? $args[0] : 0 ) );
		break;

	case 'count-published':
		fg_state_out( $repo->count_rides( array( 'status' => FG_RIDE_STATUS_PUBLISHED ) ) );
		break;

	case 'count-alias':
		$needle = isset( $args[0] ) ? (string) $args[0] : '';
		$found  = 0;
		foreach ( $repo->get_rides_page( array(), 0, 0 ) as $ride ) {
			if ( 0 === strpos( $ride->alias, $needle ) ) {
				++$found;
			}
		}
		fg_state_out( $found );
		break;

	case 'count-public-for-event':
		fg_state_out( count( $repo->get_published_rides( isset( $args[0] ) ? $args[0] : 0 ) ) );
		break;

	case 'event':
		$event = $repo->get_event( isset( $args[0] ) ? $args[0] : 0 );
		if ( ! $event ) {
			fg_state_out( 'missing' );
			break;
		}

		$fields = array(
			'id',
			'title',
			'event_date',
			'event_time',
			'participants',
			'event_uuid',
			'public_ref',
			'is_active',
			'created_at',
		);
		$name = isset( $args[1] ) ? $args[1] : 'id';
		fg_state_out( in_array( $name, $fields, true ) ? $event->{$name} : 'unknown-field' );
		break;

	case 'ride':
		$ride = $repo->get_ride( isset( $args[0] ) ? $args[0] : 0 );
		if ( ! $ride ) {
			fg_state_out( 'missing' );
			break;
		}

		$fields = array(
			'id',
			'event_id',
			'status',
			'mode',
			'alias',
			'origin',
			'contact_email',
			'public_ref',
			'confirmed_at',
			'consent_version',
			'consented_at',
			'created_at',
			'pending_confirm_hash',
			'pending_discard_hash',
			'delete_hash',
			'delete_expires',
			'source_url',
		);
		$name = isset( $args[1] ) ? $args[1] : 'id';
		fg_state_out( in_array( $name, $fields, true ) ? $ride->{$name} : 'unknown-field' );
		break;

	case 'exists-event':
		fg_state_out( null !== $repo->get_event( isset( $args[0] ) ? $args[0] : 0 ) );
		break;

	case 'exists-ride':
		fg_state_out( null !== $repo->get_ride( isset( $args[0] ) ? $args[0] : 0 ) );
		break;

	case 'make-event':
		$event_id = $repo->insert_event(
			array(
				'title'        => isset( $args[0] ) ? $args[0] : 'Dienst',
				'event_date'   => isset( $args[1] ) ? $args[1] : gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ),
				'event_time'   => isset( $args[3] ) && '' !== $args[3] ? $args[3] : '',
				'participants' => isset( $args[2] ) ? $args[2] : '',
				'is_active'    => ! isset( $args[4] ) || '0' !== $args[4],
			)
		);
		fg_state_out( $event_id );
		break;

	case 'make-ride':
		$ride = $repo->create_pending_ride(
			array(
				'event_id'      => isset( $args[0] ) ? $args[0] : 0,
				'mode'          => isset( $args[1] ) ? $args[1] : FG_RIDE_MODE_OFFER,
				'alias'         => isset( $args[2] ) ? $args[2] : 'Fahrt',
				'origin'        => isset( $args[3] ) ? $args[3] : 'Innenstadt',
				'contact_email' => isset( $args[4] ) ? $args[4] : 'anton@angeln.example.org',
			)
		);
		$ride_id = $ride['id'];
		if ( $ride_id && isset( $args[5] ) && FG_RIDE_STATUS_PUBLISHED === $args[5] ) {
			$repo->update_ride(
				$ride_id,
				array(
					'status'       => FG_RIDE_STATUS_PUBLISHED,
					'confirmed_at' => current_time( 'mysql' ),
				)
			);
		}
		fg_state_out( $ride_id );
		break;

	case 'find-event':
		$found = 0;
		foreach ( $repo->get_all_events() as $event ) {
			if ( $event->title === ( isset( $args[0] ) ? $args[0] : '' ) ) {
				$found = $event->id;
				break;
			}
		}
		fg_state_out( $found );
		break;

	case 'delete-event':
		$result = $repo->delete_event( isset( $args[0] ) ? $args[0] : 0 );
		fg_state_out( $result['deleted'] ? 'deleted:' . $result['rides'] : 'missing' );
		break;

	case 'delete-ride':
		fg_state_out( $repo->delete_ride( isset( $args[0] ) ? $args[0] : 0 ) );
		break;

	case 'purge':
		$removed = 0;
		foreach ( $repo->get_all_events() as $event ) {
			$result = $repo->delete_event( $event->id );
			$removed += (int) $result['rides'];
			++$removed;
		}
		fg_state_out( $removed );
		break;

	case 'tables':
		fg_state_out( FG_Schema::tables_exist() ? 'present' : 'missing' );
		break;

	default:
		fg_state_out( 'unknown-command' );
		exit( 1 );
}
