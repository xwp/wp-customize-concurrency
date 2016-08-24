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
		api.bind( 'ready', function() {
			_.extend( component.data, _customizeConcurrency );

			api.each( function( setting ) {
				setting.concurrency_timestamp = component.data.session_start_timestamp;
			} );

			api.bind( 'add', function( setting ) {
				setting.concurrency_timestamp = component.data.session_start_timestamp;
			} );

			api.bind( 'saved', function( data ) {
				if ( ! data.concurrency_session_timestamp || ! data.setting_validities ) {
					return;
				}
				_.each( data.setting_validities, function( validity, settingId ) {
					var setting = api( settingId );
					if ( setting && true === validity ) {
						setting.concurrency_timestamp = data.concurrency_session_timestamp;
						setting.concurrency_override = false;
					}
				} );

				component.data.session_start_timestamp = data.concurrency_session_timestamp;
			} );

			api.bind( 'change', function( setting ) {
				var control = api.control( setting.id );
				control.notificationsTemplate = wp.template( 'customize-concurrency-notifications' );
				api.previewer.send( 'customize-concurrency-data', component.getTimestampsAndOverrides() );
			});

			api.bind( 'error', function( response ) {

				_.each( response.setting_validities, function( validity, settingId ) {
					var control, theirValue;

					if ( true !== validity && validity.concurrency_conflict ) {
						theirValue = validity.concurrency_conflict.data.their_value;
						control = api.control( settingId );

						if ( control && control.notifications ) {
							control.notificationsTemplate = wp.template( 'customize-concurrency-notifications' );
							control.renderNotifications();

							control.deferred.embedded.done( function() {
								control.container.on( 'click', '.concurrency-conflict-override', function() {
									control.setting.concurrency_override = true;
									control.notifications.remove( control.setting.id + ':concurrency_conflict' );
									alert( control.setting.id + ':concurrency_conflict' );
								} );
								control.container.on( 'click', '.concurrency-conflict-accept', function() {
									control.setting.set( theirValue );
									control.notifications.remove( control.setting.id + ':concurrency_conflict' );
									alert( control.setting.id + ':concurrency_conflict' );
								} );
							} );
						}
					}
				} );
			} );

			component.extendPreviewerQuery();

		} );
	};

	/**
	 * Send timestamp of last save/read to compare against other sessions.
	 *
	 * @return {void}
	 */
	component.extendPreviewerQuery = function() {
		var originalQuery = api.previewer.query;

		api.previewer.query = function() {
			var retval = originalQuery.apply( this, arguments );
			_.extend( retval, component.getTimestampsAndOverrides() );
			return retval;
		};
	};

	component.getTimestampsAndOverrides = function() {
		var timestamps = {}, overrides = {};

		api.each( function( setting ) {
			if ( setting._dirty ) {
				timestamps[ setting.id ] = setting.concurrency_timestamp;
				if ( setting.concurrency_override ) {
					overrides[ setting.id ] = true;
				}
			}
		} );

		return {
			concurrency_timestamps: JSON.stringify( timestamps ),
			concurrency_overrides: JSON.stringify( overrides )
		};
	};

	component.init();

} )( wp.customize );
