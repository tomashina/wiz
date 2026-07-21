<?php
class ModelExtensionFeedGoogleBase extends Model {
	public function install() {
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "google_base_category` (
				`google_base_category_id` INT(11) NOT NULL AUTO_INCREMENT,
				`name` varchar(255) NOT NULL,
				PRIMARY KEY (`google_base_category_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		");

		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "google_base_category_to_category` (
				`google_base_category_id` INT(11) NOT NULL,
				`category_id` INT(11) NOT NULL,
				PRIMARY KEY (`google_base_category_id`, `category_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		");

		$this->seedWizMappings();
		$this->seedMissingProductSeoUrls();
	}

	public function setup() {
		$this->install();
	}

	public function uninstall() {
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "google_base_category`");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "google_base_category_to_category`");
	}

    public function import($string) {
        $this->db->query("DELETE FROM " . DB_PREFIX . "google_base_category");

        $lines = explode("\n", $string);

        foreach ($lines as $line) {
			if (substr($line, 0, 1) != '#') {
	            $part = explode(' - ', $line, 2);

	            if (isset($part[1])) {
	                $this->db->query("INSERT INTO " . DB_PREFIX . "google_base_category SET google_base_category_id = '" . (int)$part[0] . "', name = '" . $this->db->escape($part[1]) . "'");
	            }
			}
        }
    }

    public function getGoogleBaseCategories($data = array()) {
        $sql = "SELECT * FROM `" . DB_PREFIX . "google_base_category` WHERE name LIKE '%" . $this->db->escape($data['filter_name']) . "%' ORDER BY name ASC";

		if (isset($data['start']) || isset($data['limit'])) {
			if ($data['start'] < 0) {
				$data['start'] = 0;
			}

			if ($data['limit'] < 1) {
				$data['limit'] = 20;
			}

			$sql .= " LIMIT " . (int)$data['start'] . "," . (int)$data['limit'];
		}

		$query = $this->db->query($sql);

		return $query->rows;
    }

	public function addCategory($data) {
		$this->db->query("DELETE FROM " . DB_PREFIX . "google_base_category_to_category WHERE category_id = '" . (int)$data['category_id'] . "'");

		$this->db->query("INSERT INTO " . DB_PREFIX . "google_base_category_to_category SET google_base_category_id = '" . (int)$data['google_base_category_id'] . "', category_id = '" . (int)$data['category_id'] . "'");
	}

	public function deleteCategory($category_id) {
		$this->db->query("DELETE FROM " . DB_PREFIX . "google_base_category_to_category WHERE category_id = '" . (int)$category_id . "'");
	}

    public function getCategories($data = array()) {
        $sql = "SELECT google_base_category_id, (SELECT name FROM `" . DB_PREFIX . "google_base_category` gbc WHERE gbc.google_base_category_id = gbc2c.google_base_category_id) AS google_base_category, category_id, (SELECT name FROM `" . DB_PREFIX . "category_description` cd WHERE cd.category_id = gbc2c.category_id AND cd.language_id = '" . (int)$this->config->get('config_language_id') . "') AS category FROM `" . DB_PREFIX . "google_base_category_to_category` gbc2c ORDER BY google_base_category ASC";

		if (isset($data['start']) || isset($data['limit'])) {
			if ($data['start'] < 0) {
				$data['start'] = 0;
			}

			if ($data['limit'] < 1) {
				$data['limit'] = 20;
			}

			$sql .= " LIMIT " . (int)$data['start'] . "," . (int)$data['limit'];
		}

		$query = $this->db->query($sql);

		return $query->rows;
    }

	public function getTotalCategories() {
		$query = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "google_base_category_to_category`");

		return $query->row['total'];
    }

	private function seedWizMappings() {
		if ($this->getTotalCategories()) {
			return;
		}

		$google_categories = array(
			100    => 'Luggage & Bags > Backpacks',
			222    => 'Electronics',
			301    => 'Electronics > Electronics Accessories > Computer Components > Input Devices > Game Controllers',
			303    => 'Electronics > Electronics Accessories > Computer Components > Input Devices > Keyboards',
			304    => 'Electronics > Electronics Accessories > Computer Components > Input Devices > Mice & Trackballs',
			311    => 'Electronics > Electronics Accessories > Computer Components > USB & FireWire Hubs',
			395    => 'Electronics > Video > Video Accessories > Projector Accessories > Projection Screens',
			436    => 'Furniture',
			1279   => 'Software > Video Game Software',
			1867   => 'Electronics > Electronics Accessories > Cables > Audio & Video Cables',
			2027   => 'Electronics > Video > Video Accessories',
			2082   => 'Electronics > Electronics Accessories',
			3328   => 'Electronics > Electronics Accessories > Cable Management',
			4191   => 'Furniture > Office Furniture > Desks',
			4458   => 'Electronics > Video > Video Accessories > Television Parts & Accessories > TV & Monitor Mounts',
			5257   => 'Electronics > Video > Video Accessories > Projector Accessories > Projector Mounts',
			5489   => 'Electronics > Electronics Accessories > Computer Accessories > Computer Risers & Stands',
			6521   => 'Office Supplies > Office & Chair Mats > Chair Mats',
			6800   => 'Furniture > Chairs > Gaming Chairs',
			8008   => 'Electronics > Electronics Accessories > Computer Components > Input Device Accessories > Game Controller Accessories',
			8164   => 'Electronics > Electronics Accessories > Audio & Video Splitters & Switches > HDMI Splitters & Switches',
			8049   => 'Electronics > Audio > Audio Accessories > Speaker Accessories > Speaker Stands & Mounts',
			499956 => 'Electronics > Electronics Accessories > Computer Accessories > Tablet Computer Docks & Stands',
			500040 => 'Electronics > Electronics Accessories > Computer Accessories > Computer Accessory Sets',
			503766 => 'Furniture > Office Furniture Accessories > Desk Parts & Accessories',
			505767 => 'Electronics > Electronics Accessories > Computer Components > Storage Devices > Hard Drive Accessories > Hard Drive Enclosures & Mounts',
			505797 => 'Electronics > Audio > Audio Accessories > Headphone & Headset Accessories',
			543626 => 'Electronics > Audio > Audio Components > Headphones & Headsets > Headphones',
			543627 => 'Electronics > Audio > Audio Components > Headphones & Headsets > Headsets'
		);

		foreach ($google_categories as $google_category_id => $name) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "google_base_category` SET google_base_category_id = '" . (int)$google_category_id . "', name = '" . $this->db->escape($name) . "' ON DUPLICATE KEY UPDATE name = VALUES(name)");
		}

		$mappings = array(
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

		foreach ($mappings as $category_id => $google_category_id) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "google_base_category_to_category` SET google_base_category_id = '" . (int)$google_category_id . "', category_id = '" . (int)$category_id . "'");
		}
	}

	private function seedMissingProductSeoUrls() {
		$language_query = $this->db->query("SELECT language_id FROM `" . DB_PREFIX . "language` WHERE code = 'hr-hr' AND status = '1' LIMIT 1");

		if (!$language_query->num_rows) {
			return;
		}

		$language_id = (int)$language_query->row['language_id'];
		$sql = "SELECT p.product_id, p.model, pd.name FROM `" . DB_PREFIX . "product` p JOIN `" . DB_PREFIX . "product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = '" . $language_id . "') JOIN `" . DB_PREFIX . "product_to_store` p2s ON (p2s.product_id = p.product_id AND p2s.store_id = '0') LEFT JOIN `" . DB_PREFIX . "seo_url` su ON (su.query = CONCAT('product_id=', p.product_id) AND su.store_id = '0' AND su.language_id = '" . $language_id . "') WHERE p.status = '1' AND p.quantity > '0' AND p.date_available <= NOW() AND su.seo_url_id IS NULL";
		$query = $this->db->query($sql);

		foreach ($query->rows as $product) {
			$keyword = $this->slug($product['name'] . ' ' . $product['model']);

			if ($keyword === '') {
				$keyword = 'proizvod-' . (int)$product['product_id'];
			}

			$keyword_query = $this->db->query("SELECT seo_url_id FROM `" . DB_PREFIX . "seo_url` WHERE keyword = '" . $this->db->escape($keyword) . "' LIMIT 1");

			if ($keyword_query->num_rows) {
				$keyword .= '-' . (int)$product['product_id'];
			}

			$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "seo_url` SET store_id = '0', language_id = '" . $language_id . "', query = 'product_id=" . (int)$product['product_id'] . "', keyword = '" . $this->db->escape($keyword) . "'");
		}
	}

	private function slug($value) {
		$value = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$value = utf8_strtolower($value);
		$value = strtr($value, array('č' => 'c', 'ć' => 'c', 'đ' => 'd', 'š' => 's', 'ž' => 'z'));
		$value = preg_replace('/[^a-z0-9]+/u', '-', $value);

		return trim($value, '-');
	}
}
