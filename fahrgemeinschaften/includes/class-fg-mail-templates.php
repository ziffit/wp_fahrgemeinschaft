<?php
/**
 * HTML layout for the transactional e-mails.
 *
 * @package Fahrgemeinschaften
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
	 * Content-ID of the embedded logo.
	 *
	 * The layout refers to the logo as `cid:logo`. WordPress uses the key of
	 * the embeds array as Content-ID, so the key and this constant have to
	 * match.
	 */
	const LOGO_CID = 'logo';

	/**
	 * Read the stored settings and fill in defaults for what is not set yet.
	 *
	 * The footer fields are mandatory in the admin, so an empty value here
	 * means the settings were never saved and the footer stays empty.
	 *
	 * @return array{logo_attachment_id:int,footer_organisation:string,footer_contact:string,footer_legal:string}
	 */
	public static function get_settings() {
		$stored = get_option( FG_SETTINGS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$text = static function ( $key ) use ( $stored ) {
			if ( ! isset( $stored[ $key ] ) || ! is_scalar( $stored[ $key ] ) ) {
				return '';
			}

			return trim( (string) $stored[ $key ] );
		};

		return array(
			'logo_attachment_id' => isset( $stored['logo_attachment_id'] ) ? absint( $stored['logo_attachment_id'] ) : 0,
			'footer_organisation' => $text( 'footer_organisation' ),
			'footer_contact'      => $text( 'footer_contact' ),
			'footer_legal'        => $text( 'footer_legal' ),
		);
	}

	/**
	 * Resolve the configured logo to a file that can be embedded.
	 *
	 * An embed has to be a file on this server, which is why the logo is
	 * picked from the media library instead of being given as a URL. The
	 * address of the recipient is never leaked to another host, and a client
	 * that blocks remote images still shows the logo.
	 *
	 * @return array<string,string> Map of Content-ID to file path, empty when
	 *                             no usable logo is configured.
	 */
	public static function get_logo_embed() {
		$settings = static::get_settings();
		$id       = $settings['logo_attachment_id'];

		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			return array();
		}

		$path = get_attached_file( $id );

		if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
			return array();
		}

		return array( self::LOGO_CID => $path );
	}

	/**
	 * Put the plain text and the HTML layout into a prepared message.
	 *
	 * wp_mail() has no parameter for the plain-text alternative, so the plain
	 * text is handed over as the message and moved into the AltBody property
	 * here. PHPMailer then writes the AltBody as the first `text/plain` part
	 * and the body as the `text/html` part, and switches the content type to
	 * `multipart/alternative` on its own.
	 *
	 * @param object $phpmailer PHPMailer instance, passed by the phpmailer_init action.
	 * @param string $subject   Message subject.
	 * @param string $text      Message text as plain text.
	 * @return void
	 */
	public static function apply_alternative( $phpmailer, $subject, $text ) {
		$phpmailer->AltBody = (string) $text;
		$phpmailer->Body    = static::render( $subject, $text );
	}

	/**
	 * Render the HTML alternative of a message.
	 *
	 * @param string      $subject  Message subject.
	 * @param string      $text     Message text as plain text.
	 * @param string|null $logo_src Image address for the logo row, or null to
	 *                              embed the configured logo as `cid:logo`.
	 *                              An empty string omits the row.
	 * @return string
	 */
	public static function render( $subject, $text, $logo_src = null ) {
		$subject = sanitize_text_field( (string) $subject );

		if ( null === $logo_src ) {
			$logo_src = static::get_logo_embed() ? 'cid:' . self::LOGO_CID : '';
		}

		$replacements = array(
			'{{SUBJECT}}'  => esc_html( $subject ),
			'{{HEADLINE}}' => esc_html( $subject ),
			'{{LOGO}}'     => static::logo_block( (string) $logo_src ),
			'{{CONTENT}}'  => static::paragraphs( (string) $text ),
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
	 * text carries the alias and the origin area a visitor typed in.
	 *
	 * @param string $text Message text as plain text.
	 * @return string
	 */
	private static function paragraphs( $text ) {
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

		return $html;
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
	 * The three fields become three paragraphs, the order they are stored in.
	 * A line break inside a field stays a line break, which is how the postal
	 * address and the contact block are written.
	 *
	 * @return string
	 */
	private static function footer_block() {
		$settings = static::get_settings();
		$fields   = array(
			'footer_organisation',
			'footer_contact',
			'footer_legal',
		);

		$html = '';

		foreach ( $fields as $field ) {
			$value = $settings[ $field ];

			if ( '' === $value ) {
				continue;
			}

			$html .= '<p style="font-size: 1em; margin: 0; padding: 0">'
				. nl2br( esc_html( $value ) )
				. "</p>\n";
		}

		return $html;
	}

	/**
	 * The mail layout.
	 *
	 * Tables and inline styles, no external stylesheet, no web font, no
	 * background image. Everything a client needs is in the document itself.
	 * The Outlook conditionals are the reason for the repeated wrapper tables.
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
			   <td align="center" bgcolor="#f1f1f1" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; padding: 12px 24px; font-size: 0.8em; line-height: 20px; color: #666666">
			<!--[if (gte mso 9)|(IE)]>
					<table align="center" border="0" cellpadding="0" cellspacing="0" width="600px">
					<tr>
						<td align="center" valign="top" width="600px">
				<![endif]-->
				<table border="0" cellpadding="0" cellspacing="0" width="100%" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; border-collapse: collapse !important; max-width: 600px">
				<!-- start copy -->
					<tr>
						<td align="left" bgcolor="#ffffff" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; padding: 12px 24px">
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
					  <td align="center" bgcolor="#f1f1f1" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; padding: 12px 24px; font-size: 0.8em; line-height: 20px; color: #666666">
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
