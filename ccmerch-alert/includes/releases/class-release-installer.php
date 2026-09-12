<?php
/**
 * Release Installer — creates the ccmerch_releases table.
 *
 * Follows the same activate() + maybe_install() pattern as Order_Installer,
 * Creator_Installer, and Alert_Event_Installer. Loaded in ccmerch-alert.php
 * before plugins_loaded so the activation hook can fire correctly.
 *
 * Seeds two initial rows when the table is first created (one per release
 * shipped before this system existed) so the Creator Portal shows data
 * immediately without manual admin entry.
 *
 * @package CCMerchAlert\Releases
 * @feature release_manager
 */

namespace CCMerchAlert\Releases;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Release_Installer {

	public const DB_VERSION_KEY = 'ccmerch_releases_db_version';
	public const DB_VERSION     = '1.0';

	public static function activate(): void {
		self::create_table();
		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_VERSION_KEY ) === self::DB_VERSION ) {
			return;
		}
		self::create_table();
		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	private static function create_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'ccmerch_releases';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			version VARCHAR(50) NOT NULL DEFAULT '',
			stage VARCHAR(20) NOT NULL DEFAULT 'beta',
			title VARCHAR(255) NOT NULL DEFAULT '',
			description TEXT NOT NULL DEFAULT '',
			feature_tags TEXT NOT NULL DEFAULT '[]',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			KEY stage (stage),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		self::seed_if_empty( $table );
	}

	private static function seed_if_empty( string $table ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count > 0 ) {
			return;
		}

		$now = \gmdate( 'Y-m-d H:i:s' );

		$wpdb->insert(
			$table,
			[
				'version'      => '1.0.0',
				'stage'        => 'beta',
				'title'        => 'First Beta Release',
				'description'  => 'Released the Beta version of CCMerch Alert.',
				'feature_tags' => (string) \wp_json_encode( [
					'alert_templates', 'audio_isolation', 'audio_upload',
					'browser_source', 'changelog_system', 'creator_portal',
					'dashboard', 'order_log', 'overlay_designer', 'routing_engine',
				] ),
				'created_at'   => $now,
				'updated_at'   => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		$wpdb->insert(
			$table,
			[
				'version'      => '1.0.1',
				'stage'        => 'beta',
				'title'        => 'Release Manager',
				'description'  => 'Introduced Release System v2: database-driven changelog, REST API endpoint, and Admin Changelog Manager.',
				'feature_tags' => (string) \wp_json_encode( [ 'changelog_system', 'release_manager' ] ),
				'created_at'   => $now,
				'updated_at'   => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}
}
