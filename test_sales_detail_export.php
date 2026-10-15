<?php
/**
 * Test Suite: Detail Transaksi Penjualan per Invoice & Export Excel
 * Menggunakan curl / HTTP stream context ke server lokal http://localhost:8000
 */

$baseUrl = 'http://localhost:8000';

echo "=== TEST DETAIL TRANSAKSI PENJUALAN & EXPORT EXCEL ===\n\n";

// 1. Ambil data transaksi dari database
require_once __DIR__ . '/db.php';
$sampleSale = $pdo->query("SELECT invoice_number FROM sales ORDER BY id DESC LIMIT 1")->fetch();

if (!$sampleSale) {
    die("Error: Tidak ada data transaksi di tabel sales.\n");
}

$invoiceNumber = $sampleSale['invoice_number'];
echo "1. Nomor Invoice Uji: $invoiceNumber\n";

// 2. Test Endpoint get_sale_detail
echo "\n2. Menguji GET api.php?action=get_sale_detail&invoice_number=...\n";
$detailUrl = "$baseUrl/api.php?action=get_sale_detail&invoice_number=" . urlencode($invoiceNumber);
$detailResponse = @file_get_contents($detailUrl);

if (!$detailResponse) {
    die("✗ [FAIL] Tidak dapat menghubungi server di $detailUrl\n");
}

$detailJson = json_decode($detailResponse, true);

if ($detailJson && !empty($detailJson['success'])) {
    echo "✓ [PASS] Endpoint get_sale_detail berhasil mengembalikan data:\n";
    echo "   - Invoice: " . $detailJson['sale']['invoice_number'] . "\n";
    echo "   - Cabang: " . $detailJson['sale']['branch_name'] . "\n";
    echo "   - Kasir: " . $detailJson['sale']['cashier_name'] . " (" . $detailJson['sale']['cashier_nik'] . ")\n";
    echo "   - Kurir / Driver: " . $detailJson['sale']['effective_driver_name'] . "\n";
    echo "   - Grand Total: Rp " . number_format($detailJson['sale']['grand_total']) . "\n";
    echo "   - Jumlah Item: " . count($detailJson['items']) . " item\n";
    foreach ($detailJson['items'] as $idx => $it) {
        $var = !empty($it['variant_name']) ? " (" . $it['variant_name'] . ")" : "";
        echo "     [" . ($idx + 1) . "] " . $it['product_name'] . $var . " x " . $it['qty'] . " " . $it['unit'] . " @ Rp " . number_format($it['unit_price']) . " = Rp " . number_format($it['subtotal_price']) . "\n";
    }
} else {
    echo "✗ [FAIL] Endpoint get_sale_detail gagal: $detailResponse\n";
    exit(1);
}

echo "\n--------------------------------------------------\n";

// 3. Test Endpoint export_sales_excel (Filter Range)
echo "3. Menguji GET api.php?action=export_sales_excel (Semua Transaksi / Periode)...\n";
$exportUrl = "$baseUrl/api.php?action=export_sales_excel&start_date=2020-01-01&end_date=" . date('Y-m-d') . "&payment_method=ALL";
$excelOutput = @file_get_contents($exportUrl);

if ($excelOutput && strpos($excelOutput, '<table') !== false && strpos($excelOutput, 'LAPORAN RINCIAN DETAIL PENJUALAN') !== false && strpos($excelOutput, $invoiceNumber) !== false) {
    echo "✓ [PASS] export_sales_excel berhasil memproduksi HTML/Excel Table!\n";
    echo "   - Header tabel ditemukan\n";
    echo "   - Data invoice ($invoiceNumber) ditemukan di baris spreadsheet\n";
    echo "   - Ukuran payload output: " . strlen($excelOutput) . " bytes\n";
} else {
    echo "✗ [FAIL] Format output Excel periode tidak sesuai ekspektasi.\n";
    exit(1);
}

echo "\n--------------------------------------------------\n";

// 4. Test Endpoint export_sales_excel (Single Invoice)
echo "4. Menguji GET api.php?action=export_sales_excel (Per-Invoice Spesifik)...\n";
$singleExportUrl = "$baseUrl/api.php?action=export_sales_excel&invoice_number=" . urlencode($invoiceNumber);
$singleExcelOutput = @file_get_contents($singleExportUrl);

if ($singleExcelOutput && strpos($singleExcelOutput, '<table') !== false && strpos($singleExcelOutput, $invoiceNumber) !== false) {
    echo "✓ [PASS] export_sales_excel per-invoice berhasil!\n";
    echo "   - Berisi transaksi invoice spesifik $invoiceNumber\n";
    echo "   - Ukuran output: " . strlen($singleExcelOutput) . " bytes\n";
} else {
    echo "✗ [FAIL] Format output Excel per-invoice gagal.\n";
    exit(1);
}

echo "\n=== SEMUA PENGUJIAN DETAIL PENJUALAN & EXPORT EXCEL BERHASIL (100% PASS) ===\n";
