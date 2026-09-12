<?php
/**
 * Alert Router.
 *
 * @package CCMerchAlert\Engine
 */

namespace CCMerchAlert\Engine;

use CCMerchAlert\Creators\Creator_Overlay_Repository;
use CCMerchAlert\Creators\Creator_Profile;
use CCMerchAlert\Creators\Creator_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes each WooCommerce order item to its creator's alert event queue.
 *
 * Called by Order_Logger immediately after a new order is saved to the Order Log.
 * For every line item the router:
 *
 *  1. Iterates the item's WooCommerce category term IDs in order.
 *  2. Looks up the first Creator Profile assigned to any of those categories.
 *     (First matching category wins — see process_item() for the documented rule.)
 *  3. Fetches the creator's overlay_id from the overlay settings table.
 *  4. Creates a fully-resolved Alert_Event and writes it to the queue.
 *
 * After process_order() returns, no further routing, category lookups, or
 * WooCommerce API calls ever occur for these items. The overlay poll reads the
 * already-resolved queue and renders it directly.
 *
 * "Resolve once. Store once. Render many."
 *
 * Items whose categories do not match any creator assignment are silently
 * skipped — they belong to the global overlay or no overlay at all.
 *
 * Cross-creator contamination is impossible by design: each Alert_Event stores
 * creator_id and overlay_id at creation time. The poll endpoint reads from the
 * queue filtered strictly by overlay_id — events from other creators never
 * appear in a different creator's queue.
 *
 * Must never: render HTML.
 * Must never: be called from the overlay poll path.
 * Must never: perform its own WooCommerce API calls (all product data arrives
 * pre-resolved in the $items_data array from Order_Logger).
 */
final class Alert_Router {

	/**
	 * @param Creator_Repository         $creator_repository         Category → creator profile lookup.
	 * @param Creator_Overlay_Repository $creator_overlay_repository Creator → overlay_id lookup.
	 * @param Alert_Event_Repository     $alert_event_repository     Destination alert queue.
	 */
	public function __construct(
		private readonly Creator_Repository $creator_repository,
		private readonly Creator_Overlay_Repository $creator_overlay_repository,
		private readonly Alert_Event_Repository $alert_event_repository,
	) {}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Routes every line item in a WooCommerce order to its creator's queue.
	 *
	 * Called once per new order, immediately after Order_Repository::save().
	 * Items with no matching creator assignment are silently skipped.
	 *
	 * @param int                             $order_id   WooCommerce order ID.
	 * @param array<string, mixed>            $order_data Order header: order_number, first_name, etc.
	 * @param array<int, array<string, mixed>> $items_data One array per line item (same format as
	 *                                                     Order_Logger passes to Order_Repository).
	 * @return void
	 */
	public function process_order( int $order_id, array $order_data, array $items_data ): void {
		foreach ( $items_data as $item ) {
			$this->process_item( $order_id, $order_data, $item );
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Routes a single order item to a creator queue if a matching creator exists.
	 *
	 * Category matching rule:
	 *  - The item's category_ids are iterated in the order WooCommerce returns them
	 *    (typically term creation-date order, oldest first).
	 *  - The FIRST category that has a creator assignment wins.
	 *  - If a product belongs to multiple categories mapped to DIFFERENT creators,
	 *    only ONE Alert_Event is created (for the first matching creator). This is
	 *    the intentional v1 behaviour — it prevents duplicate alerts for multi-
	 *    category products. Future versions can introduce explicit priority rules.
	 *  - If a product belongs to multiple categories all mapped to the SAME creator,
	 *    only ONE Alert_Event is created (first match, immediate return).
	 *
	 * @param int                  $order_id   WooCommerce order ID.
	 * @param array<string, mixed> $order_data Order header data (order_number, first_name).
	 * @param array<string, mixed> $item       Single line item from Order_Logger.
	 * @return void
	 */
	private function process_item( int $order_id, array $order_data, array $item ): void {
		$item_id     = (int) ( $item['item_id'] ?? 0 );
		$category_ids = (array) $item['category_ids'];

		$creator = $this->match_creator( $category_ids );

		if ( null === $creator ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log( \sprintf(
					'CCMerch Alert [Alert_Router] order %d item %d skipped — no creator assigned to categories [%s].',
					$order_id,
					$item_id,
					\implode( ', ', $category_ids )
				) );
			}
			return;
		}

		$overlay_row = $this->creator_overlay_repository->find_by_creator_id( $creator->id );

		if ( null === $overlay_row ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log( \sprintf(
					'CCMerch Alert [Alert_Router] order %d item %d skipped — creator %d has no overlay profile row.',
					$order_id,
					$item_id,
					$creator->id
				) );
			}
			return;
		}

		$overlay_id = (string) ( $overlay_row['overlay_id'] ?? '' );

		if ( '' === $overlay_id ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log( \sprintf(
					'CCMerch Alert [Alert_Router] order %d item %d skipped — creator %d overlay_id is empty (OBS URL not generated yet).',
					$order_id,
					$item_id,
					$creator->id
				) );
			}
			return;
		}

		// Idempotency check: skip if an event already exists for this order item
		// + overlay pair. This prevents duplicates when the WC hook fires more
		// than once for the same order or when a race condition causes two
		// concurrent requests to both pass the Order_Logger::exists() guard.
		// The UNIQUE KEY on (order_item_id, overlay_id) in the DB is a secondary
		// safety net — this check is the primary guard at the application layer.
		if ( $this->alert_event_repository->event_exists( $item_id, $overlay_id ) ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log( \sprintf(
					'CCMerch Alert [Alert_Router] order %d item %d skipped — duplicate event already exists for overlay %s.',
					$order_id,
					$item_id,
					$overlay_id
				) );
			}
			return;
		}

		$enqueued = $this->alert_event_repository->enqueue( [
			'creator_id'                   => $creator->id,
			'creator_uuid'                 => $creator->creator_uuid,
			'overlay_id'                   => $overlay_id,
			'order_id'                     => $order_id,
			'order_number'                 => (string) $order_data['order_number'],
			'order_item_id'                => $item_id,
			'customer_first_name'          => (string) $order_data['first_name'],
			'quantity'                     => (int) $item['quantity'],
			'product_id'                   => (int) $item['product_id'],
			'variation_id'                 => (int) $item['variation_id'],
			'product_name'                 => (string) $item['product_name'],
			'featured_image_attachment_id' => (int) $item['image_attachment_id'],
			'category_term_ids'            => $category_ids,
			'created_at'                   => \gmdate( 'Y-m-d H:i:s' ),
		] );

		if ( $enqueued && \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( \sprintf(
				'CCMerch Alert [Alert_Router] order %d item %d → creator %d overlay %s — event enqueued.',
				$order_id,
				$item_id,
				$creator->id,
				$overlay_id
			) );
		}
	}

	/**
	 * Returns the first Creator_Profile whose category assignment matches any
	 * of the given WooCommerce product_cat term IDs, or null when no match exists.
	 *
	 * Term IDs are checked in the order provided. The first non-null result from
	 * Creator_Repository::find_by_category_term_id() is returned immediately.
	 *
	 * @param  int[] $term_ids WooCommerce product_cat term IDs.
	 * @return Creator_Profile|null
	 */
	private function match_creator( array $term_ids ): ?Creator_Profile {
		foreach ( $term_ids as $term_id ) {
			$creator = $this->creator_repository->find_by_category_term_id( (int) $term_id );
			if ( null !== $creator ) {
				return $creator;
			}
		}
		return null;
	}
}
