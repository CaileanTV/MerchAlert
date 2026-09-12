/**
 * CCMerch Alert — Overlay browser-source runtime. Beta 1.3.1.
 *
 * Single-entry-point IIFE that manages the complete Browser Source alert
 * lifecycle. State lives in RuntimeState (one place). Inter-module signals
 * flow through EventBus (named events). No module calls another module's
 * internals directly — only RuntimeState reads/writes and EventBus events.
 *
 * Module map:
 *   EventBus            — pub/sub; all internal signals flow here.
 *   RuntimeState        — single source of truth for all mutable runtime state.
 *   AudioManager        — alert sound playback; stateless side-effect.
 *   OverlayStateService — applies live config events to the DOM.
 *   DebugPanel          — observability; subscribes to EventBus; never writes state.
 *   QueueManager        — sequential alert display pipeline.
 *   ConnectionManager   — long-poll lifecycle; the only code that sends poll requests.
 *
 * Transport: fetch()-based long polling (primary). Each request is held by PHP for
 * up to 8 seconds and returns a complete HTTP response the moment events arrive or
 * the timeout elapses. The client immediately opens the next request. This is fully
 * compatible with Cloudflare and other reverse proxies that buffer SSE streams.
 *
 * Event flow:
 *   first successful poll → EVENTS.CONNECTION_OPENED  → state=CONNECTED, backoff reset
 *   poll with alerts      → EVENTS.ALERT_RECEIVED     → QueueManager.receive()
 *   poll with config      → EVENTS.OVERLAY_UPDATED    → OverlayStateService.applyConfig()
 *   empty poll            → EVENTS.HEARTBEAT_RECEIVED → (heartbeat / cursor advance)
 *   poll failure          → EVENTS.CONNECTION_LOST    → exponential backoff retry
 *   visibilitychange      → immediate reconnect if not CONNECTED
 *
 * Configuration injected via ccmerchOverlay (Overlay_Renderer::render_page()):
 *   ajaxUrl, overlayId, pollAction, longpollAction, streamAction, pollInterval,
 *   displayDuration, animDuration, initialTime, hasSecondary.
 *
 * Overlay DOM elements (position:fixed, independently placed by layout data):
 *   #ccmerch-overlay-image     — product thumbnail
 *   #ccmerch-overlay-text      — primary alert text
 *   #ccmerch-overlay-secondary — secondary text (conditional)
 */
( function () {
	'use strict';

	/* global ccmerchOverlay */
	if ( typeof ccmerchOverlay === 'undefined' ) {
		return;
	}

	// Singleton guard: if a previous IIFE instance already started (e.g. the
	// script was loaded twice), abort silently so only one runtime exists.
	if ( window.ccmerchRuntimeStarted ) {
		console.warn( '[CCMerch Runtime] Duplicate script load detected — aborting second instance.' );
		return;
	}
	window.ccmerchRuntimeStarted = true;

	/** Immutable boot-time configuration injected by the server. */
	var config = ccmerchOverlay;

	// ─────────────────────────────────────────────────────────────────────────
	// EVENTS — canonical list of all internal runtime signals.
	// Any module may emit or subscribe to these; no other strings are permitted.
	// ─────────────────────────────────────────────────────────────────────────

	var EVENTS = {
		// Connection lifecycle.
		CONNECTION_OPENING:   'connection:opening',
		CONNECTION_OPENED:    'connection:opened',
		CONNECTION_CLOSING:   'connection:closing',
		CONNECTION_LOST:      'connection:lost',
		CONNECTION_RECOVERED: 'connection:recovered',
		// Alert pipeline.
		ALERT_RECEIVED:  'alert:received',
		ALERT_QUEUED:    'alert:queued',
		ALERT_DISPLAYED: 'alert:displayed',
		ALERT_COMPLETE:  'alert:complete',
		// Overlay configuration.
		OVERLAY_UPDATED: 'overlay:updated',
		// Heartbeat.
		HEARTBEAT_RECEIVED: 'heartbeat:received',
		HEARTBEAT_TIMEOUT:  'heartbeat:timeout',
	};

	// ─────────────────────────────────────────────────────────────────────────
	// EVENT BUS — lightweight internal pub/sub.
	//
	// Listeners are copied before iteration so a handler that removes itself
	// mid-dispatch does not corrupt the loop. Errors in handlers are caught and
	// logged so one bad listener never silences the rest.
	// ─────────────────────────────────────────────────────────────────────────

	var EventBus = ( function () {
		var _listeners = {};

		function on( event, fn ) {
			if ( ! _listeners[ event ] ) { _listeners[ event ] = []; }
			_listeners[ event ].push( fn );
		}

		function off( event, fn ) {
			if ( ! _listeners[ event ] ) { return; }
			_listeners[ event ] = _listeners[ event ].filter( function ( f ) { return f !== fn; } );
		}

		function emit( event, data ) {
			var fns = ( _listeners[ event ] || [] ).slice();
			for ( var i = 0; i < fns.length; i++ ) {
				try { fns[ i ]( data ); }
				catch ( e ) { console.warn( '[CCMerch EventBus] Handler error on "' + event + '":', e ); }
			}
		}

		return { on: on, off: off, emit: emit };
	} )();

	// ─────────────────────────────────────────────────────────────────────────
	// RUNTIME STATE — single source of truth for all mutable runtime state.
	//
	// No module may declare its own state variables. Modules call RuntimeState
	// accessors. Writes that have side-effects (setConnection) also emit an
	// internal event so DebugPanel and other observers stay in sync without
	// being explicitly notified by the writing module.
	// ─────────────────────────────────────────────────────────────────────────

	var RuntimeState = ( function () {
		var CONNECTION = {
			DISCONNECTED: 'disconnected',
			CONNECTING:   'connecting',
			CONNECTED:    'connected',
			RECONNECTING: 'reconnecting',
		};

		var _s = {
			connection:        CONNECTION.DISCONNECTED,
			reconnectAttempts: 0,
			// Cursor: only events AFTER this timestamp are delivered.
			since:             config.initialTime || 0,
			// Alert queue.
			alertQueue: [],
			animating:  false,
			// Live config — may be updated by SSE config events.
			displayDuration: config.displayDuration,
			animDuration:    config.animDuration,
			hasSecondary:    config.hasSecondary,
		};

		function setConnection( state ) {
			_s.connection = state;
			// Notify observers (DebugPanel, etc.) without them knowing who changed it.
			EventBus.emit( 'runtime:state_changed', {
				connection: state,
				attempts:   _s.reconnectAttempts,
			} );
		}

		return {
			CONNECTION: CONNECTION,
			// Connection.
			getConnection:   function ()      { return _s.connection; },
			setConnection:   setConnection,
			// Cursor.
			getSince:        function ()      { return _s.since; },
			setSince:        function ( v )   { _s.since = v; },
			// Reconnect attempts.
			getAttempts:     function ()      { return _s.reconnectAttempts; },
			incAttempts:     function ()      { _s.reconnectAttempts = Math.min( _s.reconnectAttempts + 1, 10 ); },
			resetAttempts:   function ()      { _s.reconnectAttempts = 0; },
			// Alert queue.
			isAnimating:     function ()      { return _s.animating; },
			setAnimating:    function ( v )   { _s.animating = v; },
			enqueue:         function ( i )   { _s.alertQueue.push( i ); },
			dequeue:         function ()      { return _s.alertQueue.shift(); },
			queueLen:        function ()      { return _s.alertQueue.length; },
			// Live config accessors (updated by SSE config events).
			getDisplayDuration: function ()   { return _s.displayDuration; },
			getAnimDuration:    function ()   { return _s.animDuration; },
			getHasSecondary:    function ()   { return _s.hasSecondary; },
			applyConfigValues:  function ( js ) {
				if ( js.display_duration !== undefined ) { _s.displayDuration = js.display_duration; }
				if ( js.anim_duration    !== undefined ) { _s.animDuration    = js.anim_duration;    }
				if ( js.has_secondary    !== undefined ) { _s.hasSecondary    = js.has_secondary;    }
			},
		};
	} )();

	/** Shorthand so modules can write CONNECTION.CONNECTED etc. without prefix. */
	var CONNECTION = RuntimeState.CONNECTION;

	// ─────────────────────────────────────────────────────────────────────────
	// AUDIO MANAGER — alert sound playback.
	//
	// sound_url from the server is the sole gate: resolve_sound_url() returns ''
	// when audio is disabled or unconfigured. An empty URL means "no audio" — no
	// JS gate is added. OBS Browser Source (Chromium) permits autoplay without a
	// user gesture. Regular browser autoplay errors are caught silently.
	// ─────────────────────────────────────────────────────────────────────────

	var AudioManager = ( function () {
		var current = null;

		function play( url ) {
			if ( ! url ) {
				console.log( '[CCMerch Audio] No sound URL — skipping.' );
				return;
			}
			stop();
			console.log( '[CCMerch Audio] play() →', url );
			try {
				current = new Audio( url );
				current.play()
					.then( function ()    { console.log( '[CCMerch Audio] Playback started.' ); } )
					.catch( function ( e ) { console.warn( '[CCMerch Audio] Blocked by autoplay policy:', e ); } );
			} catch ( e ) {
				console.warn( '[CCMerch Audio] Audio() constructor failed:', e );
				current = null;
			}
		}

		function stop() {
			if ( current ) {
				current.pause();
				current.currentTime = 0;
				current = null;
			}
		}

		return { play: play, stop: stop };
	} )();

	// ─────────────────────────────────────────────────────────────────────────
	// FONT LOADER — injects a Google Fonts <link> into the page on demand.
	//
	// The Browser Source page is a long-lived document. Fonts are loaded once and
	// cached by the browser for the lifetime of the page. loadUrl() is idempotent:
	// calling it with a URL that was already injected is a silent no-op.
	//
	// Called at init-time with config.fontUrl (baked into the page by the server),
	// and again whenever OverlayStateService receives a config event that includes
	// a font_url key (live font changes without a page reload).
	// ─────────────────────────────────────────────────────────────────────────

	var FontLoader = ( function () {
		// Set of URLs already injected — prevents duplicate <link> elements.
		var _loaded = {};

		/**
		 * Injects a Google Fonts CSS2 stylesheet URL into the document <head>.
		 * Skips if url is empty or was already injected.
		 *
		 * @param {string} url Prebuilt Google Fonts CSS2 URL.
		 */
		function loadUrl( url ) {
			if ( ! url || _loaded[ url ] ) { return; }
			var link  = document.createElement( 'link' );
			link.rel  = 'stylesheet';
			link.href = url;
			document.head.appendChild( link );
			_loaded[ url ] = true;
		}

		return { loadUrl: loadUrl };
	} )();

	// ─────────────────────────────────────────────────────────────────────────
	// OVERLAY STATE SERVICE — applies server-pushed config events to the live DOM.
	//
	// Called when the SSE stream emits a `config` event (triggered whenever
	// overlay settings are saved from admin or creator portal). No page reload.
	//
	// CSS custom properties  → document.documentElement (cascade to all elements).
	// Element inline styles  → replaced atomically via style.cssText.
	// JS timing values       → RuntimeState.applyConfigValues() so the next
	//                          showAlert() call uses the updated durations.
	//
	// Animation class state (.ccmerch-overlay-el--hidden/visible/hiding) is
	// class-based, so it survives style.cssText replacement without being wiped.
	// ─────────────────────────────────────────────────────────────────────────

	var OverlayStateService = ( function () {
		var CSS_VAR_MAP = {
			anim:              '--ccmerch-anim',
			font_family:       '--ccmerch-font-family',
			font_size:         '--ccmerch-font-size',
			font_weight:       '--ccmerch-font-weight',
			text_color:        '--ccmerch-text-color',
			text_shadow:       '--ccmerch-text-shadow',
			text_align:        '--ccmerch-text-align',
			img_border_radius: '--ccmerch-img-border-radius',
		};

		var ELEMENT_ID_MAP = {
			product_image:  'ccmerch-overlay-image',
			main_text:      'ccmerch-overlay-text',
			secondary_text: 'ccmerch-overlay-secondary',
		};

		/**
		 * @param {object} data   Payload from the `config` SSE event.
		 * @param {string} ver    SSE last-event-id (config version marker); may be ''.
		 */
		function applyConfig( data, ver ) {
			// Load the new font before applying CSS vars so the browser begins
			// fetching the stylesheet before any text is re-rendered.
			if ( data.font_url ) { FontLoader.loadUrl( data.font_url ); }

			var root = document.documentElement;
			var vars = data.css_vars || {};
			for ( var key in vars ) {
				if ( Object.prototype.hasOwnProperty.call( vars, key ) && CSS_VAR_MAP[ key ] ) {
					root.style.setProperty( CSS_VAR_MAP[ key ], vars[ key ] );
				}
			}

			var elements = data.elements || {};
			for ( var elKey in elements ) {
				if ( ! Object.prototype.hasOwnProperty.call( elements, elKey ) ) { continue; }
				var domId = ELEMENT_ID_MAP[ elKey ];
				if ( ! domId ) { continue; }
				var el = document.getElementById( domId );
				if ( el ) {
					var classes = el.className; // preserve animation state
					el.style.cssText = elements[ elKey ];
					el.className = classes;
				}
			}

			RuntimeState.applyConfigValues( data.js || {} );
			console.log( '[CCMerch Runtime] Overlay configuration refreshed from server.' );
			EventBus.emit( EVENTS.OVERLAY_UPDATED, { config_version: ver || '' } );
		}

		return { applyConfig: applyConfig };
	} )();

	// ─────────────────────────────────────────────────────────────────────────
	// DEBUG PANEL — visible when ?debug=1 is in the Browser Source URL.
	//
	// Subscribes to EventBus events; never writes to RuntimeState or calls other
	// modules directly. All display data is derived from event payloads.
	//
	// Fields: Overlay ID (last 8), connection state, reconnect count, queue depth,
	//         last alert time, last config-push time, config version.
	// ─────────────────────────────────────────────────────────────────────────

	var DebugPanel = ( function () {
		var panel  = null;
		var rows   = {};
		var active = window.location.search.indexOf( 'debug=1' ) !== -1;

		function fmt( label, value ) {
			var pad = '                '.slice( 0, 16 - label.length );
			return label + pad + value;
		}

		function render() {
			if ( ! panel ) { return; }
			panel.innerText = [
				'CCMerch Debug',
				fmt( 'Overlay ID:',  rows.overlay      || '?' ),
				fmt( 'State:',       rows.state        || 'initializing' ),
				fmt( 'Reconnects:',  rows.reconnects   || '0' ),
				fmt( 'Queue Len:',   rows.queue        || '0' ),
				fmt( 'Last Alert:',  rows.last_alert   || '—' ),
				fmt( 'Last Config:', rows.last_config  || '—' ),
				fmt( 'Config Ver:',  rows.config_ver   || '(none)' ),
			].join( '\n' );
		}

		function set( key, value ) {
			if ( ! active ) { return; }
			rows[ key ] = value;
			render();
		}

		function ts( unix ) {
			return unix ? new Date( unix * 1000 ).toLocaleTimeString() : '—';
		}

		function init() {
			if ( ! active ) { return; }
			panel = document.createElement( 'div' );
			panel.id = 'ccmerch-debug-panel';
			panel.style.cssText = [
				'position:fixed', 'top:10px', 'left:10px',
				'background:rgba(0,0,0,0.85)', 'color:#39ff14',
				'font-family:monospace', 'font-size:12px', 'line-height:1.7',
				'white-space:pre', 'padding:10px 14px', 'z-index:99999',
				'border-radius:4px', 'pointer-events:none',
			].join( ';' );
			document.body.appendChild( panel );
			set( 'overlay', '...' + ( config.overlayId || '?' ).slice( -8 ) );
			set( 'state', 'initializing' );

			// Subscribe to EventBus — DebugPanel updates automatically without
			// any individual module needing to call DebugPanel.set() directly.
			EventBus.on( 'runtime:state_changed', function ( data ) {
				set( 'state', data.connection );
				set( 'reconnects', String( data.attempts ) );
			} );
			EventBus.on( EVENTS.ALERT_QUEUED, function () {
				set( 'queue', String( RuntimeState.queueLen() ) );
			} );
			EventBus.on( EVENTS.ALERT_DISPLAYED, function () {
				set( 'last_alert', ts( Math.floor( Date.now() / 1000 ) ) );
				set( 'queue', String( RuntimeState.queueLen() ) );
			} );
			EventBus.on( EVENTS.OVERLAY_UPDATED, function ( data ) {
				set( 'last_config', ts( Math.floor( Date.now() / 1000 ) ) );
				if ( data && data.config_version ) { set( 'config_ver', data.config_version ); }
			} );
		}

		return { init: init };
	} )();

	// ─────────────────────────────────────────────────────────────────────────
	// QUEUE MANAGER — sequential alert display pipeline.
	//
	// receive()    ingests a server alert batch; updates the since cursor;
	//              pushes items to RuntimeState.alertQueue; drains via process().
	//
	// process()    pops one item when not animating. Safe to call anytime — returns
	//              immediately when the queue is empty or an animation is running.
	//
	// _showAlert() renders and animates one full alert cycle across the three
	//              overlay elements. On completion, calls process() again so the
	//              queue drains automatically without any external coordination.
	//
	// All alert sources — live orders, test alerts, replay — call receive() via
	// the SSE `alert` event. There are no separate code paths.
	// ─────────────────────────────────────────────────────────────────────────

	var QueueManager = ( function () {
		function receive( queue, serverTime ) {
			if ( serverTime ) {
				RuntimeState.setSince( serverTime );
			}
			if ( ! queue || ! queue.length ) {
				return;
			}
			console.log(
				'[CCMerch Runtime] Received', queue.length, 'alert(s). Queue depth before:', RuntimeState.queueLen()
			);
			for ( var i = 0; i < queue.length; i++ ) {
				RuntimeState.enqueue( queue[ i ] );
				EventBus.emit( EVENTS.ALERT_QUEUED, { item: queue[ i ] } );
			}
			process();
		}

		function process() {
			if ( RuntimeState.isAnimating() || RuntimeState.queueLen() === 0 ) {
				return;
			}
			var item = RuntimeState.dequeue();
			console.log(
				'[CCMerch Queue] Dequeuing alert. sound_url:', item.sound_url || '(none)',
				'| Queue remaining:', RuntimeState.queueLen()
			);
			_showAlert(
				item.image_html     || '',
				item.text_html      || '',
				item.sound_url      || '',
				item.secondary_html || ''
			);
		}

		function _showAlert( imageHtml, textHtml, soundUrl, secondaryHtml ) {
			var imgEl  = document.getElementById( 'ccmerch-overlay-image' );
			var textEl = document.getElementById( 'ccmerch-overlay-text' );
			var secEl  = document.getElementById( 'ccmerch-overlay-secondary' );

			if ( ! imgEl || ! textEl ) {
				return;
			}

			RuntimeState.setAnimating( true );
			EventBus.emit( EVENTS.ALERT_DISPLAYED, { sound_url: soundUrl } );

			imgEl.innerHTML  = imageHtml;
			textEl.innerHTML = textHtml;

			var showSecondary = secEl && RuntimeState.getHasSecondary() && '' !== secondaryHtml;
			if ( secEl ) {
				secEl.innerHTML = showSecondary ? secondaryHtml : '';
			}

			// Collect visible elements that have content to animate.
			var animEls = [];
			if ( imgEl.style.display  !== 'none' && imageHtml )               { animEls.push( imgEl );  }
			if ( textEl.style.display !== 'none' )                             { animEls.push( textEl ); }
			if ( secEl && showSecondary && secEl.style.display !== 'none' )    { animEls.push( secEl );  }

			if ( animEls.length === 0 ) {
				RuntimeState.setAnimating( false );
				EventBus.emit( EVENTS.ALERT_COMPLETE, {} );
				process();
				return;
			}

			AudioManager.play( soundUrl );

			// Fade in.
			animEls.forEach( function ( el ) {
				el.classList.remove( 'ccmerch-overlay-el--hidden', 'ccmerch-overlay-el--hiding' );
				el.classList.add( 'ccmerch-overlay-el--visible' );
			} );

			// Hold → fade out → clear.
			setTimeout( function () {
				animEls.forEach( function ( el ) {
					el.classList.remove( 'ccmerch-overlay-el--visible' );
					el.classList.add( 'ccmerch-overlay-el--hiding' );
				} );

				setTimeout( function () {
					animEls.forEach( function ( el ) {
						el.classList.remove( 'ccmerch-overlay-el--hiding' );
						el.classList.add( 'ccmerch-overlay-el--hidden' );
						el.innerHTML = '';
					} );
					RuntimeState.setAnimating( false );
					EventBus.emit( EVENTS.ALERT_COMPLETE, {} );
					process();
				}, RuntimeState.getAnimDuration() );
			}, RuntimeState.getDisplayDuration() );
		}

		return { receive: receive, process: process };
	} )();

	// ─────────────────────────────────────────────────────────────────────────
	// CONNECTION MANAGER — long-poll lifecycle.
	//
	// Primary transport: fetch()-based long polling. Each POST request is held
	// by the PHP handler for up to 8 seconds and returns a complete HTTP response
	// the instant events are available (or the timeout elapses). The client opens
	// the next request immediately on response. This is fully compatible with
	// Cloudflare and other reverse proxies that buffer SSE streams.
	//
	// This is the only module that sends poll requests. QueueManager.receive()
	// is the single consumer of all incoming alert data — identical to the
	// previous SSE path, so all queue, animation, and audio code is unchanged.
	//
	// Connection state machine:
	//   DISCONNECTED → CONNECTING    connect() called; first request in flight
	//   CONNECTING   → CONNECTED     first successful JSON response received
	//   CONNECTED    → CONNECTED     subsequent successful responses (steady state)
	//   CONNECTED    → RECONNECTING  network error or bad response
	//   RECONNECTING → CONNECTING    retry timer fires
	//
	// Backoff: 1 s → 2 → 4 → 8 → 16 → 30 s (cap). Resets on every success.
	// visibilitychange: reconnects immediately when OBS scene becomes active.
	// ─────────────────────────────────────────────────────────────────────────

	var ConnectionManager = ( function () {
		var _active       = false;
		var _inFlight     = false;
		var _retryTimer   = null;
		var _controller   = null;
		var _connected    = false;

		var MAX_BACKOFF = 30000;

		function _getBackoffDelay() {
			var delay = Math.min( 1000 * Math.pow( 2, RuntimeState.getAttempts() ), MAX_BACKOFF );
			RuntimeState.incAttempts();
			return delay;
		}

		function _scheduleRetry( planned ) {
			if ( _retryTimer ) { clearTimeout( _retryTimer ); _retryTimer = null; }
			var delay = planned ? 100 : _getBackoffDelay();

			RuntimeState.setConnection( CONNECTION.RECONNECTING );
			EventBus.emit( EVENTS.CONNECTION_LOST, { planned: planned, delay: delay } );

			console.log(
				'[CCMerch Runtime] Reconnecting in', delay, 'ms.',
				planned ? '(planned)' : '(attempt ' + RuntimeState.getAttempts() + ', backoff ' + delay + ' ms)'
			);

			_retryTimer = setTimeout( _poll, delay );
		}

		function _poll() {
			if ( ! _active || _inFlight ) { return; }
			_inFlight = true;

			if ( ! _connected ) {
				RuntimeState.setConnection( CONNECTION.CONNECTING );
				EventBus.emit( EVENTS.CONNECTION_OPENING, {} );
				console.log( '[CCMerch Runtime] Connecting to alert stream...' );
			}

			_controller = window.AbortController ? new AbortController() : null;

			var body = new URLSearchParams();
			body.append( 'action',     config.longpollAction );
			body.append( 'overlay_id', config.overlayId );
			body.append( 'since',      String( RuntimeState.getSince() ) );

			var opts = { method: 'POST', body: body, cache: 'no-store' };
			if ( _controller ) { opts.signal = _controller.signal; }

			fetch( config.ajaxUrl, opts )
				.then( function ( r ) {
					if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); }
					return r.json();
				} )
				.then( function ( json ) {
					_inFlight   = false;
					_controller = null;
					if ( ! _active ) { return; }

					if ( ! json || ! json.success || ! json.data ) {
						_connected = false;
						_scheduleRetry( false );
						return;
					}

					// First successful response — mark as connected.
					if ( ! _connected ) {
						var wasRecovering = RuntimeState.getAttempts() > 0;
						_connected = true;
						RuntimeState.resetAttempts();
						RuntimeState.setConnection( CONNECTION.CONNECTED );
						EventBus.emit( EVENTS.CONNECTION_OPENED, {} );
						if ( wasRecovering ) { EventBus.emit( EVENTS.CONNECTION_RECOVERED, {} ); }
						console.log( '[CCMerch Runtime] Connected to alert runtime.' );
					}

					var d = json.data;

					// Config update pushed by the server (settings saved in admin/portal).
					if ( d.config ) {
						try {
							OverlayStateService.applyConfig( d.config, '' );
						} catch ( e ) {
							console.warn( '[CCMerch Runtime] Failed to apply config update:', e );
						}
					}

					if ( d.queue && d.queue.length > 0 ) {
						EventBus.emit( EVENTS.ALERT_RECEIVED, d );
						QueueManager.receive( d.queue, d.server_time || 0 );
					} else {
						// Empty response = heartbeat / no events yet.
						if ( d.server_time ) { RuntimeState.setSince( d.server_time ); }
						EventBus.emit( EVENTS.HEARTBEAT_RECEIVED, {} );
						console.log( '[CCMerch Runtime] Heartbeat — no new events.' );
					}

					// Immediately open the next request.
					_poll();
				} )
				.catch( function ( err ) {
					_inFlight   = false;
					_controller = null;
					if ( ! _active || ( err && err.name === 'AbortError' ) ) { return; }
					_connected = false;
					console.log( '[CCMerch Runtime] Long poll request failed:', err && err.message );
					_scheduleRetry( false );
				} );
		}

		// OBS Browser Source throttles setTimeout in inactive scenes. The
		// visibilitychange event fires reliably when the scene becomes active,
		// ensuring the connection is always live regardless of timer throttling.
		document.addEventListener( 'visibilitychange', function () {
			if ( document.visibilityState === 'visible' && ! _inFlight && RuntimeState.getConnection() !== CONNECTION.CONNECTED ) {
				console.log( '[CCMerch Runtime] Scene became visible — reconnecting.' );
				if ( _retryTimer ) { clearTimeout( _retryTimer ); _retryTimer = null; }
				_connected = false;
				_poll();
			}
		} );

		return {
			connect: function () {
				_active = true;
				_poll();
			},
		};
	} )();

	// ─────────────────────────────────────────────────────────────────────────
	// BOOTSTRAP — initialise modules and start the alert stream.
	// ─────────────────────────────────────────────────────────────────────────

	console.log( '[CCMerch Runtime] Overlay runtime starting. Version: Beta 1.6.0' );
	// Load the Google Font that was selected when the overlay page was rendered.
	// build_link_tag() already injected a <link> in the PHP page for the initial
	// font; loadUrl() here covers the case where the PHP <link> was not present
	// (e.g. the font changed while the Browser Source was already open and a config
	// SSE event already applied the new font_url before this init code ran).
	if ( config.fontUrl ) { FontLoader.loadUrl( config.fontUrl ); }
	DebugPanel.init();
	ConnectionManager.connect();

} )();
