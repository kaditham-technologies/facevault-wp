/**
 * Front-end verify flow: click → mint token server-side → open the
 * FaceVault widget → optimistic status update → authoritative refresh.
 *
 * Vanilla JS, no build step. Config arrives via wp_localize_script as
 * `facevaultVerify` ({ restUrl, nonce, i18n }). The widget loader
 * (embed.js) exposes window.FV; the `complete` event is UX-only — the
 * server learns the real outcome from the webhook / status poll.
 */
( function () {
	'use strict';

	var cfg = window.facevaultVerify || {};
	var i18n = cfg.i18n || {};
	var active = null; // The .facevault-verify wrapper currently verifying.
	var fvHooked = false;

	function text( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	function setMessage( wrap, message ) {
		var el = wrap.querySelector( '.facevault-verify__message' );
		if ( el ) {
			el.textContent = message;
		}
	}

	function setBusy( wrap, busy ) {
		var btn = wrap.querySelector( '.facevault-verify__button' );
		if ( btn ) {
			btn.disabled = busy;
			btn.classList.toggle( 'is-busy', busy );
		}
	}

	function setBadge( wrap, status, message ) {
		wrap.setAttribute( 'data-status', status );
		var btn = wrap.querySelector( '.facevault-verify__button' );
		if ( btn && ( 'verified' === status || 'review' === status ) ) {
			btn.style.display = 'none';
		}
		setMessage( wrap, message );
	}

	function restFetch( path, body ) {
		return window.fetch( cfg.restUrl + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce,
			},
			body: JSON.stringify( body || {} ),
		} );
	}

	function waitForFV( timeoutMs ) {
		return new Promise( function ( resolve, reject ) {
			var waited = 0;
			( function poll() {
				if ( window.FV && window.FV.open ) {
					resolve( window.FV );
				} else if ( waited >= timeoutMs ) {
					reject( new Error( 'embed unavailable' ) );
				} else {
					waited += 100;
					window.setTimeout( poll, 100 );
				}
			} )();
		} );
	}

	function hookEvents( FV ) {
		if ( fvHooked ) {
			return;
		}
		fvHooked = true;

		FV.on( 'complete', function ( detail ) {
			if ( ! active ) {
				return;
			}
			var wrap = active;
			if ( detail && 'accept' === detail.decision ) {
				setBadge( wrap, 'verified', text( 'confirming', 'Verified — confirming…' ) );
			} else if ( detail && 'review' === detail.decision ) {
				setBadge( wrap, 'review', text( 'review', 'Verification submitted — pending review.' ) );
			} else {
				setBadge( wrap, 'pending', text( 'processing', 'Verification submitted — processing…' ) );
			}
			// Pull the authoritative state from our server.
			restFetch( 'refresh' )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( data ) {
					if ( ! data || ! data.status ) {
						return;
					}
					if ( 'verified' === data.status ) {
						setBadge( wrap, 'verified', text( 'verified', 'Identity verified ✓' ) );
						var redirect = wrap.getAttribute( 'data-redirect' );
						if ( redirect ) {
							window.location.assign( redirect );
						}
					} else if ( 'review' === data.status ) {
						setBadge( wrap, 'review', text( 'review', 'Verification submitted — pending review.' ) );
					}
				} )
				.catch( function () {
					// Optimistic badge stays; webhook/poll will settle it.
				} );
			active = null;
		} );

		FV.on( 'error', function () {
			if ( active ) {
				setBusy( active, false );
				setMessage( active, text( 'widgetError', 'Something went wrong in the verification widget. Please try again.' ) );
				active = null;
			}
		} );

		FV.on( 'cancel', function () {
			if ( active ) {
				setBusy( active, false );
				setMessage( active, '' );
				active = null;
			}
		} );
	}

	function tokenErrorMessage( data ) {
		var code = data && data.code ? data.code : '';
		if ( 'rest_cookie_invalid_nonce' === code ) {
			return text( 'staleNonce', 'Your session expired — please reload the page and try again.' );
		}
		if ( 'rate_limited' === code ) {
			return text( 'rateLimited', 'Too many attempts — please wait a few minutes and try again.' );
		}
		if ( 'already_verified' === code ) {
			return text( 'verified', 'Identity verified ✓' );
		}
		return text( 'unavailable', 'Identity verification is temporarily unavailable. Please try again later.' );
	}

	function startVerification( wrap ) {
		setBusy( wrap, true );
		setMessage( wrap, '' );

		restFetch( 'token', { page: window.location.href } )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok || ! result.data || ! result.data.token ) {
					if ( result.data && 'already_verified' === result.data.code ) {
						setBadge( wrap, 'verified', text( 'verified', 'Identity verified ✓' ) );
					} else {
						setBusy( wrap, false );
						setMessage( wrap, tokenErrorMessage( result.data ) );
					}
					return;
				}
				return waitForFV( 3000 ).then( function ( FV ) {
					hookEvents( FV );
					active = wrap;
					FV.open( result.data.token );
				} );
			} )
			.catch( function () {
				setBusy( wrap, false );
				setMessage( wrap, text( 'embedBlocked', 'Could not load the verification widget — check your ad blocker and try again.' ) );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest ? event.target.closest( '.facevault-verify__button' ) : null;
		if ( ! btn || btn.disabled ) {
			return;
		}
		var wrap = btn.closest( '.facevault-verify' );
		if ( wrap ) {
			event.preventDefault();
			startVerification( wrap );
		}
	} );
} )();
