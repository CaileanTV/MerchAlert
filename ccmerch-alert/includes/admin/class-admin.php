<?php
/**
 * Admin module.
 *
 * @package CCMerchAlert\Admin
 */

namespace CCMerchAlert\Admin;

use CCMerchAlert\Admin\Dashboard_Orders;
use CCMerchAlert\Integrations\WooCommerce;
use CCMerchAlert\Models\Orders;
use CCMerchAlert\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's admin menu and renders its pages.
 *
 * Each page renderer is a thin shell: structural HTML only, with all form
 * fields delegated to the WordPress Settings API via do_settings_sections().
 *
 * Asset enqueueing is intentionally absent — the Assets module handles that
 * concern and reads the page hook via {@see Admin::get_page_hook()}.
 *
 * Instantiated by {@see \CCMerchAlert\Plugin::load_components()} only when is_admin() is true.
 */
final class Admin {

	/**
	 * Hook suffix for the top-level dashboard page, returned by add_menu_page().
	 *
	 * Exposed via {@see Admin::get_page_hook()} so the Assets module can scope
	 * CSS/JS enqueueing to this page only.
	 *
	 * @var string
	 */
	private string $page_hook = '';

	/**
	 * WooCommerce integration module, used to read connection state for display.
	 *
	 * @var WooCommerce
	 */
	private WooCommerce $woocommerce;

	/**
	 * Settings module, used to read alert-enabled state for display.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Orders module, used to read the monitored order count for display.
	 *
	 * @var Orders
	 */
	private Orders $orders;

	/**
	 * Dashboard recent-orders renderer.
	 *
	 * @var Dashboard_Orders
	 */
	private Dashboard_Orders $dashboard_orders;

	/**
	 * @param WooCommerce      $woocommerce      WooCommerce module instance.
	 * @param Settings         $settings         Settings module instance.
	 * @param Orders           $orders           Orders module instance.
	 * @param Dashboard_Orders $dashboard_orders Recent orders dashboard renderer.
	 */
	public function __construct( WooCommerce $woocommerce, Settings $settings, Orders $orders, Dashboard_Orders $dashboard_orders ) {
		$this->woocommerce      = $woocommerce;
		$this->settings         = $settings;
		$this->orders           = $orders;
		$this->dashboard_orders = $dashboard_orders;

		add_action( 'admin_menu', [ $this, 'register_menu' ] );
	}

	// -------------------------------------------------------------------------
	// Accessors
	// -------------------------------------------------------------------------

	/**
	 * Returns the hook suffix for the plugin's top-level admin page.
	 *
	 * Only populated after the `admin_menu` hook has fired. The Assets module
	 * reads this inside `admin_enqueue_scripts`, which always runs after
	 * `admin_menu`, so the timing is correct.
	 *
	 * @return string
	 */
	public function get_page_hook(): string {
		return $this->page_hook;
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * Registers all admin menu pages for the plugin.
	 *
	 * Creates a top-level entry and a Settings submenu. WordPress automatically
	 * generates a duplicate first submenu item that mirrors the top-level page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->page_hook = (string) add_menu_page(
			__( 'CCMerch Alert', 'ccmerch-alert' ),  // Page title.
			__( 'CCMerch Alert', 'ccmerch-alert' ),  // Menu label.
			'manage_options',                         // Capability.
			'ccmerch-alert',                          // Menu slug.
			[ $this, 'render_main_page' ],            // Callback.
			'dashicons-warning',                      // Icon.
			56                                        // Position.
		);

		add_submenu_page(
			'ccmerch-alert',                                    // Parent slug.
			__( 'CCMerch Alert Settings', 'ccmerch-alert' ),   // Page title.
			__( 'Settings', 'ccmerch-alert' ),                  // Menu label.
			'manage_options',                                    // Capability.
			Settings::PAGE,                                      // Menu slug.
			[ $this, 'render_settings_page' ]                   // Callback.
		);
	}

	// -------------------------------------------------------------------------
	// Page renderers
	// -------------------------------------------------------------------------

	/**
	 * Renders the main admin dashboard page.
	 *
	 * @return void
	 */
	public function render_main_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$wc_active     = $this->woocommerce->is_active();
		$alerts_active = (bool) $this->settings->get( Settings::FIELD_ENABLE_ALERTS );
		$orders_count  = $this->dashboard_orders->count_today();
		?>
		<div class="wrap">

			<h1><?php esc_html_e( 'CCMerch Alert', 'ccmerch-alert' ); ?></h1>

			<div class="ccmerch-grid">

				<div class="ccmerch-card">
					<div class="ccmerch-card__header">
						<span class="dashicons dashicons-store ccmerch-card__icon" aria-hidden="true"></span>
						<h2 class="ccmerch-card__title"><?php esc_html_e( 'WooCommerce Status', 'ccmerch-alert' ); ?></h2>
					</div>
					<div class="ccmerch-card__body">
						<span class="ccmerch-status <?php echo esc_attr( $wc_active ? 'ccmerch-status--connected' : 'ccmerch-status--disconnected' ); ?>">
							<?php echo esc_html( $this->woocommerce->connection_label() ); ?>
						</span>
					</div>
				</div>

				<div class="ccmerch-card">
					<div class="ccmerch-card__header">
						<span class="dashicons dashicons-chart-bar ccmerch-card__icon" aria-hidden="true"></span>
						<h2 class="ccmerch-card__title"><?php esc_html_e( 'Orders Monitored', 'ccmerch-alert' ); ?></h2>
					</div>
					<div class="ccmerch-card__body">
						<span class="ccmerch-stat" id="ccmerch-orders-count"><?php echo esc_html( (string) $orders_count ); ?></span>
						<span class="ccmerch-stat__label"><?php esc_html_e( 'orders in selected range', 'ccmerch-alert' ); ?></span>
					</div>
				</div>

				<div class="ccmerch-card">
					<div class="ccmerch-card__header">
						<span class="dashicons dashicons-warning ccmerch-card__icon" aria-hidden="true"></span>
						<h2 class="ccmerch-card__title"><?php esc_html_e( 'Alert Status', 'ccmerch-alert' ); ?></h2>
					</div>
					<div class="ccmerch-card__body">
						<span class="ccmerch-status <?php echo esc_attr( $alerts_active ? 'ccmerch-status--active' : '' ); ?>">
							<?php echo $alerts_active ? esc_html__( 'Active', 'ccmerch-alert' ) : esc_html__( 'Paused', 'ccmerch-alert' ); ?>
						</span>
					</div>
				</div>

				<div class="ccmerch-card">
					<div class="ccmerch-card__header">
						<span class="dashicons dashicons-admin-plugins ccmerch-card__icon" aria-hidden="true"></span>
						<h2 class="ccmerch-card__title"><?php esc_html_e( 'Plugin Version', 'ccmerch-alert' ); ?></h2>
					</div>
					<div class="ccmerch-card__body">
						<span class="ccmerch-stat"><?php echo esc_html( CCMERCH_ALERT_VERSION ); ?></span>
					</div>
				</div>

			</div>

			<?php $this->dashboard_orders->render(); ?>

		</div>
		<?php
	}

	/**
	 * Renders the Settings admin page.
	 *
	 * The form shell and submit button are the only markup here; all field
	 * HTML is generated by the WordPress Settings API via do_settings_sections()
	 * and submit_button().
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">

			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( Settings::PAGE );
				do_settings_sections( Settings::PAGE );
				submit_button( __( 'Save Settings', 'ccmerch-alert' ) );
				?>
			</form>

		</div>
		<?php
	}
}
