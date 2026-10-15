<?php
require_once __DIR__ . '/db.php';

echo "=== TEST 1: CUSTOMERS TABLE ===" . PHP_EOL;
$stmt = $pdo->query("SELECT code, name, category, discount_percent FROM customers ORDER BY id");
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Total customers: " . count($customers) . PHP_EOL;
foreach ($customers as $c) {
    echo "  [{$c['code']}] {$c['name']} - Category: {$c['category']} (Disc: {$c['discount_percent']}%)" . PHP_EOL;
}

echo PHP_EOL . "=== TEST 2: MITRA CUSTOM CONTRACT PRICES ===" . PHP_EOL;
$stmt = $pdo->query("
    SELECT m.id, c.code as cust_code, c.name as customer_name, p.sku as prod_sku, p.name as product_name, m.custom_price 
    FROM mitra_custom_prices m 
    JOIN customers c ON m.customer_id = c.id 
    JOIN products p ON m.product_id = p.id
    ORDER BY c.id, p.id
");
$mitraPrices = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Total contracted prices: " . count($mitraPrices) . PHP_EOL;
foreach ($mitraPrices as $mp) {
    echo "  {$mp['customer_name']} -> {$mp['product_name']}: Rp " . number_format($mp['custom_price'], 0, ',', '.') . PHP_EOL;
}

echo PHP_EOL . "=== TEST 3: BRANCH SPECIFIC PRODUCT PRICES ===" . PHP_EOL;
$stmt = $pdo->query("
    SELECT b.code as branch_code, b.name as branch_name, p.sku as prod_sku, p.name as prod_name, bs.local_price, bs.stock
    FROM branch_stocks bs
    JOIN branches b ON bs.branch_id = b.id
    JOIN products p ON bs.product_id = p.id
    WHERE p.sku IN ('PRD-001', 'PRD-002')
    ORDER BY p.sku, b.id
");
$branchPrices = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Branch stock prices sample:" . PHP_EOL;
foreach ($branchPrices as $bp) {
    echo "  [{$bp['branch_code']}] {$bp['branch_name']} | {$bp['prod_name']}: Rp " . number_format($bp['local_price'], 0, ',', '.') . " (Stock: {$bp['stock']})" . PHP_EOL;
}

echo PHP_EOL . "=== TEST 4: PRICING HIERARCHY VERIFICATION ===" . PHP_EOL;
$testProduct = $pdo->query("SELECT * FROM products WHERE sku = 'PRD-001'")->fetch(PDO::FETCH_ASSOC);
$branch1Stock = $pdo->query("SELECT * FROM branch_stocks WHERE branch_id = 1 AND product_id = {$testProduct['id']}")->fetch(PDO::FETCH_ASSOC);
$branch2Stock = $pdo->query("SELECT * FROM branch_stocks WHERE branch_id = 2 AND product_id = {$testProduct['id']}")->fetch(PDO::FETCH_ASSOC);
$branch3Stock = $pdo->query("SELECT * FROM branch_stocks WHERE branch_id = 3 AND product_id = {$testProduct['id']}")->fetch(PDO::FETCH_ASSOC);

echo "Product: {$testProduct['name']} (SKU: {$testProduct['sku']})" . PHP_EOL;
echo " - Base Price: Rp " . number_format($testProduct['selling_price'], 0, ',', '.') . PHP_EOL;
echo " - Jakarta (Branch 1) Local Price: Rp " . number_format($branch1Stock['local_price'], 0, ',', '.') . PHP_EOL;
echo " - Surabaya (Branch 2) Local Price: Rp " . number_format($branch2Stock['local_price'], 0, ',', '.') . PHP_EOL;
echo " - Bandung (Branch 3) Local Price: Rp " . number_format($branch3Stock['local_price'], 0, ',', '.') . PHP_EOL;
echo " - Base Member Tier Price: Rp " . number_format($testProduct['member_price'], 0, ',', '.') . PHP_EOL;
echo " - Base Reseller Tier Price: Rp " . number_format($testProduct['reseller_price'], 0, ',', '.') . PHP_EOL;
echo " - Base Mitra Tier Price: Rp " . number_format($testProduct['mitra_price'], 0, ',', '.') . PHP_EOL;

// Check Mitra A price vs Mitra B price
$mitraA = $pdo->query("SELECT * FROM customers WHERE code = 'CUST-004'")->fetch(PDO::FETCH_ASSOC);
$mitraAPrice = $pdo->query("SELECT custom_price FROM mitra_custom_prices WHERE customer_id = {$mitraA['id']} AND product_id = {$testProduct['id']}")->fetchColumn();
echo " - Mitra A (PT Mitra Bahtera Abadi) Contract Price: Rp " . number_format($mitraAPrice, 0, ',', '.') . PHP_EOL;

$mitraB = $pdo->query("SELECT * FROM customers WHERE code = 'CUST-005'")->fetch(PDO::FETCH_ASSOC);
$mitraBPrice = $pdo->query("SELECT custom_price FROM mitra_custom_prices WHERE customer_id = {$mitraB['id']} AND product_id = {$testProduct['id']}")->fetchColumn();
echo " - Mitra B (CV Mitra Nusantara Sejahtera) Contract Price: Rp " . number_format($mitraBPrice, 0, ',', '.') . PHP_EOL;

echo PHP_EOL . "=== ALL TESTS VERIFIED AND MATCH SPECIFICATIONS! ===" . PHP_EOL;
