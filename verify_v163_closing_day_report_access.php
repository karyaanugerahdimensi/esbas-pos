<?php
/**
 * Test Suite: Verifikasi Akses Laporan Shift Kasir & Tutup Toko v1.6.3
 * Memastikan Kasir (misal Bima) dapat mengakses laporan shift & closing day
 * setelah toko ditutup (Closing Day) atau shift ditutup, meskipun shift dibuka oleh kasir lain.
 */

// Siapkan database temporary
$tempDbPath = __DIR__ . '/test_v163_closing_access.sqlite';
if (file_exists($tempDbPath)) unlink($tempDbPath);

$pdo = new PDO("sqlite:{$tempDbPath}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Buat schema minimal yang relevan
$pdo->exec("
    CREATE TABLE branches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        is_active INTEGER DEFAULT 1
    );

    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nik TEXT,
        name TEXT,
        email TEXT,
        role TEXT,
        branch_id INTEGER,
        pin TEXT,
        password TEXT,
        is_active INTEGER DEFAULT 1,
        can_access_all_branches INTEGER DEFAULT 0
    );

    CREATE TABLE user_roles (
        code TEXT PRIMARY KEY,
        name TEXT,
        can_access_all_branches INTEGER DEFAULT 0
    );

    CREATE TABLE cashier_shifts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER,
        user_id INTEGER,
        start_time DATETIME,
        end_time DATETIME,
        initial_cash REAL DEFAULT 0,
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
        opening_items_json TEXT,
        closing_items_json TEXT,
        night_items_json TEXT,
        opening_added_items_json TEXT,
        opening_raw_mineral INTEGER DEFAULT 0,
        opening_raw_ro INTEGER DEFAULT 0,
        opening_raw_plastik INTEGER DEFAULT 0,
        closing_raw_mineral INTEGER DEFAULT 0,
        closing_raw_ro INTEGER DEFAULT 0,
        closing_raw_plastik INTEGER DEFAULT 0,
        closed_by_user_id INTEGER DEFAULT NULL,
        shift_notes TEXT,
        water_meter_yesterday REAL,
        water_meter_today REAL,
        water_meter_difference REAL,
        water_meter_adjustment REAL,
        water_meter_adjustment_type TEXT,
        water_meter_adjustment_reason TEXT,
        water_meter_dispensed_galon REAL
    );

    CREATE TABLE products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku TEXT,
        name TEXT,
        unit TEXT,
        category TEXT,
        image_emoji TEXT,
        product_type TEXT DEFAULT 'FINISHED_GOODS',
        cogs REAL DEFAULT 0,
        price REAL DEFAULT 0,
        has_bom INTEGER DEFAULT 0,
        is_stockable INTEGER DEFAULT 1
    );

    CREATE TABLE branch_stocks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER,
        product_id INTEGER,
        stock REAL DEFAULT 0,
        local_cogs REAL DEFAULT 0
    );

    CREATE TABLE opening_shift_products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER,
        sort_order INTEGER DEFAULT 1,
        is_active INTEGER DEFAULT 1
    );

    CREATE TABLE sales (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_number TEXT,
        shift_id INTEGER,
        branch_id INTEGER,
        user_id INTEGER,
        total_amount REAL,
        discount_amount REAL DEFAULT 0,
        grand_total REAL,
        payment_method TEXT,
        total_cogs REAL DEFAULT 0,
        status TEXT DEFAULT 'COMPLETED',
        created_at DATETIME
    );

    CREATE TABLE sale_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER,
        product_id INTEGER,
        variant_id INTEGER DEFAULT 0,
        variant_name TEXT DEFAULT '',
        qty REAL,
        unit_price REAL,
        cogs REAL DEFAULT 0,
        subtotal REAL
    );

    CREATE TABLE stock_movements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER,
        product_id INTEGER,
        movement_type TEXT,
        qty_change REAL,
        stock_before REAL,
        stock_after REAL,
        reference_no TEXT,
        notes TEXT,
        user_id INTEGER,
        created_at DATETIME
    );

    CREATE TABLE water_meter_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER,
        user_id INTEGER,
        shift_id INTEGER,
        meter_yesterday REAL,
        meter_today REAL,
        diff REAL,
        adjustment REAL DEFAULT 0,
        adjustment_type TEXT DEFAULT 'PULSE',
        reason TEXT,
        dispensed_galon REAL,
        log_date DATE,
        created_at DATETIME
    );
");

// Insert seed data
$pdo->exec("
    INSERT INTO branches (id, name) VALUES (1, 'Pusat Cimahi');
    INSERT INTO user_roles (code, name, can_access_all_branches) VALUES ('CASHIER', 'Kasir POS', 0);
    INSERT INTO users (id, nik, name, email, role, branch_id) VALUES 
        (1, 'EMP01', 'Kasir Pagi', 'pagi@esbas.id', 'CASHIER', 1),
        (2, 'EMP02', 'Bima', 'bima@esbas.id', 'CASHIER', 1);

    INSERT INTO products (id, sku, name, unit, category, image_emoji, product_type) VALUES
        (1, 'PRD-00001', 'Air Mineral Galon 19 Liter', 'galon', 'Air Minum', '💧', 'FINISHED_GOODS'),
        (2, 'RAW-089', 'Tutup Galon Mineral', 'pcs', 'Bahan Baku', '🔵', 'RAW_MATERIAL');

    INSERT INTO opening_shift_products (product_id, sort_order, is_active) VALUES (1, 1, 1), (2, 2, 1);
");

$passed = 0;
$total = 0;

function assertTest($desc, $cond) {
    global $passed, $total;
    $total++;
    if ($cond) {
        $passed++;
        echo "✅ PASS: {$desc}\n";
    } else {
        echo "❌ FAIL: {$desc}\n";
    }
}

$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

echo "=== TEST SUITE: CLOSING DAY REPORT ACCESS FOR CASHIER (v1.6.3) ===\n\n";

// 1. Kasir 1 membuka Shift 1
$pdo->prepare("
    INSERT INTO cashier_shifts (id, branch_id, user_id, start_time, initial_cash, status, shift_number, shift_name)
    VALUES (101, 1, 1, ?, 50000, 'OPEN', 1, 'Shift 1')
")->execute([$now]);

// Test 1: Saat shift masih OPEN dan belum pernah ditutup, Bima (Kasir 2) tidak bisa intip laporan
$reqUserId = 2; // Bima
$branchId = 1;
$reportDate = $today;
$isManagerOrAdmin = false;

// Logika backend v1.6.3
$chkFinalStmt = $pdo->prepare("
    SELECT COUNT(*) FROM cashier_shifts
    WHERE branch_id = ? AND status = 'CLOSED' AND is_final_shift = 1
      AND (date(start_time) = ? OR (end_time IS NOT NULL AND date(end_time) = ?))
");
$chkFinalStmt->execute([$branchId, $reportDate, $reportDate]);
$isBranchClosedOnDate = ((int)$chkFinalStmt->fetchColumn()) > 0;

$chkBranchClosedStmt = $pdo->prepare("
    SELECT COUNT(*) FROM cashier_shifts
    WHERE branch_id = ? AND status = 'CLOSED'
      AND (date(start_time) = ? OR (end_time IS NOT NULL AND date(end_time) = ?))
");
$chkBranchClosedStmt->execute([$branchId, $reportDate, $reportDate]);
$hasClosedShiftForBranch = ((int)$chkBranchClosedStmt->fetchColumn()) > 0;

$chkClosedStmt = $pdo->prepare("
    SELECT COUNT(*) FROM cashier_shifts 
    WHERE (user_id = ? OR (closed_by_user_id IS NOT NULL AND closed_by_user_id = ?)) AND branch_id = ? AND status = 'CLOSED' 
      AND (date(start_time) = ? OR (end_time IS NOT NULL AND date(end_time) = ?))
");
$chkClosedStmt->execute([$reqUserId, $reqUserId, $branchId, $reportDate, $reportDate]);
$hasClosedShiftForUser = ((int)$chkClosedStmt->fetchColumn()) > 0;

$canViewBeforeClose = $isManagerOrAdmin || $isBranchClosedOnDate || $hasClosedShiftForBranch || $hasClosedShiftForUser;
assertTest("Saat shift masih OPEN dan belum ada closing di cabang, kasir TIDAK BISA mengintip laporan", $canViewBeforeClose === false);

// 2. Sekarang Bima (Kasir 2) atau kasir lain melakukan End Day Closing / Tutup Toko
$pdo->prepare("
    UPDATE cashier_shifts
    SET end_time = ?,
        status = 'CLOSED',
        is_final_shift = 1,
        actual_cash = 450000,
        expected_cash = 450000,
        closed_by_user_id = ?
    WHERE id = 101
")->execute([$now, 2]);

// Test 2: Setelah Tutup Toko (Closing Day) dilakukan di cabang, cek akses backend untuk Bima
$chkFinalStmt->execute([$branchId, $reportDate, $reportDate]);
$isBranchClosedOnDate = ((int)$chkFinalStmt->fetchColumn()) > 0;

$chkBranchClosedStmt->execute([$branchId, $reportDate, $reportDate]);
$hasClosedShiftForBranch = ((int)$chkBranchClosedStmt->fetchColumn()) > 0;

$chkClosedStmt->execute([$reqUserId, $reqUserId, $branchId, $reportDate, $reportDate]);
$hasClosedShiftForUser = ((int)$chkClosedStmt->fetchColumn()) > 0;

$canViewAfterClose = $isManagerOrAdmin || $isBranchClosedOnDate || $hasClosedShiftForBranch || $hasClosedShiftForUser;
assertTest("Setelah Tutup Toko, canViewShiftReport bernilai TRUE untuk Kasir Bima", $canViewAfterClose === true);
assertTest("Branch Closed On Date terdeteksi TRUE", $isBranchClosedOnDate === true);
assertTest("Shift berstatus CLOSED di cabang terdeteksi TRUE", $hasClosedShiftForBranch === true);
assertTest("Bima sebagai closer (closed_by_user_id) terdeteksi hasClosedShiftForUser TRUE", $hasClosedShiftForUser === true);

// Test 3: Kueri shift untuk kasir Bima mengembalikan shift yang telah ditutup
$stmtShifts = $pdo->prepare("
    SELECT cs.*, u.name as cashier_name, u.nik as cashier_nik, b.name as branch_name
    FROM cashier_shifts cs
    JOIN users u ON u.id = cs.user_id
    JOIN branches b ON b.id = cs.branch_id
    WHERE cs.branch_id = ? AND (date(cs.start_time) = ? OR (cs.end_time IS NOT NULL AND date(cs.end_time) = ?))
      AND (cs.status = 'CLOSED' OR cs.user_id = ? OR (cs.closed_by_user_id IS NOT NULL AND cs.closed_by_user_id = ?))
    ORDER BY cs.id ASC
");
$stmtShifts->execute([$branchId, $reportDate, $reportDate, $reqUserId, $reqUserId]);
$returnedShifts = $stmtShifts->fetchAll(PDO::FETCH_ASSOC);

assertTest("Kueri shift untuk Kasir Bima berhasil mengembalikan data shift yang ditutup", count($returnedShifts) === 1);
assertTest("ID shift yang dikembalikan cocok (#101)", (int)$returnedShifts[0]['id'] === 101);
assertTest("Shift opener adalah Kasir Pagi (user_id=1)", (int)$returnedShifts[0]['user_id'] === 1);
assertTest("Shift closer adalah Bima (closed_by_user_id=2)", (int)$returnedShifts[0]['closed_by_user_id'] === 2);

// Test 4: Cek evaluasi bootstrap untuk Kasir Bima
$stmtBranchFinalClosed = $pdo->prepare("
    SELECT cs.*, u.name as closer_name 
    FROM cashier_shifts cs
    JOIN users u ON u.id = cs.user_id
    WHERE cs.branch_id = ? AND cs.status = 'CLOSED' AND cs.is_final_shift = 1
      AND (date(cs.start_time) = ? OR (cs.end_time IS NOT NULL AND date(cs.end_time) = ?))
    ORDER BY cs.id DESC LIMIT 1
");
$stmtBranchFinalClosed->execute([1, $today, $today]);
$finalClosedShiftToday = $stmtBranchFinalClosed->fetch();
$isBranchClosedToday = !empty($finalClosedShiftToday);

$stmtBranchClosedToday = $pdo->prepare("
    SELECT COUNT(*) FROM cashier_shifts
    WHERE branch_id = ? AND status = 'CLOSED'
      AND (date(start_time) = ? OR (end_time IS NOT NULL AND date(end_time) = ?))
");
$stmtBranchClosedToday->execute([1, $today, $today]);
$hasClosedShiftAtBranch = ((int)$stmtBranchClosedToday->fetchColumn()) > 0;

$stmtHasClosed = $pdo->prepare("
    SELECT COUNT(*) FROM cashier_shifts
    WHERE (user_id = ? OR (closed_by_user_id IS NOT NULL AND closed_by_user_id = ?)) AND status = 'CLOSED' 
      AND (date(start_time) = ? OR (end_time IS NOT NULL AND date(end_time) = ?))
");
$stmtHasClosed->execute([2, 2, $today, $today]);
$hasClosedShiftUser = ((int)$stmtHasClosed->fetchColumn()) > 0;

$bootstrapHasClosed = $isBranchClosedToday || $hasClosedShiftAtBranch || $hasClosedShiftUser;
assertTest("Bootstrap data.has_closed_shift bernilai TRUE untuk Kasir Bima", $bootstrapHasClosed === true);
assertTest("Bootstrap data.branch_closed_today bernilai TRUE", $isBranchClosedToday === true);

// Test 5: Evaluasi frontend `hasClosed` kondisi v1.6.3
$frontendState = [
    'currentUserHasClosedShift' => $bootstrapHasClosed,
    'branchClosedToday' => $isBranchClosedToday,
    'finalClosedShift' => $finalClosedShiftToday
];
$frontendHasClosed = ($frontendState['currentUserHasClosedShift'] === true)
                  || ($frontendState['branchClosedToday'] === true)
                  || ($frontendState['finalClosedShift'] !== null);
assertTest("Frontend condition `hasClosed` mengevaluasi TRUE tanpa memunculkan alert", $frontendHasClosed === true);

// Bersihkan file database temporary
if (file_exists($tempDbPath)) unlink($tempDbPath);

echo "\nResult: {$passed} / {$total} tests passed!\n";
if ($passed === $total) {
    echo "🎉 ALL TESTS PASSED!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED!\n";
    exit(1);
}
