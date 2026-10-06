<?php
/** Capability-aware WebP/AVIF conversion and compression orchestration. */

defined( 'ABSPATH' ) || exit;

final class PIPO_Conversion_Engine {
	private $processor; private $settings; private $images; private $logs;
	public function __construct( $processor = null, $settings = null, $images = null, $logs = null ) { $this->processor = $processor ?: new PIPO_Image_Processor(); $this->settings = $settings ?: new PIPO_Conversion_Settings(); $this->images = $images ?: new PIPO_Image_Repository(); $this->logs = $logs ?: new PIPO_Log_Repository(); }
	/** @return array<string,mixed> */
	public function convert( $source_path, $target_mime, $attachment_id = 0 ) {
		$allowed = array( 'image/jpeg' => array( 'image/webp', 'image/avif' ), 'image/png' => array( 'image/webp', 'image/avif' ), 'image/webp' => array( 'image/avif' ) );
		$source = $this->processor->validate( $source_path );
		if ( empty( $allowed[ $source['mime'] ] ) || ! in_array( $target_mime, $allowed[ $source['mime'] ], true ) ) { throw new RuntimeException( 'conversion_not_allowed' ); }
		$options = $this->settings->get();
		if ( 'image/webp' === $target_mime && empty( $options['webp_enabled'] ) ) { throw new RuntimeException( 'webp_disabled' ); }
		if ( 'image/avif' === $target_mime && ( empty( $options['avif_enabled'] ) || ! $this->settings->avif_available() ) ) { throw new RuntimeException( 'avif_unsupported_or_disabled' ); }
		$quality = 'image/avif' === $target_mime ? (int) $options['avif_quality'] : (int) $options['webp_quality'];
		$extension = 'image/avif' === $target_mime ? 'avif' : 'webp';
		$destination = preg_replace( '/\.[^.]+$/', '-pipo-' . $extension . '-' . substr( hash_file( 'sha256', $source_path ), 0, 8 ) . '.' . $extension, $source_path );
		$started = microtime( true );
		$result = $this->processor->process( $source_path, array( 'mime' => $target_mime, 'quality' => $quality, 'destination' => $destination, 'strip_metadata' => ! empty( $options['remove_metadata'] ), 'preserve_copyright' => ! empty( $options['preserve_copyright'] ), 'preserve_transparency' => ! empty( $options['preserve_transparency'] ), 'normalize_orientation' => true, 'engine_options' => array( 'lossless' => 'image/webp' === $target_mime && ! empty( $options['webp_lossless'] ), 'preserve_icc' => $this->has_icc_profile( $source_path, $source['mime'] ) ) ) );
		$original_size = (int) $source['size']; $optimized_size = (int) $result['size'];
		$preferred = $optimized_size < $original_size; $saved = $preferred ? $original_size - $optimized_size : 0; $percent = $preferred && $original_size ? round( $saved / $original_size * 100, 2 ) : 0;
		if ( ! $preferred && is_file( $result['path'] ) ) { wp_delete_file( $result['path'] ); }
		$history = array( 'source_mime' => $source['mime'], 'target_mime' => $target_mime, 'original_size' => $original_size, 'optimized_size' => $optimized_size, 'bytes_saved' => $saved, 'saving_percent' => $percent, 'quality' => $quality, 'lossless' => 'image/webp' === $target_mime && ! empty( $options['webp_lossless'] ), 'preferred' => $preferred, 'output_path' => $preferred ? $result['path'] : null, 'engine' => $result['engine'], 'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 3 ) );
		$this->logs->record_compression( $attachment_id, $history );
		if ( $attachment_id ) { $record = $this->images->find_by_attachment( $attachment_id ); if ( $record ) { $field = 'image/webp' === $target_mime ? 'webp_exists' : 'avif_exists'; $this->images->update( $record->id, array( $field => $preferred ? 1 : 0, 'optimized' => $preferred ? 1 : (int) $record->optimized, 'optimization_status' => $preferred ? 'completed' : 'larger_than_original', 'compression_quality' => $quality, 'preferred_format' => $preferred ? $extension : $record->preferred_format, 'optimized_size' => $preferred ? $optimized_size : $record->optimized_size, 'bytes_saved' => $preferred ? $saved : $record->bytes_saved, 'saving_percent' => $preferred ? $percent : $record->saving_percent, 'last_optimized_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ) ); } }
		return $history;
	}
	private function has_icc_profile( $path, $mime ) { $content = file_get_contents( $path, false, null, 0, 1048576 ); if ( false === $content ) { return false; } return ( 'image/jpeg' === $mime && false !== strpos( $content, 'ICC_PROFILE' ) ) || ( 'image/png' === $mime && false !== strpos( $content, 'iCCP' ) ); }
}
