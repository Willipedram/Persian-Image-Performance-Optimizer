<?php
/** Responsive candidate planning and generation tests. */

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['responsive_options'] = array( 'jpeg_enabled' => 1, 'webp_enabled' => 0, 'avif_enabled' => 0 );
$GLOBALS['conversion_options'] = array( 'webp_enabled' => 0, 'avif_enabled' => 0, 'webp_quality' => 80, 'avif_quality' => 55, 'remove_metadata' => 0, 'preserve_copyright' => 0, 'preserve_transparency' => 1 );
$GLOBALS['attachment_files'] = array(); $GLOBALS['attachment_metadata'] = array();
function get_option( $key, $default = array() ) { if ( 'pipo_responsive_settings' === $key ) { return $GLOBALS['responsive_options']; } if ( 'pipo_conversion_settings' === $key ) { return $GLOBALS['conversion_options']; } return $default; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_generate_password( $length ) { static $i = 300; return substr( hash( 'sha256', ++$i ), 0, $length ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function get_attached_file( $id ) { return $GLOBALS['attachment_files'][ $id ]; }
function wp_get_attachment_url( $id ) { return 'https://example.test/uploads/' . basename( $GLOBALS['attachment_files'][ $id ] ); }
function wp_get_attachment_metadata( $id ) { return $GLOBALS['attachment_metadata'][ $id ]; }
function wp_update_attachment_metadata( $id, $data ) { $GLOBALS['attachment_metadata'][ $id ] = $data; return true; }
function update_post_meta() { return true; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function current_time() { return '2026-10-04 12:00:00'; }
function wp_delete_file( $path ) { return unlink( $path ); }

require dirname( __DIR__ ) . '/includes/processing/interfaces.php';
require dirname( __DIR__ ) . '/includes/processing/class-pipo-image-validator.php';
require dirname( __DIR__ ) . '/includes/processing/class-pipo-gd-engine.php';
require dirname( __DIR__ ) . '/includes/processing/class-pipo-image-processor.php';
require dirname( __DIR__ ) . '/includes/conversion/class-pipo-conversion-settings.php';
require dirname( __DIR__ ) . '/includes/responsive/class-pipo-responsive-settings.php';
require dirname( __DIR__ ) . '/includes/responsive/class-pipo-responsive-generator.php';

final class ResponsiveCapabilities { public function detect() { return array( 'avif_encode' => false ); } }
final class ResponsiveImages { public function find_by_attachment() { return null; } public function update() { return 1; } }
final class ResponsiveJobs {}
function responsive_assert( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
function responsive_fixture( $path, $width, $height ) { $image = imagecreatetruecolor( $width, $height ); imagefill( $image, 0, 0, imagecolorallocate( $image, 80, 140, 200 ) ); imagejpeg( $image, $path, 90 ); imagedestroy( $image ); }

$root = sys_get_temp_dir() . '/pipo-responsive-' . getmypid(); mkdir( $root );
$validator = new PIPO_Image_Validator(); $processor = new PIPO_Image_Processor( array( new PIPO_GD_Engine() ), new class implements BackupManagerInterface { public function create_backup( $p ) {} public function validate_backup( $p, $m = null ) {} public function restore( $a, $b ) {} public function previous_versions( $p ) { return array(); } public function remove_generated_variants( $p ) { return 0; } }, $validator );
$responsive_settings = new PIPO_Responsive_Settings(); $conversion_settings = new PIPO_Conversion_Settings( new ResponsiveCapabilities() );
$generator = new PIPO_Responsive_Generator( $processor, $responsive_settings, $conversion_settings, new ResponsiveImages(), new ResponsiveJobs() );

responsive_assert( array( 320, 480, 640, 768, 866 ) === $generator->candidate_widths( 866 ), 'Portrait candidates must stop at the 866px original.' );
responsive_assert( array() === $generator->candidate_widths( 200 ), 'Small originals below the minimum must create no candidates.' );
$huge = $generator->candidate_widths( 5000 ); responsive_assert( count( $huge ) === 6 && max( $huge ) <= 1920, 'Huge originals must obey maximum width and variant count.' );
$without_duplicate = $generator->candidate_widths( 1024, array( 480, 768 ) ); responsive_assert( ! in_array( 480, $without_duplicate, true ) && ! in_array( 768, $without_duplicate, true ), 'Existing WordPress widths must not be duplicated.' );

foreach ( array( 1 => array( 866, 1280 ), 2 => array( 1280, 720 ), 3 => array( 900, 900 ) ) as $id => $dimensions ) { $path = $root . '/image-' . $id . '.jpg'; responsive_fixture( $path, $dimensions[0], $dimensions[1] ); $GLOBALS['attachment_files'][ $id ] = $path; $GLOBALS['attachment_metadata'][ $id ] = array( 'width' => $dimensions[0], 'height' => $dimensions[1], 'sizes' => 2 === $id ? array( 'medium' => array( 'width' => 640, 'height' => 360, 'file' => 'core-medium.jpg', 'mime-type' => 'image/jpeg' ) ) : array() ); }
$portrait = $generator->generate_for_attachment( 1 ); responsive_assert( count( $portrait ) <= 6 && abs( $portrait[0]['height'] / $portrait[0]['width'] - 1280 / 866 ) < 0.01, 'Portrait variants must preserve their aspect ratio.' );
$landscape = $generator->generate_for_attachment( 2 ); responsive_assert( ! in_array( 640, array_column( $landscape, 'width' ), true ), 'Core landscape thumbnails must remain untouched and not be duplicated.' );
$square = $generator->generate_for_attachment( 3 ); responsive_assert( $square[0]['width'] === $square[0]['height'], 'Square variants must remain square.' );
responsive_assert( isset( $GLOBALS['attachment_metadata'][2]['sizes']['medium'] ) && isset( $GLOBALS['attachment_metadata'][2][ PIPO_Responsive_Generator::META_KEY ] ), 'Responsive metadata must merge without replacing core sizes.' );

$iterator = new FilesystemIterator( $root ); foreach ( $iterator as $file ) { unlink( $file->getPathname() ); } rmdir( $root );
echo "All responsive generator tests passed.\n";
