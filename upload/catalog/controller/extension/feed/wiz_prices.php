<?php
require_once DIR_SYSTEM . 'library/wiz_price_publication.php';

class ControllerExtensionFeedWizPrices extends Controller {
    public function index() {
        $this->load->model('extension/feed/wiz_prices');
        $archive = new WizPricePublication(DIR_DOWNLOAD . 'wiz-cjenik');
        $index = $this->model_extension_feed_wiz_prices->publish($archive);
        $format = $this->request->get['format'] ?? '';
        if ($format !== '') {
            $version = $this->request->get['version'] ?? null;
            $file = $archive->read($index, $format, is_scalar($version) || $version === null ? $version : -1);
            if (!$file) { $this->response->addHeader('HTTP/1.1 404 Not Found'); $this->response->setOutput('Cjenik nije pronađen.'); return; }
            $this->response->addHeader('Content-Type: ' . ($format === 'xml' ? 'application/xml' : 'text/csv') . '; charset=UTF-8');
            $this->response->addHeader('Content-Disposition: ' . ($format === 'csv' ? 'attachment' : 'inline') . '; filename="' . $file['file'] . '"');
            $this->response->addHeader('Cache-Control: no-cache');
            $this->response->setOutput($file['data']);
            return;
        }
        $this->document->setTitle('Digitalni cjenik');
        $data['store'] = WizPricePublication::MERCHANT;
        $data['address'] = WizPricePublication::ADDRESS;
        $data['oib'] = WizPricePublication::OIB;
        $data['versions'] = array_reverse($index);
        $data['header'] = $this->load->controller('common/header');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('information/wiz_prices', $data));
    }
}
