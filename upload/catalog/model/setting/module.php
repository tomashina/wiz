<?php
class ModelSettingModule extends Model {
	// Reuse an enabled storefront filter when a product listing has no assigned instance.
	public function getListingFilter() {
		$rows = $this->db->query("SELECT setting FROM " . DB_PREFIX . "module WHERE code='tf_filter' ORDER BY module_id")->rows;
		foreach ($rows as $row) {
			$settings = json_decode($row['setting'], true);
			if (!empty($settings['status'])) { return $settings; }
		}
		return array();
	}

	public function getModule($module_id) {
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "module WHERE module_id = '" . (int)$module_id . "'");
		
		if ($query->row) {
			return json_decode($query->row['setting'], true);
		} else {
			return array();	
		}
	}		
}