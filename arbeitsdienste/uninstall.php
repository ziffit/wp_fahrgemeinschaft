<?php
/**
 * Uninstall cleanup.
 *
 * Removing the plugin removes its data: all five tables are dropped, so every
 * work service, ride, member, registration and mail text is gone for good.
 * Export anything you still need before deleting the plugin.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'fg_daily_cleanup' );

if ( class_exists( 'FG_Schema' ) ) {
	FG_Schema::drop();
} else {
	// The plugin files are already gone at this point, so the table names are
	// rebuilt from the table prefix.
	global $wpdb;

	foreach ( array( 'fg_rides', 'fg_event_members', 'fg_events', 'fg_members', 'fg_mail_templates' ) as $tabelle ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS `%s`', $wpdb->prefix . $tabelle ) );
	}

	delete_option( 'fg_schema_version' );
}

delete_option( defined( 'FG_STATS_OPTION' ) ? FG_STATS_OPTION : 'fg_daily_statistics' );
delete_option( defined( 'FG_CLEANUP_OPTION' ) ? FG_CLEANUP_OPTION : 'fg_last_cleanup' );
delete_option( defined( 'FG_SETTINGS_OPTION' ) ? FG_SETTINGS_OPTION : 'fg_settings' );
