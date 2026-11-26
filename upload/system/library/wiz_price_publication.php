<?php
require_once __DIR__ . '/wiz_base_prices.php';

class WizPricePublication {
    const MERCHANT = 'Wiz store d.o.o.';
    const OIB = '20584673149';
    const ADDRESS = 'Bribirska 13, 10000 Zagreb';
    const LOCATION = 'INTERNET_TRGOVINA_BRIBIRSKA_13_ZAGREB_WEB';
    const HEADERS = ['Naziv robe', 'Šifra robe', 'Marka robe', 'Neto količina', 'Jedinica mjere', 'Maloprodajna cijena (EUR)', 'Poseban oblik prodaje', 'Cijena za jedinicu mjere (EUR)', 'Barkod', 'EAN barkod', 'Bazna cijena (EUR)', 'Dostupnost', 'Referentni datum'];
    private $dir;

    public function __construct($dir) {
        $this->dir = rtrim($dir, '/') . '/';
        if (!is_dir($this->dir) && !mkdir($this->dir, 0750, true) && !is_dir($this->dir)) { throw new RuntimeException('Nije moguće otvoriti arhivu cjenika.'); }
    }

    private function write($path, $bytes) {
        $tmp = tempnam($this->dir, 'tmp-');
        try {
            if (!$tmp || file_put_contents($tmp, $bytes) !== strlen($bytes) || !rename($tmp, $this->dir . $path)) { throw new RuntimeException('Zapis cjenika nije uspio.'); }
        } finally {
            if ($tmp && is_file($tmp)) { unlink($tmp); }
        }
    }

    private static function xml($rows, $stamp) {
        $w = new XMLWriter();
        $w->openMemory();
        $w->startDocument('1.0','UTF-8');
        $w->startElement('cjenik');
        foreach (['trgovac'=>self::MERCHANT,'oib'=>self::OIB,'adresa'=>self::ADDRESS,'prodajno_mjesto'=>'WIZ web','valuta'=>'EUR','objavljeno'=>$stamp] as $k=>$v) { $w->writeAttribute($k, $v); }
        foreach ($rows as $row) {
            $w->startElement('roba');
            foreach ($row as $key=>$value) { $w->writeElement($key, (string)$value); }
            $w->endElement();
        }
        $w->endElement();
        $w->endDocument();
        return $w->outputMemory();
    }

    // Serialize reading the database and publishing. A slower request cannot replace a newer snapshot.
    public function refresh(callable $fetch, ?DateTimeImmutable $now = null, ?callable $audit = null) {
        $lock = fopen($this->dir . 'publish.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Arhiva cjenika je nedostupna.'); }
        try {
            $now = ($now ?: new DateTimeImmutable())->setTimezone(new DateTimeZone('Europe/Zagreb'));
            $path = $this->dir . 'index.json';
            $index = is_file($path) ? json_decode(file_get_contents($path), true) : [];
            if (!is_array($index)) { throw new RuntimeException('Oštećen indeks arhive cjenika.'); }
            $rows = $fetch();
            $csv = WizBasePrices::csv(self::HEADERS, $rows);
            $digest = hash('sha256', $csv);
            $last = $index ? end($index) : [];
            if (!$last || $last['date'] !== $now->format('Y-m-d') || $last['digest'] !== $digest) {
                $seq = ($last['sequence'] ?? 0) + 1;
                $file = self::OIB . '_' . self::LOCATION . '_ROBA_' . sprintf('%06d', $seq) . '_' . $now->format('Ymd_Hi');
                $this->write($file . '.csv', $csv);
                $this->write($file . '.xml', self::xml($rows, $now->format(DATE_ATOM)));
                if ($last) { $index[count($index)-1]['replaced'] = $now->getTimestamp(); }
                $index[] = ['sequence'=>$seq, 'file'=>$file, 'date'=>$now->format('Y-m-d'), 'published'=>$now->format(DATE_ATOM), 'digest'=>$digest, 'replaced'=>null, 'products'=>count($rows)];
                $expired = [];
                $keep = [];
                foreach ($index as $entry) {
                    if ($entry['replaced'] !== null && $entry['replaced'] < $now->modify('-30 days')->getTimestamp()) { $expired[] = $entry['file']; }
                    else { $keep[] = $entry; }
                }
                $index = $keep;
                $this->write('index.json', json_encode($index, JSON_THROW_ON_ERROR));
                foreach ($expired as $old) {
                    foreach (['csv','xml','provjera.csv'] as $format) { if (is_file($this->dir . $old . '.' . $format)) { unlink($this->dir . $old . '.' . $format); } }
                }
            }
            $current = end($index);
            $report = $this->dir . $current['file'] . '.provjera.csv';
            if ($audit && (!is_file($report) || filemtime($report) < filemtime(__DIR__ . '/wiz_base_prices.php'))) {
                $this->write($current['file'] . '.provjera.csv', $audit());
            }
            $index[count($index)-1]['missing_base'] = count(array_filter($rows, static function ($row) { return $row['bazna_cijena'] === ''; }));
            return $index;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function read(array $index, $format, $sequence) {
        if (!in_array($format, ['csv','xml'], true)) { return false; }
        $entry = end($index);
        if ($sequence !== null) {
            $entries = array_column($index, null, 'sequence');
            $entry = $entries[$sequence] ?? null;
        }
        if (!$entry) { return false; }
        $file = $entry['file'] . '.' . $format;
        $data = file_get_contents($this->dir . $file);
        if ($data === false) { throw new RuntimeException('Datoteka cjenika nije dostupna.'); }
        return ['file'=>$file, 'data'=>$data];
    }
}
