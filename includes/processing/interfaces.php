<?php
/** Image processing contracts. @package PersianImagePerformanceOptimizer */

defined( 'ABSPATH' ) || exit;

interface ImageEngineInterface {
	public function is_available();
	public function supports_mime( $mime );
	public function load( $path );
	public function dimensions();
	public function resize( $max_width, $max_height );
	public function crop( $x, $y, $width, $height, $target_width = null, $target_height = null );
	public function compress( $quality );
	public function configure( $options );
	public function change_format( $mime );
	public function strip_metadata( $options = array() );
	public function normalize_orientation();
	public function save( $temporary_path );
	public function name();
}

interface BackupManagerInterface {
	public function create_backup( $source_path );
	public function validate_backup( $backup_path, $expected_mime = null );
	public function restore( $backup_path, $destination_path );
	public function previous_versions( $source_path );
	public function remove_generated_variants( $variant_paths );
}

interface ImageProcessorInterface {
	public function process( $source_path, $options = array() );
	public function validate( $path, $expected = array() );
}
