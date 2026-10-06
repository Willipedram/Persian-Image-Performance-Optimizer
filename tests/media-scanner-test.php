<?php
/** File-level tests for the incremental media scanner. */

define( 'ABSPATH', __DIR__ . '/' );
$fixture_dir = sys_get_temp_dir() . '/pipo-scanner-' . getmypid();
mkdir( $fixture_dir );

$files = array(
	1 => $fixture_dir . '/missing.jpg',
	2 => $fixture_dir . '/corrupt.png',
	3 => $fixture_dir . '/vector.svg',
	4 => $fixture_dir . '/animated.gif',
	5 => $fixture_dir . '/image.webp',
);
file_put_contents( $files[2], 'not an image' );
file_put_contents( $files[3], '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 180"><path d="M0 0"/></svg>' );
file_put_contents( $files[4], "GIF89a\x01\x00\x01\x00\x00\x00\x00" . "\x00\x21\xF9\x04\x00\x00\x00\x00\x00\x2C" . "\x00\x21\xF9\x04\x00\x00\x00\x00\x00\x2C" );
file_put_contents( $files[5], "RIFF\x16\x00\x00\x00WEBPVP8X\x0A\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" );

$mimes = array( 1 => 'image/jpeg', 2 => 'image/png', 3 => 'image/svg+xml', 4 => 'image/gif', 5 => 'image/webp' );
$GLOBALS['pipo_test_files'] = $files;
$GLOBALS['pipo_test_mimes'] = $mimes;
function current_time() { return '2026-10-04 12:00:00'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function get_attached_file( $id ) { return $GLOBALS['pipo_test_files'][ $id ]; }
function wp_get_attachment_url( $id ) { return 'https://example.test/uploads/' . basename( $GLOBALS['pipo_test_files'][ $id ] ); }
function get_post_mime_type( $id ) { return $GLOBALS['pipo_test_mimes'][ $id ]; }
function wp_get_attachment_metadata( $id ) { return 2 === $id ? 'broken metadata' : array( 'sizes' => array( 'thumbnail' => array( 'file' => 'thumb.jpg' ) ) ); }

final class ScannerImageRepo {
	public $rows = array();
	public function find_by_attachment( $id ) { return isset( $this->rows[ $id ] ) ? (object) $this->rows[ $id ] : null; }
	public function upsert_attachment( $id, $data ) { $this->rows[ $id ] = array_merge( isset( $this->rows[ $id ] ) ? $this->rows[ $id ] : array( 'id' => $id ), $data, array( 'attachment_id' => $id ) ); return $id; }
	public function update( $id, $data ) { foreach ( $this->rows as $attachment => $row ) { if ( $row['id'] === $id ) { $this->rows[ $attachment ] = array_merge( $row, $data ); return 1; } } return 0; }
}
final class ScannerJobRepo {
	public $job;
	public function insert( $data ) { $this->job = (object) array_merge( array( 'id' => 1, 'started_at' => null ), $data ); return 1; }
	public function latest_scanner_job() { return $this->job; }
	public function update( $id, $data ) { foreach ( $data as $key => $value ) { $this->job->$key = $value; } return 1; }
}
final class ScannerLogRepo { public $rows = array(); public function insert( $data ) { $this->rows[] = $data; return count( $this->rows ); } }

require dirname( __DIR__ ) . '/includes/scanner/class-pipo-media-scanner.php';
function assert_scan( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

$images = new ScannerImageRepo(); $logs = new ScannerLogRepo(); $jobs = new ScannerJobRepo();
$scanner = new PIPO_Media_Scanner( $images, $jobs, $logs );
assert_scan( 1 === $scanner->start( 'incremental', 999 ), 'An incremental scan job should start.' );
assert_scan( 50 === json_decode( $jobs->job->payload, true )['batch_size'], 'Invalid batch sizes must fall back to 50.' );
assert_scan( 1 === $scanner->stop() && 'paused' === $jobs->job->status, 'Active scans must be pausable.' );
assert_scan( 1 === $scanner->resume() && 'pending' === $jobs->job->status, 'Paused scans must resume from their stored payload.' );
assert_scan( false === $scanner->scan_attachment( 1 ), 'Missing files must fail without stopping the scanner.' );
assert_scan( 'missing_file' === $images->rows[1]['error_code'], 'Missing file error must be stored.' );
assert_scan( false === $scanner->scan_attachment( 2 ), 'Corrupt raster must be rejected gracefully.' );
assert_scan( 1 === $images->rows[2]['is_corrupt'], 'Corrupt status must be stored even with malformed metadata.' );
assert_scan( true === $scanner->scan_attachment( 3 ), 'Safe SVG dimensions should scan.' );
assert_scan( 320 === $images->rows[3]['original_width'] && 180 === $images->rows[3]['original_height'], 'SVG viewBox dimensions must be detected.' );
assert_scan( 1 === $images->rows[3]['is_svg'], 'SVG status must be stored.' );
assert_scan( true === $scanner->scan_attachment( 4 ), 'GIF fixture should scan.' );
assert_scan( 1 === $images->rows[4]['animated_gif'], 'Animated GIF must be protected with a stored flag.' );
assert_scan( true === $scanner->scan_attachment( 5 ), 'WebP fixture should scan.' );
assert_scan( 1 === $images->rows[5]['webp_exists'], 'Original WebP must count as existing WebP.' );
assert_scan( count( $logs->rows ) >= 2, 'File failures must be logged while processing continues.' );

foreach ( $files as $file ) { if ( is_file( $file ) ) { unlink( $file ); } }
rmdir( $fixture_dir );
echo "All media scanner tests passed.\n";
