<?php
defined( 'ABSPATH' ) || exit;

final class PIPO_WordPress_Engine implements ImageEngineInterface {
	private $editor; private $mime;
	public function name() { return 'wordpress'; }
	public function is_available() { return function_exists( 'wp_get_image_editor' ); }
	public function supports_mime( $mime ) { return function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( array( 'mime_type' => $mime ) ); }
	public function load( $path ) { $this->editor = wp_get_image_editor( $path ); if ( is_wp_error( $this->editor ) ) { throw new RuntimeException( $this->editor->get_error_code() ); } $info = getimagesize( $path ); $this->mime = $info['mime']; return $this; }
	public function dimensions() { $size = $this->editor->get_size(); return array( 'width' => (int) $size['width'], 'height' => (int) $size['height'] ); }
	public function resize( $max_width, $max_height ) { $d = $this->dimensions(); if ( $d['width'] <= $max_width && $d['height'] <= $max_height ) { return $this; } $result = $this->editor->resize( $max_width, $max_height, false ); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_code() ); } return $this; }
	public function crop( $x, $y, $width, $height, $target_width = null, $target_height = null ) { $result = $this->editor->crop( $x, $y, $width, $height, $target_width, $target_height, false ); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_code() ); } return $this; }
	public function compress( $quality ) { $result = $this->editor->set_quality( max( 1, min( 100, (int) $quality ) ) ); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_code() ); } return $this; }
	public function configure( $options ) { if ( ! empty( $options['lossless'] ) ) { throw new RuntimeException( 'wordpress_lossless_not_guaranteed' ); } if ( ! empty( $options['preserve_icc'] ) ) { throw new RuntimeException( 'wordpress_icc_preservation_unavailable' ); } return $this; }
	public function change_format( $mime ) { if ( ! $this->supports_mime( $mime ) ) { throw new RuntimeException( 'wordpress_format_unsupported' ); } $this->mime = $mime; return $this; }
	public function strip_metadata( $options = array() ) { if ( ! empty( $options['preserve_copyright'] ) ) { throw new RuntimeException( 'wordpress_copyright_preservation_unavailable' ); } return $this; /* WordPress editors omit nonessential metadata when saving. */ }
	public function normalize_orientation() { if ( method_exists( $this->editor, 'maybe_exif_rotate' ) ) { $result = $this->editor->maybe_exif_rotate(); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_code() ); } } return $this; }
	public function save( $path ) { $result = $this->editor->save( $path, $this->mime ); if ( is_wp_error( $result ) || empty( $result['path'] ) ) { throw new RuntimeException( is_wp_error( $result ) ? $result->get_error_code() : 'wordpress_save_failed' ); } return $result['path']; }
}
