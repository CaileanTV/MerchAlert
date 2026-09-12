<?php
/**
 * Test Controls component.
 *
 * @package CCMerchAlert\UI\Components
 */

namespace CCMerchAlert\UI\Components;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Test Controls card used on the Overlay Configuration page and
 * the Creator Portal.
 *
 * The card contains a heading, a description paragraph, one or more action
 * buttons, and a shared feedback element. Each button carries all AJAX
 * parameters as data attributes so the shared ccmerch-utils.js handler can
 * fire the request without any per-page JavaScript.
 *
 * Auth is context-specific and encoded per-button:
 *   Admin (Overlay page): auth_field = '_ajax_nonce', auth_value = wp_create_nonce()
 *   Portal:               auth_field = 'creator_token', auth_value = access token
 *
 * Button array format:
 *   [
 *     'label'      => string   — button text
 *     'style'      => string   — 'primary' or 'secondary' (default 'primary')
 *     'action'     => string   — WordPress AJAX action name
 *     'auth_field' => string   — POST field name for auth
 *     'auth_value' => string   — auth value (nonce or creator token)
 *     'ajax_url'   => string   — admin-ajax.php URL
 *   ]
 *
 * The ccmerch-utils.js delegated listener handles clicks on
 * [data-ccmerch-test-action] buttons automatically — callers do not need to
 * write any JavaScript for the AJAX interaction.
 *
 * Must never: register WordPress hooks.
 * Must never: contain business logic.
 * Must never: be instantiated — all methods are static.
 */
final class Test_Controls_Component {

	/**
	 * Renders the Test Controls card.
	 *
	 * @param string               $icon_html  Icon HTML (already escaped by the caller).
	 * @param string               $description Card description paragraph text.
	 * @param array<int, array>    $buttons    Array of button configuration arrays.
	 * @param string               $card_style Inline style on the outer card element.
	 * @return void
	 */
	public static function render(
		string $icon_html,
		string $description,
		array  $buttons,
		string $card_style = 'margin-top:20px;'
	): void {
		?>
		<div class="ccmerch-card ccmerch-test-controls" style="<?php echo esc_attr( $card_style ); ?>">
			<div class="ccmerch-card__header">
				<?php echo $icon_html; // phpcs:ignore — already escaped by caller. ?>
				<h2 class="ccmerch-card__title"><?php esc_html_e( 'Test Controls', 'ccmerch-alert' ); ?></h2>
			</div>
			<div class="ccmerch-card__body" style="gap:12px;">
				<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php if ( ! empty( $buttons ) ) : ?>
				<div style="display:flex;gap:12px;flex-wrap:wrap;">
					<?php foreach ( $buttons as $btn ) :
						$btn_class = ( 'secondary' === ( $btn['style'] ?? '' ) )
							? 'button'
							: 'button button-primary';
					?>
					<button
						type="button"
						class="<?php echo esc_attr( $btn_class ); ?>"
						data-ccmerch-test-action="<?php echo esc_attr( $btn['action'] ); ?>"
						data-ccmerch-auth-field="<?php echo esc_attr( $btn['auth_field'] ); ?>"
						data-ccmerch-auth-value="<?php echo esc_attr( $btn['auth_value'] ); ?>"
						data-ccmerch-ajax-url="<?php echo esc_url( $btn['ajax_url'] ); ?>"
						data-ccmerch-sending="<?php esc_attr_e( 'Sending…', 'ccmerch-alert' ); ?>"
						data-ccmerch-success="<?php esc_attr_e( 'Queued! Watch your overlay.', 'ccmerch-alert' ); ?>"
						data-ccmerch-error="<?php esc_attr_e( 'Something went wrong. Please try again.', 'ccmerch-alert' ); ?>"
						data-ccmerch-net-error="<?php esc_attr_e( 'Request failed. Please try again.', 'ccmerch-alert' ); ?>"
					><?php echo esc_html( $btn['label'] ); ?></button>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<div class="ccmerch-test-feedback" style="display:none;font-size:13px;margin-top:4px;"></div>
			</div>
		</div>
		<?php
	}
}
