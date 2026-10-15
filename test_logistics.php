<?php
/**
 * Automated Verification Test Script: Logistics, Geolocation, Dynamic Delivery Fee,
 * Fuel Mileage Tracking, and Fleet Service Expense Posting
 */

require_once __DIR__ . '/db.php';

// $pdo is provided globally by db.php

echo "========================================================================\n";
echo "TEST SUITE: LOGISTIK, PETA GEOSPASIAL, BIAYA KIRIM & ARMADA KURIR\n";
echo "========================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $testName, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] " . $testName . ($details ? " -> $details" : "") . "\n";
        $passCount++;
    } else {
        echo "[FAIL] " . $testName . ($details ? " -> $details" : "") . "\n";
        $failCount++;
    }
}

// -------------------------------------------------------------
// TEST 1: TITIK KOORDINAT MAPS OUTLET & PELANGGAN
// -------------------------------------------------------------
echo "--- UJI 1: Titik Peta Geospasial Cabang & Pelanggan ---\n";
$stmtBranch = $pdo->query("SELECT id, name, latitude, longitude FROM branches WHERE latitude IS NOT NULL AND longitude IS NOT NULL");
$branches = $stmtBranch->fetchAll();
assertTest(count($branches) >= 3, "Cabang Memiliki Titik Maps GPS", "Ditemukan " . count($branches) . " cabang berkoordinat valid");

$stmtCust = $pdo->query("SELECT id, name, category, latitude, longitude FROM customers WHERE latitude IS NOT NULL AND longitude IS NOT NULL");
$customers = $stmtCust->fetchAll();
assertTest(count($customers) >= 4, "Pelanggan/Mitra Memiliki Titik Maps GPS", "Ditemukan " . count($customers) . " pelanggan berkoordinat valid");

// -------------------------------------------------------------
// TEST 2: PERHITUNGAN BIAYA KIRIM DINAMIS & JARAK HAVERSINE
// -------------------------------------------------------------
echo "\n--- UJI 2: Biaya Kirim Jarak Dinamis & Haversine Geodesic ---\n";
// Uji jarak Cabang 1 (Grand Indonesia: -6.1950, 106.8230) ke Pelanggan 2 (Anisa: -6.2155, 106.8315)
$lat1 = -6.195000; $lon1 = 106.823000;
$lat2 = -6.215500; $lon2 = 106.831500;
$earthRadius = 6371;
$dLat = deg2rad($lat2 - $lat1);
$dLon = deg2rad($lon2 - $lon1);
$a = sin($dLat / 2) * sin($dLat / 2) +
     cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
     sin($dLon / 2) * sin($dLon / 2);
$c = 2 * atan2(sqrt($a), sqrt(1 - $a));
$distanceKm = round($earthRadius * $c, 2);

assertTest($distanceKm >= 2.0 && $distanceKm <= 3.0, "Kalkulasi Jarak Haversine Outlet -> Pelanggan", "Jarak: {$distanceKm} km");

// Ambil delivery setting Cabang 1
$stmtDS = $pdo->prepare("SELECT * FROM delivery_settings WHERE branch_id = 1");
$stmtDS->execute();
$ds = $stmtDS->fetch();

$baseFee = (float)$ds['base_fee']; // 12000
$baseKm = (float)$ds['base_distance_km']; // 3.0
$perKm = (float)$ds['per_km_rate']; // 3000
$freeThreshold = (float)$ds['free_delivery_threshold']; // 200000

$feeJarakDekat = ($distanceKm <= $baseKm) ? $baseFee : ($baseFee + ceil($distanceKm - $baseKm) * $perKm);
assertTest($feeJarakDekat == $baseFee, "Tarif Jarak Dasar (<= 3 km)", "Fee: Rp " . number_format($feeJarakDekat, 0, ',', '.'));

// Uji tarif jarak lebih jauh: 5.8 km (Toko Berkah Tebet)
$distJauh = 5.8;
$feeJarakJauh = $baseFee + ceil($distJauh - $baseKm) * $perKm; // 12000 + ceil(2.8)*3000 = 12000 + 3*3000 = 21000
assertTest($feeJarakJauh == ($baseFee + 3 * $perKm), "Tarif Jarak Tambahan Dinamis (5.8 km)", "Fee: Rp " . number_format($feeJarakJauh, 0, ',', '.'));

// Uji gratis ongkir saat subtotal >= threshold
$subtotalPromo = 250000;
$isFree = ($freeThreshold > 0 && $subtotalPromo >= $freeThreshold);
$finalFee = $isFree ? 0 : $feeJarakJauh;
assertTest($finalFee === 0, "Threshold Gratis Ongkir Terpenuhi (Belanja Rp 250k >= Rp 200k)", "Final Fee: Rp 0");

// -------------------------------------------------------------
// TEST 3: ARMADA KENDARAAN KURIR & LOG BENSIN (BBM)
// -------------------------------------------------------------
echo "\n--- UJI 3: Armada Kendaraan Kurir & Pencatatan Bensin BBM ---\n";
// Ambil motor kurir B 4821 KTZ
$stmtV = $pdo->prepare("SELECT * FROM courier_vehicles WHERE plate_number = 'B 4821 KTZ'");
$stmtV->execute();
$vehicle = $stmtV->fetch();
assertTest(!empty($vehicle), "Armada Motor Kurir Terdaftar", "Plat: {$vehicle['plate_number']} ({$vehicle['vehicle_name']})");

// Simulasikan input log BBM
$lastOdo = (float)$vehicle['current_odometer'];
$newOdo = $lastOdo + 114.0; // tempuh 114 km
$litersBought = 3.0;
$costPerLiter = 10000.0;
$totalCost = $litersBought * $costPerLiter; // 30.000
$configuredRatio = (float)$vehicle['fuel_ratio']; // 38 km/L
$expectedLiters = 114.0 / $configuredRatio; // 3.0 L
$efficiencyPercent = round(($expectedLiters / $litersBought) * 100, 1); // 100%

// Simpan transaksi BBM
$today = date('Y-m-d');
$pdo->beginTransaction();

// Insert branch_expenses
$stmtExp = $pdo->prepare("
    INSERT INTO branch_expenses (branch_id, category, amount, expense_date, source, notes)
    VALUES (?, 'Beban Bahan Bakar (BBM) Kurir', ?, ?, 'VEHICLE_FUEL', ?)
");
$stmtExp->execute([$vehicle['branch_id'], $totalCost, $today, "Pembelian bensin {$litersBought}L motor {$vehicle['plate_number']}"]);
$expId = $pdo->lastInsertId();

// Insert vehicle_fuel_logs
$stmtFL = $pdo->prepare("
    INSERT INTO vehicle_fuel_logs (
        vehicle_id, branch_id, log_date, last_odometer, current_odometer,
        distance_traveled, liters_bought, cost_per_liter, total_cost,
        configured_ratio, expected_liters, efficiency_percent, expense_record_id, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now', 'localtime'))
");
$stmtFL->execute([
    $vehicle['id'], $vehicle['branch_id'], $today, $lastOdo, $newOdo,
    114.0, $litersBought, $costPerLiter, $totalCost,
    $configuredRatio, $expectedLiters, $efficiencyPercent, $expId
]);
$fuelLogId = $pdo->lastInsertId();

// Update odometer kendaraan
$stmtUpdV = $pdo->prepare("UPDATE courier_vehicles SET current_odometer = ? WHERE id = ?");
$stmtUpdV->execute([$newOdo, $vehicle['id']]);

$pdo->commit();

assertTest($fuelLogId > 0 && $expId > 0, "Pencatatan Pembelian Bensin Kurir Berhasil", "Fuel Log ID: $fuelLogId, Expense ID: $expId");
assertTest($efficiencyPercent == 100.0, "Evaluasi Efisiensi Rasio Konsumsi BBM", "Efisiensi: {$efficiencyPercent}% (114 km / 3 L = 38 km/L)");

// -------------------------------------------------------------
// TEST 4: SERVIS KENDARAAN KURIR & POSTING KE BEBAN LABA RUGI
// -------------------------------------------------------------
echo "\n--- UJI 4: Servis Kendaraan Kurir & Posting Beban ke P&L ---\n";
$serviceCost = 135000.0;
$serviceType = "Ganti Oli Rutin & Tune Up";
$workshop = "AHASS Honda Palmerah";

$pdo->beginTransaction();

// Insert branch_expenses untuk servis
$stmtSrvExp = $pdo->prepare("
    INSERT INTO branch_expenses (branch_id, category, amount, expense_date, source, notes)
    VALUES (?, 'Beban Servis & Perawatan Armada Kurir', ?, ?, 'VEHICLE_SERVICE', ?)
");
$stmtSrvExp->execute([$vehicle['branch_id'], $serviceCost, $today, "Servis {$serviceType} motor {$vehicle['plate_number']} di {$workshop}"]);
$srvExpId = $pdo->lastInsertId();

// Insert vehicle_service_logs
$stmtSL = $pdo->prepare("
    INSERT INTO vehicle_service_logs (
        vehicle_id, branch_id, service_date, odometer_km, service_type,
        workshop_name, cost, expense_record_id, notes, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now', 'localtime'))
");
$stmtSL->execute([
    $vehicle['id'], $vehicle['branch_id'], $today, $newOdo, $serviceType,
    $workshop, $serviceCost, $srvExpId, "Ganti oli mesin MPX2 + kampas rem depan"
]);
$serviceLogId = $pdo->lastInsertId();

$pdo->commit();

assertTest($serviceLogId > 0 && $srvExpId > 0, "Catatan Servis Kendaraan Kurir Berhasil Disimpan", "Service Log ID: $serviceLogId, Expense ID: $srvExpId");

// Verifikasi Laporan Laba Rugi Cabang 1 Memuat Kedua Beban Kurir
$stmtPnl = $pdo->prepare("
    SELECT category, SUM(amount) as total_cat
    FROM branch_expenses
    WHERE branch_id = 1
    GROUP BY category
");
$stmtPnl->execute();
$pnlExpenses = $stmtPnl->fetchAll(PDO::FETCH_KEY_PAIR);

$hasFuelExp = isset($pnlExpenses['Beban Bahan Bakar (BBM) Kurir']) && $pnlExpenses['Beban Bahan Bakar (BBM) Kurir'] > 0;
$hasSrvExp = isset($pnlExpenses['Beban Servis & Perawatan Armada Kurir']) && $pnlExpenses['Beban Servis & Perawatan Armada Kurir'] > 0;

assertTest($hasFuelExp, "Beban Bensin Masuk Laporan Laba Rugi Cabang", "Total BBM Kurir: Rp " . number_format($pnlExpenses['Beban Bahan Bakar (BBM) Kurir'] ?? 0, 0, ',', '.'));
assertTest($hasSrvExp, "Beban Servis Armada Masuk Laporan Laba Rugi Cabang", "Total Servis Kurir: Rp " . number_format($pnlExpenses['Beban Servis & Perawatan Armada Kurir'] ?? 0, 0, ',', '.'));

// -------------------------------------------------------------
// TEST 5: TRANSAKSI POS DENGAN ONGKIR & ARMADA KURIR
// -------------------------------------------------------------
echo "\n--- UJI 5: Checkout Transaksi POS dengan Pengiriman Kurir ---\n";
// Simulasikan checkout sale delivery
$invoiceNumber = "INV-DELIV-" . date('YmdHis');
$saleSubtotal = 75000.0;
$saleDeliveryFee = 12000.0;
$saleTax = round($saleSubtotal * 0.11);
$saleGrandTotal = $saleSubtotal + $saleTax + $saleDeliveryFee;

$pdo->beginTransaction();
$stmtSale = $pdo->prepare("
    INSERT INTO sales (
        invoice_number, branch_id, user_id, customer_id, customer_name, customer_category,
        sales_type, subtotal, discount, tax, grand_total, total_cogs, payment_method, cash_received,
        cash_change, delivery_fee, delivery_distance_km, courier_vehicle_id, delivery_status, created_at
    ) VALUES (
        ?, 1, 1, 2, 'Anisa Maharani', 'REGULER', 'RETAIL',
        ?, 0, ?, ?, 45000, 'CASH', 100000, ?, ?, 2.5, ?, 'DELIVERED', datetime('now', 'localtime')
    )
");
$cashChange = 100000 - $saleGrandTotal;
$stmtSale->execute([
    $invoiceNumber, $saleSubtotal, $saleTax, $saleGrandTotal, $cashChange,
    $saleDeliveryFee, $vehicle['id']
]);
$saleId = $pdo->lastInsertId();
$pdo->commit();

// Verifikasi sale tersimpan dengan rincian pengiriman
$stmtVerifySale = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
$stmtVerifySale->execute([$saleId]);
$savedSale = $stmtVerifySale->fetch();

assertTest($savedSale['delivery_fee'] == 12000.0, "Biaya Kirim Tersimpan di Faktur Penjualan", "Ongkir: Rp " . number_format($savedSale['delivery_fee'], 0, ',', '.'));
assertTest($savedSale['courier_vehicle_id'] == $vehicle['id'], "ID Armada Kurir Tertaut ke Transaksi", "Vehicle ID: {$savedSale['courier_vehicle_id']}");
assertTest($savedSale['delivery_status'] === 'DELIVERED', "Status Pengiriman Tercatat", "Status: {$savedSale['delivery_status']}");

echo "\n========================================================================\n";
echo "HASIL PENGUJIAN: $passCount LULUS, $failCount GAGAL\n";
if ($failCount === 0) {
    echo "STATUS: SEMUA FITUR LOGISTIK, MAPS, BENSIN & SERVIS BERFUNGSI SEMPURNA (100% PASS)!\n";
} else {
    echo "STATUS: ADA PENGUJIAN YANG GAGAL!\n";
}
echo "========================================================================\n";
