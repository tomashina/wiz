<?php
class ModelExtensionFeedGoogleSitemap extends Model {
	public function getTotals() {
		$totals = array();

		foreach ($this->queries() as $type => $query) {
			$result = $this->db->query('SELECT COUNT(DISTINCT ' . $query['id'] . ') AS total ' . $query['from']);
			$totals[$type] = (int)$result->row['total'];
		}

		return $totals;
	}

	public function getProducts($start, $limit) {
		return $this->getRows('products', $start, $limit);
	}

	public function getCategories($start, $limit) {
		return $this->getRows('categories', $start, $limit);
	}

	public function getManufacturers($start, $limit) {
		return $this->getRows('manufacturers', $start, $limit);
	}

	public function getInformations($start, $limit) {
		return $this->getRows('information', $start, $limit);
	}

	private function getRows($type, $start, $limit) {
		$queries = $this->queries();
		$query = $queries[$type];
		$start = max(0, (int)$start);
		$limit = min(1000, max(1, (int)$limit));
		$result = $this->db->query('SELECT DISTINCT ' . $query['fields'] . ' ' . $query['from'] . ' ORDER BY ' . $query['id'] . ' ASC LIMIT ' . $start . ',' . $limit);

		return $result->rows;
	}

	private function queries() {
		$store_id = (int)$this->config->get('config_store_id');
		$language_id = (int)$this->config->get('config_language_id');

		// Use the same public visibility rules for totals and pages. Stock does
		// not determine indexability: an enabled out-of-stock product stays here.
		return array(
			'products' => array(
				'id' => 'p.product_id',
				'fields' => 'p.product_id, p.date_modified',
				'from' => "FROM `" . DB_PREFIX . "product` p JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . $language_id . "') JOIN `" . DB_PREFIX . "product_to_store` p2s ON (p2s.product_id = p.product_id AND p2s.store_id = '" . $store_id . "') WHERE p.status = '1' AND p.date_available <= NOW()"
			),
			'categories' => array(
				'id' => 'c.category_id',
				'fields' => 'c.category_id, c.date_modified',
				'from' => "FROM `" . DB_PREFIX . "category` c JOIN `" . DB_PREFIX . "category_description` cd ON (cd.category_id = c.category_id AND cd.language_id = '" . $language_id . "') JOIN `" . DB_PREFIX . "category_to_store` c2s ON (c2s.category_id = c.category_id AND c2s.store_id = '" . $store_id . "') WHERE c.status = '1'"
			),
			'manufacturers' => array(
				'id' => 'm.manufacturer_id',
				'fields' => 'm.manufacturer_id',
				'from' => "FROM `" . DB_PREFIX . "manufacturer` m JOIN `" . DB_PREFIX . "manufacturer_to_store` m2s ON (m2s.manufacturer_id = m.manufacturer_id AND m2s.store_id = '" . $store_id . "')"
			),
			'information' => array(
				'id' => 'i.information_id',
				'fields' => 'i.information_id',
				'from' => "FROM `" . DB_PREFIX . "information` i JOIN `" . DB_PREFIX . "information_description` id ON (id.information_id = i.information_id AND id.language_id = '" . $language_id . "') JOIN `" . DB_PREFIX . "information_to_store` i2s ON (i2s.information_id = i.information_id AND i2s.store_id = '" . $store_id . "') WHERE i.status = '1'"
			)
		);
	}
}
