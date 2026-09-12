<?php
/**
 * Google Fonts admin management page.
 *
 * @package CCMerchAlert\Admin
 */

namespace CCMerchAlert\Admin;

use CCMerchAlert\Fonts\Google_Fonts_Provider;
use CCMerchAlert\Fonts\Font_Library;
use CCMerchAlert\Settings;
use CCMerchAlert\UI\Asset_Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the CCMerch Alert → Font Library admin submenu page.
 *
 * Provides:
 * - Catalog sync (calls Google Fonts API, upserts local table).
 * - Per-font enable/disable toggle (AJAX).
 * - Search filter (client-side, no AJAX).
 * - Approved-font count summary.
 *
 * Must never: query the database directly (use Google_Fonts_Provider).
 * Must never: render HTML outside of the render methods.
 */
final class Fonts_Admin {

	/** @var string Admin submenu page slug. */
	public const PAGE = 'ccmerch-alert-fonts';

	/** @var string AJAX action for syncing the catalog. */
	public const AJAX_SYNC = 'ccmerch_fonts_sync';

	/** @var string AJAX action for toggling approval status. */
	public const AJAX_TOGGLE = 'ccmerch_fonts_toggle';

	/** @var string Nonce action used by both AJAX endpoints. */
	public const NONCE_ACTION = 'ccmerch_fonts_manage';

	/** @var Google_Fonts_Provider */
	private Google_Fonts_Provider $provider;

	/** @var Font_Library */
	private Font_Library $library;

	/** @var Settings */
	private Settings $settings;

	public function __construct(
		Google_Fonts_Provider $provider,
		Font_Library $library,
		Settings $settings
	) {
		$this->provider = $provider;
		$this->library  = $library;
		$this->settings = $settings;
	}

	// -------------------------------------------------------------------------
	// WordPress hooks
	// -------------------------------------------------------------------------

	/**
	 * Registers the submenu and AJAX endpoints.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu',              [ $this, 'add_submenu' ] );
		add_action( 'wp_ajax_' . self::AJAX_SYNC,   [ $this, 'handle_sync' ] );
		add_action( 'wp_ajax_' . self::AJAX_TOGGLE, [ $this, 'handle_toggle' ] );
	}

	/**
	 * Adds the Font Library submenu under the CCMerch Alert top-level menu.
	 *
	 * @return void
	 */
	public function add_submenu(): void {
		add_submenu_page(
			'ccmerch-alert',
			'Font Library',
			'Font Library',
			'manage_options',
			self::PAGE,
			[ $this, 'render_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Page render
	// -------------------------------------------------------------------------

	/**
	 * Renders the Font Library admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->enqueue_assets();

		$total_fonts    = $this->provider->get_count();
		$approved_count = $this->library->get_count();
		$last_synced    = $this->provider->get_last_synced();
		$api_key        = (string) $this->settings->get( Settings::FIELD_GOOGLE_FONTS_API_KEY );
		$has_api_key    = '' !== \trim( $api_key );
		$fonts          = $this->provider->get_all();

		$nonce = wp_create_nonce( self::NONCE_ACTION );
		?>
		<div class="wrap">
			<h1>Font Library</h1>

			<?php if ( ! $has_api_key ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong>Google Fonts API key not configured.</strong>
						Enter your API key on the
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Settings::PAGE ) ); ?>">Settings page</a>
						before syncing the catalog.
					</p>
				</div>
			<?php endif; ?>

			<div class="ccmerch-fonts-header">
				<div class="ccmerch-fonts-stats">
					<span class="ccmerch-stat">
						<strong><?php echo esc_html( \number_format_i18n( $total_fonts ) ); ?></strong> fonts in catalog
					</span>
					<span class="ccmerch-stat">
						<strong><?php echo esc_html( \number_format_i18n( $approved_count ) ); ?></strong> approved for creators
					</span>
					<?php if ( null !== $last_synced ) : ?>
						<span class="ccmerch-stat ccmerch-stat--muted">
							Last synced: <?php echo esc_html( \gmdate( 'M j, Y g:i A', \strtotime( $last_synced ) ) ); ?> UTC
						</span>
					<?php endif; ?>
				</div>

				<div class="ccmerch-fonts-actions">
					<button
						type="button"
						id="ccmerch-fonts-sync-btn"
						class="button button-primary"
						<?php disabled( ! $has_api_key ); ?>
						data-nonce="<?php echo esc_attr( $nonce ); ?>"
						data-ajax-url="<?php echo esc_attr( admin_url( 'admin-ajax.php' ) ); ?>"
					>
						Sync Google Fonts Catalog
					</button>
				</div>
			</div>

			<div id="ccmerch-fonts-sync-feedback" class="ccmerch-fonts-feedback" style="display:none;"></div>

			<?php if ( $total_fonts > 0 ) : ?>
				<div class="ccmerch-fonts-toolbar">
					<input
						type="search"
						id="ccmerch-fonts-search"
						class="regular-text"
						placeholder="Search fonts…"
						autocomplete="off"
					/>
					<select id="ccmerch-fonts-category" title="Filter by category">
						<option value="">All categories</option>
						<option value="sans-serif">Sans Serif</option>
						<option value="serif">Serif</option>
						<option value="display">Display</option>
						<option value="handwriting">Handwriting</option>
						<option value="monospace">Monospace</option>
					</select>
					<select id="ccmerch-fonts-sort" title="Sort order">
						<option value="alpha">A → Z</option>
						<option value="alpha_desc">Z → A</option>
						<option value="popularity">Most Popular</option>
						<option value="newest">Newest</option>
					</select>
					<label class="ccmerch-fonts-filter-label">
						<input type="checkbox" id="ccmerch-fonts-approved-only" />
						Show approved only
					</label>
				</div>

				<table class="widefat striped ccmerch-fonts-table" id="ccmerch-fonts-list">
					<thead>
						<tr>
							<th class="ccmerch-col-approved">Approved</th>
							<th class="ccmerch-col-preview">Preview</th>
							<th class="ccmerch-col-family">Font Family</th>
							<th class="ccmerch-col-category">Category</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $fonts as $font ) : ?>
							<tr
								class="ccmerch-font-row<?php echo $font['approved'] ? ' ccmerch-font-row--approved' : ''; ?>"
								data-family="<?php echo esc_attr( $font['family'] ); ?>"
								data-family-lower="<?php echo esc_attr( \strtolower( $font['family'] ) ); ?>"
								data-category="<?php echo esc_attr( $font['category'] ); ?>"
								data-sort-order="<?php echo esc_attr( (string) $font['sort_order'] ); ?>"
								data-last-modified="<?php echo esc_attr( $font['last_modified'] ); ?>"
								data-approved="<?php echo $font['approved'] ? '1' : '0'; ?>"
							>
								<td class="ccmerch-col-approved">
									<label class="ccmerch-font-toggle">
										<input
											type="checkbox"
											class="ccmerch-font-approve-cb"
											<?php checked( $font['approved'] ); ?>
											data-family="<?php echo esc_attr( $font['family'] ); ?>"
											data-nonce="<?php echo esc_attr( $nonce ); ?>"
											data-ajax-url="<?php echo esc_attr( admin_url( 'admin-ajax.php' ) ); ?>"
										/>
										<span class="ccmerch-toggle-track"></span>
									</label>
								</td>
								<td class="ccmerch-col-preview">
									<span
										class="ccmerch-font-preview"
										data-font-preview="<?php echo esc_attr( $font['family'] ); ?>"
										style="font-family:'<?php echo esc_attr( $font['family'] ); ?>',sans-serif"
									>Aa Bb 123</span>
								</td>
								<td class="ccmerch-col-family">
									<?php echo esc_html( $font['family'] ); ?>
								</td>
								<td class="ccmerch-col-category">
									<span class="ccmerch-cat-badge"><?php echo esc_html( $font['category'] ); ?></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="ccmerch-fonts-count-info" id="ccmerch-fonts-visible-count">
					Showing <?php echo esc_html( \number_format_i18n( $total_fonts ) ); ?> fonts
				</p>

			<?php else : ?>
				<div class="ccmerch-fonts-empty">
					<p>No fonts in the catalog yet. <?php echo $has_api_key ? 'Click <strong>Sync Google Fonts Catalog</strong> to download the full library.' : 'Configure your API key first, then sync the catalog.'; ?></p>
				</div>
			<?php endif; ?>
		</div>

		<script>
		( function() {
			var ajaxUrl   = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
			var syncNonce = '<?php echo esc_js( $nonce ); ?>';

			// ----------------------------------------------------------------
			// Sync button
			// ----------------------------------------------------------------
			var syncBtn      = document.getElementById( 'ccmerch-fonts-sync-btn' );
			var syncFeedback = document.getElementById( 'ccmerch-fonts-sync-feedback' );

			function showSyncFeedback( msg, isError ) {
				if ( ! syncFeedback ) { return; }
				syncFeedback.textContent    = msg;
				syncFeedback.style.display  = 'block';
				syncFeedback.style.color    = isError ? '#d63638' : '#00a32a';
			}

			if ( syncBtn ) {
				syncBtn.addEventListener( 'click', function() {
					syncBtn.disabled      = true;
					syncBtn.textContent   = 'Syncing…';
					syncFeedback.style.display = 'none';

					var fd = new FormData();
					fd.append( 'action', '<?php echo esc_js( self::AJAX_SYNC ); ?>' );
					fd.append( '_ajax_nonce', syncNonce );

					fetch( ajaxUrl, { method: 'POST', body: fd } )
						.then( function( r ) { return r.json(); } )
						.then( function( r ) {
							if ( r.success ) {
								showSyncFeedback( r.data.message, false );
								// Reload to reflect new font list
								window.location.reload();
							} else {
								showSyncFeedback( ( r.data && r.data.message ) || 'Sync failed.', true );
							}
						} )
						.catch( function() {
							showSyncFeedback( 'Network error. Please try again.', true );
						} )
						.finally( function() {
							syncBtn.disabled    = false;
							syncBtn.textContent = 'Sync Google Fonts Catalog';
						} );
				} );
			}

			// ----------------------------------------------------------------
			// Approve/disable toggles
			// ----------------------------------------------------------------
			document.addEventListener( 'change', function( e ) {
				var cb = e.target;
				if ( ! cb.classList.contains( 'ccmerch-font-approve-cb' ) ) { return; }

				var family   = cb.getAttribute( 'data-family' );
				var approved = cb.checked ? '1' : '0';
				var nonce    = cb.getAttribute( 'data-nonce' );
				var row      = cb.closest( 'tr' );

				cb.disabled = true;

				var fd = new FormData();
				fd.append( 'action',      '<?php echo esc_js( self::AJAX_TOGGLE ); ?>' );
				fd.append( '_ajax_nonce', nonce );
				fd.append( 'family',      family );
				fd.append( 'approved',    approved );

				fetch( ajaxUrl, { method: 'POST', body: fd } )
					.then( function( r ) { return r.json(); } )
					.then( function( r ) {
						if ( r.success ) {
							if ( row ) {
								row.dataset.approved = approved;
								row.classList.toggle( 'ccmerch-font-row--approved', cb.checked );
							}
						} else {
							cb.checked = ! cb.checked; // revert
						}
					} )
					.catch( function() {
						cb.checked = ! cb.checked; // revert
					} )
					.finally( function() {
						cb.disabled = false;
					} );
			} );

			// ----------------------------------------------------------------
			// Search + category + approved-only filter
			// ----------------------------------------------------------------
			var searchInput    = document.getElementById( 'ccmerch-fonts-search' );
			var categorySelect = document.getElementById( 'ccmerch-fonts-category' );
			var approvedCheck  = document.getElementById( 'ccmerch-fonts-approved-only' );
			var countInfo      = document.getElementById( 'ccmerch-fonts-visible-count' );

			function applyFilters() {
				var query        = searchInput    ? searchInput.value.toLowerCase().trim() : '';
				var catFilter    = categorySelect ? categorySelect.value                   : '';
				var approvedOnly = approvedCheck  ? approvedCheck.checked                  : false;
				var rows         = document.querySelectorAll( '.ccmerch-font-row' );
				var visible      = 0;

				rows.forEach( function( row ) {
					var family   = row.getAttribute( 'data-family-lower' ) || '';
					var cat      = row.getAttribute( 'data-category' )     || '';
					var approved = row.getAttribute( 'data-approved' )     === '1';

					var show = ( ! query        || family.indexOf( query )  !== -1 )
					        && ( ! catFilter    || cat    === catFilter )
					        && ( ! approvedOnly || approved );

					row.style.display = show ? '' : 'none';
					if ( show ) { visible++; }
				} );

				if ( countInfo ) {
					countInfo.textContent = 'Showing ' + visible.toLocaleString() + ' font' + ( 1 === visible ? '' : 's' );
				}
			}

			if ( searchInput )    { searchInput.addEventListener( 'input',  applyFilters ); }
			if ( categorySelect ) { categorySelect.addEventListener( 'change', applyFilters ); }
			if ( approvedCheck )  { approvedCheck.addEventListener( 'change', applyFilters ); }

			// ----------------------------------------------------------------
			// DOM-based sort (instant, no AJAX required)
			// ----------------------------------------------------------------
			var sortSelect = document.getElementById( 'ccmerch-fonts-sort' );
			var fontsTable = document.getElementById( 'ccmerch-fonts-list' );

			function applySort() {
				if ( ! fontsTable || ! sortSelect ) { return; }
				var tbody = fontsTable.querySelector( 'tbody' );
				if ( ! tbody ) { return; }

				var mode = sortSelect.value;
				var rows = Array.prototype.slice.call( tbody.querySelectorAll( '.ccmerch-font-row' ) );

				rows.sort( function( a, b ) {
					if ( 'alpha_desc' === mode ) {
						return ( b.getAttribute( 'data-family-lower' ) || '' )
							.localeCompare( a.getAttribute( 'data-family-lower' ) || '' );
					}
					if ( 'popularity' === mode ) {
						var oa = parseInt( a.getAttribute( 'data-sort-order' ) || '0', 10 );
						var ob = parseInt( b.getAttribute( 'data-sort-order' ) || '0', 10 );
						// sort_order 0 means no rank data — push to end
						if ( 0 === oa ) { return 1; }
						if ( 0 === ob ) { return -1; }
						return oa - ob;
					}
					if ( 'newest' === mode ) {
						var da = a.getAttribute( 'data-last-modified' ) || '';
						var db = b.getAttribute( 'data-last-modified' ) || '';
						return db.localeCompare( da );
					}
					// default: alpha
					return ( a.getAttribute( 'data-family-lower' ) || '' )
						.localeCompare( b.getAttribute( 'data-family-lower' ) || '' );
				} );

				// Re-append in sorted order (doesn't recreate nodes, preserves IntersectionObserver).
				rows.forEach( function( row ) { tbody.appendChild( row ); } );
			}

			if ( sortSelect ) { sortSelect.addEventListener( 'change', applySort ); }
		} )();
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * Handles the catalog sync AJAX request.
	 *
	 * @return never
	 */
	public function handle_sync(): never {
		check_ajax_referer( self::NONCE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.', 'reason' => 'caps', 'step' => 'auth' ] );
		}

		$api_key = (string) $this->settings->get( Settings::FIELD_GOOGLE_FONTS_API_KEY );
		$result  = $this->provider->sync( $api_key );

		if ( '' !== $result['error'] ) {
			wp_send_json_error( [ 'message' => $result['error'], 'reason' => 'sync_failed', 'step' => 'sync' ] );
		}

		wp_send_json_success( [
			'message' => \sprintf(
				'Sync complete. %s fonts imported.',
				\number_format_i18n( $result['count'] )
			),
			'count'   => $result['count'],
		] );
	}

	/**
	 * Handles per-font approve/disable AJAX toggle.
	 *
	 * @return never
	 */
	public function handle_toggle(): never {
		check_ajax_referer( self::NONCE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.', 'reason' => 'caps', 'step' => 'auth' ] );
		}

		$family   = \sanitize_text_field( wp_unslash( $_POST['family']   ?? '' ) );
		$approved = '1' === ( $_POST['approved'] ?? '0' );

		if ( '' === $family ) {
			wp_send_json_error( [ 'message' => 'Font family is required.', 'reason' => 'invalid_family', 'step' => 'validate' ] );
		}

		$this->provider->set_approved( $family, $approved );

		wp_send_json_success( [
			'family'   => $family,
			'approved' => $approved,
		] );
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueues assets for the Font Library page.
	 *
	 * @return void
	 */
	private function enqueue_assets(): void {
		Asset_Loader::enqueue_fonts_admin();
	}
}
