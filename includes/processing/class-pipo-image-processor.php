<?php
defined( 'ABSPATH' ) || exit;

final class PIPO_Image_Processor implements ImageProcessorInterface {
	private $engines; private $backup; private $validator;
	public function __construct( $engines = null, $backup = null, $validator = null ) {
		$this->engines = $engines ?: array( new PIPO_Imagick_Engine(), new PIPO_WordPress_Engine(), new PIPO_GD_Engine() );
		$this->validator = $validator ?: new PIPO_Image_Validator();
		$this->backup = $backup ?: new PIPO_Backup_Manager( null, $this->validator );
	}
	public function validate( $path, $expected = array() ) { return $this->validator->validate( $path, $expected ); }
	private function engine( $input_mime, $output_mime ) { foreach ( $this->engines as $engine ) { if ( $engine->is_available() && $engine->supports_mime( $input_mime ) && $engine->supports_mime( $output_mime ) ) { return $engine; } } throw new RuntimeException( 'no_image_engine' ); }
	/**
	 * Produces a derivative by default. Setting overwrite=true is explicit and
	 * always creates a validated private backup before touching the original.
	 *
	 * @return array<string,mixed>
	 */
	public function process( $source_path, $options = array() ) {
		$source = $this->validator->validate( $source_path );
		$defaults = array( 'width' => $source['width'], 'height' => $source['height'], 'quality' => 82, 'mime' => $source['mime'], 'crop' => null, 'strip_metadata' => true, 'preserve_copyright' => false, 'preserve_transparency' => true, 'engine_options' => array(), 'normalize_orientation' => true, 'overwrite' => false, 'destination' => null, 'metadata_callback' => null );
		$options = array_merge( $defaults, is_array( $options ) ? $options : array() );
		$options['width'] = max( 1, min( $source['width'], (int) $options['width'] ) );
		$options['height'] = max( 1, min( $source['height'], (int) $options['height'] ) );
		$extension = $this->extension_for_mime( $options['mime'] );
		$destination = $options['overwrite'] ? $source_path : ( $options['destination'] ?: preg_replace( '/\.[^.]+$/', '-pipo-' . wp_generate_password( 6, false, false ) . '.' . $extension, $source_path ) );
		if ( ! $destination || ( ! $options['overwrite'] && wp_normalize_path( $destination ) === wp_normalize_path( $source_path ) ) ) { throw new RuntimeException( 'unsafe_destination' ); }
		$directory = dirname( $destination ); if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) { throw new RuntimeException( 'destination_not_writable' ); }
		$backup = $options['overwrite'] ? $this->backup->create_backup( $source_path ) : null;
		$temp = $directory . '/.' . basename( $destination ) . '.pipo-tmp-' . wp_generate_password( 8, false, false );
		$alpha_required = ! empty( $options['preserve_transparency'] ) && $this->validator->has_alpha_channel( $source_path, $source['mime'] ) && in_array( $options['mime'], array( 'image/png', 'image/webp', 'image/avif' ), true );
		try {
			$engine = $this->engine( $source['mime'], $options['mime'] ); $engine->load( $source_path );
			if ( $options['normalize_orientation'] ) { $engine->normalize_orientation(); }
			if ( is_array( $options['crop'] ) ) { $crop = $options['crop']; $crop_width = max( 1, min( $source['width'], (int) $crop['width'] ) ); $crop_height = max( 1, min( $source['height'], (int) $crop['height'] ) ); $engine->crop( max( 0, (int) $crop['x'] ), max( 0, (int) $crop['y'] ), $crop_width, $crop_height, min( $options['width'], $crop_width ), min( $options['height'], $crop_height ) ); }
			else { $engine->resize( $options['width'], $options['height'] ); }
			$engine->compress( $options['quality'] )->change_format( $options['mime'] )->configure( $options['engine_options'] );
			if ( $options['strip_metadata'] ) { $engine->strip_metadata( array( 'preserve_copyright' => $options['preserve_copyright'] ) ); }
			$engine->save( $temp );
			$output = $this->validator->validate( $temp, array( 'mime' => $options['mime'], 'max_width' => $source['width'], 'max_height' => $source['height'], 'alpha_required' => $alpha_required, 'orientation_normalized' => $options['normalize_orientation'] ) );
			if ( ! rename( $temp, $destination ) ) { throw new RuntimeException( 'atomic_commit_failed' ); }
			if ( is_callable( $options['metadata_callback'] ) ) { try { if ( false === call_user_func( $options['metadata_callback'], $destination, $output ) ) { throw new RuntimeException( 'metadata_update_failed' ); } } catch ( Throwable $metadata_error ) { if ( $backup ) { $this->backup->restore( $backup, $source_path ); } elseif ( is_file( $destination ) ) { unlink( $destination ); } throw $metadata_error; } }
			$output['path'] = $destination; $output['engine'] = $engine->name(); $output['backup'] = $backup; return $output;
		} catch ( Throwable $error ) { if ( is_file( $temp ) ) { @unlink( $temp ); } throw $error; }
	}
	private function extension_for_mime( $mime ) { $map = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif' ); if ( ! isset( $map[ $mime ] ) ) { throw new RuntimeException( 'unsupported_output_mime' ); } return $map[ $mime ]; }
}
