<?php
require_once DIR_SYSTEM . 'library/wiz_withdrawals.php';

class ControllerInformationWithdrawal extends Controller {
    public function index() {
        $title = 'Jednostrani raskid ugovora i povrat artikala';
        $this->document->setTitle($title);
        $session = &$this->session->data;
        $url = $this->url->link('information/withdrawal', '', true);
        $data = ['stage'=>'form','fields'=>[], 'error'=>'','url'=>$url];
        if (empty($session['wiz_withdraw_nonce'])) { $session['wiz_withdraw_nonce'] = bin2hex(random_bytes(32)); }
        if (isset($this->request->get['received']) && isset($session['wiz_withdraw_received'])) {
            $data['stage'] = 'done';
            $data['receipt'] = $session['wiz_withdraw_received'];
        } elseif ($this->request->server['REQUEST_METHOD'] === 'POST') {
            $post = $this->request->post;
            try {
                if (!is_string($post['nonce'] ?? null) || !hash_equals($session['wiz_withdraw_nonce'], $post['nonce'])) {
                    throw new InvalidArgumentException('Obrazac je istekao. Ponovno pregledajte podatke.');
                }
                if (isset($post['confirm']) && isset($session['wiz_withdraw_draft'])) {
                    $service = new WizWithdrawals($this->registry);
                    $id = $service->record($post['nonce'], $session['wiz_withdraw_draft']);
                    $session['wiz_withdraw_received'] = $id;
                    unset($session['wiz_withdraw_draft'], $session['wiz_withdraw_nonce']);
                    $service->notify($id);
                    $this->response->redirect($this->url->link('information/withdrawal','received=1',true));
                    return;
                } elseif (isset($post['edit'])) {
                    $data['fields'] = $session['wiz_withdraw_draft'] ?? [];
                    unset($session['wiz_withdraw_draft']);
                } else {
                    $data['fields'] = WizWithdrawals::fields($post);
                    $session['wiz_withdraw_draft'] = $data['fields'];
                    $data['stage'] = 'review';
                }
            } catch (InvalidArgumentException $e) {
                $data['error'] = $e->getMessage();
                foreach (['full_name','email','order_ref','items'] as $k) { $data['fields'][$k] = is_scalar($post[$k] ?? null) ? html_entity_decode($post[$k], ENT_QUOTES, 'UTF-8') : ''; }
            }
        }
        $data['nonce'] = $session['wiz_withdraw_nonce'];
        $data['return_url'] = $this->url->link('account/return/add', '', true);
        $data['header'] = $this->load->controller('common/header');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->addHeader('Cache-Control: private, no-store');
        $this->response->setOutput($this->load->view('information/withdrawal', $data));
    }
}
