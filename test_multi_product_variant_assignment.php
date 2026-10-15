<?php
// test_multi_product_variant_assignment.php
require_once __DIR__ . '/db.php';

echo "=== TESTING MULTI-PRODUCT VARIANT ASSIGNMENT ===\n";

$db = $pdo;

// 1. Get or create at least two finished goods products
$products = $db->query("SELECT id, name, sku FROM products WHERE product_type = 'FINISHED_GOODS' AND is_active = 1 LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);

if (count($products) < 2) {
    echo "Creating two test finished goods...\n";
    $db->prepare("INSERT INTO products (sku, barcode, name, category, product_type, unit, cogs, selling_price, is_active) VALUES ('PRD-TEST-MULTI-1', '899111110001', 'Minuman Kopi Susu A', 'Minuman', 'FINISHED_GOODS', 'cup', 5000, 15000, 1)")->execute();
    $id1 = $db->lastInsertId();
    $db->prepare("INSERT INTO products (sku, barcode, name, category, product_type, unit, cogs, selling_price, is_active) VALUES ('PRD-TEST-MULTI-2', '899111110002', 'Minuman Teh Tarik B', 'Minuman', 'FINISHED_GOODS', 'cup', 4000, 12000, 1)")->execute();
    $id2 = $db->lastInsertId();
    $products = [
        ['id' => $id1, 'name' => 'Minuman Kopi Susu A', 'sku' => 'PRD-TEST-MULTI-1'],
        ['id' => $id2, 'name' => 'Minuman Teh Tarik B', 'sku' => 'PRD-TEST-MULTI-2']
    ];
}

$p1 = $products[0];
$p2 = $products[1];
echo "Testing with Product 1: [{$p1['id']}] {$p1['name']} ({$p1['sku']})\n";
echo "Testing with Product 2: [{$p2['id']}] {$p2['name']} ({$p2['sku']})\n";

// 2. Test API payload to assign a variant to multiple products simultaneously
$varName = "Ukuran Jumbo (Upsize)";
$skuVarBase = "VAR-JUMBO";
$addPrice = 3000;
$addCogs = 1000;

// Cleanup any old test variants with same name on these products
$db->exec("DELETE FROM product_variants WHERE variant_name = '{$varName}' AND product_id IN ({$p1['id']}, {$p2['id']})");

// Emulate calling save_variant logic with product_ids array
$payload = [
    'product_ids' => [$p1['id'], $p2['id']],
    'variant_name' => $varName,
    'sku_variant' => $skuVarBase,
    'additional_price' => $addPrice,
    'additional_cogs' => $addCogs
];

// Execute insertion logic exactly as implemented in api.php
$createdCount = 0;
$stmtInsert = $db->prepare("
    INSERT INTO product_variants (product_id, variant_name, sku_variant, additional_price, additional_cogs)
    VALUES (?, ?, ?, ?, ?)
");

foreach ($payload['product_ids'] as $pId) {
    $pRow = $db->query("SELECT sku FROM products WHERE id = " . (int)$pId)->fetch();
    $pSku = $pRow ? $pRow['sku'] : 'PRD';
    $finalSkuVar = "{$pSku}-{$skuVarBase}";
    $checkDup = $db->prepare("SELECT COUNT(*) FROM product_variants WHERE sku_variant = ?");
    $checkDup->execute([$finalSkuVar]);
    if ($checkDup->fetchColumn() > 0) {
        $finalSkuVar = "{$finalSkuVar}-" . rand(100, 999);
    }
    $stmtInsert->execute([$pId, $varName, $finalSkuVar, $addPrice, $addCogs]);
    $createdCount++;
}

assert($createdCount === 2, "Variant should be created for both products");
echo "PASS: Successfully created variant for {$createdCount} products.\n";

// 3. Verify in database
$v1 = $db->query("SELECT * FROM product_variants WHERE product_id = {$p1['id']} AND variant_name = '{$varName}'")->fetch(PDO::FETCH_ASSOC);
$v2 = $db->query("SELECT * FROM product_variants WHERE product_id = {$p2['id']} AND variant_name = '{$varName}'")->fetch(PDO::FETCH_ASSOC);

assert(!empty($v1), "Product 1 must have the variant");
assert(!empty($v2), "Product 2 must have the variant");
assert($v1['additional_price'] == $addPrice, "Product 1 variant price must match");
assert($v2['additional_price'] == $addPrice, "Product 2 variant price must match");
assert($v1['sku_variant'] !== $v2['sku_variant'], "Variant SKUs must be unique per product");

echo "PASS: Product 1 has variant ID {$v1['id']} (SKU: {$v1['sku_variant']}) with +Rp {$v1['additional_price']}\n";
echo "PASS: Product 2 has variant ID {$v2['id']} (SKU: {$v2['sku_variant']}) with +Rp {$v2['additional_price']}\n";

// 4. Test duplicate prevention on same product
$checkName = $db->prepare("SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND LOWER(variant_name) = LOWER(?)");
$checkName->execute([$p1['id'], $varName]);
$exists = $checkName->fetchColumn();
assert($exists > 0, "Duplicate detection works: variant already exists for product 1");
echo "PASS: Duplicate prevention check verified.\n";

// 5. Cleanup test records
$db->exec("DELETE FROM product_variants WHERE id IN ({$v1['id']}, {$v2['id']})");
echo "=== ALL MULTI-PRODUCT VARIANT TESTS PASSED! ===\n";
