/* global jQuery, _customizeConcurrency */
/* eslint-disable no-extra-parens */

( function( api ) {
	'use strict';

	var component;

	if ( ! api.Concurrency ) {
		api.Concurrency = {};
	}

	component = api.Concurrency;

	component.data = {};

	/**
	 * Inject the functionality.
	 *
	 * @return {void}
	 */
	component.init = function() {
		api.bind( 'ready', function() {
			api.each( function( setting ) {
				component.data[ setting.id ] = new Date().valueOf();
			} );

			api.bind( 'add', function( setting ) {
				component.data[ setting.id ] = new Date().valueOf();
//				component.data['function'][ setting.id ] = 'add';
			} );

			api.bind( 'change', function( setting ) {
				component.data[ setting.id ] = new Date().valueOf();
//				component.data['function'][ setting.id ] = 'change';
			} );

			component.extendPreviewerQuery();

		} );

	};

	/**
	 * Amend the preview query so we can update the concurrency posts during `customize_save`.
	 *
	 * @return {void}
	 */
	component.extendPreviewerQuery = function() {
		var originalQuery = api.previewer.query;

		api.previewer.query = function() {
			var retval = originalQuery.apply( this, arguments );
			retval.concurrency_timestamps = {};

			api.each( function( setting ) {
				if ( setting._dirty && component.data[ setting.id ] ) {
					retval.concurrency_timestamps[ setting.id ] = component.data[ setting.id ];
				}
			} );
			return retval;
		};
	};

	component.init();

} )( wp.customize );
