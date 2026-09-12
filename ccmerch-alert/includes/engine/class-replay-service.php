<?php
/**
 * Replay Service.
 *
 * @package CCMerchAlert\Engine
 */

namespace CCMerchAlert\Engine;

use CCMerchAlert\Creators\Creator_Service;
use CCMerchAlert\Overlay\Overlay_Renderer;
use CCMerchAlert\Overlay\Overlay_Template_Engine;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Business-logic layer for replaying stored alert events on a creator's overlay.
 *
 * Replay always uses the full runtime pipeline:
 *   stored Alert_Event → Overlay_Renderer::render_event() → transient → SSE loop → overlay.js
 *
 * Ownership validation is enforced at the SQL level on every operation: every query
 * filters by creator_id, so a creator can never replay an event they do not own even
 * if they supply a foreign event_id or order_id. Application-layer existence checks
 * provide an early-exit path and audit trail before the query executes.
 *
 * Order replay is automatically creator-scoped: if a WooCommerce order contains products
 * from Creator A and Creator B, replaying the order as Creator A only queues Creator A's
 * items. Creator B's items are never visible or accessible.
 *
 * All denied operations are logged to PHP error_log() when WP_DEBUG_LOG is enabled.
 * This log is the single security audit trail for the replay system.
 *
 * Must never: bypass ownership validation.
 * Must never: rebuild replay payloads from WooCommerce at replay time (stored snapshots only).
 * Must never: register WordPress hooks.
 * Must never: render HTML.
 */
final class Replay_Service {

	/**
	 * @param Alert_Event_Repository  $repository      Alert event data access.
	 * @param Creator_Service         $creator_service  Creator overlay profile lookup.
	 * @param Overlay_Template_Engine $engine           Template variable substitution engine.
	 */
	public function __construct(
		private readonly Alert_Event_Repository $repository,
		private readonly Creator_Service $creator_service,
		private readonly Overlay_Template_Engine $engine,
	) {}

	/**
	 * Replays a single stored alert event on the creator's live overlay.
	 *
	 * Ownership is validated via a creator_id-scoped database query before any
	 * rendering or transient write occurs. Returns false (and logs the denial)
	 * when the event does not exist or does not belong to the authenticated creator.
	 *
	 * @param  int $event_id   Alert event primary key (untrusted client-supplied value).
	 * @param  int $creator_id Authenticated creator profile ID — ownership gate.
	 * @return bool            True when the replay was queued; false on any failure.
	 */
	public function replay_event( int $event_id, int $creator_id ): bool {
		$event = $this->repository->get_event_by_id_for_creator( $event_id, $creator_id );

		if ( null === $event ) {
			$this->log_denied( $creator_id, 'event', $event_id, 'event_not_found_or_not_owned' );
			return false;
		}

		$profile = $this->creator_service->get_overlay_profile( $creator_id );

		if ( null === $profile || '' === $profile->get_overlay_id() ) {
			return false;
		}

		$renderer = new Overlay_Renderer( $profile, $this->engine );
		$fragment = $renderer->render_event( $event );

		set_transient(
			'ccmerch_creator_overlay_pending_' . $profile->get_overlay_id(),
			[ 'queue' => [ $fragment ], 'test' => false ],
			300
		);

		return true;
	}

	/**
	 * Replays all of a creator's alert events for a specific WooCommerce order.
	 *
	 * Cross-creator isolation is automatic: the query filters by both creator_id
	 * AND order_id, so only this creator's events for that order are returned.
	 * Another creator's products in the same WooCommerce order are never replayed.
	 *
	 * Events play in the original purchase sequence (created_at ASC, id ASC from DB).
	 *
	 * @param  int $order_id   WooCommerce order ID (untrusted client-supplied value).
	 * @param  int $creator_id Authenticated creator profile ID — ownership + scoping gate.
	 * @return bool            True when at least one event was queued; false otherwise.
	 */
	public function replay_order( int $order_id, int $creator_id ): bool {
		$events = $this->repository->get_events_for_creator_order( $creator_id, $order_id );

		if ( empty( $events ) ) {
			$this->log_denied( $creator_id, 'order', $order_id, 'no_events_found_for_creator_order' );
			return false;
		}

		$profile = $this->creator_service->get_overlay_profile( $creator_id );

		if ( null === $profile || '' === $profile->get_overlay_id() ) {
			return false;
		}

		$renderer = new Overlay_Renderer( $profile, $this->engine );
		$queue    = [];
		foreach ( $events as $event ) {
			$queue[] = $renderer->render_event( $event );
		}

		set_transient(
			'ccmerch_creator_overlay_pending_' . $profile->get_overlay_id(),
			[ 'queue' => $queue, 'test' => false ],
			300
		);

		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Logs a denied replay attempt for security monitoring.
	 *
	 * Fires only when WP_DEBUG_LOG is enabled. Never exposes raw SQL, database
	 * error messages, or PII in the log line. The reason code is a machine-readable
	 * slug that identifies the denial cause without leaking internal system details.
	 *
	 * @param int    $creator_id Authenticated creator who made the request.
	 * @param string $type       'event' or 'order'.
	 * @param int    $target_id  The event_id or order_id that was denied.
	 * @param string $reason     Machine-readable denial reason slug.
	 */
	private function log_denied( int $creator_id, string $type, int $target_id, string $reason ): void {
		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( \sprintf(
				'[CCMerch Alert] Replay denied — creator_id=%d type=%s target_id=%d reason=%s timestamp=%d',
				$creator_id,
				$type,
				$target_id,
				$reason,
				\time()
			) );
		}
	}
}
