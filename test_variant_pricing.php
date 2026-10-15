<?php
/**
 * Automated Test: Penetapan Harga Varian (Dengan Tambahan Harga vs Tanpa Tambahan Harga)
 * 
 * Skenario yang diuji:
 * 1. Checkout Varian dengan Tambahan Harga (+Rp 1.000 pada Galon 19L atau +Rp 8.000 pada Kopi Espresso)
 *    - Ekspektasi: unit_price = base_price + additional_price
 *    - subtotal = unit_price * qty
 * 2. Checkout Varian TANPA Tambahan Harga (+Rp 0 pada Galon Refill Saja atau +Rp 0 pada Kopi Regular)
 *    - Ekspektasi: unit_price = base_price + 0 = base_price
 *    - subtotal = base_price * qty
 * 3. Verifikasi CRUD Varian Baru:
 *    - Tambah varian baru dengan additional_price = 0 (Tanpa Tambahan Biaya)
 *    - Tambah varian baru dengan additional_price = 5000 (Ada Tambahan Biaya)
 *    - Validasi database dan perhitungan harga saat penjualan
 */

require_once __DIR__ . '/db.php';

echo "====================================================================\n";
echo "TEST SUITE: PENETAPAN HARGA VARIAN (BERBAYAR & BEBAS BIAYA)\n";
echo "====================================================================\n\n";

$branchId = 1;
$userId = 1;

function callApi($url, $payload = null) {
    $ch = curl_init('http://localhost:8000/api.php' . $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return json_decode($res, true);
}

// -------------------------------------------------------------
// TEST 1: Checkout Varian Ada Tambahan Harga (+Rp 1.000)
// -------------------------------------------------------------
echo "[TEST 1] Transaksi Galon Mineral 19L dengan Varian 'Pakai Tutup Segel' (+Rp 1.000)...\n";
$galon = $pdo->query("SELECT * FROM products WHERE sku = 'PRD-GLN-19L'")->fetch(PDO::FETCH_ASSOC);
$branchStock = $pdo->query("SELECT local_price FROM branch_stocks WHERE branch_id = {$branchId} AND product_id = {$galon['id']}")->fetch(PDO::FETCH_ASSOC);
$varTutup = $pdo->query("SELECT * FROM product_variants WHERE sku_variant = 'VAR-GLN-TUTUP'")->fetch(PDO::FETCH_ASSOC);

$basePrice = (float)$branchStock['local_price']; // Cabang 1 local_price = 19000
$addPriceTutup = (float)$varTutup['additional_price']; // 1000
$expectedUnit1 = $basePrice + $addPriceTutup; // 20000
$qty1 = 2;
$expectedSubtotal1 = $expectedUnit1 * $qty1; // 40000

$payload1 = [
    'branch_id' => $branchId,
    'user_id' => $userId,
    'customer_id' => 1,
    'customer_name' => 'Pelanggan Umum',
    'customer_category' => 'REGULER',
    'sales_type' => 'RETAIL',
    'payment_method' => 'CASH',
    'cash_received' => 50000,
    'discount' => 0,
    'delivery_fee' => 0,
    'items' => [
        [
            'product_id' => (int)$galon['id'],
            'variant_id' => (int)$varTutup['id'],
            'variant_name' => $varTutup['variant_name'],
            'qty' => $qty1
        ]
    ]
];

$res1 = callApi('?action=checkout_sale', $payload1);
if (!isset($res1['success']) || !$res1['success']) {
    die("FAILED: Checkout 1 error: " . json_encode($res1) . "\n");
}

$saleId1 = $res1['sale']['id'];
$sale1 = $pdo->query("SELECT * FROM sales WHERE id = {$saleId1}")->fetch(PDO::FETCH_ASSOC);
$item1 = $pdo->query("SELECT * FROM sale_items WHERE sale_id = {$saleId1}")->fetch(PDO::FETCH_ASSOC);

echo " -> Harga Dasar: Rp " . number_format($basePrice, 0, ',', '.') . "\n";
echo " -> Tambahan Harga Varian: +Rp " . number_format($addPriceTutup, 0, ',', '.') . "\n";
echo " -> Unit Price di Database: Rp " . number_format($item1['unit_price'], 0, ',', '.') . " (Ekspektasi: Rp " . number_format($expectedUnit1, 0, ',', '.') . ")\n";
echo " -> Subtotal di Database: Rp " . number_format($item1['subtotal_price'], 0, ',', '.') . " (Ekspektasi: Rp " . number_format($expectedSubtotal1, 0, ',', '.') . ")\n";
echo " -> Grand Total Sale: Rp " . number_format($sale1['grand_total'], 0, ',', '.') . "\n";

assert((float)$item1['unit_price'] === $expectedUnit1, "Unit price varian bertambahan harga salah!");
assert((float)$item1['subtotal_price'] === $expectedSubtotal1, "Subtotal varian bertambahan harga salah!");
echo " -> [PASSED] Varian dengan tambahan harga berhasil dihitung secara akurat!\n\n";

// -------------------------------------------------------------
// TEST 2: Checkout Varian TANPA Tambahan Harga (+Rp 0)
// -------------------------------------------------------------
echo "[TEST 2] Transaksi Galon Mineral 19L dengan Varian 'Tanpa Tutup (Refill Saja)' (+Rp 0 / Bebas Biaya)...\n";
$varNoTutup = $pdo->query("SELECT * FROM product_variants WHERE sku_variant = 'VAR-GLN-NOTUTUP'")->fetch(PDO::FETCH_ASSOC);

$addPriceNoTutup = (float)$varNoTutup['additional_price']; // 0
$expectedUnit2 = $basePrice + $addPriceNoTutup; // 18000
$qty2 = 3;
$expectedSubtotal2 = $expectedUnit2 * $qty2; // 54000

$payload2 = [
    'branch_id' => $branchId,
    'user_id' => $userId,
    'customer_id' => 1,
    'customer_name' => 'Pelanggan Umum',
    'customer_category' => 'REGULER',
    'sales_type' => 'RETAIL',
    'payment_method' => 'CASH',
    'cash_received' => 100000,
    'discount' => 0,
    'delivery_fee' => 0,
    'items' => [
        [
            'product_id' => (int)$galon['id'],
            'variant_id' => (int)$varNoTutup['id'],
            'variant_name' => $varNoTutup['variant_name'],
            'qty' => $qty2
        ]
    ]
];

$res2 = callApi('?action=checkout_sale', $payload2);
if (!isset($res2['success']) || !$res2['success']) {
    die("FAILED: Checkout 2 error: " . json_encode($res2) . "\n");
}

$saleId2 = $res2['sale']['id'];
$sale2 = $pdo->query("SELECT * FROM sales WHERE id = {$saleId2}")->fetch(PDO::FETCH_ASSOC);
$item2 = $pdo->query("SELECT * FROM sale_items WHERE sale_id = {$saleId2}")->fetch(PDO::FETCH_ASSOC);

echo " -> Harga Dasar: Rp " . number_format($basePrice, 0, ',', '.') . "\n";
echo " -> Tambahan Harga Varian: +Rp " . number_format($addPriceNoTutup, 0, ',', '.') . " (Tanpa Tambahan)\n";
echo " -> Unit Price di Database: Rp " . number_format($item2['unit_price'], 0, ',', '.') . " (Ekspektasi: Rp " . number_format($expectedUnit2, 0, ',', '.') . ")\n";
echo " -> Subtotal di Database: Rp " . number_format($item2['subtotal_price'], 0, ',', '.') . " (Ekspektasi: Rp " . number_format($expectedSubtotal2, 0, ',', '.') . ")\n";
echo " -> Grand Total Sale: Rp " . number_format($sale2['grand_total'], 0, ',', '.') . "\n";

assert((float)$item2['unit_price'] === $expectedUnit2, "Unit price varian tanpa tambahan harga salah!");
assert((float)$item2['subtotal_price'] === $expectedSubtotal2, "Subtotal varian tanpa tambahan harga salah!");
assert((float)$item2['unit_price'] === $basePrice, "Unit price harus sama persis dengan base price produk!");
echo " -> [PASSED] Varian tanpa tambahan harga berhasil diverifikasi tetap pada harga dasar!\n\n";

// -------------------------------------------------------------
// TEST 3: CRUD Pembuatan Varian Baru Dinamis (Tanpa Tambahan & Berbayar)
// -------------------------------------------------------------
echo "[TEST 3] Pembuatan Varian Baru via API `save_variant`...\n";

// Varian 3A: Tanpa Tambahan Harga (+Rp 0)
$skuFree = 'VAR-TEST-FREE-' . time();
$createFree = callApi('?action=save_variant', [
    'product_id' => (int)$galon['id'],
    'variant_name' => 'Paket Hemat Refill Pribadi',
    'sku_variant' => $skuFree,
    'additional_price' => 0,
    'additional_cogs' => 0
]);
echo " -> Simpan Varian Bebas Biaya: " . ($createFree['success'] ? "BERHASIL" : "GAGAL") . "\n";
assert($createFree['success'] === true);

// Varian 3B: Ada Tambahan Harga (+Rp 5.000)
$skuPaid = 'VAR-TEST-PAID-' . time();
$createPaid = callApi('?action=save_variant', [
    'product_id' => (int)$galon['id'],
    'variant_name' => 'Edisi Galon Segel Sanitasi + Bonus Tissue',
    'sku_variant' => $skuPaid,
    'additional_price' => 5000,
    'additional_cogs' => 1500
]);
echo " -> Simpan Varian Berbayar (+Rp 5.000): " . ($createPaid['success'] ? "BERHASIL" : "GAGAL") . "\n";
assert($createPaid['success'] === true);

// Verifikasi di database
$savedFree = $pdo->query("SELECT * FROM product_variants WHERE sku_variant = '{$skuFree}'")->fetch(PDO::FETCH_ASSOC);
$savedPaid = $pdo->query("SELECT * FROM product_variants WHERE sku_variant = '{$skuPaid}'")->fetch(PDO::FETCH_ASSOC);

echo " -> Database Free Variant Price: +Rp " . number_format($savedFree['additional_price'], 0, ',', '.') . "\n";
echo " -> Database Paid Variant Price: +Rp " . number_format($savedPaid['additional_price'], 0, ',', '.') . "\n";
assert((float)$savedFree['additional_price'] === 0.0);
assert((float)$savedPaid['additional_price'] === 5000.0);

// Cleanup varian uji test 3
$pdo->prepare("DELETE FROM product_variants WHERE sku_variant IN (?, ?)")->execute([$skuFree, $skuPaid]);
echo " -> [PASSED] CRUD Varian berbayar dan gratis teruji tervalidasi 100%!\n\n";

echo "====================================================================\n";
echo "SEMUA TEST PENETAPAN HARGA VARIAN BERHASIL DILALUI DENGAN SUKSES! (100% OK)\n";
echo "====================================================================\n";
