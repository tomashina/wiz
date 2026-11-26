<?php
// Exercise the real storefront price exporter with an isolated three-product catalogue.
define('DIR_SYSTEM', dirname(__DIR__) . '/upload/system/');
define('DIR_APPLICATION', dirname(__DIR__) . '/upload/catalog/');
define('DB_PREFIX', 'oc_');
define('DIR_DOWNLOAD', sys_get_temp_dir() . '/wiz-estimates-' . bin2hex(random_bytes(8)) . '/');
require DIR_SYSTEM . 'engine/model.php';
require DIR_SYSTEM . 'engine/registry.php';
require DIR_SYSTEM . 'library/cart/tax.php';
require DIR_SYSTEM . 'library/config.php';
require DIR_APPLICATION . 'model/extension/feed/wiz_prices.php';

function verify($ok, $message) {
    if (!$ok) { throw new RuntimeException($message); }
    echo "PASS $message\n";
}

class PriceEstimateDatabase {
    public $pdo;

    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->sqliteCreateFunction('NOW', fn() => '2026-11-26 08:45:00');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE oc_product(product_id INTEGER PRIMARY KEY,model TEXT,price TEXT,status INTEGER DEFAULT 1,
    quantity INTEGER DEFAULT 1,date_added TEXT,date_available TEXT DEFAULT '2026-01-01',tax_class_id INTEGER DEFAULT 1,
    sku TEXT,upc TEXT,ean TEXT,jan TEXT,isbn TEXT,manufacturer_id INTEGER,stock_status_id INTEGER);
CREATE TABLE oc_product_to_store(product_id INTEGER,store_id INTEGER DEFAULT 0);
CREATE TABLE oc_product_description(product_id INTEGER,name TEXT,language_id INTEGER DEFAULT 1);
CREATE TABLE oc_language(language_id INTEGER,code TEXT);
CREATE TABLE oc_product_special(product_id INTEGER,customer_group_id INTEGER,price TEXT,priority INTEGER,date_start TEXT,date_end TEXT);
CREATE TABLE oc_product_discount(product_id INTEGER,customer_group_id INTEGER,price TEXT,priority INTEGER,date_start TEXT,date_end TEXT,quantity INTEGER);
CREATE TABLE oc_manufacturer(manufacturer_id INTEGER,name TEXT);
CREATE TABLE oc_stock_status(stock_status_id INTEGER,language_id INTEGER,name TEXT);
CREATE TABLE oc_wiz_price_estimate(product_id INTEGER PRIMARY KEY,model TEXT,name TEXT,estimated_price TEXT,retail_at_snapshot TEXT,basis TEXT,estimated_at TEXT);
CREATE TABLE oc_setting(store_id INTEGER,`key` TEXT,value TEXT);
CREATE TABLE oc_wiz_base_price(product_id INTEGER PRIMARY KEY,base_price TEXT,reference_date TEXT,net_quantity TEXT,unit TEXT,regime TEXT,first_offer TEXT);
INSERT INTO oc_product(product_id,model,price,date_added) VALUES(1,'REG','10.00','2026-09-09 11:00:00'),(2,'SALE','40.00','2026-10-14 17:45:00'),(3,'VERIFIED','100.00','2026-10-22 12:00:00');
INSERT INTO oc_product_to_store(product_id) SELECT product_id FROM oc_product;
INSERT INTO oc_language VALUES(1,'hr-hr');
INSERT INTO oc_product_description(product_id,name) SELECT product_id,model FROM oc_product;
INSERT INTO oc_setting VALUES (0,'config_customer_group_id','1'),(0,'config_country_id','53'),(0,'config_zone_id','1'),(0,'config_currency','EUR');
INSERT INTO oc_product_special VALUES(2,1,'24.00',1,'2026-11-01','2026-12-01');
INSERT INTO oc_wiz_base_price(product_id,base_price,reference_date) VALUES(3,'109.00','2026-09-10');
SQL
        );
    }

    public function escape($v) { return str_replace("'", "''", $v); }

    public function query($sql) {
        $sql = str_replace(' ON DUPLICATE KEY UPDATE product_id=VALUES(product_id)', ' ON CONFLICT(product_id) DO NOTHING', $sql);
        if ($sql === 'START TRANSACTION') { $sql = 'BEGIN'; }
        if (str_starts_with($sql, 'SELECT p.product_id,p.model,d.name,p.date_added')) {
            // The unrelated category audit uses MySQL-specific aggregate functions.
            $sql = 'SELECT 1 WHERE 0';
        } elseif (str_starts_with($sql, 'SHOW ')) {
            $sql = 'SELECT 1';
        } elseif (str_contains($sql, 'FROM oc_tax_rule tr1')) {
            $sql = str_contains($sql, "tr1.based = 'store'") ?
                "SELECT 1 AS tax_class_id, 1 AS tax_rate_id, 'PDV' AS name, 25 AS rate, 'P' AS type, 1 AS priority" :
                'SELECT 1 WHERE 0';
        }
        $stmt = $this->pdo->query($sql);
        $rows = $stmt->columnCount() ? $stmt->fetchAll() : [];
        return (object)['rows' => $rows, 'row' => $rows[0] ?? [], 'num_rows' => count($rows)];
    }
}

function removeTestDirectory($dir) {
    if (!is_dir($dir)) { return; }
    foreach (new DirectoryIterator($dir) as $file) {
        if ($file->isDot()) { continue; }
        if ($file->isDir()) { removeTestDirectory($file->getPathname()); }
        else { unlink($file->getPathname()); }
    }
    rmdir($dir);
}

$db = new PriceEstimateDatabase();
$registry = new Registry();
$registry->set('db', $db);
$registry->set('config', new Config());
$model = new ModelExtensionFeedWizPrices($registry);
$estimates = new WizPriceEstimates($db);
mkdir(DIR_DOWNLOAD);
try {
    $archive = new WizPricePublication(DIR_DOWNLOAD . 'archive');
    $versions = $model->publish($archive);
    $drafts = $estimates->getMany([1, 2, 3]);
    verify(array_keys($drafts) === [1, 2], 'only products missing historical evidence receive drafts');
    verify($drafts[1]['estimated_price'] === '12.50' && $drafts[1]['basis'] === 'regular', 'ordinary price estimate includes Croatian VAT');
    verify($drafts[2]['estimated_price'] === '50.00' && $drafts[2]['retail_at_snapshot'] === '30.00' && $drafts[2]['basis'] === 'regular_during_promotion', 'sale records regular gross price and actual promotional gross price');
    $public = simplexml_load_string($archive->read($versions, 'xml', null)['data']);
    $publicRows = $public->xpath('//roba');
    verify((string)$publicRows[0]->referentni_datum === '' && (string)$publicRows[1]->bazna_cijena === '' && (string)$publicRows[1]->referentni_datum === '2026-10-14' &&
        (string)$publicRows[2]->bazna_cijena === '109.00' && (string)$publicRows[2]->referentni_datum === '2026-09-10', 'XML uses the addition date for later products and preserves the documented date and amount');
    $csv = $archive->read($versions, 'csv', null)['data'];
    $saleCsv = str_getcsv(explode("\n", $csv)[2], ';', '"', '');
    verify($saleCsv[1] === 'SALE' && $saleCsv[10] === '' && $saleCsv[12] === '2026-10-14', 'CSV exports the same catalogue date independently of a missing historical price');
    $early = ['date_added' => '2025-06-04 03:20:00','regime' => 'skupina2025'];
    verify(WizBasePrices::withCatalogueDate($early)['reference_date'] === '2025-06-04' &&
        empty(WizBasePrices::withCatalogueDate(['date_added' => '2099-01-01'])['reference_date']) &&
        empty(WizBasePrices::withCatalogueDate(['date_added' => '0000-00-00'])['reference_date']), 'known 2025 regime uses its own cutoff and invalid or future creation dates stay pending');
    $report = file_get_contents(DIR_DOWNLOAD . 'wiz-procjene-baznih-cijena.csv');
    verify(str_contains($report, '50.00') && str_contains($report, '30.00') && str_contains($report, 'Procjena') &&
        !str_contains($report, 'VERIFIED'), 'private draft CSV includes pending estimates and observed retail prices');

    $db->pdo->exec("UPDATE oc_product SET price='80.00' WHERE product_id=2; DELETE FROM oc_product_special");
    $model->publish($archive);
    verify($estimates->get(2) === $drafts[2], 'later price change and promotion expiry preserve first estimate, basis and time');
    $clashing = WizPriceEstimates::draft(2, 'SALE', 'Changed', 100, 100, false);
    $estimates->saveMissing([$clashing], new DateTimeImmutable('2030-01-01'));
    verify($estimates->get(2) === $drafts[2], 'competing insert for an existing product keeps the original snapshot');

    $db->pdo->exec("INSERT INTO oc_wiz_base_price(product_id,base_price,reference_date) VALUES(2,'41.25','2026-09-10')");
    $versions = $model->publish($archive);
    $public = simplexml_load_string($archive->read($versions, 'xml', null)['data']);
    verify($estimates->get(2) === null && (string)$public->roba[1]->bazna_cijena === '41.25' &&
        !str_contains(file_get_contents(DIR_DOWNLOAD . 'wiz-procjene-baznih-cijena.csv'), 'SALE'), 'manual verification supersedes draft in public export and pending list');
    verify((int)$db->pdo->query('SELECT COUNT(*) FROM oc_wiz_base_price')->fetchColumn() === 2, 'automatic estimation adds no confirmed historical records');
} finally {
    removeTestDirectory(DIR_DOWNLOAD);
}
