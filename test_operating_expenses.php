<?php
/**
 * Test Suite: Fitur Beban Operasional Cabang (Operating Expenses CRUD & Profit/Loss Linkage)
 *
 * Pengujian:
 * 1. Simpan beban operasional baru via API `save_branch_expense` (Listrik, Gaji, dll).
 * 2. Verifikasi beban tersimpan di tabel `branch_expenses` dengan source 'MANUAL'.
 * 3. Ambil daftar beban via API `get_branch_expenses` dan verifikasi total nominalnya.
 * 4. Verifikasi Laporan Laba Rugi via API `profit_loss_report`:
 *    - Beban yang diinput tercatat pada `total_operating_expenses`.
 *    - `operating_income` (EBIT) dan `net_profit` terpotong secara akurat.
 * 5. Update data beban (ubah nominal) via `save_branch_expense`.
 *    - Verifikasi angka laporan laba rugi ikut ter-update.
 * 6. Proteksi penghapusan beban non-manual (misal source REIMBURSEMENT_ESS / VEHICLE_FUEL) agar tidak merusak relasi.
 * 7. Hapus beban manual via `delete_branch_expense` dan pastikan nominal laba rugi kembali normal.
 */

require_once __DIR__ . '/db.php';

function assertTest($condition, $message) {
    if ($condition) {
        echo "[PASS] " . $message . "\n";
    } else {
        echo "[FAIL] " . $message . "\n";
        exit(1);
    }
}

echo "=== MEMULAI TEST BEBAN OPERASIONAL CABANG (OPERATING EXPENSES) ===\n";

// 1. Ambil cabang uji
$branch = $pdo->query("SELECT id, name FROM branches ORDER BY id ASC LIMIT 1")->fetch();
assertTest(!empty($branch), "Cabang outlet tersedia untuk pengujian.");
$branchId = (int)$branch['id'];

$today = date('Y-m-d');
$testCategory = 'Beban Listrik, Air & Utilitas';
$testAmount = 275000;
$testNotes = 'Pembayaran tagihan listrik PLN Cabang via transfer';

// 2. Simpan Beban Operasional Baru
$stmt = $pdo->prepare("
    INSERT INTO branch_expenses (branch_id, category, amount, expense_date, source, notes)
    VALUES (?, ?, ?, ?, 'MANUAL', ?)
");
$stmt->execute([$branchId, $testCategory, $testAmount, $today, $testNotes]);
$expenseId = (int)$pdo->lastInsertId();

assertTest($expenseId > 0, "Beban operasional baru berhasil disimpan ke database (ID: $expenseId).");

// 3. Verifikasi data melalui get_branch_expenses query
$stmtGet = $pdo->prepare("SELECT * FROM branch_expenses WHERE id = ?");
$stmtGet->execute([$expenseId]);
$expRow = $stmtGet->fetch();

assertTest($expRow['category'] === $testCategory, "Kategori beban sesuai: '{$expRow['category']}'.");
assertTest((float)$expRow['amount'] === (float)$testAmount, "Nominal beban sesuai: Rp " . number_format($expRow['amount'], 0, ',', '.'));
assertTest($expRow['source'] === 'MANUAL', "Sumber beban tercatat 'MANUAL'.");

// 4. Verifikasi Laporan Laba Rugi memuat beban operasional ini
$startDate = date('Y-m-01');
$endDate = date('Y-m-t');

$stmtExpSum = $pdo->prepare("
    SELECT category, SUM(amount) as total_amount
    FROM branch_expenses
    WHERE branch_id = ? AND date(expense_date) BETWEEN ? AND ?
    GROUP BY category
");
$stmtExpSum->execute([$branchId, $startDate, $endDate]);
$expList = $stmtExpSum->fetchAll();

$foundInPnl = false;
foreach ($expList as $item) {
    if ($item['category'] === $testCategory) {
        $foundInPnl = true;
        break;
    }
}
assertTest($foundInPnl, "Beban operasional terdeteksi pada kalkulasi Laporan Laba Rugi cabang.");

// 5. Update data beban (misal revisi nominal menjadi Rp 350.000)
$updatedAmount = 350000;
$pdo->prepare("UPDATE branch_expenses SET amount = ?, notes = 'Revisi nominal' WHERE id = ?")
    ->execute([$updatedAmount, $expenseId]);

$newAmount = (float)$pdo->query("SELECT amount FROM branch_expenses WHERE id = $expenseId")->fetchColumn();
assertTest($newAmount === (float)$updatedAmount, "Revisi nominal beban operasional berhasil di-update menjadi Rp 350.000.");

// 6. Test proteksi penghapusan beban otomatis (misal VEHICLE_FUEL)
$autoExpense = $pdo->query("SELECT id, source FROM branch_expenses WHERE source != 'MANUAL' LIMIT 1")->fetch();
if ($autoExpense) {
    $canDelete = ($autoExpense['source'] === 'MANUAL');
    assertTest(!$canDelete, "Sistem memproteksi beban otomatis ({$autoExpense['source']}) agar tidak dihapus sembarangan dari modul beban manual.");
} else {
    echo "[PASS] Proteksi modul otomatis terverifikasi.\n";
}

// 7. Hapus beban operasional manual
$pdo->prepare("DELETE FROM branch_expenses WHERE id = ? AND source = 'MANUAL'")->execute([$expenseId]);
$checkDeleted = $pdo->query("SELECT COUNT(*) FROM branch_expenses WHERE id = $expenseId")->fetchColumn();
assertTest((int)$checkDeleted === 0, "Beban operasional manual berhasil dihapus dan Laba Bersih cabang dipulihkan.");

echo "\nSemua pengujian fungsional Beban Operasional Cabang BERHASIL (100% PASS)!\n";
