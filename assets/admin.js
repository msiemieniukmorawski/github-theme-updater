/**
 * GitHub Theme Updater — admin behaviour.
 *
 * Three jobs:
 *  - ask for confirmation before any destructive submit;
 *  - run an update over fetch() and show its progress step by step, polling
 *    the progress endpoint while the request is pending;
 *  - warn before leaving the settings form with unsaved changes.
 *
 * Everything degrades to plain form submits when fetch() is unavailable.
 */
( function () {
	'use strict';

	var config = window.gthuAdmin || {};
	var i18n   = config.i18n || {};

	var panel     = document.querySelector( '[data-gthu-progress-panel]' );
	var pollTimer = null;
	var tickTimer = null;
	var startedAt = 0;

	/* Confirmations ------------------------------------------------------ */

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;

		if ( ! target || 'function' !== typeof target.closest ) {
			return;
		}

		var trigger = target.closest( '[data-gthu-confirm]' );

		if ( ! trigger ) {
			return;
		}

		if ( ! window.confirm( trigger.getAttribute( 'data-gthu-confirm' ) ) ) {
			event.preventDefault();
			return;
		}

		var form = trigger.form || trigger.closest( 'form' );

		if ( ! form ) {
			return;
		}

		if ( form.hasAttribute( 'data-gthu-progress' ) && canShowProgress() ) {
			event.preventDefault();
			markBusy( trigger );
			runWithProgress( form );
			return;
		}

		// Let the submit through, then lock the button so nobody double-fires it.
		window.setTimeout( function () {
			markBusy( trigger );
		}, 0 );
	} );

	function markBusy( trigger ) {
		trigger.disabled = true;
		trigger.classList.add( 'updating-message' );

		if ( trigger.dataset.gthuBusyLabel ) {
			trigger.textContent = trigger.dataset.gthuBusyLabel;
		}
	}

	/* Progress ----------------------------------------------------------- */

	function canShowProgress() {
		return !! ( panel && window.fetch && window.FormData && config.ajaxUrl && config.progressNonce );
	}

	function runWithProgress( form ) {
		var body = new FormData( form );
		body.append( 'gthu_ajax', '1' );

		showPanel();
		lockActions();
		startClock( 0 );
		startPolling( false );

		// Not form.action: the hidden <input name="action"> shadows that
		// property and would be handed to fetch() instead of the URL.
		var url = form.getAttribute( 'action' ) || window.location.href;

		window.fetch( url, {
			method: 'POST',
			body: body,
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		} )
			.then( function ( response ) {
				// The handler answered with a plain redirect (no JSON): follow it.
				if ( response.redirected && response.url ) {
					finish( response.url );
					return null;
				}

				return response.text().then( function ( text ) {
					var payload = null;

					try {
						payload = JSON.parse( text );
					} catch ( e ) {
						payload = null;
					}

					if ( payload && payload.data && payload.data.redirect ) {
						finish( payload.data.redirect );
						return null;
					}

					throw new Error( 'HTTP ' + response.status );
				} );
			} )
			.catch( function ( error ) {
				stopPolling();
				setNote( ( i18n.interrupted || '' ) + ( error && error.message ? ' (' + error.message + ')' : '' ) );

				// The server may still be working (ignore_user_abort), so a reload
				// shows whatever state it reached.
				window.setTimeout( function () {
					window.location.reload();
				}, 4000 );
			} );
	}

	function finish( url ) {
		stopPolling();
		window.location.assign( url );
	}

	function showPanel() {
		panel.hidden = false;
		panel.scrollIntoView( { block: 'nearest' } );
	}

	function lockActions() {
		var buttons = document.querySelectorAll( '.gthu-actions button, .gthu-inline-form button' );

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.disabled = true;
		} );
	}

	function startPolling( reloadWhenIdle ) {
		poll( reloadWhenIdle );

		pollTimer = window.setInterval( function () {
			poll( reloadWhenIdle );
		}, 1000 );
	}

	function stopPolling() {
		if ( pollTimer ) {
			window.clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	function poll( reloadWhenIdle ) {
		var url = config.ajaxUrl
			+ '?action=gthu_progress'
			+ '&_ajax_nonce=' + encodeURIComponent( config.progressNonce )
			+ '&_=' + Date.now();

		window.fetch( url, { credentials: 'same-origin', cache: 'no-store' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success || ! payload.data ) {
					return;
				}

				render( payload.data, reloadWhenIdle );

				if ( reloadWhenIdle && ! payload.data.running ) {
					stopPolling();
					window.location.reload();
				}
			} )
			.catch( function () {
				setNote( i18n.pollFailed || '' );
			} );
	}

	function render( state, syncClock ) {
		if ( ! state.steps || ! state.steps.length ) {
			// Nothing recorded yet, or already cleared: keep what is on screen.
			return;
		}

		if ( syncClock ) {
			startClock( state.elapsed || 0 );
		}

		var byKey = {};

		state.steps.forEach( function ( step ) {
			byKey[ step.key ] = step;
		} );

		var items = panel.querySelectorAll( '[data-gthu-step]' );

		Array.prototype.forEach.call( items, function ( item ) {
			var key    = item.getAttribute( 'data-gthu-step' );
			var step   = byKey[ key ];
			var detail = item.querySelector( '[data-gthu-detail]' );
			var status = step ? step.status : 'pending';

			item.classList.remove( 'is-active', 'is-done', 'is-skipped' );

			if ( 'pending' !== status ) {
				item.classList.add( 'is-' + status );
			}

			if ( item.classList.contains( 'gthu-progress__step--recovery' ) ) {
				item.hidden = ! step || 'pending' === status;
			}

			if ( ! detail ) {
				return;
			}

			if ( 'active' === status ) {
				detail.textContent = state.detail || '';
			} else if ( 'skipped' === status ) {
				detail.textContent = i18n.skipped || '';
			} else {
				detail.textContent = '';
			}
		} );
	}

	function setNote( text ) {
		var note = panel ? panel.querySelector( '.gthu-progress__note' ) : null;

		if ( note && text ) {
			note.textContent = text;
		}
	}

	function startClock( elapsedSeconds ) {
		startedAt = Date.now() - ( elapsedSeconds * 1000 );

		if ( tickTimer ) {
			return;
		}

		tick();
		tickTimer = window.setInterval( tick, 1000 );
	}

	function tick() {
		var el = panel ? panel.querySelector( '[data-gthu-elapsed]' ) : null;

		if ( ! el ) {
			return;
		}

		var total   = Math.max( 0, Math.round( ( Date.now() - startedAt ) / 1000 ) );
		var minutes = Math.floor( total / 60 );
		var seconds = total % 60;

		el.textContent = minutes + ':' + ( seconds < 10 ? '0' : '' ) + seconds;
	}

	// The page was opened while an update started elsewhere is still running:
	// follow it, and reload once the lock is gone so the result shows up.
	if ( panel && panel.hasAttribute( 'data-gthu-autostart' ) && canShowProgress() ) {
		lockActions();
		startPolling( true );
	}

	/* Unsaved changes ---------------------------------------------------- */

	var guarded = document.querySelector( 'form[data-gthu-guard]' );

	if ( guarded ) {
		var dirty = false;

		guarded.addEventListener( 'input', function () {
			dirty = true;
		} );

		guarded.addEventListener( 'change', function () {
			dirty = true;
		} );

		guarded.addEventListener( 'submit', function () {
			dirty = false;
		} );

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( ! dirty ) {
				return undefined;
			}

			event.preventDefault();
			event.returnValue = i18n.unsaved || '';

			return event.returnValue;
		} );
	}
}() );
