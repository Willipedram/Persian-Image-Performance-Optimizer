<?php
/** Generates useful, non-upscaled responsive derivatives. */

defined( 'ABSPATH' ) || exit;

final class PIPO_Responsive_Generator {
	const META_KEY = 'pipo_responsive_variants';
	const DISK_RESERVE = 104857600;
	private $processor; private $settings; private $conversion_settings; private $images; private $jobs;
	public function __construct( $processor = null, $settings = null, $conversion_settings = null, $images = null, $jobs = null ) { $this->processor = $processor ?: new PIPO_Image_Processor(); $this->settings = $settings ?: new PIPO_Responsive_Settings(); $this->conversion_settings = $conversion_settings ?: new PIPO_Conversion_Settings(); $this->images = $images ?: new PIPO_Image_Repository(); $this->jobs = $jobs ?: new PIPO_Job_Repository(); }

	/** @return array<int,int> */
	public function candidate_widths( $original_width, $existing_widths = array() ) {
		$options = $this->settings->get(); $original_width = (int) $original_width;
		$widths = array_filter( array_map( 'intval', $options['candidate_widths'] ), function ( $width ) use ( $options, $original_width ) { return $width >= $options['minimum_width'] && $width <= $options['maximum_width'] && $width < $original_width; } );
		if ( $original_width >= $options['minimum_width'] && $original_width <= $options['maximum_width'] ) { $widths[] = $original_width; }
		$widths = array_values( array_unique( array_diff( $widths, array_map( 'intval', $existing_widths ) ) ) ); sort( $widths, SORT_NUMERIC );
		if ( count( $widths ) > $options['maximum_variants'] ) { $indexes = array_unique( array_map( function ( $i ) use ( $widths, $options ) { return (int) round( $i * ( count( $widths ) - 1 ) / max( 1, $options['maximum_variants'] - 1 ) ); }, range( 0, $options['maximum_variants'] - 1 ) ) ); $widths = array_values( array_intersect_key( $widths, array_flip( $indexes ) ) ); }
		return $widths;
	}

	/** @return array<int,array<string,mixed>> */
	public function generate_for_attachment( $attachment_id, $regenerate = false ) {
		$path = get_attached_file( $attachment_id ); if ( ! is_string( $path ) || ! is_file( $path ) ) { throw new RuntimeException( 'responsive_source_missing' ); }
		$source = $this->processor->validate( $path ); $metadata = wp_get_attachment_metadata( $attachment_id ); $metadata = is_array( $metadata ) ? $metadata : array();
		if ( $regenerate ) { $this->remove_variants( $metadata[ self::META_KEY ] ?? array() ); unset( $metadata[ self::META_KEY ] ); }
		$existing = $this->existing_by_format( $metadata, $source['mime'] ); $settings = $this->settings->get(); $conversion = $this->conversion_settings->get();
		$formats = array(); if ( ! empty( $settings['jpeg_enabled'] ) && 'image/jpeg' === $source['mime'] ) { $formats[] = 'image/jpeg'; } if ( ! empty( $settings['webp_enabled'] ) && ! empty( $conversion['webp_enabled'] ) ) { $formats[] = 'image/webp'; } if ( ! empty( $settings['avif_enabled'] ) && ! empty( $conversion['avif_enabled'] ) && $this->conversion_settings->avif_available() ) { $formats[] = 'image/avif'; }
		$existing[ $source['mime'] ][] = (int) $source['width'];
		$variants = $regenerate ? array() : ( isset( $metadata[ self::META_KEY ] ) && is_array( $metadata[ self::META_KEY ] ) ? $metadata[ self::META_KEY ] : array() ); $directory = dirname( $path ); $base_url = trailingslashit( dirname( wp_get_attachment_url( $attachment_id ) ) );
		$plans = array(); $largest_plan = 0; foreach ( $formats as $mime ) { $plans[ $mime ] = $this->candidate_widths( $source['width'], $existing[ $mime ] ?? array() ); $largest_plan = max( $largest_plan, count( $plans[ $mime ] ) ); }
		for ( $position = 0; $position < $largest_plan && count( $variants ) < (int) $settings['maximum_variants']; $position++ ) {
			foreach ( $formats as $mime ) {
				if ( count( $variants ) >= (int) $settings['maximum_variants'] || ! isset( $plans[ $mime ][ $position ] ) ) { continue; }
				$width = $plans[ $mime ][ $position ];
				if ( ! $this->disk_is_safe( $directory, $source['size'] ) ) { throw new RuntimeException( 'responsive_disk_reserve_reached' ); }
				$height = max( 1, (int) round( $source['height'] * $width / $source['width'] ) ); $extension = array( 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/avif' => 'avif' )[ $mime ];
				$filename = pathinfo( $path, PATHINFO_FILENAME ) . '-pipo-' . $width . 'w.' . $extension; $destination = $directory . '/' . $filename;
				if ( is_file( $destination ) ) { $info = @getimagesize( $destination ); if ( $info && (int) $info[0] === $width && $info['mime'] === $mime ) { $variants[] = $this->variant( $destination, $base_url . $filename, $mime, $width, (int) $info[1] ); continue; } }
				try { $result = $this->processor->process( $path, array( 'width' => $width, 'height' => $height, 'mime' => $mime, 'quality' => 'image/avif' === $mime ? (int) $conversion['avif_quality'] : (int) $conversion['webp_quality'], 'destination' => $destination, 'preserve_transparency' => ! empty( $conversion['preserve_transparency'] ), 'strip_metadata' => ! empty( $conversion['remove_metadata'] ), 'preserve_copyright' => ! empty( $conversion['preserve_copyright'] ) ) ); $variants[] = $this->variant( $result['path'], $base_url . basename( $result['path'] ), $mime, $result['width'], $result['height'] ); } catch ( RuntimeException $error ) { continue; }
			}
		}
		$variants = $this->deduplicate( $variants ); $latest = wp_get_attachment_metadata( $attachment_id ); $latest = is_array( $latest ) ? $latest : array(); $latest[ self::META_KEY ] = $variants;
		if ( false === wp_update_attachment_metadata( $attachment_id, $latest ) ) { throw new RuntimeException( 'responsive_metadata_update_failed' ); }
		update_post_meta( $attachment_id, '_pipo_responsive_variants', $variants ); $record = $this->images->find_by_attachment( $attachment_id ); if ( $record ) { $this->images->update( $record->id, array( 'responsive_variants' => wp_json_encode( $variants ), 'updated_at' => current_time( 'mysql', true ) ) ); }
		return $variants;
	}

	public function start_bulk( $batch_size = 10 ) { $now = current_time( 'mysql', true ); return $this->jobs->insert( array( 'job_type' => 'responsive_regenerate', 'status' => 'pending', 'priority' => 10, 'payload' => wp_json_encode( array( 'offset' => 0, 'batch_size' => max( 1, min( 50, (int) $batch_size ) ) ) ), 'available_at' => $now, 'created_at' => $now, 'updated_at' => $now ) ); }
	/** Processes a caller-supplied bulk slice, keeping a single request bounded. */
	public function regenerate_bulk( $attachment_ids, $limit = 10 ) { $results = array(); foreach ( array_slice( array_map( 'intval', (array) $attachment_ids ), 0, max( 1, min( 50, (int) $limit ) ) ) as $id ) { try { $results[ $id ] = $this->generate_for_attachment( $id, true ); } catch ( RuntimeException $error ) { $results[ $id ] = $error->getMessage(); } } return $results; }
	private function existing_by_format( $metadata, $original_mime ) { $found = array(); foreach ( $metadata['sizes'] ?? array() as $size ) { if ( empty( $size['width'] ) ) { continue; } $mime = $size['mime-type'] ?? $original_mime; $found[ $mime ][] = (int) $size['width']; } foreach ( $metadata[ self::META_KEY ] ?? array() as $variant ) { if ( isset( $variant['mime'], $variant['width'] ) ) { $found[ $variant['mime'] ][] = (int) $variant['width']; } } return $found; }
	private function variant( $path, $url, $mime, $width, $height ) { return array( 'file' => basename( $path ), 'path' => wp_normalize_path( $path ), 'url' => $url, 'mime' => $mime, 'width' => (int) $width, 'height' => (int) $height, 'size' => (int) filesize( $path ) ); }
	private function deduplicate( $variants ) { $unique = array(); foreach ( $variants as $variant ) { $unique[ $variant['mime'] . ':' . $variant['width'] ] = $variant; } return array_values( $unique ); }
	private function remove_variants( $variants ) { foreach ( (array) $variants as $variant ) { if ( ! empty( $variant['path'] ) && false !== strpos( basename( $variant['path'] ), '-pipo-' ) && is_file( $variant['path'] ) ) { wp_delete_file( $variant['path'] ); } } }
	private function disk_is_safe( $directory, $estimated ) { if ( ! function_exists( 'disk_free_space' ) ) { return true; } $free = @disk_free_space( $directory ); return false === $free || $free > self::DISK_RESERVE + (int) $estimated; }
}
