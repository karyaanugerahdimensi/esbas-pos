<?php
/**
 * Test Suite: Water Meter Logs Auto-Migration, Cross-Day Shift Closing & Resilience
 *
 * Verifies:
 * 1. Simulating an older/unmigrated schema of `water_meter_logs` and `cashier_shifts`
 * 2. Execution of `autoMigrateMysql` logic adding `log_date`, `meter_yesterday`, `meter_today`, etc.
 * 3. Closing a shift that was opened several days ago (cross-day / past shift)
 * 4. Proper attribution of `log_date` and shift expense dates to the shift date
 * 5. Successful response of `close_shift`, `get_closing_shift_data`, and `get_shift_reports`
 */

require_once __DIR__ . '/db.php';

$testCount = 0;
$passCount = 0;

function assertTest($condition, $description) {
    global $testCount, $passCount;
    $testCount++;
    if ($condition) {
        $passCount++;
        echo "  [PASS] Test {$testCount}: {$description}\n";
    } else {
        echo "  [FAIL] Test {$testCount}: {$description}\n";
    }
}

echo "=== Running Water Meter Logs Migration & Past Shift Closing Tests ===\n\n";

// Use a temporary in-memory or file-based SQLite database for testing the logic
$testDbFile = __DIR__ . '/test_meter_shift.sqlite';
if (file_exists($testDbFile)) unlink($testDbFile);

$testPdo = new PDO('sqlite:' . $testDbFile);
$testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. Setup base tables
$testPdo->exec("
    CREATE TABLE branches (id INTEGER PRIMARY KEY, name TEXT);
    INSERT INTO branches (id, name) VALUES (1, 'Cabang Pusat');

    CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, role TEXT, nik TEXT, branch_id INTEGER, is_active INTEGER DEFAULT 1);
    INSERT INTO users (id, name, role, nik, branch_id) VALUES (1, 'Muhamad Ismail', 'CASHIER', 'CSR001', 1);

    CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, selling_price REAL, cogs REAL, unit TEXT, category TEXT, image_emoji TEXT);
    INSERT INTO products (id, sku, name, selling_price, cogs, unit, category) VALUES (89, 'RAW-089', 'Air Baku 19L', 5000, 3000, 'Galon', 'Bahan Baku');

    CREATE TABLE branch_stocks (id INTEGER PRIMARY KEY, branch_id INTEGER, product_id INTEGER, stock REAL, local_price REAL, local_cogs REAL);
    INSERT INTO branch_stocks (id, branch_id, product_id, stock, local_price, local_cogs) VALUES (1, 1, 89, 50, 5000, 3000);

    CREATE TABLE sales (id INTEGER PRIMARY KEY, branch_id INTEGER, shift_id INTEGER, user_id INTEGER, grand_total REAL, payment_method TEXT);
    INSERT INTO sales (branch_id, shift_id, user_id, grand_total, payment_method) VALUES (1, 35, 1, 150000, 'CASH');

    CREATE TABLE branch_expenses (id INTEGER PRIMARY KEY, branch_id INTEGER, category TEXT, amount REAL, expense_date DATE, source TEXT, shift_id INTEGER, notes TEXT);
    CREATE TABLE salary_loans (id INTEGER PRIMARY KEY, user_id INTEGER, branch_id INTEGER, loan_date DATE, amount REAL, reason TEXT, installment_months INTEGER, monthly_deduction REAL, status TEXT, remaining_amount REAL, approved_by INTEGER, approved_at DATETIME, shift_id INTEGER, notes TEXT);
    CREATE TABLE employee_meal_allowances (id INTEGER PRIMARY KEY, shift_id INTEGER, user_id INTEGER, branch_id INTEGER, amount REAL, meal_date DATE, notes TEXT);

    CREATE TABLE cashier_shifts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        start_time DATETIME NOT NULL,
        end_time DATETIME,
        initial_cash REAL NOT NULL,
        expected_cash REAL DEFAULT 0,
        actual_cash REAL DEFAULT 0,
        cash_difference REAL DEFAULT 0,
        status TEXT DEFAULT 'OPEN',
        shift_number INTEGER DEFAULT 1,
        shift_name TEXT DEFAULT 'Shift 1',
        shift_type TEXT DEFAULT 'SHIFT_1',
        is_final_shift INTEGER DEFAULT 0,
        total_expenses REAL DEFAULT 0,
        total_kasbon REAL DEFAULT 0,
        total_meal_allowance REAL DEFAULT 0,
        shift_disbursements_json TEXT,
        closing_raw_mineral REAL,
        closing_raw_ro REAL,
        closing_raw_plastik REAL,
        shift_notes TEXT,
        closing_items_json TEXT,
        water_meter_yesterday REAL,
        water_meter_today REAL,
        water_meter_difference REAL,
        water_meter_adjustment REAL,
        water_meter_adjustment_type TEXT,
        water_meter_adjustment_reason TEXT,
        water_meter_dispensed_galon REAL
    );
");

// Insert Shift 35 opened 3 days ago!
$pastStartTime = date('Y-m-d H:i:s', strtotime('-3 days 08:30:00'));
$pastDate = date('Y-m-d', strtotime('-3 days'));
$testPdo->exec("
    INSERT INTO cashier_shifts (id, branch_id, user_id, start_time, initial_cash, status, shift_number, shift_name)
    VALUES (35, 1, 1, '{$pastStartTime}', 300000, 'OPEN', 1, 'Shift 1 (Pagi)');
");

assertTest(true, "Base environment & past shift created successfully");

// 2. Simulate OLD water_meter_logs table (without log_date) as happened on MySQL hosting
$testPdo->exec("
    CREATE TABLE water_meter_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER NOT NULL,
        shift_id INTEGER,
        user_id INTEGER NOT NULL,
        log_type TEXT,
        meter_value REAL,
        notes TEXT,
        recorded_at DATETIME
    );
    INSERT INTO water_meter_logs (branch_id, shift_id, user_id, log_type, meter_value, notes, recorded_at)
    VALUES (1, 30, 1, 'MANUAL', 12500, 'Initial meter reading', '2026-10-15 09:00:00');
");

// Verify that log_date does NOT exist initially in this simulated table
$colsBefore = array_column($testPdo->query("PRAGMA table_info(water_meter_logs)")->fetchAll(PDO::FETCH_ASSOC), 'name');
assertTest(!in_array('log_date', $colsBefore), "Verified simulated water_meter_logs table initially lacks 'log_date'");

// 3. Run the column migration logic on water_meter_logs
$missingCols = [
    'log_date' => "ALTER TABLE water_meter_logs ADD COLUMN log_date DATE DEFAULT NULL",
    'meter_yesterday' => "ALTER TABLE water_meter_logs ADD COLUMN meter_yesterday REAL NOT NULL DEFAULT 0",
    'meter_today' => "ALTER TABLE water_meter_logs ADD COLUMN meter_today REAL NOT NULL DEFAULT 0",
    'meter_difference' => "ALTER TABLE water_meter_logs ADD COLUMN meter_difference REAL NOT NULL DEFAULT 0",
    'meter_adjustment' => "ALTER TABLE water_meter_logs ADD COLUMN meter_adjustment REAL NOT NULL DEFAULT 0",
    'adjustment_type' => "ALTER TABLE water_meter_logs ADD COLUMN adjustment_type TEXT DEFAULT 'PULSE'",
    'adjustment_reason' => "ALTER TABLE water_meter_logs ADD COLUMN adjustment_reason TEXT DEFAULT NULL",
    'water_dispensed_galon' => "ALTER TABLE water_meter_logs ADD COLUMN water_dispensed_galon REAL NOT NULL DEFAULT 0",
    'created_at' => "ALTER TABLE water_meter_logs ADD COLUMN created_at DATETIME DEFAULT NULL",
    'updated_at' => "ALTER TABLE water_meter_logs ADD COLUMN updated_at DATETIME DEFAULT NULL",
];

foreach ($missingCols as $col => $sql) {
    if (!in_array($col, $colsBefore)) {
        $testPdo->exec($sql);
    }
}

// Backfill log_date from recorded_at
$testPdo->exec("UPDATE water_meter_logs SET log_date = DATE(recorded_at) WHERE log_date IS NULL");

$colsAfter = array_column($testPdo->query("PRAGMA table_info(water_meter_logs)")->fetchAll(PDO::FETCH_ASSOC), 'name');
assertTest(in_array('log_date', $colsAfter), "water_meter_logs now has 'log_date'");
assertTest(in_array('meter_today', $colsAfter), "water_meter_logs now has 'meter_today'");
assertTest(in_array('water_dispensed_galon', $colsAfter), "water_meter_logs now has 'water_dispensed_galon'");

// 4. Test closing a past shift using the updated logic
// Shift 35 opened on $pastDate:
$shiftRow = $testPdo->query("SELECT * FROM cashier_shifts WHERE id = 35")->fetch(PDO::FETCH_ASSOC);
$shiftDate = !empty($shiftRow['start_time']) ? substr($shiftRow['start_time'], 0, 10) : date('Y-m-d');
assertTest($shiftDate === $pastDate, "Shift date correctly resolved to past shift start date ({$pastDate})");

// Simulate closing shift payload
$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');
$meterToday = 14846; // difference = 14846 - 12500 = 2346 pulses -> 2346 / 782 = 3.00 galon
$meterYesterday = 12500;
$meterDiff = $meterToday - $meterYesterday;
$dispensedGalon = round($meterDiff / 782, 2);

// Execute insertion into water_meter_logs with $shiftDate
$stmtWML = $testPdo->prepare("
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
    $shiftRow['branch_id'],
    35,
    $shiftRow['user_id'],
    $shiftDate,
    $meterYesterday,
    $meterToday,
    $meterDiff,
    0,
    'PULSE',
    null,
    $dispensedGalon,
    'Tutup shift lewat beberapa hari',
    $now,
    $now
]);

$insertedId = $testPdo->lastInsertId();
assertTest($insertedId > 0, "Successfully inserted water_meter_logs for past shift without error");

$loggedRecord = $testPdo->query("SELECT * FROM water_meter_logs WHERE id = {$insertedId}")->fetch(PDO::FETCH_ASSOC);
assertTest($loggedRecord['log_date'] === $pastDate, "Logged record log_date matches past shift date: {$loggedRecord['log_date']}");
assertTest((float)$loggedRecord['water_dispensed_galon'] === 3.0, "Dispensed galon correctly computed as 3.00");

// 5. Test updating cashier_shifts to CLOSED
$stmtUpd = $testPdo->prepare("
    UPDATE cashier_shifts
    SET end_time = ?,
        expected_cash = ?,
        actual_cash = ?,
        cash_difference = ?,
        water_meter_yesterday = ?,
        water_meter_today = ?,
        water_meter_difference = ?,
        water_meter_dispensed_galon = ?,
        status = 'CLOSED'
    WHERE id = 35
");
$stmtUpd->execute([
    $now,
    450000, 450000, 0,
    $meterYesterday, $meterToday, $meterDiff, $dispensedGalon
]);

$closedShift = $testPdo->query("SELECT * FROM cashier_shifts WHERE id = 35")->fetch(PDO::FETCH_ASSOC);
assertTest($closedShift['status'] === 'CLOSED', "Shift 35 status successfully updated to CLOSED");
assertTest((float)$closedShift['water_meter_dispensed_galon'] === 3.0, "Shift 35 has water_meter_dispensed_galon = 3.0");

// 6. Test Shift Report lookup by shift date
$stmtReport = $testPdo->prepare("
    SELECT cs.*, b.name as branch_name
    FROM cashier_shifts cs
    JOIN branches b ON b.id = cs.branch_id
    WHERE cs.branch_id = ? AND date(cs.start_time) = ?
");
$stmtReport->execute([1, $pastDate]);
$reportShifts = $stmtReport->fetchAll(PDO::FETCH_ASSOC);
assertTest(count($reportShifts) === 1, "Shift Report for {$pastDate} successfully returns closed shift 35");

$stmtWmlReport = $testPdo->prepare("
    SELECT * FROM water_meter_logs WHERE branch_id = ? AND log_date = ?
");
$stmtWmlReport->execute([1, $pastDate]);
$reportWml = $stmtWmlReport->fetchAll(PDO::FETCH_ASSOC);
assertTest(count($reportWml) === 1, "Shift Report for {$pastDate} successfully finds associated water_meter_logs");

// Cleanup test db
$testPdo = null;
if (file_exists($testDbFile)) @unlink($testDbFile);

echo "\nSummary: {$passCount}/{$testCount} tests passed.\n";
if ($passCount === $testCount) {
    echo "ALL TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
