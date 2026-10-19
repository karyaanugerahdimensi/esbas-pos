<?php
/**
 * Test Suite: MariaDB / MySQL Shift Closing & Date Syntax Compatibility
 * Version: 1.2.6
 */

require_once __DIR__ . '/db.php';

$passedTests = 0;
$totalTests = 0;

function assertCheck($condition, $description) {
    global $passedTests, $totalTests;
    $totalTests++;
    if ($condition) {
        echo "✅ PASS: {$description}\n";
        $passedTests++;
    } else {
        echo "❌ FAIL: {$description}\n";
    }
}

echo "=== MEMULAI TEST SINTAKS TUTUP SHIFT & MARIADB COMPATIBILITY ===\n\n";

// 1. Uji AppPDO adaptQuery saat isMysql = true
$pdo->setIsMysql(true);

$sql1 = "INSERT INTO water_meter_logs (branch_id, shift_id, user_id, log_date) VALUES (?, ?, ?, date('now', 'localtime'))";
$adapted1 = $pdo->adaptQuery($sql1);
assertCheck(strpos($adapted1, 'CURDATE()') !== false && strpos($adapted1, 'localtime') === false, "adaptQuery mengonversi date('now', 'localtime') menjadi CURDATE()");

$sql2 = "UPDATE cashier_shifts SET end_time = datetime('now', 'localtime') WHERE id = ?";
$adapted2 = $pdo->adaptQuery($sql2);
assertCheck(strpos($adapted2, 'NOW()') !== false && strpos($adapted2, 'localtime') === false, "adaptQuery mengonversi datetime('now', 'localtime') menjadi NOW()");

$sql3 = "SELECT COUNT(*) FROM cashier_shifts WHERE branch_id = ? AND date(start_time) = date('now', 'localtime')";
$adapted3 = $pdo->adaptQuery($sql3);
assertCheck(strpos($adapted3, 'CURDATE()') !== false && strpos($adapted3, 'localtime') === false, "adaptQuery mengonversi query hitung shift hari ini menjadi CURDATE()");

$sql4 = "SELECT time('now', 'localtime') as cur_time";
$adapted4 = $pdo->adaptQuery($sql4);
assertCheck(strpos($adapted4, 'CURTIME()') !== false && strpos($adapted4, 'localtime') === false, "adaptQuery mengonversi time('now', 'localtime') menjadi CURTIME()");

// Kembalikan isMysql sesuai driver asli
$pdo->setIsMysql($dbDriver === 'mysql');

// 2. Verifikasi kueri close_shift di api.php TIDAK lagi menyematkan literal date('now', 'localtime')
$apiContent = file_get_contents(__DIR__ . '/api.php');
// Ambil blok kode case 'close_shift'
$startPos = strpos($apiContent, "case 'close_shift':");
$endPos = strpos($apiContent, "case 'get_shift_reports':");
$closeBlock = substr($apiContent, $startPos, $endPos - $startPos);

assertCheck(strpos($closeBlock, "date('now', 'localtime')") === false, "close_shift TIDAK memiliki date('now', 'localtime') hardcoded");
assertCheck(strpos($closeBlock, "datetime('now', 'localtime')") === false, "close_shift TIDAK memiliki datetime('now', 'localtime') hardcoded");
assertCheck(strpos($closeBlock, '$today = date(\'Y-m-d\')') !== false, "close_shift menggunakan parameter waktu \$today dari PHP");
assertCheck(strpos($closeBlock, '$now = date(\'Y-m-d H:i:s\')') !== false, "close_shift menggunakan parameter waktu \$now dari PHP");

// 3. Verifikasi kueri open_shift di api.php
$startOpenPos = strpos($apiContent, "case 'open_shift':");
$endOpenPos = strpos($apiContent, "case 'close_shift':");
$openBlock = substr($apiContent, $startOpenPos, $endOpenPos - $startOpenPos);

assertCheck(strpos($openBlock, "date('now', 'localtime')") === false, "open_shift TIDAK memiliki date('now', 'localtime') hardcoded");
assertCheck(strpos($openBlock, "datetime('now', 'localtime')") === false, "open_shift TIDAK memiliki datetime('now', 'localtime') hardcoded");

// 4. Uji Eksekusi Aktual Alur Buka & Tutup Shift
$testBranchId = 1;
$testUserId = 1;
$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

// A. Insert Shift Baru (Simulasi Buka Shift)
$stmtIns = $pdo->prepare("
    INSERT INTO cashier_shifts (
        branch_id, user_id, start_time, initial_cash, status,
        opening_raw_mineral, opening_raw_ro, opening_raw_plastik,
        closing_night_mineral, closing_night_ro, closing_night_plastik,
        shift_notes, opening_items_json,
        shift_number, shift_name, shift_type, is_final_shift
    ) VALUES (?, ?, ?, ?, 'OPEN', 50, 50, 50, 45, 45, 45, 'Test Shift', null, 1, 'Shift 1 (Pagi)', 'SHIFT_1', 0)
");
$stmtIns->execute([$testBranchId, $testUserId, $now, 200000]);
$shiftId = (int)$pdo->lastInsertId();
assertCheck($shiftId > 0, "Shift uji berhasil dibuka di database (ID: {$shiftId})");

// B. Catat Pengeluaran, Kasbon, Uang Makan saat Tutup Shift
$stmtExp = $pdo->prepare("
    INSERT INTO branch_expenses (branch_id, category, amount, expense_date, source, shift_id, notes)
    VALUES (?, ?, ?, ?, 'SHIFT_EXPENSE', ?, ?)
");
$stmtExp->execute([$testBranchId, 'Operasional', 25000, $today, $shiftId, 'Uji beban shift']);

$stmtLoan = $pdo->prepare("
    INSERT INTO salary_loans (
        user_id, branch_id, loan_date, amount, reason, installment_months,
        monthly_deduction, status, remaining_amount, approved_by, approved_at, shift_id, notes
    ) VALUES (
        ?, ?, ?, ?, ?, 1,
        ?, 'ACTIVE', ?, ?, ?, ?, ?
    )
");
$stmtLoan->execute([$testUserId, $testBranchId, $today, 50000, 'Uji kasbon shift', 50000, 50000, $testUserId, $now, $shiftId, 'Catatan']);

$stmtMeal = $pdo->prepare("
    INSERT INTO employee_meal_allowances (shift_id, user_id, branch_id, amount, meal_date, notes)
    VALUES (?, ?, ?, ?, ?, ?)
");
$stmtMeal->execute([$shiftId, $testUserId, $testBranchId, 15000, $today, 'Uji uang makan']);

// C. Catat Water Meter Log
$stmtWML = $pdo->prepare("
    INSERT INTO water_meter_logs (
        branch_id, shift_id, user_id, log_date,
        meter_yesterday, meter_today, meter_difference,
        meter_adjustment, adjustment_type, adjustment_reason,
        water_dispensed_galon, notes, created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?, ?
    )
");
$stmtWML->execute([
    $testBranchId, $shiftId, $testUserId, $today,
    1000, 1500, 500,
    0, 'PULSE', null,
    round(500/782, 2), 'Uji meteran',
    $now, $now
]);
$wmlId = $pdo->lastInsertId();
assertCheck($wmlId > 0, "water_meter_logs berhasil disimpan dengan parameter ANSI SQL tanpa syntax error");

// D. Eksekusi Tutup Shift
$stmtUpdate = $pdo->prepare("
    UPDATE cashier_shifts
    SET end_time = ?,
        expected_cash = ?,
        actual_cash = ?,
        cash_difference = ?,
        total_expenses = ?,
        total_kasbon = ?,
        total_meal_allowance = ?,
        status = 'CLOSED'
    WHERE id = ?
");
$stmtUpdate->execute([$now, 200000, 200000, 0, 25000, 50000, 15000, $shiftId]);

// E. Verifikasi Hasil
$check = $pdo->query("SELECT status, end_time, total_expenses FROM cashier_shifts WHERE id = {$shiftId}")->fetch(PDO::FETCH_ASSOC);
assertCheck($check['status'] === 'CLOSED', "Status shift berhasil berubah menjadi CLOSED");
assertCheck(!empty($check['end_time']), "Waktu end_time tersimpan akurat");

// Bersihkan data dummy pengujian
$pdo->exec("DELETE FROM water_meter_logs WHERE shift_id = {$shiftId}");
$pdo->exec("DELETE FROM branch_expenses WHERE shift_id = {$shiftId}");
$pdo->exec("DELETE FROM salary_loans WHERE shift_id = {$shiftId}");
$pdo->exec("DELETE FROM employee_meal_allowances WHERE shift_id = {$shiftId}");
$pdo->exec("DELETE FROM cashier_shifts WHERE id = {$shiftId}");

echo "\n=== HASIL AKHIR: {$passedTests} dari {$totalTests} pengujian BERHASIL! ===\n";

if ($passedTests === $totalTests) {
    exit(0);
} else {
    exit(1);
}
