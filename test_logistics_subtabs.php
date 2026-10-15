<?php
/**
 * Test Suite: Modul 10 - Kurir, Logistik, Peta Geospasial & Armada Motor (5 Sub-tab Navigation)
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

$pdo = new PDO('sqlite:' . __DIR__ . '/database.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=========================================================\n";
echo "TEST SUITE: LOGISTIK & 5 SUB-TAB VERIFIKASI\n";
echo "=========================================================\n\n";

function runTest($name, $callback) {
    echo "• Menguji [{$name}] ... ";
    try {
        $callback();
        echo "✅ PASS\n";
    } catch (Exception $e) {
        echo "❌ FAIL: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function callApi($getParams, $postData = null) {
    global $pdo;
    $_GET = $getParams;
    $_POST = $postData ?: [];
    $_SERVER['REQUEST_METHOD'] = $postData !== null ? 'POST' : 'GET';
    
    ob_start();
    include __DIR__ . '/api.php';
    $output = ob_get_clean();
    return json_decode($output, true);
}

// -------------------------------------------------------------
// Test 1: Sub-tab 1 - Armada Kendaraan Kurir
// -------------------------------------------------------------
runTest("Sub-tab 1: Endpoint get_courier_vehicles & Data Armada", function() {
    $data = callApi(['action' => 'get_courier_vehicles']);

    if (!$data || empty($data['success'])) {
        throw new Exception("Response API get_courier_vehicles gagal atau tidak valid");
    }
    if (empty($data['vehicles'])) {
        throw new Exception("Data armada kendaraan kosong");
    }
    $first = $data['vehicles'][0];
    if (empty($first['plate_number']) || empty($first['vehicle_name'])) {
        throw new Exception("Field plat nomor atau nama kendaraan tidak valid");
    }
    echo "[Armada: " . count($data['vehicles']) . " unit, Contoh: {$first['plate_number']} ({$first['vehicle_name']})] ";
});

// -------------------------------------------------------------
// Test 2: Sub-tab 2 - Log Bensin BBM & Kilometer Motor
// -------------------------------------------------------------
runTest("Sub-tab 2: Endpoint get_fuel_logs dengan dual keys fuel_logs & logs", function() {
    $data = callApi(['action' => 'get_fuel_logs']);

    if (!$data || empty($data['success'])) {
        throw new Exception("Response API get_fuel_logs gagal");
    }
    if (!isset($data['fuel_logs']) || !isset($data['logs'])) {
        throw new Exception("Response tidak memiliki dual keys fuel_logs dan logs");
    }
    if (count($data['fuel_logs']) !== count($data['logs'])) {
        throw new Exception("Ukuran data fuel_logs dan logs tidak sinkron");
    }
    $totalBiaya = array_reduce($data['fuel_logs'], function($acc, $row) {
        return $acc + (float)$row['total_cost'];
    }, 0);
    echo "[Riwayat BBM: " . count($data['fuel_logs']) . " transaksi, Total Biaya: Rp " . number_format($totalBiaya, 0, ',', '.') . "] ";
});

// -------------------------------------------------------------
// Test 3: Sub-tab 3 - Catatan Servis & Beban Biaya Laba Rugi
// -------------------------------------------------------------
runTest("Sub-tab 3: Endpoint get_service_logs dengan dual keys service_logs & logs", function() {
    $data = callApi(['action' => 'get_service_logs']);

    if (!$data || empty($data['success'])) {
        throw new Exception("Response API get_service_logs gagal");
    }
    if (!isset($data['service_logs']) || !isset($data['logs'])) {
        throw new Exception("Response tidak memiliki dual keys service_logs dan logs");
    }
    $totalBiayaServis = array_reduce($data['service_logs'], function($acc, $row) {
        return $acc + (float)$row['cost'];
    }, 0);
    echo "[Riwayat Servis: " . count($data['service_logs']) . " catatan, Total Biaya: Rp " . number_format($totalBiayaServis, 0, ',', '.') . "] ";
});

// -------------------------------------------------------------
// Test 4: Sub-tab 4 - Konfigurasi Tarif Biaya Kirim Dinamis Cabang
// -------------------------------------------------------------
runTest("Sub-tab 4: Endpoint get_delivery_settings", function() {
    $data = callApi(['action' => 'get_delivery_settings', 'branch_id' => 1]);

    if (!$data || empty($data['success']) || !isset($data['setting'])) {
        throw new Exception("Gagal membaca pengaturan ongkir dinamis cabang 1");
    }
    $s = $data['setting'];
    if ((float)$s['base_fee'] <= 0 || (float)$s['base_distance_km'] <= 0) {
        throw new Exception("Konfigurasi tarif dasar tidak wajar");
    }
    echo "[Base Fee: Rp " . number_format($s['base_fee'], 0, ',', '.') . ", Base KM: {$s['base_distance_km']} km, Per KM: Rp " . number_format($s['per_km_rate'], 0, ',', '.') . "] ";
});

// -------------------------------------------------------------
// Test 5: Sub-tab 5 - Peta Geospasial & Kalkulasi Tarif Haversine
// -------------------------------------------------------------
runTest("Sub-tab 5: Endpoint calculate_delivery_fee rute Cabang 1 ke Pelanggan", function() use ($pdo) {
    $cust = $pdo->query("SELECT id, name, latitude, longitude FROM customers WHERE latitude IS NOT NULL AND longitude IS NOT NULL LIMIT 1")->fetch();
    if (!$cust) {
        throw new Exception("Tidak ada pelanggan dengan koordinat valid untuk pengujian peta");
    }

    $data = callApi([
        'action' => 'calculate_delivery_fee',
        'branch_id' => 1,
        'customer_id' => $cust['id'],
        'subtotal' => 50000
    ]);

    if (!$data || empty($data['success'])) {
        throw new Exception("Kalkulasi ongkir dinamis geospasial gagal: " . ($data['message'] ?? 'Unknown error'));
    }
    if (!isset($data['distance_km']) || !isset($data['delivery_fee'])) {
        throw new Exception("Hasil kalkulasi jarak atau ongkir tidak ada");
    }
    echo "[Tujuan: {$cust['name']}, Jarak: {$data['distance_km']} km, Ongkir: Rp " . number_format($data['delivery_fee'], 0, ',', '.') . "] ";
});

// -------------------------------------------------------------
// Test 6: Verifikasi Kode Frontend index.php
// -------------------------------------------------------------
runTest("Verifikasi Frontend: Integrasi JavaScript sub-tab dan ID DOM di index.php", function() {
    $html = file_get_contents(__DIR__ . '/index.php');

    // 1. switchLogisticsSubtab dan setLogisticsSubtab
    if (strpos($html, 'function switchLogisticsSubtab') === false) {
        throw new Exception("Fungsi switchLogisticsSubtab tidak ditemukan di index.php");
    }
    if (strpos($html, 'function setLogisticsSubtab') === false) {
        throw new Exception("Fungsi setLogisticsSubtab tidak ditemukan di index.php");
    }

    // 2. Button IDs matching
    $buttons = ['subtab-btn-vehicles', 'subtab-btn-fuel', 'subtab-btn-service', 'subtab-btn-settings', 'subtab-btn-maps'];
    foreach ($buttons as $btn) {
        if (strpos($html, "id=\"{$btn}\"") === false) {
            throw new Exception("Tombol sub-tab ID '{$btn}' tidak ditemukan di HTML index.php");
        }
    }

    // 3. View Containers matching
    $views = ['subtab-logistics-vehicles', 'subtab-logistics-fuel', 'subtab-logistics-service', 'subtab-logistics-settings', 'subtab-logistics-maps'];
    foreach ($views as $view) {
        if (strpos($html, "id=\"{$view}\"") === false) {
            throw new Exception("Container sub-tab view ID '{$view}' tidak ditemukan di HTML index.php");
        }
    }

    // 4. Table bodies matching
    if (strpos($html, 'fuel-logs-table-body') === false) {
        throw new Exception("Tabel fuel-logs-table-body tidak ditemukan");
    }
    if (strpos($html, 'service-logs-table-body') === false) {
        throw new Exception("Tabel service-logs-table-body tidak ditemukan");
    }
    if (strpos($html, 'renderVehiclesTable') === false) {
        throw new Exception("Fungsi renderVehiclesTable tidak ditemukan");
    }
    if (strpos($html, 'updateDeliverySimulator') === false) {
        throw new Exception("Fungsi updateDeliverySimulator tidak ditemukan");
    }

    echo "[Seluruh 5 ID sub-tab, 5 Button sub-tab, Tabel body DOM, dan JS Handlers valid 100%] ";
});

echo "\n=========================================================\n";
echo "🎉 SELURUH PENGUJIAN LOGISTIK & 5 SUB-TAB BERHASIL 100%!\n";
echo "=========================================================\n";
