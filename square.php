<?php
/*
Plugin Name: WP eCommerce Square
Plugin URI: https://wpecommerce.org
Version: 1.0
Author: WP eCommerce
Description: A plugin that allows the store owner to process payments using Square
Author URI:  https://wpecommerce.org
*/



function wpsc_sqr_register_file() {
	wpsc_register_payment_gateway_file( dirname(__FILE__) . '/square-payments.php' );
}
add_filter( 'wpsc_init', 'wpsc_sqr_register_file' );
