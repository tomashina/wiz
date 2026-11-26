<?php
/** Confirmed historical WIZ retail prices, in EUR including VAT. */
class WizBasePrices {
    const COLUMNS = ['product_id', 'model', 'regime', 'first_offer', 'reference_date', 'base_price', 'promotion', 'evidence', 'net_quantity', 'unit'];
    const AUDIT_COLUMNS = ['product_id','model','Naziv','Kategorije','Datum dodavanja u OpenCart','Podešena dostupnost','Akcije u evidenciji','Skupina za provjeru','Mogući referentni datum','Akcija na mogući datum','Potrebno potvrditi'];
    const UNITS = ['kg', 'l', 'm', 'm2', 'm3', 'kom'];
    private $db;
    private $table;
    private $ready = false;

    public function __construct($db) {
        $this->db = $db;
        $this->table = DB_PREFIX . 'wiz_base_price';
    }

    public function ensureTable() {
        if ($this->ready) { return; }
        if (!$this->db->query("SHOW TABLES LIKE '" . $this->db->escape(str_replace('_', '\\_', $this->table)) . "'")->num_rows) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `" . $this->table . "` (
                product_id INT UNSIGNED NOT NULL PRIMARY KEY, regime VARCHAR(12) NOT NULL, first_offer DATE NULL,
                reference_date DATE NULL, base_price DECIMAL(15,2) NULL, promotion VARCHAR(12) NOT NULL,
                evidence VARCHAR(500) NOT NULL, net_quantity DECIMAL(15,6) NULL, unit VARCHAR(8) NOT NULL DEFAULT '',
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $definition = $this->db->query("SHOW COLUMNS FROM " . $this->table . " LIKE 'base_price'")->row;
        if (($definition['Null'] ?? '') === 'NO') {
            $this->db->query("ALTER TABLE " . $this->table . " MODIFY first_offer DATE NULL, MODIFY reference_date DATE NULL, MODIFY base_price DECIMAL(15,2) NULL");
        }
        $this->ready = true;
    }

    public function getMany(array $ids) {
        if (!$ids) { return []; }
        $this->ensureTable();
        return array_column($this->db->query("SELECT * FROM " . $this->table . " WHERE product_id IN (" .
            implode(',', array_unique(array_map('intval', $ids))) . ")")->rows, null, 'product_id');
    }

    public static function dateValid($date) {
        $d = DateTime::createFromFormat('!Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date && $date <= (new DateTime('now', new DateTimeZone('Europe/Zagreb')))->format('Y-m-d');
    }

    public static function validate(array $input) {
        $r = [];
        foreach (self::COLUMNS as $field) {
            $r[$field] = isset($input[$field]) && is_scalar($input[$field]) ? trim((string)$input[$field]) : '';
        }
        if (!ctype_digit($r['product_id']) || (int)$r['product_id'] < 1 || $r['model'] === '') {
            throw new InvalidArgumentException('Upišite ID i šifru/model artikla.');
        }
        $r['net_quantity'] = str_replace(',', '.', $r['net_quantity']);
        if ($r['unit'] !== '' || $r['net_quantity'] !== '') {
            if (!in_array($r['unit'], self::UNITS, true) || !preg_match('/^\d{1,9}(?:\.\d{1,6})?$/D', $r['net_quantity']) || (float)$r['net_quantity'] <= 0) {
                throw new InvalidArgumentException('Za jediničnu cijenu potrebne su pozitivna neto količina i jedinica mjere.');
            }
        }
        if ($r['base_price'] === '' && $r['net_quantity'] !== '' && !array_filter([$r['regime'],$r['first_offer'],$r['reference_date'],$r['promotion'],$r['evidence']])) { return $r; }
        if (!in_array($r['regime'], ['ostalo', 'skupina2025'], true) || ($r['first_offer'] !== '' && !self::dateValid($r['first_offer'])) || !self::dateValid($r['reference_date'])) {
            throw new InvalidArgumentException('Provjerite skupinu robe i oba datuma u obliku GGGG-MM-DD.');
        }
        $baseline = $r['regime'] === 'skupina2025' ? '2025-05-02' : '2026-09-10';
        if ($r['reference_date'] !== max($baseline, $r['first_offer'])) {
            throw new InvalidArgumentException('Referentni datum za tu skupinu i prvu ponudu je ' . max($baseline, $r['first_offer']) . '.');
        }
        $expected = $r['regime'] === 'skupina2025' ? 'najniza30' : 'prethodna';
        if (!in_array($r['promotion'], ['redovna', $expected], true) || $r['evidence'] === '' || mb_strlen($r['evidence']) > 500) {
            throw new InvalidArgumentException('Potvrdite izvor iznosa i osnovu. Za akciju u ovoj skupini upišite ' . $expected . '.');
        }
        $r['base_price'] = str_replace(',', '.', $r['base_price']);
        if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $r['base_price'])) {
            throw new InvalidArgumentException('Upišite potvrđenu maloprodajnu cijenu s PDV-om (EUR).');
        }
        return $r;
    }

    public function saveRows(array $rows) {
        $this->ensureTable();
        $seen = [];
        $this->db->query('START TRANSACTION');
        try {
            foreach ($rows as $index => $row) {
                try {
                    $r = self::validate($row);
                    $id = (int)$r['product_id'];
                    $product = $this->db->query("SELECT model FROM " . DB_PREFIX . "product WHERE product_id = " . $id)->row;
                    if (!$product || $product['model'] !== $r['model'] || isset($seen[$id])) {
                        throw new InvalidArgumentException('Provjerite podudaranje ID-a i modela te ponovljene retke.');
                    }
                    $seen[$id] = true;
                    $assignments = [];
                    foreach (array_slice(self::COLUMNS, 2) as $field) {
                        $assignments[$field] = "`" . $field . "` = " . (in_array($field, ['first_offer','reference_date','base_price','net_quantity'], true) && $r[$field] === '' ? 'NULL' : "'" . $this->db->escape($r[$field]) . "'");
                    }
                    $set = implode(', ', $assignments);
                    $updates = $assignments;
                    if ($r['base_price'] === '') { $updates = array_intersect_key($updates, array_flip(['unit','net_quantity'])); }
                    elseif ($r['net_quantity'] === '') { unset($updates['unit'],$updates['net_quantity']); }
                    $this->db->query("INSERT INTO " . $this->table . " SET product_id = " . $id . ", " . $set . " ON DUPLICATE KEY UPDATE " . implode(', ', $updates));
                } catch (InvalidArgumentException $e) {
                    throw new InvalidArgumentException('Redak ' . ($index + 2) . ' (' . ($row['model'] ?? '') . '): ' . $e->getMessage());
                }
            }
            $this->db->query('COMMIT');
        } catch (Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
    }

    public static function readCsv($file) {
        $fp = fopen($file, 'rb');
        try {
            if (fread($fp, 3) !== "\xEF\xBB\xBF") { rewind($fp); }
            if (fgetcsv($fp, 0, ';', '"', '') !== self::COLUMNS) { throw new InvalidArgumentException('Koristite zaglavlje iz CSV predloška.'); }
            $rows = [];
            while (($line = fgetcsv($fp, 0, ';', '"', '')) !== false) {
                if ($line === [null]) { continue; }
                if (count($line) !== count(self::COLUMNS)) { throw new InvalidArgumentException('Pogrešan broj stupaca u retku ' . (count($rows) + 2)); }
                $rows[] = array_combine(self::COLUMNS, $line);
            }
            if (!$rows) { throw new InvalidArgumentException('CSV nema podataka.'); }
            return $rows;
        } finally { fclose($fp); }
    }

    public static function csv(array $columns, array $rows) {
        $fp = fopen('php://temp', 'w+b');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, $columns, ';', '"', '');
        foreach ($rows as $row) {
            $values = array_map(static function ($v) {
                return preg_match('/^[\s]*[=+@-]/u', (string)$v) ? "'" . $v : (string)$v;
            }, $row);
            fputcsv($fp, $values, ';', '"', '');
        }
        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);
        return $csv;
    }

    public function catalogue($audit, $language_id) {
        $this->ensureTable();
        $p = DB_PREFIX;
        $data = $this->db->query("SELECT p.product_id,p.model,d.name,p.date_added,p.date_available,
            a.regime,a.first_offer,a.reference_date,a.base_price,a.promotion,a.evidence,a.net_quantity,a.unit,
            (SELECT GROUP_CONCAT(DISTINCT cd.name ORDER BY cd.name SEPARATOR ' | ') FROM " . $p . "product_to_category pc JOIN " . $p . "category_description cd ON cd.category_id=pc.category_id AND cd.language_id=d.language_id WHERE pc.product_id=p.product_id) categories,
            (SELECT GROUP_CONCAT(DISTINCT CONCAT(s.date_start,' / ',s.date_end) SEPARATOR ' | ') FROM " . $p . "product_special s WHERE s.product_id=p.product_id) specials
            FROM " . $p . "product p JOIN " . $p . "product_to_store ps ON ps.product_id=p.product_id AND ps.store_id=0
            JOIN " . $p . "product_description d ON d.product_id=p.product_id AND d.language_id=" . (int)$language_id .
            " LEFT JOIN " . $p . "wiz_base_price a ON a.product_id=p.product_id WHERE p.status=1 AND p.date_available<=NOW()" .
            ($audit ? " AND a.base_price IS NULL" : '') . " ORDER BY p.product_id")->rows;
        $rows = [];
        foreach ($data as $r) {
            if ($audit) {
                $household = !$r['categories'] || preg_match('/šalice|lampe|rasvjeta|stolice|stolovi|kućanstvo|čišćenje|kozmetika|hrana|piće/iu', $r['categories']);
                $added = substr($r['date_added'], 0, 10);
                $available = $r['date_available'];
                // Catalogue dates are leads for the merchant, never proof of a historical public offer.
                $reference = !$household && $added === $available && self::dateValid($available) ? max('2026-09-10', $available) : '';
                $on_sale = '';
                if ($reference) {
                    $on_sale = 'Nema zapisa za taj datum';
                    foreach (explode(' | ', (string)$r['specials']) as $range) {
                        $dates = explode(' / ', $range);
                        if (count($dates) === 2 && ($dates[0] === '0000-00-00' || $dates[0] <= $reference) && ($dates[1] === '0000-00-00' || $dates[1] >= $reference)) {
                            $on_sale = 'Provjeriti primjenu evidentirane akcije';
                            break;
                        }
                    }
                }
                $rows[] = [$r['product_id'],$r['model'],html_entity_decode($r['name'], ENT_QUOTES, 'UTF-8'),$r['categories'],$r['date_added'],$available,$r['specials'],
                    $household ? 'Provjeriti razvrstavanje robe i datum 02.05.2025. ili 10.09.2026.' : 'Ostala roba prema kategoriji, za potvrdu',
                    $reference,$on_sale,'Potvrditi prvu javnu ponudu i WIZ maloprodajnu cijenu s PDV-om na primjenjivi datum. Za akciju potvrditi prethodno primijenjenu cijenu (ostala roba) ili najnižu u 30 dana prije početka akcije (skupina 2025).'];
            } else {
                $rows[] = array_map(static function ($key) use ($r) { return $r[$key] ?? ''; }, WizBasePrices::COLUMNS);
            }
        }
        return $rows;
    }
}
