<?php
/**
 * Creator Profile database installer.
 *
 * @package CCMerchAlert\Creators
 */

namespace CCMerchAlert\Creators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the Creator Profile database tables.
 *
 * Called once via register_activation_hook() in the plugin bootstrap. Uses
 * dbDelta() so subsequent activations upgrade the schema rather than
 * clobbering existing data.
 *
 * Tables created:
 *  - {prefix}ccmerch_creator_profiles            — one row per creator
 *  - {prefix}ccmerch_creator_category_assignments — creator ↔ WC term ID mapping
 *
 * Category uniqueness (one term per creator) is enforced at the application
 * level in Creator_Service, NOT via a UNIQUE KEY here. This means the schema
 * requires no migration when that restriction is later relaxed to allow
 * multiple categories per creator.
 *
 * Loaded early in ccmerch-alert.php, not in Plugin::load_files(), for the
 * same reason as Order_Installer: activation hooks must be registered before
 * plugins_loaded fires.
 */
final class Creator_Installer {

	/** wp_options key that stores the installed DB schema version. */
	public const DB_VERSION_KEY = 'ccmerch_creator_db_version';

	/** Current DB schema version. Increment when altering table structure. */
	public const DB_VERSION = '1.2';

	/**
	 * Activation callback. Creates or upgrades Creator tables.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	/**
	 * Checks whether the Creator tables need to be created or upgraded and
	 * runs dbDelta() if the stored schema version does not match DB_VERSION.
	 *
	 * Called on plugins_loaded so installations that were active before 2.3.0
	 * was deployed get the schema without needing to deactivate and reactivate
	 * the plugin. The get_option() call is cheap; dbDelta() only runs when the
	 * version is absent or outdated.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( get_option( self::DB_VERSION_KEY ) === self::DB_VERSION ) {
			return;
		}

		self::create_tables();
		self::backfill_access_tokens();
		self::backfill_overlay_profiles();
		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	/**
	 * Generates access tokens for any existing profiles that were created before
	 * the creator_access_token column was added in schema version 1.1.
	 *
	 * Called only inside maybe_install() when the schema version is outdated.
	 * Safe to call multiple times — skips rows that already have a token.
	 *
	 * @return void
	 */
	private static function backfill_access_tokens(): void {
		global $wpdb;

		$profiles_table = $wpdb->prefix . 'ccmerch_creator_profiles';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			"SELECT id FROM {$profiles_table} WHERE creator_access_token = '' OR creator_access_token IS NULL"
		);

		if ( empty( $ids ) ) {
			return;
		}

		foreach ( $ids as $profile_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$profiles_table,
				[ 'creator_access_token' => \bin2hex( \random_bytes( 32 ) ) ],
				[ 'id' => (int) $profile_id ],
				[ '%s' ],
				[ '%d' ]
			);
		}
	}

	/**
	 * Creates an overlay settings row for every creator profile that does not
	 * already have one. Runs on schema upgrades from 1.1 → 1.2.
	 *
	 * Safe to call multiple times — the INSERT is skipped when a row already exists.
	 *
	 * @return void
	 */
	private static function backfill_overlay_profiles(): void {
		global $wpdb;

		$profiles_table = $wpdb->prefix . 'ccmerch_creator_profiles';
		$overlay_table  = $wpdb->prefix . 'ccmerch_creator_overlay_settings';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$creator_ids = $wpdb->get_col(
			"SELECT p.id FROM {$profiles_table} p
			 LEFT JOIN {$overlay_table} o ON o.creator_id = p.id
			 WHERE o.creator_id IS NULL"
		);

		if ( empty( $creator_ids ) ) {
			return;
		}

		$now      = \gmdate( 'Y-m-d H:i:s' );
		$defaults = wp_json_encode( [] );

		foreach ( $creator_ids as $creator_id ) {
			$overlay_id = \bin2hex( \random_bytes( 16 ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$overlay_table,
				[
					'creator_id' => (int) $creator_id,
					'overlay_id' => $overlay_id,
					'settings'   => $defaults,
					'created_at' => $now,
					'updated_at' => $now,
				],
				[ '%d', '%s', '%s', '%s', '%s' ]
			);
		}
	}

	/**
	 * Runs dbDelta() for all Creator tables.
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset_collate   = $wpdb->get_charset_collate();
		$profiles_table    = $wpdb->prefix . 'ccmerch_creator_profiles';
		$assignments_table = $wpdb->prefix . 'ccmerch_creator_category_assignments';
		$overlay_table     = $wpdb->prefix . 'ccmerch_creator_overlay_settings';

		$sql = "CREATE TABLE {$profiles_table} (
  id                    BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  creator_uuid          CHAR(36) NOT NULL DEFAULT '',
  creator_access_token  VARCHAR(64) NOT NULL DEFAULT '',
  creator_name          VARCHAR(255) NOT NULL DEFAULT '',
  description           TEXT NOT NULL DEFAULT '',
  status                VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at            DATETIME NOT NULL,
  updated_at            DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY creator_uuid (creator_uuid),
  UNIQUE KEY creator_access_token (creator_access_token)
) {$charset_collate};

CREATE TABLE {$assignments_table} (
  id                   BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  creator_id           BIGINT(20) UNSIGNED NOT NULL,
  wc_category_term_id  BIGINT(20) UNSIGNED NOT NULL,
  created_at           DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY creator_id (creator_id),
  KEY wc_category_term_id (wc_category_term_id)
) {$charset_collate};

CREATE TABLE {$overlay_table} (
  id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  creator_id  BIGINT(20) UNSIGNED NOT NULL,
  overlay_id  VARCHAR(32) NOT NULL DEFAULT '',
  settings    LONGTEXT NOT NULL,
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY creator_id (creator_id),
  KEY overlay_id (overlay_id)
) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
