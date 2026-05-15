<?php

class ControllerExtensionModuleXmlModule extends Controller {
	private $error = array();
	private $updated_cnt = 0;
	private $inserted_cnt = 0;
	private $language_id = 1; //current language

	public function index() {

		$this->load->language('extension/module/xml_module');
		$this->load->model('setting/setting');

		$this->document->setTitle($this->language->get('heading_title'));

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('module_xml_module', $this->request->post);
			$this->session->data['success'] = $this->language->get('text_success');
			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
		}

		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/module/xml_module', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['action'] = $this->url->link('extension/module/xml_module', 'user_token=' . $this->session->data['user_token'], true);

		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

		if (isset($this->request->post['module_xml_module_status'])) {
			$data['module_xml_module_status'] = $this->request->post['module_xml_module_status'];
		} else {
			$data['module_xml_module_status'] = $this->config->get('module_xml_module_status');
		}

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$data['xml_import_link'] = 'http://colby.si/xml/veleprodaja.xml';

		$data['import_xml'] = (HTTPS_SERVER . 'index.php?route=extension/module/xml_module/export_xml&token=' . $this->session->data['user_token']);

		$data['user_token'] = $this->session->data['user_token'];

		$data['export_xml'] = (HTTPS_SERVER . 'controller/export_xml/export.php');

		$this->response->setOutput($this->load->view('extension/module/xml_module', $data));
	}

	/**
	* Importing goods from XML url and save them do DB
	*/
	public function import_xml(){

		$this->load->language('extension/module/xml_module');

		$this->load->model('extension/module/xml_module');

		$this->load->model('catalog/attribute_group');

		$this->load->model('catalog/attribute');

		$this->load->model('catalog/manufacturer');

		$this->load->model('catalog/product');

		$json = array();
		$xml_url = "";

		// Check user has permission
		if (!$this->user->hasPermission('modify', 'extension/module/xml_module')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if ($_POST["xml_url"]){
			$xml_url = $_POST["xml_url"];
			$xml = simplexml_load_file($xml_url) or die("feed not loading");
			//$this->parseCategories($xml);
			$this->parseProducts($xml);
		}

		if (!$json) {
			$json['success'] = $this->language->get('text_import_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));

		$this->session->data['success'] = $this->language->get('text_success');
	}

	/**
	* Parsing XML and adding products to DB
	* @param SimpleXMLElement Object
	*/
	private function parseProducts($xml){
		$catalogue = $xml->podjetje->izdelek;

		print_r($catalogue);

		break;
		$data = array();

		/*foreach ($catalogue as $offer) {
			
			foreach ($offer as $product) {

				$isMainImageSet = false;
				$data['product_image'] = array();

				foreach ($product->attributes() as $key => $value) {
					if ($key == 'id') $data['sku'] = $value;
					elseif ($key == 'available') $data["stock_status_id"] = $value == true ? 7 : 5;
					elseif ($key == 'instock') $data["quantity"] = $value;
				}

				$data['price'] = $product->price;
				$data['currencyId'] = $product->currencyId;
				$data['product_category'] = array( $product->categoryId );

				foreach ($product->picture as $image) {
					if(!$isMainImageSet) { //main image
						$data['image'] = $image;
						$isMainImageSet = true;
					}else{ //additional images
						array_push($data['product_image'], $image);
					}
				}

				$data['shipping'] = $product->delivery == true ? 1 : 0;
				$data['name'] = $product->name;
				$data['vendor'] = $product->vendor;
				$data['vendorCode'] = $product->vendorCode;
				$data['product_description'] = array(
					$this->language_id => 
					array(
						'language_id' => $this->language_id,
						'name' => $product->name,
						'description' => $product->description,
						'tag' => '',
						'meta_title' => $product->name,
						'meta_description' => $product->description,
						'meta_keyword' => ''
					)
				);
				$data['model'] = $product->model;

				$manufacturers = $this->model_extension_module_xml_module->getIdManufacturerByName($product->vendor);
				if( !empty($manufacturers) ) $data['manufacturer_id'] = $manufacturers['manufacturer_id'];
				else {
					$data['manufacturer_id'] = $this->model_catalog_manufacturer->addManufacturer(
						array(
							'name' => $product->vendor,
							'sort_order' => '0'
						));
				}

				
				

				$data['upc'] = '';
				$data['ean'] = '';
				$data['jan'] = '';
				$data['isbn'] = '';
				$data['mpn'] = '';
				$data['location'] = '';
				$data['minimum'] = '1';
				$data['subtract'] = '';
				$data['date_available'] = '';
				$data['points'] = '';
				$data['weight'] = '';
				$data['weight_class_id'] = '';
				$data['length'] = '';
				$data['width'] = '';
				$data['height'] = '';
				$data['length_class_id'] = '';
				$data['status'] = 1;
				$data['tax_class_id'] = '';
				$data['sort_order'] = '';

				$data['product_layout'] = array('0' => '0');
				$data['product_store'] = array('0');

				$products = $this->model_extension_module_xml_module->getProductIdByModel($data['model']);
				$product_id = 0;
			if( !empty($products) ) {
					$product_id = $products['product_id'];
					$this->model_catalog_product->editProduct($product_id, $data);
				}else{
					$product_id = $this->model_catalog_product->addProduct($data);
				}
			} 
		}*/
	}

	
}
