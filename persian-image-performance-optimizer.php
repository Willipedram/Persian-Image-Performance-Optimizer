<?php
/**
 * Plugin Name: بهینه‌ساز عملکرد تصاویر فارسی
 * Plugin URI:  https://example.com/persian-image-performance-optimizer
 * Description: فشرده‌سازی امن تصاویر وردپرس و ساخت نسخه WebP هنگام بارگذاری.
 * Version:     1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      PIPO Contributors
 * Text Domain: persian-image-performance-optimizer
 * Domain Path: /languages
 * License:     GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'PIPO_VERSION', '1.0.0' );
define( 'PIPO_FILE', __FILE__ );
define( 'PIPO_DIR', plugin_dir_path( __FILE__ ) );

require_once PIPO_DIR . 'includes/class-pipo-plugin.php';

PIPO_Plugin::instance();

