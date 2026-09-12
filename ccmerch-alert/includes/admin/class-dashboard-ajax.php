<?php
/**
 * Dashboard AJAX handler.
 *
 * @package CCMerchAlert\Admin
 */

namespace CCMerchAlert\Admin;

use CCMerchAlert\OrderLog\Order_Repository;
use CCMerchAlert\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the wp_ajax_ccmerch_dashboard_sync endpoint.
 *
 * Accepts a filter slug and an optional last-sync timestamp, then returns a
 * minimal JSON payload so the frontend can update the orders list and counter
 * without a full page reload.
 *
 * Security: nonce verified via check_ajax_referer(); capability checked before
 * any data is returned.
 *
 * All database access goes through Order_Repository. This class never queries
 * WooCommerce directly and never touches the ccmerch_order_log tables itself.
 */
final class Dashboard_Ajax {

	/**
	 * WordPress nonce action string used to verify requests.
	 *
	 * Must match the action passed to wp_create_nonce() in Assets.
	 */
	public const NONCE_ACTION = 'ccmerch_dashboard_sync';

	/**
	 * WordPress admin-ajax action name.
	 */
	private const AJAX_ACTION = 'ccmerch_dashboard_sync';

	/**
	 * @var Order_Repository
	 */
	private Order_Repository $repository;

	/**
	 * @var Dashboard_Orders
	 */
	private Dashboard_Orders $dashboard_orders;

	/**
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * @param Order_Repository $repository       Order log data source.
	 * @param Dashboard_Orders $dashboard_orders Renderer used to build order HTML.
	 * @param Settings         $settings         Plugin settings (reads refresh interval).
	 */
	public function __construct( Order_Repository $repository, Dashboard_Orders $dashboard_orders, Settings $settings ) {
		$this->repository       = $repository;
		$this->dashboard_orders = $dashboard_orders;
		$this->settings         = $settings;

		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'handle' ] );
	}

	// -------------------------------------------------------------------------
	// Accessors
	// -------------------------------------------------------------------------

	/**
	 * Returns the configured refresh interval in seconds.
	 *
	 * Read by Assets to populate `refreshInterval` in wp_localize_script().
	 * Also returned in every AJAX response so the frontend adapts if the admin
	 * changes the setting mid-session.
	 *
	 * @return int
	 */
	public function get_polling_interval(): int {
		return (int) $this->settings->get( Settings::FIELD_REFRESH_INTERVAL );
	}

	// -------------------------------------------------------------------------
	// AJAX handler
	// -------------------------------------------------------------------------

	/**
	 * Processes a dashboard sync request and returns JSON.
	 *
	 * POST parameters:
	 *   _ajax_nonce          — WordPress nonce (required).
	 *   filter               — One of "today", "last_24h", "all_time" (default: "today").
	 *   last_sync_timestamp  — Unix timestamp of the last successful sync, or 0 for a
	 *                          full reload (e.g. on initial load or filter change).
	 *
	 * JSON response shape (on success):
	 *   orders_html  string  — HTML for new/all order cards.
	 *   count        int     — Order count matching the active filter.
	 *   has_new      bool    — Whether orders_html contains anything to render.
	 *   is_empty     bool    — Whether the filtered result set is completely empty.
	 *   server_time  int     — Current UTC Unix timestamp for the next sync.
	 *
	 * @return void
	 */
	public function handle(): void {
		check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$filter      = $this->sanitize_filter( (string) ( $_POST['filter'] ?? '' ) );
		$last_sync   = (int) ( $_POST['last_sync_timestamp'] ?? 0 );
		$server_time = \time();

		if ( $last_sync > 0 ) {
			// Background poll — only fetch orders logged after the last sync.
			$entries = $this->repository->get_since( $last_sync, $filter );
			$has_new = ! empty( $entries );
			$html    = $has_new ? $this->dashboard_orders->render_orders_html( $entries ) : '';
		} else {
			// Filter change or initial load — return the full filtered list.
			$entries = $this->repository->get_filtered( $filter, 10 );
			$has_new = true; // Client always replaces the list in this mode.
			$html    = $this->dashboard_orders->render_orders_html( $entries );
		}

		wp_send_json_success(
			[
				'orders_html'      => $html,
				'count'            => $this->repository->count_filtered( $filter ),
				'has_new'          => $has_new,
				'is_empty'         => empty( $entries ),
				'server_time'      => $server_time,
				'refresh_interval' => $this->get_polling_interval(),
			]
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the filter value if it is one of the allowed slugs, otherwise
	 * falls back to FILTER_TODAY.
	 *
	 * @param string $raw Raw value from $_POST.
	 * @return string
	 */
	private function sanitize_filter( string $raw ): string {
		$allowed = [
			Order_Repository::FILTER_TODAY,
			Order_Repository::FILTER_LAST_24,
			Order_Repository::FILTER_ALL,
		];

		return \in_array( $raw, $allowed, true ) ? $raw : Order_Repository::FILTER_TODAY;
	}
}
