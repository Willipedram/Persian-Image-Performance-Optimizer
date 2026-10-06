<?php
defined( 'ABSPATH' ) || exit;

final class PIPO_Usage_Repository extends PIPO_Repository {
	protected $formats = array(
		'attachment_id' => '%d', 'page_id' => '%d', 'page_url' => '%s', 'source_url' => '%s', 'current_src' => '%s',
		'natural_width' => '%d', 'natural_height' => '%d', 'rendered_width' => '%d', 'rendered_height' => '%d',
		'viewport_width' => '%d', 'viewport_height' => '%d', 'device_pixel_ratio' => '%f', 'above_fold' => '%d',
		'possible_lcp' => '%d', 'element_type' => '%s', 'background_image' => '%d', 'slider_image' => '%d',
		'first_seen_at' => '%s', 'last_seen_at' => '%s', 'created_at' => '%s', 'updated_at' => '%s',
	);
	protected function table_suffix() { return 'pipo_usage'; }
}
