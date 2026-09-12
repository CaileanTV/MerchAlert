<?php
/**
 * Alert Event Repository.
 *
 * @package CCMerchAlert\Engine
 */

namespace CCMerchAlert\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes Alert Events in the per-creator alert queue table.
 *
 * Write path: Alert_Router calls enqueue() once per resolved order item.
 * Read path:  Creator_Overlay_Controller::handle_poll() calls
 *             get_pending_for_overlay() on every poll request.
 *
 * Storage conventions (match Order_Log_Item):
 *  - variation_id == 0               → null on read
 *  - featured_image_attachment_id == 0 → null on read
 *  - category_term_ids == ''         → [] on read
 *  - category_term_ids (non-empty)   → JSON-decoded int[] on read
 *
 * Must never: contain business or routing logic.
 * Must never: be called directly from admin rendering classes.
 * Must never: perform WooCommerce API calls.
 */
final class Alert_Event_Repository {

	/** @var string Fully-qualified table name including $wpdb prefix. */
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'ccmerch_alert_events';
	}

	// -------------------------------------------------------------------------
	// Write API
	// -------------------------------------------------------------------------

	/**
	 * Inserts a fully-resolved Alert Event into the creator's queue.
	 *
	 * All routing decisions have been made by Alert_Router before this call.
	 * Data is stored verbatim — no business logic or validation here.
	 *
	 * @param  array<string, mixed> $data {
	 *     @type int    $creator_id                   Creator profile primary key.
	 *     @type string $creator_uuid                 Creator UUID v4 identifier.
	 *     @type string $overlay_id                   32-char hex overlay access token.
	 *     @type int    $order_id                     WooCommerce order ID.
	 *     @type string $order_number                 WooCommerce order number.
	 *     @type int    $order_item_id                WooCommerce order item ID.
	 *     @type string $customer_first_name          Billing first name.
	 *     @type int    $quantity                     Units purchased.
	 *     @type int    $product_id                   Parent product ID (not variation).
	 *     @type int    $variation_id                 Variation ID, or 0 for simple products.
	 *     @type string $product_name                 Product name.
	 *     @type int    $featured_image_attachment_id Featured image attachment ID, or 0.
	 *     @type int[]  $category_term_ids            WC product_cat term IDs.
	 *     @type string $created_at                   UTC datetime string (Y-m-d H:i:s).
	 * }
	 * @return bool True on success.
	 */
	public function enqueue( array $data ): bool {
		global $wpdb;

		$encoded_cats = ! empty( $data['category_term_ids'] )
			? (string) wp_json_encode( $data['category_term_ids'] )
			: '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$this->table,
			[
				'creator_id'                   => (int) $data['creator_id'],
				'creator_uuid'                 => (string) $data['creator_uuid'],
				'overlay_id'                   => (string) $data['overlay_id'],
				'order_id'                     => (int) $data['order_id'],
				'order_number'                 => (string) $data['order_number'],
				'order_item_id'                => (int) ( $data['order_item_id'] ?? 0 ),
				'customer_first_name'          => (string) $data['customer_first_name'],
				'quantity'                     => (int) $data['quantity'],
				'product_id'                   => (int) $data['product_id'],
				'variation_id'                 => (int) $data['variation_id'],
				'product_name'                 => (string) $data['product_name'],
				'featured_image_attachment_id' => (int) $data['featured_image_attachment_id'],
				'category_term_ids'            => $encoded_cats,
				'created_at'                   => (string) $data['created_at'],
			],
			[ '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s' ]
		);

		if ( false === $result ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log( 'CCMerch Alert [Alert_Event_Repository::enqueue] insert failed — ' . $wpdb->last_error );
			}
		}

		return false !== $result;
	}

	// -------------------------------------------------------------------------
	// Read API
	// -------------------------------------------------------------------------

	/**
	 * Returns true when an Alert Event already exists for this order item + overlay pair.
	 *
	 * Called by Alert_Router::process_item() before enqueue() to enforce the
	 * idempotency rule: one WooCommerce order item + creator overlay → exactly one
	 * Alert Event, regardless of how many times routing is triggered.
	 *
	 * Returns false when order_item_id is 0 (invalid) or overlay_id is empty,
	 * so the UNIQUE KEY constraint remains the last-resort safety net in those
	 * degenerate cases.
	 *
	 * @param  int    $order_item_id WooCommerce order item ID.
	 * @param  string $overlay_id    32-char hex overlay access token.
	 * @return bool True when a matching row already exists.
	 */
	public function event_exists( int $order_item_id, string $overlay_id ): bool {
		if ( $order_item_id <= 0 || '' === $overlay_id ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE order_item_id = %d AND overlay_id = %s LIMIT 1",
				$order_item_id,
				$overlay_id
			)
		);
	}

	/**
	 * Returns all Alert Events for a specific overlay created after the given timestamp.
	 *
	 * Results are ordered oldest-first (chronological purchase sequence) so the
	 * JS queue plays alerts in the order items were purchased, without requiring
	 * client-side reversal. Capped at 50 events per poll — sufficient for any
	 * real-world burst of orders between two poll ticks.
	 *
	 * The overlay_id + created_at composite index makes this query O(log n).
	 *
	 * @param  string $overlay_id      32-char hex overlay access token.
	 * @param  int    $since_timestamp Unix timestamp; only events created after this are returned.
	 * @return Alert_Event[]           Ordered by created_at ASC.
	 */
	public function get_pending_for_overlay( string $overlay_id, int $since_timestamp ): array {
		global $wpdb;

		$since_dt = \gmdate( 'Y-m-d H:i:s', $since_timestamp );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE overlay_id = %s AND created_at >= %s
				 ORDER BY created_at ASC, id ASC
				 LIMIT 50",
				$overlay_id,
				$since_dt
			)
		);

		return $this->rows_to_events( $rows );
	}

	// -------------------------------------------------------------------------
	// History read API
	// -------------------------------------------------------------------------

	/**
	 * Returns paginated order groups for a creator's alert history.
	 *
	 * Each group aggregates all alert events for one WooCommerce order, providing
	 * summary metadata (order_number, customer_first_name, alert_count, timestamps).
	 * Results are ordered by the most recent event in each order, newest first.
	 *
	 * Creator isolation is enforced at the SQL layer via `creator_id = %d`. Events
	 * from other creators that belong to the same WooCommerce order never appear.
	 *
	 * @param  int $creator_id Creator profile primary key.
	 * @param  int $limit      Maximum rows to return (pagination page size).
	 * @param  int $offset     Rows to skip (zero-based).
	 * @return array[] Each element: { order_id, order_number, customer_first_name,
	 *                                 alert_count, created_at, latest_created_at }
	 *                 where created_at and latest_created_at are Unix timestamps.
	 */
	public function get_order_groups_for_creator( int $creator_id, int $limit, int $offset ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					order_id,
					order_number,
					customer_first_name,
					COUNT(*)                    AS alert_count,
					MIN(created_at)             AS first_created_at,
					MAX(created_at)             AS latest_created_at
				FROM {$this->table}
				WHERE creator_id = %d
				GROUP BY order_id, order_number, customer_first_name
				ORDER BY latest_created_at DESC
				LIMIT %d OFFSET %d",
				$creator_id,
				$limit,
				$offset
			)
		);

		if ( ! \is_array( $rows ) || empty( $rows ) ) {
			return [];
		}

		return \array_map(
			static function ( \stdClass $row ): array {
				return [
					'order_id'            => (int) $row->order_id,
					'order_number'        => (string) $row->order_number,
					'customer_first_name' => (string) $row->customer_first_name,
					'alert_count'         => (int) $row->alert_count,
					'created_at'          => (int) \strtotime( (string) $row->first_created_at ),
					'latest_created_at'   => (int) \strtotime( (string) $row->latest_created_at ),
				];
			},
			$rows
		);
	}

	/**
	 * Returns the total number of distinct WooCommerce orders in a creator's history.
	 *
	 * Used for pagination: total_pages = ceil( count / PER_PAGE ).
	 *
	 * @param  int $creator_id Creator profile primary key.
	 * @return int
	 */
	public function count_order_groups_for_creator( int $creator_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT order_id) FROM {$this->table} WHERE creator_id = %d",
				$creator_id
			)
		);

		return (int) $count;
	}

	/**
	 * Returns all Alert Events for a creator's specific WooCommerce order.
	 *
	 * Both creator_id AND order_id are required in the WHERE clause. This ensures
	 * that if Creator A and Creator B both have events for the same WooCommerce order,
	 * Creator A's query returns only Creator A's events — never Creator B's.
	 *
	 * Results are ordered oldest-first (ASC) to match the original purchase sequence.
	 *
	 * @param  int $creator_id Creator profile primary key.
	 * @param  int $order_id   WooCommerce order ID.
	 * @return Alert_Event[]   Ordered by created_at ASC, id ASC.
	 */
	public function get_events_for_creator_order( int $creator_id, int $order_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE creator_id = %d AND order_id = %d
				 ORDER BY created_at ASC, id ASC",
				$creator_id,
				$order_id
			)
		);

		return $this->rows_to_events( $rows );
	}

	/**
	 * Returns a single Alert Event only if it belongs to the given creator.
	 *
	 * The double filter (`id = %d AND creator_id = %d`) is the ownership gate:
	 * an event_id supplied by the client cannot be used to access another creator's
	 * events. Returns null on any mismatch — the caller must treat null as a 403.
	 *
	 * @param  int $event_id   Alert event primary key (untrusted client value).
	 * @param  int $creator_id Authenticated creator profile ID.
	 * @return Alert_Event|null
	 */
	public function get_event_by_id_for_creator( int $event_id, int $creator_id ): ?Alert_Event {
		if ( $event_id <= 0 || $creator_id <= 0 ) {
			return null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d AND creator_id = %d LIMIT 1",
				$event_id,
				$creator_id
			)
		);

		if ( null === $row ) {
			return null;
		}

		return $this->row_to_event( $row );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Maps raw $wpdb rows to Alert_Event value objects.
	 *
	 * @param  mixed $rows Result from $wpdb->get_results(), or null.
	 * @return Alert_Event[]
	 */
	private function rows_to_events( mixed $rows ): array {
		if ( ! \is_array( $rows ) || empty( $rows ) ) {
			return [];
		}

		return \array_map( [ $this, 'row_to_event' ], $rows );
	}

	/**
	 * Maps a single stdClass row to an Alert_Event value object.
	 *
	 * Storage conventions applied:
	 *  - variation_id == 0               → null
	 *  - featured_image_attachment_id == 0 → null
	 *  - category_term_ids == ''         → []
	 *  - template_id / audio_profile_id / animation_profile_id: DB NULL → null
	 *
	 * @param  \stdClass $row Database row.
	 * @return Alert_Event
	 */
	private function row_to_event( \stdClass $row ): Alert_Event {
		$variation_id = (int) $row->variation_id;
		$image_id     = (int) $row->featured_image_attachment_id;

		$cats_raw = (string) $row->category_term_ids;
		if ( '' !== $cats_raw ) {
			$decoded = \json_decode( $cats_raw, true );
			$cats    = \is_array( $decoded ) ? \array_map( 'intval', $decoded ) : [];
		} else {
			$cats = [];
		}

		return new Alert_Event(
			(int) $row->id,
			(int) $row->creator_id,
			(string) $row->creator_uuid,
			(string) $row->overlay_id,
			(int) $row->order_id,
			(string) $row->order_number,
			(int) $row->order_item_id,
			(string) $row->customer_first_name,
			(int) $row->quantity,
			(int) $row->product_id,
			$variation_id > 0 ? $variation_id : null,
			(string) $row->product_name,
			$image_id > 0 ? $image_id : null,
			$cats,
			(int) \strtotime( (string) $row->created_at ),
			isset( $row->template_id )          ? (int) $row->template_id          : null,
			isset( $row->audio_profile_id )     ? (int) $row->audio_profile_id     : null,
			isset( $row->animation_profile_id ) ? (int) $row->animation_profile_id : null,
		);
	}

	/**
	 * Returns all distinct creator IDs that have Alert_Event rows for a given order.
	 *
	 * Used by Chatbot_Message_Service to determine which creators to notify
	 * when a WooCommerce order completes, without adding a dependency on Order_Logger
	 * or querying WooCommerce directly.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return int[] Distinct creator IDs; empty array if no events exist for this order.
	 */
	public function get_creator_ids_for_order( int $order_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT creator_id FROM {$this->table} WHERE order_id = %d",
				$order_id
			)
		);

		return \is_array( $rows ) ? \array_map( 'intval', $rows ) : [];
	}
}
