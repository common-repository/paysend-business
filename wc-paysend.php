<?php
/*
  Plugin Name: Paysend Business Payment Gateway
  Description: Allows you to use Paysend Business payment gateway with the WooCommerce plugin.
  Author: Paysend Business
  Version: 2.0.0
 */


if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly


/* Add a custom payment class to WC
  ------------------------------------------------------------ */
add_action('plugins_loaded', 'woocommerce_paysend');

function woocommerce_paysend(){
    load_plugin_textdomain( 'paysend-payment-gateway',  false, dirname( plugin_basename( __FILE__ ) ));
    if (!class_exists('WC_Payment_Gateway'))
        return; // if the WC payment gateway class is not available, do nothing
    if(class_exists('WC_PAYSEND'))
        return;
    if( class_exists('WooCommerce_Payment_Status') )
        add_filter( 'woocommerce_valid_order_statuses_for_payment', array( 'WC_PAYSEND', 'valid_order_statuses_for_payment' ), 52, 2 );

    class WC_PAYSEND extends WC_Payment_Gateway{
        public function __construct(){
            $plugin_dir = plugin_dir_url(__FILE__);


            $this->id = 'paysend';
            $this->icon = apply_filters('woocommerce_paysend_icon', ''.$plugin_dir.'paysend-logo.svg');
            $this->has_fields = false;

            // Load the settings
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->method_description = 'Accept online payments in 30+ currencies with global payment methods, clear rates and next day settlement. Click here to see set up guide.';
            $this->api_key = $this->get_option('api_key');
            $this->api_secret = $this->get_option('api_secret');
            $this->debug = $this->get_option('debug');
            $this->description = $this->get_option('description');
            $this->payment_url = plugins_url( 'payment.php', __FILE__ );

            // Logs
            $this->log = new WC_Logger();

            // Actions
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

            // Save options
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

        }

        /**
         * Check if this gateway is enabled and available in the user's country
         */
        function is_valid_for_use(){
            if (!in_array(get_option('woocommerce_currency'), array('RUB', 'EUR', 'USD', 'CNY', 'GBP', 'JPY', 'UAH') )) {
                return false;
            }
            return true;
        }

        /**
         * Admin Panel Options
         **/
        public function admin_options() {
            ?>
            <h3><?php _e('Paysend Business payment gateway options.', 'paysend-payment-gateway'); ?></h3>

            <?php if ( $this->is_valid_for_use() ) : ?>

                <table class="form-table">

                    <?php
                    // Generate the HTML For the settings form.
                    $this->generate_settings_html();
                    ?>

                    <tr valign="top">
                        <th scope="row" class="titledesc">
                            <label for="woocommerce_paysend_webhook">URL for webhook integration </label>
                        </th>
                        <td class="forminp">
                            <fieldset>
                                <legend class="screen-reader-text"></legend>
                                <div>
                                    <input readonly type="text" name="woocommerce_paysend_webhook" id="woocommerce_paysend_webhook" <?php echo 'value="' . site_url() . '/wp-json/paysend-business/v1/transaction"' ?>>
                                    <p class="description">Copy this url into "Shop settings -> Webhooks" in your Client Cabinet Dashboard. It updates your order status when payment status changes on the payment provider’s side.</p>
                                </div>
                            </fieldset>
                        </td>
                    </tr>
                </table>

            <?php else : ?>
                <div class="inline error"><p><strong><?php _e('Gateway disabled', 'paysend-payment-gateway'); ?></strong>: <?php _e('Paysend does not support your store currencies. Available currencies: RUB, EUR, USD, CNY, GBP, JPY, UAH.', 'paysend-payment-gateway' ); ?></p></div>
            <?php
            endif;

        }

        /**
         * Initialise Gateway Settings Form Fields
         *
         * @access public
         * @return void
         */
        function init_form_fields(){
            $debug = __('(<code>woocommerce/logs/' . $this->id . '.txt</code>)', 'paysend-payment-gateway');
            if ( !version_compare( WOOCOMMERCE_VERSION, '2.0', '<' ) ) {
                if ( version_compare( WOOCOMMERCE_VERSION, '2.2.0', '<' ) )
                    $debug = str_replace( $this->id , $this->id . '-' . sanitize_file_name( wp_hash( $this->id ) ), $debug );
                elseif( function_exists('wc_get_log_file_path') ) {
                    $debug = str_replace( 'woocommerce/logs/' . $this->id . '.txt', '<a href="/wp-admin/admin.php?page=wc-status&tab=logs&log_file=' . $this->id . '-' . sanitize_file_name( wp_hash( $this->id ) ) . '-log" target="_blank">' . wc_get_log_file_path( $this->id ) . '</a>' , $debug );
                }
            }
            $this->form_fields = array(
                'title' => array(
                    'title' => __('Payment gateway name', 'paysend-payment-gateway'),
                    'type' => 'text',
                    'default' => __('Paysend Business', 'paysend-payment-gateway')
                ),
                'api_key' => array(
                    'title' => __('Api key', 'paysend-payment-gateway'),
                    'type' => 'text',
                    'default' => ''
                ),
                'api_secret' => array(
                    'title' => __('Api secret', 'paysend-payment-gateway'),
                    'type' => 'password',
                    'default' => ''
                ),
                'description' => array(
                    'title' => __( 'Description', 'paysend-payment-gateway' ),
                    'type' => 'textarea',
                    'description' => __( 'A description of the payment method that the client will see on your site.', 'paysend-payment-gateway' ),
                    'default' => 'Pay with your credit or debit card via Paysend Business.'
                ),
                'debug' => array(
                    'title' => __('Debug', 'paysend-payment-gateway'),
                    'type' => 'checkbox',
                    'label' => $debug,
                    'default' => 'no'
                ),
            );
        }

        /**
         * Generate the dibs button link
         **/
        public function generate_form($order_id){
            $order = new WC_Order( $order_id );

            $amount_sum = number_format($order->order_total, 2, '.', '');
            $amount = floatval($amount_sum);

            $api_key = $this->api_key;
            $api_secret = $this->api_secret;
            $payment_url = $this->payment_url;
            $description = 'Order number '. $order_id;
            $currency = get_woocommerce_currency();

            $array = array(
                "apiKey" => "$api_key",
                "orderId" => "$order_id",
                "amount" => $amount,
                "currency" => "$currency",
                "isRecurring" => false,
                "description" => "$description",
                "callbackUrl" => 'https://gateway.business.paysend.com/ecommerce/payments/result'
            );

            $json = json_encode($array, JSON_UNESCAPED_SLASHES);

            $signature = hash_hmac('sha256', $json, $api_secret);

            $form = "<script type=\"text/javascript\" src=\"https://pay.business.paysend.com/paysendPaymentLibrary.umd.min.js\"></script>";

            $form .= "<script type=\"text/javascript\">
       function send() {
           let json = window.PaysendBusinessPayment.setPaymentData({
               apiKey: '$api_key',
               orderId: '$order_id',
               description: '$description',
               isRecurring: false,
               currency: '$currency',
               amount: $amount,
               customerEmail: ''
           })
           let isSupportedApplePayJs = false;
            if (window.ApplePaySession) {
                isSupportedApplePayJs = true;
            }    

           //encrypt order
                 let eventMessage = {
          eventType: 'PaysendBusiness_OpenModal',
          details: '$signature',
          isApplePayAvailable: isSupportedApplePayJs
      };

      window.postMessage(eventMessage, window.PaysendBusinessPayment.PaysendBusinessPaymentHost);   
      
        //event subscription
        window.addEventListener('message', (event) => {
			//console.log(event.data.eventType);
				if (event.data.eventType === 'PaysendBusiness_PaymentCompleted') {
					jQuery.post('$payment_url', { orderId:event.data.details.orderId,status:event.data.eventType })
					.done(function(data) {
					window.location = data;
					});
				}
		});
  }
   </script>";

            $form .= "<input id=\"paysend\" type=\"submit\" onclick=\"send()\" value=\"Pay\">";
            $form .= "<script type=\"text/javascript\"> document.getElementById('paysend').click(); </script>";

            return $form;
        }


        /**
         * Process the payment and return the result
         **/
        function process_payment($order_id){
            $order = new WC_Order($order_id);

            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

        /**
         * receipt_page
         **/
        function receipt_page($order){
            echo $this->generate_form($order);
        }
    }

    /**
     * Add the gateway to WooCommerce
     **/
    function add_paysend_gateway($methods){
        $methods[] = 'WC_PAYSEND';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_paysend_gateway');
}

define( 'PLUGIN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
add_action( 'rest_api_init', 'init_transaction_REST_controller');
function init_transaction_REST_controller() {
    require_once PLUGIN_PATH . '/includes/controllers/class-transaction-rest-controller.php';
    $controller = new Transaction_REST_Controller();
    $controller->register_routes();
}