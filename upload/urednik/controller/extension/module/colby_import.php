<?php
class ControllerExtensionModuleColbyImport extends Controller {
	private $route = 'extension/module/colby_import';
	private $feed_endpoint = 'https://cdn.colby.si/xml/colbyhr.xml';
	private $wiz_feed_endpoint = 'https://cdn.colby.si/xml/wiz.xml';

	public function index() {
		$this->load->language($this->route);
		$this->load->model('catalog/category');
		$this->load->model('extension/module/colby_import');
		$this->load->model('localisation/tax_class');
		$this->load->model('setting/setting');

		$this->document->setTitle($this->language->get('heading_title'));

		$settings = $this->getSettings();

		if (empty($settings['cron_token'])) {
			$settings['cron_token'] = token(32);
			$this->saveSettings($settings);
		}

		$filter_search = isset($this->request->get['filter_search']) ? trim($this->request->get['filter_search']) : '';
		$filter_supplier_category_id = isset($this->request->get['filter_supplier_category_id']) ? trim($this->request->get['filter_supplier_category_id']) : '';
		$filter_import_status = isset($this->request->get['filter_import_status']) ? trim($this->request->get['filter_import_status']) : '';
		$page = isset($this->request->get['page']) ? (int)$this->request->get['page'] : 1;
		$limit = 50;

		if ($page < 1) {
			$page = 1;
		}

		$force_refresh = !empty($this->request->get['refresh']);
		$products = array();
		$supplier_categories = array();
		$feed_error = '';

		try {
			$products = $this->normaliseProducts($this->fetchXml('products', $settings, $force_refresh));
			$supplier_categories = $this->buildCategoriesFromProducts($products);
		} catch (Exception $e) {
			$feed_error = $e->getMessage();
		}

		if (!$supplier_categories && $products) {
			$supplier_categories = $this->buildCategoriesFromProducts($products);
		}

		$local_categories = $this->model_catalog_category->getCategories(array(
			'sort'  => 'name',
			'order' => 'ASC'
		));

		$local_category_names = array();

		foreach ($local_categories as $category) {
			$local_category_names[(int)$category['category_id']] = html_entity_decode($category['name'], ENT_QUOTES, 'UTF-8');
		}

		$filtered_products = $this->filterProducts($products, $filter_search, $filter_supplier_category_id);

		if ($filter_import_status !== '') {
			$existing_for_filter = $this->model_extension_module_colby_import->getProductsByModels($this->pluckCodes($filtered_products));
			$tmp = array();

			foreach ($filtered_products as $product) {
				$exists = isset($existing_for_filter[$product['code']]);

				if (($filter_import_status === 'imported' && $exists) || ($filter_import_status === 'new' && !$exists)) {
					$tmp[] = $product;
				}
			}

			$filtered_products = $tmp;
		}

		$product_total = count($filtered_products);
		$page_products = array_slice($filtered_products, ($page - 1) * $limit, $limit);
		$existing_products = $this->model_extension_module_colby_import->getProductsByModels($this->pluckCodes($page_products));
		$category_map = !empty($settings['category_map']) && is_array($settings['category_map']) ? $settings['category_map'] : array();

		$data['products'] = array();

		foreach ($page_products as $product) {
			$existing = isset($existing_products[$product['code']]) ? $existing_products[$product['code']] : array();
			$mapped_category_id = !empty($category_map[$product['supplier_category_id']]) ? (int)$category_map[$product['supplier_category_id']] : 0;

			$data['products'][] = array(
				'id'                   => $product['id'],
				'code'                 => $product['code'],
				'barcode'              => $product['barcode'],
				'name'                 => $product['name'],
				'brand'                => $product['brand'],
				'quantity'             => $product['quantity'],
				'price_base'           => $this->formatPrice($product['prices']['price']),
				'price_ppc'            => $this->formatPrice($product['prices']['ppc']),
				'price_soa'            => $this->formatPrice($product['prices']['soa_price']),
				'supplier_category_id' => $product['supplier_category_id'],
				'supplier_category'    => $product['supplier_category_name'],
				'image'                => !empty($product['images'][0]['thumb']) ? $product['images'][0]['thumb'] : (!empty($product['images'][0]['url']) ? $product['images'][0]['url'] : ''),
				'is_imported'          => !empty($existing),
				'product_id'           => !empty($existing['product_id']) ? (int)$existing['product_id'] : 0,
				'local_quantity'       => isset($existing['quantity']) ? (int)$existing['quantity'] : '',
				'local_price'          => isset($existing['price']) ? $this->formatPrice($existing['price']) : '',
				'local_name'           => !empty($existing['name']) ? $existing['name'] : '',
				'mapped_category_id'   => $mapped_category_id,
				'mapped_category'      => ($mapped_category_id && isset($local_category_names[$mapped_category_id])) ? $local_category_names[$mapped_category_id] : '',
				'edit'                 => !empty($existing['product_id']) ? $this->url->link('catalog/product/edit', 'user_token=' . $this->session->data['user_token'] . '&product_id=' . (int)$existing['product_id'], true) : ''
			);
		}

		$url = $this->buildFilterUrl(array('page'));

		$pagination = new Pagination();
		$pagination->total = $product_total;
		$pagination->page = $page;
		$pagination->limit = $limit;
		$pagination->url = $this->url->link($this->route, 'user_token=' . $this->session->data['user_token'] . $url . '&page={page}', true);

		$data['pagination'] = $pagination->render();
		$data['results'] = sprintf($this->language->get('text_pagination'), ($product_total) ? (($page - 1) * $limit) + 1 : 0, ((($page - 1) * $limit) > ($product_total - $limit)) ? $product_total : ((($page - 1) * $limit) + $limit), $product_total, ceil($product_total / $limit));

		$data['breadcrumbs'] = array(
			array(
				'text' => $this->language->get('text_home'),
				'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
			),
			array(
				'text' => $this->language->get('heading_title'),
				'href' => $this->url->link($this->route, 'user_token=' . $this->session->data['user_token'], true)
			)
		);

		$data['user_token'] = $this->session->data['user_token'];
		$data['filter_search'] = $filter_search;
		$data['filter_supplier_category_id'] = $filter_supplier_category_id;
			$data['filter_import_status'] = $filter_import_status;
			$data['filter_action'] = 'index.php';
			$data['import'] = $this->url->link($this->route . '/import', 'user_token=' . $this->session->data['user_token'] . $this->buildFilterUrl(), true);
			$data['update_quantities'] = $this->url->link($this->route . '/updateQuantities', 'user_token=' . $this->session->data['user_token'] . '&return_route=products' . $this->buildFilterUrl(), true);
			$data['update_prices'] = $this->url->link($this->route . '/updatePrices', 'user_token=' . $this->session->data['user_token'] . '&return_route=products' . $this->buildFilterUrl(), true);
			$data['update_products'] = $this->url->link($this->route . '/updateProducts', 'user_token=' . $this->session->data['user_token'] . '&return_route=products' . $this->buildFilterUrl(), true);
			$data['refresh'] = $this->url->link($this->route, 'user_token=' . $this->session->data['user_token'] . $this->buildFilterUrl(array('refresh')) . '&refresh=1', true);
			$data['cancel'] = $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true);
			$data['products_page'] = $this->url->link($this->route, 'user_token=' . $this->session->data['user_token'], true);
			$data['categories_page'] = $this->url->link($this->route . '/categories', 'user_token=' . $this->session->data['user_token'], true);
			$data['settings_page'] = $this->url->link($this->route . '/settings', 'user_token=' . $this->session->data['user_token'], true);
			$data['active_tab'] = 'products';

			$data['supplier_categories'] = $supplier_categories;
			$data['local_categories'] = $local_categories;
			$data['local_category_names'] = $local_category_names;
			$data['category_map'] = $category_map;
			$data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();
			unset($settings['wiz_feed_password']);
			$data['settings'] = $settings;
			$data['price_modes'] = array(
				'ppc'       => $this->language->get('text_price_ppc'),
				'price'     => $this->language->get('text_price_base'),
				'soa_price' => $this->language->get('text_price_soa')
			);

		if ($feed_error) {
			$data['error_warning'] = $feed_error;
		} elseif (!empty($this->session->data['warning'])) {
			$data['error_warning'] = $this->session->data['warning'];
			unset($this->session->data['warning']);
		} else {
			$data['error_warning'] = '';
		}

		if (!empty($this->session->data['success'])) {
			$data['success'] = $this->session->data['success'];
			unset($this->session->data['success']);
		} else {
			$data['success'] = '';
		}

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

			$this->response->setOutput($this->load->view($this->route, $data));
		}

		public function categories() {
			$this->load->language($this->route);
			$this->load->model('catalog/category');
			$this->load->model('setting/setting');

			$this->document->setTitle($this->language->get('heading_categories'));

			$settings = $this->getSettings();
			$force_refresh = !empty($this->request->get['refresh']);
			$supplier_categories = array();
			$feed_error = '';

			try {
				$supplier_categories = $this->buildCategoriesFromProducts($this->normaliseProducts($this->fetchXml('products', $settings, $force_refresh)));
			} catch (Exception $e) {
				$feed_error = $e->getMessage();
			}

			$data['breadcrumbs'] = array(
				array(
					'text' => $this->language->get('text_home'),
					'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
				),
				array(
					'text' => $this->language->get('heading_title'),
					'href' => $this->url->link($this->route, 'user_token=' . $this->session->data['user_token'], true)
				),
				array(
					'text' => $this->language->get('heading_categories'),
					'href' => $this->url->link($this->route . '/categories', 'user_token=' . $this->session->data['user_token'], true)
				)
			);

			$data['user_token'] = $this->session->data['user_token'];
			$data['products_page'] = $this->url->link($this->route, 'user_token=' . $this->session->data['user_token'], true);
			$data['categories_page'] = $this->url->link($this->route . '/categories', 'user_token=' . $this->session->data['user_token'], true);
			$data['settings_page'] = $this->url->link($this->route . '/settings', 'user_token=' . $this->session->data['user_token'], true);
			$data['active_tab'] = 'categories';
			$data['save'] = $this->url->link($this->route . '/save', 'user_token=' . $this->session->data['user_token'], true);
			$data['create_categories'] = $this->url->link($this->route . '/createCategories', 'user_token=' . $this->session->data['user_token'], true);
			$data['refresh'] = $this->url->link($this->route . '/categories', 'user_token=' . $this->session->data['user_token'] . '&refresh=1', true);
			$data['cancel'] = $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true);
			$data['supplier_categories'] = $supplier_categories;
			$data['local_categories'] = $this->model_catalog_category->getCategories(array(
				'sort'  => 'name',
				'order' => 'ASC'
			));
			unset($settings['wiz_feed_password']);
			$data['settings'] = $settings;
			$data['category_map'] = !empty($settings['category_map']) && is_array($settings['category_map']) ? $settings['category_map'] : array();

			if ($feed_error) {
				$data['error_warning'] = $feed_error;
			} elseif (!empty($this->session->data['warning'])) {
				$data['error_warning'] = $this->session->data['warning'];
				unset($this->session->data['warning']);
			} else {
				$data['error_warning'] = '';
			}

			if (!empty($this->session->data['success'])) {
				$data['success'] = $this->session->data['success'];
				unset($this->session->data['success']);
			} else {
				$data['success'] = '';
			}

			$data['header'] = $this->load->controller('common/header');
			$data['column_left'] = $this->load->controller('common/column_left');
			$data['footer'] = $this->load->controller('common/footer');

			$this->response->setOutput($this->load->view($this->route . '_categories', $data));
		}

		public function settings() {
			$this->load->language($this->route);
			$this->load->model('localisation/tax_class');
			$this->load->model('setting/setting');

			$this->document->setTitle($this->language->get('heading_settings'));

			$settings = $this->getSettings();

			if (empty($settings['cron_token'])) {
				$settings['cron_token'] = token(32);
				$this->saveSettings($settings);
			}

			$data['breadcrumbs'] = array(
				array(
					'text' => $this->language->get('text_home'),
					'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
				),
				array(
					'text' => $this->language->get('heading_title'),
					'href' => $this->url->link($this->route, 'user_token=' . $this->session->data['user_token'], true)
				),
				array(
					'text' => $this->language->get('heading_settings'),
					'href' => $this->url->link($this->route . '/settings', 'user_token=' . $this->session->data['user_token'], true)
				)
			);

			$data['user_token'] = $this->session->data['user_token'];
			$data['products_page'] = $this->url->link($this->route, 'user_token=' . $this->session->data['user_token'], true);
			$data['categories_page'] = $this->url->link($this->route . '/categories', 'user_token=' . $this->session->data['user_token'], true);
			$data['settings_page'] = $this->url->link($this->route . '/settings', 'user_token=' . $this->session->data['user_token'], true);
			$data['active_tab'] = 'settings';
			$data['save'] = $this->url->link($this->route . '/save', 'user_token=' . $this->session->data['user_token'], true);
			$data['update_quantities'] = $this->url->link($this->route . '/updateQuantities', 'user_token=' . $this->session->data['user_token'] . '&return_route=settings', true);
			$data['update_prices'] = $this->url->link($this->route . '/updatePrices', 'user_token=' . $this->session->data['user_token'] . '&return_route=settings', true);
			$data['update_products'] = $this->url->link($this->route . '/updateProducts', 'user_token=' . $this->session->data['user_token'] . '&return_route=settings', true);
			$data['cancel'] = $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true);
			$data['wiz_feed_active'] = $settings['wiz_feed_password'] !== '';
			$data['can_change_feed'] = $this->user->hasPermission('modify', $this->route);
			unset($settings['wiz_feed_password']);
			$data['settings'] = $settings;
			$data['cron_url'] = HTTPS_SERVER . 'index.php?route=' . $this->route . '/cron&token=' . $settings['cron_token'];
			$data['feed_url'] = $data['wiz_feed_active'] ? $this->wiz_feed_endpoint : $this->feed_endpoint;
			$data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();
			$data['price_modes'] = array(
				'ppc'       => $this->language->get('text_price_ppc'),
				'price'     => $this->language->get('text_price_base'),
				'soa_price' => $this->language->get('text_price_soa')
			);

			if (!empty($this->session->data['warning'])) {
				$data['error_warning'] = $this->session->data['warning'];
				unset($this->session->data['warning']);
			} else {
				$data['error_warning'] = '';
			}

			if (!empty($this->session->data['success'])) {
				$data['success'] = $this->session->data['success'];
				unset($this->session->data['success']);
			} else {
				$data['success'] = '';
			}

			$data['header'] = $this->load->controller('common/header');
			$data['column_left'] = $this->load->controller('common/column_left');
			$data['footer'] = $this->load->controller('common/footer');

			$this->response->setOutput($this->load->view($this->route . '_settings', $data));
		}

		public function save() {
			$this->load->language($this->route);
			$this->load->model('setting/setting');

		if ($this->request->server['REQUEST_METHOD'] == 'POST') {
			$settings = $this->settingsFromPost($this->getSettings());
			$feed_password = $this->request->post['colby_wiz_feed_password'] ?? '';
			$use_legacy_feed = !empty($this->request->post['colby_use_legacy_feed']);

			try {
				if ($feed_password !== '' || $use_legacy_feed) {
					if (!$this->user->hasPermission('modify', $this->route) || !is_string($feed_password)) {
						throw new Exception($this->language->get('error_feed_permission'));
					}

					$settings['wiz_feed_password'] = $use_legacy_feed ? '' : html_entity_decode($feed_password, ENT_QUOTES, 'UTF-8');

					if ($settings['wiz_feed_password'] !== '' && !$this->normaliseProducts($this->fetchXml('products', $settings, true))) {
						throw new Exception($this->language->get('error_xml'));
					}

					$this->saveFeedPassword($settings['wiz_feed_password']);
				}

				$this->saveSettings($settings);
				$this->session->data['success'] = $this->language->get('text_settings_success');
			} catch (Exception $e) {
				$this->session->data['warning'] = $e->getMessage();
			}
			}

			$return_route = isset($this->request->post['return_route']) ? $this->request->post['return_route'] : 'settings';

			if ($return_route == 'categories') {
				$this->response->redirect($this->url->link($this->route . '/categories', 'user_token=' . $this->session->data['user_token'], true));
			} else {
				$this->response->redirect($this->url->link($this->route . '/settings', 'user_token=' . $this->session->data['user_token'], true));
			}
		}

	public function import() {
		$this->load->language($this->route);
		$this->load->model('extension/module/colby_import');
		$this->load->model('setting/setting');

		$selected = isset($this->request->post['selected']) ? (array)$this->request->post['selected'] : array();

			if (!$selected) {
				$this->session->data['warning'] = $this->language->get('error_selected');
				$this->response->redirect($this->url->link($this->route, 'user_token=' . $this->session->data['user_token'] . $this->buildFilterUrl(), true));
				return;
			}

		$settings = $this->settingsFromPost($this->getSettings());
		$category_map = !empty($settings['category_map']) && is_array($settings['category_map']) ? $settings['category_map'] : array();
		$default_category_id = isset($this->request->post['category_id']) ? (int)$this->request->post['category_id'] : 0;
		$create_parent_category_id = isset($this->request->post['create_parent_category_id']) ? (int)$this->request->post['create_parent_category_id'] : 0;
		$use_category_map = !empty($this->request->post['use_category_map']);
		$auto_create_category = !empty($this->request->post['auto_create_category']);
		$settings['update_existing_descriptions'] = !empty($this->request->post['update_existing_descriptions']);

		try {
			$products = $this->normaliseProducts($this->fetchXml('products', $settings, false));
			$supplier_categories = $this->buildCategoriesFromProducts($products);
			} catch (Exception $e) {
				$this->session->data['warning'] = $e->getMessage();
				$this->response->redirect($this->url->link($this->route, 'user_token=' . $this->session->data['user_token'] . $this->buildFilterUrl(), true));
				return;
			}

		$products_by_code = array();
		foreach ($products as $product) {
			$products_by_code[$product['code']] = $product;
		}

		$supplier_categories_by_id = array();
		foreach ($supplier_categories as $category) {
			$supplier_categories_by_id[$category['id']] = $category;
		}

		$created = 0;
		$updated = 0;
		$skipped = 0;
		$map_changed = false;

		foreach ($selected as $code) {
			$code = trim($code);

			if (!$code || empty($products_by_code[$code])) {
				$skipped++;
				continue;
			}

			$product = $products_by_code[$code];
			$category_id = 0;

			if ($use_category_map && !empty($category_map[$product['supplier_category_id']])) {
				$category_id = (int)$category_map[$product['supplier_category_id']];
			}

			if (!$category_id && $auto_create_category && $product['supplier_category_id']) {
				$supplier_category = !empty($supplier_categories_by_id[$product['supplier_category_id']]) ? $supplier_categories_by_id[$product['supplier_category_id']] : array(
					'id'      => $product['supplier_category_id'],
					'name_hr' => $product['supplier_category_name'],
					'name_en' => $product['supplier_category_name']
				);

				$category_id = $this->model_extension_module_colby_import->createCategoryFromSupplier($supplier_category, $create_parent_category_id);

				if ($category_id) {
					$category_map[$product['supplier_category_id']] = $category_id;
					$map_changed = true;
				}
			}

			if (!$category_id && $default_category_id) {
				$category_id = $default_category_id;
			}

			if (!$category_id) {
				$skipped++;
				continue;
			}

			$result = $this->model_extension_module_colby_import->importProduct($product, $category_id, $settings);

			if ($result == 'created') {
				$created++;
			} elseif ($result == 'updated') {
				$updated++;
			} else {
				$skipped++;
			}
		}

		if ($map_changed) {
			$settings['category_map'] = $category_map;
			$this->saveSettings($settings);
		}

			$this->session->data['success'] = sprintf($this->language->get('text_import_success'), $created, $updated, $skipped);
			$this->response->redirect($this->url->link($this->route, 'user_token=' . $this->session->data['user_token'] . $this->buildFilterUrl(), true));
		}

	public function updateQuantities() {
		$this->load->language($this->route);
		$this->load->model('extension/module/colby_import');
		$this->load->model('setting/setting');

		$settings = $this->getSettings();

		try {
			$products = $this->normaliseProducts($this->fetchXml('products', $settings, true));
			$result = $this->model_extension_module_colby_import->updateQuantities($products);

			$this->session->data['success'] = sprintf($this->language->get('text_quantity_success'), $result['updated'], $result['not_found']);
		} catch (Exception $e) {
			$this->session->data['warning'] = $e->getMessage();
		}

			$return_route = isset($this->request->get['return_route']) ? $this->request->get['return_route'] : 'settings';

			if ($return_route == 'products') {
				$this->response->redirect($this->url->link($this->route, 'user_token=' . $this->session->data['user_token'] . $this->buildFilterUrl(), true));
			} else {
				$this->response->redirect($this->url->link($this->route . '/settings', 'user_token=' . $this->session->data['user_token'], true));
			}
		}

	public function updatePrices() {
		$this->load->language($this->route);
		$this->load->model('extension/module/colby_import');
		$this->load->model('setting/setting');

		$settings = $this->getSettings();

		try {
			$products = $this->normaliseProducts($this->fetchXml('products', $settings, true));
			$result = $this->model_extension_module_colby_import->updatePrices($products, $settings);

			$this->session->data['success'] = sprintf($this->language->get('text_price_success'), $result['updated'], $result['not_found'], $result['skipped']);
		} catch (Exception $e) {
			$this->session->data['warning'] = $e->getMessage();
		}

			$return_route = isset($this->request->get['return_route']) ? $this->request->get['return_route'] : 'settings';

			if ($return_route == 'products') {
				$this->response->redirect($this->url->link($this->route, 'user_token=' . $this->session->data['user_token'] . $this->buildFilterUrl(), true));
			} else {
				$this->response->redirect($this->url->link($this->route . '/settings', 'user_token=' . $this->session->data['user_token'], true));
			}
		}

	public function updateProducts() {
		$this->load->language($this->route);
		$this->load->model('extension/module/colby_import');
		$this->load->model('setting/setting');

		$settings = $this->getSettings();

		try {
			$products = $this->normaliseProducts($this->fetchXml('products', $settings, true));
			$result = $this->model_extension_module_colby_import->updateProducts($products, $settings);

			$this->session->data['success'] = sprintf($this->language->get('text_update_success'), $result['updated'], $result['not_found'], $result['skipped']);
		} catch (Exception $e) {
			$this->session->data['warning'] = $e->getMessage();
		}

			$return_route = isset($this->request->get['return_route']) ? $this->request->get['return_route'] : 'settings';

			if ($return_route == 'products') {
				$this->response->redirect($this->url->link($this->route, 'user_token=' . $this->session->data['user_token'] . $this->buildFilterUrl(), true));
			} else {
				$this->response->redirect($this->url->link($this->route . '/settings', 'user_token=' . $this->session->data['user_token'], true));
			}
		}

	public function updateProductImages() {
		$this->load->language($this->route);
		$this->load->model('catalog/product');
		$this->load->model('extension/module/colby_import');
		$this->load->model('setting/setting');

		$product_id = isset($this->request->get['product_id']) ? (int)$this->request->get['product_id'] : 0;
		$redirect = $product_id ? $this->url->link('catalog/product/edit', 'user_token=' . $this->session->data['user_token'] . '&product_id=' . $product_id, true) : $this->url->link('catalog/product', 'user_token=' . $this->session->data['user_token'], true);
		$product_info = $product_id ? $this->model_catalog_product->getProduct($product_id) : array();

		if (!$product_info) {
			$this->session->data['warning'] = $this->language->get('error_product_not_found');
			$this->response->redirect($redirect);
			return;
		}

		try {
			$settings = $this->getSettings();
			$products = $this->normaliseProducts($this->fetchXml('products', $settings, false));
			$supplier_product = $this->findSupplierProductForLocalProduct($products, $product_info);

			if (!$supplier_product) {
				$this->session->data['warning'] = $this->language->get('error_colby_product_not_found');
				$this->response->redirect($redirect);
				return;
			}

			$count = $this->model_extension_module_colby_import->updateProductImagesFromSupplier($product_id, $supplier_product);

			if ($count) {
				$this->session->data['success'] = sprintf($this->language->get('text_image_update_success'), $count);
			} else {
				$this->session->data['warning'] = $this->language->get('error_colby_images_not_found');
			}
		} catch (Exception $e) {
			$this->session->data['warning'] = $e->getMessage();
		}

		$this->response->redirect($redirect);
	}

	public function cron() {
		$this->load->language($this->route);
		$this->load->model('extension/module/colby_import');
		$this->load->model('setting/setting');

		$settings = $this->getSettings();
		$token = isset($this->request->get['token']) ? $this->request->get['token'] : '';

		$this->response->addHeader('Content-Type: application/json');

		if (empty($settings['cron_token']) || !hash_equals($settings['cron_token'], $token)) {
			$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 403 Forbidden');
			$this->response->setOutput(json_encode(array(
				'success' => false,
				'error'   => 'Invalid token'
			)));
			return;
		}

		try {
			$action = isset($this->request->get['action']) ? $this->request->get['action'] : 'products';
			$products = $this->normaliseProducts($this->fetchXml('products', $settings, true));

			if ($action == 'prices') {
				$result = $this->model_extension_module_colby_import->updatePrices($products, $settings);
			} elseif ($action == 'quantities') {
				$result = $this->model_extension_module_colby_import->updateQuantities($products);
			} else {
				$action = 'products';
				$result = $this->model_extension_module_colby_import->updateProducts($products, $settings);
			}

			$this->response->setOutput(json_encode(array(
				'success'   => true,
				'action'    => $action,
				'updated'   => $result['updated'],
				'not_found' => $result['not_found'],
				'skipped'   => isset($result['skipped']) ? $result['skipped'] : 0
			)));
		} catch (Exception $e) {
			$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
			$this->response->setOutput(json_encode(array(
				'success' => false,
				'error'   => $e->getMessage()
			)));
		}
	}

	public function createCategories() {
		$this->load->language($this->route);
		$this->load->model('extension/module/colby_import');
		$this->load->model('setting/setting');

		$selected = isset($this->request->post['supplier_category_ids']) ? (array)$this->request->post['supplier_category_ids'] : array();

			if (!$selected) {
				$this->session->data['warning'] = $this->language->get('error_category_selected');
				$this->response->redirect($this->url->link($this->route . '/categories', 'user_token=' . $this->session->data['user_token'], true));
				return;
			}

		$settings = $this->settingsFromPost($this->getSettings());
		$category_map = !empty($settings['category_map']) && is_array($settings['category_map']) ? $settings['category_map'] : array();
		$create_parent_category_id = isset($this->request->post['create_parent_category_id']) ? (int)$this->request->post['create_parent_category_id'] : 0;
		$created = 0;
		$linked = 0;

		try {
			$supplier_categories = $this->buildCategoriesFromProducts($this->normaliseProducts($this->fetchXml('products', $settings, false)));
			} catch (Exception $e) {
				$this->session->data['warning'] = $e->getMessage();
				$this->response->redirect($this->url->link($this->route . '/categories', 'user_token=' . $this->session->data['user_token'], true));
				return;
			}

		$supplier_categories_by_id = array();
		foreach ($supplier_categories as $category) {
			$supplier_categories_by_id[$category['id']] = $category;
		}

		foreach ($selected as $supplier_category_id) {
			$supplier_category_id = trim($supplier_category_id);

			if (!$supplier_category_id || empty($supplier_categories_by_id[$supplier_category_id])) {
				continue;
			}

			$category_id = !empty($category_map[$supplier_category_id]) ? (int)$category_map[$supplier_category_id] : 0;

			if (!$category_id) {
				$category_id = $this->model_extension_module_colby_import->createCategoryFromSupplier($supplier_categories_by_id[$supplier_category_id], $create_parent_category_id);
				$created++;
			}

			if ($category_id) {
				$category_map[$supplier_category_id] = $category_id;
				$linked++;
			}
		}

		$settings['category_map'] = $category_map;
		$this->saveSettings($settings);

			$this->session->data['success'] = sprintf($this->language->get('text_category_create_success'), $created, $linked);
			$this->response->redirect($this->url->link($this->route . '/categories', 'user_token=' . $this->session->data['user_token'], true));
		}

	private function fetchXml($cache_key, $settings, $force_refresh) {
		$password = $settings['wiz_feed_password'];
		$use_wiz_feed = $password !== '';
		$cache_file = DIR_CACHE . 'colby_import_' . $cache_key . ($use_wiz_feed ? '_wiz' : '') . '.xml';
		$cache_lifetime = 1800;
		$use_cache = !$force_refresh && is_file($cache_file) && (filemtime($cache_file) > (time() - $cache_lifetime));

		if ($use_cache) {
			$content = file_get_contents($cache_file);
		} else {
			$endpoint = $use_wiz_feed ? $this->wiz_feed_endpoint . '?password=' . rawurlencode($password) : $this->feed_endpoint;
			$content = $this->requestFeed($endpoint);
		}

		$previous_errors = libxml_use_internal_errors(true);
		$xml = simplexml_load_string((string)$content, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET | LIBXML_PARSEHUGE);
		libxml_clear_errors();
		libxml_use_internal_errors($previous_errors);

		if (!$xml || ($use_wiz_feed && ($xml->getName() !== 'company' || !isset($xml->item)))) {
			throw new Exception($this->language->get('error_xml'));
		}

		if (!$use_cache) {
			file_put_contents($cache_file, $content, LOCK_EX);
		}

		return $xml;
	}

	private function requestFeed($endpoint) {
		if (function_exists('curl_init')) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $endpoint);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
			curl_setopt($ch, CURLOPT_TIMEOUT, 90);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Wiz Colby Import');

			$content = curl_exec($ch);
			$error = curl_strerror(curl_errno($ch));
			$status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			if ($content === false || $status_code < 200 || $status_code >= 300) {
				throw new Exception(sprintf($this->language->get('error_feed'), $status_code, $error));
			}

			return $content;
		}

		$context = stream_context_create(array(
			'http' => array(
				'method'  => 'GET',
				'header'  => 'User-Agent: Wiz Colby Import',
				'timeout' => 90
			)
		));

		$content = @file_get_contents($endpoint, false, $context);

		if ($content === false) {
			throw new Exception(sprintf($this->language->get('error_feed'), 0, 'file_get_contents'));
		}

		return $content;
	}

	private function normaliseProducts($xml) {
		$products = array();
		$use_wiz_feed = $xml->getName() === 'company';
		$nodes = $use_wiz_feed ? $xml->item : $xml->product;

		foreach ($nodes as $node) {
			$ean = trim((string)($use_wiz_feed ? $node->EAN : $node->productEAN));
			$supplier_id = $use_wiz_feed ? null : trim((string)$node->supplierID);
			$code = $use_wiz_feed ? $ean : (trim((string)$node->productCode) ?: ($ean ?: $supplier_id));
			$name = trim((string)$node->productName);
			$subcategory = $use_wiz_feed ? $node->subCategory : $node->subcategory;
			$main_category_name = trim((string)$node->category);
			$subcategory_name = trim((string)$subcategory);
			$main_category_id = isset($node->category['id']) ? trim((string)$node->category['id']) : $main_category_name;
			$subcategory_id = isset($subcategory['id']) ? trim((string)$subcategory['id']) : $subcategory_name;
			$supplier_category_id = $main_category_id . ($subcategory_id !== '' ? '|' . $subcategory_id : '');
			if ($use_wiz_feed && $supplier_category_id !== '') {
				$supplier_category_id = 'wiz:' . $supplier_category_id;
			}
			$supplier_category_name = $main_category_name . ($subcategory_name !== '' ? ' > ' . $subcategory_name : '');
			$stock = (int)$node->stock;
			$available = $use_wiz_feed ? ($stock > 0 ? 'YES' : 'NO') : strtoupper(trim((string)$node->availableToOrder));

			if ($code === '' || $name === '' || ($use_wiz_feed && trim((string)$node->curCode) !== 'EUR')) {
				continue;
			}

			$attributes = $this->normaliseDescriptionAttributes($node);
			$products[] = array(
				'id'                     => $supplier_id,
				'code'                   => $code,
				'barcode'                => $ean,
				'name'                   => $name,
				'name_hr'                => $name,
				'name_en'                => $name,
				'brand'                  => trim((string)$node->brand) ?: trim((string)$node->publisher),
				'quantity'               => $available === 'YES' ? $stock : 0,
				'availability_display'   => $available,
				'items_in_package'       => '',
				'supplier_category_id'   => $supplier_category_id,
				'supplier_category_name' => $supplier_category_name,
				'supplier_parent_id'     => $main_category_id,
				'supplier_parent_name'   => $main_category_name,
				'supplier_leaf_id'       => $subcategory_id,
				'supplier_leaf_name'     => $subcategory_name,
				'attributes_hr'          => $attributes,
				'attributes_en'          => $attributes,
				'images'                 => $this->normaliseImages($node, $use_wiz_feed),
				'prices'                 => $this->normalisePrices($node, $use_wiz_feed)
			);
		}

		return $products;
	}

	private function normaliseAvailability($xml) {
		return $this->normaliseProducts($xml);
	}

	private function buildCategoriesFromProducts($products) {
		$categories = array();

		foreach ($products as $product) {
			if ($product['supplier_category_id'] && empty($categories[$product['supplier_category_id']])) {
				$categories[$product['supplier_category_id']] = array(
					'id'          => $product['supplier_category_id'],
					'map_key'     => rawurlencode($product['supplier_category_id']),
					'name'        => $product['supplier_category_name'],
					'name_hr'     => $product['supplier_category_name'],
					'name_en'     => $product['supplier_category_name'],
					'parent_id'   => $product['supplier_parent_id'],
					'parent_name' => $product['supplier_parent_name'],
					'leaf_id'     => $product['supplier_leaf_id'],
					'leaf_name'   => $product['supplier_leaf_name']
				);
			}
		}

		usort($categories, function($a, $b) {
			return strcasecmp($a['name'], $b['name']);
		});

		return $categories;
	}

	private function normaliseDescriptionAttributes($node) {
		$attributes = array();
		$fields = array(
			'publisher'    => 'Publisher',
			'developer'    => 'Developer',
			'warranty'     => 'Warranty',
			'netWeight'    => 'Net weight',
			'grossWeight'  => 'Gross weight',
			'taxRate'      => 'Tax rate',
			'url'          => 'Colby URL'
		);

		foreach ($fields as $field => $title) {
			$value = isset($node->{$field}) ? trim((string)$node->{$field}) : '';

			if ($value !== '') {
				$attributes[] = array(
					'title' => $title,
					'value' => $value
				);
			}
		}

		return $attributes;
	}

	private function normaliseImages($node, $use_wiz_feed) {
		$images = array();
		$seen = array();
		$candidates = $use_wiz_feed ? array(trim((string)$node->imageBig)) : array(trim((string)$node->picBig), trim((string)$node->boxPic));
		$extra_images = $use_wiz_feed ? ($node->imagesOther->image ?? array()) : ($node->extraPhotos->extraPhoto ?? array());

		foreach ($extra_images as $extra_photo) {
			$candidates[] = trim((string)$extra_photo);
		}

		foreach ($candidates as $url) {
			if (!$this->isUsableImageUrl($url) || isset($seen[$url])) {
				continue;
			}

			$seen[$url] = true;
			$images[] = array(
				'url'   => $url,
				'thumb' => $url,
				'title' => ''
			);
		}

		return $images;
	}

	private function isUsableImageUrl($url) {
		if ($url === '' || substr($url, -1) === '/' || stripos($url, '/null') !== false) {
			return false;
		}

		$extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

		return in_array($extension, array('jpg', 'jpeg', 'png', 'gif', 'webp'));
	}

	private function normalisePrices($node, $use_wiz_feed) {
		$retail_price = $use_wiz_feed ? $node->SRP : $node->PPC;
		$sale_price = $use_wiz_feed ? $node->priceOnSale : $node->SOAPrice;

		return array(
			'price'     => (float)str_replace(',', '.', (string)$node->price),
			'ppc'       => (float)str_replace(',', '.', (string)$retail_price),
			'soa_price' => (float)str_replace(',', '.', (string)$sale_price)
		);
	}

	private function filterProducts($products, $search, $supplier_category_id) {
		$filtered = array();
		$search = utf8_strtolower($search);

		foreach ($products as $product) {
			if ($supplier_category_id && $product['supplier_category_id'] !== $supplier_category_id) {
				continue;
			}

			if ($search) {
				$haystack = utf8_strtolower($product['code'] . ' ' . $product['barcode'] . ' ' . $product['name'] . ' ' . $product['brand'] . ' ' . $product['supplier_category_name']);

				if (strpos($haystack, $search) === false) {
					continue;
				}
			}

			$filtered[] = $product;
		}

		return $filtered;
	}

	private function pluckCodes($products) {
		$codes = array();

		foreach ($products as $product) {
			if (!empty($product['code'])) {
				$codes[] = $product['code'];
			}
		}

		return $codes;
	}

	private function findSupplierProductForLocalProduct($products, $product_info) {
		foreach ($products as $product) {
			if (!empty($product['code']) && !empty($product_info['model']) && $product['code'] === $product_info['model']) {
				return $product;
			}
		}

		foreach ($products as $product) {
			if (!empty($product['id']) && !empty($product_info['sku']) && $product['id'] === $product_info['sku']) {
				return $product;
			}

			if (!empty($product['barcode']) && !empty($product_info['ean']) && $product['barcode'] === $product_info['ean']) {
				return $product;
			}
		}

		return array();
	}

	private function getSettings() {
		if (!isset($this->model_setting_setting)) {
			$this->load->model('setting/setting');
		}

		$setting = $this->model_setting_setting->getSetting('colby_import');

		return array(
			'wiz_feed_password' => (string)$this->model_setting_setting->getSettingValue('colby_wiz_feed_password'),
			'price_mode'        => !empty($setting['colby_import_price_mode']) ? $setting['colby_import_price_mode'] : 'ppc',
			'margin'            => isset($setting['colby_import_margin']) ? (float)$setting['colby_import_margin'] : 0,
			'tax_class_id'      => isset($setting['colby_import_tax_class_id']) ? (int)$setting['colby_import_tax_class_id'] : 0,
			'category_map'      => !empty($setting['colby_import_category_map']) && is_array($setting['colby_import_category_map']) ? $setting['colby_import_category_map'] : array(),
			'cron_token'        => !empty($setting['colby_import_cron_token']) ? $setting['colby_import_cron_token'] : ''
		);
	}

	private function saveFeedPassword($password) {
		$code = 'colby_wiz_feed';
		$key = 'colby_wiz_feed_password';

		try {
			if ($this->model_setting_setting->getSettingValue($key) === null) {
				$this->model_setting_setting->editSetting($code, array($key => $password));
			} else {
				$this->model_setting_setting->editSettingValue($code, $key, $password);
			}
		} catch (Exception $e) {
			throw new Exception($this->language->get('error_feed_settings'));
		}
	}

	private function settingsFromPost($current) {
		$settings = $current;

		if (isset($this->request->post['colby_import_price_mode']) && in_array($this->request->post['colby_import_price_mode'], array('price', 'ppc', 'soa_price'))) {
			$settings['price_mode'] = $this->request->post['colby_import_price_mode'];
		}

		if (isset($this->request->post['colby_import_margin'])) {
			$settings['margin'] = (float)str_replace(',', '.', $this->request->post['colby_import_margin']);
		}

		if (isset($this->request->post['colby_import_tax_class_id'])) {
			$settings['tax_class_id'] = (int)$this->request->post['colby_import_tax_class_id'];
		}

		if (isset($this->request->post['colby_import_cron_token']) && trim($this->request->post['colby_import_cron_token']) !== '') {
			$settings['cron_token'] = trim($this->request->post['colby_import_cron_token']);
		} elseif (empty($settings['cron_token'])) {
			$settings['cron_token'] = token(32);
		}

		if (isset($this->request->post['colby_import_category_map']) && is_array($this->request->post['colby_import_category_map'])) {
			$use_wiz_feed = $settings['wiz_feed_password'] !== '';
			$settings['category_map'] = array_filter($settings['category_map'], function($category_id) use ($use_wiz_feed) {
				return (strpos((string)$category_id, 'wiz:') === 0) !== $use_wiz_feed;
			}, ARRAY_FILTER_USE_KEY);

			foreach ($this->request->post['colby_import_category_map'] as $supplier_category_id => $category_id) {
				if ((int)$category_id > 0) {
					$settings['category_map'][trim(rawurldecode($supplier_category_id))] = (int)$category_id;
				}
			}
		}

		return $settings;
	}

	private function saveSettings($settings) {
		if (!isset($this->model_setting_setting)) {
			$this->load->model('setting/setting');
		}

		$this->model_setting_setting->editSetting('colby_import', array(
			'colby_import_price_mode'     => $settings['price_mode'],
			'colby_import_margin'         => $settings['margin'],
			'colby_import_tax_class_id'   => $settings['tax_class_id'],
			'colby_import_category_map'   => $settings['category_map'],
			'colby_import_cron_token'     => $settings['cron_token']
		));
	}

	private function buildFilterUrl($exclude = array()) {
		$url = '';
		$params = array('filter_search', 'filter_supplier_category_id', 'filter_import_status', 'page');

		foreach ($params as $param) {
			if (in_array($param, $exclude)) {
				continue;
			}

			if (isset($this->request->get[$param]) && $this->request->get[$param] !== '') {
				$url .= '&' . $param . '=' . urlencode(html_entity_decode($this->request->get[$param], ENT_QUOTES, 'UTF-8'));
			}
		}

		return $url;
	}

	private function clearFeedCache() {
		foreach (array('products') as $cache_key) {
			$cache_file = DIR_CACHE . 'colby_import_' . $cache_key . '.xml';

			if (is_file($cache_file)) {
				@unlink($cache_file);
			}
		}
	}

	private function formatPrice($price) {
		return number_format((float)$price, 2, '.', '');
	}
}
