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
				setting['concurrency_timestamp'] = _customizeConcurrency.session_start_timestamp;
			} );

			api.bind( 'add', function( setting ) {
				// todo get the timestamp into the ajax response when additional settings are loaded so that we can use it here
				setting['concurrency_timestamp'] = _customizeConcurrency.session_start_timestamp;
			} );

			api.bind( 'saved', function( data ) {
				wp.customize.each( function( setting ) {
					if ( data.saved_post_setting_values.hasOwnProperty( setting.ID ) ) {
						setting['concurrency_timestamp'] = data.concurrency_session_timestamp;
					}
				} );
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
			var retval = originalQuery.apply( this, arguments ), timestamps = {};

			api.each( function( setting ) {
				if ( setting._dirty ) {
					timestamps[ setting.id ] = setting.concurrency_timestamp;
				}
			} );
			retval.concurrency_timestamps = JSON.stringify( timestamps );

			return retval;
		};
	};

	component.init();

} )( wp.customize );
