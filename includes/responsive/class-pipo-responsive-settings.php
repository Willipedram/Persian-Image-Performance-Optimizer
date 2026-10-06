<?php
/** Responsive image generation settings. @package PersianImagePerformanceOptimizer */

defined( 'ABSPATH' ) || exit;

final class PIPO_Responsive_Settings {
	const OPTION = 'pipo_responsive_settings';
	const PRESETS = array( 320, 480, 640, 768, 960, 1024, 1280, 1536, 1920, 2560 );
	public static function defaults() { return array( 'minimum_width' => 320, 'maximum_width' => 1920, 'candidate_widths' => self::PRESETS, 'maximum_variants' => 6, 'jpeg_enabled' => 1, 'webp_enabled' => 1, 'avif_enabled' => 0 ); }
	public function get() { return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() ); }
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array(); $defaults = self::defaults();
		$minimum = max( 64, min( 2560, absint( $input['minimum_width'] ?? $defaults['minimum_width'] ) ) );
		$maximum = max( $minimum, min( 8192, absint( $input['maximum_width'] ?? $defaults['maximum_width'] ) ) );
		$widths = isset( $input['candidate_widths'] ) ? ( is_array( $input['candidate_widths'] ) ? $input['candidate_widths'] : preg_split( '/[\s,]+/', $input['candidate_widths'] ) ) : self::PRESETS;
		$widths = array_values( array_unique( array_filter( array_map( 'absint', $widths ), function ( $width ) use ( $minimum, $maximum ) { return $width >= $minimum && $width <= $maximum; } ) ) ); sort( $widths, SORT_NUMERIC );
		return array( 'minimum_width' => $minimum, 'maximum_width' => $maximum, 'candidate_widths' => $widths ?: array( $minimum ), 'maximum_variants' => max( 1, min( 20, absint( $input['maximum_variants'] ?? $defaults['maximum_variants'] ) ) ), 'jpeg_enabled' => empty( $input['jpeg_enabled'] ) ? 0 : 1, 'webp_enabled' => empty( $input['webp_enabled'] ) ? 0 : 1, 'avif_enabled' => empty( $input['avif_enabled'] ) ? 0 : 1 );
	}
}
