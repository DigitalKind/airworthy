/**
 * Airworthy's dashboard widget: "Don't show again" on its one line about Airworthy Pro.
 * Saves the same per-user choice as the results card, then removes the line.
 *
 * @package Airworthy
 */

( function () {
	'use strict';

	var apiFetch = window.wp.apiFetch;
	var speak    = window.wp.a11y.speak;
	var __       = window.wp.i18n.__;

	document.addEventListener(
		'click',
		function ( event ) {
			var button = event.target.closest ? event.target.closest( '.airworthy-widget-hide' ) : null;
			if ( ! button ) {
				return;
			}
			var line = button.closest( '.airworthy-widget-pro' );
			if ( line && line.parentNode ) {
				line.parentNode.removeChild( line );
			}
			apiFetch( { path: '/airworthy/v1/pro-card', method: 'POST', data: { choice: 'never' } } ).catch( function () {} );
			speak( __( 'Airworthy won\'t show this again.', 'airworthy' ) );
		}
	);
}() );
