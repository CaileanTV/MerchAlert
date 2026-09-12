/**
 * CCMerch Alert — shared utility functions.
 *
 * Loaded by every admin page and the Creator Portal that needs clipboard
 * helpers. Do not add page-specific logic here.
 *
 * @package CCMerchAlert
 */
( function () {
	'use strict';

	// -------------------------------------------------------------------------
	// CustomerNameFormatter — shared customer name display component
	// -------------------------------------------------------------------------
	// Single source of truth for formatting customer first names in the UI.
	// Implements optional privacy (anonymize) mode that replaces every character
	// with '*', preserving length. Preference is persisted in localStorage.
	//
	// Rules (CLAUDE.md Component-First):
	//   · This is the ONLY place that defines anonymization logic.
	//   · Any future module that displays customer names must call
	//     CustomerNameFormatter.format() instead of using the raw name.
	//   · Never duplicate this logic elsewhere in JS or PHP.

	var CustomerNameFormatter = ( function () {
		var LS_KEY   = 'ccmerch_anonymize_names';
		var _enabled = false;

		try {
			_enabled = localStorage.getItem( LS_KEY ) === '1';
		} catch ( e ) { /* localStorage unavailable — default to off */ }

		return {
			isEnabled: function () {
				return _enabled;
			},
			setEnabled: function ( val ) {
				_enabled = !! val;
				try {
					localStorage.setItem( LS_KEY, _enabled ? '1' : '0' );
				} catch ( e ) {}
			},
			/**
			 * Formats a customer name for display.
			 *
			 * When privacy mode is enabled, every character is replaced with '*'
			 * so the name length is preserved but the identity is hidden.
			 *
			 * @param  {string} name  Raw customer first name.
			 * @return {string}
			 */
			format: function ( name ) {
				if ( ! name ) { return ''; }
				if ( ! _enabled ) { return name; }
				return new Array( name.length + 1 ).join( '*' );
			}
		};
	} )();

	/**
	 * Copies a URL to the clipboard with a visual feedback cycle on the button.
	 *
	 * HTTP-safe: tries the Clipboard API first (HTTPS / localhost), falls back
	 * to textarea + execCommand, and prompts the user as a last resort.
	 *
	 * Button markup conventions (CLAUDE.md):
	 *   data-copied    — label shown on success   (default: '✓ Copied!')
	 *   data-copy-fail — label shown on failure   (default: 'Unable to copy.')
	 *
	 * @param {HTMLElement} btn  Trigger button element (pass `this` from onclick).
	 * @param {string}      url  URL to copy to the clipboard.
	 */
	function ccmerchCopyToClipboard( btn, url ) {
		var originalText = btn.textContent;
		var successText  = btn.getAttribute( 'data-copied' )    || '✓ Copied!';
		var failText     = btn.getAttribute( 'data-copy-fail' ) || 'Unable to copy.';

		function onSuccess() {
			btn.textContent = successText;
			setTimeout( function () { btn.textContent = originalText; }, 2000 );
		}

		function onFailure() {
			btn.textContent = failText;
			setTimeout( function () { btn.textContent = originalText; }, 3000 );
			window.prompt( 'Copy this link manually:', url );
		}

		// Clipboard API requires a secure context (HTTPS / localhost).
		if ( window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( url ).then( onSuccess, onFailure );
			return;
		}

		// Legacy fallback: hidden textarea + execCommand.
		try {
			var ta = document.createElement( 'textarea' );
			ta.value = url;
			ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:1px;height:1px;opacity:0;';
			document.body.appendChild( ta );
			ta.focus();
			ta.select();
			var ok = document.execCommand( 'copy' );
			document.body.removeChild( ta );
			if ( ok ) { onSuccess(); } else { onFailure(); }
		} catch ( e ) {
			onFailure();
		}
	}

	// Expose globally so onclick attributes work across all pages.
	window.ccmerchCopyToClipboard = ccmerchCopyToClipboard;

	// Named aliases used by specific pages (map to the same implementation).
	window.ccmerchCopyPortalUrl = ccmerchCopyToClipboard; // Creator_Admin list page
	window.ccmerchCopyLink      = ccmerchCopyToClipboard; // Creator_Admin edit page

	// Expose the revoke handler globally so onclick fallback works even if the
	// delegated listener below was registered before the section became visible.
	window.ccmerchRevokeChannelAuth = ccmerchRevokeChannelAuth;

	// -------------------------------------------------------------------------
	// Test Controls — shared AJAX handler
	// -------------------------------------------------------------------------
	// Handles clicks on any button rendered by Test_Controls_Component. Reads
	// all AJAX parameters from data attributes so no per-page JavaScript is
	// required. Covers:
	//   Admin Overlay page — Send Test Alert + Replay Latest Order (WP nonce auth)
	//   Creator Portal     — Send Test Alert (creator-token auth)

	/**
	 * Fires a test-controls AJAX request for the clicked button.
	 *
	 * @param {HTMLElement} btn Button with data-ccmerch-test-action.
	 */
	function ccmerchSendTestRequest( btn ) {
		var card     = btn.closest( '.ccmerch-test-controls' );
		var feedback = card ? card.querySelector( '.ccmerch-test-feedback' ) : null;
		var origLabel = btn.textContent;

		btn.disabled    = true;
		btn.textContent = btn.getAttribute( 'data-ccmerch-sending' ) || 'Sending…';
		if ( feedback ) { feedback.style.display = 'none'; }

		var formData = new FormData();
		formData.append( 'action',                                   btn.getAttribute( 'data-ccmerch-test-action' ) );
		formData.append( btn.getAttribute( 'data-ccmerch-auth-field' ), btn.getAttribute( 'data-ccmerch-auth-value' ) );

		fetch( btn.getAttribute( 'data-ccmerch-ajax-url' ), { method: 'POST', body: formData } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				var ok  = !! ( json && json.success );
				var msg = ( json && json.data && json.data.message )
					? json.data.message
					: ( ok
						? ( btn.getAttribute( 'data-ccmerch-success'   ) || 'Queued! Watch your overlay.' )
						: ( btn.getAttribute( 'data-ccmerch-error'     ) || 'Something went wrong. Please try again.' ) );
				if ( feedback ) {
					feedback.textContent   = msg;
					feedback.style.color   = ok ? '#00a32a' : '#d63638';
					feedback.style.display = 'block';
				}
			} )
			.catch( function () {
				if ( feedback ) {
					feedback.textContent   = btn.getAttribute( 'data-ccmerch-net-error' ) || 'Request failed. Please try again.';
					feedback.style.color   = '#d63638';
					feedback.style.display = 'block';
				}
			} )
			.finally( function () {
				btn.disabled    = false;
				btn.textContent = origLabel;
			} );
	}

	// Delegated listener: covers all current and future test-controls buttons
	// on the page regardless of render order.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-ccmerch-test-action]' );
		if ( btn && ! btn.disabled ) {
			ccmerchSendTestRequest( btn );
		}
	} );

	// -------------------------------------------------------------------------
	// Audio Upload — shared upload / delete handlers
	// -------------------------------------------------------------------------
	// Handles all interactions for elements rendered by Audio_Upload_Component.
	// All AJAX parameters live on the container's data attributes; no per-page
	// JavaScript is required for upload or delete interactions.

	/**
	 * Finds the closest audio upload container from a child element.
	 *
	 * @param {HTMLElement} el Any element inside a ccmerch-audio-upload block.
	 * @returns {HTMLElement|null}
	 */
	function audioContainer( el ) {
		return el.closest( '[data-ccmerch-audio-container]' );
	}

	/**
	 * Shows or hides the status message inside an audio upload container.
	 *
	 * @param {HTMLElement} container
	 * @param {string}      message   Empty string hides the status area.
	 * @param {boolean}     isError
	 */
	function audioStatus( container, message, isError ) {
		var el = container.querySelector( '[data-ccmerch-audio-status]' );
		if ( ! el ) { return; }
		el.textContent   = message;
		el.style.color   = isError ? '#d63638' : '#00a32a';
		el.style.display = message ? 'block' : 'none';
	}

	/**
	 * Sends an upload request for the given file and updates the container UI.
	 *
	 * Called after the user selects a file via the hidden <input type="file">.
	 * Saves the new attachment ID into the hidden form field immediately so the
	 * next "Save Settings" submission includes it — no second round-trip needed.
	 *
	 * @param {HTMLElement} container  Audio upload container element.
	 * @param {HTMLElement} uploadBtn  Upload / Replace button inside the container.
	 * @param {File}        file       File object from the change event.
	 */
	function ccmerchAudioUpload( container, uploadBtn, file ) {
		var ajaxUrl    = container.getAttribute( 'data-ccmerch-audio-ajax-url' );
		var action     = container.getAttribute( 'data-ccmerch-upload-action' );
		var authField  = container.getAttribute( 'data-ccmerch-audio-auth-field' );
		var authValue  = container.getAttribute( 'data-ccmerch-audio-auth-value' );
		var nonceField = container.getAttribute( 'data-ccmerch-audio-nonce-field' );
		var nonceValue = container.getAttribute( 'data-ccmerch-audio-nonce-value' );
		var attInput   = container.querySelector( '[data-ccmerch-audio-att-id]' );
		var deleteBtn  = container.querySelector( '[data-ccmerch-audio-delete]' );

		var origLabel         = uploadBtn.textContent;
		uploadBtn.disabled    = true;
		uploadBtn.textContent = 'Uploading…';
		audioStatus( container, '', false );

		var formData = new FormData();
		formData.append( 'action',             action );
		formData.append( authField,            authValue );
		if ( nonceField ) {
			formData.append( nonceField, nonceValue );
		}
		formData.append( 'old_attachment_id', attInput ? attInput.value : '0' );
		formData.append( 'ccmerch_audio_file', file );

		fetch( ajaxUrl, { method: 'POST', body: formData } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( ! ( json && json.success ) ) {
					audioStatus(
						container,
						( json && json.data && json.data.message ) ? json.data.message : 'Upload failed. Please try again.',
						true
					);
					return;
				}

				var d = json.data;

				// Update hidden attachment ID field — included in next form save.
				if ( attInput ) { attInput.value = String( d.attachment_id ); }

				// Update preview elements.
				var filenameEl = container.querySelector( '[data-ccmerch-audio-filename]' );
				var metaEl     = container.querySelector( '[data-ccmerch-audio-filemeta]' );
				var playerEl   = container.querySelector( '[data-ccmerch-audio-player]' );
				var sourceEl   = playerEl ? playerEl.querySelector( 'source' ) : null;

				if ( filenameEl ) { filenameEl.textContent = d.filename; }
				if ( metaEl )     { metaEl.textContent = d.filesize + ( d.duration_formatted ? ' · ' + d.duration_formatted : '' ); }
				if ( sourceEl ) {
					sourceEl.src  = d.url;
					sourceEl.type = d.mime_type;
					if ( playerEl ) { playerEl.load(); }
				}

				// Toggle visibility state.
				var noAudio  = container.querySelector( '[data-ccmerch-audio-no-audio]' );
				var hasAudio = container.querySelector( '[data-ccmerch-audio-has-audio]' );
				if ( noAudio )   { noAudio.style.display  = 'none'; }
				if ( hasAudio )  { hasAudio.style.display  = ''; }
				if ( deleteBtn ) { deleteBtn.style.display = ''; }

				// Switch button label to "Replace Audio".
				uploadBtn.textContent = uploadBtn.getAttribute( 'data-label-replace' ) || 'Replace Audio';
				audioStatus( container, 'Audio uploaded successfully.', false );
			} )
			.catch( function () {
				audioStatus( container, 'Request failed. Please try again.', true );
			} )
			.finally( function () {
				uploadBtn.disabled = false;
				// Reset so the same file can be re-selected after an error.
				var fileInput = container.querySelector( '[data-ccmerch-audio-file-input]' );
				if ( fileInput ) { fileInput.value = ''; }
			} );
	}

	/**
	 * Sends a delete request for the audio attachment in the given container.
	 *
	 * Deletes the physical file via wp_delete_attachment() on the server and
	 * clears the hidden form field so the next "Save Settings" saves 0.
	 *
	 * @param {HTMLElement} deleteBtn The Delete Audio button element.
	 */
	function ccmerchAudioDelete( deleteBtn ) {
		if ( ! window.confirm( 'Delete this audio file? This cannot be undone.' ) ) {
			return;
		}

		var container  = audioContainer( deleteBtn );
		if ( ! container ) { return; }

		var ajaxUrl    = container.getAttribute( 'data-ccmerch-audio-ajax-url' );
		var action     = container.getAttribute( 'data-ccmerch-delete-action' );
		var authField  = container.getAttribute( 'data-ccmerch-audio-auth-field' );
		var authValue  = container.getAttribute( 'data-ccmerch-audio-auth-value' );
		var nonceField = container.getAttribute( 'data-ccmerch-audio-nonce-field' );
		var nonceValue = container.getAttribute( 'data-ccmerch-audio-nonce-value' );
		var attInput   = container.querySelector( '[data-ccmerch-audio-att-id]' );
		var uploadBtn  = container.querySelector( '[data-ccmerch-audio-upload]' );

		var origLabel         = deleteBtn.textContent;
		deleteBtn.disabled    = true;
		deleteBtn.textContent = 'Deleting…';
		audioStatus( container, '', false );

		var formData = new FormData();
		formData.append( 'action',        action );
		formData.append( authField,       authValue );
		if ( nonceField ) {
			formData.append( nonceField, nonceValue );
		}
		formData.append( 'attachment_id', attInput ? attInput.value : '0' );

		fetch( ajaxUrl, { method: 'POST', body: formData } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( ! ( json && json.success ) ) {
					audioStatus(
						container,
						( json && json.data && json.data.message ) ? json.data.message : 'Delete failed. Please try again.',
						true
					);
					return;
				}

				// Clear attachment ID — next save stores 0.
				if ( attInput ) { attInput.value = '0'; }

				// Revert to the no-audio state.
				var noAudio  = container.querySelector( '[data-ccmerch-audio-no-audio]' );
				var hasAudio = container.querySelector( '[data-ccmerch-audio-has-audio]' );
				if ( noAudio )  { noAudio.style.display  = ''; }
				if ( hasAudio ) { hasAudio.style.display  = 'none'; }
				deleteBtn.style.display = 'none';

				// Reset upload button label.
				if ( uploadBtn ) {
					uploadBtn.textContent = uploadBtn.getAttribute( 'data-label-upload' ) || 'Upload Audio';
				}
			} )
			.catch( function () {
				audioStatus( container, 'Request failed. Please try again.', true );
			} )
			.finally( function () {
				deleteBtn.disabled    = false;
				deleteBtn.textContent = origLabel;
			} );
	}

	// Delegated listeners for all audio upload/delete controls on the page.
	document.addEventListener( 'click', function ( e ) {
		// Upload / Replace button → trigger the hidden file input.
		var uploadBtn = e.target.closest( '[data-ccmerch-audio-upload]' );
		if ( uploadBtn ) {
			var cont      = audioContainer( uploadBtn );
			var fileInput = cont ? cont.querySelector( '[data-ccmerch-audio-file-input]' ) : null;
			if ( fileInput ) { fileInput.click(); }
			return;
		}

		// Delete button.
		var deleteBtn = e.target.closest( '[data-ccmerch-audio-delete]' );
		if ( deleteBtn && ! deleteBtn.disabled ) {
			ccmerchAudioDelete( deleteBtn );
		}
	} );

	document.addEventListener( 'change', function ( e ) {
		var fileInput = e.target.closest( '[data-ccmerch-audio-file-input]' );
		if ( ! fileInput || ! fileInput.files || ! fileInput.files[0] ) { return; }
		var cont      = audioContainer( fileInput );
		var uploadBtn = cont ? cont.querySelector( '[data-ccmerch-audio-upload]' ) : null;
		if ( cont && uploadBtn ) {
			ccmerchAudioUpload( cont, uploadBtn, fileInput.files[0] );
		}
	} );

	// -------------------------------------------------------------------------
	// Creator Portal — settings save (AJAX, no page reload)
	// -------------------------------------------------------------------------
	// Intercepts submit on forms with data-ccmerch-portal-save="1", posts to
	// admin-ajax.php, and displays inline success/error feedback. The Creator
	// Portal settings form uses this so saves work without a WordPress login.
	//
	// After a successful save, a synthetic 'submit' event is dispatched on the
	// form so layout-editor.js's submit listener resets its dirty flag — preventing
	// the browser "unsaved changes" dialog from showing after a successful AJAX save.

	/**
	 * Sends the Creator Portal settings form via AJAX and shows inline feedback.
	 *
	 * @param {HTMLFormElement} form The settings form (data-ccmerch-portal-save="1").
	 */
	function ccmerchPortalSave( form ) {
		var feedback  = document.getElementById( 'ccmerch-portal-save-feedback' );
		var submitBtn = form.querySelector( '[type=submit]' );
		var origLabel = submitBtn ? submitBtn.textContent : '';

		if ( submitBtn ) {
			submitBtn.disabled    = true;
			submitBtn.textContent = 'Saving…';
		}
		if ( feedback ) { feedback.style.display = 'none'; }

		fetch( form.getAttribute( 'action' ), { method: 'POST', body: new FormData( form ) } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				var ok  = !! ( json && json.success );
				var msg = ( json && json.data && json.data.message )
					? json.data.message
					: ( ok ? 'Settings saved.' : 'Failed to save. Please try again.' );

				if ( feedback ) {
					feedback.textContent   = msg;
					feedback.style.color   = ok ? '#00a32a' : '#d63638';
					feedback.style.display = 'block';
				}

				if ( ok ) {
					// Notify layout-editor.js that the form has been saved so it can
					// reset its dirty flag and clear the beforeunload guard.
					form.dispatchEvent( new Event( 'submit', { bubbles: false, cancelable: false } ) );
				}
			} )
			.catch( function () {
				if ( feedback ) {
					feedback.textContent   = 'Request failed. Please try again.';
					feedback.style.color   = '#d63638';
					feedback.style.display = 'block';
				}
			} )
			.finally( function () {
				if ( submitBtn ) {
					submitBtn.disabled    = false;
					submitBtn.textContent = origLabel;
				}
			} );
	}

	// Delegated submit listener: handles all Creator Portal settings forms.
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest( '[data-ccmerch-portal-save]' );
		if ( ! form ) { return; }
		// Only intercept user-initiated submits (cancelable). The synthetic submit
		// dispatched above after save is non-cancelable and must pass through so
		// layout-editor.js receives it and resets the dirty flag.
		if ( ! e.cancelable ) { return; }
		e.preventDefault();
		ccmerchPortalSave( form );
	} );

	// -------------------------------------------------------------------------
	// Creator Portal — Generate Overlay ID (AJAX, no page reload for regenerate)
	// -------------------------------------------------------------------------
	// Intercepts submit on forms with data-ccmerch-generate-id="1", posts to
	// admin-ajax.php, and updates the Browser Source URL card in place.
	//
	// Regenerate case (overlay URL already displayed): updates existing elements.
	// First-time case (no URL yet): reloads the portal page so the full card
	// renders server-side — happens only once during initial setup.

	/**
	 * Builds the inner HTML for the #ccmerch-overlay-url-section when a URL
	 * is generated for the first time. Mirrors the PHP output from
	 * Overlay_Config_Renderer::render_browser_source_card().
	 *
	 * @param  {string} url The new overlay browser-source URL.
	 * @return {string} HTML string (attribute-safe escaping only; text set via textContent below).
	 */
	function ccmerchBuildOverlayUrlHtml( url ) {
		var safe = url
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
		return '<div class="ccmerch-overlay-url">'
			+ '<input type="text" id="ccmerch-overlay-url-input" class="large-text" value="' + safe + '" readonly>'
			+ '<div class="ccmerch-overlay-url__actions">'
			+ '<button type="button" id="ccmerch-overlay-copy-btn" class="button"'
			+   ' data-url="' + safe + '"'
			+   ' data-copied="✓ Copied!"'
			+   ' data-copy-fail="Unable to copy automatically."'
			+   ' onclick="ccmerchCopyToClipboard(this,this.dataset.url)">Copy URL</button>'
			+ '<a id="ccmerch-overlay-open-link" href="' + safe + '" target="_blank" rel="noopener" class="button">Open in Browser</a>'
			+ '</div>'
			+ '</div>';
	}

	/**
	 * Handles the Generate Overlay ID AJAX form submission.
	 *
	 * @param {HTMLFormElement} form The generate-id form (data-ccmerch-generate-id="1").
	 */
	function ccmerchHandleGenerateOverlayId( form ) {
		var feedback  = document.getElementById( 'ccmerch-generate-id-feedback' );
		var submitBtn = form.querySelector( '[type=submit]' );
		var origLabel = submitBtn ? submitBtn.textContent : '';

		if ( submitBtn ) {
			submitBtn.disabled    = true;
			submitBtn.textContent = 'Generating…';
		}
		if ( feedback ) { feedback.style.display = 'none'; }

		fetch( form.getAttribute( 'action' ), { method: 'POST', body: new FormData( form ) } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( ! ( json && json.success ) ) {
					var errMsg = ( json && json.data && json.data.message ) ? json.data.message : 'Failed to generate ID. Please try again.';
					if ( feedback ) {
						feedback.textContent   = errMsg;
						feedback.style.color   = '#d63638';
						feedback.style.display = 'block';
					}
					return;
				}

				var newUrl     = json.data.overlay_url || '';
				var msg        = json.data.message     || 'New overlay ID generated. Update your OBS browser source.';
				var urlInput   = document.getElementById( 'ccmerch-overlay-url-input' );
				var copyBtn    = document.getElementById( 'ccmerch-overlay-copy-btn' );
				var openLink   = document.getElementById( 'ccmerch-overlay-open-link' );
				var urlSection = document.getElementById( 'ccmerch-overlay-url-section' );

				if ( urlInput && newUrl ) {
					// Regenerate: update the existing URL display in place.
					urlInput.value = newUrl;
					if ( copyBtn ) {
						copyBtn.setAttribute( 'data-url', newUrl );
					}
					if ( openLink ) {
						openLink.href = newUrl;
					}
				} else if ( urlSection && newUrl ) {
					// First-time: the URL section currently shows only the description
					// paragraph. Rebuild it with the full URL display. The submit button
					// style switches from primary to secondary to match the "has ID" state.
					urlSection.innerHTML = ccmerchBuildOverlayUrlHtml( newUrl );
					if ( submitBtn ) {
						submitBtn.className = submitBtn.className.replace( 'button-primary', 'button-secondary' );
					}
				}

				if ( feedback ) {
					feedback.textContent   = msg;
					feedback.style.color   = '#00a32a';
					feedback.style.display = 'block';
				}
			} )
			.catch( function () {
				if ( feedback ) {
					feedback.textContent   = 'Request failed. Please try again.';
					feedback.style.color   = '#d63638';
					feedback.style.display = 'block';
				}
			} )
			.finally( function () {
				if ( submitBtn ) {
					submitBtn.disabled = false;
					if ( submitBtn.textContent === 'Generating…' ) {
						submitBtn.textContent = origLabel;
					}
				}
			} );
	}

	// Delegated submit listener for Generate Overlay ID forms.
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest( '[data-ccmerch-generate-id]' );
		if ( ! form ) { return; }
		e.preventDefault();
		ccmerchHandleGenerateOverlayId( form );
	} );

	// -------------------------------------------------------------------------
	// Changelog Modal — open / close + API fetch
	// -------------------------------------------------------------------------
	// Handles the Changelog_Modal_Component trigger button and dialog.
	// On first open, fetches releases from the REST API and renders them.
	// No per-page JavaScript is required — all interactions are delegated here.

	var _changelogFetched = false;

	function ccmerchOpenChangelog() {
		var modal = document.getElementById( 'ccmerch-changelog-modal' );
		if ( ! modal ) { return; }
		modal.setAttribute( 'aria-hidden', 'false' );
		modal.classList.add( 'ccmerch-modal--open' );
		if ( ! _changelogFetched ) {
			_changelogFetched = true;
			ccmerchFetchChangelog();
		}
	}

	function ccmerchCloseChangelog() {
		var modal = document.getElementById( 'ccmerch-changelog-modal' );
		if ( ! modal ) { return; }
		modal.setAttribute( 'aria-hidden', 'true' );
		modal.classList.remove( 'ccmerch-modal--open' );
	}

	function ccmerchFetchChangelog() {
		var apiBase = ( typeof window.ccmerchApiBase !== 'undefined' ) ? window.ccmerchApiBase : '';
		if ( ! apiBase ) { return; }
		var body = document.getElementById( 'ccmerch-changelog-body' );
		fetch( apiBase + '/releases', { method: 'GET', headers: { 'Accept': 'application/json' } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( releases ) { ccmerchRenderChangelog( body, releases ); } )
			.catch( function () {
				if ( body ) {
					var p = document.createElement( 'p' );
					p.className = 'ccmerch-changelog-error';
					p.textContent = 'Failed to load changelog. Please try again later.';
					body.innerHTML = '';
					body.appendChild( p );
				}
			} );
	}

	function ccmerchRenderChangelog( body, releases ) {
		if ( ! body ) { return; }
		body.innerHTML = '';
		if ( ! releases || ! releases.length ) {
			var empty = document.createElement( 'p' );
			empty.textContent = 'No releases found.';
			body.appendChild( empty );
			return;
		}
		releases.forEach( function ( r ) {
			var entry = document.createElement( 'div' );
			entry.className = 'ccmerch-modal__entry';

			var version = document.createElement( 'h3' );
			version.className = 'ccmerch-modal__version';
			version.textContent = r.version + ' (' + ( r.stage_label || r.stage ) + ')';
			entry.appendChild( version );

			if ( r.title ) {
				var title = document.createElement( 'p' );
				title.className = 'ccmerch-modal__release-title';
				title.textContent = r.title;
				entry.appendChild( title );
			}

			if ( r.description ) {
				var desc = document.createElement( 'p' );
				desc.className = 'ccmerch-modal__release-desc';
				desc.textContent = r.description;
				entry.appendChild( desc );
			}

			if ( r.feature_labels && r.feature_labels.length ) {
				var ul = document.createElement( 'ul' );
				ul.className = 'ccmerch-modal__notes';
				r.feature_labels.forEach( function ( label ) {
					var li = document.createElement( 'li' );
					li.textContent = label;
					ul.appendChild( li );
				} );
				entry.appendChild( ul );
			}

			body.appendChild( entry );
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		// Close: X button or backdrop click.
		if ( e.target.closest( '[data-ccmerch-changelog-close]' ) ) {
			ccmerchCloseChangelog();
			return;
		}
		// Open: Changelog trigger button.
		if ( e.target.closest( '[data-ccmerch-changelog="1"]' ) ) {
			ccmerchOpenChangelog();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			ccmerchCloseChangelog();
		}
	} );

	// -------------------------------------------------------------------------
	// Overlay Designer — preview data refresh
	// -------------------------------------------------------------------------
	// Sends an AJAX request to the creator preview-data endpoint and, on success,
	// dispatches a `ccmerch:previewData` custom event so layout-editor.js can
	// update its internal preview variable and re-render the canvas immediately.
	//
	// Triggered by buttons with data-ccmerch-refresh-preview="1". All AJAX
	// parameters are read from data attributes on the button element.

	/**
	 * Refreshes the Overlay Designer canvas preview with new creator-scoped data.
	 *
	 * @param {HTMLButtonElement} btn The Refresh Preview button.
	 */
	function ccmerchRefreshPreview( btn ) {
		var action    = btn.getAttribute( 'data-ccmerch-refresh-action' )    || '';
		var authField = btn.getAttribute( 'data-ccmerch-refresh-auth-field' ) || '';
		var authValue = btn.getAttribute( 'data-ccmerch-refresh-auth-value' ) || '';
		var ajaxUrl   = btn.getAttribute( 'data-ccmerch-refresh-ajax-url' )   || '';

		if ( ! action || ! ajaxUrl ) { return; }

		var origText     = btn.textContent;
		btn.disabled     = true;
		btn.textContent  = 'Refreshing…';

		var body = new FormData();
		body.append( 'action', action );
		if ( authField && authValue ) {
			body.append( authField, authValue );
		}

		fetch( ajaxUrl, { method: 'POST', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json && json.success && json.data ) {
					document.dispatchEvent( new CustomEvent( 'ccmerch:previewData', { detail: json.data } ) );
				}
			} )
			.catch( function () { /* silent — canvas keeps previous preview */ } )
			.finally( function () {
				btn.disabled    = false;
				btn.textContent = origText;
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-ccmerch-refresh-preview]' );
		if ( btn ) {
			ccmerchRefreshPreview( btn );
		}
	} );

	// ─────────────────────────────────────────────────────────────────────────
	// Portal Tab Switching
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Switches the active Creator Portal tab and lazy-loads Alert History on first
	 * activation of the history tab.
	 *
	 * Tab state is purely CSS-driven: active tab gets ccmerch-portal__tab--active;
	 * inactive sections get ccmerch-portal__section--hidden.
	 *
	 * @param {string} tabId  The data-ccmerch-portal-tab value of the target tab.
	 */
	function ccmerchSwitchPortalTab( tabId ) {
		// Toggle tab button states.
		var tabs = document.querySelectorAll( '[data-ccmerch-portal-tab]' );
		tabs.forEach( function ( t ) {
			var isActive = t.getAttribute( 'data-ccmerch-portal-tab' ) === tabId;
			t.classList.toggle( 'ccmerch-portal__tab--active', isActive );
			t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
		} );

		// Toggle section visibility.
		var sections = document.querySelectorAll( '.ccmerch-portal__section' );
		sections.forEach( function ( s ) {
			s.classList.toggle( 'ccmerch-portal__section--hidden', s.id !== 'ccmerch-portal-section-' + tabId );
		} );

		// Lazy-load history on first activation.
		if ( tabId === 'history' ) {
			var container = document.getElementById( 'ccmerch-history-container' );
			if ( container && ! container.getAttribute( 'data-history-loaded' ) ) {
				container.setAttribute( 'data-history-loaded', '1' );
				ccmerchLoadHistory( 1 );
			}
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var tab = e.target.closest( '[data-ccmerch-portal-tab]' );
		if ( tab ) {
			ccmerchSwitchPortalTab( tab.getAttribute( 'data-ccmerch-portal-tab' ) );
		}
	} );

	// ─────────────────────────────────────────────────────────────────────────
	// Alert History — privacy (anonymize) toggle
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Re-renders every customer name element in the DOM using the current
	 * CustomerNameFormatter mode. Called when the anonymize toggle is changed.
	 *
	 * Elements must carry a data-ccmerch-customer-name attribute holding the raw
	 * value; textContent is the display-safe output that gets replaced here.
	 */
	function ccmerchApplyAnonymization() {
		var nameEls = document.querySelectorAll( '[data-ccmerch-customer-name]' );
		nameEls.forEach( function ( el ) {
			el.textContent = CustomerNameFormatter.format(
				el.getAttribute( 'data-ccmerch-customer-name' )
			);
		} );
	}

	// Delegated change listener: handles the anonymize toggle checkbox.
	document.addEventListener( 'change', function ( e ) {
		var cb = e.target.closest( '[data-ccmerch-anonymize-toggle]' );
		if ( cb ) {
			CustomerNameFormatter.setEnabled( cb.checked );
			ccmerchApplyAnonymization();
		}
	} );

	// ─────────────────────────────────────────────────────────────────────────
	// Alert History
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Fetches and renders a page of order groups for the creator's alert history.
	 *
	 * Reads all auth parameters from data attributes on #ccmerch-history-container
	 * so no per-page JS is required.
	 *
	 * @param {number} page 1-indexed page number.
	 */
	function ccmerchLoadHistory( page ) {
		var container = document.getElementById( 'ccmerch-history-container' );
		if ( ! container ) { return; }

		var action   = container.getAttribute( 'data-ccmerch-history-action' )     || '';
		var authField = container.getAttribute( 'data-ccmerch-history-auth-field' ) || '';
		var authValue = container.getAttribute( 'data-ccmerch-history-auth-value' ) || '';
		var ajaxUrl  = container.getAttribute( 'data-ccmerch-history-ajax-url' )    || '';

		if ( ! action || ! ajaxUrl ) { return; }

		container.innerHTML = '<div class="ccmerch-history__loading">Loading…</div>';

		var body = new FormData();
		body.append( 'action', action );
		body.append( 'page',   page );
		if ( authField && authValue ) {
			body.append( authField, authValue );
		}

		fetch( ajaxUrl, { method: 'POST', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json && json.success && json.data ) {
					ccmerchRenderHistoryPage( container, json.data );
				} else {
					container.innerHTML = '<p class="ccmerch-history__error">Failed to load alert history.</p>';
				}
			} )
			.catch( function () {
				container.innerHTML = '<p class="ccmerch-history__error">Network error loading alert history.</p>';
			} );
	}

	/**
	 * Renders a full page of history data into the container.
	 *
	 * @param {HTMLElement} container  The #ccmerch-history-container element.
	 * @param {Object}      data       Response data from handle_history().
	 */
	function ccmerchRenderHistoryPage( container, data ) {
		container.innerHTML = '';

		if ( ! data.groups || data.groups.length === 0 ) {
			var emptyEl = document.createElement( 'p' );
			emptyEl.className = 'ccmerch-history__empty';
			emptyEl.textContent = 'No alerts have been sent yet.';
			container.appendChild( emptyEl );
			return;
		}

		var table = document.createElement( 'div' );
		table.className = 'ccmerch-history__table';

		var header = document.createElement( 'div' );
		header.className = 'ccmerch-history__table-header';
		var _s = window.ccmerchStrings || {};
		header.innerHTML = [
			'<span>' + ( _s['history.col.order']    || 'Order'    ) + '</span>',
			'<span>' + ( _s['history.col.customer'] || 'Customer' ) + '</span>',
			'<span>' + ( _s['history.col.alerts']   || 'Alerts'   ) + '</span>',
			'<span>' + ( _s['history.col.date']     || 'Date'     ) + '</span>',
			'<span>' + ( _s['history.col.actions']  || 'Actions'  ) + '</span>',
		].join( '' );
		table.appendChild( header );

		data.groups.forEach( function ( group ) {
			table.appendChild( ccmerchBuildHistoryGroup( container, group ) );
		} );

		container.appendChild( table );
		container.appendChild( ccmerchBuildHistoryPagination( container, data ) );
	}

	/**
	 * Builds one order group row with its collapsed detail panel.
	 *
	 * @param  {HTMLElement} container Parent container (holds auth data attrs).
	 * @param  {Object}      group     Order group object from handle_history().
	 * @returns {HTMLElement}
	 */
	function ccmerchBuildHistoryGroup( container, group ) {
		var replayOrderAction = container.getAttribute( 'data-ccmerch-replay-order-action' ) || '';
		var authField         = container.getAttribute( 'data-ccmerch-history-auth-field' )  || '';
		var authValue         = container.getAttribute( 'data-ccmerch-history-auth-value' )  || '';
		var ajaxUrl           = container.getAttribute( 'data-ccmerch-history-ajax-url' )    || '';

		var groupEl = document.createElement( 'div' );
		groupEl.className = 'ccmerch-history__group';
		groupEl.setAttribute( 'data-order-id', group.order_id );

		// ── Row ──────────────────────────────────────────────────────────────
		var row = document.createElement( 'div' );
		row.className = 'ccmerch-history__group-row';

		var orderNum = document.createElement( 'span' );
		orderNum.className   = 'ccmerch-history__col-order';
		orderNum.textContent = '#' + group.order_number;
		row.appendChild( orderNum );

		var nameEl = document.createElement( 'span' );
		nameEl.className = 'ccmerch-history__col-name';

		// First name: subject to anonymization — stored in data attribute so
		// ccmerchApplyAnonymization() can re-render it without touching the username.
		var firstNameSpan = document.createElement( 'span' );
		firstNameSpan.setAttribute( 'data-ccmerch-customer-name', group.customer_first_name || '' );
		firstNameSpan.textContent = CustomerNameFormatter.format( group.customer_first_name || '' );
		nameEl.appendChild( firstNameSpan );

		// Username: NEVER anonymized — sibling span has no data-ccmerch-customer-name.
		var usernameSpan = document.createElement( 'span' );
		usernameSpan.className = 'ccmerch-history__username';
		usernameSpan.textContent = ' (' + ( group.customer_username || 'Anonymous' ) + ')';
		nameEl.appendChild( usernameSpan );

		row.appendChild( nameEl );

		var countEl = document.createElement( 'span' );
		countEl.className   = 'ccmerch-history__col-count';
		var _s2 = window.ccmerchStrings || {};
		countEl.textContent = group.alert_count + ' ' + ( group.alert_count === 1
			? ( _s2['history.row.alert_singular'] || 'alert' )
			: ( _s2['history.row.alert_plural']   || 'alerts' ) );
		row.appendChild( countEl );

		var dateEl = document.createElement( 'span' );
		dateEl.className   = 'ccmerch-history__col-date';
		dateEl.textContent = group.created_at_formatted;
		row.appendChild( dateEl );

		var actionsEl = document.createElement( 'span' );
		actionsEl.className = 'ccmerch-history__col-actions';

		if ( replayOrderAction ) {
			var replayOrderBtn = document.createElement( 'button' );
			replayOrderBtn.type          = 'button';
			replayOrderBtn.className     = 'button button-secondary ccmerch-history__replay-btn';
			replayOrderBtn.textContent   = ( window.ccmerchStrings && window.ccmerchStrings['history.row.replay_order'] ) || 'Replay Order';
			replayOrderBtn.setAttribute( 'data-ccmerch-replay-action',     replayOrderAction );
			replayOrderBtn.setAttribute( 'data-ccmerch-replay-auth-field', authField );
			replayOrderBtn.setAttribute( 'data-ccmerch-replay-auth-value', authValue );
			replayOrderBtn.setAttribute( 'data-ccmerch-replay-ajax-url',   ajaxUrl );
			replayOrderBtn.setAttribute( 'data-ccmerch-replay-id',         group.order_id );
			replayOrderBtn.setAttribute( 'data-ccmerch-replay-type',       'order' );
			actionsEl.appendChild( replayOrderBtn );
		}

		var expandBtn = document.createElement( 'button' );
		expandBtn.type      = 'button';
		expandBtn.className = 'button ccmerch-history__expand-btn';
		expandBtn.textContent  = ( window.ccmerchStrings && window.ccmerchStrings['history.row.details'] ) || 'Details';
		expandBtn.setAttribute( 'aria-expanded', 'false' );
		expandBtn.addEventListener( 'click', function () {
			ccmerchToggleHistoryGroup( container, groupEl );
		} );
		actionsEl.appendChild( expandBtn );

		row.appendChild( actionsEl );
		groupEl.appendChild( row );

		// ── Replay feedback ───────────────────────────────────────────────────
		var feedbackEl = document.createElement( 'div' );
		feedbackEl.className = 'ccmerch-history__replay-feedback';
		feedbackEl.setAttribute( 'role', 'status' );
		groupEl.appendChild( feedbackEl );

		// ── Detail panel (hidden until expanded) ─────────────────────────────
		var detailEl = document.createElement( 'div' );
		detailEl.className = 'ccmerch-history__detail';
		detailEl.innerHTML = '<div class="ccmerch-history__loading">Loading…</div>';
		groupEl.appendChild( detailEl );

		return groupEl;
	}

	/**
	 * Expands or collapses a history group row.
	 *
	 * On first expand: fetches per-order event detail and caches it in the DOM
	 * (data-loaded="1" on the detail element prevents duplicate fetches).
	 *
	 * @param {HTMLElement} container  The #ccmerch-history-container element.
	 * @param {HTMLElement} groupEl    The .ccmerch-history__group element to toggle.
	 */
	function ccmerchToggleHistoryGroup( container, groupEl ) {
		var isOpen = groupEl.classList.toggle( 'ccmerch-history__group--open' );

		var expandBtn = groupEl.querySelector( '.ccmerch-history__expand-btn' );
		if ( expandBtn ) {
			expandBtn.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
			var _st = window.ccmerchStrings || {};
			expandBtn.textContent = isOpen
				? ( _st['history.row.hide']    || 'Hide'    )
				: ( _st['history.row.details'] || 'Details' );
		}

		if ( ! isOpen ) { return; }

		var detailEl = groupEl.querySelector( '.ccmerch-history__detail' );
		if ( ! detailEl || detailEl.getAttribute( 'data-loaded' ) ) { return; }

		var orderId   = groupEl.getAttribute( 'data-order-id' );
		var orderAction = container.getAttribute( 'data-ccmerch-history-order-action' ) || '';
		var authField   = container.getAttribute( 'data-ccmerch-history-auth-field' )   || '';
		var authValue   = container.getAttribute( 'data-ccmerch-history-auth-value' )   || '';
		var ajaxUrl     = container.getAttribute( 'data-ccmerch-history-ajax-url' )     || '';

		var body = new FormData();
		body.append( 'action',   orderAction );
		body.append( 'order_id', orderId );
		if ( authField && authValue ) {
			body.append( authField, authValue );
		}

		fetch( ajaxUrl, { method: 'POST', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json && json.success && json.data ) {
					ccmerchRenderHistoryEvents( container, detailEl, json.data.events );
					detailEl.setAttribute( 'data-loaded', '1' );
				} else {
					detailEl.innerHTML = '<p class="ccmerch-history__error">Failed to load alert details.</p>';
				}
			} )
			.catch( function () {
				detailEl.innerHTML = '<p class="ccmerch-history__error">Network error.</p>';
			} );
	}

	/**
	 * Renders per-alert event items inside an expanded order row's detail panel.
	 *
	 * image_html is server-rendered by wp_get_attachment_image() and set via
	 * innerHTML (trusted server output). All other fields use textContent.
	 *
	 * @param {HTMLElement}  container  The #ccmerch-history-container element.
	 * @param {HTMLElement}  detailEl   The .ccmerch-history__detail element.
	 * @param {Array<Object>} events    Event objects from handle_history_order().
	 */
	function ccmerchRenderHistoryEvents( container, detailEl, events ) {
		detailEl.innerHTML = '';

		if ( ! events || events.length === 0 ) {
			var emptyEl = document.createElement( 'p' );
			emptyEl.className   = 'ccmerch-history__empty';
			emptyEl.textContent = ( window.ccmerchStrings && window.ccmerchStrings['history.empty'] ) || 'No individual alerts found.';
			detailEl.appendChild( emptyEl );
			return;
		}

		var replayEventAction = container.getAttribute( 'data-ccmerch-replay-event-action' ) || '';
		var authField         = container.getAttribute( 'data-ccmerch-history-auth-field' )  || '';
		var authValue         = container.getAttribute( 'data-ccmerch-history-auth-value' )  || '';
		var ajaxUrl           = container.getAttribute( 'data-ccmerch-history-ajax-url' )    || '';

		events.forEach( function ( ev ) {
			var itemEl = document.createElement( 'div' );
			itemEl.className = 'ccmerch-history__alert-item';

			// Image (server-generated HTML from wp_get_attachment_image).
			var imgWrap = document.createElement( 'div' );
			imgWrap.className = 'ccmerch-history__alert-img';
			imgWrap.innerHTML = ev.image_html || '';
			itemEl.appendChild( imgWrap );

			// Details block.
			var infoEl = document.createElement( 'div' );
			infoEl.className = 'ccmerch-history__alert-info';

			var nameEl = document.createElement( 'strong' );
			nameEl.textContent = ev.product_name;
			infoEl.appendChild( nameEl );

			if ( ev.variation_id ) {
				var varEl = document.createElement( 'span' );
				varEl.className   = 'ccmerch-history__alert-meta';
				varEl.textContent = ' (Variation #' + ev.variation_id + ')';
				infoEl.appendChild( varEl );
			}

			var metaEl = document.createElement( 'div' );
			metaEl.className   = 'ccmerch-history__alert-meta';
			metaEl.textContent = 'Qty: ' + ev.quantity +
				( ev.category_names ? ' · ' + ev.category_names : '' ) +
				' · ' + ev.created_at_formatted;
			infoEl.appendChild( metaEl );

			itemEl.appendChild( infoEl );

			// Per-alert replay button.
			if ( replayEventAction ) {
				var replayBtn = document.createElement( 'button' );
				replayBtn.type        = 'button';
				replayBtn.className   = 'button button-secondary ccmerch-history__replay-btn ccmerch-history__replay-btn--event';
				replayBtn.textContent = ( window.ccmerchStrings && window.ccmerchStrings['history.event.replay'] ) || 'Replay';
				replayBtn.setAttribute( 'data-ccmerch-replay-action',     replayEventAction );
				replayBtn.setAttribute( 'data-ccmerch-replay-auth-field', authField );
				replayBtn.setAttribute( 'data-ccmerch-replay-auth-value', authValue );
				replayBtn.setAttribute( 'data-ccmerch-replay-ajax-url',   ajaxUrl );
				replayBtn.setAttribute( 'data-ccmerch-replay-id',         ev.id );
				replayBtn.setAttribute( 'data-ccmerch-replay-type',       'event' );
				itemEl.appendChild( replayBtn );
			}

			detailEl.appendChild( itemEl );
		} );
	}

	/**
	 * Builds the pagination control for the history table.
	 *
	 * @param  {HTMLElement} container  #ccmerch-history-container (holds auth attrs).
	 * @param  {Object}      data       Response data from handle_history().
	 * @returns {HTMLElement}
	 */
	function ccmerchBuildHistoryPagination( container, data ) {
		var nav = document.createElement( 'div' );
		nav.className = 'ccmerch-history__pagination';

		if ( data.total_pages <= 1 ) { return nav; }

		var prevBtn = document.createElement( 'button' );
		prevBtn.type      = 'button';
		prevBtn.className = 'button ccmerch-history__page-btn';
		prevBtn.textContent = '‹ Prev';
		prevBtn.disabled    = data.page <= 1;
		prevBtn.addEventListener( 'click', function () {
			ccmerchLoadHistory( data.page - 1 );
		} );
		nav.appendChild( prevBtn );

		var pageInfo = document.createElement( 'span' );
		pageInfo.className   = 'ccmerch-history__page-info';
		pageInfo.textContent = 'Page ' + data.page + ' of ' + data.total_pages;
		nav.appendChild( pageInfo );

		var nextBtn = document.createElement( 'button' );
		nextBtn.type      = 'button';
		nextBtn.className = 'button ccmerch-history__page-btn';
		nextBtn.textContent = 'Next ›';
		nextBtn.disabled    = data.page >= data.total_pages;
		nextBtn.addEventListener( 'click', function () {
			ccmerchLoadHistory( data.page + 1 );
		} );
		nav.appendChild( nextBtn );

		return nav;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Replay button handler
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Sends a replay request for an individual alert event or a full order.
	 *
	 * Reads all parameters from data attributes on the button:
	 *   data-ccmerch-replay-action     AJAX action slug
	 *   data-ccmerch-replay-auth-field POST field name for auth credential
	 *   data-ccmerch-replay-auth-value Auth credential value
	 *   data-ccmerch-replay-ajax-url   admin-ajax.php URL
	 *   data-ccmerch-replay-id         event_id (event replay) or order_id (order replay)
	 *   data-ccmerch-replay-type       'event' or 'order'
	 *
	 * Feedback is shown in the nearest .ccmerch-history__replay-feedback element.
	 *
	 * @param {HTMLButtonElement} btn The clicked replay button.
	 */
	function ccmerchSendReplayRequest( btn ) {
		var action    = btn.getAttribute( 'data-ccmerch-replay-action' )     || '';
		var authField = btn.getAttribute( 'data-ccmerch-replay-auth-field' ) || '';
		var authValue = btn.getAttribute( 'data-ccmerch-replay-auth-value' ) || '';
		var ajaxUrl   = btn.getAttribute( 'data-ccmerch-replay-ajax-url' )   || '';
		var replayId  = btn.getAttribute( 'data-ccmerch-replay-id' )         || '';
		var replayType = btn.getAttribute( 'data-ccmerch-replay-type' )      || 'event';

		if ( ! action || ! ajaxUrl || ! replayId ) { return; }

		// Locate feedback element: nearest parent group → feedback div.
		var groupEl   = btn.closest( '.ccmerch-history__group' );
		var feedbackEl = groupEl ? groupEl.querySelector( '.ccmerch-history__replay-feedback' ) : null;

		var origText  = btn.textContent;
		btn.disabled  = true;
		btn.textContent = 'Sending…';

		var body = new FormData();
		body.append( 'action', action );
		if ( authField && authValue ) {
			body.append( authField, authValue );
		}
		if ( replayType === 'event' ) {
			body.append( 'event_id', replayId );
		} else {
			body.append( 'order_id', replayId );
		}

		fetch( ajaxUrl, { method: 'POST', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( feedbackEl ) {
					feedbackEl.style.display = 'block';
					feedbackEl.style.color   = json && json.success ? '#00a32a' : '#d63638';
					feedbackEl.textContent   = ( json && json.data && json.data.message )
						? json.data.message
						: ( json && json.success ? 'Replay queued.' : 'Replay failed.' );
					setTimeout( function () {
						feedbackEl.style.display = 'none';
						feedbackEl.textContent   = '';
					}, 3000 );
				}
			} )
			.catch( function () {
				if ( feedbackEl ) {
					feedbackEl.style.display = 'block';
					feedbackEl.style.color   = '#d63638';
					feedbackEl.textContent   = 'Request failed. Please try again.';
					setTimeout( function () {
						feedbackEl.style.display = 'none';
					}, 3000 );
				}
			} )
			.finally( function () {
				btn.disabled    = false;
				btn.textContent = origText;
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-ccmerch-replay-action]' );
		if ( btn ) {
			ccmerchSendReplayRequest( btn );
		}
	} );

	// ─────────────────────────────────────────────────────────────────────────
	// Initialise persistent UI state from localStorage
	// ─────────────────────────────────────────────────────────────────────────

	// Restore the anonymize-toggle checkbox from the persisted preference so
	// the creator's choice survives page reloads. The script is loaded at the
	// bottom of <body>, so the DOM element is guaranteed to be present.
	var _anonCb = document.getElementById( 'ccmerch-anonymize-toggle' );
	if ( _anonCb ) {
		_anonCb.checked = CustomerNameFormatter.isEnabled();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Translation Manager admin UI
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Saves a single translation string override via AJAX.
	 *
	 * Reads the key from data-trans-key on the button, reads the value from the
	 * sibling input with a matching data-trans-input attribute, and POSTs to the
	 * shared Translation Manager AJAX endpoint.
	 *
	 * @param {HTMLButtonElement} btn The save button.
	 */
	function ccmerchSaveTranslation( btn ) {
		var cfg   = window.ccmerchTranslations || {};
		var table = btn.closest( '#ccmerch-trans-table' );
		if ( ! table ) { return; }

		var key      = btn.getAttribute( 'data-trans-key' ) || '';
		var inputEl  = table.querySelector( '[data-trans-input="' + key + '"]' );
		var feedback = btn.closest( 'tr' ) ? btn.closest( 'tr' ).querySelector( '.ccmerch-trans-feedback' ) : null;

		if ( ! key || ! inputEl ) { return; }

		var value     = inputEl.value;
		var origText  = btn.textContent;
		btn.disabled  = true;
		btn.textContent = '…';

		var body = new FormData();
		body.append( 'action',      cfg.saveAction  || '' );
		body.append( '_ajax_nonce', cfg.nonce        || '' );
		body.append( 'trans_key',   key );
		body.append( 'trans_value', value );
		body.append( 'lang',        'en' );

		fetch( cfg.ajaxUrl || '', { method: 'POST', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( feedback ) {
					feedback.textContent = json && json.success ? '✓ Saved' : '✗ Failed';
					feedback.style.color = json && json.success ? '#00a32a' : '#d63638';
					setTimeout( function () { feedback.textContent = ''; }, 2500 );
				}
				if ( json && json.success && json.data ) {
					var row = btn.closest( 'tr' );
					if ( row ) {
						if ( json.data.is_overridden ) {
							row.classList.add( 'ccmerch-trans-row--overridden' );
							if ( ! row.querySelector( '[data-ccmerch-translation-reset]' ) ) {
								var resetBtn = document.createElement( 'button' );
								resetBtn.type      = 'button';
								resetBtn.className = 'button button-secondary button-small';
								resetBtn.setAttribute( 'data-ccmerch-translation-reset', '1' );
								resetBtn.setAttribute( 'data-trans-key', key );
								resetBtn.textContent = 'Reset';
								btn.parentNode.appendChild( resetBtn );
							}
						} else {
							row.classList.remove( 'ccmerch-trans-row--overridden' );
						}
					}
				}
			} )
			.catch( function () {
				if ( feedback ) {
					feedback.textContent = '✗ Error';
					feedback.style.color = '#d63638';
					setTimeout( function () { feedback.textContent = ''; }, 2500 );
				}
			} )
			.finally( function () {
				btn.disabled    = false;
				btn.textContent = origText;
			} );
	}

	/**
	 * Resets a single translation string to its registered default via AJAX.
	 *
	 * @param {HTMLButtonElement} btn The reset button.
	 */
	function ccmerchResetTranslation( btn ) {
		var cfg   = window.ccmerchTranslations || {};
		var table = btn.closest( '#ccmerch-trans-table' );
		if ( ! table ) { return; }

		var key      = btn.getAttribute( 'data-trans-key' ) || '';
		var inputEl  = table.querySelector( '[data-trans-input="' + key + '"]' );
		var feedback = btn.closest( 'tr' ) ? btn.closest( 'tr' ).querySelector( '.ccmerch-trans-feedback' ) : null;

		if ( ! key ) { return; }

		var origText  = btn.textContent;
		btn.disabled  = true;
		btn.textContent = '…';

		var body = new FormData();
		body.append( 'action',      cfg.resetAction || '' );
		body.append( '_ajax_nonce', cfg.nonce        || '' );
		body.append( 'trans_key',   key );
		body.append( 'lang',        'en' );

		fetch( cfg.ajaxUrl || '', { method: 'POST', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json && json.success && json.data ) {
					if ( inputEl ) {
						inputEl.value = '';
					}
					var row = btn.closest( 'tr' );
					if ( row ) {
						row.classList.remove( 'ccmerch-trans-row--overridden' );
					}
					btn.remove();
					if ( feedback ) {
						feedback.textContent = '✓ Reset';
						feedback.style.color = '#00a32a';
						setTimeout( function () { feedback.textContent = ''; }, 2500 );
					}
				}
			} )
			.catch( function () {
				if ( feedback ) {
					feedback.textContent = '✗ Error';
					feedback.style.color = '#d63638';
					setTimeout( function () { feedback.textContent = ''; }, 2500 );
				}
			} )
			.finally( function () {
				if ( btn.parentNode ) {
					btn.disabled    = false;
					btn.textContent = origText;
				}
			} );
	}

	/**
	 * Filters the translation table rows client-side.
	 *
	 * Reads the search value from #ccmerch-trans-search and the context from
	 * #ccmerch-trans-context. Hides rows that do not match both filters.
	 * Shows the no-results message when no rows are visible.
	 */
	function ccmerchFilterTranslations() {
		var search  = ( document.getElementById( 'ccmerch-trans-search' )  || {} ).value  || '';
		var context = ( document.getElementById( 'ccmerch-trans-context' ) || {} ).value || '';
		var table   = document.getElementById( 'ccmerch-trans-table' );
		var noRes   = document.getElementById( 'ccmerch-trans-no-results' );

		if ( ! table ) { return; }

		var rows    = table.querySelectorAll( 'tbody tr.ccmerch-trans-row' );
		var visible = 0;
		var lc      = search.toLowerCase();

		rows.forEach( function ( row ) {
			var keyMatch     = ! lc     || row.getAttribute( 'data-trans-key' )     .toLowerCase().indexOf( lc ) !== -1;
			var ctxMatch     = ! lc     || row.getAttribute( 'data-trans-context' ) .toLowerCase().indexOf( lc ) !== -1;
			var defaultMatch = ! lc     || ( row.querySelector( '.ccmerch-trans-col-default' ) || {} ).textContent.toLowerCase().indexOf( lc ) !== -1;
			var overrideMatch = ! lc    || ( row.querySelector( '.ccmerch-trans-input' ) || {} ).value.toLowerCase().indexOf( lc ) !== -1;
			var contextFilter = ! context || row.getAttribute( 'data-trans-context' ) === context;

			var show = ( keyMatch || ctxMatch || defaultMatch || overrideMatch ) && contextFilter;
			row.style.display = show ? '' : 'none';
			if ( show ) { visible++; }
		} );

		if ( noRes ) {
			noRes.style.display = visible === 0 ? '' : 'none';
		}
	}

	// Delegated listeners for translation admin buttons.
	document.addEventListener( 'click', function ( e ) {
		var saveBtn  = e.target.closest( '[data-ccmerch-translation-save]' );
		var resetBtn = e.target.closest( '[data-ccmerch-translation-reset]' );
		if ( saveBtn  ) { ccmerchSaveTranslation( saveBtn );  }
		if ( resetBtn ) { ccmerchResetTranslation( resetBtn ); }
	} );

	// Delegated listeners for translation search/filter inputs.
	document.addEventListener( 'input', function ( e ) {
		if ( e.target.getAttribute( 'data-ccmerch-trans-search' ) ) {
			ccmerchFilterTranslations();
		}
	} );
	document.addEventListener( 'change', function ( e ) {
		if ( e.target.getAttribute( 'data-ccmerch-trans-context' ) ) {
			ccmerchFilterTranslations();
		}
	} );

	// =========================================================================
	// Chatbot portal handlers
	// =========================================================================

	/**
	 * Submits the chatbot settings form via fetch and shows inline feedback.
	 *
	 * @param {HTMLFormElement} form
	 */
	function ccmerchChatbotSave( form ) {
		var btn      = form.querySelector( 'button[type="submit"]' );
		var feedback = document.getElementById( 'ccmerch-chatbot-save-feedback' );
		var origText = btn ? btn.textContent : '';

		if ( btn ) {
			btn.disabled    = true;
			btn.textContent = 'Saving…';
		}
		if ( feedback ) {
			feedback.style.display = 'none';
		}

		var data = new FormData( form );

		fetch( form.getAttribute( 'action' ) || ( window.ajaxurl || '' ), {
			method:      'POST',
			body:        data,
			credentials: 'same-origin',
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( r ) {
			if ( feedback ) {
				feedback.textContent    = r.data && r.data.message ? r.data.message : ( r.success ? 'Saved.' : 'Error.' );
				feedback.style.color    = r.success ? '#00a32a' : '#d63638';
				feedback.style.display  = '';
				setTimeout( function () { feedback.style.display = 'none'; }, 3000 );
			}
		} )
		.catch( function () {
			if ( feedback ) {
				feedback.textContent   = 'Request failed.';
				feedback.style.color   = '#d63638';
				feedback.style.display = '';
				setTimeout( function () { feedback.style.display = 'none'; }, 3000 );
			}
		} )
		.finally( function () {
			if ( btn ) {
				btn.disabled    = false;
				btn.textContent = origText;
			}
		} );
	}

	/**
	 * Sends a chatbot message preview request and displays the rendered string.
	 *
	 * @param {HTMLElement} btn  Button with data-ccmerch-action / data-ccmerch-auth-* attributes.
	 */
	function ccmerchChatbotPreview( btn ) {
		var form       = btn.closest( 'form' );
		var output     = document.getElementById( 'ccmerch-chatbot-preview-output' );
		var action     = btn.getAttribute( 'data-ccmerch-action' )     || '';
		var authField  = btn.getAttribute( 'data-ccmerch-auth-field' ) || '';
		var authValue  = btn.getAttribute( 'data-ccmerch-auth-value' ) || '';
		var ajaxUrl    = btn.getAttribute( 'data-ccmerch-ajax-url' )   || ( window.ajaxurl || '' );
		var template   = form ? ( form.querySelector( '[name="chatbot_template"]' ) || {} ).value || '' : '';

		if ( ! action ) { return; }

		btn.disabled = true;

		var body = new URLSearchParams( {
			action:            action,
			chatbot_template:  template,
		} );
		body.append( authField, authValue );

		fetch( ajaxUrl, {
			method:      'POST',
			body:        body.toString(),
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
			credentials: 'same-origin',
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( r ) {
			if ( output ) {
				if ( r.success && r.data && r.data.preview ) {
					output.textContent   = r.data.preview;
					output.style.display = '';
				} else {
					output.textContent   = ( r.data && r.data.message ) ? r.data.message : 'Preview unavailable.';
					output.style.display = '';
				}
			}
		} )
		.catch( function () {
			if ( output ) {
				output.textContent   = 'Preview request failed.';
				output.style.display = '';
			}
		} )
		.finally( function () { btn.disabled = false; } );
	}

	// Delegated submit listener for chatbot settings form.
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest( '[data-ccmerch-chatbot-save]' );
		if ( ! form ) { return; }
		e.preventDefault();
		ccmerchChatbotSave( form );
	} );

	// Delegated click listener for chatbot preview button.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-ccmerch-chatbot-preview]' );
		if ( btn ) { ccmerchChatbotPreview( btn ); }
	} );

	/**
	 * Sends an actual test chatbot message via the creator portal endpoint.
	 *
	 * Shows success/error in #ccmerch-chatbot-save-feedback.
	 *
	 * @param {HTMLButtonElement} btn  Button with data-ccmerch-action / auth attributes.
	 */
	function ccmerchChatbotSendTest( btn ) {
		var feedback  = document.getElementById( 'ccmerch-chatbot-save-feedback' );
		var action    = btn.getAttribute( 'data-ccmerch-action' )     || '';
		var authField = btn.getAttribute( 'data-ccmerch-auth-field' ) || '';
		var authValue = btn.getAttribute( 'data-ccmerch-auth-value' ) || '';
		var ajaxUrl   = btn.getAttribute( 'data-ccmerch-ajax-url' )   || ( window.ajaxurl || '' );

		if ( ! action ) { return; }

		var original = btn.textContent;
		btn.disabled    = true;
		btn.textContent = 'Sending…';

		if ( feedback ) {
			feedback.textContent   = '';
			feedback.style.color   = '';
			feedback.style.display = 'none';
		}

		var body = new URLSearchParams( { action: action } );
		body.append( authField, authValue );

		fetch( ajaxUrl, {
			method:      'POST',
			body:        body.toString(),
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
			credentials: 'same-origin',
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( r ) {
			if ( feedback ) {
				var msg = ( r.data && r.data.message ) ? r.data.message : ( r.success ? 'Sent!' : 'Failed.' );
				feedback.textContent   = msg;
				feedback.style.color   = r.success ? '#00a32a' : '#d63638';
				feedback.style.display = '';
			}
		} )
		.catch( function () {
			if ( feedback ) {
				feedback.textContent   = 'Request failed — check your connection and try again.';
				feedback.style.color   = '#d63638';
				feedback.style.display = '';
			}
		} )
		.finally( function () {
			btn.disabled    = false;
			btn.textContent = original;
		} );
	}

	// Delegated click listener for chatbot portal test-send button.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-ccmerch-chatbot-send-test]' );
		if ( btn ) { ccmerchChatbotSendTest( btn ); }
	} );

	// ── Chatbot admin test send ───────────────────────────────────────────────

	/**
	 * Sends a test chatbot message for a specific creator from the admin
	 * Chatbot Diagnostics card.
	 *
	 * Reads creator_id, nonce, and ajax_url from data attributes on the button.
	 * Shows a summary line + step-by-step diagnostic log in the span after the button.
	 *
	 * @param {HTMLButtonElement} btn The "Test Send" button element.
	 */
	function ccmerchChatbotTestSend( btn ) {
		var creatorId = btn.getAttribute( 'data-ccmerch-creator-id' ) || '';
		var ajaxUrl   = btn.getAttribute( 'data-ccmerch-ajax-url' )   || ( window.ajaxurl || '' );
		var nonce     = btn.getAttribute( 'data-ccmerch-nonce' )       || '';
		var feedback  = btn.nextElementSibling;

		if ( ! creatorId || ! ajaxUrl ) { return; }

		var original = btn.textContent;
		btn.disabled    = true;
		btn.textContent = 'Sending…';

		if ( feedback ) {
			feedback.innerHTML = '';
		}

		var body = new FormData();
		body.append( 'action',      'ccmerch_chatbot_test_send' );
		body.append( '_ajax_nonce', nonce );
		body.append( 'creator_id',  creatorId );

		fetch( ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) {
				if ( ! feedback ) { return; }

				feedback.innerHTML = '';

				var msg   = ( r.data && r.data.message ) ? r.data.message : ( r.success ? 'Sent!' : 'Failed.' );
				var steps = ( r.data && Array.isArray( r.data.steps ) ) ? r.data.steps : [];

				// Summary line.
				var summary = document.createElement( 'span' );
				summary.className   = 'ccmerch-chatbot-test-summary';
				summary.textContent = msg;
				summary.style.color = r.success ? '#00a32a' : '#d63638';
				feedback.appendChild( summary );

				// Step log (shown below the row via CSS block layout).
				if ( steps.length ) {
					var log = document.createElement( 'ol' );
					log.className = 'ccmerch-chatbot-steps';
					for ( var i = 0; i < steps.length; i++ ) {
						var item   = document.createElement( 'li' );
						var status = steps[i][0]; // 'ok' | 'fail' | 'info'
						var text   = steps[i][1];
						item.className   = 'ccmerch-chatbot-step ccmerch-chatbot-step--' + status;
						item.textContent = text;
						log.appendChild( item );
					}
					feedback.appendChild( log );
				}
			} )
			.catch( function () {
				if ( feedback ) {
					feedback.innerHTML   = '';
					var err = document.createElement( 'span' );
					err.className   = 'ccmerch-chatbot-test-summary';
					err.textContent = 'Request failed — check browser console for details.';
					err.style.color = '#d63638';
					feedback.appendChild( err );
				}
			} )
			.finally( function () {
				btn.disabled    = false;
				btn.textContent = original;
			} );
	}

	// Delegated click listener for chatbot admin test-send buttons.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-ccmerch-chatbot-test]' );
		if ( btn ) {
			e.preventDefault();
			ccmerchChatbotTestSend( btn );
		}
	} );

	// =========================================================================
	// Chatbot — Creator Channel Authorization
	// =========================================================================

	/**
	 * Revokes the creator's channel:bot authorization.
	 *
	 * Uses a two-step inline confirmation (arm → confirm) instead of
	 * window.confirm(), which browsers can silently block (returns false
	 * without showing any dialog, making the button appear non-functional).
	 *
	 * @param {HTMLButtonElement} btn Button with data-ccmerch-action / auth attributes.
	 */
	function ccmerchRevokeChannelAuth( btn ) {
		var action    = btn.getAttribute( 'data-ccmerch-action' )     || '';
		var authField = btn.getAttribute( 'data-ccmerch-auth-field' ) || '';
		var authValue = btn.getAttribute( 'data-ccmerch-auth-value' ) || '';
		var ajaxUrl   = btn.getAttribute( 'data-ccmerch-ajax-url' )   || '';

		if ( ! action || ! ajaxUrl ) { return; }

		// First click — arm the button. Second click within 3 s — execute.
		if ( ! btn.getAttribute( 'data-ccmerch-revoke-armed' ) ) {
			btn.setAttribute( 'data-ccmerch-revoke-original', btn.textContent );
			btn.setAttribute( 'data-ccmerch-revoke-armed', '1' );
			btn.textContent          = 'Click again to confirm';
			btn.style.background     = '#d63638';
			btn.style.borderColor    = '#d63638';
			btn.style.color          = '#fff';
			var armTimer = setTimeout( function () {
				btn.removeAttribute( 'data-ccmerch-revoke-armed' );
				btn.removeAttribute( 'data-ccmerch-revoke-timer' );
				btn.textContent       = btn.getAttribute( 'data-ccmerch-revoke-original' ) || 'Revoke Authorization';
				btn.removeAttribute( 'data-ccmerch-revoke-original' );
				btn.style.background  = '';
				btn.style.borderColor = '';
				btn.style.color       = '';
			}, 3000 );
			btn.setAttribute( 'data-ccmerch-revoke-timer', armTimer );
			return;
		}

		// Second click — proceed with revoke.
		var timerVal = parseInt( btn.getAttribute( 'data-ccmerch-revoke-timer' ) || '0', 10 );
		if ( timerVal ) { clearTimeout( timerVal ); }
		var origText = btn.getAttribute( 'data-ccmerch-revoke-original' ) || 'Revoke Authorization';
		btn.removeAttribute( 'data-ccmerch-revoke-armed' );
		btn.removeAttribute( 'data-ccmerch-revoke-timer' );
		btn.removeAttribute( 'data-ccmerch-revoke-original' );
		btn.disabled             = true;
		btn.textContent          = 'Revoking…';
		btn.style.background     = '';
		btn.style.borderColor    = '';
		btn.style.color          = '';

		var body = new FormData();
		body.append( 'action', action );
		if ( authField && authValue ) { body.append( authField, authValue ); }

		fetch( ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json && json.success ) {
					// Rebuild the channel auth card in-place — no page reload required.
					var card = document.getElementById( 'ccmerch-channel-auth-status' );
					if ( card ) {
						var authUrl = card.getAttribute( 'data-ccmerch-auth-url' ) || '';

						var authorizeHtml;
						if ( authUrl ) {
							authorizeHtml =
								'<p><a href="' + authUrl + '" class="button button-primary">' +
								'Authorize Chatbot for My Channel</a></p>';
						} else {
							authorizeHtml =
								'<p><span class="description" style="color:#d63638;">' +
								'Twitch app credentials are not configured. Please contact your admin.' +
								'</span></p>';
						}

						// Preserve the data-ccmerch-auth-url attribute across the rebuild so a
						// second authorize→revoke cycle within the same page load still works.
						card.innerHTML =
							'<h2 class="ccmerch-card__title">Channel Authorization</h2>' +
							'<p>' +
								'<span style="color:#d63638;font-size:1.1em;" aria-hidden="true">&#9679;</span> ' +
								'<strong>Not authorized</strong>' +
							'</p>' +
							'<p class="description">' +
								'Authorize the bot to send messages in your Twitch channel. ' +
								'You will be redirected to Twitch to grant permission.' +
							'</p>' +
							authorizeHtml +
							'<p style="color:#00a32a;margin-top:8px;font-size:0.875rem;" id="ccmerch-revoke-notice">' +
								'Authorization revoked successfully.' +
							'</p>';

						// Auto-remove the success notice after 4 s.
						setTimeout( function () {
							var notice = document.getElementById( 'ccmerch-revoke-notice' );
							if ( notice && notice.parentNode ) {
								notice.parentNode.removeChild( notice );
							}
						}, 4000 );
					}
				} else {
					var msg = ( json && json.data && json.data.message )
						? json.data.message
						: 'Failed to revoke authorization.';
					alert( msg );
					btn.disabled    = false;
					btn.textContent = origText;
				}
			} )
			.catch( function () {
				alert( 'Request failed. Please check your connection and try again.' );
				btn.disabled    = false;
				btn.textContent = origText;
			} );
	}

	// Delegated click listener for channel authorization revoke button.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-ccmerch-channel-auth-revoke="1"]' );
		if ( btn ) { ccmerchRevokeChannelAuth( btn ); }
	} );

} )();
