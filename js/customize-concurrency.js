/* global jQuery, _customizeConcurrency */
/* eslint-disable no-extra-parens */

( function( api, $ ) {
	'use strict';

	var component;

	if ( ! api.Concurrency ) {
		api.Concurrency = {};
	}

	component = api.Concurrency;

	component.data = {};

	if ( 'undefined' !== typeof _customizeConcurrency ) {
		_.extend( component.data, _customizeConcurrency );
	}

	/**
	 * Inject the functionality.
	 *
	 * @return {void}
	 */
	component.init = function() {
		api.bind( 'ready', function() {
			api.bind( 'change', function() {
				// todo: update concurrency data
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
			// todo: move _customizeConcurrency and all updates into component.data so we can use that instead of _customizeConcurrency
			retval.saved_settings = _customizeConcurrency.saved_settings;
			retval.session_start_timestamp = _customizeConcurrency.session_start_timestamp;
			retval.current_user_id = _customizeConcurrency.current_user_id;
			return retval;
		};
	};

	component.init();

} )( wp.customize, jQuery );