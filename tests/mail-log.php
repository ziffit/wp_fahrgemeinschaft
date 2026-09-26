<?php
/**
 * Plugin Name: FG Mail Log (test environment)
 * Description: Records every wp_mail() call of the site so the functional suite can inspect recipient, headers, content type and body. Never part of the plugin.
 *
 * @package Fahrgemeinschaften
 */

defined( 'ABSPATH' ) || exit;

const FG_TEST_MAIL_TABLE = 'fg_test_mail_log';

/**
 * Check if the test mail log is enabled.
 *
 * Disabled by default so external mail simulators (like ShureMail) can receive
 * mails. The functional test suite explicitly enables it during bootstrap.
 *
 * @return bool
 */
function fg_test_mail_is_enabled() {
	// Allow constant override.
	if ( defined( 'FG_TEST_MAIL_ENABLED' ) && FG_TEST_MAIL_ENABLED ) {
		return true;
	}

	// Check option (set by tests/bootstrap).
	if ( '1' === (string) get_option( 'fg_test_mail_enabled', '0' ) ) {
		return true;
	}

	return false;
}

/**
 * Table name including the site prefix.
 *
 * @return string
 */
function fg_test_mail_table() {
	global $wpdb;
	return $wpdb->prefix . FG_TEST_MAIL_TABLE;
}

/**
 * Create the log table.
 *
 * @return void
 */
function fg_test_mail_install() {
	global $wpdb;

	$table = fg_test_mail_table();
	if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$collate = $wpdb->get_charset_collate();

	dbDelta(
		"CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			mail_to TEXT NOT NULL,
			mail_subject TEXT NOT NULL,
			mail_headers LONGTEXT NOT NULL,
			mail_content_type VARCHAR(60) NOT NULL DEFAULT '',
			mail_body LONGTEXT NOT NULL,
			mail_result TINYINT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id)
		) {$collate};"
	);
}

// Disabled state must not touch the database either: no filter, no table.
if ( ! fg_test_mail_is_enabled() ) {
	return;
}

fg_test_mail_install();

/**
 * Read the effective content type of a message.
 *
 * @param string|array $headers Headers as passed to wp_mail().
 * @param string       $type    Content type the mailer asked for.
 * @return string
 */
function fg_test_mail_content_type( $headers, $type ) {
	$list = array();
	if ( is_array( $headers ) ) {
		$list = $headers;
	} elseif ( is_string( $headers ) ) {
		$list = preg_split( '/\r\n|\r|\n/', $headers );
	}

	$found = is_string( $type ) ? $type : '';
	foreach ( $list as $header ) {
		if ( ! is_string( $header ) ) {
			continue;
		}
		if ( stripos( $header, 'content-type:' ) === 0 ) {
			$found = trim( substr( $header, strlen( 'content-type:' ) ) );
		}
	}

	return $found;
}

/**
 * Record a message and simulate the transport.
 *
 * @param null|bool $short_circuit Result of a previous filter.
 * @param array    atts          wp_mail() arguments.
 * @return bool
 */
function fg_test_mail_filter( $short_circuit, $atts ) {
	global $wpdb;

	if ( null !== $short_circuit ) {
		return $short_circuit;
	}

	$headers = isset( $atts['headers'] ) ? $atts['headers'] : '';
	$text    = fg_test_mail_content_type( $headers, 'text/plain' );
	$fail    = '1' === (string) get_option( 'fg_test_mail_fail', '' );
	$result  = $fail ? false : true;
	$to      = isset( $atts['to'] ) ? (array) $atts['to'] : array();

	$wpdb->insert(
		fg_test_mail_table(),
		array(
			'mail_to'           => implode( ', ', $to ),
			'mail_subject'      => (string) ( isset( $atts['subject'] ) ? $atts['subject'] : '' ),
			'mail_headers'      => is_array( $headers ) ? wp_json_encode( $headers ) : (string) $headers,
			'mail_content_type' => $text,
			'mail_body'         => (string) ( isset( $atts['message'] ) ? $atts['message'] : '' ),
			'mail_result'       => $result ? 1 : 0,
			'created_at'        => gmdate( 'Y-m-d H:i:s' ),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
	);

	return $result;
}

// Only record if the test mail log is enabled. Disabled means: let the mail
// travel through to the real transport (SMTP, ShureMail, or any other handler).
if ( fg_test_mail_is_enabled() ) {
	add_filter( 'pre_wp_mail', 'fg_test_mail_filter', 10, 2 );
}
