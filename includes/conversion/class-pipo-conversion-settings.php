<?php
/** Conversion settings with capability-aware AVIF safety. */

defined( 'ABSPATH' ) || exit;

final class PIPO_Conversion_Settings {
	const OPTION = 'pipo_conversion_settings';
	private $capabilities;
	public function __construct( $capabilities = null ) { $this->capabilities = $capabilities ?: new PIPO_Capability_Service(); }
	public static function defaults() { return array( 'webp_enabled' => 1, 'avif_enabled' => 0, 'webp_quality' => 80, 'avif_quality' => 55, 'webp_lossless' => 0, 'remove_metadata' => 1, 'preserve_copyright' => 0, 'preserve_transparency' => 1 ); }
	public function get() { return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() ); }
	public function avif_available() { $report = $this->capabilities->detect(); return ! empty( $report['avif_encode'] ); }
	public function sanitize( $input ) { $input = is_array( $input ) ? $input : array(); $defaults = self::defaults(); return array( 'webp_enabled' => empty( $input['webp_enabled'] ) ? 0 : 1, 'avif_enabled' => ! empty( $input['avif_enabled'] ) && $this->avif_available() ? 1 : 0, 'webp_quality' => max( 1, min( 100, isset( $input['webp_quality'] ) ? absint( $input['webp_quality'] ) : $defaults['webp_quality'] ) ), 'avif_quality' => max( 1, min( 100, isset( $input['avif_quality'] ) ? absint( $input['avif_quality'] ) : $defaults['avif_quality'] ) ), 'webp_lossless' => empty( $input['webp_lossless'] ) ? 0 : 1, 'remove_metadata' => empty( $input['remove_metadata'] ) ? 0 : 1, 'preserve_copyright' => empty( $input['preserve_copyright'] ) ? 0 : 1, 'preserve_transparency' => empty( $input['preserve_transparency'] ) ? 0 : 1 ); }
}
