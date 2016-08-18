/* global wp, _, _customizeConcurrency, JSON */
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
		api.bind( 'preview-ready', function() {
			api.preview.bind('customize-concurrency-data', function( data ){
				component.data.timestamps = data.concurrency_timestamps;
				component.data.overrides = data.concurrency_overrides;
			});
		});
		component.extendCustomizeQuery();
	};

	/**
	 * Attempt to override the other query
	 *
	 * @return {void}
	 */
	component.extendCustomizeQuery = function() {
		var originalQuery;

		if ( undefined === api.selectiveRefresh ) {
			return;
		}

		originalQuery = api.selectiveRefresh.getCustomizeQuery;

		api.selectiveRefresh.getCustomizeQuery = function() {
			var retval = originalQuery.apply( this, arguments );

			retval.concurrency_timestamps = component.data.timestamps;
			retval.concurrency_overrides = component.data.overrides;

			return retval;
		};

	};

	component.init();

} )( wp.customize );
