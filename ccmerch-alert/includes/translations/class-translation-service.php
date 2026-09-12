<?php
/**
 * Translation Service — resolution façade used throughout the plugin.
 *
 * @package CCMerchAlert\Translations
 */

namespace CCMerchAlert\Translations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service layer that resolves a string key to its effective display value.
 *
 * Resolution order (first non-empty value wins):
 *   1. Stored override for the active language in Translation_Store.
 *   2. Registered default text from String_Registry.
 *   3. The key itself as an absolute fallback.
 *
 * The global `ccmerch_translate( $key )` helper defined in ccmerch-alert.php
 * delegates to `Translation_Service::t()`.
 *
 * `save()` and `reset()` normalise storage: if the saved value equals the
 * registered default the override row is deleted to keep the option compact.
 *
 * `to_js_array()` produces an associative array of resolved values suitable
 * for `wp_json_encode()` and injection as a JS variable (`ccmerchStrings`).
 *
 * Must never: be instantiated.
 * Must never: register WordPress hooks.
 */
final class Translation_Service {

	private static string $lang = 'en';

	/**
	 * Resolves a string key to its effective display value.
	 *
	 * This is the method called by the global `ccmerch_translate()` helper.
	 *
	 * @param  string $key
	 * @return string
	 */
	public static function t( string $key ): string {
		$override = Translation_Store::get( $key, self::$lang );
		if ( null !== $override && '' !== $override ) {
			return $override;
		}
		return String_Registry::default_for( $key );
	}

	/**
	 * Sets the active language for all subsequent t() calls.
	 *
	 * @param string $lang Language code (e.g. 'en', 'da').
	 */
	public static function set_language( string $lang ): void {
		self::$lang = $lang;
	}

	/**
	 * Returns the current active language code.
	 *
	 * @return string
	 */
	public static function current_language(): string {
		return self::$lang;
	}

	/**
	 * Persists a user-supplied override for a string key.
	 *
	 * Returns false when the key is not registered. Trims the value; if the
	 * trimmed result equals the registered default (or is empty), the override
	 * is deleted rather than stored.
	 *
	 * @param  string $key
	 * @param  string $value
	 * @param  string $lang  Empty string uses the active language.
	 * @return bool
	 */
	public static function save( string $key, string $value, string $lang = '' ): bool {
		if ( ! String_Registry::get_definition( $key ) ) {
			return false;
		}
		$lang  = '' !== $lang ? $lang : self::$lang;
		$value = \trim( $value );
		if ( '' === $value || $value === String_Registry::default_for( $key ) ) {
			return Translation_Store::delete( $key, $lang );
		}
		return Translation_Store::set( $key, $value, $lang );
	}

	/**
	 * Removes any stored override for a key, restoring the registered default.
	 *
	 * @param  string $key
	 * @param  string $lang  Empty string uses the active language.
	 * @return bool
	 */
	public static function reset( string $key, string $lang = '' ): bool {
		$lang = '' !== $lang ? $lang : self::$lang;
		return Translation_Store::delete( $key, $lang );
	}

	/**
	 * Returns all registered strings enriched with their current override status.
	 *
	 * Used to populate the Translation Manager admin table.
	 *
	 * @param  string $lang
	 * @return array<int, array{key:string,context:string,default_text:string,current_value:string,is_overridden:bool}>
	 */
	public static function all_for_admin( string $lang = 'en' ): array {
		$result = [];
		foreach ( String_Registry::all() as $key => $def ) {
			$override = Translation_Store::get( $key, $lang );
			$result[] = [
				'key'          => $key,
				'context'      => $def['context'],
				'default_text' => $def['default_text'],
				'current_value' => $override ?? '',
				'is_overridden' => null !== $override,
			];
		}
		return $result;
	}

	/**
	 * Resolves a list of keys to their effective values for JS injection.
	 *
	 * Pass the result to `wp_json_encode()` and output as `var ccmerchStrings = {...}`.
	 *
	 * @param  string[] $keys
	 * @param  string   $lang  Empty string uses the active language.
	 * @return array<string, string>
	 */
	public static function to_js_array( array $keys, string $lang = '' ): array {
		$lang   = '' !== $lang ? $lang : self::$lang;
		$result = [];
		foreach ( $keys as $key ) {
			$override       = Translation_Store::get( $key, $lang );
			$result[ $key ] = ( null !== $override && '' !== $override )
				? $override
				: String_Registry::default_for( $key );
		}
		return $result;
	}
}
