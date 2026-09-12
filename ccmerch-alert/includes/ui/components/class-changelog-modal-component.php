<?php
/**
 * Changelog Modal component.
 *
 * @package CCMerchAlert\UI\Components
 */

namespace CCMerchAlert\UI\Components;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Changelog trigger button and modal used in the Creator Portal.
 *
 * The component is self-contained:
 *   render_button() — outputs the trigger button (data-ccmerch-changelog attribute).
 *   render_modal()  — outputs the hidden modal HTML; shown/hidden by ccmerch-utils.js.
 *
 * Changelog entries are populated dynamically via a REST API fetch in ccmerch-utils.js.
 * The modal body starts with a loading placeholder; ccmerchFetchChangelog() replaces it
 * with entries from /wp-json/ccmerch/v1/releases on first open.
 *
 * ccmerch-utils.js delegated listeners handle:
 *   open  — click on [data-ccmerch-changelog="1"]
 *   close — click on [data-ccmerch-changelog-close], Escape key
 * No per-page JavaScript is required.
 *
 * Must never: register WordPress hooks.
 * Must never: contain business logic.
 * Must never: be instantiated — all methods are static.
 */
final class Changelog_Modal_Component {

	/**
	 * Renders the Changelog trigger button.
	 *
	 * The button carries data-ccmerch-changelog="1" so ccmerch-utils.js opens
	 * the modal on click without any per-page JavaScript.
	 *
	 * @param string $style Optional inline CSS applied directly to the button element.
	 * @return void
	 */
	public static function render_button( string $style = '' ): void {
		?>
		<button
			type="button"
			class="button ccmerch-changelog-btn"
			data-ccmerch-changelog="1"
			<?php if ( '' !== $style ) : ?>style="<?php echo esc_attr( $style ); ?>"<?php endif; ?>
		><?php esc_html_e( 'Changelog', 'ccmerch-alert' ); ?></button>
		<?php
	}

	/**
	 * Renders the Changelog modal.
	 *
	 * Hidden by default (aria-hidden="true" and no ccmerch-modal--open class).
	 * ccmerch-utils.js toggles the open class and aria-hidden attribute on first open,
	 * then calls ccmerchFetchChangelog() to populate the body via REST API.
	 *
	 * The backdrop element carries data-ccmerch-changelog-close="1" so clicking
	 * outside the dialog box closes the modal (click-outside-to-dismiss).
	 *
	 * @return void
	 */
	public static function render_modal(): void {
		?>
		<div
			id="ccmerch-changelog-modal"
			class="ccmerch-modal"
			aria-hidden="true"
			role="dialog"
			aria-modal="true"
			aria-labelledby="ccmerch-changelog-title"
		>
			<div class="ccmerch-modal__backdrop" data-ccmerch-changelog-close="1"></div>
			<div class="ccmerch-modal__dialog">
				<div class="ccmerch-modal__header">
					<h2 id="ccmerch-changelog-title" class="ccmerch-modal__title">
						<?php esc_html_e( 'Changelog', 'ccmerch-alert' ); ?>
					</h2>
					<button
						type="button"
						class="ccmerch-modal__close"
						data-ccmerch-changelog-close="1"
						aria-label="<?php esc_attr_e( 'Close', 'ccmerch-alert' ); ?>"
					>&#x2715;</button>
				</div>
				<div class="ccmerch-modal__body" id="ccmerch-changelog-body">
					<p class="ccmerch-changelog-loading"><?php esc_html_e( 'Loading…', 'ccmerch-alert' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
}
