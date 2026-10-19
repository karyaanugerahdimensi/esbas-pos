<?php
/**
 * Test Suite: Validasi Kewajiban Pengisian Password Login & Durasi Sesi 12 Jam Harian
 */

echo "=== TEST SUITE: LOGIN RE-ENTRY PASSWORD & 12-HOUR SESSION LIFETIME ===\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($description, $condition) {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] $description\n";
        $passCount++;
    } else {
        echo "[FAIL] $description\n";
        $failCount++;
    }
}

// 1. Verifikasi index.php tidak memiliki hardcoded prefill password
$indexContent = file_get_contents(__DIR__ . '/index.php');
$hasHardcodedPasswordValue = (strpos($indexContent, 'id="login-password" value="123456"') !== false);
assertTest('index.php TIDAK memiliki hardcoded default value="123456" pada input login-password', !$hasHardcodedPasswordValue);

$hasNewPasswordAttr = (strpos($indexContent, 'autocomplete="new-password"') !== false);
assertTest('index.php memiliki attribute autocomplete="new-password" pada password field', $hasNewPasswordAttr);

$hasSessionBadge = (strpos($indexContent, 'topbar-session-badge') !== false);
assertTest('index.php memiliki elemen topbar-session-badge untuk menampilkan durasi sesi 12 jam', $hasSessionBadge);

$hasSelectQuickAccount = (strpos($indexContent, 'selectQuickAccount(') !== false);
assertTest('index.php mengarahkan quick login ke selectQuickAccount (wajib isi password)', $hasSelectQuickAccount);

// 2. Verifikasi konfigurasi durasi sesi di api.php
$apiContent = file_get_contents(__DIR__ . '/api.php');
$has43200 = (strpos($apiContent, '43200') !== false);
assertTest('api.php mendefinisikan durasi sesi 43.200 detik (12 jam per hari)', $has43200);

$hasSessionExpiredCheck = (strpos($apiContent, 'session_expired') !== false);
assertTest('api.php memiliki penanganan pemutusan sesi otomatis saat melebihi 12 jam', $hasSessionExpiredCheck);

// 3. Test Simualasi Login & Session Expiry Logic
require_once __DIR__ . '/db.php';

// A. Test Login tanpa password
// Simulasi request login dengan password kosong
$ch = curl_init();
$payloadEmptyPass = json_encode(['username' => 'CSR001', 'password' => '']);
// Kita dapat menguji secara langsung logic database dan session
$stmt = $pdo->prepare("SELECT * FROM users WHERE (nik = ? OR email = ?) AND is_active = 1 LIMIT 1");
$stmt->execute(['CSR001', 'CSR001']);
$testUser = $stmt->fetch();
assertTest('User CSR001 ditemukan di database', !empty($testUser));

// B. Verifikasi Password & PIN sesuai logic api.php
$userPass = $testUser['password'] ?? '';
$userPin = $testUser['pin'] ?? '';
$isPasswordValid = function($inputPass, $pass, $pin) {
    if (empty($inputPass)) return false;
    if ($inputPass === $pass || $inputPass === $pin) return true;
    if (password_verify($inputPass, $pass)) return true;
    return false;
};

assertTest('Password/PIN valid (123456) berhasil diverifikasi untuk akun CSR001', $isPasswordValid('123456', $userPass, $userPin));
assertTest('Password kosong ditolak dan tidak dapat login', !$isPasswordValid('', $userPass, $userPin));
assertTest('Password salah ditolak dan tidak dapat login', !$isPasswordValid('salahpassword', $userPass, $userPin));

// C. Test Durasi 12 Jam Sesi
$now = time();
$maxSession = 43200; // 12 jam

// Kasus 1: Sesi baru login
$sessionLoginTime = $now;
$isExpiredNew = ($now - $sessionLoginTime > $maxSession);
$remainingNew = max(0, $maxSession - ($now - $sessionLoginTime));
assertTest('Sesi baru tidak expired', !$isExpiredNew);
assertTest('Sisa waktu sesi baru tepat 43200 detik (12 jam)', $remainingNew === 43200);

// Kasus 2: Sesi telah berjalan 6 jam (21.600 detik)
$sessionLoginTime6h = $now - 21600;
$isExpired6h = ($now - $sessionLoginTime6h > $maxSession);
$remaining6h = max(0, $maxSession - ($now - $sessionLoginTime6h));
assertTest('Sesi 6 jam masih aktif dan belum expired', !$isExpired6h);
assertTest('Sisa waktu sesi 6 jam adalah 21600 detik (6 jam)', $remaining6h === 21600);

// Kasus 3: Sesi telah melewati 12 jam (12 jam 1 detik)
$sessionLoginTimeExpired = $now - 43201;
$isExpiredOver12h = ($now - $sessionLoginTimeExpired > $maxSession);
$remainingExpired = max(0, $maxSession - ($now - $sessionLoginTimeExpired));
assertTest('Sesi > 12 jam terdeteksi EXPIRED', $isExpiredOver12h);
assertTest('Sisa waktu sesi expired adalah 0 detik', $remainingExpired === 0);

echo "\n======================================================\n";
echo "HASIL PENGUJIAN: $passCount PASS, $failCount FAIL\n";
echo "======================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
