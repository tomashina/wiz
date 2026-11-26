<?php
require_once DIR_SYSTEM . 'library/wiz_base_prices.php';
require_once DIR_SYSTEM . 'library/wiz_price_estimates.php';

class ControllerCatalogProductBasePrice extends Controller {
    public function index() {
        $this->document->setTitle('Bazne cijene');
        $prices = new WizBasePrices($this->db);
        $prices->ensureTable();
        $estimates = new WizPriceEstimates($this->db);
        $id = (int)($this->request->get['product_id'] ?? 0);
        $url = $this->url->link('catalog/product/base_price', 'user_token=' . $this->session->data['user_token'], true);
        $data = ['url'=>$url, 'record'=>[], 'error'=>'', 'success'=>$this->session->data['wiz_price_success'] ?? ''];
        unset($this->session->data['wiz_price_success']);
        if (isset($this->request->get['review'])) {
            $this->review($prices, $estimates, $data);
            return;
        }
        if (isset($this->request->get['download'])) {
            if ($this->request->get['download'] === 'confirmation') {
                $filename = 'wiz-skupna-potvrda.csv';
                $csv = $prices->confirmationCsv((int)$this->config->get('config_language_id'), $estimates);
            } elseif ($this->request->get['download'] === 'estimates') {
                $filename = 'wiz-procjene-povijesnih-cijena.csv';
                $csv = $estimates->csv();
            } else {
                $audit = $this->request->get['download'] === 'audit';
                $rows = $prices->catalogue($audit, (int)$this->config->get('config_language_id'));
                $columns = $audit ? WizBasePrices::AUDIT_COLUMNS : WizBasePrices::COLUMNS;
                $filename = $audit ? 'wiz-nepotvrdene-cijene.csv' : 'wiz-predlozak-cijena.csv';
                $csv = WizBasePrices::csv($columns, $rows);
            }
            $this->response->addHeader('Content-Type: text/csv; charset=UTF-8');
            $this->response->addHeader('Content-Disposition: attachment; filename="' . $filename . '"');
            $this->response->setOutput($csv);
            return;
        }
        if ($this->request->server['REQUEST_METHOD'] === 'POST') {
            try {
                if (!$this->user->hasPermission('modify','catalog/product')) { throw new InvalidArgumentException('Nemate ovlast za izmjenu proizvoda.'); }
                $batch = ($this->request->post['import'] ?? '') === 'confirm';
                if (isset($this->request->post['import'])) {
                    $upload = $this->request->files['csv'] ?? [];
                    if (($upload['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) { throw new InvalidArgumentException('Odaberite CSV datoteku.'); }
                    $rows = WizBasePrices::readCsv($upload['tmp_name']);
                } else {
                    $row = array_map(static function ($v) { return is_scalar($v) ? html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8') : ''; }, $this->request->post);
                    $rows = [$row];
                    $data['record'] = $row;
                    $id = (int)($row['product_id'] ?? 0);
                }
                $commonEvidence = trim(html_entity_decode((string)($this->request->post['common_evidence'] ?? ''), ENT_QUOTES, 'UTF-8'));
                $saved = $prices->saveRows($rows, $batch, $commonEvidence);
                $this->session->data['wiz_price_success'] = $batch
                    ? 'Potvrđeno ' . $saved . ' artikala. Već potvrđenih i preskočenih ' . (count($rows) - $saved) . '.'
                    : 'Spremljeno artikala: ' . $saved . '.';
                $this->response->redirect($url . ($id ? '&product_id=' . $id : ''));
                return;
            } catch (InvalidArgumentException $e) { $data['error'] = $e->getMessage(); }
        }
        if ($id && !$data['record']) {
            $data['record'] = $prices->getDisplayRecords([$id])[$id] ?? [];
        }
        $data['estimate'] = $id ? $estimates->get($id) : null;
        if ($data['estimate']) {
            $data['estimate']['basis_label'] = WizPriceEstimates::basisLabel($data['estimate']['basis']);
        }
        $page = max(1, (int)($this->request->get['estimate_page'] ?? 1));
        $per_page = 25;
        $offset = ($page - 1) * $per_page;
        $pending = $estimates->pending($offset, $per_page + 1);
        $data['more_estimates'] = count($pending) > $per_page;
        $data['estimate_page'] = $page;
        $data['estimates'] = [];
        foreach (array_slice($pending, 0, $per_page) as $estimate) {
            $estimate['basis_label'] = WizPriceEstimates::basisLabel($estimate['basis']);
            $data['estimates'][] = $estimate;
        }
        $data['fields'] = WizBasePrices::COLUMNS;
        $data['units'] = WizBasePrices::UNITS;
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('catalog/base_price', $data));
    }

    private function review(WizBasePrices $prices, WizPriceEstimates $estimates, array $data) {
        $post = $this->request->server['REQUEST_METHOD'] === 'POST' ? $this->request->post : [];
        $choices = isset($post['groups']) && is_array($post['groups']) ? $post['groups'] : [];
        $language = (int)$this->config->get('config_language_id');
        try {
            $data['review'] = $prices->confirmationReview($language, $estimates, $choices);
        } catch (InvalidArgumentException $e) {
            $data['error'] = $e->getMessage();
            $data['review'] = $prices->confirmationReview($language, $estimates);
        }
        $data['source'] = isset($post['source']) && is_string($post['source']) ? html_entity_decode($post['source'], ENT_QUOTES, 'UTF-8') : '';
        if (isset($post['confirm_catalogue']) && !$data['error']) {
            try {
                if (!$this->user->hasPermission('modify','catalog/product')) { throw new InvalidArgumentException('Nemate ovlast za izmjenu proizvoda.'); }
                $review = $data['review'];
                $saved = $prices->saveReviewedBatch($review, $post['digest'] ?? '', $data['source'], ($post['reviewed'] ?? '') === '1');
                $this->session->data['wiz_price_success'] = 'Potvrđeno ' . $saved . ' artikala. Već potvrđeno ' .
                    (count($review['ready']) - $saved) . '. Za dopunu ' . (count($review['rows']) - count($review['ready'])) . '.';
                $this->response->redirect($data['url'] . '&review=1');
                return;
            } catch (InvalidArgumentException $e) { $data['error'] = $e->getMessage(); }
        }
        $data['can_modify'] = $this->user->hasPermission('modify','catalog/product');
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('catalog/base_price_review', $data));
    }

}
