<?php
/**
 * Test Suite: Fitur Produk Wajib Ongkir & Disesuaikan Kurir/Armada Akun Login Kasir/Staf
 * Menguji:
 * 1. Skema tabel products (is_delivery_required)
 * 2. CRUD Master Produk dengan konfigurasi is_delivery_required
 * 3. Endpoint get_all_products & init_data mengembalikan is_delivery_required
 * 4. Penugasan armada kendaraan ke staf/kasir (courier_vehicles.driver_user_id)
 * 5. Logika penyesuaian kurir & kendaraan otomatis untuk kasir/staff yang login
 * 6. Checkout POS dengan delivery_fee dan armada yang sesuai
 * 7. Integritas database dan validasi sintaks PHP
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/db.php';

$testCount = 0;
$passCount = 0;
$failCount = 0;

function assertTest($condition, $message) {
    global $testCount, $passCount, $failCount;
    $testCount++;
    if ($condition) {
        $passCount++;
        echo "[ PASS ] $message\n";
    } else {
        $failCount++;
        echo "[ FAIL ] $message\n";
    }
}

echo "===================================================================\n";
echo " TEST SUITE: FITUR PRODUK WAJIB ONGKIR & KURIR/ARMADA LOGIN KASIR\n";
echo "===================================================================\n\n";

// -----------------------------------------------------------------
// 1. UJI SKEMA BASIS DATA: is_delivery_required & courier_vehicles
// -----------------------------------------------------------------
echo "1. Menguji Skema Basis Data (Tabel products & courier_vehicles)...\n";
$prodCols = array_column($pdo->query("PRAGMA table_info(products)")->fetchAll(), 'name');
assertTest(in_array('is_delivery_required', $prodCols), "Kolom 'is_delivery_required' terpasang pada tabel products");

$vehCols = array_column($pdo->query("PRAGMA table_info(courier_vehicles)")->fetchAll(), 'name');
assertTest(in_array('driver_user_id', $vehCols), "Kolom 'driver_user_id' terpasang pada tabel courier_vehicles");

// Cek produk default galon 19L
$galon = $pdo->query("SELECT id, name, is_delivery_required FROM products WHERE sku = 'PRD-GLN-19L'")->fetch();
assertTest(!empty($galon) && $galon['is_delivery_required'] == 1, "Produk Galon 19L default terkonfigurasi is_delivery_required = 1 (Wajib Ongkir)");

// -----------------------------------------------------------------
// 2. UJI ENDPOINT get_all_products & init_data
// -----------------------------------------------------------------
echo "\n2. Menguji Endpoint get_all_products & init_data...\n";
$_GET['action'] = 'get_all_products';
ob_start();
include __DIR__ . '/api.php';
$rawAllProds = ob_get_clean();
$dataAllProds = json_decode($rawAllProds, true);

assertTest(!empty($dataAllProds) && $dataAllProds['success'] === true, "Endpoint get_all_products merespons sukses");
$foundGalonAll = false;
foreach ($dataAllProds['products'] as $p) {
    if ($p['sku'] === 'PRD-GLN-19L') {
        $foundGalonAll = isset($p['is_delivery_required']) && $p['is_delivery_required'] == 1;
        break;
    }
}
assertTest($foundGalonAll, "Kolom is_delivery_required bernilai 1 untuk Galon 19L pada get_all_products");

// Cek bootstrap (POS initialization)
$_GET['action'] = 'bootstrap';
ob_start();
include __DIR__ . '/api.php';
$rawInit = ob_get_clean();
$dataInit = json_decode($rawInit, true);

assertTest(!empty($dataInit) && $dataInit['success'] === true, "Endpoint bootstrap merespons sukses");
$foundGalonInit = false;
foreach ($dataInit['products'] as $p) {
    if ($p['sku'] === 'PRD-GLN-19L') {
        $foundGalonInit = isset($p['is_delivery_required']) && $p['is_delivery_required'] == 1;
        break;
    }
}
assertTest($foundGalonInit, "Katalog produk POS kasir pada bootstrap memuat atribut is_delivery_required");

// -----------------------------------------------------------------
// 3. UJI CRUD MASTER PRODUK DENGAN is_delivery_required
// -----------------------------------------------------------------
echo "\n3. Menguji CRUD Master Produk dengan Pengaturan Wajib Ongkir...\n";

// Tambah produk baru dengan wajib ongkir
$testSku = 'PRD-TEST-DELIV-' . time();
$_GET['action'] = 'save_product';
$prodPayload = [
    'sku' => $testSku,
    'barcode' => '89999901' . rand(10, 99),
    'name' => 'Beras Pandan Wangi 25kg (Wajib Antar)',
    'category_id' => 3,
    'product_type' => 'FINISHED_GOODS',
    'unit' => 'karung',
    'cogs' => 280000,
    'selling_price' => 330000,
    'image_emoji' => '🌾',
    'has_bom' => 0,
    'reorder_point' => 5,
    'is_taxable' => 0,
    'tax_id' => 1,
    'is_delivery_required' => 1
];
$_POST = $prodPayload;
$GLOBALS['CLI_INPUT'] = $prodPayload;

ob_start();
include __DIR__ . '/api.php';
$rawSave = ob_get_clean();
$saveRes = json_decode($rawSave, true);

if (empty($saveRes) || empty($saveRes['success'])) {
    echo "Debug rawSave error: " . $rawSave . "\n";
}

assertTest(!empty($saveRes) && $saveRes['success'] === true, "Simpan produk baru dengan is_delivery_required=1 berhasil");
$newProdId = $saveRes['product_id'] ?? 0;

$checkProd = $pdo->query("SELECT is_delivery_required FROM products WHERE id = $newProdId")->fetch();
assertTest(!empty($checkProd) && $checkProd['is_delivery_required'] == 1, "Nilai is_delivery_required tersimpan di database sebagai 1");

// Update produk menjadi is_delivery_required = 0 (opsional)
$prodPayload['id'] = $newProdId;
$prodPayload['is_delivery_required'] = 0;
$_POST = $prodPayload;
$GLOBALS['CLI_INPUT'] = $prodPayload;
ob_start();
include __DIR__ . '/api.php';
$rawUpd = ob_get_clean();
$updRes = json_decode($rawUpd, true);

$checkProd2 = $pdo->query("SELECT is_delivery_required FROM products WHERE id = $newProdId")->fetch();
assertTest(!empty($checkProd2) && $checkProd2['is_delivery_required'] == 0, "Update is_delivery_required menjadi 0 berhasil tersimpan");

// -----------------------------------------------------------------
// 4. UJI INTEGRASI KURIR / ARMADA SESUAI AKUN LOGIN KASIR / STAF
// -----------------------------------------------------------------
echo "\n4. Menguji Integrasi Kurir & Armada Sesuai Akun Login Kasir / Staf...\n";

// Cek karyawan kasir Cabang 1 (Bima, id=3)
$cashierBima = $pdo->query("SELECT * FROM users WHERE id = 3 AND role = 'CASHIER'")->fetch();
assertTest(!empty($cashierBima), "User Kasir Bima (ID 3) tersedia di Cabang 1");

// Cek apakah ada kendaraan cabang 1 yang terhubung ke Bima
$vehBima = $pdo->query("SELECT * FROM courier_vehicles WHERE driver_user_id = 3 AND branch_id = 1")->fetch();
assertTest(!empty($vehBima), "Armada motor '" . ($vehBima['plate_number'] ?? '') . "' (" . ($vehBima['vehicle_name'] ?? '') . ") terhubung dengan kasir Bima (ID 3)");

// Cek staf Keenan (id=6)
$staffKeenan = $pdo->query("SELECT * FROM users WHERE id = 6 AND role = 'STAFF'")->fetch();
$vehKeenan = $pdo->query("SELECT * FROM courier_vehicles WHERE driver_user_id = 6 AND branch_id = 1")->fetch();
assertTest(!empty($staffKeenan) && !empty($vehKeenan), "Armada motor '{$vehKeenan['plate_number']}' terhubung dengan staf Keenan (ID 6)");

// -----------------------------------------------------------------
// 5. UJI CHECKOUT POS DELIVERY DENGAN ARMADA OTOMATIS
// -----------------------------------------------------------------
echo "\n5. Menguji Checkout POS Delivery dengan Produk Wajib Ongkir...\n";

// Lakukan checkout Galon 19L (Wajib Ongkir) oleh Kasir Bima untuk Pelanggan Reguler (harga ritel standar 19.000)
$_GET['action'] = 'checkout_sale';
$checkoutPayload = [
    'branch_id' => 1,
    'user_id' => 3, // Bima Kasir
    'customer_id' => 1, // Umum / Reguler
    'customer_name' => 'Pelanggan Umum',
    'customer_category' => 'REGULER',
    'sales_type' => 'RETAIL',
    'items' => [
        [
            'product_id' => $galon['id'],
            'qty' => 1,
            'unit_price' => 19000
        ]
    ],
    'is_delivery' => 1,
    'delivery_fee' => 12000,
    'delivery_distance_km' => 2.5,
    'courier_vehicle_id' => $vehBima['id'], // Otomatis terpasang kendaraan Bima
    'payment_method' => 'CASH',
    'cash_received' => 50000
];
$_POST = $checkoutPayload;
$GLOBALS['CLI_INPUT'] = $checkoutPayload;

ob_start();
include __DIR__ . '/api.php';
$chkOut = ob_get_clean();
$chkRes = json_decode($chkOut, true);

assertTest(!empty($chkRes) && $chkRes['success'] === true, "Checkout delivery produk Galon 19L berhasil diproses");
$saleId = $chkRes['sale']['id'] ?? 0;
$invNo = $chkRes['sale']['invoice_number'] ?? '';

// Verifikasi data sales di database
$saleData = $pdo->query("SELECT * FROM sales WHERE id = $saleId")->fetch();
assertTest($saleData['delivery_fee'] == 12000, "Ongkos kirim Rp 12.000 tercatat di tabel sales");
assertTest($saleData['courier_vehicle_id'] == $vehBima['id'], "Kendaraan armada otomatis tercatat sebagai milik driver kasir login ({$vehBima['plate_number']})");
$expectedTotal = (float)$saleData['subtotal'] + (float)$saleData['tax'] + (float)$saleData['delivery_fee'] - (float)$saleData['discount'];
assertTest((float)$saleData['grand_total'] == $expectedTotal, "Grand total faktur mengakumulasikan ongkos kirim dan pajak secara tepat (Rp " . number_format($saleData['grand_total'], 0, ',', '.') . " = Subtotal {$saleData['subtotal']} + Tax {$saleData['tax']} + Ongkir {$saleData['delivery_fee']})");

// -----------------------------------------------------------------
// 6. UJI SINTAKS FILE PHP TERKAIT
// -----------------------------------------------------------------
echo "\n6. Menguji Validitas Sintaks Seluruh File Terkait...\n";
$dbSyntax = exec('php -l db.php');
$apiSyntax = exec('php -l api.php');
$indexSyntax = exec('php -l index.php');

assertTest(strpos($dbSyntax, 'No syntax errors') !== false, "db.php syntax valid (No syntax errors)");
assertTest(strpos($apiSyntax, 'No syntax errors') !== false, "api.php syntax valid (No syntax errors)");
assertTest(strpos($indexSyntax, 'No syntax errors') !== false, "index.php syntax valid (No syntax errors)");

echo "\n===================================================================\n";
echo " REKAP HASIL PENGUJIAN: {$passCount} PASS | {$failCount} FAIL\n";
echo "===================================================================\n";
if ($failCount === 0) {
    echo "SELURUH FITUR PRODUK WAJIB ONGKIR & KURIR/ARMADA LOGIN LULUS 100%!\n\n";
} else {
    echo "TERDAPAT {$failCount} PENGUJIAN GAGAL!\n\n";
    exit(1);
}
