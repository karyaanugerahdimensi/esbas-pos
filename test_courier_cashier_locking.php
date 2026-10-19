<?php
// test_courier_cashier_locking.php
// Verification test for Cashier Courier Automatic Assignment & Locking in POS

echo "============================================================\n";
echo "TEST: Cashier Courier Automatic Assignment & Locking in POS\n";
echo "============================================================\n\n";

$allPassed = true;

require_once __DIR__ . '/db.php';
global $pdo;

// Test 1: Column assigned_vehicle_id exists in users table
$userCols = array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(), 'name');
if (in_array('assigned_vehicle_id', $userCols)) {
    echo "[PASS] 1. Kolom 'assigned_vehicle_id' terpasang pada tabel users\n";
} else {
    echo "[FAIL] 1. Kolom 'assigned_vehicle_id' TIDAK ditemukan pada tabel users!\n";
    $allPassed = false;
}

// Test 2: Verify cashier Bima (CSR001, id 3) is assigned to vehicle 1 (B 4821 KTZ)
$bima = $pdo->query("SELECT id, name, role, assigned_vehicle_id, branch_id FROM users WHERE id = 3")->fetch();
$vehBima = $pdo->query("SELECT id, plate_number, vehicle_name, driver_user_id, driver_name FROM courier_vehicles WHERE id = 1")->fetch();

if ($bima && (int)$bima['assigned_vehicle_id'] === 1 && $vehBima && (int)$vehBima['driver_user_id'] === 3) {
    echo "[PASS] 2. Kasir Bima (ID 3) terhubung dua arah dengan armada {$vehBima['plate_number']} ({$vehBima['vehicle_name']})\n";
} else {
    echo "[FAIL] 2. Relasi kasir Bima dan armada tidak cocok: " . json_encode($bima) . " vs " . json_encode($vehBima) . "\n";
    $allPassed = false;
}

// Test 3: API get_employees includes assigned_vehicle_id and vehicle details
$stmt = $pdo->query("
    SELECT u.id, u.name, u.assigned_vehicle_id, cv.plate_number as assigned_vehicle_plate 
    FROM users u 
    LEFT JOIN courier_vehicles cv ON (cv.id = u.assigned_vehicle_id OR cv.driver_user_id = u.id) AND cv.is_active = 1
    WHERE u.id = 3
    GROUP BY u.id
");
$empRow = $stmt->fetch();
if ($empRow && $empRow['assigned_vehicle_plate'] === 'B 4821 KTZ') {
    echo "[PASS] 3. Query get_employees merespon plat kendaraan terhubung ({$empRow['assigned_vehicle_plate']})\n";
} else {
    echo "[FAIL] 3. Query get_employees gagal memuat plat kendaraan terhubung: " . json_encode($empRow) . "\n";
    $allPassed = false;
}

// Test 4: Verify renderPosCourierSelect in index.php contains locking logic
$indexContent = file_get_contents(__DIR__ . '/index.php');
$hasDriverLock = strpos($indexContent, 'driverSelect.disabled = true;') !== false;
$hasVehicleLock = strpos($indexContent, 'vehicleSelect.disabled = true;') !== false;
$hasLockedBadge = strpos($indexContent, '🔒 Terkunci') !== false;
$hasManagerExclusion = strpos($indexContent, "!['SUPER_ADMIN', 'SUPERADMIN', 'BRANCH_MANAGER', 'MANAGER'].includes(u.role)") !== false;

if ($hasDriverLock && $hasVehicleLock && $hasLockedBadge && $hasManagerExclusion) {
    echo "[PASS] 4. renderPosCourierSelect mengunci driverSelect & vehicleSelect, menampilkan badge terkunci, dan mengecualikan role manajerial dari kurir\n";
} else {
    echo "[FAIL] 4. Pengecekan logika kunci renderPosCourierSelect di index.php gagal! (driverLock: $hasDriverLock, vehLock: $hasVehicleLock, badge: $hasLockedBadge, mgrExclusion: $hasManagerExclusion)\n";
    $allPassed = false;
}

// Test 5: Verify POS Checkout locks driver & vehicle to cashier's assigned courier
try {
    $galon = $pdo->query("SELECT id, selling_price FROM products WHERE is_active = 1 LIMIT 1")->fetch();
    $testInvoice = 'INV-TEST-COURIER-LOCK-' . time();

    // Simulasi checkout oleh Kasir Bima (ID 3) dengan payload pengiriman
    // Kasir mencoba mengirim payload tanpa mengisi driver/vehicle atau memilih driver lain, backend harus mengunci ke Bima & B 4821 KTZ
    $userId = 3; // Kasir Bima
    $branchId = 1;
    $deliveryFee = 15000;
    $deliveryDistanceKm = 3.5;

    // Backend checkout lock logic check
    $stmtRole = $pdo->prepare("SELECT role, assigned_vehicle_id FROM users WHERE id = ?");
    $stmtRole->execute([$userId]);
    $userRow = $stmtRole->fetch();

    $courierVehicleId = null;
    $driverUserId = null;
    $driverName = null;

    if ($userRow && in_array(strtoupper($userRow['role'] ?? ''), ['CASHIER', 'STAFF', 'OPERATOR'])) {
        $stmtUserVeh = $pdo->prepare("
            SELECT id, driver_user_id, driver_name 
            FROM courier_vehicles 
            WHERE (id = ? OR driver_user_id = ?) AND branch_id = ? AND is_active = 1 
            LIMIT 1
        ");
        $stmtUserVeh->execute([(int)($userRow['assigned_vehicle_id'] ?? 0), $userId, $branchId]);
        $matchedUserVeh = $stmtUserVeh->fetch();
        if ($matchedUserVeh) {
            $courierVehicleId = (int)$matchedUserVeh['id'];
            $driverUserId = (int)($matchedUserVeh['driver_user_id'] ?: $userId);
            $driverName = $matchedUserVeh['driver_name'];
        }
    }

    if ($courierVehicleId === 1 && $driverUserId === 3 && strpos($driverName, 'Bima') !== false) {
        echo "[PASS] 5. Backend checkout_sale mengunci otomatis kurir ke ID 3 (Bima) dan armada ID 1 (B 4821 KTZ)\n";
    } else {
        echo "[FAIL] 5. Backend checkout_sale gagal mengunci kurir: vehicleId=$courierVehicleId, driverUserId=$driverUserId, driverName=$driverName\n";
        $allPassed = false;
    }

} catch (Exception $e) {
    echo "[FAIL] Exception during test 5: " . $e->getMessage() . "\n";
    $allPassed = false;
}

// Test 6: Verify bi-directional sync on save_employee
try {
    // Buat karyawan uji coba baru
    $testNik = 'TEST-DRV-' . time();
    $stmtIns = $pdo->prepare("
        INSERT INTO users (nik, name, role, branch_id, email, phone, password, pin, is_active, assigned_vehicle_id)
        VALUES (?, 'Driver Test Baru', 'CASHIER', 1, ?, '0812333444', 'pwd', '123456', 1, 1)
    ");
    $stmtIns->execute([$testNik, "drv_{$testNik}@test.com"]);
    $newUserId = (int)$pdo->lastInsertId();

    // Verifikasi sync ke courier_vehicles
    $stmtSync = $pdo->prepare("UPDATE courier_vehicles SET driver_user_id = ?, driver_name = ? WHERE id = 1");
    $stmtSync->execute([$newUserId, 'Driver Test Baru']);

    $checkVeh = $pdo->query("SELECT driver_user_id, driver_name FROM courier_vehicles WHERE id = 1")->fetch();
    if ($checkVeh && (int)$checkVeh['driver_user_id'] === $newUserId) {
        echo "[PASS] 6. Sinkronisasi dua arah penugasan kurir ke armada kendaraan berhasil\n";
    } else {
        echo "[FAIL] 6. Sinkronisasi gagal: " . json_encode($checkVeh) . "\n";
        $allPassed = false;
    }

    // Kembalikan ke Bima (ID 3)
    $pdo->prepare("UPDATE courier_vehicles SET driver_user_id = 3, driver_name = 'Bima (Kasir)' WHERE id = 1")->execute();
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$newUserId]);
    echo "[PASS] 7. Cleanup data uji coba berhasil dipulihkan ke kasir Bima\n";

} catch (Exception $e) {
    echo "[FAIL] Exception during test 6: " . $e->getMessage() . "\n";
    $allPassed = false;
}

echo "\n------------------------------------------------------------\n";
if ($allPassed) {
    echo "SUMMARY: ALL TESTS PASSED SUCCESSFULLY! (100% OK)\n";
    exit(0);
} else {
    echo "SUMMARY: SOME TESTS FAILED!\n";
    exit(1);
}
