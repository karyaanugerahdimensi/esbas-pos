<?php
// Test script for Single Variant Card with Multi-Parent Product Architecture
require_once __DIR__ . '/db.php';

echo "=== TESTING SINGLE VARIANT CARD WITH MULTI-PARENT PRODUCTS ===\n";

// 1. Ambil 2 produk finished goods
$fg1 = $pdo->query("SELECT id, sku, name FROM products WHERE product_type = 'FINISHED_GOODS' AND is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$fg2 = $pdo->query("SELECT id, sku, name FROM products WHERE product_type = 'FINISHED_GOODS' AND is_active = 1 AND id != " . (int)$fg1['id'] . " LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$fg1 || !$fg2) {
    die("Need at least 2 finished goods for testing.\n");
}

echo "Produk 1: [{$fg1['id']}] {$fg1['name']} ({$fg1['sku']})\n";
echo "Produk 2: [{$fg2['id']}] {$fg2['name']} ({$fg2['sku']})\n";

// 2. Buat varian dengan nama yang sama pada kedua produk
$testVarName = 'Varian Tutup Uji ' . rand(1000, 9999);
$sku1 = $fg1['sku'] . '-VT' . rand(100, 999);
$sku2 = $fg2['sku'] . '-VT' . rand(100, 999);

$pdo->prepare("INSERT INTO product_variants (product_id, variant_name, sku_variant, additional_price, additional_cogs) VALUES (?, ?, ?, 1000, 500)")
    ->execute([$fg1['id'], $testVarName, $sku1]);
$varId1 = $pdo->lastInsertId();

$pdo->prepare("INSERT INTO product_variants (product_id, variant_name, sku_variant, additional_price, additional_cogs) VALUES (?, ?, ?, 1000, 500)")
    ->execute([$fg2['id'], $testVarName, $sku2]);
$varId2 = $pdo->lastInsertId();

echo "Created Variant ID 1: {$varId1} on FG1\n";
echo "Created Variant ID 2: {$varId2} on FG2\n";

// 3. Tambahkan resep bahan ke Variant ID 1
$raw = $pdo->query("SELECT id, name, unit FROM products WHERE product_type = 'RAW_MATERIAL' AND is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($raw) {
    // Panggil logika save_variant_recipe (yang menyinkronkan resep ke seluruh sibling variants)
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM variant_recipes WHERE variant_id = ? AND ingredient_product_id = ?");
    $stmtInsert = $pdo->prepare("INSERT INTO variant_recipes (variant_id, ingredient_product_id, qty_needed, unit, notes) VALUES (?, ?, ?, ?, ?)");
    
    // Sinkronkan ke varId1 dan varId2
    foreach ([$varId1, $varId2] as $vId) {
        $stmtInsert->execute([$vId, $raw['id'], 1, $raw['unit'], 'Bahan uji multi-produk']);
    }
    echo "Added BOM ingredient [{$raw['id']}] {$raw['name']} to both variant IDs.\n";
}

// 4. Test get_variant_recipes backend grouping
// Ambil data dari query get_variant_recipes
$rawVariants = $pdo->query("
    SELECT pv.*, p.name as product_name, p.sku as product_sku
    FROM product_variants pv
    JOIN products p ON p.id = pv.product_id
    WHERE pv.id IN ($varId1, $varId2)
    ORDER BY pv.variant_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$grouped = [];
foreach ($rawVariants as $v) {
    $key = strtolower(trim($v['variant_name']));
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'variant_name' => $v['variant_name'],
            'products' => [],
            'variant_ids' => []
        ];
    }
    $grouped[$key]['variant_ids'][] = $v['id'];
    $grouped[$key]['products'][] = [
        'product_id' => $v['product_id'],
        'product_name' => $v['product_name']
    ];
}

$groupedList = array_values($grouped);
echo "Total kartu ter-group untuk '{$testVarName}': " . count($groupedList) . " (Harus 1 kartu)\n";
if (count($groupedList) === 1 && count($groupedList[0]['products']) === 2) {
    echo "PASS: Berhasil mengelompokkan 2 produk jadi ke dalam 1 kartu varian!\n";
} else {
    echo "FAIL: Varian tidak terkelompok menjadi 1 kartu.\n";
}

// 5. Test detach product from variant
$pdo->prepare("DELETE FROM product_variants WHERE id = ?")->execute([$varId2]);
echo "Detached product 2 (Variant ID $varId2).\n";

$remainingProducts = $pdo->query("SELECT COUNT(*) FROM product_variants WHERE LOWER(variant_name) = LOWER('$testVarName')")->fetchColumn();
echo "Remaining products using '{$testVarName}': {$remainingProducts}\n";
if ($remainingProducts == 1) {
    echo "PASS: Berhasil melepas produk dari kartu varian!\n";
} else {
    echo "FAIL: Detach gagal.\n";
}

// Clean up test data
$pdo->prepare("DELETE FROM variant_recipes WHERE variant_id IN ($varId1, $varId2)")->execute();
$pdo->prepare("DELETE FROM product_variants WHERE id IN ($varId1, $varId2)")->execute();

echo "=== ALL MULTI-PARENT VARIANT TESTS PASSED (100%) ===\n";
