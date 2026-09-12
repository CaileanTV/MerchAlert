<?php
/**
 * Google Fonts catalog table installer.
 *
 * @package CCMerchAlert\Fonts
 */

namespace CCMerchAlert\Fonts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the {prefix}ccmerch_google_fonts table.
 *
 * Follows the same activate() + maybe_install() pattern as all other
 * CCMerch Alert installers so file-only deployments receive the schema
 * without requiring a plugin reactivation cycle.
 *
 * Table: {prefix}ccmerch_google_fonts
 *   id             — auto-increment primary key
 *   family         — font family name (unique; used as the identifier)
 *   category       — Google Fonts category slug (serif, sans-serif, …)
 *   variants       — JSON array of variant strings ('400', '700italic', …)
 *   subsets        — JSON array of supported subset strings ('latin', …)
 *   sort_order     — popularity rank from the Google Fonts API (1 = most popular)
 *   last_modified  — date string from the Google Fonts API, e.g. '2024-09-03'
 *   approved       — 0 = hidden from creators; 1 = visible in font selector
 *   last_synced_at — UTC datetime of the last catalog sync
 */
final class Google_Fonts_Installer {

	/** @var string wp_options key that stores the installed schema version. */
	public const DB_VERSION_KEY = 'ccmerch_google_fonts_db_version';

	/** @var string Current schema version. Bump when the table structure changes. */
	public const DB_VERSION = '1.1';

	/**
	 * Called by register_activation_hook() in the plugin bootstrap.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_table();
		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	/**
	 * Ensures the table exists on every request, creating or upgrading it when
	 * the stored schema version does not match DB_VERSION.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( get_option( self::DB_VERSION_KEY ) === self::DB_VERSION ) {
			return;
		}
		self::create_table();
		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	/**
	 * Creates or upgrades the table via dbDelta().
	 *
	 * @return void
	 */
	private static function create_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . 'ccmerch_google_fonts';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			family VARCHAR(255) NOT NULL,
			category VARCHAR(50) NOT NULL DEFAULT '',
			variants TEXT NOT NULL DEFAULT '[]',
			subsets TEXT NOT NULL DEFAULT '[]',
			sort_order INT UNSIGNED NOT NULL DEFAULT 0,
			last_modified VARCHAR(20) NOT NULL DEFAULT '',
			approved TINYINT(1) NOT NULL DEFAULT 0,
			last_synced_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY family (family),
			KEY approved (approved),
			KEY sort_order (sort_order)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
