<?php
/**
 * Orders model.
 *
 * @package CCMerchAlert\Models
 */

namespace CCMerchAlert\Models;

use CCMerchAlert\Integrations\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access layer for WooCommerce orders relevant to alert monitoring.
 *
 * Depends on {@see WooCommerce} to determine whether WooCommerce is active
 * before attaching any order-related hooks. When WooCommerce is inactive the
 * constructor returns early and no hooks are registered.
 *
 * All WooCommerce query logic is isolated here so that other modules
 * (Admin, Engine) never call WooCommerce functions directly.
 *
 * Instantiated by {@see \CCMerchAlert\Plugin::load_components()} on every request.
 */
final class Orders {

	/**
	 * WooCommerce integration module.
	 *
	 * @var WooCommerce
	 */
	private WooCommerce $woocommerce;

	/**
	 * @param WooCommerce $woocommerce WooCommerce module instance.
	 */
	public function __construct( WooCommerce $woocommerce ) {
		$this->woocommerce = $woocommerce;

		if ( ! $this->woocommerce->is_active() ) {
			return;
		}

		// Future: attach order-monitoring hooks here.
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Returns the number of orders currently being monitored.
	 *
	 * @return int
	 */
	public function get_monitored_count(): int {
		// Placeholder — real query will be added with WooCommerce integration.
		return 0;
	}
}
