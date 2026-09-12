<?php
/**
 * Order Log entry value object.
 *
 * @package CCMerchAlert\OrderLog
 */

namespace CCMerchAlert\OrderLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable snapshot of a single logged order.
 *
 * Populated by {@see Order_Repository::get_recent()} from the
 * {prefix}ccmerch_order_log table. Carries the order header data and the
 * full set of line items for that order.
 *
 * This is a read model — used only for display and engine evaluation.
 * Write operations always go through Order_Logger → Order_Repository.
 */
final class Order_Log_Entry {

	/**
	 * @param int                    $id           Primary key in ccmerch_order_log.
	 * @param int                    $order_id     WooCommerce order ID.
	 * @param string                 $order_number WooCommerce order number (may differ from ID).
	 * @param \DateTimeImmutable     $order_date   Date/time the order was placed (UTC).
	 * @param string                 $first_name   Customer billing first name.
	 * @param Order_Log_Item[]       $items        All line items belonging to this order.
	 */
	public function __construct(
		public readonly int                $id,
		public readonly int                $order_id,
		public readonly string             $order_number,
		public readonly \DateTimeImmutable $order_date,
		public readonly string             $first_name,
		public readonly array              $items,
	) {}
}
