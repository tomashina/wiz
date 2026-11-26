<?php
require_once DIR_SYSTEM . 'library/wiz_base_prices.php';
class ControllerEventWizPrices extends Controller {
    public function index(&$route, &$data) {
        $preview = defined('WIZ_PREVIEW_PRICE_ESTIMATES') && WIZ_PREVIEW_PRICE_ESTIMATES === true &&
            defined('HTTP_SERVER') && parse_url(HTTP_SERVER, PHP_URL_HOST) === 'wiz.test';
        if ($route === 'common/header' && $preview) {
            $data['wiz_price_preview'] = in_array($this->request->get['route'] ?? 'common/home',
                ['common/home','product/product','product/category','product/search','product/special','product/manufacturer/info','extension/blog/blog'], true);
            return;
        }
        if (!in_array($route, ['product/product','product/category','product/search','product/special','product/manufacturer_info','blog/blog','extension/module/basel_products'], true)) { return; }
        $ids = array_column($data['products'] ?? [], 'product_id');
        if ($route === 'extension/module/basel_products') {
            foreach ($data['tabs'] ?? [] as $tab) {
                $ids = array_merge($ids, array_column($tab['products'], 'product_id'));
            }
        }
        if ($route === 'product/product' && !empty($data['product_id'])) { $ids[] = $data['product_id']; }
        if (!$ids) { return; }
        $records = (new WizBasePrices($this->db))->getMany($ids);
        $labels = array_fill_keys($ids, 'Bazna cijena u provjeri');
        if ($preview) {
            require_once DIR_SYSTEM . 'library/wiz_price_estimates.php';
            $estimates = (new WizPriceEstimates($this->db))->getMany($ids);
            foreach ($estimates as $id => $estimate) {
                $labels[$id] = $this->priceLabel('10.09.2026.', $estimate['estimated_price']);
            }
        }
        foreach ($records as $id => $record) {
            if ($record['base_price'] === null) { continue; }
            $date = DateTime::createFromFormat('!Y-m-d', $record['reference_date']);
            $labels[$id] = $this->priceLabel($date->format('d.m.Y.'), $record['base_price']);
        }
        $data['wiz_base_prices'] = $labels;
    }

    private function priceLabel($date, $price) {
        return 'Bazna cijena na ' . $date . ' · ' . number_format($price, 2, ',', '.') . ' €';
    }
}
