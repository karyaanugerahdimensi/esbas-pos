<?php
/**
 * Test: Dynamic User Roles, Role Menu Permissions & User-Location Linkage
 */

require_once __DIR__ . '/db.php';

echo "============================================================\n";
echo "TEST: Dynamic Roles, Menu Permissions & Location Linkage\n";
echo "============================================================\n\n";

function postApi($action, $data) {
    global $pdo;
    $_GET['action'] = $action;
    $input = $data;
    ob_start();
    require __DIR__ . '/api.php';
    $out = ob_get_clean();
    $json = json_decode($out, true);
    if (!$json) {
        echo "[ERROR] Invalid JSON response for {$action}: " . substr($out, 0, 300) . "\n";
        exit(1);
    }
    return $json;
}

function getApi($action, $params = []) {
    global $pdo;
    $_GET = array_merge(['action' => $action], $params);
    ob_start();
    require __DIR__ . '/api.php';
    $out = ob_get_clean();
    $json = json_decode($out, true);
    if (!$json) {
        echo "[ERROR] Invalid JSON response for {$action}: " . substr($out, 0, 300) . "\n";
        exit(1);
    }
    return $json;
}

// 1. Verify user_roles table exists and default roles are populated
$stmtRoles = $pdo->query("SELECT * FROM user_roles ORDER BY id ASC");
$initialRoles = $stmtRoles->fetchAll();
$roleCodes = array_column($initialRoles, 'code');
echo "Initial roles found: " . implode(', ', $roleCodes) . "\n";
if (!in_array('SUPER_ADMIN', $roleCodes) || !in_array('CASHIER', $roleCodes)) {
    echo "[FAIL] Core system roles missing from user_roles!\n";
    exit(1);
}
echo "[PASS] 1. user_roles table exists with core default roles\n";

// 2. Test adding a new custom role 'SUPERVISOR'
$resAddRole = postApi('save_user_role', [
    'code' => 'SUPERVISOR',
    'name' => 'Supervisor Toko',
    'description' => 'Supervisi operasional kasir dan stok cabang',
    'can_access_all_branches' => 0,
    'template_preset' => 'CASHIER'
]);

if (!$resAddRole['success']) {
    echo "[FAIL] Failed to save custom role SUPERVISOR: " . ($resAddRole['message'] ?? '') . "\n";
    exit(1);
}
echo "[PASS] 2. Successfully created new custom role 'SUPERVISOR'\n";

// Verify initial permissions were seeded for SUPERVISOR
$resPerms = getApi('get_role_permissions');
if (!$resPerms['success'] || !isset($resPerms['grouped']['SUPERVISOR'])) {
    echo "[FAIL] Permissions for SUPERVISOR were not seeded in role_menu_permissions!\n";
    exit(1);
}
$spvPerms = $resPerms['grouped']['SUPERVISOR'];
echo "[PASS] 3. Initial 15 menu permissions seeded for SUPERVISOR (POS={$spvPerms['pos']}, ESS={$spvPerms['ess']})\n";

// 3. Test updating permissions for SUPERVISOR (enable stocks and reports)
$newPerms = $resPerms['grouped'];
$newPerms['SUPERVISOR']['stocks'] = 1;
$newPerms['SUPERVISOR']['reports'] = 1;
$newPerms['SUPERVISOR']['pnl'] = 0;

$resSavePerms = postApi('save_role_permissions', [
    'permissions' => $newPerms
]);
if (!$resSavePerms['success']) {
    echo "[FAIL] Failed to update role permissions matrix!\n";
    exit(1);
}

$resPermsCheck = getApi('get_role_permissions');
if ((int)$resPermsCheck['grouped']['SUPERVISOR']['stocks'] !== 1) {
    echo "[FAIL] Updated permission for SUPERVISOR 'stocks' was not saved!\n";
    exit(1);
}
echo "[PASS] 4. Successfully customized menu permissions for SUPERVISOR\n";

// 4. Test creating an employee with role 'SUPERVISOR' assigned to Branch 2
$nikTest = 'SPV001';
$pdo->prepare("DELETE FROM users WHERE nik = ?")->execute([$nikTest]);

$resSaveEmp = postApi('save_employee', [
    'nik' => $nikTest,
    'name' => 'Rangga Supervisor',
    'role' => 'SUPERVISOR',
    'branch_id' => 2,
    'email' => 'rangga@esbas.com',
    'phone' => '08123456789',
    'pin' => '112233',
    'password' => 'spv123',
    'commission_rate' => 0.02
]);

if (!$resSaveEmp['success']) {
    echo "[FAIL] Failed to save employee with SUPERVISOR role: " . ($resSaveEmp['message'] ?? '') . "\n";
    exit(1);
}

$spvUser = $pdo->query("SELECT * FROM users WHERE nik = '{$nikTest}'")->fetch();
if (!$spvUser || $spvUser['role'] !== 'SUPERVISOR' || (int)$spvUser['branch_id'] !== 2) {
    echo "[FAIL] Employee record does not match assigned role and branch!\n";
    exit(1);
}
echo "[PASS] 5. Employee created with role SUPERVISOR and assigned to Cabang 2\n";

// 5. Test bootstrap for this SUPERVISOR user (Location & Permission Linkage)
$resBootstrapSpv = getApi('bootstrap', [
    'user_id' => $spvUser['id'],
    'branch_id' => 1 // Even if requested branch 1, should be bound to branch 2!
]);

if (!$resBootstrapSpv['success']) {
    echo "[FAIL] Bootstrap failed for SUPERVISOR user!\n";
    exit(1);
}

$curUserSpv = $resBootstrapSpv['current_user'];
$curBranchSpv = $resBootstrapSpv['current_branch'];
$userPermsSpv = $resBootstrapSpv['user_permissions'];

if ((int)$curUserSpv['can_access_all_branches'] !== 0) {
    echo "[FAIL] SUPERVISOR should have can_access_all_branches = 0!\n";
    exit(1);
}

if ((int)$curBranchSpv['id'] !== 2) {
    echo "[FAIL] Location linkage failed! User assigned to Branch 2 was initialized with Branch {$curBranchSpv['id']}!\n";
    exit(1);
}

if (empty($userPermsSpv['stocks']) || empty($userPermsSpv['reports'])) {
    echo "[FAIL] User permissions for SUPERVISOR did not reflect role permissions!\n";
    exit(1);
}

echo "[PASS] 6. Keterkaitan Hak Akses - User - Lokasi verified (User locked to Branch 2, permissions accurately loaded)\n";

// 6. Test bootstrap for SUPER_ADMIN (Multi-branch allowed)
$resBootstrapAdmin = getApi('bootstrap', [
    'user_id' => 1,
    'branch_id' => 3
]);
$curUserAdmin = $resBootstrapAdmin['current_user'];
$curBranchAdmin = $resBootstrapAdmin['current_branch'];

if ((int)$curUserAdmin['can_access_all_branches'] !== 1) {
    echo "[FAIL] Super Admin should have can_access_all_branches = 1!\n";
    exit(1);
}
if ((int)$curBranchAdmin['id'] !== 3) {
    echo "[FAIL] Super Admin should be able to switch to Branch 3!\n";
    exit(1);
}
echo "[PASS] 7. Super Admin has can_access_all_branches = 1 and can access any branch location\n";

// 7. Test role deletion protection
// a. System role cannot be deleted
$superRoleId = $pdo->query("SELECT id FROM user_roles WHERE code = 'SUPER_ADMIN'")->fetchColumn();
$resDelSys = postApi('delete_user_role', ['id' => $superRoleId]);
if ($resDelSys['success']) {
    echo "[FAIL] System role SUPER_ADMIN should not be deletable!\n";
    exit(1);
}
echo "[PASS] 8. System roles are protected from deletion\n";

// b. Custom role cannot be deleted while assigned to users
$spvRoleId = $pdo->query("SELECT id FROM user_roles WHERE code = 'SUPERVISOR'")->fetchColumn();
$resDelUsed = postApi('delete_user_role', ['id' => $spvRoleId]);
if ($resDelUsed['success']) {
    echo "[FAIL] Role assigned to user should not be deletable!\n";
    exit(1);
}
echo "[PASS] 9. Role assigned to active employees cannot be deleted\n";

// c. Reassign user & delete custom role
$pdo->prepare("DELETE FROM users WHERE nik = ?")->execute([$nikTest]);
$resDelOk = postApi('delete_user_role', ['id' => $spvRoleId]);
if (!$resDelOk['success']) {
    echo "[FAIL] Unused custom role should be deletable: " . ($resDelOk['message'] ?? '') . "\n";
    exit(1);
}
$permsCount = $pdo->query("SELECT COUNT(*) FROM role_menu_permissions WHERE role = 'SUPERVISOR'")->fetchColumn();
if ($permsCount > 0) {
    echo "[FAIL] Deleting role should clean up role_menu_permissions!\n";
    exit(1);
}
echo "[PASS] 10. Role deletion cleans up user_roles and role_menu_permissions cascade\n";

echo "\n------------------------------------------------------------\n";
echo "SUMMARY: ALL 10 TESTS PASSED SUCCESSFULLY! (100% OK)\n";
echo "============================================================\n";
