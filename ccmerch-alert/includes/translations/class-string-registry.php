<?php
/**
 * String Registry — boot-time store for all translatable string definitions.
 *
 * @package CCMerchAlert\Translations
 */

namespace CCMerchAlert\Translations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static registry that holds every translatable string definition.
 *
 * String definitions are registered at boot time via register() and never
 * change for the lifetime of a request. No database reads are performed here.
 *
 * Must never: be instantiated.
 * Must never: read from or write to the database.
 * Must never: change a definition once it has been registered.
 */
final class String_Registry {

	/** @var array<string, array{key:string,default_text:string,context:string}> */
	private static array $strings = [];

	/**
	 * Registers a translatable string definition.
	 *
	 * @param string $key          Dot-notation identifier, e.g. 'checkout.username.label'.
	 * @param string $default_text The English default shown when no override exists.
	 * @param string $context      Grouping slug used for filtering in the admin UI.
	 */
	public static function register( string $key, string $default_text, string $context ): void {
		self::$strings[ $key ] = [
			'key'          => $key,
			'default_text' => $default_text,
			'context'      => $context,
		];
	}

	/**
	 * Returns the full definition for a key, or null if not registered.
	 *
	 * @param  string $key
	 * @return array{key:string,default_text:string,context:string}|null
	 */
	public static function get_definition( string $key ): ?array {
		return self::$strings[ $key ] ?? null;
	}

	/**
	 * Returns all registered definitions, keyed by their string key.
	 *
	 * @return array<string, array{key:string,default_text:string,context:string}>
	 */
	public static function all(): array {
		return self::$strings;
	}

	/**
	 * Returns the registered default text for a key, or the key itself as fallback.
	 *
	 * @param  string $key
	 * @return string
	 */
	public static function default_for( string $key ): string {
		return self::$strings[ $key ]['default_text'] ?? $key;
	}

	/**
	 * Returns a sorted, deduplicated list of all registered context slugs.
	 *
	 * @return string[]
	 */
	public static function contexts(): array {
		$contexts = \array_unique( \array_column( self::$strings, 'context' ) );
		\sort( $contexts );
		return $contexts;
	}
}
