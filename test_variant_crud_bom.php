<?php
/**
 * Automated Test: CRUD Varian Produk & Penetapan Tambahan Harga pada Sub-Tab Resep Pilihan Varian
 * 
 * Skenario Pengujian:
 * 1. Read / Query: Mendapatkan daftar varian beserta produk induk & bahan baku via `get_variant_recipes`.
 * 2. Create (C): Tambah varian baru langsung untuk produk induk dengan tambahan harga (misal +Rp 2.500) via `save_variant`.
 * 3. Read (R): Verifikasi varian baru muncul di `get_variant_recipes` dengan tambahan harga yang tepat.
 * 4. Update (U): Edit varian tersebut via `save_variant` (ubah nama, ubah tambahan harga dari +Rp 2.500 menjadi +Rp 0 atau +Rp 4.000).
 * 5. Verify Checkout: Lakukan transaksi kasir pada varian yang diedit dan pastikan harga satuan dihitung akurat.
 * 6. Delete (D): Hapus varian via `delete_variant` dan pastikan bersih dari database.
 */

require_once __DIR__ . '/db.php';

echo "====================================================================\n";
echo "TEST SUITE: CRUD VARIAN & TAMBAHAN HARGA DI SUB-TAB RESEP PILIHAN VARIAN\n";
echo "====================================================================\n\n";

function callApi($action, $payload = null) {
    $url = 'http://localhost:8000/api.php?action=' . $action;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// -------------------------------------------------------------
// 1. READ: Ambil list varian awal
// -------------------------------------------------------------
echo "[STEP 1] Memeriksa API `get_variant_recipes`...\n";
$dataInitial = callApi('get_variant_recipes');
assert($dataInitial['success'] === true, "Gagal mengambil data varian recipes");
assert(isset($dataInitial['finished_goods']), "finished_goods harus ada di response untuk dropdown produk induk");
assert(count($dataInitial['finished_goods']) > 0, "Daftar produk jadi tidak boleh kosong");
echo " -> Terdaftar " . count($dataInitial['variants']) . " varian dan " . count($dataInitial['finished_goods']) . " produk induk.\n";
echo " -> [PASSED] Read subtab Resep Pilihan Varian OK!\n\n";

// Ambil salah satu produk jadi (PRD-GLN-19L)
$galon = null;
foreach ($dataInitial['finished_goods'] as $fg) {
    if ($fg['sku'] === 'PRD-GLN-19L') {
        $galon = $fg;
        break;
    }
}
if (!$galon) $galon = $dataInitial['finished_goods'][0];

// -------------------------------------------------------------
// 2. CREATE: Buat Varian Baru dari Sub-Tab dengan Tambahan Harga
// -------------------------------------------------------------
echo "[STEP 2] CREATE: Membuat Varian Baru dengan Tambahan Harga +Rp 3.500...\n";
$skuTest = 'VAR-BOM-TEST-' . time();
$createPayload = [
    'product_id' => (int)$galon['id'],
    'variant_name' => 'Varian Uji Galon Dingin + Tutup',
    'sku_variant' => $skuTest,
    'additional_price' => 3500,
    'additional_cogs' => 800
];

$resCreate = callApi('save_variant', $createPayload);
echo " -> Response create: " . json_encode($resCreate) . "\n";
assert($resCreate['success'] === true, "Gagal membuat varian baru");

// Ambil ID varian yang baru dibuat dari database
$savedVar = $pdo->query("SELECT * FROM product_variants WHERE sku_variant = '{$skuTest}'")->fetch(PDO::FETCH_ASSOC);
assert($savedVar !== false, "Varian baru tidak ditemukan di database!");
$createdVarId = (int)$savedVar['id'];
assert((float)$savedVar['additional_price'] === 3500.0, "Tambahan harga tidak sesuai");
echo " -> ID Varian Baru: {$createdVarId}, SKU: {$skuTest}, Tambahan Harga: +Rp " . number_format($savedVar['additional_price'], 0, ',', '.') . "\n";
echo " -> [PASSED] Create varian di subtab Resep Pilihan Varian OK!\n\n";

// -------------------------------------------------------------
// 3. READ: Verifikasi Varian Muncul di Sub-Tab Resep Pilihan Varian
// -------------------------------------------------------------
echo "[STEP 3] READ: Verifikasi Varian Terdaftar di `get_variant_recipes`...\n";
$dataAfterCreate = callApi('get_variant_recipes');
$foundCreated = false;
foreach ($dataAfterCreate['variants'] as $v) {
    if ((int)$v['id'] === $createdVarId) {
        $foundCreated = true;
        assert((float)$v['additional_price'] === 3500.0);
        break;
    }
}
assert($foundCreated, "Varian baru harus muncul pada sub-tab Resep Pilihan Varian");
echo " -> [PASSED] Varian baru tampil di Resep Pilihan Varian dengan harga +Rp 3.500!\n\n";

// -------------------------------------------------------------
// 4. UPDATE: Edit Varian (Ubah Nama & Ubah Tambahan Harga menjadi +Rp 0 / Bebas Biaya)
// -------------------------------------------------------------
echo "[STEP 4] UPDATE: Mengedit Varian (Ubah ke Tanpa Tambahan Harga / Bebas Biaya +Rp 0)...\n";
$updatePayload = [
    'id' => $createdVarId,
    'product_id' => (int)$galon['id'],
    'variant_name' => 'Varian Uji Galon Dingin (Promo Bebas Biaya)',
    'sku_variant' => $skuTest,
    'additional_price' => 0,
    'additional_cogs' => 500
];

$resUpdate = callApi('save_variant', $updatePayload);
echo " -> Response update: " . json_encode($resUpdate) . "\n";
assert($resUpdate['success'] === true, "Gagal mengupdate varian");

$updatedVar = $pdo->query("SELECT * FROM product_variants WHERE id = {$createdVarId}")->fetch(PDO::FETCH_ASSOC);
assert($updatedVar['variant_name'] === 'Varian Uji Galon Dingin (Promo Bebas Biaya)');
assert((float)$updatedVar['additional_price'] === 0.0, "Tambahan harga harus menjadi 0!");
echo " -> Varian berhasil diupdate! Nama baru: '{$updatedVar['variant_name']}', Tambahan Harga: +Rp 0\n";
echo " -> [PASSED] Update varian & ubah tambahan harga OK!\n\n";

// -------------------------------------------------------------
// 5. CHECKOUT VERIFICATION: Uji Transaksi POS dengan Varian yang Diedit
// -------------------------------------------------------------
echo "[STEP 5] VERIFIKASI TRANSAKSI: Checkout POS menggunakan varian yang diedit (+Rp 0)...\n";
$branchStock = $pdo->query("SELECT local_price FROM branch_stocks WHERE branch_id = 1 AND product_id = {$galon['id']}")->fetch(PDO::FETCH_ASSOC);
$basePrice = (float)$branchStock['local_price'];

$checkoutPayload = [
    'branch_id' => 1,
    'user_id' => 1,
    'customer_id' => 1,
    'customer_name' => 'Pelanggan Uji CRUD',
    'customer_category' => 'REGULER',
    'sales_type' => 'RETAIL',
    'payment_method' => 'CASH',
    'cash_received' => 50000,
    'discount' => 0,
    'delivery_fee' => 0,
    'items' => [
        [
            'product_id' => (int)$galon['id'],
            'variant_id' => $createdVarId,
            'variant_name' => $updatedVar['variant_name'],
            'qty' => 1
        ]
    ]
];

$resSale = callApi('checkout_sale', $checkoutPayload);
assert($resSale['success'] === true, "Checkout dengan varian gagal");
$saleId = $resSale['sale']['id'];
$saleItem = $pdo->query("SELECT * FROM sale_items WHERE sale_id = {$saleId}")->fetch(PDO::FETCH_ASSOC);

echo " -> Harga Dasar Cabang: Rp " . number_format($basePrice, 0, ',', '.') . "\n";
echo " -> Unit Price Transaksi: Rp " . number_format($saleItem['unit_price'], 0, ',', '.') . " (Ekspektasi: Rp " . number_format($basePrice, 0, ',', '.') . ")\n";
assert((float)$saleItem['unit_price'] === $basePrice, "Unit price harus sama persis dengan base price produk!");
echo " -> [PASSED] Verifikasi transaksi dengan varian hasil update sukses!\n\n";

// -------------------------------------------------------------
// 6. DELETE: Hapus Varian dari Database
// -------------------------------------------------------------
echo "[STEP 6] DELETE: Menghapus Varian Uji...\n";
$resDelete = callApi('delete_variant', ['variant_id' => $createdVarId]);
echo " -> Response delete: " . json_encode($resDelete) . "\n";
assert($resDelete['success'] === true, "Gagal menghapus varian");

$checkDeleted = $pdo->query("SELECT * FROM product_variants WHERE id = {$createdVarId}")->fetch(PDO::FETCH_ASSOC);
assert($checkDeleted === false, "Varian seharusnya sudah terhapus!");
echo " -> Varian ID {$createdVarId} telah terhapus sempurna dari database.\n";
echo " -> [PASSED] Delete varian OK!\n\n";

echo "====================================================================\n";
echo "SEMUA SKENARIO CRUD VARIAN PADA SUB-TAB RESEP PILIHAN VARIAN 100% SUKSES!\n";
echo "====================================================================\n";
