<?php
/**
 * Uninstall cleanup.
 *
 * Removing the plugin removes its data: both tables are dropped, so all work
 * services, rides and participant lists are gone for good. Export anything you
 * still need before deleting the plugin.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'fg_daily_cleanup' );

if ( class_exists( 'FG_Schema' ) ) {
	FG_Schema::drop();
} else {
	// The plugin files are already gone at this point, so the table names are
	// rebuilt from the table prefix.
	global $wpdb;

	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS `%s`', $wpdb->prefix . 'fg_rides' ) );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS `%s`', $wpdb->prefix . 'fg_events' ) );

	delete_option( 'fg_schema_version' );
}

delete_option( defined( 'FG_STATS_OPTION' ) ? FG_STATS_OPTION : 'fg_daily_statistics' );
delete_option( defined( 'FG_CLEANUP_OPTION' ) ? FG_CLEANUP_OPTION : 'fg_last_cleanup' );
delete_option( defined( 'FG_SETTINGS_OPTION' ) ? FG_SETTINGS_OPTION : 'fg_settings' );
