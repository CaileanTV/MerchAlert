<?php
/**
 * Component registry.
 *
 * @package CCMerchAlert\UI
 */

namespace CCMerchAlert\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central registry for all reusable UI components.
 *
 * Every UI feature used in more than one interface must be registered here
 * before use. Registration maps a stable string ID to the callable that
 * renders the component.
 *
 * Registration (done in Plugin::load_components()):
 *
 *   Component_Registry::register(
 *       'overlay-designer',
 *       [ Overlay_Config_Renderer::class, 'render_designer' ]
 *   );
 *
 * Rendering from any context:
 *
 *   Component_Registry::render( 'overlay-designer', $layout );
 *
 * Because registration accepts any callable, a future module can replace a
 * built-in component by re-registering under the same ID without modifying
 * any existing file (open/closed principle).
 *
 * Must never: instantiate components — all registered callables must be
 * static methods or closures.
 * Must never: contain business logic.
 * Must never: be instantiated — all methods are static.
 */
final class Component_Registry {

	/** @var array<string, callable> Map of component IDs to render callables. */
	private static array $components = [];

	/**
	 * Registers a component render callable under the given ID.
	 *
	 * Overwrites any previously registered callable for the same ID, allowing
	 * future code to replace built-in components without modifying existing
	 * files.
	 *
	 * @param string   $id       Stable string identifier for the component.
	 * @param callable $callable Static method or closure that renders the component.
	 * @return void
	 */
	public static function register( string $id, callable $callable ): void {
		self::$components[ $id ] = $callable;
	}

	/**
	 * Renders a registered component, forwarding any extra arguments to the callable.
	 *
	 * @param string $id      Component identifier.
	 * @param mixed  ...$args Arguments forwarded to the component callable.
	 * @return void
	 * @throws \InvalidArgumentException When the component ID is not registered.
	 */
	public static function render( string $id, mixed ...$args ): void {
		if ( ! isset( self::$components[ $id ] ) ) {
			throw new \InvalidArgumentException(
				"CCMerch Alert: no component registered with ID '{$id}'."
			);
		}

		\call_user_func_array( self::$components[ $id ], $args );
	}

	/**
	 * Returns true when a component is registered under the given ID.
	 *
	 * @param string $id Component identifier.
	 * @return bool
	 */
	public static function has( string $id ): bool {
		return isset( self::$components[ $id ] );
	}

	/**
	 * Returns all registered component IDs.
	 *
	 * Useful for admin UIs that need to list or inspect available components.
	 *
	 * @return string[]
	 */
	public static function ids(): array {
		return \array_keys( self::$components );
	}
}
