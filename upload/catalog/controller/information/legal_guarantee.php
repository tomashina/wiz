<?php
class ControllerInformationLegalGuarantee extends Controller {
    public function index() {
        $this->document->setTitle('Zakonsko jamstvo – najmanje 2 godine');
        $data['header'] = $this->load->controller('common/header');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('information/legal_guarantee', $data));
    }
}
