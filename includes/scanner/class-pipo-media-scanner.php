<?php
/** Incremental, batch-based WordPress media scanner. @package PersianImagePerformanceOptimizer */

defined( 'ABSPATH' ) || exit;

final class PIPO_Media_Scanner {
	const ALLOWED_BATCHES = array( 10, 20, 50, 100 );
	const DEFAULT_BATCH = 50;
	const MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif', 'image/svg+xml' );

	/** @var PIPO_Image_Repository */ private $images;
	/** @var PIPO_Job_Repository */ private $jobs;
	/** @var PIPO_Log_Repository */ private $logs;

	public function __construct( $images = null, $jobs = null, $logs = null ) {
		$this->images = $images ?: new PIPO_Image_Repository();
		$this->jobs   = $jobs ?: new PIPO_Job_Repository();
		$this->logs   = $logs ?: new PIPO_Log_Repository();
	}

	/** @return int|false */
	public function start( $mode = 'incremental', $batch_size = self::DEFAULT_BATCH ) {
		$batch_size = in_array( (int) $batch_size, self::ALLOWED_BATCHES, true ) ? (int) $batch_size : self::DEFAULT_BATCH;
		$now = current_time( 'mysql', true );
		return $this->jobs->insert( array(
			'job_type' => 'media_scan', 'status' => 'pending', 'priority' => 10, 'attempts' => 0, 'max_attempts' => 3,
			'payload' => wp_json_encode( array( 'mode' => in_array( $mode, array( 'full', 'incremental', 'retry' ), true ) ? $mode : 'incremental', 'offset' => 0, 'batch_size' => $batch_size ) ),
			'available_at' => $now, 'created_at' => $now, 'updated_at' => $now,
		) );
	}

	public function stop() {
		$job = $this->jobs->latest_scanner_job();
		return $job && in_array( $job->status, array( 'pending', 'running' ), true ) ? $this->jobs->update( $job->id, array( 'status' => 'paused', 'updated_at' => current_time( 'mysql', true ) ) ) : false;
	}

	public function resume() {
		$job = $this->jobs->latest_scanner_job();
		return $job && 'paused' === $job->status ? $this->jobs->update( $job->id, array( 'status' => 'pending', 'updated_at' => current_time( 'mysql', true ) ) ) : false;
	}

	/** @return int|false */
	public function retry_failures( $batch_size = self::DEFAULT_BATCH ) {
		return $this->start( 'retry', $batch_size );
	}

	/** Processes at most one configured batch. @return array<string,int|string|bool> */
	public function process_batch() {
		$job = $this->jobs->latest_scanner_job();
		if ( ! $job || ! in_array( $job->status, array( 'pending', 'running' ), true ) ) {
			return array( 'processed' => 0, 'finished' => true, 'status' => $job ? $job->status : 'idle' );
		}
		$payload = json_decode( (string) $job->payload, true );
		$payload = is_array( $payload ) ? $payload : array();
		$batch   = in_array( (int) ( $payload['batch_size'] ?? 0 ), self::ALLOWED_BATCHES, true ) ? (int) $payload['batch_size'] : self::DEFAULT_BATCH;
		$offset  = max( 0, (int) ( $payload['offset'] ?? 0 ) );
		$mode    = $payload['mode'] ?? 'incremental';
		$ids     = 'retry' === $mode ? array_slice( $this->images->failed_attachment_ids( 500 ), $offset, $batch ) : $this->attachment_ids( $offset, $batch );
		$now     = current_time( 'mysql', true );
		$this->jobs->update( $job->id, array( 'status' => 'running', 'started_at' => $job->started_at ?: $now, 'updated_at' => $now ) );

		foreach ( $ids as $attachment_id ) {
			$this->scan_attachment( $attachment_id, 'full' === $mode );
		}

		$payload['offset'] = $offset + count( $ids );
		$finished = count( $ids ) < $batch;
		$this->jobs->update( $job->id, array(
			'status' => $finished ? 'completed' : 'pending', 'payload' => wp_json_encode( $payload ),
			'completed_at' => $finished ? current_time( 'mysql', true ) : null, 'updated_at' => current_time( 'mysql', true ),
		) );
		return array( 'processed' => count( $ids ), 'finished' => $finished, 'status' => $finished ? 'completed' : 'pending' );
	}

	/** @return array<int,int> */
	private function attachment_ids( $offset, $limit ) {
		return array_map( 'intval', get_posts( array(
			'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => self::MIME_TYPES,
			'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'posts_per_page' => $limit, 'offset' => $offset,
			'no_found_rows' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false,
		) ) );
	}

	/** Scans exactly one attachment and never throws for file-level failures. @return bool */
	public function scan_attachment( $attachment_id, $force = false ) {
		$attachment_id = (int) $attachment_id;
		$path = get_attached_file( $attachment_id );
		$url  = wp_get_attachment_url( $attachment_id );
		$mime = (string) get_post_mime_type( $attachment_id );
		$now  = current_time( 'mysql', true );
		$base = array( 'original_path' => is_string( $path ) ? wp_normalize_path( $path ) : '', 'original_url' => is_string( $url ) ? $url : '', 'mime_type' => $mime, 'last_scanned_at' => $now, 'updated_at' => $now );

		if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			$this->record_error( $attachment_id, $base, 'missing_file', 'فایل پیوست وجود ندارد یا قابل خواندن نیست.' );
			return false;
		}

		$hash = hash_file( 'sha256', $path );
		$old  = $this->images->find_by_attachment( $attachment_id );
		if ( ! $force && $old && $old->original_hash === $hash && empty( $old->error_code ) ) {
			$this->images->update( $old->id, array( 'last_scanned_at' => $now, 'is_modified' => 0, 'updated_at' => $now ) );
			return true;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$is_svg   = 'image/svg+xml' === $mime || 'svg' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$dimensions = $is_svg ? $this->svg_dimensions( $path ) : $this->raster_dimensions( $path );
		$corrupt = false === $dimensions;
		$dimensions = is_array( $dimensions ) ? $dimensions : array( 0, 0 );
		$data = array_merge( $base, array(
			'original_width' => (int) ( $dimensions[0] ?: ( $metadata['width'] ?? 0 ) ),
			'original_height' => (int) ( $dimensions[1] ?: ( $metadata['height'] ?? 0 ) ),
			'original_size' => (int) filesize( $path ), 'generated_sizes' => wp_json_encode( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array() ),
			'attachment_metadata' => wp_json_encode( $metadata ), 'webp_exists' => $this->companion_exists( $path, 'webp' ) ? 1 : 0, 'avif_exists' => $this->companion_exists( $path, 'avif' ) ? 1 : 0,
			'animated_gif' => 'image/gif' === $mime && $this->is_animated_gif( $path ) ? 1 : 0, 'is_svg' => $is_svg ? 1 : 0,
			'has_transparency' => $this->transparency( $path, $mime ), 'is_corrupt' => $corrupt ? 1 : 0,
			'is_modified' => $old && $old->original_hash !== $hash ? 1 : 0, 'file_modified_at' => (int) filemtime( $path ),
			'original_hash' => (string) $hash, 'optimization_status' => $corrupt ? 'error' : 'scanned',
			'error_code' => $corrupt ? 'corrupt_file' : null, 'error_message' => $corrupt ? 'ساختار تصویر قابل خواندن نیست.' : null,
			'created_at' => $old ? $old->created_at : $now,
		) );
		$this->images->upsert_attachment( $attachment_id, $data );
		if ( $corrupt ) { $this->log_error( $attachment_id, 'corrupt_file', $data['error_message'] ); }
		return ! $corrupt;
	}

	private function record_error( $id, $base, $code, $message ) {
		$old = $this->images->find_by_attachment( $id );
		$this->images->upsert_attachment( $id, array_merge( $base, array( 'optimization_status' => 'error', 'error_code' => $code, 'error_message' => $message, 'is_corrupt' => 0, 'created_at' => $old ? $old->created_at : $base['updated_at'] ) ) );
		$this->log_error( $id, $code, $message );
	}

	private function log_error( $id, $operation, $message ) {
		$this->logs->insert( array( 'level' => 'error', 'attachment_id' => $id, 'operation' => $operation, 'message' => $message, 'created_at' => current_time( 'mysql', true ) ) );
	}

	private function raster_dimensions( $path ) {
		$result = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt images are an expected scan result.
		return $result ? array( (int) $result[0], (int) $result[1] ) : false;
	}

	private function svg_dimensions( $path ) {
		$content = file_get_contents( $path, false, null, 0, 262144 );
		if ( false === $content || ! preg_match( '/<svg\b[^>]*>/i', $content, $tag ) ) { return false; }
		$width = $height = 0;
		if ( preg_match( '/\bwidth=["\']\s*([0-9.]+)/i', $tag[0], $m ) ) { $width = (float) $m[1]; }
		if ( preg_match( '/\bheight=["\']\s*([0-9.]+)/i', $tag[0], $m ) ) { $height = (float) $m[1]; }
		if ( ( ! $width || ! $height ) && preg_match( '/\bviewBox=["\']\s*[-0-9.]+[ ,]+[-0-9.]+[ ,]+([0-9.]+)[ ,]+([0-9.]+)/i', $tag[0], $m ) ) { $width = $width ?: (float) $m[1]; $height = $height ?: (float) $m[2]; }
		return array( (int) round( $width ), (int) round( $height ) );
	}

	private function companion_exists( $path, $extension ) {
		return strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) === $extension || is_file( preg_replace( '/\.[^.]+$/', '.' . $extension, $path ) );
	}

	private function is_animated_gif( $path ) {
		$handle = fopen( $path, 'rb' ); if ( ! $handle ) { return false; }
		$count = 0; $tail = '';
		while ( ! feof( $handle ) && $count < 2 ) { $chunk = $tail . fread( $handle, 1048576 ); $count += preg_match_all( '/\x00\x21\xF9\x04.{4}\x00[\x2C\x21]/s', $chunk ); $tail = substr( $chunk, -16 ); }
		fclose( $handle ); return $count > 1;
	}

	/** @return int|null Null means it cannot be detected reliably. */
	private function transparency( $path, $mime ) {
		$head = file_get_contents( $path, false, null, 0, 64 );
		if ( false === $head ) { return null; }
		if ( 'image/png' === $mime && strlen( $head ) > 25 ) { return in_array( ord( $head[25] ), array( 4, 6 ), true ) ? 1 : 0; }
		if ( 'image/gif' === $mime ) { return false !== strpos( $head, "\x21\xF9\x04" ) ? 1 : 0; }
		if ( 'image/webp' === $mime && strlen( $head ) > 21 && 'VP8X' === substr( $head, 12, 4 ) ) { return ord( $head[20] ) & 16 ? 1 : 0; }
		return null;
	}
}
