<?php
/**
 * AJAX authentication context.
 *
 * @package CCMerchAlert\Auth
 */

namespace CCMerchAlert\Auth;

use CCMerchAlert\Creators\Creator_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolved authentication context for a dual-context AJAX request.
 *
 * Two contexts exist in CCMerch Alert:
 *
 *  - 'creator' — Authenticated via creator_access_token. The creator is NOT
 *    required to be a WordPress user. WordPress nonces are intentionally NOT
 *    verified in creator context: the portal page may be served from a caching
 *    layer (invalidating nonce freshness) and creators have no WP session.
 *    The 256-bit access token provides the authentication guarantee.
 *
 *  - 'admin' — Authenticated via WordPress nonce + manage_options capability.
 *    Standard WordPress admin AJAX authentication.
 *
 * Usage:
 *   $ctx = Auth_Context::resolve_creator( $creator_service );
 *   $ctx = Auth_Context::resolve_admin( 'my_nonce_action' );
 *
 * Both factory methods terminate via wp_send_json_error() on any auth failure.
 * Callers always receive a fully-resolved context object — or execution ends.
 *
 * Registering endpoints for both wp_ajax_ and wp_ajax_nopriv_ is required so
 * creator portal uploads work regardless of whether the visitor is a logged-in
 * WordPress user or not. Both hooks route to the same handler; the handler calls
 * resolve_creator() which authenticates via token, not WP session.
 */
final class Auth_Context {

	/** @var string 'creator' | 'admin' */
	public readonly string $context;

	/** @var int Creator profile ID; 0 in admin context. */
	public readonly int $creator_id;

	/** @var string Creator overlay ID; '' in admin context. */
	public readonly string $overlay_id;

	private function __construct( string $context, int $creator_id, string $overlay_id ) {
		$this->context    = $context;
		$this->creator_id = $creator_id;
		$this->overlay_id = $overlay_id;
	}

	/**
	 * Resolves a Creator Portal AJAX request via creator_access_token.
	 *
	 * The creator_access_token (256-bit entropy, 64 hex chars) is the sole
	 * authentication mechanism for creator portal AJAX endpoints. WordPress
	 * nonces are explicitly NOT checked here. Reasons:
	 *
	 *  1. The portal page may be cached — nonces created at cache time expire
	 *     before the user interacts with the page, producing false failures.
	 *  2. Creators are not WordPress users — no session state is available to
	 *     seed a stable nonce hash.
	 *  3. The 256-bit token already provides stronger authentication than a
	 *     12-hour WP nonce.
	 *
	 * Creator endpoints MUST register BOTH wp_ajax_ and wp_ajax_nopriv_ hooks
	 * so the same handler fires whether or not the request comes from a
	 * logged-in WordPress user (e.g., an admin testing the portal).
	 *
	 * Terminates with a structured wp_send_json_error() on any auth failure.
	 * Declared return type is self because PHP cannot express "returns self OR
	 * terminates" — callers always receive a valid context object.
	 *
	 * @param  Creator_Service $service Creator lookup for token validation.
	 * @return self
	 */
	public static function resolve_creator( Creator_Service $service ): self {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$token = (string) ( $_POST['creator_token'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable

		if ( '' === $token ) {
			wp_send_json_error( [
				'message' => __( 'Access denied.', 'ccmerch-alert' ),
				'reason'  => 'missing_token',
				'step'    => 'token_read',
			] );
		}

		$creator = $service->get_creator_by_token( $token );

		if ( null === $creator || 'active' !== $creator->status ) {
			wp_send_json_error( [
				'message' => __( 'Access denied.', 'ccmerch-alert' ),
				'reason'  => 'invalid_token',
				'step'    => 'token_validate',
			] );
		}

		$profile    = $service->get_overlay_profile( $creator->id );
		$overlay_id = null !== $profile ? $profile->get_overlay_id() : '';

		return new self( 'creator', $creator->id, $overlay_id );
	}

	/**
	 * Resolves a WordPress admin AJAX request via nonce + manage_options.
	 *
	 * Looks for `_ajax_nonce` or `_wpnonce` in $_REQUEST automatically.
	 * Terminates with a structured wp_send_json_error() on any auth failure.
	 *
	 * @param  string $nonce_action Nonce action registered with wp_create_nonce() on the admin page.
	 * @return self
	 */
	public static function resolve_admin( string $nonce_action ): self {
		if ( false === check_ajax_referer( $nonce_action, false, false ) ) {
			wp_send_json_error( [
				'message' => __( 'Security check failed. Please reload the page and try again.', 'ccmerch-alert' ),
				'reason'  => 'invalid_nonce',
				'step'    => 'nonce_verify',
			] );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [
				'message' => __( 'Access denied.', 'ccmerch-alert' ),
				'reason'  => 'insufficient_permissions',
				'step'    => 'capability_check',
			] );
		}

		return new self( 'admin', 0, '' );
	}
}
