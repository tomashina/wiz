<?php
require_once DIR_SYSTEM . 'library/wiz_withdrawals.php';
require_once DIR_SYSTEM . 'library/wiz_base_prices.php';

class ControllerSaleReturnWithdrawal extends Controller {
    public function index() {
        $service = new WizWithdrawals($this->registry);
        $url = $this->url->link('sale/return/withdrawal','user_token=' . $this->session->data['user_token'],true);
        if ($this->request->server['REQUEST_METHOD'] === 'POST' && $this->user->hasPermission('modify','sale/return') && !empty($this->request->post['retry'])) {
            $service->ensureTable();
            $service->notify((int)$this->request->post['retry']);
            $this->response->redirect($url);
            return;
        }
        $this->response->addHeader('Cache-Control: private, no-store');
        if (isset($this->request->get['export'])) {
            $columns = ['Broj zahtjeva','Ime i prezime','E-mail','Oznaka narudžbe','Artikli (prazno = cijela narudžba)','Datum i vrijeme primitka','Poslano kupcu','Poslano administratoru'];
            $rows = $service->listing(0, 2147483647);
            foreach ($rows as &$r) { $r['submitted_at'] = WizWithdrawals::localTime($r['submitted_at']); }
            unset($r);
            $this->response->addHeader('Content-Type: text/csv; charset=UTF-8');
            $this->response->addHeader('Content-Disposition: attachment; filename="wiz-raskidi.csv"');
            $this->response->setOutput(WizBasePrices::csv($columns, $rows));
            return;
        }
        $page = max(1,(int)($this->request->get['page'] ?? 1));
        $rows = $service->listing(($page-1)*50,51);
        $data = ['url'=>$url,'page'=>$page,'more'=>count($rows)>50,'rows'=>array_slice($rows,0,50)];
        foreach ($data['rows'] as &$r) { $r['submitted_at'] = WizWithdrawals::localTime($r['submitted_at']); }
        unset($r);
        $this->document->setTitle('Jednostrani raskidi ugovora');
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('sale/withdrawal', $data));
    }
}
