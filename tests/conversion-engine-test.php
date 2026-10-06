<?php
/** Conversion policy, compression accounting and capability tests. */

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['conversion_options'] = array();
function get_option( $key, $default = array() ) { return 'pipo_conversion_settings' === $key ? $GLOBALS['conversion_options'] : $default; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_generate_password( $length ) { static $i = 100; return substr( hash( 'sha256', ++$i ), 0, $length ); }
function wp_normalize_path( $value ) { return str_replace( '\\', '/', $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_delete_file( $path ) { return unlink( $path ); }
function current_time() { return '2026-10-04 12:00:00'; }

require dirname( __DIR__ ) . '/includes/processing/interfaces.php';
require dirname( __DIR__ ) . '/includes/processing/class-pipo-image-validator.php';
require dirname( __DIR__ ) . '/includes/processing/class-pipo-gd-engine.php';
require dirname( __DIR__ ) . '/includes/processing/class-pipo-image-processor.php';
require dirname( __DIR__ ) . '/includes/conversion/class-pipo-conversion-settings.php';
require dirname( __DIR__ ) . '/includes/conversion/class-pipo-conversion-engine.php';

final class ConversionCapabilities { private $avif; public function __construct( $avif ) { $this->avif = $avif; } public function detect() { return array( 'avif_encode' => $this->avif ); } }
final class ConversionImages { public function find_by_attachment() { return null; } public function update() { return 1; } }
final class ConversionLogs { public $history = array(); public function record_compression( $id, $data ) { $this->history[] = $data; return count( $this->history ); } }
final class FakeConversionProcessor { private $larger; public function __construct( $larger = false ) { $this->larger = $larger; } public function validate( $path ) { $info = getimagesize( $path ); return array( 'path' => $path, 'mime' => $info['mime'], 'width' => $info[0], 'height' => $info[1], 'size' => filesize( $path ) ); } public function process( $path, $options ) { if ( 'image/webp' === $options['mime'] ) { $content = "RIFF\x16\x00\x00\x00WEBPVP8X\x0A\x00\x00\x00\x10\x00\x00\x00\x00\x00\x00\x00\x00\x00"; } else { $content = str_repeat( 'A', 10 ); } if ( $this->larger ) { $content .= str_repeat( 'X', filesize( $path ) + 100 ); } file_put_contents( $options['destination'], $content ); return array( 'path' => $options['destination'], 'mime' => $options['mime'], 'width' => 1, 'height' => 1, 'size' => strlen( $content ), 'engine' => 'test-codec' ); } }
function conversion_assert( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
function conversion_fixture( $path, $width, $height, $mime, $alpha = false, $noise = false ) { if ( 'image/webp' === $mime ) { file_put_contents( $path, "RIFF\x16\x00\x00\x00WEBPVP8X\x0A\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" ); return; } $im = imagecreatetruecolor( $width, $height ); if ( $alpha ) { imagealphablending( $im, false ); imagesavealpha( $im, true ); imagefill( $im, 0, 0, imagecolorallocatealpha( $im, 0, 0, 0, 127 ) ); } elseif ( $noise ) { for ( $y = 0; $y < $height; $y++ ) { for ( $x = 0; $x < $width; $x++ ) { imagesetpixel( $im, $x, $y, imagecolorallocate( $im, random_int( 0, 255 ), random_int( 0, 255 ), random_int( 0, 255 ) ) ); } } } else { imagefill( $im, 0, 0, imagecolorallocate( $im, 90, 130, 180 ) ); } if ( 'image/jpeg' === $mime ) { imagejpeg( $im, $path, 95 ); } else { imagepng( $im, $path ); } imagedestroy( $im ); }

$root = sys_get_temp_dir() . '/pipo-conversion-' . getmypid(); mkdir( $root );
$validator = new PIPO_Image_Validator(); $processor = new PIPO_Image_Processor( array( new PIPO_GD_Engine() ), new class implements BackupManagerInterface { public function create_backup( $p ) {} public function validate_backup( $p, $m = null ) {} public function restore( $a, $b ) {} public function previous_versions( $p ) { return array(); } public function remove_generated_variants( $p ) { return 0; } }, $validator );
$settings = new PIPO_Conversion_Settings( new ConversionCapabilities( false ) ); $logs = new ConversionLogs();
$engine = new PIPO_Conversion_Engine( new FakeConversionProcessor(), $settings, new ConversionImages(), $logs );
conversion_fixture( $root . '/photo.jpg', 300, 180, 'image/jpeg' );
$jpeg = $engine->convert( $root . '/photo.jpg', 'image/webp', 1 );
conversion_assert( 'image/webp' === $jpeg['target_mime'] && isset( $jpeg['original_size'], $jpeg['optimized_size'], $jpeg['bytes_saved'], $jpeg['saving_percent'] ), 'JPEG to WebP must record before/after history.' );
conversion_fixture( $root . '/transparent.png', 120, 80, 'image/png', true );
$png = $engine->convert( $root . '/transparent.png', 'image/webp', 2 );
conversion_assert( $png['preferred'] && $validator->has_alpha_channel( $png['output_path'], 'image/webp' ), 'Transparent PNG to WebP must preserve alpha.' );
conversion_fixture( $root . '/large.jpg', 700, 500, 'image/jpeg', false, true );
$large = $engine->convert( $root . '/large.jpg', 'image/webp', 3 );
conversion_assert( $large['optimized_size'] < $large['original_size'] && $large['bytes_saved'] > 0 && $large['saving_percent'] > 0, 'Large JPEG compression must report savings.' );
conversion_fixture( $root . '/existing.webp', 160, 90, 'image/webp' );
try { $engine->convert( $root . '/existing.webp', 'image/avif', 4 ); conversion_assert( false, 'Unsupported AVIF must remain disabled.' ); } catch ( RuntimeException $e ) { conversion_assert( 'avif_unsupported_or_disabled' === $e->getMessage(), 'Unsupported AVIF must fail safely.' ); }
$sanitized = $settings->sanitize( array( 'avif_enabled' => 1, 'webp_quality' => 80, 'avif_quality' => 55 ) );
conversion_assert( 0 === $sanitized['avif_enabled'], 'AVIF setting must be forced off without encoding support.' );
conversion_assert( count( $logs->history ) === 3, 'Every completed conversion must create compression history.' );
$large_output_engine = new PIPO_Conversion_Engine( new FakeConversionProcessor( true ), $settings, new ConversionImages(), new ConversionLogs() );
$not_preferred = $large_output_engine->convert( $root . '/photo.jpg', 'image/webp', 6 );
conversion_assert( ! $not_preferred['preferred'] && null === $not_preferred['output_path'], 'An output larger than its original must never become preferred.' );

$GLOBALS['conversion_options'] = array( 'avif_enabled' => 1, 'preserve_copyright' => 0 );
$avif_settings = new PIPO_Conversion_Settings( new ConversionCapabilities( true ) ); $avif_logs = new ConversionLogs();
$avif_engine = new PIPO_Conversion_Engine( new FakeConversionProcessor(), $avif_settings, new ConversionImages(), $avif_logs );
foreach ( array( $root . '/photo.jpg', $root . '/transparent.png', $root . '/existing.webp' ) as $source ) { $avif = $avif_engine->convert( $source, 'image/avif', 5 ); conversion_assert( $avif['preferred'] && 'image/avif' === $avif['target_mime'], 'JPEG, PNG and WebP to AVIF must run when capability is explicitly available.' ); }

$iterator = new FilesystemIterator( $root ); foreach ( $iterator as $file ) { unlink( $file->getPathname() ); } rmdir( $root );
echo "All conversion engine tests passed.\n";
