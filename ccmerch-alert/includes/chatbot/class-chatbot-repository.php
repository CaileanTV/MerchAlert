<?php
/**
 * Chatbot data repository.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All SQL for the ccmerch_creator_chatbot table.
 *
 * Read API:  find_by_creator_id(), get_all().
 * Write API: save(), set_channel().
 *
 * Must never contain business logic.
 */
final class Chatbot_Repository {

	/** @var string Fully-qualified table name (with WP prefix). */
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'ccmerch_creator_chatbot';
	}

	/**
	 * Finds the chatbot settings row for a creator.
	 *
	 * @param int $creator_id Creator profile ID.
	 * @return Chatbot_Creator_Settings|null Null when no row exists yet.
	 */
	public function find_by_creator_id( int $creator_id ): ?Chatbot_Creator_Settings {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE creator_id = %d LIMIT 1", $creator_id )
		);

		return null === $row ? null : $this->row_to_settings( $row );
	}

	/**
	 * Upserts enabled + template for a creator.
	 *
	 * @param int    $creator_id Creator profile ID.
	 * @param bool   $enabled    Whether the chatbot is active.
	 * @param string $template   Message template.
	 * @return bool True on success.
	 */
	public function save( int $creator_id, bool $enabled, string $template ): bool {
		global $wpdb;

		$now      = \gmdate( 'Y-m-d H:i:s' );
		$existing = $this->find_by_creator_id( $creator_id );

		if ( null === $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->insert(
				$this->table,
				[
					'creator_id'     => $creator_id,
					'twitch_channel' => '',
					'enabled'        => $enabled ? 1 : 0,
					'template'       => $template,
					'created_at'     => $now,
					'updated_at'     => $now,
				],
				[ '%d', '%s', '%d', '%s', '%s', '%s' ]
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->update(
				$this->table,
				[
					'enabled'    => $enabled ? 1 : 0,
					'template'   => $template,
					'updated_at' => $now,
				],
				[ 'creator_id' => $creator_id ],
				[ '%d', '%s', '%s' ],
				[ '%d' ]
			);
		}

		return false !== $result;
	}

	/**
	 * Upserts the Twitch channel for a creator.
	 *
	 * @param int    $creator_id Creator profile ID.
	 * @param string $channel    Twitch channel login name.
	 * @return bool True on success.
	 */
	public function set_channel( int $creator_id, string $channel ): bool {
		global $wpdb;

		$now      = \gmdate( 'Y-m-d H:i:s' );
		$existing = $this->find_by_creator_id( $creator_id );

		if ( null === $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->insert(
				$this->table,
				[
					'creator_id'     => $creator_id,
					'twitch_channel' => $channel,
					'enabled'        => 0,
					'template'       => '',
					'created_at'     => $now,
					'updated_at'     => $now,
				],
				[ '%d', '%s', '%d', '%s', '%s', '%s' ]
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->update(
				$this->table,
				[
					'twitch_channel' => $channel,
					'updated_at'     => $now,
				],
				[ 'creator_id' => $creator_id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
		}

		return false !== $result;
	}

	/**
	 * Returns all chatbot settings rows ordered by creator_id.
	 *
	 * @return Chatbot_Creator_Settings[]
	 */
	public function get_all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT * FROM {$this->table} ORDER BY creator_id ASC" );

		if ( ! \is_array( $rows ) ) {
			return [];
		}

		return \array_map( [ $this, 'row_to_settings' ], $rows );
	}

	/**
	 * Converts a raw DB row to a Chatbot_Creator_Settings value object.
	 *
	 * @param \stdClass $row Raw database row.
	 * @return Chatbot_Creator_Settings
	 */
	private function row_to_settings( \stdClass $row ): Chatbot_Creator_Settings {
		return new Chatbot_Creator_Settings(
			(int)    $row->id,
			(int)    $row->creator_id,
			(string) $row->twitch_channel,
			(bool)   $row->enabled,
			(string) $row->template,
		);
	}
}
