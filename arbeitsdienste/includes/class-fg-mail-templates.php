<?php
/**
 * HTML layout for the transactional e-mails.
 *
 * @package Arbeitsdienste
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the HTML alternative of the plugin's e-mails.
 *
 * Every message exists once, as plain text. The HTML is generated from that
 * text, so the two alternatives of the mail cannot drift apart and the wording
 * of a message is written in exactly one place.
 *
 * The layout is a table based document with inline styles, because that is what
 * mail clients still render reliably. It is deliberately not editable from the
 * admin: only the logo and the footer text are configurable. That keeps this
 * file the one place where markup is written, and it means no arbitrary HTML
 * can be pushed into every outgoing message.
 */
final class FG_Mail_Templates {
	/**
	 * Read the stored settings and fill in defaults for what is not set yet.
	 *
	 * The footer is one field. Up to version 1.9.0 it was three — sender, contact
	 * and legal notice — and an installation that has not saved its settings
	 * since then still carries the three names in the option. They are joined in
	 * the order they were entered, separated by a blank line, so nothing is lost
	 * and nobody has to type a legal notice a second time. The next save writes
	 * the one field and drops the three; the fallback is not needed afterwards.
	 *
	 * The footer is mandatory in the admin, so an empty value here means the
	 * settings were never saved and the footer stays empty.
	 *
	 * @return array{logo_attachment_id:int,footer:string}
	 */
	public static function get_settings() {
		$stored = get_option( FG_SETTINGS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$text = static function ( $value ) {
			if ( ! is_scalar( $value ) ) {
				return '';
			}

			// A textarea in a browser sends CRLF and the stored value keeps it.
			// nl2br() below only knows the LF, so every line break would carry a
			// stray CR into the mail.
			return trim( str_replace( "\r\n", "\n", (string) $value ) );
		};

		$footer = $text( isset( $stored['footer'] ) ? $stored['footer'] : null );

		if ( '' === $footer ) {
			$footer = implode(
				"\n\n",
				array_filter(
					array(
						$text( isset( $stored['footer_organisation'] ) ? $stored['footer_organisation'] : null ),
						$text( isset( $stored['footer_contact'] ) ? $stored['footer_contact'] : null ),
						$text( isset( $stored['footer_legal'] ) ? $stored['footer_legal'] : null ),
					),
					static function ( $part ) {
						return '' !== $part;
					}
				)
			);
		}

		return array(
			'logo_attachment_id' => isset( $stored['logo_attachment_id'] ) ? absint( $stored['logo_attachment_id'] ) : 0,
			'footer'             => $footer,
		);
	}

	/**
	 * Is something else sending the messages of this site?
	 *
	 * A mail plugin takes the message over at `pre_wp_mail` and builds it
	 * itself. That is the normal case on a club site, and it is where this
	 * plugin lost its whole layout until version 1.21.0: the layout was attached
	 * to the `phpmailer_init` action, and a plugin that builds the message on
	 * its own never fires it.
	 *
	 * The club should be able to see that something else is in charge, because
	 * two things follow from it and neither is visible in a received mail: the
	 * plain-text alternative this plugin hands over is dropped, and any other
	 * wording the club's mail plugin adds is added to the layout instead of to
	 * the text part.
	 *
	 * **What this cannot see, and it is worth knowing before it is trusted:** an
	 * extension that only *applies* `pre_wp_mail` without ever adding to it. There
	 * is one in the test environment, and it is the shape that lost the layout in
	 * the first place. The layout arrives there since 1.21.0, and what is lost
	 * there is the text part alone — but the screen stays quiet, because a
	 * registration that is not there cannot be reported.
	 *
	 * The recorder of the test environment hangs there too, and the recorder is
	 * not a mail plugin a club has; a screen that names it as one would be
	 * crying wolf in the test environment. The check is therefore for a handler
	 * that is registered under its own name, and that is the only thing a
	 * foreign handler can be asked for without calling it.
	 *
	 * @return string Name of the registered handler, or an empty string.
	 */
	public static function foreign_mail_handler() {
		global $wp_filter;

		if ( ! isset( $wp_filter['pre_wp_mail'] ) ) {
			return '';
		}

		// Two levels, not one: WP_Hook::callbacks is a list of priorities, and each
		// of them holds the registered callbacks. Reading one level gives the list
		// itself, and 'function' out of that is nothing — which is how the first
		// version of this method reported "unbekannt" for a handler whose name was
		// sitting right there in the next level.
		foreach ( $wp_filter['pre_wp_mail']->callbacks as $callbacks ) {
			foreach ( (array) $callbacks as $registriert ) {
				$funktion = isset( $registriert['function'] ) ? $registriert['function'] : null;

				// The recorder of the test environment is the one handler that is
				// not a mail plugin, and it is named exactly. A prefix would also
				// silence a real plugin whose name happens to start the same way,
				// and a diagnostic that can be silenced by a name is not one.
				if ( 'fg_test_mail_filter' === $funktion ) {
					continue;
				}

				return static::mail_handler_name( $funktion );
			}
		}

		return '';
	}

	/**
	 * A readable name for whatever is registered as a mail handler.
	 *
	 * Three shapes are in use and all three appear in the wild: a plain function
	 * name, an array of object or class and method, and a closure. A closure is
	 * the one that needs help, because get_class() would answer "Closure" and name
	 * nobody; the class it was created in is the useful part, and if there is none
	 * then it belongs to no class and the answer says so rather than guessing.
	 *
	 * @param mixed $funktion Registered callback.
	 * @return string
	 */
	private static function mail_handler_name( $funktion ) {
		if ( is_string( $funktion ) ) {
			return $funktion;
		}

		if ( $funktion instanceof Closure ) {
			// getClosureCalledClass() hands back a ReflectionClass, not the class
			// itself, so the name has to be asked for instead of read off.
			$scope = ( new ReflectionFunction( $funktion ) )->getClosureCalledClass();

			return $scope ? $scope->getName() . ' (anonyme Funktion)' : 'eine anonyme Funktion';
		}

		if ( is_array( $funktion ) && isset( $funktion[0], $funktion[1] ) ) {
			$traeger = is_object( $funktion[0] ) ? static::mail_handler_name( $funktion[0] ) : (string) $funktion[0];

			return $traeger . '::' . $funktion[1];
		}

		if ( is_object( $funktion ) ) {
			$name = get_class( $funktion );

			// An anonymous class answers with its name, the file and the line it
			// was written on: "class@anonymous" plus a NUL byte plus
			// "/path/file.php:539$0". That is no name a club can do anything with,
			// and a plugin that registers a handler that way has not named itself
			// either. The test is on the prefix and not the other way round: a
			// condition that returns the raw name when the prefix is there would
			// print the file and the line, which is what it did.
			return 0 === strpos( $name, 'class@anonymous' ) ? 'eine anonyme Klasse' : $name;
		}

		return 'ein Händler ohne Namen';
	}

	/**
	 * The address of the configured logo, or an empty string.
	 *
	 * The address is on the club's own site, so nothing about the recipient
	 * reaches a third host. Until version 1.21.0 the logo was attached to the
	 * message as a file and referred to as `cid:logo`; that only ever worked
	 * where WordPress' own PHPMailer built the message. A mail plugin that takes
	 * the message over never fires the action the embed was attached to, and the
	 * mails went out with the layout missing altogether — which is worse than a
	 * client that blocks images, because a blocked image is still a readable
	 * mail.
	 *
	 * What the address costs is stated where it is decided: the client's address
	 * is not disclosed, but the club's own web server sees that one of its own
	 * mailboxes has loaded a file. It sees that already when a member opens any
	 * page of the site.
	 *
	 * @return string Address, or an empty string when no usable logo is set.
	 */
	public static function get_logo_url() {
		$settings = static::get_settings();
		$id       = $settings['logo_attachment_id'];

		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			return '';
		}

		return (string) wp_get_attachment_image_url( $id, 'full' );
	}

	/**
	 * Put the plain text of a message into a prepared one as the alternative.
	 *
	 * wp_mail() has no parameter for a plain-text alternative, so it is put into
	 * the AltBody property of the prepared message. PHPMailer then writes the
	 * AltBody as the first `text/plain` part, the body as the `text/html` part,
	 * and switches the content type to `multipart/alternative` on its own.
	 *
	 * Only the alternative is set, and that is what changed in version 1.21.0:
	 * Until then this method also wrote the body, because the body was not
	 * known to wp_mail() yet — the plain text was the message. The body is the
	 * layout now and comes in through wp_mail() itself, and a method that also
	 * wrote it would put a second, differently rendered one underneath.
	 *
	 * @param object $phpmailer PHPMailer instance, passed by the phpmailer_init action.
	 * @param string $text      Message text as plain text.
	 * @param array<string, array{url: string, label: string}> $links The links of the message.
	 * @return void
	 */
	public static function apply_alternative( $phpmailer, $text, $links = array() ) {
		$phpmailer->AltBody = static::plain_text( (string) $text, (array) $links );
	}

	/**
	 * The message as the text part goes out.
	 *
	 * A client that shows the text part instead of the layout would otherwise
	 * show a message without sender, without contact data and without a legal
	 * notice, and a forwarded message stays plain text for a long time. The
	 * footer therefore belongs in both alternatives, the logo does not: a text
	 * part has no images, and the From: header already names the site.
	 *
	 * The footer is taken from the same place the layout takes it, so there is
	 * one place where a club's contact data stands and not two.
	 *
	 * The link placeholders are still in the text when it comes here, and they
	 * are finished here: the text part gets the address as a bare line, the
	 * layout gets an anchor. Since version 1.21.0 neither of the two parts is
	 * what goes into wp_mail() — the layout is — and what every filter on
	 * wp_mail() sees is the finished HTML. A message that arrives at such a
	 * filter with a placeholder in it is a message that is half written, so
	 * both parts are written from the same text and each finishes it for itself.
	 *
	 * @param string $text  Message text as plain text.
	 * @param array<string, array{url: string, label: string}> $links The links of the message.
	 * @return string
	 */
	public static function text_part( $text, $links = array() ) {
		$ersetzungen = array();
		$text        = static::mark_links( (string) $text, (array) $links, $ersetzungen, 'text' );

		return $ersetzungen ? strtr( $text, $ersetzungen ) : $text;
	}

	/**
	 * The message as the text part goes out.
	 *
	 * A client that shows the text part instead of the layout would otherwise
	 * show a message without sender, without contact data and without a legal
	 * notice, and a forwarded message stays plain text for a long time. The
	 * footer therefore belongs in both alternatives, the logo does not: a text
	 * part has no images, and the From: header already names the site.
	 *
	 * The footer is taken from the same place the layout takes it, so there is
	 * one place where a club's contact data stands and not two.
	 *
	 * @param string $text  Message text as plain text.
	 * @param array<string, array{url: string, label: string}> $links The links of the message.
	 * @return string
	 */
	public static function plain_text( $text, $links = array() ) {
		$text   = static::text_part( (string) $text, (array) $links );
		$footer = static::get_settings()['footer'];

		if ( '' === $footer ) {
			return $text;
		}

		return rtrim( $text ) . "\n\n" . $footer;
	}

	/**
	 * Render the HTML alternative of a message.
	 *
	 * @param string      $subject  Message subject.
	 * @param string      $text     Message text as plain text.
	 * @param string|null $logo_src Image address for the logo row, or null to
	 *                              use the configured logo. An empty string
	 *                              omits the row.
	 * @param array<string, array{url: string, label: string}> $links The links of the message.
	 * @return string
	 */
	public static function render( $subject, $text, $logo_src = null, $links = array() ) {
		$subject = sanitize_text_field( (string) $subject );

		if ( null === $logo_src ) {
			$logo_src = static::get_logo_url();
		}

		$replacements = array(
			'{{SUBJECT}}'  => esc_html( $subject ),
			'{{HEADLINE}}' => esc_html( $subject ),
			'{{LOGO}}'     => static::logo_block( (string) $logo_src ),
			'{{CONTENT}}'  => static::paragraphs( (string) $text, (array) $links ),
			'{{FOOTER}}'   => static::footer_block(),
		);

		// strtr() replaces the longest keys first and does not look at the
		// text it inserted, so a value that happens to contain a token stays
		// untouched.
		return strtr( self::LAYOUT, $replacements );
	}

	/**
	 * Turn the plain text of a message into the paragraphs of the copy block.
	 *
	 * A blank line separates two paragraphs, a single line break inside a
	 * paragraph becomes a line break in the HTML. Every value is escaped: the
	 * text carries the first name of a member and the origin area a visitor
	 * typed in.
	 *
	 * The links of a message are put in after the escaping, never through it. A
	 * placeholder for a link is therefore taken out of the text first and put
	 * back as a mark made of characters that esc_html() leaves alone. Escaping
	 * then does its work on everything a club or a visitor typed, and the mark
	 * is still there to be exchanged for the link.
	 *
	 * @param string $text  Message text as plain text.
	 * @param array<string, array{url: string, label: string}> $links The links of the message.
	 * @return string
	 */
	private static function paragraphs( $text, array $links = array() ) {
		$anker = array();
		$text  = static::mark_links( (string) $text, $links, $anker, 'html' );

		$html = '';

		foreach ( preg_split( '/\n\s*\n/', trim( $text ) ) as $block ) {
			$lines = array_filter(
				array_map( 'trim', explode( "\n", $block ) ),
				static function ( $line ) {
					return '' !== $line;
				}
			);

			if ( ! $lines ) {
				continue;
			}

			$html .= '<p style="font-size: 1em; margin: 1em 0">'
				. implode( '<br>', array_map( 'esc_html', $lines ) )
				. "</p>\n";
		}

		// strtr() replaces the longest keys first and does not look at the text
		// it inserted, so a link that happens to contain a mark stays untouched.
		return $anker ? strtr( $html, $anker ) : $html;
	}

	/**
	 * Take the link placeholders out of a text and put their links in their place.
	 *
	 * A link placeholder is either the bare name or the name with a colon and
	 * the wording of the link behind it. The wording belongs to the club, and it
	 * is the one string of a message that ends up inside the html, so it is
	 * escaped here like every other value. A stored text that names a link
	 * without a wording gets the default of that message, and a link that is
	 * listed but carries no address at all is written as its wording alone — an
	 * anchor with an empty target would be a link to nowhere.
	 *
	 * @param string $text          Message text as plain text.
	 * @param array<string, array{url: string, label: string}> $links The links of the message.
	 * @param array<string, string> $out   Replacements by mark, filled in here.
	 * @param string $format         Either "html" or "text".
	 * @return string
	 */
	private static function mark_links( $text, array $links, array &$out, $format ) {
		if ( ! $links ) {
			return $text;
		}

		$namen = array();

		foreach ( array_keys( $links ) as $name ) {
			$namen[] = preg_quote( $name, '/' );
		}

		$ersetzt = static function ( $treffer ) use ( $links, &$out, $format ) {
			$name    = $treffer[1];
			$wortlaut = isset( $treffer[2] ) ? trim( $treffer[2] ) : '';
			$link    = $links[ $name ];

			if ( '' === $wortlaut ) {
				$wortlaut = (string) $link['label'];
			}

			$url = (string) $link['url'];

			if ( '' === $url ) {
				return $wortlaut;
			}

			$ersetzung = 'text' === $format ? static::link_as_text( $url, $wortlaut ) : static::anchor( $url, $wortlaut );

			if ( 'text' === $format ) {
				return $ersetzung;
			}

			// Two characters that no html entity needs and that a line of a text
			// does not carry, so the mark survives the escaping untouched.
			$mark = "\x01" . count( $out ) . "\x02";
			$out[ $mark ] = $ersetzung;

			return $mark;
		};

		return preg_replace_callback(
			'/\{\{(' . implode( '|', $namen ) . ')(?::([^{}]*))?\}\}/',
			$ersetzt,
			$text
		);
	}

	/**
	 * A link for the html part.
	 *
	 * The address goes through esc_url() and the wording through esc_html(), so
	 * a stored text cannot bring markup into the message. The wording is what a
	 * reader clicks; a link that shows an address of ninety-five characters
	 * pushes every line after it out of the column and is unreadable on a phone.
	 *
	 * @param string $url     Address.
	 * @param string $wortlaut Wording of the link.
	 * @return string
	 */
	private static function anchor( $url, $wortlaut ) {
		$url = esc_url( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		$wortlaut = trim( (string) $wortlaut );

		return '<a href="' . $url . '" style="color: #1a82e2; text-decoration: underline">'
			. esc_html( '' === $wortlaut ? $url : $wortlaut )
			. '</a>';
	}

	/**
	 * A link for the text part.
	 *
	 * @param string $url     Address.
	 * @param string $wortlaut Wording of the link.
	 * @return string
	 */
	private static function link_as_text( $url, $wortlaut ) {
		$wortlaut = trim( (string) $wortlaut );

		return '' === $wortlaut ? (string) $url : $wortlaut . ': ' . $url;
	}

	/**
	 * Build the logo row.
	 *
	 * The logo links to the site, which is where the mail was triggered from.
	 * The address is not configurable, because a configurable link target
	 * would be a second place to maintain a club address.
	 *
	 * @param string $src Image address.
	 * @return string Empty string when no logo should be shown.
	 */
	private static function logo_block( $src ) {
		if ( '' === $src ) {
			return '';
		}

		// A `cid:` address is not an allowed protocol for esc_url(), and the
		// value is a constant of this class, not user input.
		$src = 0 === strpos( $src, 'cid:' ) ? esc_attr( $src ) : esc_url( $src );

		if ( '' === $src ) {
			return '';
		}

		$site = esc_url( home_url( '/' ) );
		$alt  = esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );

		return "\t<!-- start logo -->\n"
			. "\t<tr>\n"
			. "\t\t<td align=\"center\" bgcolor=\"#f1f1f1\" style=\"-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt\">\n"
			. "\t\t<!--[if (gte mso 9)|(IE)]>\n"
			. "\t\t<table align=\"center\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"600px\">\n"
			. "\t\t<tr>\n"
			. "\t\t<td align=\"center\" valign=\"top\" width=\"600px\">\n"
			. "\t\t<![endif]-->\n"
			. "\t\t<table border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; border-collapse: collapse !important; max-width: 600px\">\n"
			. "\t\t\t<tr>\n"
			. "\t\t\t\t<td align=\"center\" valign=\"top\" style=\"-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; padding: 24px 0\">\n"
			. "\t\t\t\t\t\t<a style=\"-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; color: #1a82e2; text-decoration: none; display: inline-block\" href=\"$site\"><img src=\"$src\" alt=\"$alt\" border=\"0\" style=\"-ms-interpolation-mode: bicubic; line-height: 100%; text-decoration: none; outline: none; border: none; display: block; height: auto; max-height: 200px; width: auto; max-width: 100%; min-width: 48px\"></a>\n"
			. "\t\t\t\t\t</td>\n"
			. "\t\t\t</tr>\n"
			. "\t\t</table>\n"
			. "\n"
			. "\t\t<!--[if (gte mso 9)|(IE)]>\n"
			. "\t\t</td>\n"
			. "\t\t</tr>\n"
			. "\t\t</table>\n"
			. "\t\t<![endif]-->\n"
			. "\t\t</td>\n"
			. "\t</tr>\n"
			. "\t<!-- end logo -->";
	}

	/**
	 * Build the footer from the configured text.
	 *
	 * One field, one block. A line break inside it stays a line break, which is
	 * how the address and the contact block are written; a blank line in the
	 * field leaves a blank line in the mail, so the sections can be told apart
	 * without a second field.
	 *
	 * @return string
	 */
	private static function footer_block() {
		$value = static::get_settings()['footer'];

		if ( '' === $value ) {
			return '';
		}

		return '<p style="font-size: 1em; margin: 0; padding: 0">'
			. nl2br( esc_html( $value ) )
			. "</p>\n";
	}

	/**
	 * The mail layout.
	 *
	 * Tables and inline styles, no external stylesheet, no web font, no
	 * background image. Everything a client needs is in the document itself.
	 * The Outlook conditionals are the reason for the repeated wrapper tables.
	 *
	 * The headline and the copy are two white boxes on the grey ground, and they
	 * touch. Both of their containers therefore carry no padding at all: the
	 * horizontal spacing sits in the white cell, where it is 24px on both boxes
	 * and they line up at every window width. Padding the container instead would
	 * put a grey bar between the two boxes, and on a narrow window it would also
	 * make the copy 48px narrower than the headline above it.
	 *
	 * A size that is relative to the body belongs on the cell that holds the text,
	 * not on its container. `font-size: 0.8em` used to sit on the container of the
	 * copy and came out at 11.2px, because the body is 14px; the same cell also
	 * carried the grey of the footer. The copy now says 14px in the colour of the
	 * body, and the footer says 12px.
	 *
	 * The tokens are replaced in render().
	 */
	const LAYOUT = <<<'FG_MAIL_LAYOUT'
<!DOCTYPE html>
<html lang="de">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
	<meta http-equiv="x-ua-compatible" content="ie=edge">
	<title>{{SUBJECT}}</title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	
<style type="text/css">

	
	body,
	table,
	td,
	div,
	p,
	a {
		-ms-text-size-adjust: 100%; 
		-webkit-text-size-adjust: 100%; 
	}

	body,
	table.body-wrap {
		overflow: auto;
		box-sizing: border-box;
		color: #111111;
		font-family: Arial, Helvetica, sans-serif;
		font-size: 14px;
		line-height: 1.5;
		font-weight: normal;
		font-style: normal;
		background-color: #f1f1f1;
	}

	div,
	ol,
	ul,
	p {
		font-size: 1em;
	}

	p, ul, ol, h1, h2, h3, h4, h5, h6  {
		margin: 1em 0;
	}

	.footer p{
		margin: 0;
		padding: 0;
	}

	body {
		width: 100% !important;
		height: 100% !important;
		padding: 0 !important;
		margin: 0 !important;
	}

	table {
		border-collapse: collapse !important;
	}

	h1, h2, h3, h4, h5, h6 {
		font-weight: 700;
	}

	h1 {
		font-size: 32px;
		line-height: 48px;
	}

	h2{
		font-size: 28px;
		line-height: 36px;
	}

	h3 {
		font-size: 24px;
		line-height: 30px;
	}

	h4 {
		font-size: 20px;
		line-height: 26px;
	}

	h5 {
		font-size: 18px;
		line-height: 22px;
	}

	h6 {
		font-size: 16px;
		line-height: 20px;
	}

	img, figure {
		height: auto;
		line-height: 100%;
		text-decoration: none;
		border: 0;
		outline: none;
		max-width: 100%;
	}

</style>
</head>
<body style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; overflow: auto; box-sizing: border-box; color: #111111; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.5; font-weight: normal; font-style: normal; width: 100% !important; height: 100% !important; padding: 0 !important; margin: 0 !important; background-color: #f1f1f1">
<div style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; display: none !important; max-width: 0; max-height: 0; overflow: hidden; font-size: 1px; line-height: 1px; color: #fff; opacity: 0">{{SUBJECT}}</div>

	<!--[if mso]>
		<style type="text/css">
			table,
			td,
			div,
			p,
			a {
				font-family: Arial, sans-serif;
			}
		</style>
	<![endif]-->
	<!-- start body -->
	<table class="body-wrap" border="0" cellpadding="0" cellspacing="0" width="100%" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; border-collapse: collapse !important; overflow: auto; box-sizing: border-box; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.5; font-weight: normal; font-style: normal; background-color: #f1f1f1; margin-top: 10px; color: #111111" bgcolor="#f1f1f1">

	
{{LOGO}}

	
		<!-- start hero -->
		<tr>
			<td align="center" bgcolor="#f1f1f1" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt">
			<!--[if (gte mso 9)|(IE)]>
					<table align="center" border="0" cellpadding="0" cellspacing="0" width="600px">
					<tr>
						<td align="center" valign="top" width="600px">
					<![endif]-->
					<table border="0" cellpadding="0" cellspacing="0" width="100%" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; border-collapse: collapse !important; max-width: 600px">
						<tr>
							<td align="left" bgcolor="#ffffff" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; padding: 36px 24px 0; border-top: 3px solid #1a82e2">
									<h1 style="mso-line-height-rule: exactly; font-weight: 700; font-size: 32px; line-height: 48px; margin: 0; letter-spacing: -1px">{{HEADLINE}}</h1>
							</td>
					</tr>
					</table>
				<!--[if (gte mso 9)|(IE)]>
					 </td>
				</tr>
				</table>
			<![endif]-->
		</td>
	</tr>
		<!-- end hero -->

			<!-- start copy block -->
	<tr>
			   <td align="center" bgcolor="#f1f1f1" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; padding: 0">
			<!--[if (gte mso 9)|(IE)]>
					<table align="center" border="0" cellpadding="0" cellspacing="0" width="600px">
					<tr>
						<td align="center" valign="top" width="600px">
				<![endif]-->
				<table border="0" cellpadding="0" cellspacing="0" width="100%" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; border-collapse: collapse !important; max-width: 600px">
				<!-- start copy -->
					<tr>
						<td align="left" bgcolor="#ffffff" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; padding: 12px 24px; font-size: 14px; color: #111111">
								{{CONTENT}}
						</td>
					</tr>
				<!-- end copy -->
				</table>
			<!--[if (gte mso 9)|(IE)]>
				 </td>
				</tr>
				</table>
			<![endif]-->
		</td>
	</tr>
	<!-- end copy block -->
	<!-- start footer -->
		<tr>
			<td align="center" bgcolor="#f1f1f1" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; padding: 24px" class="footer">
			<!--[if (gte mso 9)|(IE)]>
					<table align="center" border="0" cellpadding="0" cellspacing="0" width="600px">
					<tr>
						<td align="center" valign="top" width="600px">
				<![endif]-->
				<table border="0" cellpadding="0" cellspacing="0" width="100%" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; border-collapse: collapse !important; max-width: 600px">

				<!-- start footer text -->
					 <tr>
					  <td align="center" bgcolor="#f1f1f1" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; padding: 12px 24px; font-size: 12px; line-height: 18px; color: #666666">
							{{FOOTER}}
					  </td>
					 </tr>
				<!-- end footer text -->

			 </table>
			<!--[if (gte mso 9)|(IE)]>
			 </td>
				</tr>
				</table>
			<![endif]-->
		</td>
	</tr>
	<!-- end footer -->

	</table>
	<!-- end body -->

</body>
</html>
FG_MAIL_LAYOUT;
}
