<?php
/**
 * Plugin Name:       CCMerch Alert
 * Plugin URI:        https://ccmerch.dk
 * Description:       Merchandise purchase alerts for your WooCommerce store.
 * Version:           Beta 2.0.2
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Jonas 'Cailean' Nørholm Nielsen
 * Author URI:        https://ccmerch.dk
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ccmerch-alert
 * Domain Path:       /languages
 *
 * @package CCMerchAlert
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Enforce minimum requirements before loading anything else.
global $wp_version;

if ( version_compare( PHP_VERSION, '8.1', '<' ) || version_compare( $wp_version, '6.6', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'CCMerch Alert requires PHP 8.1 and WordPress 6.6 or higher. Please upgrade before activating this plugin.', 'ccmerch-alert' )
				. '</p></div>';
		}
	);
	return;
}

define( 'CCMERCH_ALERT_VERSION', 'Beta 2.0.2' );
define( 'CCMERCH_ALERT_FILE',    __FILE__ );
define( 'CCMERCH_ALERT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'CCMERCH_ALERT_URL',     plugin_dir_url( __FILE__ ) );

require_once CCMERCH_ALERT_DIR . 'includes/class-plugin.php';

// Translation core — loaded before plugins_loaded so ccmerch_translate() is
// available globally from the moment WordPress fires plugins_loaded callbacks.
require_once CCMERCH_ALERT_DIR . 'includes/translations/class-string-registry.php';
require_once CCMERCH_ALERT_DIR . 'includes/translations/class-translation-store.php';
require_once CCMERCH_ALERT_DIR . 'includes/translations/class-translation-service.php';

if ( ! \function_exists( 'ccmerch_translate' ) ) {
	/**
	 * Returns the effective display value for a translatable string key.
	 *
	 * Resolution order: stored override → registered default → key itself.
	 *
	 * @param  string $key Dot-notation key, e.g. 'checkout.username.label'.
	 * @return string
	 */
	function ccmerch_translate( string $key ): string {
		return \CCMerchAlert\Translations\Translation_Service::t( $key );
	}
}
require_once CCMERCH_ALERT_DIR . 'includes/order-log/class-order-installer.php';
require_once CCMERCH_ALERT_DIR . 'includes/creators/class-creator-installer.php';
require_once CCMERCH_ALERT_DIR . 'includes/engine/class-alert-event-installer.php';
require_once CCMERCH_ALERT_DIR . 'includes/releases/class-release-installer.php';
require_once CCMERCH_ALERT_DIR . 'includes/fonts/class-google-fonts-installer.php';
require_once CCMERCH_ALERT_DIR . 'includes/chatbot/class-chatbot-installer.php';
require_once CCMERCH_ALERT_DIR . 'includes/chatbot/class-chatbot-message-log-installer.php';
// Loaded early so the static deactivation hook (which needs no instance) is registered
// before plugins_loaded fires, matching the pattern used by all other installers.
require_once CCMERCH_ALERT_DIR . 'includes/chatbot/class-chatbot-runtime.php';

register_activation_hook( __FILE__, [ CCMerchAlert\OrderLog\Order_Installer::class,      'activate' ] );
register_activation_hook( __FILE__, [ CCMerchAlert\Creators\Creator_Installer::class,    'activate' ] );
register_activation_hook( __FILE__, [ CCMerchAlert\Engine\Alert_Event_Installer::class,  'activate' ] );
register_activation_hook( __FILE__, [ CCMerchAlert\Releases\Release_Installer::class,    'activate' ] );
register_activation_hook( __FILE__, [ CCMerchAlert\Fonts\Google_Fonts_Installer::class,  'activate' ] );
register_activation_hook( __FILE__, [ CCMerchAlert\Chatbot\Chatbot_Installer::class,     'activate' ] );
register_activation_hook( __FILE__, [ CCMerchAlert\Chatbot\Chatbot_Message_Log_Installer::class, 'activate' ] );

// Clear the chatbot background token-check cron event when the plugin is deactivated.
register_deactivation_hook( __FILE__, [ CCMerchAlert\Chatbot\Chatbot_Runtime::class, 'deactivate' ] );

// Signal that the rewrite rule cache must be rebuilt on the next wp_loaded.
// A direct flush_rewrite_rules() in the activation hook is unreliable because
// Plugin::init() hasn't run yet (the plugin wasn't active during plugins_loaded),
// so Overlay_Controller's init hook was never registered for the current request.
// The deferred flag lets the first real request after activation rebuild the
// cache once every plugin's rules are in place.
register_activation_hook(
	__FILE__,
	static function (): void {
		update_option( 'ccmerch_alert_flush_rewrite', '1' );
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		// Ensure all DB tables exist for installations updated without reactivation.
		// Each maybe_install() is a cheap get_option() guard — dbDelta runs only
		// when the stored version differs from the class constant.
		CCMerchAlert\Creators\Creator_Installer::maybe_install();
		CCMerchAlert\Engine\Alert_Event_Installer::maybe_install();
		CCMerchAlert\Releases\Release_Installer::maybe_install();
		CCMerchAlert\Fonts\Google_Fonts_Installer::maybe_install();
		CCMerchAlert\Chatbot\Chatbot_Installer::maybe_install();
		CCMerchAlert\Chatbot\Chatbot_Message_Log_Installer::maybe_install();
		CCMerchAlert\Plugin::get_instance()->init();
	}
);
