<?php
/**
 * Focused integration checks without a running storefront or production database.
 * Run: php tests/seo_regression.php
 * Also verify an existing HuntBee modification cache:
 *      php tests/seo_regression.php --cached-rewrite
 */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$root = dirname(__DIR__);
define('DB_PREFIX', 'oc_');
require $root . '/upload/system/engine/registry.php';
require $root . '/upload/system/engine/controller.php';
require $root . '/upload/system/engine/model.php';
require $root . '/upload/system/library/config.php';
require $root . '/upload/system/library/request.php';
require $root . '/upload/system/library/url.php';
require $root . '/upload/system/helper/utf8.php';
$rewrite_file = in_array('--cached-rewrite', $argv, true)
    ? '/storagewiz/modification/catalog/controller/startup/seo_url.php'
    : '/upload/catalog/controller/startup/seo_url.php';
if (!is_file($root . $rewrite_file)) {
    fwrite(STDERR, 'SEO rewrite cache does not exist: ' . $rewrite_file . PHP_EOL);
    exit(1);
}
require $root . $rewrite_file;
require $root . '/upload/catalog/controller/startup/product_canonical.php';
require $root . '/upload/catalog/controller/extension/feed/google_sitemap.php';
require $root . '/upload/catalog/model/extension/feed/google_sitemap.php';

final class SeoTestDatabase {
    public $pdo;

    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('NOW', function () { return '2026-10-27 12:00:00'; });
        $this->pdo->exec('CREATE TABLE oc_seo_url (seo_url_id INTEGER PRIMARY KEY, store_id INTEGER, language_id INTEGER, query TEXT, keyword TEXT)');
        $statement = $this->pdo->prepare('INSERT INTO oc_seo_url (store_id, language_id, query, keyword) VALUES (0, 1, ?, ?)');
        foreach (array(
            'product_id=42' => 'keyboard',
            'product_id=43' => 'disabled-product',
            'category_id=7' => 'gaming',
            'category_id=8' => 'mechanical',
            'manufacturer_id=3' => 'white-shark'
        ) as $query => $keyword) {
            $statement->execute(array($query, $keyword));
        }
    }

    public function query($sql) {
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return (object)array('rows' => $rows, 'row' => $rows ? $rows[0] : array(), 'num_rows' => count($rows));
    }

    public function escape($value) {
        return str_replace("'", "''", (string)$value);
    }
}

final class SeoTestRedirect extends RuntimeException {
    public $url;
    public $status;

    public function __construct($url, $status) {
        parent::__construct('Redirect');
        $this->url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
        $this->status = $status;
    }
}

final class SeoTestResponse {
    public $headers = array();
    public $output = '';

    public function redirect($url, $status = 302) { throw new SeoTestRedirect($url, $status); }
    public function addHeader($header) { $this->headers[] = $header; }
    public function setOutput($output) { $this->output = $output; }
}

final class SeoTestProducts {
    public function getProduct($id) {
        return in_array($id, array(42, 43, 999), true)
            ? array('product_id' => $id, 'status' => $id === 43 ? 0 : 1)
            : false;
    }
}

final class SeoTestLoader {
    private $registry;

    public function __construct($registry) { $this->registry = $registry; }

    public function model($route) {
        if ($route === 'catalog/product') {
            $this->registry->set('model_catalog_product', new SeoTestProducts());
        } elseif ($route === 'extension/feed/google_sitemap') {
            $this->registry->set('model_extension_feed_google_sitemap', new ModelExtensionFeedGoogleSitemap($this->registry));
        } else {
            throw new RuntimeException('Unexpected model dependency: ' . $route);
        }
    }
}

function fixture($get = array(), $uri = '/', $method = 'GET', $ssl = 'https://www.wiz.test/') {
    $registry = new Registry();
    $config = new Config();
    foreach (array(
        'config_seo_url' => true,
        'config_store_id' => 0,
        'config_language_id' => 1,
        'config_url' => 'http://www.wiz.test/',
        'config_ssl' => $ssl,
        'feed_google_sitemap_status' => false,
        'hb_seourl_simplified' => false
    ) as $key => $value) {
        $config->set($key, $value);
    }
    $registry->set('config', $config);
    $registry->set('db', new SeoTestDatabase());
    $registry->set('url', new Url('http://www.wiz.test/', $ssl));
    $request = new Request();
    $request->get = $request->clean($get);
    $request->post = array();
    $request->server = $request->clean(array(
        'REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'HTTPS' => 'on', 'HTTP_HOST' => 'www.wiz.test'
    ));
    $registry->set('request', $request);
    $registry->set('response', new SeoTestResponse());
    $registry->set('load', new SeoTestLoader($registry));
    (new ControllerStartupSeoUrl($registry))->index();
    return $registry;
}

function same($expected, $actual, $message = '') {
    if ($expected !== $actual) {
        throw new RuntimeException($message . PHP_EOL . 'Expected: ' . var_export($expected, true) . PHP_EOL . 'Actual: ' . var_export($actual, true));
    }
}

function check($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function canonicalRequest($uri, $method = 'GET', $overrides = array()) {
    $parts = parse_url($uri);
    $get = array();
    if (isset($parts['query'])) {
        parse_str($parts['query'], $get);
    }
    if ($parts['path'] !== '/index.php') {
        $get['_route_'] = ltrim($parts['path'], '/');
    }
    $registry = fixture(array_merge($get, $overrides), $uri, $method);
    try {
        (new ControllerStartupProductCanonical($registry))->index();
    } catch (SeoTestRedirect $redirect) {
        return $redirect;
    }
    return null;
}

function seedSitemap($registry) {
    $db = $registry->get('db')->pdo;
    $db->exec('CREATE TABLE oc_product (product_id INTEGER, status INTEGER, quantity INTEGER, date_available TEXT, date_modified TEXT);
        CREATE TABLE oc_product_description (product_id INTEGER, language_id INTEGER);
        CREATE TABLE oc_product_to_store (product_id INTEGER, store_id INTEGER);
        CREATE TABLE oc_category (category_id INTEGER, status INTEGER, date_modified TEXT);
        CREATE TABLE oc_category_description (category_id INTEGER, language_id INTEGER);
        CREATE TABLE oc_category_to_store (category_id INTEGER, store_id INTEGER);
        CREATE TABLE oc_manufacturer (manufacturer_id INTEGER);
        CREATE TABLE oc_manufacturer_to_store (manufacturer_id INTEGER, store_id INTEGER);
        CREATE TABLE oc_information (information_id INTEGER, status INTEGER);
        CREATE TABLE oc_information_description (information_id INTEGER, language_id INTEGER);
        CREATE TABLE oc_information_to_store (information_id INTEGER, store_id INTEGER)');
    $db->beginTransaction();
    $product = $db->prepare('INSERT INTO oc_product VALUES (?, ?, ?, ?, ?)');
    $description = $db->prepare('INSERT INTO oc_product_description VALUES (?, ?)');
    $store = $db->prepare('INSERT INTO oc_product_to_store VALUES (?, ?)');
    for ($id = 1; $id <= 1009; $id++) {
        $modified = array(2 => '0000-00-00 00:00:00', 3 => '2026-02-30 12:00:00', 4 => '2999-01-01 00:00:00');
        $product->execute(array($id, $id === 1006 ? 0 : 1, $id === 1005 ? 0 : 10,
            $id === 1007 ? '2999-01-01 00:00:00' : '2020-01-01 00:00:00',
            isset($modified[$id]) ? $modified[$id] : '2020-03-04 12:00:00'));
        $description->execute(array($id, $id === 1009 ? 2 : 1));
        $store->execute(array($id, $id === 1008 ? 1 : 0));
    }
    $db->exec("INSERT INTO oc_category VALUES (7,1,'2020-03-04 12:00:00'),(8,1,'2020-03-04 12:00:00'),(9,0,'2020-03-04 12:00:00'),(10,1,'2020-03-04 12:00:00'),(11,1,'2020-03-04 12:00:00');
        INSERT INTO oc_category_description VALUES (7,1),(8,1),(9,1),(10,1),(11,2);
        INSERT INTO oc_category_to_store VALUES (7,0),(8,0),(9,0),(10,1),(11,0);
        INSERT INTO oc_manufacturer VALUES (3),(4);
        INSERT INTO oc_manufacturer_to_store VALUES (3,0),(4,1);
        INSERT INTO oc_information VALUES (5,1),(6,0),(7,1),(8,1);
        INSERT INTO oc_information_description VALUES (5,1),(6,1),(7,1),(8,2);
        INSERT INTO oc_information_to_store VALUES (5,0),(6,0),(7,1),(8,0)");
    $db->commit();
}

function sitemap($registry, $method, $page = null) {
    $registry->get('request')->get = $page === null ? array() : array('page' => $page);
    $registry->set('response', new SeoTestResponse());
    (new ControllerExtensionFeedGoogleSitemap($registry))->{$method}();
    $response = $registry->get('response');
    check(in_array('Content-Type: application/xml; charset=UTF-8', $response->headers, true), 'Missing XML content type');
    $document = new DOMDocument();
    check($document->loadXML($response->output), 'Invalid sitemap XML');
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    return array($response, $xpath);
}

function nodeValues($nodes) {
    $values = array();
    foreach ($nodes as $node) {
        $values[] = $node->textContent;
    }
    return $values;
}

$tests = array();
$tests['product links from category and manufacturer contexts use the short HTTPS URL'] = function () {
    $url = fixture()->get('url');
    same('https://www.wiz.test/keyboard', $url->link('product/product', 'path=7_8&product_id=42'));
    same('https://www.wiz.test/keyboard', $url->link('product/product', array('manufacturer_id' => 3, 'product_id' => 42)));
};
$tests['product search, pagination and filter context is removed for string and array arguments'] = function () {
    $args = array(
        'product_id' => 42, 'path' => '7_8', 'manufacturer_id' => 3, 'search' => 'keyboard',
        'tag' => 'red', 'description' => 1, 'category_id' => 7, 'sub_category' => 1,
        'sort' => 'p.price', 'order' => 'DESC', 'page' => 2, 'limit' => 100,
        'filter' => '12', 'filter_name' => 'keyboard', 'filter_sub_category' => 1, 'filter_description' => 1
    );
    $url = fixture()->get('url');
    same('https://www.wiz.test/keyboard', $url->link('product/product', $args));
    same('https://www.wiz.test/keyboard', $url->link('product/product', http_build_query($args, '', '&amp;')));
};
$tests['campaign and product-specific parameters survive without double HTML escaping'] = function () {
    $link = fixture()->get('url')->link('product/product', 'product_id=42&amp;path=7&amp;utm_source=newsletter&amp;gclid=A%26B&amp;quantity=2');
    same('https://www.wiz.test/keyboard?utm_source=newsletter&amp;gclid=A%26B&amp;quantity=2', $link);
    check(strpos($link, '&amp;amp;') === false, 'A query separator was escaped twice');
};
$tests['category and manufacturer routes retain their context and existing security behavior'] = function () {
    $url = fixture()->get('url');
    same('http://www.wiz.test/gaming/mechanical?sort=p.price&amp;page=2', $url->link('product/category', 'path=7_8&sort=p.price&page=2'));
    same('http://www.wiz.test/white-shark?page=2', $url->link('product/manufacturer/info', 'manufacturer_id=3&page=2'));
    // Check action arguments before rewriting, whose legacy category behavior is unrelated.
    $plain = new Url('http://www.wiz.test/', 'https://www.wiz.test/');
    same('http://www.wiz.test/index.php?route=product/product/write&amp;product_id=42&amp;path=7', $plain->link('product/product/write', 'product_id=42&path=7'));
};
$tests['products without an SEO keyword use a canonical index.php URL'] = function () {
    same('https://www.wiz.test/index.php?route=product/product&amp;product_id=999', fixture()->get('url')->link('product/product', 'path=7&product_id=999'));
};
$tests['stores without SSL still generate usable product links'] = function () {
    same('http://www.wiz.test/keyboard', fixture(array(), '/', 'GET', '')->get('url')->link('product/product', 'path=7&product_id=42'));
};
$tests['category and manufacturer product aliases redirect once with HTTP 301'] = function () {
    foreach (array('/gaming/keyboard', '/gaming/mechanical/keyboard', '/white-shark/keyboard', '/keyboard/') as $uri) {
        $redirect = canonicalRequest($uri);
        check($redirect instanceof SeoTestRedirect, 'No redirect for ' . $uri);
        same(301, $redirect->status, $uri);
        same('https://www.wiz.test/keyboard', $redirect->url, $uri);
        same(null, canonicalRequest(parse_url($redirect->url, PHP_URL_PATH)), 'The redirect target must not redirect again');
    }
};
$tests['legacy query product URLs redirect to the same short address'] = function () {
    $redirect = canonicalRequest('/index.php?route=product/product&product_id=42&path=7&search=red&page=2');
    check($redirect instanceof SeoTestRedirect, 'No redirect for legacy query URL');
    same(301, $redirect->status);
    same('https://www.wiz.test/keyboard', $redirect->url);
};
$tests['canonical and tracking URLs do not loop; alias redirects retain campaign parameters'] = function () {
    same(null, canonicalRequest('/keyboard'));
    same(null, canonicalRequest('/keyboard?utm_source=newsletter&gclid=A%26B'));
    $redirect = canonicalRequest('/gaming/keyboard?utm_source=newsletter&gclid=A%26B');
    same('https://www.wiz.test/keyboard?utm_source=newsletter&gclid=A%26B', $redirect->url);
    same(null, canonicalRequest('/keyboard?utm_source=newsletter&gclid=A%26B'));
};
$tests['HEAD redirects while POST, action routes and missing or disabled products do not'] = function () {
    same(301, canonicalRequest('/gaming/keyboard', 'HEAD')->status);
    same(null, canonicalRequest('/gaming/keyboard', 'POST'));
    same(null, canonicalRequest('/gaming/keyboard', 'GET', array('route' => 'product/product/write')));
    same(null, canonicalRequest('/gaming/keyboard', 'GET', array('route' => 'product/product/review')));
    same(null, canonicalRequest('/gaming/disabled-product'));
    same(null, canonicalRequest('/index.php?route=product/product&product_id=100000'));
    same(null, canonicalRequest('/unknown-product'));
};
$tests['missing-keyword fallback is stable and strips listing context'] = function () {
    same(null, canonicalRequest('/index.php?route=product/product&product_id=999'));
    $redirect = canonicalRequest('/index.php?route=product/product&product_id=999&path=7');
    same('https://www.wiz.test/index.php?route=product/product&product_id=999', $redirect->url);
};
$tests['Request cleaning preserves tracking ampersands and literal HTML entity text across redirects'] = function () {
    $query = 'utm_source=newsletter&gclid=A%26B&campaign=%26amp%3Bspring';
    $redirect = canonicalRequest('/gaming/keyboard?' . $query);
    same('https://www.wiz.test/keyboard?' . $query, $redirect->url);
    same(null, canonicalRequest('/keyboard?' . $query));
};
$tests['sitemap index is available with the legacy feed disabled and lists all chunks'] = function () {
    $registry = fixture();
    seedSitemap($registry);
    list($response, $xpath) = sitemap($registry, 'index');
    same(array(
        'https://www.wiz.test/sitemap-products-1.xml',
        'https://www.wiz.test/sitemap-products-2.xml',
        'https://www.wiz.test/sitemap-categories-1.xml',
        'https://www.wiz.test/sitemap-manufacturers-1.xml',
        'https://www.wiz.test/sitemap-information-1.xml'
    ), nodeValues($xpath->query('/s:sitemapindex/s:sitemap/s:loc')));
};
$tests['product sitemap covers more than 1000 products without duplicates and includes zero stock'] = function () {
    $registry = fixture();
    seedSitemap($registry);
    list($first, $first_xpath) = sitemap($registry, 'products', 1);
    list($second, $second_xpath) = sitemap($registry, 'products', 2);
    $first_urls = nodeValues($first_xpath->query('/s:urlset/s:url/s:loc'));
    $second_urls = nodeValues($second_xpath->query('/s:urlset/s:url/s:loc'));
    same(1000, count($first_urls));
    same(5, count($second_urls));
    $urls = array_merge($first_urls, $second_urls);
    same(1005, count(array_unique($urls)), 'Every visible product must occur exactly once');
    check(in_array('https://www.wiz.test/keyboard', $urls, true), 'Product SEO keyword was not used');
    check(in_array('https://www.wiz.test/index.php?route=product/product&product_id=1005', $urls, true), 'Enabled out-of-stock product was omitted');
    foreach (range(1006, 1009) as $excluded) {
        check(!in_array('https://www.wiz.test/index.php?route=product/product&product_id=' . $excluded, $urls, true), 'Excluded product ' . $excluded . ' leaked into sitemap');
    }
    check(strpos($first->output, '&amp;product_id=1</loc>') !== false, 'Fallback query URL lacks an XML-escaped separator');
    check(strpos($first->output, '&amp;amp;') === false, 'Sitemap URL was escaped twice');
};
$tests['sitemap dates omit zero, invalid and future dates'] = function () {
    $registry = fixture();
    seedSitemap($registry);
    list($response, $xpath) = sitemap($registry, 'products', 1);
    same('2020-03-04', $xpath->evaluate('string(/s:urlset/s:url[1]/s:lastmod)'));
    foreach (array(2, 3, 4) as $position) {
        same(0, $xpath->query('/s:urlset/s:url[' . $position . ']/s:lastmod')->length);
    }
};
$tests['category and other sitemaps contain visible canonical entity URLs'] = function () {
    $registry = fixture();
    seedSitemap($registry);
    list($response, $xpath) = sitemap($registry, 'categories', 1);
    same(array('https://www.wiz.test/gaming', 'https://www.wiz.test/mechanical'), nodeValues($xpath->query('/s:urlset/s:url/s:loc')));
    list($response, $xpath) = sitemap($registry, 'manufacturers', 1);
    same(array('https://www.wiz.test/white-shark'), nodeValues($xpath->query('/s:urlset/s:url/s:loc')));
    list($response, $xpath) = sitemap($registry, 'information', 1);
    same(array('https://www.wiz.test/index.php?route=information/information&information_id=5'), nodeValues($xpath->query('/s:urlset/s:url/s:loc')));
};
$tests['invalid and empty sitemap pages return XML with HTTP 404'] = function () {
    $registry = fixture();
    seedSitemap($registry);
    foreach (array(0, -1, '01', 'abc', array('1'), 3) as $page) {
        list($response, $xpath) = sitemap($registry, 'products', $page);
        check(in_array('HTTP/1.1 404 Not Found', $response->headers, true), 'Expected 404 for ' . var_export($page, true));
        same(0, $xpath->query('/s:urlset/s:url')->length);
    }
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo 'PASS ' . $name . PHP_EOL;
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL);
    }
}
echo count($tests) . ' tests, ' . $failures . ' failures (' . $rewrite_file . ')' . PHP_EOL;
exit($failures ? 1 : 0);
