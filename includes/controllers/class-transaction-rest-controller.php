<?php
/**
 * Class Transaction_REST_Controller
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PLUGIN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );

require_once PLUGIN_PATH . '/includes/constants/ecom-payment-status.php';

class Transaction_REST_Controller extends WP_REST_Controller {
	function __construct() {
		$apiVersion = 1;
		$this->namespace = 'paysend-business/v' . $apiVersion;
		$this->rest_base = '/transaction';
	}

	function register_routes() {
		//endpoint: /wp-json/paysend-business/v1/transaction
		register_rest_route($this->namespace, $this->rest_base, [
			// POST
			[
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => [$this, 'update_order_status'],
				'args' => $this->get_post_request_arguments()
			]
		]);
	}

	function update_order_status(WP_REST_Request $request) {
		$order = new WC_Order($request->get_param('orderId'));
		$final_status = '';
		$ecom_payment_status = $request->get_param('status');

		if ($ecom_payment_status == Ecom_Payment_Status::Completed) {
			return $order->payment_complete();
		}

		switch ($ecom_payment_status) {
			case Ecom_Payment_Status::Cancelled:
				$final_status = 'cancelled';
				break;
			case Ecom_Payment_Status::Failed:
				$final_status = 'failed';
				break;
		}
		return $order->update_status($final_status);
	}

	function get_post_request_arguments() {
		$args = array();

		$args['reference'] = [
			'type' => 'string',
			'required' => true
		];

		$args['status'] = [
			'type' => 'string',
			'required' => true
		];

		$args['errorMessage'] = [
			'type' => null,
			'required' => false
		];

		$args['paymentMethod'] = [
			'type' => 'string',
			'required' => true
		];

		$args['created'] = [
			'type' => '',
			'required' => true
		];

		$args['orderId'] = [
			'type' => 'string',
			'required' => true
		];

		$args['amount'] = [
			'type' => 'number',
			'required' => true
		];

		return $args;
	}
}