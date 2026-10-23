<?php
/**
 * Test Opening Shift Formula (Penambahan Stok Baru Kasir Pertama & Modal Kasir Kasir Berikutnya)
 * 
 * Verifikasi:
 * 1. Kasir 1 (Shift 1): Formula Stok Awal = Input Tambahan Baru + Stok Malam. Default 0 tidak mengubah stok.
 * 2. Kasir 2 (Shift 2+): Hanya menginputkan uang modal awal saja, sisa stok diserahterimakan otomatis.
 * 3. Backend auto-inheritance: Jika payload items kosong di Shift 2+, otomatis mewarisi closing shift sebelumnya.
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

echo "=== MEMULAI TEST OPENING SHIFT PENAMBAHAN STOK & KASIR BERIKUTNYA ===\n\n";

$branchId = 1;
$userId1 = 1;
$userId2 = 2;
$baseUrl = 'http://localhost:8000';
if (getenv('BASE_URL')) {
    $baseUrl = getenv('BASE_URL');
}

// Persiapan: Tutup semua shift aktif sebelumnya pada branch 1
$pdo->query("UPDATE cashier_shifts SET status = 'CLOSED' WHERE branch_id = $branchId AND status = 'OPEN'");

// Siapkan stok awal di branch_stocks
$pdo->prepare("REPLACE INTO branch_stocks (branch_id, product_id, stock, local_price, local_cogs) VALUES (?, 89, 25, 0, 0)")->execute([$branchId]);
$pdo->prepare("REPLACE INTO branch_stocks (branch_id, product_id, stock, local_price, local_cogs) VALUES (?, 88, 15, 0, 0)")->execute([$branchId]);

// -------------------------------------------------------------
// [TEST 1] Kasir Pertama (Shift 1 Pagi): Input Tambahan Stok
// -------------------------------------------------------------
echo "[TEST 1] Menguji Kasir Pertama (Shift 1) dengan input tambahan...\n";

// Produk 89: Stok malam = 25, input tambahan = 5 -> Stok awal = 30
// Produk 88: Stok malam = 15, input tambahan = 0 -> Stok awal = 15
$payloadShift1 = json_encode([
    'branch_id' => $branchId,
    'user_id' => $userId1,
    'shift_number' => 1,
    'shift_name' => 'Shift 1 (Pagi)',
    'initial_cash' => 100000,
    'items' => [
        [
            'product_id' => 89,
            'sku' => 'RAW-089',
            'added_qty' => 5,
            'night_qty' => 25,
            'opening_qty' => 30
        ],
        [
            'product_id' => 88,
            'sku' => 'RAW-020',
            'added_qty' => 0,
            'night_qty' => 15,
            'opening_qty' => 15
        ]
    ],
    'opening_raw_mineral' => 30,
    'opening_raw_ro' => 15,
    'closing_night_mineral' => 25,
    'closing_night_ro' => 15
]);

$ch = curl_init("$baseUrl/api.php?action=open_shift");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadShift1);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$res = curl_exec($ch);
if (curl_errno($ch)) {
    echo "CURL ERROR: " . curl_error($ch) . "\n";
}
curl_close($ch);

echo "RES: " . substr($res, 0, 500) . "\n";
$data1 = json_decode($res, true);
assertTest($data1 && $data1['success'] === true, "Opening Shift 1 harus berhasil: " . ($data1['message'] ?? ''));
$shift1Id = (int)$data1['shift']['id'];
assertTest($shift1Id > 0, "ID Shift 1 valid ($shift1Id)");

// Verifikasi isi record cashier_shifts
$stmtCheck1 = $pdo->prepare("SELECT * FROM cashier_shifts WHERE id = ?");
$stmtCheck1->execute([$shift1Id]);
$rowShift1 = $stmtCheck1->fetch();

assertTest((int)$rowShift1['shift_number'] === 1, "Shift number adalah 1");
assertTest((int)$rowShift1['opening_raw_mineral'] === 30, "opening_raw_mineral bernilai 30 (25 + 5)");
assertTest((int)$rowShift1['opening_raw_ro'] === 15, "opening_raw_ro bernilai 15 (15 + 0)");

$openMap1 = json_decode($rowShift1['opening_items_json'], true);
assertTest(isset($openMap1[89]) && (int)$openMap1[89] === 30, "opening_items_json untuk produk 89 = 30");
assertTest(isset($openMap1[88]) && (int)$openMap1[88] === 15, "opening_items_json untuk produk 88 = 15");

// Verifikasi branch_stocks terupdate
$stmtStock = $pdo->prepare("SELECT stock FROM branch_stocks WHERE branch_id = ? AND product_id = ?");
$stmtStock->execute([$branchId, 89]);
$stock89 = (int)$stmtStock->fetchColumn();
assertTest($stock89 === 30, "branch_stocks produk 89 terupdate menjadi 30");

$stmtStock->execute([$branchId, 88]);
$stock88 = (int)$stmtStock->fetchColumn();
assertTest($stock88 === 15, "branch_stocks produk 88 tetap 15");
echo "Shift 1 Pagi berhasil dibuka dengan rumus penambahan stok.\n\n";

// -------------------------------------------------------------
// [TEST 2] Tutup Shift 1 (Closing Shift 1)
// -------------------------------------------------------------
echo "[TEST 2] Menutup Shift 1 dengan sisa stok fisik...\n";
// Misal produk 89 sisa 22 (terjual 8), produk 88 sisa 11 (terjual 4)
$payloadClose1 = json_encode([
    'shift_id' => $shift1Id,
    'branch_id' => $branchId,
    'user_id' => $userId1,
    'actual_cash' => 150000,
    'closing_raw_mineral' => 22,
    'closing_raw_ro' => 11,
    'closing_raw_plastik' => 100,
    'closing_items' => [
        ['product_id' => 89, 'closing_qty' => 22],
        ['product_id' => 88, 'closing_qty' => 11]
    ]
]);

$ch = curl_init("$baseUrl/api.php?action=close_shift");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadClose1);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$resClose = curl_exec($ch);
echo "RES CLOSE: " . $resClose . "\n";
curl_close($ch);

$dataClose = json_decode($resClose, true);
assertTest($dataClose && $dataClose['success'] === true, "Closing Shift 1 harus sukses: " . ($dataClose['message'] ?? ''));

// Verifikasi status Shift 1 menjadi CLOSED
// Re-open PDO connection to ensure no stale SQLite cache
$pdo = new PDO('sqlite:' . __DIR__ . '/database.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmtClosed1 = $pdo->prepare("SELECT id, status, closing_items_json, closing_raw_mineral, closing_raw_ro FROM cashier_shifts WHERE id = ?");
$stmtClosed1->execute([$shift1Id]);
$rowClosed1 = $stmtClosed1->fetch(PDO::FETCH_ASSOC);
echo "ROW CLOSED 1: " . json_encode($rowClosed1) . "\n";
assertTest($rowClosed1 && $rowClosed1['status'] === 'CLOSED', "Status Shift 1 adalah CLOSED");
assertTest((int)$rowClosed1['closing_raw_mineral'] === 22, "Closing raw mineral = 22");
assertTest((int)$rowClosed1['closing_raw_ro'] === 11, "Closing raw ro = 11");
echo "Shift 1 berhasil ditutup dan siap diserahterimakan.\n\n";

// -------------------------------------------------------------
// [TEST 3] Get Opening Shift Data untuk Kasir 2 (Shift Berikutnya)
// -------------------------------------------------------------
echo "[TEST 3] Memeriksa get_opening_shift_data untuk Shift 2...\n";
$ch = curl_init("$baseUrl/api.php?action=get_opening_shift_data&branch_id=$branchId");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resData2 = curl_exec($ch);
curl_close($ch);

$data2 = json_decode($resData2, true);
assertTest($data2 && $data2['success'] === true, "get_opening_shift_data Shift 2 sukses");
$nextShiftNum = (int)$data2['next_shift_number'];
assertTest($nextShiftNum > 1, "next_shift_number adalah shift berikutnya (ditemukan $nextShiftNum)");
assertTest($data2['is_continuation'] === true, "is_continuation bernilai true");
assertTest(!empty($data2['previous_shift_info']), "previous_shift_info tersedia");

// Verifikasi stok acuan night_stock adalah sisa closing Shift 1
$found89 = false;
$found88 = false;
foreach ($data2['items'] as $item) {
    if ((int)$item['product_id'] === 89) {
        $found89 = true;
        assertTest((int)$item['night_stock'] === 22, "Produk 89 sisa serah terima = 22");
    }
    if ((int)$item['product_id'] === 88) {
        $found88 = true;
        assertTest((int)$item['night_stock'] === 11, "Produk 88 sisa serah terima = 11");
    }
}
assertTest($found89 && $found88, "Kedua produk ditemukan di payload serah terima");
echo "Data serah terima Shift 2 valid.\n\n";

// -------------------------------------------------------------
// [TEST 4] Kasir 2 Buka Shift 2: Hanya Input Uang Modal Awal
// -------------------------------------------------------------
echo "[TEST 4] Membuka Shift 2: Kasir hanya input uang modal awal (tanpa ketik stok)...\n";
$payloadShift2 = json_encode([
    'branch_id' => $branchId,
    'user_id' => $userId2,
    'shift_number' => 2,
    'shift_name' => 'Shift 2 (Siang/Sore)',
    'initial_cash' => 200000,
    // Frontend mengirimkan carried items
    'items' => [
        ['product_id' => 89, 'added_qty' => 0, 'night_qty' => 22, 'opening_qty' => 22],
        ['product_id' => 88, 'added_qty' => 0, 'night_qty' => 11, 'opening_qty' => 11]
    ],
    'opening_raw_mineral' => 22,
    'opening_raw_ro' => 11
]);

$ch = curl_init("$baseUrl/api.php?action=open_shift");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadShift2);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$resShift2 = curl_exec($ch);
curl_close($ch);

$dataShift2 = json_decode($resShift2, true);
assertTest($dataShift2 && $dataShift2['success'] === true, "Opening Shift 2 sukses: " . ($dataShift2['message'] ?? ''));
$shift2Id = (int)$dataShift2['shift']['id'];
assertTest($shift2Id > 0, "ID Shift 2 valid ($shift2Id)");

$pdo = new PDO('sqlite:' . __DIR__ . '/database.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$stmtCheck2 = $pdo->prepare("SELECT * FROM cashier_shifts WHERE id = ?");
$stmtCheck2->execute([$shift2Id]);
$rowShift2 = $stmtCheck2->fetch(PDO::FETCH_ASSOC);
assertTest((int)$rowShift2['shift_number'] === 2, "Shift 2 shift_number = 2");
assertTest((int)$rowShift2['initial_cash'] === 200000, "Modal awal Shift 2 = 200.000");
assertTest((int)$rowShift2['opening_raw_mineral'] === 22, "Stok awal Shift 2 raw mineral = 22");
assertTest((int)$rowShift2['opening_raw_ro'] === 11, "Stok awal Shift 2 raw ro = 11");
echo "Kasir 2 sukses membuka shift hanya dengan input uang modal awal.\n\n";

// -------------------------------------------------------------
// [TEST 5] Shift Berikutnya dengan Payload Items Kosong (Auto-Inheritance)
// -------------------------------------------------------------
echo "[TEST 5] Menguji Auto-Inheritance backend ketika payload items kosong di Shift > 1...\n";
// Tutup shift 2 dulu
$pdo->prepare("UPDATE cashier_shifts SET status = 'CLOSED', closing_items_json = '{\"89\":18,\"88\":9}', closing_raw_mineral = 18, closing_raw_ro = 9 WHERE id = ?")->execute([$shift2Id]);

// Kasir 3 buka shift 3 dengan payload minimal (HANYA initial_cash)
$payloadShift3 = json_encode([
    'branch_id' => $branchId,
    'user_id' => $userId1,
    'shift_number' => 3,
    'shift_name' => 'Shift 3 (Malam)',
    'initial_cash' => 50000,
    'items' => [] // sengaja kosong
]);

$ch = curl_init("$baseUrl/api.php?action=open_shift");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadShift3);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$resShift3 = curl_exec($ch);
curl_close($ch);

$dataShift3 = json_decode($resShift3, true);
assertTest($dataShift3 && $dataShift3['success'] === true, "Opening Shift 3 tanpa items sukses");
$shift3Id = (int)$dataShift3['shift']['id'];

$pdo = new PDO('sqlite:' . __DIR__ . '/database.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$stmtCheck3 = $pdo->prepare("SELECT * FROM cashier_shifts WHERE id = ?");
$stmtCheck3->execute([$shift3Id]);
$rowShift3 = $stmtCheck3->fetch();
$openMap3 = json_decode($rowShift3['opening_items_json'], true);
assertTest(isset($openMap3[89]) && (int)$openMap3[89] === 18, "Auto-inheritance produk 89 = 18");
assertTest(isset($openMap3[88]) && (int)$openMap3[88] === 9, "Auto-inheritance produk 88 = 9");
assertTest((int)$rowShift3['opening_raw_mineral'] === 18, "Auto-inheritance raw mineral = 18");
assertTest((int)$rowShift3['opening_raw_ro'] === 9, "Auto-inheritance raw ro = 9");
echo "Auto-inheritance backend berfungsi 100% sempurna.\n\n";

// Cleanup: tutup shift uji coba
$pdo->prepare("UPDATE cashier_shifts SET status = 'CLOSED' WHERE id IN (?, ?, ?)")->execute([$shift1Id, $shift2Id, $shift3Id]);

echo "=== SEMUA TEST BERHASIL DILALUI (100% PASS) ===\n";
