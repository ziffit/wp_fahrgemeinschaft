<?php
/**
 * MIME structure of the plugin's messages.
 *
 * The other suites read what the plugin hands to wp_mail(). That is not
 * enough for a multipart message: the plain text and the HTML travel as two
 * parts of one MIME structure, and wp_mail() cannot express the second part.
 * This script therefore builds the message the way wp_mail() does and hands it
 * to PHPMailer, then inspects the bytes that would go out.
 *
 * It stores a setting of its own and removes it again, so it is safe to run
 * between the other suites.
 *
 * Usage: docker exec wpdev-wordpress-1 php /tmp/fgtests/mail-mime.php
 *
 * @package Fahrgemeinschaften
 */

require '/var/www/html/wp-load.php';

$pass = 0;
$fail = 0;

/**
 * Report one check.
 *
 * @param string $label Check description.
 * @param bool   $ok   Result.
 * @param string $note Detail for a failure.
 * @return void
 */
function fg_mime_check( $label, $ok, $note = '' ) {
	global $pass, $fail;

	if ( $ok ) {
		$pass++;
		printf( "  ok   %s\n", $label );
		return;
	}

	$fail++;
	printf( "  FAIL %s%s\n", $label, '' !== $note ? ' :: ' . $note : '' );
}

/**
 * Build the message exactly as wp_mail() would.
 *
 * @param string $subject Subject.
 * @param string $text    Message text as plain text.
 * @return string The MIME message.
 */
function fg_mime_build( $subject, $text ) {
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';

	$mailer = new PHPMailer\PHPMailer\PHPMailer( true );

	// The order of wp_mail(): the message becomes the body, then the
	// phpmailer_init action runs and the plugin adds the HTML part.
	$mailer->ContentType = 'text/plain';
	$mailer->CharSet     = 'UTF-8';
	$mailer->Subject     = $subject;
	$mailer->Body        = $text;
	$mailer->setFrom( 'admin@angeln.example.org', 'Test' );
	$mailer->addAddress( 'anton@angeln.example.org' );

	FG_Mail_Templates::apply_alternative( $mailer, $subject, $text );

	foreach ( FG_Mail_Templates::get_logo_embed() as $cid => $path ) {
		$mailer->addEmbeddedImage( $path, $cid, basename( $path ), 'base64', 'image/png' );
	}

	$mailer->preSend();

	return $mailer->getSentMIMEMessage();
}

/**
 * Create a small image in the media library and return its attachment ID.
 *
 * @return int
 */
function fg_mime_logo() {
	$upload = wp_upload_dir();
	$file   = trailingslashit( $upload['path'] ) . 'fg-test-logo.png';

	// A single transparent pixel is enough: the point is the MIME structure.
	file_put_contents( $file, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' ) );

	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'FG test logo',
			'post_status'    => 'inherit',
		),
		$file
	);

	return (int) $id;
}

$previous = get_option( FG_SETTINGS_OPTION, null );

$subject = 'Fahrgemeinschaft bestätigen – Arbeitsdienst Laber';
$text    = implode(
	"\n",
	array(
		'Hallo Anton,',
		'',
		'Deine Eintragung wurde vorgemerkt, aber noch nicht veröffentlicht.',
		'',
		'Bezeichnung: Amsel & Söhne <b>',
		'',
		'Ist der Arbeitsdienst vorbei, wird dein Eintrag automatisch aus der öffentlichen Anzeige entfernt.',
		'',
		'VERÖFFENTLICHUNG BESTÄTIGEN:',
		'https://localhost:8443/?fg_ride_action=view&ride_ref=abc123&token=deadbeef',
	)
);

// One field, and a blank line between the sections: that is what tells them
// apart in the mail, so it has to survive into the HTML as a blank line.
$footer = array(
	'footer' => 'Musterverein e.V.' . "\n"
		. 'Musterstraße 1' . "\n\n"
		. '0911 / 000 000' . "\n"
		. 'info@angeln.example.org' . "\n\n"
		. 'Angaben gemäß § 5 TMG: Musterverein e.V.',
);

// --- without a logo
update_option( FG_SETTINGS_OPTION, array_merge( $footer, array( 'logo_attachment_id' => 0 ) ), false );
$mime = fg_mime_build( $subject, $text );

echo "[1] message without a logo\n";
fg_mime_check( 'content type is multipart/alternative', (bool) preg_match( '/^Content-Type: multipart\/alternative/m', $mime ) );
fg_mime_check( 'a text/plain part is present', false !== strpos( $mime, 'Content-Type: text/plain' ) );
fg_mime_check( 'a text/html part is present', false !== strpos( $mime, 'Content-Type: text/html' ) );
fg_mime_check( 'the plain text part comes first', strpos( $mime, 'Content-Type: text/plain' ) < strpos( $mime, 'Content-Type: text/html' ) );
fg_mime_check( 'the plain text is unchanged', false !== strpos( $mime, 'ride_ref=abc123&token=deadbeef' ) );
fg_mime_check( 'the html part escapes a visitor value', false !== strpos( $mime, 'Amsel &amp; Söhne &lt;b&gt;' ) );
fg_mime_check( 'the html part carries the subject as headline', false !== strpos( $mime, 'Fahrgemeinschaft bestätigen – Arbeitsdienst Laber</h1>' ) );
fg_mime_check( 'the html part carries the footer', false !== strpos( $mime, 'Musterverein e.V.' ) && false !== strpos( $mime, 'info@angeln.example.org' ) && false !== strpos( $mime, '§ 5 TMG' ) );
fg_mime_check( 'no image is referenced', false === strpos( $mime, '<img' ) );
fg_mime_check( 'no address of another host is loaded', ! preg_match( '/<(img|table|div|td)[^>]+(src|background)="https?:/i', $mime ) );

// --- with a logo
$logo_id = fg_mime_logo();
update_option( FG_SETTINGS_OPTION, array_merge( $footer, array( 'logo_attachment_id' => $logo_id ) ), false );
$mime = fg_mime_build( $subject, $text );

echo "[2] message with a logo\n";
fg_mime_check( 'content type is multipart/alternative', (bool) preg_match( '/^Content-Type: multipart\/alternative/m', $mime ) );
fg_mime_check( 'the plain text part comes first', strpos( $mime, 'Content-Type: text/plain' ) < strpos( $mime, 'Content-Type: text/html' ) );
fg_mime_check( 'the logo is embedded under the id logo', false !== strpos( $mime, 'Content-ID: <logo>' ) );
fg_mime_check( 'the logo is sent inline', (bool) preg_match( '/Content-Type: image\/png.*Content-ID: <logo>.*Content-Disposition: inline/s', $mime ) );
fg_mime_check( 'the logo is sent as base64', false !== strpos( $mime, 'Content-Transfer-Encoding: base64' ) );
fg_mime_check( 'the html part refers to the embedded logo', false !== strpos( $mime, 'src="cid:logo"' ) );
fg_mime_check( 'the logo is not loaded from a foreign address', ! preg_match( '/<img[^>]+src="https?:/i', $mime ) );
fg_mime_check( 'the embed is a file of this installation', 1 === count( FG_Mail_Templates::get_logo_embed() ) );

// --- a logo that is not an image is refused
update_option( FG_SETTINGS_OPTION, array_merge( $footer, array( 'logo_attachment_id' => 1 ) ), false );
echo "[3] a wrong logo\n";
fg_mime_check( 'an attachment that is not an image is not embedded', array() === FG_Mail_Templates::get_logo_embed() );
$mime = fg_mime_build( $subject, $text );
fg_mime_check( 'the message still goes out without a logo', false === strpos( $mime, 'cid:logo' ) );

// --- the footer: one field, and the three the version before wrote
echo "[4] the footer of the mail\n";
update_option( FG_SETTINGS_OPTION, array_merge( $footer, array( 'logo_attachment_id' => 0 ) ), false );
$neu = FG_Mail_Templates::render( $subject, $text, '' );
// A blank line in the field is what tells the sections apart, so it has to
// reach the mail as a blank line and not as a single break.
fg_mime_check( 'the blank line between two sections of the footer is one', false !== strpos( $neu, 'Musterstraße 1<br />' . "\n" . '<br />' . "\n" . '0911' ) );
// A footer that was stored as three fields, which is how it was written up to
// version 1.9.0, has to reach the mail as one block and in the order it was
// entered. Nobody may have to type a legal notice a second time after an
// update.
update_option( FG_SETTINGS_OPTION, array( 'logo_attachment_id' => 0, 'footer_organisation' => 'Musterverein e.V.', 'footer_contact' => 'info@angeln.example.org', 'footer_legal' => '§ 5 TMG' ), false );
$alte = FG_Mail_Templates::render( $subject, $text, '' );
fg_mime_check( 'the three old fields come out joined, in order and separated', false !== strpos( $alte, 'Musterverein e.V.<br />' . "\n" . '<br />' . "\n" . 'info@angeln.example.org<br />' . "\n" . '<br />' . "\n" . '§ 5 TMG' ) );
// One field is one block. The three of them used to become three paragraphs,
// and a footer that is one block is what the layout's single rule for the
// footer is written for.
$abschnitt = strstr( $alte, '<!-- start footer text -->' );
$abschnitt = false === $abschnitt ? '' : strstr( $abschnitt, '<!-- end footer text -->', true );
fg_mime_check( 'the footer is one paragraph and not one per field', substr_count( (string) $abschnitt, '<p' ) === 1 );
// The one field wins over the three: after a save the option holds one name,
// and a footer that is stored must not be added to a stale second copy of it.
update_option( FG_SETTINGS_OPTION, array( 'logo_attachment_id' => 0, 'footer' => 'Nur diese Angabe.', 'footer_legal' => 'Altes Feld' ), false );
$beide = FG_Mail_Templates::render( $subject, $text, '' );
fg_mime_check( 'a stored footer is not added to a leftover of the old fields', false !== strpos( $beide, 'Nur diese Angabe.' ) && false === strpos( $beide, 'Altes Feld' ) );
// A textarea in a browser sends CRLF, and the stored value keeps it. A line
// break that carries a stray CR would show as a broken character in a client
// that is strict about the bytes.
update_option( FG_SETTINGS_OPTION, array( 'logo_attachment_id' => 0, 'footer' => "Musterverein e.V.\r\nMusterstraße 1" ), false );
$crlf = FG_Mail_Templates::render( $subject, $text, '' );
fg_mime_check( 'a carriage return from the browser never reaches the mail', false === strpos( $crlf, "\r" ) );

// --- restore
wp_delete_attachment( $logo_id, true );

if ( null === $previous ) {
	delete_option( FG_SETTINGS_OPTION );
} else {
	update_option( FG_SETTINGS_OPTION, $previous, false );
}

echo "\n== $pass passed, $fail failed ==\n";
exit( $fail > 0 ? 1 : 0 );
