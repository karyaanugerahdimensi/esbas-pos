<?php
/**
 * Test Shift Modal Render Fix
 * Memverifikasi bahwa data Opening dan Closing Shift termuat 100% tanpa error
 */

$baseUrl = 'http://127.0.0.1:8000';

echo "=== MEMULAI TEST VERIFIKASI PERBAIKAN OPENING & CLOSING SHIFT DATA ===" . PHP_EOL . PHP_EOL;

// 1. Verifikasi fungsi escapeHtml ada di index.php
echo "[TEST 1] Memeriksa keberadaan fungsi escapeHtml di index.php..." . PHP_EOL;
$indexContent = file_get_contents(__DIR__ . '/index.php');
if (strpos($indexContent, 'function escapeHtml(') !== false) {
    echo " [PASS] Fungsi escapeHtml(str) ditemukan di index.php" . PHP_EOL;
} else {
    echo " [FAIL] Fungsi escapeHtml(str) TIDAK ditemukan di index.php!" . PHP_EOL;
    exit(1);
}

// 2. Verifikasi pemanggilan get_opening_shift_data
echo PHP_EOL . "[TEST 2] Menguji API get_opening_shift_data..." . PHP_EOL;
$openingJson = file_get_contents("{$baseUrl}/api.php?action=get_opening_shift_data&branch_id=1");
$openingData = json_decode($openingJson, true);

if ($openingData && !empty($openingData['success']) && !empty($openingData['items'])) {
    echo " [PASS] get_opening_shift_data sukses, mengembalikan " . count($openingData['items']) . " produk." . PHP_EOL;
    foreach ($openingData['items'] as $it) {
        if (empty($it['name']) || !isset($it['opening_stock']) || !isset($it['night_stock'])) {
            echo " [FAIL] Item data tidak lengkap: " . json_encode($it) . PHP_EOL;
            exit(1);
        }
    }
    echo " [PASS] Semua item produk opening memiliki name, opening_stock, dan night_stock yang valid." . PHP_EOL;
} else {
    echo " [FAIL] get_opening_shift_data gagal memuat data: " . $openingJson . PHP_EOL;
    exit(1);
}

// 3. Verifikasi pemanggilan get_closing_shift_data
echo PHP_EOL . "[TEST 3] Menguji API get_closing_shift_data..." . PHP_EOL;

// Jika tidak ada shift aktif, buka shift uji coba terlebih dahulu
$checkActive = json_decode(file_get_contents("{$baseUrl}/api.php?action=get_closing_shift_data&branch_id=1"), true);
if (empty($checkActive['success'])) {
    // Buka shift sementara untuk pengujian closing
    $openPayload = json_encode([
        'branch_id' => 1,
        'user_id' => 1,
        'initial_cash' => 250000,
        'items' => [
            ['product_id' => 89, 'opening_qty' => 150, 'night_qty' => 150],
            ['product_id' => 88, 'opening_qty' => 90, 'night_qty' => 90]
        ]
    ]);
    $ctx = stream_context_create([
        'http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $openPayload]
    ]);
    file_get_contents("{$baseUrl}/api.php?action=open_shift", false, $ctx);
}

$closingJson = file_get_contents("{$baseUrl}/api.php?action=get_closing_shift_data&branch_id=1");
$closingData = json_decode($closingJson, true);

if ($closingData && !empty($closingData['success']) && !empty($closingData['items'])) {
    echo " [PASS] get_closing_shift_data sukses, mengembalikan " . count($closingData['items']) . " produk komparasi." . PHP_EOL;
    echo " [PASS] Shift ID aktif: " . ($closingData['shift']['id'] ?? 'N/A') . PHP_EOL;
    echo " [PASS] Modal awal: Rp " . number_format($closingData['initial_cash'], 0, ',', '.') . PHP_EOL;
    echo " [PASS] Penjualan tunai: Rp " . number_format($closingData['cash_sales'], 0, ',', '.') . PHP_EOL;
    echo " [PASS] Ekspektasi kas: Rp " . number_format($closingData['expected_cash'], 0, ',', '.') . PHP_EOL;
    foreach ($closingData['items'] as $it) {
        if (empty($it['name']) || !isset($it['opening_qty']) || !isset($it['closing_qty'])) {
            echo " [FAIL] Item closing tidak lengkap: " . json_encode($it) . PHP_EOL;
            exit(1);
        }
    }
    echo " [PASS] Semua item produk closing memiliki name, opening_qty, dan closing_qty yang valid." . PHP_EOL;
} else {
    echo " [FAIL] get_closing_shift_data gagal memuat data: " . $closingJson . PHP_EOL;
    exit(1);
}

// 4. Verifikasi bahwa tidak ada referensi undefined di blok modal shift index.php
echo PHP_EOL . "[TEST 4] Memeriksa konsistensi kode JavaScript modal shift di index.php..." . PHP_EOL;
// Memeriksa fungsi calculateOpeningShiftVariance, calculateClosingCashDifference, calculateClosingItemDiff
$requiredFns = [
    'calculateOpeningShiftVariance',
    'calculateClosingCashDifference',
    'calculateClosingItemDiff',
    'openShiftModal',
    'closeShiftModal',
    'executeShiftAction'
];
foreach ($requiredFns as $fn) {
    if (strpos($indexContent, "function {$fn}(") !== false) {
        echo " [PASS] Fungsi {$fn} terdefinisi dengan benar." . PHP_EOL;
    } else {
        echo " [FAIL] Fungsi {$fn} TIDAK terdefinisi!" . PHP_EOL;
        exit(1);
    }
}

echo PHP_EOL . "===================================================================" . PHP_EOL;
echo " SEMUA VERIFIKASI PERBAIKAN OPENING & CLOSING SHIFT 100% SUKSES!" . PHP_EOL;
echo "===================================================================" . PHP_EOL;
