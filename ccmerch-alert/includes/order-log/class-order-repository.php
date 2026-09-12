<?php
/**
 * Order Log repository.
 *
 * @package CCMerchAlert\OrderLog
 */

namespace CCMerchAlert\OrderLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes Order Log records in the database.
 *
 * Owns all SQL for the two Order Log tables. No other class queries these
 * tables directly. Incoming data arrives as plain PHP arrays from
 * {@see Order_Logger}; outgoing data is returned as typed value objects
 * ({@see Order_Log_Entry} / {@see Order_Log_Item}).
 *
 * Duplicate prevention: {@see Order_Repository::exists()} checks whether an
 * order is already logged before Order_Logger inserts it. The UNIQUE KEY on
 * order_id provides a secondary safety net at the database level.
 *
 * Storage conventions:
 *  - variation_id and image_attachment_id are stored as BIGINT with 0 meaning
 *    "absent". Converted back to null when building value objects on read.
 *  - category_ids are stored as a JSON-encoded array of integers, or an empty
 *    string when the product has no categories.
 */
final class Order_Repository {

	// -------------------------------------------------------------------------
	// Filter constants
	// -------------------------------------------------------------------------

	/** Show only orders logged on the current calendar day (site timezone). */
	public const FILTER_TODAY   = 'today';

	/** Show orders logged in the rolling 24-hour window ending now. */
	public const FILTER_LAST_24 = 'last_24h';

	/** Show all logged orders with no date constraint. */
	public const FILTER_ALL     = 'all_time';

	/**
	 * @var \wpdb WordPress database abstraction object.
	 */
	private \wpdb $wpdb;

	/**
	 * @var string Fully-qualified name of the order log table.
	 */
	private string $log_table;

	/**
	 * @var string Fully-qualified name of the order log items table.
	 */
	private string $items_table;

	public function __construct() {
		global $wpdb;
		$this->wpdb        = $wpdb;
		$this->log_table   = $wpdb->prefix . 'ccmerch_order_log';
		$this->items_table = $wpdb->prefix . 'ccmerch_order_log_items';
	}

	// -------------------------------------------------------------------------
	// Write API
	// -------------------------------------------------------------------------

	/**
	 * Returns true when the given WooCommerce order ID is already in the log.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return bool
	 */
	public function exists( int $order_id ): bool {
		return (bool) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->log_table} WHERE order_id = %d LIMIT 1",
				$order_id
			)
		);
	}

	/**
	 * Inserts one order and its line items into the log.
	 *
	 * Rolls back silently if the header row fails (e.g. duplicate key on a
	 * concurrent request). Item rows are skipped if no log_id was generated.
	 *
	 * @param array<string,mixed>   $order_data {
	 *     @type int    $order_id     WooCommerce order ID.
	 *     @type string $order_number WooCommerce order number.
	 *     @type string $order_date   UTC datetime string (Y-m-d H:i:s).
	 *     @type string $first_name   Customer billing first name.
	 *     @type string $logged_at    UTC datetime string when logged.
	 * }
	 * @param array<int,array<string,mixed>> $items_data Each element: {
	 *     @type int    $product_id          Parent product ID.
	 *     @type int    $variation_id        Variation product ID, or 0.
	 *     @type string $product_name        Product name.
	 *     @type string $product_sku         Product SKU.
	 *     @type int    $quantity            Units purchased.
	 *     @type int    $image_attachment_id Featured image attachment ID, or 0.
	 *     @type int[]  $category_ids        Category term IDs.
	 * }
	 * @return bool True on success, false if the header row failed.
	 */
	public function save( array $order_data, array $items_data ): bool {
		$inserted = $this->wpdb->insert(
			$this->log_table,
			[
				'order_id'     => (int) $order_data['order_id'],
				'order_number' => (string) $order_data['order_number'],
				'order_date'   => (string) $order_data['order_date'],
				'first_name'   => (string) $order_data['first_name'],
				'logged_at'    => (string) $order_data['logged_at'],
			],
			[ '%d', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			return false;
		}

		$log_id = (int) $this->wpdb->insert_id;

		foreach ( $items_data as $item ) {
			$encoded_cats = ! empty( $item['category_ids'] )
				? wp_json_encode( $item['category_ids'] )
				: '';

			$this->wpdb->insert(
				$this->items_table,
				[
					'log_id'              => $log_id,
					'product_id'          => (int) $item['product_id'],
					'variation_id'        => (int) ( $item['variation_id'] ?? 0 ),
					'product_name'        => (string) $item['product_name'],
					'product_sku'         => (string) $item['product_sku'],
					'quantity'            => (int) $item['quantity'],
					'image_attachment_id' => (int) ( $item['image_attachment_id'] ?? 0 ),
					'category_ids'        => (string) ( $encoded_cats ?: '' ),
				],
				[ '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s' ]
			);
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Read API
	// -------------------------------------------------------------------------

	/**
	 * Returns the count of orders logged today, in the site's configured timezone.
	 *
	 * Delegates to count_filtered() so "today" logic lives in one place.
	 *
	 * @return int
	 */
	public function count_today(): int {
		return $this->count_filtered( self::FILTER_TODAY );
	}

	/**
	 * Returns the number of orders matching a filter slug.
	 *
	 * @param string $filter One of the FILTER_* constants.
	 * @return int
	 */
	public function count_filtered( string $filter ): int {
		[ $start, $end ] = $this->filter_range( $filter );

		if ( null === $start ) {
			// No date constraint — full table count.
			return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->log_table}" );
		}

		if ( null === $end ) {
			return (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->log_table} WHERE logged_at >= %s",
					$start
				)
			);
		}

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->log_table} WHERE logged_at >= %s AND logged_at < %s",
				$start,
				$end
			)
		);
	}

	/**
	 * Returns the most recently logged orders matching a filter, newest first.
	 *
	 * @param string $filter One of the FILTER_* constants.
	 * @param int    $limit  Maximum number of orders to return (1–100).
	 * @return Order_Log_Entry[]
	 */
	public function get_filtered( string $filter, int $limit = 10 ): array {
		$limit          = \max( 1, \min( 100, $limit ) );
		[ $start, $end ] = $this->filter_range( $filter );

		if ( null === $start ) {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->log_table} ORDER BY logged_at DESC LIMIT %d",
					$limit
				),
				ARRAY_A
			);
		} elseif ( null === $end ) {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->log_table} WHERE logged_at >= %s ORDER BY logged_at DESC LIMIT %d",
					$start,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->log_table} WHERE logged_at >= %s AND logged_at < %s ORDER BY logged_at DESC LIMIT %d",
					$start,
					$end,
					$limit
				),
				ARRAY_A
			);
		}

		return $this->rows_to_entries( $rows );
	}

	/**
	 * Returns orders logged after a given UTC timestamp that also match a filter.
	 *
	 * Returns empty array when $since_timestamp is 0 or negative — callers should
	 * use get_filtered() for a full reload instead.
	 *
	 * Results are ordered newest-first so the caller can prepend each card to the
	 * top of the dashboard list without additional sorting.
	 *
	 * @param int    $since_timestamp Unix timestamp; only orders with logged_at > this are returned.
	 * @param string $filter          One of the FILTER_* constants.
	 * @return Order_Log_Entry[]
	 */
	public function get_since( int $since_timestamp, string $filter ): array {
		if ( $since_timestamp <= 0 ) {
			return [];
		}

		$since_dt        = \gmdate( 'Y-m-d H:i:s', $since_timestamp );
		[ $start, $end ] = $this->filter_range( $filter );

		$conditions = [ 'logged_at > %s' ];
		$params     = [ $since_dt ];

		if ( null !== $start ) {
			$conditions[] = 'logged_at >= %s';
			$params[]     = $start;
		}
		if ( null !== $end ) {
			$conditions[] = 'logged_at < %s';
			$params[]     = $end;
		}

		$where = \implode( ' AND ', $conditions );
		$sql   = "SELECT * FROM {$this->log_table} WHERE {$where} ORDER BY logged_at DESC LIMIT 50";

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( $sql, ...$params ),
			ARRAY_A
		);

		return $this->rows_to_entries( $rows );
	}

	/**
	 * Returns the most recently logged orders, newest first.
	 *
	 * Each entry includes its full set of line items.
	 *
	 * @param int $limit Maximum number of orders to return (1–100).
	 * @return Order_Log_Entry[]
	 */
	public function get_recent( int $limit = 10 ): array {
		$limit = \max( 1, \min( 100, $limit ) );

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->log_table} ORDER BY order_date DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( ! \is_array( $rows ) || empty( $rows ) ) {
			return [];
		}

		$entries = [];

		foreach ( $rows as $row ) {
			$log_id     = (int) $row['id'];
			$order_date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $row['order_date'] );

			if ( false === $order_date ) {
				$order_date = new \DateTimeImmutable();
			}

			$entries[] = new Order_Log_Entry(
				id:           $log_id,
				order_id:     (int) $row['order_id'],
				order_number: (string) $row['order_number'],
				order_date:   $order_date,
				first_name:   (string) $row['first_name'],
				items:        $this->get_items_for_log( $log_id ),
			);
		}

		return $entries;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Computes the [start, end] UTC datetime strings for a given filter slug.
	 *
	 * Returns [null, null] for all_time (no constraint).
	 * Returns [start, null] for last_24h (open-ended upper bound).
	 * Returns [start, end] for today (midnight–midnight in site timezone → UTC).
	 *
	 * @param string $filter One of the FILTER_* constants.
	 * @return array{0: string|null, 1: string|null}
	 */
	private function filter_range( string $filter ): array {
		$utc = new \DateTimeZone( 'UTC' );

		if ( self::FILTER_TODAY === $filter ) {
			$tz    = wp_timezone();
			$start = ( new \DateTimeImmutable( 'today midnight', $tz ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
			$end   = ( new \DateTimeImmutable( 'tomorrow midnight', $tz ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
			return [ $start, $end ];
		}

		if ( self::FILTER_LAST_24 === $filter ) {
			$start = \gmdate( 'Y-m-d H:i:s', \time() - 86400 );
			return [ $start, null ];
		}

		return [ null, null ]; // FILTER_ALL — no constraint.
	}

	/**
	 * Converts raw $wpdb result rows into Order_Log_Entry value objects.
	 *
	 * Reused by get_filtered() and get_since() to keep mapping logic in one place.
	 *
	 * @param array<int,array<string,mixed>>|null $rows Raw rows from $wpdb->get_results().
	 * @return Order_Log_Entry[]
	 */
	private function rows_to_entries( ?array $rows ): array {
		if ( ! \is_array( $rows ) || empty( $rows ) ) {
			return [];
		}

		$entries = [];

		foreach ( $rows as $row ) {
			$log_id     = (int) $row['id'];
			$order_date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $row['order_date'] );

			if ( false === $order_date ) {
				$order_date = new \DateTimeImmutable();
			}

			$entries[] = new Order_Log_Entry(
				id:           $log_id,
				order_id:     (int) $row['order_id'],
				order_number: (string) $row['order_number'],
				order_date:   $order_date,
				first_name:   (string) $row['first_name'],
				items:        $this->get_items_for_log( $log_id ),
			);
		}

		return $entries;
	}

	/**
	 * Returns all line items belonging to a single log entry.
	 *
	 * @param int $log_id Primary key in ccmerch_order_log.
	 * @return Order_Log_Item[]
	 */
	private function get_items_for_log( int $log_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->items_table} WHERE log_id = %d",
				$log_id
			),
			ARRAY_A
		);

		if ( ! \is_array( $rows ) || empty( $rows ) ) {
			return [];
		}

		$items = [];

		foreach ( $rows as $row ) {
			$raw_cats    = ! empty( $row['category_ids'] ) ? \json_decode( (string) $row['category_ids'], true ) : [];
			$cat_ids     = \is_array( $raw_cats ) ? $raw_cats : [];
			$variation   = (int) $row['variation_id'];
			$img         = (int) $row['image_attachment_id'];

			$items[] = new Order_Log_Item(
				id:                  (int) $row['id'],
				log_id:              $log_id,
				product_id:          (int) $row['product_id'],
				variation_id:        $variation > 0 ? $variation : null,
				product_name:        (string) $row['product_name'],
				product_sku:         (string) $row['product_sku'],
				quantity:            (int) $row['quantity'],
				image_attachment_id: $img > 0 ? $img : null,
				category_ids:        $cat_ids,
			);
		}

		return $items;
	}
}
