<?php
defined( 'ABSPATH' ) || exit;

final class PIPO_Image_Repository extends PIPO_Repository {
	protected $formats = array(
		'attachment_id' => '%d', 'original_path' => '%s', 'original_url' => '%s', 'mime_type' => '%s',
		'original_width' => '%d', 'original_height' => '%d', 'original_size' => '%d', 'webp_exists' => '%d',
		'avif_exists' => '%d', 'optimized' => '%d', 'optimization_status' => '%s', 'compression_quality' => '%d',
		'preferred_format' => '%s', 'optimized_size' => '%d', 'bytes_saved' => '%d', 'saving_percent' => '%f',
		'original_hash' => '%s', 'last_scanned_at' => '%s', 'last_optimized_at' => '%s', 'error_code' => '%s',
		'generated_sizes' => '%s', 'attachment_metadata' => '%s', 'animated_gif' => '%d', 'is_svg' => '%d',
		'responsive_variants' => '%s',
		'has_transparency' => '%d', 'is_corrupt' => '%d', 'is_modified' => '%d', 'file_modified_at' => '%d',
		'error_message' => '%s', 'created_at' => '%s', 'updated_at' => '%s',
	);
	protected function table_suffix() { return 'pipo_images'; }

	/** @return object|null */
	public function find_by_attachment( $attachment_id ) {
		return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE attachment_id = %d", (int) $attachment_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Inserts a new attachment record or updates its existing row. @return int|false */
	public function upsert_attachment( $attachment_id, $data ) {
		$existing = $this->find_by_attachment( $attachment_id );
		$data['attachment_id'] = (int) $attachment_id;
		if ( $existing ) {
			$result = $this->update( $existing->id, $data );
			return false === $result ? false : (int) $existing->id;
		}
		return $this->insert( $data );
	}

	/** @return array<string,int|string|null> */
	public function statistics( $total_attachments ) {
		$scanned = (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE last_scanned_at IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$errors  = (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE error_code IS NOT NULL AND error_code != ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last    = $this->db->get_var( "SELECT MAX(last_scanned_at) FROM {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array( 'total' => (int) $total_attachments, 'scanned' => $scanned, 'pending' => max( 0, (int) $total_attachments - $scanned ), 'errors' => $errors, 'last_scan' => $last );
	}

	/** @return array<int,int> */
	public function failed_attachment_ids( $limit = 100 ) {
		return array_map( 'intval', $this->db->get_col( $this->db->prepare( "SELECT attachment_id FROM {$this->table} WHERE error_code IS NOT NULL AND error_code != '' ORDER BY id ASC LIMIT %d", max( 1, min( 500, (int) $limit ) ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
