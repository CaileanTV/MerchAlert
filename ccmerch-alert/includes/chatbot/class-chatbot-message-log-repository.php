<?php
/**
 * Chatbot Message Log repository.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All SQL for the {prefix}ccmerch_chatbot_messages table.
 *
 * This table answers exactly one question: "has a chatbot message already
 * been generated for this creator + WooCommerce order item?" It is consulted
 * by Chatbot_Message_Service::send_for_events() — the single shared sender
 * used by both live orders and Creator Portal replay — so that:
 *  - A line item that already has a logged message is not logged a second
 *    time (no duplicate record), but is still sent/replayed every time
 *    send_for_events() is called for it.
 *  - A line item that has no logged message yet (most commonly: an order
 *    placed before the Beta 2.0.2 one-message-per-product fix, where only
 *    the first line item of the order was ever actually sent) gets one
 *    created the moment it is next sent — live or via replay.
 *
 * order_item_id is the same WooCommerce order item identifier Alert_Event
 * uses (WC_Order_Item::get_id()) — the reliable, stable identifier tying one
 * row here to exactly one line item, never derived from message text.
 *
 * Must never: contain business logic. Must never be called from rendering classes.
 */
final class Chatbot_Message_Log_Repository {

	/** @var string Fully-qualified table name including $wpdb prefix. */
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'ccmerch_chatbot_messages';
	}

	/**
	 * Returns true when a chatbot message has already been logged for this
	 * creator + WooCommerce order item.
	 *
	 * @param  int $creator_id    Creator profile primary key.
	 * @param  int $order_item_id WooCommerce order item ID (Alert_Event::order_item_id).
	 * @return bool
	 */
	public function exists( int $creator_id, int $order_item_id ): bool {
		if ( $creator_id <= 0 || $order_item_id <= 0 ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE creator_id = %d AND order_item_id = %d LIMIT 1",
				$creator_id,
				$order_item_id
			)
		);
	}

	/**
	 * Records that a chatbot message was generated for this creator + order item.
	 *
	 * Safe to call defensively even when a row might already exist — the
	 * UNIQUE KEY on (creator_id, order_item_id) rejects the duplicate insert
	 * at the database layer, matching the same two-layer idempotency guard
	 * pattern used by Alert_Router / Alert_Event (application check + DB
	 * unique key).
	 *
	 * @param  int    $creator_id    Creator profile primary key.
	 * @param  int    $order_id      WooCommerce order ID.
	 * @param  int    $order_item_id WooCommerce order item ID.
	 * @param  string $product_name  Product name at the time the message was logged.
	 * @return bool True on success (including "already existed").
	 */
	public function record( int $creator_id, int $order_id, int $order_item_id, string $product_name ): bool {
		if ( $creator_id <= 0 || $order_item_id <= 0 ) {
			return false;
		}

		if ( $this->exists( $creator_id, $order_item_id ) ) {
			return true;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$this->table,
			[
				'creator_id'    => $creator_id,
				'order_id'      => $order_id,
				'order_item_id' => $order_item_id,
				'product_name'  => $product_name,
				'created_at'    => \gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%d', '%d', '%d', '%s', '%s' ]
		);

		if ( false === $result ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log( 'CCMerch Alert [Chatbot_Message_Log_Repository::record] insert failed — ' . $wpdb->last_error );
			}
			// A duplicate-key rejection here still means the state we want (a
			// logged row for this creator + order item) already exists.
			return $this->exists( $creator_id, $order_item_id );
		}

		return true;
	}
}
