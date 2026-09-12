<?php
/**
 * Chatbot Message Log database installer.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the chatbot message log table.
 *
 * Table: {prefix}ccmerch_chatbot_messages
 *  - One row per (creator, WooCommerce order item) that has ever had a chatbot
 *    message generated for it — by a live order or by replay.
 *  - order_item_id is the same identifier Alert_Event uses (WC_Order_Item::get_id()),
 *    so one row here corresponds 1:1 to one line item's chatbot message, exactly
 *    like Alert_Event corresponds 1:1 to one line item's overlay alert.
 *  - UNIQUE KEY on (creator_id, order_item_id) is the durable dedup guard: a
 *    second attempt to record the same creator + line item is rejected at the
 *    DB layer even if the application-layer existence check is bypassed by a
 *    race condition.
 *
 * Follows the activate() + maybe_install() pattern used by all installers in
 * this plugin. Called via register_activation_hook() in the bootstrap and via
 * maybe_install() on plugins_loaded so file-only deployments receive the
 * schema automatically.
 */
final class Chatbot_Message_Log_Installer {

	/** @var string Option key that stores the installed schema version. */
	public const DB_VERSION_KEY = 'ccmerch_chatbot_message_log_db_version';

	/** @var string Current schema version. Increment whenever the table definition changes. */
	public const DB_VERSION = '1.0';

	/** @var string Unprefixed table name. */
	private const TABLE = 'ccmerch_chatbot_messages';

	/**
	 * Runs on plugin activation via register_activation_hook().
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_table();
		update_option( self::DB_VERSION_KEY, self::DB_VERSION, false );
	}

	/**
	 * Runs on plugins_loaded to catch file-only deployments.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( get_option( self::DB_VERSION_KEY ) === self::DB_VERSION ) {
			return;
		}
		self::create_table();
		update_option( self::DB_VERSION_KEY, self::DB_VERSION, false );
	}

	/**
	 * Creates or upgrades the table using dbDelta().
	 *
	 * @return void
	 */
	private static function create_table(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . self::TABLE;

		$sql = "CREATE TABLE {$table} (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			creator_id    BIGINT(20) UNSIGNED NOT NULL,
			order_id      BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			order_item_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			product_name  VARCHAR(255) NOT NULL DEFAULT '',
			created_at    DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY creator_item (creator_id, order_item_id),
			KEY order_id (order_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
