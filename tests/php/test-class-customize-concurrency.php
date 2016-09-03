<?php
/**
 * Test Customize_Concurrency.
 *
 * @package CustomizeConcurrency
 */

namespace CustomizeConcurrency;

/**
 * Class Test_Customize_Concurrency
 */
class Test_Customize_Concurrency extends \WP_UnitTestCase {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * A valid UUID.
	 *
	 * @type string
	 */
	const UUID = '65aee1ff-af47-47df-9e14-9c69b3017cd3';

	/**
	 * Customize Manager.
	 *
	 * @var \WP_Customize_Manager
	 */
	protected $wp_customize;

	/**
	 * Snapshot Manager.
	 *
	 * @var Customize_Snapshot_Manager
	 */
	protected $snapshot_manager;

	/**
	 * User ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Concurrency object.
	 *
	 * @var Customize_Concurrency
	 */
	protected $concurrency;

	/**
	 * Set up.
	 */
	function setUp() {
		parent::setUp();
		$this->plugin = get_plugin_instance();
		require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
		$GLOBALS['wp_customize'] = new \WP_Customize_Manager(); // WPCS: global override ok.
		$this->wp_customize = $GLOBALS['wp_customize'];

		$this->wp_customize->add_setting( 'foo', array( 'default' => 'foo_default' ) );
		$this->wp_customize->add_setting( 'bar', array( 'default' => 'bar_default' ) );

		$this->snapshot_manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->snapshot_manager->init();
		$this->user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );

	}

	/**
	 * Test constructor.
	 *
	 * @see Customize_Concurrency::__construct()
	 */
	function test_construct() {
		$concurrency = new Customize_Concurrency( $this->plugin );

		$this->assertInstanceOf( '\WP_Customize_Manager', $concurrency->customize_manager );
		$this->assertEquals( 10, has_action( 'init', array( $concurrency, 'register_post_type' ) ) );
		$this->assertEquals( 10, has_action( 'customize_controls_enqueue_scripts', array( $concurrency, 'customize_controls_enqueue_scripts' ) ) );
		$this->assertEquals( 10, has_action( 'customize_preview_init', array( $concurrency, 'customize_preview_init' ) ) );
		$this->assertEquals( 10, has_action( 'customize_preview_init', array( $concurrency, 'customize_preview_sanitize' ) ) );
		$this->assertEquals( 10, has_action( 'customize_controls_print_footer_scripts', array( $concurrency, 'customize_controls_print_footer_scripts' ) ) );
		$this->assertEquals( 10, has_action( 'wp_insert_post_data', array( $concurrency, 'preserve_inserted_post_name' ) ) );
		$this->assertEquals( 10, has_action( 'customize_snapshot_save_before', array( $concurrency, 'customize_snapshot_save_before' ) ) );
		$this->assertEquals( 10, has_action( 'customize_snapshot_save', array( $concurrency, 'customize_snapshot_save' ) ) );
		$this->assertEquals( 10, has_action( 'customize_save_response', array( $concurrency, 'customize_save_response' ) ) );

		$this->assertFalse( has_action( 'customize_register', array( $concurrency, 'customize_register' ) ) );
		$this->assertFalse( has_action( 'customize_save_after', array( $concurrency, 'customize_save_after' ) ) );

		$_REQUEST['customize_snapshot_uuid'] = self::UUID;
		$concurrency = new Customize_Concurrency( $this->plugin );

		$this->assertEquals( 30, has_action( 'customize_register', array( $concurrency, 'customize_register' ) ) );
		$this->assertEquals( 10, has_action( 'customize_save_after', array( $concurrency, 'customize_save_after' ) ) );
	}

	/**
	 * Test Set up post type.
	 *
	 * @covers \CustomizeConcurrency\Customize_Concurrency::register_post_type()
	 */
	public function test_register_post_type() {
		$concurrency = new Customize_Concurrency( $this->plugin );
		$concurrency->register_post_type();
		$post_type = get_post_type_object( $concurrency::POST_TYPE );

		$this->assertInstanceOf( 'WP_Post_Type', $post_type );
		$this->assertFalse( $post_type->public );
		$this->assertFalse( $post_type->hierarchical );
	}

	/**
	 * Test Add scripts & styles.
	 *
	 * @covers \CustomizeConcurrency\Customize_Concurrency::customize_controls_enqueue_scripts()
	 */
	public function test_customize_controls_enqueue_scripts() {
		$concurrency = new Customize_Concurrency( $this->plugin );
		$concurrency->customize_controls_enqueue_scripts();

		$this->assertTrue( wp_script_is( $this->plugin->slug, 'enqueued' ) );
		$this->assertTrue( wp_style_is( $this->plugin->slug, 'enqueued' ) );
	}

	/**
	 * Test Add script specifically for the preview pane.
	 *
	 * @covers \CustomizeConcurrency\Customize_Concurrency::customize_preview_init()
	 */
	public function test_customize_preview_init() {
		$concurrency = new Customize_Concurrency( $this->plugin );
		$concurrency->customize_preview_init();

		$this->assertTrue( wp_script_is( "{$this->plugin->slug}-preview", 'enqueued' ) );
	}

	/**
	 * Test Send timestamp for when this session started and output template.
	 *
	 * @covers \CustomizeConcurrency\Customize_Concurrency::customize_controls_print_footer_scripts()
	 */
	public function customize_controls_print_footer_scripts() {
		ob_start();
		\CustomizeConcurrency\Customize_Concurrency::customize_controls_print_footer_scripts();
		$output = ob_get_clean();

		$this->assertEqual( $output, <<<EOT
			<script>var _customizeConcurrency = {"session_start_timestamp":1472878499};</script>		<script type="text/html" id="tmpl-customize-concurrency-notifications">
			<ul>
				<# _.each( data.notifications, function( notification ) { #>
					<li class="notice notice-{{ notification.type || 'info' }} {{ data.altNotice ? 'notice-alt' : '' }}" data-code="{{ notification.code }}" data-type="{{ notification.type }}">
						{{{ notification.message || notification.code }}}
					</li>
				<# } ); #>
			</ul>
		</script>
EOT
		);
	}

	/**
	 * Test Prevent sanitize_title() on the post_name.
	 *
	 * @covers \CustomizeConcurrency\Customize_Concurrency::preserve_inserted_post_name()
	 */
	public function test_preserve_inserted_post_name() {
		$postarr = array( 'post_name' => 'setting[with][brackets]' );
		$data = array( 'post_type' => \CustomizeConcurrency\Customize_Concurrency::POST_TYPE );
		$data = \CustomizeConcurrency\Customize_Concurrency::preserve_inserted_post_name( $data, $postarr );

		$this->assertEqual( $data['post_name'], wp_slash( $postarr['post_name'] ) );
	}
}




















