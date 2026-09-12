<?php
/**
 * Chatbot message delivery service.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

use CCMerchAlert\Engine\Alert_Event;
use CCMerchAlert\Engine\Alert_Event_Repository;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends chatbot messages to Twitch channels when WooCommerce orders complete.
 *
 * Hooks into WooCommerce order status transitions at priority 20 (after
 * Order_Logger at priority 10) so Alert_Event rows are always available
 * when this handler runs.
 *
 * One order can contain multiple line items (multiple Alert_Event rows per
 * creator). This service sends ONE chatbot message per Alert_Event, never a
 * single message combining several products. send_for_events() is the single
 * shared implementation of that rule — it is called both from the live-order
 * pipeline (handle_order() below) and from the Creator Portal replay pipeline
 * (Creator_Overlay_Controller::handle_replay_event() / handle_replay_order()),
 * so both flows always produce identical chatbot output for the same events.
 *
 * Chatbot_Message_Log_Repository backfills legacy orders: replaying an order
 * placed before this one-message-per-product behaviour existed will detect
 * any line item that never had a message logged and log + send one for it,
 * while line items that already have a logged message are only ever sent
 * again (replayed), never logged a second time.
 *
 * Rate-limit-safe spacing: send_for_events() pauses MESSAGE_SEND_DELAY_MICROSECONDS
 * between consecutive sends to the same channel — see that method for the exact
 * Twitch limit this is based on. Because that spacing can add up to several
 * seconds for a multi-product order, live orders never send inline on the
 * WooCommerce order-status hook (handle_order() below only schedules a
 * WP-Cron event per creator); the actual paced sending happens in
 * run_scheduled_dispatch(), decoupled from the checkout request entirely, the
 * same deferred-execution pattern Chatbot_Runtime already uses in this module.
 * Replay is a creator-initiated action (not customer checkout), so it calls
 * send_for_events() directly and synchronously — both paths still go through
 * the exact same spacing implementation, they just differ in when it runs.
 *
 * All message sending is wrapped in a try/catch to ensure the chatbot
 * never breaks the checkout flow. Failures are logged to the WordPress
 * debug log when WP_DEBUG_LOG is enabled.
 *
 * Must never break checkout. Must never delay checkout. Must never modify order data.
 */
final class Chatbot_Message_Service {

	/**
	 * WP-Cron hook that defers one creator's paced chatbot dispatch for one
	 * order outside of the triggering WooCommerce request. Scheduled by
	 * handle_order(); handled by run_scheduled_dispatch().
	 */
	public const CRON_DISPATCH_HOOK = 'ccmerch_chatbot_dispatch_order';

	/**
	 * Delay, in microseconds, paused between consecutive chatbot messages sent
	 * to the same Twitch channel within one send_for_events() call.
	 *
	 * Twitch's documented chat rate limit for a regular account — one that is
	 * NOT a moderator, VIP, or "known bot" in the target channel — is 20
	 * messages per rolling 30-second window per channel: roughly one message
	 * every 1.5 seconds sustained. This plugin has no reliable way to know in
	 * advance whether the bot currently holds elevated (moderator/VIP) status
	 * in a given creator's channel, so every batch is paced to the stricter
	 * regular-account limit unconditionally. This is always safe: a bot that
	 * happens to already be a moderator (100 messages / 30 s) simply finishes
	 * sooner than its higher limit would require.
	 */
	public const MESSAGE_SEND_DELAY_MICROSECONDS = 1_500_000;

	/**
	 * @param Chatbot_Settings_Service       $settings_service Business-logic layer for chatbot settings.
	 * @param ChatTransportInterface         $transport        Chat message transport (Helix API or future impl).
	 * @param Alert_Event_Repository         $event_repository For resolving creator IDs from order IDs.
	 * @param Chatbot_Message_Log_Repository $message_log      Tracks which creator + order item pairs
	 *                                                          already have a logged chatbot message —
	 *                                                          see send_for_events().
	 */
	public function __construct(
		private readonly Chatbot_Settings_Service $settings_service,
		private readonly ChatTransportInterface   $transport,
		private readonly Alert_Event_Repository   $event_repository,
		private readonly Chatbot_Message_Log_Repository $message_log,
	) {
		// Priority 20: after Order_Logger (priority 10) has written Alert_Event rows.
		add_action( 'woocommerce_order_status_processing', [ $this, 'handle_order' ], 20 );
		add_action( 'woocommerce_order_status_completed',  [ $this, 'handle_order' ], 20 );
		add_action( self::CRON_DISPATCH_HOOK, [ $this, 'run_scheduled_dispatch' ], 10, 2 );
	}

	/**
	 * Handles a WooCommerce order status transition.
	 *
	 * Only schedules deferred dispatch — never sends inline. send_for_events()
	 * paces multiple messages with MESSAGE_SEND_DELAY_MICROSECONDS between
	 * sends, which can take several seconds for an order with several matched
	 * line items. Sending inline here, on a WooCommerce order-status hook that
	 * can run synchronously within the customer's checkout request, would
	 * violate this class's "must never delay checkout" contract. Scheduling a
	 * WP-Cron event is a fast DB write; the actual paced sending happens in
	 * run_scheduled_dispatch(), fired via WordPress's non-blocking cron spawn,
	 * fully decoupled from this request.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function handle_order( int $order_id ): void {
		try {
			if ( ! $this->settings_service->is_connected() ) {
				return;
			}

			$creator_ids = $this->event_repository->get_creator_ids_for_order( $order_id );
			if ( empty( $creator_ids ) ) {
				return;
			}

			foreach ( $creator_ids as $creator_id ) {
				wp_schedule_single_event( \time(), self::CRON_DISPATCH_HOOK, [ $creator_id, $order_id ] );
			}
		} catch ( \Throwable $e ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch Chatbot] handle_order failed for order ' . $order_id . ': ' . $e->getMessage() );
			}
		}
	}

	/**
	 * WP-Cron callback — sends one creator's chatbot messages for one order.
	 *
	 * Runs in WordPress's separate, non-blocking cron request, entirely
	 * outside the WooCommerce request that scheduled it via handle_order().
	 * This is the only place live-order chatbot messages are actually sent.
	 *
	 * @param int $creator_id Creator profile ID.
	 * @param int $order_id   WooCommerce order ID.
	 * @return void
	 */
	public function run_scheduled_dispatch( int $creator_id, int $order_id ): void {
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			$this->send_for_creator( $creator_id, $order );
		} catch ( \Throwable $e ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch Chatbot] run_scheduled_dispatch failed for creator ' . $creator_id . ', order ' . $order_id . ': ' . $e->getMessage() );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Public API — shared by the live-order pipeline and the replay pipeline
	// -------------------------------------------------------------------------

	/**
	 * Sends one chatbot message per Alert_Event for one creator + one order.
	 *
	 * This is the single implementation of "one message per line item." It is
	 * called from handle_order() for live WooCommerce orders, and from
	 * Creator_Overlay_Controller's replay handlers for replayed orders, so both
	 * flows can never diverge in how many messages they send or how each one
	 * is worded.
	 *
	 * Order-level template variables ($Name, $UserName, $Amount) are read once
	 * from $order and reused for every message; only $Product changes per event.
	 *
	 * Message log (legacy-order backfill): for each event, Chatbot_Message_Log_Repository
	 * is consulted before sending. A line item that has never had a message logged
	 * — most commonly a line item from an order placed before the one-message-per-
	 * product fix, where only the first item was ever actually announced — gets a
	 * log row created the moment it is sent here, live or via replay. A line item
	 * that already has a log row is never logged a second time (no duplicate
	 * record), but is still sent every time this method runs for it — replaying
	 * an order is always expected to (re)announce every one of its products.
	 *
	 * Per-event isolation: each event is sent inside its own try/catch, so a
	 * failure sending event N (network error, Twitch API error, anything
	 * unexpected) can never prevent events N+1..count($events) from being
	 * attempted. The loop always runs to completion for every event supplied,
	 * regardless of how many there are — 1, 3, 10, or more.
	 *
	 * @param int            $creator_id Creator profile ID.
	 * @param \WC_Order      $order      WooCommerce order — source of $Name/$UserName/$Amount.
	 * @param Alert_Event[]  $events     Alert_Event rows to send; one message per row.
	 * @return void
	 */
	public function send_for_events( int $creator_id, \WC_Order $order, array $events ): void {
		if ( empty( $events ) ) {
			return;
		}

		if ( ! $this->settings_service->is_enabled( $creator_id ) ) {
			return;
		}

		$channel = $this->settings_service->get_channel( $creator_id );
		if ( '' === $channel ) {
			return;
		}

		$username   = (string) get_post_meta( (int) $order->get_id(), '_ccmerch_username', true );
		$first_name = (string) $order->get_billing_first_name();
		$amount     = (string) $order->get_formatted_order_total();
		$template   = $this->settings_service->get_template( $creator_id );

		$total = \count( $events );
		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log( '[CCMerch Chatbot] send_for_events — creator ' . $creator_id . ', order ' . (int) $order->get_id() . ', ' . $total . ' event(s) to send.' );
		}

		// Every event is sent inside its own try/catch. A single event throwing
		// (network error, transport failure, anything unexpected) must never
		// abort the remaining events in the batch — every order, regardless of
		// how many line items it has, must have every one of its events attempted.
		foreach ( $events as $index => $event ) {
			try {
				if ( ! $this->message_log->exists( $creator_id, $event->order_item_id ) ) {
					$this->message_log->record( $creator_id, $event->order_id, $event->order_item_id, $event->product_name );
				}

				$message = $this->render_message( $template, $username, $first_name, $event->product_name, $amount );
				$sent    = $this->transport->send( $channel, $message );

				if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					\error_log( \sprintf(
						'[CCMerch Chatbot] send_for_events — event %d/%d (order_item_id=%d, product="%s") sent=%s',
						$index + 1,
						$total,
						$event->order_item_id,
						$event->product_name,
						$sent ? 'true' : 'false'
					) );
				}
			} catch ( \Throwable $e ) {
				if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					\error_log( \sprintf(
						'[CCMerch Chatbot] send_for_events — event %d/%d (order_item_id=%d) threw: %s',
						$index + 1,
						$total,
						$event->order_item_id,
						$e->getMessage()
					) );
				}
				// Deliberately no rethrow — continue to the next event.
			}

			// Rate-limit-safe spacing: pause before the next message in this batch,
			// skipped after the last one so no time is wasted once the batch is
			// done. Applied unconditionally — even after a failed/thrown send —
			// since a failed attempt still counts toward Twitch's rate-limit window.
			if ( $index < $total - 1 ) {
				\usleep( self::MESSAGE_SEND_DELAY_MICROSECONDS );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolves every matched Alert_Event for one creator + order, then delegates
	 * to send_for_events() — the shared per-event sender.
	 *
	 * @param int       $creator_id Creator profile ID.
	 * @param \WC_Order $order      WooCommerce order object.
	 * @return void
	 */
	private function send_for_creator( int $creator_id, \WC_Order $order ): void {
		$events = $this->event_repository->get_events_for_creator_order( $creator_id, (int) $order->get_id() );
		$this->send_for_events( $creator_id, $order, $events );
	}

	/**
	 * Substitutes $Variable placeholders in the message template.
	 *
	 * @param string $template   Message template.
	 * @param string $username   Customer stream username (or 'Anonymous').
	 * @param string $first_name Customer billing first name.
	 * @param string $product    Single product name for this message.
	 * @param string $amount     Formatted order total.
	 * @return string Rendered message.
	 */
	private function render_message(
		string $template,
		string $username,
		string $first_name,
		string $product,
		string $amount,
	): string {
		$username   = '' !== $username ? $username : 'Anonymous';
		$first_name = '' !== $first_name ? $first_name : 'Someone';

		return \str_replace(
			[ '$UserName', '$Name', '$Product', '$Amount' ],
			[
				\html_entity_decode( \strip_tags( $username ),   ENT_QUOTES, 'UTF-8' ),
				\html_entity_decode( \strip_tags( $first_name ), ENT_QUOTES, 'UTF-8' ),
				\html_entity_decode( \strip_tags( $product ),    ENT_QUOTES, 'UTF-8' ),
				\html_entity_decode( \strip_tags( $amount ),     ENT_QUOTES, 'UTF-8' ),
			],
			$template
		);
	}
}
