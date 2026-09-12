<?php
/**
 * Overlay settings contract.
 *
 * @package CCMerchAlert\Overlay
 */

namespace CCMerchAlert\Overlay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the minimum surface that Overlay_Renderer requires from any settings source.
 *
 * Implemented by:
 *  - Overlay_Settings        — the global site-wide overlay (stored in wp_options).
 *  - Creator_Overlay_Profile — per-creator overlay (stored in ccmerch_creator_overlay_settings).
 *
 * This interface exists solely to allow Overlay_Renderer to accept either
 * implementation without coupling to the concrete class.
 *
 * The renderer must never read overlay_id or the poll action from the settings
 * array; it must always call get_overlay_id() and get_poll_action() so that
 * each implementation can supply its own routing identity.
 */
interface Overlay_Settings_Contract {

	/**
	 * Returns a single setting value by key.
	 *
	 * @param  string $key One of the Overlay_Settings::FIELD_* constants.
	 * @return mixed
	 */
	public function get( string $key ): mixed;

	/**
	 * Returns all settings as an associative array, merged over defaults.
	 *
	 * Does NOT include overlay_id — use get_overlay_id() for the access token.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array;

	/**
	 * Returns the validated layout data (element positions + canvas options).
	 *
	 * @return array<string, mixed>
	 */
	public function get_layout(): array;

	/**
	 * Returns the 32-character hex overlay access token for this overlay instance.
	 *
	 * Used by Overlay_Renderer to inject the ID into the ccmerchOverlay JS config
	 * so overlay.js can authenticate its poll requests.
	 *
	 * @return string 32-char hex string, or empty string when not yet generated.
	 */
	public function get_overlay_id(): string;

	/**
	 * Returns the WordPress AJAX action name for the live poll endpoint.
	 *
	 * Used by Overlay_Renderer to inject pollAction into the ccmerchOverlay JS
	 * config. Differs between the global overlay and per-creator overlays because
	 * each controller registers its own AJAX hook.
	 *
	 * @return string AJAX action slug (without 'wp_ajax_' prefix).
	 */
	public function get_poll_action(): string;

	/**
	 * Returns the WordPress AJAX action name for the SSE stream endpoint.
	 *
	 * Used by Overlay_Renderer to inject streamAction into the ccmerchOverlay JS
	 * config so overlay.js can open a persistent EventSource connection to the
	 * correct controller for this overlay instance.
	 *
	 * @return string AJAX action slug (without 'wp_ajax_' prefix).
	 */
	public function get_stream_action(): string;

	/**
	 * Returns the WordPress AJAX action name for the long-poll endpoint.
	 *
	 * Used by Overlay_Renderer to inject longpollAction into the ccmerchOverlay JS
	 * config so overlay.js can use fetch()-based long polling as the primary
	 * transport when SSE is unavailable or blocked (e.g. behind Cloudflare).
	 *
	 * @return string AJAX action slug (without 'wp_ajax_' prefix).
	 */
	public function get_longpoll_action(): string;
}
