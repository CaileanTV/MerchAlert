<?php
/**
 * Chatbot database installer.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades all chatbot database tables.
 *
 * Follows the activate() + maybe_install() pattern used by all installers in this plugin.
 * Called via register_activation_hook() in the bootstrap and via maybe_install() on
 * plugins_loaded so file-only deployments receive the schema automatically.
 */
final class Chatbot_Installer {

	/** @var string Option key that stores the installed schema version. */
	public const DB_VERSION_KEY = 'ccmerch_chatbot_db_version';

	/** @var string Current schema version. Increment whenever the table definition changes. */
	public const DB_VERSION = '2.0';

	/** @var string Unprefixed table name for creator chatbot settings. */
	private const TABLE = 'ccmerch_creator_chatbot';

	/** @var string Unprefixed table name for creator channel:bot authorization. */
	private const TABLE_CHANNEL_AUTH = 'ccmerch_creator_channel_auth';

	/**
	 * Runs on plugin activation via register_activation_hook().
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
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
		self::create_tables();
		update_option( self::DB_VERSION_KEY, self::DB_VERSION, false );
	}

	/**
	 * Creates or upgrades all chatbot tables using dbDelta().
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset    = $wpdb->get_charset_collate();
		$table      = $wpdb->prefix . self::TABLE;
		$table_auth = $wpdb->prefix . self::TABLE_CHANNEL_AUTH;

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			creator_id bigint(20) unsigned NOT NULL,
			twitch_channel varchar(100) NOT NULL DEFAULT '',
			enabled tinyint(1) NOT NULL DEFAULT 0,
			template text NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY creator_id (creator_id)
		) {$charset};";

		$sql_auth = "CREATE TABLE {$table_auth} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			creator_id bigint(20) unsigned NOT NULL,
			channel_login varchar(100) NOT NULL DEFAULT '',
			access_token text NOT NULL,
			refresh_token text NOT NULL,
			scope varchar(255) NOT NULL DEFAULT '',
			authorized_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY creator_id (creator_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		dbDelta( $sql_auth );
	}
}
