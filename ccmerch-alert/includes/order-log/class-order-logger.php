<?php
/**
 * Order Logger.
 *
 * @package CCMerchAlert\OrderLog
 */

namespace CCMerchAlert\OrderLog;

use CCMerchAlert\Engine\Alert_Router;
use CCMerchAlert\Integrations\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens to WooCommerce order status hooks and persists new orders to the log.
 *
 * Hooks attached:
 *  - woocommerce_order_status_processing — covers most paid orders (card, PayPal).
 *  - woocommerce_order_status_completed  — catches virtual/downloadable orders that
 *    skip processing and go directly to completed.
 *
 * Duplicate prevention: Order_Repository::exists() is checked first. If the
 * order is already logged (e.g. because the status changed from processing to
 * completed), the hook returns early without inserting a second row. The UNIQUE
 * KEY on order_id in the database table is a secondary safety net.
 *
 * After a new order is saved, Alert_Router::process_order() is called to route
 * each line item to its creator's alert event queue. Routing only fires when
 * the save succeeded (no duplicate, no DB error) and an Alert_Router was
 * injected (optional — defaults to null for safety).
 *
 * All WooCommerce API calls are guarded by WooCommerce::is_active(), so this
 * class does nothing when WooCommerce is not present.
 */
final class Order_Logger {

	/**
	 * WooCommerce integration module.
	 *
	 * @var WooCommerce
	 */
	private WooCommerce $woocommerce;

	/**
	 * Order log repository.
	 *
	 * @var Order_Repository
	 */
	private Order_Repository $repository;

	/**
	 * Alert routing engine — null when not injected.
	 *
	 * @var Alert_Router|null
	 */
	private ?Alert_Router $alert_router;

	/**
	 * @param WooCommerce      $woocommerce  WooCommerce integration module.
	 * @param Order_Repository $repository   Order log repository.
	 * @param Alert_Router|null $alert_router Alert routing engine (optional for
	 *                                        backwards compatibility; always passed
	 *                                        in production via Plugin::load_components()).
	 */
	public function __construct( WooCommerce $woocommerce, Order_Repository $repository, ?Alert_Router $alert_router = null ) {
		$this->woocommerce  = $woocommerce;
		$this->repository   = $repository;
		$this->alert_router = $alert_router;

		if ( ! $this->woocommerce->is_active() ) {
			return;
		}

		add_action( 'woocommerce_order_status_processing', [ $this, 'log_order' ] );
		add_action( 'woocommerce_order_status_completed',  [ $this, 'log_order' ] );
	}

	// -------------------------------------------------------------------------
	// Hook callbacks
	// -------------------------------------------------------------------------

	/**
	 * Logs a WooCommerce order to the Order Log if not already present,
	 * then routes each line item to its creator's alert queue.
	 *
	 * Both status hooks (processing, completed) call this method, so the first
	 * matching status change wins. Subsequent changes for the same order are
	 * discarded after the exists() check.
	 *
	 * Alert routing (Alert_Router::process_order) is called only when:
	 *  - The order was not already in the log (exists() returned false).
	 *  - Order_Repository::save() succeeded (returned true).
	 *  - An Alert_Router was injected into the constructor.
	 *
	 * @param int $order_id WooCommerce order ID passed by the hook.
	 * @return void
	 */
	public function log_order( int $order_id ): void {
		if ( $this->repository->exists( $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$date_created = $order->get_date_created();

		$order_data = [
			'order_id'     => $order->get_id(),
			'order_number' => $order->get_order_number(),
			'order_date'   => $date_created instanceof \WC_DateTime
				? $date_created->date( 'Y-m-d H:i:s' )
				: \gmdate( 'Y-m-d H:i:s' ),
			'first_name'   => $order->get_billing_first_name(),
			'logged_at'    => \gmdate( 'Y-m-d H:i:s' ),
		];

		$items_data = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id   = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();
			$product      = $item->get_product();
			$sku          = $product instanceof \WC_Product ? $product->get_sku() : '';

			$thumbnail_id = get_post_thumbnail_id( $product_id );
			$image_id     = $thumbnail_id ? (int) $thumbnail_id : 0;

			$raw_cats     = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
			$category_ids = ( ! is_wp_error( $raw_cats ) && \is_array( $raw_cats ) )
				? \array_values( $raw_cats )
				: [];

			$items_data[] = [
				'item_id'             => (int) $item->get_id(), // WC order item ID — used by Alert_Router for traceability.
				'product_id'          => $product_id,
				'variation_id'        => $variation_id > 0 ? $variation_id : 0,
				'product_name'        => $item->get_name(),
				'product_sku'         => $sku,
				'quantity'            => (int) $item->get_quantity(),
				'image_attachment_id' => $image_id,
				'category_ids'        => $category_ids,
			];
		}

		$saved = $this->repository->save( $order_data, $items_data );

		// Route each item to its creator's alert queue. Only fires when the save
		// succeeded — prevents routing phantom events for failed inserts.
		if ( $saved && null !== $this->alert_router ) {
			$this->alert_router->process_order( $order_id, $order_data, $items_data );
		}
	}
}
