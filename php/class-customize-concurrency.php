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

	const SLUG = 'customize_concurrency';

	const POST_TYPE = 'customize_previewed';

	const AJAX_ACTION = 'customize_previewed_settings';

	const POSTMETA_KEY = '_revision_number';

	const CACHE_GROUP = 'customize_previewed_setting_post';

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
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_customize_settings_previewed' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'handle_insert_customize_previewed_post' ), 10, 3 );
		add_action( 'delete_post', array( $this, 'handle_delete_customize_previewed_post' ) );

		add_filter( 'heartbeat_settings', array( $this, 'filter_heartbeat_settings' ) );
		add_filter( 'heartbeat_received', array( $this, 'filter_heartbeat_received' ), 10, 3 );
		add_action( 'customize_save_after', array( $this, 'customize_save_after' ), 9 );
	}

	/**
	 * Get the default config for the plugin.
	 *
	 * @return array
	 */
	public static function default_config() {
		$config = array(
			'send_settings_delay' => 2000, // In milliseconds.
			'capability'          => 'customize',
			'heartbeat_interval'  => 5, // Null means inherit.
			'lock_window_seconds' => apply_filters( 'wp_check_post_lock_window', 150 ),
		);

		/**
		 * Filter the default config.
		 *
		 * @param array $config Config values.
		 * @return array
		 */
		$config = apply_filters( 'customize_concurrency_default_config', $config );

		return $config;
	}

	/**
	 * Return the config entry for the supplied key, or all configs if not supplied.
	 *
	 * @param string $key  Config key.
	 * @return array|mixed
	 */
	public function config( $key = null ) {
		$config = self::default_config();
		if ( is_null( $key ) ) {
			return $config;
		} else if ( isset( $config[ $key ] ) ) {
			return $config[ $key ];
		} else {
			return null;
		}
	}

	/**
	 * Register the post type.
	 */
	public function register_post_type() {
		$args = array(
			'labels' => array(
				'name' => __( 'Previewed Customize Setting', 'customize-concurrency' ),
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
		wp_enqueue_script( 'customize-concurrency' );
		wp_enqueue_style( 'customize-concurrency' );
		add_action( 'customize_controls_print_footer_scripts', array( $this, 'export_js_data' ), 1 );
	}

	/**
	 * Export the _customizeConcurrency JavaScript object.
	 *
	 * @action customize_controls_print_footer_scripts
	 */
	public function export_js_data() {
		$setting_count = 100;
		$query_vars = array(
			'post_type' => self::POST_TYPE,
			'post_status' => array( 'draft', 'publish' ),
			'date_query' => array(
				array(
					'after' => gmdate( 'c', time() - $this->config( 'lock_window_seconds' ) ),
					'inclusive' => true,
					'column' => 'post_date_gmt',
				),
			),
			'order' => 'desc',
			'orderby' => 'date',
			'posts_per_page' => $setting_count,
		);
		$query = new \WP_Query( $query_vars );

		$recently_previewed_settings = array();
		foreach ( $query->posts as $post ) {
			if ( intval( $post->post_author ) === intval( get_current_user_id() ) || 'draft' !== $post->post_status ) {
				continue;
			}
			$setting_id = $post->post_name;
			$setting = $this->get_setting( $setting_id );
			$value = null;
			if ( 'draft' === $post->post_status ) {
				$value = json_decode( $post->post_content_filtered, true );
				if ( ! preg_match( '/sidebars_widgets\]?\[/', $setting_id ) ) {
					$value = apply_filters( "customize_sanitize_js_{$setting_id}", $value, $setting );
				}
			}

			$recently_previewed_settings[ $setting_id ] = array(
				'post_id' => $post->ID,
				'post_author' => $this->get_preview_user_data( $post->post_author ),
				'post_status' => $post->post_status,
				'post_date' => strtotime( $post->post_date_gmt ),
				'value' => $value,
				'transport' => $setting->transport,
			);
		}

		/**
		 * Filters which settings have been previewed.
		 *
		 * @param array $recently_previewed_settings Sanitized post values.
		 * @return array
		 */
		$recently_previewed_settings = apply_filters( 'customize_concurrency_settings', $recently_previewed_settings );

		$data = array(
			'session_start_timestamp' => time(),
			'lock_window_seconds' => $this->config( 'lock_window_seconds' ),
			'last_update_timestamp_cursor' => time(),
			'action' => self::AJAX_ACTION,
			'send_settings_debounce_delay' => $this->config( 'send_settings_debounce_delay' ),
			'current_user_id' => wp_get_current_user()->ID,
			'recently_previewed_settings_data' => $recently_previewed_settings,
			'l10n' => array(
				'concurrentUserTooltip' => __( '%1$s recently updated: %2$s', 'customize-concurrency' ),
			),
			// Note that the preview nonce is to be re-used.
		);

		printf( '<script>var _customizeConcurrency = %s;</script>', wp_json_encode( $data ) );
	}

	/**
	 * Filter heartbeat settings for the Customizer.
	 *
	 * Note that the Customizer Concurrency functionality only is active when the
	 * Customizer is on, so this filter will only apply in the Customizer.
	 *
	 * @filter heartbeat_settings
	 *
	 * @param array $settings  Current settings to filter.
	 * @return array
	 */
	public function filter_heartbeat_settings( $settings ) {
		global $pagenow;
		if ( 'customize.php' !== $pagenow ) {
			return $settings;
		}

		$settings['screenId'] = 'customize';
		if ( $this->config( 'heartbeat_interval' ) ) {
			$settings['interval'] = intval( $this->config( 'heartbeat_interval' ) );
		}
		return $settings;
	}

	/**
	 * Filter for heartbeat data sent from client.
	 *
	 * @filter heartbeat_received
	 *
	 * @param array  $response   Data to send back to client.
	 * @param array  $data       Data sent from client.
	 * @param string $screen_id  Current screen.
	 * @return array
	 */
	public function filter_heartbeat_received( $response, $data, $screen_id ) {
		$is_customize_concurrency = (
			'customize' === $screen_id
			&&
			isset( $data[ self::SLUG ]['last_update_timestamp_cursor'] )
			&&
			intval( $data[ self::SLUG ]['last_update_timestamp_cursor'] ) > ( time() - $this->config( 'lock_window_seconds' ) )
		);
		if ( ! $is_customize_concurrency ) {
			return $response;
		}

		if ( empty( $this->customize_manager ) ) {
			require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
			$GLOBALS['wp_customize'] = new \WP_Customize_Manager();
			$this->customize_manager = $GLOBALS['wp_customize'];
		}

		do_action( 'customize_register', $this->customize_manager );

		$next_update_timestamp_cursor = time();
		$last_update_timestamp_cursor = absint( $data[ self::SLUG ]['last_update_timestamp_cursor'] );
		$query_vars = array(
			'post_type' => self::POST_TYPE,
			'date_query' => array(
				array(
					'after' => gmdate( 'c', $last_update_timestamp_cursor ),
					'inclusive' => true,
					'column' => 'post_date_gmt',
				),
			),
			'posts_per_page' => 50,
		);
		$query = new \WP_Query( $query_vars );

		$setting_updates = array();
		foreach ( $query->posts as $post ) {
			if ( intval( $post->post_author ) === intval( get_current_user_id() ) ) {
				continue;
			}

			$setting_id = $post->post_name;
			$setting = $this->get_setting( $setting_id );
			$value = json_decode( $post->post_content_filtered, true );

			if ( ! $this->customize_manager->get_setting( $setting_id ) ) {
				$this->customize_manager->add_setting( $setting );
				$this->customize_manager->set_post_value( $setting_id, $value );
				$setting->preview();
			}

			/*
			 * @todo This filter is prevented from applying due to optimized-widget-registration.
			 * Make sure we account for why. Heartbeat may need to send the active widgets.
			 */
			if ( ! preg_match( '/sidebars_widgets\]?\[/', $setting_id ) ) {
				$value = apply_filters( "customize_sanitize_js_{$setting_id}", $value, $setting );
			}

			$args = array(
				'post_id' => $post->ID,
				'post_status' => $post->post_status,
				'post_author' => $this->get_preview_user_data( $post->post_author ),
				'post_date' => strtotime( $post->post_date_gmt ),
				'value' => $value,
				'transport' => $setting->transport, // This is so a client can dynamically create the setting.
			);
			$setting_updates[ $post->post_name ] = $args;
		}

		/**
		 * Filters which settings have been updated.
		 *
		 * @param array $setting_updates Sanitized post values.
		 * @return array
		 */
		$setting_updates = apply_filters( 'customize_concurrency_settings', $setting_updates );

		$response[ self::SLUG ] = array(
			'next_update_timestamp_cursor' => $next_update_timestamp_cursor,
			'old_last_update_timestamp_cursor' => $last_update_timestamp_cursor,
			'setting_updates' => $setting_updates,
		);
		return $response;
	}

	/**
	 * Get the setting value for a given customize_previewed post.
	 *
	 * @param int|\WP_Post $post  A customize_previewed post.
	 * @throws Exception When the supplied post is invalid or there is a parse error on the JSON.
	 * @return mixed|null  Returns null if no setting was stored. Otherwise, returns unserialized JSON.
	 */
	public function get_setting_value_from_post( $post ) {
		$value = null;
		if ( empty( $post ) ) {
			throw new Exception( 'Empty post supplied.' );
		}
		$post = get_post( $post );
		if ( empty( $post ) || $post->post_type !== self::POST_TYPE ) {
			throw new Exception( 'Supplied post is not a customize_previewed post type.' );
		}

		if ( ! empty( $post->post_content_filtered ) ) {
			$value = json_decode( $post->post_content_filtered, true );
			if ( json_last_error() ) {
				throw new Exception( "JSON parse error for customize_previewed {$post->ID}: error code " . json_last_error() );
			}
		}
		return $value;
	}

	/**
	 * Handle Ajax request that notifies of the settings previewed.
	 *
	 * @throws Exception But it is caught. This line is here because of a WordPress-Docs PHPCS bug.
	 * @action wp_ajax_customize_settings_previewed
	 */
	public function ajax_customize_settings_previewed() {
		try {
			if ( ! check_ajax_referer( 'preview-customize_' . $this->customize_manager->get_stylesheet(), 'nonce', false ) ) {
				throw new Exception( 'bad_nonce', 403 );
			}
			if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) { // WPCS: input var okay; sanitization ok.
				throw new Exception( 'bad_method', 405 );
			}
			$params = array(
				'nonce' => isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : null, // WPCS: input var ok.
				'last_update_timestamp_cursor' => isset( $_POST['last_update_timestamp_cursor'] ) ? intval( wp_unslash( $_POST['last_update_timestamp_cursor'] ) ) : null, // WPCS: input var ok.
			);
			wp_send_json_success( $this->request_customize_settings_previewed( $params ) );
		} catch ( Exception $e ) {
			$code = $e->getCode();
			$message = $e->getMessage();
			wp_send_json_error( compact( 'code', 'message' ) );
		}
	}

	/**
	 * Do logic for customized_settings_previewed Ajax request.
	 *
	 * @param array $params {
	 *     Params as passed by ajax_customize_settings_previewed().
	 *
	 *     @type string $nonce                        The preview-customize_{stylesheet} nonce.
	 *     @type int    $last_update_timestamp_cursor Last previewed timestamp.
	 * }
	 * @throws Exception  User is not logged in, if they can't customize, or if a param is missing.
	 * @return array
	 */
	public function request_customize_settings_previewed( array $params ) {
		if ( ! is_user_logged_in() ) {
			throw new Exception( 'not_logged_in', 403 );
		}
		if ( ! current_user_can( 'customize' ) ) {
			throw new Exception( 'unauthorized', 403 );
		}
		if ( empty( $this->customize_manager ) ) {
			throw new Exception( 'customize_off', 400 );
		}
		$action = 'preview-customize_' . $this->customize_manager->get_stylesheet();
		if ( ! wp_verify_nonce( $params['nonce'], $action ) ) {
			throw new Exception( 'bad_nonce', 403 );
		}
		$customized = $this->customize_manager->unsanitized_post_values();
		if ( empty( $customized ) ) {
			throw new Exception( 'customized_empty', 400 );
		}
		if ( empty( $params['last_update_timestamp_cursor'] ) ) {
			throw new Exception( 'missing_last_update_timestamp_cursor', 400 );
		}

		/**
		 * Filters which settings have been previewed.
		 *
		 * @param array $customized Unsanitized post values.
		 * @return array
		 */
		$customized = apply_filters( 'customize_concurrency_settings', $customized );

		$settings = array();
		foreach ( array_keys( $customized ) as $setting_id ) {
			$setting = $this->customize_manager->get_setting( $setting_id );
			if ( ! $setting ) {
				throw new Exception( "unknown_setting: $setting_id", 404 );
			}
			$setting->preview();
			$sanitized_value = $setting->post_value();
			$settings[ $setting_id ] = $sanitized_value;
		}

		$previewed_settings = array();
		foreach ( $settings as $setting_id => $sanitized_value ) {
			$results = $this->save_previewed_setting( $setting_id, array(
				'sanitized_value' => $sanitized_value,
				'preview_timestamp_cursor' => $params['last_update_timestamp_cursor'],
			) );
			$previewed_settings[ $setting_id ] = $results;
		}

		return array(
			'previewed_settings' => $previewed_settings,
		);
	}

	/**
	 * Locate the customize_previewed post for the given setting ID.
	 *
	 * @param string $setting_id The ID for the setting to look up.
	 * @return \WP_Post|null
	 */
	public function find_post( $setting_id ) {
		$post = null;
		$post_id = wp_cache_get( $setting_id, self::CACHE_GROUP );
		if ( $post_id && ( $post = get_post( $post_id ) ) ) {
			return $post;
		}

		$query_vars = array(
			'name' => $setting_id,
			'post_type' => self::POST_TYPE,
			'posts_per_page' => 1,
			'post_status' => array( 'publish', 'draft' ),
		);

		$posts = $this->with_sanitize_title_suspended( function () use ( $query_vars ) {
			return get_posts( $query_vars );
		} );
		if ( empty( $posts ) ) {
			return null;
		}

		$post = array_shift( $posts );
		wp_cache_set( $setting_id, $post->ID, self::CACHE_GROUP );

		return $post;
	}

	/**
	 * Persist setting_id=>post_id lookup cache for newly-created customize_previewed posts.
	 *
	 * @param int      $post_id The post ID.
	 * @param \WP_Post $post    The post object.
	 * @param bool     $update  Whether this is an existing post being updated or not.
	 */
	public function handle_insert_customize_previewed_post( $post_id, $post, $update ) {
		if ( ! $update ) {
			unset( $post_id );
			$setting_id = $post->post_name;
			wp_cache_set( $setting_id, $post->ID, self::CACHE_GROUP );
		}
	}

	/**
	 * Clear setting_id=>post_id lookup cache when a customize_previewed post is deleted.
	 *
	 * @param int $post_id The customize_previewed post ID.
	 */
	public function handle_delete_customize_previewed_post( $post_id ) {
		$post = get_post( $post_id );
		if ( empty( $post ) || self::POST_TYPE !== $post->post_type ) {
			return;
		}
		$setting_id = $post->post_name;
		add_action( 'deleted_post', function ( $deleted_post_id ) use ( $post_id, $setting_id ) {
			if ( $post_id === $deleted_post_id ) {
				wp_cache_delete( $setting_id, Customize_Concurrency::CACHE_GROUP );
			}
		} );
	}

	/**
	 * Call the supplied function with the sanitize_title filter returning the raw_title.
	 *
	 * @param callable $callback  Function to call while the filter is suspended.
	 * @return mixed The value returned by the callback.
	 * @throws \Exception But it is caught. This line is just for a WordPress-Docs PHPCS bug.
	 */
	public function with_sanitize_title_suspended( $callback ) {
		$filter_sanitize_title = function ( $title, $raw_title, $context ) {
			unset( $title );
			$title = $raw_title;
			if ( 'query' === $context ) {
				$title = esc_sql( $title );
			}
			return $title;
		};
		try {
			add_filter( 'sanitize_title', $filter_sanitize_title, 10, 3 );
			$retval = call_user_func( $callback );
			remove_filter( 'sanitize_title', $filter_sanitize_title, 10 );
		} catch ( \Exception $e ) {
			remove_filter( 'sanitize_title', $filter_sanitize_title, 10 ); // PHP always block.
			throw $e;
		}
		return $retval;
	}

	/**
	 * Insert/update the latest previewed setting.
	 *
	 * @param string $setting_id The setting ID to save.
	 * @param array  $args {
	 *     Optional. Array of default arguments.
	 *
	 *     @type mixed  $sanitized_value          The setting value to save.
	 *     @type string $preview_timestamp_cursor An existing setting preview post's timestamp must be less than this, or else rejected.
	 *     @type string $post_status
	 * }
	 * @return array {
	 *     @type string $status
	 *     @type int $post_id
	 *     @type array|null $post_author
	 *     @type int|null $post_date
	 *     @type string|null $post_status
	 *     @type mixed|null $value
	 * }
	 */
	public function save_previewed_setting( $setting_id, $args ) {
		$args = array_merge( array(
			'sanitized_value' => null,
			'preview_timestamp_cursor' => -1,
			'post_status' => 'draft',
		), $args );

		$previous_preview_timestamp = null;
		$sanitized_value = $args['sanitized_value'];
		$preview_timestamp_cursor = $args['preview_timestamp_cursor'];
		$post_status = $args['post_status'];

		$revision_number = 0;
		$previous_preview_value = null;
		$previous_preview_user = null;
		$found_preview_timestamp = null;

		$post = $this->find_post( $setting_id );
		if ( $post ) {
			$revision_number = get_post_meta( $post->ID, self::POSTMETA_KEY, true );
			$previous_preview_user = $this->get_preview_user_data( $post->post_author );
			$found_preview_timestamp = intval( mysql2date( 'U', $post->post_date_gmt ) );

			$should_reject_preview = (
				'publish' !== $post_status
				&&
				intval( $post->post_author ) !== intval( get_current_user_id() )
				&&
				$preview_timestamp_cursor
				&&
				$found_preview_timestamp > $preview_timestamp_cursor
			);

			$setting = $this->customize_manager->get_setting( $setting_id );
			$value = json_decode( $post->post_content_filtered, true );
			if ( ! preg_match( '/sidebars_widgets\]?\[/', $setting_id ) ) {
				$value = apply_filters( "customize_sanitize_js_{$setting_id}", $value, $setting );
			}

			if ( $should_reject_preview ) {
				return array(
					'status' => 'rejected',
					'post_id' => $post->ID,
					'post_author' => $previous_preview_user,
					'post_status' => $post->post_status,
					'post_date' => $found_preview_timestamp,
					'value' => $value,
					'revision_number' => $revision_number,
					'previous_previewer' => $previous_preview_user,
					'previous_preview_timestamp' => $found_preview_timestamp,
					'previous_preview_value' => $previous_preview_value,
				);
			}

			$previous_preview_timestamp = $found_preview_timestamp;
			$previous_preview_value = $value;
		}

		$post_data = array(
			'post_type' => self::POST_TYPE,
			'post_status' => $post_status,
			'post_name' => $setting_id,
			'post_content_filtered' => wp_json_encode( $sanitized_value ),
			'post_date' => current_time( 'mysql', 0 ), // Must update post_date because post_modified doesn't have an INDEX for orderby queries.
			'post_date_gmt' => current_time( 'mysql', 1 ), // Must update post_date_gmt because post_modified_gmt doesn't have an INDEX for orderby queries.
			'post_author' => get_current_user_id(),
		);

		if ( $post ) {
			$post_data['ID'] = $post->ID;
			$post_data['post_title'] = $post->post_title;
		} else {
			$post_data['post_title'] = $setting_id;
		}
		$r = $this->with_sanitize_title_suspended( function () use ( $post_data ) {
			return wp_insert_post( $post_data, true );
		} );

		if ( is_wp_error( $r ) ) {
			return array(
				'status' => 'failed',
				'post_id' => $post ? $post->ID : null,
				'post_author' => $previous_preview_user,
				'post_status' => $post ? $post->post_status : null,
				'post_date' => $previous_preview_timestamp,
				'value' => $previous_preview_value,
				'revision_number' => $revision_number,
				'previous_previewer' => $previous_preview_user,
				'previous_preview_timestamp' => $found_preview_timestamp,
				'previous_preview_value' => $previous_preview_value,
			);
		}
		$post_id = $r;
		$post = get_post( $post_id );

		// Auto-increment a revision number which can be used for more granularity than just the post_date.
		$revision_number += 1;
		update_post_meta( $post_id, self::POSTMETA_KEY, $revision_number );

		return array(
			'status' => 'accepted',
			'post_id' => $post_id,
			'post_author' => $previous_preview_user,
			'post_status' => $post->post_status,
			'post_date' => $previous_preview_timestamp,
			'value' => $previous_preview_value,
			'revision_number' => $revision_number,
			'previous_previewer' => $previous_preview_user,
			'previous_preview_timestamp' => $found_preview_timestamp,
			'previous_preview_value' => $previous_preview_value,
		);
	}

	/**
	 * Update saved previewed settings with a publish status.
	 *
	 * This results in the setting value being pushed out to other user sessions
	 * and the settings being unlocked.
	 */
	public function customize_save_after() {
		$customized = $this->customize_manager->unsanitized_post_values();

		/**
		 * Filters which settings have been saved.
		 *
		 * @param array $customized Unsanitized post values.
		 * @return array
		 */
		$customized = apply_filters( 'customize_concurrency_settings', $customized );

		$concurrency_save_results = array();
		foreach ( array_keys( $customized ) as $setting_id ) {
			$setting = $this->customize_manager->get_setting( $setting_id );
			if ( ! $setting ) {
				continue;
			}
			$concurrency_save_results[ $setting_id ] = $this->save_previewed_setting( $setting_id, array(
				'sanitized_value' => $setting->value(),
				'post_status' => 'publish',
			) );
		}

		add_filter( 'customize_save_response', function ( $results ) use ( $concurrency_save_results ) {
			$results['concurrency_save_results'] = $concurrency_save_results;
			return $results;
		} );
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
	 * Get the preview user data.
	 *
	 * @param int $user_id The user ID.
	 * @return array|null
	 */
	public function get_preview_user_data( $user_id ) {
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
