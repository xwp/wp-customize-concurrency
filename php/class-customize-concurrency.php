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

//		add_action( 'customize_save_after', array( $this, 'customize_save_after' ), 9 );
		add_filter( 'wp_insert_post_data', array( $this, 'preserve_inserted_post_name' ), 10, 2 );


		add_action( 'customize_save', function( \WP_Customize_Manager $wp_customize ) {

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
		}, 1000 );
	}

	/**
	 * Register the post type.
	 */
	public function register_post_type() {
		$args = array(
			'labels' => array(
				'name' => __( 'Customize Saved Setting', 'customize-concurrency' ),
			),
			'description' => __( 'Copy of Customizer value for tracking concurrency.', 'customize-concurrency' ),
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
		wp_enqueue_style( 'wp-customize-concurrency' );
	}

	/**
	 * Underscore (JS) templates for concurrency locking.
	 */
	public function customize_controls_print_footer_scripts() {
		$data = array(
			'session_start_timestamp' => time(),
			'current_user_id' => wp_get_current_user()->ID,
		);
		printf( '<script>var _customizeConcurrency = %s;</script>', wp_json_encode( $data ) );

	}

	/**
	I think there should be a new post type like `customize_saved_setting`...
	where the content is the `value` of the setting being saved...
	and the `post_name` is the setting ID
	and then when we try saving changes in the Customizer again, we can do a `post_name__in` query to obtain any `customize_saved_setting`
	 posts, and check to see if their modified times are greater than the customizer session start time
	and if so, mark those settings as invalid
	and present the conflict resolution UI

	so I think it needs a reboot that is focused on integrating with setting validation in a similar way to Customize Posts is now doing
	and then revisit Heartbeat later

	for snapshots we'd do a similar check, but instead of looking at `customize_saved_setting` posts, we'd just look at the snapshot being
	 saved to see if the values in them have associated modified times that are greater than the customizer session start time

	 */
	public function customize_save_after() {
		$customized = $this->customize_manager->unsanitized_post_values();

		$concurrency_save_results = array();
		foreach ( array_keys( $customized ) as $setting_id ) {
			$setting = $this->customize_manager->get_setting( $setting_id );
			if ( ! $setting ) {
				continue;
			}
//re-use some of the logic in Snapshots for the Post_Type to prevent kses from blowing up the JSON you'll be storing in the `post_content`


		}

	}


	/**
	 * Get saved setting by setting id.
	 *
	 * @param string $setting_id
	 * @return \WP_Post|null
	 */
	function get_saved_setting_post( $setting_id ) {
		/*
		 * We have to explicitly query all post stati due to how WP_Query::get_posts()
		 * is written. See https://github.com/xwp/wordpress-develop/blob/4.3.1/src/wp-includes/query.php#L3542-L3549
		 */
		$post_stati = array_values( get_post_stati() );
		$post_stati[] = 'any';
		// Filter to ensure that special characters in post_name get sanitize_title()'ed-away (unlikely for widget posts).
		$filter_sanitize_title = function ( $title, $raw_title ) {
			unset( $title );
			$title = esc_sql( $raw_title );
			return $title;
		};
		$args = array(
			'name'             => $setting_id,
			'post_type'        => self::POST_TYPE,
			'post_status'      => $post_stati,
			'posts_per_page'   => 1,
			'suppress_filters' => false,
		);
		add_filter( 'sanitize_title', $filter_sanitize_title, 10, 2 );
		$posts = get_posts( $args );
		remove_filter( 'sanitize_title', $filter_sanitize_title, 10 );
		if ( empty( $posts ) ) {
			return null;
		}
		$post = array_shift( $posts );
		return $post;
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
	function preserve_inserted_post_name( $data, $postarr ) {
		if ( self::POST_TYPE === $data['post_type'] && isset( $postarr['post_name'] ) ) {
			$data['post_name'] = wp_slash( $postarr['post_name'] );
		}
		return $data;
	}



	/**
	 * Get the WP_Customize_Setting instance.
	 *
	 * @param string $setting_id The setting ID.
	 * @return \WP_Customize_Setting
	 */
	public function get_setting( $setting_id ) {
		$setting = $this->customize_manager->get_setting( $setting_id );
		if ( ! $setting ) {
			$setting_args = array_fill_keys( array(
				'type',
				'transport',
			), null );
			$setting_class = 'WP_Customize_Setting';

			/** This filter is documented in class-wp-customize-manager.php */
			$setting_args = apply_filters( 'customize_dynamic_setting_args', $setting_args, $setting_id );

			/** This filter is documented in class-wp-customize-manager.php */
			$setting_class = apply_filters( 'customize_dynamic_setting_class', $setting_class, $setting_id, $setting_args );

			$setting = new $setting_class( $this->customize_manager, $setting_id, $setting_args );
		}
		return $setting;
	}

	/**
	 * Get the user data.
	 *
	 * @param int $user_id The user ID.
	 * @return array|null
	 */
	public function get_user_data( $user_id ) {
		$user = get_user_by( 'id', intval( $user_id ) );
		if ( ! $user ) {
			return null;
		}
		return array(
			'user_id' => $user->ID,
			'display_name' => $user->display_name,
			'avatar' => get_avatar_data( $user->ID, array(
				'size' => 40,
			) ),
		);
	}
}
