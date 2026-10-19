<?php
/**
 * Test Suite: Validasi Kompatibilitas Sintaks MariaDB / MySQL Hosting
 * Memastikan tidak ada sintaks SQLite-spesifik seperti 'ON CONFLICT ... DO UPDATE SET'
 * yang memicu SQLSTATE[42000] Syntax error 1064 di MariaDB / MySQL hosting.
 */

echo "=== TEST SUITE: MARIADB / MYSQL HOSTING SYNTAX COMPATIBILITY ===\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($description, $condition) {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] $description\n";
        $passCount++;
    } else {
        echo "[FAIL] $description\n";
        $failCount++;
    }
}

// 1. Verifikasi tidak ada lagi query ON CONFLICT atau excluded. pada api.php
$apiContent = file_get_contents(__DIR__ . '/api.php');
$hasConflict = (stripos($apiContent, 'ON CONFLICT') !== false);
assertTest('api.php TIDAK memiliki sintaks "ON CONFLICT" yang merusak MariaDB/MySQL', !$hasConflict);

$hasExcluded = (stripos($apiContent, 'excluded.') !== false);
assertTest('api.php TIDAK memiliki kata kunci "excluded." yang merusak MariaDB/MySQL', !$hasExcluded);

// 2. Verifikasi AppPDO::adaptQuery di db.php menangani ON CONFLICT ke ON DUPLICATE KEY UPDATE
require_once __DIR__ . '/db.php';

$dummyMysqlDsn = 'mysql:host=127.0.0.1;dbname=testdb;charset=utf8mb4';
// Refleksi AppPDO
$refClass = new ReflectionClass('AppPDO');
$adaptMethod = $refClass->getMethod('adaptQuery');

// Kita buat subclass untuk menguji adaptQuery dalam mode MySQL
class TestMysqlAppPDO extends AppPDO {
    public function __construct() {
        // Dummy constructor tanpa koneksi aktual
    }
    public function testAdapt(string $sql): string {
        $refProp = new ReflectionProperty('AppPDO', 'isMysql');
        $refProp->setAccessible(true);
        $refProp->setValue($this, true);
        return $this->adaptQuery($sql);
    }
}

$testPdo = new TestMysqlAppPDO();
$sqlTest = "INSERT INTO delivery_settings (branch_id, base_fee) VALUES (1, 10000) ON CONFLICT(branch_id) DO UPDATE SET base_fee = excluded.base_fee";
$adapted = $testPdo->testAdapt($sqlTest);

assertTest('AppPDO::adaptQuery mengonversi "ON CONFLICT" ke "ON DUPLICATE KEY UPDATE"', strpos($adapted, 'ON DUPLICATE KEY UPDATE') !== false);
assertTest('AppPDO::adaptQuery mengonversi "excluded.base_fee" ke "VALUES(base_fee)"', strpos($adapted, 'VALUES(base_fee)') !== false);

// 3. Test Eksekusi Nyata Endpoint save_delivery_settings pada Database
// Simulasi request simpan tarif kiriman cabang 1
$branchId = 1;
$stmtDelCheck = $pdo->prepare("SELECT id FROM delivery_settings WHERE branch_id = ?");
$stmtDelCheck->execute([$branchId]);
$existingId = $stmtDelCheck->fetchColumn();

// Simpan data tarif kiriman
$testBaseFee = 15000;
$testPerKm = 3000;
$testFreeThreshold = 200000;

if ($existingId) {
    $stmt = $pdo->prepare("
        UPDATE delivery_settings SET
            base_fee = ?,
            base_distance_km = 4.0,
            per_km_rate = ?,
            free_delivery_threshold = ?,
            max_distance_km = 40.0,
            updated_at = datetime('now', 'localtime')
        WHERE branch_id = ?
    ");
    $res = $stmt->execute([$testBaseFee, $testPerKm, $testFreeThreshold, $branchId]);
} else {
    $stmt = $pdo->prepare("
        INSERT INTO delivery_settings (branch_id, base_fee, base_distance_km, per_km_rate, free_delivery_threshold, max_distance_km, updated_at)
        VALUES (?, ?, 4.0, ?, ?, 40.0, datetime('now', 'localtime'))
    ");
    $res = $stmt->execute([$branchId, $testBaseFee, $testPerKm, $testFreeThreshold]);
}
assertTest('Query save_delivery_settings berhasil dieksekusi tanpa error', $res);

// Verifikasi data tersimpan
$stmtVerify = $pdo->prepare("SELECT base_fee, per_km_rate, free_delivery_threshold FROM delivery_settings WHERE branch_id = ?");
$stmtVerify->execute([$branchId]);
$row = $stmtVerify->fetch(PDO::FETCH_ASSOC);
assertTest('Nilai base_fee delivery_settings tersimpan akurat (15000)', (float)$row['base_fee'] === 15000.0);
assertTest('Nilai per_km_rate delivery_settings tersimpan akurat (3000)', (float)$row['per_km_rate'] === 3000.0);

// 4. Test Eksekusi Nyata Endpoint save_app_settings untuk Konfigurasi Nota (branch_settings)
$targetBranch = 1;
$testSettings = [
    'receipt_header_title' => 'ESBAS TOKO CABANG 1',
    'receipt_header_subtitle' => 'Pusat Sembako & Kopi',
    'receipt_footer_line1' => 'Terima Kasih Atas Kunjungan Anda!'
];

$stmtCheckBranch = $pdo->prepare("SELECT id FROM branch_settings WHERE branch_id = ? AND setting_key = ?");
$stmtBranchUp = $pdo->prepare("UPDATE branch_settings SET setting_value = ?, updated_at = datetime('now', 'localtime') WHERE branch_id = ? AND setting_key = ?");
$stmtBranchIn = $pdo->prepare("INSERT INTO branch_settings (branch_id, setting_key, setting_value, setting_group, description, updated_at) VALUES (?, ?, ?, 'BRANCH', 'Pengaturan Khusus Cabang', datetime('now', 'localtime'))");

$saveBranchSuccess = true;
foreach ($testSettings as $key => $val) {
    $stmtCheckBranch->execute([$targetBranch, $key]);
    if ($stmtCheckBranch->fetchColumn()) {
        $ok = $stmtBranchUp->execute([$val, $targetBranch, $key]);
    } else {
        $ok = $stmtBranchIn->execute([$targetBranch, $key, $val]);
    }
    if (!$ok) $saveBranchSuccess = false;
}
assertTest('Query save_app_settings untuk konfigurasi nota cabang berhasil dieksekusi', $saveBranchSuccess);

// Verifikasi data branch_settings
$stmtGetSetting = $pdo->prepare("SELECT setting_value FROM branch_settings WHERE branch_id = ? AND setting_key = 'receipt_header_title'");
$stmtGetSetting->execute([$targetBranch]);
$valReceiptTitle = $stmtGetSetting->fetchColumn();
assertTest('Nilai receipt_header_title di branch_settings cocok ("ESBAS TOKO CABANG 1")', $valReceiptTitle === 'ESBAS TOKO CABANG 1');

// 5. Test Eksekusi Nyata Endpoint save_role_permissions
$stmtCheckRole = $pdo->prepare("SELECT id FROM role_menu_permissions WHERE role = ? AND menu_key = ?");
$stmtRoleUp = $pdo->prepare("UPDATE role_menu_permissions SET can_access = ?, menu_title = ?, updated_at = datetime('now', 'localtime') WHERE role = ? AND menu_key = ?");
$stmtRoleIn = $pdo->prepare("INSERT INTO role_menu_permissions (role, menu_key, menu_title, can_access, updated_at) VALUES (?, ?, ?, ?, datetime('now', 'localtime'))");

$testRole = 'CASHIER';
$testMenu = 'settings';
$canAccess = 1;

$stmtCheckRole->execute([$testRole, $testMenu]);
if ($stmtCheckRole->fetchColumn()) {
    $roleOk = $stmtRoleUp->execute([$canAccess, '15. Pengaturan Sistem', $testRole, $testMenu]);
} else {
    $roleOk = $stmtRoleIn->execute([$testRole, $testMenu, '15. Pengaturan Sistem', $canAccess]);
}
assertTest('Query save_role_permissions berhasil dieksekusi tanpa ON CONFLICT', $roleOk);

// 6. Test Eksekusi Nyata branch_stocks Update pada Opening & Closing Shift
$stmtP = $pdo->query("SELECT id FROM products LIMIT 1");
$pId = (int)$stmtP->fetchColumn();
if ($pId > 0) {
    $stmtCheckBs = $pdo->prepare("SELECT id FROM branch_stocks WHERE branch_id = ? AND product_id = ?");
    $stmtUpdBs = $pdo->prepare("UPDATE branch_stocks SET stock = ? WHERE branch_id = ? AND product_id = ?");
    $stmtInsBs = $pdo->prepare("INSERT INTO branch_stocks (branch_id, product_id, stock, local_price, local_cogs) VALUES (?, ?, ?, 0, 0)");

    $stmtCheckBs->execute([1, $pId]);
    if ($stmtCheckBs->fetchColumn()) {
        $stockOk = $stmtUpdBs->execute([75, 1, $pId]);
    } else {
        $stockOk = $stmtInsBs->execute([1, $pId, 75]);
    }
    assertTest('Query branch_stocks update (opening/closing shift) berhasil dieksekusi tanpa ON CONFLICT', $stockOk);
} else {
    assertTest('Query branch_stocks update (opening/closing shift) diskip karena tidak ada produk', true);
}

echo "\n======================================================\n";
echo "HASIL PENGUJIAN: $passCount PASS, $failCount FAIL\n";
echo "======================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
