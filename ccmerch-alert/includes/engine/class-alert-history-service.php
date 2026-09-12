<?php
/**
 * Alert History Service.
 *
 * @package CCMerchAlert\Engine
 */

namespace CCMerchAlert\Engine;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Business-logic layer for reading and paginating a creator's alert history.
 *
 * Fetches alert events grouped by WooCommerce order_id so the Creator Portal
 * can display "one order = one row" with expand-to-detail for individual items.
 * All queries are strictly scoped to a single creator_id — cross-creator
 * contamination is impossible even when an order spans multiple creators.
 *
 * Pagination operates at the ORDER level (not the event level): "page 1" shows
 * the most recent PER_PAGE distinct WooCommerce orders, regardless of how many
 * alert events those orders produced.
 *
 * Must never: register WordPress hooks.
 * Must never: render HTML.
 * Must never: perform ownership validation (that is Replay_Service's job).
 * Must never: return data belonging to a different creator.
 */
final class Alert_History_Service {

	/** Number of order groups shown per history page. */
	public const PER_PAGE = 10;

	/**
	 * @param Alert_Event_Repository $repository Alert event data access layer.
	 */
	public function __construct(
		private readonly Alert_Event_Repository $repository,
	) {}

	/**
	 * Returns one page of order groups for a creator's alert history.
	 *
	 * Each group represents one WooCommerce order and contains summary metadata:
	 * order_id, order_number, customer_first_name, alert_count, created_at,
	 * latest_created_at. Individual alert events are NOT included — use
	 * get_events_for_order() when the user expands a row.
	 *
	 * @param  int $creator_id Creator profile primary key.
	 * @param  int $page       1-indexed page number.
	 * @return array[] Array of order group associative arrays.
	 */
	public function get_order_groups( int $creator_id, int $page ): array {
		$page   = \max( 1, $page );
		$offset = ( $page - 1 ) * self::PER_PAGE;

		return $this->repository->get_order_groups_for_creator( $creator_id, self::PER_PAGE, $offset );
	}

	/**
	 * Returns the total number of distinct orders in a creator's alert history.
	 *
	 * @param  int $creator_id Creator profile primary key.
	 * @return int
	 */
	public function get_total_orders( int $creator_id ): int {
		return $this->repository->count_order_groups_for_creator( $creator_id );
	}

	/**
	 * Returns the total number of pages for a creator's order history.
	 *
	 * @param  int $creator_id Creator profile primary key.
	 * @return int Always at least 1.
	 */
	public function get_total_pages( int $creator_id ): int {
		$total = $this->get_total_orders( $creator_id );

		return \max( 1, (int) \ceil( $total / self::PER_PAGE ) );
	}

	/**
	 * Returns all Alert Events for a creator's specific order, in purchase sequence.
	 *
	 * The creator_id + order_id filter ensures only this creator's items are
	 * returned even when the WooCommerce order contains products from multiple
	 * creators. Cross-creator contamination is impossible at the query level.
	 *
	 * @param  int $creator_id Creator profile primary key.
	 * @param  int $order_id   WooCommerce order ID.
	 * @return Alert_Event[]   Ordered by created_at ASC, id ASC (original purchase sequence).
	 */
	public function get_events_for_order( int $creator_id, int $order_id ): array {
		return $this->repository->get_events_for_creator_order( $creator_id, $order_id );
	}
}
