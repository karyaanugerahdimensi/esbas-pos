<?php
// test_bom_unit_sync.php
require_once __DIR__ . '/db.php';

echo "=== TESTING BOM RECIPE UNIT SYNCHRONIZATION ===\n";

$db = $pdo;

// 1. Check product_units exists
$units = $db->query("SELECT * FROM product_units ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
echo "Master product units count: " . count($units) . "\n";
assert(count($units) > 0, "product_units table should have data");

// 2. Fetch or create a test raw material with custom unit from product_units
$unitCode = $units[0]['code']; // e.g. liter or gram or pcs
echo "Selected unit for test: {$unitCode}\n";

// Find finished good and raw material
$fg = $db->query("SELECT * FROM products WHERE product_type = 'FINISHED_GOODS' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$rm = $db->query("SELECT * FROM products WHERE product_type = 'RAW_MATERIAL' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$fg || !$rm) {
    echo "Creating test FG and RM...\n";
    if (!$rm) {
        $stmt = $db->prepare("INSERT INTO products (sku, barcode, name, category, product_type, unit, cogs, selling_price, is_active) VALUES ('RM-TEST-UNIT', '899999990001', 'RM Test Unit', 'Bahan Baku', 'RAW_MATERIAL', :unit, 500, 0, 1)");
        $stmt->execute(['unit' => $unitCode]);
        $rmId = $db->lastInsertId();
    } else {
        $rmId = $rm['id'];
    }
    if (!$fg) {
        $stmt = $db->prepare("INSERT INTO products (sku, barcode, name, category, product_type, unit, cogs, selling_price, is_active) VALUES ('FG-TEST-UNIT', '899999990002', 'FG Test Unit', 'Minuman', 'FINISHED_GOODS', 'cup', 1000, 5000, 1)");
        $stmt->execute();
        $fgId = $db->lastInsertId();
    } else {
        $fgId = $fg['id'];
    }
} else {
    $fgId = $fg['id'];
    $rmId = $rm['id'];
}

echo "Using FG ID: {$fgId}, RM ID: {$rmId}\n";

// 3. Test saving recipe item with unit
echo "Testing recipe item insertion with unit = {$unitCode}...\n";
$db->exec("DELETE FROM product_recipes WHERE finished_product_id = {$fgId} AND ingredient_product_id = {$rmId}");

$stmt = $db->prepare("INSERT INTO product_recipes (finished_product_id, ingredient_product_id, qty_needed, unit) VALUES (?, ?, ?, ?)");
$stmt->execute([$fgId, $rmId, 2.5, $unitCode]);
$recipeId = $db->lastInsertId();

$savedRecipe = $db->query("SELECT * FROM product_recipes WHERE id = {$recipeId}")->fetch(PDO::FETCH_ASSOC);
assert($savedRecipe['unit'] === $unitCode, "Saved recipe unit should match {$unitCode}");
echo "PASS: Base recipe saved with unit '{$savedRecipe['unit']}'\n";

// 4. Test variant recipe with unit
$variant = $db->query("SELECT * FROM product_variants WHERE product_id = {$fgId} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$variant) {
    $db->prepare("INSERT INTO product_variants (product_id, variant_name, sku_variant, additional_price, additional_cogs) VALUES (?, 'Varian Test', 'VAR-TEST-01', 1000, 0)")->execute([$fgId]);
    $variantId = $db->lastInsertId();
} else {
    $variantId = $variant['id'];
}

$db->exec("DELETE FROM variant_recipes WHERE variant_id = {$variantId} AND ingredient_product_id = {$rmId}");
$stmtVar = $db->prepare("INSERT INTO variant_recipes (variant_id, ingredient_product_id, qty_needed, unit, notes) VALUES (?, ?, ?, ?, ?)");
$stmtVar->execute([$variantId, $rmId, 1.0, $unitCode, 'Bahan varian test']);
$varRecipeId = $db->lastInsertId();

$savedVarRecipe = $db->query("SELECT * FROM variant_recipes WHERE id = {$varRecipeId}")->fetch(PDO::FETCH_ASSOC);
assert($savedVarRecipe['unit'] === $unitCode, "Saved variant recipe unit should match {$unitCode}");
echo "PASS: Variant recipe saved with unit '{$savedVarRecipe['unit']}'\n";

// Cleanup test records
$db->exec("DELETE FROM product_recipes WHERE id = {$recipeId}");
$db->exec("DELETE FROM variant_recipes WHERE id = {$varRecipeId}");
echo "=== ALL BOM UNIT SYNC TESTS PASSED! ===\n";
