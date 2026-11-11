<?php
class ControllerExtensionPaymentWSPay extends Controller {
	public function index() {
		$this->load->language('extension/payment/wspay');
		$this->load->model('checkout/order');

		$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id'] ?? 0);
		$merchant = (string)$this->config->get('payment_wspay_merchant');
		$secret = (string)$this->config->get('payment_wspay_password');

		if (!$order_info || $order_info['payment_code'] !== 'wspay' || $merchant === '' || $secret === '') {
			return '';
		}

		$data['button_confirm'] = $this->language->get('button_confirm');
		$data['action'] = $this->config->get('payment_wspay_test')
			? 'https://formtest.wspay.biz/Authorization.aspx'
			: 'https://form.wspay.biz/Authorization.aspx';
		$data['merchant'] = $merchant;
		$data['order_id'] = $order_info['order_id'];
		$data['currency'] = $order_info['currency_code'];
		$data['tecaj'] = $order_info['currency_value'];
		$data['total'] = number_format((float)$order_info['total'], 2, ',', '');

		$amount = str_replace(',', '', $data['total']);
		$data['signature'] = hash('sha512', $merchant . $secret . $data['order_id'] . $secret . $amount . $secret);

		$data['address'] = $order_info['payment_address_1'];
		$data['city'] = $order_info['payment_city'];
		$data['firstname'] = $order_info['payment_firstname'];
		$data['lastname'] = $order_info['payment_lastname'];
		$data['postcode'] = $order_info['payment_postcode'];
		$data['country'] = $order_info['payment_iso_code_2'];
		$data['telephone'] = $order_info['telephone'];
		$data['email'] = $order_info['email'];

		$data['cancel_url'] = $this->url->link('checkout/checkout', '', true);
		$data['return_url'] = $this->url->link('extension/payment/wspay/callback', '', true);

		return $this->load->view('extension/payment/wspay', $data);
	}

	public function callback() {
		// Verify the original field values before OpenCart's request escaping.
		$result = $this->request->server['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
		$success = $result['Success'] ?? '';
		$cart_id = $result['ShoppingCartID'] ?? '';
		$approval_code = $result['ApprovalCode'] ?? '';
		$signature = $result['Signature'] ?? '';

		if ($success !== '1') {
			$this->response->redirect($this->url->link('checkout/checkout', '', true), 303);
			return;
		}

		$merchant = (string)$this->config->get('payment_wspay_merchant');
		$secret = (string)$this->config->get('payment_wspay_password');
		$order_status_id = (int)$this->config->get('payment_wspay_order_status_id');

		if (!is_string($cart_id) || !ctype_digit($cart_id) || (string)(int)$cart_id !== $cart_id || $cart_id === '0'
			|| !is_string($approval_code) || trim($approval_code) === ''
			|| !is_string($signature) || !preg_match('/\A[0-9a-f]{128}\z/i', $signature)
			|| $merchant === '' || $secret === '' || $order_status_id <= 0) {
			http_response_code(400);
			return;
		}

		$expected = hash('sha512', $merchant . $secret . $cart_id . $secret . $success . $secret . $approval_code . $secret);

		if (!hash_equals($expected, strtolower($signature))) {
			http_response_code(400);
			return;
		}

		$order_id = (int)$cart_id;
		$this->load->model('extension/payment/wspay');

		if (!$this->model_extension_payment_wspay->confirmOrder($order_id, $order_status_id)) {
			http_response_code(400);
			return;
		}

		// The success page clears the current checkout. Leave a different order's cart intact.
		$session_order_id = (int)($this->session->data['order_id'] ?? 0);
		$route = !$session_order_id || $session_order_id === $order_id ? 'checkout/success' : 'checkout/cart';
		$this->response->redirect($this->url->link($route, '', true), 303);
	}
}
