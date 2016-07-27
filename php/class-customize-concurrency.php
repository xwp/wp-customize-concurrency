<?php
/**
 * Addresses Core ticket #31436: Handle conflicts in concurrent Customizer sessions.
 *
 * @link https://core.trac.wordpress.org/ticket/31436
 *
 * @package CustomizeConcurrency
 */

namespace CustomizeConcurrency;

/**
 * Customize Concurrency class.
 */
class Customize_Concurrency {

	/**
	 * Post type that stores settings changes until session ends.
	 *
	 * Limited by WP to 20 characters
	 *
	 * @var string $POST_TYPE
	 */
	const POST_TYPE = 'custom_saved_setting';

	/**
	 * Plugin instance.
	 *
	 * @var Plugin $plugin
	 */
	public $plugin;

	/**
	 * Customize manager.
	 *
	 * @var \WP_Customize_Manager
	 */
	public $customize_manager;

	/**
	 * Whether kses filters on content_save_pre are added.
	 *
	 * @var bool
	 */
	protected $kses_suspended = false;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->customize_manager = null;

		// Bootstrap the Customizer.
		if ( ! empty( $GLOBALS['wp_customize'] ) ) {
			$this->customize_manager = $GLOBALS['wp_customize'];
		}

		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'customize_controls_enqueue_scripts' ) );
		add_action( 'customize_controls_print_footer_scripts', array( $this, 'customize_controls_print_footer_scripts' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'preserve_inserted_post_name' ), 10, 2 );
		add_action( 'customize_save', array( $this, 'customize_save' ), 1000 );
	}

	/**
	 * Register the post type.
	 */
	public function register_post_type() {
		$args = array(
			'labels' => array(
				'name' => __( 'Customize Saved Setting', 'customize-concurrency' ),
			),
			'description' => __( 'Copy of Customizer setting value for tracking concurrency.', 'customize-concurrency' ),
			'public' => false,
			'hierarchical' => false,
		);
		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Add scripts & styles.
	 *
	 * @action customize_controls_enqueue_scripts
	 */
	public function customize_controls_enqueue_scripts() {
		wp_enqueue_script( 'wp-customize-concurrency' );
	}

	/**
	 * Put timestamp and footer in hidden field.
	 *
	 * todo: find a better way to get this into what is sent via ajax
	 */
	public function customize_controls_print_footer_scripts() {
		printf( '<input type="hidden" id="customizer_session_timestamp" value="%s" />', time() );
		printf( '<input type="hidden" id="current_user_id" value="%s" />', get_current_user_id() );
	}

	/**
	 * Prevent sanitize_title() on the post_name.
	 *
	 * @see wp_insert_post()
	 * @filter wp_insert_post_data
	 *
	 * @param array $data    An array of slashed post data.
	 * @param array $postarr An array of sanitized, but otherwise unmodified post data.
	 * @return array
	 */
	public function preserve_inserted_post_name( $data, $postarr ) {
		if ( self::POST_TYPE === $data['post_type'] && isset( $postarr['post_name'] ) ) {
			$data['post_name'] = wp_slash( $postarr['post_name'] );
		}
		return $data;
	}

	public function customize_save ( \WP_Customize_Manager $wp_customize ) {

		$customizer_session_timestamp = isset( $_POST['customizer_session_timestamp'] ) ? intval( $_POST['customizer_session_timestamp'] ) : time();

		$post_values = $wp_customize->unsanitized_post_values();
		$setting_ids = array_keys( $post_values );
		$invalidities = array();
		$customize_saved_setting_posts = new \WP_Query( array(
			'post_name__in' => $setting_ids,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'post_type' => self::POST_TYPE,
			'posts_per_page' => -1,
			'ignore_sticky_posts' => true,
		) );

		foreach ( $customize_saved_setting_posts->posts as $setting_post ) {
			$setting_id = $setting_post->post_name;
			$existing_value = json_decode( $setting_post->post_content, true );
			$is_conflicted = (
				strtotime( $setting_post->post_modified_gmt ) > $customizer_session_timestamp
				&&
				isset( $post_values[ $setting_id ] )
				&&
				$existing_value !== $post_values[ $setting_id ]
			);
			if ( $is_conflicted ) {
				$invalidities[ $setting_id ] = new \WP_Error( 'concurrency_conflict', __( 'Concurrency conflict' ), array( 'their_value' => $existing_value ) );
			}
		}

		if ( ! empty( $invalidities ) ) {
			$exported_setting_validities = array_map( array( $wp_customize, 'prepare_setting_validity_for_js' ), $invalidities );
			$invalid_setting_count = count( $exported_setting_validities );
			$response = array(
				'setting_validities' => $exported_setting_validities,
				'message' => sprintf( _n( 'There is %s invalid setting.', 'There are %s invalid settings.', $invalid_setting_count ), number_format_i18n( $invalid_setting_count ) ),
			);

			/** This filter is documented in wp-includes/class-wp-customize-manager.php */
			$response = apply_filters( 'customize_save_response', $response, $this );
			wp_send_json_error( $response );
		}

	}

	public function customize_save_after() {

		$post_values = $this->customize_manager->unsanitized_post_values();
		$setting_ids = array_keys( $post_values );

		foreach ( $setting_ids as $setting_id ) {
			$setting = $this->customize_manager->get_setting( $setting_id );
			if ( ! $setting ) {
				continue;
			}

			$post_data = array(
				'post_type' => self::POST_TYPE,
				'post_status' => 'draft',
				'post_title' => $setting_id,
				'post_name' => $setting_id,
				'post_content_filtered' => wp_json_encode( $post_values[ $setting_id ] ),
				'post_date' => current_time( 'mysql', 0 ),
				'post_date_gmt' => current_time( 'mysql', 1 ),
				'post_author' => get_current_user_id(),
			);

			$this->suspend_kses();
				wp_insert_post( $post_data, true );
			$this->restore_kses();
		}
	}

	/**
	 * Suspend kses which runs on content_save_pre and can corrupt JSON in post_content.
	 *
	 * @see \sanitize_post()
	 */
	function suspend_kses() {
		if ( false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ) ) {
			$this->kses_suspended = true;
			kses_remove_filters();
		}
	}

	/**
	 * Restore kses which runs on content_save_pre and can corrupt JSON in post_content.
	 *
	 * @see \sanitize_post()
	 */
	function restore_kses() {
		if ( $this->kses_suspended ) {
			kses_init_filters();
			$this->kses_suspended = false;
		}
	}

}