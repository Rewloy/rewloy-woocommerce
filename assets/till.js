/**
 * Rewloy for WooCommerce, Rewloy › Kasa: read a card (typed, or a USB/Bluetooth scanner typing the QR code's link),
 * record a sale, use the card's own operations.
 *
 * - Every call goes to WordPress (admin-ajax, with the screen's nonce), never to Rewloy: the API key stays on the
 *   server, which checks the person's capability and the nonce again.
 * - From a scanned card link only the card number is taken; the link (and its private `k`) is cleared from the field
 *   at once and never sent, stored or logged.
 * - Each button press gets its own Idempotency-Key (a random UUID). If no clear answer comes, "Try again" sends the
 *   SAME press with the SAME key, so Rewloy never writes it twice; a new press gets a new key.
 * - Everything from the server is written as text, never as HTML.
 */
( function () {
	'use strict';
	var cfg = window.RewloyWc;
	var readForm = document.getElementById( 'rewloy-wc-till-read' );
	if ( ! cfg || ! readForm ) {
		return;
	}
	var t = cfg.text;
	var input = document.getElementById( 'rewloy-wc-till-card' );
	var message = document.getElementById( 'rewloy-wc-till-message' );
	var stateBox = document.getElementById( 'rewloy-wc-till-card-state' );
	var saleForm = document.getElementById( 'rewloy-wc-till-sale' );
	var saleWrites = document.getElementById( 'rewloy-wc-till-sale-writes' );
	var amount = document.getElementById( 'rewloy-wc-till-amount' );
	var reference = document.getElementById( 'rewloy-wc-till-reference' );
	var actionsBox = document.getElementById( 'rewloy-wc-till-actions' );
	var serial = '';
	var busy = false;

	/** A version 4 UUID: the press's Idempotency-Key. */
	function newKey() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}
		var b = window.crypto.getRandomValues( new Uint8Array( 16 ) );
		b[ 6 ] = ( b[ 6 ] & 0x0f ) | 0x40;
		b[ 8 ] = ( b[ 8 ] & 0x3f ) | 0x80;
		var h = Array.prototype.map.call( b, function ( x ) {
			return ( x + 0x100 ).toString( 16 ).slice( 1 );
		} ).join( '' );
		return h.slice( 0, 8 ) + '-' + h.slice( 8, 12 ) + '-' + h.slice( 12, 16 ) + '-' + h.slice( 16, 20 ) + '-' + h.slice( 20 );
	}

	function normalize( raw ) {
		var plain = String( raw ).replace( /[\s-]+/g, '' ).toUpperCase();
		return /^[A-Z0-9]{12}$/.test( plain ) ? plain.slice( 0, 4 ) + '-' + plain.slice( 4, 8 ) + '-' + plain.slice( 8 ) : '';
	}

	/** The card number from a typed number or a scanned card link on rewloy.com; '' otherwise. */
	function serialOf( raw ) {
		raw = String( raw ).trim();
		if ( /^https?:\/\//i.test( raw ) ) {
			try {
				var u = new URL( raw );
				var m = /^\/p\/([A-Za-z0-9-]{12,14})\/?$/.exec( u.pathname );
				if ( u.protocol === 'https:' && /(^|\.)rewloy\.com$/i.test( u.hostname ) && ! u.port && ! u.username && m ) {
					return normalize( m[ 1 ] );
				}
			} catch ( e ) {
				// Not a link a browser can read.
			}
			return '';
		}
		if ( /rewloy/i.test( raw ) ) {
			// A scanner set to another keyboard layout changes ':' '/' '-' '?': the number is still 4-4-4.
			var g = /(?:^|[^A-Za-z0-9])([A-Za-z0-9]{4})[^A-Za-z0-9]([A-Za-z0-9]{4})[^A-Za-z0-9]([A-Za-z0-9]{4})(?:[^A-Za-z0-9]|$)/.exec( raw.split( /[?,]/ )[ 0 ] );
			return g ? normalize( g[ 1 ] + g[ 2 ] + g[ 3 ] ) : '';
		}
		return normalize( raw );
	}

	function el( tag, text, cls ) {
		var x = document.createElement( tag );
		if ( text !== undefined && text !== null ) {
			x.textContent = String( text );
		}
		if ( cls ) {
			x.className = cls;
		}
		return x;
	}

	function clear( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function say( text, kind, retry ) {
		clear( message );
		message.className = 'rewloy-wc-message' + ( kind ? ' is-' + kind : '' );
		if ( ! text ) {
			return;
		}
		message.appendChild( el( 'p', text ) );
		if ( retry ) {
			message.appendChild( el( 'p', t.retryNote, 'description' ) );
			var b = el( 'button', t.retry, 'button' );
			b.type = 'button';
			b.addEventListener( 'click', retry );
			message.appendChild( b );
		}
	}

	/** One press: the same payload and key on "Try again". */
	function send( payload, done ) {
		if ( busy ) {
			return;
		}
		busy = true;
		var form = new FormData();
		form.append( 'nonce', cfg.nonce );
		Object.keys( payload ).forEach( function ( k ) {
			form.append( k, payload[ k ] );
		} );
		say( payload.action === 'rewloy_wc_till_lookup' ? t.reading : t.sending, '' );
		fetch( cfg.ajax, { method: 'POST', body: form, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				busy = false;
				if ( ! data || typeof data !== 'object' ) {
					throw new Error( 'no answer' );
				}
				if ( data.retry && payload.key ) {
					say( data.message, 'error', function () {
						send( payload, done );
					} );
					return;
				}
				done( data );
			} )
			.catch( function () {
				busy = false;
				// No answer: the press may or may not have been recorded. A lookup is simply asked again.
				say( t.offline, 'error', payload.key ? function () {
					send( payload, done );
				} : null );
			} );
	}

	function row( table, label, value ) {
		var tr = el( 'tr' );
		tr.appendChild( el( 'th', label ) );
		tr.appendChild( el( 'td', value ) );
		table.appendChild( tr );
	}

	function showCard( card ) {
		clear( stateBox );
		clear( actionsBox );
		if ( ! card ) {
			stateBox.hidden = true;
			saleForm.hidden = true;
			actionsBox.hidden = true;
			return;
		}
		serial = card.serial || serial;
		var table = el( 'table', null, 'widefat striped rewloy-wc-facts' );
		var tbody = el( 'tbody' );
		row( tbody, t.card, card.shown || card.serial );
		if ( card.type_label ) {
			row( tbody, t.type, card.type_label );
			row( tbody, t.status, card.status_label );
			row( tbody, card.progress_label, card.progress );
			if ( card.tier ) {
				row( tbody, t.level, card.tier );
			}
			row( tbody, t.reward, card.reward_ready ? t.ready : t.notYet );
		}
		table.appendChild( tbody );
		stateBox.appendChild( table );
		( card.notices || [] ).forEach( function ( n ) {
			stateBox.appendChild( el( 'p', n.text, 'rewloy-wc-notice tone-' + n.tone ) );
		} );
		if ( card.customer_url ) {
			var p = el( 'p' );
			var a = el( 'a', t.customer, 'button' );
			a.href = card.customer_url;
			a.target = '_blank';
			a.rel = 'noopener noreferrer';
			p.appendChild( a );
			stateBox.appendChild( p );
		}
		stateBox.hidden = false;
		if ( ! card.allowed ) {
			saleForm.hidden = true;
			actionsBox.hidden = true;
			return;
		}
		saleWrites.textContent = card.sale || '';
		saleForm.hidden = false;
		actionsBox.appendChild( el( 'h3', t.operations ) );
		if ( ! card.type_label ) {
			actionsBox.appendChild( el( 'p', t.noView, 'description' ) );
		} else {
			var any = false;
			( card.actions || [] ).forEach( function ( op ) {
				any = any || op.ready;
				actionsBox.appendChild( operation( op ) );
			} );
			if ( ! any ) {
				actionsBox.appendChild( el( 'p', t.noneNow, 'description' ) );
			}
		}
		actionsBox.hidden = false;
	}

	function field( label, name ) {
		var wrap = el( 'label' );
		wrap.appendChild( document.createTextNode( label + ' ' ) );
		var i = el( 'input' );
		i.type = 'text';
		i.name = name;
		i.inputMode = name === 'amount' ? 'decimal' : 'numeric';
		i.autocomplete = 'off';
		wrap.appendChild( i );
		return wrap;
	}

	function operation( op ) {
		var box = el( 'div', null, 'rewloy-wc-op' );
		var inputs = {};
		( op.needs || [] ).forEach( function ( need ) {
			var name = need === 'amountMinor' ? 'amount' : need === 'points' ? 'points' : 'reward';
			var label = need === 'amountMinor' ? t.amount : need === 'points' ? t.points : t.rewardNo;
			var f = field( label, name );
			inputs[ name ] = f.querySelector( 'input' );
			box.appendChild( f );
		} );
		var b = el( 'button', op.label, 'button' + ( op.spends ? '' : ' button-secondary' ) );
		b.type = 'button';
		b.disabled = ! op.ready;
		b.addEventListener( 'click', function () {
			if ( op.spends && ! window.confirm( t.confirm ) ) {
				return;
			}
			var payload = { action: 'rewloy_wc_till_action', serial: serial, operation: op.action, key: newKey() };
			Object.keys( inputs ).forEach( function ( k ) {
				payload[ k ] = inputs[ k ].value;
			} );
			send( payload, answered );
		} );
		box.appendChild( b );
		return box;
	}

	function answered( data ) {
		if ( data.card ) {
			showCard( data.card );
		}
		say( data.message, data.ok ? 'ok' : 'error' );
		if ( data.ok ) {
			amount.value = '';
			reference.value = '';
		}
	}

	readForm.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var s = serialOf( input.value );
		// Whatever was scanned, only the number stays in the field: a card link's private key is gone from the page.
		input.value = s;
		if ( ! s ) {
			showCard( null );
			say( t.notCard, 'error' );
			input.focus();
			return;
		}
		serial = s;
		send( { action: 'rewloy_wc_till_lookup', card: s }, function ( data ) {
			showCard( data.ok ? data.card : null );
			say( data.ok ? '' : data.message, data.ok ? '' : 'error' );
			input.select();
		} );
	} );

	saleForm.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		send( { action: 'rewloy_wc_till_sale', serial: serial, amount: amount.value, reference: reference.value, key: newKey() }, answered );
	} );

	input.focus();
} )();
