<?php
/**
 * Test Automation: Multi-Shift Harian (2 Shift, 2 Kasir Berbeda)
 * Memverifikasi:
 * 1. Kasir 1 buka Shift 1 (Pagi), transaksi penjualan Shift 1, tutup Shift 1 (Serah terima siang).
 * 2. Kasir 2 buka Shift 2 (Siang/Malam), stok acuan otomatis bersumber dari sisa closing Shift 1.
 * 3. Transaksi Shift 2 terikat ke Kasir 2.
 * 4. Kasir 2 tutup Shift 2 sebagai Closing Akhir / Tutup Toko Malam.
 * 5. Pelaporan Shift Kasir: Data Kasir 1 dan Kasir 2 terisolasi murni tanpa bercampur.
 * 6. Rekap Tutup Toko Harian: Konsolidasi omzet dan stok akhir malam penutupan toko terkunci.
 */

require_once 'db.php';

function assertTest($condition, $message) {
    if ($condition) {
        echo "  [PASS] $message\n";
    } else {
        echo "  [FAIL] $message\n";
        exit(1);
    }
}

echo "=== MEMULAI TEST INTEGRASI MULTI-SHIFT & ISOLASI LAPORAN KASIR ===\n\n";

$branchId = 1;
$userKasir1 = 3; // Bima
$userKasir2 = 4; // Abdul Latif (atau user 6 Keenan)

// Pastikan shift open sebelumnya di cabang 1 ditutup terlebih dahulu untuk isolasi test
$pdo->prepare("UPDATE cashier_shifts SET status = 'CLOSED' WHERE branch_id = ? AND status = 'OPEN'")->execute([$branchId]);

// -------------------------------------------------------------
// STEP 1: Cek Opening Shift Data untuk Kasir 1 (Shift 1 Pagi)
// -------------------------------------------------------------
echo "1. Menguji get_opening_shift_data untuk Shift 1...\n";
$_GET = ['action' => 'get_opening_shift_data', 'branch_id' => $branchId];
ob_start();
include 'api.php';
$openData1 = json_decode(ob_get_clean(), true);
assertTest($openData1['success'] === true, "Endpoint get_opening_shift_data berhasil");
assertTest(!empty($openData1['next_shift_number']), "Mendapatkan nomor shift berikutnya");

// -------------------------------------------------------------
// STEP 2: Kasir 1 Buka Shift 1
// -------------------------------------------------------------
echo "\n2. Kasir 1 (User ID: $userKasir1) membuka Shift 1...\n";
$inputOpening1 = [
    'branch_id' => $branchId,
    'user_id' => $userKasir1,
    'shift_number' => 1,
    'shift_name' => 'Shift 1 (Pagi)',
    'initial_cash' => 300000,
    'items' => [
        ['product_id' => 89, 'opening_qty' => 150, 'night_qty' => 140],
        ['product_id' => 88, 'opening_qty' => 180, 'night_qty' => 180],
        ['product_id' => 105, 'opening_qty' => 200, 'night_qty' => 200]
    ]
];
$_GET = ['action' => 'open_shift'];
$input = $inputOpening1;
ob_start();
include 'api.php';
$shiftRes1 = json_decode(ob_get_clean(), true);
assertTest($shiftRes1['success'] === true, "Kasir 1 berhasil membuka Shift 1");
$shift1Id = (int)$shiftRes1['shift']['id'];
assertTest($shift1Id > 0, "Shift 1 ID valid: $shift1Id");
assertTest((int)$shiftRes1['shift']['shift_number'] === 1, "Shift number tercatat = 1");

// -------------------------------------------------------------
// STEP 3: Transaksi Kasir 1 di Shift 1
// -------------------------------------------------------------
echo "\n3. Melayani transaksi penjualan Shift 1 oleh Kasir 1...\n";
// Transaksi 1: Tunai (2 galon Air Mineral Galon 19 Liter)
$pGln = $pdo->query("SELECT id, selling_price, cogs FROM products WHERE id = 98 OR sku = 'PRD-00001'")->fetch();
$pIdGln = (int)$pGln['id'];

$_GET = ['action' => 'checkout_sale'];
$input = [
    'branch_id' => $branchId,
    'user_id' => $userKasir1,
    'shift_id' => $shift1Id,
    'customer_name' => 'Pelanggan Pagi 1',
    'payment_method' => 'CASH',
    'cash_received' => 50000,
    'items' => [
        ['product_id' => $pIdGln, 'qty' => 2]
    ]
];
ob_start();
include 'api.php';
$sale1 = json_decode(ob_get_clean(), true);
assertTest($sale1['success'] === true, "Transaksi Tunai Kasir 1 berhasil (Grand Total: " . $sale1['sale']['grand_total'] . ")");
$tx1GrandTotal = (float)$sale1['sale']['grand_total'];

// Transaksi 2: QRIS Rp 18.000 (1 galon)
$_GET = ['action' => 'checkout_sale'];
$input = [
    'branch_id' => $branchId,
    'user_id' => $userKasir1,
    'shift_id' => $shift1Id,
    'customer_name' => 'Pelanggan Pagi 2',
    'payment_method' => 'QRIS',
    'cash_received' => 18000,
    'items' => [
        ['product_id' => $pIdGln, 'qty' => 1]
    ]
];
ob_start();
include 'api.php';
$sale2 = json_decode(ob_get_clean(), true);
assertTest($sale2['success'] === true, "Transaksi QRIS Kasir 1 berhasil");
$tx2GrandTotal = (float)$sale2['sale']['grand_total'];

// -------------------------------------------------------------
// STEP 4: Kasir 1 Tutup Shift 1 (Serah Terima ke Kasir 2)
// -------------------------------------------------------------
echo "\n4. Kasir 1 menutup Shift 1 (Serah Terima Kasir)...\n";
$_GET = ['action' => 'get_closing_shift_data', 'shift_id' => $shift1Id, 'branch_id' => $branchId];
ob_start();
include 'api.php';
$closingShift1Data = json_decode(ob_get_clean(), true);
assertTest($closingShift1Data['success'] === true, "Data closing Shift 1 berhasil dimuat");
assertTest((float)$closingShift1Data['cash_sales'] === $tx1GrandTotal, "Penjualan tunai Shift 1 terhitung tepat (Hanya transaksi tunai Kasir 1)");

$expectedCashS1 = 300000 + $tx1GrandTotal;
$inputClose1 = [
    'shift_id' => $shift1Id,
    'actual_cash' => $expectedCashS1,
    'is_final_shift' => 0, // Serah terima kasir siang
    'shift_notes' => 'Serah terima siang dari Kasir 1 ke Kasir 2 berjalan lancar.',
    'items' => [
        ['product_id' => 89, 'closing_qty' => 147],
        ['product_id' => 88, 'closing_qty' => 179],
        ['product_id' => 105, 'closing_qty' => 198]
    ]
];
$_GET = ['action' => 'close_shift'];
$input = $inputClose1;
ob_start();
include 'api.php';
$closeRes1 = json_decode(ob_get_clean(), true);
assertTest($closeRes1['success'] === true, "Shift 1 berhasil ditutup oleh Kasir 1");
assertTest((int)$closeRes1['is_final_shift'] === 0, "Shift 1 ditandai sebagai serah terima (bukan tutup toko final)");

// -------------------------------------------------------------
// STEP 5: Kasir 2 Buka Shift 2 (Cek Serah Terima Otomatis)
// -------------------------------------------------------------
echo "\n5. Kasir 2 (User ID: $userKasir2) memeriksa data serah terima & buka Shift 2...\n";
$_GET = ['action' => 'get_opening_shift_data', 'branch_id' => $branchId];
ob_start();
include 'api.php';
$openData2 = json_decode(ob_get_clean(), true);
assertTest($openData2['success'] === true, "get_opening_shift_data untuk Shift 2 berhasil");
assertTest($openData2['is_continuation'] === true, "Sistem mendeteksi ini adalah kelanjutan shift hari ini (is_continuation = true)");
assertTest((int)$openData2['next_shift_number'] >= 2, "Nomor shift berikutnya = 2");

// Verifikasi stok acuan Shift 2 mengambil dari closing fisik Shift 1 Kasir 1 (Mineral = 147, RO = 179, Plastik = 198)
$refItem89 = null;
foreach ($openData2['items'] as $it) {
    if ($it['product_id'] == 89) $refItem89 = $it;
}
assertTest($refItem89 !== null && (int)$refItem89['night_stock'] === 147, "Stok acuan mineral Shift 2 otomatis 147 (hasil closing Shift 1 Kasir 1)");

// Kasir 2 membuka Shift 2
$inputOpening2 = [
    'branch_id' => $branchId,
    'user_id' => $userKasir2,
    'shift_number' => 2,
    'shift_name' => 'Shift 2 (Siang/Malam)',
    'initial_cash' => 250000,
    'items' => [
        ['product_id' => 89, 'opening_qty' => 147, 'night_qty' => 147],
        ['product_id' => 88, 'opening_qty' => 179, 'night_qty' => 179],
        ['product_id' => 105, 'opening_qty' => 198, 'night_qty' => 198]
    ]
];
$_GET = ['action' => 'open_shift'];
$input = $inputOpening2;
ob_start();
include 'api.php';
$shiftRes2 = json_decode(ob_get_clean(), true);
assertTest($shiftRes2['success'] === true, "Kasir 2 berhasil membuka Shift 2");
$shift2Id = (int)$shiftRes2['shift']['id'];
assertTest($shift2Id > $shift1Id, "Shift 2 ID terbit mandiri: $shift2Id");

// -------------------------------------------------------------
// STEP 6: Transaksi Kasir 2 di Shift 2
// -------------------------------------------------------------
echo "\n6. Melayani transaksi penjualan Shift 2 oleh Kasir 2...\n";
// Transaksi 3: Tunai Rp 18.000 (1 galon)
$_GET = ['action' => 'checkout_sale'];
$input = [
    'branch_id' => $branchId,
    'user_id' => $userKasir2,
    'shift_id' => $shift2Id,
    'customer_name' => 'Pelanggan Sore 1',
    'payment_method' => 'CASH',
    'cash_received' => 20000,
    'items' => [
        ['product_id' => $pIdGln, 'qty' => 1]
    ]
];
ob_start();
include 'api.php';
$sale3 = json_decode(ob_get_clean(), true);
assertTest($sale3['success'] === true, "Transaksi Tunai Kasir 2 berhasil");
$tx3GrandTotal = (float)$sale3['sale']['grand_total'];

// -------------------------------------------------------------
// STEP 7: Kasir 2 Tutup Shift 2 (Tutup Toko Akhir Malam)
// -------------------------------------------------------------
echo "\n7. Kasir 2 menutup Shift 2 sebagai Penutupan Toko Malam (Final Shift)...\n";
$expectedCashS2 = 250000 + $tx3GrandTotal;
$inputClose2 = [
    'shift_id' => $shift2Id,
    'actual_cash' => $expectedCashS2,
    'is_final_shift' => 1, // Tutup toko malam
    'shift_notes' => 'Tutup toko operasional malam selesai. Toko terkunci aman.',
    'items' => [
        ['product_id' => 89, 'closing_qty' => 146],
        ['product_id' => 88, 'closing_qty' => 179],
        ['product_id' => 105, 'closing_qty' => 197]
    ]
];
$_GET = ['action' => 'close_shift'];
$input = $inputClose2;
ob_start();
include 'api.php';
$closeRes2 = json_decode(ob_get_clean(), true);
assertTest($closeRes2['success'] === true, "Shift 2 berhasil ditutup oleh Kasir 2");
assertTest((int)$closeRes2['is_final_shift'] === 1, "Shift 2 tercatat sebagai Tutup Toko Final Malam (is_final_shift = 1)");

// -------------------------------------------------------------
// STEP 8: Verifikasi Pelaporan Multi-Shift & Isolasi Data
// -------------------------------------------------------------
echo "\n8. Menguji get_shift_reports untuk verifikasi pemisahan data kasir...\n";
unset($_GET);
$_GET = ['action' => 'get_shift_reports', 'branch_id' => $branchId, 'date' => date('Y-m-d')];
ob_start();
include 'api.php';
$reportRes = json_decode(ob_get_clean(), true);
assertTest($reportRes['success'] === true, "get_shift_reports berhasil dipanggil");

// Temukan laporan Shift 1 dan Shift 2
$s1Report = null;
$s2Report = null;
foreach ($reportRes['shifts'] as $s) {
    if ($s['id'] == $shift1Id) $s1Report = $s;
    if ($s['id'] == $shift2Id) $s2Report = $s;
}

assertTest($s1Report !== null, "Laporan Shift 1 (Kasir 1) ditemukan");
assertTest($s2Report !== null, "Laporan Shift 2 (Kasir 2) ditemukan");

// Validasi ISOLASI DATA Kasir 1
echo "\n   Validasi Isolasi Data Shift 1 (Kasir 1):\n";
assertTest((int)$s1Report['cashier_id'] === $userKasir1, "  Shift 1 terikat ke Kasir 1 (User ID $userKasir1)");
assertTest((float)$s1Report['initial_cash'] === 300000.0, "  Modal awal Kasir 1 = Rp 300.000");
assertTest((float)$s1Report['cash_sales'] === $tx1GrandTotal, "  Penjualan tunai Kasir 1 = Rp " . number_format($tx1GrandTotal));
assertTest((float)$s1Report['total_sales'] === ($tx1GrandTotal + $tx2GrandTotal), "  Total omzet Kasir 1 = Rp " . number_format($tx1GrandTotal + $tx2GrandTotal));
assertTest((float)$s1Report['actual_cash'] === $expectedCashS1, "  Kas fisik aktual Kasir 1 = Rp " . number_format($expectedCashS1));
assertTest((float)$s1Report['cash_difference'] === 0.0, "  Selisih kas Kasir 1 = Rp 0 (Seimbang)");

// Validasi ISOLASI DATA Kasir 2
echo "\n   Validasi Isolasi Data Shift 2 (Kasir 2):\n";
assertTest((int)$s2Report['cashier_id'] === $userKasir2, "  Shift 2 terikat ke Kasir 2 (User ID $userKasir2)");
assertTest((float)$s2Report['initial_cash'] === 250000.0, "  Modal awal Kasir 2 = Rp 250.000");
assertTest((float)$s2Report['cash_sales'] === $tx3GrandTotal, "  Penjualan tunai Kasir 2 = Rp " . number_format($tx3GrandTotal));
assertTest((float)$s2Report['total_sales'] === $tx3GrandTotal, "  Total omzet Kasir 2 = Rp " . number_format($tx3GrandTotal));
assertTest((float)$s2Report['actual_cash'] === $expectedCashS2, "  Kas fisik aktual Kasir 2 = Rp " . number_format($expectedCashS2));
assertTest((float)$s2Report['cash_difference'] === 0.0, "  Selisih kas Kasir 2 = Rp 0 (Seimbang)");

// Validasi REKAP TUTUP TOKO HARIAN (Konsolidasi & Stok Akhir Malam Terkunci)
echo "\n   Validasi Rekapitulasi Tutup Toko Harian (Store Daily Summary):\n";
$daily = $reportRes['daily_summary'];
assertTest($daily['is_store_closed'] === true, "  Status Toko: TUTUP TOKO MALAM SELESAI (is_store_closed = true)");
$finalNightMineral = null;
foreach ($daily['final_stocks'] as $st) {
    if ($st['product_id'] == 89) $finalNightMineral = $st;
}
assertTest($finalNightMineral !== null && (int)$finalNightMineral['closing_qty'] === 146, "  Stok Akhir Malam Mineral Terkunci = 146 galon");

echo "\n=== SEMUA 16 UJI OTOMASI MULTI-SHIFT & ISOLASI PELAPORAN BERHASIL (100% PASS) ===\n";
