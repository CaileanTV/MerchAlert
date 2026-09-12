<?php
/**
 * Overlay admin page.
 *
 * @package CCMerchAlert\Admin
 */

namespace CCMerchAlert\Admin;

use CCMerchAlert\Audio\Audio_Upload_Controller;
use CCMerchAlert\Fonts\Font_Library;
use CCMerchAlert\OrderLog\Order_Repository;
use CCMerchAlert\Overlay\Overlay_Config_Renderer;
use CCMerchAlert\Overlay\Overlay_Controller;
use CCMerchAlert\Overlay\Overlay_Settings;
use CCMerchAlert\UI\Asset_Loader;
use CCMerchAlert\UI\Components\Test_Controls_Component;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the CCMerch Alert → Overlay admin submenu page.
 *
 * Displays:
 *  - The current overlay browser-source URL with Copy and Open buttons.
 *  - A "Generate New Overlay ID" form (invalidates any previous URL).
 *  - Test Controls (Send Test Alert / Replay Latest Order) for the live OBS source.
 *  - The overlay settings form, rendered via Overlay_Config_Renderer:
 *      · Hidden appearance fields (templates, typography, background, timing).
 *      · 1920 × 1080 Overlay Designer canvas (drag, resize, property inspector).
 *      · Audio section (Enable, Default Sound, Category Sound map).
 *
 * The Overlay_Config_Renderer methods are the single implementation shared by
 * this page and Creator_Portal_Renderer. Do not duplicate their HTML here.
 *
 * Test Controls are rendered via Test_Controls_Component::render() so the same
 * card is shared with Creator_Portal_Renderer. The only difference is the auth
 * mechanism encoded in the button data attributes (WP nonce here vs creator
 * token on the portal). The shared ccmerch-utils.js handles the AJAX call.
 *
 * Must never: write to the database. Must never contain sanitization logic
 * (that belongs in Overlay_Settings). Must never output overlay page HTML.
 */
final class Overlay_Admin {

	/** @var string Page hook suffix returned by add_submenu_page(). */
	private string $page_hook = '';

	/**
	 * @param Overlay_Settings $settings     Settings store.
	 * @param Order_Repository $repository   Order log data access.
	 * @param Font_Library     $font_library Approved-font library for JS injection.
	 */
	public function __construct(
		private readonly Overlay_Settings $settings,
		private readonly Order_Repository $repository,
		private readonly Font_Library $font_library,
	) {
		add_action( 'admin_menu',             [ $this, 'register_menu'  ] );
		add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
	}

	// -------------------------------------------------------------------------
	// Menu registration
	// -------------------------------------------------------------------------

	/**
	 * Registers the Overlay submenu under the CCMerch Alert top-level menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->page_hook = (string) add_submenu_page(
			'ccmerch-alert',
			__( 'Overlay Settings', 'ccmerch-alert' ),
			__( 'Overlay', 'ccmerch-alert' ),
			'manage_options',
			Overlay_Settings::PAGE,
			[ $this, 'render_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Asset enqueueing
	// -------------------------------------------------------------------------

	/**
	 * Enqueues all assets required by the Overlay Configuration page.
	 *
	 * Delegates asset URL management to Asset_Loader so filenames are defined
	 * in one place. Callers of this method do not need to know individual asset
	 * paths. wp_localize_script() and wp_enqueue_media() are called here because
	 * they carry page-specific data that Asset_Loader does not own.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->page_hook ) {
			return;
		}

		Asset_Loader::enqueue_overlay_config();

		wp_localize_script(
			'ccmerch-layout-editor',
			'ccmerchLayout',
			[
				'layout'  => $this->settings->get_layout(),
				'preview' => Overlay_Config_Renderer::resolve_preview_data( $this->repository ),
			]
		);

		wp_localize_script(
			'ccmerch-layout-editor',
			'ccmerchApprovedFonts',
			$this->font_library->get_approved_with_meta()
		);

		// Required for wp.media() used by the audio attachment picker buttons.
		wp_enqueue_media();
	}

	// -------------------------------------------------------------------------
	// Page renderer
	// -------------------------------------------------------------------------

	/**
	 * Renders the full Overlay admin page.
	 *
	 * The settings form body (appearance fields, designer, audio) is delegated
	 * to Overlay_Config_Renderer so the same HTML is shared with the Creator Portal.
	 * Test Controls are rendered via Test_Controls_Component so the same card is
	 * shared with the Creator Portal. Only the page chrome, form target, and
	 * auth parameters are admin-specific here.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$overlay_url = $this->settings->get_overlay_url();
		$has_id      = '' !== $overlay_url;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$id_just_generated = ! empty( $_GET['ccmerch-id-generated'] );
		// phpcs:enable
		?>
		<div class="wrap">

			<h1><?php esc_html_e( 'Overlay', 'ccmerch-alert' ); ?></h1>

			<?php if ( $id_just_generated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'New overlay ID generated. Update your OBS browser source with the new URL below.', 'ccmerch-alert' ); ?></p>
				</div>
			<?php endif; ?>

			<!-- ── Browser Source URL ─────────────────────────────────────── -->
			<?php
			Overlay_Config_Renderer::render_browser_source_card(
				$overlay_url,
				'<span class="dashicons dashicons-desktop ccmerch-card__icon" aria-hidden="true"></span>',
				function () {
					echo '<input type="hidden" name="action" value="' . esc_attr( Overlay_Controller::ACTION_GENERATE_ID ) . '">';
					wp_nonce_field( Overlay_Controller::ACTION_GENERATE_ID );
				},
				'max-width:860px;margin-top:20px;'
			);
			?>

			<!-- ── Test Controls ──────────────────────────────────────────── -->
			<?php if ( $has_id ) :
				$test_nonce = wp_create_nonce( Overlay_Controller::NONCE_TEST );
				$ajax_url   = admin_url( 'admin-ajax.php' );
				Test_Controls_Component::render(
					'<span class="dashicons dashicons-controls-play ccmerch-card__icon" aria-hidden="true"></span>',
					__( 'Trigger alerts on your live overlay without placing real orders. The alert appears on your OBS browser source within a few seconds.', 'ccmerch-alert' ),
					[
						[
							'label'      => __( 'Send Test Alert', 'ccmerch-alert' ),
							'style'      => 'primary',
							'action'     => Overlay_Controller::AJAX_TEST_ALERT,
							'auth_field' => '_ajax_nonce',
							'auth_value' => $test_nonce,
							'ajax_url'   => $ajax_url,
						],
						[
							'label'      => __( 'Replay Latest Order', 'ccmerch-alert' ),
							'style'      => 'secondary',
							'action'     => Overlay_Controller::AJAX_REPLAY_LATEST,
							'auth_field' => '_ajax_nonce',
							'auth_value' => $test_nonce,
							'ajax_url'   => $ajax_url,
						],
					],
					'max-width:860px;margin-top:20px;'
				);
			endif; ?>

			<!-- ── Settings form ──────────────────────────────────────────── -->
			<form method="post" action="options.php" style="max-width:1400px;margin-top:20px;">
				<?php
				settings_fields( Overlay_Settings::PAGE );

				$layout = $this->settings->get_layout();

				// Shared renderer: hidden appearance fields, 1920×1080 canvas, full audio section.
				Overlay_Config_Renderer::render_appearance_fields( $this->settings->all(), Overlay_Settings::OPTION_KEY );
				Overlay_Config_Renderer::render_designer( $layout );
				Overlay_Config_Renderer::render_audio_section(
					$this->settings->all(),
					Overlay_Settings::OPTION_KEY,
					true,
					null,
					[
						'upload_action' => Audio_Upload_Controller::AJAX_ADMIN_UPLOAD,
						'delete_action' => Audio_Upload_Controller::AJAX_ADMIN_DELETE,
						'auth_field'    => '_ajax_nonce',
						'auth_value'    => wp_create_nonce( Audio_Upload_Controller::NONCE_ADMIN ),
						'nonce_field'   => '',
						'nonce_value'   => '',
						'ajax_url'      => admin_url( 'admin-ajax.php' ),
					]
				);

				submit_button( __( 'Save Settings', 'ccmerch-alert' ) );
				?>
			</form>

		</div>

		<script type="text/javascript">
		( function () {
			'use strict';

			// Audio attachment picker — wires up all "Select Sound" / "Remove" buttons
			// rendered by Overlay_Config_Renderer::render_audio_section(). Uses event
			// delegation so it works for every button in the form regardless of DOM order.
			// Test Controls AJAX is handled by ccmerch-utils.js — no per-page JS required.
			( function () {
				function openMediaPicker( fieldId ) {
					var input    = document.getElementById( fieldId );
					var nameEl   = document.getElementById( fieldId + '_name' );
					var clearBtn = document.querySelector( '[data-field="' + fieldId + '"].ccmerch-audio-clear-btn' );

					if ( ! input || typeof wp === 'undefined' || ! wp.media ) {
						return;
					}

					var frame = wp.media( {
						title:    <?php echo wp_json_encode( __( 'Select Alert Sound', 'ccmerch-alert' ) ); ?>,
						button:   { text: <?php echo wp_json_encode( __( 'Use this sound', 'ccmerch-alert' ) ); ?> },
						multiple: false,
						library:  { type: [ 'audio' ] },
					} );

					frame.on( 'select', function () {
						var att = frame.state().get( 'selection' ).first().toJSON();
						input.value = String( att.id );
						if ( nameEl )   { nameEl.textContent = att.filename || att.title || ''; }
						if ( clearBtn ) { clearBtn.style.display = ''; }
					} );

					frame.open();
				}

				document.addEventListener( 'click', function ( e ) {
					var selBtn = e.target.closest( '.ccmerch-audio-select-btn' );
					if ( selBtn ) {
						openMediaPicker( selBtn.getAttribute( 'data-field' ) );
						return;
					}

					var clearBtn = e.target.closest( '.ccmerch-audio-clear-btn' );
					if ( clearBtn ) {
						var fieldId = clearBtn.getAttribute( 'data-field' );
						var input   = document.getElementById( fieldId );
						var nameEl  = document.getElementById( fieldId + '_name' );
						if ( input )  { input.value = '0'; }
						if ( nameEl ) { nameEl.textContent = <?php echo wp_json_encode( __( 'No file selected', 'ccmerch-alert' ) ); ?>; }
						clearBtn.style.display = 'none';
					}
				} );
			} )();

		} )();
		</script>
		<?php
	}
}
