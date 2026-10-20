<?php
/**
 * Test Suite: Stock Adjust Items Loading & API Inventory Stocks Column Compatibility
 * Version: 1.3.0
 */

require_once __DIR__ . '/db.php';
global $pdo;

$passedTests = 0;
$totalTests = 0;

function assertCheck($condition, $description) {
    global $passedTests, $totalTests;
    $totalTests++;
    if ($condition) {
        echo "✅ PASS: {$description}\n";
        $passedTests++;
    } else {
        echo "❌ FAIL: {$description}\n";
    }
}

echo "=== MEMULAI TEST PEMUATAN ITEM MODAL SETEL STOK AWAL & API INVENTORY STOCKS ===\n\n";

// 1. Verifikasi get_inventory_stocks dengan branch_id = ALL
$_GET = ['action' => 'get_inventory_stocks', 'branch_id' => 'ALL'];
ob_start();
include __DIR__ . '/api.php';
$rawAll = ob_get_clean();
$dataAll = json_decode($rawAll, true);

assertCheck(!empty($dataAll) && ($dataAll['success'] ?? false) === true, "API get_inventory_stocks (Global/ALL) merespon success = true tanpa galat SQL");
assertCheck(!empty($dataAll['stocks']) && count($dataAll['stocks']) > 0, "API get_inventory_stocks (Global/ALL) mengembalikan data produk (Total item: " . count($dataAll['stocks'] ?? []) . ")");

// 2. Verifikasi get_inventory_stocks untuk setiap cabang yang ada di database
$branches = $pdo->query("SELECT id, name, code FROM branches ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
assertCheck(count($branches) > 0, "Terdapat cabang terdaftar di database (Total: " . count($branches) . ")");

foreach ($branches as $br) {
    $bId = (int)$br['id'];
    $_GET = ['action' => 'get_inventory_stocks', 'branch_id' => (string)$bId];
    ob_start();
    include __DIR__ . '/api.php';
    $rawBr = ob_get_clean();
    $dataBr = json_decode($rawBr, true);

    $isOk = !empty($dataBr) && ($dataBr['success'] ?? false) === true && count($dataBr['stocks'] ?? []) > 0;
    assertCheck($isOk, "API get_inventory_stocks untuk cabang #{$bId} ({$br['name']}) sukses & merespon " . count($dataBr['stocks'] ?? []) . " item barang");
}

// 3. Verifikasi produk memuat barang jadi dan bahan baku
$hasFinishedGoods = false;
$hasRawMaterials = false;
foreach ($dataAll['stocks'] as $s) {
    if ($s['product_type'] === 'FINISHED_GOODS') $hasFinishedGoods = true;
    if ($s['product_type'] === 'RAW_MATERIAL') $hasRawMaterials = true;
}
assertCheck($hasFinishedGoods, "Daftar item memuat produk tipe FINISHED_GOODS (Barang Jadi)");
assertCheck($hasRawMaterials, "Daftar item memuat produk tipe RAW_MATERIAL (Bahan Baku)");

// 4. Verifikasi di index.php: loadStockAdjustProducts menangani loading, fallback, dan render opsi lengkap
$indexContent = file_get_contents(__DIR__ . '/index.php');
assertCheck(strpos($indexContent, 'loadStockAdjustProducts') !== false, "Fungsi loadStockAdjustProducts terdefinisi di index.php");
assertCheck(strpos($indexContent, 'id="adj-product-select"') !== false, "Dropdown #adj-product-select terdefinisi pada modal penyesuaian stok");
assertCheck(strpos($indexContent, 'currentModalBranchStocks = data.stocks') !== false, "loadStockAdjustProducts mengonsumsi data.stocks dari response API");
assertCheck(strpos($indexContent, 'fallback to bootstrap products') !== false || strpos($indexContent, 'state.products') !== false, "loadStockAdjustProducts memiliki proteksi fallback ke state.products");

// 5. Verifikasi di api.php: tidak ada referensi salah ke p.price pada kueri get_inventory_stocks
$apiContent = file_get_contents(__DIR__ . '/api.php');
assertCheck(strpos($apiContent, 'p.selling_price') !== false, "api.php menggunakan p.selling_price");
$hasWrongPrice = preg_match('/case\s+[\'"]get_inventory_stocks[\'"].*?p\.price/s', $apiContent);
assertCheck(!$hasWrongPrice, "api.php pada case get_inventory_stocks TIDAK lagi menggunakan kolom palsu p.price");

echo "\n=== HASIL AKHIR: {$passedTests} dari {$totalTests} pengujian BERHASIL! ===\n";

if ($passedTests === $totalTests) {
    exit(0);
} else {
    exit(1);
}
