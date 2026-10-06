<?php
/**
 * Plugin Name: بهینه‌ساز عملکرد تصاویر فارسی
 * Plugin URI:  https://example.com/persian-image-performance-optimizer
 * Description: بررسی سلامت محیط و قابلیت‌های پردازش تصویر پیش از بهینه‌سازی.
 * Version:     1.6.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      PIPO Contributors
 * Text Domain: persian-image-performance-optimizer
 * Domain Path: /languages
 * License:     GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'PIPO_VERSION', '1.6.0' );
define( 'PIPO_FILE', __FILE__ );
define( 'PIPO_DIR', plugin_dir_path( __FILE__ ) );

require_once PIPO_DIR . 'includes/class-pipo-capability-service.php';
require_once PIPO_DIR . 'includes/database/class-pipo-migrator.php';
require_once PIPO_DIR . 'includes/database/class-pipo-repository.php';
require_once PIPO_DIR . 'includes/database/class-pipo-image-repository.php';
require_once PIPO_DIR . 'includes/database/class-pipo-usage-repository.php';
require_once PIPO_DIR . 'includes/database/class-pipo-job-repository.php';
require_once PIPO_DIR . 'includes/database/class-pipo-log-repository.php';
require_once PIPO_DIR . 'includes/scanner/class-pipo-media-scanner.php';
require_once PIPO_DIR . 'includes/processing/interfaces.php';
require_once PIPO_DIR . 'includes/processing/class-pipo-image-validator.php';
require_once PIPO_DIR . 'includes/processing/class-pipo-gd-engine.php';
require_once PIPO_DIR . 'includes/processing/class-pipo-wordpress-engine.php';
require_once PIPO_DIR . 'includes/processing/class-pipo-imagick-engine.php';
require_once PIPO_DIR . 'includes/processing/class-pipo-backup-manager.php';
require_once PIPO_DIR . 'includes/processing/class-pipo-rollback-manager.php';
require_once PIPO_DIR . 'includes/processing/class-pipo-image-processor.php';
require_once PIPO_DIR . 'includes/conversion/class-pipo-conversion-settings.php';
require_once PIPO_DIR . 'includes/conversion/class-pipo-conversion-engine.php';
require_once PIPO_DIR . 'includes/responsive/class-pipo-responsive-settings.php';
require_once PIPO_DIR . 'includes/responsive/class-pipo-responsive-generator.php';
require_once PIPO_DIR . 'includes/class-pipo-plugin.php';

/** Creates or upgrades plugin tables on activation. */
function pipo_activate() {
	( new PIPO_Migrator() )->migrate();
}

register_activation_hook( PIPO_FILE, 'pipo_activate' );
add_action( 'plugins_loaded', array( new PIPO_Migrator(), 'maybe_migrate' ) );

PIPO_Plugin::instance();
