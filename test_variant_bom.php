<?php
/**
 * Automated Test: Dual-Layer BOM Deduction (Product Base BOM + Variant BOM)
 * Case: Air Mineral Galon 19 Liter (PRD-007)
 *   - Base Recipe: 19 Liter RAW-008 (Air Baku RO)
 *   - Variant 1 (PRD-007-TUTUP): "Pakai Tutup Segel (+Rp 1.000)" -> memotong 1 pcs RAW-009 (Tutup) & 1 pcs RAW-010 (Tisu)
 *   - Variant 2 (PRD-007-NOTUTUP): "Tanpa Tutup (Refill Saja)" -> TIDAK memotong RAW-009 maupun RAW-010
 */

require_once __DIR__ . '/db.php';
// $pdo is initialized in db.php

echo "====================================================================\n";
echo "TEST SUITE: VERIFIKASI PEMOTONGAN BOM VARIAN (DUAL-LAYER BOM)\n";
echo "====================================================================\n\n";

$branchId = 1;
$userId = 1;

$allP = $pdo->query("SELECT id, sku, name FROM products")->fetchAll(PDO::FETCH_ASSOC);
echo "DAFTAR SEMUA PRODUK DI DATABASE:\n";
foreach ($allP as $row) {
    echo " - [{$row['id']}] {$row['sku']}: {$row['name']}\n";
}
echo "\n";

// 1. Fetch products & variants
$galon = $pdo->query("SELECT * FROM products WHERE sku = 'PRD-GLN-19L'")->fetch(PDO::FETCH_ASSOC);
$airBaku = $pdo->query("SELECT * FROM products WHERE sku = 'RAW-AIR-RO'")->fetch(PDO::FETCH_ASSOC);
$tutup = $pdo->query("SELECT * FROM products WHERE sku = 'RAW-CAP-GLN'")->fetch(PDO::FETCH_ASSOC);
$tisu = $pdo->query("SELECT * FROM products WHERE sku = 'RAW-TISU-GLN'")->fetch(PDO::FETCH_ASSOC);

$varTutup = $pdo->query("SELECT * FROM product_variants WHERE sku_variant = 'VAR-GLN-TUTUP'")->fetch(PDO::FETCH_ASSOC);
$varNoTutup = $pdo->query("SELECT * FROM product_variants WHERE sku_variant = 'VAR-GLN-NOTUTUP'")->fetch(PDO::FETCH_ASSOC);

echo "Status query:\n";
echo " - Galon: " . ($galon ? $galon['name'] : 'NULL') . "\n";
echo " - Air Baku: " . ($airBaku ? $airBaku['name'] : 'NULL') . "\n";
echo " - Tutup: " . ($tutup ? $tutup['name'] : 'NULL') . "\n";
echo " - Tisu: " . ($tisu ? $tisu['name'] : 'NULL') . "\n";
echo " - Varian Tutup: " . ($varTutup ? $varTutup['variant_name'] : 'NULL') . "\n";
echo " - Varian Tanpa Tutup: " . ($varNoTutup ? $varNoTutup['variant_name'] : 'NULL') . "\n\n";

if (!$galon || !$airBaku || !$tutup || !$tisu || !$varTutup || !$varNoTutup) {
    die("FATAL ERROR: Master data galon / varian belum ter-seed lengkap!\n");
}

function getStock($pdo, $branchId, $productId) {
    $stmt = $pdo->prepare("SELECT stock FROM branch_stocks WHERE branch_id = ? AND product_id = ?");
    $stmt->execute([$branchId, $productId]);
    return (float)$stmt->fetchColumn();
}

function doCheckoutApi($payload) {
    $ch = curl_init('http://localhost:8000/api.php?action=checkout_sale');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return json_decode($response, true);
}

// Catat stok awal sebelum pengujian
$stockGalon0 = getStock($pdo, $branchId, $galon['id']);
$stockAir0   = getStock($pdo, $branchId, $airBaku['id']);
$stockTutup0 = getStock($pdo, $branchId, $tutup['id']);
$stockTisu0  = getStock($pdo, $branchId, $tisu['id']);

echo "[BASELINE STOK AWAL CABANG #{$branchId}]\n";
echo " - {$galon['name']}: {$stockGalon0} galon\n";
echo " - {$airBaku['name']}: {$stockAir0} liter\n";
echo " - {$tutup['name']}: {$stockTutup0} pcs\n";
echo " - {$tisu['name']}: {$stockTisu0} pcs\n\n";

// ====================================================================
// TEST CASE 1: Penjualan 2 Galon dengan Varian "Pakai Tutup Segel"
// Ekspektasi:
//   - Galon terpotong 2
//   - Air Baku RO terpotong 2 x 19 = 38 liter
//   - Tutup Galon Segel terpotong 2 pcs
//   - Tisu Sanitasi terpotong 2 pcs
// ====================================================================
echo ">>> UJI COBA 1: Transaksi 2 Galon [PAKAI TUTUP SEGEL] <<<\n";
$payload1 = [
    'branch_id' => $branchId,
    'user_id' => $userId,
    'shift_id' => null,
    'customer_id' => 1,
    'customer_name' => 'Pelanggan Uji Tutup',
    'customer_category' => 'REGULER',
    'sales_type' => 'RETAIL',
    'items' => [
        [
            'product_id' => $galon['id'],
            'variant_id' => $varTutup['id'],
            'variant_name' => $varTutup['variant_name'],
            'qty' => 2
        ]
    ],
    'payment_method' => 'CASH',
    'cash_received' => 50000,
    'discount' => 0,
    'delivery_fee' => 0,
    'delivery_distance_km' => 0,
    'courier_vehicle_id' => null,
    'delivery_status' => 'NONE'
];

$res1 = doCheckoutApi($payload1);
if (!$res1 || empty($res1['success'])) {
    echo "FAILED: " . json_encode($res1) . "\n";
    exit(1);
}
echo "✓ Checkout Berhasil! Invoice: {$res1['sale']['invoice_number']}\n";

$stockGalon1 = getStock($pdo, $branchId, $galon['id']);
$stockAir1   = getStock($pdo, $branchId, $airBaku['id']);
$stockTutup1 = getStock($pdo, $branchId, $tutup['id']);
$stockTisu1  = getStock($pdo, $branchId, $tisu['id']);

$diffGalon1 = $stockGalon0 - $stockGalon1;
$diffAir1   = $stockAir0 - $stockAir1;
$diffTutup1 = $stockTutup0 - $stockTutup1;
$diffTisu1  = $stockTisu0 - $stockTisu1;

echo "  -> Pengurangan Stok Galon: {$diffGalon1} (Expected: 2)\n";
echo "  -> Pengurangan Stok Air RO: {$diffAir1} L (Expected: 38)\n";
echo "  -> Pengurangan Stok Tutup: {$diffTutup1} pcs (Expected: 2)\n";
echo "  -> Pengurangan Stok Tisu: {$diffTisu1} pcs (Expected: 2)\n";

assert($diffGalon1 == 2, "Stok galon tidak terpotong 2");
assert($diffAir1 == 38, "Stok air tidak terpotong 38L");
assert($diffTutup1 == 2, "Stok tutup tidak terpotong 2 pcs");
assert($diffTisu1 == 2, "Stok tisu tidak terpotong 2 pcs");
echo ">>> HASIL UJI COBA 1: [PASSED] Tutup dan Tisu BERHASIL TERPOTONG!\n\n";

// ====================================================================
// TEST CASE 2: Penjualan 3 Galon dengan Varian "Tanpa Tutup (Refill Saja)"
// Ekspektasi:
//   - Galon terpotong 3
//   - Air Baku RO terpotong 3 x 19 = 57 liter
//   - Tutup Galon Segel TETAP (TIDAK BERKURANG / selisih = 0)
//   - Tisu Sanitasi TETAP (TIDAK BERKURANG / selisih = 0)
// ====================================================================
echo ">>> UJI COBA 2: Transaksi 3 Galon [TANPA TUTUP (REFILL SAJA)] <<<\n";
$payload2 = [
    'branch_id' => $branchId,
    'user_id' => $userId,
    'shift_id' => null,
    'customer_id' => 1,
    'customer_name' => 'Pelanggan Uji Tanpa Tutup',
    'customer_category' => 'REGULER',
    'sales_type' => 'RETAIL',
    'items' => [
        [
            'product_id' => $galon['id'],
            'variant_id' => $varNoTutup['id'],
            'variant_name' => $varNoTutup['variant_name'],
            'qty' => 3
        ]
    ],
    'payment_method' => 'CASH',
    'cash_received' => 70000,
    'discount' => 0,
    'delivery_fee' => 0,
    'delivery_distance_km' => 0,
    'courier_vehicle_id' => null,
    'delivery_status' => 'NONE'
];

$res2 = doCheckoutApi($payload2);
if (!$res2 || empty($res2['success'])) {
    echo "FAILED: " . json_encode($res2) . "\n";
    exit(1);
}
echo "✓ Checkout Berhasil! Invoice: {$res2['sale']['invoice_number']}\n";

$stockGalon2 = getStock($pdo, $branchId, $galon['id']);
$stockAir2   = getStock($pdo, $branchId, $airBaku['id']);
$stockTutup2 = getStock($pdo, $branchId, $tutup['id']);
$stockTisu2  = getStock($pdo, $branchId, $tisu['id']);

$diffGalon2 = $stockGalon1 - $stockGalon2;
$diffAir2   = $stockAir1 - $stockAir2;
$diffTutup2 = $stockTutup1 - $stockTutup2;
$diffTisu2  = $stockTisu1 - $stockTisu2;

echo "  -> Pengurangan Stok Galon: {$diffGalon2} (Expected: 3)\n";
echo "  -> Pengurangan Stok Air RO: {$diffAir2} L (Expected: 57)\n";
echo "  -> Pengurangan Stok Tutup: {$diffTutup2} pcs (Expected: 0 - TIDAK TERPOTONG)\n";
echo "  -> Pengurangan Stok Tisu: {$diffTisu2} pcs (Expected: 0 - TIDAK TERPOTONG)\n";

assert($diffGalon2 == 3, "Stok galon tidak terpotong 3");
assert($diffAir2 == 57, "Stok air tidak terpotong 57L");
assert($diffTutup2 == 0, "Stok tutup seharusnya TIDAK berkurang!");
assert($diffTisu2 == 0, "Stok tisu seharusnya TIDAK berkurang!");
echo ">>> HASIL UJI COBA 2: [PASSED] Stok Tutup dan Tisu AMAN & TIDAK BERKURANG!\n\n";

// Cek data di sale_items & stock_movements
$lastSaleItems = $pdo->query("SELECT * FROM sale_items WHERE sale_id = {$res2['sale']['id']}")->fetchAll(PDO::FETCH_ASSOC);
echo "Verifikasi Tabel sale_items Transaksi #{$res2['sale']['id']}:\n";
foreach ($lastSaleItems as $si) {
    echo " - Item: {$si['product_name']}, Variant ID: {$si['variant_id']}, Variant Name: {$si['variant_name']}, Qty: {$si['qty']}, Subtotal: Rp " . number_format($si['subtotal_price'], 0, ',', '.') . "\n";
}

echo "\n====================================================================\n";
echo "SEMUA PENGUJIAN PEMOTONGAN BOM VARIAN 100% SUKSES DAN TERVALIDASI!\n";
echo "====================================================================\n";
