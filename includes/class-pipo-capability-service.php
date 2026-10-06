<?php
/**
 * Detects server capabilities without changing images or server configuration.
 *
 * @package PersianImagePerformanceOptimizer
 */

defined( 'ABSPATH' ) || exit;

final class PIPO_Capability_Service {
	/** @var array<string,mixed> */
	private $overrides;

	/**
	 * Overrides exist solely to make environment-dependent detection testable.
	 *
	 * @param array<string,mixed> $overrides Detection value overrides.
	 */
	public function __construct( $overrides = array() ) {
		$this->overrides = is_array( $overrides ) ? $overrides : array();
	}

	/** @return array<string,mixed> */
	public function detect() {
		global $wp_version;

		$upload       = wp_get_upload_dir();
		$upload_path  = empty( $upload['basedir'] ) ? '' : wp_normalize_path( $upload['basedir'] );
		$imagick      = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
		$gd           = extension_loaded( 'gd' ) && function_exists( 'gd_info' );
		$imagick_webp = $imagick && $this->imagick_supports( 'WEBP' );
		$imagick_avif = $imagick && $this->imagick_supports( 'AVIF' );
		$server       = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		$temp         = get_temp_dir();

		$values = array(
			'php_version'            => PHP_VERSION,
			'wordpress_version'      => isset( $wp_version ) ? (string) $wp_version : '',
			'imagick_available'      => $imagick,
			'imagick_version'        => $imagick ? $this->imagick_version() : '',
			'gd_available'           => $gd,
			'webp_encode'            => ( $gd && function_exists( 'imagewebp' ) ) || $imagick_webp,
			'webp_decode'            => ( $gd && function_exists( 'imagecreatefromwebp' ) ) || $imagick_webp,
			'avif_encode'            => ( $gd && function_exists( 'imageavif' ) ) || $imagick_avif,
			'avif_decode'            => ( $gd && function_exists( 'imagecreatefromavif' ) ) || $imagick_avif,
			'jpeg_support'           => ( $gd && function_exists( 'imagejpeg' ) && function_exists( 'imagecreatefromjpeg' ) ) || ( $imagick && $this->imagick_supports( 'JPEG' ) ),
			'png_support'            => ( $gd && function_exists( 'imagepng' ) && function_exists( 'imagecreatefrompng' ) ) || ( $imagick && $this->imagick_supports( 'PNG' ) ),
			'upload_writable'        => $upload_path && is_dir( $upload_path ) && wp_is_writable( $upload_path ),
			'upload_path'            => $upload_path,
			'wordpress_memory_limit' => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '',
			'php_memory_limit'       => (string) ini_get( 'memory_limit' ),
			'max_execution_time'     => (string) ini_get( 'max_execution_time' ),
			'max_input_time'         => (string) ini_get( 'max_input_time' ),
			'wp_cron_enabled'        => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
			'disable_wp_cron'        => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'server_software'        => $server,
			'server_type'            => $this->server_type( $server ),
			'disk_free_space'        => $this->disk_free_space( $upload_path ),
			'temp_directory'         => wp_normalize_path( $temp ),
			'temp_writable'          => $temp && is_dir( $temp ) && wp_is_writable( $temp ),
			'upload_max_filesize'    => (string) ini_get( 'upload_max_filesize' ),
			'post_max_size'          => (string) ini_get( 'post_max_size' ),
		);

		$values                     = array_replace( $values, $this->overrides );
		$values['preferred_engine'] = $this->preferred_engine( $values );
		$values['items']            = $this->build_items( $values );

		return $values;
	}

	/** @return bool */
	private function imagick_supports( $format ) {
		try {
			return ! empty( Imagick::queryFormats( $format ) );
		} catch ( Exception $exception ) {
			return false;
		}
	}

	/** @return string */
	private function imagick_version() {
		try {
			$version = Imagick::getVersion();
			return is_array( $version ) && isset( $version['versionString'] ) ? sanitize_text_field( $version['versionString'] ) : '';
		} catch ( Exception $exception ) {
			return '';
		}
	}

	/** @return string */
	private function server_type( $software ) {
		$software = strtolower( $software );
		if ( false !== strpos( $software, 'litespeed' ) ) {
			return 'litespeed';
		}
		if ( false !== strpos( $software, 'nginx' ) ) {
			return 'nginx';
		}
		if ( false !== strpos( $software, 'apache' ) ) {
			return 'apache';
		}
		return 'unknown';
	}

	/** @return int|null */
	private function disk_free_space( $path ) {
		if ( ! $path || ! is_dir( $path ) || ! function_exists( 'disk_free_space' ) ) {
			return null;
		}
		$space = @disk_free_space( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- OS-level probe may warn on restricted hosts.
		return false === $space ? null : (int) $space;
	}

	/** @param array<string,mixed> $values Values. @return string */
	private function preferred_engine( $values ) {
		if ( ! empty( $values['imagick_available'] ) ) {
			return 'imagick';
		}
		if ( function_exists( 'wp_get_image_editor' ) && ( ! empty( $values['jpeg_support'] ) || ! empty( $values['png_support'] ) ) ) {
			return 'wordpress';
		}
		return ! empty( $values['gd_available'] ) ? 'gd' : 'none';
	}

	/**
	 * Converts raw facts into UI-ready status, description, severity and remediation.
	 *
	 * @param array<string,mixed> $v Raw values.
	 * @return array<string,array<string,string>>
	 */
	private function build_items( $v ) {
		$yes_no = function ( $value ) { return $value ? 'فعال' : 'غیرفعال'; };
		$support = function ( $value ) { return $value ? 'پشتیبانی می‌شود' : 'پشتیبانی نمی‌شود'; };
		$bool_item = function ( $label, $value, $description, $remediation ) use ( $yes_no ) {
			return array( 'label' => $label, 'status' => $yes_no( $value ), 'description' => $description, 'severity' => $value ? 'success' : 'warning', 'remediation' => $value ? 'اقدامی لازم نیست.' : $remediation );
		};
		$items = array(
			'php_version' => array( 'label' => 'نسخه PHP', 'status' => (string) $v['php_version'], 'description' => 'نسخه PHP در حال اجرای وردپرس.', 'severity' => version_compare( $v['php_version'], '7.4', '>=' ) ? 'success' : 'error', 'remediation' => 'PHP را به نسخه 7.4 یا جدیدتر ارتقا دهید.' ),
			'wordpress_version' => array( 'label' => 'نسخه وردپرس', 'status' => (string) $v['wordpress_version'], 'description' => 'نسخه هسته وردپرس.', 'severity' => 'info', 'remediation' => 'وردپرس را همواره به‌روز نگه دارید.' ),
			'imagick' => $bool_item( 'Imagick', $v['imagick_available'], $v['imagick_available'] ? 'نسخه: ' . ( $v['imagick_version'] ?: 'نامشخص' ) : 'افزونه Imagick در PHP بارگذاری نشده است.', 'افزونه PHP Imagick را روی سرور نصب و فعال کنید.' ),
			'gd' => $bool_item( 'GD', $v['gd_available'], 'کتابخانه پردازش تصویر GD.', 'افزونه GD را در PHP فعال کنید.' ),
			'webp_encode' => array( 'label' => 'رمزگذاری WebP', 'status' => $support( $v['webp_encode'] ), 'description' => 'امکان ساخت فایل WebP.', 'severity' => $v['webp_encode'] ? 'success' : 'warning', 'remediation' => $v['webp_encode'] ? 'اقدامی لازم نیست.' : 'GD یا Imagick را با پشتیبانی WebP نصب کنید.' ),
			'webp_decode' => array( 'label' => 'خواندن WebP', 'status' => $support( $v['webp_decode'] ), 'description' => 'امکان بازکردن فایل WebP.', 'severity' => $v['webp_decode'] ? 'success' : 'warning', 'remediation' => $v['webp_decode'] ? 'اقدامی لازم نیست.' : 'کتابخانه تصویر را با decoder مربوط به WebP فعال کنید.' ),
			'avif_encode' => array( 'label' => 'رمزگذاری AVIF', 'status' => $v['avif_encode'] ? 'پشتیبانی می‌شود' : 'غیرفعال', 'description' => 'امکان ساخت فایل AVIF.', 'severity' => $v['avif_encode'] ? 'success' : 'warning', 'remediation' => $v['avif_encode'] ? 'اقدامی لازم نیست.' : 'AVIF تا زمان نصب codec سازگار غیرفعال می‌ماند.' ),
			'avif_decode' => array( 'label' => 'خواندن AVIF', 'status' => $support( $v['avif_decode'] ), 'description' => 'امکان بازکردن فایل AVIF.', 'severity' => $v['avif_decode'] ? 'success' : 'warning', 'remediation' => $v['avif_decode'] ? 'اقدامی لازم نیست.' : 'کتابخانه تصویر را با decoder مربوط به AVIF فعال کنید.' ),
			'jpeg' => $bool_item( 'JPEG', $v['jpeg_support'], 'پشتیبانی خواندن و نوشتن JPEG.', 'GD یا Imagick را با پشتیبانی JPEG نصب کنید.' ),
			'png' => $bool_item( 'PNG', $v['png_support'], 'پشتیبانی خواندن و نوشتن PNG.', 'GD یا Imagick را با پشتیبانی PNG نصب کنید.' ),
			'uploads' => array( 'label' => 'دسترسی نوشتن Uploads', 'status' => $v['upload_writable'] ? 'بله' : 'خیر', 'description' => $v['upload_path'] ?: 'مسیر uploads در دسترس نیست.', 'severity' => $v['upload_writable'] ? 'success' : 'error', 'remediation' => $v['upload_writable'] ? 'اقدامی لازم نیست.' : 'مالکیت و سطح دسترسی پوشه uploads را بررسی کنید.' ),
			'cron' => array( 'label' => 'WP-Cron', 'status' => $v['wp_cron_enabled'] ? 'فعال' : 'غیرفعال', 'description' => $v['disable_wp_cron'] ? 'ثابت DISABLE_WP_CRON فعال است.' : 'زمان‌بندی داخلی وردپرس فعال است.', 'severity' => $v['wp_cron_enabled'] ? 'success' : 'warning', 'remediation' => $v['wp_cron_enabled'] ? 'اقدامی لازم نیست.' : 'یک Cron واقعی سرور تنظیم کنید یا DISABLE_WP_CRON را غیرفعال کنید.' ),
		);

		$informational = array(
			'memory' => array( 'محدودیت حافظه', 'WordPress: ' . $v['wordpress_memory_limit'] . ' / PHP: ' . $v['php_memory_limit'], 'سقف حافظه وردپرس و PHP.' ),
			'timeouts' => array( 'محدودیت زمان', 'Execution: ' . $v['max_execution_time'] . 's / Input: ' . $v['max_input_time'] . 's', 'مقادیر صفر معمولاً به معنی بدون محدودیت است.' ),
			'server' => array( 'وب‌سرور', ucfirst( $v['server_type'] ), $v['server_software'] ?: 'هدر نرم‌افزار سرور ارائه نشده است.' ),
			'disk' => array( 'فضای آزاد دیسک', null === $v['disk_free_space'] ? 'در دسترس نیست' : size_format( $v['disk_free_space'], 1 ), 'فضای آزاد فایل‌سیستم uploads، در صورت اجازه میزبان.' ),
			'temp' => array( 'پوشه موقت PHP', $v['temp_writable'] ? 'قابل نوشتن' : 'غیرقابل نوشتن', $v['temp_directory'] ?: 'مسیر نامشخص' ),
			'uploads_limits' => array( 'محدودیت بارگذاری', 'Upload: ' . $v['upload_max_filesize'] . ' / Post: ' . $v['post_max_size'], 'محدودیت‌های PHP برای درخواست بارگذاری.' ),
			'engine' => array( 'موتور ترجیحی', ucfirst( $v['preferred_engine'] ), 'اولویت: Imagick، ویرایشگر وردپرس، سپس GD.' ),
			'disable_wp_cron' => array( 'ثابت DISABLE_WP_CRON', $v['disable_wp_cron'] ? 'فعال' : 'غیرفعال', 'وضعیت ثابت کنترل‌کننده زمان‌بندی داخلی وردپرس.' ),
		);
		foreach ( $informational as $key => $data ) {
			$items[ $key ] = array( 'label' => $data[0], 'status' => $data[1], 'description' => $data[2], 'severity' => ( 'temp' === $key && ! $v['temp_writable'] ) ? 'warning' : 'info', 'remediation' => ( 'temp' === $key && ! $v['temp_writable'] ) ? 'مسیر موقت PHP و مجوز نوشتن آن را بررسی کنید.' : 'صرفاً جهت اطلاع.' );
		}
		return $items;
	}
}
