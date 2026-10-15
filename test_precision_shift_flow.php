<?php
/**
 * Test Precision Shift Flow:
 * - Opening Shift (Stok Awal di Kiri + Penambahan Stok Baru Pagi, Stok Malam di Kanan Terkunci/Readonly)
 * - Transaksi Penjualan Shift
 * - Closing Akhir di Shift Terakhir (Rekonsiliasi Kas Laci + Komparasi Stok Fisik Akhir Malam vs Stok Awal)
 * - Auto Pre-fill Terkunci untuk Sesi Shift Berikutnya
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

echo "=== MEMULAI TEST PRECISION SHIFT FLOW (OPENING & CLOSING AKHIR) ===\n\n";

$branchId = 1;
$userId = 1;

// Tutup shift aktif jika ada sebelumnya
$pdo->query("UPDATE cashier_shifts SET status = 'CLOSED' WHERE branch_id = $branchId AND status = 'OPEN'");

// 1. Ambil data opening shift awal
echo "[TEST 1] Memeriksa get_opening_shift_data (Stok Malam sebagai referensi terkunci)...\n";
$ch = curl_init("http://127.0.0.1:8000/api.php?action=get_opening_shift_data&branch_id=$branchId");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
curl_close($ch);
$data = json_decode($res, true);
assertTest($data && $data['success'] === true, "get_opening_shift_data berhasil");
$items = $data['items'];
assertTest(!empty($items), "Daftar produk pelacakan shift tidak kosong");
echo "Ditemukan " . count($items) . " produk yang dilacak.\n\n";

// 2. Buka shift pagi dengan penambahan stok baru (Stok Awal > Stok Malam)
// Sesuai kasus user: Tutup Galon Mineral malam ada 50, pagi ini ada penambahan baru +90 sehingga Stok Awal menjadi 140
echo "[TEST 2] Membuka Opening Shift dengan logika penambahan stok baru pagi...\n";
$openingPayload = [
    'branch_id' => $branchId,
    'user_id' => $userId,
    'initial_cash' => 300000,
    'items' => []
];

// Cari item Tutup Mineral (RAW-089), Tutup RO (RAW-020), Plastik ES (RAW-00092)
foreach ($items as $it) {
    $pId = (int)$it['product_id'];
    $sku = $it['sku'];
    $nightStock = (int)$it['night_stock'];

    if ($sku === 'RAW-089') {
        // Ada penambahan stok baru pagi +90 pcs
        $openQty = $nightStock + 90;
    } elseif ($sku === 'RAW-020') {
        // Tetap sama dengan malam
        $openQty = $nightStock;
    } else {
        $openQty = $nightStock;
    }

    $openingPayload['items'][] = [
        'product_id' => $pId,
        'opening_qty' => $openQty,
        'night_qty' => $nightStock
    ];
}

$ch = curl_init("http://127.0.0.1:8000/api.php?action=open_shift");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($openingPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$res = curl_exec($ch);
curl_close($ch);
$openRes = json_decode($res, true);
assertTest($openRes && $openRes['success'] === true, "open_shift berhasil dibuka: " . ($openRes['message'] ?? ''));
$shiftId = (int)$openRes['shift']['id'];
assertTest($shiftId > 0, "Shift ID aktif valid: $shiftId");

// Verifikasi branch_stocks tersinkronisasi dengan Stok Awal fisik pagi
$stmtBs = $pdo->prepare("SELECT stock FROM branch_stocks WHERE branch_id = ? AND product_id = ?");
$stmtBs->execute([$branchId, 89]);
$bsMin = (float)$stmtBs->fetchColumn();
assertTest($bsMin >= 90, "branch_stocks produk Tutup Mineral (ID 89) mencerminkan stok awal pagi: $bsMin");
echo "Shift ID $shiftId berhasil dibuka dengan verifikasi penambahan stok pagi.\n\n";

// 3. Lakukan transaksi penjualan saat shift aktif untuk menguji rekonsiliasi kas
echo "[TEST 3] Melakukan transaksi penjualan kasir (checkout) saat shift aktif...\n";
$salePayload = [
    'branch_id' => $branchId,
    'user_id' => $userId,
    'shift_id' => $shiftId,
    'customer_name' => 'Pelanggan Toko Shift',
    'customer_category' => 'REGULER',
    'items' => [
        ['product_id' => 98, 'qty' => 2, 'unit_price' => 18000] // Air Mineral Galon
    ],
    'payment_method' => 'CASH',
    'cash_received' => 50000
];

$ch = curl_init("http://127.0.0.1:8000/api.php?action=checkout_sale");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($salePayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$res = curl_exec($ch);
curl_close($ch);
$saleRes = json_decode($res, true);
assertTest($saleRes && $saleRes['success'] === true, "Penjualan tunai berhasil diproses saat shift aktif");
$saleTotal = (float)$saleRes['sale']['grand_total'];
assertTest($saleTotal > 0, "Total penjualan tunai: Rp " . number_format($saleTotal, 0, ',', '.'));
echo "Transaksi penjualan tercatat pada shift ID $shiftId.\n\n";

// 4. Test endpoint get_closing_shift_data
echo "[TEST 4] Menguji API get_closing_shift_data untuk persiapan Closing Akhir Shift Terakhir...\n";
$ch = curl_init("http://127.0.0.1:8000/api.php?action=get_closing_shift_data&shift_id=$shiftId&branch_id=$branchId");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
curl_close($ch);
$closingData = json_decode($res, true);
assertTest($closingData && $closingData['success'] === true, "get_closing_shift_data berhasil dipanggil");
assertTest((float)$closingData['initial_cash'] === 300000.0, "Modal awal terverifikasi Rp 300.000");
assertTest((float)$closingData['cash_sales'] >= $saleTotal, "Penjualan tunai shift ini terhitung akurat");
$expectedCash = (float)$closingData['expected_cash'];
assertTest($expectedCash === (300000 + (float)$closingData['cash_sales']), "Ekspektasi uang fisik kasir: Rp " . number_format($expectedCash, 0, ',', '.'));
assertTest(!empty($closingData['items']), "Item komparasi stok penutupan tersedia");
echo "Data rekonsiliasi kas dan stok komparasi closing siap disajikan di antarmuka kasir.\n\n";

// 5. Tutup shift malam (Closing Akhir Shift Terakhir)
echo "[TEST 5] Menutup Shift Terakhir (Closing Akhir Malam) dengan laporan sisa stok fisik...\n";
$closingPayload = [
    'shift_id' => $shiftId,
    'actual_cash' => $expectedCash, // Uang fisik klop
    'items' => [
        ['product_id' => 89, 'closing_qty' => 135], // Sisa fisik malam tutup mineral 135
        ['product_id' => 88, 'closing_qty' => 80],  // Sisa fisik malam tutup ro 80
        ['product_id' => 105, 'closing_qty' => 205] // Sisa fisik malam plastik es 205
    ],
    'shift_notes' => 'Closing Shift Terakhir Malam: Operasional lancar, kas klop, sisa fisik siap serah terima esok hari.'
];

$ch = curl_init("http://127.0.0.1:8000/api.php?action=close_shift");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($closingPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$res = curl_exec($ch);
curl_close($ch);
$closeRes = json_decode($res, true);
assertTest($closeRes && $closeRes['success'] === true, "close_shift berhasil ditutup");
assertTest((float)$closeRes['summary']['cash_difference'] === 0.0, "Selisih kas bernilai Rp 0 (Klop)");

// Verifikasi database cashier_shifts status CLOSED dan closing_items_json
$dbCheck = new PDO('sqlite:' . __DIR__ . '/database.sqlite');
$dbCheck->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$stmtClosed = $dbCheck->prepare("SELECT status, closing_items_json, shift_notes FROM cashier_shifts WHERE id = ?");
$stmtClosed->execute([$shiftId]);
$rowClosed = $stmtClosed->fetch(PDO::FETCH_ASSOC);
assertTest($rowClosed && $rowClosed['status'] === 'CLOSED', "Status shift berubah menjadi CLOSED");
$savedClosingJson = json_decode($rowClosed['closing_items_json'], true);
assertTest(isset($savedClosingJson[89]) && $savedClosingJson[89] === 135, "Sisa fisik malam Tutup Mineral tersimpan 135");
assertTest(!empty($rowClosed['shift_notes']), "Catatan shift malam tersimpan");
echo "Shift terakhir berhasil ditutup dan laporan sisa fisik malam terkunci di database.\n\n";

// 6. Verifikasi Sesi Opening Shift Esok Hari (Pre-fill Terkunci di Kolom Kanan)
echo "[TEST 6] Memverifikasi pre-fill terkunci pada Opening Shift sesi berikutnya...\n";
$ch = curl_init("http://127.0.0.1:8000/api.php?action=get_opening_shift_data&branch_id=$branchId");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
curl_close($ch);
$nextDayData = json_decode($res, true);
assertTest($nextDayData && $nextDayData['success'] === true, "get_opening_shift_data sesi berikutnya sukses");

$foundMin = null;
foreach ($nextDayData['items'] as $item) {
    if ((int)$item['product_id'] === 89) {
        $foundMin = $item;
        break;
    }
}
assertTest($foundMin !== null, "Tutup Mineral ditemukan di daftar produk");
assertTest((int)$foundMin['night_stock'] === 135, "Stok malam Tutup Mineral otomatis terisi 135 (sama dengan laporan closing malam)");
assertTest((int)$foundMin['opening_stock'] === 135, "Stok awal default terisi 135 (siap ditambah stok baru jika ada kiriman pagi)");
echo "Pre-fill otomatis pada Kolom Kanan (Stok Malam Terkunci) 100% akurat!\n\n";

echo "===================================================================\n";
echo " SEMUA TEST PRECISION SHIFT FLOW BERHASIL DENGAN SEMPURNA (100% PASS)\n";
echo "===================================================================\n";
