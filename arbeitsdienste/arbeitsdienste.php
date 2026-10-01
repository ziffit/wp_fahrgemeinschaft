<?php
/**
 * Plugin Name:       Arbeitsdienste
 * Description:       Arbeitsdienste eines Vereins planen, Mitglieder eintragen und öffentlich anbieten, mit Anmeldung zum Dienst und zu den Fahrgemeinschaften je Dienst.
 * Version:           1.25.5
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Verein
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       arbeitsdienste
 */

defined( 'ABSPATH' ) || exit;

/**
 * Version des Plugins.
 *
 * Diese Zahl steht auch als Parameter an der Adresse des Stylesheets. Sie muss
 * mit jeder Änderung an der Datei mitwachsen, sonst hält der Browser die alte
 * Fassung im Cache und die Seite zeigt den neuen Text zur alten Optik.
 *
 * Sie wächst auch dann, wenn sich nur Text ändert, zum Beispiel an Formular,
 * Mails oder Datenschutzerklärung: sie kennzeichnet die ausgelieferte Fassung,
 * und wer ein Archiv entpackt, soll an der Zahl erkennen, was darin ist.
 *
 * Sie steht zweimal in dieser Datei, einmal als "Version:" im Kopf über dem
 * Plugin-Namen und einmal hier. WordPress zeigt im Pluginverzeichnis die Zahl
 * aus dem Kopf an und prüft_updates() vergleicht sie, während der Browser die
 * Zahl von hier bekommt. Ein Kopf, der drei Fassungen zurückliegt, meldet dem
 * Verein eine neue Version, die es nicht gibt, und liefert beim Update die alte
 * Optik zur neuen. Beide Zahlen werden deshalb zusammen gesetzt.
 */
define( 'FG_VERSION', '1.25.5' );
define( 'FG_ADMIN_MENU_SLUG', 'fahrgemeinschaften' );
/**
 * Name of the element that carries a message after a form was sent.
 *
 * The redirect after a submission carries this name as a fragment, so the
 * browser puts the message at the top of the window instead of leaving the
 * visitor where they pressed the button. The form stands at the bottom of the
 * page, so without the fragment the message would sit above the fold.
 */
define( 'FG_NOTICE_ANCHOR', 'fg-hinweis' );
define( 'FG_EVENTS_PAGE_SLUG', 'fahrgemeinschaften-events' );
define( 'FG_MEMBERS_PAGE_SLUG', 'fahrgemeinschaften-members' );
define( 'FG_RIDES_PAGE_SLUG', 'fahrgemeinschaften-rides' );
define( 'FG_SETTINGS_PAGE_SLUG', 'fahrgemeinschaften-settings' );
define( 'FG_MAILS_PAGE_SLUG', 'fahrgemeinschaften-mails' );
define( 'FG_SETTINGS_OPTION', 'fg_settings' );
define( 'FG_STATS_OPTION', 'fg_daily_statistics' );
define( 'FG_CLEANUP_OPTION', 'fg_last_cleanup' );
define( 'FG_PUBLISHED_DELETE_TOKEN_TTL', 30 * DAY_IN_SECONDS );
define( 'FG_STATISTICS_RETENTION_DAYS', 90 );
/**
 * Version of the consent text.
 *
 * Raised to 1.1 when the consent began to name what becomes public. A first
 * name or nickname is expected in the list, and the sentence no longer leaves
 * open whether a name counts as a contact detail.
 *
 * Raised to 1.2 in version 1.14.0, because the consent now names the source of
 * the name as well as the name. Until then the visitor typed the name into the
 * form and the sentence talked about "my first name or nickname". The name that
 * appears in the list is now the first name of the member in the club's member
 * administration, and the sentence says so — a person reading the list can then
 * see where the name came from. The member number joins the address in the part
 * that is named as not being published.
 *
 * The version is written to every new ride and is only read back in the admin,
 * so raising it asks nobody anything. Rides consented to under 1.1 keep their
 * note: they were consented to for exactly the fields that were shown then, and
 * the only field that disappeared is the one the visitor used to choose.
 */
define( 'FG_CONSENT_VERSION', '1.2' );
define( 'FG_RIDE_STATUS_PUBLISHED', 'published' );
define( 'FG_RIDE_MODE_OFFER', 'offer' );
define( 'FG_RIDE_MODE_SEARCH', 'search' );

require_once __DIR__ . '/includes/class-fg-security.php';
require_once __DIR__ . '/includes/class-fg-records.php';
require_once __DIR__ . '/includes/class-fg-schema.php';
require_once __DIR__ . '/includes/class-fg-store.php';
require_once __DIR__ . '/includes/class-fg-repository.php';
require_once __DIR__ . '/includes/class-fg-member-import.php';
require_once __DIR__ . '/includes/class-fg-stats.php';
require_once __DIR__ . '/includes/class-fg-mail-templates.php';
require_once __DIR__ . '/includes/class-fg-mail-texts.php';
require_once __DIR__ . '/includes/class-fg-mailer.php';
require_once __DIR__ . '/includes/class-fg-public.php';
require_once __DIR__ . '/includes/class-fg-public-events.php';
require_once __DIR__ . '/includes/class-fg-actions.php';
require_once __DIR__ . '/includes/class-fg-admin.php';
require_once __DIR__ . '/includes/class-fg-admin-events.php';
require_once __DIR__ . '/includes/class-fg-admin-members.php';
require_once __DIR__ . '/includes/class-fg-admin-rides.php';
require_once __DIR__ . '/includes/class-fg-admin-settings.php';
require_once __DIR__ . '/includes/class-fg-admin-mails.php';
require_once __DIR__ . '/includes/class-fg-privacy.php';

/**
 * Main plugin coordinator.
 */
final class FG_Plugin {
	/**
	 * Registered service objects.
	 *
	 * @var array<string, object>
	 */
	private $services = array();

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		$this->services['public']  = new FG_Public();
		$this->services['public_events'] = new FG_Public_Events();
		$this->services['actions'] = new FG_Actions();
		$this->services['admin']   = new FG_Admin();
		$this->services['privacy'] = new FG_Privacy();

		// A schema change has to be applied without asking the site owner to
		// deactivate the plugin again, so it runs on every load and does
		// nothing once the stored version matches.
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install_schema' ), 5 );
		add_action( 'init', array( $this, 'ensure_cleanup_scheduled' ) );
		add_action( 'admin_init', array( $this, 'maybe_run_cleanup_fallback' ), 20 );
	}

	/**
	 * Create the tables when they are missing or outdated.
	 *
	 * @return void
	 */
	public static function maybe_install_schema() {
		FG_Schema::maybe_install();
	}

	/**
	 * Keep cleanup scheduled after updates without relying on activation only.
	 *
	 * @return void
	 */
	public function ensure_cleanup_scheduled() {
		if ( ! wp_next_scheduled( 'fg_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'fg_daily_cleanup' );
		}
	}

	/**
	 * Run the daily cleanup from the admin as a fallback.
	 *
	 * Expired pending entries, expired delete tokens and old statistics must
	 * disappear even when WP-Cron is disabled or too rarely triggered by
	 * traffic. The marker option keeps this to one run per day.
	 *
	 * @return void
	 */
	public function maybe_run_cleanup_fallback() {
		$last = (int) get_option( FG_CLEANUP_OPTION, 0 );

		if ( $last > time() - DAY_IN_SECONDS ) {
			return;
		}

		$this->services['actions']->daily_cleanup();
	}

	/**
	 * Activation routine.
	 *
	 * @return void
	 */
	public static function activate() {
		FG_Schema::install();

		if ( ! wp_next_scheduled( 'fg_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'fg_daily_cleanup' );
		}
	}

	/**
	 * Deactivation routine. Stored content is intentionally retained.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'fg_daily_cleanup' );
	}
}

register_activation_hook( __FILE__, array( 'FG_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FG_Plugin', 'deactivate' ) );

/**
 * Boot the plugin.
 *
 * @return void
 */
function fg_bootstrap_plugin() {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new FG_Plugin();
		$plugin->register();
	}
}

fg_bootstrap_plugin();
