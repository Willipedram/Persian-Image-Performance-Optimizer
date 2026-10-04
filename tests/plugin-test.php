<?php
/** Minimal, dependency-free tests for option handling and output formats. */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['pipo_test_options'] = array();

function add_filter() {}
function add_action() {}
function get_option() { return $GLOBALS['pipo_test_options']; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_image_editor_supports() { return true; }

require dirname( __DIR__ ) . '/includes/class-pipo-plugin.php';

function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
}

$plugin = PIPO_Plugin::instance();

assert_same(
	array( 'enabled' => 1, 'quality' => 100, 'generate_webp' => 0 ),
	$plugin->sanitize_options( array( 'enabled' => 'yes', 'quality' => 999 ) ),
	'Options should be normalized and quality should be capped.'
);
assert_same( 82, $plugin->set_editor_quality( 90, 'image/jpeg' ), 'Default quality should apply to JPEG.' );
assert_same( 90, $plugin->set_editor_quality( 90, 'image/png' ), 'PNG quality should not be changed.' );
assert_same(
	array( 'image/jpeg' => 'image/webp', 'image/png' => 'image/webp' ),
	$plugin->use_webp_for_generated_sizes( array() ),
	'JPEG and PNG generated sizes should use WebP.'
);

$GLOBALS['pipo_test_options'] = array( 'enabled' => 0 );
assert_same( array(), $plugin->use_webp_for_generated_sizes( array() ), 'Disabled optimization should preserve formats.' );

echo "All plugin tests passed.\n";
