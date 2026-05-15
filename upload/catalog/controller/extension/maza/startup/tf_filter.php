<?php
class ControllerExtensionMazaStartupTfFilter extends Controller {
	public function index() {
                if ($this->registry->has('tf_filter_bootstrapped')) {
                    return;
                }

                $this->registry->set('tf_filter_bootstrapped', true);

                if (!$this->registry->has('tf_db')) {
                    $this->registry->set('tf_db', new maza\DB($this->config->get('db_engine'), $this->config->get('db_hostname'), $this->config->get('db_username'), $this->config->get('db_password'), $this->config->get('db_database'), $this->config->get('db_port')));
                }
                        
                // Create temporary table for applicable tax on user.
                $tax_class = $this->getTaxRates();
                
                $sql_values['p'] = $sql_values['f'] = array();
                
                foreach($tax_class as $tax_class_id => $tax_rates){
                    foreach($tax_rates as $tax_rate){
                        if($tax_rate['type'] == 'P'){
                            $sql_values['p'][] = "('" . (int)$tax_class_id . "', '" . (int)$tax_rate['tax_rate_id'] . "', '" . (float)$tax_rate['rate'] . "', '" . (int)$tax_rate['priority'] . "')";
                        } else {
                            $sql_values['f'][] = "('" . (int)$tax_class_id . "', '" . (int)$tax_rate['tax_rate_id'] . "', '" . (float)$tax_rate['rate'] . "', '" . (int)$tax_rate['priority'] . "')";
                        }
                    }
                }
                
                $this->db->query(
                "CREATE TEMPORARY TABLE " . DB_PREFIX . "tf_user_ptax_rates (
                     `tax_class_id` int(11) NOT NULL,
                     `tax_rate_id` int(11) NOT NULL,
                     `rate` decimal(15,4) NOT NULL DEFAULT '0.0000',
                     `priority` int(5) NOT NULL DEFAULT '1'
                )");
                
                $this->db->query(
                "CREATE TEMPORARY TABLE " . DB_PREFIX . "tf_user_ftax_rates (
                     `tax_class_id` int(11) NOT NULL,
                     `tax_rate_id` int(11) NOT NULL,
                     `rate` decimal(15,4) NOT NULL DEFAULT '0.0000',
                     `priority` int(5) NOT NULL DEFAULT '1'
                )");
                
                if($sql_values['p']){
                    $this->db->query("INSERT INTO " . DB_PREFIX . "tf_user_ptax_rates VALUES " . implode(',', $sql_values['p']));
                }
                
                if($sql_values['f']){
                    $this->db->query("INSERT INTO " . DB_PREFIX . "tf_user_ftax_rates VALUES " . implode(',', $sql_values['f']));
                }
	}

        private function getTaxRates(): array {
                if (method_exists($this->tax, 'tf_getTaxRates')) {
                        return $this->tax->tf_getTaxRates();
                }

                $tax_class = array();

                $shipping_country_id = isset($this->session->data['shipping_address']['country_id']) ? (int)$this->session->data['shipping_address']['country_id'] : (int)$this->config->get('config_country_id');
                $shipping_zone_id = isset($this->session->data['shipping_address']['zone_id']) ? (int)$this->session->data['shipping_address']['zone_id'] : (int)$this->config->get('config_zone_id');
                $payment_country_id = isset($this->session->data['payment_address']['country_id']) ? (int)$this->session->data['payment_address']['country_id'] : (int)$this->config->get('config_country_id');
                $payment_zone_id = isset($this->session->data['payment_address']['zone_id']) ? (int)$this->session->data['payment_address']['zone_id'] : (int)$this->config->get('config_zone_id');

                $addresses = array(
                        'shipping' => array($shipping_country_id, $shipping_zone_id),
                        'payment' => array($payment_country_id, $payment_zone_id),
                        'store' => array((int)$this->config->get('config_country_id'), (int)$this->config->get('config_zone_id')),
                );

                foreach ($addresses as $based => $address) {
                        $query = $this->db->query("SELECT tr1.tax_class_id, tr2.tax_rate_id, tr2.name, tr2.rate, tr2.type, tr1.priority FROM " . DB_PREFIX . "tax_rule tr1 LEFT JOIN " . DB_PREFIX . "tax_rate tr2 ON (tr1.tax_rate_id = tr2.tax_rate_id) INNER JOIN " . DB_PREFIX . "tax_rate_to_customer_group tr2cg ON (tr2.tax_rate_id = tr2cg.tax_rate_id) LEFT JOIN " . DB_PREFIX . "zone_to_geo_zone z2gz ON (tr2.geo_zone_id = z2gz.geo_zone_id) WHERE tr1.based = '" . $this->db->escape($based) . "' AND tr2cg.customer_group_id = '" . (int)$this->config->get('config_customer_group_id') . "' AND z2gz.country_id = '" . (int)$address[0] . "' AND (z2gz.zone_id = '0' OR z2gz.zone_id = '" . (int)$address[1] . "') ORDER BY tr1.priority ASC");

                        foreach ($query->rows as $row) {
                                $tax_class[$row['tax_class_id']][$row['tax_rate_id']] = array(
                                        'tax_rate_id' => $row['tax_rate_id'],
                                        'name'        => $row['name'],
                                        'rate'        => $row['rate'],
                                        'type'        => $row['type'],
                                        'priority'    => $row['priority'],
                                );
                        }
                }

                return $tax_class;
        }
}
