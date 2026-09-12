<?php
/**
 * Creator Overlay Controller.
 *
 * @package CCMerchAlert\Creators
 */

namespace CCMerchAlert\Creators;

use CCMerchAlert\Chatbot\Chatbot_Message_Service;
use CCMerchAlert\Checkout\Customer_Username_Field;
use CCMerchAlert\Engine\Alert_Event_Repository;
use CCMerchAlert\Engine\Alert_History_Service;
use CCMerchAlert\Engine\Replay_Service;
use CCMerchAlert\OrderLog\Order_Log_Entry;
use CCMerchAlert\OrderLog\Order_Log_Item;
use CCMerchAlert\OrderLog\Order_Repository;
use CCMerchAlert\Overlay\Overlay_Renderer;
use CCMerchAlert\Overlay\Overlay_Template_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles routing, AJAX, and save endpoints for per-creator overlay profiles.
 *
 * Responsibilities:
 *  - Registers the /ccmerch-alert/creator-overlay/{id}/ rewrite rule.
 *  - Intercepts matching requests on template_redirect and renders the overlay
 *    page using the creator's own Creator_Overlay_Profile settings.
 *  - Handles the nopriv AJAX poll endpoint for creator overlays.
 *  - Handles admin-only test/replay AJAX endpoints for creator overlays.
 *  - Handles the nopriv portal test AJAX endpoint (creator-token authenticated).
 *
 * Creator Portal save operations (settings save, generate overlay ID) are handled
 * by Creator_Save_Service using admin-ajax.php with both nopriv and priv hooks.
 *
 * The creator's overlay ID (32 random hex chars, 128-bit entropy) is the sole
 * authentication token for the poll endpoint. All other endpoints require
 * either the creator's portal access token or the WordPress manage_options cap.
 *
 * Must never: output HTML directly (delegated to Overlay_Renderer).
 * Must never: query the database directly (use Creator_Service / Order_Repository).
 */
final class Creator_Overlay_Controller {

	/** WordPress query var carrying the creator overlay ID from the rewrite rule. */
	public const QUERY_VAR = 'ccmerch_creator_overlay_id';

	/** Rewrite pattern — matches /ccmerch-alert/creator-overlay/{id}/. */
	public const REWRITE_RULE = '^ccmerch-alert/creator-overlay/([^/]+)/?$';

	/** Rewrite substitution string. */
	public const REWRITE_QUERY = 'index.php?ccmerch_creator_overlay_id=$matches[1]';

	/** AJAX action for live poll (nopriv — overlay ID is the token). */
	public const AJAX_POLL = 'ccmerch_creator_overlay_poll';

	/** AJAX action for admin test alert. */
	public const AJAX_TEST = 'ccmerch_creator_overlay_test';

	/** AJAX action for admin replay latest. */
	public const AJAX_REPLAY = 'ccmerch_creator_overlay_replay';

	/** Nonce action for test/replay. */
	public const NONCE_TEST = 'ccmerch_creator_overlay_test';

	/** AJAX action for test alert triggered from the creator portal (nopriv; token-authenticated). */
	public const AJAX_PORTAL_TEST = 'ccmerch_creator_portal_test';

	/** AJAX action for the SSE persistent stream (nopriv — overlay ID is the token). */
	public const AJAX_STREAM = 'ccmerch_creator_overlay_stream';

	/** AJAX action for long-poll (nopriv — overlay ID is the token). */
	public const AJAX_LONGPOLL = 'ccmerch_creator_overlay_longpoll';

	/** AJAX action for the Overlay Designer canvas preview data refresh (nopriv; token-authenticated). */
	public const AJAX_PREVIEW_DATA = 'ccmerch_creator_preview_data';

	/** AJAX action for paginated order group history (nopriv; token-authenticated). */
	public const AJAX_HISTORY = 'ccmerch_creator_alert_history';

	/** AJAX action for per-order event detail expansion (nopriv; token-authenticated). */
	public const AJAX_HISTORY_ORDER = 'ccmerch_creator_history_order';

	/** AJAX action for replaying a single stored alert event (nopriv; token-authenticated). */
	public const AJAX_REPLAY_EVENT = 'ccmerch_creator_replay_event';

	/** AJAX action for replaying all of a creator's alert events for one order (nopriv; token-authenticated). */
	public const AJAX_REPLAY_ORDER = 'ccmerch_creator_replay_order';

	/**
	 * @param Creator_Service        $service                Creator business-logic layer.
	 * @param Order_Repository       $repository             Order log data access (test/replay paths only).
	 * @param Overlay_Template_Engine $engine                Template variable substitution engine.
	 * @param Alert_Event_Repository $alert_event_repository Isolated per-creator alert queue (live poll path).
	 * @param Alert_History_Service  $history_service        Paginated alert history for the Creator Portal.
	 * @param Replay_Service         $replay_service         Ownership-validated replay pipeline.
	 * @param Chatbot_Message_Service $chatbot_message_service Shared per-event chatbot sender — same
	 *                                                          implementation used by live orders, so
	 *                                                          replaying an order sends the identical
	 *                                                          one-message-per-product chatbot output.
	 */
	public function __construct(
		private readonly Creator_Service $service,
		private readonly Order_Repository $repository,
		private readonly Overlay_Template_Engine $engine,
		private readonly Alert_Event_Repository $alert_event_repository,
		private readonly Alert_History_Service $history_service,
		private readonly Replay_Service $replay_service,
		private readonly Chatbot_Message_Service $chatbot_message_service,
	) {
		add_action( 'init',              [ $this, 'register_rewrite_rules' ] );
		add_filter( 'query_vars',        [ $this, 'register_query_vars'    ] );
		add_action( 'template_redirect', [ $this, 'maybe_render_overlay'   ] );

		add_action( 'wp_ajax_nopriv_' . self::AJAX_POLL, [ $this, 'handle_poll' ] );
		add_action( 'wp_ajax_'        . self::AJAX_POLL, [ $this, 'handle_poll' ] );

		add_action( 'wp_ajax_' . self::AJAX_TEST,   [ $this, 'handle_test'   ] );
		add_action( 'wp_ajax_' . self::AJAX_REPLAY, [ $this, 'handle_replay' ] );

		// Portal test — nopriv, authenticated via creator access token.
		add_action( 'wp_ajax_nopriv_' . self::AJAX_PORTAL_TEST, [ $this, 'handle_portal_test' ] );
		add_action( 'wp_ajax_'        . self::AJAX_PORTAL_TEST, [ $this, 'handle_portal_test' ] );

		// SSE stream — same auth model as poll; EventSource uses GET requests.
		add_action( 'wp_ajax_nopriv_' . self::AJAX_STREAM, [ $this, 'handle_stream' ] );
		add_action( 'wp_ajax_'        . self::AJAX_STREAM, [ $this, 'handle_stream' ] );

		// Long poll — fetch()-based; completes each HTTP request fully (Cloudflare-compatible).
		add_action( 'wp_ajax_nopriv_' . self::AJAX_LONGPOLL, [ $this, 'handle_longpoll' ] );
		add_action( 'wp_ajax_'        . self::AJAX_LONGPOLL, [ $this, 'handle_longpoll' ] );

		// Overlay Designer preview data refresh — nopriv, creator-token authenticated.
		add_action( 'wp_ajax_nopriv_' . self::AJAX_PREVIEW_DATA, [ $this, 'handle_preview_data' ] );
		add_action( 'wp_ajax_'        . self::AJAX_PREVIEW_DATA, [ $this, 'handle_preview_data' ] );

		// Alert History — nopriv, creator-token authenticated.
		add_action( 'wp_ajax_nopriv_' . self::AJAX_HISTORY,       [ $this, 'handle_history'       ] );
		add_action( 'wp_ajax_'        . self::AJAX_HISTORY,       [ $this, 'handle_history'       ] );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_HISTORY_ORDER, [ $this, 'handle_history_order' ] );
		add_action( 'wp_ajax_'        . self::AJAX_HISTORY_ORDER, [ $this, 'handle_history_order' ] );

		// Replay — nopriv, creator-token authenticated; ownership validated server-side.
		add_action( 'wp_ajax_nopriv_' . self::AJAX_REPLAY_EVENT, [ $this, 'handle_replay_event' ] );
		add_action( 'wp_ajax_'        . self::AJAX_REPLAY_EVENT, [ $this, 'handle_replay_event' ] );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_REPLAY_ORDER, [ $this, 'handle_replay_order' ] );
		add_action( 'wp_ajax_'        . self::AJAX_REPLAY_ORDER, [ $this, 'handle_replay_order' ] );
	}

	// -------------------------------------------------------------------------
	// Rewrite rule
	// -------------------------------------------------------------------------

	/**
	 * Registers the creator overlay browser-source rewrite rule.
	 *
	 * @return void
	 */
	public function register_rewrite_rules(): void {
		add_rewrite_rule( self::REWRITE_RULE, self::REWRITE_QUERY, 'top' );
	}

	/**
	 * @param  string[] $vars
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	// -------------------------------------------------------------------------
	// Route handler
	// -------------------------------------------------------------------------

	/**
	 * Intercepts /ccmerch-alert/creator-overlay/{id}/ requests.
	 *
	 * Validates the overlay ID against the creator overlay table, then renders
	 * the overlay page using that creator's settings. Returns a 404 on mismatch.
	 *
	 * @return void
	 */
	public function maybe_render_overlay(): void {
		$request_id = (string) get_query_var( self::QUERY_VAR, '' );

		if ( '' === $request_id ) {
			return;
		}

		$profile = $this->load_profile_by_overlay_id( $request_id );

		if ( null === $profile || ! \hash_equals( $profile->overlay_id, $request_id ) ) {
			wp_die( '', '', [ 'response' => 404 ] );
		}

		$renderer = new Overlay_Renderer( $profile, $this->engine );
		$renderer->render_page();
	}

	// -------------------------------------------------------------------------
	// AJAX: live poll
	// -------------------------------------------------------------------------

	/**
	 * Returns new order data for a creator's live overlay to display.
	 *
	 * Auth: creator overlay_id in POST (serves as the access token).
	 *
	 * @return void
	 */
	public function handle_poll(): void {
		$request_id = sanitize_text_field( wp_unslash( $_POST['overlay_id'] ?? '' ) );

		$profile = '' !== $request_id ? $this->load_profile_by_overlay_id( $request_id ) : null;

		if ( null === $profile || ! \hash_equals( $profile->overlay_id, $request_id ) ) {
			wp_send_json_error( null, 403 );
		}

		$since = (int) ( $_POST['since'] ?? 0 );
		$now   = \time();

		// Consume any pending test/replay transient first.
		$pending_key = 'ccmerch_creator_overlay_pending_' . $profile->overlay_id;
		$pending     = get_transient( $pending_key );
		if ( false !== $pending && \is_array( $pending ) ) {
			delete_transient( $pending_key );
			$queue = $pending['queue'] ?? [];
			wp_send_json_success( [ 'has_new' => ! empty( $queue ), 'queue' => $queue, 'server_time' => $now ] );
		}

		// On first poll ($since == 0) return an empty queue and set server_time.
		// Subsequent polls carry the server_time from the previous response, so
		// get_pending_for_overlay() returns only events created after that moment.
		if ( $since <= 0 ) {
			wp_send_json_success( [ 'has_new' => false, 'queue' => [], 'server_time' => $now ] );
		}

		// Read ONLY events belonging to THIS overlay_id — cross-creator contamination
		// is impossible because Alert_Router wrote the overlay_id at creation time.
		// Events are returned oldest-first (ASC, id ASC tiebreaker) so the JS queue
		// plays in purchase order without client-side reversal.
		$events = $this->alert_event_repository->get_pending_for_overlay( $profile->overlay_id, $since );

		if ( empty( $events ) ) {
			wp_send_json_success( [ 'has_new' => false, 'queue' => [], 'server_time' => $now ] );
		}

		// Advance server_time past the last event's created_at so the next poll's
		// `created_at >= since_dt` boundary excludes events we're about to deliver.
		// Without this, events created at the exact same second as $now would satisfy
		// `>=` again on the next poll, causing duplicate delivery.
		$last_event = $events[ \count( $events ) - 1 ];
		$now        = \max( $now, $last_event->created_at + 1 );

		$renderer = new Overlay_Renderer( $profile, $this->engine );
		$queue    = [];
		foreach ( $events as $event ) {
			$queue[] = $renderer->render_event( $event );
		}

		wp_send_json_success( [ 'has_new' => ! empty( $queue ), 'queue' => $queue, 'server_time' => $now ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: test alert (admin only)
	// -------------------------------------------------------------------------

	/**
	 * Queues a creator-scoped test alert for a creator's overlay.
	 *
	 * Selects a random published product from the creator's assigned WooCommerce
	 * categories and pairs it with a randomly chosen simulated first name. No real
	 * customer data is ever used. No WooCommerce orders are created.
	 *
	 * Auth: nonce + manage_options + creator_id in POST.
	 *
	 * @return void
	 */
	public function handle_test(): void {
		check_ajax_referer( self::NONCE_TEST );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$creator_id = (int) ( $_POST['creator_id'] ?? 0 );
		$profile    = $creator_id > 0 ? $this->service->get_overlay_profile( $creator_id ) : null;

		if ( null === $profile ) {
			wp_send_json_error( [ 'message' => __( 'Creator overlay profile not found.', 'ccmerch-alert' ) ] );
		}

		$product = $this->service->get_random_product_for_creator( $creator_id );

		if ( null === $product ) {
			wp_send_json_error( [ 'message' => __( 'No products are currently assigned to this creator\'s category.', 'ccmerch-alert' ) ] );
		}

		$first_name = Creator_Service::TEST_FIRST_NAMES[ \array_rand( Creator_Service::TEST_FIRST_NAMES ) ];
		$mock_item  = new Order_Log_Item( 0, 0, $product['product_id'], null, $product['product_name'], '', 1, $product['featured_image_attachment_id'], $product['category_term_ids'] );
		$mock_entry = new Order_Log_Entry( 0, 0, '0001', new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ), $first_name, [ $mock_item ] );

		$renderer = new Overlay_Renderer( $profile, $this->engine );
		$queue    = $renderer->render_items( $mock_entry );

		set_transient( 'ccmerch_creator_overlay_pending_' . $profile->overlay_id, [ 'queue' => $queue, 'test' => true ], 300 );

		wp_send_json_success( [ 'message' => __( 'Test alert queued. Watch your overlay.', 'ccmerch-alert' ) ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: replay latest (admin only)
	// -------------------------------------------------------------------------

	/**
	 * Queues the most recent logged order for replay on a creator's overlay.
	 *
	 * Auth: nonce + manage_options + creator_id in POST.
	 *
	 * @return void
	 */
	public function handle_replay(): void {
		check_ajax_referer( self::NONCE_TEST );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$creator_id = (int) ( $_POST['creator_id'] ?? 0 );
		$profile    = $creator_id > 0 ? $this->service->get_overlay_profile( $creator_id ) : null;

		if ( null === $profile ) {
			wp_send_json_error( [ 'message' => __( 'Creator overlay profile not found.', 'ccmerch-alert' ) ] );
		}

		$entries = $this->repository->get_recent( 1 );

		if ( empty( $entries ) ) {
			wp_send_json_error( [ 'message' => __( 'No orders in the log yet.', 'ccmerch-alert' ) ] );
		}

		$renderer = new Overlay_Renderer( $profile, $this->engine );
		$queue    = $renderer->render_items( $entries[0] );
		set_transient( 'ccmerch_creator_overlay_pending_' . $profile->overlay_id, [ 'queue' => $queue ], 300 );

		wp_send_json_success( [ 'message' => __( 'Replay queued. Watch your overlay.', 'ccmerch-alert' ) ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: portal test alert (creator-token authenticated, nopriv)
	// -------------------------------------------------------------------------

	/**
	 * Queues a creator-scoped test alert on the creator's own overlay.
	 *
	 * Selects a random published product from the creator's assigned WooCommerce
	 * categories and pairs it with a randomly chosen simulated first name. No real
	 * customer data is used and no WooCommerce orders are created. Products from
	 * other creators' categories can never appear (creator isolation enforced by
	 * Creator_Service::get_random_product_for_creator).
	 *
	 * If the creator has no products in their assigned categories the endpoint
	 * returns a structured error; no transient is written and the overlay is
	 * left undisturbed.
	 *
	 * Auth: creator portal access token (256-bit entropy). No WP nonce or session.
	 *
	 * @return void
	 */
	public function handle_portal_test(): void {
		$token   = sanitize_text_field( wp_unslash( $_POST['creator_token'] ?? '' ) );
		$creator = '' !== $token ? $this->service->get_creator_by_token( $token ) : null;

		if ( null === $creator || 'active' !== $creator->status || ! \hash_equals( $creator->creator_access_token, $token ) ) {
			wp_send_json_error( null, 403 );
		}

		$profile = $this->service->get_overlay_profile( $creator->id );

		if ( null === $profile ) {
			wp_send_json_error( [ 'message' => __( 'Overlay profile not found.', 'ccmerch-alert' ) ] );
		}

		$product = $this->service->get_random_product_for_creator( $creator->id );

		if ( null === $product ) {
			wp_send_json_error( [ 'message' => __( 'No products are currently assigned to your category.', 'ccmerch-alert' ) ] );
		}

		$first_name = Creator_Service::TEST_FIRST_NAMES[ \array_rand( Creator_Service::TEST_FIRST_NAMES ) ];
		$mock_item  = new Order_Log_Item( 0, 0, $product['product_id'], null, $product['product_name'], '', 1, $product['featured_image_attachment_id'], $product['category_term_ids'] );
		$mock_entry = new Order_Log_Entry( 0, 0, '0001', new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ), $first_name, [ $mock_item ] );

		$renderer = new Overlay_Renderer( $profile, $this->engine );
		$queue    = $renderer->render_items( $mock_entry );

		set_transient( 'ccmerch_creator_overlay_pending_' . $profile->overlay_id, [ 'queue' => $queue, 'test' => true ], 300 );

		wp_send_json_success( [ 'message' => __( 'Test alert queued. Watch your overlay.', 'ccmerch-alert' ) ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: Overlay Designer canvas preview data (creator-token authenticated, nopriv)
	// -------------------------------------------------------------------------

	/**
	 * Returns fresh creator-scoped preview data for the Overlay Designer canvas.
	 *
	 * Selects a random published product from the creator's assigned WooCommerce
	 * categories and a random first name from the predefined list. The returned
	 * shape matches the JS `preview` object consumed by layout-editor.js so the
	 * canvas re-renders without a page reload.
	 *
	 * Auth: creator portal access token (256-bit entropy). No WP nonce or session.
	 *
	 * @return void
	 */
	public function handle_preview_data(): void {
		$token   = sanitize_text_field( wp_unslash( $_POST['creator_token'] ?? '' ) );
		$creator = '' !== $token ? $this->service->get_creator_by_token( $token ) : null;

		if ( null === $creator || 'active' !== $creator->status || ! \hash_equals( $creator->creator_access_token, $token ) ) {
			wp_send_json_error( null, 403 );
		}

		wp_send_json_success( $this->service->build_preview_data( $creator->id ) );
	}

	// -------------------------------------------------------------------------
	// Long poll: fetch()-based request held for up to 8 s (Cloudflare-compatible)
	// -------------------------------------------------------------------------

	/**
	 * Long-poll endpoint for a creator's live overlay browser source.
	 *
	 * Holds the HTTP connection for up to 8 seconds, returning immediately when
	 * new events arrive. The client issues a new request the moment this one
	 * completes. Because this produces a complete HTTP response it flows through
	 * Cloudflare and other reverse proxies without buffering issues.
	 *
	 * Auth: overlay_id in POST body (same token as handle_poll).
	 *
	 * @return never
	 */
	public function handle_longpoll(): never {
		$request_id = sanitize_text_field( wp_unslash( $_POST['overlay_id'] ?? '' ) );

		$profile = '' !== $request_id ? $this->load_profile_by_overlay_id( $request_id ) : null;

		if ( null === $profile || ! \hash_equals( $profile->overlay_id, $request_id ) ) {
			wp_send_json_error( null, 403 );
		}

		$since = (int) ( $_POST['since'] ?? 0 );

		@\set_time_limit( 30 );

		if ( $since <= 0 ) {
			$since = \time();
		}

		$max_run           = 8.0; // seconds — complete HTTP response; no streaming needed.
		$start             = \microtime( true );
		$last_event_check  = 0.0;
		$last_config_check = 0.0;
		$config_version    = $this->service->get_overlay_config_version( $request_id );
		$pending_key       = 'ccmerch_creator_overlay_pending_' . $profile->overlay_id;
		$queue             = [];
		$config_payload    = null;

		while ( true ) {
			if ( ( \microtime( true ) - $start ) >= $max_run ) {
				break;
			}

			$now_f = \microtime( true );

			// Priority 1: test/replay transient — sub-100 ms detection.
			$pending = get_transient( $pending_key );
			if ( false !== $pending && \is_array( $pending ) ) {
				delete_transient( $pending_key );
				$queue = $pending['queue'] ?? [];
				if ( ! empty( $queue ) ) {
					break;
				}
			}

			// Priority 2: real Alert Events — rate-limited to 1 s.
			if ( ( $now_f - $last_event_check ) >= 1.0 ) {
				$last_event_check = $now_f;
				$events           = $this->alert_event_repository->get_pending_for_overlay( $profile->overlay_id, $since );
				if ( ! empty( $events ) ) {
					$last_event = $events[ \count( $events ) - 1 ];
					$since      = \max( \time(), $last_event->created_at + 1 );

					$renderer = new Overlay_Renderer( $profile, $this->engine );
					foreach ( $events as $event ) {
						$queue[] = $renderer->render_event( $event );
					}
					break;
				}
			}

			// Priority 3: config version change — rate-limited to 1 s.
			if ( ( $now_f - $last_config_check ) >= 1.0 ) {
				$last_config_check      = $now_f;
				$current_config_version = $this->service->get_overlay_config_version( $request_id );
				if ( $current_config_version !== $config_version ) {
					$config_version = $current_config_version;
					$fresh_profile  = $this->load_profile_by_overlay_id( $request_id );
					if ( null !== $fresh_profile ) {
						$config_payload = ( new Overlay_Renderer( $fresh_profile, $this->engine ) )->get_config_payload();
					}
					break;
				}
			}

			\usleep( 100_000 ); // 100 ms — transient check every 100 ms.
		}

		wp_send_json_success(
			[
				'queue'       => $queue,
				'has_new'     => ! empty( $queue ),
				'server_time' => \time(),
				'config'      => $config_payload,
			]
		);
	}

	// -------------------------------------------------------------------------
	// SSE: persistent alert stream
	// -------------------------------------------------------------------------

	/**
	 * Server-Sent Events stream for a creator's live overlay browser source.
	 *
	 * Holds the connection open and pushes Alert Events from the creator's
	 * isolated queue as they arrive. On planned close the client reconnects via
	 * Last-Event-ID; no events are missed across reconnects.
	 *
	 * Auth: creator overlay_id in the query string (GET — EventSource requirement).
	 *
	 * @return void
	 */
	public function handle_stream(): void {
		// Disable all forms of output buffering and compression so SSE frames reach
		// the browser immediately rather than being held by PHP, nginx, or a CDN.
		@\ini_set( 'zlib.output_compression', '0' );
		@\ini_set( 'output_buffering', '0' );
		@\ini_set( 'implicit_flush', '1' );
		while ( \ob_get_level() > 0 ) {
			\ob_end_clean();
		}

		$request_id = sanitize_text_field( wp_unslash( $_GET['overlay_id'] ?? '' ) );
		$profile    = '' !== $request_id ? $this->load_profile_by_overlay_id( $request_id ) : null;

		if ( null === $profile || ! \hash_equals( $profile->overlay_id, $request_id ) ) {
			http_response_code( 403 );
			echo "event: error\ndata: {\"reason\":\"unauthorized\"}\n\n";
			exit;
		}

		// SSE headers.
		// Content-Encoding: identity  — tells CDNs and reverse proxies that the body
		//   is not compressed; prevents them from buffering to decompress/re-encode.
		// X-Accel-Buffering: no       — nginx: disable proxy_buffering for this response.
		// Cache-Control: no-store     — CDNs must not cache this endpoint.
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Content-Encoding: identity' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );

		// Force proxy/CDN/PHP-FPM buffers to flush before the first SSE event.
		// Small responses (< 4–8 KB) are commonly held by nginx fastcgi_buffer,
		// PHP-FPM output buffer, or Cloudflare until the buffer fills or the
		// connection closes. An SSE comment large enough to exceed that threshold
		// ensures the 'connected' event reaches the browser in < 1 s regardless
		// of server configuration. EventSource silently ignores ':' comment lines.
		echo ':' . \str_repeat( ' ', 4096 ) . "\n\n";
		\flush();

		// On reconnect, EventSource sends Last-Event-ID (last id: value received).
		$last_id = isset( $_SERVER['HTTP_LAST_EVENT_ID'] ) && '' !== $_SERVER['HTTP_LAST_EVENT_ID']
			? (int) $_SERVER['HTTP_LAST_EVENT_ID']
			: -1;
		$since   = $last_id >= 0 ? $last_id : (int) ( $_GET['since'] ?? 0 );

		@\set_time_limit( 0 );
		\ignore_user_abort( true );

		$start   = \time();
		$max_run = 25; // seconds — planned reconnect; kept below common PHP-FPM timeouts.
		$now     = $start;

		$this->sse_emit( 'connected', [ 'server_time' => $now ], $now );

		// Anchor since to now on the very first connection so we only deliver new events.
		if ( $since <= 0 ) {
			$since = $now;
		}

		$pending_key    = 'ccmerch_creator_overlay_pending_' . $profile->overlay_id;
		$config_version = $this->service->get_overlay_config_version( $request_id );

		// Sub-second timing state for rate-limiting expensive checks while keeping the
		// transient (test alert) check fast. The loop runs every 100 ms; live-event and
		// config-version checks are gated to 1 s intervals so DB load is unchanged.
		$last_event_check  = \microtime( true );
		$last_config_check = \microtime( true );
		$last_heartbeat_f  = (float) $start;

		while ( true ) {
			if ( \connection_aborted() ) {
				break;
			}

			if ( ( \time() - $start ) >= $max_run ) {
				$this->sse_emit( 'reconnect', [ 'server_time' => \time() ], \time() );
				break;
			}

			$now_f = \microtime( true );
			$now   = (int) $now_f;

			// Priority 1: queued test/replay transient — checked every 100 ms so test
			// alerts reach the Browser Source within ~100 ms of the button click.
			$pending = get_transient( $pending_key );
			if ( false !== $pending && \is_array( $pending ) ) {
				delete_transient( $pending_key );
				$queue = $pending['queue'] ?? [];
				if ( ! empty( $queue ) ) {
					$since = $now;
					$this->sse_emit(
						'alert',
						[
							'has_new'     => true,
							'queue'       => $queue,
							'test'        => (bool) ( $pending['test'] ?? false ),
							'server_time' => $now,
						],
						$now
					);
				}
			} elseif ( ( $now_f - $last_event_check ) >= 1.0 ) {
				// Priority 2: real Alert Events — checked every 1 s (DB load unchanged).
				$last_event_check = $now_f;
				$events           = $this->alert_event_repository->get_pending_for_overlay( $profile->overlay_id, $since );
				if ( ! empty( $events ) ) {
					$last_event = $events[ \count( $events ) - 1 ];
					$since      = \max( \time(), $last_event->created_at + 1 );

					$renderer = new Overlay_Renderer( $profile, $this->engine );
					$queue    = [];
					foreach ( $events as $event ) {
						$queue[] = $renderer->render_event( $event );
					}

					$this->sse_emit( 'alert', [ 'has_new' => true, 'queue' => $queue, 'test' => false, 'server_time' => $since ], $since );
				}
			}

			if ( ( $now_f - $last_heartbeat_f ) >= 15.0 ) {
				$last_heartbeat_f = $now_f;
				$this->sse_emit( 'heartbeat', [ 'server_time' => $now ], $now );
			}

			// Config version check — rate-limited to 1 s so the direct DB query runs at
			// the same frequency as before despite the shorter loop interval.
			if ( ( $now_f - $last_config_check ) >= 1.0 ) {
				$last_config_check      = $now_f;
				$current_config_version = $this->service->get_overlay_config_version( $request_id );
				if ( $current_config_version !== $config_version ) {
					$config_version = $current_config_version;
					$fresh_profile  = $this->load_profile_by_overlay_id( $request_id );
					if ( null !== $fresh_profile ) {
						$profile = $fresh_profile;
						if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
							\error_log( '[CCMerch Config Sync] Creator overlay ' . $request_id . ': config version changed to ' . $current_config_version . ' — emitting config event.' );
						}
						$this->sse_emit( 'config', ( new Overlay_Renderer( $fresh_profile, $this->engine ) )->get_config_payload(), \time() );
					}
				}
			}

			\usleep( 100_000 ); // 100 ms — transient check runs every 100 ms.
		}

		exit;
	}

	/**
	 * Emits a single SSE event and flushes the output buffer.
	 *
	 * @param string $event Event type name.
	 * @param array  $data  Payload, JSON-encoded automatically.
	 * @param int    $id    Event ID (used as Last-Event-ID on reconnect).
	 * @return void
	 */
	private function sse_emit( string $event, array $data, int $id ): void {
		echo "id: {$id}\n";
		echo "event: {$event}\n";
		echo "data: " . \wp_json_encode( $data ) . "\n\n";
		\flush();
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Loads a Creator_Overlay_Profile by its overlay_id.
	 *
	 * Returns null when the ID is not found or the profile cannot be loaded.
	 *
	 * @param  string $overlay_id 32-char hex overlay access token.
	 * @return Creator_Overlay_Profile|null
	 */
	private function load_profile_by_overlay_id( string $overlay_id ): ?Creator_Overlay_Profile {
		$creator_id = (int) ( $this->service->get_overlay_profile_id_by_overlay_id( $overlay_id ) ?? 0 );

		if ( $creator_id <= 0 ) {
			return null;
		}

		return $this->service->get_overlay_profile( $creator_id );
	}

	// -------------------------------------------------------------------------
	// Alert History handlers
	// -------------------------------------------------------------------------

	/**
	 * Returns a paginated list of order groups for the authenticated creator's alert history.
	 *
	 * Each group represents one WooCommerce order and includes a summary count of
	 * alerts fired for that order. Individual event details are NOT loaded here —
	 * the client fetches them lazily via handle_history_order() on row expand.
	 *
	 * Creator isolation: every query in Alert_History_Service filters by creator_id.
	 * No events from other creators can appear in the response.
	 *
	 * @return never Calls wp_send_json_success() / wp_send_json_error() and exits.
	 */
	public function handle_history(): void {
		$token   = sanitize_text_field( wp_unslash( $_POST['creator_token'] ?? '' ) );
		$creator = '' !== $token ? $this->service->get_creator_by_token( $token ) : null;

		if ( null === $creator || 'active' !== $creator->status || ! \hash_equals( $creator->creator_access_token, $token ) ) {
			wp_send_json_error( null, 403 );
		}

		$page   = \max( 1, (int) ( $_POST['page'] ?? 1 ) );
		$groups = $this->history_service->get_order_groups( $creator->id, $page );
		$total  = $this->history_service->get_total_orders( $creator->id );
		$pages  = $this->history_service->get_total_pages( $creator->id );

		$groups = \array_map(
			static function ( array $g ): array {
				$g['created_at_formatted'] = (string) wp_date( 'M j, Y · H:i', $g['latest_created_at'] );
				$raw                       = (string) get_post_meta( $g['order_id'], Customer_Username_Field::META_KEY, true );
				$g['customer_username']    = '' !== $raw ? $raw : 'Anonymous';
				return $g;
			},
			$groups
		);

		wp_send_json_success( [
			'groups'       => $groups,
			'total_orders' => $total,
			'page'         => $page,
			'per_page'     => Alert_History_Service::PER_PAGE,
			'total_pages'  => $pages,
		] );
	}

	/**
	 * Returns detailed Alert Event data for a single WooCommerce order.
	 *
	 * Called when the user expands an order row in the history table. The response
	 * includes product name, image HTML, category names, variation ID, quantity, and
	 * formatted timestamp for each Alert Event.
	 *
	 * Creator isolation: the SQL query filters by both creator_id AND order_id, so
	 * only this creator's events for that order are returned. Products belonging to
	 * a different creator in the same WooCommerce order are never visible.
	 *
	 * @return never Calls wp_send_json_success() / wp_send_json_error() and exits.
	 */
	public function handle_history_order(): void {
		$token   = sanitize_text_field( wp_unslash( $_POST['creator_token'] ?? '' ) );
		$creator = '' !== $token ? $this->service->get_creator_by_token( $token ) : null;

		if ( null === $creator || 'active' !== $creator->status || ! \hash_equals( $creator->creator_access_token, $token ) ) {
			wp_send_json_error( null, 403 );
		}

		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid order ID.', 'ccmerch-alert' ) ] );
		}

		$events = $this->history_service->get_events_for_order( $creator->id, $order_id );

		if ( empty( $events ) ) {
			wp_send_json_error( [ 'message' => __( 'No alerts found for this order.', 'ccmerch-alert' ) ] );
		}

		$data = [];
		foreach ( $events as $event ) {
			$image_html = '';
			if ( null !== $event->featured_image_attachment_id ) {
				$image_html = wp_get_attachment_image( $event->featured_image_attachment_id, [ 48, 48 ] );
			}

			$cat_names = [];
			foreach ( $event->category_term_ids as $term_id ) {
				$term = get_term( $term_id, 'product_cat' );
				if ( $term instanceof \WP_Term ) {
					$cat_names[] = $term->name;
				}
			}

			$data[] = [
				'id'                   => $event->id,
				'order_id'             => $event->order_id,
				'product_name'         => $event->product_name,
				'variation_id'         => $event->variation_id,
				'quantity'             => $event->quantity,
				'category_names'       => \implode( ', ', $cat_names ),
				'image_html'           => $image_html,
				'created_at'           => $event->created_at,
				'created_at_formatted' => (string) wp_date( 'M j, Y · H:i', $event->created_at ),
			];
		}

		wp_send_json_success( [ 'events' => $data ] );
	}

	// -------------------------------------------------------------------------
	// Replay handlers
	// -------------------------------------------------------------------------

	/**
	 * Replays a single stored Alert Event on the creator's live overlay.
	 *
	 * Ownership validation is delegated entirely to Replay_Service, which queries the
	 * database with both event_id AND creator_id. A foreign event_id cannot be used
	 * to access another creator's data — the DB returns null and Replay_Service logs
	 * the denial before this handler returns a 404-equivalent error to the client.
	 *
	 * After the overlay replay is queued, the same event also gets a chatbot message
	 * via Chatbot_Message_Service::send_for_events() — the identical shared sender
	 * used for live orders — so replay produces the same one-message-per-product
	 * chatbot output. Chatbot dispatch is best-effort: a failure here must never
	 * turn a successful overlay replay into an error response.
	 *
	 * @return never Calls wp_send_json_success() / wp_send_json_error() and exits.
	 */
	public function handle_replay_event(): void {
		$token   = sanitize_text_field( wp_unslash( $_POST['creator_token'] ?? '' ) );
		$creator = '' !== $token ? $this->service->get_creator_by_token( $token ) : null;

		if ( null === $creator || 'active' !== $creator->status || ! \hash_equals( $creator->creator_access_token, $token ) ) {
			wp_send_json_error( null, 403 );
		}

		$event_id = (int) ( $_POST['event_id'] ?? 0 );
		if ( $event_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid event ID.', 'ccmerch-alert' ) ] );
		}

		if ( ! $this->replay_service->replay_event( $event_id, $creator->id ) ) {
			wp_send_json_error( [ 'message' => __( 'Alert not found or not accessible.', 'ccmerch-alert' ) ] );
		}

		$event = $this->alert_event_repository->get_event_by_id_for_creator( $event_id, $creator->id );
		$this->dispatch_replay_chatbot_messages( $creator->id, null !== $event ? [ $event ] : [] );

		wp_send_json_success( [ 'message' => __( 'Replay queued. Watch your overlay.', 'ccmerch-alert' ) ] );
	}

	/**
	 * Replays all of a creator's alert events for a specific WooCommerce order.
	 *
	 * Cross-creator isolation is automatic: Replay_Service queries by both creator_id
	 * AND order_id, so only this creator's items are replayed. Another creator's
	 * products in the same WooCommerce order are never touched.
	 *
	 * Events play in the original purchase sequence (created_at ASC, id ASC).
	 *
	 * After the overlay replay is queued, every one of those same events also gets
	 * its own chatbot message via Chatbot_Message_Service::send_for_events() — the
	 * identical shared sender used for live orders — so a multi-product order
	 * replay sends one separate chatbot message per product, never a combined one.
	 * Chatbot dispatch is best-effort: a failure here must never turn a successful
	 * overlay replay into an error response.
	 *
	 * @return never Calls wp_send_json_success() / wp_send_json_error() and exits.
	 */
	public function handle_replay_order(): void {
		$token   = sanitize_text_field( wp_unslash( $_POST['creator_token'] ?? '' ) );
		$creator = '' !== $token ? $this->service->get_creator_by_token( $token ) : null;

		if ( null === $creator || 'active' !== $creator->status || ! \hash_equals( $creator->creator_access_token, $token ) ) {
			wp_send_json_error( null, 403 );
		}

		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid order ID.', 'ccmerch-alert' ) ] );
		}

		if ( ! $this->replay_service->replay_order( $order_id, $creator->id ) ) {
			wp_send_json_error( [ 'message' => __( 'No alerts found for this order.', 'ccmerch-alert' ) ] );
		}

		$events = $this->history_service->get_events_for_order( $creator->id, $order_id );
		$this->dispatch_replay_chatbot_messages( $creator->id, $events );

		wp_send_json_success( [ 'message' => __( 'Order replay queued. Watch your overlay.', 'ccmerch-alert' ) ] );
	}

	/**
	 * Sends one chatbot message per given Alert_Event, reusing the exact same
	 * Chatbot_Message_Service::send_for_events() implementation the live-order
	 * pipeline uses. Wrapped in try/catch so a chatbot failure (missing order,
	 * transport error, etc.) never turns an already-successful overlay replay
	 * into an error response to the creator.
	 *
	 * @param int                                  $creator_id Creator profile ID.
	 * @param \CCMerchAlert\Engine\Alert_Event[]    $events     Events to send; no-op when empty.
	 * @return void
	 */
	private function dispatch_replay_chatbot_messages( int $creator_id, array $events ): void {
		if ( empty( $events ) ) {
			return;
		}

		try {
			$order = wc_get_order( $events[0]->order_id );
			if ( $order instanceof \WC_Order ) {
				$this->chatbot_message_service->send_for_events( $creator_id, $order, $events );
			}
		} catch ( \Throwable $e ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				\error_log( '[CCMerch Chatbot] replay chatbot dispatch failed for creator ' . $creator_id . ': ' . $e->getMessage() );
			}
		}
	}
}
