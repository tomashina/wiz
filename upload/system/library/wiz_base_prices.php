<?php
/** Confirmed historical WIZ retail prices, in EUR including VAT. */
class WizBasePrices {
    const COLUMNS = ['product_id', 'model', 'regime', 'first_offer', 'reference_date', 'base_price', 'promotion', 'evidence', 'net_quantity', 'unit'];
    const AUDIT_COLUMNS = ['product_id','model','Naziv','Kategorije','Datum dodavanja u OpenCart','Podešena dostupnost','Akcije u evidenciji','Skupina za provjeru','Mogući referentni datum','Akcija na mogući datum','Potrebno potvrditi'];
    const UNITS = ['kg', 'l', 'm', 'm2', 'm3', 'kom'];
    private const REVIEW_CATEGORIES = '(^|[^[:alpha:]])šalice([^[:alpha:]]|$)|lampe|rasvjeta|stolice|stolovi|kućanstvo|čišćenje|kozmetika|hrana|piće';
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

    public function getDisplayRecords(array $ids) {
        if (!$ids) { return []; }
        $this->ensureTable();
        $rows = $this->db->query("SELECT a.*,p.product_id,p.model,p.date_added," . self::standardGoodsCategorySql() . " AS standard_category FROM " . DB_PREFIX . "product p
            LEFT JOIN " . $this->table . " a ON a.product_id=p.product_id WHERE p.product_id IN (" .
            implode(',', array_unique(array_map('intval', $ids))) . ")")->rows;
        return array_column(array_map([self::class, 'withCatalogueDate'], $rows), null, 'product_id');
    }

    /** Croatian leaf categories used by WIZ. A mixed household classification still needs review. */
    public static function standardGoodsCategorySql() {
        $p = DB_PREFIX;
        return "(SELECT MAX(cd.name IN (
                    'Zidni nosači za TV','Stropni nosači za TV','Nosači za TV po veličini ekrana','Zidni TV nosači Vogels',
                    'Stolni stalci za TV i monitore','Mobilni podni stalci za TV','Fiksni podni stalci za TV',
                    'Stropni nosači za projektore','Platna za projektore','Podni stalci za zvučnike','Zidni nosači za zvučnike',
                    'Stalci i držači za mobitele i tablete','Rashladni stalci i stalci za laptop',
                    'Gaming tipkovnice','Gaming miševi','Kontroleri i gamepadi','Podloge za miš','Web kamere',
                    'Kabeli i adapteri','Punjači','Mikrofoni','Bluetooth zvučnici','Gaming zvučnici',
                    'Slušalice','Gaming slušalice','Licencirane slušalice'))
                AND NOT MAX(LOWER(cd.name) REGEXP '" . self::REVIEW_CATEGORIES . "')
            FROM " . $p . "product_to_category pc JOIN " . $p . "category_description cd ON cd.category_id=pc.category_id
            JOIN " . $p . "language cl ON cl.language_id=cd.language_id AND cl.code='hr-hr'
            WHERE pc.product_id=p.product_id)";
    }

    private static function baseline($regime) {
        return $regime === 'skupina2025' ? '2025-05-02' : '2026-09-10';
    }

    /** WIZ uses the day of catalogue entry as the first offer for later additions. */
    public static function withCatalogueDate(array $row) {
        if (!empty($row['reference_date'])) { return $row; }
        $regime = $row['regime'] ?? '';
        if ($regime === '' && !empty($row['standard_category'])) { $regime = 'ostalo'; }
        $first = $row['first_offer'] ?? '';
        $added = substr((string)($row['date_added'] ?? ''), 0, 10);
        if ($first === '') { $first = $added; }
        $baseline = self::baseline($regime);
        // For an unknown group only dates after both statutory cutoffs are unambiguous.
        if (self::dateValid($first) && (in_array($regime, ['ostalo','skupina2025'], true) || $first >= $baseline)) {
            $row['first_offer'] = $first;
            $row['reference_date'] = max($baseline, $first);
        }
        return $row;
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
        $baseline = self::baseline($r['regime']);
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

    public function saveRows(array $rows, $pendingOnly = false, $commonEvidence = '') {
        $this->ensureTable();
        $seen = [];
        $saved = 0;
        $this->db->query('START TRANSACTION');
        try {
            foreach ($rows as $index => $row) {
                try {
                    $id = (int)($row['product_id'] ?? 0);
                    $product = $this->db->query("SELECT p.model,p.date_added," . self::standardGoodsCategorySql() . " AS standard_category FROM " . DB_PREFIX . "product p WHERE p.product_id = " . $id . " FOR UPDATE")->row;
                    if (!$product || $product['model'] !== trim((string)($row['model'] ?? '')) || isset($seen[$id])) {
                        throw new InvalidArgumentException('Provjerite podudaranje ID-a i modela te ponovljene retke.');
                    }
                    $seen[$id] = true;
                    if ($pendingOnly) {
                        $existing = $this->db->query("SELECT base_price FROM " . $this->table . " WHERE product_id=" . $id . " FOR UPDATE")->row;
                        if (isset($existing['base_price'])) { continue; }
                        if (trim((string)($row['base_price'] ?? '')) === '') {
                            throw new InvalidArgumentException('Unesite potvrđeni iznos ili izostavite ovaj redak.');
                        }
                        if (trim((string)($row['evidence'] ?? '')) === '') { $row['evidence'] = $commonEvidence; }
                        if (empty($row['reference_date']) && in_array($row['regime'] ?? '', ['ostalo','skupina2025'], true)) {
                            $first = ($row['first_offer'] ?? '') ?: substr($product['date_added'], 0, 10);
                            if (self::dateValid($first)) {
                                $row['first_offer'] = $first;
                                $row['reference_date'] = max(self::baseline($row['regime']), $first);
                            }
                        }
                    }
                    $defaults = self::withCatalogueDate($product + ['regime' => $row['regime'] ?? '']);
                    if (isset($defaults['reference_date'])) {
                        if (trim((string)($row['base_price'] ?? '')) !== '') {
                            if (empty($row['first_offer'])) { $row['first_offer'] = $defaults['first_offer']; }
                            if (empty($row['reference_date'])) { $row = self::withCatalogueDate($row); }
                        } elseif (($row['first_offer'] ?? '') === $defaults['first_offer'] && ($row['reference_date'] ?? '') === $defaults['reference_date']) {
                            // A quantity-only update can come from a prefilled form or CSV.
                            unset($row['first_offer'], $row['reference_date']);
                        }
                    }
                    $r = self::validate($row);
                    $assignments = [];
                    foreach (array_slice(self::COLUMNS, 2) as $field) {
                        $assignments[$field] = "`" . $field . "` = " . (in_array($field, ['first_offer','reference_date','base_price','net_quantity'], true) && $r[$field] === '' ? 'NULL' : "'" . $this->db->escape($r[$field]) . "'");
                    }
                    $set = implode(', ', $assignments);
                    $updates = $assignments;
                    if ($r['base_price'] === '') { $updates = array_intersect_key($updates, array_flip(['unit','net_quantity'])); }
                    elseif ($r['net_quantity'] === '') { unset($updates['unit'],$updates['net_quantity']); }
                    $this->db->query("INSERT INTO " . $this->table . " SET product_id = " . $id . ", " . $set . " ON DUPLICATE KEY UPDATE " . implode(', ', $updates));
                    $saved++;
                } catch (InvalidArgumentException $e) {
                    throw new InvalidArgumentException('Redak ' . ($index + 2) . ' (' . ($row['model'] ?? '') . '): ' . $e->getMessage());
                }
            }
            $this->db->query('COMMIT');
            return $saved;
        } catch (Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
    }

    public static function readCsv($file) {
        $fp = fopen($file, 'rb');
        try {
            if (fread($fp, 3) !== "\xEF\xBB\xBF") { rewind($fp); }
            $start = ftell($fp);
            $delimiter = null;
            foreach ([';', ',', "\t"] as $candidate) {
                fseek($fp, $start);
                $record = 0;
                $hasTitle = false;
                while (($line = fgetcsv($fp, 0, $candidate, '"', '')) !== false) {
                    $record++;
                    if ($line === self::COLUMNS) {
                        $delimiter = $candidate;
                        break 2;
                    }
                    if (trim(implode('', $line)) === '') { continue; }
                    // Numbers can export a table name on its own line above the column names.
                    if ($hasTitle || trim(implode('', array_slice($line, 1))) !== '') { break; }
                    $hasTitle = true;
                }
            }
            if ($delimiter === null) { throw new InvalidArgumentException('Koristite zaglavlje iz CSV predloška.'); }
            $rows = [];
            while (($line = fgetcsv($fp, 0, $delimiter, '"', '')) !== false) {
                $record++;
                if (trim(implode('', $line)) === '') { continue; }
                if (count($line) !== count(self::COLUMNS)) { throw new InvalidArgumentException('Pogrešan broj stupaca u retku ' . $record . '.'); }
                if ($line === self::COLUMNS) { throw new InvalidArgumentException('Učitajte jednu tablicu s jednim zaglavljem.'); }
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

    private function catalogueRecords($pendingOnly, $language_id) {
        $this->ensureTable();
        $p = DB_PREFIX;
        return $this->db->query("SELECT p.product_id,p.model,d.name,p.date_added,p.date_available," . self::standardGoodsCategorySql() . " AS standard_category,
            a.regime,a.first_offer,a.reference_date,a.base_price,a.promotion,a.evidence,a.net_quantity,a.unit,
            (SELECT GROUP_CONCAT(DISTINCT cd.name ORDER BY cd.name SEPARATOR ' | ') FROM " . $p . "product_to_category pc JOIN " . $p . "category_description cd ON cd.category_id=pc.category_id AND cd.language_id=d.language_id WHERE pc.product_id=p.product_id) categories,
            CONCAT_WS(' | ',
                (SELECT GROUP_CONCAT(DISTINCT CONCAT(s.date_start,' / ',s.date_end) SEPARATOR ' | ') FROM " . $p . "product_special s WHERE s.product_id=p.product_id),
                (SELECT GROUP_CONCAT(DISTINCT CONCAT(q.date_start,' / ',q.date_end) SEPARATOR ' | ') FROM " . $p . "product_discount q WHERE q.product_id=p.product_id AND q.quantity=1)
            ) specials
            FROM " . $p . "product p JOIN " . $p . "product_to_store ps ON ps.product_id=p.product_id AND ps.store_id=0
            JOIN " . $p . "product_description d ON d.product_id=p.product_id AND d.language_id=" . (int)$language_id .
            " LEFT JOIN " . $p . "wiz_base_price a ON a.product_id=p.product_id WHERE p.status=1 AND p.date_available<=NOW()" .
            ($pendingOnly ? " AND a.base_price IS NULL" : '') . " ORDER BY p.product_id")->rows;
    }

    private static function reviewCategory($categories) {
        return !$categories || preg_match('/' . self::REVIEW_CATEGORIES . '/iu', $categories);
    }

    private static function saleOnDate($ranges, $date) {
        foreach (explode(' | ', (string)$ranges) as $range) {
            $dates = explode(' / ', $range);
            if (count($dates) === 2 && ($dates[0] === '0000-00-00' || $dates[0] <= $date) &&
                ($dates[1] === '0000-00-00' || $dates[1] >= $date)) { return true; }
        }
        return false;
    }

    /** Private proposals shared by the CSV and the in-browser review. */
    public function confirmationReview($language_id, WizPriceEstimates $estimates, array $choices = [], $requireKnownCategory = true) {
        $catalogue = $this->catalogueRecords(true, $language_id);
        $drafts = $estimates->getMany(array_column($catalogue, 'product_id'));
        $rows = [];
        $ready = [];
        $groups = [];
        foreach ($catalogue as $r) {
            $draft = $drafts[$r['product_id']] ?? null;
            $r['base_price'] = $draft && $draft['model'] === $r['model'] ? $draft['estimated_price'] : '';
            if (!$r['regime']) {
                if (self::reviewCategory($r['categories']) || ($requireKnownCategory && empty($r['standard_category']))) {
                    $category = (string)$r['categories'];
                    $key = hash('sha256', $category);
                    $choice = $choices[$key] ?? '';
                    if (!in_array($choice, ['', 'ostalo', 'skupina2025'], true)) {
                        throw new InvalidArgumentException('Odaberite ponuđenu skupinu za ' . $category . '.');
                    }
                    if (!isset($groups[$key])) {
                        $groups[$key] = ['name' => $category ?: 'Bez kategorije', 'choice' => $choice, 'count' => 0];
                    }
                    $groups[$key]['count']++;
                    $r['regime'] = $choice;
                } else {
                    $r['regime'] = 'ostalo';
                }
            }
            $r = self::withCatalogueDate($r);
            $added = substr((string)$r['date_added'], 0, 10);
            if (!$r['first_offer'] && self::dateValid($added)) { $r['first_offer'] = $added; }
            if (!$r['promotion'] && $r['reference_date']) {
                $r['promotion'] = self::saleOnDate($r['specials'], $r['reference_date'])
                    ? ($r['regime'] === 'skupina2025' ? 'najniza30' : ($r['regime'] === 'ostalo' ? 'prethodna' : ''))
                    : 'redovna';
            }
            $row = [];
            foreach (self::COLUMNS as $field) { $row[$field] = $r[$field] ?? ''; }
            $missing = [];
            foreach (['regime' => 'skupina robe', 'first_offer' => 'prva ponuda', 'reference_date' => 'referentni datum',
                'base_price' => 'iznos', 'promotion' => 'osnova cijene'] as $field => $label) {
                if ($row[$field] === '') { $missing[] = $label; }
            }
            $issue = $missing ? 'Dopunite: ' . implode(', ', $missing) : '';
            if (!$issue) {
                try {
                    // The source is entered when submitting. Never store this validation placeholder.
                    self::validate(array_replace($row, ['evidence' => 'Pregled']));
                    $ready[] = $row;
                } catch (InvalidArgumentException $e) { $issue = $e->getMessage(); }
            }
            $rows[] = $row + ['name' => html_entity_decode($r['name'], ENT_QUOTES, 'UTF-8'),
                'categories' => (string)$r['categories'], 'issue' => $issue];
        }
        return ['rows' => $rows, 'ready' => $ready, 'groups' => $groups, 'digest' => hash('sha256', serialize($rows))];
    }

    public function confirmationCsv($language_id, WizPriceEstimates $estimates) {
        $review = $this->confirmationReview($language_id, $estimates, [], false);
        $columns = array_flip(self::COLUMNS);
        $rows = array_map(static fn($r) => array_intersect_key($r, $columns), $review['rows']);
        return self::csv(self::COLUMNS, $rows);
    }

    public function saveReviewedBatch(array $review, $digest, $source, $acknowledged) {
        if (!is_string($digest) || !hash_equals($review['digest'], $digest)) {
            throw new InvalidArgumentException('Katalog se promijenio. Pregledajte osvježene podatke i ponovno potvrdite odabrane cijene.');
        }
        if (!$acknowledged || !is_string($source) || trim($source) === '' || mb_strlen(trim($source)) > 500) {
            throw new InvalidArgumentException('Upišite naziv WIZ evidencije i potvrdite da ste usporedili predložene podatke.');
        }
        if (!$review['ready']) { throw new InvalidArgumentException('Nema potpunih prijedloga za potvrdu.'); }
        return $this->saveRows($review['ready'], true, trim($source));
    }

    public function catalogue($audit, $language_id) {
        $data = $this->catalogueRecords($audit, $language_id);
        $rows = [];
        foreach ($data as $r) {
            $r = self::withCatalogueDate($r);
            if ($audit) {
                $household = self::reviewCategory($r['categories']);
                $added = substr($r['date_added'], 0, 10);
                $available = $r['date_available'];
                $reference = $r['reference_date'] ?: (!$household && $added === $available && self::dateValid($available) ? max(self::baseline('ostalo'), $available) : '');
                $on_sale = '';
                if ($reference) {
                    $on_sale = self::saleOnDate($r['specials'], $reference) ? 'Provjeriti primjenu evidentirane akcije' : 'Nema zapisa za taj datum';
                }
                $rows[] = [$r['product_id'],$r['model'],html_entity_decode($r['name'], ENT_QUOTES, 'UTF-8'),$r['categories'],$r['date_added'],$available,$r['specials'],
                    $household ? 'Provjeriti razvrstavanje robe i datum 02.05.2025. ili 10.09.2026.' : 'Ostala roba prema kategoriji, za potvrdu',
                    $reference,$on_sale,($r['reference_date'] ? '' : 'Potvrditi datum prve javne ponude. ') . 'Potvrditi WIZ maloprodajnu cijenu s PDV-om na primjenjivi datum. Za akciju potvrditi prethodno primijenjenu cijenu (ostala roba) ili najnižu u 30 dana prije početka akcije (skupina 2025).'];
            } else {
                $rows[] = array_map(static function ($key) use ($r) { return $r[$key] ?? ''; }, WizBasePrices::COLUMNS);
            }
        }
        return $rows;
    }
}
