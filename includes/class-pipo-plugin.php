<?php
/**
 * Core plugin functionality.
 *
 * @package PersianImagePerformanceOptimizer
 */

defined( 'ABSPATH' ) || exit;

final class PIPO_Plugin {
	const OPTION = 'pipo_options';

	/** @var self|null */
	private static $instance = null;

	/** @return self */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_filter( 'wp_editor_set_quality', array( $this, 'set_editor_quality' ), 10, 2 );
		add_filter( 'image_editor_output_format', array( $this, 'use_webp_for_generated_sizes' ) );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'generate_webp_files' ), 20, 2 );
		add_action( 'delete_attachment', array( $this, 'delete_generated_files' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Lets WordPress reference WebP files for generated image sizes, so browsers
	 * actually receive the optimized files instead of unused sidecar images.
	 *
	 * @param array<string,string> $formats Output format map.
	 * @return array<string,string>
	 */
	public function use_webp_for_generated_sizes( $formats ) {
		$options = $this->options();
		if ( ! empty( $options['enabled'] ) && ! empty( $options['generate_webp'] ) && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			$formats['image/jpeg'] = 'image/webp';
			$formats['image/png']  = 'image/webp';
		}

		return $formats;
	}

	/** @return array<string,mixed> */
	public static function defaults() {
		return array(
			'enabled'       => 1,
			'quality'       => 82,
			'generate_webp' => 1,
		);
	}

	/** @return array<string,mixed> */
	private function options() {
		return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
	}

	/**
	 * Applies the configured quality to lossy formats only.
	 *
	 * @param int    $quality   Current quality.
	 * @param string $mime_type Image MIME type.
	 * @return int
	 */
	public function set_editor_quality( $quality, $mime_type ) {
		$options = $this->options();
		if ( empty( $options['enabled'] ) || ! in_array( $mime_type, array( 'image/jpeg', 'image/webp', 'image/avif' ), true ) ) {
			return $quality;
		}

		return (int) $options['quality'];
	}

	/**
	 * Creates WebP companions for the original and all generated sizes.
	 * Original files and attachment metadata are never replaced.
	 *
	 * @param array<string,mixed> $metadata      Attachment metadata.
	 * @param int                 $attachment_id Attachment ID.
	 * @return array<string,mixed>
	 */
	public function generate_webp_files( $metadata, $attachment_id ) {
		$options = $this->options();
		if ( empty( $options['enabled'] ) || empty( $options['generate_webp'] ) || ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return $metadata;
		}

		$original = get_attached_file( $attachment_id );
		if ( ! is_string( $original ) || ! is_file( $original ) ) {
			return $metadata;
		}

		$files = array( $original );
		$dir   = dirname( $original );
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . DIRECTORY_SEPARATOR . wp_basename( $size['file'] );
				}
			}
		}

		$generated = array();
		foreach ( array_unique( $files ) as $file ) {
			$webp = $this->create_webp( $file, (int) $options['quality'] );
			if ( $webp ) {
				$generated[] = $webp;
			}
		}

		if ( $generated ) {
			update_post_meta( $attachment_id, '_pipo_webp_files', $generated );
		}

		return $metadata;
	}

	/** @return string|false */
	private function create_webp( $source, $quality ) {
		if ( ! is_file( $source ) || 'webp' === strtolower( pathinfo( $source, PATHINFO_EXTENSION ) ) ) {
			return false;
		}

		$destination = preg_replace( '/\.[^.]+$/', '.webp', $source );
		if ( ! $destination || ( is_file( $destination ) && filemtime( $destination ) >= filemtime( $source ) ) ) {
			return is_file( $destination ) ? $destination : false;
		}

		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			return false;
		}

		$editor->set_quality( $quality );
		$result = $editor->save( $destination, 'image/webp' );

		return is_wp_error( $result ) || empty( $result['path'] ) ? false : $result['path'];
	}

	/** @param int $attachment_id Attachment ID. */
	public function delete_generated_files( $attachment_id ) {
		$files = get_post_meta( $attachment_id, '_pipo_webp_files', true );
		if ( ! is_array( $files ) ) {
			return;
		}

		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? wp_normalize_path( $uploads['basedir'] ) : '';
		foreach ( $files as $file ) {
			$normalized = wp_normalize_path( $file );
			if ( $base && 0 === strpos( $normalized, trailingslashit( $base ) ) && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	public function add_settings_page() {
		add_options_page( 'بهینه‌سازی تصاویر', 'بهینه‌سازی تصاویر', 'manage_options', 'pipo-settings', array( $this, 'render_settings_page' ) );
	}

	public function register_settings() {
		register_setting( 'pipo_settings', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize_options' ), 'default' => self::defaults() ) );
		add_settings_section( 'pipo_main', 'تنظیمات بهینه‌سازی', '__return_false', 'pipo-settings' );
		add_settings_field( 'pipo_enabled', 'فعال‌سازی', array( $this, 'render_checkbox' ), 'pipo-settings', 'pipo_main', array( 'key' => 'enabled', 'label' => 'بهینه‌سازی تصاویر جدید فعال باشد' ) );
		add_settings_field( 'pipo_quality', 'کیفیت تصویر', array( $this, 'render_quality' ), 'pipo-settings', 'pipo_main' );
		add_settings_field( 'pipo_webp', 'نسخه WebP', array( $this, 'render_checkbox' ), 'pipo-settings', 'pipo_main', array( 'key' => 'generate_webp', 'label' => 'برای تصاویر جدید نسخه WebP ساخته شود' ) );
	}

	/** @param mixed $input Raw options. @return array<string,int> */
	public function sanitize_options( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$quality = isset( $input['quality'] ) ? absint( $input['quality'] ) : self::defaults()['quality'];

		return array(
			'enabled'       => empty( $input['enabled'] ) ? 0 : 1,
			'quality'       => max( 40, min( 100, $quality ) ),
			'generate_webp' => empty( $input['generate_webp'] ) ? 0 : 1,
		);
	}

	/** @param array<string,string> $args Field arguments. */
	public function render_checkbox( $args ) {
		$options = $this->options();
		$key     = $args['key'];
		printf( '<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>', esc_attr( self::OPTION ), esc_attr( $key ), checked( ! empty( $options[ $key ] ), true, false ), esc_html( $args['label'] ) );
	}

	public function render_quality() {
		$options = $this->options();
		printf( '<input type="number" min="40" max="100" name="%1$s[quality]" value="%2$d" class="small-text"> <p class="description">مقدار پیشنهادی: ۸۲ (بین ۴۰ تا ۱۰۰)</p>', esc_attr( self::OPTION ), (int) $options['quality'] );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap" dir="rtl">
			<h1>بهینه‌ساز عملکرد تصاویر فارسی</h1>
			<p>تنظیمات زیر روی تصاویری که از این پس بارگذاری می‌شوند اعمال می‌شود. فایل اصلی حذف یا جایگزین نخواهد شد.</p>
			<form action="options.php" method="post">
				<?php settings_fields( 'pipo_settings' ); do_settings_sections( 'pipo-settings' ); submit_button( 'ذخیره تنظیمات' ); ?>
			</form>
		</div>
		<?php
	}
}
