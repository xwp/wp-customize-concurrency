<?php
/**
 * Bootstraps the Customize Concurrency plugin.
 *
 * @package CustomizeConcurrency
 */

namespace CustomizeConcurrency;

/**
 * Main plugin bootstrap file.
 */
class Plugin extends Plugin_Base {

	/**
	 * Customize concurrency instance.
	 *
	 * @var Customize_Concurrency
	 */
	public $customize_concurrency;

	/**
	 * Class constructor.
	 *
	 * @codeCoverageIgnore
	 */
	public function __construct() {
		parent::__construct();

		$priority = 9; // Because WP_Customize_Widgets::register_settings() happens at after_setup_theme priority 10.
		add_action( 'after_setup_theme', array( $this, 'init' ), $priority );
	}

	/**
	 * Initiate the plugin resources.
	 *
	 * @action after_setup_theme
	 */
	function init() {
		add_action( 'wp_default_scripts', array( $this, 'register_scripts' ), 11 );
		add_action( 'wp_default_styles', array( $this, 'register_styles' ), 11 );
		$this->customize_concurrency = new Customize_Concurrency( $this );
	}

	/**
	 * Register scripts.
	 *
	 * @param \WP_Scripts $wp_scripts Instance of \WP_Scripts.
	 * @action wp_default_scripts
	 */
	function register_scripts( \WP_Scripts $wp_scripts ) {
		$src = $this->dir_url . 'js/customize-concurrency.js';
		$deps = array( 'heartbeat', 'customize-widgets', 'underscore' );
		$wp_scripts->add( $this->slug, $src, $deps );
		$wp_scripts->add_data( $this->slug, 'group', 1 ); // 1 = in_footer.
	}

	/**
	 * Register styles.
	 *
	 * @param \WP_Styles $wp_styles Instance of \WP_Styles.
	 * @action wp_default_styles
	 */
	function register_styles( \WP_Styles $wp_styles ) {
		$src = $this->dir_url . 'css/customize-concurrency.css';
		$deps = array( 'customize-controls' );
		$wp_styles->add( $this->slug, $src, $deps );
	}
}
