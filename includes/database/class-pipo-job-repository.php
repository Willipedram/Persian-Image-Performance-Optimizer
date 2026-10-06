<?php
defined( 'ABSPATH' ) || exit;

final class PIPO_Job_Repository extends PIPO_Repository {
	const STATUSES = array( 'pending', 'running', 'completed', 'failed', 'paused' );
	protected $formats = array(
		'job_type' => '%s', 'attachment_id' => '%d', 'status' => '%s', 'priority' => '%d', 'attempts' => '%d',
		'max_attempts' => '%d', 'payload' => '%s', 'available_at' => '%s', 'started_at' => '%s',
		'completed_at' => '%s', 'last_error' => '%s', 'created_at' => '%s', 'updated_at' => '%s',
	);
	protected function table_suffix() { return 'pipo_jobs'; }

	public function insert( $data ) {
		if ( isset( $data['status'] ) && ! in_array( $data['status'], self::STATUSES, true ) ) {
			return false;
		}
		return parent::insert( $data );
	}

	public function update( $id, $data ) {
		if ( isset( $data['status'] ) && ! in_array( $data['status'], self::STATUSES, true ) ) {
			return false;
		}
		return parent::update( $id, $data );
	}

	/** @return object|null */
	public function latest_scanner_job() {
		return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE job_type = %s ORDER BY id DESC LIMIT 1", 'media_scan' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
