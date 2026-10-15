<?php
/**
 * Test Suite: Pricing Forms & Management (Harga Kontrak Khusus Mitra & Harga Jual Antar Cabang)
 */

$baseUrl = 'http://localhost:8000/api.php';

function request($method, $action, $data = []) {
    global $baseUrl;
    $url = $baseUrl . '?action=' . $action;
    $ch = curl_init();
    
    if ($method === 'GET' && !empty($data)) {
        $url .= '&' . http_build_query($data);
    }
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($err) {
        return ['success' => false, 'error' => $err];
    }
    
    return json_decode($response, true);
}

echo "=========================================================\n";
echo "TEST 1: Ambil Data Pelanggan & Mitra\n";
echo "=========================================================\n";
$custRes = request('GET', 'get_customers', ['category' => 'MITRA']);
assert($custRes['success'] === true, "Gagal mengambil pelanggan mitra");
$mitraList = $custRes['customers'];
echo "Jumlah Mitra B2B terdaftar: " . count($mitraList) . "\n";
if (empty($mitraList)) {
    echo "Membuat data Mitra B2B uji coba...\n";
    $createMitra = request('POST', 'save_customer', [
        'name' => 'PT Uji Coba Mitra B2B',
        'category' => 'MITRA',
        'phone' => '081234567890',
        'email' => 'mitra@test.com',
        'address' => 'Jl. Industri No. 99',
        'notes' => 'Mitra B2B Test'
    ]);
    assert($createMitra['success'] === true, "Gagal membuat mitra uji");
    $targetMitraId = $createMitra['customer_id'];
} else {
    $targetMitraId = $mitraList[0]['id'];
}
echo "Target Mitra ID: {$targetMitraId}\n";

echo "\n=========================================================\n";
echo "TEST 2: Set Harga Kontrak Khusus Mitra (save_mitra_price)\n";
echo "=========================================================\n";
$targetProductId = 1;
$customContractPrice = 18500;
$saveContract = request('POST', 'save_mitra_price', [
    'customer_id' => $targetMitraId,
    'product_id' => $targetProductId,
    'custom_price' => $customContractPrice,
    'notes' => 'Kontrak Volume Eksklusif 2026'
]);
echo "Response: " . json_encode($saveContract) . "\n";
assert($saveContract['success'] === true, "Gagal menyimpan harga kontrak khusus mitra");

echo "\n=========================================================\n";
echo "TEST 3: Verifikasi Harga Kontrak Khusus Mitra (get_mitra_prices)\n";
echo "=========================================================\n";
$getMitraPrices = request('GET', 'get_mitra_prices', ['customer_id' => $targetMitraId]);
assert($getMitraPrices['success'] === true, "Gagal mengambil harga kontrak mitra");
$foundContract = false;
$contractId = null;
foreach ($getMitraPrices['prices'] as $priceRow) {
    if ((int)$priceRow['product_id'] === $targetProductId && (float)$priceRow['custom_price'] === (float)$customContractPrice) {
        $foundContract = true;
        $contractId = $priceRow['id'];
        break;
    }
}
assert($foundContract === true, "Harga kontrak khusus mitra tidak cocok");
echo "Harga kontrak khusus mitra berhasil diverifikasi: Rp {$customContractPrice} (Row ID: {$contractId})\n";

echo "\n=========================================================\n";
echo "TEST 4: Update Harga Jual Khusus Cabang (update_branch_product_price)\n";
echo "=========================================================\n";
$targetBranchId = 2; // Surabaya atau cabang 2
$localBranchPrice = 28500;
$updateBranchPrice = request('POST', 'update_branch_product_price', [
    'branch_id' => $targetBranchId,
    'product_id' => $targetProductId,
    'local_price' => $localBranchPrice
]);
echo "Response: " . json_encode($updateBranchPrice) . "\n";
assert($updateBranchPrice['success'] === true, "Gagal mengupdate harga produk cabang");

echo "\n=========================================================\n";
echo "TEST 5: Verifikasi Harga Jual Cabang (get_inventory_stocks)\n";
echo "=========================================================\n";
$branchStocks = request('GET', 'get_inventory_stocks', ['branch_id' => $targetBranchId, 'product_type' => 'FINISHED_GOODS']);
assert($branchStocks['success'] === true, "Gagal mengambil stok & harga cabang");
$foundBranchPrice = false;
foreach ($branchStocks['stocks'] as $st) {
    if ((int)$st['product_id'] === $targetProductId && (float)$st['local_price'] === (float)$localBranchPrice) {
        $foundBranchPrice = true;
        break;
    }
}
assert($foundBranchPrice === true, "Harga jual cabang tidak cocok");
echo "Harga jual cabang berhasil diverifikasi: Cabang {$targetBranchId}, Produk {$targetProductId} = Rp {$localBranchPrice}\n";

echo "\n=========================================================\n";
echo "TEST 6: Validasi Input Nilai Negatif / Kosong (Negative Test)\n";
echo "=========================================================\n";
$negMitra = request('POST', 'save_mitra_price', [
    'customer_id' => $targetMitraId,
    'product_id' => $targetProductId,
    'custom_price' => -100
]);
assert($negMitra['success'] === false, "Validasi harga negatif mitra gagal");
echo "Negative test Mitra lulus: " . ($negMitra['message'] ?? 'OK') . "\n";

$negBranch = request('POST', 'update_branch_product_price', [
    'branch_id' => $targetBranchId,
    'product_id' => $targetProductId,
    'local_price' => 0
]);
assert($negBranch['success'] === false, "Validasi harga 0 cabang gagal");
echo "Negative test Cabang lulus: " . ($negBranch['message'] ?? 'OK') . "\n";

echo "\n=========================================================\n";
echo "HASIL PENGUJIAN: SEMUA TEST BERHASIL 100% (PASS)!\n";
echo "=========================================================\n";
