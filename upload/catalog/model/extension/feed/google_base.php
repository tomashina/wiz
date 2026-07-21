<?php
class ModelExtensionFeedGoogleBase extends Model {
	public function getLanguageId($code) {
		$query = $this->db->query("SELECT language_id FROM `" . DB_PREFIX . "language` WHERE code = '" . $this->db->escape($code) . "' AND status = '1' LIMIT 1");

		if ($query->num_rows) {
			return (int)$query->row['language_id'];
		}

		return (int)$this->config->get('config_language_id');
	}

	public function getProducts($language_id) {
		$sql = "SELECT p.product_id, p.model, p.sku, p.upc, p.ean, p.jan, p.isbn, p.mpn, p.quantity, p.image, p.price, p.tax_class_id, pd.name, pd.description, m.name AS manufacturer, (SELECT price FROM " . DB_PREFIX . "product_discount pd2 WHERE pd2.product_id = p.product_id AND pd2.customer_group_id = '" . (int)$this->config->get('config_customer_group_id') . "' AND pd2.quantity = '1' AND (pd2.date_start = '0000-00-00' OR pd2.date_start <= NOW()) AND (pd2.date_end = '0000-00-00' OR pd2.date_end >= NOW()) ORDER BY pd2.priority ASC, pd2.price ASC LIMIT 1) AS discount, (SELECT price FROM " . DB_PREFIX . "product_special ps WHERE ps.product_id = p.product_id AND ps.customer_group_id = '" . (int)$this->config->get('config_customer_group_id') . "' AND (ps.date_start = '0000-00-00' OR ps.date_start <= NOW()) AND (ps.date_end = '0000-00-00' OR ps.date_end >= NOW()) ORDER BY ps.priority ASC, ps.price ASC LIMIT 1) AS special FROM `" . DB_PREFIX . "product` p JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . (int)$language_id . "') JOIN `" . DB_PREFIX . "product_to_store` p2s ON (p2s.product_id = p.product_id AND p2s.store_id = '" . (int)$this->config->get('config_store_id') . "') LEFT JOIN `" . DB_PREFIX . "manufacturer` m ON (m.manufacturer_id = p.manufacturer_id) WHERE p.status = '1' AND p.quantity > '0' AND p.date_available <= NOW() ORDER BY p.product_id ASC";

		$query = $this->db->query($sql);

		return $query->rows;
	}

	public function getProductGoogleCategories() {
		// Products without a category, or in a mixed OpenCart category, need a
		// precise override so they stay complete and correctly classified.
		$categories = array(
			2940  => 1867,
			2941  => 1867,
			3190  => 8164,
			5449  => 8164,
			7020  => 3328,
			8266  => 6800,
			8742  => 505767,
			9177  => 6800,
			9127  => 8164,
			10039 => 6800,
			10150 => 6521,
			10219 => 503766,
			10243 => 6800,
			10206 => 4191,
			10370 => 4191,
			10436 => 6800,
			11759 => 505797,
			11772 => 311,
			11773 => 311,
			11774 => 311,
			11783 => 6521,
			11796 => 6800,
			11811 => 4191,
			11825 => 1867,
			11827 => 6800,
			11828 => 6800,
			11829 => 6800,
			11830 => 6800,
			11831 => 6800,
			11843 => 4458,
			11846 => 4191,
			11854 => 6800,
			11859 => 6800,
			11860 => 6800,
			11873 => 6800,
			11875 => 8008,
			11876 => 543626,
			11877 => 1279
		);

		$sql = "SELECT p2c.product_id, gbc2c.google_base_category_id, MAX(cp.level) AS category_depth FROM `" . DB_PREFIX . "product_to_category` p2c JOIN `" . DB_PREFIX . "category_path` cp ON (cp.category_id = p2c.category_id) JOIN `" . DB_PREFIX . "google_base_category_to_category` gbc2c ON (gbc2c.category_id = cp.path_id) GROUP BY p2c.product_id, gbc2c.google_base_category_id ORDER BY p2c.product_id ASC, category_depth DESC, gbc2c.google_base_category_id DESC";

		$query = $this->db->query($sql);

		foreach ($query->rows as $row) {
			if (!isset($categories[$row['product_id']])) {
				$categories[$row['product_id']] = (int)$row['google_base_category_id'];
			}
		}

		$default_mappings = array(
			2871 => 222,
			1610 => 2082,
			2449 => 4458,
			2012 => 395,
			1620 => 2027,
			1623 => 499956,
			1612 => 4458,
			1613 => 2027,
			2436 => 2082,
			1611 => 2082,
			1614 => 4458,
			1616 => 8049,
			1619 => 5257,
			1618 => 4458,
			1622 => 4458,
			1621 => 4458,
			3286 => 8049,
			2831 => 8049,
			2830 => 5257,
			2437 => 4458,
			2448 => 4458,
			2443 => 4458,
			2442 => 4458,
			2731 => 4458,
			2559 => 4458,
			2558 => 4458,
			2446 => 4458,
			2560 => 4458,
			2445 => 4458,
			2605 => 4458,
			2873 => 304,
			3187 => 100,
			3287 => 500040,
			2913 => 543627,
			2781 => 436,
			2872 => 303,
			3297 => 301,
			1381 => 5489
		);

		$category_ids = implode(',', array_map('intval', array_keys($default_mappings)));
		$fallback_query = $this->db->query("SELECT p2c.product_id, cp.path_id AS category_id, cp.level AS category_depth FROM `" . DB_PREFIX . "product_to_category` p2c JOIN `" . DB_PREFIX . "category_path` cp ON (cp.category_id = p2c.category_id) WHERE cp.path_id IN (" . $category_ids . ") ORDER BY p2c.product_id ASC, category_depth DESC, cp.path_id DESC");

		foreach ($fallback_query->rows as $row) {
			if (!isset($categories[$row['product_id']]) && isset($default_mappings[$row['category_id']])) {
				$categories[$row['product_id']] = $default_mappings[$row['category_id']];
			}
		}

		return $categories;
	}

	public function getProductTypes($language_id) {
		$sql = "SELECT p2c.product_id, p2c.category_id, COUNT(cp.path_id) AS category_depth, GROUP_CONCAT(cd.name ORDER BY cp.level ASC SEPARATOR ' &gt; ') AS product_type FROM `" . DB_PREFIX . "product_to_category` p2c JOIN `" . DB_PREFIX . "category_path` cp ON (cp.category_id = p2c.category_id) JOIN `" . DB_PREFIX . "category_description` cd ON (cd.category_id = cp.path_id AND cd.language_id = '" . (int)$language_id . "') GROUP BY p2c.product_id, p2c.category_id ORDER BY p2c.product_id ASC, category_depth DESC, p2c.category_id ASC";

		$query = $this->db->query($sql);
		$product_types = array();

		foreach ($query->rows as $row) {
			if (!isset($product_types[$row['product_id']])) {
				$product_types[$row['product_id']] = $row['product_type'];
			}
		}

		return $product_types;
	}

	public function getCategories() {
		$query = $this->db->query("SELECT google_base_category_id, (SELECT name FROM `" . DB_PREFIX . "google_base_category` gbc WHERE gbc.google_base_category_id = gbc2c.google_base_category_id) AS google_base_category, category_id, (SELECT name FROM `" . DB_PREFIX . "category_description` cd WHERE cd.category_id = gbc2c.category_id AND cd.language_id = '" . (int)$this->config->get('config_language_id') . "') AS category FROM `" . DB_PREFIX . "google_base_category_to_category` gbc2c ORDER BY google_base_category ASC");

		return $query->rows;
	}

	public function getTotalCategories() {
		$query = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "google_base_category_to_category`");

		return $query->row['total'];
	}
}
