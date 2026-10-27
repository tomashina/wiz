<?php
class ControllerExtensionFeedGoogleSitemap extends Controller {
	// Keep every response small, even when the catalog grows beyond 50,000 URLs.
	const PAGE_SIZE = 1000;

	public function index() {
		$this->load->model('extension/feed/google_sitemap');

		$output = $this->xmlHeader();
		$output .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

		foreach ($this->model_extension_feed_google_sitemap->getTotals() as $type => $total) {
			$pages = (int)ceil($total / self::PAGE_SIZE);

			for ($page = 1; $page <= $pages; $page++) {
				$output .= '  <sitemap><loc>' . $this->xml($this->sitemapUrl($type, $page)) . '</loc></sitemap>' . PHP_EOL;
			}
		}

		$output .= '</sitemapindex>';
		$this->respond($output);
	}

	public function products() {
		$this->outputUrls('getProducts', 'product/product', 'product_id', 'product_id');
	}

	public function categories() {
		// The category controller's canonical uses the category ID alone.
		$this->outputUrls('getCategories', 'product/category', 'path', 'category_id');
	}

	public function manufacturers() {
		$this->outputUrls('getManufacturers', 'product/manufacturer/info', 'manufacturer_id', 'manufacturer_id');
	}

	public function information() {
		$this->outputUrls('getInformations', 'information/information', 'information_id', 'information_id');
	}

	private function outputUrls($method, $route, $parameter, $id_key) {
		$page = isset($this->request->get['page']) ? $this->request->get['page'] : '1';

		if (!is_scalar($page) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$page)) {
			$this->notFound();
			return;
		}

		$this->load->model('extension/feed/google_sitemap');
		$results = $this->model_extension_feed_google_sitemap->{$method}(((int)$page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

		if (!$results) {
			$this->notFound();
			return;
		}

		$output = $this->xmlHeader();
		$output .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

		foreach ($results as $result) {
			$url = $this->url->link($route, $parameter . '=' . (int)$result[$id_key], true);
			$output .= '  <url><loc>' . $this->xml($url) . '</loc>';

			if (isset($result['date_modified'])) {
				$lastmod = $this->lastModified($result['date_modified']);

				if ($lastmod) {
					$output .= '<lastmod>' . $lastmod . '</lastmod>';
				}
			}

			$output .= '</url>' . PHP_EOL;
		}

		$output .= '</urlset>';
		$this->respond($output);
	}

	private function sitemapUrl($type, $page) {
		$base = $this->config->get('config_ssl') ?: $this->config->get('config_url');

		return rtrim($base, '/') . '/sitemap-' . $type . '-' . (int)$page . '.xml';
	}

	private function lastModified($value) {
		// Zero/invalid dates must not turn into 1970 or an invented current date.
		$date = DateTime::createFromFormat('!Y-m-d H:i:s', (string)$value);

		if ($date && $date->format('Y-m-d H:i:s') === $value && $date->getTimestamp() <= time()) {
			return $date->format('Y-m-d');
		}

		return '';
	}

	private function xml($value) {
		// OpenCart URL helpers already return HTML-escaped query separators.
		return htmlspecialchars(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
	}

	private function xmlHeader() {
		return '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
	}

	private function respond($output) {
		// Indexing must remain available independently of the legacy feed toggle.
		$this->response->addHeader('Content-Type: application/xml; charset=UTF-8');
		$this->response->setOutput($output);
	}

	private function notFound() {
		$this->response->addHeader('HTTP/1.1 404 Not Found');
		$this->respond($this->xmlHeader() . '<error>Sitemap page not found.</error>');
	}
}
