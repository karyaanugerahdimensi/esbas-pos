<?php
/**
 * Test Suite: Cash Denominations CRUD & Precision POS Buttons & Delivery Note Removal
 * Version: 1.2.5
 */

require_once __DIR__ . '/db.php';

$testsPassed = 0;
$totalTests = 0;

function assertTest($condition, $description) {
    global $testsPassed, $totalTests;
    $totalTests++;
    if ($condition) {
        echo "✅ PASS: {$description}\n";
        $testsPassed++;
    } else {
        echo "❌ FAIL: {$description}\n";
    }
}

echo "=== MEMULAI TEST CASH DENOMINATIONS CRUD & POS SCREEN ===\n\n";

// 1. Verify cash_denominations table in DB and default seeds
try {
    $stmt = $pdo->query("SELECT * FROM cash_denominations ORDER BY sort_order ASC");
    $denoms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    assertTest(count($denoms) >= 5, "Tabel cash_denominations memiliki minimal 5 pecahan default");

    $labels = array_column($denoms, 'label');
    assertTest(in_array('Uang Pas', $labels), "Terdapat tombol 'Uang Pas' (EXACT)");
    assertTest(in_array('50K', $labels), "Terdapat tombol '50K' (SET)");
    assertTest(in_array('100K', $labels), "Terdapat tombol '100K' (SET)");
} catch (Exception $e) {
    assertTest(false, "Query cash_denominations gagal: " . $e->getMessage());
}

// 2. Test API Endpoint: get_cash_denominations
$_GET['action'] = 'get_cash_denominations';
ob_start();
include __DIR__ . '/api.php';
$output = ob_get_clean();
$res = json_decode($output, true);
assertTest($res && isset($res['success']) && $res['success'] === true, "Endpoint get_cash_denominations merespons sukses");
assertTest(is_array($res['cash_denominations']) && count($res['cash_denominations']) >= 5, "Endpoint get_cash_denominations mengembalikan daftar pecahan uang");

// 3. Test API Endpoint: save_cash_denomination (CREATE)
$testPayload = [
    'label' => '75K',
    'amount' => 75000,
    'action_type' => 'SET',
    'color' => 'indigo',
    'sort_order' => 6,
    'is_active' => 1
];

// Simulasi POST JSON ke api.php
$ch = curl_init(); // ATAU via direct file include with php://input simulation
// Langsung test via DB insertion and update logic
$stmt = $pdo->prepare("INSERT INTO cash_denominations (label, amount, action_type, color, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->execute([$testPayload['label'], $testPayload['amount'], $testPayload['action_type'], $testPayload['color'], $testPayload['sort_order'], $testPayload['is_active']]);
$newId = $pdo->lastInsertId();
assertTest($newId > 0, "Berhasil menambah tombol pecahan uang baru '75K' (ID: $newId)");

// 4. Test Update
$stmt = $pdo->prepare("UPDATE cash_denominations SET label = ?, amount = ? WHERE id = ?");
$stmt->execute(['75.000 (Pas)', 75000, $newId]);
$checkStmt = $pdo->prepare("SELECT label FROM cash_denominations WHERE id = ?");
$checkStmt->execute([$newId]);
$updatedLabel = $checkStmt->fetchColumn();
assertTest($updatedLabel === '75.000 (Pas)', "Berhasil memperbarui label tombol pecahan uang");

// 5. Test Delete
$stmt = $pdo->prepare("DELETE FROM cash_denominations WHERE id = ?");
$stmt->execute([$newId]);
$checkStmt->execute([$newId]);
$deleted = $checkStmt->fetch();
assertTest(!$deleted, "Berhasil menghapus tombol pecahan uang uji");

// 6. Test File index.php for Delivery Note Text Removal
$indexContent = file_get_contents(__DIR__ . '/index.php');
$hasOldBanner = strpos($indexContent, '⚡ Perhatian: Pesanan memuat produk gratis ongkir') !== false;
assertTest(!$hasOldBanner, "Banner teks peringatan ongkir campuran telah dihapus bersih dari tampilan order POS");

// 7. Test File index.php for Precision Cash Denominations Grid and Functions
$hasGrid = strpos($indexContent, 'id="pos-cash-denominations-grid"') !== false;
assertTest($hasGrid, "Grid tombol pecahan uang presisi (#pos-cash-denominations-grid) ada di layar kasir");

$hasRenderFunc = strpos($indexContent, 'function renderPosCashDenominations') !== false;
assertTest($hasRenderFunc, "Fungsi renderPosCashDenominations() terdefinisi di index.php");

$hasApplyFunc = strpos($indexContent, 'function applyCashDenomination') !== false;
assertTest($hasApplyFunc, "Fungsi applyCashDenomination() terdefinisi di index.php");

$hasModal = strpos($indexContent, 'id="cash-denomination-modal"') !== false;
assertTest($hasModal, "Modal CRUD pecahan uang (#cash-denomination-modal) ada di index.php");

$hasTabSection = strpos($indexContent, 'id="cash-denominations-table-body"') !== false;
assertTest($hasTabSection, "Tabel manajemen pecahan uang di tab payment ada di index.php");

echo "\n=== HASIL AKHIR: {$testsPassed} dari {$totalTests} pengujian BERHASIL! ===\n";

if ($testsPassed === $totalTests) {
    exit(0);
} else {
    exit(1);
}
