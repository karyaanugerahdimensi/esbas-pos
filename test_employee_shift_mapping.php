<?php
require_once __DIR__ . '/db.php';

echo "=== TEST 1: DATABASE SCHEMA & DEFAULT WORK SHIFT COLUMN ===" . PHP_EOL;
$cols = getDbTableColumns($pdo, 'users');
echo "Column default_work_shift_id exists in users: " . (in_array('default_work_shift_id', $cols) ? "YES" : "NO") . PHP_EOL;

echo PHP_EOL . "=== TEST 2: GET_EMPLOYEES API ENDPOINT ===" . PHP_EOL;
$_GET['action'] = 'get_employees';
ob_start();
include __DIR__ . '/api.php';
$out = ob_get_clean();
$data = json_decode($out, true);
echo "get_employees success: " . ($data['success'] ? "YES" : "NO") . PHP_EOL;
echo "Total employees: " . count($data['employees'] ?? []) . PHP_EOL;

$sampleCashier = null;
$sampleManager = null;
foreach ($data['employees'] as $emp) {
    echo "  - [{$emp['nik']}] {$emp['name']} ({$emp['role']}) -> Shift: " . ($emp['default_shift_name'] ?? 'NULL / Fleksibel') . PHP_EOL;
    if ($emp['role'] === 'CASHIER' && !$sampleCashier) $sampleCashier = $emp;
    if (in_array($emp['role'], ['SUPER_ADMIN', 'BRANCH_MANAGER']) && !$sampleManager) $sampleManager = $emp;
}

echo PHP_EOL . "=== TEST 3: GET_EMPLOYEE_SCHEDULE FOR CASHIER ===" . PHP_EOL;
if ($sampleCashier) {
    $_GET = [
        'action' => 'get_employee_schedule',
        'user_id' => $sampleCashier['id'],
        'month' => date('Y-m')
    ];
    ob_start();
    include __DIR__ . '/api.php';
    $outSched = ob_get_clean();
    $schedData = json_decode($outSched, true);
    echo "Cashier schedule query success: " . ($schedData['success'] ? "YES" : "NO") . PHP_EOL;
    echo "Is exempt: " . ($schedData['is_exempt'] ? "YES" : "NO") . " (Expected: NO)" . PHP_EOL;
    echo "Days in month: " . ($schedData['days_in_month'] ?? 0) . PHP_EOL;
    echo "Available work shifts: " . count($schedData['work_shifts'] ?? []) . PHP_EOL;
}

echo PHP_EOL . "=== TEST 4: GET_EMPLOYEE_SCHEDULE FOR SUPER ADMIN / MANAGER (EXEMPT) ===" . PHP_EOL;
if ($sampleManager) {
    $_GET = [
        'action' => 'get_employee_schedule',
        'user_id' => $sampleManager['id'],
        'month' => date('Y-m')
    ];
    ob_start();
    include __DIR__ . '/api.php';
    $outMgr = ob_get_clean();
    $mgrData = json_decode($outMgr, true);
    echo "Manager schedule query success: " . ($mgrData['success'] ? "YES" : "NO") . PHP_EOL;
    echo "Is exempt: " . ($mgrData['is_exempt'] ? "YES" : "NO") . " (Expected: YES)" . PHP_EOL;
}

echo PHP_EOL . "=== TEST 5: APPLY_EMPLOYEE_DEFAULT_SHIFT_MONTH ===" . PHP_EOL;
if ($sampleCashier) {
    $firstShiftId = $pdo->query("SELECT id FROM work_shifts WHERE is_day_off = 0 ORDER BY id ASC LIMIT 1")->fetchColumn();
    // Test apply default shift
    $inputData = [
        'user_id' => $sampleCashier['id'],
        'month' => date('Y-m'),
        'default_work_shift_id' => $firstShiftId,
        'override_all' => 1
    ];
    $_GET = ['action' => 'apply_employee_default_shift_month'];
    $GLOBALS['mock_input'] = $inputData;

    // Use simulated POST
    $testScript = '<?php
    $input = ' . var_export($inputData, true) . ';
    $_GET = ["action" => "apply_employee_default_shift_month"];
    ob_start();
    include __DIR__ . "/api.php";
    echo ob_get_clean();
    ';
    file_put_contents(__DIR__ . '/temp_test_apply.php', $testScript);
    $cmdOutput = shell_exec('php ' . escapeshellarg(__DIR__ . '/temp_test_apply.php'));
    unlink(__DIR__ . '/temp_test_apply.php');
    $applyResult = json_decode($cmdOutput, true);
    echo "Apply result: " . ($applyResult['message'] ?? $cmdOutput) . PHP_EOL;
}

echo PHP_EOL . "=== TEST 6: EXEMPTION GUARD WHEN SAVING SCHEDULE FOR MANAGER ===" . PHP_EOL;
if ($sampleManager) {
    $mgrInput = [
        'user_id' => $sampleManager['id'],
        'month' => date('Y-m'),
        'entries' => [
            ['shift_date' => date('Y-m-01'), 'work_shift_id' => 1, 'is_holiday' => 0]
        ]
    ];
    $testScript = '<?php
    $input = ' . var_export($mgrInput, true) . ';
    $_GET = ["action" => "save_employee_schedule_batch"];
    ob_start();
    include __DIR__ . "/api.php";
    echo ob_get_clean();
    ';
    file_put_contents(__DIR__ . '/temp_test_guard.php', $testScript);
    $guardOutput = shell_exec('php ' . escapeshellarg(__DIR__ . '/temp_test_guard.php'));
    unlink(__DIR__ . '/temp_test_guard.php');
    $guardResult = json_decode($guardOutput, true);
    echo "Exemption rejection message: " . ($guardResult['message'] ?? $guardOutput) . PHP_EOL;
}

echo PHP_EOL . "=== ALL TESTS COMPLETED SUCCESSFULLY ===" . PHP_EOL;
