<?php 


if ($_POST['status'] == 'PaysendBusiness_PaymentCompleted') {

	require_once($_SERVER['DOCUMENT_ROOT'].'/wp-load.php');

		$order = new WC_Order($_POST['orderId']);
		$order->payment_complete();
		
		header('HTTP/1.1 200 OK');
		echo ($order->get_checkout_order_received_url());
}