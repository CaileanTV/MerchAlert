<?php
/**
 * String Definitions — single source of truth for all translatable strings.
 *
 * @package CCMerchAlert\Translations
 */

namespace CCMerchAlert\Translations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers every translatable string with the String_Registry.
 *
 * Call `String_Definitions::register_all()` once at boot time (from
 * `Plugin::load_components()`) before any module calls `ccmerch_translate()`.
 *
 * To add a new translatable string:
 *   1. Add a `String_Registry::register()` call here.
 *   2. Use `ccmerch_translate('your.new.key')` in the PHP template.
 *   3. For JS usage, add the key to the `to_js_array()` call in the relevant
 *      renderer (Creator_Portal_Renderer or an admin enqueue callback).
 *
 * Must never: be instantiated.
 * Must never: read from or write to the database.
 */
final class String_Definitions {

	/**
	 * Registers all plugin translatable strings with String_Registry.
	 *
	 * Organised by context slug. The context is shown in the admin Translation
	 * Manager filter and has no effect on string resolution.
	 */
	public static function register_all(): void {
		// ── Checkout ─────────────────────────────────────────────────────────
		String_Registry::register( 'checkout.username.label',        'Stream Username',                     'checkout' );
		String_Registry::register( 'checkout.username.placeholder',  'Your streaming username (optional)',  'checkout' );

		// ── Portal UI ────────────────────────────────────────────────────────
		String_Registry::register( 'portal.tab.config',              'Overlay Configuration',               'portal' );
		String_Registry::register( 'portal.tab.history',             'Alert History',                       'portal' );
		String_Registry::register( 'portal.save.button',             'Save Settings',                       'portal' );
		String_Registry::register( 'portal.changelog.button',        'Changelog',                           'portal' );
		String_Registry::register( 'portal.source.heading',          'Browser Source URL',                  'portal' );
		String_Registry::register( 'portal.source.generate.button',  'Generate New Overlay ID',             'portal' );

		// ── Alert History ────────────────────────────────────────────────────
		String_Registry::register( 'history.col.order',              'Order',                               'history' );
		String_Registry::register( 'history.col.customer',           'Customer',                            'history' );
		String_Registry::register( 'history.col.alerts',             'Alerts',                              'history' );
		String_Registry::register( 'history.col.date',               'Date',                                'history' );
		String_Registry::register( 'history.col.actions',            'Actions',                             'history' );
		String_Registry::register( 'history.row.details',            'Details',                             'history' );
		String_Registry::register( 'history.row.hide',               'Hide',                                'history' );
		String_Registry::register( 'history.row.replay_order',       'Replay Order',                        'history' );
		String_Registry::register( 'history.row.alert_singular',     'alert',                               'history' );
		String_Registry::register( 'history.row.alert_plural',       'alerts',                              'history' );
		String_Registry::register( 'history.event.replay',           'Replay',                              'history' );
		String_Registry::register( 'history.empty',                  'No alerts yet.',                      'history' );

		// ── Overlay / Alert runtime ───────────────────────────────────────────
		String_Registry::register( 'overlay.anonymous',              'Anonymous',                           'overlay' );
	}
}
