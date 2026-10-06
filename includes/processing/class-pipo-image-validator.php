<?php
defined( 'ABSPATH' ) || exit;

final class PIPO_Image_Validator {
	const MIMES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );

	/** @return array<string,mixed> */
	public function validate( $path, $expected = array() ) {
		if ( ! is_string( $path ) || ! is_file( $path ) ) { throw new RuntimeException( 'image_missing' ); }
		if ( ! is_readable( $path ) || filesize( $path ) < 1 ) { throw new RuntimeException( 'image_unreadable_or_empty' ); }
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input is reported as a validation error.
		if ( ! $info || empty( $info[0] ) || empty( $info[1] ) || empty( $info['mime'] ) ) { throw new RuntimeException( 'invalid_image' ); }
		if ( ! in_array( $info['mime'], self::MIMES, true ) ) { throw new RuntimeException( 'unsupported_mime' ); }
		if ( ! empty( $expected['mime'] ) && $expected['mime'] !== $info['mime'] ) { throw new RuntimeException( 'unexpected_mime' ); }
		if ( ! empty( $expected['max_width'] ) && $info[0] > $expected['max_width'] ) { throw new RuntimeException( 'unexpected_width' ); }
		if ( ! empty( $expected['max_height'] ) && $info[1] > $expected['max_height'] ) { throw new RuntimeException( 'unexpected_height' ); }
		if ( ! empty( $expected['alpha_required'] ) && ! $this->has_alpha_channel( $path, $info['mime'] ) ) { throw new RuntimeException( 'alpha_not_preserved' ); }
		if ( ! empty( $expected['orientation_normalized'] ) && 'image/jpeg' === $info['mime'] && function_exists( 'exif_read_data' ) ) { $exif = @exif_read_data( $path ); if ( is_array( $exif ) && isset( $exif['Orientation'] ) && (int) $exif['Orientation'] > 1 ) { throw new RuntimeException( 'orientation_not_normalized' ); } }
		return array( 'path' => $path, 'mime' => $info['mime'], 'width' => (int) $info[0], 'height' => (int) $info[1], 'size' => (int) filesize( $path ) );
	}

	public function has_alpha_channel( $path, $mime = null ) {
		$head = file_get_contents( $path, false, null, 0, 64 );
		if ( false === $head ) { return false; }
		if ( 'image/png' === $mime && strlen( $head ) > 25 ) { return in_array( ord( $head[25] ), array( 4, 6 ), true ); }
		if ( 'image/webp' === $mime && strlen( $head ) > 21 && 'VP8X' === substr( $head, 12, 4 ) ) { return (bool) ( ord( $head[20] ) & 16 ); }
		if ( 'image/gif' === $mime ) { return false !== strpos( $head, "\x21\xF9\x04" ); }
		return false;
	}
}
