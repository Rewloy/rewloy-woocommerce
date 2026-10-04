/**
 * Rewloy for WooCommerce, Rewloy › Kartlar: the latest activity, refreshed every 30 seconds while the tab is visible.
 * It asks WordPress (admin-ajax, with the screen's nonce), never Rewloy: the API key stays on the server. Rows are
 * written as text, never as HTML.
 */
( function () {
	'use strict';
	var cfg = window.RewloyWc;
	var body = document.getElementById( 'rewloy-wc-activity-rows' );
	if ( ! cfg || ! body ) {
		return;
	}
	var stamp = document.getElementById( 'rewloy-wc-refreshed' );
	var errorBox = document.getElementById( 'rewloy-wc-activity-error' );
	var every = ( cfg.every || 30 ) * 1000;
	var last = Date.now();
	var busy = false;

	function cell( tr, text, cls ) {
		var td = document.createElement( 'td' );
		td.textContent = text || '';
		if ( cls ) {
			td.className = cls;
		}
		tr.appendChild( td );
	}

	function showError( text ) {
		if ( ! errorBox ) {
			return;
		}
		errorBox.hidden = ! text;
		var p = errorBox.querySelector( 'p' );
		if ( p ) {
			p.textContent = text || '';
		}
	}

	function render( rows ) {
		while ( body.firstChild ) {
			body.removeChild( body.firstChild );
		}
		if ( ! rows.length ) {
			var tr = document.createElement( 'tr' );
			var td = document.createElement( 'td' );
			td.colSpan = 5;
			td.textContent = cfg.text.empty;
			tr.appendChild( td );
			body.appendChild( tr );
			return;
		}
		rows.forEach( function ( r ) {
			var tr = document.createElement( 'tr' );
			cell( tr, r.when );
			cell( tr, r.what );
			cell( tr, r.card, 'rewloy-wc-mono' );
			cell( tr, r.where );
			cell( tr, r.who );
			body.appendChild( tr );
		} );
	}

	function refresh() {
		if ( busy || document.visibilityState !== 'visible' ) {
			return;
		}
		busy = true;
		var form = new FormData();
		form.append( 'action', 'rewloy_wc_activity' );
		form.append( 'nonce', cfg.nonce );
		fetch( cfg.ajax, { method: 'POST', body: form, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				last = Date.now();
				if ( data && data.ok && Array.isArray( data.rows ) ) {
					render( data.rows );
					showError( '' );
					if ( stamp ) {
						stamp.textContent = cfg.text.refreshed + ' ' + new Date().toLocaleTimeString();
					}
				} else {
					showError( ( data && data.message ) || cfg.text.offline );
				}
			} )
			.catch( function () {
				showError( cfg.text.offline );
			} )
			.finally( function () {
				busy = false;
			} );
	}

	setInterval( refresh, every );
	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'visible' && Date.now() - last >= every ) {
			refresh();
		}
	} );
} )();
