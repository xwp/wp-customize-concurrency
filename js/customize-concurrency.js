/* exported customizeConcurrency */
/* global _customizeConcurrency, _ , JSON, console */
var customizeConcurrency = ( function( $ ) {
	var self = {
		session_start_timestamp: 0,
		last_update_timestamp: 0,
		current_user_id: 0,
	};
	$.extend( self, _customizeConcurrency );

	/**
	 * Add hidden fields for timestamp and user.
	 *
	 * @return {void}
	 */
	self.addFields = function() {
		var form = $('#customize-controls'), timestampField, userField;

	    timestampField = document.createElement('input');
		timestampField.type = 'hidden';
		timestampField.name = 'customizer_session_timestamp';
		timestampField.value = self.session_start_timestamp;
	    form.appendChild(timestampField);

	    userField = document.createElement('input');
		userField.type = 'hidden';
		userField.name = 'current_user_id';
		userField.value = self.current_user_id;
	    form.appendChild(userField);
	}

	/**
	 * Initialize when Customizer is ready.
	 */
	self.init = function() {
		var self = this;

		self.addFields();
	};

	// Boot.
	wp.customize.bind( 'ready', function() {
		alert('hi');
		self.init();
	} );

	return self;
}( jQuery ) );
