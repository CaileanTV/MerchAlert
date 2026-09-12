/* global ccmerchLayout */
/**
 * CCMerch Alert — Overlay Designer.
 *
 * True WYSIWYG canvas. All appearance settings (background, typography, templates,
 * timing) are edited through the Properties panel. Layout positions write to
 * hidden inputs submitted with the Settings API form. Appearance settings write
 * to the hidden form fields in #ccmerch-overlay-appearance-fields.
 *
 * Three canvas elements (draggable/resizable):
 *   product_image  — product thumbnail
 *   main_text      — primary alert text (shows background preview)
 *   secondary_text — secondary text line (shows background preview)
 *
 * Virtual selection:
 *   background — clicking empty canvas area opens background + timing properties
 *
 * Two-way sync:
 *   Drag/resize    → hidden layout <input> fields → saved with Settings API form.
 *   Props panel    → hidden appearance form fields → saved with Settings API form.
 *   Form fields    → canvas re-renders immediately on any change.
 */
( function () {
	'use strict';

	if ( typeof ccmerchLayout === 'undefined' ) {
		return;
	}

	// ── Canvas constants ──────────────────────────────────────────────────────

	var CANVAS_W = 1920;
	var CANVAS_H = 1080;

	var LABELS = {
		product_image:  'Product Image',
		main_text:      'Main Alert Text',
		secondary_text: 'Secondary Text',
		background:     'Background',
	};

	var HANDLE_DIRS = [ 'nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w' ];

	// ── State ─────────────────────────────────────────────────────────────────

	var layout      = {};
	var preview     = {};
	var selected    = null;
	var scale       = 1;
	var dragState   = null;
	var resizeState = null;
	var dirty       = false;

	// DOM refs.
	var wrapEl       = null;
	var canvasEl     = null;
	var guideH       = null;
	var guideV       = null;
	var propsPanelEl = null;
	var propsEmptyEl = null;
	var overlays     = {};

	// ── Init ──────────────────────────────────────────────────────────────────

	function init() {
		wrapEl       = document.getElementById( 'ccmerch-layout-canvas-wrap' );
		propsPanelEl = document.getElementById( 'ccmerch-props-panel' );
		propsEmptyEl = document.getElementById( 'ccmerch-props-empty' );

		if ( ! wrapEl ) {
			return;
		}

		layout  = JSON.parse( JSON.stringify( ccmerchLayout.layout  || {} ) );
		preview = ccmerchLayout.preview || {};

		buildCanvas();
		buildAllOverlays();
		renderAll();
		bindCanvasEvents();
		bindToolbar();
		bindFormSync();
		initHiddenInputs();

		window.addEventListener( 'resize', function () {
			computeScale();
			renderAll();
		} );

		// Reset dirty flag on form submit. Use closest() so this works in both
		// the admin overlay page (options.php) and the creator portal (admin-post.php).
		var form = wrapEl ? wrapEl.closest( 'form' ) : null;
		if ( form ) {
			form.addEventListener( 'submit', function () {
				dirty = false;
			} );
		}

		// Preview data refresh — fired by ccmerch-utils.js after a successful
		// AJAX call to the preview-data endpoint. Updates the local preview
		// variable and re-renders the canvas immediately (no page reload).
		document.addEventListener( 'ccmerch:previewData', function ( e ) {
			if ( e.detail && typeof e.detail === 'object' ) {
				preview = e.detail;
				renderAll();
			}
		} );
	}

	// ── Canvas ────────────────────────────────────────────────────────────────

	function buildCanvas() {
		var label = document.createElement( 'div' );
		label.className   = 'ccmerch-layout-canvas-label';
		label.textContent = '1920 \xd7 1080';
		wrapEl.appendChild( label );

		canvasEl = document.createElement( 'div' );
		canvasEl.className = 'ccmerch-layout-canvas';
		wrapEl.appendChild( canvasEl );

		guideH = document.createElement( 'div' );
		guideH.className     = 'ccmerch-layout-guide ccmerch-layout-guide--h';
		guideH.style.display = 'none';
		canvasEl.appendChild( guideH );

		guideV = document.createElement( 'div' );
		guideV.className     = 'ccmerch-layout-guide ccmerch-layout-guide--v';
		guideV.style.display = 'none';
		canvasEl.appendChild( guideV );

		computeScale();
	}

	function computeScale() {
		var availW = wrapEl.clientWidth || 900;
		scale = availW / CANVAS_W;
		canvasEl.style.width  = availW + 'px';
		canvasEl.style.height = Math.round( availW * CANVAS_H / CANVAS_W ) + 'px';
	}

	// ── Element overlays ──────────────────────────────────────────────────────

	function buildAllOverlays() {
		[ 'product_image', 'main_text', 'secondary_text' ].forEach( buildOverlay );
	}

	function buildOverlay( id ) {
		var el = document.createElement( 'div' );
		el.className  = 'ccmerch-layout-el';
		el.dataset.id = id;

		var contentEl = document.createElement( 'div' );
		contentEl.className = 'ccmerch-layout-el__content';
		el.appendChild( contentEl );

		var labelEl = document.createElement( 'div' );
		labelEl.className   = 'ccmerch-layout-el__label';
		labelEl.textContent = LABELS[ id ] || id;
		el.appendChild( labelEl );

		HANDLE_DIRS.forEach( function ( dir ) {
			var h = document.createElement( 'div' );
			h.className      = 'ccmerch-layout-el__handle ccmerch-layout-el__handle--' + dir;
			h.dataset.handle = dir;
			el.appendChild( h );
		} );

		canvasEl.appendChild( el );
		overlays[ id ] = { el: el, contentEl: contentEl };
	}

	// ── Render ────────────────────────────────────────────────────────────────

	function renderAll() {
		computeScale();
		Object.keys( overlays ).forEach( renderOverlay );
	}

	function renderOverlay( id ) {
		var o = overlays[ id ];
		if ( ! o ) { return; }

		var d = ( layout.elements || {} )[ id ];
		if ( ! d ) { return; }

		o.el.style.left    = Math.round( d.x * scale ) + 'px';
		o.el.style.top     = Math.round( d.y * scale ) + 'px';
		o.el.style.width   = Math.round( d.w * scale ) + 'px';
		o.el.style.height  = Math.round( d.h * scale ) + 'px';
		o.el.style.display = d.visible ? '' : 'none';

		renderContent( id );
	}

	/**
	 * Renders element content and applies appearance settings.
	 *
	 * Text elements: show background colour/radius, padding, and rendered template.
	 * Image element: show product thumbnail with border-radius.
	 */
	function renderContent( id ) {
		var o = overlays[ id ];
		var d = ( layout.elements || {} )[ id ];
		if ( ! o || ! d ) { return; }

		var c = o.contentEl;

		if ( 'product_image' === id ) {
			o.el.style.background   = '';
			o.el.style.borderRadius = Math.round( ( d.border_radius || 0 ) * scale ) + 'px';
			o.el.style.overflow     = 'hidden';
			c.style.padding         = '';

			if ( preview.image_url ) {
				var img = c.querySelector( 'img' );
				if ( ! img ) {
					c.innerHTML = '';
					img = document.createElement( 'img' );
					c.appendChild( img );
				}
				img.src              = preview.image_url;
				img.style.width      = '100%';
				img.style.height     = '100%';
				img.style.objectFit  = 'cover';
				img.style.display    = 'block';
			} else {
				c.innerHTML = '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,0.3);font-size:' + Math.max( 10, Math.round( 14 * scale ) ) + 'px;background:rgba(255,255,255,0.05);">&#x1F5BC;</div>';
			}
			return;
		}

		// Text elements — apply background and typography from saved/live settings.
		var bgColor   = getFormVal( 'bg_color',      '#000000' );
		var bgOpacity = parseInt( getFormVal( 'bg_opacity', '80' ), 10 ) / 100;
		var radius    = parseInt( getFormVal( 'border_radius', '8' ),  10 );
		var padding   = parseInt( getFormVal( 'padding',      '20' ),  10 );

		var fontFamily = getFormVal( 'font_family', 'inherit' ) || 'inherit';
		var fontSize   = Math.max( 8, Math.round( parseInt( getFormVal( 'font_size', '32' ), 10 ) * scale ) );
		var fontWeight = getFormVal( 'font_weight', '700' );
		var textColor  = getFormVal( 'text_color',  '#ffffff' );
		var textAlign  = getRadioVal( 'text_align', 'center' );
		var textShadow = getFormVal( 'text_shadow', '' );

		// Background on element container.
		o.el.style.background   = hexToRgba( bgColor, bgOpacity );
		o.el.style.borderRadius = Math.round( radius * scale ) + 'px';
		o.el.style.overflow     = 'hidden';

		// Padding on content div (mirrors runtime inline style).
		c.style.padding    = Math.round( padding * scale ) + 'px';
		c.style.boxSizing  = 'border-box';

		// Template text.
		var templateField = ( 'main_text' === id ) ? 'template' : 'secondary_template';
		var templateDef   = ( 'main_text' === id )
			? '$Product was purchased by $FirstName!'
			: '';
		var templateText = getFormVal( templateField, templateDef );
		var previewText  = substituteTemplate( templateText );

		c.style.fontFamily  = fontFamily;
		c.style.fontSize    = fontSize + 'px';
		c.style.fontWeight  = fontWeight;
		c.style.color       = previewText ? textColor : 'rgba(255,255,255,0.3)';
		c.style.textAlign   = textAlign;
		c.style.textShadow  = textShadow || 'none';
		c.style.lineHeight  = '1.3';
		c.style.wordBreak   = 'break-word';
		c.style.width       = '100%';
		c.style.height      = '100%';
		c.style.overflow    = 'hidden';
		c.style.fontStyle   = previewText ? '' : 'italic';

		c.textContent = previewText || '(empty — enter template in properties panel)';
	}

	// ── Template substitution ─────────────────────────────────────────────────

	function substituteTemplate( template ) {
		if ( ! template ) { return ''; }
		var p = preview;
		return template
			.replace( /\$FirstName/g,   p.first_name   || 'Demo'         )
			.replace( /\$UserName/g,    p.first_name   || 'StreamUser'   )
			.replace( /\$Product/g,     p.product       || 'Test Product' )
			.replace( /\$Quantity/g,    String( p.quantity || 1 )         )
			.replace( /\$Category/g,    'Category'                        )
			.replace( /\$Variation/g,   ''                                )
			.replace( /\$OrderNumber/g, '#1001'                           );
	}

	// ── Color helper ──────────────────────────────────────────────────────────

	function hexToRgba( hex, opacity ) {
		hex = String( hex || '#000000' ).replace( /^#/, '' );
		if ( 3 === hex.length ) {
			hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
		}
		var r = parseInt( hex.substr( 0, 2 ), 16 ) || 0;
		var g = parseInt( hex.substr( 2, 2 ), 16 ) || 0;
		var b = parseInt( hex.substr( 4, 2 ), 16 ) || 0;
		var a = Math.max( 0, Math.min( 1, isNaN( opacity ) ? 0.8 : opacity ) );
		return 'rgba(' + r + ',' + g + ',' + b + ',' + a.toFixed( 2 ) + ')';
	}

	// ── Form field readers ────────────────────────────────────────────────────

	/** Reads a text / number / select / color / hidden field from the overlay settings form. */
	function getFormVal( field, def ) {
		var el = document.querySelector(
			'[name="ccmerch_overlay_settings[' + field + ']"]:not([type="radio"])'
		);
		return el ? el.value : def;
	}

	/** Reads the checked value from a radio group in the overlay settings form. */
	function getRadioVal( field, def ) {
		var checked = document.querySelector(
			'input[type="radio"][name="ccmerch_overlay_settings[' + field + ']"]:checked'
		);
		return checked ? checked.value : def;
	}

	// ── Appearance field writer ───────────────────────────────────────────────

	/**
	 * Writes a value to the hidden form field for an appearance setting, then
	 * re-renders the canvas so the change is immediately visible.
	 *
	 * @param {string} field Overlay settings field key.
	 * @param {string} value New value.
	 */
	function applyAppearanceProp( field, value ) {
		// Textarea.
		var ta = document.querySelector(
			'textarea[name="ccmerch_overlay_settings[' + field + ']"]'
		);
		if ( ta ) {
			ta.value = value;
			renderAll();
			dirty = true;
			return;
		}

		// Radio group.
		var radios = document.querySelectorAll(
			'input[type="radio"][name="ccmerch_overlay_settings[' + field + ']"]'
		);
		if ( radios.length ) {
			radios.forEach( function ( r ) { r.checked = ( r.value === value ); } );
			renderAll();
			dirty = true;
			return;
		}

		// Select / text / number / color / hidden.
		var el = document.querySelector(
			'[name="ccmerch_overlay_settings[' + field + ']"]'
		);
		if ( el ) {
			el.value = value;
			renderAll();
			dirty = true;
		}
	}

	// ── Two-way sync: form → canvas ───────────────────────────────────────────

	/**
	 * Watches all appearance form fields and re-renders the canvas whenever any
	 * of them change (including hidden fields written by applyAppearanceProp).
	 */
	function bindFormSync() {
		var watchFields = [
			'template', 'secondary_template',
			'font_family', 'font_size', 'font_weight',
			'text_color', 'text_shadow', 'text_align',
			'bg_color', 'bg_opacity', 'border_radius', 'padding',
		];

		watchFields.forEach( function ( field ) {
			document.querySelectorAll(
				'[name="ccmerch_overlay_settings[' + field + ']"]'
			).forEach( function ( el ) {
				el.addEventListener( 'change', function () { renderAll(); } );
				el.addEventListener( 'input',  function () { renderAll(); } );
			} );
		} );
	}

	// ── Two-way sync: canvas → hidden layout inputs ───────────────────────────

	function updateHiddenInputs( id ) {
		var d = ( layout.elements || {} )[ id ];
		if ( ! d ) { return; }

		var prefix = 'ccmerch-el-' + id + '-';
		setHidden( prefix + 'x',       d.x );
		setHidden( prefix + 'y',       d.y );
		setHidden( prefix + 'w',       d.w );
		setHidden( prefix + 'h',       d.h );
		setHidden( prefix + 'visible', d.visible ? '1' : '0' );

		if ( 'product_image' === id ) {
			setHidden( prefix + 'lock_aspect',   d.lock_aspect   ? '1' : '0' );
			setHidden( prefix + 'border_radius', d.border_radius || 0 );
		}
	}

	function updateCanvasHiddenInputs() {
		var c = layout.canvas || {};
		setHidden( 'ccmerch-canvas-snap_grid', c.snap_grid ? '1' : '0' );
		setHidden( 'ccmerch-canvas-grid_size', c.grid_size || 10 );
	}

	function setHidden( inputId, value ) {
		var el = document.getElementById( inputId );
		if ( el ) { el.value = String( value ); }
	}

	function initHiddenInputs() {
		[ 'product_image', 'main_text', 'secondary_text' ].forEach( updateHiddenInputs );
		updateCanvasHiddenInputs();
	}

	// ── Canvas mouse events ───────────────────────────────────────────────────

	function bindCanvasEvents() {
		canvasEl.addEventListener( 'mousedown', onMousedown );
		document.addEventListener( 'mousemove', onMousemove );
		document.addEventListener( 'mouseup',   onMouseup   );
	}

	function onMousedown( e ) {
		// Resize handle click.
		var handle = e.target.closest( '.ccmerch-layout-el__handle' );
		if ( handle ) {
			var elDiv = handle.closest( '.ccmerch-layout-el' );
			var id    = elDiv ? elDiv.dataset.id : null;
			if ( id && ( layout.elements || {} )[ id ] ) {
				e.preventDefault();
				var d = layout.elements[ id ];
				resizeState = {
					id: id, dir: handle.dataset.handle,
					startX: e.clientX, startY: e.clientY,
					origX: d.x, origY: d.y, origW: d.w, origH: d.h,
				};
				select( id );
			}
			return;
		}

		// Element click.
		var elDiv2 = e.target.closest( '.ccmerch-layout-el' );
		if ( elDiv2 ) {
			var id2 = elDiv2.dataset.id;
			if ( id2 && ( layout.elements || {} )[ id2 ] ) {
				e.preventDefault();
				var d2 = layout.elements[ id2 ];
				dragState = {
					id: id2,
					startX: e.clientX, startY: e.clientY,
					origX: d2.x, origY: d2.y,
				};
				select( id2 );
			}
			return;
		}

		// Click on empty canvas → select background.
		if ( e.target === canvasEl || e.target === guideH || e.target === guideV ) {
			select( 'background' );
		}
	}

	function onMousemove( e ) {
		if ( dragState ) {
			moveDrag( e );
		} else if ( resizeState ) {
			moveResize( e );
		}
	}

	function moveDrag( e ) {
		var dx  = ( e.clientX - dragState.startX ) / scale;
		var dy  = ( e.clientY - dragState.startY ) / scale;
		var d   = layout.elements[ dragState.id ];
		var raw = { x: dragState.origX + dx, y: dragState.origY + dy };

		raw.x = snapVal( raw.x );
		raw.y = snapVal( raw.y );

		d.x = Math.round( Math.max( 0, Math.min( CANVAS_W - d.w, raw.x ) ) );
		d.y = Math.round( Math.max( 0, Math.min( CANVAS_H - d.h, raw.y ) ) );

		showGuides( dragState.id );
		renderOverlay( dragState.id );
		syncPropsPosition( dragState.id );
		updateHiddenInputs( dragState.id );
		dirty = true;
	}

	function moveResize( e ) {
		var rs  = resizeState;
		var dx  = ( e.clientX - rs.startX ) / scale;
		var dy  = ( e.clientY - rs.startY ) / scale;
		var dir = rs.dir;
		var d   = layout.elements[ rs.id ];

		var newX = rs.origX;
		var newY = rs.origY;
		var newW = rs.origW;
		var newH = rs.origH;

		if ( dir.indexOf( 'e' ) !== -1 ) { newW = rs.origW + dx; }
		if ( dir.indexOf( 'w' ) !== -1 ) { newW = rs.origW - dx; newX = rs.origX + dx; }
		if ( dir.indexOf( 's' ) !== -1 ) { newH = rs.origH + dy; }
		if ( dir.indexOf( 'n' ) !== -1 ) { newH = rs.origH - dy; newY = rs.origY + dy; }

		if ( 'product_image' === rs.id && d.lock_aspect && rs.origH > 0 ) {
			var aspect = rs.origW / rs.origH;
			if ( dir === 'e' || dir === 'w' ) {
				newH = newW / aspect;
			} else if ( dir === 'n' || dir === 's' ) {
				newW = newH * aspect;
			} else {
				newW = Math.max( newW, newH * aspect );
				newH = newW / aspect;
			}
		}

		newW = Math.max( 20, snapVal( newW ) );
		newH = Math.max( 20, snapVal( newH ) );

		d.x = Math.round( newX );
		d.y = Math.round( newY );
		d.w = Math.round( newW );
		d.h = Math.round( newH );

		renderOverlay( rs.id );
		syncPropsPosition( rs.id );
		updateHiddenInputs( rs.id );
		dirty = true;
	}

	function onMouseup() {
		dragState   = null;
		resizeState = null;
		hideGuides();
	}

	// ── Alignment guides ──────────────────────────────────────────────────────

	var GUIDE_THRESHOLD = 8;

	function showGuides( id ) {
		var d  = layout.elements[ id ];
		var cx = d.x + d.w / 2;
		var cy = d.y + d.h / 2;

		if ( Math.abs( cx - CANVAS_W / 2 ) < GUIDE_THRESHOLD ) {
			guideV.style.display = '';
			guideV.style.left    = Math.round( CANVAS_W / 2 * scale ) + 'px';
			d.x = Math.round( CANVAS_W / 2 - d.w / 2 );
		} else {
			guideV.style.display = 'none';
		}

		if ( Math.abs( cy - CANVAS_H / 2 ) < GUIDE_THRESHOLD ) {
			guideH.style.display = '';
			guideH.style.top     = Math.round( CANVAS_H / 2 * scale ) + 'px';
			d.y = Math.round( CANVAS_H / 2 - d.h / 2 );
		} else {
			guideH.style.display = 'none';
		}
	}

	function hideGuides() {
		if ( guideH ) { guideH.style.display = 'none'; }
		if ( guideV ) { guideV.style.display = 'none'; }
	}

	// ── Grid snap ─────────────────────────────────────────────────────────────

	function snapVal( v ) {
		if ( ! ( ( layout.canvas || {} ).snap_grid ) ) { return v; }
		var gs = ( layout.canvas.grid_size || 10 );
		return Math.round( v / gs ) * gs;
	}

	// ── Selection ─────────────────────────────────────────────────────────────

	function select( id ) {
		selected = id;
		Object.keys( overlays ).forEach( function ( oid ) {
			overlays[ oid ].el.classList.toggle( 'ccmerch-layout-el--selected', oid === id );
		} );
		if ( canvasEl ) {
			canvasEl.classList.toggle( 'ccmerch-layout-canvas--bg-selected', id === 'background' );
		}
		renderProps();
	}

	// ── Properties panel ─────────────────────────────────────────────────────

	function renderProps() {
		var isElement = selected && selected !== 'background' && ( layout.elements || {} )[ selected ];
		var isBg      = selected === 'background';

		if ( ! isElement && ! isBg ) {
			if ( propsEmptyEl ) { propsEmptyEl.style.display = ''; }
			if ( propsPanelEl ) { propsPanelEl.style.display = 'none'; propsPanelEl.innerHTML = ''; }
			return;
		}

		if ( propsEmptyEl ) { propsEmptyEl.style.display = 'none'; }
		if ( ! propsPanelEl ) { return; }
		propsPanelEl.style.display = '';

		var html = '<div class="ccmerch-layout-props__header">' + esc( LABELS[ selected ] || selected ) + '</div>';
		html += isBg ? buildBackgroundProps() : buildElementProps( selected );

		propsPanelEl.innerHTML = html;
		bindPropInputs();
		bindAppearanceInputs();
		if ( window.CCMerchFonts ) {
			CCMerchFonts.FontSelector.initAll( propsPanelEl );
		}
	}

	// ── Background properties ─────────────────────────────────────────────────

	function buildBackgroundProps() {
		var html = '';

		html += '<div class="ccmerch-layout-props__section">';
		html += propRow( 'Background Color',
			colorInput( 'bg_color', getFormVal( 'bg_color', '#000000' ) ) );
		html += propRow( 'Opacity',
			numInput( 'bg_opacity', getFormVal( 'bg_opacity', '80' ), 0, 100 ) +
			suffix( '%' ) );
		html += propRow( 'Border Radius',
			numInput( 'border_radius', getFormVal( 'border_radius', '8' ), 0, 100 ) +
			suffix( 'px' ) );
		html += propRow( 'Padding',
			numInput( 'padding', getFormVal( 'padding', '20' ), 0, 200 ) +
			suffix( 'px' ) );
		html += propRow( 'Text Shadow',
			textInput( 'text_shadow', getFormVal( 'text_shadow', '' ) ) );
		html += '</div>';

		html += '<div class="ccmerch-layout-props__section">';
		html += propSectionLabel( 'Timing' );
		html += propRow( 'Fade Duration',
			numInput( 'anim_duration', getFormVal( 'anim_duration', '500' ), 0, 5000 ) +
			suffix( 'ms' ) );
		html += propRow( 'Display Duration',
			numInput( 'display_duration', getFormVal( 'display_duration', '5000' ), 1000, 30000 ) +
			suffix( 'ms' ) );
		html += '</div>';

		html += '<div class="ccmerch-layout-props__section">';
		html += '<p style="font-size:11px;color:#8c8f94;margin:0;">Click an element on the canvas to edit its content, position, and typography.</p>';
		html += '</div>';

		return html;
	}

	// ── Element properties ────────────────────────────────────────────────────

	function buildElementProps( id ) {
		var d    = layout.elements[ id ];
		var html = '';

		if ( 'main_text' === id || 'secondary_text' === id ) {
			// Template.
			var tField = ( 'main_text' === id ) ? 'template' : 'secondary_template';
			var tDef   = ( 'main_text' === id )
				? '$Product was purchased by $FirstName!'
				: '';
			var tVal = getFormVal( tField, tDef );

			html += '<div class="ccmerch-layout-props__section">';
			html += propSectionLabel( 'Template' );
			html += '<div class="ccmerch-layout-props__row">';
			html += '<textarea data-appearance="' + esc( tField ) + '" rows="3">' + esc( tVal ) + '</textarea>';
			html += '<p style="font-size:11px;color:#8c8f94;margin:4px 0 0;">$FirstName &nbsp;$UserName &nbsp;$Product &nbsp;$Quantity &nbsp;$Category &nbsp;$Variation &nbsp;$OrderNumber</p>';
			html += '</div>';
			html += '</div>';

			// Typography.
			html += '<div class="ccmerch-layout-props__section">';
			html += propSectionLabel( 'Typography' );
			html += propRow( 'Font Family',
				fontSelectInput( 'font_family', getFormVal( 'font_family', 'inherit' ) ) );
			html += '<div class="ccmerch-layout-props__row ccmerch-layout-props__row--half">';
			html += '<div>' + halfLabel( 'Font Size',
				numInput( 'font_size', getFormVal( 'font_size', '32' ), 8, 200 ) +
				suffix( 'px' ) ) + '</div>';
			html += '<div>' + halfLabel( 'Weight', fontWeightSelect() ) + '</div>';
			html += '</div>';
			html += propRow( 'Text Color',
				colorInput( 'text_color', getFormVal( 'text_color', '#ffffff' ) ) );
			html += alignmentRow();
			html += '</div>';
		}

		if ( 'product_image' === id ) {
			html += '<div class="ccmerch-layout-props__section">';
			html += '<div class="ccmerch-layout-props__row ccmerch-layout-props__row--inline">';
			html += '<label><input type="checkbox" data-prop="lock_aspect"' + ( d.lock_aspect ? ' checked' : '' ) + '> Lock aspect ratio</label>';
			html += '</div>';
			html += propRow( 'Border Radius',
				numCell2( 'border_radius', d.border_radius || 0, 0, 500 ) + suffix( 'px' ) );
			html += propRow( 'Image Quality',
				numInput( 'image_size', getFormVal( 'image_size', '120' ), 32, 512 ) +
				suffix( 'px' ) );
			html += '<p style="font-size:11px;color:#8c8f94;margin:0;">Image Quality sets the fetch resolution from the media library — not the display size.</p>';
			html += '</div>';
		}

		// Position + size.
		html += '<div class="ccmerch-layout-props__section">';
		html += propSectionLabel( 'Position &amp; Size' );
		html += '<div class="ccmerch-layout-props__row ccmerch-layout-props__row--xywh">';
		html += numCell( 'X', 'x', d.x );
		html += numCell( 'Y', 'y', d.y );
		html += numCell( 'W', 'w', d.w, 20 );
		html += numCell( 'H', 'h', d.h, 20 );
		html += '</div>';
		html += '</div>';

		// Visibility.
		html += '<div class="ccmerch-layout-props__section">';
		html += '<div class="ccmerch-layout-props__row ccmerch-layout-props__row--inline">';
		html += '<label><input type="checkbox" data-prop="visible"' + ( d.visible ? ' checked' : '' ) + '> Visible</label>';
		html += '</div>';
		html += '</div>';

		return html;
	}

	// ── Props sync ────────────────────────────────────────────────────────────

	function syncPropsPosition( id ) {
		if ( selected !== id || ! propsPanelEl ) { return; }
		var d = layout.elements[ id ];
		setInputVal( 'x', d.x );
		setInputVal( 'y', d.y );
		setInputVal( 'w', d.w );
		setInputVal( 'h', d.h );
	}

	function setInputVal( prop, val ) {
		var el = propsPanelEl ? propsPanelEl.querySelector( '[data-prop="' + prop + '"]' ) : null;
		if ( el ) { el.value = val; }
	}

	// ── Props event binding ───────────────────────────────────────────────────

	/** Binds layout props (data-prop) — writes to layout.elements[selected]. */
	function bindPropInputs() {
		if ( ! propsPanelEl ) { return; }
		propsPanelEl.querySelectorAll( '[data-prop]' ).forEach( function ( input ) {
			var handler = function () { applyProp( input.dataset.prop, input ); };
			input.addEventListener( 'change', handler );
			if ( input.type !== 'color' ) {
				input.addEventListener( 'input', handler );
			}
		} );
	}

	/** Binds appearance props (data-appearance) — writes to hidden form fields. */
	function bindAppearanceInputs() {
		if ( ! propsPanelEl ) { return; }
		propsPanelEl.querySelectorAll( '[data-appearance]' ).forEach( function ( input ) {
			var handler = function () {
				applyAppearanceProp( input.dataset.appearance, input.value );
				// Re-populate props panel for fields that drive other panel fields
				// (e.g. text_align radio updates font weight select label).
				// Lightweight: only re-render full props when it's a select/radio group.
				if ( input.type === 'radio' || input.tagName === 'SELECT' ) {
					// Don't rebuild panel (causes focus loss); just update other visuals.
				}
			};
			input.addEventListener( 'change', handler );
			input.addEventListener( 'input',  handler );
		} );
	}

	function applyProp( prop, input ) {
		if ( ! selected ) { return; }
		var d = layout.elements[ selected ];
		if ( ! d ) { return; }

		var numProps  = [ 'x', 'y', 'w', 'h', 'border_radius' ];
		var boolProps = [ 'visible', 'lock_aspect' ];

		if ( numProps.indexOf( prop ) !== -1 ) {
			d[ prop ] = parseFloat( input.value ) || 0;
		} else if ( boolProps.indexOf( prop ) !== -1 ) {
			d[ prop ] = input.checked;
		} else {
			d[ prop ] = input.value;
		}

		// Aspect lock.
		if ( 'product_image' === selected && d.lock_aspect ) {
			if ( prop === 'w' ) { d.h = d.w; setInputVal( 'h', d.h ); }
			if ( prop === 'h' ) { d.w = d.h; setInputVal( 'w', d.w ); }
		}

		renderOverlay( selected );
		updateHiddenInputs( selected );
		dirty = true;
	}

	// ── HTML builders ─────────────────────────────────────────────────────────

	function esc( str ) {
		return String( str || '' )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function propSectionLabel( text ) {
		return '<div style="font-size:11px;font-weight:600;color:#50575e;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">' + text + '</div>';
	}

	function propRow( label, control ) {
		return '<div class="ccmerch-layout-props__row"><label style="font-size:12px;color:#50575e;font-weight:500;">' +
			esc( label ) + '<br>' + control + '</label></div>';
	}

	function halfLabel( label, control ) {
		return '<label style="display:block;font-size:12px;color:#50575e;font-weight:500;">' +
			esc( label ) + '<br>' + control + '</label>';
	}

	function suffix( text ) {
		return ' <span style="font-size:11px;color:#8c8f94;">' + esc( text ) + '</span>';
	}

	function row( modifier, inner ) {
		var cls = 'ccmerch-layout-props__row' + ( modifier ? ' ccmerch-layout-props__row--' + modifier : '' );
		return '<div class="' + cls + '">' + inner + '</div>';
	}

	function numCell( lbl, prop, val, min ) {
		return '<div><label style="font-size:11px;color:#8c8f94;">' + esc( lbl ) +
			'<br><input type="number" data-prop="' + esc( prop ) + '" value="' + esc( String( val ) ) + '"' +
			( min !== undefined ? ' min="' + min + '"' : '' ) + '></label></div>';
	}

	function numCell2( prop, val, min, max ) {
		return '<input type="number" data-prop="' + esc( prop ) + '" value="' + esc( String( val ) ) + '"' +
			( min !== undefined ? ' min="' + min + '"' : '' ) +
			( max !== undefined ? ' max="' + max + '"' : '' ) +
			' style="width:72px;">';
	}

	function numInput( field, val, min, max ) {
		return '<input type="number" data-appearance="' + esc( field ) + '" value="' + esc( String( val ) ) + '"' +
			( min !== undefined ? ' min="' + min + '"' : '' ) +
			( max !== undefined ? ' max="' + max + '"' : '' ) +
			' style="width:72px;">';
	}

	function textInput( field, val ) {
		return '<input type="text" data-appearance="' + esc( field ) + '" value="' + esc( String( val ) ) + '">';
	}

	function fontSelectInput( field, currentVal ) {
		// If CCMerchFonts is loaded, emit the custom FontSelector widget so
		// each option shows a live font preview.  Falls back to a plain <select>
		// when the script is absent (e.g. in tests or future compat).
		if ( window.CCMerchFonts ) {
			var val = currentVal || 'inherit';
			var lbl = ( val && 'inherit' !== val ) ? esc( val ) : '— Default —';
			var swatchStyle = ( val && 'inherit' !== val )
				? ' style="font-family:\'' + esc( val ) + '\',sans-serif"'
				: ' style="display:none"';

			return '<div class="ccmerch-fs" data-font-selector>'
				+ '<div class="ccmerch-fs__trigger" tabindex="0" role="button"'
				+ ' aria-haspopup="listbox" aria-expanded="false">'
				+ '<span class="ccmerch-fs__trigger-swatch"' + swatchStyle + '>Aa</span>'
				+ '<span class="ccmerch-fs__trigger-label">' + lbl + '</span>'
				+ '<span class="ccmerch-fs__trigger-arrow">&#9660;</span>'
				+ '</div>'
				+ '<div class="ccmerch-fs__panel" hidden>'
				+ '<input type="search" class="ccmerch-fs__search" placeholder="Search fonts…">'
				+ '<div class="ccmerch-fs__list" role="listbox"></div>'
				+ '</div>'
				+ '<input type="hidden" data-appearance="' + esc( field ) + '" value="' + esc( val ) + '">'
				+ '</div>';
		}

		// Fallback plain <select> (no CCMerchFonts present).
		var fonts = ( window.ccmerchApprovedFonts && Array.isArray( window.ccmerchApprovedFonts ) )
			? window.ccmerchApprovedFonts
			: [];
		var html = '<select data-appearance="' + esc( field ) + '" style="max-width:160px;">';
		html += '<option value="inherit"' + ( 'inherit' === currentVal || '' === currentVal ? ' selected' : '' ) + '>— Default —</option>';
		fonts.forEach( function ( item ) {
			var family = 'string' === typeof item ? item : ( item.family || '' );
			if ( ! family ) { return; }
			html += '<option value="' + esc( family ) + '"' + ( family === currentVal ? ' selected' : '' ) + '>' + esc( family ) + '</option>';
		} );
		html += '</select>';
		return html;
	}

	function colorInput( field, val ) {
		return '<input type="color" data-appearance="' + esc( field ) + '" value="' + esc( String( val ) ) + '">';
	}

	function fontWeightSelect() {
		var current = getFormVal( 'font_weight', '700' );
		var opts = [
			[ '400', 'Normal'    ],
			[ '500', 'Medium'    ],
			[ '600', 'Semi Bold' ],
			[ '700', 'Bold'      ],
			[ '800', 'Extra Bold'],
			[ '900', 'Black'     ],
		];
		var html = '<select data-appearance="font_weight">';
		opts.forEach( function ( o ) {
			html += '<option value="' + o[0] + '"' + ( current === o[0] ? ' selected' : '' ) + '>' + esc( o[1] ) + '</option>';
		} );
		html += '</select>';
		return html;
	}

	function alignmentRow() {
		var current = getRadioVal( 'text_align', 'center' );
		var opts = [ [ 'left', 'Left' ], [ 'center', 'Center' ], [ 'right', 'Right' ] ];
		var html = '<div class="ccmerch-layout-props__row">';
		html += '<span style="font-size:12px;color:#50575e;font-weight:500;">Alignment</span>';
		html += '<div style="display:flex;gap:10px;margin-top:4px;">';
		opts.forEach( function ( o ) {
			html += '<label style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:normal;cursor:pointer;">';
			html += '<input type="radio" data-appearance="text_align" value="' + o[0] + '"' +
				( current === o[0] ? ' checked' : '' ) + '> ' + esc( o[1] );
			html += '</label>';
		} );
		html += '</div></div>';
		return html;
	}

	// ── Toolbar ───────────────────────────────────────────────────────────────

	function bindToolbar() {
		var snapToggle   = document.getElementById( 'ccmerch-snap-toggle' );
		var gridSizeEl   = document.getElementById( 'ccmerch-grid-size' );
		var gridSizeWrap = document.getElementById( 'ccmerch-grid-size-wrap' );

		if ( snapToggle && layout.canvas ) {
			snapToggle.checked = !! layout.canvas.snap_grid;
			if ( gridSizeWrap ) { gridSizeWrap.style.display = snapToggle.checked ? '' : 'none'; }
		}
		if ( gridSizeEl && layout.canvas ) {
			gridSizeEl.value = layout.canvas.grid_size || 10;
		}

		if ( snapToggle ) {
			snapToggle.addEventListener( 'change', function () {
				if ( ! layout.canvas ) { layout.canvas = {}; }
				layout.canvas.snap_grid = snapToggle.checked;
				if ( gridSizeWrap ) { gridSizeWrap.style.display = snapToggle.checked ? '' : 'none'; }
				updateCanvasHiddenInputs();
				dirty = true;
			} );
		}

		if ( gridSizeEl ) {
			gridSizeEl.addEventListener( 'change', function () {
				if ( ! layout.canvas ) { layout.canvas = {}; }
				layout.canvas.grid_size = Math.max( 5, Math.min( 100, parseInt( gridSizeEl.value, 10 ) || 10 ) );
				updateCanvasHiddenInputs();
				dirty = true;
			} );
		}
	}

	// ── Unsaved-changes guard ─────────────────────────────────────────────────

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

} )();
