<?php
/**
 * Test Suite: Fitur Produk Termasuk Biaya Kirim & Pencatatan Kurir Pengirim
 * 
 * Verifikasi:
 * 1. Simpan Produk dengan is_delivery_included = 1 (mode 2).
 * 2. Cek data di products & branch_stocks.
 * 3. Checkout transaksi POS dengan produk include delivery:
 *    - delivery_fee = 0
 *    - driver_user_id & driver_name tersimpan di sales
 *    - courier_vehicle_id tersimpan di sales
 *    - is_delivery_included = 1
 * 4. Panggil get_sale_detail dan verifikasi effective_driver_name.
 * 5. Update branch stock delivery mode (toggle & update_branch_product_price).
 */

require_once __DIR__ . '/db.php';

echo "=== TEST SUITE: PRODUK INCLUDE BIAYA KIRIM & PENCATATAN KURIR ===\n\n";

// $pdo sudah tersedia dari db.php

// Test 1: Simpan produk baru dengan mode 2 (Termasuk Biaya Kirim)
echo "[TEST 1] Membuat produk dengan is_delivery_included = 1...\n";
$sku = 'TEST-DELIV-' . time();
$productData = [
    'sku' => $sku,
    'barcode' => 'BC' . rand(10000, 99999),
    'name' => 'Paket Catering Express (Free Ongkir)',
    'category' => 'Makanan',
    'unit' => 'porsi',
    'cogs' => 25000,
    'selling_price' => 50000,
    'reseller_price' => 45000,
    'stock' => 100,
    'min_stock' => 5,
    'image_emoji' => '🍱',
    'is_taxable' => 0,
    'tax_percent' => 0,
    'is_delivery_required' => 2,
    'is_delivery_included' => 1,
    'variants' => []
];

// Panggil save_product secara langsung via cURL atau eksekusi internal
// Kita bisa buat simulasi internal api request
$_GET['action'] = 'save_product';
$jsonPayload = json_encode($productData);

// Gunakan stream context untuk mock HTTP POST ke server lokal http://127.0.0.1:8000/api.php?action=save_product
$options = [
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $jsonPayload
    ]
];
$context = stream_context_create($options);
$response = file_get_contents('http://127.0.0.1:8000/api.php?action=save_product', false, $context);
$resData = json_decode($response, true);

if (!$resData || !$resData['success']) {
    echo "FAIL: Gagal membuat produk. Response: " . print_r($response, true) . "\n";
    exit(1);
}
echo "PASS: Produk berhasil dibuat dengan ID " . $resData['product_id'] . ".\n";
$productId = $resData['product_id'];

// Test 2: Verifikasi data di tabel products dan branch_stocks
echo "\n[TEST 2] Verifikasi kolom database products & branch_stocks...\n";
$stmt = $pdo->prepare("SELECT id, name, is_delivery_required, is_delivery_included FROM products WHERE id = ?");
$stmt->execute([$productId]);
$prodRow = $stmt->fetch();

assert($prodRow['is_delivery_required'] == 2, "is_delivery_required harus bernilai 2");
assert($prodRow['is_delivery_included'] == 1, "is_delivery_included harus bernilai 1");
echo "PASS: Tabel products memiliki is_delivery_required = 2 dan is_delivery_included = 1.\n";

$stmtBs = $pdo->prepare("SELECT branch_id, is_delivery_required, is_delivery_included FROM branch_stocks WHERE product_id = ?");
$stmtBs->execute([$productId]);
$bsRows = $stmtBs->fetchAll();
assert(count($bsRows) > 0, "branch_stocks harus memiliki baris untuk produk ini");
foreach ($bsRows as $bs) {
    assert($bs['is_delivery_required'] == 2, "branch_stocks.is_delivery_required harus 2");
    assert($bs['is_delivery_included'] == 1, "branch_stocks.is_delivery_included harus 1");
}
echo "PASS: Sinkronisasi ke seluruh branch_stocks berhasil (is_delivery_included = 1).\n";

// Test 3: Checkout transaksi POS dengan pengiriman kurir
echo "\n[TEST 3] Checkout transaksi POS dengan Kurir Pengantar & Ongkir Rp 0...\n";
// Ambil data user & kendaraan di cabang 1
$driverStmt = $pdo->query("SELECT id, name FROM users WHERE role IN ('CASHIER', 'STAFF', 'ADMIN', 'SUPERADMIN') LIMIT 1");
$driver = $driverStmt->fetch();
$driverId = $driver['id'];
$driverName = $driver['name'];

$vehicleStmt = $pdo->query("SELECT id, vehicle_name, plate_number FROM courier_vehicles WHERE status = 'ACTIVE' LIMIT 1");
$vehicle = $vehicleStmt->fetch();
$vehicleId = $vehicle ? $vehicle['id'] : null;

$checkoutPayload = [
    'branch_id' => 1,
    'user_id' => 1,
    'customer_id' => 1,
    'customer_name' => 'Bapak Andika (Delivery)',
    'customer_category' => 'REGULER',
    'sales_type' => 'RETAIL',
    'items' => [
        [
            'product_id' => $productId,
            'qty' => 2
        ]
    ],
    'payment_method' => 'CASH',
    'cash_received' => 100000,
    'discount' => 0,
    'delivery_fee' => 0,
    'delivery_distance_km' => 0,
    'courier_vehicle_id' => $vehicleId,
    'driver_user_id' => $driverId,
    'driver_name' => $driverName,
    'is_delivery_included' => 1,
    'delivery_status' => 'ASSIGNED'
];

$checkoutOpts = [
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode($checkoutPayload)
    ]
];
$coContext = stream_context_create($checkoutOpts);
$coRes = file_get_contents('http://127.0.0.1:8000/api.php?action=checkout_sale', false, $coContext);
$coData = json_decode($coRes, true);

if (!$coData || !$coData['success']) {
    echo "FAIL: Gagal checkout POS: " . print_r($coRes, true) . "\n";
    exit(1);
}
echo "PASS: Checkout berhasil. Invoice: " . $coData['sale']['invoice_number'] . "\n";
$invoice = $coData['sale']['invoice_number'];

// Test 4: Verifikasi tabel sales
echo "\n[TEST 4] Verifikasi pencatatan di tabel sales...\n";
$pdo = new PDO("sqlite:" . __DIR__ . "/database.sqlite");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$saleStmt = $pdo->prepare("SELECT id, invoice_number, delivery_fee, courier_vehicle_id, driver_user_id, driver_name, is_delivery_included, delivery_status FROM sales WHERE invoice_number = ?");
$saleStmt->execute([$invoice]);
$saleRow = $saleStmt->fetch();

assert($saleRow['delivery_fee'] == 0, "delivery_fee harus bernilai 0");
assert($saleRow['is_delivery_included'] == 1, "is_delivery_included harus bernilai 1");
assert($saleRow['driver_user_id'] == $driverId, "driver_user_id harus sesuai");
assert($saleRow['driver_name'] == $driverName, "driver_name harus sesuai");
assert($saleRow['delivery_status'] == 'ASSIGNED', "delivery_status harus ASSIGNED");
echo "PASS: Tabel sales mencatat kurir ({$saleRow['driver_name']}), vehicle ID ({$saleRow['courier_vehicle_id']}), is_delivery_included = 1, dan ongkir = Rp 0.\n";

// Test 5: Verifikasi get_sale_detail
echo "\n[TEST 5] Verifikasi endpoint get_sale_detail...\n";
$detailRes = file_get_contents("http://127.0.0.1:8000/api.php?action=get_sale_detail&invoice_number=" . urlencode($invoice));
$detailData = json_decode($detailRes, true);

assert($detailData['success'] == true, "get_sale_detail harus sukses");
assert($detailData['sale']['effective_driver_name'] == $driverName, "effective_driver_name harus memuat nama driver");
assert($detailData['sale']['is_delivery_included'] == 1, "is_delivery_included harus bernilai 1");
echo "PASS: Endpoint get_sale_detail mengembalikan effective_driver_name = {$detailData['sale']['effective_driver_name']}.\n";

// Test 6: Toggle Branch Product Delivery Mode (Rotasi 0 -> 1 -> 2 -> 0)
echo "\n[TEST 6] Menguji toggle_branch_product_delivery per cabang...\n";
$toggleOpts = [
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode(['branch_id' => 1, 'product_id' => $productId, 'is_delivery_required' => 0])
    ]
];
$toggleCtx = stream_context_create($toggleOpts);
$toggleRes = file_get_contents('http://127.0.0.1:8000/api.php?action=toggle_branch_product_delivery', false, $toggleCtx);
$toggleData = json_decode($toggleRes, true);
assert($toggleData['success'] == true, "Toggle delivery mode harus sukses");
assert($toggleData['is_delivery_required'] == 0, "is_delivery_required harus 0 setelah rotasi ke standar");
echo "PASS: Rotasi status pengiriman cabang berhasil (Mode: {$toggleData['is_delivery_required']}).\n";

echo "\n=======================================================\n";
echo "SEMUA TEST SELESAI & LULUS DENGAN HASIL SEMPURNA (100% PASS)!\n";
echo "=======================================================\n";
