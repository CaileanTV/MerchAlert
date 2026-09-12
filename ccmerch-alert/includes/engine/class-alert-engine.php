<?php
/**
 * Alert engine.
 *
 * @package CCMerchAlert\Engine
 */

namespace CCMerchAlert\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central alert dispatcher.
 *
 * The engine is the runtime core of the alert pipeline. It:
 *  1. Opens the registry for external registrations via 'ccmerch_alert_init'.
 *  2. Activates every registered trigger so it can attach its WP/WC hooks.
 *  3. Receives Alert_Context objects from triggers via dispatch() and routes
 *     them through filters → conditions → actions.
 *
 * Future versions will load saved alert configurations from storage and
 * evaluate each one's filters, conditions, and channels here in dispatch().
 *
 * Access the engine at runtime via:
 *     CCMerchAlert\Plugin::get_instance()->engine()
 */
final class Alert_Engine {

	/**
	 * Registry of all triggers, channels, conditions, and filters.
	 *
	 * @var Alert_Registry
	 */
	private Alert_Registry $registry;

	/**
	 * @param Alert_Registry $registry Shared registry instance.
	 */
	public function __construct( Alert_Registry $registry ) {
		$this->registry = $registry;
	}

	// -------------------------------------------------------------------------
	// Accessors
	// -------------------------------------------------------------------------

	/**
	 * Returns the shared registry.
	 *
	 * Useful for reading registered components outside the engine, e.g. in
	 * admin UI screens that list available triggers and channels.
	 *
	 * @return Alert_Registry
	 */
	public function registry(): Alert_Registry {
		return $this->registry;
	}

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	/**
	 * Opens the registry for external registrations then activates all triggers.
	 *
	 * Called once by Plugin::load_components() after all module classes are
	 * instantiated. The action fires before triggers register their WC hooks,
	 * so registrations always beat events.
	 *
	 * External code that registers triggers or channels:
	 *
	 *     add_action( 'ccmerch_alert_init', function( $registry ) {
	 *         $registry->register_trigger( new My_Custom_Trigger() );
	 *         $registry->register_channel( new My_Discord_Channel() );
	 *     } );
	 *
	 * @return void
	 */
	public function init(): void {
		/**
		 * Fires when the alert engine is ready for component registration.
		 *
		 * @param Alert_Registry $registry The shared registry instance.
		 */
		do_action( 'ccmerch_alert_init', $this->registry );

		foreach ( $this->registry->triggers() as $trigger ) {
			$trigger->register( [ $this, 'dispatch' ] );
		}
	}

	// -------------------------------------------------------------------------
	// Dispatch
	// -------------------------------------------------------------------------

	/**
	 * Processes an alert event produced by a trigger.
	 *
	 * Triggers call this method (via the $dispatch callable) when their
	 * underlying WooCommerce event fires. The engine is responsible for:
	 *  - Evaluating saved alert configurations against the context.
	 *  - Running filter and condition checks.
	 *  - Dispatching to the appropriate channels for each matching alert.
	 *
	 * These steps are not yet implemented; this method is the correct
	 * extension point for that logic.
	 *
	 * @param Alert_Context $context Immutable snapshot of the fired event.
	 * @return void
	 */
	public function dispatch( Alert_Context $context ): void {
		/**
		 * Fires immediately when a trigger activates, before any processing.
		 *
		 * Use this hook to inspect or log every raw alert event:
		 *
		 *     add_action( 'ccmerch_alert_dispatch', function( $context, $registry ) {
		 *         error_log( 'Alert fired: ' . $context->trigger_id() );
		 *     }, 10, 2 );
		 *
		 * @param Alert_Context  $context  The event context.
		 * @param Alert_Registry $registry The shared registry.
		 */
		do_action( 'ccmerch_alert_dispatch', $context, $this->registry );

		// Future: load alert configurations from storage.
		// Future: for each config whose trigger matches $context->trigger_id():
		//   - Run registered Filter_Interface checks.
		//   - Run registered Condition_Interface checks.
		//   - Call Channel_Interface::send() for each configured channel.
	}
}
