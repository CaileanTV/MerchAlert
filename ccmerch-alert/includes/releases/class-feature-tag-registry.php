<?php
/**
 * Feature Tag Registry — canonical set of all feature tag slugs.
 *
 * @package CCMerchAlert\Releases
 * @feature release_manager
 */

namespace CCMerchAlert\Releases;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for every feature tag slug used across the plugin.
 *
 * TAGS maps slug → human label. Only slugs present in TAGS may appear in
 * release feature_tags columns. Free-text tags are never accepted.
 *
 * To add a new tag: append an entry to TAGS. No other file requires modification.
 *
 * Must never: be instantiated — all methods are static.
 * Must never: accept tag slugs not present in TAGS (enforced by filter_valid()).
 */
final class Feature_Tag_Registry {

	/**
	 * All known feature tags, sorted alphabetically by slug.
	 *
	 * @var array<string, string>
	 */
	public const TAGS = [
		'alert_templates'  => 'Alert Templates',
		'audio_isolation'  => 'Audio Isolation',
		'audio_upload'     => 'Audio Upload',
		'browser_source'   => 'Browser Source',
		'changelog_system' => 'Changelog System',
		'creator_portal'   => 'Creator Portal',
		'dashboard'        => 'Dashboard',
		'order_log'        => 'Order Log',
		'overlay_designer' => 'Overlay Designer',
		'release_manager'  => 'Release Manager',
		'routing_engine'   => 'Routing Engine',
	];

	public static function is_valid( string $tag ): bool {
		return \array_key_exists( $tag, self::TAGS );
	}

	public static function label( string $tag ): string {
		return self::TAGS[ $tag ] ?? $tag;
	}

	/** Strips any slug not present in TAGS; re-indexes the result. */
	public static function filter_valid( array $tags ): array {
		return \array_values( \array_filter( $tags, [ self::class, 'is_valid' ] ) );
	}
}
