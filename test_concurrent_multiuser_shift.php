<?php
/**
 * Test: Multi-User Concurrent POS Shift (Rekan Kasir Masuk Bersamaan Tidak Perlu Buka Shift Ulang)
 */

require 'db.php';

echo "============================================================\n";
echo "TEST: Concurrent Multi-User Shift Sharing at Same Branch\n";
echo "============================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($cond, $msg) {
    global $passCount, $failCount;
    if ($cond) {
        echo "[PASS] $msg\n";
        $passCount++;
    } else {
        echo "[FAIL] $msg\n";
        $failCount++;
    }
}

$branchId = 1;
// Pastikan tidak ada shift OPEN sebelumnya
$pdo->prepare("UPDATE cashier_shifts SET status = 'CLOSED' WHERE branch_id = ? AND status = 'OPEN'")->execute([$branchId]);

// 1. User A (User ID = 3, Muhamad Ismail / CSR001) membuka shift
echo "--- Langkah 1: User A (ID=3) Membuka Shift ---\n";
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['action'] = 'open_shift';
$input = [
    'branch_id' => $branchId,
    'user_id' => 3,
    'initial_cash' => 250000,
    'shift_name' => 'Shift 1 (Pagi)',
    'shift_number' => 1
];
ob_start();
include 'api.php';
$outputA = ob_get_clean();
$resA = json_decode($outputA, true);

assertTest(($resA['success'] ?? false) === true, "User A berhasil membuka Shift (ID: " . ($resA['shift']['id'] ?? 'none') . ")");
$activeShiftId = (int)($resA['shift']['id'] ?? 0);

// 2. User B (User ID = 1, Admin atau Kasir 2) memanggil bootstrap di Cabang yang SAMA
echo "\n--- Langkah 2: User B (ID=1) Masuk Bersamaan di Cabang yang Sama ---\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['action'] = 'bootstrap';
$_GET['branch_id'] = $branchId;
$_GET['user_id'] = 1; // User B yang berbeda!
unset($input);

ob_start();
include 'api.php';
$bootB = json_decode(ob_get_clean(), true);

assertTest(($bootB['success'] ?? false) === true, "User B berhasil bootstrap aplikasi");
assertTest(!empty($bootB['active_shift']), "User B otomatis menerima active_shift yang sudah dibuka di cabang tersebut");
assertTest(($bootB['active_shift']['id'] ?? 0) == $activeShiftId, "ID shift aktif User B cocok persis dengan shift yang dibuka User A ($activeShiftId)");
assertTest(($bootB['active_shift']['status'] ?? '') === 'OPEN', "Status shift aktif adalah 'OPEN'");

// 3. User B melakukan transaksi penjualan tanpa membuka shift
echo "\n--- Langkah 3: User B Melakukan Transaksi Penjualan (Tanpa Buka Shift) ---\n";
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['action'] = 'checkout_sale';
$input = [
    'branch_id' => $branchId,
    'user_id' => 1, // User B
    'shift_id' => $activeShiftId,
    'customer_id' => 1,
    'customer_name' => 'Pelanggan Walk-in',
    'customer_category' => 'REGULER',
    'sales_type' => 'RETAIL',
    'payment_method' => 'CASH',
    'cash_received' => 50000,
    'items' => [
        ['product_id' => 103, 'qty' => 1] // Aqua Refill 19 Liter
    ]
];

ob_start();
include 'api.php';
$saleRes = json_decode(ob_get_clean(), true);

$invNumber = $saleRes['sale']['invoice_number'] ?? '';
assertTest(($saleRes['success'] ?? false) === true, "User B berhasil checkout transaksi penjualan (Invoice: $invNumber)");

// Periksa ke database record transaksi penjualan
$stmtDbSale = $pdo->prepare("SELECT user_id, shift_id FROM sales WHERE invoice_number = ?");
$stmtDbSale->execute([$invNumber]);
$dbSale = $stmtDbSale->fetch();

assertTest(($dbSale['shift_id'] ?? 0) == $activeShiftId, "Transaksi di database tercatat di shift cabang aktif ($activeShiftId)");
assertTest(($dbSale['user_id'] ?? 0) == 1, "Transaksi di database tercatat atas nama User B (user_id = 1)");

// 4. User B memanggil open_shift (jika tidak sengaja menekan tombol buka shift)
echo "\n--- Langkah 4: User B Mencoba Memanggil open_shift ---\n";
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['action'] = 'open_shift';
$input = [
    'branch_id' => $branchId,
    'user_id' => 1,
    'initial_cash' => 200000
];
ob_start();
include 'api.php';
$openRes = json_decode(ob_get_clean(), true);

assertTest(($openRes['success'] ?? false) === true, "open_shift mengembalikan success=true tanpa melempar galat");
assertTest(($openRes['already_opened'] ?? false) === true, "open_shift mengindikasikan already_opened=true");
assertTest(($openRes['shift']['id'] ?? 0) == $activeShiftId, "open_shift mengembalikan data shift yang sedang aktif");

// 5. User B memanggil get_opening_shift_data
echo "\n--- Langkah 5: User B Memanggil get_opening_shift_data ---\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['action'] = 'get_opening_shift_data';
$_GET['branch_id'] = $branchId;
unset($input);

ob_start();
include 'api.php';
$opData = json_decode(ob_get_clean(), true);

assertTest(($opData['success'] ?? false) === true, "get_opening_shift_data mengembalikan success=true");
assertTest(($opData['has_active_open_shift'] ?? false) === true, "get_opening_shift_data mendeteksi has_active_open_shift=true");
assertTest(($opData['active_open_shift']['id'] ?? 0) == $activeShiftId, "get_opening_shift_data mengembalikan shift aktif yang sama");

// Cleanup test shift & test sale
$pdo->prepare("UPDATE cashier_shifts SET status = 'CLOSED' WHERE id = ?")->execute([$activeShiftId]);
if (!empty($invNumber)) {
    $pdo->prepare("DELETE FROM sale_items WHERE sale_id IN (SELECT id FROM sales WHERE invoice_number = ?)")->execute([$invNumber]);
    $pdo->prepare("DELETE FROM sales WHERE invoice_number = ?")->execute([$invNumber]);
}

echo "\n------------------------------------------------------------\n";
echo "SUMMARY: " . ($failCount === 0 ? "ALL $passCount TESTS PASSED (100% OK)" : "$failCount TESTS FAILED") . "\n";
echo "============================================================\n";
