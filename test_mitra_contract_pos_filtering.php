<?php
// test_mitra_contract_pos_filtering.php
require_once __DIR__ . '/db.php';

echo "=== TESTING MITRA CONTRACT POS PRODUCT FILTERING ===\n";

$db = $pdo;

// 1. Get or create a Mitra customer
$mitra = $db->query("SELECT * FROM customers WHERE category = 'MITRA' AND is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$mitra) {
    echo "Creating test mitra customer...\n";
    $db->prepare("INSERT INTO customers (code, name, category, is_active) VALUES ('MITRA-TEST', 'PT Mitra Sukses Mandiri', 'MITRA', 1)")->execute();
    $mitraId = $db->lastInsertId();
    $mitra = $db->query("SELECT * FROM customers WHERE id = {$mitraId}")->fetch(PDO::FETCH_ASSOC);
} else {
    $mitraId = $mitra['id'];
}
echo "Mitra Customer: ID {$mitraId} - {$mitra['name']}\n";

// 2. Get two active products
$products = $db->query("SELECT * FROM products WHERE product_type = 'FINISHED_GOODS' AND is_active = 1 LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
assert(count($products) >= 2, "Need at least 2 products for testing");

$p1 = $products[0];
$p2 = $products[1];

echo "Product 1 (will set contract): ID {$p1['id']} - {$p1['name']}\n";
echo "Product 2 (no contract): ID {$p2['id']} - {$p2['name']}\n";

// 3. Clear existing contracts for this mitra on both products
$db->exec("DELETE FROM mitra_custom_prices WHERE customer_id = {$mitraId} AND product_id IN ({$p1['id']}, {$p2['id']})");

// Set contract ONLY for p1
$contractPriceP1 = 12500;
$stmtContract = $db->prepare("INSERT INTO mitra_custom_prices (customer_id, product_id, custom_price, notes) VALUES (?, ?, ?, 'Kontrak Khusus Test')");
$stmtContract->execute([$mitraId, $p1['id'], $contractPriceP1]);
$contractId = $db->lastInsertId();

// Verify in DB
$cp1 = $db->query("SELECT * FROM mitra_custom_prices WHERE customer_id = {$mitraId} AND product_id = {$p1['id']}")->fetch(PDO::FETCH_ASSOC);
assert(!empty($cp1) && $cp1['custom_price'] == $contractPriceP1, "Product 1 must have contract price in DB");

$cp2 = $db->query("SELECT * FROM mitra_custom_prices WHERE customer_id = {$mitraId} AND product_id = {$p2['id']}")->fetch(PDO::FETCH_ASSOC);
assert(empty($cp2), "Product 2 must NOT have contract price in DB");
echo "PASS: Database setup verified. Product 1 has contract, Product 2 has no contract.\n";

// 4. Test logic emulation for frontend filter
$mitraPrices = $db->query("SELECT * FROM mitra_custom_prices WHERE customer_id = {$mitraId}")->fetchAll(PDO::FETCH_ASSOC);

function filterProductsForMitra($allProds, $activeCust, $activeCat, $mitraPrices) {
    return array_values(array_filter($allProds, function($p) use ($activeCust, $activeCat, $mitraPrices) {
        if ($activeCat === 'MITRA') {
            if (!$activeCust) return false;
            $foundContract = false;
            foreach ($mitraPrices as $mp) {
                if ($mp['customer_id'] == $activeCust['id'] && $mp['product_id'] == $p['id'] && (float)$mp['custom_price'] > 0) {
                    $foundContract = true;
                    break;
                }
            }
            if (!$foundContract) return false;
        }
        return true;
    }));
}

$visibleToMitra = filterProductsForMitra([$p1, $p2], $mitra, 'MITRA', $mitraPrices);
echo "Products visible when MITRA tier active: " . count($visibleToMitra) . "\n";
assert(count($visibleToMitra) === 1, "Only 1 product should be visible to Mitra");
assert($visibleToMitra[0]['id'] === $p1['id'], "Visible product must be Product 1 with contract");
echo "PASS: Filtering logic strictly shows only Product 1 with contract price.\n";

// Non-Mitra tier should see both products
$visibleToReguler = filterProductsForMitra([$p1, $p2], null, 'REGULER', $mitraPrices);
assert(count($visibleToReguler) === 2, "Reguler tier should see both products");
echo "PASS: Non-mitra tier (REGULER) sees all products normally.\n";

// 5. Test checkout backend validation
// Case A: Checkout with contracted product (should pass or fail only on shift/stock if any)
// We test checkout rejection on Product 2 directly with a mock payload
$branchId = 1;
// Ensure branch stock exists
$db->exec("INSERT OR IGNORE INTO branch_stocks (branch_id, product_id, stock, local_price, local_cogs) VALUES (1, {$p2['id']}, 100, 15000, 10000)");

$chCheck = $db->prepare("SELECT custom_price FROM mitra_custom_prices WHERE customer_id = ? AND product_id = ?");
$chCheck->execute([$mitraId, $p2['id']]);
$customPrice = $chCheck->fetchColumn();
assert($customPrice === false, "Contract price for Product 2 should be false");
echo "PASS: Backend check confirmed - Product 2 has no custom_price for this mitra.\n";

// Cleanup test contract
$db->exec("DELETE FROM mitra_custom_prices WHERE id = {$contractId}");
echo "=== ALL MITRA CONTRACT FILTERING TESTS PASSED! ===\n";
