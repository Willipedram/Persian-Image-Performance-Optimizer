<?php
/** Remove plugin settings on uninstall. */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Data is retained by default. Destructive removal requires explicit opt-in.
if ( 1 !== (int) get_option( 'pipo_delete_data_on_uninstall', 0 ) ) {
	return;
}

global $wpdb;
foreach ( array( 'pipo_images', 'pipo_usage', 'pipo_jobs', 'pipo_logs' ) as $suffix ) {
	$table = $wpdb->prefix . $suffix;
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted prefix and fixed suffix.
}

delete_option( 'pipo_db_version' );
delete_option( 'pipo_delete_data_on_uninstall' );
