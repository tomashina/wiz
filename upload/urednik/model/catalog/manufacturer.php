<?php
class ModelCatalogManufacturer extends Model {
	public function addManufacturer($data) {
		$this->db->query("INSERT INTO " . DB_PREFIX . "manufacturer SET name = '" . $this->db->escape($data['name']) . "', sort_order = '" . (int)$data['sort_order'] . "'");

		$manufacturer_id = $this->db->getLastId();

		if (isset($data['image'])) {
			$this->db->query("UPDATE " . DB_PREFIX . "manufacturer SET image = '" . $this->db->escape($data['image']) . "' WHERE manufacturer_id = '" . (int)$manufacturer_id . "'");
		}

		if (isset($data['manufacturer_store'])) {
			foreach ($data['manufacturer_store'] as $store_id) {
				$this->db->query("INSERT INTO " . DB_PREFIX . "manufacturer_to_store SET manufacturer_id = '" . (int)$manufacturer_id . "', store_id = '" . (int)$store_id . "'");
			}
		}
				
		// SEO URL
		if (isset($data['manufacturer_seo_url'])) {
			foreach ($data['manufacturer_seo_url'] as $store_id => $language) {
				foreach ($language as $language_id => $keyword) {
					if (!empty($keyword)) {
						$this->db->query("INSERT INTO " . DB_PREFIX . "seo_url SET store_id = '" . (int)$store_id . "', language_id = '" . (int)$language_id . "', query = 'manufacturer_id=" . (int)$manufacturer_id . "', keyword = '" . $this->db->escape($keyword) . "'");
					}
				}
			}
		}

		$this->saveManufacturerDescriptions($manufacturer_id, $data);
		
		$this->cache->delete('manufacturer');

		return $manufacturer_id;
	}

	public function editManufacturer($manufacturer_id, $data) {
		$this->db->query("UPDATE " . DB_PREFIX . "manufacturer SET name = '" . $this->db->escape($data['name']) . "', sort_order = '" . (int)$data['sort_order'] . "' WHERE manufacturer_id = '" . (int)$manufacturer_id . "'");

		if (isset($data['image'])) {
			$this->db->query("UPDATE " . DB_PREFIX . "manufacturer SET image = '" . $this->db->escape($data['image']) . "' WHERE manufacturer_id = '" . (int)$manufacturer_id . "'");
		}

		$this->db->query("DELETE FROM " . DB_PREFIX . "manufacturer_to_store WHERE manufacturer_id = '" . (int)$manufacturer_id . "'");

		if (isset($data['manufacturer_store'])) {
			foreach ($data['manufacturer_store'] as $store_id) {
				$this->db->query("INSERT INTO " . DB_PREFIX . "manufacturer_to_store SET manufacturer_id = '" . (int)$manufacturer_id . "', store_id = '" . (int)$store_id . "'");
			}
		}

		$this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url` WHERE query = 'manufacturer_id=" . (int)$manufacturer_id . "'");

		if (isset($data['manufacturer_seo_url'])) {
			foreach ($data['manufacturer_seo_url'] as $store_id => $language) {
				foreach ($language as $language_id => $keyword) {
					if (!empty($keyword)) {
						$this->db->query("INSERT INTO `" . DB_PREFIX . "seo_url` SET store_id = '" . (int)$store_id . "', language_id = '" . (int)$language_id . "', query = 'manufacturer_id=" . (int)$manufacturer_id . "', keyword = '" . $this->db->escape($keyword) . "'");
					}
				}
			}
		}

		$this->saveManufacturerDescriptions($manufacturer_id, $data);

		$this->cache->delete('manufacturer');
	}

	public function deleteManufacturer($manufacturer_id) {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "manufacturer` WHERE manufacturer_id = '" . (int)$manufacturer_id . "'");
		$this->db->query("DELETE FROM `" . DB_PREFIX . "manufacturer_to_store` WHERE manufacturer_id = '" . (int)$manufacturer_id . "'");
		$this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url` WHERE query = 'manufacturer_id=" . (int)$manufacturer_id . "'");
		$this->ensureManufacturerDescriptionTable();
		$this->db->query("DELETE FROM `" . DB_PREFIX . "manufacturer_description` WHERE manufacturer_id = '" . (int)$manufacturer_id . "'");

		$this->cache->delete('manufacturer');
	}

	public function getManufacturer($manufacturer_id) {
		$query = $this->db->query("SELECT DISTINCT * FROM " . DB_PREFIX . "manufacturer WHERE manufacturer_id = '" . (int)$manufacturer_id . "'");

		return $query->row;
	}

	public function getManufacturers($data = array()) {
		$sql = "SELECT * FROM " . DB_PREFIX . "manufacturer";

		if (!empty($data['filter_name'])) {
			$sql .= " WHERE name LIKE '" . $this->db->escape($data['filter_name']) . "%'";
		}

		$sort_data = array(
			'name',
			'sort_order'
		);

		if (isset($data['sort']) && in_array($data['sort'], $sort_data)) {
			$sql .= " ORDER BY " . $data['sort'];
		} else {
			$sql .= " ORDER BY name";
		}

		if (isset($data['order']) && ($data['order'] == 'DESC')) {
			$sql .= " DESC";
		} else {
			$sql .= " ASC";
		}

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

	public function getManufacturerStores($manufacturer_id) {
		$manufacturer_store_data = array();

		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "manufacturer_to_store WHERE manufacturer_id = '" . (int)$manufacturer_id . "'");

		foreach ($query->rows as $result) {
			$manufacturer_store_data[] = $result['store_id'];
		}

		return $manufacturer_store_data;
	}
	
	public function getManufacturerSeoUrls($manufacturer_id) {
		$manufacturer_seo_url_data = array();
		
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "seo_url WHERE query = 'manufacturer_id=" . (int)$manufacturer_id . "'");

		foreach ($query->rows as $result) {
			$manufacturer_seo_url_data[$result['store_id']][$result['language_id']] = $result['keyword'];
		}

		return $manufacturer_seo_url_data;
	}

	public function getManufacturerDescriptions($manufacturer_id) {
		$this->ensureManufacturerDescriptionTable();

		$manufacturer_description_data = array();

		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "manufacturer_description` WHERE manufacturer_id = '" . (int)$manufacturer_id . "'");

		foreach ($query->rows as $result) {
			$manufacturer_description_data[$result['language_id']] = array(
				'name'             => $result['name'],
				'description'      => $result['description'],
				'meta_title'       => isset($result['meta_title']) ? $result['meta_title'] : '',
				'meta_description' => isset($result['meta_description']) ? $result['meta_description'] : '',
				'meta_keyword'     => isset($result['meta_keyword']) ? $result['meta_keyword'] : ''
			);
		}

		return $manufacturer_description_data;
	}
	
	public function getTotalManufacturers() {
		$query = $this->db->query("SELECT COUNT(*) AS total FROM " . DB_PREFIX . "manufacturer");

		return $query->row['total'];
	}

	private function saveManufacturerDescriptions($manufacturer_id, $data) {
		$this->ensureManufacturerDescriptionTable();

		$languages = $this->db->query("SELECT language_id FROM `" . DB_PREFIX . "language`")->rows;

		foreach ($languages as $language) {
			$language_id = (int)$language['language_id'];
			$description = '';

			if (isset($data['manufacturer_description'][$language_id]['description'])) {
				$description = $data['manufacturer_description'][$language_id]['description'];
			}

			$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "manufacturer_description` WHERE manufacturer_id = '" . (int)$manufacturer_id . "' AND language_id = '" . $language_id . "' LIMIT 1");

			if ($query->num_rows) {
				$this->db->query("UPDATE `" . DB_PREFIX . "manufacturer_description` SET name = '" . $this->db->escape($data['name']) . "', description = '" . $this->db->escape($description) . "' WHERE manufacturer_id = '" . (int)$manufacturer_id . "' AND language_id = '" . $language_id . "'");
			} else {
				$this->db->query("INSERT INTO `" . DB_PREFIX . "manufacturer_description` SET manufacturer_id = '" . (int)$manufacturer_id . "', language_id = '" . $language_id . "', name = '" . $this->db->escape($data['name']) . "', description = '" . $this->db->escape($description) . "', meta_title = '', meta_description = '', meta_keyword = '', h1 = '', h2 = '', image_alt = '', image_title = ''");
			}
		}
	}

	private function ensureManufacturerDescriptionTable() {
		static $ready = false;

		if ($ready) {
			return;
		}

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "manufacturer_description` (
			`manufacturer_id` int(11) NOT NULL,
			`language_id` int(11) NOT NULL,
			`name` varchar(100) NOT NULL,
			`description` text NOT NULL,
			`meta_title` varchar(300) NOT NULL,
			`meta_description` varchar(300) NOT NULL,
			`meta_keyword` varchar(300) NOT NULL,
			`h1` varchar(300) NOT NULL,
			`h2` varchar(300) NOT NULL,
			`image_alt` varchar(300) NOT NULL,
			`image_title` varchar(300) NOT NULL,
			PRIMARY KEY (`manufacturer_id`, `language_id`)
		)");

		$columns = array(
			'name'             => "varchar(100) NOT NULL AFTER `language_id`",
			'description'      => "text NOT NULL AFTER `name`",
			'meta_title'       => "varchar(300) NOT NULL AFTER `description`",
			'meta_description' => "varchar(300) NOT NULL AFTER `meta_title`",
			'meta_keyword'     => "varchar(300) NOT NULL AFTER `meta_description`",
			'h1'               => "varchar(300) NOT NULL AFTER `meta_keyword`",
			'h2'               => "varchar(300) NOT NULL AFTER `h1`",
			'image_alt'        => "varchar(300) NOT NULL AFTER `h2`",
			'image_title'      => "varchar(300) NOT NULL AFTER `image_alt`"
		);

		foreach ($columns as $column => $definition) {
			$check = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "manufacturer_description` LIKE '" . $this->db->escape($column) . "'");

			if (!$check->num_rows) {
				$this->db->query("ALTER TABLE `" . DB_PREFIX . "manufacturer_description` ADD `" . $column . "` " . $definition);
			}
		}

		$ready = true;
	}
}
