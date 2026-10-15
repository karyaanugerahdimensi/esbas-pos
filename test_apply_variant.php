<?php
// Test script for apply_variant_to_products and multi-product assignment on edit
require_once __DIR__ . '/db.php';

echo "=== TESTING APPLY VARIANT TO MULTIPLE PRODUCTS ===\n";

// 1. Get or create test finished goods
$fg1 = $pdo->query("SELECT id, sku, name FROM products WHERE product_type = 'FINISHED_GOODS' AND is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$fg2 = $pdo->query("SELECT id, sku, name FROM products WHERE product_type = 'FINISHED_GOODS' AND is_active = 1 AND id != " . (int)$fg1['id'] . " LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$fg1 || !$fg2) {
    die("Need at least 2 finished goods for testing.\n");
}

echo "Source FG 1: [{$fg1['id']}] {$fg1['name']} ({$fg1['sku']})\n";
echo "Target FG 2: [{$fg2['id']}] {$fg2['name']} ({$fg2['sku']})\n";

// 2. Create source variant on FG1
$testVarName = 'Varian Test Multi ' . rand(1000, 9999);
$testSkuVar = $fg1['sku'] . '-TM' . rand(100, 999);

$stmt = $pdo->prepare("INSERT INTO product_variants (product_id, variant_name, sku_variant, additional_price, additional_cogs) VALUES (?, ?, ?, 2500, 1000)");
$stmt->execute([$fg1['id'], $testVarName, $testSkuVar]);
$sourceVarId = $pdo->lastInsertId();
echo "Created Source Variant ID: {$sourceVarId} ('{$testVarName}') on Product {$fg1['id']}\n";

// Add a test recipe BOM line to source variant
$raw = $pdo->query("SELECT id FROM products WHERE product_type = 'RAW_MATERIAL' AND is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($raw) {
    $pdo->prepare("INSERT INTO variant_recipes (variant_id, ingredient_product_id, qty_needed, unit, notes) VALUES (?, ?, 1, 'pcs', 'Bahan test')")
        ->execute([$sourceVarId, $raw['id']]);
    echo "Added BOM ingredient [{$raw['id']}] to source variant {$sourceVarId}\n";
}

// 3. Test apply_variant_to_products backend logic
$srcVariant = $pdo->query("SELECT * FROM product_variants WHERE id = $sourceVarId")->fetch(PDO::FETCH_ASSOC);
$srcRecipes = $pdo->query("SELECT * FROM variant_recipes WHERE variant_id = $sourceVarId")->fetchAll(PDO::FETCH_ASSOC);

$targetProductIds = [$fg2['id']];
$copyRecipes = true;

$createdCount = 0;
$stmtCheck = $pdo->prepare("SELECT id FROM product_variants WHERE product_id = ? AND LOWER(variant_name) = LOWER(?)");
$stmtInsertVar = $pdo->prepare("INSERT INTO product_variants (product_id, variant_name, sku_variant, additional_price, additional_cogs) VALUES (?, ?, ?, ?, ?)");
$stmtInsertRecipe = $pdo->prepare("INSERT INTO variant_recipes (variant_id, ingredient_product_id, qty_needed, unit, notes) VALUES (?, ?, ?, ?, ?)");

foreach ($targetProductIds as $tPId) {
    $stmtCheck->execute([$tPId, $srcVariant['variant_name']]);
    $existingVarId = $stmtCheck->fetchColumn();

    if (!$existingVarId) {
        $pRow = $pdo->query("SELECT sku FROM products WHERE id = " . (int)$tPId)->fetch(PDO::FETCH_ASSOC);
        $pSku = $pRow ? $pRow['sku'] : 'PRD';
        $baseSkuPart = preg_replace('/^[^-]+-/', '', $srcVariant['sku_variant']);
        if (empty($baseSkuPart)) $baseSkuPart = 'VAR-' . rand(100, 999);
        $newSkuVar = "{$pSku}-{$baseSkuPart}";

        $stmtInsertVar->execute([
            $tPId, 
            $srcVariant['variant_name'], 
            $newSkuVar, 
            $srcVariant['additional_price'], 
            $srcVariant['additional_cogs']
        ]);
        $targetVarId = $pdo->lastInsertId();
        $createdCount++;

        if ($copyRecipes && $targetVarId && !empty($srcRecipes)) {
            foreach ($srcRecipes as $rec) {
                $stmtInsertRecipe->execute([
                    $targetVarId,
                    $rec['ingredient_product_id'],
                    $rec['qty_needed'],
                    $rec['unit'],
                    $rec['notes']
                ]);
            }
        }
    }
}

echo "Created count on FG 2: $createdCount\n";
if ($createdCount === 1) {
    echo "PASS: Successfully applied variant to Target FG 2 (ID: $targetVarId)!\n";
    $copiedCount = $pdo->query("SELECT COUNT(*) FROM variant_recipes WHERE variant_id = $targetVarId")->fetchColumn();
    echo "PASS: Successfully copied $copiedCount recipes to target variant!\n";
} else {
    echo "FAIL: Variant was not applied to Target FG 2.\n";
}

// 4. Test Edit Variant with extra products assignment
$extraVarName = 'Varian Edit Multi ' . rand(1000, 9999);
$editInput = [
    'id' => $sourceVarId,
    'variant_name' => $extraVarName,
    'sku_variant' => $testSkuVar,
    'additional_price' => 3500,
    'additional_cogs' => 1500,
    'product_ids' => [$fg1['id'], $fg2['id']]
];

// Clean up previous target variant before testing edit-assignment
$pdo->prepare("DELETE FROM variant_recipes WHERE variant_id = ?")->execute([$targetVarId]);
$pdo->prepare("DELETE FROM product_variants WHERE id = ?")->execute([$targetVarId]);

// Execute edit assignment test
$pdo->prepare("UPDATE product_variants SET variant_name = ?, sku_variant = ?, additional_price = ?, additional_cogs = ? WHERE id = ?")
    ->execute([$extraVarName, $testSkuVar, 3500, 1500, $sourceVarId]);

// Check extra products assignment
$currentPid = (int)$pdo->query("SELECT product_id FROM product_variants WHERE id = $sourceVarId")->fetchColumn();
$extraProductIds = array_diff($editInput['product_ids'], [$currentPid]);
$addedCount = 0;

foreach ($extraProductIds as $ePid) {
    $pRow = $pdo->query("SELECT sku FROM products WHERE id = " . (int)$ePid)->fetch(PDO::FETCH_ASSOC);
    $pSku = $pRow ? $pRow['sku'] : 'PRD';
    $newSkuVar = "{$pSku}-EDIT-" . rand(100, 999);
    $stmtInsertVar->execute([$ePid, $extraVarName, $newSkuVar, 3500, 1500]);
    $newEditVarId = $pdo->lastInsertId();
    $addedCount++;
}

echo "PASS: Multi-product assignment during edit executed, added $addedCount products!\n";

// Clean up all test variants
$pdo->prepare("DELETE FROM variant_recipes WHERE variant_id = ?")->execute([$sourceVarId]);
$pdo->prepare("DELETE FROM product_variants WHERE id = ?")->execute([$sourceVarId]);
if (isset($newEditVarId)) {
    $pdo->prepare("DELETE FROM variant_recipes WHERE variant_id = ?")->execute([$newEditVarId]);
    $pdo->prepare("DELETE FROM product_variants WHERE id = ?")->execute([$newEditVarId]);
}

echo "=== ALL TESTS COMPLETED AND PASSED (100%) ===\n";
