<?php
class ControllerCheckoutSuccess extends Controller {
	public function index() {
		$this->load->language('checkout/success');

		$data = array();
		$data['ga4_purchase'] = '';
		$bank_success_append = '';

		if (isset($this->session->data['order_id'])) {
			$order_id = (int)$this->session->data['order_id'];

			$this->load->model('checkout/order');
			$this->load->model('extension/analytics/ga4');

			$order_info = $this->model_checkout_order->getOrder($order_id);

			if ($order_info) {
				$order_products = $this->model_checkout_order->getOrderProducts($order_id);

				foreach ($order_products as &$order_product) {
					$order_product['option'] = $this->model_checkout_order->getOrderOptions($order_id, $order_product['order_product_id']);
				}

				unset($order_product);

				$order_totals = $this->model_checkout_order->getOrderTotals($order_id);
				$data['ga4_purchase'] = $this->model_extension_analytics_ga4->encode($this->model_extension_analytics_ga4->getPurchaseEvent($order_info, $order_products, $order_totals));

				if ($order_info['payment_code'] == 'bank_transfer') {
					require_once(DIR_SYSTEM . 'library/dryzen_bank_transfer_qr.php');

					$dryzen_bank_transfer_qr = new DryzenBankTransferQr($this->registry);
					$qr_file = $dryzen_bank_transfer_qr->generateForOrder($order_info);
					$bank_instructions = nl2br($this->config->get('payment_bank_transfer_bank' . $order_info['language_id']));
					$bank_instructions = str_replace('%order_id%', $order_info['order_id'], $bank_instructions);

					if ($bank_instructions) {
						$bank_success_append = '<div class="bank-transfer-instructions" style="margin-top:20px;">' . $bank_instructions . '</div>';
					}

					$bank_success_append .= $dryzen_bank_transfer_qr->getImageHtml($order_info, $qr_file);
				}
			}

			$this->cart->clear();

			unset($this->session->data['shipping_method']);
			unset($this->session->data['shipping_methods']);
			unset($this->session->data['payment_method']);
			unset($this->session->data['payment_methods']);
			unset($this->session->data['guest']);
			unset($this->session->data['comment']);
			unset($this->session->data['order_id']);
			unset($this->session->data['coupon']);
			unset($this->session->data['reward']);
			unset($this->session->data['voucher']);
			unset($this->session->data['vouchers']);
			unset($this->session->data['totals']);
		}

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/home')
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_basket'),
			'href' => $this->url->link('checkout/cart')
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_checkout'),
			'href' => $this->url->link('checkout/checkout', '', true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_success'),
			'href' => $this->url->link('checkout/success')
		);

		if ($this->customer->isLogged()) {
			$data['text_message'] = sprintf($this->language->get('text_customer'), $this->url->link('account/account', '', true), $this->url->link('account/order', '', true), $this->url->link('account/download', '', true), $this->url->link('information/contact'));
		} else {
			$data['text_message'] = sprintf($this->language->get('text_guest'), $this->url->link('information/contact'));
		}

		if ($bank_success_append) {
			$data['text_message'] .= $bank_success_append;
		}

		$data['continue'] = $this->url->link('common/home');

		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->setOutput($this->load->view('common/success', $data));
	}
}
