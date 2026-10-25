/**
 * Order screens: open and close the Fraud Risk box's detail dialogs.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var opener = event.target.closest( '[data-wcaf-open]' );

		if ( opener ) {
			var dialog = document.getElementById( opener.getAttribute( 'data-wcaf-open' ) );

			if ( dialog && typeof dialog.showModal === 'function' ) {
				event.preventDefault();
				dialog.showModal();
			}
			return;
		}

		if ( event.target.closest( '[data-wcaf-close]' ) ) {
			var open = event.target.closest( 'dialog' );

			if ( open ) {
				open.close();
			}
			return;
		}

		// A click on the backdrop closes the dialog.
		if ( event.target.tagName === 'DIALOG' && event.target.classList.contains( 'wcaf-dialog' ) ) {
			var rect = event.target.getBoundingClientRect();
			var inside = event.clientX >= rect.left && event.clientX <= rect.right && event.clientY >= rect.top && event.clientY <= rect.bottom;

			if ( ! inside ) {
				event.target.close();
			}
		}
	} );
}() );
