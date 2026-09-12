<?php
/**
 * Alert Event value object.
 *
 * @package CCMerchAlert\Engine
 */

namespace CCMerchAlert\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable snapshot of a single routed alert for one WooCommerce order item.
 *
 * Created by Alert_Router at order-processing time and stored permanently in
 * the ccmerch_alert_events table. Carries every piece of information needed to
 * render the alert on a creator's overlay without any further database queries,
 * WooCommerce API calls, or routing decisions at runtime.
 *
 * "Resolve once. Store once. Render many."
 *
 * Routing identity fields (creator_id, creator_uuid, overlay_id) are resolved
 * once by Alert_Router when the WooCommerce order status hook fires. After that,
 * the overlay poll reads this snapshot and renders it directly. The runtime NEVER
 * re-routes, re-queries, or recomputes anything from this value.
 *
 * One Alert_Event per order item. An order with N line items produces up to N
 * events (fewer if some items have no creator category assignment).
 *
 * Nullable convention (matches Order_Log_Item):
 *  - variation_id = null means simple product (no variation).
 *  - featured_image_attachment_id = null means no featured image exists.
 *
 * Must never: be mutated after construction.
 * Must never: contain routing, rendering, or dispatch logic.
 * Must never: make database or WooCommerce API calls.
 */
final class Alert_Event {

	/**
	 * @param int      $id                           Primary key (0 before first DB insert).
	 * @param int      $creator_id                   Creator profile primary key.
	 * @param string   $creator_uuid                 Creator's stable public UUID v4 identifier.
	 * @param string   $overlay_id                   32-char hex overlay access token.
	 * @param int      $order_id                     WooCommerce order ID.
	 * @param string   $order_number                 WooCommerce order number (may differ from ID).
	 * @param int      $order_item_id                WooCommerce order item ID (from woocommerce_order_items).
	 * @param string   $customer_first_name          Billing first name at time of purchase.
	 * @param int      $quantity                     Units purchased for this line item.
	 * @param int      $product_id                   Parent product ID (never the variation ID).
	 * @param int|null $variation_id                 Variation ID, or null for simple products.
	 * @param string   $product_name                 Product name at time of purchase.
	 * @param int|null $featured_image_attachment_id Featured image attachment ID, or null.
	 * @param int[]    $category_term_ids            WooCommerce product_cat term IDs at routing time.
	 * @param int      $created_at                   Unix timestamp when this event was routed and stored.
	 * @param int|null $template_id                  Reserved — future per-creator template profile ID.
	 * @param int|null $audio_profile_id             Reserved — future per-creator audio profile ID.
	 * @param int|null $animation_profile_id         Reserved — future per-creator animation profile ID.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $creator_id,
		public readonly string $creator_uuid,
		public readonly string $overlay_id,
		public readonly int $order_id,
		public readonly string $order_number,
		public readonly int $order_item_id,
		public readonly string $customer_first_name,
		public readonly int $quantity,
		public readonly int $product_id,
		public readonly ?int $variation_id,
		public readonly string $product_name,
		public readonly ?int $featured_image_attachment_id,
		public readonly array $category_term_ids,
		public readonly int $created_at,
		public readonly ?int $template_id = null,
		public readonly ?int $audio_profile_id = null,
		public readonly ?int $animation_profile_id = null,
	) {}
}
