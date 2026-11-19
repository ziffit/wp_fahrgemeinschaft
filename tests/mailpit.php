<?php
/**
 * Plugin Name: FG Mailpit (test environment)
 * Description: Routes every wp_mail() call of the site to the Mailpit service of the compose stack, so a manual test can read the message in the Mailpit web UI. Never part of the plugin.
 *
 * @package Fahrgemeinschaften
 */

defined( 'ABSPATH' ) || exit;

const FG_MAILPIT_HOST = 'mailpit';
const FG_MAILPIT_PORT = 1025;

/**
 * Hand every message to Mailpit instead of the (missing) local sendmail.
 *
 * The recorder in mail-log.php short-circuits wp_mail() before PHPMailer runs,
 * so this takes effect only while the recorder is off — the state setup.sh and
 * run-all.sh hand the instance over in, and the state a manual test wants.
 *
 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer of WordPress.
 * @return void
 */
function fg_mailpit_init( $phpmailer ) {
	$phpmailer->isSMTP();
	$phpmailer->Host       = FG_MAILPIT_HOST;
	$phpmailer->Port       = FG_MAILPIT_PORT;
	$phpmailer->SMTPAuth   = false;
	$phpmailer->SMTPSecure = '';
	// Mailpit bietet kein STARTTLS. Ohne das hier versucht PHPMailer es erst und
	// wartet auf eine Antwort, die nicht kommt.
	$phpmailer->SMTPAutoTLS = false;
}

add_action( 'phpmailer_init', 'fg_mailpit_init' );
