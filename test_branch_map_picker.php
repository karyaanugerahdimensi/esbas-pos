<?php
// test_branch_map_picker.php
require_once __DIR__ . '/db.php';

echo "=============================================================\n";
echo " RUNNING TEST: MAP PICKER UI & BRANCH DATA INTEGRITY\n";
echo "=============================================================\n\n";

$passes = 0;
$fails = 0;

function assertTest($cond, $desc) {
    global $passes, $fails;
    if ($cond) {
        echo " [PASS] $desc\n";
        $passes++;
    } else {
        echo " [FAIL] $desc\n";
        $fails++;
    }
}

// 1. Check bootstrap API returns branches with staff, products, vehicles
$_GET['action'] = 'bootstrap';
$_GET['branch_id'] = '1';
$_GET['user_id'] = '1';
ob_start();
require __DIR__ . '/api.php';
$raw = ob_get_clean();
$data = json_decode($raw, true);

assertTest($data && isset($data['success']) && $data['success'] === true, "Bootstrap API response is valid");
assertTest(isset($data['branches']) && count($data['branches']) > 0, "Bootstrap contains branches array (Count: " . count($data['branches'] ?? []) . ")");

if (!empty($data['branches'])) {
    $firstBranch = $data['branches'][0];
    assertTest(array_key_exists('total_staff', $firstBranch), "Branch contains 'total_staff' metric");
    assertTest(array_key_exists('total_products', $firstBranch), "Branch contains 'total_products' metric");
    assertTest(array_key_exists('total_vehicles', $firstBranch), "Branch contains 'total_vehicles' metric");
    assertTest(isset($firstBranch['latitude']) && isset($firstBranch['longitude']), "Branch contains GPS coordinates");
}

// 2. Check get_branches API
$_GET['action'] = 'get_branches';
ob_start();
require __DIR__ . '/api.php';
$rawBranches = ob_get_clean();
$bData = json_decode($rawBranches, true);
assertTest($bData && isset($bData['branches']) && count($bData['branches']) > 0, "get_branches API returns valid branch list");

// 3. Inspect index.php for Map Picker UI elements & explicit heights
$indexContent = file_get_contents(__DIR__ . '/index.php');

assertTest(strpos($indexContent, 'id="cust-modal-map-picker"') !== false, "Customer modal contains #cust-modal-map-picker canvas");
assertTest(strpos($indexContent, 'id="branch-modal-map-picker"') !== false, "Branch modal contains #branch-modal-map-picker canvas");

// Check explicit inline heights (prevents 0px collapse issue from Tailwind missing classes)
assertTest(strpos($indexContent, 'style="height: 220px; min-height: 220px; width: 100%;"') !== false, "Map pickers have explicit inline min-height (220px)");
assertTest(strpos($indexContent, 'style="height: 520px; min-height: 520px; width: 100%;"') !== false, "Geospatial main maps have explicit inline min-height (520px)");

// Check JS picker helpers
assertTest(strpos($indexContent, 'function updateCustPickerBadge') !== false, "JS updateCustPickerBadge exists");
assertTest(strpos($indexContent, 'function updateBranchPickerBadge') !== false, "JS updateBranchPickerBadge exists");
assertTest(strpos($indexContent, 'function editBranchById') !== false, "JS editBranchById exists (safe string parsing)");
assertTest(strpos($indexContent, 'resetCustPickerToBranch') !== false, "JS resetCustPickerToBranch exists");
assertTest(strpos($indexContent, 'onCustBranchChangeInModal') !== false, "JS onCustBranchChangeInModal exists");

// Check proactive loadBranches in initApp
assertTest(strpos($indexContent, 'loadBranches();') !== false, "loadBranches is invoked proactively");

echo "\n=============================================================\n";
echo " TEST SUMMARY: $passes Passed, $fails Failed\n";
echo "=============================================================\n";

if ($fails > 0) exit(1);
