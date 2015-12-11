/*global _customizeConcurrency, _ , JSON*/
var customizeConcurrency = ( function( $ ) {
	var self = {
		action: '',
		send_settings_delay: 200,
		session_start_timestamp: 0,
		last_update_timestamp_cursor: 0,
		current_user_id: 0,
		current_send_settings_previewed_timeout_id: -1,
		recently_previewed_settings_data: {},
		lock_window_seconds: 0,
		previewedSettingsPendingSend: {},
		recentlyPreviewedSettings: new wp.customize.Values()
	};
	$.extend( self, _customizeConcurrency );

	wp.customize.bind( 'save', function() {
		self.previewedSettingsPendingSend = {};
		clearTimeout( self.current_send_settings_previewed_timeout_id );
	} );

	/**
	 * Update a recently previewed setting.
	 *
	 * @param {string} settingId
	 * @param {object} args
	 * @param {int} args.post_date
	 * @param {int} args.post_id
	 * @param {string} args.post_status
	 * @param {string} [args.status] - Status for previewing a setting.
	 * @param {object} args.post_author
	 * @param {*} args.value
	 */
	self.updateRecentlyPreviewedSetting = function( settingId, args ) {
		var self = this, previewedSetting;
		if ( ! args.post_status ) {
			throw new Error( 'expected post_status' );
		}
		if ( ! args.post_author ) {
			throw new Error( 'expected post_author' );
		}
		if ( ! args.post_date ) {
			throw new Error( 'expected post_date' );
		}
		if ( ! args.post_id ) {
			throw new Error( 'expected post_id' );
		}
		if ( 'undefined' === typeof args.value ) {
			throw new Error( 'expected value' );
		}

		previewedSetting = self.recentlyPreviewedSettings( settingId );
		if ( previewedSetting ) {
			clearTimeout( previewedSetting.timeoutId );
			previewedSetting.set( args );
		} else {
			previewedSetting = self.recentlyPreviewedSettings.create( settingId, args, {
				id: settingId
			} );
		}
		previewedSetting.timeoutId = setTimeout(
			function() {
				self.recentlyPreviewedSettings.remove( settingId );
			},
			self.lock_window_seconds * 1000
		);
	};

	/**
	 * Send all currently-previewed (dirty non-locked) settings to be checked for
	 * conflicts with other users. If someone has modified a setting first, it
	 * will be rejected and the setting (and its control) will become locked.
	 * Otherwise, the previewed setting will be accepted and will be pushed out
	 * to other users.
	 *
	 * @see customizeConcurrency.doneSendingSettingsPreviewed()
	 */
	self.sendSettingsPreviewed = function() {
		var self = this;

		clearTimeout( self.current_send_settings_previewed_timeout_id );

		self.current_send_settings_previewed_timeout_id = setTimeout( function() {
			var request, customized;
			if ( _.isEmpty( self.previewedSettingsPendingSend ) ) {
				return;
			}
			customized = self.previewedSettingsPendingSend;
			self.previewedSettingsPendingSend = {};

			request = wp.ajax.post( self.action, {
				wp_customize: 'on',
				nonce: wp.customize.settings.nonce.preview,
				customized: JSON.stringify( customized ),
				theme: wp.customize.settings.theme.stylesheet,
				last_update_timestamp_cursor: self.last_update_timestamp_cursor
			} );

			request.done( _.bind( self.doneSendingSettingsPreviewed, self ) );

			// @todo should revision_number be included here?
			// @todo handle request.fail()
		}, self.send_settings_delay );
	};

	/**
	 * Handle successful Ajax response for sending the previewed settings.
	 *
	 * @param {object} data
	 * @param {object} data.previewed_settings
	 * @see customizerConcurrency.sendSettingsPreviewed()
	 */
	self.doneSendingSettingsPreviewed = function( data ) {
		var self = this;

		_.each( data.previewed_settings, function( previewedSetting, settingId ) {
			var setting;
			/**
			 * @type {object} previewedSetting
			 * @type {string} previewedSetting.status
			 * @type {(int|null)} previewedSetting.post_date
			 * @type {(string|null)} previewedSetting.post_status
			 * @type {(object|null)} previewedSetting.post_author
			 * @type {(object|null)} previewedSetting.post_author.avatar
			 * @type {string} previewedSetting.post_author.display_name
			 * @type {int} previewedSetting.post_author.user_id
			 * @type {*} previewedSetting.value
			 */

			/*
			 *
			 */
			if ( 'accepted' === previewedSetting.status ) {
				return;
			}

			/*
			 * Just in case the setting was deleted during the request,
			 * make sure it still exists.
			 */
			if ( ! wp.customize.has( settingId ) ) {
				return;
			}
			setting = wp.customize( settingId );

			/*
			 * If the previously previewed setting value is the same as the setting's
			 * initial value, there is no conflict, as then it would seem that the
			 * previewed setting had been saved.
			 */
			if ( 'undefined' !== typeof wp.customize.settings.settings[ settingId ] && _.isEqual( previewedSetting.value, wp.customize.settings.settings[ settingId ] ) ) {
				return;
			}

			/*
			 * If the previously previewed setting value is the same as the
			 * setting's current value, there is no conflict.
			 */
			if ( _.isEqual( setting.get(), previewedSetting.value ) ) {
				return;
			}

			/*
			 * Revert the setting.
			 */
			if ( 'rejected' === previewedSetting.status ) {
				self.updateRecentlyPreviewedSetting( settingId, previewedSetting );
			}
		} );

		// @todo we could also use this as an opportunity to grab the previewed settings since the last_update_timestamp_cursor, then store the data.next_update_timestamp_cursor
	};

	/**
	 * Locking-aware override for wp.customize.previewer.query().
	 */
	self.prepareQueryData = function() {
		var dirtyCustomized = {};
		wp.customize.each( function( value, key ) {
			if ( value._dirty && ! value.concurrencyLocked() ) {
				dirtyCustomized[ key ] = value();
			}
		} );

		return {
			wp_customize: 'on',
			theme: wp.customize.settings.theme.stylesheet,
			customized: JSON.stringify( dirtyCustomized ),
			nonce: this.nonce.preview
		};
	};

	/**
	 * Update the UI to indicate which other users are currently managing things in the Customizer.
	 */
	self.updateConcurrentUserPresence = _.debounce( function() {
		var container = $( '#concurrent-users' ),
			currentUsers = {},
			settingsChangedByUser = {},
			addUserToCurrentUsersDisplay;

		self.recentlyPreviewedSettings.each( function( previewedSetting ) {
			var author = previewedSetting.get().post_author;
			currentUsers[ author.user_id ] = author;
			if ( ! settingsChangedByUser[ author.user_id ] ) {
				settingsChangedByUser[ author.user_id ] = [];
			}
			settingsChangedByUser[ author.user_id ].push( previewedSetting.id );
		} );
		container.empty();

		/**
		 * Add a user to the display.
		 *
		 * @param {object} user
		 * @param {string} user.display_name
		 * @param {int} user.user_id
		 * @param {object} user.avatar
		 */
		addUserToCurrentUsersDisplay = function( user ) {
			var img = $( '<img>', {
				src: user.avatar.url,
				title: self.l10n.concurrentUserTooltip.replace( '%1$s', user.display_name ).replace( '%2$s', settingsChangedByUser[ user.user_id ].join( ', ' ) ),
				alt: user.display_name
			} );
			container.append( img );
		};
		_.each( currentUsers, addUserToCurrentUsersDisplay );
	} );

	/**
	 * Update the state of a setting that was updated by another user.
	 *
	 * @param {object} previewedSetting
	 */
	self.updatePreviewedSettingLockedState = function( previewedSetting ) {
		var data = previewedSetting.get(),
			setting = wp.customize( previewedSetting.id ),
			wasSaved = wp.customize.state( 'saved' ).get();

		event = jQuery.Event( 'customize-concurrency-setting-locked' );
		$( document ).trigger( event, [ previewedSetting ] );

		if ( event.isDefaultPrevented() ) {
			return;
		}

		if ( ! setting ) {
			console.warn( 'updatePreviewedSettingLockedState: Setting does not exist: ' + previewedSetting.id );
			return;
		}

		if ( 'draft' === data.post_status || 'rejected' === data.status ) {
			setting.concurrencyLocked( true );
		} else if ( 'publish' === data.post_status && ! setting.concurrencyLocked() ) {
			setting.concurrencyLocked( false );
		}

		/*
		 * Remove a locked setting so other users do not get notified of a change. This fixes
		 * a deficiency with widget sidebars where one user adds a widget and another deletes
		 * one and the setting that was added becomes locked to all users.
		 */
		if ( setting.concurrencyLocked() && 'undefined' !== typeof self.previewedSettingsPendingSend[ setting.id ] ) {
			delete self.previewedSettingsPendingSend[ setting.id ];
		}

		setting.set( data.value );
		if ( 'publish' === data.post_status ) {
			setting._dirty = false;
		}
		wp.customize.state( 'saved' ).set( wasSaved );
	};

	/**
	 * Unlock a setting.
	 *
	 * This is used, for example, when a previewed setting is removed.
	 *
	 * @param {object} previewedSetting
	 * @param {int} previewedSetting.id
	 */
	self.unlockPreviewedSetting = function( previewedSetting ) {
		var setting, event;
		if ( wp.customize.has( previewedSetting.id ) ) {
			setting = wp.customize( previewedSetting.id );

			event = jQuery.Event( 'customize-concurrency-setting-unlock' );
			$( document ).trigger( event, [ setting ] );

			if ( event.isDefaultPrevented() ) {
				return;
			}

			// Set the previewed value back to the saved value.
			if ( 'undefined' !== typeof wp.customize.settings.settings[ previewedSetting.id ] ) {
				setting.set( wp.customize.settings.settings[ previewedSetting.id ].value );
			}

			setting._dirty = false;
			setting.concurrencyLocked( false );

			// @todo Should we refresh the preview, the setting may have been removed?
			switch ( setting.transport ) {
				case 'refresh':
					return wp.customize.previewer.refresh();
				case 'postMessage':
					return wp.customize.previewer.send( 'setting', [ setting.id, wp.customize() ] );
			}
		}
	};

	/**
	 * Send heartbeat data.
	 *
	 * @param {jQuery.Event} e
	 * @param {object} data
	 */
	self.sendHeartbeat = function( e, data ) {
		var self = this;
		data.customize_concurrency = {
			last_update_timestamp_cursor: self.last_update_timestamp_cursor
		};
		self.last_update_timestamp_cursor = Math.floor( ( new Date() ).getTime() / 1000 );
	};

	/**
	 * Receive heartbeat data.
	 *
	 * @param {jQuery.Event} e
	 * @param {object} data
	 */
	self.tickHeartbeat = function( e, data ) {
		var self = this;
		if ( ! data.customize_concurrency ) {
			return;
		}

		if ( data.customize_concurrency.next_update_timestamp_cursor ) {
			self.last_update_timestamp_cursor = data.customize_concurrency.next_update_timestamp_cursor;
		}

		_.each( data.customize_concurrency.setting_updates, function( settingUpdate, id ) {
			var setting;

			event = jQuery.Event( 'customize-concurrency-setting-update' );
			$( document ).trigger( event, [ id, settingUpdate ] );

			if ( event.isDefaultPrevented() ) {
				self.updateRecentlyPreviewedSetting( id, settingUpdate ); // @todo Not sure about this.
				return;
			}

			if ( ! wp.customize.has( id ) ) {
				wp.customize.create( id, id, settingUpdate.value, {
					transport: settingUpdate.transport,
					previewer: wp.customize.previewer
				} );

				// @todo If this is a widget, we should create the control to go with it?
			}

			self.updateRecentlyPreviewedSetting( id, settingUpdate );
		} );
	};

	// Inject the concurrencyLocked value for each setting created.
	self.previousSettingInitialize = wp.customize.Setting.prototype.initialize;
	wp.customize.Setting.prototype.initialize = function( id, value, options ) {
		var setting = this;
		setting.concurrencyLocked = new wp.customize.Value( false );
		self.previousSettingInitialize.call( setting, id, value, options );
	};

	// @todo update locked state when the setting gets added?

	// Link a control with its settings' locked states.
	wp.customize.control.bind( 'add', function( control ) {
		control.concurrencySettingLockedCount = new wp.customize.Value( 0 );
		control.deferred.embedded.done( function() {
			_.each( control.settings, function( setting ) {
				setting.concurrencyLocked.bind( function( nowLocked, wasLocked ) {
					if ( nowLocked === wasLocked ) {
						return;
					}
					if ( nowLocked ) {
						control.concurrencySettingLockedCount.set( control.concurrencySettingLockedCount.get() + 1 );
					} else if ( ! nowLocked ) {
						control.concurrencySettingLockedCount.set( control.concurrencySettingLockedCount.get() - 1 );
					}
				} );
			} );
		} );

		control.concurrencySettingLockedCount.bind( function( lockedCount ) {

			// @todo Introduce UI for taking over locked controls.
			// @todo Show the user who currently has the setting(s) locked.

			control.container.toggleClass( 'concurrency-locked', !! lockedCount );
		} );
	} );

	/**
	 * Initialize when Customizer is ready.
	 */
	self.init = function() {
		var self = this;

		$( '#customize-footer-actions' ).append( '<div id="concurrent-users"></div>' );

		// @todo we do want the locked dirty settings to be included if it's not a customize_save event
		// @todo so we may want to hook into the jQuery Ajax beforeSend event
		wp.customize.previewer.query = self.prepareQueryData;

		self.recentlyPreviewedSettings.bind( 'add',    self.updateConcurrentUserPresence );
		self.recentlyPreviewedSettings.bind( 'change', self.updateConcurrentUserPresence );
		self.recentlyPreviewedSettings.bind( 'remove', self.updateConcurrentUserPresence );

		self.recentlyPreviewedSettings.bind( 'add',    self.updatePreviewedSettingLockedState );
		self.recentlyPreviewedSettings.bind( 'change', self.updatePreviewedSettingLockedState );
		self.recentlyPreviewedSettings.bind( 'remove', self.unlockPreviewedSetting );

		wp.customize.bind( 'change', function( setting ) {
			var event;

			if ( setting.concurrencyLocked() || ! setting._dirty ) {
				return;
			}

			event = jQuery.Event( 'customize-concurrency-setting-change' );
			$( document ).trigger( event, [ setting ] );

			if ( event.isDefaultPrevented() ) {
				return;
			}

			self.previewedSettingsPendingSend[ setting.id ] = setting();
			self.sendSettingsPreviewed();
		} );

		// Add heartbeat event listeners.
		$( document ).on( 'heartbeat-send', function( e, data ) {
			self.sendHeartbeat( e, data );
		} );
		$( document ).on( 'heartbeat-tick', function( e, data ) {
			self.tickHeartbeat( e, data );
		} );

		// @todo First make sure that contextual settings dirty states are accounted for.
		self.recentlyPreviewedSettings.each( function( previewedSetting ) {
			self.updatePreviewedSettingLockedState( previewedSetting );
		} );

		self.updateConcurrentUserPresence();
	};

	_.each( self.recently_previewed_settings_data, function( previewedSetting, settingId ) {
		self.updateRecentlyPreviewedSetting( settingId, previewedSetting );
	} );
	delete self.recently_previewed_settings_data;

	// Boot.
	wp.customize.bind( 'ready', function() {
		self.init();
	} );

	return self;
}( jQuery ) );
