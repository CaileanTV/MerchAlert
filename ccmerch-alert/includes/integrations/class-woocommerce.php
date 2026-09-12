<?php
/**
 * WooCommerce integration module.
 *
 * @package CCMerchAlert\Integrations
 */

namespace CCMerchAlert\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges CCMerch Alert with WooCommerce.
 *
 * All WooCommerce-specific detection, notices, hooks, and display helpers are
 * isolated here so every other module stays fully independent of WooCommerce
 * being present or absent.
 *
 * Behaviour by WooCommerce state:
 *  - Active   : future integration hooks are registered here.
 *  - Inactive : an admin notice is displayed to prompt installation;
 *               no WooCommerce hooks are registered.
 *
 * Instantiated by {@see \CCMerchAlert\Plugin::load_components()} on every request.
 * Passed as a dependency to {@see \CCMerchAlert\Models\Orders} and
 * {@see \CCMerchAlert\Admin\Admin}.
 */
final class WooCommerce {

	public function __construct() {
		if ( $this->is_active() ) {
			// Future: attach WooCommerce-specific hooks here.
			return;
		}

		// Registering on admin_notices is safe on non-admin requests because
		// WordPress never fires that hook outside the admin context.
		if ( is_admin() ) {
			add_action( 'admin_notices', [ $this, 'render_inactive_notice' ] );
		}
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Returns true when WooCommerce is installed and active.
	 *
	 * Checks for the root WooCommerce class rather than inspecting the active
	 * plugins option, which is more reliable and works correctly on multisite.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return \class_exists( 'WooCommerce' );
	}

	/**
	 * Returns a human-readable connection status label.
	 *
	 * Centralising both strings here means the Admin module can display the
	 * current state without containing any WooCommerce-specific logic.
	 *
	 * @return string Translated status label.
	 */
	public function connection_label(): string {
		return $this->is_active()
			? __( 'WooCommerce Connected', 'ccmerch-alert' )
			: __( 'Not Connected', 'ccmerch-alert' );
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * Renders an admin notice when WooCommerce is not active.
	 *
	 * Scoped to users with the `activate_plugins` capability because lower-
	 * privilege roles cannot act on the notice.
	 *
	 * @return void
	 */
	public function render_inactive_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'CCMerch Alert', 'ccmerch-alert' ); ?></strong>
				&mdash;
				<?php esc_html_e( 'WooCommerce is required to send stock alerts. Please install and activate WooCommerce.', 'ccmerch-alert' ); ?>
			</p>
		</div>
		<?php
	}
}
