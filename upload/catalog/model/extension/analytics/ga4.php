<?php
class ModelExtensionAnalyticsGa4 extends Model {
	const MEASUREMENT_ID = 'G-MFLM29M1TD';

	public function getMeasurementId() {
		return self::MEASUREMENT_ID;
	}

	public function getProductEvent($product_info, $quantity = 1) {
		$item = $this->getItemFromProduct($product_info, $quantity);

		return array(
			'currency' => $this->getCurrentCurrency(),
			'value'    => $this->calculateItemsValue(array($item)),
			'items'    => array($item)
		);
	}

	public function getCartEvent($products) {
		$items = array();
		$index = 0;

		foreach ($products as $product) {
			$items[] = $this->getItemFromCartProduct($product, $index);
			$index++;
		}

		return array(
			'currency' => $this->getCurrentCurrency(),
			'value'    => $this->calculateItemsValue($items),
			'items'    => $items
		);
	}

	public function getPurchaseEvent($order_info, $order_products, $order_totals) {
		$items = array();
		$index = 0;

		foreach ($order_products as $product) {
			$items[] = $this->getItemFromOrderProduct($product, $order_info, $index);
			$index++;
		}

		$tax = 0;
		$shipping = 0;

		foreach ($order_totals as $order_total) {
			if ($order_total['code'] == 'tax') {
				$tax += (float)$order_total['value'];
			}

			if ($order_total['code'] == 'shipping') {
				$shipping += (float)$order_total['value'];
			}
		}

		$event = array(
			'transaction_id' => (string)$order_info['order_id'],
			'currency'       => $order_info['currency_code'],
			'value'          => max(0, $this->toCurrencyValue((float)$order_info['total'] - $tax - $shipping, $order_info['currency_code'], $order_info['currency_value'])),
			'tax'            => $this->toCurrencyValue($tax, $order_info['currency_code'], $order_info['currency_value']),
			'shipping'       => $this->toCurrencyValue($shipping, $order_info['currency_code'], $order_info['currency_value']),
			'items'          => $items
		);

		$coupon = $this->getOrderCoupon($order_info['order_id']);

		if ($coupon) {
			$event['coupon'] = $coupon;
		}

		return $event;
	}

	public function encode($event) {
		return json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	private function getItemFromProduct($product_info, $quantity = 1, $index = null) {
		$price = $this->getProductPrice($product_info);
		$category = $this->getProductCategory($product_info['product_id']);

		return $this->cleanItem(array(
			'item_id'       => (string)$product_info['product_id'],
			'item_name'     => html_entity_decode($product_info['name'], ENT_QUOTES, 'UTF-8'),
			'item_brand'    => isset($product_info['manufacturer']) ? html_entity_decode($product_info['manufacturer'], ENT_QUOTES, 'UTF-8') : '',
			'item_category' => $category,
			'index'         => $index,
			'price'         => $this->toCurrencyValue($price),
			'quantity'      => (int)$quantity
		));
	}

	private function getItemFromCartProduct($product, $index = null) {
		$extra = $this->getProductExtra($product['product_id']);

		return $this->cleanItem(array(
			'item_id'       => (string)$product['product_id'],
			'item_name'     => html_entity_decode($product['name'], ENT_QUOTES, 'UTF-8'),
			'item_brand'    => $extra['manufacturer'],
			'item_category' => $extra['category'],
			'item_variant'  => $this->getOptionsText(isset($product['option']) ? $product['option'] : array()),
			'index'         => $index,
			'price'         => $this->toCurrencyValue((float)$product['price']),
			'quantity'      => (int)$product['quantity']
		));
	}

	private function getItemFromOrderProduct($product, $order_info, $index = null) {
		$extra = $this->getProductExtra($product['product_id']);

		return $this->cleanItem(array(
			'item_id'       => (string)$product['product_id'],
			'item_name'     => html_entity_decode($product['name'], ENT_QUOTES, 'UTF-8'),
			'item_brand'    => $extra['manufacturer'],
			'item_category' => $extra['category'],
			'item_variant'  => $this->getOptionsText(isset($product['option']) ? $product['option'] : array()),
			'index'         => $index,
			'price'         => $this->toCurrencyValue((float)$product['price'], $order_info['currency_code'], $order_info['currency_value']),
			'quantity'      => (int)$product['quantity']
		));
	}

	private function getProductPrice($product_info) {
		if (isset($product_info['special']) && $product_info['special'] !== null && (float)$product_info['special'] >= 0) {
			return (float)$product_info['special'];
		}

		return (float)$product_info['price'];
	}

	private function calculateItemsValue($items) {
		$value = 0;

		foreach ($items as $item) {
			$value += (float)$item['price'] * (int)$item['quantity'];
		}

		return round($value, 2);
	}

	private function toCurrencyValue($value, $currency_code = '', $currency_value = '') {
		if (!$currency_code) {
			$currency_code = $this->getCurrentCurrency();
		}

		return (float)$this->currency->format((float)$value, $currency_code, $currency_value, false);
	}

	private function getCurrentCurrency() {
		if (isset($this->session->data['currency'])) {
			return $this->session->data['currency'];
		}

		return $this->config->get('config_currency');
	}

	private function getProductExtra($product_id) {
		$product_id = (int)$product_id;
		$manufacturer = '';
		$category = $this->getProductCategory($product_id);

		$query = $this->db->query("SELECT m.name AS manufacturer FROM " . DB_PREFIX . "product p LEFT JOIN " . DB_PREFIX . "manufacturer m ON (p.manufacturer_id = m.manufacturer_id) WHERE p.product_id = '" . $product_id . "' LIMIT 1");

		if ($query->num_rows && $query->row['manufacturer']) {
			$manufacturer = html_entity_decode($query->row['manufacturer'], ENT_QUOTES, 'UTF-8');
		}

		return array(
			'manufacturer' => $manufacturer,
			'category'     => $category
		);
	}

	private function getProductCategory($product_id) {
		$query = $this->db->query("SELECT cd.name FROM " . DB_PREFIX . "product_to_category p2c LEFT JOIN " . DB_PREFIX . "category_description cd ON (p2c.category_id = cd.category_id) WHERE p2c.product_id = '" . (int)$product_id . "' AND cd.language_id = '" . (int)$this->config->get('config_language_id') . "' ORDER BY p2c.category_id ASC LIMIT 1");

		if ($query->num_rows && $query->row['name']) {
			return html_entity_decode($query->row['name'], ENT_QUOTES, 'UTF-8');
		}

		return '';
	}

	private function getOptionsText($options) {
		$values = array();

		foreach ($options as $option) {
			if (!empty($option['name']) && isset($option['value'])) {
				$values[] = html_entity_decode($option['name'] . ': ' . $option['value'], ENT_QUOTES, 'UTF-8');
			}
		}

		return implode(', ', $values);
	}

	private function getOrderCoupon($order_id) {
		$query = $this->db->query("SELECT c.code FROM `" . DB_PREFIX . "coupon_history` ch LEFT JOIN `" . DB_PREFIX . "coupon` c ON (c.coupon_id = ch.coupon_id) WHERE ch.order_id = '" . (int)$order_id . "' LIMIT 1");

		if ($query->num_rows && $query->row['code']) {
			return $query->row['code'];
		}

		return '';
	}

	private function cleanItem($item) {
		foreach ($item as $key => $value) {
			if ($value === '' || $value === null) {
				unset($item[$key]);
			}
		}

		return $item;
	}
}
