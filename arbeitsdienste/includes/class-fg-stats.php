<?php
/**
 * Aggregate, non-identifying usage statistics.
 *
 * @package Arbeitsdienste
 */

defined( 'ABSPATH' ) || exit;

/**
 * Daily counters stored without form contents or direct identifiers.
 */
final class FG_Stats {
	/**
	 * Counter definitions.
	 *
	 * @return array<string, string>
	 */
	public static function labels() {
		return array(
			'publish_form_total'     => 'Veröffentlichungsformulare gesamt',
			'publish_valid_email'    => 'Einträge mit passender Mitgliedsnummer und E-Mail-Adresse',
			// One counter, two refusals, and it said three until 1.25.2: the pair does
			// not belong to a member (class-fg-actions.php, the find), or the member
			// is not on the duty's list (the is_event_participant() check). Those are
			// the two places that raise it, and the label names both, because a label
			// that named one of them would be wrong for the other. The key stays what
			// it is; renaming a stored key would throw the days that have already been
			// counted away. Splitting the two into a counter each would only be worth
			// it if the club asked how often the second one is the reason, and that
			// question was asked and answered with "no": the label is read by an
			// editor, and a second row of "people who are not on the list" beside the
			// duty list would answer it with a guess either way.
			'publish_invalid_email'  => 'Abgewiesene Einträge: Nummer und E-Mail-Adresse passen nicht zu einem Mitglied, oder das Mitglied ist für diesen Arbeitsdienst nicht angemeldet',
			'publish_published'      => 'Veröffentlichte Einträge',
			'publish_deleted'        => 'Gelöschte Einträge',
			'publish_personal_data'  => 'Einträge mit zurückgewiesenen persönlichen Angaben',
			'contact_total'          => 'Kontaktversuche gesamt',
			'contact_valid_email'    => 'Kontaktanfragen mit passender Mitgliedsnummer und E-Mail-Adresse',
			'contact_invalid_email'  => 'Abgewiesene Kontaktanfragen: Nummer und E-Mail-Adresse passen nicht zu einem Mitglied, das Mitglied ist für diesen Arbeitsdienst nicht angemeldet oder die Eintragung ist nicht sichtbar',
			'contact_mail_sent'      => 'Kontakt-E-Mails an Mitglieder übergeben',
			'contact_requester_mail' => 'Bestätigungen an Anfragende übergeben',
			'bot_honeypot'           => 'Honeypot-Treffer',
			'bot_fast_submit'        => 'Auffällig schnelle Absendungen',
			'mail_send_failed'       => 'Fehlgeschlagene E-Mail-Übermittlungen',
			'signup_total'           => 'Anmeldeformulare für Arbeitsdienste gesamt',
			'signup_valid'           => 'Anmeldungen mit passender Mitgliedsnummer und E-Mail',
			'signup_invalid'         => 'Anmeldungen ohne passendes Mitglied',
			'signup_created'         => 'Angelegte Anmeldungen',
			'signup_repeat'          => 'Anmeldungen ohne neuen Eintrag (bereits angemeldet)',
			'signup_refused_full'    => 'Anmeldungen wegen voller Belegung abgelehnt',
			'signup_unregistered'    => 'Gelöschte Anmeldungen',
			// The two counters of the backend. They are counted apart because the
			// two acts are apart: entering a member is a list, sending the mail is
			// a message, and a club that enters its board in October and writes in
			// November has two different things to look at afterwards.
			'admin_participant_added'   => 'Vom Redakteur eingetragene Anmeldungen',
			'admin_participant_notified' => 'Vom Redakteur verschickte Anmelde-E-Mails',
		);
	}

	/**
	 * Increment one daily counter.
	 *
	 * @param string $key Counter key.
	 * @param int    $amount Increment.
	 * @return void
	 */
	public function increment( $key, $amount = 1 ) {
		$key = sanitize_key( $key );
		if ( ! array_key_exists( $key, self::labels() ) ) {
			return;
		}

		$stats = get_option( FG_STATS_OPTION, array() );
		if ( ! is_array( $stats ) ) {
			$stats = array();
		}

		$today = current_time( 'Y-m-d' );
		if ( ! isset( $stats[ $today ] ) || ! is_array( $stats[ $today ] ) ) {
			$stats[ $today ] = array();
		}

		$stats[ $today ][ $key ] = isset( $stats[ $today ][ $key ] )
			? (int) $stats[ $today ][ $key ] + max( 0, (int) $amount )
			: max( 0, (int) $amount );

		update_option( FG_STATS_OPTION, $stats, false );
	}

	/**
	 * Get counters for the last N days, including today.
	 *
	 * @param int $days Number of days.
	 * @return array<string, int>
	 */
	public function get_recent( $days = 30 ) {
		$days   = max( 1, absint( $days ) );
		$stats  = get_option( FG_STATS_OPTION, array() );
		$result = array_fill_keys( array_keys( self::labels() ), 0 );

		if ( ! is_array( $stats ) ) {
			return $result;
		}

		$timezone = wp_timezone();
		$today    = current_datetime()->setTimezone( $timezone );

		for ( $offset = 0; $offset < $days; $offset++ ) {
			$date = $today->modify( '-' . $offset . ' days' )->format( 'Y-m-d' );
			if ( isset( $stats[ $date ] ) && is_array( $stats[ $date ] ) ) {
				foreach ( $result as $key => $unused ) {
					$result[ $key ] += isset( $stats[ $date ][ $key ] ) ? (int) $stats[ $date ][ $key ] : 0;
				}
			}
		}

		return $result;
	}

	/**
	 * Get the stored daily counters.
	 *
	 * @return array<string, array<string, int>>
	 */
	public function get_daily() {
		$stats = get_option( FG_STATS_OPTION, array() );

		return is_array( $stats ) ? $stats : array();
	}

	/**
	 * Remove counters older than the configured retention period.
	 *
	 * @return void
	 */
	public function cleanup() {
		$stats = get_option( FG_STATS_OPTION, array() );
		if ( ! is_array( $stats ) || empty( $stats ) ) {
			return;
		}

		$cutoff = current_datetime()
			->modify( '-' . FG_STATISTICS_RETENTION_DAYS . ' days' )
			->format( 'Y-m-d' );

		foreach ( array_keys( $stats ) as $date ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) || $date < $cutoff ) {
				unset( $stats[ $date ] );
			}
		}

		update_option( FG_STATS_OPTION, $stats, false );
	}
}
