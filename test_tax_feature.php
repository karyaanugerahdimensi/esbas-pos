<?php
/**
 * Test Suite: Tax Feature & CRUD Verification
 * 1. Test get_taxes API endpoint
 * 2. Test save_tax (Create new tax rate)
 * 3. Test save_tax (Update tax rate)
 * 4. Test delete_tax (Soft/hard delete tax rate)
 * 5. Test Product Tax Settings (Set product is_taxable = 1 vs is_taxable = 0)
 * 6. Test POS Checkout with Taxable Product (Tax must be calculated)
 * 7. Test POS Checkout with Non-Taxable / Tax Exempt Product (Tax must be 0)
 * 8. Test POS Checkout with Mixed Products (Tax calculated only for taxable items)
 */

require_once __DIR__ . '/db.php';

function assertTest($condition, $testName) {
    if ($condition) {
        echo "✅ PASS: {$testName}\n";
    } else {
        echo "❌ FAIL: {$testName}\n";
        exit(1);
    }
}

echo "=== START TESTING TAX FEATURE & PER-ITEM TAX LOGIC ===\n\n";

// Helper curl/local api call
function callApi($action, $method = 'GET', $data = []) {
    $url = "http://localhost:8000/api.php?action=" . $action;
    $ch = curl_init();
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    } else {
        $queryString = http_build_query($data);
        curl_setopt($ch, CURLOPT_URL, $queryString ? "{$url}&{$queryString}" : $url);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return json_decode($response, true);
}

// 1. Test get_taxes
$resTaxes = callApi('get_taxes');
assertTest($resTaxes['success'] === true && count($resTaxes['taxes']) >= 3, "Endpoint get_taxes mengembalikan data master pajak default (PPN11, PB1-10, TAX-0)");

// 2. Test save_tax (Create new tax)
$newTaxCode = 'TEST-TAX-5';
$resCreateTax = callApi('save_tax', 'POST', [
    'code' => $newTaxCode,
    'name' => 'Pajak Uji Coba 5%',
    'rate_percent' => 5.0,
    'description' => 'Pajak pengujian sistem 5%',
    'is_active' => 1
]);
assertTest($resCreateTax['success'] === true && !empty($resCreateTax['tax_id']), "Endpoint save_tax berhasil menambahkan tarif pajak baru (5%)");
$testTaxId = $resCreateTax['tax_id'];

// 3. Test save_tax (Update tax)
$resUpdateTax = callApi('save_tax', 'POST', [
    'id' => $testTaxId,
    'code' => $newTaxCode,
    'name' => 'Pajak Uji Coba Diubah 7.5%',
    'rate_percent' => 7.5,
    'description' => 'Update deskripsi pajak pengujian',
    'is_active' => 1
]);
assertTest($resUpdateTax['success'] === true, "Endpoint save_tax berhasil mengupdate tarif pajak menjadi 7.5%");

$checkTaxRow = $pdo->query("SELECT rate_percent FROM taxes WHERE id = $testTaxId")->fetchColumn();
assertTest((float)$checkTaxRow === 7.5, "Database verifikasi tarif pajak ter-update menjadi 7.5%");

// 4. Test delete_tax
$resDelTax = callApi('delete_tax', 'POST', ['id' => $testTaxId]);
assertTest($resDelTax['success'] === true, "Endpoint delete_tax berhasil menghapus pajak uji coba");

// Cek proteksi delete default tax id = 1
$resDelDefault = callApi('delete_tax', 'POST', ['id' => 1]);
assertTest($resDelDefault['success'] === false, "Endpoint delete_tax menolak penghapusan PPN 11% standar sistem");

// 5. Setup Dua Produk untuk Pengujian:
// Produk A: Kena Pajak (is_taxable = 1, tax_percent = 11.0, harga Rp 20.000)
// Produk B: Bebas Pajak (is_taxable = 0, tax_percent = 0, harga Rp 30.000)
$pdo->exec("INSERT OR IGNORE INTO products (sku, barcode, name, category_id, category, product_type, unit, cogs, selling_price, is_taxable, tax_id, tax_percent, is_active)
    VALUES ('PRD-TAX-YES', '8999901', 'Kopi Susu Taxable (Kena Pajak)', 1, 'Minuman (Beverage)', 'FINISHED_GOODS', 'cup', 5000, 20000, 1, 1, 11.0, 1)
");
$pdo->exec("INSERT OR IGNORE INTO products (sku, barcode, name, category_id, category, product_type, unit, cogs, selling_price, is_taxable, tax_id, tax_percent, is_active)
    VALUES ('PRD-TAX-NO', '8999902', 'Beras Bebas Pajak (Non-Taxable)', 3, 'Makanan Utama', 'FINISHED_GOODS', 'kg', 20000, 30000, 0, 3, 0.0, 1)
");

$pTaxYes = (int)$pdo->query("SELECT id FROM products WHERE sku = 'PRD-TAX-YES'")->fetchColumn();
$pTaxNo = (int)$pdo->query("SELECT id FROM products WHERE sku = 'PRD-TAX-NO'")->fetchColumn();

// Pastikan stok cabang 1 ada
$pdo->exec("INSERT OR REPLACE INTO branch_stocks (branch_id, product_id, stock, local_cogs, local_price) VALUES (1, $pTaxYes, 100, 5000, 20000)");
$pdo->exec("INSERT OR REPLACE INTO branch_stocks (branch_id, product_id, stock, local_cogs, local_price) VALUES (1, $pTaxNo, 100, 20000, 30000)");

// Pastikan shift kasir terbuka untuk user 3 di cabang 1
$pdo->exec("INSERT OR IGNORE INTO cashier_shifts (branch_id, user_id, start_time, initial_cash, status) VALUES (1, 3, datetime('now'), 100000, 'OPEN')");

// 6. Test POS Checkout Kasir: Transaksi HANYA Produk Kena Pajak
// Beli 2x Kopi Susu Taxable (@ Rp 20.000) = Subtotal Rp 40.000
// Pajak 11% = Rp 4.400
// Grand Total = Rp 44.400
$resSaleTaxOnly = callApi('checkout_sale', 'POST', [
    'branch_id' => 1,
    'user_id' => 3,
    'customer_id' => 1,
    'customer_name' => 'Pelanggan Walk-In',
    'customer_category' => 'REGULER',
    'items' => [
        ['product_id' => $pTaxYes, 'qty' => 2]
    ],
    'payment_method' => 'CASH',
    'cash_received' => 50000,
    'discount' => 0
]);
assertTest($resSaleTaxOnly['success'] === true, "Checkout produk kena pajak berhasil diproses");
assertTest((float)$resSaleTaxOnly['sale']['tax'] === 4400.0, "Pajak dihitung tepat 11% (Rp 4.400) pada produk taxable");
assertTest((float)$resSaleTaxOnly['sale']['grand_total'] === 44400.0, "Grand total sesuai (Subtotal Rp 40.000 + Tax Rp 4.400 = Rp 44.400)");

// 7. Test POS Checkout Kasir: Transaksi HANYA Produk Bebas Pajak (is_taxable = 0)
// Beli 1x Beras Non-Taxable (@ Rp 30.000) = Subtotal Rp 30.000
// Pajak HARUS Rp 0 (Tidak ada tax yang dikenakan!)
// Grand Total = Rp 30.000
$resSaleNoTax = callApi('checkout_sale', 'POST', [
    'branch_id' => 1,
    'user_id' => 3,
    'customer_id' => 1,
    'customer_name' => 'Pelanggan Bebas Pajak',
    'customer_category' => 'REGULER',
    'items' => [
        ['product_id' => $pTaxNo, 'qty' => 1]
    ],
    'payment_method' => 'CASH',
    'cash_received' => 50000,
    'discount' => 0
]);
assertTest($resSaleNoTax['success'] === true, "Checkout produk bebas pajak berhasil diproses");
assertTest((float)$resSaleNoTax['sale']['tax'] === 0.0, "Pajak = Rp 0 untuk produk yang tidak diaktifkan tax-nya (Bebas Pajak)");
assertTest((float)$resSaleNoTax['sale']['grand_total'] === 30000.0, "Grand total produk bebas pajak sama dengan subtotal (Rp 30.000)");

// 8. Test POS Checkout Kasir: Transaksi Campuran (Mixed Cart)
// 1x Kopi Taxable (Rp 20.000, tax 11% = Rp 2.200) + 1x Beras Non-Taxable (Rp 30.000, tax 0%)
// Subtotal = Rp 50.000
// Pajak = HANYA Rp 2.200 (Bukan 11% dari Rp 50.000!)
// Grand Total = Rp 52.200
$resSaleMixed = callApi('checkout_sale', 'POST', [
    'branch_id' => 1,
    'user_id' => 3,
    'customer_id' => 1,
    'customer_name' => 'Pelanggan Mixed',
    'customer_category' => 'REGULER',
    'items' => [
        ['product_id' => $pTaxYes, 'qty' => 1],
        ['product_id' => $pTaxNo, 'qty' => 1]
    ],
    'payment_method' => 'CASH',
    'cash_received' => 60000,
    'discount' => 0
]);
assertTest($resSaleMixed['success'] === true, "Checkout produk campuran (Taxable + Non-Taxable) berhasil");
assertTest((float)$resSaleMixed['sale']['tax'] === 2200.0, "Pajak campuran dihitung secara presisi per item (Rp 2.200 untuk item taxable, Rp 0 untuk item non-taxable)");
assertTest((float)$resSaleMixed['sale']['grand_total'] === 52200.0, "Grand total campuran tepat Rp 52.200");

// 9. Verifikasi API bootstrap menyertakan atribut pajak pada produk
$resBootstrap = callApi('bootstrap', 'GET', ['branch_id' => 1, 'user_id' => 3]);
assertTest($resBootstrap['success'] === true && !empty($resBootstrap['taxes']), "Bootstrap menyertakan daftar master taxes");
$prodTaxYesInBootstrap = array_values(array_filter($resBootstrap['products'], fn($p) => $p['id'] == $pTaxYes))[0] ?? null;
assertTest($prodTaxYesInBootstrap && $prodTaxYesInBootstrap['is_taxable'] == 1 && (float)$prodTaxYesInBootstrap['tax_percent'] == 11.0, "Produk dalam bootstrap memiliki atribut is_taxable = 1 dan tax_percent = 11.0");

echo "\n🎉 SEMUA PENGUJIAN FITUR TAX PER ITEM & CRUD TAXES BERHASIL 100%!\n";
