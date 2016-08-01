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
	const POST_TYPE = 'customize_c9y';

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
	 * Any settings that have been saved previously.
	 *
	 * @var array $saved_settings
	 */
	protected $saved_settings = null;

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
		wp_enqueue_script( $this->plugin->slug );
	}

	/**
	 * Send timestamps and user ids to JS.
	 */
	public function customize_controls_print_footer_scripts() {
		$saved_settings = $this->get_saved_settings();

		$data = array(
			'saved_settings' => array(
				array(
					'setting_id' => 'test[setting]',
					'author' => '1',
					'timestamp' => '1470045708',
				),
			),
			'session_start_timestamp' => time(),
			'current_user_id' => wp_get_current_user()->ID,
		);

		foreach ( $saved_settings as $setting_id => $saved_setting ) {
			$data['saved_settings'][] = array(
			);
		}

		printf( '<script>var _customizeConcurrency = %s;</script>', wp_json_encode( $data ) );
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
		$invalidities = array();
		$saved_settings = $this->get_saved_settings();

		foreach ( $saved_settings as $setting_id => $saved_setting ) {
			$is_conflicted = (
				$saved_setting['timestamp'] > $customizer_session_timestamp
				&&
				isset( $post_values[ $setting_id ] )
				&&
				$saved_setting['value'] !== $post_values[ $setting_id ]
			);
			if ( $is_conflicted ) {
				$invalidities[ $setting_id ] = new \WP_Error( 'concurrency_conflict', __( 'Concurrency conflict' ), array( 'their_value' => $saved_setting['value'] ) );
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

	/**
	 * Store update history with timestamps.
	 *
	 * We can skip this for snapshots and just make sure to save usernames and timestamps in the snapshot.
	 */
	public function customize_save_after() {

		$post_values = $this->customize_manager->unsanitized_post_values();
		$setting_ids = array_keys( $post_values );
		$saved_settings = $this->get_saved_settings();

		$this->suspend_kses();
		$r = array();

		foreach ( $setting_ids as $setting_id ) {
			$setting = $this->customize_manager->get_setting( $setting_id );
			if ( ! $setting ) {
				continue;
			}

			$is_update = isset( $saved_settings[ $setting_id ]['post_id'] );

			$post_data = array(
				'post_type' => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title' => $setting_id,
				'post_name' => $setting_id,
				'post_content_filtered' => wp_json_encode( $post_values[ $setting_id ] ),
				'post_date' => current_time( 'mysql', 0 ),
				'post_date_gmt' => current_time( 'mysql', 1 ),
				'post_author' => get_current_user_id(),
			);

			if ( $is_update ) {
				$post_data['ID'] = $saved_settings[ $setting_id ]['post_id'];
				$r[] = wp_update_post( wp_slash( $post_data ), true );
			} else {
				$r[] = wp_insert_post( wp_slash( $post_data ), true );
			}
		}

		$this->restore_kses();
		// Todo: check $r for errors - most like case we would care about is two sessions writing to the same post
	}

	/**
	 * Get saved settings from default post storage or from snapshot storage.
	 *
	 * Todo: read from snapshots instead if available.
	 * Todo: invalidate/update when object-cached
	 *
	 * @return array
	 */
	function get_saved_settings() {
		if ( null === $this->saved_settings ) {
			$this->saved_settings = array();

			$saved_setting_posts = new \WP_Query(array(
				'post_name__in' => array_keys( $this->customize_manager->unsanitized_post_values() ),
				'post_type' => self::POST_TYPE,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'posts_per_page' => -1,
				'ignore_sticky_posts' => true,
			));

			foreach ( $saved_setting_posts->posts as $setting_post ) {
				$this->saved_settings[ $setting_post->post_name ] = array(
					'post_id' => $setting_post->ID,
					'value' => json_decode( $setting_post->post_content, true ),
					'timestamp' => strtotime( $setting_post->post_modified_gmt ),
					'author' => $setting_post->post_author,
				);
			}
		}

		return $this->saved_settings;
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