<?php
/** Admin controller. @package PersianImagePerformanceOptimizer */

defined( 'ABSPATH' ) || exit;

final class PIPO_Plugin {
	/** @var self|null */
	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_post_pipo_scan_action', array( $this, 'handle_scan_action' ) );
		add_action( 'admin_init', array( $this, 'register_conversion_settings' ) );
		add_action( 'admin_init', array( $this, 'register_responsive_settings' ) );
	}

	public function add_admin_page() {
		add_management_page( 'سلامت سیستم', 'سلامت سیستم تصاویر', 'manage_options', 'pipo-system-health', array( $this, 'render_page' ) );
		add_management_page( 'اسکن تصاویر', 'اسکن تصاویر', 'manage_options', 'pipo-image-scanner', array( $this, 'render_scanner_page' ) );
		add_options_page( 'تنظیمات تبدیل تصاویر', 'تبدیل تصاویر', 'manage_options', 'pipo-conversion-settings', array( $this, 'render_conversion_settings' ) );
		add_options_page( 'تنظیمات تصاویر واکنش‌گرا', 'تصاویر واکنش‌گرا', 'manage_options', 'pipo-responsive-settings', array( $this, 'render_responsive_settings' ) );
	}

	public function register_responsive_settings() {
		$settings = new PIPO_Responsive_Settings();
		register_setting( 'pipo_responsive', PIPO_Responsive_Settings::OPTION, array( 'sanitize_callback' => array( $settings, 'sanitize' ), 'default' => PIPO_Responsive_Settings::defaults() ) );
	}

	public function render_responsive_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$values = ( new PIPO_Responsive_Settings() )->get();
		?>
		<div class="wrap" dir="rtl"><h1>تنظیمات تصاویر واکنش‌گرا</h1><p>فقط اندازه‌های مفید و کوچک‌تر یا مساوی تصویر اصلی ساخته می‌شوند.</p><form method="post" action="options.php"><?php settings_fields( 'pipo_responsive' ); ?><table class="form-table" role="presentation">
		<tr><th>حداقل عرض</th><td><input type="number" min="64" name="<?php echo esc_attr( PIPO_Responsive_Settings::OPTION ); ?>[minimum_width]" value="<?php echo esc_attr( $values['minimum_width'] ); ?>"></td></tr>
		<tr><th>حداکثر عرض</th><td><input type="number" min="64" name="<?php echo esc_attr( PIPO_Responsive_Settings::OPTION ); ?>[maximum_width]" value="<?php echo esc_attr( $values['maximum_width'] ); ?>"></td></tr>
		<tr><th>عرض‌های Candidate</th><td><input class="regular-text" name="<?php echo esc_attr( PIPO_Responsive_Settings::OPTION ); ?>[candidate_widths]" value="<?php echo esc_attr( implode( ',', $values['candidate_widths'] ) ); ?>"><p class="description">با کاما جدا کنید؛ مثال: 320,480,640,768,960,1024,1280,1536,1920,2560</p></td></tr>
		<tr><th>حداکثر Variant برای هر تصویر</th><td><input type="number" min="1" max="20" name="<?php echo esc_attr( PIPO_Responsive_Settings::OPTION ); ?>[maximum_variants]" value="<?php echo esc_attr( $values['maximum_variants'] ); ?>"></td></tr>
		<?php foreach ( array( 'jpeg_enabled' => 'JPEG', 'webp_enabled' => 'WebP', 'avif_enabled' => 'AVIF' ) as $key => $label ) : ?><tr><th><?php echo esc_html( $label ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( PIPO_Responsive_Settings::OPTION . '[' . $key . ']' ); ?>" value="1" <?php checked( ! empty( $values[ $key ] ) ); ?>> تولید Variant</label></td></tr><?php endforeach; ?>
		</table><?php submit_button( 'ذخیره تنظیمات' ); ?></form></div>
		<?php
	}

	public function register_conversion_settings() {
		$settings = new PIPO_Conversion_Settings();
		register_setting( 'pipo_conversion', PIPO_Conversion_Settings::OPTION, array( 'sanitize_callback' => array( $settings, 'sanitize' ), 'default' => PIPO_Conversion_Settings::defaults() ) );
	}

	public function render_conversion_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$service = new PIPO_Conversion_Settings(); $values = $service->get(); $avif = $service->avif_available();
		$checkboxes = array( 'webp_enabled' => 'WebP فعال', 'avif_enabled' => 'AVIF فعال', 'webp_lossless' => 'WebP بدون افت کیفیت (Lossless)', 'remove_metadata' => 'حذف Metadata غیرضروری', 'preserve_copyright' => 'حفظ اطلاعات Copyright', 'preserve_transparency' => 'حفظ Transparency' );
		?>
		<div class="wrap" dir="rtl"><h1>تنظیمات تبدیل تصاویر</h1><?php if ( ! $avif ) : ?><div class="notice notice-warning inline"><p>رمزگذاری AVIF روی این سرور پشتیبانی نمی‌شود؛ این قابلیت با امنیت کامل غیرفعال است.</p></div><?php endif; ?>
		<form method="post" action="options.php"><?php settings_fields( 'pipo_conversion' ); ?><table class="form-table" role="presentation">
		<?php foreach ( $checkboxes as $key => $label ) : $disabled = 'avif_enabled' === $key && ! $avif; ?><tr><th><?php echo esc_html( $label ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( PIPO_Conversion_Settings::OPTION . '[' . $key . ']' ); ?>" value="1" <?php checked( ! empty( $values[ $key ] ) ); disabled( $disabled ); ?>> <?php echo $disabled ? 'پشتیبانی نمی‌شود' : 'فعال'; ?></label></td></tr><?php endforeach; ?>
		<tr><th>کیفیت WebP</th><td><input type="number" min="1" max="100" name="<?php echo esc_attr( PIPO_Conversion_Settings::OPTION ); ?>[webp_quality]" value="<?php echo esc_attr( $values['webp_quality'] ); ?>"> <span>پیش‌فرض: 80</span></td></tr>
		<tr><th>کیفیت AVIF</th><td><input type="number" min="1" max="100" name="<?php echo esc_attr( PIPO_Conversion_Settings::OPTION ); ?>[avif_quality]" value="<?php echo esc_attr( $values['avif_quality'] ); ?>" <?php disabled( ! $avif ); ?>> <span>پیش‌فرض: 55</span></td></tr>
		</table><?php submit_button( 'ذخیره تنظیمات' ); ?></form></div>
		<?php
	}

	public function handle_scan_action() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'دسترسی غیرمجاز است.', 'persian-image-performance-optimizer' ) ); }
		check_admin_referer( 'pipo_scan_action' );
		$scanner = new PIPO_Media_Scanner();
		$action  = isset( $_POST['scan_command'] ) ? sanitize_key( wp_unslash( $_POST['scan_command'] ) ) : '';
		$batch   = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : PIPO_Media_Scanner::DEFAULT_BATCH;
		if ( 'start' === $action ) { $scanner->start( 'full', $batch ); $scanner->process_batch(); }
		if ( 'incremental' === $action ) { $scanner->start( 'incremental', $batch ); $scanner->process_batch(); }
		if ( 'stop' === $action ) { $scanner->stop(); }
		if ( 'resume' === $action ) { $scanner->resume(); $scanner->process_batch(); }
		if ( 'retry' === $action ) { $scanner->retry_failures( $batch ); $scanner->process_batch(); }
		wp_safe_redirect( admin_url( 'tools.php?page=pipo-image-scanner' ) );
		exit;
	}

	public function render_scanner_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$query = new WP_Query( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => PIPO_Media_Scanner::MIME_TYPES, 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => false ) );
		$stats = ( new PIPO_Image_Repository() )->statistics( $query->found_posts );
		$job   = ( new PIPO_Job_Repository() )->latest_scanner_job();
		?>
		<div class="wrap" dir="rtl"><h1>اسکن تصاویر</h1><p>پردازش در Batchهای کوچک انجام می‌شود و با هر بار «ادامه» فقط یک Batch اجرا خواهد شد.</p>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;max-width:900px;margin:20px 0">
			<?php foreach ( array( 'کل تصاویر' => $stats['total'], 'اسکن‌شده' => $stats['scanned'], 'در انتظار' => $stats['pending'], 'خطاها' => $stats['errors'], 'آخرین اسکن' => ( $stats['last_scan'] ?: '—' ) ) as $label => $value ) : ?>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px"><strong><?php echo esc_html( $label ); ?></strong><div style="font-size:20px;margin-top:8px"><?php echo esc_html( $value ); ?></div></div>
			<?php endforeach; ?>
			</div>
			<p>وضعیت پردازش: <strong><?php echo esc_html( $job ? $job->status : 'idle' ); ?></strong></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'pipo_scan_action' ); ?><input type="hidden" name="action" value="pipo_scan_action">
				<label>اندازه Batch: <select name="batch_size"><?php foreach ( PIPO_Media_Scanner::ALLOWED_BATCHES as $size ) : ?><option value="<?php echo esc_attr( $size ); ?>" <?php selected( 50, $size ); ?>><?php echo esc_html( $size ); ?></option><?php endforeach; ?></select></label>
				<p><button class="button button-primary" name="scan_command" value="start">شروع اسکن</button> <button class="button" name="scan_command" value="incremental">اسکن افزایشی</button> <button class="button" name="scan_command" value="stop">توقف</button> <button class="button" name="scan_command" value="resume">ادامه</button> <button class="button" name="scan_command" value="retry">بررسی مجدد خطاها</button></p>
			</form>
		</div>
		<?php
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'persian-image-performance-optimizer' ) );
		}
		$report = ( new PIPO_Capability_Service() )->detect();
		?>
		<div class="wrap pipo-health" dir="rtl">
			<style>
				.pipo-health{max-width:1100px}.pipo-health__intro{color:#50575e;font-size:14px}.pipo-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(310px,1fr));gap:16px;margin-top:22px}.pipo-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}.pipo-card__head{display:flex;justify-content:space-between;gap:12px;align-items:center}.pipo-card h2{font-size:15px;margin:0}.pipo-badge{border-radius:20px;padding:4px 10px;font-weight:600;font-size:12px}.pipo-badge--success{background:#edfaef;color:#116329}.pipo-badge--warning{background:#fff8e5;color:#8a5200}.pipo-badge--error{background:#fcf0f1;color:#8a2424}.pipo-badge--info{background:#eef5ff;color:#174ea6}.pipo-card p{margin:12px 0 0;color:#50575e}.pipo-remediation{border-top:1px solid #f0f0f1;padding-top:10px!important;font-size:12px}.pipo-summary{background:#1d2327;color:#fff;border-radius:10px;padding:18px;margin-top:18px}.pipo-summary strong{color:#72aee6}
			</style>
			<h1>سلامت سیستم</h1>
			<p class="pipo-health__intro">این صفحه فقط قابلیت‌های محیط را بررسی می‌کند و هیچ تصویری را تغییر یا بهینه‌سازی نمی‌کند.</p>
			<div class="pipo-summary">موتور پردازش ترجیحی: <strong><?php echo esc_html( ucfirst( $report['preferred_engine'] ) ); ?></strong></div>
			<div class="pipo-grid">
				<?php foreach ( $report['items'] as $item ) : ?>
					<section class="pipo-card">
						<div class="pipo-card__head"><h2><?php echo esc_html( $item['label'] ); ?></h2><span class="pipo-badge pipo-badge--<?php echo esc_attr( $item['severity'] ); ?>"><?php echo esc_html( $item['status'] ); ?></span></div>
						<p><?php echo esc_html( $item['description'] ); ?></p>
						<p class="pipo-remediation"><strong>راهکار:</strong> <?php echo esc_html( $item['remediation'] ); ?></p>
					</section>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
