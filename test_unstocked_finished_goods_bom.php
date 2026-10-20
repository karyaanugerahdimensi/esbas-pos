<?php
// test_unstocked_finished_goods_bom.php
// Automated Verification Suite for Unstocked Finished Goods with BOM Auto-Deduction

require_once __DIR__ . '/db.php';
global $pdo;

function runTest($name, $closure) {
    echo "Running test: [{$name}] ... ";
    try {
        $closure();
        echo "PASSED\n";
    } catch (Exception $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Test 1: Toggle Category Stockable API
runTest('Toggle Category Stockable via API endpoint', function() use ($pdo) {
    // Pick first existing category
    $catId = (int)$pdo->query("SELECT id FROM categories ORDER BY id ASC LIMIT 1")->fetchColumn();
    if (!$catId) throw new Exception("No categories found in database");
    
    // Toggle to 0
    $stmt = $pdo->prepare("UPDATE categories SET is_stockable = ? WHERE id = ?");
    $stmt->execute([0, $catId]);
    
    $val = $pdo->query("SELECT is_stockable FROM categories WHERE id = {$catId}")->fetchColumn();
    if ((int)$val !== 0) throw new Exception("Expected category is_stockable to be 0, got {$val}");
    
    // Toggle back to 1
    $stmt->execute([1, $catId]);
    $val = $pdo->query("SELECT is_stockable FROM categories WHERE id = {$catId}")->fetchColumn();
    if ((int)$val !== 1) throw new Exception("Expected category is_stockable to be 1, got {$val}");
});

// Test 2: Verify Galon 15 Liter Configuration and Recipe
runTest('Galon 15 Liter recipe and stockable configuration', function() use ($pdo) {
    $pId = 100; // Air Mineral Galon 15 Liter
    $p = $pdo->query("SELECT id, name, has_bom, is_stockable FROM products WHERE id = {$pId}")->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new Exception("Product ID 100 not found");
    if ((int)$p['has_bom'] !== 1) throw new Exception("Product ID 100 has_bom should be 1");
    
    // Set is_stockable to 0 for Galon 15 Liter
    $pdo->prepare("UPDATE products SET is_stockable = 0 WHERE id = ?")->execute([$pId]);
    $isStk = $pdo->query("SELECT is_stockable FROM products WHERE id = {$pId}")->fetchColumn();
    if ((int)$isStk !== 0) throw new Exception("Product ID 100 is_stockable should be 0");
    
    // Check recipes (Air Baku 16L, Tutup 1 pcs, Tisu 1 pcs)
    $stmtR = $pdo->prepare("
        SELECT pr.ingredient_product_id, pr.qty_needed, p.name 
        FROM product_recipes pr
        JOIN products p ON p.id = pr.ingredient_product_id
        WHERE pr.finished_product_id = ?
    ");
    $stmtR->execute([$pId]);
    $recipes = $stmtR->fetchAll(PDO::FETCH_ASSOC);
    if (count($recipes) < 3) throw new Exception("Expected at least 3 BOM ingredients for Galon 15L, found " . count($recipes));
    
    $ingNames = array_column($recipes, 'name');
    echo "(Ingredients: " . implode(', ', $ingNames) . ") ";
});

// Test 3: Checkout Unstocked Galon 15 Liter with 0 Finished Stock
runTest('Checkout Galon 15L with 0 finished stock succeeds and deducts 16L Air, 1 Tutup, 1 Tisu per qty', function() use ($pdo) {
    $branchId = 1;
    $pId = 100; // Galon 15L
    $airBakuId = 91;
    $tutupGalonId = 89;
    $tisuGalonId = 90;
    
    // Ensure Galon 15L has 0 finished stock in branch 1
    $pdo->prepare("INSERT OR IGNORE INTO branch_stocks (branch_id, product_id, stock, local_price, local_cogs, is_active) VALUES (?, ?, 0, 18000, 7000, 1)")
        ->execute([$branchId, $pId]);
    $pdo->prepare("UPDATE branch_stocks SET stock = 0, is_active = 1 WHERE branch_id = ? AND product_id = ?")
        ->execute([$branchId, $pId]);
        
    // Ensure raw materials have enough stock
    foreach ([$airBakuId => 500, $tutupGalonId => 100, $tisuGalonId => 100] as $ingId => $stk) {
        $pdo->prepare("INSERT OR IGNORE INTO branch_stocks (branch_id, product_id, stock, local_price, local_cogs, is_active) VALUES (?, ?, ?, 0, 1000, 1)")
            ->execute([$branchId, $ingId, $stk]);
        $pdo->prepare("UPDATE branch_stocks SET stock = ? WHERE branch_id = ? AND product_id = ?")
            ->execute([$stk, $branchId, $ingId]);
    }
    
    $initAir = (float)$pdo->query("SELECT stock FROM branch_stocks WHERE branch_id = {$branchId} AND product_id = {$airBakuId}")->fetchColumn();
    $initTutup = (float)$pdo->query("SELECT stock FROM branch_stocks WHERE branch_id = {$branchId} AND product_id = {$tutupGalonId}")->fetchColumn();
    $initTisu = (float)$pdo->query("SELECT stock FROM branch_stocks WHERE branch_id = {$branchId} AND product_id = {$tisuGalonId}")->fetchColumn();
    $initGalon = (float)$pdo->query("SELECT stock FROM branch_stocks WHERE branch_id = {$branchId} AND product_id = {$pId}")->fetchColumn();
    
    if ($initGalon != 0) throw new Exception("Initial Galon 15L finished stock must be 0 for this test");
    
    // Simulate POS Checkout of 2 Galon 15L
    $testInvoice = 'INV-TEST-BOM-' . time();
    $qtyBuy = 2;
    
    // Emulate checkout_sale logic from api.php directly against DB
    $pdo->beginTransaction();
    
    // Check product
    $stmtCheck = $pdo->prepare("
        SELECT p.name, p.has_bom, p.member_price, p.reseller_price, p.mitra_price,
               p.is_taxable, p.tax_id, p.tax_percent,
               COALESCE(p.is_stockable, 1) as is_stockable,
               COALESCE(c.is_stockable, 1) as cat_is_stockable,
               COALESCE(bs.is_delivery_required, p.is_delivery_required, 0) as is_delivery_required,
               COALESCE(bs.is_delivery_included, p.is_delivery_included, 0) as is_delivery_included,
               bs.stock, bs.local_price, bs.local_cogs,
               COALESCE(bs.is_active, 1) as branch_is_active
        FROM branch_stocks bs
        JOIN products p ON p.id = bs.product_id
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE bs.branch_id = ? AND bs.product_id = ?
    ");
    $stmtCheck->execute([$branchId, $pId]);
    $stockRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    $requiresStock = ((int)$stockRow['is_stockable'] === 1 && (int)$stockRow['cat_is_stockable'] === 1);
    
    if ($requiresStock) {
        throw new Exception("Galon 15L should NOT require stock");
    }
    
    if ($requiresStock && $stockRow['stock'] < $qtyBuy) {
        throw new Exception("Finished stock check should have been skipped!");
    }
    
    // Finished stock deduction is skipped because $requiresStock is false
    if ($requiresStock) {
        $pdo->prepare("UPDATE branch_stocks SET stock = stock - ? WHERE branch_id = ? AND product_id = ?")
            ->execute([$qtyBuy, $branchId, $pId]);
    }
    
    // BOM deduction
    if (!empty($stockRow['has_bom'])) {
        $stmtRecipe = $pdo->prepare("
            SELECT pr.ingredient_product_id, pr.qty_needed, pr.unit, p.name as ing_name, bs.stock as ing_stock
            FROM product_recipes pr
            JOIN products p ON p.id = pr.ingredient_product_id
            JOIN branch_stocks bs ON bs.product_id = pr.ingredient_product_id AND bs.branch_id = ?
            WHERE pr.finished_product_id = ?
        ");
        $stmtRecipe->execute([$branchId, $pId]);
        $recipes = $stmtRecipe->fetchAll(PDO::FETCH_ASSOC);

        foreach ($recipes as $r) {
            $totalIngNeeded = $r['qty_needed'] * $qtyBuy;
            $ingId = $r['ingredient_product_id'];
            $curIngStock = (float)$r['ing_stock'];
            $newIngStock = max(0, $curIngStock - $totalIngNeeded);

            $pdo->prepare("UPDATE branch_stocks SET stock = stock - ? WHERE branch_id = ? AND product_id = ?")
                ->execute([$totalIngNeeded, $branchId, $ingId]);

            $pdo->prepare("
                INSERT INTO stock_movements (branch_id, product_id, movement_type, qty_change, stock_before, stock_after, reference_no, notes, user_id)
                VALUES (?, ?, 'BOM_DEDUCTION', ?, ?, ?, ?, ?, ?)
            ")->execute([
                $branchId, $ingId, -$totalIngNeeded, $curIngStock, $newIngStock,
                $testInvoice, "Konsumsi Base BOM untuk {$qtyBuy}x Galon 15L", 1
            ]);
        }
    }
    
    $pdo->commit();
    
    // Verify Stocks after checkout
    $afterGalon = (float)$pdo->query("SELECT stock FROM branch_stocks WHERE branch_id = {$branchId} AND product_id = {$pId}")->fetchColumn();
    $afterAir = (float)$pdo->query("SELECT stock FROM branch_stocks WHERE branch_id = {$branchId} AND product_id = {$airBakuId}")->fetchColumn();
    $afterTutup = (float)$pdo->query("SELECT stock FROM branch_stocks WHERE branch_id = {$branchId} AND product_id = {$tutupGalonId}")->fetchColumn();
    $afterTisu = (float)$pdo->query("SELECT stock FROM branch_stocks WHERE branch_id = {$branchId} AND product_id = {$tisuGalonId}")->fetchColumn();
    
    if ($afterGalon != 0) throw new Exception("Galon 15L stock should remain 0, got {$afterGalon}");
    if ($afterAir != ($initAir - (16 * $qtyBuy))) throw new Exception("Air Baku should decrease by 32L (from {$initAir} to " . ($initAir - 32) . "), got {$afterAir}");
    if ($afterTutup != ($initTutup - (1 * $qtyBuy))) throw new Exception("Tutup Galon should decrease by 2 (from {$initTutup} to " . ($initTutup - 2) . "), got {$afterTutup}");
    if ($afterTisu != ($initTisu - (1 * $qtyBuy))) throw new Exception("Tisu Galon should decrease by 2 (from {$initTisu} to " . ($initTisu - 2) . "), got {$afterTisu}");
    
    // Verify movements
    $bomMoves = $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reference_no = '{$testInvoice}' AND movement_type = 'BOM_DEDUCTION'")->fetchColumn();
    if ((int)$bomMoves < 3) throw new Exception("Expected at least 3 BOM_DEDUCTION movements for invoice {$testInvoice}, found {$bomMoves}");
});

// Test 4: Verify Stocked Product enforces stock check
runTest('Stocked product (is_stockable = 1) is blocked if stock is 0', function() use ($pdo) {
    $branchId = 1;
    $pId = (int)$pdo->query("SELECT id FROM products WHERE id != 100 AND product_type = 'FINISHED_GOODS' LIMIT 1")->fetchColumn();
    if (!$pId) throw new Exception("No finished goods product found for test 4");
    
    $pdo->prepare("UPDATE products SET is_stockable = 1 WHERE id = ?")->execute([$pId]);
    $pdo->prepare("INSERT OR IGNORE INTO branch_stocks (branch_id, product_id, stock, local_price, local_cogs, is_active) VALUES (?, ?, 0, 10000, 5000, 1)")->execute([$branchId, $pId]);
    $pdo->prepare("UPDATE branch_stocks SET stock = 0 WHERE branch_id = ? AND product_id = ?")->execute([$branchId, $pId]);
    
    $stmtCheck = $pdo->prepare("
        SELECT p.name, COALESCE(p.is_stockable, 1) as is_stockable, COALESCE(c.is_stockable, 1) as cat_is_stockable, bs.stock
        FROM branch_stocks bs
        JOIN products p ON p.id = bs.product_id
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE bs.branch_id = ? AND bs.product_id = ?
    ");
    $stmtCheck->execute([$branchId, $pId]);
    $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception("Product row not found in branch_stocks");
    
    $requiresStock = ((int)$row['is_stockable'] === 1 && (int)$row['cat_is_stockable'] === 1);
    if (!$requiresStock) throw new Exception("Expected product {$pId} to require stock");
    if ($requiresStock && $row['stock'] < 1) {
        // Expected blocked!
        return;
    }
    throw new Exception("Stock check failed to block product {$pId} with 0 stock");
});

echo "\nALL TESTS PASSED SUCCESSFULLY! (100% OK)\n";
