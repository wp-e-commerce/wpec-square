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
		$this->supports 		= array( 'default_credit_card_form', 'tev1', 'tokenization' );
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
		parent::init();

		add_action( 'wp_enqueue_scripts', array( $this, 'square_scripts' ) );
		add_action( 'wp_head'	, array( $this, 'head_script' ) );

		// Add hidden field to hold token value
		add_action( 'wpsc_inside_shopping_cart', array( $this, 'te_v1_insert_hidden_field' ) );
		add_action( 'wpsc_default_credit_card_form_end', array( $this, 'te_v2_insert_hidden_field' ) );

		// Add extra zip field to card data for TeV1
		add_action( 'wpsc_tev1_default_credit_card_form_end_square-payments', array( $this, 'tev1_add_billing_card_zip' ) );
		add_filter( 'wpsc_default_credit_card_form_end-square-payments', array( $this, 'tev2_add_billing_card_zip' ), 10, 2 );
	}

	/**
	 * Load gateway only if PHP 5.3+ and TEv2.
	 *
	 * @return bool Whether or not to load gateway.
	 */
	public function load() {
		return version_compare( phpversion(), '5.3', '>=' );
	}

	public function tev2_add_billing_card_zip( $fields, $name ) {
		$fields['card-zip-field'] = '<p class="form-row form-row-last">
					<label for="' . esc_attr( $name ) . '-card-zip">' . __( 'Card Zip', 'wpec-square' ) . ' <span class="required">*</span></label>
					<input id="' . esc_attr( $name ) . '-card-zip" class="input-text wpsc-credit-card-form-card-zip" type="number" autocomplete="off" placeholder="' . esc_attr__( 'Card Zip', 'wpec-square' ) . '" />
				</p>';

		return $fields;
	}

	public function tev1_add_billing_card_zip( $name ) {
		?>
		<tr>
			<td><?php _e( 'Card Zip', 'wpec-square' ); ?></td>
			<td>
				<input type="text" id="<?php esc_attr_e( $name ); ?>-card-zip" value="" autocomplete="off" size="5" placeholder="<?php esc_attr_e( 'Card Zip', 'wpec-square' ); ?>" />
			</td>
		</tr>
		<?php
	}

	public function te_v1_insert_hidden_field() {
		echo '<input type="hidden" id="square_card_nonce" name="square_card_nonce" value="" />';
	}

	// This needs to be inserted inside the checkout page
	public function te_v2_insert_hidden_field( $name ) {
		echo '<input type="hidden" id="square_card_nonce" name="square_card_nonce" value="" />';
	}

	/**
	 * Add scripts
	 */
	public function square_scripts() {
 		$is_cart = wpsc_is_theme_engine( '1.0' ) ? wpsc_is_checkout() : ( wpsc_is_checkout() || wpsc_is_cart() );
 
        if ( ! $is_cart ) {
            return;
        }

		wp_enqueue_script( 'squareup', 'https://js.squareup.com/v2/paymentform' );
	}

	public function head_script() {
 		$is_cart = wpsc_is_theme_engine( '1.0' ) ? wpsc_is_checkout() : ( wpsc_is_checkout() || wpsc_is_cart() );

        if ( ! $is_cart ) {
            return;
        }

		if( wpsc_is_theme_engine( '2.0' ) ) {
			require_once( WPSC_TE_V2_CLASSES_PATH . '/checkout-wizard.php' );
			$wizard = WPSC_Checkout_Wizard::get_instance();
			
			if ( $wizard->active_step != 'payment' ) {
				return;
			}
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
				  elementId: 'square-payments-card-number',
				},
				cvv: {
				  elementId: 'square-payments-card-cvc',
				},
				expirationDate: {
				  elementId: 'square-payments-card-expiry',
				},
				postalCode: {
				  elementId: 'square-payments-card-zip',
				},
				callbacks: {

				  // Called when the SqPaymentForm completes a request to generate a card
				  // nonce, even if the request failed because of an error.
				  cardNonceResponseReceived: function(errors, nonce, cardData) {
					if (errors) {
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
						var nonceField = document.getElementById( 'square_card_nonce' );
						nonceField.value = nonce;
						jQuery( '#wpsc-checkout-form, .wpsc_checkout_forms' ).submit();
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
				$( '#wpsc-checkout-form, .wpsc_checkout_forms' ).on( 'submit', function( e ) {
					if (
						$( 'input[name="wpsc_payment_method"]:checked' ).val() === 'square-payments' ||
						$( 'input[name="custom_gateway"]:checked' ).val() === 'square-payments'
					) {
						e.preventDefault();
						$(this).off();
						sqPaymentForm.requestCardNonce();
					}
				});

			});
		</script>
		<?php
	}

	public function setup_form() {
?>
		<!-- Account Credentials -->
		<tr>
			<td colspan="2">
				<h4><?php _e( 'Account Credentials', 'wpec-square' ); ?></h4>
			</td>
		</tr>
		<tr>
			<td>
				<label for="wpsc-worldpay-secure-net-id"><?php _e( 'Application ID', 'wpec-square' ); ?></label>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'app_id' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'app_id' ) ); ?>" id="wpsc-worldpay-secure-net-id" />
				<br><span class="small description"><?php _e( 'Application ID.', 'wpec-square' ); ?></span>
			</td>
		</tr>
		<tr>
			<td>
				<label for="wpsc-worldpay-secure-key"><?php _e( 'Access Token', 'wpec-square' ); ?></label>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'acc_token' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'acc_token' ) ); ?>" id="wpsc-worldpay-secure-key" />
				<br><span class="small description"><?php _e( 'Access token.', 'wpec-square' ); ?></span>
			</td>
		</tr>
		<tr>
			<td>
				<label for="wpsc-worldpay-secure-key"><?php _e( 'Location ID', 'wpec-square' ); ?></label>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'location_id' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'location_id' ) ); ?>" id="wpsc-worldpay-secure-key" />
				<br><span class="small description"><?php _e( 'Store Location ID.', 'wpec-square' ); ?></span>
			</td>
		</tr>
		<tr>
			<td>
				<label for="wpsc-worldpay-payment-capture"><?php _e( 'Payment Capture', 'wpec-square' ); ?></label>
			</td>
			<td>
				<select id="wpsc-worldpay-payment-capture" name="<?php echo esc_attr( $this->setting->get_field_name( 'payment_capture' ) ); ?>">
					<option value='' <?php selected( '', $this->setting->get( 'payment_capture' ) ); ?>><?php _e( 'Authorize and capture the payment when the order is placed.', 'wpec-square' )?></option>
					<option value='authorize' <?php selected( 'authorize', $this->setting->get( 'payment_capture' ) ); ?>><?php _e( 'Authorize the payment when the order is placed.', 'wpec-square' )?></option>
				</select>
			</td>
		</tr>
		<tr>
			<td>
				<label><?php _e( 'Sandbox Mode', 'wpec-square' ); ?></label>
			</td>
			<td>
				<label><input <?php checked( $this->setting->get( 'sandbox_mode' ) ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'sandbox_mode' ) ); ?>" value="1" /> <?php _e( 'Yes', 'wpec-square' ); ?></label>&nbsp;&nbsp;&nbsp;
				<label><input <?php checked( (bool) $this->setting->get( 'sandbox_mode' ), false ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'sandbox_mode' ) ); ?>" value="0" /> <?php _e( 'No', 'wpec-square' ); ?></label>
			</td>
		</tr>
		<!-- Error Logging -->
		<tr>
			<td colspan="2">
				<h4><?php _e( 'Error Logging', 'wpec-square' ); ?></h4>
			</td>
		</tr>
		<tr>
			<td>
				<label><?php _e( 'Enable Debugging', 'wpec-square' ); ?></label>
			</td>
			<td>
				<label><input <?php checked( $this->setting->get( 'debugging' ) ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'debugging' ) ); ?>" value="1" /> <?php _e( 'Yes', 'wpec-square' ); ?></label>&nbsp;&nbsp;&nbsp;
				<label><input <?php checked( (bool) $this->setting->get( 'debugging' ), false ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'debugging' ) ); ?>" value="0" /> <?php _e( 'No', 'wpec-square' ); ?></label>
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
					$order->set( 'square-status', __( 'Square order opened. Capture the payment below. Authorized payments must be captured within 6 days.', 'wpec-square' ) )->save();
				} else {
					$order->set( 'processed', WPSC_Purchase_Log::PAYMENT_DECLINED )->save();
					}
			break;

			default:
				// Capture
				$result = $this->capture_payment( $card_token );
				if ( $result ) {
					// Payment complete
					$order->set( 'square-status', __( 'Square order completed.  Funds have been authorized and captured.', 'wpec-square' ) );
				} else {
					$order->set( 'processed'      , WPSC_Purchase_Log::PAYMENT_DECLINED );
				}
			break;
		}

		$order->save();
		$this->go_to_transaction_results();
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

			if( isset( $response['ResponseBody']->errors ) ) {
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

	/**
	 * Constructor
	 */
	public function __construct( &$gateway ) {
		$this->log     = $gateway->purchase_log;
		$this->gateway = $gateway;
		$this->init();
	}

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
				<h3 class='hndle'><?php _e( 'Square Payments' , 'wpec-square' ); ?></h3>
				<div class='inside'>
					<p><?php
							_e( 'Current status: ', 'wpec-square' );
							echo wp_kses_data( $this->log->get( 'square-status' ) );
						?>
					</p>
					<p><?php
							_e( 'Transaction ID: ', 'wpec-square' );
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
					'button' => __( 'Capture funds', 'wpec-square' )
				);
				//
				if ( ! $order_info['settled'] ) {
					//Void
					$actions['void'] = array(
						'id'     => $sq_transactionid,
						'button' => __( 'Void order', 'wpec-square' )
					);
				}
				break;
			case 'Completed' :
				//Order has been captured or its a direct payment
				if ( $order_info['settled'] ) {
					//Refund
					$actions['refund'] = array(
						'id'     => $sq_transactionid,
						'button' => __( 'Refund order', 'wpec-square' )
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
			$this->log->set( 'square-status', sprintf( __( 'Refunded (Transaction ID: %s)', 'wpec-square' ), $response['ResponseBody']->refund->id ) )->save();
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
