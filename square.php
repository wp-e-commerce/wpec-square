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

function temp_header_js() {
	
	wp_enqueue_script( 'square_payments', 'https://js.squareup.com/v2/paymentform', 'jquery', false, true );
	
	wp_localize_script(
		'square_payments',
		'square_payments_params',
		apply_filters( 'wpsc_square_payments_params', array(
			'applicationId'    => 'sandbox-sq0idp-NlBNg1PJlJf_ft9-iLLEXw',
		) )
	);
	
}

//add_action( 'init', 'temp_header_js', 0 );
