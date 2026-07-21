<?php
class ControllerExtensionFeedGoogleBase extends Controller {
	const LANGUAGE_CODE = 'hr-hr';
	const CURRENCY_CODE = 'EUR';

	public function index() {
		if ($this->config->has('feed_google_base_status') && !$this->config->get('feed_google_base_status')) {
			$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 404 Not Found');
			$this->response->setOutput('Google Merchant XML feed is disabled.');
			return;
		}

		$this->load->model('extension/feed/google_base');

		$language_id = $this->model_extension_feed_google_base->getLanguageId(self::LANGUAGE_CODE);
		$products = $this->model_extension_feed_google_base->getProducts($language_id);
		$google_categories = $this->model_extension_feed_google_base->getProductGoogleCategories();
		$product_types = $this->model_extension_feed_google_base->getProductTypes($language_id);

		$store_url = rtrim($this->config->get('config_ssl') ?: $this->config->get('config_url'), '/') . '/';
		$store_name = $this->plainText($this->config->get('config_name'), 150);
		$store_description = $this->plainText($this->config->get('config_meta_description'), 5000);

		$output = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$output .= '<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">' . "\n";
		$output .= "  <channel>\n";
		$output .= '    <title>' . $this->xml($store_name) . "</title>\n";
		$output .= '    <link>' . $this->xml($store_url) . "</link>\n";
		$output .= '    <description>' . $this->xml($store_description) . "</description>\n";

		foreach ($products as $product) {
			$title = $this->plainText($product['name'], 150);
			$description = $this->plainText($product['description'], 5000);
			$link = html_entity_decode($this->url->link('product/product', 'product_id=' . (int)$product['product_id'], true), ENT_QUOTES, 'UTF-8');
			$image_link = $this->getImageLink($product['image']);

			if ($title === '' || $description === '' || $image_link === '') {
				continue;
			}

			$price = $product['special'] !== null ? $product['special'] : ($product['discount'] !== null ? $product['discount'] : $product['price']);
			$price = $this->tax->calculate((float)$price, (int)$product['tax_class_id']);
			$price = $this->currency->format($price, self::CURRENCY_CODE, $this->currency->getValue(self::CURRENCY_CODE), false);
			$price = number_format((float)$price, 2, '.', '');
			$gtin = $this->getGtin($product);
			$brand = $this->getBrand($product);
			$mpn = $this->plainText($product['mpn'] !== '' ? $product['mpn'] : $product['model'], 70);

			$output .= "    <item>\n";
			$output .= '      <g:id>' . (int)$product['product_id'] . "</g:id>\n";
			$output .= '      <g:title>' . $this->xml($title) . "</g:title>\n";
			$output .= '      <g:description>' . $this->xml($description) . "</g:description>\n";
			$output .= '      <g:link>' . $this->xml($link) . "</g:link>\n";
			$output .= '      <g:image_link>' . $this->xml($image_link) . "</g:image_link>\n";
			$output .= "      <g:availability>in_stock</g:availability>\n";
			$output .= '      <g:price>' . $this->xml($price . ' ' . self::CURRENCY_CODE) . "</g:price>\n";
			$output .= "      <g:condition>new</g:condition>\n";

			if ($brand !== '') {
				$output .= '      <g:brand>' . $this->xml($brand) . "</g:brand>\n";
			}

			if ($gtin !== '') {
				$output .= '      <g:gtin>' . $gtin . "</g:gtin>\n";
			}

			if ($mpn !== '') {
				$output .= '      <g:mpn>' . $this->xml($mpn) . "</g:mpn>\n";
			}

			if ($gtin === '' && ($brand === '' || $mpn === '')) {
				$output .= "      <g:identifier_exists>false</g:identifier_exists>\n";
			}

			if (isset($google_categories[$product['product_id']])) {
				$output .= '      <g:google_product_category>' . (int)$google_categories[$product['product_id']] . "</g:google_product_category>\n";
			}

			if (isset($product_types[$product['product_id']])) {
				$product_type = $this->plainText($product_types[$product['product_id']], 750);

				if ($product_type !== '') {
					$output .= '      <g:product_type>' . $this->xml($product_type) . "</g:product_type>\n";
				}
			}

			$output .= "    </item>\n";
		}

		$output .= "  </channel>\n";
		$output .= "</rss>\n";

		$this->response->addHeader('Content-Type: application/xml; charset=UTF-8');
		$this->response->addHeader('X-Robots-Tag: noindex, nofollow');
		$this->response->setOutput($output);
	}

	private function getImageLink($image) {
		$image = ltrim((string)$image, '/');

		if ($image === '' || !is_file(DIR_IMAGE . $image)) {
			return '';
		}

		$base_url = rtrim($this->config->get('config_ssl') ?: $this->config->get('config_url'), '/') . '/';
		$segments = explode('/', str_replace('\\', '/', $image));
		$segments = array_map('rawurlencode', $segments);

		return $base_url . 'image/' . implode('/', $segments);
	}

	private function getGtin($product) {
		$candidates = array($product['ean'], $product['upc'], $product['jan'], $product['isbn'], $product['sku'], $product['model']);

		foreach ($candidates as $candidate) {
			$gtin = preg_replace('/[^0-9]/', '', (string)$candidate);

			if ($this->isValidGtin($gtin)) {
				return $gtin;
			}
		}

		return '';
	}

	private function getBrand($product) {
		$brand = $this->plainText($product['manufacturer'], 70);

		if ($brand !== '') {
			return $brand;
		}

		$overrides = array(
			3443  => 'Renkforce',
			11783 => 'Subsonic'
		);

		return isset($overrides[$product['product_id']]) ? $overrides[$product['product_id']] : '';
	}

	private function isValidGtin($gtin) {
		$length = strlen($gtin);

		if (!in_array($length, array(8, 12, 13, 14), true)) {
			return false;
		}

		$sum = 0;
		$position = 0;

		for ($index = $length - 2; $index >= 0; $index--) {
			$sum += (int)$gtin[$index] * ($position % 2 === 0 ? 3 : 1);
			$position++;
		}

		$check_digit = (10 - ($sum % 10)) % 10;

		return $check_digit === (int)$gtin[$length - 1];
	}

	private function plainText($value, $limit) {
		$value = (string)$value;

		for ($pass = 0; $pass < 3; $pass++) {
			$decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

			if ($decoded === $value) {
				break;
			}

			$value = $decoded;
		}

		$value = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/iu', ' ', $value);
		$value = strip_tags($value);
		$value = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value);
		$value = preg_replace('/\s+/u', ' ', $value);
		$value = trim($value);

		return utf8_substr($value, 0, $limit);
	}

	private function xml($value) {
		return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
	}
}
