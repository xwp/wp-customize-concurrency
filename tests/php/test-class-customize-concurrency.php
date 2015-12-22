<?php

namespace CustomizeConcurrency;

class Test_Customize_Concurrency extends \WP_UnitTestCase {

	/**
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * @var int
	 */
	protected $css_concat_init_priority;

	/**
	 * @var int
	 */
	protected $js_concat_init_priority;

	/**
	 * @var int
	 */
	public $user_id;

	function setUp() {
		parent::setUp();
		$this->plugin = get_plugin_instance();
		require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
		$GLOBALS['wp_customize'] = new \WP_Customize_Manager();
		$this->plugin->customize_concurrency->customize_manager = $GLOBALS['wp_customize'];
		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		remove_action( 'after_setup_theme', 'twentyfifteen_setup' );

		// For why these hooks have to be removed, see https://github.com/Automattic/nginx-http-concat/issues/5
		$this->css_concat_init_priority = has_action( 'init', 'css_concat_init' );
		if ( $this->css_concat_init_priority ) {
			remove_action( 'init', 'css_concat_init', $this->css_concat_init_priority );
		}
		$this->js_concat_init_priority = has_action( 'init', 'js_concat_init' );
		if ( $this->js_concat_init_priority ) {
			remove_action( 'init', 'js_concat_init', $this->js_concat_init_priority );
		}
	}

	function tearDown() {
		$this->plugin->customize_concurrency->customize_manager = null;
		unset( $GLOBALS['wp_customize'] );
		unset( $GLOBALS['wp_scripts'] );

		if ( $this->css_concat_init_priority ) {
			add_action( 'init', 'css_concat_init', $this->css_concat_init_priority );
		}
		if ( $this->js_concat_init_priority ) {
			add_action( 'init', 'js_concat_init', $this->js_concat_init_priority );
		}
		parent::tearDown();
	}

	/**
	 * Create a concurrency post for testing certain use cases.
	 */
	function create_concurrency_post( $setting = '', $content = '', $set_user = false ) {
		if ( $set_user ) {
			wp_set_current_user( $this->user_id );
		}
		$post_data = array(
			'post_type' => Customize_Concurrency::POST_TYPE,
			'post_status' => 'publish',
			'post_name' => $setting,
			'post_content_filtered' => $content,
			'post_date' => current_time( 'mysql', 0 ),
			'post_date_gmt' => current_time( 'mysql', 1 ),
			'post_author' =>  $this->user_id,
		);
		return wp_insert_post( $post_data );
	}

	/**
	 * @see Customize_Concurrency::__construct()
	 */
	function test_construct() {
		$instance = new Customize_Concurrency( $this->plugin );
		$this->assertEquals( 10, has_action( 'init', array( $instance, 'register_post_type' ) ) );
		$this->assertEquals( 10, has_action( 'customize_controls_enqueue_scripts', array( $instance, 'customize_controls_enqueue_scripts' ) ) );
		$this->assertEquals( 10, has_action( 'wp_ajax_' . Customize_Concurrency::AJAX_ACTION, array( $instance, 'ajax_customize_settings_previewed' ) ) );
	}

	/**
	 * @see Customize_Concurrency::default_config()
	 */
	function test_default_config() {
		$config = Customize_Concurrency::default_config();
		$this->assertArrayHasKey( 'send_settings_delay', $config );
		$this->assertArrayHasKey( 'capability', $config );
		$this->assertArrayHasKey( 'heartbeat_interval', $config );
		$this->assertArrayHasKey( 'lock_window_seconds', $config );
	}

	/**
	 * @see Customize_Concurrency::config()
	 */
	function test_config() {
		$instance = new Customize_Concurrency( $this->plugin );
		$this->assertEquals( 2000, $instance->config( 'send_settings_delay' ) );
		$this->assertEquals( 'customize', $instance->config( 'capability' ) );
		$this->assertEquals( 5, $instance->config( 'heartbeat_interval' ) );
		$this->assertEquals( 150, $instance->config( 'lock_window_seconds' ) );
		$this->assertInternalType( 'array', $instance->config() );
		$this->assertNull( $instance->config( 'foo' ) );
	}

	/**
	 * @see Customize_Concurrency::register_post_type()
	 */
	function test_register_post_type() {
		$instance = new Customize_Concurrency( $this->plugin );
		do_action( 'init' );
		unset( $instance );

		$post_type_obj = get_post_type_object( Customize_Concurrency::POST_TYPE );
		$this->assertNotEmpty( $post_type_obj );
	}

	/**
	 * @see Customize_Concurrency::customize_controls_enqueue_scripts()
	 */
	function test_customize_controls_enqueue_scripts() {
		$this->plugin->register_scripts( wp_scripts() );
		$this->plugin->register_styles( wp_styles() );
		$instance = new Customize_Concurrency( $this->plugin );
		$instance->customize_controls_enqueue_scripts();
		$this->assertTrue( wp_script_is( $this->plugin->slug, 'enqueued' ) );
		$this->assertTrue( wp_style_is( $this->plugin->slug, 'enqueued' ) );
		$this->assertEquals( 1, has_action( 'customize_controls_print_footer_scripts', array( $instance, 'export_js_data' ) ) );
	}

	/**
	 * @see Customize_Concurrency::export_js_data()
	 */
	function test_export_js_data() {
		$instance = new Customize_Concurrency( $this->plugin );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = $this->factory->post->create( array(
			'post_type' => Customize_Concurrency::POST_TYPE,
			'post_title' => 'foo',
			'post_status' => 'draft',
			'post_name' => 'foo',
			'post_content_filtered' => wp_json_encode( 'bar' ),
			'post_date' => current_time( 'mysql', 0 ),
			'post_date_gmt' => current_time( 'mysql', 1 ),
			'post_author' => get_current_user_id(),
		) );

		ob_start();
		$instance->export_js_data();
		$buffer = ob_get_clean();
		$this->assertNotContains( 'foo', $buffer );

		ob_start();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$instance->export_js_data();
		$buffer = ob_get_clean();
		$this->assertContains( "\"post_id\":$post_id,", $buffer );
		$this->assertContains( 'foo', $buffer );
	}

	/**
	 * @see Customize_Concurrency::filter_heartbeat_settings()
	 */
	function test_filter_heartbeat_settings() {
		global $pagenow;
		$tmp = $pagenow;

		$instance = new Customize_Concurrency( $this->plugin );
		$settings = $instance->filter_heartbeat_settings( array() );
		$this->assertEmpty( $settings );

		$pagenow = 'customize.php';
		$settings = $instance->filter_heartbeat_settings( array() );
		$this->assertNotEmpty( $settings );
		$this->assertArrayHasKey( 'screenId', $settings );
		$this->assertArrayHasKey( 'interval', $settings );

		$pagenow = $tmp;
	}

	/**
	 * @see Customize_Concurrency::filter_heartbeat_received()
	 */
	function test_filter_heartbeat_received() {
		$instance = new Customize_Concurrency( $this->plugin );

		$post_id_1 = $this->create_concurrency_post( 'hello-1', wp_json_encode( 'world' ) );
		$post_id_2 = $this->create_concurrency_post( 'hello-2', wp_json_encode( 'people' ) );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$response = $instance->filter_heartbeat_received( array(), array(
			'customize_concurrency' => array(
				'last_update_timestamp_cursor' => time() - 10,
			),
		), 'customize' );
		$this->assertArrayHasKey( 'hello-1', $response['customize_concurrency']['setting_updates'] );
		$this->assertArrayHasKey( 'hello-2', $response['customize_concurrency']['setting_updates'] );
	}

	/**
	 * @see Customize_Concurrency::filter_heartbeat_received()
	 */
	function test_filter_heartbeat_received_fail() {
		$instance = new Customize_Concurrency( $this->plugin );
		$instance->customize_manager = null;

		$response = $instance->filter_heartbeat_received( array(), array(
			'customize_concurrency' => array(
				'last_update_timestamp_cursor' => time() - $instance->config( 'lock_window_seconds' ),
			),
		), 'customize' );
		$this->assertEmpty( $response );

		$post_id = $this->create_concurrency_post( 'hello-3', 'world', true );
		$response = $instance->filter_heartbeat_received( array(), array(
			'customize_concurrency' => array(
				'last_update_timestamp_cursor' => time(),
			),
		), 'customize' );
		$this->assertEmpty( $response['customize_concurrency']['setting_updates'] );
	}

	/**
	 * @see Customize_Concurrency::get_setting_value_from_post()
	 */
	function test_get_setting_value_from_post() {
		$instance = new Customize_Concurrency( $this->plugin );

		$setting_value = array( 'hello' => 'world' );
		$post_id = $this->factory->post->create( array(
			'post_type' => Customize_Concurrency::POST_TYPE,
			'post_status' => 'publish',
			'post_content_filtered' => wp_json_encode( $setting_value ),
		) );

		$this->assertEquals( $setting_value, $instance->get_setting_value_from_post( $post_id ) );
	}

	/**
	 * @see Customize_Concurrency::get_setting_value_from_post()
	 */
	function test_get_setting_value_from_post_exceptions() {
		$instance = new Customize_Concurrency( $this->plugin );

		$e = null;
		try {
			$instance->get_setting_value_from_post( null );
		} catch ( Exception $e ) {}
		$this->assertInstanceOf( __NAMESPACE__ . '\\Exception', $e );
		$this->assertEquals( 'Empty post supplied.', $e->getMessage() );

		$e = null;
		try {
			$instance->get_setting_value_from_post( 1 );
		} catch ( Exception $e ) {}
		$this->assertInstanceOf( __NAMESPACE__ . '\\Exception', $e );
		$this->assertEquals( 'Supplied post is not a customize_previewed post type.', $e->getMessage() );

		$e = null;
		try {
			$post_id = $this->create_concurrency_post( 'invalid_value', '{test: invalid JSON}' );
			$instance->get_setting_value_from_post( $post_id );
			wp_delete_post( $post_id, true );
		} catch ( Exception $e ) {}
		$this->assertInstanceOf( __NAMESPACE__ . '\\Exception', $e );
		$this->assertContains( 'JSON parse error for customize_previewed', $e->getMessage() );
	}

	/**
	 * @see Customize_Concurrency::request_customize_settings_previewed()
	 */
	function test_request_customize_settings_previewed_not_logged_in() {
		$instance = new Customize_Concurrency( $this->plugin );

		$e = null;
		try {
			$instance->request_customize_settings_previewed( array() );
		} catch ( Exception $e ) {}
		$this->assertInstanceOf( __NAMESPACE__ . '\\Exception', $e );
		$this->assertEquals( 'not_logged_in', $e->getMessage() );
	}

	/**
	 * @see Customize_Concurrency::request_customize_settings_previewed()
	 */
	function test_request_customize_settings_previewed_unauthorized() {
		$instance = new Customize_Concurrency( $this->plugin );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );
		$e = null;
		try {
			$instance->request_customize_settings_previewed( array( 'nonce' => '...' ) );
		} catch ( Exception $e ) {}
		$this->assertInstanceOf( __NAMESPACE__ . '\\Exception', $e );
		$this->assertEquals( 'unauthorized', $e->getMessage() );
	}

	/**
	 * @see Customize_Concurrency::request_customize_settings_previewed()
	 */
	function test_request_customize_settings_previewed_customize_off() {
		$instance = new Customize_Concurrency( $this->plugin );
		$instance->customize_manager = null;

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$e = null;
		try {
			$instance->request_customize_settings_previewed( array( 'nonce' => '...' ) );
		} catch ( Exception $e ) {}
		$this->assertInstanceOf( __NAMESPACE__ . '\\Exception', $e );
		$this->assertEquals( 'customize_off', $e->getMessage() );
	}

	/**
	 * @see Customize_Concurrency::request_customize_settings_previewed()
	 */
	function test_request_customize_settings_previewed_bad_nonce() {
		$instance = new Customize_Concurrency( $this->plugin );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$e = null;
		try {
			$instance->request_customize_settings_previewed( array( 'nonce' => 'bad_nonce' ) );
		} catch ( Exception $e ) {}
		$this->assertInstanceOf( __NAMESPACE__ . '\\Exception', $e );
		$this->assertEquals( 'bad_nonce', $e->getMessage() );
	}

	/**
	 * @see Customize_Concurrency::request_customize_settings_previewed()
	 */
	function test_request_customize_settings_previewed_customized_empty() {
		$instance = new Customize_Concurrency( $this->plugin );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$good_nonce = wp_create_nonce( 'preview-customize_' . $instance->customize_manager->get_stylesheet() );
		$e = null;
		try {
			$instance->request_customize_settings_previewed( array( 'nonce' => $good_nonce ) );
		} catch ( Exception $e ) {}
		$this->assertInstanceOf( __NAMESPACE__ . '\\Exception', $e );
		$this->assertEquals( 'customized_empty', $e->getMessage() );
	}

	/**
	 * @see Customize_Concurrency::request_customize_settings_previewed()
	 */
	function test_request_customize_settings_previewed_missing_last_update_timestamp_cursor() {
		$instance = new Customize_Concurrency( $this->plugin );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$good_nonce = wp_create_nonce( 'preview-customize_' . $instance->customize_manager->get_stylesheet() );
		$instance->customize_manager->set_post_value( 'biz', 'bam' );
		$e = null;
		try {
			$instance->request_customize_settings_previewed( array(
				'nonce' => $good_nonce,
			) );
		} catch ( Exception $e ) {}
		$this->assertInstanceOf( __NAMESPACE__ . '\\Exception', $e );
		$this->assertStringStartsWith( 'missing_last_update_timestamp_cursor', $e->getMessage() );
	}

	/**
	 * @see Customize_Concurrency::request_customize_settings_previewed()
	 */
	function test_request_customize_settings_previewed_unknown_setting() {
		$instance = new Customize_Concurrency( $this->plugin );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$good_nonce = wp_create_nonce( 'preview-customize_' . $instance->customize_manager->get_stylesheet() );
		$instance->customize_manager->set_post_value( 'biz', 'bam' );
		$e = null;
		try {
			$instance->request_customize_settings_previewed( array(
				'nonce' => $good_nonce,
				'last_update_timestamp_cursor' => time(),
			) );
		} catch ( Exception $e ) {}
		$this->assertInstanceOf( __NAMESPACE__ . '\\Exception', $e );
		$this->assertStringStartsWith( 'unknown_setting', $e->getMessage() );
	}

	/**
	 * @see Customize_Concurrency::request_customize_settings_previewed()
	 */
	function test_request_customize_settings_previewed() {
		$instance = new Customize_Concurrency( $this->plugin );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$good_nonce = wp_create_nonce( 'preview-customize_' . $instance->customize_manager->get_stylesheet() );
		$before_initial_revision_time = time();
		$instance->customize_manager->add_setting( 'foo' );
		$instance->customize_manager->set_post_value( 'foo', 'baz' );
		$results = $instance->request_customize_settings_previewed( array(
			'nonce' => $good_nonce,
			'last_update_timestamp_cursor' => time(),
		) );
		$this->assertArrayHasKey( 'previewed_settings', $results );
		$this->assertArrayHasKey( 'post_id', $results['previewed_settings']['foo'] );
		$this->assertArrayHasKey( 'revision_number', $results['previewed_settings']['foo'] );
		$this->assertArrayHasKey( 'previous_previewer', $results['previewed_settings']['foo'] );
		$this->assertArrayHasKey( 'previous_preview_timestamp', $results['previewed_settings']['foo'] );
		$this->assertEquals( 1, $results['previewed_settings']['foo']['revision_number'] );
		$this->assertNull( $results['previewed_settings']['foo']['previous_previewer'] );
		$this->assertNull( $results['previewed_settings']['foo']['previous_preview_timestamp'] );

		$instance->customize_manager->set_post_value( 'foo', 'quux' );
		$results = $instance->request_customize_settings_previewed( array(
			'nonce' => $good_nonce,
			'last_update_timestamp_cursor' => time(),
		) );
		$this->assertEquals( 2, $results['previewed_settings']['foo']['revision_number'] );
		$this->assertInternalType( 'array', $results['previewed_settings']['foo']['previous_previewer'] );
		$this->assertArrayHasKey( 'user_id', $results['previewed_settings']['foo']['previous_previewer'] );
		$this->assertEquals( wp_get_current_user()->ID, $results['previewed_settings']['foo']['previous_previewer']['user_id'] );
		$this->assertArrayHasKey( 'display_name', $results['previewed_settings']['foo']['previous_previewer'] );
		$this->assertArrayHasKey( 'avatar', $results['previewed_settings']['foo']['previous_previewer'] );
		$this->assertLessThanOrEqual( $before_initial_revision_time, $results['previewed_settings']['foo']['previous_preview_timestamp'] );
	}

	/**
	 * @see Customize_Concurrency::find_post()
	 */
	function test_find_post_cached() {
		$instance = new Customize_Concurrency( $this->plugin );
		$post_id = $this->create_concurrency_post( 'valid_setting_id', 'valid_setting_value' );
		wp_cache_set( 'valid_setting_id', $post_id, Customize_Concurrency::CACHE_GROUP );
		$post = $instance->find_post( 'valid_setting_id' );
		$this->assertInstanceOf( 'WP_Post', $post );
	}

	/**
	 * @see Customize_Concurrency::find_post()
	 */
	function test_find_post() {
		$instance = new Customize_Concurrency( $this->plugin );

		$foo_results = $instance->save_previewed_setting( 'foo', array( 'sanitized_value' => 'bar' ) );
		$bar_baz_results = $instance->save_previewed_setting( 'bar[baz]', array( 'sanitized_value' => 'quux' ) );
		$whacko_id = '"\'&!@#$%^&*'; // Note: literal backslashes don't work here
		$whacko_results = $instance->save_previewed_setting( $whacko_id, array( 'sanitized_value' => 'a bad word?' ) );

		wp_cache_delete( 'foo', Customize_Concurrency::CACHE_GROUP );
		$foo_post = $instance->find_post( 'foo' );
		$this->assertInstanceOf( 'WP_Post', $foo_post );
		$this->assertEquals( $foo_results['post_id'], $foo_post->ID );

		$bar_baz_post = $instance->find_post( 'bar[baz]' );
		$this->assertInstanceOf( 'WP_Post', $bar_baz_post );
		$this->assertEquals( $bar_baz_results['post_id'], $bar_baz_post->ID );

		$whacko_post = $instance->find_post( $whacko_id );
		$this->assertInstanceOf( 'WP_Post', $whacko_post );
		$this->assertEquals( $whacko_results['post_id'], $whacko_post->ID );
	}

	/**
	 * @see Customize_Concurrency::handle_insert_customize_previewed_post()
	 */
	function test_handle_insert_customize_previewed_post() {
		$instance = new Customize_Concurrency( $this->plugin );
		$this->assertFalse( wp_cache_get( 'custom_setting', Customize_Concurrency::CACHE_GROUP ) );
		$post_id = $this->create_concurrency_post( 'custom_setting', 'custom_value' );
		$this->assertEquals( $post_id, wp_cache_get( 'custom_setting', Customize_Concurrency::CACHE_GROUP ) );
	}

	/**
	 * @see Customize_Concurrency::handle_delete_customize_previewed_post()
	 */
	function test_handle_delete_customize_previewed_post() {
		$instance = new Customize_Concurrency( $this->plugin );
		$instance->handle_delete_customize_previewed_post( 1 );
		$post_id = $this->create_concurrency_post( 'custom_setting', 'custom_value' );
		$this->assertEquals( $post_id, wp_cache_get( 'custom_setting', Customize_Concurrency::CACHE_GROUP ) );
		$instance->handle_delete_customize_previewed_post( $post_id );
		do_action( 'deleted_post', $post_id );
		$this->assertFalse( wp_cache_get( 'custom_setting', Customize_Concurrency::CACHE_GROUP ) );
	}

	/**
	 * @see Customize_Concurrency::with_sanitize_title_suspended()
	 */
	function test_with_sanitize_title_suspended() {
		$instance = new Customize_Concurrency( $this->plugin );
		$title = 'Hello World';
		$this->assertEquals( 'hello-world', sanitize_title_for_query( $title ) );
		$this->assertEquals( 'Hello World', $instance->with_sanitize_title_suspended( function () use ( $title ) {
			return sanitize_title_for_query( $title );
		} ) );

		try {
			$instance->with_sanitize_title_suspended( $title );
		} catch ( \Exception $e ) {
			$this->assertContains( "call_user_func() expects parameter 1 to be a valid callback, function '$title' not found or invalid function name", $e->getMessage() );
			return;
		}

		$this->fail( 'An expected exception has not been raised.' );
	}

	/**
	 * @see Customize_Concurrency::save_previewed_setting()
	 */
	function test_save_previewed_setting() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$instance = new Customize_Concurrency( $this->plugin );
		$foo_results = $instance->save_previewed_setting( 'foo[bar]', array(
			'sanitized_value' => 'baz',
			'post_status' => 'publish',
		) );
		$this->assertInternalType( 'array', $foo_results );
		$this->assertArrayHasKey( 'post_id', $foo_results );
		$this->assertArrayHasKey( 'revision_number', $foo_results );
		$this->assertArrayHasKey( 'previous_previewer', $foo_results );
		$this->assertArrayHasKey( 'previous_preview_timestamp', $foo_results );
		$this->assertEquals( 1, $foo_results['revision_number'] );
		$this->assertNull( $foo_results['previous_previewer'] );
		$this->assertNull( $foo_results['previous_preview_timestamp'] );
		$foo_post = get_post( $foo_results['post_id'] );
		$this->assertEquals( Customize_Concurrency::POST_TYPE, $foo_post->post_type );
		$this->assertEquals( 'publish', $foo_post->post_status );
		$this->assertEquals( $user_id, $foo_post->post_author );
		$this->assertEquals( wp_json_encode( 'baz' ), $foo_post->post_content_filtered );

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$before_initial_revision_time = time();
		$foo2_results = $instance->save_previewed_setting( 'foo[bar]', array(
			'sanitized_value' => 'acme',
			'post_status' => 'publish',
		) );
		$foo2_post = get_post( $foo_results['post_id'] );
		$this->assertEquals( 2, $foo2_results['revision_number'] );
		$this->assertInternalType( 'array', $foo2_results['previous_previewer'] );
		$this->assertArrayHasKey( 'user_id', $foo2_results['previous_previewer'] );
		$this->assertNotEquals( wp_get_current_user()->ID, $foo2_results['previous_previewer']['user_id'] );
		$this->assertEquals( wp_get_current_user()->ID, $foo2_post->post_author );
		$this->assertArrayHasKey( 'display_name', $foo2_results['previous_previewer'] );
		$this->assertArrayHasKey( 'avatar', $foo2_results['previous_previewer'] );
		$this->assertLessThanOrEqual( $before_initial_revision_time, $foo2_results['previous_preview_timestamp'] );
		$this->assertEquals( $foo2_results['previous_preview_timestamp'], strtotime( $foo_post->post_date_gmt ) );
		$this->assertEquals( wp_json_encode( 'acme' ), $foo2_post->post_content_filtered );
	}

	/**
	 * @see Customize_Concurrency::save_previewed_setting()
	 */
	function test_save_previewed_setting_status() {
		$instance = new Customize_Concurrency( $this->plugin );

		wp_set_current_user( $this->user_id );

		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		$foo_results = $instance->save_previewed_setting( 'foo[biz]', array(
			'sanitized_value' => 'bar',
			'post_status' => 'draft',
		) );
		$this->assertEquals( 'failed', $foo_results['status'] );
		remove_filter( 'wp_insert_post_empty_content', '__return_true' );

		$foo_results = $instance->save_previewed_setting( 'foo[biz]', array(
			'sanitized_value' => 'bar',
			'post_status' => 'draft',
		) );
		$this->assertEquals( 'accepted', $foo_results['status'] );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$foo_results = $instance->save_previewed_setting( 'foo[biz]', array(
			'sanitized_value' => 'baz',
			'post_status' => 'draft',
		) );
		$this->assertEquals( 'rejected', $foo_results['status'] );
	}

	/**
	 * @see Customize_Concurrency::customize_save_after()
	 */
	function test_customize_save_after_no_results() {
		$instance = new Customize_Concurrency( $this->plugin );

		$instance->customize_manager->set_post_value( 'foo', 'baz' );
		$instance->customize_save_after();
		$foo_results = apply_filters( 'customize_save_response', array() );
		$this->assertArrayHasKey( 'concurrency_save_results', $foo_results );
		$this->assertEmpty( $foo_results['concurrency_save_results'] );
	}

	/**
	 * @see Customize_Concurrency::customize_save_after()
	 */
	function test_customize_save_after() {
		$instance = new Customize_Concurrency( $this->plugin );
		$setting_id = 'foo[bar]';

		$instance->customize_manager->add_setting( $setting_id );
		$instance->customize_manager->set_post_value( $setting_id, 'baz' );
		$instance->customize_save_after();
		$foo_results = apply_filters( 'customize_save_response', array() );
		$this->assertArrayHasKey( 'concurrency_save_results', $foo_results );
		$this->assertArrayHasKey( $setting_id, $foo_results['concurrency_save_results'] );
		$this->assertEquals( 'publish', $foo_results['concurrency_save_results'][ $setting_id ]['post_status'] );
	}

	/**
	 * @see Customize_Concurrency::get_preview_user_data()
	 */
	function test_get_preview_user_data() {
		$instance = new Customize_Concurrency( $this->plugin );
		$this->assertNull( $instance->get_preview_user_data( 0 ) );
	}
}
