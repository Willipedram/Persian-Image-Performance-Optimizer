<?php
/** Remove plugin settings on uninstall. */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'pipo_options' );

if ( is_multisite() ) {
	delete_site_option( 'pipo_options' );
}

