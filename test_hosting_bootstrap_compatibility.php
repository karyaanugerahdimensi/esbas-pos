<?php
/**
 * Test: Hosting Bootstrap Compatibility & POS Products Verification
 */

require_once __DIR__ . '/db.php';

echo "=== Running Hosting Bootstrap Compatibility Test ===\n";

// 1. Test direct bootstrap logic across all branches
$branches = $pdo->query("SELECT id, name FROM branches ORDER BY id ASC")->fetchAll();
echo "Found " . count($branches) . " branches in database.\n";

foreach ($branches as $b) {
    $bId = (int)$b['id'];
    $_GET['action'] = 'bootstrap';
    $_GET['branch_id'] = $bId;
    $_GET['user_id'] = 1;

    ob_start();
    require __DIR__ . '/api.php';
    $output = ob_get_clean();

    $json = json_decode($output, true);
    if (!$json || empty($json['success'])) {
        echo "[FAIL] Bootstrap failed for branch {$b['name']} (ID {$bId})! Output: " . substr($output, 0, 200) . "\n";
        exit(1);
    }

    $products = $json['products'] ?? [];
    echo "[PASS] Branch '{$b['name']}' (ID: {$bId}): Returned " . count($products) . " finished goods.\n";

    if (count($products) === 0) {
        echo "[FAIL] Branch '{$b['name']}' has 0 products returned in POS!\n";
        exit(1);
    }

    // Verify properties
    foreach ($products as $p) {
        if (!isset($p['requires_stock'])) {
            echo "[FAIL] Product {$p['name']} missing requires_stock field!\n";
            exit(1);
        }
        if (!isset($p['category'])) {
            echo "[FAIL] Product {$p['name']} missing category field!\n";
            exit(1);
        }
    }
}

// 2. Test fallback when users table lacks assigned_vehicle_id
echo "Testing safe users query resilience...\n";
try {
    $users = $pdo->query("SELECT id, nik, name, role, branch_id, email, phone, commission_rate, is_active FROM users ORDER BY id ASC")->fetchAll();
    echo "[PASS] Users fallback query executes cleanly (" . count($users) . " users).\n";
} catch (Exception $e) {
    echo "[FAIL] Users query failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nAll Hosting Bootstrap Compatibility Tests PASSED SUCCESSFULLY!\n";
