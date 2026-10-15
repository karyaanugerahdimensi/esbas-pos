<?php
/**
 * Comprehensive Automated Test Suite:
 * Modul Piutang Usaha (Accounts Receivable), Pelunasan/Cicilan, dan Integrasi Laba Rugi
 */

require_once __DIR__ . '/db.php';

echo "===================================================================\n";
echo " TEST SUITE: MODUL PIUTANG USAHA (AR) & LAPORAN LABA RUGI\n";
echo "===================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $testName) {
    global $passCount, $failCount;
    if ($condition) {
        echo "[ PASS ] $testName\n";
        $passCount++;
    } else {
        echo "[ FAIL ] $testName\n";
        $failCount++;
    }
}

// -------------------------------------------------------------
// TEST 1: Verifikasi Skema Tabel & Kolom
// -------------------------------------------------------------
echo "1. Menguji Skema Basis Data...\n";
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
assertTest(in_array('customer_receivables', $tables), "Tabel 'customer_receivables' terpasang");
assertTest(in_array('receivable_payments', $tables), "Tabel 'receivable_payments' terpasang");

$saleCols = array_column($pdo->query("PRAGMA table_info(sales)")->fetchAll(), 'name');
assertTest(in_array('payment_status', $saleCols), "Kolom 'payment_status' ada di tabel sales");

$pmPiutang = $pdo->query("SELECT * FROM payment_methods WHERE code = 'PIUTANG'")->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($pmPiutang), "Metode Pembayaran 'PIUTANG' terdaftar di tabel payment_methods");

// -------------------------------------------------------------
// TEST 2: Checkout POS dengan Metode PIUTANG (Penjualan Kredit)
// -------------------------------------------------------------
echo "\n2. Menguji Checkout POS dengan Metode PIUTANG...\n";
$product = $pdo->query("SELECT id, name, selling_price, cogs FROM products WHERE sku = 'PRD-001'")->fetch(PDO::FETCH_ASSOC);
$customer = $pdo->query("SELECT id, name, category FROM customers WHERE code = 'CUST-004'")->fetch(PDO::FETCH_ASSOC); // PT Mitra Bahtera Abadi

// Cek stok awal
$initStock = (float)$pdo->query("SELECT stock FROM branch_stocks WHERE branch_id = 1 AND product_id = {$product['id']}")->fetchColumn();

// Simulasikan POST checkout_sale
$_GET = ['action' => 'checkout_sale'];
$checkoutPayload = [
    'branch_id' => 1,
    'user_id' => 3,
    'shift_id' => null,
    'customer_id' => $customer['id'],
    'customer_name' => $customer['name'],
    'customer_category' => $customer['category'],
    'sales_type' => 'B2B_PARTNER',
    'items' => [
        [
            'product_id' => $product['id'],
            'qty' => 5
        ]
    ],
    'payment_method' => 'PIUTANG',
    'cash_received' => 0,
    'discount' => 0,
    'delivery_fee' => 0,
    'due_date' => date('Y-m-d', strtotime('+30 days')),
    'notes' => 'Pesanan kredit mitra bulanan via test suite'
];

ob_start();
$_POST = $checkoutPayload;
$GLOBALS['CLI_INPUT'] = $checkoutPayload;
include __DIR__ . '/api.php';
$checkoutResp = ob_get_clean();

$checkoutData = json_decode($checkoutResp, true);
assertTest(!empty($checkoutData['success']), "Checkout dengan metode PIUTANG berhasil");
assertTest(isset($checkoutData['sale']['payment_status']) && $checkoutData['sale']['payment_status'] === 'UNPAID', "Sales payment_status diset UNPAID");
assertTest(!empty($checkoutData['sale']['receivable_id']), "ID Piutang terbentuk di response checkout: " . ($checkoutData['sale']['receivable_id'] ?? '-'));

$newReceivableId = $checkoutData['sale']['receivable_id'];
$createdInv = $checkoutData['sale']['invoice_number'];

// Verifikasi record di customer_receivables
$recRow = $pdo->query("SELECT * FROM customer_receivables WHERE id = $newReceivableId")->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($recRow), "Data faktur piutang tersimpan di customer_receivables");
assertTest($recRow['status'] === 'BELUM_LUNAS', "Status awal piutang adalah 'BELUM_LUNAS'");
assertTest((float)$recRow['remaining_amount'] == (float)$recRow['total_amount'], "Sisa piutang sama dengan total nilai faktur");

// Verifikasi pengurangan stok
$afterStock = (float)$pdo->query("SELECT stock FROM branch_stocks WHERE branch_id = 1 AND product_id = {$product['id']}")->fetchColumn();
assertTest(($initStock - $afterStock) == 5, "Stok produk terpotong sebanyak 5 pcs (Awal: $initStock, Sisa: $afterStock)");

// -------------------------------------------------------------
// TEST 3: Endpoint get_receivables & KPI Piutang
// -------------------------------------------------------------
echo "\n3. Menguji Endpoint get_receivables & KPI...\n";
$_GET = [
    'action' => 'get_receivables',
    'branch_id' => 1,
    'status' => 'ALL'
];
ob_start();
include __DIR__ . '/api.php';
$recListResp = ob_get_clean();
$recListData = json_decode($recListResp, true);

assertTest(!empty($recListData['success']), "Endpoint get_receivables sukses");
assertTest(!empty($recListData['kpi']), "KPI piutang tersedia");
assertTest((float)$recListData['kpi']['total_outstanding'] > 0, "Total piutang outstanding terhitung > 0: Rp " . number_format($recListData['kpi']['total_outstanding'], 0, ',', '.'));
assertTest((int)$recListData['kpi']['count_unpaid'] >= 1, "Jumlah faktur belum lunas terhitung >= 1");

$foundOurInv = false;
foreach ($recListData['receivables'] as $item) {
    if ($item['invoice_number'] === $createdInv) {
        $foundOurInv = true;
        break;
    }
}
assertTest($foundOurInv, "Faktur baru '$createdInv' muncul di daftar piutang");

// -------------------------------------------------------------
// TEST 4: Endpoint get_receivable_detail
// -------------------------------------------------------------
echo "\n4. Menguji Endpoint get_receivable_detail...\n";
$_GET = [
    'action' => 'get_receivable_detail',
    'id' => $newReceivableId
];
ob_start();
include __DIR__ . '/api.php';
$recDetailResp = ob_get_clean();
$recDetailData = json_decode($recDetailResp, true);

assertTest(!empty($recDetailData['success']), "Endpoint get_receivable_detail sukses");
assertTest(count($recDetailData['items']) >= 1, "Rincian item belanja faktur ditemukan: " . count($recDetailData['items']) . " item");
assertTest(isset($recDetailData['payments']), "Daftar riwayat pembayaran cicilan ditemukan");

// -------------------------------------------------------------
// TEST 5: Endpoint pay_receivable (Cicilan Bertahap & Pelunasan)
// -------------------------------------------------------------
echo "\n5. Menguji Endpoint pay_receivable (Cicilan & Pelunasan)...\n";
$totalTagihan = (float)$recRow['total_amount'];
$cicilan1 = round($totalTagihan * 0.4); // 40% pembayaran pertama

// Step 5A: Pembayaran Cicilan Pertama
$_GET = ['action' => 'pay_receivable'];
$payPayload1 = [
    'receivable_id' => $newReceivableId,
    'amount' => $cicilan1,
    'payment_method' => 'TRANSFER',
    'reference_no' => 'TRF-TEST-001',
    'notes' => 'Pembayaran cicilan termin 1 via transfer'
];
$_POST = $payPayload1;
$GLOBALS['CLI_INPUT'] = $payPayload1;
ob_start();
include __DIR__ . '/api.php';
$pay1Resp = ob_get_clean();
$pay1Data = json_decode($pay1Resp, true);

assertTest(!empty($pay1Data['success']), "Pembayaran cicilan 1 sukses dicatat");
assertTest($pay1Data['status'] === 'SEBAGIAN', "Status piutang berubah menjadi 'SEBAGIAN'");

// Cek log di receivable_payments
$payLogCount = $pdo->query("SELECT COUNT(*) FROM receivable_payments WHERE receivable_id = $newReceivableId")->fetchColumn();
assertTest($payLogCount == 1, "Riwayat pembayaran tercatat di tabel receivable_payments");

// Step 5B: Pembayaran Pelunasan Sisa
$sisaTagihan = (float)$pay1Data['new_remaining'];
$payPayload2 = [
    'receivable_id' => $newReceivableId,
    'amount' => $sisaTagihan,
    'payment_method' => 'CASH',
    'reference_no' => 'TUNAI-LUNAS-002',
    'notes' => 'Pelunasan penuh sisa tagihan faktur'
];
$_POST = $payPayload2;
$GLOBALS['CLI_INPUT'] = $payPayload2;
ob_start();
include __DIR__ . '/api.php';
$pay2Resp = ob_get_clean();
$pay2Data = json_decode($pay2Resp, true);

assertTest(!empty($pay2Data['success']), "Pelunasan sisa tagihan sukses dicatat");
assertTest($pay2Data['status'] === 'LUNAS', "Status piutang berubah menjadi 'LUNAS'");
assertTest((float)$pay2Data['new_remaining'] == 0, "Sisa tagihan menjadi Rp 0");

// Cek apakah payment_status di tabel sales berubah jadi PAID
$salePaymentStatus = $pdo->query("SELECT payment_status FROM sales WHERE id = {$recRow['sale_id']}")->fetchColumn();
assertTest($salePaymentStatus === 'PAID', "Status pembayaran faktur di tabel sales otomatis terupdate menjadi 'PAID'");

// -------------------------------------------------------------
// TEST 6: Endpoint profit_loss_report dengan Integrasi Piutang
// -------------------------------------------------------------
echo "\n6. Menguji Endpoint profit_loss_report (P&L Impact)...\n";
$_GET = [
    'action' => 'profit_loss_report',
    'branch_id' => 1,
    'start_date' => date('Y-m-01'),
    'end_date' => date('Y-m-d')
];
ob_start();
include __DIR__ . '/api.php';
$pnlResp = ob_get_clean();
$pnlData = json_decode($pnlResp, true);

assertTest(!empty($pnlData['success']), "Endpoint profit_loss_report sukses");
$pnl = $pnlData['income_statement'];
assertTest(isset($pnl['cash_sales']) && isset($pnl['receivable_sales']), "P&L memiliki breakdown Penjualan Kas vs Piutang");
assertTest(isset($pnl['receivables_analysis']), "P&L memiliki objek receivables_analysis");

$ar = $pnl['receivables_analysis'];
assertTest(isset($ar['period_receivables_billed']), "Metrik period_receivables_billed tersedia");
assertTest(isset($ar['period_receivables_collected']), "Metrik period_receivables_collected tersedia");
assertTest(isset($ar['cash_realized_sales']), "Metrik cash_realized_sales tersedia");
assertTest(isset($ar['cash_realized_net_profit']), "Metrik cash_realized_net_profit tersedia");
echo "   - Penjualan Kas Langsung: Rp " . number_format($pnl['cash_sales'], 0, ',', '.') . "\n";
echo "   - Penjualan Piutang: Rp " . number_format($pnl['receivable_sales'], 0, ',', '.') . "\n";
echo "   - Kas Masuk Piutang Tertagih: Rp " . number_format($ar['period_receivables_collected'], 0, ',', '.') . "\n";
echo "   - Realisasi Kas Masuk Penjualan: Rp " . number_format($ar['cash_realized_sales'], 0, ',', '.') . "\n";

// -------------------------------------------------------------
// TEST 7: Syntax Check index.php & db.php & api.php
// -------------------------------------------------------------
echo "\n7. Menguji Integritas Sintaks PHP...\n";
exec('php -l db.php', $outDb, $retDb);
assertTest($retDb === 0, "db.php syntax valid (No syntax errors)");

exec('php -l api.php', $outApi, $retApi);
assertTest($retApi === 0, "api.php syntax valid (No syntax errors)");

exec('php -l index.php', $outIdx, $retIdx);
assertTest($retIdx === 0, "index.php syntax valid (No syntax errors)");

// -------------------------------------------------------------
// REKAP HASIL TES
// -------------------------------------------------------------
echo "\n===================================================================\n";
echo " REKAP HASIL PENGUJIAN: $passCount PASS | $failCount FAIL\n";
echo "===================================================================\n";

if ($failCount === 0) {
    echo "SELURUH FITUR PIUTANG USAHA & LABA RUGI LULUS PENGUJIAN 100%!\n";
} else {
    echo "TERDAPAT $failCount KEGAGALAN DALAM PENGUJIAN.\n";
    exit(1);
}
