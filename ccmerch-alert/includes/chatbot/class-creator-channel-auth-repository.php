<?php
/**
 * Creator channel authorization repository.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All SQL for the {prefix}ccmerch_creator_channel_auth table.
 *
 * Stores and retrieves creator-specific Twitch channel:bot authorization
 * records. Access tokens are encrypted at rest using AES-256-CBC with a key
 * derived from wp_salt('auth'). The same key derivation is used by
 * Twitch_Auth_Service for consistency.
 *
 * Must never contain business or routing logic.
 * Must never be called from overlay or portal rendering paths.
 */
final class Creator_Channel_Auth_Repository {

	/** @var string Unprefixed table name. */
	private const TABLE = 'ccmerch_creator_channel_auth';

	// -------------------------------------------------------------------------
	// Read API
	// -------------------------------------------------------------------------

	/**
	 * Returns the authorization record for a creator, or null if none exists.
	 *
	 * @param int $creator_id Creator profile ID.
	 * @return Creator_Channel_Auth|null
	 */
	public function find_by_creator_id( int $creator_id ): ?Creator_Channel_Auth {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE creator_id = %d LIMIT 1", $creator_id ),
			ARRAY_A
		);

		return $row ? $this->row_to_auth( $row ) : null;
	}

	/**
	 * Returns all authorization records.
	 *
	 * @return Creator_Channel_Auth[]
	 */
	public function get_all(): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY creator_id ASC", ARRAY_A );

		if ( ! \is_array( $rows ) ) {
			return [];
		}

		return \array_map( [ $this, 'row_to_auth' ], $rows );
	}

	/**
	 * Returns the decrypted access_token + refresh_token for a creator.
	 *
	 * Returns null when no row exists or when decryption fails.
	 *
	 * @param int $creator_id Creator profile ID.
	 * @return array{access_token: string, refresh_token: string}|null
	 */
	public function get_tokens( int $creator_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT access_token, refresh_token FROM {$table} WHERE creator_id = %d LIMIT 1", $creator_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$access  = $this->decrypt( (string) $row['access_token'] );
		$refresh = $this->decrypt( (string) $row['refresh_token'] );

		if ( '' === $access && '' === $refresh ) {
			return null;
		}

		return [
			'access_token'  => $access,
			'refresh_token' => $refresh,
		];
	}

	// -------------------------------------------------------------------------
	// Write API
	// -------------------------------------------------------------------------

	/**
	 * Inserts or updates the authorization record for a creator (upsert).
	 *
	 * @param int    $creator_id    Creator profile ID.
	 * @param string $channel_login Twitch channel login name (lowercase).
	 * @param string $access_token  Plaintext access token — encrypted before storage.
	 * @param string $refresh_token Plaintext refresh token — encrypted before storage.
	 * @param string $scope         Space-separated OAuth scopes that were granted.
	 * @return bool True on success.
	 */
	public function save(
		int    $creator_id,
		string $channel_login,
		string $access_token,
		string $refresh_token,
		string $scope
	): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		$now   = \gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
					(creator_id, channel_login, access_token, refresh_token, scope, authorized_at, updated_at)
				VALUES (%d, %s, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
					channel_login = VALUES(channel_login),
					access_token  = VALUES(access_token),
					refresh_token = VALUES(refresh_token),
					scope         = VALUES(scope),
					updated_at    = VALUES(updated_at)",
				$creator_id,
				\strtolower( $channel_login ),
				$this->encrypt( $access_token ),
				$this->encrypt( $refresh_token ),
				$scope,
				$now,
				$now
			)
		);

		return false !== $result;
	}

	/**
	 * Updates the stored tokens for a creator (used during token refresh).
	 *
	 * @param int    $creator_id    Creator profile ID.
	 * @param string $access_token  Plaintext access token — encrypted before storage.
	 * @param string $refresh_token Plaintext refresh token — encrypted before storage.
	 * @return bool True on success.
	 */
	public function update_tokens( int $creator_id, string $access_token, string $refresh_token ): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			$table,
			[
				'access_token'  => $this->encrypt( $access_token ),
				'refresh_token' => $this->encrypt( $refresh_token ),
				'updated_at'    => \gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'creator_id' => $creator_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Deletes the authorization record for a creator.
	 *
	 * @param int $creator_id Creator profile ID.
	 * @return bool True on success.
	 */
	public function delete_by_creator_id( int $creator_id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete( $table, [ 'creator_id' => $creator_id ], [ '%d' ] );

		return false !== $result;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Maps a raw DB row (ARRAY_A) to a Creator_Channel_Auth value object.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 * @return Creator_Channel_Auth
	 */
	private function row_to_auth( array $row ): Creator_Channel_Auth {
		return new Creator_Channel_Auth(
			id:            (int) $row['id'],
			creator_id:    (int) $row['creator_id'],
			channel_login: (string) $row['channel_login'],
			scope:         (string) $row['scope'],
			authorized_at: (string) $row['authorized_at'],
			updated_at:    (string) $row['updated_at'],
		);
	}

	/**
	 * Encrypts a plaintext string using AES-256-CBC.
	 *
	 * Key derived from wp_salt('auth'). IV prepended to ciphertext before base64 encoding.
	 * Same key derivation as Twitch_Auth_Service so both use wp_salt consistently.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Encrypted, base64-encoded string (or empty on failure).
	 */
	private function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		$key = $this->get_encryption_key();
		$iv  = \openssl_random_pseudo_bytes( 16 );
		$enc = \openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $enc ) {
			return '';
		}
		return \base64_encode( $iv . $enc );
	}

	/**
	 * Decrypts a base64-encoded AES-256-CBC ciphertext.
	 *
	 * @param string $ciphertext Encrypted, base64-encoded string.
	 * @return string Decrypted plaintext (or empty on failure).
	 */
	private function decrypt( string $ciphertext ): string {
		if ( '' === $ciphertext ) {
			return '';
		}
		$key  = $this->get_encryption_key();
		$data = \base64_decode( $ciphertext, true );
		if ( false === $data || \strlen( $data ) < 17 ) {
			return '';
		}
		$iv  = \substr( $data, 0, 16 );
		$enc = \substr( $data, 16 );
		$dec = \openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return false !== $dec ? $dec : '';
	}

	/**
	 * Derives the 32-byte AES key from wp_salt('auth').
	 *
	 * @return string 32-byte binary key.
	 */
	private function get_encryption_key(): string {
		return \substr( \hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
	}
}
