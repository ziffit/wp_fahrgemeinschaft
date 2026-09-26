<?php
/**
 * Idempotent bootstrap of the test installation.
 *
 * Run twice inside the container:
 *   1. php bootstrap.php install    (creates the schema if the database is empty)
 *   2. php bootstrap.php configure  (site urls, administrator, plugin, cron)
 *
 * Every step checks its own result, so repeated runs are harmless.
 */

$phase = isset( $argv[1] ) ? $argv[1] : 'install';

if ( ! in_array( $phase, array( 'repair', 'install', 'configure' ), true ) ) {
	echo 'usage: bootstrap.php repair|install|configure', PHP_EOL;
	exit( 1 );
}

$home = 'https://localhost:8443';

/*
 * Phase "repair" runs before WordPress is loaded. A half finished
 * installation (tables without usable options) makes every WordPress request
 * die with "Error establishing a database connection", because
 * is_blog_installed() reaches dead_db() before it can look at
 * wp_installing(). Such leftovers are therefore removed with a raw
 * connection, so the install phase can start from a clean state.
 */
if ( 'repair' === $phase ) {
	mysqli_report( MYSQLI_REPORT_OFF );
	$link = @mysqli_connect(
		getenv( 'WORDPRESS_DB_HOST' ),
		getenv( 'WORDPRESS_DB_USER' ),
		getenv( 'WORDPRESS_DB_PASSWORD' ),
		getenv( 'WORDPRESS_DB_NAME' )
	);

	if ( ! $link ) {
		echo 'repair: database not reachable: ', mysqli_connect_error(), PHP_EOL;
		exit( 1 );
	}

	mysqli_set_charset( $link, 'utf8mb4' );

	$core_tables = array(
		'commentmeta',
		'comments',
		'links',
		'options',
		'postmeta',
		'posts',
		'term_relationships',
		'term_taxonomy',
		'termmeta',
		'terms',
		'usermeta',
		'users',
	);
	$prefix = getenv( 'WORDPRESS_TABLE_PREFIX' ) ? getenv( 'WORDPRESS_TABLE_PREFIX' ) : 'wp_';

	$present = array();
	foreach ( $core_tables as $suffix ) {
		$table  = $prefix . $suffix;
		$result = @mysqli_query( $link, 'SHOW TABLES LIKE \'' . mysqli_real_escape_string( $link, $table ) . '\'' );
		if ( $result && mysqli_num_rows( $result ) > 0 ) {
			$present[] = $table;
		}
	}

	if ( array() === $present ) {
		echo 'repair: no core tables, nothing to do', PHP_EOL;
		exit( 0 );
	}

	$siteurl = '';
	if ( in_array( $prefix . 'options', $present, true ) ) {
		$result = @mysqli_query(
			$link,
			"SELECT option_value FROM `{$prefix}options` WHERE option_name = 'siteurl' LIMIT 1"
		);
		if ( $result && ( $row = mysqli_fetch_row( $result ) ) ) {
			$siteurl = (string) $row[0];
		}
	}

	if ( '' !== $siteurl ) {
		echo 'repair: installation complete (siteurl=', $siteurl, ')', PHP_EOL;
		exit( 0 );
	}

	foreach ( $present as $table ) {
		@mysqli_query( $link, 'DROP TABLE IF EXISTS `' . $table . '`' );
	}

	printf( 'repair: dropped %d incomplete table(s)%s', count( $present ), PHP_EOL );
	exit( 0 );
}

if ( 'install' === $phase ) {
	define( 'WP_INSTALLING', true );
}

// wp_install() derives the site urls from the request. A CLI call has no
// request, so the environment of the public site is faked here. Without this
// the options siteurl and home stay empty and WordPress treats the
// installation as incomplete.
$_SERVER['HTTP_HOST']   = 'localhost:8443';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '8443';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTPS']       = 'on';
$_SERVER['SCRIPT_NAME'] = '/index.php';

require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

if ( 'install' === $phase ) {
	global $wpdb;

	$wpdb->suppress_errors();
	$siteurl = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteurl'" );
	$wpdb->suppress_errors( false );

	if ( '' !== $siteurl ) {
		echo 'install: database ready (siteurl=', $siteurl, ')', PHP_EOL;
		exit( 0 );
	}

	$result = wp_install( 'FVN Test', 'fg_admin', 'admin@example.org', true, '', 'Test1234!' );

	if ( is_wp_error( $result ) ) {
		echo 'install: FAILED ', $result->get_error_message(), PHP_EOL;
		exit( 1 );
	}

	// Enforce the public urls regardless of the guessed ones.
	update_option( 'siteurl', $home );
	update_option( 'home', $home );

	printf(
		'install: WordPress %s installed, admin id %d, siteurl %s%s',
		get_bloginfo( 'version' ),
		(int) $result['user_id'],
		get_option( 'siteurl' ),
		PHP_EOL
	);

	exit( 0 );
}

// --- phase 2: everything that needs a running installation ---------------

if ( ! is_blog_installed() ) {
	echo 'bootstrap: WordPress is not installed, run the install phase first', PHP_EOL;
	exit( 1 );
}

update_option( 'home', $home );
update_option( 'siteurl', $home );
update_option( 'blogname', 'FVN Test' );
update_option( 'admin_email', 'admin@example.org' );
update_option( 'timezone_string', 'Europe/Berlin' );
update_option( 'date_format', 'j. F Y' );
update_option( 'time_format', 'H:i' );
update_option( 'default_ping_status', 'closed' );
update_option( 'default_comment_status', 'closed' );
// Plain permalinks keep every test URL explicit (?page_id=…).
update_option( 'permalink_structure', '' );
update_option( 'blog_public', 1 );

// The test instance records every wp_mail() call instead of handing the message
// to a real transport. Without this the functional suite could not inspect
// recipient, headers and body. It is an option, not a constant, so the manual
// tests (ShureMail, real SMTP) only have to delete it again.
update_option( 'fg_test_mail_enabled', '1' );

$admin = get_user_by( 'login', 'fg_admin' );
if ( ! $admin ) {
	$admin_id = wp_insert_user(
		array(
			'user_login' => 'fg_admin',
			'user_pass'  => 'Test1234!',
			'user_email' => 'admin@example.org',
			'role'       => 'administrator',
		)
	);
	if ( is_wp_error( $admin_id ) ) {
		echo 'bootstrap: administrator FAILED ', $admin_id->get_error_message(), PHP_EOL;
		exit( 1 );
	}
	$admin   = get_user_by( 'id', $admin_id );
	$created = true;
} else {
	$created = false;
	wp_set_password( 'Test1234!', $admin->ID );
	$admin->set_role( 'administrator' );
}

printf(
	'bootstrap: home=%s administrator=%d (%s)%s',
	home_url( '/' ),
	(int) $admin->ID,
	$created ? 'created' : 'password reset',
	PHP_EOL
);

$plugin = 'my-plugin/fahrgemeinschaften.php';
if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
	echo 'bootstrap: plugin file missing in ', WP_PLUGIN_DIR, PHP_EOL;
	exit( 1 );
}

if ( is_plugin_active( $plugin ) ) {
	deactivate_plugins( array( $plugin ), true );
}

$activation = activate_plugin( $plugin );
if ( is_wp_error( $activation ) ) {
	echo 'bootstrap: activation FAILED ', $activation->get_error_message(), PHP_EOL;
	exit( 1 );
}

echo 'bootstrap: plugin active, version ', FG_VERSION, PHP_EOL;

global $wp_rewrite;
$wp_rewrite->flush_rules( true );

echo 'bootstrap: schema version ', (string) get_option( FG_Schema::OPTION, 'MISSING' ), PHP_EOL;
echo 'bootstrap: tables ', FG_Schema::tables_exist() ? 'present' : 'MISSING', PHP_EOL;
echo 'bootstrap: cron ', wp_next_scheduled( 'fg_daily_cleanup' ) ? 'scheduled' : 'MISSING', PHP_EOL;

// The plugin adds no capability of its own and changes no role. Anything left
// over from an earlier version of the plugin would be dead weight that a role
// editor can still see, so it is reported here.
$leftovers = array_values(
	array_filter(
		array_keys( (array) get_role( 'administrator' )->capabilities ),
		static function ( $capability ) {
			return 0 === strpos( (string) $capability, 'manage_fahrgemeinschaften' )
				|| 0 === strpos( (string) $capability, 'edit_fahrgemeinschaft' )
				|| 0 === strpos( (string) $capability, 'delete_fahrgemeinschaft' )
				|| 0 === strpos( (string) $capability, 'publish_fahrgemeinschaft' );
		}
	)
);
echo 'bootstrap: plugin capabilities ', array() === $leftovers ? 'none' : 'LEFTOVER ' . implode( ',', $leftovers ), PHP_EOL;

$statistics = get_option( FG_STATS_OPTION, array() );
echo 'bootstrap: statistics days recorded ', is_array( $statistics ) ? count( $statistics ) : 0, PHP_EOL;
