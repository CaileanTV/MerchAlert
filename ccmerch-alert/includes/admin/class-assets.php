<?php
/**
 * Assets module.
 *
 * @package CCMerchAlert\Admin
 */

namespace CCMerchAlert\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles admin asset enqueueing for the plugin.
 *
 * All CSS and JS is scoped to the plugin's top-level admin page only.
 * The page hook is read from the Admin module, which stores the value
 * returned by add_menu_page().
 *
 * Instantiated by {@see \CCMerchAlert\Plugin::load_components()} only when is_admin() is true.
 */
final class Assets {

	/**
	 * Admin module, used to determine which page to enqueue assets on.
	 *
	 * @var Admin
	 */
	private Admin $admin;

	/**
	 * AJAX handler module, used to supply nonce and config to JavaScript.
	 *
	 * @var Dashboard_Ajax
	 */
	private Dashboard_Ajax $dashboard_ajax;

	/**
	 * @param Admin          $admin          Admin module instance.
	 * @param Dashboard_Ajax $dashboard_ajax Dashboard AJAX handler instance.
	 */
	public function __construct( Admin $admin, Dashboard_Ajax $dashboard_ajax ) {
		$this->admin          = $admin;
		$this->dashboard_ajax = $dashboard_ajax;

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	/**
	 * Enqueues plugin CSS and JS on the plugin's admin page only.
	 *
	 * The $hook_suffix is compared against the page hook returned by
	 * add_menu_page() to ensure assets are not loaded on every admin page.
	 * After the script is enqueued, wp_localize_script() passes dashboard
	 * config (nonce, polling interval, AJAX URL) to the frontend.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->admin->get_page_hook() ) {
			return;
		}

		wp_enqueue_style(
			'ccmerch-alert-admin',
			CCMERCH_ALERT_URL . 'assets/css/admin.css',
			[],
			CCMERCH_ALERT_VERSION
		);

		wp_enqueue_script(
			'ccmerch-alert-admin',
			CCMERCH_ALERT_URL . 'assets/js/admin.js',
			[],
			CCMERCH_ALERT_VERSION,
			true
		);

		wp_localize_script(
			'ccmerch-alert-admin',
			'ccmerchDashboard',
			[
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( Dashboard_Ajax::NONCE_ACTION ),
				'refreshInterval' => $this->dashboard_ajax->get_polling_interval(),
				'initialTime'     => \time(),
			]
		);
	}
}
