/**
 * Vanpay waiting panel: opens the Vanpay checkout in a new tab once as a
 * first attempt, then polls the order status endpoint and flips the panel
 * to its "paid" state once the payment is confirmed.
 */
( function () {
	'use strict';

	var panel = document.getElementById( 'vanpay-pay-panel' );
	if ( ! panel ) {
		return;
	}

	var statusUrl   = panel.getAttribute( 'data-status-url' );
	var checkoutUrl = panel.getAttribute( 'data-checkout-url' );
	var interval    = parseInt( panel.getAttribute( 'data-interval' ), 10 ) || 5000;
	if ( ! statusUrl || ! window.fetch ) {
		return;
	}

	var MAX_POLL_MS = 30 * 60 * 1000; // Stop after 30 minutes.
	var startedAt   = Date.now();
	var timer       = null;
	var done        = false;

	// First-visit initiative: try to open the Vanpay checkout in a new tab.
	// Popup blockers may refuse it — the button in the panel stays the
	// primary path, and a hint is shown when the attempt was blocked.
	function autoOpenCheckout() {
		if ( ! checkoutUrl ) {
			return;
		}

		var storageKey = 'vanpay_opened_' + statusUrl;
		try {
			if ( '1' === window.sessionStorage.getItem( storageKey ) ) {
				return;
			}
			window.sessionStorage.setItem( storageKey, '1' );
		} catch ( e ) {
			// Storage unavailable (private mode etc.): still attempt once.
		}

		var popup = window.open( checkoutUrl, '_blank' );
		if ( popup ) {
			popup.opener = null;
		} else {
			var hint = panel.querySelector( '.vanpay-popup-hint' );
			if ( hint ) {
				hint.hidden = false;
			}
		}
	}

	function markPaid() {
		if ( done ) {
			return;
		}
		done = true;

		panel.classList.add( 'vanpay-paid' );

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

	autoOpenCheckout();

	timer = window.setInterval( poll, interval );

	// Poll immediately when the tab becomes visible again (e.g. the customer
	// returns from the Vanpay tab after paying).
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			poll();
		}
	} );
} )();
