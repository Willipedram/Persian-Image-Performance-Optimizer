<?php
/** Database schema migrations. @package PersianImagePerformanceOptimizer */

defined( 'ABSPATH' ) || exit;

final class PIPO_Migrator {
	const VERSION = '1.3.0';
	const VERSION_OPTION = 'pipo_db_version';

	/** @var wpdb */
	private $db;

	/** @param wpdb|null $db WordPress database connection. */
	public function __construct( $db = null ) {
		global $wpdb;
		$this->db = $db ?: $wpdb;
	}

	/**
	 * Runs safe, repeatable schema changes. dbDelta preserves existing rows and
	 * only adds or adjusts schema elements required by the current definition.
	 */
	public function migrate() {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		foreach ( $this->schema() as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/** Runs migrations only when code and stored schema versions differ. */
	public function maybe_migrate() {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			$this->migrate();
		}
	}

	/** @return array<string,string> Table identifiers keyed by logical name. */
	public function tables() {
		return array(
			'images' => $this->db->prefix . 'pipo_images',
			'usage'  => $this->db->prefix . 'pipo_usage',
			'jobs'   => $this->db->prefix . 'pipo_jobs',
			'logs'   => $this->db->prefix . 'pipo_logs',
		);
	}

	/** @return array<int,string> */
	public function schema() {
		$tables  = $this->tables();
		$collate = $this->db->get_charset_collate();

		return array(
			"CREATE TABLE {$tables['images']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				attachment_id bigint(20) unsigned NOT NULL,
				original_path text NOT NULL,
				original_url text NOT NULL,
				mime_type varchar(100) NOT NULL DEFAULT '',
				original_width int(10) unsigned NOT NULL DEFAULT 0,
				original_height int(10) unsigned NOT NULL DEFAULT 0,
				original_size bigint(20) unsigned NOT NULL DEFAULT 0,
				webp_exists tinyint(1) NOT NULL DEFAULT 0,
				avif_exists tinyint(1) NOT NULL DEFAULT 0,
				optimized tinyint(1) NOT NULL DEFAULT 0,
				optimization_status varchar(30) NOT NULL DEFAULT 'pending',
				compression_quality smallint(5) unsigned NOT NULL DEFAULT 0,
				preferred_format varchar(20) NULL DEFAULT NULL,
				optimized_size bigint(20) unsigned NULL DEFAULT NULL,
				bytes_saved bigint(20) unsigned NOT NULL DEFAULT 0,
				saving_percent decimal(6,2) unsigned NOT NULL DEFAULT 0.00,
				original_hash varchar(128) NOT NULL DEFAULT '',
				generated_sizes longtext NULL,
				attachment_metadata longtext NULL,
				responsive_variants longtext NULL,
				animated_gif tinyint(1) NOT NULL DEFAULT 0,
				is_svg tinyint(1) NOT NULL DEFAULT 0,
				has_transparency tinyint(1) NULL DEFAULT NULL,
				is_corrupt tinyint(1) NOT NULL DEFAULT 0,
				is_modified tinyint(1) NOT NULL DEFAULT 0,
				file_modified_at bigint(20) unsigned NOT NULL DEFAULT 0,
				last_scanned_at datetime NULL DEFAULT NULL,
				last_optimized_at datetime NULL DEFAULT NULL,
				error_code varchar(100) NULL DEFAULT NULL,
				error_message text NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY attachment_id (attachment_id),
				KEY optimization_status (optimization_status),
				KEY optimized_status (optimized,optimization_status),
				KEY mime_type (mime_type),
				KEY preferred_format (preferred_format),
				KEY original_hash (original_hash),
				KEY scan_state (is_modified,is_corrupt),
				KEY last_scanned_at (last_scanned_at)
			) $collate;",
			"CREATE TABLE {$tables['usage']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				attachment_id bigint(20) unsigned NOT NULL,
				page_id bigint(20) unsigned NULL DEFAULT NULL,
				page_url text NOT NULL,
				source_url text NOT NULL,
				current_src text NOT NULL,
				natural_width int(10) unsigned NOT NULL DEFAULT 0,
				natural_height int(10) unsigned NOT NULL DEFAULT 0,
				rendered_width int(10) unsigned NOT NULL DEFAULT 0,
				rendered_height int(10) unsigned NOT NULL DEFAULT 0,
				viewport_width int(10) unsigned NOT NULL DEFAULT 0,
				viewport_height int(10) unsigned NOT NULL DEFAULT 0,
				device_pixel_ratio decimal(6,3) unsigned NOT NULL DEFAULT 1.000,
				above_fold tinyint(1) NOT NULL DEFAULT 0,
				possible_lcp tinyint(1) NOT NULL DEFAULT 0,
				element_type varchar(30) NOT NULL DEFAULT 'img',
				background_image tinyint(1) NOT NULL DEFAULT 0,
				slider_image tinyint(1) NOT NULL DEFAULT 0,
				first_seen_at datetime NOT NULL,
				last_seen_at datetime NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY attachment_id (attachment_id),
				KEY page_id (page_id),
				KEY attachment_page (attachment_id,page_id),
				KEY possible_lcp (possible_lcp),
				KEY last_seen_at (last_seen_at)
			) $collate;",
			"CREATE TABLE {$tables['jobs']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_type varchar(50) NOT NULL,
				attachment_id bigint(20) unsigned NULL DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				priority smallint(5) NOT NULL DEFAULT 10,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				max_attempts smallint(5) unsigned NOT NULL DEFAULT 3,
				payload longtext NULL,
				available_at datetime NOT NULL,
				started_at datetime NULL DEFAULT NULL,
				completed_at datetime NULL DEFAULT NULL,
				last_error text NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY queue_lookup (status,available_at,priority),
				KEY attachment_status (attachment_id,status),
				KEY job_type (job_type),
				KEY created_at (created_at)
			) $collate;",
			"CREATE TABLE {$tables['logs']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				level varchar(20) NOT NULL DEFAULT 'info',
				attachment_id bigint(20) unsigned NULL DEFAULT NULL,
				operation varchar(100) NOT NULL DEFAULT '',
				message text NOT NULL,
				context longtext NULL,
				duration_ms decimal(12,3) unsigned NULL DEFAULT NULL,
				memory_bytes bigint(20) unsigned NULL DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY level (level),
				KEY attachment_id (attachment_id),
				KEY operation (operation),
				KEY created_at (created_at),
				KEY attachment_created (attachment_id,created_at)
			) $collate;",
		);
	}
}
