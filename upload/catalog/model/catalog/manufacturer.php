<?php
class ModelCatalogManufacturer extends Model {
	public function getManufacturer($manufacturer_id) {
		$description_sql = "'' AS description";
		$description_join = '';
		$has_description_table = $this->hasManufacturerDescriptionTable();
		$has_brand_description = $this->hasManufacturerColumn('brand_description');

		if ($has_description_table) {
			$description_join = " LEFT JOIN " . DB_PREFIX . "manufacturer_description md ON (m.manufacturer_id = md.manufacturer_id AND md.language_id = '" . (int)$this->config->get('config_language_id') . "')";
			$description_sql = "COALESCE(md.description, '') AS description";
		}

		if ($has_description_table && $has_brand_description) {
			$description_sql = "COALESCE(NULLIF(md.description, ''), m.brand_description, '') AS description";
		} elseif ($has_brand_description) {
			$description_sql = "COALESCE(m.brand_description, '') AS description";
		}

		$query = $this->db->query("SELECT m.*, m2s.*, " . $description_sql . " FROM " . DB_PREFIX . "manufacturer m LEFT JOIN " . DB_PREFIX . "manufacturer_to_store m2s ON (m.manufacturer_id = m2s.manufacturer_id)" . $description_join . " WHERE m.manufacturer_id = '" . (int)$manufacturer_id . "' AND m2s.store_id = '" . (int)$this->config->get('config_store_id') . "'");

		return $query->row;
	}

	public function getManufacturers($data = array()) {
		if ($data) {
			$sql = "SELECT * FROM " . DB_PREFIX . "manufacturer m LEFT JOIN " . DB_PREFIX . "manufacturer_to_store m2s ON (m.manufacturer_id = m2s.manufacturer_id) WHERE m2s.store_id = '" . (int)$this->config->get('config_store_id') . "'";

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
		} else {
			$manufacturer_data = $this->cache->get('manufacturer.' . (int)$this->config->get('config_store_id'));

			if (!$manufacturer_data) {
				$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "manufacturer m LEFT JOIN " . DB_PREFIX . "manufacturer_to_store m2s ON (m.manufacturer_id = m2s.manufacturer_id) WHERE m2s.store_id = '" . (int)$this->config->get('config_store_id') . "' ORDER BY name");

				$manufacturer_data = $query->rows;

				$this->cache->set('manufacturer.' . (int)$this->config->get('config_store_id'), $manufacturer_data);
			}

			return $manufacturer_data;
		}
	}

	private function hasManufacturerDescriptionTable() {
		static $exists = null;

		if ($exists === null) {
			$query = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . "manufacturer_description") . "'");
			$exists = (bool)$query->num_rows;
		}

		return $exists;
	}

	private function hasManufacturerColumn($column) {
		static $columns = array();

		if (!isset($columns[$column])) {
			$query = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "manufacturer` LIKE '" . $this->db->escape($column) . "'");
			$columns[$column] = (bool)$query->num_rows;
		}

		return $columns[$column];
	}
}
