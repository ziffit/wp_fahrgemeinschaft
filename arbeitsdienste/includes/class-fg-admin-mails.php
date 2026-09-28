<?php
/**
 * The e-mails screen: wording of all five messages, placeholders, preview.
 *
 * @package Arbeitsdienste
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the one screen where the wording of the messages lives.
 *
 * Five messages, five texts, five subjects, and a set of placeholders per
 * message. The list is a table with a row per message because the question a
 * club asks first is "which of these five mails am I looking at", and a column
 * of five long textareas answers a different question.
 *
 * The wording is stored per message in `fg_mail_templates`; the defaults live
 * in FG_Mail_Texts and come back when a row is deleted. That is why the
 * "back to the default" button deletes a row instead of writing the default
 * text into it: a club that changes a message twice wants the wording of the
 * version it runs, not the wording of the version it edited with.
 */
final class FG_Admin_Mails {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_post_fg_save_mail', array( $this, 'save' ) );
		add_action( 'admin_post_fg_reset_mail', array( $this, 'reset' ) );
		add_action( 'admin_post_fg_mail_text_preview', array( $this, 'preview' ) );
	}

	/**
	 * Render the list of messages and the form of the selected one.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diesen Bereich.', 'arbeitsdienste' ) );
		}

		$keys    = FG_Mail_Texts::keys();
		$gewaehlt = $this->requested_mail( $keys );
		$mail    = $gewaehlt ? FG_Mail_Texts::get( $gewaehlt ) : null;
		$getippt = $gewaehlt ? $this->typed( $gewaehlt ) : array(
			'subject' => '',
			'body'    => '',
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'E-Mails', 'arbeitsdienste' ); ?></h1>

			<?php $this->render_hold_back(); ?>

			<p><?php esc_html_e( 'Diese fünf E-Mails verschickt das Plugin. Betreff und Text sind änderbar; die Platzhalter stehen für die Angaben, die das Plugin zum Zeitpunkt des Versands einsetzt. Die Vorschau zeigt eine E-Mail mit erfundenen Namen — die Namen des letzten Empfängers stehen bewusst nirgends auf dieser Seite.', 'arbeitsdienste' ); ?></p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'E-Mail', 'arbeitsdienste' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Betreff', 'arbeitsdienste' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Stand', 'arbeitsdienste' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Vorschau', 'arbeitsdienste' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $keys as $key ) : ?>
						<?php
						$eintrag = FG_Mail_Texts::get( $key );
						$url     = add_query_arg(
							array(
								'page' => FG_MAILS_PAGE_SLUG,
								'mail' => $key,
							),
							admin_url( 'admin.php' )
						);
						?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $eintrag['label'] ); ?></a></strong><br>
								<span class="description"><?php echo esc_html( $eintrag['description'] ); ?></span>
							</td>
							<td><code><?php echo esc_html( self::first_line( $eintrag['subject'] ) ); ?></code></td>
							<td>
								<?php if ( $eintrag['changed'] ) : ?>
									<?php
									printf(
										/* translators: %s: date and time of the last change. */
										esc_html__( 'geändert am %s', 'arbeitsdienste' ),
										esc_html( $eintrag['updated'] )
									);
									?>
								<?php else : ?>
									<?php esc_html_e( 'Standard', 'arbeitsdienste' ); ?>
								<?php endif; ?>
							</td>
							<td>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fg_mail_text_preview&mail=' . $key ), 'fg_mail_text_preview_' . $key ) ); ?>" target="_blank" rel="noopener">
									<?php esc_html_e( 'ansehen', 'arbeitsdienste' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $mail ) : ?>
				<hr>

				<h2><?php echo esc_html( $mail['label'] ); ?></h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fg_save_mail">
					<input type="hidden" name="mail" value="<?php echo esc_attr( $gewaehlt ); ?>">
					<?php wp_nonce_field( 'fg_save_mail', 'fg_mail_nonce' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="fg-mail-subject"><?php esc_html_e( 'Betreff', 'arbeitsdienste' ); ?></label></th>
							<td>
								<input type="text" id="fg-mail-subject" name="fg_mail_subject" class="large-text" value="<?php echo esc_attr( '' !== $getippt['subject'] ? $getippt['subject'] : $mail['subject'] ); ?>" maxlength="200" required>
								<span class="description"><?php esc_html_e( 'Erscheint im Postfach des Empfängers. Ein Betreff mit Platzhalter wird vor dem Versand eingesetzt.', 'arbeitsdienste' ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="fg-mail-body"><?php esc_html_e( 'Text', 'arbeitsdienste' ); ?></label></th>
							<td>
								<textarea id="fg-mail-body" name="fg_mail_body" rows="18" class="large-text" required><?php echo esc_textarea( '' !== $getippt['body'] ? $getippt['body'] : $mail['body'] ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'Der Text steht unverändert als reiner Text in der ersten Alternative der Nachricht; das Layout mit Logo und Fußzeile kommt als zweite dazu. Zeilenumbrüche bleiben Zeilenumbrüche, eine Leerzeile trennt die Abschnitte.', 'arbeitsdienste' ); ?>
								</p>

								<h3><?php esc_html_e( 'Platzhalter', 'arbeitsdienste' ); ?></h3>
								<p class="description"><?php esc_html_e( 'Ein Klick setzt den Platzhalter an die Stelle, an der der Cursor im Textfeld steht. Ohne JavaScript lässt er sich von Hand eintippen; die Liste unten steht auch dann da.', 'arbeitsdienste' ); ?></p>
								<p>
									<?php foreach ( $mail['placeholders'] as $platzhalter => $beschreibung ) : ?>
										<button type="button" class="button fg-mail-insert" data-placeholder="<?php echo esc_attr( $platzhalter ); ?>" data-target="fg-mail-body"><?php echo esc_html( $platzhalter ); ?></button>
									<?php endforeach; ?>
								</p>
								<table class="widefat striped">
									<thead>
										<tr>
											<th scope="col" style="width:22%"><?php esc_html_e( 'Platzhalter', 'arbeitsdienste' ); ?></th>
											<th scope="col"><?php esc_html_e( 'Steht an dieser Stelle für', 'arbeitsdienste' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $mail['placeholders'] as $platzhalter => $beschreibung ) : ?>
											<tr>
												<td><code><?php echo esc_html( $platzhalter ); ?></code></td>
												<td><?php echo esc_html( $beschreibung ); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Speichern', 'arbeitsdienste' ) ); ?>
				</form>

				<?php if ( $mail['changed'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_js( __( 'Den Text wirklich auf den Standard zurücksetzen? Die eigene Fassung geht verloren.', 'arbeitsdienste' ) ); ?>);">
						<input type="hidden" name="action" value="fg_reset_mail">
						<input type="hidden" name="mail" value="<?php echo esc_attr( $gewaehlt ); ?>">
						<?php wp_nonce_field( 'fg_reset_mail', 'fg_mail_reset_nonce' ); ?>
						<?php submit_button( __( 'Auf Standard zurücksetzen', 'arbeitsdienste' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'Wähle oben eine E-Mail aus, um ihren Betreff und ihren Text zu ändern.', 'arbeitsdienste' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		$this->print_insert_script();
	}

	/**
	 * Show the messages that were held back because their text named a
	 * placeholder this message does not have.
	 *
	 * @return void
	 */
	private function render_hold_back() {
		$notizen = FG_Mail_Texts::notices();

		if ( ! $notizen ) {
			return;
		}

		echo '<div class="notice notice-error fg-mail-meldung"><p><strong>';
		esc_html_e( 'Mindestens eine E-Mail ist nicht angekommen:', 'arbeitsdienste' );
		echo '</strong></p><ul style="list-style:disc;margin-left:2em">';

		foreach ( $notizen as $notiz ) {
			if ( ! is_array( $notiz ) || empty( $notiz['text'] ) ) {
				continue;
			}

			printf(
				'<li>%s <span class="description">(%s)</span></li>',
				esc_html( $notiz['text'] ),
				esc_html( isset( $notiz['when'] ) ? $notiz['when'] : '' )
			);
		}

		echo '</ul></div>';
	}

	/**
	 * Keep a rejected text, so the club does not have to type it twice.
	 *
	 * A placeholder is the easiest thing in the form to get wrong, and the
	 * screen says so only after the text was sent. Throwing it away would mean
	 * that the one mistake the form is there to catch is the one that costs the
	 * most typing. The text stands in a transient for the current user until it
	 * is saved, thrown away, or an hour old.
	 *
	 * @param string $key     Message key.
	 * @param string $subject Subject as typed.
	 * @param string $body    Body as typed.
	 * @return void
	 */
	private function keep_typed( $key, $subject, $body ) {
		set_transient(
			$this->typed_key( $key ),
			array(
				'subject' => $subject,
				'body'    => $body,
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * The typed text of a message, if it was refused and not typed again since.
	 *
	 * @param string $key Message key.
	 * @return array{subject: string, body: string}
	 */
	private function typed( $key ) {
		$stored = get_transient( $this->typed_key( $key ) );

		return is_array( $stored ) && isset( $stored['subject'], $stored['body'] )
			? array(
				'subject' => (string) $stored['subject'],
				'body'    => (string) $stored['body'],
			)
			: array(
				'subject' => '',
				'body'    => '',
			);
	}

	/**
	 * Transient name of the typed text of one message.
	 *
	 * @param string $key Message key.
	 * @return string
	 */
	private function typed_key( $key ) {
		return 'fg_mail_typed_' . get_current_user_id() . '_' . $key;
	}

	/**
	 * Store the wording of one message.
	 *
	 * @return void
	 */
	public function save() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung, die E-Mails zu ändern.', 'arbeitsdienste' ) );
		}

		check_admin_referer( 'fg_save_mail', 'fg_mail_nonce' );

		$key    = isset( $_POST['mail'] ) ? sanitize_key( wp_unslash( $_POST['mail'] ) ) : '';
		$erlaubt = FG_Mail_Texts::keys();

		if ( ! in_array( $key, $erlaubt, true ) ) {
			FG_Admin::store_notice( __( 'Diese E-Mail gibt es nicht. Es wurde nichts gespeichert.', 'arbeitsdienste' ), 'error' );
			$this->redirect_back();
		}

		$subject = $this->post_text( 'fg_mail_subject' );
		$body    = $this->post_text( 'fg_mail_body' );

		$ergebnis = FG_Mail_Texts::save( $key, $subject, $body );

		if ( true !== $ergebnis ) {
			$this->keep_typed( $key, $subject, $body );
			FG_Admin::store_notice( $ergebnis, 'error' );
			$this->redirect_back( $key );
		}

		delete_transient( $this->typed_key( $key ) );

		// The wording behind a held-back message is gone now, so the notice is
		// history and would otherwise keep saying that something is broken.
		FG_Mail_Texts::clear_notices();

		FG_Admin::store_notice( __( 'Der Text der E-Mail wurde gespeichert.', 'arbeitsdienste' ) );
		$this->redirect_back( $key );
	}

	/**
	 * Give one message its default wording back.
	 *
	 * @return void
	 */
	public function reset() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung, die E-Mails zu ändern.', 'arbeitsdienste' ) );
		}

		check_admin_referer( 'fg_reset_mail', 'fg_mail_reset_nonce' );

		$key     = isset( $_POST['mail'] ) ? sanitize_key( wp_unslash( $_POST['mail'] ) ) : '';
		$erlaubt = FG_Mail_Texts::keys();

		if ( ! in_array( $key, $erlaubt, true ) ) {
			FG_Admin::store_notice( __( 'Diese E-Mail gibt es nicht. Es wurde nichts zurückgesetzt.', 'arbeitsdienste' ), 'error' );
			$this->redirect_back();
		}

		FG_Mail_Texts::reset( $key );
		delete_transient( $this->typed_key( $key ) );

		FG_Admin::store_notice( __( 'Die E-Mail steht wieder auf dem Standard des Plugins.', 'arbeitsdienste' ) );
		$this->redirect_back( $key );
	}

	/**
	 * Show one message as it goes out, in both parts.
	 *
	 * Two parts in one look, because a change can break only one of them: a
	 * link that is in the text part and not in the layout is a link nobody
	 * clicks, and a footer that stands only in the layout is a mail without a
	 * sender. The names in here are invented, never the last recipient.
	 *
	 * @return void
	 */
	public function preview() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diesen Bereich.', 'arbeitsdienste' ) );
		}

		$keys    = FG_Mail_Texts::keys();
		$key     = isset( $_GET['mail'] ) ? sanitize_key( wp_unslash( $_GET['mail'] ) ) : '';

		if ( ! in_array( $key, $keys, true ) ) {
			wp_die( esc_html__( 'Diese E-Mail gibt es nicht.', 'arbeitsdienste' ) );
		}

		check_admin_referer( 'fg_mail_text_preview_' . $key );

		$mail = FG_Mail_Texts::preview( $key );

		if ( ! $mail ) {
			wp_die( esc_html__( 'Der Text dieser E-Mail nennt einen Platzhalter, den sie nicht kennt. Bitte korrigiere ihn, dann lässt sie sich ansehen.', 'arbeitsdienste' ) );
		}

		$settings = FG_Mail_Templates::get_settings();
		$logo_url = $settings['logo_attachment_id'] && wp_attachment_is_image( $settings['logo_attachment_id'] )
			? (string) wp_get_attachment_image_url( $settings['logo_attachment_id'], 'full' )
			: '';

		$links = isset( $mail['links'] ) ? (array) $mail['links'] : array();
		$text  = FG_Mail_Templates::plain_text( $mail['body'], $links );

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		// The composed message, for the two parts. The entry of the list, for the
		// name of the message. Two variables and not one: reusing the name for
		// both once showed the raw subject with its placeholders in it, which is
		// the one line of this screen whose whole job is to show the finished
		// wording.
		$liste = FG_Mail_Texts::get( $key );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="robots" content="noindex, nofollow">
			<title><?php echo esc_html( $liste['label'] . ' — ' . __( 'Vorschau', 'arbeitsdienste' ) ); ?></title>
		</head>
		<body style="margin:0;padding:1.5em;background:#f0f0f1;font-family:sans-serif">
			<h1 style="font-size:1.1em"><?php echo esc_html( $liste['label'] ); ?></h1>
			<p style="font-size:.9em;color:#555">
				<?php esc_html_e( 'Betreff:', 'arbeitsdienste' ); ?>
				<code><?php echo esc_html( $mail['subject'] ); ?></code>
			</p>
			<p style="font-size:.9em;color:#555">
				<?php esc_html_e( 'Alle Namen und Adressen in dieser Vorschau sind erfunden. Sie zeigt die gespeicherten Texte mit Beispielwerten, nicht den letzten Empfänger.', 'arbeitsdienste' ); ?>
			</p>

			<h2 style="font-size:1em"><?php esc_html_e( 'HTML-Teil (das Layout mit Logo und Fußzeile)', 'arbeitsdienste' ); ?></h2>
			<div style="background:#fff;border:1px solid #dcdcde;padding:1em;overflow:auto">
				<?php
				// The same render path a real message takes, with the stored logo
				// linked by its address because a browser cannot resolve a cid:.
				echo FG_Mail_Templates::render( $mail['subject'], $mail['body'], $logo_url, $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Layout with escaped values, see FG_Mail_Templates::render().
				?>
			</div>

			<h2 style="font-size:1em"><?php esc_html_e( 'Text-Teil (reiner Text, wie ihn ein Textprogramm zeigt)', 'arbeitsdienste' ); ?></h2>
			<pre style="background:#fff;border:1px solid #dcdcde;padding:1em;white-space:pre-wrap;font-size:.9em"><?php echo esc_html( $text ); ?></pre>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * The message key asked for in the address, if it is one of the five.
	 *
	 * @param string[] $keys The five keys.
	 * @return string
	 */
	private function requested_mail( array $keys ) {
		$key = isset( $_GET['mail'] ) ? sanitize_key( wp_unslash( $_GET['mail'] ) ) : '';

		return in_array( $key, $keys, true ) ? $key : '';
	}

	/**
	 * The first line of a text, for a table cell.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function first_line( $text ) {
		$zeilen = explode( "\n", (string) $text );

		return $zeilen[0];
	}

	/**
	 * Read one field of the form as text, without the HTML a browser may add.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private function post_text( $key ) {
		if ( ! isset( $_POST[ $key ] ) || is_array( $_POST[ $key ] ) || is_object( $_POST[ $key ] ) ) {
			return '';
		}

		return trim( sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) );
	}

	/**
	 * The script that puts a placeholder where the cursor is.
	 *
	 * Small on purpose and not required: the placeholder list is a table on the
	 * page, so a screen without JavaScript can still be edited. The script only
	 * saves the typing of two braces.
	 *
	 * @return void
	 */
	private function print_insert_script() {
		?>
		<script>
		( function () {
			var knoepfe = document.querySelectorAll( '.fg-mail-insert' );
			if ( ! knoepfe.length ) {
				return;
			}
			knoepfe.forEach( function ( knoopf ) {
				knoopf.addEventListener( 'click', function () {
					var feld = document.getElementById( knoopf.getAttribute( 'data-target' ) );
					if ( ! feld ) {
						return;
					}
					var stelle = feld.selectionStart;
					if ( typeof stelle !== 'number' ) {
						stelle = feld.value.length;
					}
					var stueck = knoopf.getAttribute( 'data-placeholder' );
					feld.value = feld.value.slice( 0, stelle ) + stueck + feld.value.slice( feld.selectionEnd );
					feld.focus();
					feld.selectionStart = feld.selectionEnd = stelle + stueck.length;
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Return to the screen, optionally with one message open.
	 *
	 * @param string $key Message key.
	 * @return void
	 */
	private function redirect_back( $key = '' ) {
		$args = array( 'page' => FG_MAILS_PAGE_SLUG );

		if ( $key ) {
			$args['mail'] = $key;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ), 303 );
		exit;
	}
}
