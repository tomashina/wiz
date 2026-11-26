<?php
// Run as the same hosting user as the OpenCart storefront.
if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__) . '/upload/config.php';
require DIR_SYSTEM . 'startup.php';
require DIR_APPLICATION . 'model/extension/feed/wiz_prices.php';

try {
    $registry = new Registry();
    $registry->set('db', new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT));
    $registry->set('config', new Config());
    $model = new ModelExtensionFeedWizPrices($registry);
    $versions = $model->publish(new WizPricePublication(DIR_DOWNLOAD . 'wiz-cjenik'));
    $current = end($versions);
    fwrite(STDOUT, $current['file'] . ' (' . $current['products'] . " artikala)\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Objava WIZ cjenika nije uspjela. Provjerite postavke baze i prava na privatnu arhivu.' . PHP_EOL);
    exit(1);
}
