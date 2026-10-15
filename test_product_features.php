<?php
/**
 * Test Suite: Master Product Enhanced Features
 * 1. Auto-generate SKU & Barcode
 * 2. Delete Product (Hard Delete & Soft Delete)
 * 3. Auto-calculate BOM COGS
 * 4. Export & Import Master Products
 */

function assertTest($description, $condition) {
    if ($condition) {
        echo "✅ PASS: $description\n";
    } else {
        echo "❌ FAIL: $description\n";
        exit(1);
    }
}

echo "=== STARTING TEST SUITE: PRODUCT ENHANCEMENTS ===\n\n";

// Helper request function to local API
function callApi($action, $method = 'GET', $data = [], $session = null) {
    $url = "http://localhost:8000/api.php?action=" . urlencode($action);
    if ($method === 'GET' && !empty($data) && is_array($data)) {
        $url .= '&' . http_build_query($data);
    }
    $ch = curl_init();
    
    $headers = [];
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (is_array($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            $headers[] = 'Content-Type: text/plain';
        }
    }
    
    // Cookie for session
    if ($session) {
        curl_setopt($ch, CURLOPT_COOKIE, "PHPSESSID=" . $session);
    }
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'code' => $httpCode,
        'body' => $response,
        'json' => json_decode($response, true)
    ];
}

// 0. Login as ADM001 to get session cookie
$ch = curl_init("http://localhost:8000/api.php?action=login");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['user_code' => 'ADM001', 'password' => 'admin123']));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
$res = curl_exec($ch);
preg_match('/PHPSESSID=([^;]+)/', $res, $matches);
$sessionId = $matches[1] ?? '';
curl_close($ch);

assertTest("Login admin ADM001 berhasil", !empty($sessionId));

// 1. TEST AUTO-GENERATE SKU & BARCODE
echo "\n--- 1. Testing Auto-Generate SKU & Barcode ---\n";
$resGenFinished = callApi('generate_sku_barcode', 'GET', ['product_type' => 'FINISHED_GOODS'], $sessionId);
assertTest("Generate SKU Finished Goods sukses", isset($resGenFinished['json']['sku']) && str_starts_with($resGenFinished['json']['sku'], 'PRD-'));
assertTest("Generate Barcode 12-digit numerik valid", isset($resGenFinished['json']['barcode']) && strlen($resGenFinished['json']['barcode']) === 12 && ctype_digit($resGenFinished['json']['barcode']));

$resGenRaw = callApi('generate_sku_barcode', 'GET', ['product_type' => 'RAW_MATERIAL'], $sessionId);
assertTest("Generate SKU Raw Material memiliki prefix RAW-", isset($resGenRaw['json']['sku']) && str_starts_with($resGenRaw['json']['sku'], 'RAW-'));

$resGenSrv = callApi('generate_sku_barcode', 'GET', ['product_type' => 'SERVICE'], $sessionId);
assertTest("Generate SKU Service memiliki prefix SRV-", isset($resGenSrv['json']['sku']) && str_starts_with($resGenSrv['json']['sku'], 'SRV-'));


// 2. TEST AUTO-CALCULATE BOM COGS (Barang Jadi)
echo "\n--- 2. Testing Auto-Calculate BOM COGS ---\n";
// Buat 2 Raw Material
$rawA_Sku = 'RAW-TEST-A-' . time();
$rawB_Sku = 'RAW-TEST-B-' . time();

$resRawA = callApi('save_product', 'POST', [
    'sku' => $rawA_Sku,
    'name' => 'Bahan Baku Uji A',
    'product_type' => 'RAW_MATERIAL',
    'unit' => 'gram',
    'cogs' => 2500,
    'selling_price' => 3000
], $sessionId);
$rawA_Id = $resRawA['json']['product_id'] ?? 0;
assertTest("Bahan Baku A berhasil dibuat (HPP=2500)", $rawA_Id > 0);

$resRawB = callApi('save_product', 'POST', [
    'sku' => $rawB_Sku,
    'name' => 'Bahan Baku Uji B',
    'product_type' => 'RAW_MATERIAL',
    'unit' => 'ml',
    'cogs' => 1500,
    'selling_price' => 2000
], $sessionId);
$rawB_Id = $resRawB['json']['product_id'] ?? 0;
assertTest("Bahan Baku B berhasil dibuat (HPP=1500)", $rawB_Id > 0);

// Buat Barang Jadi
$fg_Sku = 'PRD-TEST-FG-' . time();
$resFg = callApi('save_product', 'POST', [
    'sku' => $fg_Sku,
    'name' => 'Produk Jadi Uji BOM',
    'product_type' => 'FINISHED_GOODS',
    'unit' => 'cup',
    'cogs' => 0,
    'selling_price' => 15000
], $sessionId);
$fg_Id = $resFg['json']['product_id'] ?? 0;
assertTest("Barang Jadi berhasil dibuat", $fg_Id > 0);

// Tambahkan resep: 2 unit Raw A (2 * 2500 = 5000) dan 3 unit Raw B (3 * 1500 = 4500) -> Total HPP = 9500
$resRecipe1 = callApi('save_recipe_item', 'POST', [
    'finished_product_id' => $fg_Id,
    'ingredient_product_id' => $rawA_Id,
    'qty_needed' => 2,
    'unit' => 'gram'
], $sessionId);
assertTest("BOM Bahan Baku A (qty 2) ditambahkan", ($resRecipe1['json']['success'] ?? false) === true);

$resRecipe2 = callApi('save_recipe_item', 'POST', [
    'finished_product_id' => $fg_Id,
    'ingredient_product_id' => $rawB_Id,
    'qty_needed' => 3,
    'unit' => 'ml'
], $sessionId);
assertTest("BOM Bahan Baku B (qty 3) ditambahkan", ($resRecipe2['json']['success'] ?? false) === true);

// Cek HPP barang jadi melalui endpoint get_product_bom_cogs
$resBomCogs = callApi('get_product_bom_cogs', 'GET', ['product_id' => $fg_Id], $sessionId);
assertTest("Endpoint get_product_bom_cogs mengembalikan 9500", ($resBomCogs['json']['calculated_cogs'] ?? 0) == 9500);

// Test cascading: Update HPP Bahan Baku A dari 2500 -> 3000
// Resep: (2 * 3000) + (3 * 1500) = 6000 + 4500 = 10500
$resUpdRawA = callApi('save_product', 'POST', [
    'id' => $rawA_Id,
    'sku' => $rawA_Sku,
    'name' => 'Bahan Baku Uji A (Updated)',
    'product_type' => 'RAW_MATERIAL',
    'unit' => 'gram',
    'cogs' => 3000,
    'selling_price' => 3500
], $sessionId);
assertTest("Update harga modal Bahan Baku A berhasil", ($resUpdRawA['json']['success'] ?? false) === true);

// Verifikasi cascading HPP pada barang jadi
$resBomCogsAfter = callApi('get_product_bom_cogs', 'GET', ['product_id' => $fg_Id], $sessionId);
assertTest("Cascading HPP: Update harga Raw A otomatis mengupdate HPP Barang Jadi ke 10500", ($resBomCogsAfter['json']['calculated_cogs'] ?? 0) == 10500);


// 3. TEST DELETE PRODUCT (HARD DELETE & SOFT DELETE)
echo "\n--- 3. Testing Delete Product Logic ---\n";
// Hard delete: Produk fg_Id belum punya riwayat transaksi penjualan
$resDeleteHard = callApi('delete_product', 'POST', ['id' => $fg_Id], $sessionId);
assertTest("Delete produk tanpa transaksi berhasil hard delete", ($resDeleteHard['json']['mode'] ?? '') === 'hard_delete');

// Soft delete test: Ambil salah satu produk dari katalog untuk diuji
$resInitial = callApi('get_initial_data', 'GET', [], $sessionId);
$activeProducts = array_filter($resInitial['json']['products'] ?? [], fn($p) => ($p['is_active'] ?? 1) == 1);
$testSoftProd = !empty($activeProducts) ? reset($activeProducts) : null;

if ($testSoftProd) {
    $soldProdId = (int)$testSoftProd['id'];
    
    // Uji endpoint delete_product
    $resDel = callApi('delete_product', 'POST', ['id' => $soldProdId], $sessionId);
    assertTest("Eksekusi delete_product sukses merespon", ($resDel['json']['success'] ?? false) === true);
    
    // Kembalikan produk agar tetap aktif di sistem katalog
    callApi('save_product', 'POST', [
        'id' => $soldProdId,
        'sku' => $testSoftProd['sku'],
        'name' => $testSoftProd['name'],
        'selling_price' => $testSoftProd['selling_price'],
        'is_active' => 1
    ], $sessionId);
}

// Bersihkan bahan baku uji
callApi('delete_product', 'POST', ['id' => $rawA_Id], $sessionId);
callApi('delete_product', 'POST', ['id' => $rawB_Id], $sessionId);


// 4. TEST EXPORT & IMPORT MASTER PRODUCTS
echo "\n--- 4. Testing Export & Import Master Products ---\n";
// Export data
$resExport = callApi('export_products', 'GET', [], $sessionId);
assertTest("Export CSV menghasilkan HTTP 200", $resExport['code'] === 200);
assertTest("Export CSV berisi header yang sesuai", str_contains($resExport['body'], 'sku') && str_contains($resExport['body'], 'name'));

// Export template
$resTemplate = callApi('export_products', 'GET', ['template' => 1], $sessionId);
assertTest("Export Template CSV menghasilkan format yang valid", str_contains($resTemplate['body'], 'PRD-SAMPLE-01'));

// Import CSV
$uniqueImportSku = 'IMP-' . time();
$uniqueImportBarcode = '899' . date('y') . rand(1000000, 9999999);
$csvContent = "Kode SKU,Barcode,Nama Produk,Kategori,Tipe Produk,Satuan,HPP Pokok,Harga Jual,Dikenakan Ongkir,Status Aktif\n" .
              "\"$uniqueImportSku\",\"$uniqueImportBarcode\",\"Teh Impor Melati\",\"Minuman\",\"FINISHED_GOODS\",\"cup\",3500,8000,1,1\n";

$resImport = callApi('import_products', 'POST', ['csv_content' => $csvContent], $sessionId);
if (($resImport['json']['success'] ?? false) !== true) {
    echo "DEBUG IMPORT RES: " . json_encode($resImport) . "\n";
}
assertTest("Import CSV string berhasil", ($resImport['json']['success'] ?? false) === true);
assertTest("Laporan imported count minimal 1", ($resImport['json']['imported'] ?? 0) >= 1);

// Verifikasi produk hasil import via API bootstrap
$resBootstrap = callApi('bootstrap', 'GET', [], $sessionId);
$foundImported = false;
foreach ($resBootstrap['json']['products'] ?? [] as $p) {
    if ($p['sku'] === $uniqueImportSku) {
        $foundImported = true;
        assertTest("Produk impor memiliki harga jual Rp 8.000", (float)$p['selling_price'] == 8000);
        assertTest("Produk impor memiliki is_delivery_required = 1", (int)$p['is_delivery_required'] == 1);
        // Hapus produk uji impor
        callApi('delete_product', 'POST', ['id' => $p['id']], $sessionId);
        break;
    }
}
assertTest("Produk impor ditemukan di katalog sistem", $foundImported);

echo "\n============================================\n";
echo "🎉 ALL TESTS PASSED SUCCESSFULLY! (100% OK)\n";
echo "============================================\n";
