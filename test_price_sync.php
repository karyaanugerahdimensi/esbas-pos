<?php
require_once __DIR__ . '/db.php';

// 1. Reset awal PRD-GLN-19L
$pdo->prepare("UPDATE products SET selling_price = 19000, member_price = 17100, reseller_price = 15200, mitra_price = 13680, cogs = 4750 WHERE sku = 'PRD-GLN-19L'")->execute();
$pdo->prepare("UPDATE branch_stocks SET local_price = 19000, local_cogs = 4750 WHERE product_id = 19")->execute();

echo "--- STEP 1: INITIAL STATE (Harga 19000) ---\n";
$ch = curl_init('http://localhost:8000/api.php?action=bootstrap&branch_id=1&user_id=1');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = json_decode(curl_exec($ch), true);
foreach ($res['products'] as $p) {
    if ($p['sku'] === 'PRD-GLN-19L') {
        echo "Produk POS Kasir: {$p['name']} | Selling Price: Rp " . number_format($p['selling_price']) . "\n";
    }
}

// 2. Simulasi Edit Produk di Form Master Produk menjadi Rp 22.000
echo "\n--- STEP 2: EDIT PRODUK MENJADI RP 22.000 ---\n";
$ch = curl_init('http://localhost:8000/api.php?action=save_product');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'id' => 19,
    'branch_id' => 1,
    'sku' => 'PRD-GLN-19L',
    'barcode' => '899100109900',
    'name' => 'Air Mineral Galon 19 Liter',
    'product_type' => 'FINISHED_GOODS',
    'category_id' => 2,
    'unit' => 'galon',
    'cogs' => 4750,
    'selling_price' => 22000,
    'image_emoji' => '🍶',
    'has_bom' => 1,
    'reorder_point' => 10
]));
$saveRes = json_decode(curl_exec($ch), true);
echo "API save_product response: " . json_encode($saveRes) . "\n";

// 3. Verifikasi apakah Form Penjualan Kasir (bootstrap) langsung menerima Rp 22.000
echo "\n--- STEP 3: VERIFIKASI HARGA DI FORM PENJUALAN KASIR (BOOTSTRAP) ---\n";
$ch = curl_init('http://localhost:8000/api.php?action=bootstrap&branch_id=1&user_id=1');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res2 = json_decode(curl_exec($ch), true);
$updatedPrice = null;
foreach ($res2['products'] as $p) {
    if ($p['sku'] === 'PRD-GLN-19L') {
        $updatedPrice = $p['selling_price'];
        echo "Produk POS Kasir: {$p['name']} | Selling Price: Rp " . number_format($p['selling_price']) . "\n";
        echo "Member Price: Rp " . number_format($p['member_price']) . " | Reseller Price: Rp " . number_format($p['reseller_price']) . " | Mitra Price: Rp " . number_format($p['mitra_price']) . "\n";
    }
}

if ($updatedPrice == 22000) {
    echo "\n>>> SUKSES! Harga produk PRD-GLN-19L BERHASIL TERUPDATE KE FORM PENJUALAN KASIR (Rp 22.000)! <<<\n";
} else {
    echo "\n>>> GAGAL! Harga produk masih Rp {$updatedPrice} <<<\n";
}
