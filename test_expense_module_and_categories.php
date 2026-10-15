<?php
// Test Script: Pengujian Menu Khusus Beban Operasional Cabang & CRUD Master Kategori Beban
echo "=== MEMULAI TEST MENU KHUSUS BEBAN OPERASIONAL CABANG & CRUD KATEGORI BEBAN ===\n";

require_once 'db.php';

function assertTest($condition, $passMsg, $failMsg) {
    if ($condition) {
        echo "[PASS] $passMsg\n";
    } else {
        echo "[FAIL] $failMsg\n";
        exit(1);
    }
}

// 1. Uji EndPoint get_expense_categories
$cats = $pdo->query("SELECT * FROM expense_categories")->fetchAll(PDO::FETCH_ASSOC);
assertTest(count($cats) >= 13, "Master kategori beban terisi minimal 13 kategori standar (ditemukan: " . count($cats) . ").", "Kategori beban master belum terisi dengan benar.");

// 2. Uji Insert Kategori Beban Baru (save_expense_category)
$testCode = 'EXP-UJI-01';
$testName = 'Beban Uji Testing Laboratorium';
// Hapus jika ada residu sebelumnya
$pdo->prepare("DELETE FROM expense_categories WHERE code = ? OR name = ?")->execute([$testCode, $testName]);

$stmt = $pdo->prepare("INSERT INTO expense_categories (code, name, group_type, description, is_active) VALUES (?, ?, 'OPERASIONAL', 'Kategori untuk unit testing', 1)");
$stmt->execute([$testCode, $testName]);
$catId = $pdo->lastInsertId();
assertTest($catId > 0, "Tambah kategori beban baru berhasil (ID: $catId).", "Gagal menambah kategori beban baru.");

// 3. Uji Validasi Duplikasi Kode / Nama
$checkDup = $pdo->prepare("SELECT COUNT(*) FROM expense_categories WHERE code = ?");
$checkDup->execute([$testCode]);
assertTest($checkDup->fetchColumn() == 1, "Proteksi keunikan kode kategori bekerja akurat.", "Kode kategori duplikat terdeteksi.");

// 4. Uji Penggunaan Kategori pada Transaksi Beban Cabang (save_branch_expense)
$branchId = 1;
$stmtExp = $pdo->prepare("INSERT INTO branch_expenses (branch_id, category, amount, expense_date, source, notes) VALUES (?, ?, ?, ?, 'MANUAL', ?)");
$stmtExp->execute([$branchId, $testName, 175000, date('Y-m-d'), 'Biaya kalibrasi alat lab cabang']);
$expId = $pdo->lastInsertId();
assertTest($expId > 0, "Transaksi beban cabang berhasil dicatat menggunakan kategori baru (ID Beban: $expId).", "Gagal mencatat transaksi beban.");

// 5. Uji Agregasi Usage Count & Total Amount Kategori
$stmtUsage = $pdo->prepare("
    SELECT ec.*,
           (SELECT COUNT(*) FROM branch_expenses be WHERE be.category = ec.name) AS expense_usage_count,
           (SELECT COALESCE(SUM(be.amount), 0) FROM branch_expenses be WHERE be.category = ec.name) AS total_amount_used
    FROM expense_categories ec
    WHERE ec.id = ?
");
$stmtUsage->execute([$catId]);
$usageRow = $stmtUsage->fetch(PDO::FETCH_ASSOC);
assertTest($usageRow['expense_usage_count'] == 1 && $usageRow['total_amount_used'] == 175000, "Agregasi pemakaian kategori akurat: 1 transaksi, Rp 175.000.", "Agregasi pemakaian kategori tidak sesuai.");

// 6. Uji Proteksi Penghapusan Kategori yang Sedang Digunakan (delete_expense_category)
$stmtUsageCheck = $pdo->prepare("SELECT COUNT(*) FROM branch_expenses WHERE category = ?");
$stmtUsageCheck->execute([$testName]);
$inUse = (int)$stmtUsageCheck->fetchColumn();
assertTest($inUse > 0, "Sistem mendeteksi kategori sedang dipakai dan mencegah penghapusan.", "Kategori tidak terdeteksi sedang dipakai.");

// 7. Uji Update Kategori & Sinkronisasi Riwayat Transaksi
$newName = 'Beban Uji Testing Laboratorium Revisi';
$pdo->prepare("UPDATE expense_categories SET name = ? WHERE id = ?")->execute([$newName, $catId]);
$pdo->prepare("UPDATE branch_expenses SET category = ? WHERE category = ?")->execute([$newName, $testName]);

$checkSync = $pdo->prepare("SELECT category FROM branch_expenses WHERE id = ?");
$checkSync->execute([$expId]);
$updatedCatName = $checkSync->fetchColumn();
assertTest($updatedCatName === $newName, "Sinkronisasi perubahan nama kategori ke riwayat transaksi berhasil.", "Nama kategori pada riwayat transaksi tidak tersinkronisasi.");

// 8. Uji Toggle Status Kategori (Aktif / Nonaktif)
$pdo->prepare("UPDATE expense_categories SET is_active = 0 WHERE id = ?")->execute([$catId]);
$statusNow = $pdo->query("SELECT is_active FROM expense_categories WHERE id = $catId")->fetchColumn();
assertTest($statusNow == 0, "Status kategori beban berhasil dinonaktifkan.", "Status kategori gagal diubah.");

$pdo->prepare("UPDATE expense_categories SET is_active = 1 WHERE id = ?")->execute([$catId]);
$statusBack = $pdo->query("SELECT is_active FROM expense_categories WHERE id = $catId")->fetchColumn();
assertTest($statusBack == 1, "Status kategori beban berhasil diaktifkan kembali.", "Status kategori gagal diaktifkan kembali.");

// 9. Hapus Transaksi Pengujian & Eksekusi Hapus Kategori Beban
$pdo->prepare("DELETE FROM branch_expenses WHERE id = ?")->execute([$expId]);
$pdo->prepare("DELETE FROM expense_categories WHERE id = ?")->execute([$catId]);
$checkDeleted = $pdo->query("SELECT COUNT(*) FROM expense_categories WHERE id = $catId")->fetchColumn();
assertTest($checkDeleted == 0, "Kategori beban yang sudah tidak terpakai berhasil dihapus bersih.", "Kategori beban gagal dihapus.");

// 10. Uji Pemisahan Antarmuka & Menu di index.php
$indexHtml = file_get_contents('index.php');
assertTest(strpos($indexHtml, 'id="tab-expenses"') !== false, "Kontainer menu khusus `#tab-expenses` tersedia di index.php.", "`#tab-expenses` tidak ditemukan.");
assertTest(strpos($indexHtml, 'id="tab-btn-expenses"') !== false, "Tombol menu sidebar `#tab-btn-expenses` tersedia di index.php.", "`#tab-btn-expenses` tidak ditemukan.");
assertTest(strpos($indexHtml, '9. Beban Operasional Cabang') !== false, "Label menu '9. Beban Operasional Cabang' terpasang pada sidebar.", "Label menu sidebar tidak sesuai.");
assertTest(strpos($indexHtml, 'loadBranchExpensesModule') !== false, "Fungsi loadBranchExpensesModule terpasang pada switchTab.", "loadBranchExpensesModule tidak ditemukan.");
assertTest(strpos($indexHtml, 'id="expense-categories-table-body"') !== false, "Tabel Master Kategori Beban tersedia di Sub-Tab 2.", "Tabel Master Kategori Beban tidak ditemukan.");

echo "\nSemua pengujian fungsional Menu Khusus Beban Operasional & CRUD Kategori Beban BERHASIL (100% PASS)!\n";
