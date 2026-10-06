<?php
/** Dependency-free database migration and repository tests. */

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['pipo_options'] = array();
$GLOBALS['pipo_dbdelta_calls'] = 0;

function update_option( $key, $value ) { $GLOBALS['pipo_options'][ $key ] = $value; return true; }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['pipo_options'] ) ? $GLOBALS['pipo_options'][ $key ] : $default; }
function dbDelta( $sql ) {
	global $wpdb;
	$GLOBALS['pipo_dbdelta_calls']++;
	if ( preg_match( '/CREATE TABLE ([^ ]+)/', $sql, $match ) ) {
		$wpdb->tables[ $match[1] ] = $sql;
	}
	return array();
}

final class PIPO_Test_DB {
	public $prefix = 'wp_test_';
	public $insert_id = 0;
	public $last_error = '';
	public $tables = array();
	public $rows = array();

	public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function insert( $table, $data ) {
		$id = ++$this->insert_id;
		$this->rows[ $table ][ $id ] = (object) array_merge( array( 'id' => $id ), $data );
		return 1;
	}
	public function update( $table, $data, $where ) {
		$id = (int) $where['id'];
		if ( empty( $this->rows[ $table ][ $id ] ) ) { return 0; }
		foreach ( $data as $key => $value ) { $this->rows[ $table ][ $id ]->$key = $value; }
		return 1;
	}
	public function delete( $table, $where ) {
		$id = (int) $where['id'];
		if ( empty( $this->rows[ $table ][ $id ] ) ) { return 0; }
		unset( $this->rows[ $table ][ $id ] );
		return 1;
	}
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) { $query = preg_replace( '/%[dfs]/', is_numeric( $arg ) ? (string) $arg : "'" . addslashes( $arg ) . "'", $query, 1 ); }
		return $query;
	}
	public function get_row( $query ) {
		if ( ! preg_match( '/FROM ([^ ]+) WHERE (id|attachment_id) = ([0-9]+)/', $query, $match ) ) { $this->last_error = 'Unsupported query'; return null; }
		$table = $match[1]; $field = $match[2]; $value = (int) $match[3];
		foreach ( isset( $this->rows[ $table ] ) ? $this->rows[ $table ] : array() as $row ) {
			if ( isset( $row->$field ) && (int) $row->$field === $value ) { return $row; }
		}
		return null;
	}
	public function get_results( $query ) {
		if ( ! preg_match( '/FROM ([^ ]+)/', $query, $match ) ) { return array(); }
		return array_values( isset( $this->rows[ $match[1] ] ) ? $this->rows[ $match[1] ] : array() );
	}
}

function assert_true( $value, $message ) { if ( ! $value ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
function assert_same( $expected, $actual, $message ) { assert_true( $expected === $actual, $message . ': ' . var_export( $actual, true ) ); }

$wpdb = new PIPO_Test_DB();
require dirname( __DIR__ ) . '/includes/database/class-pipo-migrator.php';
require dirname( __DIR__ ) . '/includes/database/class-pipo-repository.php';
require dirname( __DIR__ ) . '/includes/database/class-pipo-image-repository.php';
require dirname( __DIR__ ) . '/includes/database/class-pipo-usage-repository.php';
require dirname( __DIR__ ) . '/includes/database/class-pipo-job-repository.php';
require dirname( __DIR__ ) . '/includes/database/class-pipo-log-repository.php';

$migrator = new PIPO_Migrator( $wpdb );
$migrator->migrate();
assert_same( 4, count( $wpdb->tables ), 'Fresh install must create four tables' );
assert_same( '1.3.0', get_option( 'pipo_db_version' ), 'Migration version must be stored' );
foreach ( $migrator->tables() as $table ) { assert_true( isset( $wpdb->tables[ $table ] ), 'Expected table is missing: ' . $table ); }

$first_schema = $wpdb->tables;
$migrator->migrate();
assert_same( $first_schema, $wpdb->tables, 'Repeated migration must be idempotent' );
$calls = $GLOBALS['pipo_dbdelta_calls'];
$migrator->maybe_migrate();
assert_same( $calls, $GLOBALS['pipo_dbdelta_calls'], 'Repeated activation must skip a current schema' );

assert_true( false !== strpos( $wpdb->tables['wp_test_pipo_images'], 'UNIQUE KEY attachment_id' ), 'Images unique attachment index missing' );
assert_true( false !== strpos( $wpdb->tables['wp_test_pipo_jobs'], 'KEY queue_lookup (status,available_at,priority)' ), 'Jobs queue index missing' );
assert_true( false !== strpos( $wpdb->tables['wp_test_pipo_usage'], 'KEY attachment_page (attachment_id,page_id)' ), 'Usage compound index missing' );
assert_true( false !== strpos( $wpdb->tables['wp_test_pipo_logs'], 'KEY attachment_created (attachment_id,created_at)' ), 'Logs compound index missing' );

$images = new PIPO_Image_Repository( $wpdb );
$id = $images->insert( array( 'attachment_id' => 42, 'original_path' => '/tmp/a.jpg', 'optimization_status' => 'pending', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00', 'unsafe_column' => 'discard' ) );
assert_same( 1, $id, 'Insert must return its identifier' );
assert_same( 42, $images->find( $id )->attachment_id, 'Inserted image must be selectable' );
assert_true( ! isset( $images->find( $id )->unsafe_column ), 'Unknown columns must be rejected' );
assert_same( 1, $images->update( $id, array( 'optimization_status' => 'completed' ) ), 'Update must affect the image' );
assert_same( 'completed', $images->find_by_attachment( 42 )->optimization_status, 'Updated image must be selectable by attachment' );

$jobs = new PIPO_Job_Repository( $wpdb );
assert_same( false, $jobs->insert( array( 'status' => 'invented' ) ), 'Unknown job statuses must be rejected' );
$job_id = $jobs->insert( array( 'job_type' => 'scan', 'status' => 'pending', 'available_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00' ) );
assert_true( false !== $job_id, 'Valid jobs must insert' );

$usage = new PIPO_Usage_Repository( $wpdb );
$usage_id = $usage->insert( array( 'attachment_id' => 42, 'page_url' => 'https://example.test/page', 'source_url' => 'https://example.test/a.jpg', 'current_src' => 'https://example.test/a.jpg', 'first_seen_at' => '2026-01-01 00:00:00', 'last_seen_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00' ) );
assert_same( 42, $usage->find( $usage_id )->attachment_id, 'Usage rows must insert and select' );

$logs = new PIPO_Log_Repository( $wpdb );
$log_id = $logs->insert( array( 'level' => 'info', 'operation' => 'scan', 'message' => 'Started', 'created_at' => '2026-01-01 00:00:00' ) );
assert_same( 'scan', $logs->find( $log_id )->operation, 'Log rows must insert and select' );
assert_same( '', $wpdb->last_error, 'Database operations must not produce SQL errors' );

echo "All database layer tests passed.\n";
