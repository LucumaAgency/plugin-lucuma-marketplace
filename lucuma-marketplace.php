<?php
/**
 * Plugin Name: Lucuma Marketplace
 * Description: Heavy equipment marketplace core: equipment listings, categories, makes and models, specs per category, dealers.
 * Version: 0.2.0
 * Author: Lucuma Agency
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Text Domain: lucuma-marketplace
 */

defined( 'ABSPATH' ) || exit;

define( 'LM_VERSION', '0.2.0' );
define( 'LM_DIR', __DIR__ );

require_once LM_DIR . '/includes/class-lm-model.php';
require_once LM_DIR . '/includes/class-lm-specs.php';
require_once LM_DIR . '/includes/class-lm-admin.php';
require_once LM_DIR . '/includes/class-lm-rest.php';

LM_Model::init();
LM_Admin::init();
LM_Rest::init();

register_activation_hook( __FILE__, function () {
	LM_Model::register();
	LM_Model::add_roles();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );
