<?php
require_once __DIR__ . '/../upload/system/library/wiz_base_prices.php';

$path = tempnam(sys_get_temp_dir(), 'wiz-csv-');
$product = array_combine(WizBasePrices::COLUMNS, [
    '12264', 'PM-200XL', 'ostalo', '2026-10-01', '2026-10-01', '99,90',
    'redovna', "WIZ cjenik, izdanje \"listopad\"; cijene s PDV-om\nDopuna", '', ''
]);

function expectCsvError($path, $bytes, $fragment) {
    file_put_contents($path, $bytes);
    try {
        WizBasePrices::readCsv($path);
    } catch (InvalidArgumentException $error) {
        if (strpos($error->getMessage(), $fragment) !== false) { return; }
        throw $error;
    }
    throw new RuntimeException('Neispravna datoteka prihvaćena: ' . $fragment);
}

try {
    foreach ([';', ',', "\t"] as $delimiter) {
        foreach ([false, true] as $withTitle) {
            $file = fopen($path, 'wb');
            if ($withTitle) {
                fwrite($file, "\xEF\xBB\xBF\r\n");
                fputcsv($file, ['WIZ, pregled cijena', '', ''], $delimiter, '"', '');
                fwrite($file, "\r\n");
            }
            fputcsv($file, WizBasePrices::COLUMNS, $delimiter, '"', '');
            fputcsv($file, array_values($product), $delimiter, '"', '');
            fputcsv($file, array_fill(0, count(WizBasePrices::COLUMNS), ''), $delimiter, '"', '');
            fclose($file);
            if (WizBasePrices::readCsv($path) !== [$product]) {
                throw new RuntimeException('Neočekivani sadržaj uvezene tablice.');
            }
        }
    }
    $header = implode(',', WizBasePrices::COLUMNS) . "\n";
    expectCsvError($path, "krivo,zaglavlje\n" . $header, 'zaglavlje');
    expectCsvError($path, str_replace('base_price', 'price', $header), 'zaglavlje');
    expectCsvError($path, "Izvoz\n\n" . $header . "12264;PM-200XL;ostalo\n", 'retku 4');
    expectCsvError($path, $header . $header, 'jednim zaglavljem');
    expectCsvError($path, $header . "\n", 'nema podataka');
    echo "PASS CSV uvoz (tri razdjelnika, opcionalni naslov, navodnici, prijelomi redaka i neispravne tablice)\n";
} finally {
    unlink($path);
}
