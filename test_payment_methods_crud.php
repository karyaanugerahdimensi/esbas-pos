<?php
/**
 * Test Suite: CRUD Metode Pembayaran (Payment Methods)
 * Menguji API get_payment_methods, save_payment_method (Create & Update), dan delete_payment_method
 */

$baseUrl = 'http://localhost:8000';

echo "=== TEST SUITE: CRUD METODE PEMBAYARAN (PAYMENT METHODS) ===\n\n";

function requestApi($url, $method = 'GET', $data = null) {
    $opts = [
        'http' => [
            'method' => $method,
            'header' => 'Content-Type: application/json',
            'ignore_errors' => true
        ]
    ];
    if ($data !== null) {
        $opts['http']['content'] = json_encode($data);
    }
    $context = stream_context_create($opts);
    $res = @file_get_contents($url, false, $context);
    return json_decode($res, true);
}

// 1. Test GET Initial Payment Methods
echo "1. Menguji GET api.php?action=get_payment_methods...\n";
$resGet = requestApi("$baseUrl/api.php?action=get_payment_methods");

if ($resGet && !empty($resGet['success'])) {
    echo "✓ [PASS] Berhasil mengambil daftar metode pembayaran. Total: " . count($resGet['payment_methods']) . " metode.\n";
    foreach ($resGet['payment_methods'] as $pm) {
        echo "   - [{$pm['code']}] {$pm['name']} (Kategori: {$pm['category']}, Tunai: {$pm['is_cash']}, Aktif: {$pm['is_active']})\n";
    }
} else {
    echo "✗ [FAIL] Gagal memanggil get_payment_methods: " . json_encode($resGet) . "\n";
    exit(1);
}

echo "\n--------------------------------------------------\n";

// 2. Test CREATE Payment Method Baru
echo "2. Menguji CREATE save_payment_method (GOPAY)...\n";
$newPayload = [
    'code' => 'GOPAY',
    'name' => 'GoPay / QRIS Merchant',
    'category' => 'E-WALLET',
    'account_number' => '081234567890',
    'account_holder' => 'ESBAS TOKO JAKARTA',
    'description' => 'Pembayaran instan via saldo GoPay & QRIS Gojek',
    'is_cash' => 0,
    'is_active' => 1,
    'sort_order' => 5
];

$resCreate = requestApi("$baseUrl/api.php?action=save_payment_method", 'POST', $newPayload);

if ($resCreate && !empty($resCreate['success'])) {
    $newId = $resCreate['id'] ?? null;
    echo "✓ [PASS] Sukses tambah metode pembayaran baru (ID: $newId): {$resCreate['message']}\n";
} else {
    echo "✗ [FAIL] Gagal tambah metode pembayaran: " . json_encode($resCreate) . "\n";
    exit(1);
}

echo "\n--------------------------------------------------\n";

// 3. Test UPDATE Payment Method
echo "3. Menguji UPDATE save_payment_method (Ubah nama dan nomor akun GOPAY)...\n";
$updatePayload = [
    'id' => $newId,
    'code' => 'GOPAY',
    'name' => 'GoPay Super QRIS (Updated)',
    'category' => 'E-WALLET',
    'account_number' => '08999999999',
    'account_holder' => 'PT ESBAS TEKNOLOGI',
    'description' => 'QRIS GoPay MDR 0.7%',
    'is_cash' => 0,
    'is_active' => 1,
    'sort_order' => 5
];

$resUpdate = requestApi("$baseUrl/api.php?action=save_payment_method", 'POST', $updatePayload);

if ($resUpdate && !empty($resUpdate['success'])) {
    echo "✓ [PASS] Sukses update metode pembayaran: {$resUpdate['message']}\n";
} else {
    echo "✗ [FAIL] Gagal update metode pembayaran: " . json_encode($resUpdate) . "\n";
    exit(1);
}

echo "\n--------------------------------------------------\n";

// 4. Test Proteksi Penghapusan Default Tunai (CASH / ID 1)
echo "4. Menguji Proteksi Hapus Metode CASH (ID 1)...\n";
$resDelCash = requestApi("$baseUrl/api.php?action=delete_payment_method", 'POST', ['id' => 1]);

if ($resDelCash && $resDelCash['success'] === false) {
    echo "✓ [PASS] Proteksi berhasil! Server menolak hapus CASH: {$resDelCash['message']}\n";
} else {
    echo "✗ [FAIL] Metode CASH tidak terproteksi: " . json_encode($resDelCash) . "\n";
    exit(1);
}

echo "\n--------------------------------------------------\n";

// 5. Test DELETE (Hard Delete untuk metode tanpa transaksi)
echo "5. Menguji DELETE delete_payment_method untuk GOPAY (ID: $newId)...\n";
$resDelete = requestApi("$baseUrl/api.php?action=delete_payment_method", 'POST', ['id' => $newId]);

if ($resDelete && !empty($resDelete['success'])) {
    echo "✓ [PASS] Sukses hapus metode pembayaran: {$resDelete['message']}\n";
} else {
    echo "✗ [FAIL] Gagal hapus metode pembayaran: " . json_encode($resDelete) . "\n";
    exit(1);
}

echo "\n--------------------------------------------------\n";

// 6. Test Bootstrap sinkronisasi payment_methods
echo "6. Menguji GET api.php?action=bootstrap...\n";
$resBoot = requestApi("$baseUrl/api.php?action=bootstrap");

if ($resBoot && !empty($resBoot['success']) && isset($resBoot['payment_methods'])) {
    echo "✓ [PASS] Bootstrap berhasil menyertakan payment_methods sebanyak " . count($resBoot['payment_methods']) . " saluran aktif.\n";
} else {
    echo "✗ [FAIL] Bootstrap tidak mengembalikan payment_methods: " . json_encode($resBoot) . "\n";
    exit(1);
}

echo "\n=== SEMUA PENGUJIAN CRUD METODE PEMBAYARAN BERHASIL (100% PASS) ===\n";
