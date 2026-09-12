<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all plugin data from the database by delegating to the Settings
 * module so the option key stays in one place.
 *
 * @package CCMerchAlert
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-settings.php';

CCMerchAlert\Settings::delete_all();

delete_option( 'ccmerch_overlay_settings' );
delete_option( 'ccmerch_alert_flush_rewrite' );

// Alert Event queue table and schema version.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ccmerch_alert_events" );
delete_option( 'ccmerch_alert_events_db_version' );

// Releases table and schema version.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ccmerch_releases" );
delete_option( 'ccmerch_releases_db_version' );

// Creator Profile tables and schema version.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ccmerch_creator_overlay_settings" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ccmerch_creator_category_assignments" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ccmerch_creator_profiles" );
delete_option( 'ccmerch_creator_db_version' );

// Google Fonts catalog table and schema version.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ccmerch_google_fonts" );
delete_option( 'ccmerch_google_fonts_db_version' );

// Chatbot tables and options.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ccmerch_creator_chatbot" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ccmerch_creator_channel_auth" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ccmerch_chatbot_messages" );
delete_option( 'ccmerch_chatbot_message_log_db_version' );
delete_option( 'ccmerch_chatbot_db_version' );
delete_option( 'ccmerch_chatbot_twitch_tokens' );
delete_option( 'ccmerch_chatbot_twitch_app' );
delete_option( 'ccmerch_chatbot_diagnostics' );
delete_option( 'ccmerch_chatbot_health' );
delete_option( 'ccmerch_chatbot_scopes' );
wp_clear_scheduled_hook( 'ccmerch_chatbot_runtime_token_check' );
wp_clear_scheduled_hook( 'ccmerch_chatbot_dispatch_order' );

// Translation overrides.
delete_option( 'ccmerch_translations' );

// For multisite: remove options from every blog.
// if ( is_multisite() ) {
//     foreach ( get_sites( [ 'fields' => 'ids' ] ) as $blog_id ) {
//         switch_to_blog( $blog_id );
//         CCMerchAlert\Settings::delete_all();
//         restore_current_blog();
//     }
// }
