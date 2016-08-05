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
			component.data['settingModifiedTimes'] = {};

			api.each( function( setting ) {
				component.data.settingModifiedTimes[ setting.id ] = new Date().valueOf();
			} );

			api.bind( 'add', function( setting ) {
				component.data['settingModifiedTimes'][ setting.id ] = new Date().valueOf();
			} );

			api.bind( 'change', function( setting ) {
				component.data['settingModifiedTimes'][ setting.id ] = new Date().valueOf();
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
					retval.concurrency_setting_modified_timestamps = {};
					api.each( function( setting ) {
						if ( setting._dirty && component.data.settingModifiedTimes[ setting.id ] ) {
							retval.concurrency_setting_modified_timestamps[ setting.id ] = component.data.settingModifiedTimes[ setting.id ];
						}
					} );

			retval.session_start_timestamp = _customizeConcurrency.session_start_timestamp;
			retval.current_user_id = _customizeConcurrency.current_user_id;
			return retval;
		};
	};

	component.init();

} )( wp.customize, jQuery );
