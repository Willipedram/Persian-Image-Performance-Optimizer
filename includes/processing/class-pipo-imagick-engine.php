<?php
defined( 'ABSPATH' ) || exit;

final class PIPO_Imagick_Engine implements ImageEngineInterface {
	private $image; private $mime;
	public function name() { return 'imagick'; }
	public function is_available() { return extension_loaded( 'imagick' ) && class_exists( 'Imagick' ); }
	public function supports_mime( $mime ) { $format = strtoupper( substr( $mime, 6 ) ); return $this->is_available() && ! empty( Imagick::queryFormats( $format ) ); }
	public function load( $path ) { try { $this->image = new Imagick( $path ); $this->image->setIteratorIndex( 0 ); $this->mime = 'image/' . strtolower( $this->image->getImageFormat() ); return $this; } catch ( Exception $e ) { throw new RuntimeException( 'imagick_load_failed', 0, $e ); } }
	public function dimensions() { return array( 'width' => $this->image->getImageWidth(), 'height' => $this->image->getImageHeight() ); }
	public function resize( $max_width, $max_height ) { $d = $this->dimensions(); $scale = min( 1, $max_width / $d['width'], $max_height / $d['height'] ); if ( $scale < 1 ) { $this->image->resizeImage( max( 1, round( $d['width'] * $scale ) ), max( 1, round( $d['height'] * $scale ) ), Imagick::FILTER_LANCZOS, 1 ); } return $this; }
	public function crop( $x, $y, $width, $height, $target_width = null, $target_height = null ) { $this->image->cropImage( $width, $height, $x, $y ); $this->image->setImagePage( 0, 0, 0, 0 ); if ( $target_width && $target_height ) { $this->image->resizeImage( $target_width, $target_height, Imagick::FILTER_LANCZOS, 1 ); } return $this; }
	public function compress( $quality ) { $this->image->setImageCompressionQuality( max( 1, min( 100, (int) $quality ) ) ); return $this; }
	public function configure( $options ) { if ( ! empty( $options['lossless'] ) && 'image/webp' === $this->mime ) { $this->image->setOption( 'webp:lossless', 'true' ); } return $this; }
	public function change_format( $mime ) { if ( ! $this->supports_mime( $mime ) ) { throw new RuntimeException( 'imagick_format_unsupported' ); } $this->mime = $mime; $this->image->setImageFormat( substr( $mime, 6 ) ); return $this; }
	public function strip_metadata( $options = array() ) { if ( ! empty( $options['preserve_copyright'] ) ) { return $this; } $icc = $this->image->getImageProfile( 'icc' ); $this->image->stripImage(); if ( $icc ) { $this->image->profileImage( 'icc', $icc ); } return $this; }
	public function normalize_orientation() { if ( method_exists( $this->image, 'autoOrient' ) ) { $this->image->autoOrient(); } elseif ( method_exists( $this->image, 'autoOrientImage' ) ) { $this->image->autoOrientImage(); } $this->image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT ); return $this; }
	public function save( $path ) { $this->image->setImageAlphaChannel( Imagick::ALPHACHANNEL_ACTIVATE ); if ( ! $this->image->writeImage( $path ) ) { throw new RuntimeException( 'imagick_save_failed' ); } return $path; }
}
