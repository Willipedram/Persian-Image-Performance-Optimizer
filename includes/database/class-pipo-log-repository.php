<?php
defined( 'ABSPATH' ) || exit;

final class PIPO_Log_Repository extends PIPO_Repository {
	protected $formats = array(
		'level' => '%s', 'attachment_id' => '%d', 'operation' => '%s', 'message' => '%s', 'context' => '%s',
		'duration_ms' => '%f', 'memory_bytes' => '%d', 'created_at' => '%s',
	);
	protected function table_suffix() { return 'pipo_logs'; }

	/** Stores structured before/after compression history. @return int|false */
	public function record_compression( $attachment_id, $data ) {
		return $this->insert( array( 'level' => empty( $data['preferred'] ) ? 'info' : 'success', 'attachment_id' => (int) $attachment_id, 'operation' => 'conversion', 'message' => empty( $data['preferred'] ) ? 'خروجی بزرگ‌تر یا نامناسب بود و ترجیح داده نشد.' : 'نسخه بهینه با موفقیت ایجاد شد.', 'context' => wp_json_encode( $data ), 'duration_ms' => $data['duration_ms'] ?? null, 'memory_bytes' => memory_get_usage( true ), 'created_at' => current_time( 'mysql', true ) ) );
	}
}
