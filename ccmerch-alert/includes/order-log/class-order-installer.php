<?php
/**
 * Order Log database installer.
 *
 * @package CCMerchAlert\OrderLog
 */

namespace CCMerchAlert\OrderLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the Order Log database tables.
 *
 * Called once via register_activation_hook() in the plugin bootstrap. Uses
 * dbDelta() so subsequent activations upgrade the schema rather than
 * clobbering existing data.
 *
 * Tables created:
 *  - {prefix}ccmerch_order_log       — one row per logged order
 *  - {prefix}ccmerch_order_log_items — one row per line item in a logged order
 *
 * Loaded early in ccmerch-alert.php so the class name resolves before
 * plugins_loaded fires. Not loaded via Plugin::load_files() to avoid a
 * redundant require_once on every request.
 */
final class Order_Installer {

	/**
	 * wp_options key that stores the installed DB schema version.
	 */
	public const DB_VERSION_KEY = 'ccmerch_alert_db_version';

	/**
	 * Current DB schema version. Increment when altering table structure.
	 */
	public const DB_VERSION = '1.0';

	/**
	 * Activation callback. Creates or upgrades Order Log tables.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	/**
	 * Runs dbDelta() for both Order Log tables.
	 *
	 * dbDelta() requires:
	 *  - Two spaces between the column name and type.
	 *  - PRIMARY KEY and INDEX definitions after all column definitions.
	 *  - Each SQL statement in $sql separated by a semicolon.
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$log_table       = $wpdb->prefix . 'ccmerch_order_log';
		$items_table     = $wpdb->prefix . 'ccmerch_order_log_items';

		$sql = "CREATE TABLE {$log_table} (
  id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id     BIGINT(20) UNSIGNED NOT NULL,
  order_number VARCHAR(64) NOT NULL DEFAULT '',
  order_date   DATETIME NOT NULL,
  first_name   VARCHAR(255) NOT NULL DEFAULT '',
  logged_at    DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_id (order_id)
) {$charset_collate};

CREATE TABLE {$items_table} (
  id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  log_id              BIGINT(20) UNSIGNED NOT NULL,
  product_id          BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  variation_id        BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  product_name        VARCHAR(255) NOT NULL DEFAULT '',
  product_sku         VARCHAR(100) NOT NULL DEFAULT '',
  quantity            INT NOT NULL DEFAULT 1,
  image_attachment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  category_ids        TEXT NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY log_id (log_id)
) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
