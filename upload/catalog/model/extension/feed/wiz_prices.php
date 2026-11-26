<?php
require_once DIR_SYSTEM . 'library/wiz_price_publication.php';
require_once DIR_SYSTEM . 'library/wiz_price_estimates.php';

class ModelExtensionFeedWizPrices extends Model {
    public function publish($archive) {
        return $archive->refresh(function () {
            $estimates = new WizPriceEstimates($this->db);
            $rows = $this->rows($estimates);
            $estimates->writePrivateCsv(DIR_DOWNLOAD . 'wiz-procjene-baznih-cijena.csv');
            return $rows;
        }, null, function () {
            $language = $this->db->query("SELECT language_id FROM " . DB_PREFIX . "language WHERE code='hr-hr'")->row;
            $prices = new WizBasePrices($this->db);
            return WizBasePrices::csv(WizBasePrices::AUDIT_COLUMNS, $prices->catalogue(true, $language['language_id']));
        });
    }

    public function rows(?WizPriceEstimates $estimates = null) {
        (new WizBasePrices($this->db))->ensureTable();
        $p = DB_PREFIX;
        // Public Croatian prices, independent of the visitor's language, group, cart and tax address.
        $settings = array_column($this->db->query("SELECT `key`, value FROM " . $p . "setting WHERE store_id=0 AND `key` IN ('config_customer_group_id','config_country_id','config_zone_id','config_currency')")->rows, 'value', 'key');
        if (($settings['config_currency'] ?? '') !== 'EUR') { throw new RuntimeException('Provjerite osnovnu valutu WIZ trgovine.'); }
        $group = (int)$settings['config_customer_group_id'];
        $public_config = clone $this->config;
        $public_config->set('config_customer_group_id', $group);
        $registry = new Registry();
        $registry->set('config', $public_config);
        $registry->set('db', $this->db);
        $tax = new \Cart\Tax($registry);
        foreach (['setShippingAddress','setPaymentAddress','setStoreAddress'] as $method) {
            $tax->$method((int)$settings['config_country_id'], (int)$settings['config_zone_id']);
        }
        $active = " AND (x.date_start='0000-00-00' OR x.date_start<NOW()) AND (x.date_end='0000-00-00' OR x.date_end>NOW()) ORDER BY x.priority,x.price LIMIT 1";
        if ($estimates) { $estimates->ensureTable(); }
        $sql = "SELECT " . ($estimates ? "e.product_id AS existing_estimate," : "") . "p.product_id,p.model,p.sku,p.upc,p.ean,p.jan,p.isbn,p.tax_class_id,p.price,p.quantity,d.name,m.name AS brand,s.name AS stock,
            p.date_added,a.regime,a.first_offer,a.base_price,a.reference_date,a.net_quantity,a.unit,
            (SELECT x.price FROM " . $p . "product_special x WHERE x.product_id=p.product_id AND x.customer_group_id=" . $group . $active . ") AS special,
            (SELECT x.price FROM " . $p . "product_discount x WHERE x.product_id=p.product_id AND x.customer_group_id=" . $group . " AND x.quantity=1" . $active . ") AS discount
            FROM " . $p . "product p JOIN " . $p . "product_to_store ps ON ps.product_id=p.product_id AND ps.store_id=0
            JOIN " . $p . "product_description d ON d.product_id=p.product_id JOIN " . $p . "language l ON l.language_id=d.language_id AND l.code='hr-hr'
            LEFT JOIN " . $p . "manufacturer m ON m.manufacturer_id=p.manufacturer_id
            LEFT JOIN " . $p . "stock_status s ON s.stock_status_id=p.stock_status_id AND s.language_id=d.language_id
            LEFT JOIN " . $p . "wiz_base_price a ON a.product_id=p.product_id " .
            ($estimates ? "LEFT JOIN " . $p . "wiz_price_estimate e ON e.product_id=p.product_id " : "") .
            "WHERE p.status=1 AND p.date_available<=NOW() ORDER BY p.product_id";
        $capturedAt = new DateTimeImmutable('now', new DateTimeZone('Europe/Zagreb'));
        $drafts = [];
        $rows = [];
        foreach ($this->db->query($sql)->rows as $item) {
            $item = WizBasePrices::withCatalogueDate($item);
            $net = $item['special'] ?? $item['discount'] ?? $item['price'];
            $retail = round($tax->calculate((float)$net, (int)$item['tax_class_id'], true), 2);
            $barcode = '';
            foreach (['upc','ean','jan','isbn'] as $field) {
                if (trim((string)$item[$field]) !== '') { $barcode = trim($item[$field]); break; }
            }
            $ean = trim((string)$item['ean']);
            if (!self::validEan($ean)) { $ean = self::validEan($barcode) ? $barcode : ''; }
            $quantity = $item['net_quantity'];
            $name = html_entity_decode(trim($item['name']), ENT_QUOTES, 'UTF-8');
            $code = trim($item['model']) ?: $item['sku'];
            $onSale = $item['special'] !== null || $item['discount'] !== null;
            if ($estimates && $item['base_price'] === null && $item['existing_estimate'] === null && $net !== null && $retail >= 0) {
                $regularGross = $item['price'] === null ? null : round($tax->calculate((float)$item['price'], (int)$item['tax_class_id'], true), 2);
                $drafts[] = WizPriceEstimates::draft($item['product_id'], $code, $name, $regularGross, $retail, $onSale);
            }
            $rows[] = [
                'naziv' => $name,
                'sifra' => $code,
                'marka' => html_entity_decode((string)$item['brand'], ENT_QUOTES, 'UTF-8'),
                'neto_kolicina' => $quantity === null ? '' : rtrim(rtrim($quantity, '0'), '.'),
                'jedinica_mjere' => $item['unit'] ?? '',
                'maloprodajna_cijena' => number_format($retail, 2, '.', ''),
                'posebni_oblik_prodaje' => $onSale ? 'da' : 'ne',
                'cijena_za_jedinicu_mjere' => $quantity > 0 ? number_format($retail / $quantity, 2, '.', '') : '',
                'barkod' => $barcode, 'ean' => $ean,
                'bazna_cijena' => $item['base_price'] ?? '', 'dostupnost' => $item['quantity'] > 0 ? 'Na zalihi' : ($item['stock'] ?: 'Nema na zalihi'),
                'referentni_datum' => $item['reference_date'] ?? ''
            ];
        }
        if (!$rows) { throw new RuntimeException('Hrvatski katalog WIZ-a nema proizvoda za objavu.'); }
        if ($estimates) { $estimates->saveMissing($drafts, $capturedAt); }
        return $rows;
    }

    private static function validEan($value) {
        if (!ctype_digit($value) || !in_array(strlen($value), [8, 13], true)) { return false; }
        $sum = 0;
        $weight = 1;
        for ($i = strlen($value)-1; $i >= 0; $i--) { $sum += (int)$value[$i] * $weight; $weight = 4 - $weight; }
        return $sum % 10 === 0;
    }
}
