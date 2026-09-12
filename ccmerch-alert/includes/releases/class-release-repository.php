<?php
/**
 * Release Repository — all SQL for the ccmerch_releases table.
 *
 * @package CCMerchAlert\Releases
 * @feature release_manager
 */

namespace CCMerchAlert\Releases;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access layer for wp_ccmerch_releases.
 *
 * Write API: create(), update(), delete().
 * Read API:  get_all(), get_by_id(), get_latest().
 *
 * All filtering (by stage, by feature tag) is expressed here rather than in
 * callers. Feature-tag filtering is applied in PHP after the DB query because
 * the tags are stored as a JSON column (not a junction table) — no LIKE query.
 *
 * Must never: contain business or routing logic.
 * Must never: be called from overlay or portal rendering paths.
 */
final class Release_Repository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ccmerch_releases';
	}

	/**
	 * Returns all releases, newest first.
	 *
	 * @param string|null $stage       Filter to one stage slug; null = all.
	 * @param string|null $feature_tag Filter to releases that include this tag.
	 * @return Release[]
	 */
	public function get_all( ?string $stage = null, ?string $feature_tag = null ): array {
		global $wpdb;

		if ( null !== $stage ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'SELECT * FROM ' . $this->table() . ' WHERE stage = %s ORDER BY created_at DESC',
					$stage
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( 'SELECT * FROM ' . $this->table() . ' ORDER BY created_at DESC' );
		}

		$releases = \array_map( [ $this, 'row_to_release' ], (array) $rows );

		if ( null !== $feature_tag ) {
			$releases = \array_values(
				\array_filter(
					$releases,
					static fn( Release $r ) => \in_array( $feature_tag, $r->feature_tags, true )
				)
			);
		}

		return $releases;
	}

	public function get_by_id( int $id ): ?Release {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT * FROM ' . $this->table() . ' WHERE id = %d',
				$id
			)
		);

		return null !== $row ? $this->row_to_release( $row ) : null;
	}

	public function get_latest(): ?Release {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( 'SELECT * FROM ' . $this->table() . ' ORDER BY created_at DESC LIMIT 1' );

		return null !== $row ? $this->row_to_release( $row ) : null;
	}

	/**
	 * Inserts a new release row.
	 *
	 * @param array{ version: string, stage: string, title: string, description: string, feature_tags: string[] } $data
	 * @return int|false Inserted row ID, or false on failure.
	 */
	public function create( array $data ): int|false {
		global $wpdb;

		$now = \gmdate( 'Y-m-d H:i:s' );

		$result = $wpdb->insert(
			$this->table(),
			[
				'version'      => $data['version'],
				'stage'        => $data['stage'],
				'title'        => $data['title'],
				'description'  => $data['description'],
				'feature_tags' => (string) \wp_json_encode( $data['feature_tags'] ?? [] ),
				'created_at'   => $now,
				'updated_at'   => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $result ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[Release_Repository::create] insert failed — ' . $wpdb->last_error ); // phpcs:ignore
			}
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates an existing release.
	 *
	 * @param int   $id   Release ID.
	 * @param array $data Same shape as create() data argument.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			$this->table(),
			[
				'version'      => $data['version'],
				'stage'        => $data['stage'],
				'title'        => $data['title'],
				'description'  => $data['description'],
				'feature_tags' => (string) \wp_json_encode( $data['feature_tags'] ?? [] ),
				'updated_at'   => \gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	public function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( $this->table(), [ 'id' => $id ], [ '%d' ] );
	}

	private function row_to_release( object $row ): Release {
		$tags = \json_decode( (string) ( $row->feature_tags ?? '[]' ), true );
		if ( ! \is_array( $tags ) ) {
			$tags = [];
		}

		return new Release(
			id:           (int) $row->id,
			version:      (string) $row->version,
			stage:        (string) $row->stage,
			title:        (string) $row->title,
			description:  (string) $row->description,
			feature_tags: $tags,
			created_at:   new \DateTimeImmutable( (string) $row->created_at, new \DateTimeZone( 'UTC' ) ),
			updated_at:   new \DateTimeImmutable( (string) $row->updated_at, new \DateTimeZone( 'UTC' ) ),
		);
	}
}
