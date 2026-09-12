/**
 * CCMerch Alert — Admin dashboard.
 *
 * Handles real-time order polling, a visible countdown timer, and filter tab
 * switching on the plugin dashboard page. Configuration is injected via
 * wp_localize_script() as the global `ccmerchDashboard` object.
 *
 * No external dependencies — plain ES5-compatible vanilla JS.
 * WordPress's built-in jQuery is intentionally NOT used; native APIs suffice.
 */
( function () {
	'use strict';

	/* global ccmerchDashboard */
	if ( typeof ccmerchDashboard === 'undefined' ) {
		return;
	}

	// ── State ──────────────────────────────────────────────────────────────────

	var activeFilter     = 'today';
	var lastSyncTime     = ccmerchDashboard.initialTime;
	var refreshInterval  = ccmerchDashboard.refreshInterval; // seconds
	var remainingSeconds = refreshInterval;
	var countdownTimer   = null;
	var syncing          = false;

	// ── Countdown ──────────────────────────────────────────────────────────────

	function startCountdown() {
		stopCountdown();
		remainingSeconds = refreshInterval;
		updateCountdownDisplay();
		countdownTimer = setInterval( onCountdownTick, 1000 );
	}

	function stopCountdown() {
		if ( countdownTimer !== null ) {
			clearInterval( countdownTimer );
			countdownTimer = null;
		}
	}

	function onCountdownTick() {
		remainingSeconds = Math.max( 0, remainingSeconds - 1 );
		updateCountdownDisplay();
		if ( remainingSeconds === 0 ) {
			stopCountdown();
			sync( false );
		}
	}

	function updateCountdownDisplay() {
		var el = document.getElementById( 'ccmerch-countdown' );
		if ( el ) {
			el.textContent = remainingSeconds + 's';
		}
	}

	// ── AJAX sync ──────────────────────────────────────────────────────────────

	/**
	 * Fires a sync request.
	 *
	 * @param {boolean} fullReload  true  → replace entire list (filter change).
	 *                              false → prepend only new orders since last sync.
	 */
	function sync( fullReload ) {
		if ( syncing ) {
			return;
		}
		syncing = true;

		var data = new FormData();
		data.append( 'action',              'ccmerch_dashboard_sync' );
		data.append( '_ajax_nonce',          ccmerchDashboard.nonce );
		data.append( 'filter',              activeFilter );
		data.append( 'last_sync_timestamp', fullReload ? '0' : String( lastSyncTime ) );

		fetch( ccmerchDashboard.ajaxUrl, { method: 'POST', body: data } )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( json ) {
				if ( json && json.success ) {
					// Update refresh interval from response so settings changes
					// take effect without requiring a page reload.
					if ( json.data && json.data.refresh_interval ) {
						refreshInterval = json.data.refresh_interval;
					}
					onSyncSuccess( json.data, fullReload );
				}
			} )
			.catch( function () {
				// Silent fail — next countdown cycle retries automatically.
			} )
			.finally( function () {
				syncing = false;
				startCountdown();
			} );
	}

	// ── Response handling ──────────────────────────────────────────────────────

	function onSyncSuccess( data, fullReload ) {
		lastSyncTime = data.server_time;
		updateCount( data.count );

		var list  = document.getElementById( 'ccmerch-orders-list' );
		var empty = document.getElementById( 'ccmerch-orders-empty' );

		if ( ! list ) {
			return;
		}

		if ( fullReload ) {
			list.innerHTML = data.orders_html || '';
			toggleEmpty( list, empty, data.is_empty );
			return;
		}

		if ( data.has_new ) {
			// Parse and prepend new cards. Server returns newest-first so the
			// first card in the markup ends up at the top after insertion.
			var tmp       = document.createElement( 'div' );
			tmp.innerHTML = data.orders_html;

			var frag = document.createDocumentFragment();
			while ( tmp.firstChild ) {
				frag.appendChild( tmp.firstChild );
			}

			list.insertBefore( frag, list.firstChild );
			toggleEmpty( list, empty, false );
		}
	}

	// ── DOM helpers ────────────────────────────────────────────────────────────

	function toggleEmpty( list, empty, isEmpty ) {
		if ( ! list || ! empty ) {
			return;
		}
		if ( isEmpty ) {
			list.classList.add( 'ccmerch-hidden' );
			empty.classList.remove( 'ccmerch-hidden' );
		} else {
			list.classList.remove( 'ccmerch-hidden' );
			empty.classList.add( 'ccmerch-hidden' );
		}
	}

	function updateCount( count ) {
		var el = document.getElementById( 'ccmerch-orders-count' );
		if ( el ) {
			el.textContent = String( count );
		}
	}

	// ── Filter tabs ────────────────────────────────────────────────────────────

	function initFilters() {
		var buttons = document.querySelectorAll( '.ccmerch-filter' );

		buttons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var newFilter = btn.getAttribute( 'data-filter' );
				if ( ! newFilter || newFilter === activeFilter ) {
					return;
				}

				// Update visual state.
				buttons.forEach( function ( b ) {
					b.classList.remove( 'ccmerch-filter--active' );
				} );
				btn.classList.add( 'ccmerch-filter--active' );

				// Switch filter, reset sync timestamp, and trigger a full reload.
				// stopCountdown() prevents the background timer from firing a
				// conflicting poll while the full-reload request is in flight.
				activeFilter = newFilter;
				lastSyncTime = 0;
				stopCountdown();
				sync( true );
			} );
		} );
	}

	// ── Boot ───────────────────────────────────────────────────────────────────

	initFilters();
	startCountdown();

} )();
