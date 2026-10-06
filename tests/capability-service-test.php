<?php
/** Dependency-free unit tests for deterministic capability reporting. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_MEMORY_LIMIT', '256M' );
$GLOBALS['wp_version'] = '6.6.2';

function wp_get_upload_dir() { return array( 'basedir' => sys_get_temp_dir() ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function wp_is_writable( $path ) { return is_writable( $path ); }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function wp_unslash( $value ) { return $value; }
function get_temp_dir() { return sys_get_temp_dir() . '/'; }
function size_format( $bytes ) { return round( $bytes / 1048576, 1 ) . ' MB'; }
function wp_get_image_editor() {}

require dirname( __DIR__ ) . '/includes/class-pipo-capability-service.php';

function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

$base = array(
	'imagick_available' => false,
	'imagick_version'   => '',
	'gd_available'      => false,
	'webp_encode'       => false,
	'webp_decode'       => false,
	'avif_encode'       => false,
	'avif_decode'       => false,
	'jpeg_support'      => false,
	'png_support'       => false,
);

$unsupported = ( new PIPO_Capability_Service( $base ) )->detect();
assert_same( 'none', $unsupported['preferred_engine'], 'No processing engine must be reported when no codec exists.' );
assert_same( 'غیرفعال', $unsupported['items']['avif_encode']['status'], 'Unsupported AVIF encoding must have a disabled state.' );
assert_same( 'warning', $unsupported['items']['avif_encode']['severity'], 'Unsupported AVIF should warn, not claim success.' );

$wordpress = ( new PIPO_Capability_Service( array_merge( $base, array( 'gd_available' => true, 'jpeg_support' => true ) ) ) )->detect();
assert_same( 'wordpress', $wordpress['preferred_engine'], 'WordPress Image Editor must precede the explicit GD fallback.' );

$imagick = ( new PIPO_Capability_Service( array_merge( $base, array( 'imagick_available' => true, 'imagick_version' => 'ImageMagick 7.1', 'webp_encode' => true ) ) ) )->detect();
assert_same( 'imagick', $imagick['preferred_engine'], 'Imagick must have the highest priority.' );
assert_same( 'success', $imagick['items']['webp_encode']['severity'], 'Detected WebP encoding must be successful.' );
assert_same( 'ImageMagick 7.1', $imagick['imagick_version'], 'The real Imagick version must remain visible.' );

$server = ( new PIPO_Capability_Service( array_merge( $base, array( 'server_type' => 'litespeed', 'server_software' => 'LiteSpeed' ) ) ) )->detect();
assert_same( 'litespeed', $server['server_type'], 'LiteSpeed detection must remain distinct.' );

echo "All capability service tests passed.\n";
