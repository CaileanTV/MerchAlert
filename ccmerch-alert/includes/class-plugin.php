<?php
/**
 * Core plugin class.
 *
 * @package CCMerchAlert
 */

namespace CCMerchAlert;

use CCMerchAlert\Admin\Admin;
use CCMerchAlert\Admin\Assets;
use CCMerchAlert\Checkout\Customer_Username_Field;
use CCMerchAlert\Checkout\Customer_Username_Resolver;
use CCMerchAlert\Admin\Changelog_Admin;
use CCMerchAlert\Admin\Translations_Admin;
use CCMerchAlert\Api\Releases_Controller;
use CCMerchAlert\Translations\String_Definitions;
use CCMerchAlert\Audio\Audio_Upload_Controller;
use CCMerchAlert\Admin\Creator_Admin;
use CCMerchAlert\Admin\Dashboard_Ajax;
use CCMerchAlert\Admin\Dashboard_Orders;
use CCMerchAlert\Admin\Overlay_Admin;
use CCMerchAlert\Releases\Release_Repository;
use CCMerchAlert\Creators\Creator_Overlay_Controller;
use CCMerchAlert\Creators\Creator_Overlay_Profile;
use CCMerchAlert\Creators\Creator_Overlay_Repository;
use CCMerchAlert\Creators\Creator_Portal_Controller;
use CCMerchAlert\Creators\Creator_Portal_Renderer;
use CCMerchAlert\Creators\Creator_Repository;
use CCMerchAlert\Creators\Creator_Save_Service;
use CCMerchAlert\Creators\Creator_Service;
use CCMerchAlert\Engine\Alert_Engine;
use CCMerchAlert\Engine\Alert_Event_Repository;
use CCMerchAlert\Engine\Alert_History_Service;
use CCMerchAlert\Engine\Alert_Registry;
use CCMerchAlert\Engine\Alert_Router;
use CCMerchAlert\Engine\Replay_Service;
use CCMerchAlert\Integrations\WooCommerce;
use CCMerchAlert\Models\Orders;
use CCMerchAlert\OrderLog\Order_Logger;
use CCMerchAlert\OrderLog\Order_Repository;
use CCMerchAlert\Overlay\Overlay_Config_Renderer;
use CCMerchAlert\Overlay\Overlay_Controller;
use CCMerchAlert\Overlay\Overlay_Renderer;
use CCMerchAlert\Overlay\Overlay_Settings;
use CCMerchAlert\Overlay\Overlay_Template_Engine;
use CCMerchAlert\UI\Component_Registry;
use CCMerchAlert\UI\Components\Audio_Upload_Component;
use CCMerchAlert\UI\Components\Changelog_Modal_Component;
use CCMerchAlert\UI\Components\Font_Loader_Component;
use CCMerchAlert\UI\Components\Test_Controls_Component;
use CCMerchAlert\Admin\Chatbot_Admin;
use CCMerchAlert\Admin\Fonts_Admin;
use CCMerchAlert\Chatbot\Chatbot_Health_Monitor;
use CCMerchAlert\Chatbot\Chatbot_Message_Log_Repository;
use CCMerchAlert\Chatbot\Chatbot_Message_Service;
use CCMerchAlert\Chatbot\Chatbot_Portal_Controller;
use CCMerchAlert\Chatbot\Chatbot_Repository;
use CCMerchAlert\Chatbot\Chatbot_Runtime;
use CCMerchAlert\Chatbot\Chatbot_Scope_Manager;
use CCMerchAlert\Chatbot\Chatbot_Settings_Service;
use CCMerchAlert\Chatbot\Chatbot_Token_Manager;
use CCMerchAlert\Chatbot\Creator_Channel_Auth_Repository;
use CCMerchAlert\Chatbot\Creator_Channel_Auth_Service;
use CCMerchAlert\Chatbot\HelixChatTransport;
use CCMerchAlert\Chatbot\Twitch_Auth_Service;
use CCMerchAlert\Chatbot\Twitch_Chat_Service;
use CCMerchAlert\Fonts\Font_Library;
use CCMerchAlert\Fonts\Google_Fonts_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central orchestrator for the CCMerch Alert plugin.
 *
 * Responsibilities:
 *  - Loads every module class file.
 *  - Instantiates each module with its required dependencies.
 *  - Owns the text-domain registration.
 *  - Provides the shared Alert_Registry and Alert_Engine to the rest of the
 *    codebase via accessor methods.
 *
 * Module map:
 *
 *  Settings           — options storage and WordPress Settings API registration
 *  Alert_Registry     — holds all registered triggers, channels, conditions
 *  Alert_Engine       — dispatches events through the alert pipeline
 *  WooCommerce        — WC detection, status labels, admin notice
 *  Orders             — WC order data access layer
 *  Admin              — admin menu, page renderers  (admin-only)
 *  Assets             — CSS/JS enqueueing            (admin-only)
 *
 * All other logic lives in dedicated module classes. Obtain the single
 * shared instance via Plugin::get_instance().
 */
final class Plugin {

	/** @var self|null Singleton instance. */
	private static ?self $instance = null;

	/** @var Settings Plugin settings module. */
	private Settings $settings;

	/** @var Alert_Registry Shared component registry. */
	private Alert_Registry $registry;

	/** @var Alert_Engine Central alert dispatcher. */
	private Alert_Engine $engine;

	/** @var WooCommerce WooCommerce integration module. */
	private WooCommerce $woocommerce;

	/** @var Orders Order-monitoring data access module. */
	private Orders $orders;

	/** @var Order_Repository Order log data repository. */
	private Order_Repository $order_repository;

	/** @var Order_Logger Order log hook subscriber. */
	private Order_Logger $order_logger;

	/** @var Overlay_Settings Overlay settings store — always loaded. */
	private Overlay_Settings $overlay_settings;

	/** @var Overlay_Controller Overlay route + AJAX handler — always loaded. */
	private Overlay_Controller $overlay_controller;

	/** @var Creator_Repository Creator data access layer — always loaded. */
	private Creator_Repository $creator_repository;

	/** @var Creator_Overlay_Repository Creator overlay settings data access — always loaded. */
	private Creator_Overlay_Repository $creator_overlay_repository;

	/** @var Creator_Service Creator business-logic layer — always loaded. */
	private Creator_Service $creator_service;

	/** @var Creator_Portal_Controller Creator portal route + rendering — always loaded. */
	private Creator_Portal_Controller $creator_portal_ctrl;

	/** @var Creator_Overlay_Controller Creator overlay route + AJAX — always loaded. */
	private Creator_Overlay_Controller $creator_overlay_ctrl;

	/** @var Google_Fonts_Provider Google Fonts catalog provider — always loaded. */
	private Google_Fonts_Provider $google_fonts_provider;

	/** @var Font_Library Approved-font library — always loaded. */
	private Font_Library $font_library;

	/** @var Twitch_Auth_Service Twitch auth service — always loaded. */
	private Twitch_Auth_Service $twitch_auth_service;

	/** @var Admin|null Admin module — null on the front end. */
	private ?Admin $admin = null;

	/** @var Assets|null Asset module — null on the front end. */
	private ?Assets $assets = null;

	private function __construct() {}

	private function __clone(): void {}

	/** @throws \RuntimeException Always — singletons cannot be unserialized. */
	public function __wakeup(): never {
		throw new \RuntimeException( 'Plugin instance cannot be unserialized.' );
	}

	/**
	 * Returns the single shared plugin instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boots the plugin.
	 *
	 * Called exactly once via the `plugins_loaded` hook in the main plugin file.
	 * Loads files first so all class names are defined before instantiation.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->load_files();
		$this->load_textdomain();
		$this->load_components();
	}

	// -------------------------------------------------------------------------
	// Module accessors
	// -------------------------------------------------------------------------

	/**
	 * Returns the Settings module.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Returns the shared alert component registry.
	 *
	 * Use this to inspect registered triggers, channels, and conditions, e.g.
	 * when rendering an admin UI that lists available options.
	 *
	 * @return Alert_Registry
	 */
	public function registry(): Alert_Registry {
		return $this->registry;
	}

	/**
	 * Returns the Alert_Engine.
	 *
	 * @return Alert_Engine
	 */
	public function engine(): Alert_Engine {
		return $this->engine;
	}

	/**
	 * Returns the WooCommerce integration module.
	 *
	 * @return WooCommerce
	 */
	public function woocommerce(): WooCommerce {
		return $this->woocommerce;
	}

	/**
	 * Returns the Orders module.
	 *
	 * @return Orders
	 */
	public function orders(): Orders {
		return $this->orders;
	}

	/**
	 * Returns the Order Log repository.
	 *
	 * The primary data source for the future Alert Engine when evaluating
	 * order-based triggers without re-querying WooCommerce.
	 *
	 * @return Order_Repository
	 */
	public function order_repository(): Order_Repository {
		return $this->order_repository;
	}

	/**
	 * Returns the Admin module, or null when running on the front end.
	 *
	 * @return Admin|null
	 */
	public function admin(): ?Admin {
		return $this->admin;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Requires all module class files.
	 *
	 * Load order matters: engine contracts must be defined before the concrete
	 * engine classes that reference them, and all includes/ classes must be
	 * loaded before subdirectory classes that depend on them.
	 *
	 * @return void
	 */
	private function load_files(): void {
		$dir = __DIR__;

		// Root modules.
		require_once $dir . '/class-settings.php';

		// Engine contracts — interfaces must be defined before implementations.
		require_once $dir . '/engine/contracts/interface-trigger.php';
		require_once $dir . '/engine/contracts/interface-condition.php';
		require_once $dir . '/engine/contracts/interface-filter.php';
		require_once $dir . '/engine/contracts/interface-action.php';
		require_once $dir . '/engine/contracts/interface-channel.php';

		// Engine classes.
		require_once $dir . '/engine/class-alert-context.php';
		require_once $dir . '/engine/class-alert-registry.php';
		require_once $dir . '/engine/class-alert-engine.php';
		require_once $dir . '/engine/class-alert-event.php';
		require_once $dir . '/engine/class-alert-event-installer.php';
		require_once $dir . '/engine/class-alert-event-repository.php';
		require_once $dir . '/engine/class-alert-history-service.php';
		require_once $dir . '/engine/class-replay-service.php';

		// Integrations.
		require_once $dir . '/integrations/class-woocommerce.php';

		// Models.
		require_once $dir . '/models/class-orders.php';

		// Order Log — value objects must be defined before the repository that returns them.
		require_once $dir . '/order-log/class-order-log-entry.php';
		require_once $dir . '/order-log/class-order-log-item.php';
		require_once $dir . '/order-log/class-order-repository.php';
		require_once $dir . '/order-log/class-order-logger.php';

		// UI framework — no dependencies; must be loaded before any class that uses them.
		require_once $dir . '/ui/class-component-registry.php';
		require_once $dir . '/ui/class-asset-loader.php';
		require_once $dir . '/ui/components/class-test-controls-component.php';
		require_once $dir . '/ui/components/class-audio-upload-component.php';
		require_once $dir . '/ui/components/class-changelog-modal-component.php';
		require_once $dir . '/ui/components/class-font-loader-component.php';

		// Fonts module — always loaded (provider is used by portal renderer on front end).
		require_once $dir . '/fonts/class-google-fonts-installer.php';
		require_once $dir . '/fonts/class-google-fonts-provider.php';
		require_once $dir . '/fonts/class-font-library.php';
		require_once $dir . '/admin/class-fonts-admin.php';

		// Auth module — must be loaded before any module that uses Auth_Context.
		require_once $dir . '/auth/class-auth-context.php';

		// Audio module — nopriv endpoints must be reachable on front-end requests.
		require_once $dir . '/audio/class-audio-upload-controller.php';

		// Releases module — always loaded (REST API + admin page need this on every request).
		require_once $dir . '/releases/class-release.php';
		require_once $dir . '/releases/class-feature-tag-registry.php';
		require_once $dir . '/releases/class-release-repository.php';

		// REST API controllers — always loaded (rest_api_init fires on front-end and admin).
		require_once $dir . '/api/class-releases-controller.php';

		// Overlay contract — must be defined before Overlay_Settings and Creator_Overlay_Profile.
		require_once $dir . '/overlay/interface-overlay-settings-contract.php';

		// Creators module — value object before repository, repository before service,
		// service before portal classes that depend on it.
		require_once $dir . '/creators/class-creator-profile.php';
		require_once $dir . '/creators/class-creator-repository.php';
		require_once $dir . '/creators/class-creator-overlay-repository.php';
		require_once $dir . '/creators/class-creator-overlay-profile.php';
		require_once $dir . '/creators/class-creator-service.php';
		// Creator_Save_Service must load before Creator_Portal_Renderer which references its constants.
		require_once $dir . '/creators/class-creator-save-service.php';
		require_once $dir . '/creators/class-creator-portal-renderer.php';
		require_once $dir . '/creators/class-creator-portal-controller.php';
		require_once $dir . '/creators/class-creator-overlay-controller.php';

		// Alert Router — depends on both engine classes and creator classes; loaded last.
		require_once $dir . '/engine/class-alert-router.php';

		// Admin UI — loaded unconditionally so WordPress can resolve these
		// class names in edge cases (e.g. cached admin bar on front end).
		require_once $dir . '/admin/class-admin.php';
		require_once $dir . '/admin/class-assets.php';
		require_once $dir . '/admin/class-dashboard-orders.php';
		require_once $dir . '/admin/class-dashboard-ajax.php';
		require_once $dir . '/admin/class-overlay-admin.php';
		require_once $dir . '/admin/class-creator-admin.php';
		require_once $dir . '/admin/class-changelog-admin.php';
		require_once $dir . '/admin/class-translations-admin.php';

		// Translations module — String_Definitions must load before load_components()
		// calls register_all(). The 3 core translation classes are loaded earlier in
		// ccmerch-alert.php so the ccmerch_translate() helper is globally available.
		require_once $dir . '/translations/class-string-definitions.php';

		// Overlay module — always loaded (controller registers rewrite rule on init).
		require_once $dir . '/overlay/class-overlay-settings.php';
		require_once $dir . '/overlay/class-overlay-config-renderer.php';
		require_once $dir . '/overlay/class-overlay-template-engine.php';
		require_once $dir . '/overlay/class-overlay-renderer.php';
		require_once $dir . '/overlay/class-overlay-controller.php';

		// Checkout module — loaded after overlay so Customer_Username_Resolver can
		// reference Overlay_Template_Engine in its register_on_engine() signature.
		require_once $dir . '/checkout/class-customer-username-field.php';
		require_once $dir . '/checkout/class-customer-username-resolver.php';

		// Chatbot module — always loaded (Twitch auth AJAX callback and creator portal
		// chatbot endpoints must be reachable on all requests, not just admin).
		require_once $dir . '/chatbot/class-chatbot-installer.php';
		require_once $dir . '/chatbot/class-chatbot-message-log-installer.php';
		require_once $dir . '/chatbot/class-chatbot-message-log-repository.php';
		require_once $dir . '/chatbot/class-chatbot-creator-settings.php';
		require_once $dir . '/chatbot/class-chatbot-repository.php';
		require_once $dir . '/chatbot/class-chatbot-scope-manager.php';
		require_once $dir . '/chatbot/class-twitch-auth-service.php';
		require_once $dir . '/chatbot/class-twitch-chat-service.php';
		require_once $dir . '/chatbot/class-chatbot-settings-service.php';
		require_once $dir . '/chatbot/class-chatbot-health-monitor.php';
		require_once $dir . '/chatbot/class-chatbot-token-manager.php';
		require_once $dir . '/chatbot/class-chatbot-runtime.php';
		require_once $dir . '/chatbot/class-creator-channel-auth.php';
		require_once $dir . '/chatbot/class-creator-channel-auth-repository.php';
		require_once $dir . '/chatbot/class-creator-channel-auth-service.php';
		require_once $dir . '/chatbot/interface-chat-transport.php';
		require_once $dir . '/chatbot/class-helix-chat-transport.php';
		require_once $dir . '/chatbot/class-chatbot-message-service.php';
		require_once $dir . '/chatbot/class-chatbot-portal-controller.php';
		require_once $dir . '/admin/class-chatbot-admin.php';
	}

	/**
	 * Registers the plugin text domain for translations.
	 *
	 * @return void
	 */
	private function load_textdomain(): void {
		load_plugin_textdomain(
			'ccmerch-alert',
			false,
			\dirname( plugin_basename( CCMERCH_ALERT_FILE ) ) . '/languages'
		);
	}

	/**
	 * Instantiates every module and wires up dependencies.
	 *
	 * Boot order:
	 *  1. Settings — no dependencies.
	 *  2. Alert_Registry — no dependencies.
	 *  3. Alert_Engine — receives registry; fires 'ccmerch_alert_init' inside
	 *     init() so external code can register triggers/channels before they
	 *     attach their WC hooks.
	 *  4. WooCommerce — no plugin dependencies; attaches WC hooks if active.
	 *  5. Orders — depends on WooCommerce.
	 *  6. Admin + Assets — admin-only; Admin depends on WooCommerce, Settings,
	 *     Orders; Assets depends on Admin for the page-hook comparison.
	 *
	 * @return void
	 */
	private function load_components(): void {
		// Register all translatable strings before any module calls ccmerch_translate().
		// String_Definitions has no dependencies — safe to call first.
		String_Definitions::register_all();

		// Fonts module — always loaded (Creator Portal reads approved fonts on front-end requests).
		$this->google_fonts_provider = new Google_Fonts_Provider();
		$this->font_library          = new Font_Library( $this->google_fonts_provider );

		// Core modules — always loaded.
		$this->settings         = new Settings();
		$this->registry         = new Alert_Registry();
		$this->engine           = new Alert_Engine( $this->registry );
		$this->woocommerce      = new WooCommerce();
		$this->orders           = new Orders( $this->woocommerce );
		$this->order_repository = new Order_Repository();

		// Creator repos are instantiated here (before Order_Logger) so Alert_Router
		// can be constructed and injected into Order_Logger as its routing callback.
		$this->creator_repository         = new Creator_Repository();
		$this->creator_overlay_repository = new Creator_Overlay_Repository();

		// Alert routing engine — resolves per-item WC order events to creator queues.
		// "Resolve once. Store once. Render many."
		$alert_event_repository = new Alert_Event_Repository();
		$alert_router           = new Alert_Router(
			$this->creator_repository,
			$this->creator_overlay_repository,
			$alert_event_repository
		);

		// Order Logger receives the router; after a successful log write it calls
		// $alert_router->process_order() to fan out one Alert_Event per item.
		$this->order_logger = new Order_Logger( $this->woocommerce, $this->order_repository, $alert_router );

		// Boot the engine: opens registration, then activates all triggers.
		$this->engine->init();

		// Overlay module — always instantiated (controller registers the rewrite
		// rule and nopriv AJAX endpoint on front-end requests too).
		$this->overlay_settings = new Overlay_Settings();
		$overlay_engine         = new Overlay_Template_Engine();
		Customer_Username_Resolver::register_on_engine( $overlay_engine );
		$overlay_renderer         = new Overlay_Renderer( $this->overlay_settings, $overlay_engine );
		$this->overlay_controller = new Overlay_Controller( $this->overlay_settings, $this->order_repository, $overlay_renderer );

		// Creators module — always instantiated so Creator_Service and all portal/overlay
		// controllers are available on both front-end and admin requests.
		$this->creator_service = new Creator_Service(
			$this->creator_repository,
			$this->creator_overlay_repository,
			$this->woocommerce
		);
		// Chatbot module — always instantiated so Twitch auth callback and creator
		// portal chatbot endpoints are reachable on every request.
		$chatbot_repository        = new Chatbot_Repository();
		$this->twitch_auth_service = new Twitch_Auth_Service();
		$health_monitor            = new Chatbot_Health_Monitor();
		$twitch_chat_service       = new Twitch_Chat_Service( $this->twitch_auth_service, $health_monitor );
		$chatbot_settings_service  = new Chatbot_Settings_Service( $chatbot_repository, $this->twitch_auth_service );
		$token_manager             = new Chatbot_Token_Manager( $this->twitch_auth_service, $health_monitor );
		$chatbot_runtime           = new Chatbot_Runtime( $token_manager );
		$chatbot_runtime->init();

		$channel_auth_repository   = new Creator_Channel_Auth_Repository();
		$channel_auth_service      = new Creator_Channel_Auth_Service(
			$channel_auth_repository,
			$this->twitch_auth_service,
			$this->creator_service
		);
		$channel_auth_service->register();

		$helix_transport         = new HelixChatTransport( $twitch_chat_service );
		$chatbot_message_log     = new Chatbot_Message_Log_Repository();
		$chatbot_message_service = new Chatbot_Message_Service( $chatbot_settings_service, $helix_transport, $alert_event_repository, $chatbot_message_log );
		new Chatbot_Portal_Controller( $chatbot_settings_service, $this->creator_service, $twitch_chat_service );

		$creator_portal_renderer    = new Creator_Portal_Renderer( $this->creator_service, $this->font_library, $chatbot_settings_service, $channel_auth_service );
		$this->creator_portal_ctrl  = new Creator_Portal_Controller( $this->creator_repository, $creator_portal_renderer );

		$history_service = new Alert_History_Service( $alert_event_repository );

		$replay_engine = new Overlay_Template_Engine();
		Customer_Username_Resolver::register_on_engine( $replay_engine );
		$replay_service = new Replay_Service( $alert_event_repository, $this->creator_service, $replay_engine );

		$creator_overlay_engine = new Overlay_Template_Engine();
		Customer_Username_Resolver::register_on_engine( $creator_overlay_engine );
		$this->creator_overlay_ctrl = new Creator_Overlay_Controller(
			$this->creator_service,
			$this->order_repository,
			$creator_overlay_engine,
			$alert_event_repository, // isolated per-creator queue for live poll
			$history_service,
			$replay_service,
			$chatbot_message_service // shared per-event sender, reused by replay
		);

		// Audio upload controller — always instantiated (creator nopriv endpoints
		// must be reachable on front-end requests, not just inside is_admin()).
		new Audio_Upload_Controller( $this->settings, $this->creator_service );

		// Creator Save Service — AJAX save endpoints for Creator Portal settings and
		// overlay ID generation. Both nopriv + priv hooks registered so the save works
		// regardless of WordPress login state. Must be instantiated before admin-only
		// block so front-end creator saves are reachable.
		( new Creator_Save_Service( $this->creator_service ) )->register();

		// Checkout module — registers the optional customer username field on
		// WooCommerce checkout when enabled in settings. Always instantiated so
		// the woocommerce_checkout_fields filter is reachable on front-end requests.
		new Customer_Username_Field( $this->settings );

		// Releases module + REST API endpoint — instantiated unconditionally so
		// rest_api_init fires on both admin and front-end requests.
		$release_repository = new Release_Repository();
		( new Releases_Controller( $release_repository ) )->register();

		// Register all reusable UI components so future code can discover and
		// render them via Component_Registry::render() without knowing the
		// implementation class. Existing callers may still call the static
		// renderer methods directly — both paths are valid.
		Component_Registry::register( 'browser-source-card', [ Overlay_Config_Renderer::class, 'render_browser_source_card' ] );
		Component_Registry::register( 'appearance-fields',   [ Overlay_Config_Renderer::class, 'render_appearance_fields'   ] );
		Component_Registry::register( 'overlay-designer',    [ Overlay_Config_Renderer::class, 'render_designer'            ] );
		Component_Registry::register( 'audio-section',       [ Overlay_Config_Renderer::class, 'render_audio_section'       ] );
		Component_Registry::register( 'test-controls',       [ Test_Controls_Component::class, 'render'                     ] );
		Component_Registry::register( 'audio-upload',        [ Audio_Upload_Component::class,      'render'       ] );
		Component_Registry::register( 'changelog-button',    [ Changelog_Modal_Component::class,  'render_button' ] );
		Component_Registry::register( 'changelog-modal',     [ Changelog_Modal_Component::class,  'render_modal'  ] );

		// Admin-only modules.
		if ( is_admin() ) {
			$dashboard_orders = new Dashboard_Orders( $this->order_repository, $this->woocommerce );
			$dashboard_ajax   = new Dashboard_Ajax( $this->order_repository, $dashboard_orders, $this->settings );
			$this->admin      = new Admin( $this->woocommerce, $this->settings, $this->orders, $dashboard_orders );
			$this->assets     = new Assets( $this->admin, $dashboard_ajax );
			new Overlay_Admin( $this->overlay_settings, $this->order_repository, $this->font_library );
			new Creator_Admin( $this->creator_service );
			new Changelog_Admin( $release_repository );
			new Translations_Admin();
			( new Fonts_Admin( $this->google_fonts_provider, $this->font_library, $this->settings ) )->register();
			new Chatbot_Admin( $this->twitch_auth_service, $chatbot_settings_service, $this->creator_repository, $twitch_chat_service, $health_monitor, $token_manager, $channel_auth_service );
		}
	}
}
