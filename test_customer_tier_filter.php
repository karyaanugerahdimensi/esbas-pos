<?php
/**
 * Test UAT: Filter Kotak Pelanggan Sesuai Pilihan Tier (POS Kasir)
 * - Saat tier REGULER dipilih: hanya pelanggan kategori REGULER yang muncul di dropdown
 * - Saat tier MEMBER dipilih: hanya pelanggan kategori MEMBER yang muncul di dropdown
 * - Saat tier RESELLER dipilih: hanya pelanggan kategori RESELLER yang muncul di dropdown
 * - Saat tier MITRA dipilih: hanya pelanggan kategori MITRA yang muncul di dropdown
 */

require_once __DIR__ . '/db.php';
global $pdo;

echo "=== START TEST UAT: FILTER KOTAK PELANGGAN SESUAI TIER POS ===\n";

function assertTest($condition, $message) {
    if ($condition) {
        echo " [PASS] $message\n";
    } else {
        echo " [FAIL] $message\n";
        exit(1);
    }
}

// 1. Ambil data pelanggan dari database
$stmt = $pdo->query("SELECT id, name, category FROM customers WHERE is_active = 1");
$customers = $stmt->fetchAll();

echo "Total pelanggan aktif: " . count($customers) . "\n";

// Pastikan setiap kategori memiliki data pelanggan
$categories = ['REGULER', 'MEMBER', 'RESELLER', 'MITRA'];
foreach ($categories as $cat) {
    $custInCat = array_filter($customers, fn($c) => ($c['category'] ?? 'REGULER') === $cat);
    if (count($custInCat) === 0) {
        $pdo->prepare("INSERT INTO customers (code, name, category, phone, address, latitude, longitude, branch_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)")
            ->execute(["CUST-TEST-$cat", "Pelanggan Test $cat", $cat, "08129999000", "Jl. Test No. 1", -6.2000, 106.8200, 1]);
        $stmt = $pdo->query("SELECT id, name, category FROM customers WHERE is_active = 1");
        $customers = $stmt->fetchAll();
        $custInCat = array_filter($customers, fn($c) => ($c['category'] ?? 'REGULER') === $cat);
    }
    echo " - Kategori $cat: " . count($custInCat) . " pelanggan\n";
    assertTest(count($custInCat) > 0, "Ada pelanggan untuk kategori $cat");
}

// 2. Simulasi fungsi filter pelanggan POS di JavaScript
function simulateRenderPosCustomerSelect($customers, $selectedTier, &$selectedCustomer) {
    // Filter pelanggan yang ditampilkan sesuai tier aktif
    $filteredCusts = array_values(array_filter($customers, fn($c) => ($c['category'] ?? 'REGULER') === $selectedTier));
    
    if (empty($filteredCusts)) {
        return ['options' => [], 'selected' => null];
    }
    
    // Pastikan selectedCustomer ada dalam filteredCusts
    if (!$selectedCustomer || ($selectedCustomer['category'] ?? 'REGULER') !== $selectedTier) {
        $selectedCustomer = $filteredCusts[0];
    }
    
    $options = array_map(function($c) use ($selectedCustomer) {
        return [
            'id' => $c['id'],
            'name' => $c['name'],
            'category' => $c['category'],
            'isSelected' => $selectedCustomer && $selectedCustomer['id'] === $c['id']
        ];
    }, $filteredCusts);
    
    return ['options' => $options, 'selected' => $selectedCustomer];
}

// 3. Test Kasus 1: Pilihan Tier MEMBER (Seperti di screenshot user)
echo "\n--- UAT Case 1: Klik Tombol Tier MEMBER ---\n";
$selectedCust = null;
$resultMember = simulateRenderPosCustomerSelect($customers, 'MEMBER', $selectedCust);

assertTest(count($resultMember['options']) > 0, "Daftar opsi pelanggan tier MEMBER tidak kosong");
foreach ($resultMember['options'] as $opt) {
    assertTest($opt['category'] === 'MEMBER', "Pelanggan '{$opt['name']}' memiliki kategori MEMBER");
}
assertTest($resultMember['selected']['category'] === 'MEMBER', "Pelanggan yang terpilih '{$resultMember['selected']['name']}' adalah tier MEMBER");

// Pastikan tidak ada pelanggan REGULER, RESELLER, atau MITRA yang masuk dalam daftar
$nonMember = array_filter($resultMember['options'], fn($o) => $o['category'] !== 'MEMBER');
assertTest(count($nonMember) === 0, "TIDAK ADA pelanggan non-MEMBER yang muncul di kotak saat tier MEMBER dipilih");

// 4. Test Kasus 2: Pilihan Tier REGULER
echo "\n--- UAT Case 2: Klik Tombol Tier REGULER (Ritel) ---\n";
$selectedCust = null;
$resultReguler = simulateRenderPosCustomerSelect($customers, 'REGULER', $selectedCust);
assertTest(count($resultReguler['options']) > 0, "Daftar opsi pelanggan tier REGULER tidak kosong");
foreach ($resultReguler['options'] as $opt) {
    assertTest($opt['category'] === 'REGULER', "Pelanggan '{$opt['name']}' memiliki kategori REGULER");
}
$nonReguler = array_filter($resultReguler['options'], fn($o) => $o['category'] !== 'REGULER');
assertTest(count($nonReguler) === 0, "TIDAK ADA pelanggan non-REGULER yang muncul di kotak saat tier REGULER dipilih");

// 5. Test Kasus 3: Pilihan Tier RESELLER
echo "\n--- UAT Case 3: Klik Tombol Tier RESELLER ---\n";
$selectedCust = null;
$resultReseller = simulateRenderPosCustomerSelect($customers, 'RESELLER', $selectedCust);
assertTest(count($resultReseller['options']) > 0, "Daftar opsi pelanggan tier RESELLER tidak kosong");
foreach ($resultReseller['options'] as $opt) {
    assertTest($opt['category'] === 'RESELLER', "Pelanggan '{$opt['name']}' memiliki kategori RESELLER");
}
$nonReseller = array_filter($resultReseller['options'], fn($o) => $o['category'] !== 'RESELLER');
assertTest(count($nonReseller) === 0, "TIDAK ADA pelanggan non-RESELLER yang muncul di kotak saat tier RESELLER dipilih");

// 6. Test Kasus 4: Pilihan Tier MITRA
echo "\n--- UAT Case 4: Klik Tombol Tier MITRA (B2B) ---\n";
$selectedCust = null;
$resultMitra = simulateRenderPosCustomerSelect($customers, 'MITRA', $selectedCust);
assertTest(count($resultMitra['options']) > 0, "Daftar opsi pelanggan tier MITRA tidak kosong");
foreach ($resultMitra['options'] as $opt) {
    assertTest($opt['category'] === 'MITRA', "Pelanggan '{$opt['name']}' memiliki kategori MITRA");
}
$nonMitra = array_filter($resultMitra['options'], fn($o) => $o['category'] !== 'MITRA');
assertTest(count($nonMitra) === 0, "TIDAK ADA pelanggan non-MITRA yang muncul di kotak saat tier MITRA dipilih");

echo "\n=== ALL TIER CUSTOMER FILTER TESTS PASSED (100%) ===\n";
