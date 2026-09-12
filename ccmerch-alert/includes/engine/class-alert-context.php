<?php
/**
 * Alert context value object.
 *
 * @package CCMerchAlert\Engine
 */

namespace CCMerchAlert\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable snapshot of a single alert event.
 *
 * Created by a trigger implementation when its underlying WordPress/WooCommerce
 * hook fires. Carries the trigger ID, arbitrary event payload, and a timestamp.
 * Passed through the full alert pipeline: filters → conditions → actions.
 *
 * All properties are read-only; create a new instance for each event.
 */
final class Alert_Context {

	/**
	 * @param string              $trigger_id Identifier of the trigger that produced this context.
	 * @param array<string,mixed> $payload    Arbitrary event data (order, product, customer, etc.).
	 * @param int                 $fired_at   Unix timestamp when the event was captured.
	 */
	public function __construct(
		private readonly string $trigger_id,
		private readonly array  $payload,
		private readonly int    $fired_at = 0
	) {}

	/**
	 * Returns the trigger identifier that produced this context.
	 *
	 * @return string
	 */
	public function trigger_id(): string {
		return $this->trigger_id;
	}

	/**
	 * Returns the full event payload.
	 *
	 * @return array<string,mixed>
	 */
	public function payload(): array {
		return $this->payload;
	}

	/**
	 * Returns the Unix timestamp when the event was captured.
	 *
	 * Returns the current time when the context was constructed with the
	 * default value of 0.
	 *
	 * @return int
	 */
	public function fired_at(): int {
		return 0 === $this->fired_at ? \time() : $this->fired_at;
	}

	/**
	 * Returns a single value from the event payload.
	 *
	 * @param  string $key     Payload key.
	 * @param  mixed  $default Value returned when the key is absent.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		return $this->payload[ $key ] ?? $default;
	}
}
