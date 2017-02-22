<?php
/*
Plugin Name: WP eCommerce Square
Plugin URI: https://wpecommerce.org/store/product/square-payments/
Version: 1.0.0
Author: WP eCommerce
Description: A plugin that allows the store owner to process payments using Square
Author URI:  https://wpecommerce.org
*/

if ( ! defined( 'WPEC_SQUARE_PLUGIN_DIR' ) ) {
	define( 'WPEC_SQUARE_PLUGIN_DIR', dirname( __FILE__ ) );
}

if ( ! defined( 'WPEC_SQUARE_PLUGIN_URL' ) ) {
	define( 'WPEC_SQUARE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'WPEC_SQUARE_VERSION' ) ) {
	define( 'WPEC_SQUARE_VERSION', '1.0.0' );
}

if ( ! defined( 'WPEC_SQUARE_PRODUCT_ID' ) ) {
	define( 'WPEC_SQUARE_PRODUCT_ID', 481562 );
}

if( version_compare( PHP_VERSION, '5.3', '<' ) ) {
	add_action( 'admin_notices', 'wpsc_sq_below_php_version_notice' );
	function wpsc_sq_below_php_version_notice() {
		echo '<div class="error"><p>' . __( 'Your version of PHP is below the minimum version of PHP required by WP eCommerce Square. Please contact your host and request that your version be upgraded to 5.3 or later.', 'wpec-square' ) . '</p></div>';
	}
	return;
}

function wpsc_sqr_register_file() {
	wpsc_register_payment_gateway_file( dirname(__FILE__) . '/square-payments.php' );
}
add_filter( 'wpsc_init', 'wpsc_sqr_register_file' );

if( is_admin() ) {
	// setup the updater
	if( ! class_exists( 'WPEC_Product_Licensing_Updater' ) ) {
		// load our custom updater
		include( dirname( __FILE__ ) . '/WPEC_Product_Licensing_Updater.php' );
	}

	function wpec_square_plugin_updater() {
		// retrieve our license key from the DB
		$license = get_option( 'wpec_product_'. WPEC_SQUARE_PRODUCT_ID .'_license_active' );
		$key = ! $license ? '' : $license->license_key;
		// setup the updater
		$wpec_updater = new WPEC_Product_Licensing_Updater( 'https://wpecommerce.org', __FILE__, array(
				'version' 	=> WPEC_SQUARE_VERSION, 				// current version number
				'license' 	=> $key, 		// license key (used get_option above to retrieve from DB)
				'item_id' 	=> WPEC_SQUARE_PRODUCT_ID 	// id of this plugin
			)
		);
	}
	add_action( 'admin_init', 'wpec_square_plugin_updater', 0 );
}