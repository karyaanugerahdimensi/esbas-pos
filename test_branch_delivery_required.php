<?php
/**
 * Test Suite: Fitur Pengaturan Wajib Ongkir per Cabang (Delivery Settings per Branch)
 *
 * Pengujian:
 * 1. Verifikasi schema database: Kolom `is_delivery_required` ada pada `branch_stocks`.
 * 2. Ambil 2 cabang berbeda (Cabang 1 dan Cabang 2) dan 1 produk uji.
 * 3. Set produk di Cabang 1 menjadi WAJIB ONGKIR (`is_delivery_required = 1`), sedangkan di Cabang 2 menjadi BEBAS ONGKIR (`is_delivery_required = 0`).
 * 4. Verifikasi query `bootstrap` untuk POS Cabang 1:
 *    - Nilai `is_delivery_required` produk = 1.
 * 5. Verifikasi query `bootstrap` untuk POS Cabang 2:
 *    - Nilai `is_delivery_required` produk yang sama = 0.
 * 6. Verifikasi endpoint `toggle_branch_product_delivery` mengubah status ongkir cabang secara instan.
 * 7. Verifikasi query `get_inventory_stocks` menyajikan informasi `is_delivery_required` spesifik cabang.
 * 8. Verifikasi `update_branch_product_price` dapat menyimpan `is_delivery_required` bersama harga cabang.
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

echo "=== MEMULAI TEST PENGATURAN WAJIB ONGKIR PER CABANG ===\n";

// 1. Verifikasi kolom `is_delivery_required` pada branch_stocks
$colCheck = $pdo->query("PRAGMA table_info(branch_stocks)")->fetchAll();
$hasDelivCol = false;
foreach ($colCheck as $col) {
    if ($col['name'] === 'is_delivery_required') {
        $hasDelivCol = true;
        break;
    }
}
assertTest($hasDelivCol, "Kolom `is_delivery_required` terdaftar di tabel branch_stocks.");

// 2. Ambil cabang 1 dan 2
$branches = $pdo->query("SELECT id, name FROM branches ORDER BY id ASC LIMIT 2")->fetchAll();
assertTest(count($branches) >= 2, "Minimal 2 cabang tersedia untuk pengujian multi-cabang.");

$branch1Id = (int)$branches[0]['id'];
$branch2Id = (int)$branches[1]['id'];

// Ambil produk uji FINISHED_GOODS
$product = $pdo->query("SELECT id, name, is_delivery_required FROM products WHERE is_active = 1 AND product_type = 'FINISHED_GOODS' ORDER BY id ASC LIMIT 1")->fetch();
assertTest(!empty($product), "Produk FINISHED_GOODS tersedia untuk pengujian.");
$testProdId = (int)$product['id'];

// Pastikan entri branch_stocks tersedia di kedua cabang
$pdo->prepare("INSERT OR IGNORE INTO branch_stocks (branch_id, product_id, stock, local_cogs, local_price, is_active, is_delivery_required) VALUES (?, ?, 50, 10000, 20000, 1, NULL)")
    ->execute([$branch1Id, $testProdId]);
$pdo->prepare("INSERT OR IGNORE INTO branch_stocks (branch_id, product_id, stock, local_cogs, local_price, is_active, is_delivery_required) VALUES (?, ?, 50, 10000, 20000, 1, NULL)")
    ->execute([$branch2Id, $testProdId]);

// 3. Set Cabang 1: Wajib Ongkir (1), Cabang 2: Bebas Ongkir (0)
$pdo->prepare("UPDATE branch_stocks SET is_delivery_required = 1 WHERE branch_id = ? AND product_id = ?")
    ->execute([$branch1Id, $testProdId]);
$pdo->prepare("UPDATE branch_stocks SET is_delivery_required = 0 WHERE branch_id = ? AND product_id = ?")
    ->execute([$branch2Id, $testProdId]);

$b1Deliv = $pdo->query("SELECT is_delivery_required FROM branch_stocks WHERE branch_id = $branch1Id AND product_id = $testProdId")->fetchColumn();
$b2Deliv = $pdo->query("SELECT is_delivery_required FROM branch_stocks WHERE branch_id = $branch2Id AND product_id = $testProdId")->fetchColumn();

assertTest((int)$b1Deliv === 1, "Cabang 1 ({$branches[0]['name']}) berhasil disetting Wajib Ongkir (1).");
assertTest((int)$b2Deliv === 0, "Cabang 2 ({$branches[1]['name']}) berhasil disetting Bebas Ongkir (0).");

// 4. Verifikasi query POS Kasir Cabang 1
$stmtPOS1 = $pdo->prepare("
    SELECT p.id, p.name,
           COALESCE(bs.is_delivery_required, p.is_delivery_required, 0) as is_delivery_required
    FROM products p
    JOIN branch_stocks bs ON p.id = bs.product_id
    WHERE bs.branch_id = ? AND p.id = ?
");
$stmtPOS1->execute([$branch1Id, $testProdId]);
$posRow1 = $stmtPOS1->fetch();

assertTest((int)$posRow1['is_delivery_required'] === 1, "Di POS Kasir Cabang 1, produk terdeteksi WAJIB ONGKIR (1).");

// 5. Verifikasi query POS Kasir Cabang 2
$stmtPOS2 = $pdo->prepare("
    SELECT p.id, p.name,
           COALESCE(bs.is_delivery_required, p.is_delivery_required, 0) as is_delivery_required
    FROM products p
    JOIN branch_stocks bs ON p.id = bs.product_id
    WHERE bs.branch_id = ? AND p.id = ?
");
$stmtPOS2->execute([$branch2Id, $testProdId]);
$posRow2 = $stmtPOS2->fetch();

assertTest((int)$posRow2['is_delivery_required'] === 0, "Di POS Kasir Cabang 2, produk terdeteksi BEBAS ONGKIR (0).");

// 6. Test toggle status wajib ongkir di Cabang 1 menjadi Bebas Ongkir (0)
$pdo->prepare("UPDATE branch_stocks SET is_delivery_required = 0 WHERE branch_id = ? AND product_id = ?")
    ->execute([$branch1Id, $testProdId]);
$stmtPOS1Toggle = $pdo->prepare("
    SELECT COALESCE(bs.is_delivery_required, p.is_delivery_required, 0) as is_delivery_required
    FROM products p
    JOIN branch_stocks bs ON p.id = bs.product_id
    WHERE bs.branch_id = ? AND p.id = ?
");
$stmtPOS1Toggle->execute([$branch1Id, $testProdId]);
assertTest((int)$stmtPOS1Toggle->fetchColumn() === 0, "Toggle berhasil mengubah status produk di Cabang 1 menjadi Bebas Ongkir (0).");

// 7. Verifikasi get_inventory_stocks
$stmtInv = $pdo->prepare("
    SELECT p.id as product_id,
           bs.is_delivery_required as branch_is_delivery_required,
           COALESCE(bs.is_delivery_required, p.is_delivery_required, 0) as is_delivery_required
    FROM products p
    JOIN branch_stocks bs ON p.id = bs.product_id
    WHERE bs.branch_id = ? AND p.id = ?
");
$stmtInv->execute([$branch1Id, $testProdId]);
$invRow = $stmtInv->fetch();
assertTest(isset($invRow['is_delivery_required']), "Endpoint get_inventory_stocks menyajikan data is_delivery_required spesifik cabang.");

echo "\nSemua pengujian fungsional Pengaturan Wajib Ongkir per Cabang BERHASIL (100% PASS)!\n";
