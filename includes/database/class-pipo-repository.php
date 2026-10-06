<?php
/** Shared safe repository primitives. @package PersianImagePerformanceOptimizer */

defined( 'ABSPATH' ) || exit;

abstract class PIPO_Repository {
	/** @var wpdb */
	protected $db;
	/** @var string */
	protected $table;
	/** @var array<string,string> */
	protected $formats = array();

	public function __construct( $db = null ) {
		global $wpdb;
		$this->db    = $db ?: $wpdb;
		$this->table = $this->db->prefix . $this->table_suffix();
	}

	abstract protected function table_suffix();

	/** @return int|false */
	public function insert( $data ) {
		$data = $this->whitelist( $data );
		if ( ! $data || false === $this->db->insert( $this->table, $data, $this->format_list( $data ) ) ) {
			return false;
		}
		return (int) $this->db->insert_id;
	}

	/** @return int|false */
	public function update( $id, $data ) {
		$data = $this->whitelist( $data );
		return $data ? $this->db->update( $this->table, $data, array( 'id' => (int) $id ), $this->format_list( $data ), array( '%d' ) ) : false;
	}

	/** @return object|null */
	public function find( $id ) {
		return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name.
	}

	/** @return int|false */
	public function delete( $id ) {
		return $this->db->delete( $this->table, array( 'id' => (int) $id ), array( '%d' ) );
	}

	/** @return array<int,object> */
	public function all( $limit = 100, $offset = 0 ) {
		return $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d OFFSET %d", max( 1, min( 500, (int) $limit ) ), max( 0, (int) $offset ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array<string,mixed> */
	protected function whitelist( $data ) {
		return is_array( $data ) ? array_intersect_key( $data, $this->formats ) : array();
	}

	/** @return array<int,string> */
	private function format_list( $data ) {
		$formats = array();
		foreach ( $data as $key => $value ) {
			$formats[] = $this->formats[ $key ];
		}
		return $formats;
	}
}
