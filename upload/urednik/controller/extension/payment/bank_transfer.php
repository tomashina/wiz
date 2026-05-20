<?php
class ControllerExtensionPaymentBankTransfer extends Controller {
	private $error = array();

	public function index() {
		$this->load->language('extension/payment/bank_transfer');

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('setting/setting');

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('payment_bank_transfer', $this->request->post);

			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
		}

		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}

		if (isset($this->error['bank'])) {
			$data['error_bank'] = $this->error['bank'];
		} else {
			$data['error_bank'] = array();
		}

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/payment/bank_transfer', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['action'] = $this->url->link('extension/payment/bank_transfer', 'user_token=' . $this->session->data['user_token'], true);

		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

		$this->load->model('localisation/language');

		$data['payment_bank_transfer_bank'] = array();

		$languages = $this->model_localisation_language->getLanguages();
		
		foreach ($languages as $language) {
			if (isset($this->request->post['payment_bank_transfer_bank' . $language['language_id']])) {
				$data['payment_bank_transfer_bank'][$language['language_id']] = $this->request->post['payment_bank_transfer_bank' . $language['language_id']];
			} else {
				$data['payment_bank_transfer_bank'][$language['language_id']] = $this->config->get('payment_bank_transfer_bank' . $language['language_id']);
			}
		}

		$data['languages'] = $languages;

		if (isset($this->request->post['payment_bank_transfer_total'])) {
			$data['payment_bank_transfer_total'] = $this->request->post['payment_bank_transfer_total'];
		} else {
			$data['payment_bank_transfer_total'] = $this->config->get('payment_bank_transfer_total');
		}

		if (isset($this->request->post['payment_bank_transfer_order_status_id'])) {
			$data['payment_bank_transfer_order_status_id'] = $this->request->post['payment_bank_transfer_order_status_id'];
		} else {
			$data['payment_bank_transfer_order_status_id'] = $this->config->get('payment_bank_transfer_order_status_id');
		}

		$this->load->model('localisation/order_status');

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		if (isset($this->request->post['payment_bank_transfer_geo_zone_id'])) {
			$data['payment_bank_transfer_geo_zone_id'] = $this->request->post['payment_bank_transfer_geo_zone_id'];
		} else {
			$data['payment_bank_transfer_geo_zone_id'] = $this->config->get('payment_bank_transfer_geo_zone_id');
		}

		$this->load->model('localisation/geo_zone');

		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		if (isset($this->request->post['payment_bank_transfer_status'])) {
			$data['payment_bank_transfer_status'] = $this->request->post['payment_bank_transfer_status'];
		} else {
			$data['payment_bank_transfer_status'] = $this->config->get('payment_bank_transfer_status');
		}

		if (isset($this->request->post['payment_bank_transfer_sort_order'])) {
			$data['payment_bank_transfer_sort_order'] = $this->request->post['payment_bank_transfer_sort_order'];
		} else {
			$data['payment_bank_transfer_sort_order'] = $this->config->get('payment_bank_transfer_sort_order');
		}

		$dryzen_bank_transfer_qr_defaults = array(
			'qr_status'      => '1',
			'qr_company'     => 'GORDOM USLUGE d.o.o.',
			'qr_address'     => 'Wickerhauserova ulica 52',
			'qr_city'        => '10000 Zagreb',
			'qr_iban'        => 'HR6824020061100462543',
			'qr_model'       => 'HR00',
			'qr_reference'   => '{order_id}{year2}',
			'qr_purpose'     => 'SUPP',
			'qr_description' => 'Web narudzba Dryzen'
		);

		foreach ($dryzen_bank_transfer_qr_defaults as $dryzen_key => $dryzen_default) {
			if (isset($this->request->post['payment_bank_transfer_' . $dryzen_key])) {
				$data['payment_bank_transfer_' . $dryzen_key] = $this->request->post['payment_bank_transfer_' . $dryzen_key];
			} else {
				$dryzen_value = $this->config->get('payment_bank_transfer_' . $dryzen_key);
				$data['payment_bank_transfer_' . $dryzen_key] = ($dryzen_value !== null && $dryzen_value !== '') ? $dryzen_value : $dryzen_default;
			}
		}

		$data['text_qr_settings'] = $this->language->get('text_qr_settings');
		$data['entry_qr_status'] = $this->language->get('entry_qr_status');
		$data['entry_qr_company'] = $this->language->get('entry_qr_company');
		$data['entry_qr_address'] = $this->language->get('entry_qr_address');
		$data['entry_qr_city'] = $this->language->get('entry_qr_city');
		$data['entry_qr_iban'] = $this->language->get('entry_qr_iban');
		$data['entry_qr_model'] = $this->language->get('entry_qr_model');
		$data['entry_qr_reference'] = $this->language->get('entry_qr_reference');
		$data['entry_qr_purpose'] = $this->language->get('entry_qr_purpose');
		$data['entry_qr_description'] = $this->language->get('entry_qr_description');
		$data['help_qr_reference'] = $this->language->get('help_qr_reference');
		$data['help_qr_description'] = $this->language->get('help_qr_description');

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/payment/bank_transfer', $data));
	}

	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/payment/bank_transfer')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		$this->load->model('localisation/language');

		$languages = $this->model_localisation_language->getLanguages();

		foreach ($languages as $language) {
			if (empty($this->request->post['payment_bank_transfer_bank' . $language['language_id']])) {
				$this->error['bank'][$language['language_id']] = $this->language->get('error_bank');
			}
		}

		return !$this->error;
	}
}
