<?php
/**
 * Creator Repository.
 *
 * @package CCMerchAlert\Creators
 */

namespace CCMerchAlert\Creators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All SQL operations for the Creator Profile tables.
 *
 * Responsibilities:
 *  - CRUD for ccmerch_creator_profiles
 *  - Category assignment handling for ccmerch_creator_category_assignments
 *  - Lookup by category term ID (for future alert routing)
 *  - Validation helpers (is_category_taken)
 *
 * Must never: contain business rules — those live in Creator_Service.
 * Must never: render HTML. Must never: be called from admin rendering classes
 * directly — route through Creator_Service instead.
 */
final class Creator_Repository {

	/** @var string Profiles table name including $wpdb prefix. */
	private string $profiles_table;

	/** @var string Assignments table name including $wpdb prefix. */
	private string $assignments_table;

	public function __construct() {
		global $wpdb;
		$this->profiles_table    = $wpdb->prefix . 'ccmerch_creator_profiles';
		$this->assignments_table = $wpdb->prefix . 'ccmerch_creator_category_assignments';
	}

	// -------------------------------------------------------------------------
	// Read API
	// -------------------------------------------------------------------------

	/**
	 * Returns all creator profiles, ordered by creation date descending.
	 *
	 * @return Creator_Profile[]
	 */
	public function find_all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT * FROM {$this->profiles_table} ORDER BY created_at DESC" );

		return $this->rows_to_profiles( $rows );
	}

	/**
	 * Returns a single creator by primary key, or null if not found.
	 *
	 * @param  int $id Primary key.
	 * @return Creator_Profile|null
	 */
	public function find_by_id( int $id ): ?Creator_Profile {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->profiles_table} WHERE id = %d", $id )
		);

		if ( null === $row ) {
			return null;
		}

		return $this->row_to_profile( $row );
	}

	/**
	 * Returns the WooCommerce category term IDs assigned to a creator.
	 *
	 * @param  int $creator_id Creator profile primary key.
	 * @return int[]
	 */
	public function get_category_ids( int $creator_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT wc_category_term_id FROM {$this->assignments_table} WHERE creator_id = %d",
				$creator_id
			)
		);

		if ( ! \is_array( $rows ) ) {
			return [];
		}

		return \array_map( static fn ( $r ) => (int) $r->wc_category_term_id, $rows );
	}

	/**
	 * Returns the creator assigned to a specific WooCommerce category term ID.
	 *
	 * Primary use: order → category → creator routing in the future Alert Engine.
	 * When multiple assignments exist for the same term (possible after the
	 * uniqueness restriction is lifted), the most recently assigned creator wins.
	 *
	 * @param  int $term_id WooCommerce product_cat term ID.
	 * @return Creator_Profile|null
	 */
	public function find_by_category_term_id( int $term_id ): ?Creator_Profile {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$creator_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT creator_id FROM {$this->assignments_table}
				 WHERE wc_category_term_id = %d
				 ORDER BY id DESC
				 LIMIT 1",
				$term_id
			)
		);

		if ( null === $creator_id ) {
			return null;
		}

		return $this->find_by_id( (int) $creator_id );
	}

	/**
	 * Checks whether a WooCommerce category term ID is already assigned to any creator.
	 *
	 * Pass $exclude_creator_id when editing an existing creator so the creator
	 * can keep its current category without triggering a false uniqueness error.
	 *
	 * @param  int      $term_id            WooCommerce product_cat term ID.
	 * @param  int|null $exclude_creator_id Creator ID to exclude from the check.
	 * @return bool True when the term is already claimed by another creator.
	 */
	public function is_category_taken( int $term_id, ?int $exclude_creator_id = null ): bool {
		global $wpdb;

		if ( null !== $exclude_creator_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->assignments_table}
					 WHERE wc_category_term_id = %d AND creator_id != %d",
					$term_id,
					$exclude_creator_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->assignments_table}
					 WHERE wc_category_term_id = %d",
					$term_id
				)
			);
		}

		return $count > 0;
	}

	// -------------------------------------------------------------------------
	// Write API
	// -------------------------------------------------------------------------

	/**
	 * Inserts a new creator profile and returns its new primary key.
	 *
	 * The UUID and access token are passed in from the service layer, which is
	 * responsible for generating cryptographically secure values before calling
	 * this method.
	 *
	 * @param  string $creator_name         Display name.
	 * @param  string $description          Optional description.
	 * @param  string $status               'active' or 'disabled'.
	 * @param  string $creator_uuid         UUID v4 stable identifier.
	 * @param  string $creator_access_token 64-char hex portal access token.
	 * @return int|false New primary key on success, false on DB error.
	 */
	public function create( string $creator_name, string $description, string $status, string $creator_uuid, string $creator_access_token ): int|false {
		global $wpdb;

		$now = \gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$this->profiles_table,
			[
				'creator_uuid'         => $creator_uuid,
				'creator_access_token' => $creator_access_token,
				'creator_name'         => $creator_name,
				'description'          => $description,
				'status'               => $status,
				'created_at'           => $now,
				'updated_at'           => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $result ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log( 'CCMerch Alert [Creator_Repository::create] insert failed — ' . $wpdb->last_error );
			}
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Returns the creator profile with the given access token, or null if absent.
	 *
	 * Used by Creator_Portal_Controller to validate portal URL requests without
	 * exposing the internal primary key in the URL.
	 *
	 * @param  string $token 64-char hex access token from the URL.
	 * @return Creator_Profile|null
	 */
	public function find_by_access_token( string $token ): ?Creator_Profile {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->profiles_table} WHERE creator_access_token = %s LIMIT 1",
				$token
			)
		);

		if ( null === $row ) {
			return null;
		}

		return $this->row_to_profile( $row );
	}

	/**
	 * Replaces the access token for an existing creator profile.
	 *
	 * Called by Creator_Service::reset_access_token() when an admin requests a
	 * new portal link. The previous link is immediately invalidated.
	 *
	 * @param  int    $id    Creator profile primary key.
	 * @param  string $token New 64-char hex access token.
	 * @return bool True on success.
	 */
	public function update_access_token( int $id, string $token ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			$this->profiles_table,
			[
				'creator_access_token' => $token,
				'updated_at'           => \gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Updates an existing creator profile's editable fields.
	 *
	 * @param  int    $id           Primary key.
	 * @param  string $creator_name New display name.
	 * @param  string $description  New description.
	 * @param  string $status       New status.
	 * @return bool True on success.
	 */
	public function update( int $id, string $creator_name, string $description, string $status ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			$this->profiles_table,
			[
				'creator_name' => $creator_name,
				'description'  => $description,
				'status'       => $status,
				'updated_at'   => \gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Deletes a creator profile and all its category assignments.
	 *
	 * Assignment removal happens first so no orphaned assignment rows remain
	 * if the profiles delete fails.
	 *
	 * @param  int $id Primary key.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$this->remove_category_assignments( $id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete( $this->profiles_table, [ 'id' => $id ], [ '%d' ] );

		return false !== $result;
	}

	/**
	 * Assigns a WooCommerce category term ID to a creator.
	 *
	 * Does not enforce uniqueness — call is_category_taken() via Creator_Service first.
	 *
	 * @param  int $creator_id          Creator profile primary key.
	 * @param  int $wc_category_term_id WooCommerce product_cat term ID.
	 * @return bool True on success.
	 */
	public function assign_category( int $creator_id, int $wc_category_term_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$this->assignments_table,
			[
				'creator_id'          => $creator_id,
				'wc_category_term_id' => $wc_category_term_id,
				'created_at'          => \gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%d', '%d', '%s' ]
		);

		return false !== $result;
	}

	/**
	 * Removes all category assignments for a creator.
	 *
	 * Called before re-assigning a category (edit flow) and inside delete().
	 *
	 * @param  int $creator_id Creator profile primary key.
	 * @return bool True on success.
	 */
	public function remove_category_assignments( int $creator_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete(
			$this->assignments_table,
			[ 'creator_id' => $creator_id ],
			[ '%d' ]
		);

		return false !== $result;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Maps raw $wpdb rows to Creator_Profile value objects.
	 *
	 * @param  mixed $rows Result from $wpdb->get_results(), or null.
	 * @return Creator_Profile[]
	 */
	private function rows_to_profiles( mixed $rows ): array {
		if ( ! \is_array( $rows ) || empty( $rows ) ) {
			return [];
		}

		return \array_map( [ $this, 'row_to_profile' ], $rows );
	}

	/**
	 * Maps a single stdClass row to a Creator_Profile value object.
	 *
	 * @param  \stdClass $row Database row.
	 * @return Creator_Profile
	 */
	private function row_to_profile( \stdClass $row ): Creator_Profile {
		$tz = new \DateTimeZone( 'UTC' );

		return new Creator_Profile(
			(int) $row->id,
			(string) $row->creator_uuid,
			(string) $row->creator_access_token,
			(string) $row->creator_name,
			(string) $row->description,
			(string) $row->status,
			new \DateTimeImmutable( (string) $row->created_at, $tz ),
			new \DateTimeImmutable( (string) $row->updated_at, $tz ),
		);
	}
}
