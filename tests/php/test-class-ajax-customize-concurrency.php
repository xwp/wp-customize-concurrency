<?php

namespace CustomizeConcurrency;

class Test_Ajax_Customize_Concurrency extends \WP_Ajax_UnitTestCase {

	/**
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * Set up the test fixture.
	 */
	public function setUp() {
		parent::setUp();
		$this->plugin = get_plugin_instance();
		require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
		$GLOBALS['wp_customize'] = new \WP_Customize_Manager();
		$this->plugin->customize_concurrency->customize_manager = $GLOBALS['wp_customize'];
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['wp_customize'] = 'on';
	}

	function tearDown() {
		$this->plugin->customize_concurrency->customize_manager = null;
		unset( $GLOBALS['wp_customize'] );
		unset( $GLOBALS['wp_scripts'] );
		unset( $_REQUEST['wp_customize'] );
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tearDown();
	}

	/**
	 * Helper to keep it DRY
	 *
	 * @param string $action Action.
	 */
	protected function make_ajax_call( $action ) {
		// Make the request.
		try {
			$this->_handleAjax( $action );
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		}
	}

	/**
	 * @see Customize_Concurrency::ajax_customize_settings_previewed()
	 */
	function test_ajax_customize_settings_previewed_bad_nonce() {
		$_POST = array(
			'action' => Customize_Concurrency::AJAX_ACTION,
			'nonce' => 'bad-nonce-12345',
		);

		$this->make_ajax_call( Customize_Concurrency::AJAX_ACTION );

		// Get the results.
		$response = json_decode( $this->_last_response, true );
		$expected_results = array(
			'success' => false,
			'data'    => array(
				'code'    => 403,
        'message' => 'bad_nonce',
			),
		);

		$this->assertSame( $expected_results, $response );
	}

	/**
	 * @see Customize_Concurrency::ajax_customize_settings_previewed()
	 */
	function test_ajax_customize_settings_previewed_bad_method() {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST = array(
			'action' => Customize_Concurrency::AJAX_ACTION,
			'nonce' => wp_create_nonce( 'preview-customize_' . $this->plugin->customize_concurrency->customize_manager->get_stylesheet() ),
		);

		$this->make_ajax_call( Customize_Concurrency::AJAX_ACTION );

		// Get the results.
		$response = json_decode( $this->_last_response, true );
		$expected_results = array(
			'success' => false,
			'data'    => array(
				'code'    => 405,
        'message' => 'bad_method',
			),
		);

		$this->assertSame( $expected_results, $response );
	}

	/**
	 * @see Customize_Concurrency::ajax_customize_settings_previewed()
	 */
	function test_ajax_customize_settings_previewed() {
		$this->plugin->customize_concurrency->customize_manager->add_setting( 'foo' );
		$this->plugin->customize_concurrency->customize_manager->set_post_value( 'foo', 'baz' );

		$_POST = array(
			'action' => Customize_Concurrency::AJAX_ACTION,
			'nonce' => wp_create_nonce( 'preview-customize_' . $this->plugin->customize_concurrency->customize_manager->get_stylesheet() ),
			'last_update_timestamp_cursor' => time(),
		);

		$this->make_ajax_call( Customize_Concurrency::AJAX_ACTION );

		// Get the results.
		$response = json_decode( $this->_last_response, true );
		$this->assertArrayHasKey( 'previewed_settings', $response['data'] );
		$this->assertArrayHasKey( 'post_id', $response['data']['previewed_settings']['foo'] );
		$this->assertArrayHasKey( 'revision_number', $response['data']['previewed_settings']['foo'] );
		$this->assertArrayHasKey( 'previous_previewer', $response['data']['previewed_settings']['foo'] );
		$this->assertArrayHasKey( 'previous_preview_timestamp', $response['data']['previewed_settings']['foo'] );
		$this->assertEquals( 1, $response['data']['previewed_settings']['foo']['revision_number'] );
		$this->assertNull( $response['data']['previewed_settings']['foo']['previous_previewer'] );
		$this->assertNull( $response['data']['previewed_settings']['foo']['previous_preview_timestamp'] );
		wp_delete_post( $response['data']['previewed_settings']['foo']['post_id'] );
	}
}