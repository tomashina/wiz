<?php
class ModelExtensionPaymentWSPay extends Model {
	public function getMethod($address, $total) {
		$this->language->load('extension/payment/wspay');

		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('wspay_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

		if ($this->config->get('wspay_total') > 0 && $this->config->get('wspay_total') > $total) {
			$status = false;
		} elseif (!$this->config->get('wspay_geo_zone_id')) {
			$status = true;
		} elseif ($query->num_rows) {
			$status = true;
		} else {
			$status = false;
		}

		$method_data = array();

		if ($status) {
			$method_data = array(
				'code'       => 'wspay',
				'title'      => $this->language->get('text_title'),
				'terms'      => '',
				'sort_order' => $this->config->get('wspay_sort_order')
			);
		}

		return $method_data;
	}

	public function confirmOrder($order_id, $order_status_id) {
		// Serialize duplicate gateway returns before OpenCart updates stock and order history.
		$lock = hash('sha256', 'wspay:' . DB_DATABASE . ':' . DB_PREFIX . ':' . $order_id);
		$query = $this->db->query("SELECT GET_LOCK('" . $lock . "', 5) AS acquired");

		if ((int)$query->row['acquired'] !== 1) {
			throw new RuntimeException('Could not lock WSPay order ' . $order_id);
		}

		try {
			$this->load->model('checkout/order');
			$order_info = $this->model_checkout_order->getOrder($order_id);

			if (!$order_info || $order_info['payment_code'] !== 'wspay') {
				return false;
			}

			if ((int)$order_info['order_status_id'] === 0) {
				$this->model_checkout_order->addOrderHistory($order_id, $order_status_id, '', true);
			}

			return true;
		} finally {
			$this->db->query("SELECT RELEASE_LOCK('" . $lock . "')");
		}
	}
}
