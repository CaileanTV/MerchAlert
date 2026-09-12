<?php
/**
 * Creator Portal renderer.
 *
 * @package CCMerchAlert\Creators
 */

namespace CCMerchAlert\Creators;

use CCMerchAlert\Audio\Audio_Upload_Controller;
use CCMerchAlert\Fonts\Font_Library;
use CCMerchAlert\Overlay\Overlay_Config_Renderer;
use CCMerchAlert\Overlay\Overlay_Settings;
use CCMerchAlert\UI\Asset_Loader;
use CCMerchAlert\UI\Components\Changelog_Modal_Component;
use CCMerchAlert\UI\Components\Test_Controls_Component;
use CCMerchAlert\Chatbot\Chatbot_Portal_Controller;
use CCMerchAlert\Chatbot\Chatbot_Settings_Service;
use CCMerchAlert\Chatbot\Creator_Channel_Auth_Service;
use CCMerchAlert\Creators\Creator_Save_Service;
use CCMerchAlert\Translations\Translation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces the standalone Creator Portal HTML page.
 *
 * The portal is a self-contained HTML document served at the creator's access
 * token URL. It renders the complete overlay configuration UI — identical to the
 * admin Overlay page — but loaded with the creator's own Overlay Profile instead
 * of the global overlay settings.
 *
 * All form sections (hidden appearance fields, 1920×1080 Overlay Designer canvas,
 * full Audio section with category map) are produced by Overlay_Config_Renderer
 * static methods — the same shared implementation used by Overlay_Admin.
 *
 * Audio picker buttons are not available on the portal because creators may not
 * be WordPress users with media library access. Number inputs are shown instead.
 * All other sections, controls, and canvas functionality are identical.
 *
 * Test Controls are rendered via Test_Controls_Component::render() so the same
 * card is shared with Overlay_Admin. Auth is encoded per-button as data attributes;
 * the delegated listener in ccmerch-utils.js handles the AJAX call — no per-page
 * JavaScript is required for the test button.
 *
 * Settings are saved via Creator_Save_Service AJAX endpoints (both nopriv + priv
 * hooks) so the save works regardless of WordPress login state. All responses are
 * JSON; the page never reloads on save — feedback is shown inline.
 *
 * Must never: read from the database directly.
 * Must never: register WordPress hooks.
 * Must never: output WordPress admin chrome.
 * Must never: duplicate HTML that belongs in Overlay_Config_Renderer.
 */
final class Creator_Portal_Renderer {

	/**
	 * @param Creator_Service              $service                  Creator business-logic layer.
	 * @param Font_Library                 $font_library             Approved-font library for font selector injection.
	 * @param Chatbot_Settings_Service     $chatbot_settings_service Chatbot settings for the portal tab.
	 * @param Creator_Channel_Auth_Service $channel_auth_service     Channel:bot authorization service.
	 */
	public function __construct(
		private readonly Creator_Service              $service,
		private readonly Font_Library                 $font_library,
		private readonly Chatbot_Settings_Service     $chatbot_settings_service,
		private readonly Creator_Channel_Auth_Service $channel_auth_service,
	) {}

	/**
	 * Renders the complete Creator Portal HTML document and exits.
	 *
	 * @param  Creator_Profile $creator Validated, active creator profile.
	 * @return never
	 */
	public function render_page( Creator_Profile $creator ): never {
		$profile     = $this->service->get_overlay_profile( $creator->id );
		$overlay_url = null !== $profile ? $profile->get_overlay_url() : '';
		$has_overlay = '' !== $overlay_url;

		$token        = $creator->creator_access_token;
		$action_nonce = wp_create_nonce( 'ccmerch_portal_action_' . $token );

		// Notice from redirect params.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$notice = sanitize_key( $_GET['ccmerch_notice'] ?? '' );
		// phpcs:enable

		$layout           = null !== $profile ? $profile->get_layout() : [];
		$all_settings     = null !== $profile ? $profile->all() : [];
		$option_key       = Overlay_Settings::OPTION_KEY;
		$allowed_term_ids = $this->service->get_category_ids( $creator->id );

		$chatbot_settings  = $this->chatbot_settings_service->get_settings( $creator->id );
		$chatbot_connected = $this->chatbot_settings_service->is_connected();
		$chatbot_bot_login = $this->chatbot_settings_service->get_bot_login();

		$preview_data  = $this->service->build_preview_data( $creator->id );
		$layout_config = (string) wp_json_encode( [
			'layout'  => $layout,
			'preview' => $preview_data,
		] );

		// Asset URLs for standalone HTML document (no wp_head/wp_footer).
		$css_admin  = esc_url( Asset_Loader::versioned( Asset_Loader::admin_css() ) );
		$css_layout = esc_url( Asset_Loader::versioned( Asset_Loader::layout_css() ) );
		$css_portal = esc_url( Asset_Loader::versioned( Asset_Loader::portal_css() ) );
		$js_utils   = esc_url( Asset_Loader::versioned( Asset_Loader::utils_js() ) );
		$js_fonts   = esc_url( Asset_Loader::versioned( Asset_Loader::fonts_js() ) );
		$js_layout  = esc_url( Asset_Loader::versioned( Asset_Loader::layout_js() ) );

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $creator->creator_name ); ?> — <?php esc_html_e( 'Overlay Configuration', 'ccmerch-alert' ); ?></title>
	<link rel="stylesheet" href="<?php echo $css_admin; ?>">
	<link rel="stylesheet" href="<?php echo $css_layout; ?>">
	<link rel="stylesheet" href="<?php echo $css_portal; ?>">
	<script>var ccmerchLayout = <?php echo $layout_config; // phpcs:ignore ?>;</script>
	<script>var ccmerchApiBase = '<?php echo esc_url( rest_url( 'ccmerch/v1' ) ); ?>';</script>
	<script>var ccmerchApprovedFonts = <?php echo wp_json_encode( $this->font_library->get_approved_with_meta() ); // phpcs:ignore ?>;</script>
	<script>var ccmerchStrings = <?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_json_encode( Translation_Service::to_js_array( [
			'history.col.order',
			'history.col.customer',
			'history.col.alerts',
			'history.col.date',
			'history.col.actions',
			'history.row.details',
			'history.row.hide',
			'history.row.replay_order',
			'history.row.alert_singular',
			'history.row.alert_plural',
			'history.event.replay',
			'history.empty',
		] ) );
	?>;</script>
</head>
<body class="ccmerch-portal-body">

<div class="ccmerch-portal ccmerch-portal--config">

	<!-- ── Header ──────────────────────────────────────────────────────── -->
	<header class="ccmerch-portal__header">
		<div class="ccmerch-portal__header-top">
			<div class="ccmerch-portal__brand">
				<span class="ccmerch-portal__brand-label"><?php esc_html_e( 'CCMerch Alert', 'ccmerch-alert' ); ?></span>
				<span class="ccmerch-portal__brand-sep">·</span>
				<span class="ccmerch-portal__brand-label"><?php esc_html_e( 'Overlay Configuration', 'ccmerch-alert' ); ?></span>
			</div>
			<?php Changelog_Modal_Component::render_button(); ?>
		</div>
		<h1 class="ccmerch-portal__name"><?php echo esc_html( $creator->creator_name ); ?></h1>
	</header>

	<main class="ccmerch-portal__main">

		<!-- ── Notices ──────────────────────────────────────────────── -->
		<?php
		// Disabled-font notice: the currently saved font is no longer in the approved list.
		$saved_font = (string) ( $all_settings[ Overlay_Settings::FIELD_FONT_FAMILY ] ?? '' );
		if ( '' !== $saved_font && 'inherit' !== $saved_font && ! $this->font_library->is_approved( $saved_font ) ) :
		?>
		<div class="ccmerch-portal__notice">
			<p><?php
				/* translators: %s: font family name */
				echo esc_html( \sprintf( 'The font "%s" is no longer available. Please select a new font and save.', $saved_font ) );
			?></p>
		</div>
		<?php endif; ?>
		<?php if ( 'settings_saved' === $notice ) : ?>
		<div class="ccmerch-portal__notice ccmerch-portal__notice--success">
			<p><?php esc_html_e( 'Settings saved.', 'ccmerch-alert' ); ?></p>
		</div>
		<?php elseif ( 'overlay_id_generated' === $notice ) : ?>
		<div class="ccmerch-portal__notice ccmerch-portal__notice--success">
			<p><?php esc_html_e( 'New overlay ID generated. Update your OBS browser source with the new URL below.', 'ccmerch-alert' ); ?></p>
		</div>
		<?php elseif ( 'channel_auth_success' === $notice ) : ?>
		<div class="ccmerch-portal__notice ccmerch-portal__notice--success">
			<p><?php esc_html_e( 'Channel authorization successful! The bot is now authorized to send messages in your channel.', 'ccmerch-alert' ); ?></p>
		</div>
		<?php elseif ( 'channel_auth_failed' === $notice ) : ?>
		<div class="ccmerch-portal__notice">
			<p><?php esc_html_e( 'Channel authorization failed. Please try again or contact your administrator.', 'ccmerch-alert' ); ?></p>
		</div>
		<?php endif; ?>

		<!-- ── Tab Navigation ──────────────────────────────────────── -->
		<nav class="ccmerch-portal__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Portal sections', 'ccmerch-alert' ); ?>">
			<button
				class="ccmerch-portal__tab ccmerch-portal__tab--active"
				data-ccmerch-portal-tab="config"
				role="tab"
				aria-selected="true"
				aria-controls="ccmerch-portal-section-config"
			><?php echo esc_html( ccmerch_translate( 'portal.tab.config' ) ); ?></button>
			<button
				class="ccmerch-portal__tab"
				data-ccmerch-portal-tab="history"
				role="tab"
				aria-selected="false"
				aria-controls="ccmerch-portal-section-history"
			><?php echo esc_html( ccmerch_translate( 'portal.tab.history' ) ); ?></button>
			<button
				class="ccmerch-portal__tab"
				data-ccmerch-portal-tab="chatbot"
				role="tab"
				aria-selected="false"
				aria-controls="ccmerch-portal-section-chatbot"
			><?php esc_html_e( 'Chatbot', 'ccmerch-alert' ); ?></button>
		</nav>

		<!-- ── Overlay Configuration section ───────────────────────── -->
		<div id="ccmerch-portal-section-config" class="ccmerch-portal__section">

		<!-- ── Browser Source URL ──────────────────────────────────── -->
		<?php
		Overlay_Config_Renderer::render_browser_source_card(
			$overlay_url,
			'<span class="ccmerch-card__icon" aria-hidden="true">🖥</span>',
			function () use ( $token ) {
				// action value matches Creator_Save_Service::AJAX_GENERATE_ID.
				// No nonce — creator endpoints authenticate via token only (see CLAUDE.md AJAX Architecture).
				echo '<input type="hidden" name="action" value="' . esc_attr( Creator_Save_Service::AJAX_GENERATE_ID ) . '">';
				echo '<input type="hidden" name="creator_token" value="' . esc_attr( $token ) . '">';
			},
			'margin-top:20px;',
			true  // $use_ajax_generate — form targets admin-ajax.php; JS handles inline DOM update.
		);
		?>

		<!-- ── Test Controls ──────────────────────────────────────── -->
		<?php if ( $has_overlay ) :
			Test_Controls_Component::render(
				'<span class="ccmerch-card__icon" aria-hidden="true">&#9654;</span>',
				__( 'Send a test alert to your live overlay. Your OBS browser source must be open.', 'ccmerch-alert' ),
				[
					[
						'label'      => __( 'Send Test Alert', 'ccmerch-alert' ),
						'style'      => 'primary',
						'action'     => Creator_Overlay_Controller::AJAX_PORTAL_TEST,
						'auth_field' => 'creator_token',
						'auth_value' => $token,
						'ajax_url'   => admin_url( 'admin-ajax.php' ),
					],
				],
				'margin-top:20px;'
			);
		endif; ?>

		<!-- ── Overlay Configuration Form ─────────────────────────── -->
		<?php if ( null !== $profile ) : ?>
		<form
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			style="margin-top:20px;"
			id="ccmerch-portal-settings-form"
			data-ccmerch-portal-save="1"
		>
			<!-- action value matches Creator_Save_Service::AJAX_SAVE -->
			<input type="hidden" name="action"        value="<?php echo esc_attr( Creator_Save_Service::AJAX_SAVE ); ?>">
			<input type="hidden" name="creator_token" value="<?php echo esc_attr( $token ); ?>">
			<!-- _nonce kept for Audio_Upload_Component compatibility; not checked by the save handler -->
			<input type="hidden" name="_nonce"        value="<?php echo esc_attr( $action_nonce ); ?>">

			<?php
			/*
			 * Shared renderer — identical to the admin Overlay page.
			 * render_appearance_fields: hidden inputs read/written by layout-editor.js.
			 * render_designer:          1920×1080 canvas + toolbar + layout hidden inputs.
			 * render_audio_section:     full audio card (enable, default sound, category map).
			 *   with_media_picker=false → number inputs instead of wp.media() buttons because
			 *   creators may not be WordPress users with media library access.
			 */
			Overlay_Config_Renderer::render_appearance_fields( $all_settings, $option_key );
			Overlay_Config_Renderer::render_designer(
				$layout,
				[
					'action'     => Creator_Overlay_Controller::AJAX_PREVIEW_DATA,
					'auth_field' => 'creator_token',
					'auth_value' => $token,
					'ajax_url'   => admin_url( 'admin-ajax.php' ),
				]
			);
			Overlay_Config_Renderer::render_audio_section(
				$all_settings,
				$option_key,
				false,
				$allowed_term_ids,
				[
					'upload_action' => Audio_Upload_Controller::AJAX_CREATOR_UPLOAD,
					'delete_action' => Audio_Upload_Controller::AJAX_CREATOR_DELETE,
					'auth_field'    => 'creator_token',
					'auth_value'    => $token,
					'nonce_field'   => '_nonce',
					'nonce_value'   => $action_nonce,
					'ajax_url'      => admin_url( 'admin-ajax.php' ),
				]
			);
			?>

			<p style="margin-top:24px;">
				<button type="submit" class="button button-primary button-large">
					<?php echo esc_html( ccmerch_translate( 'portal.save.button' ) ); ?>
				</button>
			</p>
			<div id="ccmerch-portal-save-feedback" role="status" style="display:none;margin-top:10px;font-size:0.875rem;"></div>
		</form>
		<?php else : ?>
		<p class="description" style="margin-top:20px;">
			<?php esc_html_e( 'Overlay profile not found. Please contact your administrator.', 'ccmerch-alert' ); ?>
		</p>
		<?php endif; ?>

		</div><!-- /ccmerch-portal-section-config -->

		<!-- ── Alert History section ────────────────────────────────── -->
		<div id="ccmerch-portal-section-history" class="ccmerch-portal__section ccmerch-portal__section--hidden">

			<!-- Privacy toolbar: rendered in PHP so it is never wiped by JS
			     re-renders of the history container on pagination. -->
			<div class="ccmerch-history__toolbar">
				<label class="switch">
					<input
						type="checkbox"
						id="ccmerch-anonymize-toggle"
						data-ccmerch-anonymize-toggle="1"
					>
					<span class="slider"></span>
				</label>
				<span class="ccmerch-history__toggle-label"><?php esc_html_e( 'Anonymize customer names', 'ccmerch-alert' ); ?></span>
			</div>

			<div
				id="ccmerch-history-container"
				class="ccmerch-history"
				data-ccmerch-history-action="<?php echo esc_attr( Creator_Overlay_Controller::AJAX_HISTORY ); ?>"
				data-ccmerch-history-order-action="<?php echo esc_attr( Creator_Overlay_Controller::AJAX_HISTORY_ORDER ); ?>"
				data-ccmerch-history-auth-field="creator_token"
				data-ccmerch-history-auth-value="<?php echo esc_attr( $token ); ?>"
				data-ccmerch-history-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
				data-ccmerch-replay-event-action="<?php echo esc_attr( Creator_Overlay_Controller::AJAX_REPLAY_EVENT ); ?>"
				data-ccmerch-replay-order-action="<?php echo esc_attr( Creator_Overlay_Controller::AJAX_REPLAY_ORDER ); ?>"
			>
				<div class="ccmerch-history__loading"><?php esc_html_e( 'Loading alert history…', 'ccmerch-alert' ); ?></div>
			</div>
		</div><!-- /ccmerch-portal-section-history -->

		<!-- ── Chatbot section ──────────────────────────────────────── -->
		<div id="ccmerch-portal-section-chatbot" class="ccmerch-portal__section ccmerch-portal__section--hidden">

			<?php if ( ! $chatbot_connected ) : ?>
			<div class="ccmerch-card" style="margin-top:20px;">
				<h2 class="ccmerch-card__title"><?php esc_html_e( 'Chatbot', 'ccmerch-alert' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The Chatbot is not connected. Please ask your administrator to connect the bot account in CCMerch Alert → Chatbot.', 'ccmerch-alert' ); ?></p>
			</div>
			<?php else : ?>

			<form
				id="ccmerch-chatbot-settings-form"
				method="post"
				action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
				data-ccmerch-chatbot-save="1"
				style="margin-top:20px;"
			>
				<input type="hidden" name="action"        value="<?php echo esc_attr( Chatbot_Portal_Controller::AJAX_SAVE ); ?>">
				<input type="hidden" name="creator_token" value="<?php echo esc_attr( $token ); ?>">

				<div class="ccmerch-card">
					<h2 class="ccmerch-card__title"><?php esc_html_e( 'Chatbot', 'ccmerch-alert' ); ?></h2>

					<div class="ccmerch-chatbot-setup-notice">
						<p class="ccmerch-chatbot-setup-notice__secondary">
							<?php esc_html_e( 'The chatbot will automatically post in your Twitch channel when customers complete a purchase. No additional setup is required.', 'ccmerch-alert' ); ?>
						</p>
						<p class="ccmerch-chatbot-setup-notice__secondary">
							<strong><?php esc_html_e( 'Optional:', 'ccmerch-alert' ); ?></strong>
							<?php esc_html_e( 'To allow the bot to bypass slow mode or other chat restrictions, type one of the following commands in your Twitch channel:', 'ccmerch-alert' ); ?>
						</p>
						<p class="ccmerch-chatbot-setup-notice__secondary">
							<code class="ccmerch-chatbot-mod-command" style="color:#0e9d12;">/mod <?php echo esc_html( '' !== $chatbot_bot_login ? $chatbot_bot_login : 'CCMerch' ); ?></code>
							<span style="margin:0 6px;opacity:.5;"><?php esc_html_e( 'or', 'ccmerch-alert' ); ?></span>
							<code class="ccmerch-chatbot-mod-command" style="color:#e206b9;">/vip <?php echo esc_html( '' !== $chatbot_bot_login ? $chatbot_bot_login : 'CCMerch' ); ?></code>
						</p>
					</div>

					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Enable Chatbot', 'ccmerch-alert' ); ?></th>
							<td>
								<label>
									<input
										type="checkbox"
										name="chatbot_enabled"
										value="1"
										<?php checked( $chatbot_settings->enabled ); ?>
									>
									<?php esc_html_e( 'Send a chat message when an alert fires', 'ccmerch-alert' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th><label for="ccmerch-chatbot-template"><?php esc_html_e( 'Message Template', 'ccmerch-alert' ); ?></label></th>
							<td>
								<textarea
									id="ccmerch-chatbot-template"
									name="chatbot_template"
									rows="3"
									class="large-text"
									style="max-width:600px;"
								><?php echo esc_textarea( $chatbot_settings->template ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'Variables: $UserName (stream username), $Name (billing first name), $Product (product name), $Amount (order total).', 'ccmerch-alert' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<p style="margin-top:16px;">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Save Chatbot Settings', 'ccmerch-alert' ); ?>
						</button>
						<button
							type="button"
							class="button"
							style="margin-left:8px;"
							data-ccmerch-chatbot-preview="1"
							data-ccmerch-action="<?php echo esc_attr( Chatbot_Portal_Controller::AJAX_PREVIEW ); ?>"
							data-ccmerch-auth-field="creator_token"
							data-ccmerch-auth-value="<?php echo esc_attr( $token ); ?>"
							data-ccmerch-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
						><?php esc_html_e( 'Preview Message', 'ccmerch-alert' ); ?></button>
						<button
							type="button"
							class="button"
							style="margin-left:8px;"
							data-ccmerch-chatbot-send-test="1"
							data-ccmerch-action="<?php echo esc_attr( Chatbot_Portal_Controller::AJAX_TEST_SEND ); ?>"
							data-ccmerch-auth-field="creator_token"
							data-ccmerch-auth-value="<?php echo esc_attr( $token ); ?>"
							data-ccmerch-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
						><?php esc_html_e( 'Send Test Message', 'ccmerch-alert' ); ?></button>
					</p>
					<div id="ccmerch-chatbot-save-feedback" role="status" style="display:none;margin-top:10px;font-size:0.875rem;"></div>
					<div id="ccmerch-chatbot-preview-output" style="display:none;margin-top:12px;padding:10px 14px;background:#f0f0f1;border-left:4px solid #2271b1;font-family:monospace;word-break:break-all;"></div>
				</div>
			</form>
			<?php endif; ?>

		</div><!-- /ccmerch-portal-section-chatbot -->

	</main>

	<footer class="ccmerch-portal__footer">
		<p><?php esc_html_e( 'Powered by CCMerch Alert', 'ccmerch-alert' ); ?></p>
	</footer>

</div>

<script src="<?php echo $js_utils; ?>"></script>
<script src="<?php echo $js_fonts; ?>"></script>
<script src="<?php echo $js_layout; ?>"></script>

<?php Changelog_Modal_Component::render_modal(); ?>

</body>
</html>
		<?php
		exit;
	}
}
