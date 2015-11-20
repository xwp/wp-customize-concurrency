<?php
/**
 * Instantiates the Customize Concurrency plugin
 *
 * @package CustomizeConcurrency
 */

namespace CustomizeConcurrency;

global $customize_concurrency_plugin;

require_once __DIR__ . '/php/class-plugin-base.php';
require_once __DIR__ . '/php/class-plugin.php';

$customize_concurrency_plugin = new Plugin();

/**
 * Customize Concurrency Plugin Instance
 *
 * @return Plugin
 */
function get_plugin_instance() {
	global $customize_concurrency_plugin;
	return $customize_concurrency_plugin;
}
