<?php
require_once __DIR__ . '/wiz_base_prices.php';

/** Private first-observed price estimates. Confirmed reference prices are stored separately. */
class WizPriceEstimates {
    private $db;
    private $table;
    private $ready = false;

    public function __construct($db) {
        $this->db = $db;
        $this->table = DB_PREFIX . 'wiz_price_estimate';
    }

    public function ensureTable() {
        if ($this->ready) { return; }
        $table = $this->db->escape(str_replace('_', '\\_', $this->table));
        if (!$this->db->query("SHOW TABLES LIKE '" . $table . "'")->num_rows) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `" . $this->table . "` (
                product_id INT UNSIGNED NOT NULL PRIMARY KEY,
                model VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL,
                estimated_price DECIMAL(15,2) NOT NULL, retail_at_snapshot DECIMAL(15,2) NOT NULL,
                basis VARCHAR(32) NOT NULL, estimated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $this->ready = true;
    }

    public static function basisLabel($basis) {
        $labels = [
            'regular' => 'Redovna WIZ maloprodajna cijena na dan procjene',
            'regular_during_promotion' => 'Redovna WIZ maloprodajna cijena tijekom akcije ili količinskog popusta za jedan komad',
            'current_retail' => 'Aktualna WIZ maloprodajna cijena na dan procjene (redovna cijena nije raspoloživa)'
        ];
        return $labels[$basis] ?? '';
    }

    public static function draft($productId, $model, $name, $regularGross, $retailGross, $onSale) {
        $validRegular = $regularGross !== null && ($regularGross > 0 || ($regularGross == 0 && $retailGross == 0));
        $basis = $onSale ? 'regular_during_promotion' : 'regular';
        return [
            'product_id' => (int)$productId, 'model' => $model, 'name' => $name,
            'estimated_price' => number_format($validRegular ? $regularGross : $retailGross, 2, '.', ''),
            'retail_at_snapshot' => number_format($retailGross, 2, '.', ''),
            'basis' => $validRegular ? $basis : 'current_retail'
        ];
    }

    /** A repeated publication, price change, or concurrent insert keeps the original estimate. */
    public function saveMissing(array $drafts, DateTimeImmutable $capturedAt) {
        if (!$drafts) { return; }
        $this->ensureTable();
        $stamp = $capturedAt->setTimezone(new DateTimeZone('Europe/Zagreb'))->format('Y-m-d H:i:s');
        $this->db->query('START TRANSACTION');
        try {
            foreach (array_chunk($drafts, 150) as $batch) {
                $values = [];
                foreach ($batch as $row) {
                    $cells = [(int)$row['product_id']];
                    foreach (['model','name','estimated_price','retail_at_snapshot','basis'] as $key) {
                        $cells[] = "'" . $this->db->escape((string)$row[$key]) . "'";
                    }
                    $cells[] = "'" . $stamp . "'";
                    $values[] = '(' . implode(',', $cells) . ')';
                }
                $this->db->query("INSERT INTO " . $this->table .
                    " (product_id,model,name,estimated_price,retail_at_snapshot,basis,estimated_at) VALUES " .
                    implode(',', $values) . " ON DUPLICATE KEY UPDATE product_id=VALUES(product_id)");
            }
            $this->db->query('COMMIT');
        } catch (Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
    }

    private function pendingSql() {
        $this->ensureTable();
        return "SELECT e.product_id,e.model,e.name,e.estimated_price,e.retail_at_snapshot,e.basis,e.estimated_at
            FROM " . $this->table . " e JOIN " . DB_PREFIX . "product p ON p.product_id=e.product_id
            JOIN " . DB_PREFIX . "product_to_store ps ON ps.product_id=p.product_id AND ps.store_id=0
            LEFT JOIN " . DB_PREFIX . "wiz_base_price a ON a.product_id=p.product_id
            WHERE a.base_price IS NULL AND p.status=1 AND p.date_available<=NOW()";
    }

    public function getMany(array $ids) {
        if (!$ids) { return []; }
        $list = implode(',', array_unique(array_map('intval',$ids)));
        return array_column($this->db->query($this->pendingSql() . ' AND e.product_id IN (' . $list . ')')->rows, null, 'product_id');
    }

    public function get($id) {
        return $this->getMany([(int)$id])[(int)$id] ?? null;
    }

    public function pending($offset, $limit) {
        return $this->db->query($this->pendingSql() . " ORDER BY e.product_id LIMIT " .
            max(0,(int)$offset) . "," . max(1,(int)$limit))->rows;
    }

    public function csv() {
        $records = $this->db->query($this->pendingSql() . ' ORDER BY e.product_id')->rows;
        $columns = ['ID proizvoda','Šifra / model','Naziv','Procijenjena bazna cijena s PDV-om (EUR)',
            'Prodajna cijena na dan procjene s PDV-om (EUR)','Osnova procjene','Vrijeme procjene (Europe/Zagreb)','Status'];
        $rows = [];
        foreach ($records as $r) {
            $rows[] = [$r['product_id'],$r['model'],$r['name'],$r['estimated_price'],$r['retail_at_snapshot'],
                self::basisLabel($r['basis']),$r['estimated_at'],'Procjena – povijesna cijena nije potvrđena'];
        }
        return WizBasePrices::csv($columns,$rows);
    }

    public function writePrivateCsv($path) {
        $csv = $this->csv();
        if (is_file($path) && hash_file('sha256',$path) === hash('sha256',$csv)) { return; }
        $tmp = tempnam(dirname($path), 'wiz-procjene-');
        try {
            if (!$tmp || file_put_contents($tmp,$csv) !== strlen($csv) || !rename($tmp,$path)) {
                throw new RuntimeException('Privatni CSV procjena nije spremljen.');
            }
        } finally {
            if ($tmp && is_file($tmp)) { unlink($tmp); }
        }
    }
}
