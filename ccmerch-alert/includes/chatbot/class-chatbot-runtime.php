<?php
/**
 * Chatbot WP-Cron runtime orchestrator.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules and drives all background maintenance for the Twitch chatbot.
 *
 * Registers a single hourly WP-Cron event (`ccmerch_chatbot_runtime_token_check`)
 * that validates the bot token and updates health metrics. The runtime is
 * activated on plugin activation and deactivated on plugin deactivation.
 *
 * Must never contain message delivery or OAuth flow logic.
 * Must never be instantiated more than once.
 */
final class Chatbot_Runtime {

	/** @var string WP-Cron hook name for the hourly token check. */
	public const HOOK_TOKEN_CHECK = 'ccmerch_chatbot_runtime_token_check';

	/** @var Chatbot_Token_Manager */
	private Chatbot_Token_Manager $token_manager;

	/**
	 * @param Chatbot_Token_Manager $token_manager Token validation + refresh service.
	 */
	public function __construct( Chatbot_Token_Manager $token_manager ) {
		$this->token_manager = $token_manager;
	}

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Registers the WP-Cron handler and schedules the event if not already scheduled.
	 *
	 * Called unconditionally from Plugin::load_components() so the hook is always
	 * registered regardless of admin/front-end context.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::HOOK_TOKEN_CHECK, [ $this, 'run_token_check' ] );
		$this->maybe_schedule();
	}

	/**
	 * Clears all scheduled cron events.
	 *
	 * Called from the plugin deactivation hook in ccmerch-alert.php.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::HOOK_TOKEN_CHECK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK_TOKEN_CHECK );
		}
	}

	// -------------------------------------------------------------------------
	// Cron handler
	// -------------------------------------------------------------------------

	/**
	 * Runs the token maintenance check.
	 *
	 * Invoked by WP-Cron. All failures are caught and logged silently —
	 * this must never throw or affect any other WordPress operation.
	 *
	 * @return void
	 */
	public function run_token_check(): void {
		try {
			$this->token_manager->run_maintenance();
		} catch ( \Throwable $e ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch Chatbot Runtime] Token check failed: ' . $e->getMessage() );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Schedules the hourly cron event if it is not already scheduled.
	 *
	 * @return void
	 */
	private function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK_TOKEN_CHECK ) ) {
			wp_schedule_event( \time(), 'hourly', self::HOOK_TOKEN_CHECK );
		}
	}
}
