<?php
/**
 * Translation Store — persistent storage for string overrides.
 *
 * @package CCMerchAlert\Translations
 */

namespace CCMerchAlert\Translations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lazy-loading store backed by a single wp_options row.
 *
 * Only overrides are stored — strings equal to their registered default are
 * deleted to keep the option compact and avoid storing redundant data.
 *
 * The in-process cache (`$cache`) is a single flat load per request. Call
 * `invalidate()` if you need to force a fresh DB read within the same request
 * (e.g. in unit tests or after an external update_option call).
 *
 * Must never: be instantiated.
 * Must never: register WordPress hooks.
 */
final class Translation_Store {

	public const OPTION_KEY = 'ccmerch_translations';

	/** @var array<string, array<string, string>>|null In-process request cache. */
	private static ?array $cache = null;

	/**
	 * Returns the stored override for a key/language pair, or null when absent.
	 *
	 * @param  string $key  String key.
	 * @param  string $lang Language code, e.g. 'en'.
	 * @return string|null
	 */
	public static function get( string $key, string $lang = 'en' ): ?string {
		$data  = self::load();
		$value = $data[ $lang ][ $key ] ?? null;
		return ( null !== $value ) ? (string) $value : null;
	}

	/**
	 * Persists an override for a key/language pair.
	 *
	 * @param  string $key
	 * @param  string $value
	 * @param  string $lang
	 * @return bool
	 */
	public static function set( string $key, string $value, string $lang = 'en' ): bool {
		$data = self::load();
		if ( ! \is_array( $data[ $lang ] ?? null ) ) {
			$data[ $lang ] = [];
		}
		$data[ $lang ][ $key ] = $value;
		$ok = (bool) update_option( self::OPTION_KEY, $data, false );
		if ( $ok ) {
			self::$cache = $data;
		}
		return $ok;
	}

	/**
	 * Removes a stored override for a key/language pair.
	 *
	 * @param  string $key
	 * @param  string $lang
	 * @return bool
	 */
	public static function delete( string $key, string $lang = 'en' ): bool {
		$data = self::load();
		if ( ! isset( $data[ $lang ][ $key ] ) ) {
			return true; // Already absent — no-op.
		}
		unset( $data[ $lang ][ $key ] );
		$ok = (bool) update_option( self::OPTION_KEY, $data, false );
		if ( $ok ) {
			self::$cache = $data;
		}
		return $ok;
	}

	/**
	 * Loads and caches the full translations option from the database.
	 *
	 * The result is cached for the lifetime of the request so repeated calls
	 * within one page load incur only one DB read.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function load(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPTION_KEY, [] );
			self::$cache = \is_array( $raw ) ? $raw : [];
		}
		return self::$cache;
	}

	/**
	 * Clears the in-process cache, forcing the next get/set/load to re-read the DB.
	 */
	public static function invalidate(): void {
		self::$cache = null;
	}
}
