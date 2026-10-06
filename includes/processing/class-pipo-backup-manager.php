<?php
defined( 'ABSPATH' ) || exit;

final class PIPO_Backup_Manager implements BackupManagerInterface {
	private $root; private $validator;
	public function __construct( $root = null, $validator = null ) {
		$uploads = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array();
		$this->root = $root ?: trailingslashit( $uploads['basedir'] ?? sys_get_temp_dir() ) . 'pipo-backups';
		$this->validator = $validator ?: new PIPO_Image_Validator();
	}
	private function prepare_root() {
		if ( ! is_dir( $this->root ) && ! wp_mkdir_p( $this->root ) ) { throw new RuntimeException( 'backup_directory_unavailable' ); }
		if ( ! is_writable( $this->root ) ) { throw new RuntimeException( 'backup_directory_not_writable' ); }
		if ( ! is_file( $this->root . '/index.php' ) ) { file_put_contents( $this->root . '/index.php', "<?php\n// Silence is golden.\n" ); }
		if ( ! is_file( $this->root . '/.htaccess' ) ) { file_put_contents( $this->root . '/.htaccess', "Require all denied\nDeny from all\n" ); }
		if ( ! is_file( $this->root . '/web.config' ) ) { file_put_contents( $this->root . '/web.config', "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>" ); }
	}
	public function create_backup( $source_path ) {
		$info = $this->validator->validate( $source_path ); $this->prepare_root();
		$key = hash( 'sha256', wp_normalize_path( $source_path ) ); $directory = $this->root . '/' . substr( $key, 0, 2 ) . '/' . $key;
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) { throw new RuntimeException( 'backup_subdirectory_unavailable' ); }
		$filename = gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false, false ) . '.' . pathinfo( $source_path, PATHINFO_EXTENSION );
		$destination = $directory . '/' . $filename; $temporary = $destination . '.tmp';
		if ( ! copy( $source_path, $temporary ) ) { throw new RuntimeException( 'backup_copy_failed' ); }
		$this->validator->validate( $temporary, array( 'mime' => $info['mime'] ) );
		if ( ! rename( $temporary, $destination ) ) { @unlink( $temporary ); throw new RuntimeException( 'backup_commit_failed' ); }
		file_put_contents( $destination . '.json', wp_json_encode( array( 'source' => wp_normalize_path( $source_path ), 'mime' => $info['mime'], 'hash' => hash_file( 'sha256', $destination ), 'created_at' => gmdate( 'c' ) ) ) );
		return $destination;
	}
	public function validate_backup( $backup_path, $expected_mime = null ) {
		$info = $this->validator->validate( $backup_path, $expected_mime ? array( 'mime' => $expected_mime ) : array() );
		$manifest = $backup_path . '.json'; if ( ! is_file( $manifest ) ) { throw new RuntimeException( 'backup_manifest_missing' ); }
		$data = json_decode( file_get_contents( $manifest ), true );
		if ( ! is_array( $data ) || empty( $data['hash'] ) || ! hash_equals( $data['hash'], hash_file( 'sha256', $backup_path ) ) ) { throw new RuntimeException( 'backup_hash_mismatch' ); }
		return $info;
	}
	public function restore( $backup_path, $destination_path ) {
		$backup = $this->validate_backup( $backup_path ); $temporary = dirname( $destination_path ) . '/.' . basename( $destination_path ) . '.restore-' . wp_generate_password( 8, false, false );
		if ( ! is_dir( dirname( $destination_path ) ) || ! is_writable( dirname( $destination_path ) ) || ! copy( $backup_path, $temporary ) ) { throw new RuntimeException( 'restore_write_failed' ); }
		$this->validator->validate( $temporary, array( 'mime' => $backup['mime'] ) );
		if ( ! rename( $temporary, $destination_path ) ) { @unlink( $temporary ); throw new RuntimeException( 'restore_commit_failed' ); }
		return $destination_path;
	}
	public function previous_versions( $source_path ) {
		$key = hash( 'sha256', wp_normalize_path( $source_path ) ); $files = glob( $this->root . '/' . substr( $key, 0, 2 ) . '/' . $key . '/*' );
		$files = array_values( array_filter( $files ?: array(), function ( $file ) { return '.json' !== substr( $file, -5 ) && '.tmp' !== substr( $file, -4 ); } ) ); rsort( $files ); return $files;
	}
	public function remove_generated_variants( $variant_paths ) {
		$count = 0; foreach ( (array) $variant_paths as $path ) { if ( is_string( $path ) && is_file( $path ) && false !== strpos( basename( $path ), '-pipo-' ) && unlink( $path ) ) { $count++; } } return $count;
	}
}
