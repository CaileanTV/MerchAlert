<?php
/**
 * Alert Event database installer.
 *
 * @package CCMerchAlert\Engine
 */

namespace CCMerchAlert\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the Alert Events queue table.
 *
 * Called via register_activation_hook() in the plugin bootstrap and via
 * maybe_install() on plugins_loaded, so file-only deployments work correctly
 * without requiring a deactivate/reactivate cycle.
 *
 * Table: {prefix}ccmerch_alert_events
 *  - One row per routed alert event (one WooCommerce order item → one creator overlay).
 *  - composite index on (overlay_id, created_at) makes the poll query O(log n).
 *  - category_term_ids stored as JSON text; decoded to int[] on read.
 *  - variation_id and featured_image_attachment_id stored as UNSIGNED BIGINT
 *    with 0 meaning "absent"; converted to null by Alert_Event_Repository on read.
 *  - template_id, audio_profile_id, animation_profile_id default to NULL;
 *    reserved for future per-creator profile assignments.
 *
 * Loaded in ccmerch-alert.php before register_activation_hook() fires so the
 * class is available at activation time. Also loaded in Plugin::load_files()
 * for runtime access. Double-loading is safe — PHP require_once is idempotent.
 */
final class Alert_Event_Installer {

	/** wp_options key that stores the installed DB schema version. */
	public const DB_VERSION_KEY = 'ccmerch_alert_events_db_version';

	/** Current schema version. Increment whenever the table structure changes. */
	public const DB_VERSION = '1.1';

	/**
	 * Activation callback — creates the table and records the schema version.
	 *
	 * Called by register_activation_hook() in ccmerch-alert.php.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	/**
	 * Idempotent install — runs only when the stored version does not match.
	 *
	 * Called on plugins_loaded in ccmerch-alert.php so installations that were
	 * active before 3.0.0 was deployed receive the schema without requiring a
	 * deactivate / reactivate cycle. The get_option() check is cheap; dbDelta()
	 * only runs when the version is absent or outdated.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( get_option( self::DB_VERSION_KEY ) === self::DB_VERSION ) {
			return;
		}

		self::create_tables();
		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	/**
	 * Runs dbDelta() for the alert events table.
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table           = $wpdb->prefix . 'ccmerch_alert_events';

		$sql = "CREATE TABLE {$table} (
  id                           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  creator_id                   BIGINT(20) UNSIGNED NOT NULL,
  creator_uuid                 CHAR(36) NOT NULL DEFAULT '',
  overlay_id                   VARCHAR(32) NOT NULL DEFAULT '',
  order_id                     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  order_number                 VARCHAR(64) NOT NULL DEFAULT '',
  order_item_id                BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  customer_first_name          VARCHAR(255) NOT NULL DEFAULT '',
  quantity                     INT(11) NOT NULL DEFAULT 1,
  product_id                   BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  variation_id                 BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  product_name                 VARCHAR(255) NOT NULL DEFAULT '',
  featured_image_attachment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  category_term_ids            TEXT NOT NULL DEFAULT '',
  template_id                  BIGINT(20) UNSIGNED DEFAULT NULL,
  audio_profile_id             BIGINT(20) UNSIGNED DEFAULT NULL,
  animation_profile_id         BIGINT(20) UNSIGNED DEFAULT NULL,
  created_at                   DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY item_overlay (order_item_id, overlay_id),
  KEY overlay_created (overlay_id, created_at),
  KEY creator_id (creator_id),
  KEY order_id (order_id)
) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
