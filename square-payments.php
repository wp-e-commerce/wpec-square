<?php
class WPSC_Payment_Gateway_Square_Payments extends WPSC_Payment_Gateway {

	private $endpoints = array(
		'sandbox' => 'https://connect.squareup.com/v2/',
		'production' => 'https://connect.squareup.com/v2/',
	);
	private $endpoint;
	private $sandbox;
	private $payment_capture;
	private $order_handler;
	public $location_id;
	
	public function __construct() {
		parent::__construct();
		
		$this->title 			= __( 'Square', 'wpsc' );
		$this->supports 		= array( 'default_credit_card_form', 'tev2' );
		$this->sandbox			= $this->setting->get( 'sandbox_mode' ) == '1' ? true : false;
		$this->endpoint			= $this->sandbox ? $this->endpoints['sandbox'] : $this->endpoints['production'];
		$this->payment_capture 	= $this->setting->get( 'payment_capture' ) !== null ? $this->setting->get( 'payment_capture' ) : '';
		$this->order_handler	= WPSC_Square_Payments_Order_Handler::get_instance( $this );
		
		// Define user set variables
		$this->app_id			= $this->setting->get( 'app_id' );
		$this->location_id		= $this->setting->get( 'location_id' );
		$this->acc_token  		= $this->setting->get( 'acc_token' );
	}

	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'square_scripts' ) );
		add_action( 'wp_head'	, array( $this, 'footer_script' ) );
		
		// Add extra card field
		add_action( 'wpsc_default_credit_card_form_end', array( $this, 'insert_extra_card_field_to_form' ) );
		add_filter( 'wpsc_default_credit_card_form_fields', array( $this, 'remove_default_card_fields' ), 99, 2 );
		
		add_filter( 'wpsc_get_checkout_payment_method_form_args', array( $this, 'te_v2_show_payment_fields' ) );
		// Add hidden field to hold token value
		add_filter( 'wpsc_get_checkout_payment_method_form_args', array( $this, 'insert_reference_id_to_form' ) );
		add_filter(
			'wpsc_payment_method_form_fields',
			array( 'WPSC_Payment_Gateway_Square_Payments', 'filter_unselect_default' ), 100 , 1
		);
	}
	
	/**
	 * Load gateway only if PHP 5.3+ and TEv2.
	 *
	 * @return bool Whether or not to load gateway.
	 */
	public static function load() {
		return version_compare( phpversion(), '5.3', '>=' ) && function_exists( '_wpsc_get_current_controller' );
	}
	
	public function remove_default_card_fields( $fields, $gateway ) {
		return array();	
	}
	
	public function insert_extra_card_field_to_form ( $gateway ) {

		echo '<p class="form-row form-row-last">
					<label for="' . esc_attr( $gateway ) . '-card-number">' . __( 'Card Number', 'wp-e-commerce' ) . ' <span class="required">*</span></label>
					<div id="' . esc_attr( $gateway ) . '-card-number" /></div>
				</p>';

		echo '<p class="form-row form-row-last">
					<label for="' . esc_attr( $gateway ) . '-card-expiry">' . __( 'Expiration Date (MM/YY)', 'wp-e-commerce' ) . ' <span class="required">*</span></label>
					<div id="' . esc_attr( $gateway ) . '-card-expiry" /></div>
				</p>';

		echo '<p class="form-row form-row-last">
					<label for="' . esc_attr( $gateway ) . '-card-cvc">' . __( 'Card Code', 'wp-e-commerce' ) . ' <span class="required">*</span></label>
					<div id="' . esc_attr( $gateway ) . '-card-cvc" /></div>
				</p>';
				
		echo '<p class="form-row form-row-last">
					<label for="' . esc_attr( $gateway ) . '-card-zip">' . __( 'Card Zip', 'wp-e-commerce' ) . ' <span class="required">*</span></label>
					<div id="' . esc_attr( $gateway ) . '-card-zip" placeholder="' . esc_attr__( 'Card Zip', 'wp-e-commerce' ) . '" /></div>
				</p>';
	}
	
	/**
	 * No payment gateway is selected by default
	 *
	 * @access public
	 * @param array $fields
	 * @return array
	 *
	 * @since 3.9
	 */
	public static function filter_unselect_default( $fields ) {
		foreach ( $fields as $i => $field ) {
			$fields[ $i ][ 'checked' ] = false;
		}

		return $fields;
	}
	
	public function te_v2_show_payment_fields( $args ) {
		$default = '<div class="wpsc-form-actions">';
		ob_start();
		$this->payment_fields();
		$fields = ob_get_clean();
		$args['before_form_actions'] = $fields . $default;
		return $args;
	}
	
	//$this->payment_fields to show the card fields but where ?
	
	/**
	 * Add scripts
	 */
	public function square_scripts() {
		if ( ! wpsc_is_cart() && ! wpsc_is_checkout() ) {
			return;
		}
		
		wp_enqueue_script( 'squareup', 'https://js.squareup.com/v2/paymentform' );
	}
	
	public function footer_script() {
		if ( ! wpsc_is_cart() && ! wpsc_is_checkout() ) {
			return;
		}
		
		require_once( WPSC_TE_V2_CLASSES_PATH . '/checkout-wizard.php' );
		$wizard = WPSC_Checkout_Wizard::get_instance();
		
		if ( $wizard->active_step != 'payment' ) {
			return;
		}
		
		?>
		<style type="text/css">
		.square-card {
		}
		.square-card--error {
		  /* Indicates how form inputs should appear when they contain invalid values */
		  outline: 5px auto rgb(255, 97, 97);
		}
		</style>
		
		<script type='text/javascript'>
			var alerts = '';
			var sqPaymentForm = new SqPaymentForm({
				applicationId: '<?php echo $this->app_id; ?>',
				inputClass: 'square-card',
				inputStyles: [
				  {
					fontSize: '15px'
				  }
				],
				cardNumber: {
				  elementId: 'square_payments-card-number',
				},
				cvv: {
				  elementId: 'square_payments-card-cvc',
				},
				expirationDate: {
				  elementId: 'square_payments-card-expiry',
				},
				postalCode: {
				  elementId: 'square_payments-card-zip',
				},
				callbacks: {

				  // Called when the SqPaymentForm completes a request to generate a card
				  // nonce, even if the request failed because of an error.
				  cardNonceResponseReceived: function(errors, nonce, cardData) {
					if (errors) {
					  console.log("Encountered errors:");

					  // This logs all errors encountered during nonce generation to the
					  // Javascript console.
					  errors.forEach(function(error) {
						alerts += error.message + '\n';
						console.log('  ' + error.message);
					  });
						alert(alerts);
						return;
					// No errors occurred. Extract the card nonce.
					} else {
						//alert('Nonce received! ' + nonce + ' ' + JSON.stringify(cardData));
						var nonceField = document.getElementById('square_card_nonce');
						nonceField.value = nonce;
						document.getElementById('wpsc-checkout-form').submit();
					}
				  },

				  paymentFormLoaded: function() {
					// Fill in this callback to perform actions after the payment form is
					// done loading (such as setting the postal code field programmatically).
					// sqPaymentForm.setPostalCode('94103');
				  }
				}
			});
		
			jQuery( document ).ready( function( $ ) {
				$( '#wpsc-checkout-form' ).submit( function( e ) {
					e.preventDefault();
					sqPaymentForm.requestCardNonce();
				});
			});
		</script>
		<?php
	}
	
	// This needs to be inserted inside the checkout page
	public function insert_reference_id_to_form( $args ) {
		ob_start();
		echo '<input type="hidden" id="square_card_nonce" name="square_card_nonce" value="" />';
		
		$id = ob_get_clean();
		if ( isset( $args['before_form_actions'] ) ) {
			$args['before_form_actions'] .= $id;
		} else {
			$args['before_form_actions']  = $id;
		}
		return $args;
	}
	
	
	public function setup_form() {
?>
		<!-- Account Credentials -->
		<tr>
			<td colspan="2">
				<h4><?php _e( 'Account Credentials', 'wp-e-commerce' ); ?></h4>
			</td>
		</tr>
		<tr>
			<td>
				<label for="wpsc-worldpay-secure-net-id"><?php _e( 'Application ID', 'wp-e-commerce' ); ?></label>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'app_id' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'app_id' ) ); ?>" id="wpsc-worldpay-secure-net-id" />
				<br><span class="small description"><?php _e( 'Application ID.', 'wp-e-commerce' ); ?></span>
			</td>
		</tr>
		<tr>
			<td>
				<label for="wpsc-worldpay-secure-key"><?php _e( 'Access Token', 'wp-e-commerce' ); ?></label>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'acc_token' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'acc_token' ) ); ?>" id="wpsc-worldpay-secure-key" />
				<br><span class="small description"><?php _e( 'Access token.', 'wp-e-commerce' ); ?></span>
			</td>
		</tr>
		<tr>
			<td>
				<label for="wpsc-worldpay-secure-key"><?php _e( 'Location ID', 'wp-e-commerce' ); ?></label>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'location_id' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'location_id' ) ); ?>" id="wpsc-worldpay-secure-key" />
				<br><span class="small description"><?php _e( 'Store Location ID.', 'wp-e-commerce' ); ?></span>
			</td>
		</tr>
		<tr>
			<td>
				<label for="wpsc-worldpay-payment-capture"><?php _e( 'Payment Capture', 'wp-e-commerce' ); ?></label>
			</td>
			<td>
				<select id="wpsc-worldpay-payment-capture" name="<?php echo esc_attr( $this->setting->get_field_name( 'payment_capture' ) ); ?>">
					<option value='' <?php selected( '', $this->setting->get( 'payment_capture' ) ); ?>><?php _e( 'Authorize and capture the payment when the order is placed.', 'wp-e-commerce' )?></option>
					<option value='authorize' <?php selected( 'authorize', $this->setting->get( 'payment_capture' ) ); ?>><?php _e( 'Authorize the payment when the order is placed.', 'wp-e-commerce' )?></option>
				</select>
			</td>
		</tr>
		<tr>
			<td>
				<label><?php _e( 'Sandbox Mode', 'wp-e-commerce' ); ?></label>
			</td>
			<td>
				<label><input <?php checked( $this->setting->get( 'sandbox_mode' ) ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'sandbox_mode' ) ); ?>" value="1" /> <?php _e( 'Yes', 'wp-e-commerce' ); ?></label>&nbsp;&nbsp;&nbsp;
				<label><input <?php checked( (bool) $this->setting->get( 'sandbox_mode' ), false ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'sandbox_mode' ) ); ?>" value="0" /> <?php _e( 'No', 'wp-e-commerce' ); ?></label>
			</td>
		</tr>
		<!-- Error Logging -->
		<tr>
			<td colspan="2">
				<h4><?php _e( 'Error Logging', 'wp-e-commerce' ); ?></h4>
			</td>
		</tr>
		<tr>
			<td>
				<label><?php _e( 'Enable Debugging', 'wp-e-commerce' ); ?></label>
			</td>
			<td>
				<label><input <?php checked( $this->setting->get( 'debugging' ) ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'debugging' ) ); ?>" value="1" /> <?php _e( 'Yes', 'wp-e-commerce' ); ?></label>&nbsp;&nbsp;&nbsp;
				<label><input <?php checked( (bool) $this->setting->get( 'debugging' ), false ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'debugging' ) ); ?>" value="0" /> <?php _e( 'No', 'wp-e-commerce' ); ?></label>
			</td>
		</tr>
<?php
	}
	
	public function process() {
		
		$order = $this->purchase_log;
		$status = $this->payment_capture === '' ? WPSC_Purchase_Log::ACCEPTED_PAYMENT : WPSC_Purchase_Log::ORDER_RECEIVED;
		
		// Check card token created, if not error out ?
		$card_token = isset( $_POST['square_card_nonce'] ) ? sanitize_text_field( $_POST['square_card_nonce'] ) : '';

		$order->set( 'processed', $status )->save();
	
		$this->order_handler->set_purchase_log( $order->get( 'id' ) );
	
		switch ( $this->payment_capture ) {
			case 'authorize' :
				// Authorize only
				$result = $this->capture_payment( $card_token, true );
				if ( $result ) {
					// Mark as on-hold
					$order->set( 'square-status', __( 'Square order opened. Capture the payment below. Authorized payments must be captured within 6 days.', 'wp-e-commerce' ) )->save();
				} else {
					$order->set( 'processed', WPSC_Purchase_Log::PAYMENT_DECLINED )->save();
					$this->handle_declined_transaction( $order );
				}
			break;
			default:
				// Capture
				$result = $this->capture_payment( $card_token );
				if ( $result ) {
					// Payment complete
					$order->set( 'square-status', __( 'Square order completed.  Funds have been authorized and captured.', 'wp-e-commerce' ) );
				} else {
					$order->set( 'processed'      , WPSC_Purchase_Log::PAYMENT_DECLINED );
					$this->handle_declined_transaction( $order );
				}
			break;
		}
		
		$order->save();
		$this->go_to_transaction_results();
	}

	/**
	 * Handles declined transactions from Square.
	 *
	 * On the front-end, if a transaction is declined due to an invalid payment method, the user needs
	 * to be returned to the payment page to select a different method.
	 *
	 *
	 * @since  4.0
	 *
	 * @param  WPSC_Purchase_Log $order Current purchase log for transaction.
	 * @return void
	 */
	private function handle_declined_transaction( $order ) {
		$error_message = $order->get( 'square-status' );

		$url     = wpsc_get_cart_url();

		WPSC_Message_Collection::get_instance()->add( $error_message, 'error', 'main', 'flash' );
		wp_safe_redirect( $url );

		exit;
	}
	
	public function capture_payment( $card_token, $preauth = false ) {
		if ( $this->purchase_log->get( 'gateway' ) == 'square-payments' ) {
			$order = $this->purchase_log;
			
			$params = array(
				'card_nonce' 		=> $card_token,
				'reference_id'		=> $order->get( 'id' ),
				'buyer_email_address'	=> $this->checkout_data->get( 'billingemail' ),
				# Monetary amounts are specified in the smallest unit of the applicable currency.
				# This amount is in cents. It's also hard-coded for $1.00, which isn't very useful.
				'amount_money' 	=> array (
					'amount'	=> floatval( $order->get( 'totalprice' ) ) * 100,
					'currency'	=> strtoupper( $this->get_currency_code() ),
				),
				'billing_address'	=> array (
					'address_line_1'	=> $this->checkout_data->get( 'billingaddress' ),
					'locality'			=> $this->checkout_data->get( 'billingcity' ), // City
					'administrative_district_level_1'	=> $this->checkout_data->get( 'billingstate' ), // State
					'postal_code'		=> $this->checkout_data->get( 'billingpostcode' ), // Zip
					'country' 			=> $this->checkout_data->get( 'billingcountry' ), // The address's country, in ISO 3166-1-alpha-2 format.
					
				),
				'idempotency_key' 	=> uniqid(),
				'delay_capture'		=> $preauth,
			);
			
			$response = $this->execute( "locations/{$this->location_id}/transactions", $params );

			if( $response['ResponseBody']->errors ) {
				$order->set( 'square-status', $response['ResponseBody']->errors[0]->detail );
				return false;
			}
			
			$transaction_id = $response['ResponseBody']->transaction->id;
			
			// Store transaction ID in the order
			$order->set( 'sq_transactionid', $transaction_id )->save();
			$order->set( 'transactid'      , $transaction_id )->save();
			// Set order status based on the charge being auth or not
			$order_status = $preauth === true ? 'Open' : 'Completed';		
			$order->set( 'sq_order_status' , $order_status )->save();
			
			return true;
		}
		return false;
	}
	
	public function execute( $endpoint, $params = array(), $type = 'POST' ) {
	   // where we make the API petition
        $endpoint = $this->endpoint . $endpoint;
		$data = json_encode( $params );
		$args = array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->acc_token,
				'Content-Type'  => 'application/json',
				'Accept'		=> 'application/json',
			),
			'sslverify' => false,
			'body'      => $data,
		);
		$request  = $type == 'GET' ? wp_safe_remote_get( $endpoint, $args ) : wp_safe_remote_post( $endpoint, $args );
        $response = wp_remote_retrieve_body( $request );

		if ( ! is_wp_error( $request ) ) {
			$response_object = array();
			$response_object['ResponseBody'] = json_decode( $response );
			$response_object['Status']       = wp_remote_retrieve_response_code( $request );
			$response = $response_object;
		}
		return $response;
    }
}

class WPSC_Square_Payments_Order_Handler {
	private static $instance;
	public $log;
	public $gateway;
	
	public function __construct( &$gateway ) {
		$this->log     = $gateway->purchase_log;
		$this->gateway = $gateway;
		$this->init();
	}
	/**
	 * Constructor
	 */
	public function init() {
		add_action( 'wpsc_purchlogitem_metabox_start', array( $this, 'meta_box' ), 8 );
		add_action( 'wp_ajax_square_order_action'  , array( $this, 'order_actions' ) );
	}
	
	public static function get_instance( $gateway ) {
		if ( is_null( self::$instance ) ) {
			self::$instance = new WPSC_Square_Payments_Order_Handler( $gateway );
		}
		return self::$instance;
	}

	public function set_purchase_log( $id ) {
		$this->log = new WPSC_Purchase_Log( $id );
	}
	/**
	 * Perform order actions
	 */
	public function order_actions() {
		check_ajax_referer( 'sq_order_action', 'security' );
		$order_id = absint( $_POST['order_id'] );
		$id       = isset( $_POST['square_id'] ) ? sanitize_text_field( $_POST['square_id'] ) : '';
		$action   = sanitize_title( $_POST['square_action'] );
		$this->set_purchase_log( $order_id );
		switch ( $action ) {
			case 'capture' :
				//Capture an AUTH
				$this->capture_payment($id);
			break;
			case 'void' :
				// void auth before settled
				$this->void_payment( $id );
			break;
			case 'refund' :
				// refund a captured payment
				$this->refund_payment( $id );
			break;
		}
		echo json_encode( array( 'action' => $action, 'order_id' => $order_id, 'square_id' => $id ) );
		die();
	}
	/**
	 * meta_box function.
	 *
	 * @access public
	 * @return void
	 */
	function meta_box( $log_id ) {
		$this->set_purchase_log( $log_id );
		$gateway = $this->log->get( 'gateway' );
		
		if ( $gateway == 'square-payments' ) {
			$this->authorization_box();
		}
	}
	/**
	 * pre_auth_box function.
	 *
	 * @access public
	 * @return void
	 */
	public function authorization_box() {
		$actions  = array();
		$order_id = $this->log->get( 'id' );

		// Get ids
		$sq_transactionid 	= $this->log->get( 'sq_transactionid' );
		$sq_order_status	= $this->log->get( 'sq_order_status' );

		//Don't change order status if a refund has been requested
		$sq_refund_set = wpsc_get_purchase_meta( $order_id, 'square_refunded', true );
		$order_info    = $this->refresh_transaction_info( $sq_transactionid, ! (bool) $sq_refund_set );
		?>

		<div class="metabox-holder">
			<div id="wpsc-square-payments" class="postbox">
				<h3 class='hndle'><?php _e( 'Square Payments' , 'wp-e-commerce' ); ?></h3>
				<div class='inside'>
					<p><?php
							_e( 'Current status: ', 'wp-e-commerce' );
							echo wp_kses_data( $this->log->get( 'square-status' ) );
						?>
					</p>
					<p><?php
							_e( 'Transaction ID: ', 'wp-e-commerce' );
							echo wp_kses_data( $sq_transactionid );
						?>
					</p>
		<?php
		//Show actions based on order status
		switch ( $sq_order_status ) {
			case 'Open' :
				//Order is only authorized and still not captured/voided
				$actions['capture'] = array(
					'id'     => $sq_transactionid,
					'button' => __( 'Capture funds', 'wp-e-commerce' )
				);
				//
				if ( ! $order_info['settled'] ) {
					//Void
					$actions['void'] = array(
						'id'     => $sq_transactionid,
						'button' => __( 'Void order', 'wp-e-commerce' )
					);
				}
				break;
			case 'Completed' :
				//Order has been captured or its a direct payment
				if ( $order_info['settled'] ) {
					//Refund
					$actions['refund'] = array(
						'id'     => $sq_transactionid,
						'button' => __( 'Refund order', 'wp-e-commerce' )
					);
				}
			break;
			case 'Refunded' :
			break;
		}
		if ( ! empty( $actions ) ) {
			echo '<p class="buttons">';
			foreach ( $actions as $action_name => $action ) {
				echo '<a href="#" class="button" data-action="' . $action_name . '" data-id="' . $action['id'] . '">' . $action['button'] . '</a> ';
			}
			echo '</p>';
		}
		?>
		<script type="text/javascript">
		jQuery( document ).ready( function( $ ) {
			$('#wpsc-square-payments').on( 'click', 'a.button, a.refresh', function( e ) {
				var $this = $( this );
				e.preventDefault();
				var data = {
					action: 		'square_order_action',
					security: 		'<?php echo wp_create_nonce( "sq_order_action" ); ?>',
					order_id: 		'<?php echo $order_id; ?>',
					square_action: 	$this.data('action'),
					square_id: 		$this.data('id'),
					square_refund_amount: $('.square_refund_amount').val(),
				};
				// Ajax action
				$.post( ajaxurl, data, function( result ) {
						location.reload();
					}, 'json' );
				return false;
			});
		} );
		</script>
		</div>
		</div>
		</div>
		<?php
	}
	
    /**
     * Get the order status from API
     *
     * @param  string $transaction_id
     */
	public function refresh_transaction_info( $transaction_id, $update = true ) {
		if ( $this->log->get( 'gateway' ) == 'square-payments' ) {

			$response = $this->gateway->execute( "locations/{$this->gateway->location_id}/transactions/$transaction_id", null, 'GET' );
			if ( is_wp_error( $response ) ) {
				throw new Exception( $response->get_error_message() );
			}
			$response_object = array();
			$response_object['trans_type'] = $response['ResponseBody']->transactions[0]->transactionType;
			$response_object['settled']    = isset( $response['ResponseBody']->transactions[0]->settlementData ) ? true : false;
			//Recheck status and update if required
			if ( $update ) {
				switch ( $response_object['trans_type'] ) {
					case 'AUTH_ONLY' :
						$this->log->set( 'sq_order_status', 'Open' )->save();
					break;
					case 'VOID' :
						$this->log->set( 'sq_order_status', 'Voided' )->save();
					break;
					case 'REFUND' :
					case 'CREDIT' :
						$this->log->set( 'sq_order_status', 'Refunded' )->save();
					break;
					case 'AUTH_CAPTURE' :
					case 'PRIOR_AUTH_CAPTURE' :
						$this->log->set( 'sq_order_status', 'Completed' )->save();
					break;
				}
			}
			return $response_object;
		}
	}
	
    /**
     * Void auth/capture
     *
     * @param  string $transaction_id
     */
    public function void_payment( $transaction_id ) {
		if ( $this->log->get( 'gateway' ) == 'square-payments' ) {
			$response = $this->gateway->execute( "locations/{$this->location_id}/transactions/{$transaction_id}/void", $params, null );
			
			if ( is_wp_error( $response ) ) {
				throw new Exception( $response->get_error_message() );
			}
			
			$this->log->set( 'sq_order_status', 'Voided' )->save();
			$this->log->set( 'square-status', 'Authorization voided ' )->save();
			$this->log->set( 'processed'      , WPSC_Purchase_Log::INCOMPLETE_SALE )->save();
		}
    }
	
    /**
     * Refund payment
     *
     * @param  string $transaction_id
     */
    public function refund_payment( $transaction_id ) {
		if ( $this->log->get( 'gateway' ) == 'square-payments' ) {
			$params = array(
				'idempotency_key' 	=> uniqid(),
				'tender_id'			=> '',
				# Monetary amounts are specified in the smallest unit of the applicable currency.
				# This amount is in cents. It's also hard-coded for $1.00, which isn't very useful.
				'amount_money' 		=> array (
					'amount'	=> floatval( $order->get( 'totalprice' ) ) * 100,
					'currency'	=> strtoupper( $this->get_currency_code() ),
				),				
			);
			
			$response = $this->gateway->execute( "locations/{$this->location_id}/transactions/{$transaction_id}/refund", $params );
			
			if ( is_wp_error( $response ) ) {
				throw new Exception( $response->get_error_message() );
			}
			
			wpsc_add_purchase_meta( $this->log->get( 'id' ), 'square_refunded', true );
			wpsc_add_purchase_meta( $this->log->get( 'id' ), 'square_refund_id', $response['ResponseBody']->refund->id );
			$this->log->set( 'square-status', sprintf( __( 'Refunded (Transaction ID: %s)', 'wp-e-commerce' ), $response['ResponseBody']->refund->id ) )->save();
			$this->log->set( 'processed'      , WPSC_Purchase_Log::REFUNDED )->save();
			$this->log->set( 'sq_order_status', 'Refunded' )->save();
		}
    }
	
    /**
     * Capture authorized payment
     *
     * @param  string $transaction_id
     */
    public function capture_payment( $transaction_id ) {
		if ( $this->log->get( 'gateway' ) == 'square-payments' ) {
			$response = $this->gateway->execute( "locations/{$this->location_id}/transactions/{$transaction_id}/capture", null );
			
			if ( is_wp_error( $response ) ) {
				throw new Exception( $response->get_error_message() );
			}
			
			$this->log->set( 'sq_order_status', 'Completed' )->save();
			$this->log->set( 'square-status', 'Authorization Captured' )->save();
			$this->log->set( 'processed'      , WPSC_Purchase_Log::ACCEPTED_PAYMENT )->save();
		}
    }
}
?>
