<?php
/**
 * Test Suite: Opening Shift & Sales Restriction
 * Memvalidasi fitur Opening Sales (Modal, Stok Awal, Stok Malam, Blokir Checkout jika shift belum buka, Closing Shift)
 */

require_once __DIR__ . '/db.php';

function test_log($msg) {
    echo "[TEST] $msg\n";
}

function assert_true($cond, $msg) {
    if (!$cond) {
        echo " [FAIL] $msg\n";
        exit(1);
    }
    echo " [PASS] $msg\n";
}

global $pdo;
$db = $pdo;

// Setup Branch dan User kasir untuk tes
$branch = $db->query("SELECT * FROM branches LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$branchId = (int)$branch['id'];

$user = $db->query("SELECT * FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$userId = (int)($user['id'] ?? 1);

test_log("Memulai pengujian dengan Branch ID: $branchId, User ID: $userId");

// Bersihkan shift aktif jika ada untuk branch & user ini
$db->exec("UPDATE cashier_shifts SET status = 'CLOSED' WHERE branch_id = $branchId AND status = 'OPEN'");

// TEST 1: Checkout POS HARUS GAGAL jika belum ada opening shift yang aktif
test_log("Test 1: Checkout saat shift belum buka (harus ditolak)");

// Ambil 1 produk aktif
$product = $db->query("SELECT * FROM products WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$prodId = (int)$product['id'];

// Simulasi panggilan checkout_sale via internal logic atau curl/request ke api.php
$postData = [
    'action' => 'checkout_sale',
    'branch_id' => $branchId,
    'user_id' => $userId,
    'payment_method' => 'cash',
    'cash_received' => 50000,
    'items' => [
        [
            'product_id' => $prodId,
            'qty' => 1,
            'unit_price' => 10000,
            'subtotal' => 10000
        ]
    ]
];

$ch = curl_init('http://127.0.0.1:8000/api.php?action=checkout_sale');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$resp = curl_exec($ch);
curl_close($ch);

$res = json_decode($resp, true);
assert_true(isset($res['success']) && $res['success'] === false, "Checkout tanpa shift aktif harus gagal");
$errText = $res['message'] ?? ($res['error'] ?? '');
assert_true(strpos($errText, 'Opening Sales') !== false || strpos($errText, 'Shift') !== false, "Error message menyebutkan shift kasir / Opening Sales: " . $errText);

// TEST 2: Ambil data opening shift (get_opening_shift_data)
test_log("Test 2: Mengambil data opening shift (get_opening_shift_data)");
$ch = curl_init("http://127.0.0.1:8000/api.php?action=get_opening_shift_data&branch_id=$branchId");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resp = curl_exec($ch);
curl_close($ch);

$res = json_decode($resp, true);
assert_true(isset($res['success']) && $res['success'] === true, "get_opening_shift_data harus berhasil");
assert_true(isset($res['raw_items']['RAW-089']), "Data produk RAW-089 mineral ditemukan");
assert_true(isset($res['raw_items']['RAW-020']), "Data produk RAW-020 ro ditemukan");
assert_true(isset($res['raw_items']['RAW-00092']), "Data produk RAW-00092 plastik ditemukan");
assert_true(isset($res['night_stock']), "Data night_stock ada di response");

test_log("RAW Mineral: " . ($res['raw_items']['RAW-089']['name'] ?? ''));
test_log("RAW RO: " . ($res['raw_items']['RAW-020']['name'] ?? ''));
test_log("RAW Plastik: " . ($res['raw_items']['RAW-00092']['name'] ?? ''));

// TEST 3: Buka shift (open_shift) dengan nilai modal dan stok awal
test_log("Test 3: Buka shift kasir (open_shift)");
$openData = [
    'branch_id' => $branchId,
    'user_id' => $userId,
    'initial_cash' => 300000,
    'opening_raw_mineral' => 120,
    'opening_raw_ro' => 85,
    'opening_raw_plastik' => 200,
    'closing_night_mineral' => 120,
    'closing_night_ro' => 85,
    'closing_night_plastik' => 200
];

$ch = curl_init('http://127.0.0.1:8000/api.php?action=open_shift');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($openData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$resp = curl_exec($ch);
curl_close($ch);

$res = json_decode($resp, true);
assert_true(isset($res['success']) && $res['success'] === true, "open_shift harus berhasil: " . ($res['message'] ?? ''));
$activeShiftId = (int)($res['shift']['id'] ?? ($res['data']['id'] ?? 0));
assert_true($activeShiftId > 0, "Shift ID yang dibuka harus valid: $activeShiftId");

// Verifikasi di DB
$stmt = $db->prepare("SELECT * FROM cashier_shifts WHERE id = ?");
$stmt->execute([$activeShiftId]);
$shiftRow = $stmt->fetch(PDO::FETCH_ASSOC);
assert_true($shiftRow['status'] === 'OPEN', "Status shift di database harus OPEN");
assert_true((int)$shiftRow['opening_raw_mineral'] === 120, "opening_raw_mineral tersimpan 120");
assert_true((int)$shiftRow['opening_raw_ro'] === 85, "opening_raw_ro tersimpan 85");
assert_true((int)$shiftRow['opening_raw_plastik'] === 200, "opening_raw_plastik tersimpan 200");

// TEST 4: Checkout POS SEKARANG HARUS BERHASIL karena shift sudah OPEN
test_log("Test 4: Checkout saat shift telah aktif (harus berhasil)");
$postData['shift_id'] = $activeShiftId;

$ch = curl_init('http://127.0.0.1:8000/api.php?action=checkout_sale');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$resp = curl_exec($ch);
curl_close($ch);

$res = json_decode($resp, true);
assert_true(isset($res['success']) && $res['success'] === true, "Checkout saat shift aktif harus berhasil: " . ($res['message'] ?? ''));
$saleId = (int)($res['sale']['id'] ?? ($res['data']['sale_id'] ?? 0));
assert_true($saleId > 0, "Sale ID harus valid: $saleId");

// TEST 5: Tutup shift malam (close_shift) dan simpan stok akhir malam
test_log("Test 5: Tutup shift (close_shift) dengan laporan stok malam");
$closeData = [
    'shift_id' => $activeShiftId,
    'user_id' => $userId,
    'actual_cash' => 310000,
    'closing_raw_mineral' => 110,
    'closing_raw_ro' => 75,
    'closing_raw_plastik' => 190,
    'notes' => 'Tutup shift malam - serah terima'
];

$ch = curl_init('http://127.0.0.1:8000/api.php?action=close_shift');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($closeData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$resp = curl_exec($ch);
curl_close($ch);

$res = json_decode($resp, true);
assert_true(isset($res['success']) && $res['success'] === true, "close_shift harus berhasil: " . ($res['error'] ?? ''));

// Verifikasi di DB
$stmt = $db->prepare("SELECT * FROM cashier_shifts WHERE id = ?");
$stmt->execute([$activeShiftId]);
$shiftClosedRow = $stmt->fetch(PDO::FETCH_ASSOC);
assert_true($shiftClosedRow['status'] === 'CLOSED', "Status shift harus CLOSED");
assert_true((int)$shiftClosedRow['closing_raw_mineral'] === 110, "closing_raw_mineral tersimpan 110");
assert_true((int)$shiftClosedRow['closing_raw_ro'] === 75, "closing_raw_ro tersimpan 75");
assert_true((int)$shiftClosedRow['closing_raw_plastik'] === 190, "closing_raw_plastik tersimpan 190");

// TEST 6: Buka shift berikutnya dan pastikan Stok Malam otomatis terisi dari closing shift sebelumnya!
test_log("Test 6: Cek get_opening_shift_data untuk shift berikutnya (prefill stok malam)");
$ch = curl_init("http://127.0.0.1:8000/api.php?action=get_opening_shift_data&branch_id=$branchId");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resp = curl_exec($ch);
curl_close($ch);

$res = json_decode($resp, true);
assert_true(isset($res['success']) && $res['success'] === true, "get_opening_shift_data berikutnya harus berhasil");
assert_true((int)$res['night_stock']['mineral'] === 110, "Prefill Tutup Mineral malam adalah 110");
assert_true((int)$res['night_stock']['ro'] === 75, "Prefill Tutup RO malam adalah 75");
assert_true((int)$res['night_stock']['plastik'] === 190, "Prefill Plastik ES malam adalah 190");

// Cleanup test transaction jika diperlukan
$db->exec("DELETE FROM sale_items WHERE sale_id = $saleId");
$db->exec("DELETE FROM sales WHERE id = $saleId");

echo "\n=========================================\n";
echo " SEMUA TEST OPENING SHIFT BERHASIL (100% PASS)\n";
echo "=========================================\n";
