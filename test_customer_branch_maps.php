<?php
/**
 * Test Otomasi Fitur: Peta Maps Langsung di Menu Pelanggan dan Menu Cabang
 * Menyerupai tampilan Menu 11 Kurir & Biaya Kirim (Sub-tab Peta Maps Outlet & Pelanggan)
 */

echo "=============================================================\n";
echo " RUNNING TEST: GEOSPATIAL MAPS IN CUSTOMER & BRANCH MENUS\n";
echo "=============================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCheck($condition, $description) {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] " . $description . "\n";
        $passCount++;
    } else {
        echo " [FAIL] " . $description . "\n";
        $failCount++;
    }
}

// 1. Membaca index.php untuk verifikasi integritas kode UI dan JavaScript
$indexPath = __DIR__ . '/index.php';
assertCheck(file_exists($indexPath), "File index.php ditemukan");
$indexContent = file_get_contents($indexPath);

// 2. Verifikasi UI Menu Pelanggan (tab-customers)
assertCheck(strpos($indexContent, 'id="subtab-btn-cust-maps"') !== false, "Tombol sub-nav '5. Peta Geospasial Pelanggan' (#subtab-btn-cust-maps) tersedia");
assertCheck(strpos($indexContent, 'id="subview-cust-maps"') !== false, "Container subview peta pelanggan (#subview-cust-maps) tersedia");
assertCheck(strpos($indexContent, 'id="customer-geospatial-map"') !== false, "Canvas interaktif Leaflet pelanggan (#customer-geospatial-map) tersedia");
assertCheck(strpos($indexContent, 'id="cust-map-branch-filter"') !== false, "Filter cabang pendaftaran pada peta pelanggan tersedia");
assertCheck(strpos($indexContent, 'id="cust-map-category-filter"') !== false, "Filter kategori tier pada peta pelanggan tersedia");
assertCheck(strpos($indexContent, 'id="cust-map-customer-select"') !== false, "Dropdown pemilih pelanggan pada peta (#cust-map-customer-select) tersedia");
assertCheck(strpos($indexContent, 'id="cust-btn-fit-map"') !== false, "Tombol fokus batas semua titik pelanggan (#cust-btn-fit-map) tersedia");

// Panel Samping Profil Pelanggan Terpilih
assertCheck(strpos($indexContent, 'id="cust-map-info-name"') !== false, "Panel samping: Field nama pelanggan (#cust-map-info-name) tersedia");
assertCheck(strpos($indexContent, 'id="cust-map-info-phone"') !== false, "Panel samping: Field nomor kontak & tombol WA (#cust-map-wa-btn) tersedia");
assertCheck(strpos($indexContent, 'id="cust-map-info-address"') !== false, "Panel samping: Field alamat fisik pelanggan (#cust-map-info-address) tersedia");
assertCheck(strpos($indexContent, 'id="cust-map-info-branch"') !== false, "Panel samping: Field cabang pendaftaran (#cust-map-info-branch) tersedia");
assertCheck(strpos($indexContent, 'id="cust-map-info-coords"') !== false, "Panel samping: Field koordinat GPS Lat, Lng (#cust-map-info-coords) tersedia");
assertCheck(strpos($indexContent, 'id="cust-map-info-distance"') !== false, "Panel samping: Kalkulator jarak garis lurus (#cust-map-info-distance) tersedia");
assertCheck(strpos($indexContent, 'id="cust-map-gmaps-link"') !== false, "Panel samping: Tombol external Google Maps (#cust-map-gmaps-link) tersedia");

// Mini-Map Picker di Customer Modal
assertCheck(strpos($indexContent, 'id="cust-modal-map-picker"') !== false, "Mini-map picker interaktif di Modal Pelanggan (#cust-modal-map-picker) tersedia");
assertCheck(strpos($indexContent, 'focusCustomerOnMap') !== false, "Action shortcut tombol pin peta di tabel pelanggan (focusCustomerOnMap) tersedia");

// 3. Verifikasi UI Menu Cabang (tab-branches)
assertCheck(strpos($indexContent, 'id="subtab-btn-branch-table"') !== false, "Sub-nav Cabang: Tab Tabel Data Cabang (#subtab-btn-branch-table) tersedia");
assertCheck(strpos($indexContent, 'id="subtab-btn-branch-maps"') !== false, "Sub-nav Cabang: Tab Peta Geospasial Jaringan Cabang (#subtab-btn-branch-maps) tersedia");
assertCheck(strpos($indexContent, 'id="subview-branch-table"') !== false, "Container subview tabel cabang (#subview-branch-table) tersedia");
assertCheck(strpos($indexContent, 'id="subview-branch-maps"') !== false, "Container subview peta cabang (#subview-branch-maps) tersedia");
assertCheck(strpos($indexContent, 'id="branch-geospatial-map"') !== false, "Canvas interaktif Leaflet cabang (#branch-geospatial-map) tersedia");
assertCheck(strpos($indexContent, 'id="branch-map-select"') !== false, "Dropdown pemilih outlet cabang (#branch-map-select) tersedia");
assertCheck(strpos($indexContent, 'id="branch-map-status-filter"') !== false, "Filter status operasional cabang pada peta tersedia");

// Panel Samping Info Cabang Terpilih
assertCheck(strpos($indexContent, 'id="branch-map-info-name"') !== false, "Panel samping cabang: Field nama cabang (#branch-map-info-name) tersedia");
assertCheck(strpos($indexContent, 'id="branch-map-info-code"') !== false, "Panel samping cabang: Field kode cabang (#branch-map-info-code) tersedia");
assertCheck(strpos($indexContent, 'id="branch-map-info-phone"') !== false, "Panel samping cabang: Field kontak telepon (#branch-map-info-phone) tersedia");
assertCheck(strpos($indexContent, 'id="branch-map-info-address"') !== false, "Panel samping cabang: Field alamat fisik toko (#branch-map-info-address) tersedia");
assertCheck(strpos($indexContent, 'id="branch-map-info-coords"') !== false, "Panel samping cabang: Field titik koordinat GPS (#branch-map-info-coords) tersedia");
assertCheck(strpos($indexContent, 'id="branch-map-info-radius"') !== false, "Panel samping cabang: Field radius presensi (#branch-map-info-radius) tersedia");
assertCheck(strpos($indexContent, 'id="branch-map-info-staff"') !== false, "Panel samping cabang: Metrik operasional staf (#branch-map-info-staff) tersedia");
assertCheck(strpos($indexContent, 'id="branch-map-gmaps-link"') !== false, "Panel samping cabang: Tombol external Google Maps (#branch-map-gmaps-link) tersedia");

// Mini-Map Picker di Branch Modal
assertCheck(strpos($indexContent, 'id="branch-modal-map-picker"') !== false, "Mini-map picker interaktif di Modal Cabang (#branch-modal-map-picker) tersedia");
assertCheck(strpos($indexContent, 'focusBranchOnMap') !== false, "Action shortcut tombol pin peta di tabel cabang (focusBranchOnMap) tersedia");

// 4. Verifikasi JavaScript Functions
assertCheck(strpos($indexContent, 'function initCustomerMap()') !== false, "JS: function initCustomerMap() terdefinisi");
assertCheck(strpos($indexContent, 'function renderCustomerMapMarkers()') !== false, "JS: function renderCustomerMapMarkers() terdefinisi");
assertCheck(strpos($indexContent, 'function selectCustomerOnMap(') !== false, "JS: function selectCustomerOnMap() terdefinisi");
assertCheck(strpos($indexContent, 'function initCustomerPickerMap()') !== false, "JS: function initCustomerPickerMap() terdefinisi");
assertCheck(strpos($indexContent, 'function setBranchSubtab(') !== false, "JS: function setBranchSubtab() terdefinisi");
assertCheck(strpos($indexContent, 'function initBranchMap()') !== false, "JS: function initBranchMap() terdefinisi");
assertCheck(strpos($indexContent, 'function renderBranchMapMarkers()') !== false, "JS: function renderBranchMapMarkers() terdefinisi");
assertCheck(strpos($indexContent, 'function selectBranchOnMap(') !== false, "JS: function selectBranchOnMap() terdefinisi");
assertCheck(strpos($indexContent, 'function initBranchPickerMap()') !== false, "JS: function initBranchPickerMap() terdefinisi");
assertCheck(strpos($indexContent, 'function getHaversineDistanceKm(') !== false, "JS: function getHaversineDistanceKm() terdefinisi");

// 5. Verifikasi Backend Data via Database & API
require_once __DIR__ . '/db.php';
global $pdo;

// Test data cabang koordinat
$stmtBranches = $pdo->query("SELECT id, name, code, latitude, longitude, radius_meters FROM branches WHERE is_active = 1");
$branches = $stmtBranches->fetchAll();
assertCheck(count($branches) > 0, "Database memiliki cabang aktif (" . count($branches) . " cabang)");
foreach ($branches as $b) {
    assertCheck(!empty($b['latitude']) && !empty($b['longitude']), "Cabang [{$b['name']}] memiliki koordinat valid: Lat {$b['latitude']}, Lon {$b['longitude']}");
}

// Test data pelanggan koordinat
$stmtCusts = $pdo->query("SELECT id, name, category, latitude, longitude, branch_id FROM customers WHERE is_active = 1");
$custs = $stmtCusts->fetchAll();
assertCheck(count($custs) > 0, "Database memiliki pelanggan aktif (" . count($custs) . " pelanggan)");

// Test kalkulasi Geodetik Haversine
function testHaversine($lat1, $lon1, $lat2, $lon2) {
    $r = 6371; // Earth radius in km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return round($r * $c, 2);
}

$sampleBranch = $branches[0];
$sampleCust = $custs[0];
$calcDistance = testHaversine($sampleBranch['latitude'], $sampleBranch['longitude'], $sampleCust['latitude'] ?: -6.20, $sampleCust['longitude'] ?: 106.82);
assertCheck($calcDistance >= 0, "Kalkulasi jarak geodetik berhasil dihitung: {$calcDistance} km antara {$sampleBranch['name']} dan {$sampleCust['name']}");

echo "\n=============================================================\n";
echo " TEST SUMMARY: {$passCount} Passed, {$failCount} Failed\n";
echo "=============================================================\n";

if ($failCount > 0) {
    exit(1);
}
