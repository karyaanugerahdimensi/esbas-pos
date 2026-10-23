<?php
/**
 * Test: Deduplikasi Produk POS & Sinkronisasi Menu Aktif Cabang
 */

require 'db.php';

echo "============================================================\n";
echo "TEST: Deduplikasi Produk POS & Keselarasan Menu Aktif Cabang\n";
echo "============================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($cond, $msg) {
    global $passCount, $failCount;
    if ($cond) {
        echo "[PASS] $msg\n";
        $passCount++;
    } else {
        echo "[FAIL] $msg\n";
        $failCount++;
    }
}

// 1. Uji setiap cabang via bootstrap API
$expectedActiveCounts = [
    1 => 8, // Pusat Cimahi: Semua 8 produk jadi aktif
    2 => 5, // Cabang Ciganitri: 5 produk aktif (100, 101, 102 nonaktif)
    3 => 3, // Cabang Buahbatu: 3 produk aktif (98, 99, 103)
    6 => 3  // Cabang Cimahi 1: 3 produk aktif (98, 104, 106)
];

$lastData = null;
foreach ($expectedActiveCounts as $bId => $expectedCount) {
    $_GET['action'] = 'bootstrap';
    $_GET['branch_id'] = $bId;
    $_GET['user_id'] = 1;
    
    // Simulasikan request bootstrap
    ob_start();
    include 'api.php';
    $output = ob_get_clean();
    $data = json_decode($output, true);
    $lastData = $data;
    
    assertTest(is_array($data) && ($data['success'] ?? false) === true, "Bootstrap berhasil untuk Cabang ID: $bId");
    
    $products = $data['products'] ?? [];
    $pids = array_column($products, 'id');
    $uniquePids = array_unique($pids);
    
    assertTest(count($pids) === count($uniquePids), "Cabang ID $bId: Ketiadaan duplikasi produk (Total: " . count($pids) . ", Unik: " . count($uniquePids) . ")");
    assertTest(count($products) === $expectedCount, "Cabang ID $bId: Jumlah produk aktif di POS sesuai (" . count($products) . " dari target $expectedCount)");
}

// 2. Uji ketiadaan duplikasi ID produk
echo "\n--- Uji Pertahanan Duplikasi Data ---\n";
$p1Prods = [];
foreach (($lastData['products'] ?? []) as $p) {
    $p1Prods[$p['id']] = ($p1Prods[$p['id']] ?? 0) + 1;
}
$hasDupes = false;
foreach ($p1Prods as $id => $c) {
    if ($c > 1) { $hasDupes = true; break; }
}
assertTest(!$hasDupes, "Tidak ditemukan ID produk ganda pada respon bootstrap");

// 3. Verifikasi skema esbas_pos_mysql.sql memuat UNIQUE KEY uq_branch_product
$mysqlSql = file_get_contents('esbas_pos_mysql.sql');
assertTest(strpos($mysqlSql, "UNIQUE KEY `uq_branch_product` (`branch_id`, `product_id`)") !== false, "esbas_pos_mysql.sql memuat UNIQUE KEY uq_branch_product pada branch_stocks");

// 4. Verifikasi autoMigrateMysql memuat query deduplikasi dan penegakan unik
$dbCode = file_get_contents('db.php');
assertTest(strpos($dbCode, "DELETE bs1 FROM `branch_stocks` bs1") !== false, "db.php memuat query pembersihan duplikat DELETE bs1 FROM branch_stocks");
assertTest(strpos($dbCode, "uq_branch_product") !== false, "db.php memuat pengecekan dan penambahan UNIQUE KEY uq_branch_product");
assertTest(strpos($dbCode, "SET bs.is_active = 0") !== false, "db.php memuat sinkronisasi menu cabang nonaktif yang presisi");

echo "\n------------------------------------------------------------\n";
echo "SUMMARY: " . ($failCount === 0 ? "ALL $passCount TESTS PASSED (100% OK)" : "$failCount TESTS FAILED") . "\n";
echo "============================================================\n";
