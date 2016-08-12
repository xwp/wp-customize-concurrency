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
					}
				} );

				component.data.session_start_timestamp = data.concurrency_session_timestamp;
			} );

			api.bind( 'error', function( response ) {

				_.each( response.setting_validities, function( validity, settingId ) {
					if ( true !== validity && validity.concurrency_conflict ) {
						var control, section, notification, theirValue;

						theirValue = validity.concurrency_conflict.data.their_value;
						control = api.control( settingId );

						if ( control && control.notifications ) {
							notification = new api.Notification( 'setting_update_conflict', {
								message: component.notificationsTemplate
							} );
							control.notificationsTemplate = wp.template( 'customize-concurrency-notifications' );
							control.renderNotifications;
							// control.notifications.remove( notification.code );
							// control.notifications.add( notification.code, notification );
						}
					}
				} );
			} );

			component.extendPreviewerQuery();

		} );

		wp.customize( 'established_year', function ( setting ) {
			setting.validate = function ( value ) {
				var code, notification;
				var year = parseInt( value, 10 );

				code = 'required';
				if ( isNaN( year ) ) {
					notification = new wp.customize.Notification( code, {message: myPlugin.l10n.requiredYear} );
					setting.notifications.add( code, notification );
				} else {
					setting.notifications.remove( code );
				}

				if ( isNaN( year ) ) {
					return value;
				}

				return value;
			};
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
