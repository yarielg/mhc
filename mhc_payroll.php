<?php
/*
*
* @package yariko


Plugin Name:  MHC Payroll
Plugin URI:   https://webreadynow.com/
Description:  Creates a custom flow for payroll
Version:      1.0.0
Author:       WRN
Author URI:   https://webreadynow.com/
Tested up to: 6.8.2
Text Domain:  whc_payroll
Domain Path:  /languages
*/

defined('ABSPATH') or die('You do not have access, sally human!!!');

define ( 'MHC_PLUGIN_VERSION', '1.0.0');

if( file_exists( dirname( __FILE__ ) . '/vendor/autoload.php') ){
    require_once  dirname( __FILE__ ) . '/vendor/autoload.php';
}

// global constants for AJAX controllers
if (!defined('MHC_DEFAULT_CAPABILITY')) {
    define('MHC_DEFAULT_CAPABILITY', 'manage_options');
}
if (!defined('MHC_DEFAULT_NONCE_ACTION')) {
    define('MHC_DEFAULT_NONCE_ACTION', 'mhc_ajax');
}
define('MHC_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define('MHC_PLUGIN_URL' , plugin_dir_url(  __FILE__  ) );
define('MHC_ADMIN_URL' , get_admin_url() );
define('MHC_PLUGIN_DIR_BASENAME' , dirname(plugin_basename(__FILE__)) );

//include the helpers
include 'inc/util/helpers.php';

if( class_exists( 'Mhc\\Inc\\Init' ) ){
    register_activation_hook( __FILE__ , array('Mhc\\Inc\\Base\\Activate','activate') );
    register_deactivation_hook( __FILE__ , array('Mhc\\Inc\\Base\\Deactivate','deactivate') );
    Mhc\Inc\Init::register_services();
}




