<?php
// test_mariadb_mitra_custom_prices_syntax.php
// Unit test for Mitra Custom Price query compatibility on MariaDB/MySQL and SQLite.

echo "============================================================\n";
echo "TEST: MariaDB & SQLite Compatibility for Mitra Custom Prices\n";
echo "============================================================\n\n";

$allPassed = true;

// 1. Verify adaptQuery logic
require_once __DIR__ . '/db.php';
global $pdo;
$pdo->setIsMysql(true); // Test MySQL translation mode

// Test 1: INSERT OR REPLACE INTO adaptation
$sql1 = "INSERT OR REPLACE INTO mitra_custom_prices (customer_id, product_id, custom_price, notes) VALUES (?, ?, ?, ?)";
$adapted1 = $pdo->adaptQuery($sql1);
if (strpos($adapted1, "REPLACE INTO") !== false && strpos($adapted1, "OR REPLACE") === false) {
    echo "[PASS] Rule 8: INSERT OR REPLACE INTO correctly converted to REPLACE INTO\n";
} else {
    echo "[FAIL] Rule 8 failed: $adapted1\n";
    $allPassed = false;
}

// Test 2: INSERT OR IGNORE INTO adaptation
$sql2 = "INSERT OR IGNORE INTO branch_stocks (branch_id, product_id, stock) VALUES (1, 2, 50)";
$adapted2 = $pdo->adaptQuery($sql2);
if (strpos($adapted2, "INSERT IGNORE INTO") !== false && strpos($adapted2, "OR IGNORE") === false) {
    echo "[PASS] Rule 9: INSERT OR IGNORE INTO correctly converted to INSERT IGNORE INTO\n";
} else {
    echo "[FAIL] Rule 9 failed: $adapted2\n";
    $allPassed = false;
}

// Test 3: datetime('now', 'localtime') adaptation
$sql3 = "UPDATE my_table SET updated_at = datetime('now', 'localtime')";
$adapted3 = $pdo->adaptQuery($sql3);
if (strpos($adapted3, "NOW()") !== false && strpos($adapted3, "datetime") === false) {
    echo "[PASS] Rule 1: datetime('now', 'localtime') correctly converted to NOW()\n";
} else {
    echo "[FAIL] Rule 1 failed: $adapted3\n";
    $allPassed = false;
}

// Reset back to sqlite mode if running locally
$pdo->setIsMysql(false);

// Test 4: Verify api.php save_mitra_price query syntax
$apiContent = file_get_contents(__DIR__ . '/api.php');
$saveMitraPos = strpos($apiContent, "case 'save_mitra_price':");
$nextCasePos = strpos($apiContent, "case 'delete_mitra_price':", $saveMitraPos);
$saveMitraBlock = substr($apiContent, $saveMitraPos, $nextCasePos - $saveMitraPos);

if (strpos($saveMitraBlock, "INSERT OR REPLACE") === false && strpos($saveMitraBlock, "datetime(") === false) {
    echo "[PASS] api.php save_mitra_price uses ANSI cross-database compatible queries (no OR REPLACE, no datetime())\n";
} else {
    echo "[FAIL] api.php save_mitra_price still contains non-standard dialect in: \n$saveMitraBlock\n";
    $allPassed = false;
}

// Test 5: Functional verification in actual database
try {
    // Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS mitra_custom_prices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        custom_price REAL NOT NULL,
        notes TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(customer_id, product_id)
    )");

    $testCustomerId = (int)$pdo->query("SELECT id FROM customers LIMIT 1")->fetchColumn();
    $testProductId = (int)$pdo->query("SELECT id FROM products LIMIT 1")->fetchColumn();

    if (!$testCustomerId || !$testProductId) {
        // Fallback: create temporary customer and product
        $pdo->exec("INSERT INTO customers (name, phone) VALUES ('Test Mitra', '08129999999')");
        $testCustomerId = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO products (name, sku, cogs, selling_price) VALUES ('Test Product', 'TP-999', 5000, 10000)");
        $testProductId = (int)$pdo->lastInsertId();
    }
    $pdo->prepare("DELETE FROM mitra_custom_prices WHERE customer_id = ? AND product_id = ?")->execute([$testCustomerId, $testProductId]);

    // Step A: Insert test
    $now = date('Y-m-d H:i:s');
    $stmtCheck = $pdo->prepare("SELECT id FROM mitra_custom_prices WHERE customer_id = ? AND product_id = ?");
    $stmtCheck->execute([$testCustomerId, $testProductId]);
    $existingId = $stmtCheck->fetchColumn();

    if (!$existingId) {
        $stmt = $pdo->prepare("
            INSERT INTO mitra_custom_prices (customer_id, product_id, custom_price, notes, updated_at) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$testCustomerId, $testProductId, 15000, 'Harga Mitra Awal', $now]);
    }

    $stmtCheck->execute([$testCustomerId, $testProductId]);
    $row = $stmtCheck->fetchColumn();
    if ($row) {
        echo "[PASS] Functional Insert into mitra_custom_prices succeeded (ID: $row)\n";
    } else {
        echo "[FAIL] Functional Insert failed!\n";
        $allPassed = false;
    }

    // Step B: Update test
    $stmtCheck->execute([$testCustomerId, $testProductId]);
    $existingId = $stmtCheck->fetchColumn();
    $newPrice = 17500;
    $newNotes = 'Harga Kontrak Diperbarui';
    $nowUpdate = date('Y-m-d H:i:s');

    if ($existingId) {
        $stmt = $pdo->prepare("
            UPDATE mitra_custom_prices 
            SET custom_price = ?, notes = ?, updated_at = ? 
            WHERE id = ?
        ");
        $stmt->execute([$newPrice, $newNotes, $nowUpdate, $existingId]);
    }

    $stmtSelect = $pdo->prepare("SELECT custom_price, notes FROM mitra_custom_prices WHERE customer_id = ? AND product_id = ?");
    $stmtSelect->execute([$testCustomerId, $testProductId]);
    $data = $stmtSelect->fetch(PDO::FETCH_ASSOC);

    if ($data && (float)$data['custom_price'] === 17500.0 && $data['notes'] === $newNotes) {
        echo "[PASS] Functional Update in mitra_custom_prices succeeded (Price: {$data['custom_price']}, Notes: {$data['notes']})\n";
    } else {
        echo "[FAIL] Functional Update failed: " . json_encode($data) . "\n";
        $allPassed = false;
    }

    // Cleanup
    $pdo->prepare("DELETE FROM mitra_custom_prices WHERE customer_id = ? AND product_id = ?")->execute([$testCustomerId, $testProductId]);
    echo "[PASS] Functional Cleanup succeeded\n";

} catch (Exception $e) {
    echo "[FAIL] Exception during database functional test: " . $e->getMessage() . "\n";
    $allPassed = false;
}

echo "\n------------------------------------------------------------\n";
if ($allPassed) {
    echo "SUMMARY: ALL TESTS PASSED SUCCESSFULLY! (100% OK)\n";
    exit(0);
} else {
    echo "SUMMARY: SOME TESTS FAILED!\n";
    exit(1);
}
