<?php
/**
 * Settings screen: logo and footer of the e-mails.
 *
 * @package Arbeitsdienste
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the one settings screen of the plugin.
 *
 * The screen configures what a club puts into its mails and nothing else. The
 * layout itself is not part of the screen on purpose: a field for raw HTML
 * would be the only way to push arbitrary markup into every message the site
 * sends, so the layout stays in the code and only its values are configurable.
 *
 * The footer is one field and it is mandatory. A mail without a sender
 * address and a legal notice is the one mistake that cannot be undone
 * afterwards, so an empty footer is refused instead of being stored and
 * discovered in a real mail. It was three fields up to version 1.9.0; what the
 * three said is still what the one field says, and FG_Mail_Templates::get_settings()
 * joins the old values for an installation that has not saved since.
 */
final class FG_Admin_Settings {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_post_fg_save_settings', array( $this, 'save' ) );
		add_action( 'admin_post_fg_mail_preview', array( $this, 'preview' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media_picker' ) );
	}

	/**
	 * Render the form.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diesen Bereich.', 'arbeitsdienste' ) );
		}

		$settings = FG_Mail_Templates::get_settings();
		$logo_id  = $settings['logo_attachment_id'];
		$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$preview  = wp_nonce_url(
			admin_url( 'admin-post.php?action=fg_mail_preview' ),
			'fg_mail_preview'
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Einstellungen', 'arbeitsdienste' ); ?></h1>

			<p><?php esc_html_e( 'Diese Angaben stehen in allen E-Mails, die das Plugin verschickt. Das Layout selbst ist nicht einstellbar; es wird im Plugin mitgeliefert und hier nur mit Logo und Fußzeile versehen.', 'arbeitsdienste' ); ?></p>

			<?php // A mail plugin that takes the message over drops the plain-text
			// alternative this plugin hands to wp_mail(), and its own wording is added
			// to the layout. Neither is visible in a received mail, so the screen says
			// it here: this is the one thing about the way the mails leave the site that
			// the club cannot see by looking at one. ?>
			<?php $handler = FG_Mail_Templates::foreign_mail_handler(); ?>
			<?php if ( '' !== $handler ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: name of the class that takes wp_mail() over. */
							esc_html__( 'Eine Mail-Erweiterung (%s) verschickt die Nachrichten dieser Website selbst. Das Layout kommt trotzdem an, aber der Textteil als zweite Fassung der Mail geht dabei verloren, und ein Text, den die Erweiterung selbst ergänzt, landet im Layout statt im Textteil.', 'arbeitsdienste' ),
							esc_html( $handler )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="fg_save_settings">
				<?php wp_nonce_field( 'fg_save_settings', 'fg_settings_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Logo', 'arbeitsdienste' ); ?></th>
						<td>
							<input type="hidden" id="fg-logo-id" name="fg_logo_attachment_id" value="<?php echo esc_attr( $logo_id ); ?>">
							<p id="fg-logo-preview">
								<?php if ( $logo_url ) : ?>
									<img src="<?php echo esc_url( $logo_url ); ?>" alt="" style="max-height:64px;height:auto">
								<?php endif; ?>
							</p>
							<button type="button" class="button" id="fg-logo-pick" disabled><?php esc_html_e( 'Logo auswählen', 'arbeitsdienste' ); ?></button>
							<button type="button" class="button" id="fg-logo-clear" disabled<?php echo $logo_id ? '' : ' hidden'; ?>><?php esc_html_e( 'Logo entfernen', 'arbeitsdienste' ); ?></button>
							<p class="description"><?php esc_html_e( 'Bild aus der Mediathek. Es wird in die E-Mail eingebettet und nicht von einer fremden Adresse nachgeladen. Ohne Logo wird die E-Mail ohne Bild versendet.', 'arbeitsdienste' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fg-footer"><?php esc_html_e( 'Fußzeile', 'arbeitsdienste' ); ?></label></th>
						<td>
							<textarea id="fg-footer" name="fg_footer" rows="9" class="large-text" required><?php echo esc_textarea( $settings['footer'] ); ?></textarea>
							<?php /* One field, because the three it replaces were three boxes with one purpose: what the club is and how to reach it. A blank line separates the sections, the line breaks inside them stay line breaks. */ ?>
							<span class="description"><?php esc_html_e( 'Vereinsname und Anschrift, Telefon, E-Mail-Adresse, Website und die satzungsgemäß erforderlichen Angaben. Eine Angabe pro Zeile; eine Leerzeile trennt die Abschnitte voneinander ab. Pflichtangabe.', 'arbeitsdienste' ); ?></span>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Speichern', 'arbeitsdienste' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Vorschau', 'arbeitsdienste' ); ?></h2>
			<p>
				<a class="button" id="fg-mail-preview" href="<?php echo esc_url( $preview ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'E-Mail ansehen', 'arbeitsdienste' ); ?></a>
				<span class="description"><?php esc_html_e( 'Zeigt den HTML-Teil mit den gespeicherten Angaben und einem Beispieltext. Die Vorschau verwendet die gespeicherten Werte, also zuerst speichern.', 'arbeitsdienste' ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Store the settings after a nonce and capability check.
	 *
	 * @return void
	 */
	public function save() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung, die Einstellungen zu ändern.', 'arbeitsdienste' ) );
		}

		check_admin_referer( 'fg_save_settings', 'fg_settings_nonce' );

		$logo_id = isset( $_POST['fg_logo_attachment_id'] ) && ! is_array( $_POST['fg_logo_attachment_id'] )
			? absint( wp_unslash( $_POST['fg_logo_attachment_id'] ) )
			: 0;

		$fields = array(
			'footer' => $this->post_text( 'fg_footer' ),
		);

		// A mail without a sender address and a legal notice is the one mistake
		// that cannot be undone afterwards, so an empty footer is refused instead
		// of being stored and discovered in a real mail. There is one field, so
		// there is one name to say.
		if ( '' === $fields['footer'] ) {
			FG_Admin::store_notice(
				__( 'Es wurde nichts gespeichert, weil die Fußzeile fehlt. Sie steht in jeder E-Mail des Plugins.', 'arbeitsdienste' ),
				'error'
			);
			$this->redirect_back();
		}

		// An attachment that is not an image is refused instead of being stored:
		// the value is used to embed a file, and only an image may be embedded.
		if ( $logo_id && ! wp_attachment_is_image( $logo_id ) ) {
			FG_Admin::store_notice( __( 'Das gewählte Logo wurde nicht gespeichert, weil es kein Bild aus der Mediathek ist.', 'arbeitsdienste' ), 'error' );
			$this->redirect_back();
		}

		$settings = array_merge(
			array( 'logo_attachment_id' => $logo_id ),
			$fields
		);

		update_option( FG_SETTINGS_OPTION, $settings, false );

		FG_Admin::store_notice( __( 'Die Einstellungen wurden gespeichert.', 'arbeitsdienste' ) );
		$this->redirect_back();
	}

	/**
	 * Show the HTML part of a message with the stored settings.
	 *
	 * A browser cannot resolve a `cid:` address, so the preview links the logo
	 * by its address in the media library instead. Everything else is the
	 * render path a real message takes.
	 *
	 * @return void
	 */
	public function preview() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diesen Bereich.', 'arbeitsdienste' ) );
		}

		check_admin_referer( 'fg_mail_preview' );

		$settings = FG_Mail_Templates::get_settings();
		$logo_url = $settings['logo_attachment_id'] && wp_attachment_is_image( $settings['logo_attachment_id'] )
			? (string) wp_get_attachment_image_url( $settings['logo_attachment_id'], 'full' )
			: '';

		// The example text carries an ampersand on purpose: the name is
		// typed by a visitor, and this is the place where it becomes visible
		// that such a value is escaped rather than carried over as markup.
		$text = implode(
			"\n",
			array(
				'Hallo Anton Berger',
				'',
				__( 'so sieht eine E-Mail des Plugins im HTML-Teil aus. Der Text steht unverändert als reiner Text in der ersten Alternative der Nachricht.', 'arbeitsdienste' ),
				'',
				__( 'Fahrgemeinschaft: Müller & Söhne aus Innenstadt', 'arbeitsdienste' ),
				'',
				__( 'Mit freundlichen Grüßen', 'arbeitsdienste' ),
				__( 'Fahrgemeinschaften', 'arbeitsdienste' ),
			)
		);

		// The subject of the mail to a ride, with the invented duty filled in. Up to
		// version 1.14.0 it read "Fahrgemeinschaft bestätigen" and named a state
		// that does not exist any more; a club that reads this line to see what a
		// mail of the plugin looks like would have read a word for a step that is
		// not taken.
		$subject = __( 'Deine Fahrgemeinschaft ist eingetragen – Arbeitseinsatz', 'arbeitsdienste' );

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		echo FG_Mail_Templates::render( $subject, $text, $logo_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Layout with escaped values, see FG_Mail_Templates::render().

		exit;
	}

	/**
	 * Add the media library and the picker to the settings screen.
	 *
	 * The script is attached to the media library with
	 * wp_add_inline_script() instead of being printed here. Printing it during
	 * admin_enqueue_scripts would put it into the head of the document, where
	 * neither wp.media nor the buttons exist yet, and the buttons would then do
	 * nothing without reporting an error. Attached to media-views it is printed
	 * behind that script, in the footer, where both are there.
	 *
	 * @return void
	 */
	public function enqueue_media_picker() {
		if ( ! $this->is_settings_screen() ) {
			return;
		}

		wp_enqueue_media();

		$strings = array(
			'frameTitle'  => __( 'Logo auswählen', 'arbeitsdienste' ),
			'frameButton' => __( 'Dieses Bild verwenden', 'arbeitsdienste' ),
		);

		wp_add_inline_script( 'media-views', $this->picker_script( $strings ) );
	}

	/**
	 * Build the script that drives the two logo buttons.
	 *
	 * Both buttons are rendered disabled and are only enabled here. If the
	 * script does not run, the screen shows that instead of two buttons that
	 * silently do nothing.
	 *
	 * @param array $strings Texts for the media frame.
	 * @return string
	 */
	private function picker_script( $strings ) {
		$script = <<<'JS'
( function () {
	var pick = document.getElementById( 'fg-logo-pick' );
	var clear = document.getElementById( 'fg-logo-clear' );
	var field = document.getElementById( 'fg-logo-id' );
	var box = document.getElementById( 'fg-logo-preview' );
	if ( ! pick || ! clear || ! field || ! box || ! window.wp || ! window.wp.media ) {
		return;
	}

	var strings = __FG_PICKER_STRINGS__;

	var frame = window.wp.media( {
		title: strings.frameTitle,
		button: { text: strings.frameButton },
		library: { type: 'image' },
		multiple: false
	} );

	function show( url ) {
		box.innerHTML = url
			? '<img src="' + url + '" alt="" style="max-height:64px;height:auto">'
			: '';
		clear.hidden = field.value === '';
		clear.disabled = field.value === '';
	}

	pick.addEventListener( 'click', function ( event ) {
		event.preventDefault();
		frame.open();
	} );

	clear.addEventListener( 'click', function ( event ) {
		event.preventDefault();
		field.value = '';
		show( '' );
	} );

	frame.on( 'select', function () {
		var item = frame.state().get( 'selection' ).first().toJSON();
		var url = item.sizes && item.sizes.medium ? item.sizes.medium.url : item.url;
		field.value = item.id;
		show( url );
	} );

	pick.disabled = false;

	// The stored logo is shown by the server. Its address is only needed to
	// decide whether the remove button applies, so a missing preview image must
	// not stop the picker.
	var current = box.querySelector( 'img' );
	show( '' === field.value || ! current ? '' : current.src );
} )();
JS;

		return str_replace( '__FG_PICKER_STRINGS__', wp_json_encode( $strings ), $script );
	}

	/**
	 * Read one textarea of the form.
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
	 * Report whether the current screen is the settings screen.
	 *
	 * @return bool
	 */
	private function is_settings_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen instanceof WP_Screen
			&& FG_Admin::screen_id( FG_SETTINGS_PAGE_SLUG ) === $screen->id;
	}

	/**
	 * Return to the settings screen.
	 *
	 * @return void
	 */
	private function redirect_back() {
		wp_safe_redirect(
			add_query_arg( 'page', FG_SETTINGS_PAGE_SLUG, admin_url( 'admin.php' ) ),
			303
		);
		exit;
	}
}
