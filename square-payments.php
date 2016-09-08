<?php
class WPSC_Payment_Gateway_Square_Payments extends WPSC_Payment_Gateway {

	private $endpoints = array(
		'sandbox' => 'https://connect.squareup.com/v2/',
		'production' => 'https://connect.squareup.com/v2/',
	);
	private $endpoint;
	private $sandbox;
	
	public function __construct() {
		parent::__construct();
		
		$this->title 		= __( 'Square', 'wpsc' );
		$this->supports 	= array( 'default_credit_card_form', 'tev2' );
		$this->sandbox		= $this->setting->get( 'sandbox_mode' ) == '1' ? true : false;
		$this->endpoint		= $this->sandbox ? $this->endpoints['sandbox'] : $this->endpoints['production'];
		$this->square_sdk	= dirname( __FILE__ ) . '/lib';
		
		// Define user set variables
		$this->app_id				= $this->setting->get( 'app_id' );
		$this->location_id			= $this->setting->get( 'location_id' );
		$this->acc_token  			= $this->setting->get( 'acc_token' );

	}

	public function init() {

		add_action( 'wp_enqueue_scripts', array( $this, 'scripts' ) );
		add_action( 'wp_head'           , array( $this, 'head_script' ) );
		
		add_filter( 'wpsc_get_checkout_payment_method_form_args', array( $this, 'insert_reference_id_to_form' ) );
	}
	
	/**
	 * Add scripts
	 */
	public function scripts() {
		wp_enqueue_script( 'squareup', 'https://js.squareup.com/v2/paymentform', 'jquery', false, true );
	}
	
	public function head_script() {
		?>
		<script type='text/javascript'>

		</script>
		<?php
	}
	

	//This needs to be inserted inside the checkout page
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
		
		$card_token = isset( $_POST['square_card_nonce'] ) ? sanitize_text_field( $_POST['square_card_nonce'] ) : '';
		
		if ( $this->purchase_log->get( 'gateway' ) == 'square-payments' ) {
			$order = $this->purchase_log;
			
			$request_body = array (
				"card_nonce" => $card_token,
				# Monetary amounts are specified in the smallest unit of the applicable currency.
				# This amount is in cents. It's also hard-coded for $1.00, which isn't very useful.
				"amount_money" => array (
					"amount" => $order->get( 'totalprice' ),
					"currency" => strtoupper( $this->get_currency_code() )
				),
				"idempotency_key" => $this->purchase_log->get( 'sessionid' )
			);
			
						
			if ( ! class_exists( 'SquareConnect\Api\TransactionApi' ) ) {
				require_once $this->square_sdk . '/Api/TransactionApi.php';
			}
			
			$transaction_api = new \SquareConnect\Api\TransactionApi();
		
			try {
				$result = $transaction_api->charge( $this->acc_token, $this->location_id, $request_body);
				echo "<pre>";
				print_r($result);
				echo "</pre>";
			} catch (\SquareConnect\ApiException $e) {
				echo "Caught exception!<br/>";
				print_r("<strong>Response body:</strong><br/>");
				echo "<pre>"; var_dump($e->getResponseBody()); echo "</pre>";
				echo "<br/><strong>Response headers:</strong><br/>";
				echo "<pre>"; var_dump($e->getResponseHeaders()); echo "</pre>";
			}
		
		}
	}
	
	public function execute( $endpoint, $params = array(), $type = 'POST' ) {
	   // where we make the API petition
        $endpoint = $this->endpoint . $endpoint;
		if ( ! is_null( $params ) ) {
			$params += array(
				"developerApplication" => array(
					"developerId" => 10000644,
					"version"     => "1.2"
				),
			);
		}
		$data = json_encode( $params );
		$args = array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => $this->auth,
				'Content-Type'  => 'application/json',
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
			$request = $response_object;
		}
		return $request;
    }


}
?>