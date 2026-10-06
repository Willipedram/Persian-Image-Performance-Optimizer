<?php
/** Validated rollback facade for previous originals and generated variants. */

defined( 'ABSPATH' ) || exit;

final class PIPO_Rollback_Manager {
	/** @var BackupManagerInterface */ private $backups;
	public function __construct( BackupManagerInterface $backups ) { $this->backups = $backups; }
	public function restore_single_image( $backup_path, $original_path ) { return $this->backups->restore( $backup_path, $original_path ); }
	public function restore_previous_version( $original_path ) { $versions = $this->backups->previous_versions( $original_path ); if ( empty( $versions ) ) { throw new RuntimeException( 'previous_version_not_found' ); } return $this->backups->restore( $versions[0], $original_path ); }
	public function remove_generated_variants( $variant_paths ) { return $this->backups->remove_generated_variants( $variant_paths ); }
}
