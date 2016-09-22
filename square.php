<?php
/*
Plugin Name: WP eCommerce Square
Plugin URI: https://wpecommerce.org
Version: 1.0
Author: WP eCommerce
Description: A plugin that allows the store owner to process payments using Square
Author URI:  https://wpecommerce.org
*/

if ( ! defined( 'WPEC_SQUARE_PLUGIN_DIR' ) ) {
	define( 'WPEC__SQUARE_PLUGIN_DIR', dirname( __FILE__ ) );
}
if ( ! defined( 'WPEC_SQUARE_PLUGIN_URL' ) ) {
	define( 'WPEC_SQUARE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if( version_compare( PHP_VERSION, '5.3', '<' ) ) {
	add_action( 'admin_notices', 'wpsc_sq_below_php_version_notice' );
	function wpsc_sq_below_php_version_notice() {
		echo '<div class="error"><p>' . __( 'Your version of PHP is below the minimum version of PHP required by WP eCommerce - Square Payment Gateway. Please contact your host and request that your version be upgraded to 5.3 or later.', 'wpec-square' ) . '</p></div>';
	}
	return;
}

function wpsc_sqr_register_file() {
	wpsc_register_payment_gateway_file( dirname(__FILE__) . '/square-payments.php' );
}
add_filter( 'wpsc_init', 'wpsc_sqr_register_file' );
