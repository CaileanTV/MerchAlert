<?php
/**
 * Alert registry.
 *
 * @package CCMerchAlert\Engine
 */

namespace CCMerchAlert\Engine;

use CCMerchAlert\Engine\Contracts\Channel_Interface;
use CCMerchAlert\Engine\Contracts\Condition_Interface;
use CCMerchAlert\Engine\Contracts\Filter_Interface;
use CCMerchAlert\Engine\Contracts\Trigger_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central registry for all alert engine components.
 *
 * Triggers, channels, conditions, and filters register themselves here during
 * the 'ccmerch_alert_init' action. The Alert_Engine reads the registry during
 * init to wire up hooks, and at dispatch time to route events to channels.
 *
 * Components are keyed by their id() return value, so IDs must be unique
 * within each component type.
 *
 * Example registration (from a third-party plugin):
 *
 *     add_action( 'ccmerch_alert_init', function( $registry ) {
 *         $registry->register_trigger( new My_Custom_Trigger() );
 *         $registry->register_channel( new My_Custom_Channel() );
 *     } );
 */
final class Alert_Registry {

	/** @var array<string, Trigger_Interface> */
	private array $triggers = [];

	/** @var array<string, Channel_Interface> */
	private array $channels = [];

	/** @var array<string, Condition_Interface> */
	private array $conditions = [];

	/** @var array<string, Filter_Interface> */
	private array $filters = [];

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	/**
	 * Registers a trigger.
	 *
	 * If a trigger with the same ID is already registered, it is overwritten.
	 * This allows child plugins to replace built-in triggers.
	 *
	 * @param Trigger_Interface $trigger Trigger to register.
	 * @return void
	 */
	public function register_trigger( Trigger_Interface $trigger ): void {
		$this->triggers[ $trigger->id() ] = $trigger;
	}

	/**
	 * Registers a notification channel.
	 *
	 * @param Channel_Interface $channel Channel to register.
	 * @return void
	 */
	public function register_channel( Channel_Interface $channel ): void {
		$this->channels[ $channel->id() ] = $channel;
	}

	/**
	 * Registers a condition.
	 *
	 * @param Condition_Interface $condition Condition to register.
	 * @return void
	 */
	public function register_condition( Condition_Interface $condition ): void {
		$this->conditions[ $condition->id() ] = $condition;
	}

	/**
	 * Registers an entity filter.
	 *
	 * @param Filter_Interface $filter Filter to register.
	 * @return void
	 */
	public function register_filter( Filter_Interface $filter ): void {
		$this->filters[ $filter->id() ] = $filter;
	}

	// -------------------------------------------------------------------------
	// Accessors
	// -------------------------------------------------------------------------

	/**
	 * Returns all registered triggers, keyed by ID.
	 *
	 * @return array<string, Trigger_Interface>
	 */
	public function triggers(): array {
		return $this->triggers;
	}

	/**
	 * Returns all registered channels, keyed by ID.
	 *
	 * @return array<string, Channel_Interface>
	 */
	public function channels(): array {
		return $this->channels;
	}

	/**
	 * Returns all registered conditions, keyed by ID.
	 *
	 * @return array<string, Condition_Interface>
	 */
	public function conditions(): array {
		return $this->conditions;
	}

	/**
	 * Returns all registered filters, keyed by ID.
	 *
	 * @return array<string, Filter_Interface>
	 */
	public function filters(): array {
		return $this->filters;
	}

	/**
	 * Returns a single trigger by ID, or null if not registered.
	 *
	 * @param string $id Trigger ID.
	 * @return Trigger_Interface|null
	 */
	public function trigger( string $id ): ?Trigger_Interface {
		return $this->triggers[ $id ] ?? null;
	}

	/**
	 * Returns a single channel by ID, or null if not registered.
	 *
	 * @param string $id Channel ID.
	 * @return Channel_Interface|null
	 */
	public function channel( string $id ): ?Channel_Interface {
		return $this->channels[ $id ] ?? null;
	}

	/**
	 * Returns a single condition by ID, or null if not registered.
	 *
	 * @param string $id Condition ID.
	 * @return Condition_Interface|null
	 */
	public function condition( string $id ): ?Condition_Interface {
		return $this->conditions[ $id ] ?? null;
	}

	/**
	 * Returns a single filter by ID, or null if not registered.
	 *
	 * @param string $id Filter ID.
	 * @return Filter_Interface|null
	 */
	public function filter( string $id ): ?Filter_Interface {
		return $this->filters[ $id ] ?? null;
	}
}
