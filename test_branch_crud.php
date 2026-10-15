<?php
/**
 * Test Suite: CRUD Cabang / Outlet (Branches)
 * Menguji:
 * 1. READ: Membaca daftar cabang via api.php?action=get_branches
 * 2. CREATE: Menambah cabang baru (kode unik, nama, alamat, koordinat maps, radius)
 *    - Verifikasi stok default otomatis diinisialisasi di branch_stocks
 *    - Verifikasi delivery_settings otomatis diinisialisasi
 * 3. UPDATE: Mengubah data cabang (nama, alamat, koordinat, radius, status)
 * 4. VALIDASI: Menolak duplikasi kode cabang & menolak hapus cabang Head Office (ID 1)
 * 5. DELETE: Menghapus cabang uji coba & membersihkan data relasi
 */

require_once __DIR__ . '/db.php';

function makeRequest($url, $method = 'GET', $data = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return json_decode($response, true);
}

$baseUrl = 'http://localhost:8000/api.php';

echo "====================================================================\n";
echo "TEST SUITE: CRUD CABANG / OUTLET (BRANCHES)\n";
echo "====================================================================\n\n";

// --- TEST 1: READ BRANCHES ---
echo "[TEST 1] READ: Mengambil daftar cabang via api.php?action=get_branches...\n";
$readRes = makeRequest($baseUrl . '?action=get_branches');
if (!isset($readRes['success']) || !$readRes['success']) {
    die("FAILED: Gagal membaca get_branches: " . json_encode($readRes) . "\n");
}
$initCount = count($readRes['branches']);
echo " -> Ditemukan {$initCount} cabang terdaftar.\n";
foreach ($readRes['branches'] as $b) {
    echo "    - [{$b['code']}] {$b['name']} | Koordinat: ({$b['latitude']}, {$b['longitude']}) | Staf: {$b['total_staff']} | Produk: {$b['total_products']}\n";
}
echo " -> [PASSED] Read Branches OK!\n\n";

// --- TEST 2: CREATE NEW BRANCH ---
echo "[TEST 2] CREATE: Menambahkan Cabang Baru 'Cabang Medan (Sun Plaza)'...\n";
$newBranchCode = 'MDN-01';
$createPayload = [
    'code' => $newBranchCode,
    'name' => 'Cabang Medan (Sun Plaza)',
    'address' => 'Jl. KH. Zainul Arifin No. 7, Medan Petisah, Kota Medan',
    'phone' => '061-4501234',
    'latitude' => 3.5852,
    'longitude' => 98.6710,
    'radius_meters' => 120,
    'is_active' => 1
];
$createRes = makeRequest($baseUrl . '?action=save_branch', 'POST', $createPayload);
echo " -> Response create: " . json_encode($createRes) . "\n";
if (!isset($createRes['success']) || !$createRes['success']) {
    die("FAILED: Gagal membuat cabang baru!\n");
}
$createdBranchId = $createRes['branch_id'];
echo " -> ID Cabang Baru: {$createdBranchId}\n";

// Verifikasi auto-init stok produk untuk cabang baru
$stockCount = $pdo->query("SELECT COUNT(*) FROM branch_stocks WHERE branch_id = {$createdBranchId}")->fetchColumn();
echo " -> Verifikasi Inisialisasi Stok: {$stockCount} produk otomatis terisi di branch_stocks cabang ini.\n";

// Verifikasi auto-init delivery_settings
$delivRow = $pdo->query("SELECT * FROM delivery_settings WHERE branch_id = {$createdBranchId}")->fetch(PDO::FETCH_ASSOC);
echo " -> Verifikasi Ongkir Dinamis: Base fee Rp " . number_format($delivRow['base_fee'] ?? 0) . " / {$delivRow['base_distance_km']} km.\n";

if ($stockCount > 0 && !empty($delivRow)) {
    echo " -> [PASSED] Create Cabang & Auto Inisialisasi SUKSES 100%!\n\n";
} else {
    die("FAILED: Stok produk atau setting ongkir tidak terinisialisasi otomatis!\n");
}

// --- TEST 3: VALIDASI KODE DUPLIKAT ---
echo "[TEST 3] VALIDASI: Mencoba membuat cabang dengan kode duplikat '{$newBranchCode}'...\n";
$dupRes = makeRequest($baseUrl . '?action=save_branch', 'POST', $createPayload);
echo " -> Response duplikat: " . json_encode($dupRes) . "\n";
if (!$dupRes['success'] && strpos($dupRes['message'], 'sudah digunakan') !== false) {
    echo " -> [PASSED] Validasi duplikasi kode cabang terbukti aman dan ditolak sistem!\n\n";
} else {
    die("FAILED: Sistem meloloskan kode cabang duplikat!\n");
}

// --- TEST 4: UPDATE BRANCH ---
echo "[TEST 4] UPDATE: Mengedit Cabang Medan (ubah nama, radius, nomor telepon)...\n";
$updatePayload = [
    'id' => $createdBranchId,
    'code' => $newBranchCode,
    'name' => 'Cabang Medan Centre Point Mall',
    'address' => 'Jl. Timor Blok H No. 1, Medan Timur, Kota Medan',
    'phone' => '061-8051999',
    'latitude' => 3.5925,
    'longitude' => 98.6812,
    'radius_meters' => 150,
    'is_active' => 1
];
$updateRes = makeRequest($baseUrl . '?action=save_branch', 'POST', $updatePayload);
echo " -> Response update: " . json_encode($updateRes) . "\n";
if (!isset($updateRes['success']) || !$updateRes['success']) {
    die("FAILED: Gagal mengupdate data cabang!\n");
}

// Verifikasi data di DB
$checkBranch = $pdo->query("SELECT * FROM branches WHERE id = {$createdBranchId}")->fetch(PDO::FETCH_ASSOC);
if ($checkBranch['name'] === 'Cabang Medan Centre Point Mall' && (int)$checkBranch['radius_meters'] === 150) {
    echo " -> Verifikasi DB: Nama menjadi '{$checkBranch['name']}', Radius {$checkBranch['radius_meters']}m.\n";
    echo " -> [PASSED] Update Cabang SUKSES!\n\n";
} else {
    die("FAILED: Data update di database tidak cocok!\n");
}

// --- TEST 5: VALIDASI KEAMANAN HEAD OFFICE (ID 1) ---
echo "[TEST 5] VALIDASI: Mencoba menghapus Cabang Utama / Head Office (ID 1)...\n";
$deleteMainRes = makeRequest($baseUrl . '?action=delete_branch', 'POST', ['id' => 1]);
echo " -> Response delete ID 1: " . json_encode($deleteMainRes) . "\n";
if (!$deleteMainRes['success'] && strpos($deleteMainRes['message'], 'tidak dapat dihapus') !== false) {
    echo " -> [PASSED] Cabang Utama (ID 1) terlindungi dari penghapusan!\n\n";
} else {
    die("FAILED: Cabang utama tidak terproteksi!\n");
}

// --- TEST 6: DELETE CREATED BRANCH ---
echo "[TEST 6] DELETE: Menghapus cabang uji coba (ID {$createdBranchId})...\n";
$delRes = makeRequest($baseUrl . '?action=delete_branch', 'POST', ['id' => $createdBranchId]);
echo " -> Response delete: " . json_encode($delRes) . "\n";
if (!isset($delRes['success']) || !$delRes['success']) {
    die("FAILED: Gagal menghapus cabang uji coba!\n");
}

// Verifikasi DB
$deletedRow = $pdo->query("SELECT * FROM branches WHERE id = {$createdBranchId}")->fetch();
$delStock = $pdo->query("SELECT COUNT(*) FROM branch_stocks WHERE branch_id = {$createdBranchId}")->fetchColumn();
if (!$deletedRow && $delStock == 0) {
    echo " -> Verifikasi DB: Cabang dan seluruh stok inisialisasinya berhasil dibersihkan.\n";
    echo " -> [PASSED] Delete Cabang SUKSES!\n\n";
} else {
    die("FAILED: Data cabang masih tersisa di database!\n");
}

echo "====================================================================\n";
echo "SEMUA TEST SUITE CRUD CABANG / OUTLET 100% SUKSES DAN VALID!\n";
echo "====================================================================\n";
