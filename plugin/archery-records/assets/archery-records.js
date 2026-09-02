/*
 * The "+" button on the records tables.
 *
 * Every previous holder is already in the page as a hidden table row, so all
 * this does is show and hide rows that are already there. There is no request
 * to the server and no data held in JavaScript.
 *
 * One listener is attached to the document rather than one per button, so the
 * cost does not grow with the size of the table - some of these pages carry
 * several hundred rows.
 */
( function () {
	'use strict';

	/**
	 * Show or hide the history rows belonging to one toggle button.
	 *
	 * @param {HTMLElement} button The clicked toggle.
	 */
	function toggle( button ) {
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

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ?
			event.target.closest( '.archery-records-toggle' ) :
			null;

		if ( button ) {
			event.preventDefault();
			toggle( button );
		}
	} );
}() );
