/**
 * BeaverMind admin JS — wires the Enhance Prompt button and the Quick
 * Refine action buttons. Loaded only on BeaverMind admin pages.
 *
 * Conventions (so we don't need a different script per page):
 *   - Enhance Prompt button: <button data-bm-enhance-target="<textarea-id>">…</button>
 *   - Quick Refine action:   <button data-bm-template-target="<textarea-id>"
 *                                    data-bm-template="…instruction text…">…</button>
 *
 * Globals: window.BeaverMindAdmin = { restRoot, restNonce } injected via
 * wp_localize_script so we can hit /wp-json/beavermind/v1/enhance-prompt
 * without inlining the URL or nonce.
 */
( function () {
	'use strict';

	const cfg = window.BeaverMindAdmin || {};

	// ---------- Enhance Prompt ----------

	function findTextarea( id ) {
		const el = document.getElementById( id );
		if ( ! el || ( el.tagName !== 'TEXTAREA' && el.tagName !== 'INPUT' ) ) {
			return null;
		}
		return el;
	}

	function setBusy( button, busy, originalLabel ) {
		if ( busy ) {
			button.dataset.bmOriginalLabel = button.textContent;
			button.textContent = '✨ Enhancing…';
			button.disabled = true;
		} else {
			button.textContent = originalLabel || button.dataset.bmOriginalLabel || 'Enhance Prompt';
			button.disabled = false;
			delete button.dataset.bmOriginalLabel;
		}
	}

	async function enhancePrompt( button ) {
		const targetId = button.dataset.bmEnhanceTarget;
		const target = findTextarea( targetId );
		if ( ! target ) {
			return;
		}
		const prompt = ( target.value || '' ).trim();
		if ( ! prompt ) {
			alert( 'Write a prompt first, then click Enhance.' );
			return;
		}
		if ( ! cfg.restRoot || ! cfg.restNonce ) {
			alert( 'BeaverMind admin JS is missing config. Reload the page.' );
			return;
		}

		setBusy( button, true );
		try {
			const resp = await fetch( cfg.restRoot + 'enhance-prompt', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.restNonce,
				},
				body: JSON.stringify( { prompt: prompt } ),
			} );
			const data = await resp.json();
			if ( ! resp.ok ) {
				throw new Error( data.message || ( 'HTTP ' + resp.status ) );
			}
			if ( typeof data.enhanced !== 'string' ) {
				throw new Error( 'Bad response shape' );
			}

			// Replace the textarea content. The user's original is recoverable
			// via Cmd+Z / Ctrl+Z thanks to native textarea undo.
			target.value = data.enhanced;
			target.focus();
			// Trigger input event so any listeners (e.g. character counters)
			// pick up the change.
			target.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} catch ( err ) {
			console.error( 'BeaverMind enhance-prompt failed:', err );
			alert( 'Enhance failed: ' + ( err.message || 'unknown error' ) );
		} finally {
			setBusy( button, false );
		}
	}

	// ---------- Quick Refine templates ----------

	function applyTemplate( button ) {
		const targetId = button.dataset.bmTemplateTarget;
		const target = findTextarea( targetId );
		if ( ! target ) {
			return;
		}
		const template = button.dataset.bmTemplate || '';
		// Append rather than replace so users can stack quick actions
		// ("Make shorter" then "More playful") and refine in one go.
		const current = ( target.value || '' ).trim();
		target.value = current ? current + '\n' + template : template;
		target.focus();
		target.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}

	// ---------- Wire on DOM ready ----------

	function wire() {
		document.querySelectorAll( '[data-bm-enhance-target]' ).forEach( ( btn ) => {
			btn.addEventListener( 'click', ( e ) => {
				e.preventDefault();
				enhancePrompt( btn );
			} );
		} );

		document.querySelectorAll( '[data-bm-template-target]' ).forEach( ( btn ) => {
			btn.addEventListener( 'click', ( e ) => {
				e.preventDefault();
				applyTemplate( btn );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', wire );
	} else {
		wire();
	}
} )();
