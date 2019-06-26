<?php if ( ! defined( 'myCRED_VERSION' ) ) exit;
/*
Plugin Name: myCRED Rave Payment Gateway
Plugin URI: https://rave.flutterwave.com/
Description: Official myCRED payment gateway for Rave.
Version: 1.0.0
Author: Chigbo Ezejiugo
Author URI: http://github.com/emmajiugo
License: MIT License
*/

add_filter( 'mycred_setup_gateways', 'mycredpro_adjust_gateways' );
function mycredpro_adjust_gateways( $installed ) {
	
	$installed['rave'] = array(
		'title'         => __('Rave Payment'),
		'documentation' => 'http://rave.flutterwave.com',
		'callback'      => array( 'myCRED_Rave' ),
		'icon'          => 'dashicons-admin-generic',
		'sandbox'       => true,
		'external'      => true,
		// 'custom_rate'   => true
	);

	return $installed;

}

if ( ! class_exists( 'myCRED_Rave' ) ) :
	class myCRED_Rave extends myCRED_Payment_Gateway {
		/**
		 * Construct
		 */
		function __construct( $gateway_prefs ) {

			$types = mycred_get_types();
			$default_exchange = array();
			foreach ( $types as $type => $label )
                $default_exchange[ $type ] = 1;
                
            // register settings
			parent::__construct( array(
				'id'               => 'rave',
				'label'            => 'Rave Payment',
				'gateway_logo_url' => '', //plugins_url( 'assets/images/paypal.png' ),
				'defaults'         => array(
					'sandbox'		=> 0,
					'hash'			=> '',
                    'live_pkey'     => '',
                    'live_skey'     => '',
                    'test_pkey'     => '',
                    'test_skey'     => '',
                    'currency'      => 'NGN',
                    'item_name'     => 'Purchase of myCRED %plural%',
					'exchange'      => $default_exchange,
				)
			), $gateway_prefs );
        }

        /**
		 * Adjust Currencies
		 */
        public function rave_currencies( $currencies ) {

			// $currencies['RON'] = 'Romanian Leu';
			
			unset( $currencies );

			$currencies['NGN'] = 'Nigerian Naira';
			
			return $currencies;
		}
        
		/**
		 * Process
         * Used to handle webhook callback from Rave
		 */
		public function process() {
			
			// Required fields
			if ( isset( $_POST['postData'] ) && isset( $_POST['id'] ) && isset( $_POST['price'] ) ) {
				// Get Pending Payment
				$pending_post_id = sanitize_key( $_POST['postData'] );
				$pending_payment = $this->get_pending_payment( $pending_post_id );
				if ( $pending_payment !== false ) {
					// Verify Call with PayPal
					if ( $this->IPN_is_valid_call() ) {
						$errors = false;
						$new_call = array();
						// Check amount paid
						if ( $_POST['price'] != $pending_payment['cost'] ) {
							$new_call[] = sprintf( __( 'Price mismatch. Expected: %s Received: %s', 'mycred' ), $pending_payment['cost'], $_POST['price'] );
							$errors = true;
						}
						// Check currency
						if ( $_POST['currency'] != $pending_payment['currency'] ) {
							$new_call[] = sprintf( __( 'Currency mismatch. Expected: %s Received: %s', 'mycred' ), $pending_payment['currency'], $_POST['currency'] );
							$errors = true;
						}
						// Check status
						if ( $_POST['status'] != 'paid' ) {
							$new_call[] = sprintf( __( 'Payment not completed. Received: %s', 'mycred' ), $_POST['status'] );
							$errors = true;
						}
						// Credit payment
						if ( $errors === false ) {
							// If account is credited, delete the post and it's comments.
							if ( $this->complete_payment( $pending_payment, $_POST['id'] ) )
								wp_delete_post( $pending_post_id, true );
							else
								$new_call[] = __( 'Failed to credit users account.', 'mycred' );
							
						}
						
						// Log Call
						if ( ! empty( $new_call ) )
							$this->log_call( $pending_post_id, $new_call );
					}
					
				}
			
			}
		}
		/**
		 * Returning
		 * @since 1.4
		 * @version 1.0
		 */
        public function returning() {

			if ( isset( $_GET['transaction_id'] ) && ! empty( $_GET['transaction_id'] ) && isset( $_GET['msid'] ) && ! empty( $_GET['msid'] ) ) {
				$this->get_page_header( __( 'Success', 'mycred' ), $this->get_thankyou() );
				echo '<h1>' . __( 'Thank you for your purchase', 'mycred' ) . '</h1>';
				$this->get_page_footer();
				exit;
			}

		}
        
		/**
		 * Generate Hosted Link for Rave
		 */
		public function generate_hosted_link( $args ) {

			$data = json_encode( $args );

			$curl = curl_init();
			curl_setopt_array($curl, array(
				CURLOPT_URL => "https://api.ravepay.co/flwv3-pug/getpaidx/api/v2/hosted/pay",
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CUSTOMREQUEST => "POST",
				CURLOPT_POSTFIELDS => $data,
				CURLOPT_HTTPHEADER => [
				  "content-type: application/json",
				  "cache-control: no-cache"
				],
			));
			$reply = curl_exec( $curl );

			if ( $reply == false )
				$response = curl_error( $curl );
			else
				$response = json_decode( $reply, true );

			curl_close( $curl );
			if ( is_string( $response ) )
				return array( 'error' => $response );	
			return $response;
        }
        
		/**
		 * Buy Creds
		 */
		public function buy() {

            // Example: Require that settings are saved before this gateway is used
			if ( ! isset( $this->prefs['live_pkey'] ) || empty( $this->prefs['live_pkey'] ) )
				wp_die( __( 'Please setup this gateway before attempting to make a purchase!', 'mycred' ) );
				
			// set public key
			$pkey = ( $this->sandbox_mode ) ? $this->prefs['test_pkey'] : $this->prefs['live_pkey'];

			// echo "<script>console.log(".$pkey.");</script>";
			// file_put_contents('KEY_'.time(), json_encode($pkey));			
			// exit;
                
			// Type
            $type = $this->get_point_type();
            // Load the selected point type
            $mycred = mycred( $type );
            
			// Amount
			$amount = $mycred->number( $_REQUEST['amount'] );
            $amount = abs( $amount );
            
			// Get Cost
			$cost = $this->get_cost( $amount, $type );
			// Get the recipient of the purchase (in case this is a gift purchase)
			$to    = $this->get_to();
			// Get the buyers ID (always the current user)
			$from  = get_current_user_id();
            
			// Revisiting pending payment. If this is a request to pay an existing pending payment
			if ( isset( $_REQUEST['revisit'] ) ) {
				$this->transaction_id = strtoupper( $_REQUEST['revisit'] );
			}
			else {
				$post_id = $this->add_pending_payment( array( $to, $from, $amount, $cost, $this->prefs['currency'], $type ) );
				$this->transaction_id = get_the_title( $post_id );
            }
            
			// Thank you page
			$thankyou_url = $this->get_thankyou();
			// Cancel page
            $cancel_url = $this->get_cancelled( $this->transaction_id );
            
			// Item Name
			$item_name = str_replace( '%number%', $amount, $this->prefs['item_name'] );
			$item_name = $mycred->template_tags_general( $item_name );
			$from_user = get_userdata( $from );
			echo "<script>console.log(".$from_user.");</script>";
            
			// Send in payload request here
			$request = $this->generate_hosted_link( array(
				'PBFPubKey'      	=> $pkey,
				'amount'         	=> $cost,
				'currency'       	=> $this->prefs['currency'],
				'redirect_url'   	=> $this->callback_url(),
				'customer_firstname'=> $from_user->first_name,
				'customer_lastname' => $from_user->last_name,				'customer_email'    => $from_user->email,
				'txref'				=> $this->transaction_id .'_'.time()
            ) );
            
			// Request Failed
			if ( isset( $request['error'] ) ) {
				$this->get_page_header( __( 'Processing payment &hellip;', 'mycred' ) ); ?>

                <p><?php _e( 'Could not create a Rave Hosted Link. Please contact the site administrator!', 'mycred' ); ?></p>
                <p><?php printf( __( 'Rave returned the following error message:', 'mycred' ) . ' ', $request['error'] ); ?></p>

            <?php
			}
			// Request success
			else {
				$this->get_page_header( __( 'Processing payment &hellip;', 'mycred' ) ); ?>

                <div class="continue-forward" style="text-align:center;">
                    <p>&nbsp;</p>
                    <img src="<?php echo plugins_url( 'assets/images/loading.gif', MYCRED_PURCHASE ); ?>" alt="Loading" />
                    <p id="manual-continue"><a href="<?php echo $request['data']['link']; ?>"><?php _e( 'Click here if you are not automatically redirected', 'mycred' ); ?></a></p>
                </div>

            <?php
            }
            
			$this->get_page_footer();
        }
        
		/**
		 * Gateway Prefs
         * Here is for setting the Rave settings
		 */
		function preferences() {
            add_filter( 'mycred_dropdown_currencies', array( $this, 'rave_currencies' ) );
			$prefs = $this->prefs; ?>

			<div class="row">
				<div class="col-md-6">
				
					<label class="subheader"><?php _e( 'Webhook URL', 'mycred' ); ?></label>
					<ol>
						<li>
							<code style="padding: 12px;display:block;"><?php echo $this->callback_url(); ?></code>
							<p><?php _e( 'Make sure the "Notification URL" is set to the above address and that you have selected "Receive IPN messages (Enabled)".', 'mycred' ); ?></p>
						</li>
					</ol>
					<label class="subheader" for="<?php echo $this->field_id( 'hash' ); ?>"><?php _e( 'Secret Hash', 'mycred' ); ?></label>
					<ol>
						<li>
							<div class="h2"><input type="text" name="<?php echo $this->field_name( 'hash' ); ?>" id="<?php echo $this->field_id( 'hash' ); ?>" value="<?php echo $prefs['hash']; ?>" class="long" /></div>
							<span class="description"><?php _e( 'Most be the same with the secret hash on your Rave dashboard.', 'mycred' ); ?></span>
						</li>
					</ol>
					<label class="subheader" for="<?php echo $this->field_id( 'live_pkey' ); ?>"><?php _e( 'Live Public Key', 'mycred' ); ?></label>
					<ol>
						<li>
							<div class="h2"><input type="text" name="<?php echo $this->field_name( 'live_pkey' ); ?>" id="<?php echo $this->field_id( 'live_pkey' ); ?>" value="<?php echo $prefs['live_pkey']; ?>" class="long" /></div>
						</li>
					</ol>
					<label class="subheader" for="<?php echo $this->field_id( 'live_skey' ); ?>"><?php _e( 'Live Secret Key', 'mycred' ); ?></label>
					<ol>
						<li>
							<div class="h2"><input type="text" name="<?php echo $this->field_name( 'live_skey' ); ?>" id="<?php echo $this->field_id( 'live_skey' ); ?>" value="<?php echo $prefs['live_skey']; ?>" class="long" /></div>
						</li>
					</ol>
					<label class="subheader" for="<?php echo $this->field_id( 'test_pkey' ); ?>"><?php _e( 'Test Public Key', 'mycred' ); ?></label>
					<ol>
						<li>
							<div class="h2"><input type="text" name="<?php echo $this->field_name( 'test_pkey' ); ?>" id="<?php echo $this->field_id( 'test_pkey' ); ?>" value="<?php echo $prefs['test_pkey']; ?>" class="long" /></div>
						</li>
					</ol>
					<label class="subheader" for="<?php echo $this->field_id( 'test_skey' ); ?>"><?php _e( 'Test Secret Key', 'mycred' ); ?></label>
					<ol>
						<li>
							<div class="h2"><input type="text" name="<?php echo $this->field_name( 'test_skey' ); ?>" id="<?php echo $this->field_id( 'test_skey' ); ?>" value="<?php echo $prefs['test_skey']; ?>" class="long" /></div>
						</li>
					</ol>
					<label class="subheader" for="<?php echo $this->field_id( 'currency' ); ?>"><?php _e( 'Currency', 'mycred' ); ?></label>
					<ol>
						<li>
							<?php $this->currencies_dropdown( 'currency', 'mycred-gateway-rave-currency' ); ?>
						</li>
					</ol>
					<label class="subheader" for="<?php echo $this->field_id( 'item_name' ); ?>"><?php _e( 'Item Name', 'mycred' ); ?></label>
					<ol>
						<li>
							<div class="h2"><input type="text" name="<?php echo $this->field_name( 'item_name' ); ?>" id="<?php echo $this->field_id( 'item_name' ); ?>" value="<?php echo $prefs['item_name']; ?>" class="long" /></div>
							<span class="description"><?php _e( 'Description of the item being purchased by the user.', 'mycred' ); ?></span>
						</li>
					</ol>
					<label class="subheader"><?php _e( 'Exchange Rates', 'mycred' ); ?></label>
					<ol>
						<?php $this->exchange_rate_setup(); ?>
					</ol>
				</div>
			</div>
            <?php
		}
		
		/**
		 * Sanatize Prefs
		 */
		public function sanitise_preferences( $data ) {

			$new_data = array();

			$new_data['sandbox']   	= ( isset( $data['sandbox'] ) ) ? 1 : 0;
			$new_data['hash']   	= sanitize_text_field( $data['hash'] );
			$new_data['currency'] 	= sanitize_text_field( $data['currency'] );
			$new_data['live_pkey'] 	= sanitize_text_field( $data['live_pkey'] );
			$new_data['live_skey'] 	= sanitize_text_field( $data['live_skey'] );
			$new_data['test_pkey'] 	= sanitize_text_field( $data['test_pkey'] );
			$new_data['test_skey'] 	= sanitize_text_field( $data['test_skey'] );
			$new_data['item_name'] 	= sanitize_text_field( $data['item_name'] );

			// If exchange is less then 1 we must start with a zero
			if ( isset( $data['exchange'] ) ) {
				foreach ( (array) $data['exchange'] as $type => $rate ) {
					if ( $rate != 1 && in_array( substr( $rate, 0, 1 ), array( '.', ',' ) ) )
						$data['exchange'][ $type ] = (float) '0' . $rate;
				}
			}
			$new_data['exchange']  = $data['exchange'];

			return $new_data;
		}
	}

endif;