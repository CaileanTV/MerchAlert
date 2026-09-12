<?php
/**
 * Order Log item value object.
 *
 * @package CCMerchAlert\OrderLog
 */

namespace CCMerchAlert\OrderLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable snapshot of a single line item within a logged order.
 *
 * Populated by {@see Order_Repository} from the {prefix}ccmerch_order_log_items
 * table. Stores only IDs for image, category, and variation data — never
 * URLs, names, or labels — so display data is always resolved dynamically
 * from the current WordPress/WooCommerce state.
 *
 * Conventions:
 *  - $variation_id === null  → simple product (not a variation).
 *  - $image_attachment_id === null  → product has no featured image.
 *  - $category_ids === []    → product belongs to no categories.
 *
 * These nulls are stored as 0 in the database and converted to null on read
 * by {@see Order_Repository} to keep the PHP API expressive.
 */
final class Order_Log_Item {

	/**
	 * @param int      $id                  Primary key in ccmerch_order_log_items.
	 * @param int      $log_id              Foreign key to ccmerch_order_log.id.
	 * @param int      $product_id          WooCommerce parent product ID.
	 * @param int|null $variation_id        WooCommerce variation ID, or null for simple products.
	 * @param string   $product_name        Product name as it appeared at purchase time.
	 * @param string   $product_sku         Product SKU as it appeared at purchase time.
	 * @param int      $quantity            Number of units purchased.
	 * @param int|null $image_attachment_id WordPress attachment ID of the product's featured image.
	 * @param int[]    $category_ids        WordPress term IDs for the product's categories.
	 */
	public function __construct(
		public readonly int     $id,
		public readonly int     $log_id,
		public readonly int     $product_id,
		public readonly ?int    $variation_id,
		public readonly string  $product_name,
		public readonly string  $product_sku,
		public readonly int     $quantity,
		public readonly ?int    $image_attachment_id,
		public readonly array   $category_ids,
	) {}
}
