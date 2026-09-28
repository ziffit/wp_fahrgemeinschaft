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
 * @param array  $links   Links of the message, by name without braces.
 * @return string The MIME message.
 */
function fg_mime_build( $subject, $text, $links = array() ) {
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

	FG_Mail_Templates::apply_alternative( $mailer, $subject, $text, $links );

	foreach ( FG_Mail_Templates::get_logo_embed() as $cid => $path ) {
		$mailer->addEmbeddedImage( $path, $cid, basename( $path ), 'base64', 'image/png' );
	}

	$mailer->preSend();

	return $mailer->getSentMIMEMessage();
}

/**
 * The body of one part of the message.
 *
 * A check that looks for a value in the whole MIME string is a check that cannot
 * fail: the message carries the text and the layout, and whichever part holds
 * the value answers for it. That is how the footer was checked for a long time —
 * the check said "the html part carries the footer" while it searched everything,
 * so a footer that was missing from the text part went unnoticed. This reads one
 * part and only one.
 *
 * @param string $mime The MIME message.
 * @param string $type Content type to read, e.g. "text/plain".
 * @return string Part body, or an empty string when the part is not there.
 */
function fg_mime_part( $mime, $type ) {
	// The boundary stands on a continuation line of the content type, with a
	// leading blank in front of it.
	if ( ! preg_match( '/^[ \t]*boundary="?([^";\r\n]+)"?/m', $mime, $treffer ) ) {
		return '';
	}

	foreach ( explode( '--' . $treffer[1], $mime ) as $stueck ) {
		if ( false === strpos( $stueck, 'Content-Type: ' . $type ) ) {
			continue;
		}

		$teile = preg_split( "/\r?\n\r?\n/", $stueck, 2 );

		return isset( $teile[1] ) ? $teile[1] : '';
	}

	return '';
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
$textteil = fg_mime_part( $mime, 'text/plain' );
$htmlteil = fg_mime_part( $mime, 'text/html' );
fg_mime_check( 'the plain text part was read on its own', '' !== $textteil );
fg_mime_check( 'the html part was read on its own', false !== strpos( $htmlteil, '</html>' ) );
fg_mime_check( 'the plain text is unchanged', false !== strpos( $textteil, 'ride_ref=abc123&token=deadbeef' ) );
fg_mime_check( 'the html part escapes a visitor value', false !== strpos( $htmlteil, 'Amsel &amp; Söhne &lt;b&gt;' ) );
fg_mime_check( 'the html part carries the subject as headline', false !== strpos( $htmlteil, 'Fahrgemeinschaft bestätigen – Arbeitsdienst Laber</h1>' ) );
fg_mime_check( 'the html part carries the footer', false !== strpos( $htmlteil, 'Musterverein e.V.' ) && false !== strpos( $htmlteil, 'info@angeln.example.org' ) && false !== strpos( $htmlteil, '§ 5 TMG' ) );
// The footer is the sender, the contact data and the legal notice. A client that
// shows the text part, and every forwarded message for a long time, must carry
// it as well — a mail without it names nobody.
fg_mime_check( 'the plain text part carries the footer too', false !== strpos( $textteil, 'Musterverein e.V.' ) && false !== strpos( $textteil, 'info@angeln.example.org' ) && false !== strpos( $textteil, '§ 5 TMG' ) );
$textteil_lf = str_replace( "\r\n", "\n", $textteil );
fg_mime_check( 'the footer in the text part keeps the blank line between its sections', false !== strpos( $textteil_lf, "Musterstraße 1\n\n0911 / 000 000" ) );
fg_mime_check( 'the footer stands behind the text and not in the middle of it', strpos( $textteil_lf, 'Musterverein e.V.' ) > strpos( $textteil_lf, 'deadbeef' ) );
// A footer with an umlaut makes the text part non-ASCII. Without a charset that
// says UTF-8 the umlaut arrives as two question marks, and the check above would
// still be green because it looks for the ASCII lines of the footer.
fg_mime_check( 'the text part is announced as UTF-8 when it carries an umlaut', false !== strpos( $mime, 'Content-Type: text/plain; charset=UTF-8' ) );
fg_mime_check( 'the footer in the text part is not the html of the layout', false === strpos( $textteil, '<p' ) && false === strpos( $textteil, '<br' ) );
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

// --- the links of a message
// A link that is written into the html part as its bare address is not a link:
// it is not clickable in every client, and an address of ninety-five characters
// takes the column apart, so that the line after it starts somewhere else. This
// section reads both parts of a message that carries two links and looks at
// where the address stands.
echo "[5] the links in the html part\n";

$links = array(
	'Bestaetigungslink' => array(
		'url'   => 'https://localhost:8443/?fg_ride_action=view&ride_ref=abc123&token=deadbeef',
		'label' => 'Fahrgemeinschaft bestätigen',
	),
	'Verwerfungslink'   => array(
		'url'   => 'https://localhost:8443/?fg_ride_action=view&ride_ref=abc123&token=feedface',
		'label' => 'Eintragung verwerfen',
	),
);

$linktext = implode(
	"\n",
	array(
		'Hallo Anton,',
		'',
		'VERÖFFENTLICHUNG BESTÄTIGEN:',
		'{{Bestaetigungslink:Fahrgemeinschaft bestätigen}}',
		'',
		'Eintragung verwerfen:',
		'{{Verwerfungslink}}',
	)
);

$mime      = fg_mime_build( $subject, $linktext, $links );
$textteil  = fg_mime_part( $mime, 'text/plain' );
$htmlteil  = fg_mime_part( $mime, 'text/html' );

// The two addresses in their html form. esc_url() writes the ampersand as an
// entity, which is what belongs in an attribute.
preg_match_all( '#<a href="([^"]*)"[^>]*>(.*?)</a>#s', $htmlteil, $anker, PREG_SET_ORDER );
$mit_anker = array();
foreach ( $anker as $eintrag ) {
	if ( false === strpos( $eintrag[1], 'fg_ride_action' ) ) {
		continue;
	}
	$mit_anker[] = $eintrag;
}
fg_mime_check( 'the html part has one anchor per link', 2 === count( $mit_anker ), 'gefunden: ' . count( $mit_anker ) );
fg_mime_check( 'the first anchor points at the confirmation address', isset( $mit_anker[0] ) && false !== strpos( $mit_anker[0][1], 'token=deadbeef' ) );
fg_mime_check( 'the second anchor points at the discard address', isset( $mit_anker[1] ) && false !== strpos( $mit_anker[1][1], 'token=feedface' ) );
fg_mime_check( 'an ampersand in an address is written as an entity', isset( $mit_anker[0] ) && false !== strpos( $mit_anker[0][1], 'ride_ref=abc123&#038;token=deadbeef' ) );
fg_mime_check( 'the wording of the club is the text of the anchor', isset( $mit_anker[0] ) && 'Fahrgemeinschaft bestätigen' === trim( $mit_anker[0][2] ) );
// A link without a wording of its own takes the one the plugin carries, so a
// text written before the wording existed still reads as a link and not as an
// address.
fg_mime_check( 'a link without a wording takes the one of the message', isset( $mit_anker[1] ) && 'Eintragung verwerfen' === trim( $mit_anker[1][2] ) );
// The complaint of the club: an address that is only inside an href attribute
// cannot break the layout, because a reader never sees it.
$ohne_href = preg_replace( '#\shref="[^"]*"#', '', $htmlteil );
fg_mime_check( 'no address of a link is left standing in the text of the html part', false === strpos( (string) $ohne_href, 'token=deadbeef' ) && false === strpos( (string) $ohne_href, 'token=feedface' ) );
fg_mime_check( 'no placeholder is left standing in the html part', false === strpos( $htmlteil, '{{' ) );
// The text part has no links and must carry the address, or a reader who
// answers from a text client has nothing to answer with.
fg_mime_check( 'the text part has no link', false === strpos( $textteil, '<a' ) && false === strpos( $textteil, 'href' ) );
fg_mime_check( 'the text part carries the address of the confirmation', false !== strpos( $textteil, 'token=deadbeef' ) );
fg_mime_check( 'the text part carries the wording in front of the address', false !== strpos( $textteil, 'Fahrgemeinschaft bestätigen: https://localhost:8443/' ) );
fg_mime_check( 'the text part keeps the ampersand of the address', false !== strpos( $textteil, 'ride_ref=abc123&token=deadbeef' ) );

// A wording typed by the club ends up inside the html. It is escaped like every
// other value, so a stored text cannot bring markup into a message.
$links['Bestaetigungslink']['label'] = 'Fahrgemeinschaft bestätigen';
$mime     = fg_mime_build( $subject, 'Hier:\n{{Bestaetigungslink:<b>jetzt</b> öffnen & lesen}}', $links );
$htmlteil = fg_mime_part( $mime, 'text/html' );
fg_mime_check( 'a wording from the club is escaped in the html part', false !== strpos( $htmlteil, '&lt;b&gt;jetzt&lt;/b&gt; öffnen &amp; lesen' ) );
fg_mime_check( 'a wording from the club brings no markup into the html part', ! preg_match( '#<a href="[^"]*"[^>]*>[^<]*<b>#', $htmlteil ) );

// A link whose address is empty has no target. An anchor without one would be
// a link to the page it stands on.
$leer = FG_Mail_Templates::render( $subject, 'Hier:\n{{Bestaetigungslink:Eigener Wortlaut}}', '', array( 'Bestaetigungslink' => array( 'url' => '', 'label' => 'Vorgabe' ) ) );
fg_mime_check( 'a link without an address is no link', false === strpos( $leer, '<a href' ) );
fg_mime_check( 'a link without an address keeps its wording', false !== strpos( $leer, 'Eigener Wortlaut' ) );

// The body of a message still carries the token, and the two parts are what turn
// it into a link and into an address. A token that was already replaced with the
// address in the body could not be a link any more.
$komponiert = FG_Mail_Texts::compose( 'ride_published', array( 'Arbeitsdienst' => 'Flussaktion', 'Loeschlink' => $links['Bestaetigungslink']['url'] ) );
fg_mime_check( 'the body keeps the token until the parts are written', false !== strpos( $komponiert['body'], '{{Loeschlink}}' ) );
fg_mime_check( 'the message carries the address of its link', 'https://localhost:8443/?fg_ride_action=view&ride_ref=abc123&token=deadbeef' === $komponiert['links']['Loeschlink']['url'] );
fg_mime_check( 'the message carries the wording of its link', 'Fahrgemeinschaft löschen' === $komponiert['links']['Loeschlink']['label'] );

// The lists of the definitions have to agree with each other. Every name in
// "links" is also a placeholder of the same message, and it carries a wording
// the admin can read; a name that is only in one of the two lists is a link the
// message does not offer, and a check that cannot see that would call this whole
// section green.
echo "[6] the two lists of each message agree\n";
$namen_immer_gut = true;
$wortlaut_immer  = true;
foreach ( FG_Mail_Texts::mails() as $key => $mail ) {
	foreach ( (array) $mail['links'] as $platzhalter => $wortlaut ) {
		if ( ! isset( $mail['placeholders'][ $platzhalter ] ) ) {
			$namen_immer_gut = false;
			printf( "        %s: %s steht in \"links\", aber nicht in \"placeholders\"\n", $key, $platzhalter );
		}
		if ( '' === trim( (string) $wortlaut ) ) {
			$wortlaut_immer = false;
			printf( "        %s: %s hat keinen Wortlaut\n", $key, $platzhalter );
		}
		// And the sample has to carry an address, or the admin page shows a
		// link that goes nowhere.
		if ( empty( $mail['sample'][ trim( (string) $platzhalter, '{}' ) ] ) ) {
			$wortlaut_immer = false;
			printf( "        %s: %s hat keine Beispieladresse\n", $key, $platzhalter );
		}
	}
}
fg_mime_check( 'every link of every message is also a placeholder of it', $namen_immer_gut );
fg_mime_check( 'every link of every message carries a wording and an example address', $wortlaut_immer );

// A wording is only allowed behind a link of that same message. Otherwise a
// text could hide a second value behind a name that has none.
echo "[7] a wording behind a name that is no link of the message\n";
$table = FG_Schema::mail_templates_table();
global $wpdb;
$vorher = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE mail_key = %s", 'ride_published' ), ARRAY_A );

$wpdb->query( $wpdb->prepare( "INSERT INTO $table (mail_key, subject, body, updated_at) VALUES (%s, %s, %s, %s) ON DUPLICATE KEY UPDATE body = VALUES(body)", 'ride_published', 'Wort', 'Text {{Abmeldelink:Teilnahme abmelden}}', current_time( 'mysql' ) ) );
fg_mime_check( 'a wording behind a link of another message is refused', false === FG_Mail_Texts::compose( 'ride_published', array( 'Arbeitsdienst' => 'X', 'Loeschlink' => 'https://example.org/' ) ) );

$wpdb->query( $wpdb->prepare( "INSERT INTO $table (mail_key, subject, body, updated_at) VALUES (%s, %s, %s, %s) ON DUPLICATE KEY UPDATE body = VALUES(body)", 'ride_published', 'Wort', 'Text {{Art:ich biete}}', current_time( 'mysql' ) ) );
fg_mime_check( 'a wording behind a name that is no link at all is refused', false === FG_Mail_Texts::compose( 'ride_published', array( 'Arbeitsdienst' => 'X', 'Loeschlink' => 'https://example.org/' ) ) );

$wpdb->query( $wpdb->prepare( "INSERT INTO $table (mail_key, subject, body, updated_at) VALUES (%s, %s, %s, %s) ON DUPLICATE KEY UPDATE body = VALUES(body)", 'ride_published', 'Wort', 'Text {{Loeschlink:Meine Eintragung löschen}}', current_time( 'mysql' ) ) );
fg_mime_check( 'a wording behind a link of this message is allowed', false !== FG_Mail_Texts::compose( 'ride_published', array( 'Arbeitsdienst' => 'X', 'Loeschlink' => 'https://example.org/' ) ) );

// A subject is one line of text, so a link in it becomes the address. A token
// left standing in a subject would be the one line of a mail that names a
// placeholder instead of saying something.
$wpdb->query( $wpdb->prepare( "INSERT INTO $table (mail_key, subject, body, updated_at) VALUES (%s, %s, %s, %s) ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body)", 'ride_published', 'Betrifft {{Loeschlink}}', 'Text', current_time( 'mysql' ) ) );
$mit_betreff = FG_Mail_Texts::compose( 'ride_published', array( 'Arbeitsdienst' => 'X', 'Loeschlink' => 'https://example.org/?token=deadbeef' ) );
fg_mime_check( 'a link in the subject becomes the address', is_array( $mit_betreff ) && false !== strpos( $mit_betreff['subject'], 'https://example.org/?token=deadbeef' ) );
fg_mime_check( 'no token is left standing in a subject', is_array( $mit_betreff ) && false === strpos( $mit_betreff['subject'], '{{' ) );

// The complaint about a wrong name has to name the form with the colon too, or a
// club that used it and was refused does not learn from the list that it works.
$kommentar = FG_Mail_Texts::save( 'ride_published', 'Wort', 'Text {{Art:x}}' );
fg_mime_check( 'the complaint names the form with a colon as allowed', is_string( $kommentar ) && false !== strpos( $kommentar, '{{Loeschlink:Wortlaut}}' ) );

if ( $vorher ) {
	$wpdb->query( $wpdb->prepare( "INSERT INTO $table (mail_key, subject, body, updated_at) VALUES (%s, %s, %s, %s) ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), updated_at = VALUES(updated_at)", 'ride_published', $vorher['subject'], $vorher['body'], $vorher['updated_at'] ) );
} else {
	$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE mail_key = %s", 'ride_published' ) );
}
FG_Mail_Texts::clear_notices();

// --- restore
wp_delete_attachment( $logo_id, true );

if ( null === $previous ) {
	delete_option( FG_SETTINGS_OPTION );
} else {
	update_option( FG_SETTINGS_OPTION, $previous, false );
}

echo "\n== $pass passed, $fail failed ==\n";
exit( $fail > 0 ? 1 : 0 );
