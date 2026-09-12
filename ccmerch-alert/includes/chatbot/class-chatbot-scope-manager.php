<?php
/**
 * Chatbot OAuth scope manager.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for all Twitch OAuth scope definitions.
 *
 * Required scopes are locked and cannot be disabled. Optional scopes can be
 * toggled by admins via the Chatbot admin page. Only enabled scopes are
 * included in the OAuth authorization URL.
 *
 * Must never be instantiated — all methods are static.
 */
final class Chatbot_Scope_Manager {

	/** @var string wp_options key for the persisted optional-scope config. */
	public const OPTION_KEY = 'ccmerch_chatbot_scopes';

	/**
	 * Twitch scopes required for the bot account to send chat messages.
	 * These are always included and cannot be disabled.
	 *
	 * @var string[]
	 */
	public const REQUIRED_BOT_SCOPES = [
		'user:write:chat',
		'user:bot',
	];

	/**
	 * Twitch scopes required from the broadcaster's channel.
	 * These are always included and cannot be disabled.
	 *
	 * @var string[]
	 */
	public const REQUIRED_CREATOR_SCOPES = [
		'channel:bot',
	];

	/**
	 * Optional bot-account scopes that can be enabled by the admin.
	 *
	 * Future expansion pattern:
	 * [
	 *   'moderator:manage:banned_users' => 'Timeout / ban users in chat',
	 *   'channel:moderate'               => 'Moderate channel events',
	 * ]
	 *
	 * @var array<string, string> slug => human label
	 */
	public const OPTIONAL_BOT_SCOPES = [];

	// -------------------------------------------------------------------------
	// Scope string getters
	// -------------------------------------------------------------------------

	/**
	 * Returns the space-separated scope string for the bot account OAuth flow.
	 *
	 * Combines required scopes with any enabled optional scopes from the stored config.
	 *
	 * @return string
	 */
	public static function get_bot_scopes(): string {
		$scopes = self::REQUIRED_BOT_SCOPES;

		if ( ! empty( self::OPTIONAL_BOT_SCOPES ) ) {
			$config = get_option( self::OPTION_KEY, [] );

			if ( \is_array( $config ) ) {
				foreach ( \array_keys( self::OPTIONAL_BOT_SCOPES ) as $slug ) {
					if ( ! empty( $config[ $slug ] ) ) {
						$scopes[] = $slug;
					}
				}
			}
		}

		return \implode( ' ', $scopes );
	}

	/**
	 * Returns the space-separated scope string for the creator channel OAuth flow.
	 *
	 * @return string
	 */
	public static function get_creator_scopes(): string {
		return \implode( ' ', self::REQUIRED_CREATOR_SCOPES );
	}

	// -------------------------------------------------------------------------
	// Scope validation
	// -------------------------------------------------------------------------

	/**
	 * Returns true when the granted scopes satisfy all required bot scopes.
	 *
	 * @param string[] $granted Scopes as returned by the Twitch validate endpoint.
	 * @return bool
	 */
	public static function validate_bot_scopes( array $granted ): bool {
		foreach ( self::REQUIRED_BOT_SCOPES as $required ) {
			if ( ! \in_array( $required, $granted, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns true when the granted scopes satisfy all required creator scopes.
	 *
	 * @param string[] $granted Scopes as returned by the Twitch validate endpoint.
	 * @return bool
	 */
	public static function validate_creator_scopes( array $granted ): bool {
		foreach ( self::REQUIRED_CREATOR_SCOPES as $required ) {
			if ( ! \in_array( $required, $granted, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns true when the desired bot scopes differ from the currently-granted
	 * scopes, meaning re-authorization is required.
	 *
	 * @param string[] $granted_scopes Currently-granted scopes for the bot account.
	 * @return bool
	 */
	public static function bot_needs_reauth( array $granted_scopes ): bool {
		$desired = \explode( ' ', self::get_bot_scopes() );
		foreach ( $desired as $scope ) {
			if ( ! \in_array( $scope, $granted_scopes, true ) ) {
				return true;
			}
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Persistence
	// -------------------------------------------------------------------------

	/**
	 * Persists the optional-scope configuration.
	 *
	 * Only keys present in OPTIONAL_BOT_SCOPES are stored; unknown keys are
	 * silently dropped.
	 *
	 * @param array<string, mixed> $config Map of scope slug => truthy/falsy.
	 * @return void
	 */
	public static function save_scope_config( array $config ): void {
		$clean = [];
		foreach ( \array_keys( self::OPTIONAL_BOT_SCOPES ) as $slug ) {
			$clean[ $slug ] = ! empty( $config[ $slug ] );
		}
		update_option( self::OPTION_KEY, $clean );
	}
}
