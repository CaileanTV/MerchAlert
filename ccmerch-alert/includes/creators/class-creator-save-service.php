<?php
/**
 * Creator Save Service.
 *
 * @package CCMerchAlert\Creators
 */

namespace CCMerchAlert\Creators;

use CCMerchAlert\Auth\Auth_Context;
use CCMerchAlert\Overlay\Overlay_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token-authenticated AJAX save endpoints for the Creator Portal.
 *
 * Both actions register on wp_ajax_nopriv_ (unauthenticated creators) AND
 * wp_ajax_ (creators who also happen to be logged-in WordPress users), following
 * the Auth_Context dual-hook pattern documented in CLAUDE.md AJAX Architecture.
 *
 * Authentication is performed exclusively via the creator's 256-bit access token
 * in the POST body. WordPress session state, nonces, and capability checks are
 * intentionally not used — the token is the sole credential for both endpoints.
 * This is correct and by design: the creator portal may be served from cache
 * (invalidating nonce freshness), and creators are not WordPress users.
 *
 * All responses are JSON. There are no redirects. The Creator Portal JS handles
 * success and error feedback inline so the creator never leaves the page.
 *
 * This is the standard for ALL Creator Portal save operations. Any future
 * save endpoint (templates, branding, etc.) must be added here using the same
 * register() / handle_*() pattern with Auth_Context::resolve_creator().
 *
 * Must never: call is_user_logged_in(), current_user_can(), or check_admin_referer().
 * Must never: redirect — all responses are JSON via wp_send_json_success/error.
 * Must never: render HTML.
 * Must never: contain routing, rewrite, or poll logic (that belongs in Creator_Overlay_Controller).
 */
final class Creator_Save_Service {

	/** AJAX action for saving creator overlay settings from the portal. */
	public const AJAX_SAVE = 'ccmerch_creator_save_settings';

	/** AJAX action for generating a new overlay ID from the portal. */
	public const AJAX_GENERATE_ID = 'ccmerch_creator_generate_overlay_id';

	/**
	 * @param Creator_Service $creator_service Creator business-logic layer.
	 */
	public function __construct(
		private readonly Creator_Service $creator_service,
	) {}

	/**
	 * Registers all Creator Portal save AJAX hooks.
	 *
	 * Called unconditionally in Plugin::load_components() so nopriv endpoints
	 * are reachable on front-end requests (not just inside is_admin()).
	 *
	 * Each action registers both the nopriv variant (unauthenticated creator)
	 * and the non-nopriv variant (logged-in WordPress user visiting the portal
	 * to test or configure it). Without the non-nopriv hook, WordPress admins
	 * receive -1 (not valid JSON) when the portal AJAX fires as a logged-in request.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_nopriv_' . self::AJAX_SAVE,        [ $this, 'handle_save'        ] );
		add_action( 'wp_ajax_'        . self::AJAX_SAVE,        [ $this, 'handle_save'        ] );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_GENERATE_ID, [ $this, 'handle_generate_id' ] );
		add_action( 'wp_ajax_'        . self::AJAX_GENERATE_ID, [ $this, 'handle_generate_id' ] );
	}

	/**
	 * Saves creator overlay settings submitted from the portal form.
	 *
	 * Reads POST[Overlay_Settings::OPTION_KEY], sanitizes through the creator's
	 * Creator_Overlay_Profile, and persists via Creator_Service. Responds with
	 * JSON — no redirects, no page reload required.
	 *
	 * Auth: Auth_Context::resolve_creator() validates $_POST['creator_token'],
	 * looks up the creator profile, and terminates with wp_send_json_error() on
	 * any failure — callers either receive a valid context or never continue.
	 *
	 * @return never
	 */
	public function handle_save(): never {
		$auth       = Auth_Context::resolve_creator( $this->creator_service );
		$creator_id = $auth->creator_id;
		$profile    = $this->creator_service->get_overlay_profile( $creator_id );

		if ( null === $profile ) {
			wp_send_json_error( [
				'message' => __( 'Overlay profile not found.', 'ccmerch-alert' ),
				'reason'  => 'no_profile',
				'step'    => 'profile_lookup',
			] );
		}

		$allowed_ids = $this->creator_service->get_category_ids( $creator_id );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- token-authenticated endpoint; nonces intentionally not used for creator context (see CLAUDE.md AJAX Architecture)
		$raw       = wp_unslash( $_POST[ Overlay_Settings::OPTION_KEY ] ?? [] );
		$sanitized = $profile->sanitize( \is_array( $raw ) ? $raw : [], $allowed_ids );

		$saved = $this->creator_service->save_overlay_settings( $creator_id, $sanitized );

		if ( ! $saved ) {
			wp_send_json_error( [
				'message' => __( 'Failed to save settings. Please try again.', 'ccmerch-alert' ),
				'reason'  => 'db_fail',
				'step'    => 'save',
			] );
		}

		wp_send_json_success( [ 'message' => __( 'Settings saved.', 'ccmerch-alert' ) ] );
	}

	/**
	 * Generates a new browser-source overlay ID and returns the new URL.
	 *
	 * On success, the JS updates the URL display in place — no page reload.
	 * On first-time generation (no existing URL in the DOM), the JS reloads
	 * the portal page so the full browser-source URL card renders correctly.
	 *
	 * Auth: Auth_Context::resolve_creator() — same token-based path as handle_save().
	 *
	 * @return never
	 */
	public function handle_generate_id(): never {
		$auth       = Auth_Context::resolve_creator( $this->creator_service );
		$creator_id = $auth->creator_id;

		$result = $this->creator_service->reset_overlay_id( $creator_id );

		if ( false === $result ) {
			wp_send_json_error( [
				'message' => __( 'Failed to generate overlay ID. Please try again.', 'ccmerch-alert' ),
				'reason'  => 'db_fail',
				'step'    => 'generate',
			] );
		}

		$profile = $this->creator_service->get_overlay_profile( $creator_id );
		$new_url = null !== $profile ? $profile->get_overlay_url() : '';

		wp_send_json_success( [
			'overlay_url' => $new_url,
			'message'     => __( 'New overlay ID generated. Update your OBS browser source with the new URL below.', 'ccmerch-alert' ),
		] );
	}
}
