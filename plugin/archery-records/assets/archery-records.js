/*
 * Two small pieces of behaviour on the records pages.
 *
 * The class tabs: every class is already in the page as its own panel, so switching
 * tabs shows one and hides the others. Nothing is fetched.
 *
 * The "+" buttons: every previous holder is already in the page as a hidden table row,
 * so the button shows and hides rows that are already there.
 *
 * Neither needs a request to the server, and both are attached with one listener on the
 * document rather than one per control, because some of these pages carry several
 * hundred rows.
 *
 * If this file fails to load, the page still works: every class shows one after another
 * with a heading each, and the previous holders show expanded.
 */
( function () {
	'use strict';

	/**
	 * Show or hide the history rows belonging to one toggle button.
	 *
	 * @param {HTMLElement} button The clicked toggle.
	 */
	function toggleHistory( button ) {
		var expanded = button.getAttribute( 'aria-expanded' ) === 'true';
		var controls = ( button.getAttribute( 'aria-controls' ) || '' ).split( /\s+/ );

		controls.forEach( function ( id ) {
			if ( ! id ) {
				return;
			}
			var row = document.getElementById( id );
			if ( row ) {
				row.hidden = expanded;
			}
		} );

		button.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
	}

	/**
	 * The tabs belonging to the same strip as the given one.
	 *
	 * @param {HTMLElement} tab A tab button.
	 * @return {Array} Its siblings, in document order.
	 */
	function tabsIn( tab ) {
		var strip = tab.closest( '.archery-records-tabs' );
		return strip ? Array.prototype.slice.call( strip.querySelectorAll( '.archery-records-tab' ) ) : [];
	}

	/**
	 * Select one tab and show its panel, hiding the rest of the strip.
	 *
	 * @param {HTMLElement} tab   The tab to select.
	 * @param {boolean}     focus Whether to move keyboard focus to it.
	 */
	function selectTab( tab, focus ) {
		tabsIn( tab ).forEach( function ( other ) {
			var selected = other === tab;
			var panel = document.getElementById( other.getAttribute( 'aria-controls' ) );

			other.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
			other.setAttribute( 'tabindex', selected ? '0' : '-1' );

			if ( panel ) {
				panel.hidden = ! selected;
			}
		} );

		if ( focus ) {
			tab.focus();
		}
	}

	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest ) {
			return;
		}

		var toggle = event.target.closest( '.archery-records-toggle' );
		if ( toggle ) {
			event.preventDefault();
			toggleHistory( toggle );
			return;
		}

		var tab = event.target.closest( '.archery-records-tab' );
		if ( tab ) {
			event.preventDefault();
			selectTab( tab, false );
		}
	} );

	// Left and right move between tabs, Home and End jump to the ends. This is what a
	// screen-reader user expects of a tab strip, and it costs very little.
	document.addEventListener( 'keydown', function ( event ) {
		if ( ! event.target.closest ) {
			return;
		}

		var tab = event.target.closest( '.archery-records-tab' );
		if ( ! tab ) {
			return;
		}

		var tabs = tabsIn( tab );
		var index = tabs.indexOf( tab );
		var next = null;

		if ( 'ArrowRight' === event.key || 'ArrowDown' === event.key ) {
			next = tabs[ ( index + 1 ) % tabs.length ];
		} else if ( 'ArrowLeft' === event.key || 'ArrowUp' === event.key ) {
			next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
		} else if ( 'Home' === event.key ) {
			next = tabs[ 0 ];
		} else if ( 'End' === event.key ) {
			next = tabs[ tabs.length - 1 ];
		}

		if ( next ) {
			event.preventDefault();
			selectTab( next, true );
		}
	} );

	// Marks the page as enhanced, which hides the per-class headings that only exist
	// for the no-JavaScript case.
	function markEnhanced() {
		var pages = document.querySelectorAll( '.archery-records-page' );
		Array.prototype.forEach.call( pages, function ( page ) {
			page.classList.add( 'is-tabbed' );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', markEnhanced );
	} else {
		markEnhanced();
	}
}() );
