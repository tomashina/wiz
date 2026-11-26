<?php
// Isolated checkout-mail transport. No message leaves the test process.
namespace Mail {
    #[\AllowDynamicProperties]
    class WizCapture {
        public static $sent = [];
        public function send() { self::$sent[] = get_object_vars($this); }
    }
}
namespace {
    define('DIR_SYSTEM', dirname(__DIR__) . '/upload/system/');
    define('DIR_APPLICATION', dirname(__DIR__) . '/upload/catalog/');
    define('DB_PREFIX', 'oc_');
    define('DB_DATABASE', 'wiz_test_fixture');
    require dirname(__DIR__) . '/storagewiz/vendor/autoload.php';
    foreach (['engine/registry','engine/controller','library/config','library/mail','library/wiz_guarantee','library/wiz_price_publication','library/wiz_withdrawals'] as $part) { require_once DIR_SYSTEM . $part . '.php'; }
    require DIR_APPLICATION . 'controller/information/withdrawal.php';
    require DIR_APPLICATION . 'controller/event/wiz_prices.php';
    require dirname(__DIR__) . '/upload/urednik/controller/sale/return/withdrawal.php';

    function check($ok,$message) {
        if (!$ok) { throw new \RuntimeException($message); }
        echo "PASS " . $message . "\n";
    }

    // Only MySQL-specific statements are translated for a transaction-isolated SQLite fixture.
    class FixtureDatabase {
        public $pdo;
        private $last = 0;
        public function __construct() {
            $this->pdo = new \PDO('sqlite::memory:');
            $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $this->pdo->sqliteCreateFunction('NOW', static fn() => '2026-11-26 10:00:00');
            $this->pdo->exec("CREATE TABLE oc_product(product_id INTEGER PRIMARY KEY, model TEXT, date_added TEXT, status INTEGER DEFAULT 1, date_available TEXT DEFAULT '2026-01-01');
                INSERT INTO oc_product(product_id,model,date_added) VALUES
                (1,'TEST-1','2026-10-12 10:20:00'),(2,'TEST-2','0000-00-00 00:00:00'),(3,'TEST-3','2026-10-20 19:15:12')");
            $this->pdo->exec("CREATE TABLE oc_wiz_base_price(product_id INTEGER PRIMARY KEY,regime TEXT,first_offer TEXT,reference_date TEXT,base_price TEXT,promotion TEXT,evidence TEXT,net_quantity REAL,unit TEXT)");
            $this->pdo->exec("CREATE TABLE oc_wiz_withdrawal(withdrawal_id INTEGER PRIMARY KEY,submission TEXT UNIQUE,full_name TEXT,email TEXT,order_ref TEXT,items TEXT,submitted_at TEXT,customer_sent INTEGER DEFAULT 0,admin_sent INTEGER DEFAULT 0)");
        }
        public function escape($value) { return str_replace("'", "''", $value); }
        public function getLastId() { return $this->last; }
        public function query($sql) {
            if (str_starts_with($sql,'SHOW ') || str_starts_with($sql,'SELECT GET_LOCK') || str_starts_with($sql,'SELECT RELEASE_LOCK')) { $sql = 'SELECT 1 AS ok'; }
            if (str_ends_with($sql, ' FOR UPDATE')) { $sql = substr($sql, 0, -11); }
            if (str_starts_with($sql, 'SELECT p.product_id,p.model,d.name,p.date_added')) {
                $sql = str_replace("GROUP_CONCAT(DISTINCT cd.name ORDER BY cd.name SEPARATOR ' | ')", "GROUP_CONCAT(cd.name,' | ')", $sql);
                foreach (['s','q'] as $alias) {
                    $sql = str_replace("GROUP_CONCAT(DISTINCT CONCAT($alias.date_start,' / ',$alias.date_end) SEPARATOR ' | ')",
                        "GROUP_CONCAT($alias.date_start || ' / ' || $alias.date_end,' | ')", $sql);
                }
            }
            if ($sql === 'START TRANSACTION') { $sql = 'BEGIN'; }
            if (preg_match('/^INSERT INTO (oc_wiz_base_price|oc_wiz_withdrawal) SET (.+) ON DUPLICATE KEY UPDATE /s', $sql, $m)) {
                $update = substr($sql, strpos($sql, ' ON DUPLICATE KEY UPDATE ') + strlen(' ON DUPLICATE KEY UPDATE '));
                $assignments = str_replace('UTC_TIMESTAMP()', "'2026-11-26 07:36:10'", $m[2]);
                preg_match_all("/`?(\\w+)`?\\s*=\\s*('(?:''|[^'])*'|NULL|\\d+)/", $assignments, $parts);
                $cols = $parts[1]; $vals = $parts[2];
                $sql = "INSERT INTO " . $m[1] . " (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
                if ($m[1] === 'oc_wiz_base_price') { $sql .= ' ON CONFLICT(product_id) DO UPDATE SET ' . $update; }
                else { $sql .= ' ON CONFLICT(submission) DO NOTHING'; }
            }
            $q = $this->pdo->query($sql);
            if (str_starts_with($sql,'INSERT INTO oc_wiz_withdrawal')) { $this->last = (int)$this->pdo->lastInsertId(); }
            $rows = $q->columnCount() ? $q->fetchAll() : [];
            return (object)['row'=>$rows[0]??[], 'rows'=>$rows,'num_rows'=>count($rows)];
        }
    }
    $db = new FixtureDatabase();
    $service = new \WizBasePrices($db);
    $row = ['product_id'=>'1','model'=>'TEST-1','regime'=>'ostalo','first_offer'=>'2026-05-13','reference_date'=>'2026-09-10','base_price'=>'34,90','promotion'=>'prethodna','evidence'=>'Testni arhivski cjenik','net_quantity'=>'2','unit'=>'m'];
    $tmp = sys_get_temp_dir() . '/wiz-regression-' . bin2hex(random_bytes(6));
    mkdir($tmp);
    try {
        file_put_contents($tmp.'/import.csv', \WizBasePrices::csv(\WizBasePrices::COLUMNS, [$row]));
        $service->saveRows(\WizBasePrices::readCsv($tmp.'/import.csv'));
        check($service->getMany([1])[1]['base_price'] === '34.90','CSV import and confirmed price lookup');
        $invalid = $row; $invalid['product_id'] = '2'; $invalid['model'] = 'BAD'; $change = $row; $change['base_price']='99.00';
        try { $service->saveRows([$change,$invalid]); throw new \LogicException('Invalid CSV accepted'); } catch (\InvalidArgumentException $e) {}
        check($service->getMany([1])[1]['base_price'] === '34.90','invalid second row rolls back the whole import');
        $measure = ['product_id'=>'1','model'=>'TEST-1','net_quantity'=>'3','unit'=>'m'];
        $service->saveRows([$measure]); $verified = $service->getMany([1])[1];
        check((float)$verified['net_quantity']===3.0 && $verified['base_price']==='34.90','independent quantity update preserves historical price');
        $service->saveRows([array_replace($measure,['product_id'=>'2','model'=>'TEST-2'])]);
        check($service->getMany([2])[2]['base_price']===null,'measure can precede historical price confirmation');
        $group = array_replace($row,['regime'=>'skupina2025','first_offer'=>'2025-01-01','reference_date'=>'2025-05-02','promotion'=>'najniza30']);
        check(\WizBasePrices::validate($group)['reference_date']==='2025-05-02','regulated 2025 group and promotion');
        $later = array_replace($row,['first_offer'=>'2026-09-17','reference_date'=>'2026-09-17']);
        check(\WizBasePrices::validate($later)['reference_date']==='2026-09-17','first offer after statutory reference date');
        $registry = new \Registry(); $registry->set('db',$db);
        $event = new \ControllerEventWizPrices($registry);
        $route='product/product'; $data=['product_id'=>1,'products'=>[['product_id'=>2]]]; $event->index($route,$data);
        check($data['wiz_base_prices'][1] === 'Bazna cijena na 10.09.2026. · 34,90 €' &&
            $data['wiz_base_prices'][2] === 'Bazna cijena', 'product and card retain the base price label when only one product has a verified amount');
        $route = 'product/search'; $cards = ['products' => [['product_id' => 3]]];
        $event->index($route, $cards);
        check($cards['wiz_base_prices'][3] === 'Bazna cijena na 20.10.2026.', 'search cards retain the later addition date without an amount or status text');
        define('WIZ_PREVIEW_PRICE_ESTIMATES', true); define('HTTP_SERVER', 'https://wiz.test/');
        $db->pdo->exec("CREATE TABLE oc_product_to_store(product_id INTEGER, store_id INTEGER);
            CREATE TABLE oc_wiz_price_estimate(product_id INTEGER, model TEXT, name TEXT, estimated_price TEXT, retail_at_snapshot TEXT, basis TEXT, estimated_at TEXT);
            INSERT INTO oc_product_to_store VALUES(1,0),(3,0);
            INSERT INTO oc_wiz_price_estimate(product_id,estimated_price) VALUES(1,'50.00'),(3,'12.50')");
        $route = 'product/product'; $detail = ['product_id' => 3, 'products' => [['product_id' => 1]]];
        $event->index($route, $detail);
        check($detail['wiz_base_prices'][3] === 'Bazna cijena na 20.10.2026. · 12,50 €' &&
            $detail['wiz_base_prices'][1] === 'Bazna cijena na 10.09.2026. · 34,90 €', 'local preview uses the product addition date and preserves verified dates and amounts');
        $db->pdo->sqliteCreateFunction('CONCAT_WS', static fn($separator, ...$values) => implode($separator, array_filter($values, static fn($v) => $v !== null)), -1);
        $db->pdo->exec("CREATE TABLE oc_product_to_category(product_id INTEGER,category_id INTEGER);
            CREATE TABLE oc_category_description(category_id INTEGER,language_id INTEGER,name TEXT);
            CREATE TABLE oc_product_description(product_id INTEGER,language_id INTEGER,name TEXT);
            CREATE TABLE oc_product_special(product_id INTEGER,date_start TEXT,date_end TEXT);
            CREATE TABLE oc_product_discount(product_id INTEGER,quantity INTEGER,date_start TEXT,date_end TEXT);
            INSERT INTO oc_product_to_store VALUES(2,0);
            INSERT INTO oc_product_description VALUES(1,1,'Prvi'),(2,1,'Šalica'),(3,1,'Nosač');
            INSERT INTO oc_category_description VALUES(2,1,'Šalice'),(3,1,'Nosači');
            INSERT INTO oc_product_to_category VALUES(2,2),(3,3);
            INSERT INTO oc_product_discount VALUES(3,1,'2026-10-01','2026-10-31');
            UPDATE oc_wiz_price_estimate SET model='TEST-3' WHERE product_id=3");
        $estimateService = new \WizPriceEstimates($db);
        file_put_contents($tmp.'/batch.csv', $service->confirmationCsv(1, $estimateService));
        $proposals = \WizBasePrices::readCsv($tmp.'/batch.csv');
        check(count($proposals) === 2 && $proposals[0]['model'] === 'TEST-2' && $proposals[0]['regime'] === '' &&
            $proposals[1]['base_price'] === '12.50' && $proposals[1]['reference_date'] === '2026-10-20' &&
            $proposals[1]['promotion'] === 'prethodna' && $proposals[1]['evidence'] === '',
            'pending confirmation CSV round-trips, proposes stored prices, and exposes unresolved fields');
        try { $service->saveRows([$proposals[1]], true); throw new \LogicException('Missing evidence accepted'); }
        catch (\InvalidArgumentException $e) {}
        $reviewed = [$proposals[1], $change];
        check($service->saveRows($reviewed, true, 'WIZ arhivska evidencija') === 1 &&
            $service->getMany([3])[3]['evidence'] === 'WIZ arhivska evidencija' && $service->getMany([1])[1]['base_price'] === '34.90' &&
            $service->saveRows($reviewed, true, 'WIZ arhivska evidencija') === 0,
            'reviewed batch records its source, preserves previously confirmed rows and is repeatable');
        $pub = new \WizPricePublication($tmp);
        $rows = [['naziv'=>'Proba','sifra'=>'0123','marka'=>'WIZ','neto_kolicina'=>'2','jedinica_mjere'=>'m','maloprodajna_cijena'=>'20.00','posebni_oblik_prodaje'=>'da','cijena_za_jedinicu_mjere'=>'10.00','barkod'=>'0123','ean'=>'','bazna_cijena'=>'34.90','dostupnost'=>'Na zalihi','referentni_datum'=>'2026-09-10']];
        $fetch = function() use (&$rows) { return $rows; };
        $t = new \DateTimeImmutable('2026-11-01T09:00:00+01:00');
        $v = $pub->refresh($fetch,$t); $original=$pub->read($v,'xml',1)['data'];
        check(count($pub->refresh($fetch,$t))===1, 'unchanged same-day export preserves version');
        $rows[0]['maloprodajna_cijena']='18.00'; $v=$pub->refresh($fetch,$t->modify('+1 hour'));
        check(count($v)===2 && $v[0]['replaced']===$t->modify('+1 hour')->getTimestamp() && $pub->read($v,'xml',1)['data']===$original,'same-day correction preserves previous bytes and replacement time');
        $v=$pub->refresh($fetch,$t->modify('+1 day')); check(count($v)===3,'new daily edition without a price change');
        $v=$pub->refresh($fetch,$t->modify('+30 days')); check($pub->read($v,'csv',1)!==false,'archive kept 30 days from replacement');
        $v=$pub->refresh($fetch,$t->modify('+32 days')); check($pub->read($v,'csv',1)===false,'expired archive removed with both formats');
        $cfg = new \Config(); $cfg->set('config_mail_engine','WizCapture'); $cfg->set('config_email','admin@example.invalid'); $cfg->set('config_name','WIZ test');
        $registry->set('config',$cfg); $registry->set('session',(object)['data'=>['user_token'=>'test']]);
        $request = (object)['get'=>[], 'post'=>[], 'server'=>['REQUEST_METHOD'=>'GET']]; $registry->set('request',$request);
        $registry->set('url',new class { public function link($r,$p='',$ssl=true){return 'https://wiz.test/index.php?route='.$r.($p?'&'.$p:'');} });
        $registry->set('document',new class {public function setTitle($t){}});
        $registry->set('response',new class {public $out,$url; public function setOutput($s){$this->out=$s;} public function addHeader($s){} public function redirect($s){$this->url=$s;}});
        $registry->set('load',new class {public $data; public function controller($r){return '';} public function view($r,$d){$this->data=$d;return $r;}});
        $ctl=new \ControllerInformationWithdrawal($registry); $ctl->index();
        $nonce=$registry->get('load')->data['nonce'];
        $request->server['REQUEST_METHOD']='POST'; $request->post=['nonce'=>$nonce,'full_name'=>'Test Kupac','email'=>'guest@example.invalid','order_ref'=>'TEST-9','items'=>'1 × TEST-1'];
        $ctl->index(); check($registry->get('load')->data['stage']==='review','guest withdrawal review with a partial return');
        $request->post=['nonce'=>$nonce,'confirm'=>'1']; $ctl->index();
        check(count(\Mail\WizCapture::$sent)===2 && array_column(\Mail\WizCapture::$sent,'to')===['guest@example.invalid','admin@example.invalid'],'confirmation sends separately to customer and administrator');
        $receipt=\Mail\WizCapture::$sent[0]['text'];
        check(strpos($receipt,'26.11.2026. 08:36:10 CET')!==false && strpos($receipt,'1 × TEST-1')!==false && strpos($receipt,'TEST-9')!==false,'receipt includes local time and complete statement');
        $service2=new \WizWithdrawals($registry); $service2->notify(1); $ctl->index();
        check(count(\Mail\WizCapture::$sent)===2 && count($service2->listing())===1,'retry and browser resubmit do not duplicate a receipt');
        $request->server['REQUEST_METHOD']='GET'; $request->get=['export'=>'csv'];
        (new \ControllerSaleReturnWithdrawal($registry))->index();
        check(strpos($registry->get('response')->out,'guest@example.invalid')!==false && strpos($registry->get('response')->out,'TEST-9')!==false,'admin CSV exports saved request');
        $twig=new \Twig\Environment(new \Twig\Loader\FilesystemLoader(dirname(__DIR__).'/upload/catalog/view/theme/default/template'),['autoescape'=>false]);
        $html=$twig->render('mail/order_add.twig',['wiz_guarantee'=>\WizGuarantee::html('https://wiz.test/')]);
        $path=dirname(DIR_SYSTEM).'/'.\WizGuarantee::IMAGE;
        $mail=new \Mail('WizCapture');$mail->setTo('order@example.invalid');$mail->setFrom('shop@example.invalid');$mail->setSender('WIZ');$mail->setSubject('Testna narudžba');$mail->setHtml($html);$mail->addAttachment($path);$mail->send();
        $last=end(\Mail\WizCapture::$sent);
        check(strpos($last['html'],\WizGuarantee::TITLE)!==false && strpos($last['html'],\WizGuarantee::EU)!==false && strpos($last['html'],'information/withdrawal')!==false && is_file($last['attachments'][0]),'order confirmation contains separate links and official colour attachment');
        check(hash_file('sha256',str_replace('.png','.svg',$path))==='a22432ff287ae772fd7f1bc7198dce26cbcfba3b1c8c6fa207189c1163e2612e','official Croatian poster matches original SVG');
    } finally {
        foreach (glob($tmp.'/*') as $f) { unlink($f); }
        rmdir($tmp);
    }
}
