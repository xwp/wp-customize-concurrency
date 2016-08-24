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
	 * Title of post (setting id) before being destroyed by sanitize_post
	 *
	 * @var string $saved_setting_title
	 */
	protected $saved_setting_title = '';

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
		add_action( 'customize_preview_init', array( $this, 'customize_preview_init' ) );
		add_action( 'customize_controls_print_footer_scripts', array( $this, 'customize_controls_print_footer_scripts' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'preserve_inserted_post_name' ), 10, 2 );
		add_action( 'customize_snapshot_save_before', array( $this, 'customize_snapshot_save_before' ), 10, 2 );
//		add_action( 'customize_register', array( $this, 'customize_register' ), 30 );
		add_action( 'customize_save_after', array( $this, 'customize_save_after' ) );
		add_action( 'customize_snapshot_save', array( $this, 'customize_snapshot_save' ), 10, 2 );
		add_action( 'customize_save_response', array( $this, 'customize_save_response' ) );
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
		wp_enqueue_style( $this->plugin->slug );
	}

	/**
	 * Add script specifically for the preview pane.
	 *
	 * @action customize_preview_init
	 */
	function customize_preview_init() {
		wp_enqueue_script( "{$this->plugin->slug}-preview" );
	}

	/**
	 * Send timestamp for when this session started and output template.
	 */
	public function customize_controls_print_footer_scripts() {
		$data = array(
			'session_start_timestamp' => strtotime( current_time( 'mysql', true ) ),
		);

		printf( '<script>var _customizeConcurrency = %s;</script>', wp_json_encode( $data ) );

		?>
		<script type="text/html" id="tmpl-customize-concurrency-notifications">
			<ul>
				<# _.each( data.notifications, function( notification ) { #>
					<li class="notice notice-{{ notification.type || 'info' }} {{ data.altNotice ? 'notice-alt' : '' }} notice-concurrency_conflict" data-code="{{ notification.code }}" data-type="{{ notification.type }}">
						<# if ( /concurrency_conflict/.test( notification.code ) ) { #>
							<button class="button concurrency-conflict-override" type="button" data-tooltip="Reject Change"><span class="dashicons dashicons-thumbs-down"></span></button>
							<button class="button concurrency-conflict-accept" type="button" data-tooltip="Accept Change"><span class="dashicons dashicons-thumbs-up"></span></button>
							Conflict due to concurrent update by {{ notification.data.user }}.
							<p><b>Change:</b> <i>"{{ notification.data.their_value }}"</i></p>
						<# } #>
					</li>
				<# } ); #>
			</ul>
		</script>
		<?php

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

	/**
	 * Set up conflict checking within snapshots.
	 */
	public function customize_snapshot_save_before( $snapshot, \WP_Customize_Manager $wp_customize ) {
		$saved_settings = $snapshot->data();
		$this->sanitize_conflicts( $saved_settings, $wp_customize );
	}

	/**
	 * Set up conflict checking without snapshots.
	 *
	 * @todo detect snapshot save and do not validate against live changes
	 */
	public function customize_register( \WP_Customize_Manager $wp_customize ) {
		$post_values = $wp_customize->unsanitized_post_values();
		$saved_settings = $this->get_saved_settings( array_keys( $post_values ) );
		$this->sanitize_conflicts( $saved_settings, $wp_customize );
	}


	/**
	 * Check all incoming values to see if any were changed between the last time we checked and when we published these
	 * new values.
	 */
	public function sanitize_conflicts ($saved_settings, \WP_Customize_Manager $wp_customize ) {
		// @todo When publishing a snapshot outside the Customizer (e.g. from admin or WP Cron), the setting $timestamps should get supplied from modified times for setting updates in the snapshot?
		$timestamps = isset( $_POST['concurrency_timestamps'] ) ? (array) json_decode( wp_unslash( $_POST['concurrency_timestamps'] ) ) : array();
		$overrides = isset( $_POST['concurrency_overrides'] ) ? (array) json_decode( wp_unslash( $_POST['concurrency_overrides'] ) ) : array();

		$validate_concurrency_conflict = function( \WP_Error $validity, $value, \WP_Customize_Setting $setting ) use ( $timestamps, $saved_settings, $overrides ) {
			$saved_setting = isset( $saved_settings[ $setting->id ] ) ? $saved_settings[ $setting->id ] : null;
			$is_conflicted = (
				array_key_exists( $setting->id, $saved_settings )
				&&
				isset( $saved_setting['timestamp'], $saved_setting['value'] )
				&&
				( $saved_setting['timestamp'] > $timestamps[ $setting->id ] )
				&&
				$saved_setting['value'] !== $value
				&&
				empty( $overrides[ $setting->id ] )
			);
			if ( $is_conflicted ) {
				$user = get_user_by( 'ID', (int) $saved_setting['author'] );
				$message = __( 'Conflict due to concurrent update.', 'customize-concurrency' );
				$validity->add( 'concurrency_conflict', $message, array( 'their_value' => $saved_setting['value'], 'user' => $user->display_name ) );
			}
			return $validity;
		};

		// @todo fix $wp_customize->settings() is giving a 500/502 when called inside customize_snapshot_save_before
		foreach ( $wp_customize->settings() as $setting ) {
			add_filter( "customize_validate_{$setting->id}", $validate_concurrency_conflict, 1000, 3 );
		}
	}

	/**
	 * Store update history with timestamps.
	 *
	 * Runs when content is "Published" not when saved to snapshot.
	 */
	public function customize_save_after() {

		$post_values = $this->customize_manager->unsanitized_post_values();
		$setting_ids = array_keys( $post_values );

		// Exclude post field settings since these are already conflict checked by customize-post plugin.
		$setting_ids = array_filter( $setting_ids, function( $setting_id ) {
			return ( 0 !== strpos( $setting_id, 'post[' ) ) ? $setting_id : false;
		});

		$saved_settings = $this->get_saved_settings( $setting_ids );

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
		// @todo: check $r for errors.
	}

	/**
	 * Runs when a snapshot is saved/updated
	 */
	public function customize_snapshot_save( $data, $snapshot ) {
		// Since we only send timestamps for dirty values, this will give us the list of which settings have new values.
		$setting_ids = isset( $_POST['concurrency_timestamps'] ) ? array_keys( (array) json_decode( wp_unslash( $_POST['concurrency_timestamps'] ) ) ) : array();

		foreach ( $setting_ids as $setting_id ) {
			$data[ $setting_id ]['timestamp'] = current_time( 'mysql', 1 );
			$data[ $setting_id ]['author'] = get_current_user_id();
		}

		return $data;
	}

	/**
	 * Add concurrency_session_timestamp to customize_save_response.
	 *
	 * @param array $response Response.
	 *
	 * @return array
	 */
	public function customize_save_response( $response ) {
		$response['concurrency_session_timestamp'] = strtotime( current_time( 'mysql', true ) );
		return $response;
	}

	/**
	 * Get saved settings from default post storage or from snapshot storage.
	 *
	 * Todo: read from snapshots when applicable.
	 *
	 * @param array $setting_ids Setting IDs.
	 * @return array Saved settings.
	 */
	function get_saved_settings( $setting_ids ) {
		$saved_settings = array();

		add_filter( 'sanitize_title', array( $this, 'sanitize_title_for_query' ), 1000, 3 );
		$saved_setting_posts = new \WP_Query(array(
			'post_name__in' => $setting_ids,
			'post_type' => self::POST_TYPE,
			'post_status' => 'publish',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'posts_per_page' => -1,
			'ignore_sticky_posts' => true,
		));
		remove_filter( 'sanitize_title', array( $this, 'sanitize_title_for_query' ), 1000 );

		foreach ( $saved_setting_posts->posts as $setting_post ) {

			$saved_settings[ $setting_post->post_name ] = array(
				'post_id' => $setting_post->ID,
				'value' => json_decode( $setting_post->post_content_filtered, true ),
				'timestamp' => strtotime( $setting_post->post_modified_gmt ),
				'author' => $setting_post->post_author,
			);
		}

		return $saved_settings;
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

	/**
	 * Keep square brackets from being removed when the title is sanitized for the query in $this->get_saved_settings().
	 *
	 * @see get_saved_settings()
	 * @filter sanitize_title
	 *
	 * @param string $title     Sanitized title without our much needed brackets.
	 * @param string $raw_title Original title to revert to.
	 * @param string $context   Context.
	 * @return string Raw title.
	 */
	function sanitize_title_for_query( $title, $raw_title, $context ) {
		unset( $title, $context );
		return esc_sql( $raw_title );
	}
}
