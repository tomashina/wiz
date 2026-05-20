<?php
class ControllerExtensionPaymentBankTransfer extends Controller {
	public function index() {
		$this->load->language('extension/payment/bank_transfer');

		$order_id = isset($this->session->data['order_id']) ? (int)$this->session->data['order_id'] : 0;

		$data['bank'] = nl2br($this->config->get('payment_bank_transfer_bank' . $this->config->get('config_language_id')));
		$data['bank'] = str_replace('%order_id%', $order_id, $data['bank']);

		if ($order_id) {
			$this->load->model('checkout/order');

			$order_info = $this->model_checkout_order->getOrder($order_id);

			if ($order_info) {
				require_once(DIR_SYSTEM . 'library/dryzen_bank_transfer_qr.php');

				$dryzen_bank_transfer_qr = new DryzenBankTransferQr($this->registry);
				$qr_html = $dryzen_bank_transfer_qr->getImageHtml($order_info);

				if ($qr_html) {
					$data['bank'] .= $qr_html;
				}
			}
		}

		return $this->load->view('extension/payment/bank_transfer', $data);
		
	}

	public function confirm() {
		$json = array();
		
		if (isset($this->session->data['payment_method']['code']) && $this->session->data['payment_method']['code'] == 'bank_transfer') {
			$this->load->language('extension/payment/bank_transfer');

			$this->load->model('checkout/order');

			$comment  = $this->language->get('text_instruction') . "\n\n";
			$comment .= $this->config->get('payment_bank_transfer_bank' . $this->config->get('config_language_id')) . "\n\n";
			$comment .= $this->language->get('text_payment');
			$comment = str_replace('%order_id%', $this->session->data['order_id'], $comment);

			$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

			if ($order_info) {
				require_once(DIR_SYSTEM . 'library/dryzen_bank_transfer_qr.php');

				$dryzen_bank_transfer_qr = new DryzenBankTransferQr($this->registry);
				$comment = $dryzen_bank_transfer_qr->appendImageToComment($comment, $order_info);
			}

			$this->model_checkout_order->addOrderHistory($this->session->data['order_id'], $this->config->get('payment_bank_transfer_order_status_id'), $comment, true);
		
			$json['redirect'] = $this->url->link('checkout/success');
		}
		
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));		
	}
}
