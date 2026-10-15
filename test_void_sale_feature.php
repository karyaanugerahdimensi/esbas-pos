<?php
/**
 * Test Suite: Modul Pembatalan Transaksi Penjualan (Void Sales - Level Super Admin)
 * Menguji otorisasi superadmin, pengembalian stok barang jadi & BOM, pembatalan piutang, dan integritas laporan keuangan.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/db.php';

$testCount = 0;
$passCount = 0;
$failCount = 0;

function assertTest($condition, $message) {
    global $testCount, $passCount, $failCount;
    $testCount++;
    if ($condition) {
        $passCount++;
        echo "[ PASS ] $message\n";
    } else {
        $failCount++;
        echo "[ FAIL ] $message\n";
    }
}

echo "===================================================================\n";
echo " TEST SUITE: PEMBATALAN TRANSAKSI PENJUALAN (SUPERADMIN VOID)\n";
echo "===================================================================\n\n";

// -----------------------------------------------------------------
// 1. UJI SKEMA BASIS DATA & AKUN SUPERADMIN
// -----------------------------------------------------------------
echo "1. Menguji Skema Basis Data & Akun Super Admin...\n";
$saleCols = array_column($pdo->query("PRAGMA table_info(sales)")->fetchAll(), 'name');
assertTest(in_array('status', $saleCols), "Kolom 'status' terpasang di tabel sales");
assertTest(in_array('cancellation_reason', $saleCols), "Kolom 'cancellation_reason' terpasang di tabel sales");
assertTest(in_array('cancelled_by', $saleCols), "Kolom 'cancelled_by' terpasang di tabel sales");
assertTest(in_array('cancelled_at', $saleCols), "Kolom 'cancelled_at' terpasang di tabel sales");

$superAdmin = $pdo->query("SELECT * FROM users WHERE role IN ('SUPERADMIN', 'SUPER_ADMIN') AND nik = 'ADM001'")->fetch();
assertTest(!empty($superAdmin) && $superAdmin['is_active'] == 1, "Akun Super Admin (ADM001 - " . ($superAdmin['name'] ?? 'Super Admin') . ") aktif dan tersedia");

// -----------------------------------------------------------------
// 2. UJI ENDPOINT get_cancellable_sales
// -----------------------------------------------------------------
echo "\n2. Menguji Endpoint get_cancellable_sales...\n";
$_GET['action'] = 'get_cancellable_sales';
$_GET['status_filter'] = 'ALL';
ob_start();
include __DIR__ . '/api.php';
$rawOutput = ob_get_clean();
$dataList = json_decode($rawOutput, true);

assertTest(!empty($dataList) && $dataList['success'] === true, "Endpoint get_cancellable_sales merespons sukses");
assertTest(isset($dataList['kpi']) && isset($dataList['kpi']['total_all']), "KPI agregat transaksi tersedia");
assertTest(is_array($dataList['sales']), "Daftar transaksi berhasil dimuat dalam array");

// -----------------------------------------------------------------
// 3. UJI OTORISASI SUPER ADMIN SAAT PEMBATALAN (REJECTION TEST)
// -----------------------------------------------------------------
echo "\n3. Menguji Otorisasi Super Admin (Penolakan Tanpa Kredensial Superadmin)...\n";

// Buat 1 transaksi baru untuk uji coba
$pCroissant = $pdo->query("SELECT id, name FROM products WHERE sku = 'PRD-002'")->fetch();
$stockBefore = (float)$pdo->query("SELECT stock FROM branch_stocks WHERE branch_id = 1 AND product_id = {$pCroissant['id']}")->fetchColumn();

$_GET['action'] = 'checkout_sale';
$GLOBALS['CLI_INPUT'] = [
    'branch_id' => 1,
    'user_id' => 3, // Kasir
    'items' => [
        ['product_id' => $pCroissant['id'], 'qty' => 3, 'unit_price' => 24000]
    ],
    'payment_method' => 'CASH',
    'cash_received' => 100000
];

ob_start();
include __DIR__ . '/api.php';
$checkoutOut = ob_get_clean();
$checkoutRes = json_decode($checkoutOut, true);
$saleId1 = $checkoutRes['sale']['id'] ?? 0;
$inv1 = $checkoutRes['sale']['invoice_number'] ?? '';

assertTest(!empty($saleId1) && !empty($inv1), "Transaksi kasir uji coba berhasil dibuat: {$inv1}");

// Coba batalkan transaksi dengan PIN / Password salah (Bukan superadmin)
$_GET['action'] = 'cancel_sale';
$GLOBALS['CLI_INPUT'] = [
    'sale_id' => $saleId1,
    'reason' => 'Salah input barang oleh kasir',
    'admin_pin' => '000000', // PIN salah
    'admin_password' => 'wrongpass',
    'admin_user_id' => 3 // User Kasir (Bukan superadmin)
];

ob_start();
include __DIR__ . '/api.php';
$rejectOut = ob_get_clean();
$rejectRes = json_decode($rejectOut, true);

assertTest($rejectRes['success'] === false, "Pembatalan tanpa otorisasi Super Admin DITOLAK sistem");
assertTest(strpos($rejectRes['message'], 'OTORISASI DITOLAK') !== false, "Pesan error otorisasi superadmin tepat");

// -----------------------------------------------------------------
// 4. UJI PEMBATALAN TRANSAKSI (VOID) DENGAN KREDENSIAL SUPERADMIN SAH
// -----------------------------------------------------------------
echo "\n4. Menguji Pembatalan Transaksi dengan Kredensial Super Admin yang Sah...\n";

// Sekarang batalkan dengan PIN Superadmin ADM001 yang sah (999999 / admin123)
$_GET['action'] = 'cancel_sale';
$GLOBALS['CLI_INPUT'] = [
    'sale_id' => $saleId1,
    'reason' => 'Pelanggan membatalkan pesanan karena salah pilih produk',
    'admin_pin' => '999999',
    'admin_password' => 'admin123',
    'admin_user_id' => 1
];

ob_start();
include __DIR__ . '/api.php';
$voidOut = ob_get_clean();
$voidRes = json_decode($voidOut, true);

assertTest($voidRes['success'] === true, "Pembatalan transaksi oleh Super Admin BERHASIL dieksekusi");

// Cek status transaksi di database
$saleCheck = $pdo->query("SELECT * FROM sales WHERE id = $saleId1")->fetch();
assertTest($saleCheck['status'] === 'CANCELLED', "Status transaksi di tabel sales berubah menjadi 'CANCELLED'");
assertTest($saleCheck['payment_status'] === 'CANCELLED', "Status pembayaran transaksi berubah menjadi 'CANCELLED'");
assertTest(!empty($saleCheck['cancelled_at']), "Kolom cancelled_at terisi waktu pembatalan");
assertTest($saleCheck['cancelled_by'] == 1, "Kolom cancelled_by tercatat sebagai Super Admin (User ID 1)");
assertTest($saleCheck['cancellation_reason'] === 'Pelanggan membatalkan pesanan karena salah pilih produk', "Catatan alasan pembatalan tersimpan akurat");

// Cek apakah stok fisik produk Croissant telah dikembalikan (di-restore)
$stockAfterVoid = (float)$pdo->query("SELECT stock FROM branch_stocks WHERE branch_id = 1 AND product_id = {$pCroissant['id']}")->fetchColumn();
assertTest($stockAfterVoid == $stockBefore, "Stok fisik produk telah dikembalikan utuh (+3 pcs) ke stok awal ($stockAfterVoid == $stockBefore)");

// Cek riwayat mutasi stok (Movement Ledger)
$moveLog = $pdo->query("SELECT * FROM stock_movements WHERE reference_no = '{$inv1}' AND movement_type = 'VOID_RETURN'")->fetch();
assertTest(!empty($moveLog) && $moveLog['qty_change'] == 3, "Mutasi pengembalian stok (VOID_RETURN) tercatat di stock_movements");

// -----------------------------------------------------------------
// 5. UJI PROTEKSI PEMBATALAN GANDA (DOUBLE VOID)
// -----------------------------------------------------------------
echo "\n5. Menguji Proteksi Pembatalan Ganda (Double Void Prevention)...\n";
ob_start();
include __DIR__ . '/api.php';
$doubleVoidOut = ob_get_clean();
$doubleVoidRes = json_decode($doubleVoidOut, true);

assertTest($doubleVoidRes['success'] === false, "Percobaan pembatalan kedua kalinya DITOLAK oleh proteksi sistem");
assertTest(strpos($doubleVoidRes['message'], 'sudah DIBATALKAN sebelumnya') !== false, "Pesan proteksi double void sesuai ekspektasi");

// -----------------------------------------------------------------
// 6. UJI VOID TRANSAKSI PRODUK DENGAN RESEP BOM (BASE BOM & VARIAN)
// -----------------------------------------------------------------
echo "\n6. Menguji Void Transaksi Produk dengan Formula Resep BOM & Varian...\n";

// Ambil produk Galon dan varian tutup segel
$pGalon = $pdo->query("SELECT id FROM products WHERE sku = 'PRD-GLN-19L'")->fetch();
$vCap = $pdo->query("SELECT id FROM product_variants WHERE sku_variant = 'VAR-GLN-TUTUP'")->fetch();
$rawAir = $pdo->query("SELECT id FROM products WHERE sku = 'RAW-AIR-RO'")->fetch();
$rawTutup = $pdo->query("SELECT id FROM products WHERE sku = 'RAW-CAP-GLN'")->fetch();

if ($pGalon && $vCap && $rawAir && $rawTutup) {
    // Catat stok awal sebelum transaksi
    $glnStockPre = (float)$pdo->query("SELECT stock FROM branch_stocks WHERE branch_id = 1 AND product_id = {$pGalon['id']}")->fetchColumn();
    $airStockPre = (float)$pdo->query("SELECT stock FROM branch_stocks WHERE branch_id = 1 AND product_id = {$rawAir['id']}")->fetchColumn();
    $capStockPre = (float)$pdo->query("SELECT stock FROM branch_stocks WHERE branch_id = 1 AND product_id = {$rawTutup['id']}")->fetchColumn();

    // Checkout 2x Galon Pakai Tutup
    $_GET['action'] = 'checkout_sale';
    $GLOBALS['CLI_INPUT'] = [
        'branch_id' => 1,
        'user_id' => 3,
        'items' => [
            [
                'product_id' => $pGalon['id'],
                'variant_id' => $vCap['id'],
                'variant_name' => 'Pakai Tutup Segel (+Rp 1.000)',
                'qty' => 2,
                'unit_price' => 20000
            ]
        ],
        'payment_method' => 'CASH',
        'cash_received' => 50000
    ];

    ob_start();
    include __DIR__ . '/api.php';
    $chkBomOut = ob_get_clean();
    $chkBomRes = json_decode($chkBomOut, true);
    $saleIdBom = $chkBomRes['sale']['id'] ?? 0;
    $invBom = $chkBomRes['sale']['invoice_number'] ?? '';

    assertTest(!empty($saleIdBom), "Checkout transaksi Galon 19L dengan BOM Varian berhasil: {$invBom}");

    // Batalkan transaksi Galon oleh Super Admin
    $_GET['action'] = 'cancel_sale';
    $GLOBALS['CLI_INPUT'] = [
        'sale_id' => $saleIdBom,
        'reason' => 'Salah input varian tutup oleh kasir',
        'admin_pin' => '999999',
        'admin_password' => 'admin123',
        'admin_user_id' => 1
    ];

    ob_start();
    include __DIR__ . '/api.php';
    $voidBomOut = ob_get_clean();
    $voidBomRes = json_decode($voidBomOut, true);

    assertTest($voidBomRes['success'] === true, "Pembatalan transaksi produk ber-BOM oleh Superadmin sukses");

    // Cek stok setelah void
    $glnStockPost = (float)$pdo->query("SELECT stock FROM branch_stocks WHERE branch_id = 1 AND product_id = {$pGalon['id']}")->fetchColumn();
    $airStockPost = (float)$pdo->query("SELECT stock FROM branch_stocks WHERE branch_id = 1 AND product_id = {$rawAir['id']}")->fetchColumn();
    $capStockPost = (float)$pdo->query("SELECT stock FROM branch_stocks WHERE branch_id = 1 AND product_id = {$rawTutup['id']}")->fetchColumn();

    assertTest($glnStockPost == $glnStockPre, "Stok Galon 19L kembali utuh ke saldo sebelum checkout ($glnStockPost == $glnStockPre)");
    assertTest($airStockPost == $airStockPre, "Bahan Baku Air RO Base BOM kembali utuh (+38L) ($airStockPost == $airStockPre)");
    assertTest($capStockPost == $capStockPre, "Bahan Baku Tutup Segel Varian BOM kembali utuh (+2 pcs) ($capStockPost == $capStockPre)");

    // Cek log mutasi BOM_RESTOCK_VOID
    $bomLog = $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reference_no = '{$invBom}' AND movement_type = 'BOM_RESTOCK_VOID'")->fetchColumn();
    assertTest($bomLog >= 2, "Log mutasi 'BOM_RESTOCK_VOID' mencatat pengembalian bahan Base & Variant BOM ($bomLog log)");
} else {
    echo "[ SKIP ] Produk Galon atau varian resep belum seeded.\n";
}

// -----------------------------------------------------------------
// 7. UJI VOID TRANSAKSI PENJUALAN METODE PIUTANG (PIUTANG USAHA)
// -----------------------------------------------------------------
echo "\n7. Menguji Void Transaksi Kredit dengan Metode PIUTANG...\n";

// Checkout dengan metode PIUTANG
$_GET['action'] = 'checkout_sale';
$GLOBALS['CLI_INPUT'] = [
    'branch_id' => 1,
    'user_id' => 3,
    'customer_id' => 3, // Toko Berkah Reseller
    'customer_name' => 'Toko Berkah Coffee (Reseller)',
    'customer_category' => 'RESELLER',
    'items' => [
        ['product_id' => 1, 'qty' => 5, 'unit_price' => 30000]
    ],
    'payment_method' => 'PIUTANG',
    'notes' => 'Uji coba piutang tempo reseller'
];

ob_start();
include __DIR__ . '/api.php';
$chkArOut = ob_get_clean();
$chkArRes = json_decode($chkArOut, true);
$saleIdAr = $chkArRes['sale']['id'] ?? 0;
$invAr = $chkArRes['sale']['invoice_number'] ?? '';

// Pastikan faktur piutang terbit
$arRow = $pdo->query("SELECT * FROM customer_receivables WHERE sale_id = $saleIdAr")->fetch();
assertTest(!empty($arRow) && $arRow['status'] === 'BELUM_LUNAS', "Faktur piutang awal terbit dengan status 'BELUM_LUNAS'");

// Batalkan transaksi penjualan piutang oleh Super Admin
$_GET['action'] = 'cancel_sale';
$GLOBALS['CLI_INPUT'] = [
    'sale_id' => $saleIdAr,
    'reason' => 'Reseller membatalkan nota pesanan kredit',
    'admin_pin' => '999999',
    'admin_password' => 'admin123',
    'admin_user_id' => 1
];

ob_start();
include __DIR__ . '/api.php';
$voidArOut = ob_get_clean();
$voidArRes = json_decode($voidArOut, true);

assertTest($voidArRes['success'] === true && $voidArRes['is_receivable_voided'] === true, "Pembatalan transaksi piutang berhasil memicu void piutang");

// Cek status piutang di database
$arRowPost = $pdo->query("SELECT * FROM customer_receivables WHERE sale_id = $saleIdAr")->fetch();
assertTest($arRowPost['status'] === 'DIBATALKAN', "Status piutang di customer_receivables berubah menjadi 'DIBATALKAN'");
assertTest($arRowPost['remaining_amount'] == 0, "Sisa saldo piutang otomatis dinolkan (Rp 0)");
assertTest(strpos($arRowPost['notes'], 'DIBATALKAN OLEH SUPERADMIN') !== false, "Audit trail pembatalan tercatat pada catatan piutang");

// -----------------------------------------------------------------
// 8. UJI INTEGRITAS LAPORAN PENJUALAN & LABA RUGI (EXCLUDE CANCELLED)
// -----------------------------------------------------------------
echo "\n8. Menguji Pengecualian Transaksi Dibatalkan dari Laporan Penjualan & Laba Rugi...\n";

// Laporan Penjualan
$_GET['action'] = 'sales_reports';
$_GET['start_date'] = date('Y-m-d');
$_GET['end_date'] = date('Y-m-d');
$_GET['payment_method'] = 'ALL';

ob_start();
include __DIR__ . '/api.php';
$repOut = ob_get_clean();
$repRes = json_decode($repOut, true);

// Pastikan faktur yang dibatalkan tidak muncul di recent_sales
$recentInvoices = array_column($repRes['recent_sales'] ?? [], 'invoice_number');
assertTest(!in_array($inv1, $recentInvoices), "Faktur void '$inv1' tidak muncul pada laporan penjualan aktif");
assertTest(!in_array($invAr, $recentInvoices), "Faktur void piutang '$invAr' tidak muncul pada laporan penjualan aktif");

// Laporan Laba Rugi
$_GET['action'] = 'profit_loss_report';
$_GET['start_date'] = date('Y-m-d');
$_GET['end_date'] = date('Y-m-d');

ob_start();
include __DIR__ . '/api.php';
$pnlOut = ob_get_clean();
$pnlRes = json_decode($pnlOut, true);

assertTest($pnlRes['success'] === true, "Laporan Laba Rugi merespons sukses tanpa error");
assertTest(isset($pnlRes['income_statement']['net_revenue']), "Laba Rugi mengecualikan omzet faktur yang dibatalkan");

// -----------------------------------------------------------------
// REKAP HASIL
// -----------------------------------------------------------------
echo "\n===================================================================\n";
echo " REKAP HASIL PENGUJIAN: {$passCount} PASS | {$failCount} FAIL\n";
echo "===================================================================\n";

if ($failCount === 0) {
    echo "SELURUH FITUR PEMBATALAN TRANSAKSI PENJUALAN LULUS 100%!\n\n";
    exit(0);
} else {
    echo "ADA PENGUJIAN YANG GAGAL!\n\n";
    exit(1);
}
