<?php
class ControllerCheckoutSuccess extends Controller {
	public function index() {
		$this->load->language('checkout/success');

		$data = array();
		$data['ga4_purchase'] = '';

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
