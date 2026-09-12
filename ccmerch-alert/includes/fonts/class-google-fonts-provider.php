<?php
/**
 * Google Fonts catalog provider.
 *
 * @package CCMerchAlert\Fonts
 */

namespace CCMerchAlert\Fonts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Downloads and stores the Google Fonts catalog locally.
 *
 * The Creator Portal NEVER communicates directly with Google Fonts —
 * all font data is served from the local {prefix}ccmerch_google_fonts table.
 *
 * Must never: render HTML.
 * Must never: be instantiated more than once.
 */
final class Google_Fonts_Provider {

	/** @var string Google Fonts Developer API v1 endpoint. */
	private const API_URL = 'https://www.googleapis.com/webfonts/v1/webfonts?key=%s&sort=popularity';

	/** @var int Maximum HTTP response timeout for the catalog fetch. */
	private const HTTP_TIMEOUT = 30;

	/** @var int Maximum fonts per batch INSERT to avoid query size limits. */
	private const BATCH_SIZE = 100;

	// -------------------------------------------------------------------------
	// Sync
	// -------------------------------------------------------------------------

	/**
	 * Downloads the full Google Fonts catalog and upserts it into the local DB.
	 *
	 * Each existing row is updated in place (variants, category, subsets,
	 * last_synced_at). New font families are inserted with approved = 0 so the
	 * admin must explicitly approve them before creators can select them. The
	 * approved flag on existing rows is never touched during sync.
	 *
	 * @param  string $api_key Google Fonts Developer API key.
	 * @return array{count: int, error: string} count = 0 on failure; error = '' on success.
	 */
	public function sync( string $api_key ): array {
		$api_key = \trim( $api_key );

		if ( '' === $api_key ) {
			return [ 'count' => 0, 'error' => 'Google Fonts API key is not configured.' ];
		}

		$items = $this->fetch_catalog( $api_key );

		if ( false === $items ) {
			return [ 'count' => 0, 'error' => 'Failed to fetch the Google Fonts catalog. Check your API key and try again.' ];
		}

		if ( empty( $items ) ) {
			return [ 'count' => 0, 'error' => 'Google Fonts returned an empty catalog.' ];
		}

		$this->upsert_batch( $items );

		return [ 'count' => \count( $items ), 'error' => '' ];
	}

	// -------------------------------------------------------------------------
	// Read
	// -------------------------------------------------------------------------

	/**
	 * Searches the local catalog by family name (case-insensitive), with an
	 * optional category filter.
	 *
	 * @param  string $query         Partial family name to match.
	 * @param  bool   $approved_only Return only admin-approved fonts.
	 * @param  int    $limit         Maximum number of results (1–500).
	 * @param  string $category      When non-empty, restrict to this category slug.
	 * @return array<int, array{family: string, category: string}>
	 */
	public function search( string $query, bool $approved_only = false, int $limit = 50, string $category = '' ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'ccmerch_google_fonts';
		$limit = \max( 1, \min( 500, $limit ) );
		$like  = '%' . $wpdb->esc_like( \trim( $query ) ) . '%';

		$clauses = [ $wpdb->prepare( 'family LIKE %s', $like ) ];
		if ( $approved_only ) {
			$clauses[] = 'approved = 1';
		}
		if ( '' !== \trim( $category ) ) {
			$clauses[] = $wpdb->prepare( 'category = %s', \trim( $category ) );
		}

		$where = 'WHERE ' . \implode( ' AND ', $clauses );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			"SELECT family, category FROM {$table} {$where} ORDER BY family ASC LIMIT {$limit}",
			ARRAY_A
		);

		return \is_array( $rows ) ? $rows : [];
	}

	/**
	 * Returns all fonts in the catalog, optionally filtered to approved-only,
	 * and sorted by the requested mode.
	 *
	 * Sort modes:
	 *   'alpha'      — A → Z by family name (default)
	 *   'alpha_desc' — Z → A by family name
	 *   'popularity' — ascending sort_order (1 = most popular)
	 *   'newest'     — descending last_modified date string
	 *
	 * @param  bool   $approved_only When true, returns only fonts with approved = 1.
	 * @param  string $sort_by       Sort mode: 'alpha', 'alpha_desc', 'popularity', 'newest'.
	 * @return array<int, array{id: int, family: string, category: string, approved: bool, sort_order: int, last_modified: string, last_synced_at: string}>
	 */
	public function get_all( bool $approved_only = false, string $sort_by = 'alpha' ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'ccmerch_google_fonts';
		$where = $approved_only ? 'WHERE approved = 1' : '';

		$order = match ( $sort_by ) {
			'alpha_desc' => 'ORDER BY family DESC',
			'popularity' => 'ORDER BY sort_order ASC, family ASC',
			'newest'     => 'ORDER BY last_modified DESC, family ASC',
			default      => 'ORDER BY family ASC',
		};

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			"SELECT id, family, category, approved, sort_order, last_modified, last_synced_at FROM {$table} {$where} {$order}",
			ARRAY_A
		);

		if ( ! \is_array( $rows ) ) {
			return [];
		}

		return \array_map(
			static fn( array $r ) => [
				'id'            => (int) $r['id'],
				'family'        => (string) $r['family'],
				'category'      => (string) $r['category'],
				'approved'      => (bool) (int) $r['approved'],
				'sort_order'    => (int) $r['sort_order'],
				'last_modified' => (string) $r['last_modified'],
				'last_synced_at' => (string) $r['last_synced_at'],
			],
			$rows
		);
	}

	/**
	 * Returns the total count of fonts in the catalog.
	 *
	 * @param  bool $approved_only Count only approved fonts.
	 * @return int
	 */
	public function get_count( bool $approved_only = false ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'ccmerch_google_fonts';
		$where = $approved_only ? 'WHERE approved = 1' : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
	}

	/**
	 * Returns the UTC datetime string of the most recent sync, or null.
	 *
	 * @return string|null
	 */
	public function get_last_synced(): ?string {
		global $wpdb;

		$table = $wpdb->prefix . 'ccmerch_google_fonts';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var( "SELECT MAX(last_synced_at) FROM {$table}" );

		return '' !== (string) $value ? (string) $value : null;
	}

	/**
	 * Sets the approved flag for a single font family.
	 *
	 * @param  string $family   Font family name.
	 * @param  bool   $approved New approved state.
	 * @return bool Whether the update touched at least one row.
	 */
	public function set_approved( string $family, bool $approved ): bool {
		global $wpdb;

		$result = $wpdb->update(
			$wpdb->prefix . 'ccmerch_google_fonts',
			[ 'approved' => $approved ? 1 : 0 ],
			[ 'family'   => $family ],
			[ '%d' ],
			[ '%s' ]
		);

		return false !== $result && $result > 0;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetches the catalog from the Google Fonts API.
	 *
	 * The API returns items in popularity order when sort=popularity is used,
	 * so the 0-based array index + 1 gives the popularity rank.
	 *
	 * @param  string $api_key Valid API key.
	 * @return array<int, array{family: string, category: string, variants: string[], subsets: string[], sort_order: int, last_modified: string}>|false
	 *         Array of font item arrays, or false on failure.
	 */
	private function fetch_catalog( string $api_key ): array|false {
		$url      = \sprintf( self::API_URL, rawurlencode( $api_key ) );
		$response = wp_remote_get(
			$url,
			[
				'timeout'    => self::HTTP_TIMEOUT,
				'user-agent' => 'CCMerchAlert/' . CCMERCH_ALERT_VERSION,
			]
		);

		if ( is_wp_error( $response ) ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch Fonts] Catalog fetch failed: ' . $response->get_error_message() );
			}
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = \json_decode( $body, true );

		if ( ! \is_array( $data ) || empty( $data['items'] ) || ! \is_array( $data['items'] ) ) {
			return false;
		}

		$items = [];
		foreach ( $data['items'] as $index => $item ) {
			if ( empty( $item['family'] ) ) {
				continue;
			}
			$items[] = [
				'family'        => (string) $item['family'],
				'category'      => (string) ( $item['category'] ?? '' ),
				'variants'      => \is_array( $item['variants'] ?? null ) ? $item['variants'] : [],
				'subsets'       => \is_array( $item['subsets']  ?? null ) ? $item['subsets']  : [],
				'sort_order'    => $index + 1,
				'last_modified' => (string) ( $item['lastModified'] ?? '' ),
			];
		}

		return $items;
	}

	/**
	 * Upserts font rows into the local table in batches.
	 *
	 * INSERT ... ON DUPLICATE KEY UPDATE preserves the existing `approved` flag.
	 *
	 * @param  array<int, array{family: string, category: string, variants: string[], subsets: string[], sort_order: int, last_modified: string}> $items
	 * @return void
	 */
	private function upsert_batch( array $items ): void {
		global $wpdb;

		$table    = $wpdb->prefix . 'ccmerch_google_fonts';
		$now      = \gmdate( 'Y-m-d H:i:s' );
		$batches  = \array_chunk( $items, self::BATCH_SIZE );

		foreach ( $batches as $batch ) {
			$placeholders = [];
			$values       = [];

			foreach ( $batch as $item ) {
				$placeholders[] = '(%s, %s, %s, %s, %d, %s, %s)';
				$values[]       = $item['family'];
				$values[]       = $item['category'];
				$values[]       = \wp_json_encode( $item['variants'] );
				$values[]       = \wp_json_encode( $item['subsets'] );
				$values[]       = $item['sort_order'];
				$values[]       = $item['last_modified'];
				$values[]       = $now;
			}

			$sql = "INSERT INTO {$table} (family, category, variants, subsets, sort_order, last_modified, last_synced_at)
				VALUES " . \implode( ', ', $placeholders ) . "
				ON DUPLICATE KEY UPDATE
					category       = VALUES(category),
					variants       = VALUES(variants),
					subsets        = VALUES(subsets),
					sort_order     = VALUES(sort_order),
					last_modified  = VALUES(last_modified),
					last_synced_at = VALUES(last_synced_at)";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( $sql, ...$values ) );
		}
	}
}
