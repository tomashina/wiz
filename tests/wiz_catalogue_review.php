<?php
// Exercise the catalogue review actions with no storefront database writes.
define('DB_PREFIX', 'oc_');
require dirname(__DIR__) . '/upload/system/library/wiz_price_estimates.php';
require dirname(__DIR__) . '/storagewiz/vendor/autoload.php';

class ReviewCatalogueDb {
    public $rows;
    public function escape($value) { return $value; }
    public function query($sql) {
        $rows = strpos($sql, 'SELECT p.product_id,p.model,d.name,p.date_added') === 0 ? $this->rows : [['ok' => 1]];
        return (object)['rows' => $rows, 'num_rows' => count($rows), 'row' => $rows[0] ?? []];
    }
}
class ReviewEstimates extends WizPriceEstimates {
    public $amount = '89.99';
    public function getMany(array $ids) {
        return [1 => ['model'=>'MONITOR','estimated_price'=>'99.90'], 2 => ['model'=>'SALICA','estimated_price'=>$this->amount]];
    }
}
class ReviewPrices extends WizBasePrices {
    public $saved = [];
    public function saveRows(array $rows, $pendingOnly = false, $commonEvidence = '') {
        if (!$pendingOnly) { throw new LogicException('Expected pending-only save'); }
        foreach ($rows as $row) {
            $row['evidence'] = $commonEvidence;
            $this->saved[] = self::validate($row);
        }
        return count($rows);
    }
}
function expectReview($ok, $message) {
    if (!$ok) { throw new RuntimeException($message); }
    echo 'PASS ', $message, PHP_EOL;
}
function rejectedReview($action) {
    try { $action(); } catch (InvalidArgumentException $e) { return true; }
    return false;
}
$db = new ReviewCatalogueDb();
$item = ['regime'=>null, 'first_offer'=>null, 'reference_date'=>null, 'promotion'=>null, 'date_added'=>'2024-10-02 12:30:00',
    'product_id'=>1, 'model'=>'MONITOR','name'=>'Test monitor', 'categories'=>'Zidni nosači za TV','standard_category'=>1, 'specials'=>''];
$db->rows = [$item, array_replace($item, ['product_id'=>2,'model'=>'SALICA','name'=>'Testna šalica','categories'=>'Licencirane šalice',
    'standard_category'=>0, 'specials'=>'2025-05-01 / 2025-05-03'])];
$estimates = new ReviewEstimates($db);
$prices = new ReviewPrices($db);
$initial = $prices->confirmationReview(1, $estimates);
expectReview(count($initial['ready']) === 1 && strpos($initial['rows'][1]['issue'], 'skupina robe') !== false, 'ambiguous group stays visible and unresolved');
$key = array_key_first($initial['groups']);
$classifications = [$key=>'skupina2025'];
$review = $prices->confirmationReview(1, $estimates, $classifications);
expectReview(count($review['ready']) === 2 && $review['ready'][1]['reference_date'] === '2025-05-02' &&
    $review['ready'][1]['promotion'] === 'najniza30', 'explicit category choice recalculates the date and sale basis');
expectReview(rejectedReview(fn() => $prices->saveReviewedBatch($review, $initial['digest'], 'WIZ cjenik', true)) &&
    rejectedReview(fn() => $prices->saveReviewedBatch($review, $review['digest'], '', true)) &&
    rejectedReview(fn() => $prices->saveReviewedBatch($review, $review['digest'], 'WIZ cjenik', false)), 'changed scope, absent evidence or acknowledgement rejects the batch');
$estimates->amount = '90.00';
$changed = $prices->confirmationReview(1, $estimates, $classifications);
expectReview(rejectedReview(fn() => $prices->saveReviewedBatch($changed, $review['digest'], 'WIZ cjenik', true)) && !$prices->saved,
    'a changed price after review cannot be confirmed');
expectReview($prices->saveReviewedBatch($changed, $changed['digest'], 'WIZ cjenik', true) === 2 &&
    $prices->saved[1]['base_price'] === '90.00' && $prices->saved[1]['evidence'] === 'WIZ cjenik', 'exact reviewed prices carry the merchant source');
$twig = new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/upload/urednik/view/template'));
$page = $twig->render('catalog/base_price_review.twig', ['url'=>'index.php?route=catalog/product/base_price','review'=>$review, 'can_modify'=>true]);
expectReview(strpos($page, 'Potvrdi 2 baznih cijena') !== false && strpos($page,'89,99 €') !== false && strpos($page, 'SALICA') !== false &&
    strpos($page, $review['digest']) !== false && strpos($page, 'name="reviewed"') !== false, 'review screen includes every SKU, bound digest and intentional confirmation');
