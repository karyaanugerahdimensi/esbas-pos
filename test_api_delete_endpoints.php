<?php
/**
 * Test Suite: Direct API Endpoint Invocation for Delete Category, Product Type, and Product Unit
 */

require_once __DIR__ . '/db.php';
global $pdo;

echo "=== START TEST: DIRECT API POST DELETE ACTIONS ===\n";

function assertTest($condition, $message) {
    if ($condition) {
        echo " [PASS] $message\n";
    } else {
        echo " [FAIL] $message\n";
        exit(1);
    }
}

function callApi($action, $data) {
    $url = "http://localhost:8000/api.php?action=" . urlencode($action);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'data' => json_decode($response, true), 'raw' => $response];
}

// 1. Test Delete Category via HTTP POST
echo "\n--- 1. Testing POST delete_category ---\n";
// Create test category first
$pdo->prepare("INSERT INTO categories (code, name, icon_emoji) VALUES ('TCATDEL', 'Test Category To Delete', '🏷️')")->execute();
$catId = $pdo->lastInsertId();

$res = callApi('delete_category', ['id' => $catId]);
assertTest($res['code'] === 200, "HTTP Status 200 returned for delete_category");
assertTest(isset($res['data']['success']) && $res['data']['success'] === true, "delete_category succeeded with message: " . ($res['data']['message'] ?? ''));

// Test Protection on Category ID 1
$resProt = callApi('delete_category', ['id' => 1]);
assertTest($resProt['data']['success'] === false, "delete_category on default ID 1 safely rejected: " . ($resProt['data']['message'] ?? ''));

// 2. Test Delete Product Type via HTTP POST
echo "\n--- 2. Testing POST delete_product_type ---\n";
// Create custom product type
$pdo->prepare("INSERT INTO product_types (code, name, prefix, is_stockable, is_saleable, icon_emoji) VALUES ('TPDEL', 'Type To Delete', 'DEL', 1, 1, '📦')")->execute();
$typeId = $pdo->lastInsertId();

$resType = callApi('delete_product_type', ['id' => $typeId]);
assertTest($resType['code'] === 200, "HTTP Status 200 returned for delete_product_type");
assertTest(isset($resType['data']['success']) && $resType['data']['success'] === true, "delete_product_type succeeded with message: " . ($resType['data']['message'] ?? ''));

// Test Protection on core product type FINISHED_GOODS
$fgId = $pdo->query("SELECT id FROM product_types WHERE code = 'FINISHED_GOODS'")->fetchColumn();
$resFg = callApi('delete_product_type', ['id' => $fgId]);
assertTest($resFg['data']['success'] === false, "delete_product_type on FINISHED_GOODS safely rejected: " . ($resFg['data']['message'] ?? ''));

// 3. Test Delete Product Unit via HTTP POST
echo "\n--- 3. Testing POST delete_product_unit ---\n";
// Create custom unit
$pdo->prepare("INSERT INTO product_units (code, name, description, is_active) VALUES ('delu', 'Unit To Delete', 'Test', 1)")->execute();
$unitId = $pdo->lastInsertId();

$resUnit = callApi('delete_product_unit', ['id' => $unitId]);
assertTest($resUnit['code'] === 200, "HTTP Status 200 returned for delete_product_unit");
assertTest(isset($resUnit['data']['success']) && $resUnit['data']['success'] === true, "delete_product_unit succeeded with message: " . ($resUnit['data']['message'] ?? ''));

echo "\n=== ALL HTTP API DELETE ENDPOINT TESTS PASSED (100%) ===\n";
