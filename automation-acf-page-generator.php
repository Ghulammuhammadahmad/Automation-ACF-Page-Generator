<?php
/**
 * Plugin Name: Automation ACF Page Generator (OpenAI)
 * Description: Generates a child page from an Elementor template category and populates ACF fields using OpenAI JSON Schema.
 * Version: 1.0.0
 * Author: CSC Dallas Workspace
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) { exit; }

define('AAPG_PLUGIN_VERSION', '1.0.0');
define('AAPG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AAPG_PLUGIN_URL', plugin_dir_url(__FILE__));

define('AAPG_DEFAULT_PROMPT_ID', 'pmpt_697732cd233081979612e14e3c8b8f260bc2b578e7052e41');
define('AAPG_DEFAULT_PROMPT_VERSION', '1');

define('AAPG_OPTION_KEY', 'aapg_settings');

define('AAPG_TEMPLATE_CATEGORY_SLUG', 'hubtemplates');

require_once AAPG_PLUGIN_DIR . 'includes/class-aapg-plugin.php';

add_action('plugins_loaded', function () {
    \AAPG\Plugin::instance();
});
