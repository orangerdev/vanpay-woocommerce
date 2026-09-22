/**
 * Vanpay waiting panel: polls the order status endpoint and flips the panel
 * to its "paid" state once the payment is confirmed.
 */
( function () {
	'use strict';

	var panel = document.getElementById( 'vanpay-pay-panel' );
	if ( ! panel ) {
		return;
	}

	var statusUrl = panel.getAttribute( 'data-status-url' );
	var interval  = parseInt( panel.getAttribute( 'data-interval' ), 10 ) || 5000;
	if ( ! statusUrl || ! window.fetch ) {
		return;
	}

	var MAX_POLL_MS = 30 * 60 * 1000; // Stop after 30 minutes.
	var startedAt   = Date.now();
	var timer       = null;
	var done        = false;

	function markPaid() {
		if ( done ) {
			return;
		}
		done = true;

		var waiting = panel.querySelector( '.vanpay-state-waiting' );
		var paid    = panel.querySelector( '.vanpay-state-paid' );
		if ( waiting ) {
			waiting.style.display = 'none';
		}
		if ( paid ) {
			paid.style.display = '';
		}
		panel.style.borderColor = '#c3e6cb';
		panel.style.background  = '#f0fff4';

		// Hook point for analytics/tracking integrations.
		document.dispatchEvent( new CustomEvent( 'vanpay:paid' ) );

		// Reload so status-dependent content (and tracking plugins that key
		// off the order status) render the confirmed state.
		window.setTimeout( function () {
			window.location.reload();
		}, 2000 );
	}

	function poll() {
		if ( done || document.hidden ) {
			return;
		}
		if ( Date.now() - startedAt > MAX_POLL_MS ) {
			window.clearInterval( timer );
			return;
		}

		fetch( statusUrl, { credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( data ) {
				if ( data && data.paid ) {
					window.clearInterval( timer );
					markPaid();
				}
			} )
			.catch( function () {
				// Transient network error: keep polling.
			} );
	}

	timer = window.setInterval( poll, interval );

	// Poll immediately when the tab becomes visible again (e.g. the customer
	// returns from the Vanpay tab after paying).
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			poll();
		}
	} );
} )();
