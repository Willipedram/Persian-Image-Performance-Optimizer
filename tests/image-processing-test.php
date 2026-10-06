<?php
/** Integration-style tests for the GD core, atomic output and rollback. */

define( 'ABSPATH', __DIR__ . '/' );
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function wp_normalize_path( $value ) { return str_replace( '\\', '/', $value ); }
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function wp_generate_password( $length ) { static $i = 0; return substr( hash( 'sha256', ++$i ), 0, $length ); }
function wp_json_encode( $value ) { return json_encode( $value ); }

require dirname( __DIR__ ) . '/includes/processing/interfaces.php';
require dirname( __DIR__ ) . '/includes/processing/class-pipo-image-validator.php';
require dirname( __DIR__ ) . '/includes/processing/class-pipo-gd-engine.php';
require dirname( __DIR__ ) . '/includes/processing/class-pipo-backup-manager.php';
require dirname( __DIR__ ) . '/includes/processing/class-pipo-rollback-manager.php';
require dirname( __DIR__ ) . '/includes/processing/class-pipo-image-processor.php';

function processing_assert( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
function fixture_image( $path, $width, $height, $mime, $transparent = false ) {
	$image = imagecreatetruecolor( $width, $height );
	if ( $transparent ) { imagealphablending( $image, false ); imagesavealpha( $image, true ); imagefill( $image, 0, 0, imagecolorallocatealpha( $image, 0, 0, 0, 127 ) ); }
	else { imagefill( $image, 0, 0, imagecolorallocate( $image, 40, 120, 200 ) ); }
	if ( 'image/jpeg' === $mime ) { imagejpeg( $image, $path, 90 ); }
	if ( 'image/png' === $mime ) { imagepng( $image, $path ); }
	if ( 'image/webp' === $mime ) { imagewebp( $image, $path, 90 ); }
	imagedestroy( $image );
}

$root = sys_get_temp_dir() . '/pipo-processing-' . getmypid(); mkdir( $root ); mkdir( $root . '/backups' );
$validator = new PIPO_Image_Validator(); $backup = new PIPO_Backup_Manager( $root . '/backups', $validator );
$processor = new PIPO_Image_Processor( array( new PIPO_GD_Engine() ), $backup, $validator );

fixture_image( $root . '/landscape.jpg', 400, 200, 'image/jpeg' );
fixture_image( $root . '/portrait.jpg', 200, 400, 'image/jpeg' );
fixture_image( $root . '/square.png', 240, 240, 'image/png' );
fixture_image( $root . '/alpha.png', 160, 100, 'image/png', true );
if ( function_exists( 'imagewebp' ) ) { fixture_image( $root . '/source.webp', 180, 90, 'image/webp' ); }

$result = $processor->process( $root . '/landscape.jpg', array( 'width' => 100, 'height' => 100 ) );
processing_assert( 100 === $result['width'] && 50 === $result['height'], 'Landscape aspect ratio must be maintained.' );
$result = $processor->process( $root . '/portrait.jpg', array( 'width' => 100, 'height' => 100 ) );
processing_assert( 50 === $result['width'] && 100 === $result['height'], 'Portrait aspect ratio must be maintained.' );
$result = $processor->process( $root . '/square.png', array( 'width' => 500, 'height' => 500 ) );
processing_assert( 240 === $result['width'] && 240 === $result['height'], 'Square images must never upscale.' );
$result = $processor->process( $root . '/alpha.png', array( 'width' => 80, 'height' => 80 ) );
processing_assert( 80 === $result['width'] && 50 === $result['height'] && $validator->has_alpha_channel( $result['path'], 'image/png' ), 'Transparent PNG alpha must survive resize.' );
if ( is_file( $root . '/source.webp' ) ) { $result = $processor->process( $root . '/source.webp', array( 'width' => 90, 'height' => 90 ) ); processing_assert( 'image/webp' === $result['mime'] && 90 === $result['width'], 'WebP must load, resize and save.' ); }

file_put_contents( $root . '/corrupt.jpg', 'broken' );
try { $processor->process( $root . '/corrupt.jpg' ); processing_assert( false, 'Corrupt input must fail validation.' ); } catch ( RuntimeException $e ) { processing_assert( 'invalid_image' === $e->getMessage(), 'Corrupt input should return the validation error.' ); }

$original_hash = hash_file( 'sha256', $root . '/landscape.jpg' );
try { $processor->process( $root . '/landscape.jpg', array( 'destination' => $root . '/missing/output.jpg' ) ); processing_assert( false, 'An unwritable destination must fail.' ); } catch ( RuntimeException $e ) { processing_assert( $original_hash === hash_file( 'sha256', $root . '/landscape.jpg' ), 'Write failure must leave the original intact.' ); }

$result = $processor->process( $root . '/landscape.jpg', array( 'width' => 80, 'height' => 80, 'overwrite' => true ) );
processing_assert( is_file( $result['backup'] ) && 80 === $result['width'], 'Explicit overwrite must create a valid backup first.' );
$backup->restore( $result['backup'], $root . '/landscape.jpg' );
processing_assert( $original_hash === hash_file( 'sha256', $root . '/landscape.jpg' ), 'Rollback must restore the exact previous version.' );
$rollback = new PIPO_Rollback_Manager( $backup );
$processor->process( $root . '/landscape.jpg', array( 'width' => 70, 'height' => 70, 'overwrite' => true ) );
$rollback->restore_previous_version( $root . '/landscape.jpg' );
processing_assert( $original_hash === hash_file( 'sha256', $root . '/landscape.jpg' ), 'Rollback manager must restore the latest validated version.' );

try { $processor->process( $root . '/landscape.jpg', array( 'width' => 60, 'height' => 60, 'overwrite' => true, 'metadata_callback' => function () { return false; } ) ); processing_assert( false, 'Metadata failure must abort processing.' ); } catch ( RuntimeException $e ) { processing_assert( $original_hash === hash_file( 'sha256', $root . '/landscape.jpg' ), 'Metadata failure must atomically roll back the original.' ); }

$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $iterator as $item ) { $item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); } rmdir( $root );
echo "All image processing tests passed.\n";
