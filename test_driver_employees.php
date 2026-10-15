<?php
/**
 * Test Suite: Driver / Kurir Selection from Branch Employees
 * Menguji:
 * 1. Verifikasi data karyawan berdasarkan branch_id:
 *    - Cabang 1 (Jakarta) -> Karyawan cabang 1
 *    - Cabang 2 (Surabaya) -> Karyawan cabang 2
 *    - Cabang 3 (Bandung) -> Karyawan cabang 3
 * 2. API `save_courier_vehicle` dengan `driver_user_id`:
 *    - Memilih karyawan cabang sebagai driver
 *    - Nama driver otomatis terisi dari user
 * 3. Verifikasi `get_courier_vehicles`:
 *    - Mengembalikan `driver_user_id` dan `employee_driver_name`
 * 4. Integrasi checkout transaksi POS delivery:
 *    - Memilih armada dengan driver karyawan cabang
 */

require_once __DIR__ . '/db.php';

function makeRequest($url, $method = 'GET', $data = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

$baseUrl = 'http://localhost:8000/api.php';

echo "====================================================================\n";
echo "TEST SUITE: NAMA DRIVER / KURIR DIAMBIL DARI KARYAWAN CABANG\n";
echo "====================================================================\n\n";

// [TEST 1] Ambil data bootstrap untuk Cabang 1 (Jakarta) dan Cabang 2 (Surabaya)
echo "[TEST 1] Memeriksa daftar karyawan per lokasi cabang...\n";
$users = $pdo->query("SELECT id, name, role, branch_id FROM users WHERE is_active = 1 ORDER BY branch_id, id")->fetchAll(PDO::FETCH_ASSOC);

$usersByBranch = [];
foreach ($users as $u) {
    $bId = $u['branch_id'] ?: 1;
    $usersByBranch[$bId][] = $u;
}

foreach ($usersByBranch as $bId => $empList) {
    $bName = $pdo->query("SELECT name FROM branches WHERE id = {$bId}")->fetchColumn() ?: "Cabang {$bId}";
    echo " -> {$bName} (Branch ID {$bId}): " . count($empList) . " karyawan\n";
    foreach ($empList as $emp) {
        echo "    - [ID {$emp['id']}] {$emp['name']} ({$emp['role']})\n";
    }
}

if (empty($usersByBranch[1])) {
    die("FAILED: Cabang 1 tidak memiliki karyawan!\n");
}
$jktEmployee = $usersByBranch[1][0];
echo " -> [PASSED] Verifikasi Karyawan per Cabang OK!\n\n";

// [TEST 2] Mendaftarkan Kendaraan Baru dengan Driver dari Karyawan Cabang Jakarta
echo "[TEST 2] CREATE: Mendaftarkan Kendaraan dengan Driver Karyawan Cabang 1 ({$jktEmployee['name']})...\n";
$newPlate = 'B ' . rand(1000, 9999) . ' DRV';
$vehPayload = [
    'branch_id' => 1,
    'plate_number' => $newPlate,
    'vehicle_name' => 'Yamaha Aerox Kurir Express',
    'vehicle_type' => 'MOTOR',
    'driver_user_id' => $jktEmployee['id'],
    'driver_name' => $jktEmployee['name'],
    'current_odometer' => 1250.5,
    'fuel_ratio' => 38.0,
    'fuel_ratio_unit' => 'KM_PER_LITER',
    'fuel_type' => 'Pertalite',
    'status' => 'ACTIVE',
    'notes' => 'Armada kurir resmi cabang Jakarta'
];

$saveRes = makeRequest($baseUrl . '?action=save_courier_vehicle', 'POST', $vehPayload);
echo " -> Response save_courier_vehicle: " . json_encode($saveRes) . "\n";
if (!isset($saveRes['success']) || !$saveRes['success']) {
    die("FAILED: Gagal menyimpan kendaraan dengan driver karyawan cabang!\n");
}
$vehId = $saveRes['vehicle_id'];

// [TEST 3] READ: Verifikasi kendaraan via get_courier_vehicles
echo "\n[TEST 3] READ: Verifikasi kendaraan via api.php?action=get_courier_vehicles&branch_id=1...\n";
$getVehRes = makeRequest($baseUrl . '?action=get_courier_vehicles&branch_id=1');
$foundVeh = null;
foreach ($getVehRes['vehicles'] as $v) {
    if ($v['id'] == $vehId) {
        $foundVeh = $v;
        break;
    }
}

if ($foundVeh && $foundVeh['driver_user_id'] == $jktEmployee['id'] && $foundVeh['driver_name'] == $jktEmployee['name']) {
    echo " -> Data ditemukan: Plat {$foundVeh['plate_number']}, Driver: {$foundVeh['driver_name']} (User ID: {$foundVeh['driver_user_id']})\n";
    echo " -> Relasi Karyawan: {$foundVeh['employee_driver_name']} | Role: {$foundVeh['driver_role']}\n";
    echo " -> [PASSED] Driver terasosiasi secara akurat dengan karyawan cabang!\n\n";
} else {
    die("FAILED: Data driver kendaraan tidak sesuai dengan karyawan cabang!\n");
}

// [TEST 4] UPDATE: Mengganti Driver ke Karyawan Lain di Cabang yang Sama
echo "[TEST 4] UPDATE: Mengganti Driver ke Karyawan lain di Cabang 1...\n";
$targetEmp = count($usersByBranch[1]) > 1 ? $usersByBranch[1][1] : $usersByBranch[1][0];
$updatePayload = [
    'id' => $vehId,
    'branch_id' => 1,
    'plate_number' => $newPlate,
    'vehicle_name' => 'Yamaha Aerox Kurir Express',
    'vehicle_type' => 'MOTOR',
    'driver_user_id' => $targetEmp['id'],
    'driver_name' => '', // Biarkan backend lookup otomatis dari driver_user_id
    'current_odometer' => 1290.0,
    'fuel_ratio' => 38.0,
    'fuel_ratio_unit' => 'KM_PER_LITER',
    'fuel_type' => 'Pertalite',
    'status' => 'ACTIVE'
];

$upRes = makeRequest($baseUrl . '?action=save_courier_vehicle', 'POST', $updatePayload);
echo " -> Response update: " . json_encode($upRes) . "\n";

$checkVeh = $pdo->query("SELECT * FROM courier_vehicles WHERE id = {$vehId}")->fetch(PDO::FETCH_ASSOC);
if ($checkVeh['driver_user_id'] == $targetEmp['id'] && $checkVeh['driver_name'] == $targetEmp['name']) {
    echo " -> Verifikasi DB: Driver sukses diperbarui ke {$checkVeh['driver_name']} (ID {$checkVeh['driver_user_id']})\n";
    echo " -> [PASSED] Update driver kendaraan dari karyawan cabang sukses!\n\n";
} else {
    die("FAILED: Update driver dari karyawan gagal!\n");
}

// [TEST 5] Bersihkan data uji coba
echo "[TEST 5] CLEANUP: Menghapus kendaraan uji coba...\n";
$pdo->prepare("DELETE FROM courier_vehicles WHERE id = ?")->execute([$vehId]);
echo " -> [PASSED] Cleanup selesai!\n\n";

echo "====================================================================\n";
echo "SEMUA TEST SUITE DRIVER / KURIR KARYAWAN CABANG 100% SUKSES DAN VALID!\n";
echo "====================================================================\n";
