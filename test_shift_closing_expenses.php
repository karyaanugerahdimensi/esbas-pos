<?php
/**
 * Test Suite: Shift Closing Expenses, Cash Advances & Meal Allowances Integration
 * Menguji penambahan opsi biaya beban toko, kasbon karyawan, dan uang makan karyawan
 * saat closing shift kasir, serta integrasinya ke Beban Operasional, Laba Rugi, dan Slip Gaji.
 */

require_once __DIR__ . '/db.php';

echo "========================================================\n";
echo "TEST SUITE: CLOSING SHIFT DISBURSEMENTS INTEGRATION\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $message) {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $message\n";
        $passCount++;
    } else {
        echo " [FAIL] $message\n";
        $failCount++;
    }
}

function callApiRunner($action, $data = [], $get = []) {
    $script = tempnam(sys_get_temp_dir(), 'api_test_') . '.php';
    $getArr = var_export(array_merge(['action' => $action], $get), true);
    $dataArr = var_export($data, true);
    $code = "<?php
\$_GET = $getArr;
\$GLOBALS['CLI_INPUT'] = $dataArr;
require " . var_export(__DIR__ . '/api.php', true) . ";
";
    file_put_contents($script, $code);
    $output = shell_exec("php " . escapeshellarg($script));
    @unlink($script);
    return json_decode($output, true) ?: ['raw' => $output];
}

// 1. Setup Test Branch & Test Users
$branchId = 1;
$stmtU = $pdo->query("SELECT id FROM users WHERE branch_id = 1 AND is_active = 1 LIMIT 2");
$users = $stmtU->fetchAll(PDO::FETCH_COLUMN);
if (count($users) < 2) {
    $cashierId = 1;
    $empId = 3;
} else {
    $cashierId = (int)$users[0];
    $empId = (int)$users[1];
}

// 2. Buat Shift Kasir Baru (OPEN)
$initialCash = 300000;
$stmtShift = $pdo->prepare("
    INSERT INTO cashier_shifts (branch_id, user_id, start_time, initial_cash, status, shift_number, shift_name)
    VALUES (?, ?, datetime('now', 'localtime'), ?, 'OPEN', 1, 'Shift Uji Kasir')
");
$stmtShift->execute([$branchId, $cashierId, $initialCash]);
$shiftId = (int)$pdo->lastInsertId();
assertTest($shiftId > 0, "1. Shift kasir baru berhasil dibuat dengan ID: $shiftId dan modal awal Rp " . number_format($initialCash, 0, ',', '.'));

// 3. Tambah Penjualan Tunai untuk Shift Ini
$cashSalesAmount = 700000;
$stmtSale = $pdo->prepare("
    INSERT INTO sales (invoice_number, branch_id, user_id, shift_id, subtotal, grand_total, total_cogs, payment_method, status, created_at)
    VALUES (?, ?, ?, ?, ?, ?, 350000, 'CASH', 'COMPLETED', datetime('now', 'localtime'))
");
$invNum = 'TEST-INV-' . time() . '-' . rand(100, 999);
$stmtSale->execute([$invNum, $branchId, $cashierId, $shiftId, $cashSalesAmount, $cashSalesAmount]);
$saleId = (int)$pdo->lastInsertId();
assertTest($saleId > 0, "2. Transaksi penjualan tunai Rp " . number_format($cashSalesAmount, 0, ',', '.') . " berhasil dicatat pada shift $shiftId");

// 4. Test API Action: get_closing_shift_data
$closingDataRes = callApiRunner('get_closing_shift_data', [], ['shift_id' => $shiftId, 'branch_id' => $branchId]);
assertTest(!empty($closingDataRes['success']), "3. API get_closing_shift_data sukses mengembalikan respon valid");
assertTest(!empty($closingDataRes['expense_categories']), "4. Master kategori beban operasional tersedia di get_closing_shift_data (Total: " . count($closingDataRes['expense_categories'] ?? []) . " kategori)");
assertTest(!empty($closingDataRes['branch_employees']), "5. Daftar karyawan cabang tersedia di get_closing_shift_data (Total: " . count($closingDataRes['branch_employees'] ?? []) . " karyawan)");

// 5. Test API Action: close_shift dengan 3 Opsi Pengeluaran Kasir
// Skenario Pengeluaran:
// - Beban Operasional: Rp 50.000 (Beli Lakban & Pembersih)
// - Kasbon Karyawan: Rp 150.000 (Kasbon Darurat Karyawan ID: $empId)
// - Uang Makan Karyawan: Rp 25.000 (Uang Makan Shift Karyawan ID: $empId)
// Total Kas Keluar: 50.000 + 150.000 + 25.000 = Rp 225.000
// Modal Awal: Rp 300.000
// Penjualan Tunai: Rp 700.000
// Total Uang Masuk: Rp 1.000.000
// Ekspektasi Kas di Laci: 1.000.000 - 225.000 = Rp 775.000
// Kas Fisik Aktual yang Diserahkan: Rp 775.000 (Pas / Diff = 0)

$expenseAmount = 50000;
$loanAmount = 150000;
$mealAmount = 25000;
$expectedDisbursements = $expenseAmount + $loanAmount + $mealAmount; // 225.000
$expectedDrawerCash = ($initialCash + $cashSalesAmount) - $expectedDisbursements; // 775.000
$actualDrawerCash = 775000; // Pas

$closePayload = [
    'shift_id' => $shiftId,
    'actual_cash' => $actualDrawerCash,
    'closing_raw_mineral' => 10,
    'closing_raw_ro' => 15,
    'closing_raw_plastik' => 20,
    'shift_notes' => 'Tutup shift uji integrasi pengeluaran kasir',
    'is_final_shift' => 1,
    'expenses' => [
        [
            'category' => 'Biaya Operasional Toko',
            'amount' => $expenseAmount,
            'notes' => 'Beli lakban packing & pembersih galon'
        ]
    ],
    'loans' => [
        [
            'user_id' => $empId,
            'amount' => $loanAmount,
            'notes' => 'Kasbon tunai laci kasir darurat'
        ]
    ],
    'meals' => [
        [
            'user_id' => $empId,
            'amount' => $mealAmount,
            'notes' => 'Uang makan harian shift kerja'
        ]
    ]
];

$closeRes = callApiRunner('close_shift', $closePayload);
assertTest(!empty($closeRes['success']), "6. Endpoint close_shift berhasil memproses penutupan shift dengan pengeluaran kas laci");

// 6. Verifikasi Data cashier_shifts
$stmtClosedShift = $pdo->prepare("SELECT * FROM cashier_shifts WHERE id = ?");
$stmtClosedShift->execute([$shiftId]);
$closedShift = $stmtClosedShift->fetch();

assertTest($closedShift['status'] === 'CLOSED', "7. Status shift berhasil berubah menjadi 'CLOSED'");
assertTest((float)$closedShift['total_expenses'] === (float)$expenseAmount, "8. total_expenses shift tercatat akurat: Rp " . number_format($closedShift['total_expenses'], 0, ',', '.'));
assertTest((float)$closedShift['total_kasbon'] === (float)$loanAmount, "9. total_kasbon shift tercatat akurat: Rp " . number_format($closedShift['total_kasbon'], 0, ',', '.'));
assertTest((float)$closedShift['total_meal_allowance'] === (float)$mealAmount, "10. total_meal_allowance shift tercatat akurat: Rp " . number_format($closedShift['total_meal_allowance'], 0, ',', '.'));
assertTest((float)$closedShift['expected_cash'] === (float)$expectedDrawerCash, "11. expected_cash terhitung akurat: Rp " . number_format($closedShift['expected_cash'], 0, ',', '.') . " (Modal Rp $initialCash + Omzet Rp $cashSalesAmount - Pengeluaran Rp $expectedDisbursements)");
assertTest((float)$closedShift['cash_difference'] === 0.0, "12. cash_difference selisih kas fisik Rp 0 (SEIMBANG / PAS)");
assertTest(!empty($closedShift['shift_disbursements_json']), "13. shift_disbursements_json menyimpan rincian payload pengeluaran kasir");

// 7. Verifikasi Integrasi ke branch_expenses (Menu Beban Operasional & Laba Rugi)
$stmtExpCheck = $pdo->prepare("SELECT * FROM branch_expenses WHERE shift_id = ? AND source = 'SHIFT_EXPENSE'");
$stmtExpCheck->execute([$shiftId]);
$expRecord = $stmtExpCheck->fetch();

assertTest(!empty($expRecord), "14. Biaya beban toko berhasil dicatat ke tabel branch_expenses");
assertTest((float)$expRecord['amount'] === (float)$expenseAmount, "15. Nominal beban di branch_expenses cocok: Rp " . number_format($expRecord['amount'], 0, ',', '.'));

$stmtMealExpCheck = $pdo->prepare("SELECT * FROM branch_expenses WHERE shift_id = ? AND source = 'SHIFT_MEAL'");
$stmtMealExpCheck->execute([$shiftId]);
$mealExpRecord = $stmtMealExpCheck->fetch();

assertTest(!empty($mealExpRecord), "16. Uang makan karyawan berhasil dicatat ke branch_expenses sebagai beban operasional toko");
assertTest((float)$mealExpRecord['amount'] === (float)$mealAmount, "17. Nominal beban uang makan di branch_expenses cocok: Rp " . number_format($mealExpRecord['amount'], 0, ',', '.'));

// 8. Verifikasi Integrasi ke salary_loans (Pencatatan Gaji / Kasbon Karyawan)
$stmtLoanCheck = $pdo->prepare("SELECT * FROM salary_loans WHERE shift_id = ?");
$stmtLoanCheck->execute([$shiftId]);
$loanRecord = $stmtLoanCheck->fetch();

assertTest(!empty($loanRecord), "18. Kasbon karyawan berhasil dicatat ke tabel salary_loans");
assertTest((int)$loanRecord['user_id'] === $empId, "19. User ID kasbon cocok dengan karyawan penerima: User #$empId");
assertTest((float)$loanRecord['amount'] === (float)$loanAmount, "20. Nominal kasbon cocok: Rp " . number_format($loanRecord['amount'], 0, ',', '.'));
assertTest($loanRecord['status'] === 'ACTIVE', "21. Status pinjaman kasbon otomatis 'ACTIVE' siap dipotong pada slip gaji bulanan");

// 9. Verifikasi Integrasi ke employee_meal_allowances (Pencatatan Uang Makan Karyawan)
$stmtMealCheck = $pdo->prepare("SELECT * FROM employee_meal_allowances WHERE shift_id = ?");
$stmtMealCheck->execute([$shiftId]);
$mealRecord = $stmtMealCheck->fetch();

assertTest(!empty($mealRecord), "22. Uang makan karyawan berhasil dicatat ke employee_meal_allowances");
assertTest((int)$mealRecord['user_id'] === $empId, "23. User ID uang makan cocok dengan karyawan penerima: User #$empId");
assertTest((float)$mealRecord['amount'] === (float)$mealAmount, "24. Nominal uang makan cocok: Rp " . number_format($mealRecord['amount'], 0, ',', '.'));

// 10. Verifikasi Slip Gaji Karyawan (get_employee_payslip)
$monthCurrent = date('Y-m');
$payslipRes = callApiRunner('get_employee_payslip', [], ['user_id' => $empId, 'month' => $monthCurrent]);

assertTest(!empty($payslipRes['success']), "25. Endpoint get_employee_payslip sukses merespon");
$loanDeductionInSlip = (float)($payslipRes['payroll']['loan_deduction'] ?? 0);
assertTest($loanDeductionInSlip >= $loanAmount, "26. Slip gaji karyawan otomatis mencatat potongan kasbon kasir: Rp " . number_format($loanDeductionInSlip, 0, ',', '.') . " (minimal Rp " . number_format($loanAmount, 0, ',', '.') . ")");

$shiftMealInSlip = (float)($payslipRes['shift_meal_allowance'] ?? 0);
assertTest($shiftMealInSlip >= $mealAmount, "27. Slip gaji karyawan otomatis mencatat uang makan yang telah diterima di kasir: Rp " . number_format($shiftMealInSlip, 0, ',', '.') . " (minimal Rp " . number_format($mealAmount, 0, ',', '.') . ")");

// 11. Verifikasi Laporan Shift (get_shift_reports)
$today = date('Y-m-d');
$shiftReportsRes = callApiRunner('get_shift_reports', [], ['branch_id' => $branchId, 'date' => $today]);

assertTest(!empty($shiftReportsRes['success']), "28. Endpoint get_shift_reports sukses merespon");
$foundTestShift = null;
foreach ($shiftReportsRes['shifts'] ?? [] as $sr) {
    if ((int)$sr['id'] === $shiftId) {
        $foundTestShift = $sr;
        break;
    }
}
assertTest(!empty($foundTestShift), "29. Shift uji coba ditemukan pada laporan get_shift_reports");
assertTest((float)($foundTestShift['total_expenses'] ?? 0) === (float)$expenseAmount, "30. Laporan shift menampilkan total biaya beban toko: Rp " . number_format($expenseAmount, 0, ',', '.'));
assertTest((float)($foundTestShift['total_kasbon'] ?? 0) === (float)$loanAmount, "31. Laporan shift menampilkan total kasbon karyawan: Rp " . number_format($loanAmount, 0, ',', '.'));
assertTest((float)($foundTestShift['total_meal_allowance'] ?? 0) === (float)$mealAmount, "32. Laporan shift menampilkan total uang makan karyawan: Rp " . number_format($mealAmount, 0, ',', '.'));
assertTest((float)($foundTestShift['total_disbursements'] ?? 0) === (float)$expectedDisbursements, "33. Laporan shift menampilkan total pengeluaran kasir: Rp " . number_format($expectedDisbursements, 0, ',', '.'));

// Cleanup data pengujian
$pdo->exec("PRAGMA busy_timeout = 5000");
usleep(100000);
try {
    $pdo->prepare("DELETE FROM sales WHERE id = ?")->execute([$saleId]);
    $pdo->prepare("DELETE FROM branch_expenses WHERE shift_id = ?")->execute([$shiftId]);
    $pdo->prepare("DELETE FROM salary_loans WHERE shift_id = ?")->execute([$shiftId]);
    $pdo->prepare("DELETE FROM employee_meal_allowances WHERE shift_id = ?")->execute([$shiftId]);
    $pdo->prepare("DELETE FROM cashier_shifts WHERE id = ?")->execute([$shiftId]);
} catch (Exception $e) {
    // Abaikan jika database busy saat cleanup tes
}

echo "\n========================================================\n";
echo "HASIL PENGUJIAN AKHIR:\n";
echo "Total Berhasil (PASS): $passCount\n";
echo "Total Gagal    (FAIL): $failCount\n";
echo "Persentase Kelulusan : " . round(($passCount / ($passCount + $failCount)) * 100, 2) . "%\n";
echo "========================================================\n";

if ($failCount === 0) {
    echo "SELURUH FITUR PENGELUARAN SHIFT KASIR (BEBAN, KASBON & UANG MAKAN) LULUS 100%!\n";
    exit(0);
} else {
    echo "TERDAPAT PENGUJIAN YANG GAGAL!\n";
    exit(1);
}
