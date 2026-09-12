<?php
/**
 * Overlay controller.
 *
 * @package CCMerchAlert\Overlay
 */

namespace CCMerchAlert\Overlay;

use CCMerchAlert\OrderLog\Order_Log_Entry;
use CCMerchAlert\OrderLog\Order_Log_Item;
use CCMerchAlert\OrderLog\Order_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all overlay routing, AJAX endpoints, and admin actions.
 *
 * Responsibilities:
 *  - Registers the WordPress rewrite rule for the browser-source URL.
 *  - Intercepts the request on template_redirect and hands off to the renderer.
 *  - Handles the nopriv AJAX poll endpoint (open to the public — the overlay
 *    ID itself is the access token).
 *  - Handles the priv AJAX preview endpoint (admin only, nonce-protected).
 *  - Handles the admin-post action for generating a new overlay ID.
 *
 * Must never: output HTML directly. Must never query the database.
 * Must never contain admin-page rendering (that belongs in Overlay_Admin).
 */
final class Overlay_Controller {

	/** WordPress query var that carries the overlay ID from the rewrite rule. */
	public const QUERY_VAR = 'ccmerch_overlay_id';

	/** Rewrite pattern — matches /ccmerch-alert/overlay/{id}/. */
	public const REWRITE_RULE = '^ccmerch-alert/overlay/([^/]+)/?$';

	/** Rewrite substitution string. */
	public const REWRITE_QUERY = 'index.php?ccmerch_overlay_id=$matches[1]';

	/** AJAX action name for live overlay polling (nopriv — public access). */
	public const AJAX_POLL = 'ccmerch_overlay_poll';

	/** AJAX action name for the SSE persistent stream (nopriv — overlay ID is the token). */
	public const AJAX_STREAM = 'ccmerch_overlay_stream';

	/** AJAX action name for long-poll (nopriv — overlay ID is the token). */
	public const AJAX_LONGPOLL = 'ccmerch_overlay_longpoll';

	/** AJAX action name for admin preview (logged-in only). */
	public const AJAX_PREVIEW = 'ccmerch_overlay_preview';

	/** AJAX action name for sending a test alert to the live overlay (admin only). */
	public const AJAX_TEST_ALERT = 'ccmerch_overlay_test_alert';

	/** AJAX action name for replaying the latest logged order on the live overlay (admin only). */
	public const AJAX_REPLAY_LATEST = 'ccmerch_overlay_replay_latest';

	/** Nonce action used by the admin preview request. */
	public const NONCE_PREVIEW = 'ccmerch_overlay_preview';

	/** Nonce action shared by the test-alert and replay-latest admin endpoints. */
	public const NONCE_TEST = 'ccmerch_overlay_test';

	/** admin_post action that fires when the "Generate New ID" form is submitted. */
	public const ACTION_GENERATE_ID = 'ccmerch_generate_overlay_id';

	/**
	 * @param Overlay_Settings  $settings   Settings store.
	 * @param Order_Repository  $repository Order log data access.
	 * @param Overlay_Renderer  $renderer   HTML output producer.
	 */
	public function __construct(
		private readonly Overlay_Settings $settings,
		private readonly Order_Repository $repository,
		private readonly Overlay_Renderer $renderer,
	) {
		add_action( 'init',              [ $this, 'register_rewrite_rules'  ] );
		add_action( 'wp_loaded',         [ $this, 'maybe_flush_rewrite'     ] );
		add_filter( 'query_vars',        [ $this, 'register_query_vars'     ] );
		add_action( 'template_redirect', [ $this, 'maybe_render_overlay'    ] );

		// Public poll — both logged-in and anonymous callers (the overlay ID is the token).
		add_action( 'wp_ajax_nopriv_' . self::AJAX_POLL,   [ $this, 'handle_poll'   ] );
		add_action( 'wp_ajax_'        . self::AJAX_POLL,   [ $this, 'handle_poll'   ] );

		// SSE stream — same auth model as poll; EventSource uses GET requests.
		add_action( 'wp_ajax_nopriv_' . self::AJAX_STREAM, [ $this, 'handle_stream' ] );
		add_action( 'wp_ajax_'        . self::AJAX_STREAM, [ $this, 'handle_stream' ] );

		// Long poll — fetch()-based; completes each HTTP request fully (Cloudflare-compatible).
		add_action( 'wp_ajax_nopriv_' . self::AJAX_LONGPOLL, [ $this, 'handle_longpoll' ] );
		add_action( 'wp_ajax_'        . self::AJAX_LONGPOLL, [ $this, 'handle_longpoll' ] );

		// Admin-only preview, test/replay triggers, and ID generation.
		add_action( 'wp_ajax_'    . self::AJAX_PREVIEW,        [ $this, 'handle_preview'      ] );
		add_action( 'wp_ajax_'    . self::AJAX_TEST_ALERT,     [ $this, 'handle_test_alert'   ] );
		add_action( 'wp_ajax_'    . self::AJAX_REPLAY_LATEST,  [ $this, 'handle_replay_latest'] );
		add_action( 'admin_post_' . self::ACTION_GENERATE_ID,  [ $this, 'handle_generate_id'  ] );
	}

	// -------------------------------------------------------------------------
	// Rewrite rule
	// -------------------------------------------------------------------------

	/**
	 * Registers the overlay browser-source rewrite rule.
	 *
	 * Called on init every request so the rule is always present in the
	 * in-memory WP_Rewrite object. The persistent cache is rebuilt by
	 * maybe_flush_rewrite() on wp_loaded when needed.
	 *
	 * @return void
	 */
	public function register_rewrite_rules(): void {
		add_rewrite_rule( self::REWRITE_RULE, self::REWRITE_QUERY, 'top' );
	}

	/**
	 * Rebuilds the rewrite rule cache the first time WordPress fully loads
	 * after plugin activation.
	 *
	 * wp_loaded fires after init, meaning every plugin has already registered
	 * its rewrite rules for this request. Flushing here produces a complete,
	 * correct cache that includes the overlay rule.
	 *
	 * The activation hook in ccmerch-alert.php sets 'ccmerch_alert_flush_rewrite'
	 * to '1'. This method reads that flag, flushes once, and deletes the flag so
	 * it never runs again.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite(): void {
		if ( ! get_option( 'ccmerch_alert_flush_rewrite' ) ) {
			return;
		}

		delete_option( 'ccmerch_alert_flush_rewrite' );
		flush_rewrite_rules();

		error_log( '[CCMerch Alert] Rewrite rules flushed after activation — overlay URL is now active.' );
	}

	/**
	 * Exposes the overlay query var to WordPress so get_query_var() works.
	 *
	 * @param  string[] $vars Registered public query variables.
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
	 * Intercepts overlay URL requests and either renders the page or 404s.
	 *
	 * Fires on template_redirect. Does nothing when the overlay query var is
	 * absent so normal WordPress requests are completely unaffected.
	 *
	 * @return void
	 */
	public function maybe_render_overlay(): void {
		$request_id = get_query_var( self::QUERY_VAR, '' );

		if ( '' === $request_id ) {
			return;
		}

		$stored_id = (string) $this->settings->get( Overlay_Settings::FIELD_OVERLAY_ID );
		$match     = '' !== $stored_id && \hash_equals( $stored_id, $request_id );

		// DEBUG: remove once routing is confirmed stable.
		error_log( \sprintf(
			'[CCMerch Alert] Overlay request — request_id="%s" stored_id="%s" id_match=%s',
			$request_id,
			'' !== $stored_id ? $stored_id : '(none)',
			$match ? 'true' : 'false'
		) );

		if ( ! $match ) {
			wp_die( '', '', [ 'response' => 404 ] );
		}

		// Never returns — outputs full HTML document and exits.
		$this->renderer->render_page();
	}

	// -------------------------------------------------------------------------
	// AJAX: live poll
	// -------------------------------------------------------------------------

	/**
	 * Returns new order data for the live overlay to display.
	 *
	 * Authentication: the overlay_id POST parameter is compared against the
	 * stored ID. No nonce is used here because this endpoint must be accessible
	 * to an anonymous OBS browser source.
	 *
	 * Request params (POST):
	 *   overlay_id  string — must match the stored overlay ID.
	 *   since       int    — Unix timestamp; only orders after this are returned.
	 *                        When 0, just returns the current server time.
	 *
	 * @return void
	 */
	public function handle_poll(): void {
		$request_id = sanitize_text_field( wp_unslash( $_POST['overlay_id'] ?? '' ) );
		$stored_id  = (string) $this->settings->get( Overlay_Settings::FIELD_OVERLAY_ID );

		if ( '' === $stored_id || '' === $request_id || ! \hash_equals( $stored_id, $request_id ) ) {
			wp_send_json_error( null, 403 );
		}

		$since = (int) ( $_POST['since'] ?? 0 );
		$now   = \time();

		// Check for a queued test or replay alert written by admin endpoints.
		// Consumed on first read so the queue fires exactly once on the overlay.
		$pending_key = 'ccmerch_overlay_pending_' . $stored_id;
		$pending     = get_transient( $pending_key );
		if ( false !== $pending && \is_array( $pending ) ) {
			delete_transient( $pending_key );
			$queue = $pending['queue'] ?? [];
			wp_send_json_success(
				[
					'has_new'     => ! empty( $queue ),
					'queue'       => $queue,
					'server_time' => $now,
				]
			);
		}

		if ( $since <= 0 ) {
			wp_send_json_success( [ 'has_new' => false, 'queue' => [], 'server_time' => $now ] );
		}

		// get_since() returns newest-first; reverse so the client queue plays
		// chronologically (oldest order first, items in original purchase sequence).
		$entries = $this->repository->get_since( $since, Order_Repository::FILTER_ALL );

		if ( empty( $entries ) ) {
			wp_send_json_success( [ 'has_new' => false, 'queue' => [], 'server_time' => $now ] );
		}

		$queue = [];
		foreach ( \array_reverse( $entries ) as $entry ) {
			foreach ( $this->renderer->render_items( $entry ) as $alert ) {
				$queue[] = $alert;
			}
		}

		wp_send_json_success(
			[
				'has_new'     => ! empty( $queue ),
				'queue'       => $queue,
				'server_time' => $now,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Long poll: fetch()-based request held for up to 8 s (Cloudflare-compatible)
	// -------------------------------------------------------------------------

	/**
	 * Long-poll endpoint for the live overlay browser source.
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
		$stored_id  = (string) $this->settings->get( Overlay_Settings::FIELD_OVERLAY_ID );

		if ( '' === $stored_id || '' === $request_id || ! \hash_equals( $stored_id, $request_id ) ) {
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
		$config_version    = $this->settings->get_config_version();
		$pending_key       = 'ccmerch_overlay_pending_' . $stored_id;
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

			// Priority 2: real WooCommerce orders — rate-limited to 1 s.
			if ( ( $now_f - $last_event_check ) >= 1.0 ) {
				$last_event_check = $now_f;
				$entries          = $this->repository->get_since( $since, Order_Repository::FILTER_ALL );
				if ( ! empty( $entries ) ) {
					foreach ( \array_reverse( $entries ) as $entry ) {
						foreach ( $this->renderer->render_items( $entry ) as $alert ) {
							$queue[] = $alert;
						}
					}
					break;
				}
			}

			// Priority 3: config version change — rate-limited to 1 s.
			if ( ( $now_f - $last_config_check ) >= 1.0 ) {
				$last_config_check      = $now_f;
				$current_config_version = $this->settings->get_config_version();
				if ( $current_config_version !== $config_version ) {
					$config_version = $current_config_version;
					$this->settings->reload_from_db();
					$config_payload = $this->renderer->get_config_payload();
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
	 * Server-Sent Events stream for the live overlay browser source.
	 *
	 * Holds the HTTP connection open and pushes new alert events as they arrive,
	 * eliminating the poll latency gap. The connection closes after a planned
	 * maximum duration and the browser's EventSource API reconnects automatically
	 * using the Last-Event-ID header so no events are missed across reconnects.
	 *
	 * Auth: overlay_id in the query string (same token as handle_poll).
	 * EventSource always uses GET — POST parameters are not available here.
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

		// Validate overlay_id from GET (EventSource is a GET request).
		$request_id = sanitize_text_field( wp_unslash( $_GET['overlay_id'] ?? '' ) );
		$stored_id  = (string) $this->settings->get( Overlay_Settings::FIELD_OVERLAY_ID );

		if ( '' === $stored_id || '' === $request_id || ! \hash_equals( $stored_id, $request_id ) ) {
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

		// On reconnect, EventSource sends Last-Event-ID (the last id: value received).
		// Use it as the `since` cursor so we resume without re-delivering past alerts.
		// Fall back to the ?since= query parameter for the initial connection.
		$last_id = isset( $_SERVER['HTTP_LAST_EVENT_ID'] ) && '' !== $_SERVER['HTTP_LAST_EVENT_ID']
			? (int) $_SERVER['HTTP_LAST_EVENT_ID']
			: -1;
		$since   = $last_id >= 0 ? $last_id : (int) ( $_GET['since'] ?? 0 );

		@\set_time_limit( 0 );
		\ignore_user_abort( true );

		$start   = \time();
		$max_run = 25; // seconds — planned reconnect; kept below common PHP-FPM timeouts.
		$now     = $start;

		// Immediately confirm the connection is live.
		$this->sse_emit( 'connected', [ 'server_time' => $now ], $now );

		// When since == 0 (first-ever connect), anchor to now so we only deliver
		// events that arrive AFTER this connection — not the entire order history.
		if ( $since <= 0 ) {
			$since = $now;
		}

		$pending_key    = 'ccmerch_overlay_pending_' . $stored_id;
		$config_version = $this->settings->get_config_version();

		// Sub-second timing for rate-limiting expensive checks. The loop runs every
		// 100 ms; live-event and config-version checks are gated to 1 s intervals
		// so DB load is unchanged from the original sleep(1) design.
		$last_event_check  = \microtime( true );
		$last_config_check = \microtime( true );
		$last_heartbeat_f  = (float) $start;

		while ( true ) {
			if ( \connection_aborted() ) {
				break;
			}

			if ( ( \time() - $start ) >= $max_run ) {
				// Planned close — EventSource auto-reconnects with Last-Event-ID.
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
				// Priority 2: real WooCommerce orders — checked every 1 s (DB load unchanged).
				$last_event_check = $now_f;
				$entries          = $this->repository->get_since( $since, Order_Repository::FILTER_ALL );
				if ( ! empty( $entries ) ) {
					$queue = [];
					foreach ( \array_reverse( $entries ) as $entry ) {
						foreach ( $this->renderer->render_items( $entry ) as $alert ) {
							$queue[] = $alert;
						}
					}
					$now   = \time();
					$since = $now;
					$this->sse_emit( 'alert', [ 'has_new' => true, 'queue' => $queue, 'test' => false, 'server_time' => $now ], $now );
				}
			}

			// Heartbeat every 15 s keeps the connection alive through proxies.
			if ( ( $now_f - $last_heartbeat_f ) >= 15.0 ) {
				$last_heartbeat_f = $now_f;
				$this->sse_emit( 'heartbeat', [ 'server_time' => $now ], $now );
			}

			// Config version check — rate-limited to 1 s so the direct DB query runs at
			// the same frequency as before despite the shorter loop interval.
			if ( ( $now_f - $last_config_check ) >= 1.0 ) {
				$last_config_check      = $now_f;
				$current_config_version = $this->settings->get_config_version();
				if ( $current_config_version !== $config_version ) {
					$config_version = $current_config_version;
					$this->settings->reload_from_db();
					if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
						\error_log( '[CCMerch Config Sync] Global overlay: config version changed to ' . $current_config_version . ' — emitting config event.' );
					}
					$this->sse_emit( 'config', $this->renderer->get_config_payload(), \time() );
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
	// AJAX: admin preview
	// -------------------------------------------------------------------------

	/**
	 * Returns a rendered alert for the most recent logged order.
	 *
	 * Used by the Overlay admin page to simulate what an alert looks like
	 * without waiting for a real WooCommerce order to arrive.
	 *
	 * Authentication: nonce + manage_options capability.
	 *
	 * @return void
	 */
	public function handle_preview(): void {
		check_ajax_referer( self::NONCE_PREVIEW );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$entries = $this->repository->get_recent( 1 );

		if ( empty( $entries ) ) {
			wp_send_json_error(
				[ 'message' => __( 'No orders in the log yet. Place a test order to see a preview.', 'ccmerch-alert' ) ]
			);
		}

		$alert = $this->renderer->render_alert( $entries[0] );

		wp_send_json_success(
			[
				'image_html'     => $alert['image_html'],
				'text_html'      => $alert['text_html'],
				'secondary_html' => $alert['secondary_html'],
				'sound_url'      => $alert['sound_url'],
			]
		);
	}

	// -------------------------------------------------------------------------
	// AJAX: test alert
	// -------------------------------------------------------------------------

	/**
	 * Queues a test alert for the live overlay to display on its next poll.
	 *
	 * Uses the most recent logged order when one exists. Falls back to a
	 * synthetic entry with placeholder data when the log is empty, so the
	 * button always produces a visible result regardless of order history.
	 *
	 * The rendered alert is written to a transient keyed by the overlay ID.
	 * handle_poll() consumes and deletes it on first read.
	 *
	 * Authentication: nonce + manage_options capability.
	 *
	 * @return void
	 */
	public function handle_test_alert(): void {
		check_ajax_referer( self::NONCE_TEST );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$stored_id = (string) $this->settings->get( Overlay_Settings::FIELD_OVERLAY_ID );
		if ( '' === $stored_id ) {
			wp_send_json_error( [ 'message' => __( 'No overlay ID configured. Generate one first.', 'ccmerch-alert' ) ] );
		}

		$entries = $this->repository->get_recent( 1 );

		if ( ! empty( $entries ) ) {
			$queue = $this->renderer->render_items( $entries[0] );
		} else {
			// Synthetic entry — used only when no orders have been logged yet.
			$mock_item  = new Order_Log_Item( 0, 0, 0, null, 'Test Product', 'DEMO-001', 1, null, [] );
			$mock_entry = new Order_Log_Entry( 0, 0, '0001', new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ), 'Demo', [ $mock_item ] );
			$queue      = $this->renderer->render_items( $mock_entry );
		}

		set_transient( 'ccmerch_overlay_pending_' . $stored_id, [ 'queue' => $queue, 'test' => true ], 300 );

		wp_send_json_success(
			[ 'message' => __( 'Test alert queued. Watch your overlay.', 'ccmerch-alert' ) ]
		);
	}

	// -------------------------------------------------------------------------
	// AJAX: replay latest order
	// -------------------------------------------------------------------------

	/**
	 * Queues the most recent logged order for replay on the live overlay.
	 *
	 * Behaves identically to a real incoming order alert — full template
	 * rendering, image, audio, and animation. Does not create a new log entry.
	 *
	 * Returns an error when no orders exist in the log.
	 *
	 * Authentication: nonce + manage_options capability.
	 *
	 * @return void
	 */
	public function handle_replay_latest(): void {
		check_ajax_referer( self::NONCE_TEST );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$stored_id = (string) $this->settings->get( Overlay_Settings::FIELD_OVERLAY_ID );
		if ( '' === $stored_id ) {
			wp_send_json_error( [ 'message' => __( 'No overlay ID configured. Generate one first.', 'ccmerch-alert' ) ] );
		}

		$entries = $this->repository->get_recent( 1 );

		if ( empty( $entries ) ) {
			wp_send_json_error(
				[ 'message' => __( 'No orders in the log yet. Place a test WooCommerce order first.', 'ccmerch-alert' ) ]
			);
		}

		$queue = $this->renderer->render_items( $entries[0] );
		set_transient( 'ccmerch_overlay_pending_' . $stored_id, [ 'queue' => $queue, 'test' => false ], 300 );

		wp_send_json_success(
			[ 'message' => __( 'Replay queued. It will appear on your overlay within a few seconds.', 'ccmerch-alert' ) ]
		);
	}

	// -------------------------------------------------------------------------
	// Admin-post: generate overlay ID
	// -------------------------------------------------------------------------

	/**
	 * Generates a new random overlay ID and redirects back to the overlay page.
	 *
	 * Fires when the "Generate New Overlay ID" form is submitted. The old
	 * overlay URL is immediately invalidated — any OBS source using the old ID
	 * will receive a 404 on its next load.
	 *
	 * @return void
	 */
	public function handle_generate_id(): void {
		check_admin_referer( self::ACTION_GENERATE_ID );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ccmerch-alert' ) );
		}

		$this->settings->generate_id();

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => Overlay_Settings::PAGE, 'ccmerch-id-generated' => '1' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
