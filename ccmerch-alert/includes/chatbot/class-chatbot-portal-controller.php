<?php
/**
 * Chatbot Creator Portal AJAX controller.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

use CCMerchAlert\Auth\Auth_Context;
use CCMerchAlert\Creators\Creator_Service;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles AJAX endpoints for creator chatbot settings.
 *
 * Both wp_ajax_nopriv_* AND wp_ajax_* hooks are registered for each action
 * so the endpoints work whether or not the creator happens to also be a
 * logged-in WordPress user (e.g. the site admin testing the portal).
 *
 * All authentication uses creator_access_token via Auth_Context::resolve_creator().
 * WordPress nonces are not used here — the 256-bit access token is the security boundary.
 *
 * Must never use admin-post.php. Must never register admin_post_* hooks.
 * Must never contain rendering logic.
 */
final class Chatbot_Portal_Controller {

	/** @var string AJAX action for saving creator chatbot settings. */
	public const AJAX_SAVE = 'ccmerch_creator_chatbot_save';

	/** @var string AJAX action for previewing a rendered chatbot message. */
	public const AJAX_PREVIEW = 'ccmerch_creator_chatbot_preview';

	/** @var string AJAX action for sending an actual test message to the creator's channel. */
	public const AJAX_TEST_SEND = 'ccmerch_creator_chatbot_portal_test_send';

	/**
	 * @param Chatbot_Settings_Service $settings_service Business-logic layer.
	 * @param Creator_Service          $creator_service  For Auth_Context resolution.
	 * @param Twitch_Chat_Service      $chat_service     For sending the test message.
	 */
	public function __construct(
		private readonly Chatbot_Settings_Service $settings_service,
		private readonly Creator_Service          $creator_service,
		private readonly Twitch_Chat_Service      $chat_service,
	) {
		add_action( 'wp_ajax_nopriv_' . self::AJAX_SAVE,      [ $this, 'handle_save'      ] );
		add_action( 'wp_ajax_'        . self::AJAX_SAVE,      [ $this, 'handle_save'      ] );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_PREVIEW,   [ $this, 'handle_preview'   ] );
		add_action( 'wp_ajax_'        . self::AJAX_PREVIEW,   [ $this, 'handle_preview'   ] );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_TEST_SEND, [ $this, 'handle_test_send' ] );
		add_action( 'wp_ajax_'        . self::AJAX_TEST_SEND, [ $this, 'handle_test_send' ] );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * Saves the creator's chatbot enabled state and message template.
	 *
	 * @return never
	 */
	public function handle_save(): never {
		$auth = Auth_Context::resolve_creator( $this->creator_service );

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$enabled  = ! empty( $_POST['chatbot_enabled'] );
		$template = sanitize_text_field( wp_unslash( $_POST['chatbot_template'] ?? '' ) );
		// phpcs:enable

		if ( '' === $template ) {
			$template = Chatbot_Settings_Service::DEFAULT_TEMPLATE;
		}

		$saved = $this->settings_service->save_creator_settings(
			$auth->creator_id,
			$enabled,
			$template
		);

		if ( ! $saved ) {
			wp_send_json_error( [
				'message' => __( 'Failed to save chatbot settings.', 'ccmerch-alert' ),
				'reason'  => 'db_fail',
				'step'    => 'save',
			] );
		}

		wp_send_json_success( [
			'message' => __( 'Chatbot settings saved.', 'ccmerch-alert' ),
		] );
	}

	/**
	 * Returns a rendered preview of the chatbot message template.
	 *
	 * Uses sample data so no real customer information is exposed.
	 *
	 * @return never
	 */
	public function handle_preview(): never {
		$auth = Auth_Context::resolve_creator( $this->creator_service );

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$template = sanitize_text_field( wp_unslash( $_POST['chatbot_template'] ?? '' ) );
		// phpcs:enable

		if ( '' === $template ) {
			$template = $this->settings_service->get_template( $auth->creator_id );
		}

		$preview = \str_replace(
			[ '$UserName', '$Name', '$Product', '$Amount' ],
			[ 'StreamUser42', 'Alex', 'Supporter Hoodie', '$24.99' ],
			$template
		);

		wp_send_json_success( [ 'preview' => $preview ] );
	}

	/**
	 * Sends an actual test chatbot message to the creator's configured Twitch channel.
	 *
	 * Uses sample data — no real customer information is exposed.
	 *
	 * @return never
	 */
	public function handle_test_send(): never {
		$auth = Auth_Context::resolve_creator( $this->creator_service );

		$channel = $this->settings_service->get_channel( $auth->creator_id );

		if ( '' === $channel ) {
			wp_send_json_error( [
				'message' => __( 'No Twitch channel configured for your account. Ask your administrator to set your channel on the Chatbot page.', 'ccmerch-alert' ),
				'reason'  => 'no_channel',
				'step'    => 'channel_check',
			] );
		}

		$template = $this->settings_service->get_template( $auth->creator_id );
		$message  = \str_replace(
			[ '$UserName', '$Name', '$Product', '$Amount' ],
			[ 'StreamUser42', 'Alex', 'Supporter Hoodie', '$24.99' ],
			$template
		);

		$sent = $this->chat_service->send_message( $channel, '[Test] ' . $message );

		if ( ! $sent ) {
			$diag  = $this->chat_service->get_channel_diagnostics( $channel );
			$error = ! empty( $diag['last_error'] ) ? (string) $diag['last_error'] : __( 'Message was not delivered. Check the Chatbot diagnostics in the admin panel.', 'ccmerch-alert' );
			wp_send_json_error( [
				'message' => $error,
				'reason'  => 'send_failed',
				'step'    => 'send',
			] );
		}

		wp_send_json_success( [
			'message' => \sprintf(
				/* translators: %s: Twitch channel name */
				__( 'Test message sent to #%s!', 'ccmerch-alert' ),
				$channel
			),
		] );
	}
}
