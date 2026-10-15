<?php
/**
 * Test Dynamic Opening Shift Products (Fleksibilitas Tambah Produk di Opening Sales)
 */

require_once __DIR__ . '/db.php';

function assertTest($condition, $message) {
    if ($condition) {
        echo " [PASS] $message\n";
    } else {
        echo " [FAIL] $message\n";
        exit(1);
    }
}

echo "=== MEMULAI TEST DYNAMIC OPENING SHIFT PRODUCTS ===\n\n";

$branchId = 1;
$userId = 1;

// 1. Ambil daftar produk pelacakan awal via api get_opening_shift_products
echo "[TEST 1] Mengambil daftar konfigurasi produk pelacakan opening shift...\n";
$ch = curl_init("http://127.0.0.1:8000/api.php?action=get_opening_shift_products");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
curl_close($ch);

$data = json_decode($res, true);
assertTest($data && $data['success'] === true, "API get_opening_shift_products harus sukses");
$initialCount = count($data['products']);
assertTest($initialCount >= 3, "Minimal 3 produk default terdaftar (ditemukan $initialCount)");
echo "Jumlah produk pelacakan awal: $initialCount\n\n";

// 2. Tambah produk baru (misal: ID 90 - RAW-090 Tisu Galon CC2) ke pelacakan opening shift
echo "[TEST 2] Mendaftarkan produk baru (RAW-090 Tisu Galon CC2, ID: 90) ke Opening Shift...\n";
$newProductId = 90;

$postData = json_encode([
    'product_id' => $newProductId,
    'sort_order' => 4,
    'is_active' => 1
]);

$ch = curl_init("http://127.0.0.1:8000/api.php?action=save_opening_shift_product");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$res = curl_exec($ch);
curl_close($ch);

$data = json_decode($res, true);
assertTest($data && $data['success'] === true, "save_opening_shift_product harus sukses: " . ($data['message'] ?? ''));

// Verifikasi get_opening_shift_products bertambah 1
$ch = curl_init("http://127.0.0.1:8000/api.php?action=get_opening_shift_products");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
curl_close($ch);
$data = json_decode($res, true);
$newCount = count($data['products']);
assertTest($newCount === $initialCount + 1, "Jumlah produk pelacakan bertambah menjadi " . ($initialCount + 1));
$foundNew = false;
foreach ($data['products'] as $p) {
    if ((int)$p['product_id'] === $newProductId) {
        $foundNew = true;
        break;
    }
}
assertTest($foundNew, "Produk baru ID $newProductId (RAW-090) ditemukan di daftar pelacakan");
echo "Produk ID $newProductId berhasil ditambahkan ke daftar pelacakan.\n\n";

// 3. Test get_opening_shift_data: Harus merender item baru di array items
echo "[TEST 3] Verifikasi get_opening_shift_data mengembalikan produk baru di dynamic items...\n";
$ch = curl_init("http://127.0.0.1:8000/api.php?action=get_opening_shift_data&branch_id=$branchId");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
curl_close($ch);
$data = json_decode($res, true);
assertTest($data && $data['success'] === true, "get_opening_shift_data harus sukses");
$items = $data['items'];
assertTest(count($items) === $newCount, "Dynamic items berjumlah $newCount item");
$foundInShiftItems = false;
foreach ($items as $item) {
    if ((int)$item['product_id'] === $newProductId) {
        $foundInShiftItems = true;
        assertTest($item['sku'] === 'RAW-090', "SKU item sesuai RAW-090");
        break;
    }
}
assertTest($foundInShiftItems, "Produk baru RAW-090 muncul di payload opening shift items");
echo "Produk baru siap dirender pada modal Opening Shift pagi.\n\n";

// 4. Buka shift dengan payload dynamic items (termasuk produk baru)
echo "[TEST 4] Membuka Shift Kasir dengan dynamic items payload...\n";
// Pastikan tidak ada shift aktif
$pdo->query("UPDATE cashier_shifts SET status = 'CLOSED' WHERE branch_id = $branchId AND status = 'OPEN'");

$openPayload = json_encode([
    'branch_id' => $branchId,
    'user_id' => $userId,
    'initial_cash' => 250000,
    'items' => [
        ['product_id' => 89, 'opening_qty' => 150, 'night_qty' => 150],
        ['product_id' => 88, 'opening_qty' => 90, 'night_qty' => 90],
        ['product_id' => 105, 'opening_qty' => 220, 'night_qty' => 220],
        ['product_id' => 90, 'opening_qty' => 300, 'night_qty' => 300]
    ]
]);

$ch = curl_init("http://127.0.0.1:8000/api.php?action=open_shift");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $openPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$res = curl_exec($ch);
curl_close($ch);

$data = json_decode($res, true);
assertTest($data && $data['success'] === true, "open_shift harus sukses: " . ($data['message'] ?? ''));
$shiftId = (int)$data['shift']['id'];
assertTest($shiftId > 0, "Shift ID valid: $shiftId");

// Verifikasi penyimpanan opening_items_json di database
$stmtCheckShift = $pdo->prepare("SELECT opening_items_json, opening_raw_mineral FROM cashier_shifts WHERE id = ?");
$stmtCheckShift->execute([$shiftId]);
$shiftRow = $stmtCheckShift->fetch(PDO::FETCH_ASSOC);
$savedOpeningJson = json_decode($shiftRow['opening_items_json'], true);
assertTest(is_array($savedOpeningJson), "opening_items_json tersimpan sebagai JSON valid");
assertTest(isset($savedOpeningJson[90]) && $savedOpeningJson[90] === 300, "Stok awal produk 90 (RAW-090) tersimpan 300 di JSON");
assertTest((int)$shiftRow['opening_raw_mineral'] === 150, "Backward compatibility kolom opening_raw_mineral tersimpan 150");
echo "Opening shift berhasil dibuka dengan stok produk baru terverifikasi.\n\n";

// 5. Tutup shift dengan payload dynamic items (Closing stock untuk seluruh produk termasuk produk baru)
echo "[TEST 5] Menutup Shift Kasir dengan dynamic items payload closing...\n";
$closePayload = json_encode([
    'shift_id' => $shiftId,
    'actual_cash' => 250000,
    'items' => [
        ['product_id' => 89, 'closing_qty' => 140],
        ['product_id' => 88, 'closing_qty' => 85],
        ['product_id' => 105, 'closing_qty' => 210],
        ['product_id' => 90, 'closing_qty' => 280] // Tisu sisa malam 280
    ]
]);

$ch = curl_init("http://127.0.0.1:8000/api.php?action=close_shift");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $closePayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$res = curl_exec($ch);
curl_close($ch);

$data = json_decode($res, true);
assertTest($data && $data['success'] === true, "close_shift harus sukses");

// Verifikasi penyimpanan closing_items_json di database
$stmtCheckShift = $pdo->prepare("SELECT status, closing_items_json, closing_raw_mineral FROM cashier_shifts WHERE id = ?");
$stmtCheckShift->execute([$shiftId]);
$closedRow = $stmtCheckShift->fetch(PDO::FETCH_ASSOC);
assertTest($closedRow['status'] === 'CLOSED', "Status shift berstatus CLOSED");
$savedClosingJson = json_decode($closedRow['closing_items_json'], true);
assertTest(is_array($savedClosingJson), "closing_items_json tersimpan sebagai JSON valid");
assertTest(isset($savedClosingJson[90]) && $savedClosingJson[90] === 280, "Stok closing produk 90 (RAW-090) tersimpan 280 di JSON");
assertTest((int)$closedRow['closing_raw_mineral'] === 140, "Backward compatibility closing_raw_mineral tersimpan 140");
echo "Closing shift berhasil ditutup dengan laporan stok malam produk baru tersimpan.\n\n";

// 6. Test pre-fill otomatis stok malam pada sesi opening shift berikutnya
echo "[TEST 6] Memverifikasi pre-fill otomatis stok malam untuk produk baru pada shift berikutnya...\n";
$ch = curl_init("http://127.0.0.1:8000/api.php?action=get_opening_shift_data&branch_id=$branchId");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
curl_close($ch);
$data = json_decode($res, true);
assertTest($data && $data['success'] === true, "get_opening_shift_data sesi berikutnya sukses");

$nextItems = $data['items'];
$foundNext90 = null;
foreach ($nextItems as $it) {
    if ((int)$it['product_id'] === 90) {
        $foundNext90 = $it;
        break;
    }
}
assertTest($foundNext90 !== null, "Produk 90 ditemukan di respon sesi berikutnya");
assertTest((int)$foundNext90['night_stock'] === 280, "Stok malam produk baru otomatis ter-prefill 280 (nilai closing shift sebelumnya)");
assertTest((int)$foundNext90['opening_stock'] === 280, "Stok awal default terisi 280 sama dengan stok malam");
echo "Pre-fill otomatis stok malam produk baru terkonfirmasi akurat!\n\n";

// 7. Test hapus / nonaktifkan konfigurasi pelacakan produk (opsional CRUD)
echo "[TEST 7] Test CRUD konfigurasi: toggle nonaktif dan hapus produk pelacakan...\n";
// Ambil ID baris opening_shift_products untuk produk 90
$stmtGetOspId = $pdo->prepare("SELECT id FROM opening_shift_products WHERE product_id = ?");
$stmtGetOspId->execute([$newProductId]);
$ospId = (int)$stmtGetOspId->fetchColumn();
assertTest($ospId > 0, "ID konfigurasi opening_shift_products valid: $ospId");

// Hapus konfigurasi
$delPayload = json_encode(['id' => $ospId]);
$ch = curl_init("http://127.0.0.1:8000/api.php?action=delete_opening_shift_product");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $delPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$res = curl_exec($ch);
curl_close($ch);
$data = json_decode($res, true);
assertTest($data && $data['success'] === true, "delete_opening_shift_product harus sukses");

// Verifikasi setelah dihapus
$ch = curl_init("http://127.0.0.1:8000/api.php?action=get_opening_shift_products");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
curl_close($ch);
$data = json_decode($res, true);
assertTest(count($data['products']) === $initialCount, "Jumlah produk kembali ke $initialCount setelah produk dihapus");
echo "Penghapusan produk pelacakan dari konfigurasi berhasil.\n\n";

echo "===========================================================\n";
echo " SEMUA TEST DYNAMIC OPENING SHIFT PRODUCTS BERHASIL (100% PASS)\n";
echo "===========================================================\n";
