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
        if (!in_array($route, ['product/product','product/category','product/search','product/special','product/manufacturer_info','product/quickview','product/compare','account/wishlist','blog/blog','extension/module/basel_products'], true)) { return; }
        $ids = array_column($data['products'] ?? [], 'product_id');
        if ($route === 'extension/module/basel_products') {
            foreach ($data['tabs'] ?? [] as $tab) {
                $ids = array_merge($ids, array_column($tab['products'], 'product_id'));
            }
        }
        if (in_array($route, ['product/product','product/quickview'], true) && !empty($data['product_id'])) { $ids[] = $data['product_id']; }
        if (!$ids) { return; }
        $records = (new WizBasePrices($this->db))->getDisplayRecords($ids);
        $labels = array_fill_keys($ids, 'Bazna cijena');
        $estimates = [];
        if ($preview) {
            require_once DIR_SYSTEM . 'library/wiz_price_estimates.php';
            $estimates = (new WizPriceEstimates($this->db))->getMany($ids);
        }
        foreach ($records as $id => $record) {
            $date = $record['reference_date'];
            $price = $record['base_price'];
            if ($price === null && isset($estimates[$id])) {
                $price = $estimates[$id]['estimated_price'];
                $date = $date ?: '2026-09-10';
            }
            if ($date) {
                $labels[$id] = $this->priceLabel($date, $price);
            }
        }
        $data['wiz_base_prices'] = $labels;
    }

    private function priceLabel($date, $price) {
        $formattedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date)->format('d.m.Y.');
        $label = 'Bazna cijena na ' . $formattedDate;
        return $price === null ? $label : $label . ' · ' . number_format($price, 2, ',', '.') . ' €';
    }
}
