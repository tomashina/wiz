<?php
class ModelExtensionBaselNewsletter extends Model {
	public function checkExist($data) {
	   $query=$this->db->query("SELECT * FROM " . DB_PREFIX . "newsletter where email='" . $this->db->escape($data['email']) . "'");
	   return $query->num_rows;
	}
	
	public function checkRegistered($data) {
	   $query=$this->db->query("SELECT * FROM " . DB_PREFIX . "customer where email='" . $this->db->escape($data['email']) . "'");
	   return $query->num_rows;
	}

	public function getRegisteredCustomer($data) {
	   $query=$this->db->query("SELECT * FROM " . DB_PREFIX . "customer where email='" . $this->db->escape($data['email']) . "' LIMIT 1");
	   return $query->row;
	}
	
	public function UpdateRegistered($data,$status) {
	   $query=$this->db->query("UPDATE  " . DB_PREFIX . "customer SET newsletter ='" . (int)$status . "' where email='" . $this->db->escape($data['email']) . "'");
	}
		
	public function subscribe($data) {
		   $this->db->query("INSERT INTO " . DB_PREFIX . "newsletter SET 
		   email='" . $this->db->escape($data['email']) . "'");
	}
	
	public function unsubscribe($data) {
		$this->db->query("DELETE FROM " . DB_PREFIX . "newsletter WHERE email='" . $this->db->escape($data['email']) . "'");
	}
   
}
