<?php

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
                'sandbox'       => 0,
                'hash'          => '',
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
        
        // unset( $currencies );
        $currencies['NGN'] = 'Nigerian Naira';
        $currencies['GHS'] = 'Ghana Cedis';
        $currencies['KES'] = 'Kenyan Shillings';
        $currencies['ZAR'] = 'South Africa Rands';
        $currencies['TZS'] = 'Tanzanian Shilling';
        $currencies['UGX'] = 'Ugandan Shilling';
        $currencies['RWF'] = 'Rwandan franc';
        $currencies['SLL'] = 'Sierra Leonean Leone';
        $currencies['ZMW'] = 'Zambian Kwacha';
        
        unset( $currencies['MXN'] );
        unset( $currencies['BRL'] );
        unset( $currencies['PHP'] );
            
        return $currencies;
    }
    
    /**
     * Process
     * Used to handle webhook callback from Rave
     */
    public function process() {
        
        // Retrieve the request's body
        $body = @file_get_contents("php://input");

        // retrieve the signature sent in the reques header's.
        $signature = (isset($_SERVER['HTTP_VERIF_HASH']) ? $_SERVER['HTTP_VERIF_HASH'] : '');

        /* It is a good idea to log all events received. Add code *
        * here to log the signature and body to db or file       */

        if (!$signature) {
            // only a post with rave signature header gets our attention
            exit();
        }

        // Store the same signature on your server as an env variable and check against what was sent in the headers
        $local_signature = $this->prefs['hash'];

        // confirm the event's signature
        if( $signature !== $local_signature ){
        // silently forget this ever happened
        exit();
        }

        http_response_code(200); // PHP 5.4 or greater
        // parse event (which is json string) as object
        // Give value to your customer but don't give any output
        // Remember that this is a call from rave's servers and 
        // Your customer is not seeing the response here at all
        $response = json_decode($body);
        if ($response->status == 'successful') {

            // get the order transaction id
            $order_id = explode("_", $response->txRef);

            $pending_post_id = sanitize_key( $order_id[0] );
            $pending_payment = $this->get_pending_payment( $pending_post_id );
            
            if ( $pending_payment !== false ) {

                $errors   = false;
                $new_call = array();

                // Check amount paid
                if ( $response->amount != $pending_payment->cost ) {
                    $new_call[] = sprintf( __( 'Price mismatch. Expected: %s Received: %s', 'mycred' ), $pending_payment->cost, $response->amount );
                    $errors     = true;
                }

                // Check currency
                if ( $response->currency != $pending_payment->currency ) {
                    $new_call[] = sprintf( __( 'Currency mismatch. Expected: %s Received: %s', 'mycred' ), $pending_payment->currency, $response->currency );
                    $errors     = true;
                }

                // Credit payment
                if ( $errors === false ) {

                    // If account is credited, delete the post and it's comments.
                    if ( $this->complete_payment( $pending_payment, $order_id[0] ) )
                        $this->trash_pending_payment( $pending_post_id );
                    else
                        $new_call[] = __( 'Failed to credit users account.', 'mycred' );

                }

                // Log Call
                if ( ! empty( $new_call ) )
                    $this->log_call( $pending_post_id, $new_call );
            }
        }
    }

    /**
     * Returning
     * @since 1.4
     * @version 1.0
     */
    public function returning() {
        // echo "<script>alert('Returning from Rave.');</script>";

        if ( isset( $_GET['cancelled'] ) && ! empty( $_GET['txref'] ) ) {

            $this->get_page_header( __( 'Cancelled', 'mycred' ), $this->get_thankyou() );
            echo '<h1>' . __( 'You cancelled the transaction', 'mycred' ) . '</h1>';
            $this->get_page_footer();
            exit;

        } elseif ( isset( $_GET['txref'] ) && isset( $_GET['flwref'] ) ) {

            // get the order transaction id
            $transaction_ref = $_GET['txref'];
            $order_id = explode("_", $transaction_ref);

            // verify the transaction
            $result = $this->verify_transaction( $_GET['txref'] );

            // print_r($result); exit;

            if ( $result->data->status === "successful" && ($result->data->chargecode === "00" || $result->data->chargecode === "00") ) {

                $pending_post_id = sanitize_key( $order_id[0] );
                $pending_payment = $this->get_pending_payment( $pending_post_id );
                
                if ( $pending_payment !== false ) {

                    $errors   = false;
                    $new_call = array();

                    // Check amount paid
                    if ( $result->data->amount != $pending_payment->cost ) {
                        $new_call[] = sprintf( __( 'Price mismatch. Expected: %s Received: %s', 'mycred' ), $pending_payment->cost, $result->data->amount );
                        $errors     = true;
                    }

                    // Check currency
                    if ( $result->data->currency != $pending_payment->currency ) {
                        $new_call[] = sprintf( __( 'Currency mismatch. Expected: %s Received: %s', 'mycred' ), $pending_payment->currency, $result->data->currency );
                        $errors     = true;
                    }

                    // Credit payment
                    if ( $errors === false ) {

                        // If account is credited, delete the post and it's comments.
                        if ( $this->complete_payment( $pending_payment, $order_id[0] ) )
                            $this->trash_pending_payment( $pending_post_id );
                        else
                            $new_call[] = __( 'Failed to credit users account.', 'mycred' );

                    }

                    // Log Call
                    if ( ! empty( $new_call ) )
                        $this->log_call( $pending_post_id, $new_call );

                }

                $this->get_page_header( __( 'Success', 'mycred' ), $this->get_thankyou() );
                echo '<h1>' . __( 'Thank you for your purchase', 'mycred' ) . '</h1>';
                $this->get_page_footer();
                exit;

            } else {

                $this->get_page_header( __( 'Failed', 'mycred' ), $this->get_thankyou() );
                echo '<h1>' . __( 'Seems there was a problem with your payment.', 'mycred' ) . '</h1>';
                $this->get_page_footer();
                exit;

            }

        }
    }

    /**
     * Verify trasaction
     */
    public function verify_transaction( $txref )
    {

        // set public key
        $seckey = ( $this->sandbox_mode ) ? $this->prefs['test_skey'] : $this->prefs['live_skey'];

        $data = array(
            'txref' => $txref,
            'SECKEY' => $seckey
        );

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.ravepay.co/flwv3-pug/getpaidx/api/v2/verify",
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
            $response = json_decode( $reply );
        curl_close( $curl );
        if ( is_string( $response ) )
            return array( 'error' => $response );

        return $response;
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
            $response = json_decode( $reply );
        curl_close( $curl );
        if ( is_string( $response ) )
            return array( 'error' => $response );

        return $response;

    }

    /**
     * Prep Sale
     * @since 1.8
     * @version 1.0
     */
    public function prep_sale( $new_transaction = false ) {

        // Set currency
        $this->currency = $this->prefs['currency'];

        //set the currency to route to their countries
        switch ($this->currency) {
            case 'KES':
              $country = 'KE';
              break;
            case 'GHS':
              $country = 'GH';
              break;
            case 'ZAR':
              $country = 'ZA';
              break;
            case 'TZS':
              $country = 'TZ';
              break;
            
            default:
              $country = 'NG';
              break;
        }
        
        // Get the buyers ID (always the current user)
        $from  = get_current_user_id();

        // The item name
        $item_name = str_replace( '%number%', $this->amount, $this->prefs['item_name'] );
        $item_name = $this->core->template_tags_general( $item_name );
        $from_user = get_userdata( $from );
        
        // set public key
        $pkey = ( $this->sandbox_mode ) ? $this->prefs['test_pkey'] : $this->prefs['live_pkey'];

        // Send in payload request here
        $request = $this->generate_hosted_link( array(
            'PBFPubKey'         => $pkey,
            'amount'            => $this->cost,
            'currency'          => $this->prefs['currency'],
            'country'           => $country,
            'redirect_url'      => $this->get_thankyou(), //$this->callback_url(),
            'customer_firstname'=> $from_user->data->user_nicename,
            'customer_lastname' => '',
            'customer_email'    => $from_user->data->user_email,
            'txref'             => $this->transaction_id .'_'.time()
        ) );

        // // This gateway redirects, so we need to populate redirect_to
        // $this->redirect_to = 'https://api.ravepay.co/flwv3-pug/getpaidx/api/v2/hosted/pay';

        // // Transaction variables that needs to be submitted
        // $this->redirect_fields = array(
        //     'PBFPubKey'         => $pkey,
        //     'amount'            => $this->cost,
        //     'currency'          => $this->prefs['currency'],
        //     'redirect_url'      => $this->callback_url(),
        //     'customer_firstname'=> $from_user->data->user_nicename,
        //     'customer_lastname' => '',
        //     'customer_email'    => $from_user->data->user_email,
        //     'txref'             => $this->transaction_id .'_'.time()
        // );

        // $this->checkout_page_body($request->data->link);
        $this->rave_link = $request->data->link;

    }

    /**
     * AJAX Buy Handler
     * @since 1.8
     * @version 1.0
     */
    public function ajax_buy() {

        // Construct the checkout box content
        $content  = $this->checkout_header();
        $content .= $this->checkout_logo();
        $content .= $this->checkout_order();
        $content .= $this->checkout_cancel();
        $content .= $this->checkout_footer();

        // Return a JSON response
        $this->send_json( $content );

    }

    /**
     * Checkout Page Body
     * This gateway only uses the checkout body.
     * @since 1.8
     * @version 1.0
     */
    public function checkout_page_body() {

        echo $this->checkout_header();
        echo $this->checkout_logo( false );

        echo $this->checkout_order();
        echo $this->checkout_cancel();

        // override the default checkout button
        echo "<a href='".$this->rave_link."' 
            style = 'appearance: button;
            -moz-appearance: button;
            -webkit-appearance: button;
            text-decoration: none; font: menu; color: ButtonText;
            float: right;
            display: inline-block; padding: 5px 11px;'
            > 
                Continue to Rave...
            </a>";
        
        // echo $this->checkout_footer();

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
        $new_data['sandbox']    = ( isset( $data['sandbox'] ) ) ? 1 : 0;
        $new_data['hash']       = sanitize_text_field( $data['hash'] );
        $new_data['currency']   = sanitize_text_field( $data['currency'] );
        $new_data['live_pkey']  = sanitize_text_field( $data['live_pkey'] );
        $new_data['live_skey']  = sanitize_text_field( $data['live_skey'] );
        $new_data['test_pkey']  = sanitize_text_field( $data['test_pkey'] );
        $new_data['test_skey']  = sanitize_text_field( $data['test_skey'] );
        $new_data['item_name']  = sanitize_text_field( $data['item_name'] );
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