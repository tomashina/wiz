<?php
class ControllerStartupProductCanonical extends Controller {
	public function index() {
		$method = isset($this->request->server['REQUEST_METHOD']) ? $this->request->server['REQUEST_METHOD'] : '';

		if (!in_array($method, array('GET', 'HEAD'), true)
			|| !isset($this->request->get['route'], $this->request->get['product_id'])
			|| $this->request->get['route'] !== 'product/product'
			|| !isset($this->request->server['REQUEST_URI'])) {
			return;
		}

		// Preserve campaign and product-specific parameters, removing listing context
		// through the same URL builder used by canonical tags and internal links.
		// Request HTML-escapes server values; decode that once and parse the original
		// query so encoded ampersands in tracking values cannot cause redirect loops.
		$request_uri = html_entity_decode($this->request->server['REQUEST_URI'], ENT_QUOTES, 'UTF-8');
		$query = parse_url($request_uri, PHP_URL_QUERY);
		$args = array();
		parse_str($query === null || $query === false ? '' : $query, $args);
		unset($args['route'], $args['_route_'], $args['product_id']);
		$args = array('product_id' => (int)$this->request->get['product_id']) + $args;
		$target = html_entity_decode($this->url->link('product/product', $args, true), ENT_QUOTES, 'UTF-8');
		$target_parts = parse_url($target);
		$target_uri = $target_parts['path'];

		if (isset($target_parts['query'])) {
			$target_uri .= '?' . $target_parts['query'];
		}

		if ($request_uri === $target_uri) {
			return;
		}

		$this->load->model('catalog/product');
		$product = $this->model_catalog_product->getProduct((int)$this->request->get['product_id']);

		if ($product && !empty($product['status'])) {
			$this->response->redirect($target, 301);
		}
	}
}
