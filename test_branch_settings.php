<?php
// test_branch_settings.php
// Comprehensive Test Suite for Per-Branch Settings Customization (Multi-Branch Settings)

require_once __DIR__ . '/db.php';

echo "=== STARTING PER-BRANCH SETTINGS TEST SUITE ===\n\n";

function makeApiCall($action, $method = 'GET', $data = [], $query = []) {
    global $pdo;
    $_GET = array_merge(['action' => $action], $query);
    $_POST = $data;
    $_SERVER['REQUEST_METHOD'] = $method;
    $GLOBALS['CLI_INPUT'] = $data;
    $input = $data;

    ob_start();
    include __DIR__ . '/api.php';
    $output = ob_get_clean();

    $json = json_decode($output, true);
    if ($json === null) {
        return ['success' => false, 'raw' => $output];
    }
    return $json;
}

$passCount = 0;
$failCount = 0;

function assertTest($title, $condition, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] " . $title . "\n";
        $passCount++;
    } else {
        echo "[FAIL] " . $title . ($details ? " - " . $details : "") . "\n";
        $failCount++;
    }
}

// Persiapan: Ambil data cabang minimal 2 cabang
$branches = $pdo->query("SELECT id, name FROM branches ORDER BY id ASC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
if (count($branches) < 2) {
    // Buat cabang kedua jika belum ada
    $pdo->exec("INSERT OR IGNORE INTO branches (id, code, name, address, phone) VALUES (2, 'BR002', 'Cabang Ciganitri', 'Jl. Ciganitri No. 45, Bandung', '0812-9999-8888')");
    $branches = $pdo->query("SELECT id, name FROM branches ORDER BY id ASC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
}

$branch1Id = (int)$branches[0]['id'];
$branch2Id = (int)$branches[1]['id'];
$branch1Name = $branches[0]['name'];
$branch2Name = $branches[1]['name'];

echo "Menguji pada 2 Cabang Berbeda:\n";
echo "- Cabang 1: ID {$branch1Id} ({$branch1Name})\n";
echo "- Cabang 2: ID {$branch2Id} ({$branch2Name})\n\n";

// Bersihkan override lama untuk kedua cabang agar tes idempoten
$pdo->exec("DELETE FROM branch_settings WHERE branch_id IN ({$branch1Id}, {$branch2Id})");

// -------------------------------------------------------------------
// TEST 1: Identitas & Logo Per-Cabang (Fitur 1)
// -------------------------------------------------------------------
echo "--- TEST 1: Identitas & Logo Kustomisasi Per-Cabang ---\n";

// Set Pengaturan Global Pusat
makeApiCall('save_app_settings', 'POST', [
    'branch_id' => 0,
    'settings' => [
        'app_name' => 'ESBAS HOLDING PUSAT',
        'app_subtitle' => 'Kantor Pusat Retail',
        'app_logo_emoji' => '🏢',
        'company_name' => 'PT Esbas Retail Holding'
    ]
]);

// Set Override Khusus Cabang 1
$resSaveB1Logo = makeApiCall('save_app_settings', 'POST', [
    'branch_id' => $branch1Id,
    'settings' => [
        'app_name' => 'ESBAS SUPERSTORE CIMAHI',
        'app_subtitle' => 'Pusat Belanja & Grosir Cimahi',
        'app_logo_emoji' => '⚡',
        'company_name' => 'PT Esbas Cabang Cimahi'
    ]
]);
assertTest("Simpan identitas & logo khusus Cabang 1 ({$branch1Name})", isset($resSaveB1Logo['success']) && $resSaveB1Logo['success'] === true);

// Set Override Khusus Cabang 2
$resSaveB2Logo = makeApiCall('save_app_settings', 'POST', [
    'branch_id' => $branch2Id,
    'settings' => [
        'app_name' => 'ESBAS CAFE & ROASTERY CIGANITRI',
        'app_subtitle' => 'Artisan Coffee & Food Ciganitri',
        'app_logo_emoji' => '☕',
        'company_name' => 'PT Esbas Cabang Ciganitri'
    ]
]);
assertTest("Simpan identitas & logo khusus Cabang 2 ({$branch2Name})", isset($resSaveB2Logo['success']) && $resSaveB2Logo['success'] === true);

// Verifikasi pembacaan masing-masing cabang
$resGetB1 = makeApiCall('get_app_settings', 'GET', [], ['branch_id' => $branch1Id]);
$resGetB2 = makeApiCall('get_app_settings', 'GET', [], ['branch_id' => $branch2Id]);

assertTest("Cabang 1 membaca nama khusus 'ESBAS SUPERSTORE CIMAHI'", ($resGetB1['settings']['app_name'] ?? '') === 'ESBAS SUPERSTORE CIMAHI', "Got: " . ($resGetB1['settings']['app_name'] ?? ''));
assertTest("Cabang 1 membaca logo emoji khusus '⚡'", ($resGetB1['settings']['app_logo_emoji'] ?? '') === '⚡');
assertTest("Cabang 1 memiliki flag has_override = true", ($resGetB1['has_override'] ?? false) === true);

assertTest("Cabang 2 membaca nama khusus 'ESBAS CAFE & ROASTERY CIGANITRI'", ($resGetB2['settings']['app_name'] ?? '') === 'ESBAS CAFE & ROASTERY CIGANITRI', "Got: " . ($resGetB2['settings']['app_name'] ?? ''));
assertTest("Cabang 2 membaca logo emoji khusus '☕'", ($resGetB2['settings']['app_logo_emoji'] ?? '') === '☕');
assertTest("Cabang 2 memiliki flag has_override = true", ($resGetB2['has_override'] ?? false) === true);

// -------------------------------------------------------------------
// TEST 2: Setting Printer USB / IP Per-Cabang (Fitur 2)
// -------------------------------------------------------------------
echo "\n--- TEST 2: Setting Printer USB / IP Network Per-Cabang ---\n";

// Cabang 1 menggunakan Thermal IP Network 80mm
makeApiCall('save_app_settings', 'POST', [
    'branch_id' => $branch1Id,
    'settings' => [
        'printer_type' => 'IP',
        'printer_ip_address' => '192.168.1.150',
        'printer_ip_port' => '9100',
        'printer_paper_width' => '80mm',
        'printer_auto_cut' => '1',
        'printer_cash_drawer' => '1'
    ]
]);

// Cabang 2 menggunakan Thermal USB 58mm
makeApiCall('save_app_settings', 'POST', [
    'branch_id' => $branch2Id,
    'settings' => [
        'printer_type' => 'USB',
        'printer_ip_address' => '',
        'printer_ip_port' => '',
        'printer_paper_width' => '58mm',
        'printer_auto_cut' => '0',
        'printer_cash_drawer' => '0'
    ]
]);

$b1Prn = makeApiCall('get_app_settings', 'GET', [], ['branch_id' => $branch1Id])['settings'] ?? [];
$b2Prn = makeApiCall('get_app_settings', 'GET', [], ['branch_id' => $branch2Id])['settings'] ?? [];

assertTest("Cabang 1 terkonfigurasi Printer IP Network Port 9100", ($b1Prn['printer_type'] ?? '') === 'IP' && ($b1Prn['printer_ip_address'] ?? '') === '192.168.1.150');
assertTest("Cabang 1 lebar kertas 80mm & auto-cut aktif", ($b1Prn['printer_paper_width'] ?? '') === '80mm' && ($b1Prn['printer_auto_cut'] ?? '') === '1');

assertTest("Cabang 2 terkonfigurasi Printer USB", ($b2Prn['printer_type'] ?? '') === 'USB');
assertTest("Cabang 2 lebar kertas 58mm & auto-cut nonaktif", ($b2Prn['printer_paper_width'] ?? '') === '58mm' && ($b2Prn['printer_auto_cut'] ?? '') === '0');

// -------------------------------------------------------------------
// TEST 3: Layout Nota Struk Per-Cabang (Fitur 3)
// -------------------------------------------------------------------
echo "\n--- TEST 3: Layout Print Nota Struk Per-Cabang ---\n";

makeApiCall('save_app_settings', 'POST', [
    'branch_id' => $branch1Id,
    'settings' => [
        'receipt_header_title' => 'NOTA RESMI CIMAHI STORE',
        'receipt_header_subtitle' => 'Jl. Gandawijaya No. 12, Cimahi',
        'receipt_header_custom_text' => 'NPWP CABANG CIMAHI: 01.111.111.1-111.000',
        'receipt_body_show_sku' => '1',
        'receipt_footer_line1' => 'Matur nuwun atas kunjungan Anda di Cimahi!',
        'receipt_footer_line3' => 'IG: @esbas.cimahi'
    ]
]);

makeApiCall('save_app_settings', 'POST', [
    'branch_id' => $branch2Id,
    'settings' => [
        'receipt_header_title' => 'CAFE RECEIPT CIGANITRI',
        'receipt_header_subtitle' => 'Jl. Terusan Buah Batu No. 88, Ciganitri',
        'receipt_header_custom_text' => 'NPWP CABANG CIGANITRI: 02.222.222.2-222.000',
        'receipt_body_show_sku' => '0',
        'receipt_footer_line1' => 'Hatur nuhun parantos sumping di Ciganitri!',
        'receipt_footer_line3' => 'IG: @esbas.ciganitri'
    ]
]);

$b1Rcp = makeApiCall('get_app_settings', 'GET', [], ['branch_id' => $branch1Id])['settings'] ?? [];
$b2Rcp = makeApiCall('get_app_settings', 'GET', [], ['branch_id' => $branch2Id])['settings'] ?? [];

assertTest("Cabang 1 header nota 'NOTA RESMI CIMAHI STORE'", ($b1Rcp['receipt_header_title'] ?? '') === 'NOTA RESMI CIMAHI STORE');
assertTest("Cabang 1 NPWP khusus Cimahi", ($b1Rcp['receipt_header_custom_text'] ?? '') === 'NPWP CABANG CIMAHI: 01.111.111.1-111.000');
assertTest("Cabang 1 menampilkan SKU produk (1)", ($b1Rcp['receipt_body_show_sku'] ?? '') === '1');
assertTest("Cabang 1 footer khusus Cimahi", ($b1Rcp['receipt_footer_line3'] ?? '') === 'IG: @esbas.cimahi');

assertTest("Cabang 2 header nota 'CAFE RECEIPT CIGANITRI'", ($b2Rcp['receipt_header_title'] ?? '') === 'CAFE RECEIPT CIGANITRI');
assertTest("Cabang 2 NPWP khusus Ciganitri", ($b2Rcp['receipt_header_custom_text'] ?? '') === 'NPWP CABANG CIGANITRI: 02.222.222.2-222.000');
assertTest("Cabang 2 sembunyikan SKU produk (0)", ($b2Rcp['receipt_body_show_sku'] ?? '') === '0');
assertTest("Cabang 2 footer khusus Ciganitri", ($b2Rcp['receipt_footer_line3'] ?? '') === 'IG: @esbas.ciganitri');

// -------------------------------------------------------------------
// TEST 4: Layout Slip Gaji Per-Cabang (Fitur 4)
// -------------------------------------------------------------------
echo "\n--- TEST 4: Layout Slip Gaji Per-Cabang ---\n";

makeApiCall('save_app_settings', 'POST', [
    'branch_id' => $branch1Id,
    'settings' => [
        'payslip_header_title' => 'SLIP GAJI KARYAWAN OUTLET CIMAHI',
        'payslip_company_name' => 'PT ESBAS RETAIL - UNIT CIMAHI',
        'payslip_signer_1_title' => 'Staf Cabang Cimahi',
        'payslip_signer_2_title' => 'Branch Manager Cimahi'
    ]
]);

makeApiCall('save_app_settings', 'POST', [
    'branch_id' => $branch2Id,
    'settings' => [
        'payslip_header_title' => 'SLIP GAJI KARYAWAN OUTLET CIGANITRI',
        'payslip_company_name' => 'PT ESBAS RETAIL - UNIT CIGANITRI',
        'payslip_signer_1_title' => 'Barista / Staf Ciganitri',
        'payslip_signer_2_title' => 'Store Supervisor Ciganitri'
    ]
]);

$b1Pay = makeApiCall('get_app_settings', 'GET', [], ['branch_id' => $branch1Id])['settings'] ?? [];
$b2Pay = makeApiCall('get_app_settings', 'GET', [], ['branch_id' => $branch2Id])['settings'] ?? [];

assertTest("Cabang 1 slip title 'SLIP GAJI KARYAWAN OUTLET CIMAHI'", ($b1Pay['payslip_header_title'] ?? '') === 'SLIP GAJI KARYAWAN OUTLET CIMAHI');
assertTest("Cabang 1 signer 2 'Branch Manager Cimahi'", ($b1Pay['payslip_signer_2_title'] ?? '') === 'Branch Manager Cimahi');

assertTest("Cabang 2 slip title 'SLIP GAJI KARYAWAN OUTLET CIGANITRI'", ($b2Pay['payslip_header_title'] ?? '') === 'SLIP GAJI KARYAWAN OUTLET CIGANITRI');
assertTest("Cabang 2 signer 2 'Store Supervisor Ciganitri'", ($b2Pay['payslip_signer_2_title'] ?? '') === 'Store Supervisor Ciganitri');

// -------------------------------------------------------------------
// TEST 5: Nota WA & Telegram Per-Cabang (Fitur 5)
// -------------------------------------------------------------------
echo "\n--- TEST 5: Nota WhatsApp & Telegram Gateway Per-Cabang ---\n";

// Cabang 1: Gateway WA CIMAHI & Telegram CIMAHI
makeApiCall('save_app_settings', 'POST', [
    'branch_id' => $branch1Id,
    'settings' => [
        'wa_api_url' => 'https://api.fonnte.com/send',
        'wa_api_token' => 'token_secret_cimahi_111',
        'wa_template_message' => "Halo {customer_name}, terima kasih belanja di Cimahi! Faktur: {invoice_number} Total: {grand_total}",
        'telegram_bot_token' => '111111:AAABBB_CimahiBot',
        'telegram_chat_id' => '-100111111',
        'telegram_template_message' => "🛒 NOTA CIMAHI: {invoice_number} oleh {cashier_name} Total: {grand_total}"
    ]
]);

// Cabang 2: Gateway WA CIGANITRI & Telegram CIGANITRI
makeApiCall('save_app_settings', 'POST', [
    'branch_id' => $branch2Id,
    'settings' => [
        'wa_api_url' => 'https://api.wanotif.id/v1/send',
        'wa_api_token' => 'token_secret_ciganitri_222',
        'wa_template_message' => "Halo {customer_name}, terima kasih ngopi di Ciganitri! Faktur: {invoice_number} Total: {grand_total}",
        'telegram_bot_token' => '222222:CCCDDD_CiganitriBot',
        'telegram_chat_id' => '-100222222',
        'telegram_template_message' => "☕ TRANSAKSI CAFE CIGANITRI: {invoice_number} Total: {grand_total}"
    ]
]);

// Uji Simulasi Kirim WA untuk Cabang 1
$simWA1 = makeApiCall('test_send_electronic_receipt', 'POST', [
    'branch_id' => $branch1Id,
    'channel' => 'WA',
    'destination' => '081211111111',
    'invoice_number' => 'INV-CMH-001'
]);
assertTest("Eksekusi simulasi WA Cabang 1 sukses", isset($simWA1['success']) && $simWA1['success'] === true);
assertTest("Simulasi WA Cabang 1 memuat template khusus Cimahi", strpos($simWA1['message_rendered'] ?? '', 'belanja di Cimahi!') !== false);

// Uji Simulasi Kirim WA untuk Cabang 2
$simWA2 = makeApiCall('test_send_electronic_receipt', 'POST', [
    'branch_id' => $branch2Id,
    'channel' => 'WA',
    'destination' => '081222222222',
    'invoice_number' => 'INV-CGN-002'
]);
assertTest("Eksekusi simulasi WA Cabang 2 sukses", isset($simWA2['success']) && $simWA2['success'] === true);
assertTest("Simulasi WA Cabang 2 memuat template khusus Ciganitri", strpos($simWA2['message_rendered'] ?? '', 'ngopi di Ciganitri!') !== false);

// Uji Simulasi Kirim Telegram untuk Cabang 1
$simTG1 = makeApiCall('test_send_electronic_receipt', 'POST', [
    'branch_id' => $branch1Id,
    'channel' => 'TELEGRAM',
    'destination' => '-100111111',
    'invoice_number' => 'INV-CMH-001'
]);
assertTest("Simulasi Telegram Cabang 1 memuat template Cimahi", strpos($simTG1['message_rendered'] ?? '', 'NOTA CIMAHI:') !== false);

// Uji Simulasi Kirim Telegram untuk Cabang 2
$simTG2 = makeApiCall('test_send_electronic_receipt', 'POST', [
    'branch_id' => $branch2Id,
    'channel' => 'TELEGRAM',
    'destination' => '-100222222',
    'invoice_number' => 'INV-CGN-002'
]);
assertTest("Simulasi Telegram Cabang 2 memuat template Ciganitri", strpos($simTG2['message_rendered'] ?? '', 'TRANSAKSI CAFE CIGANITRI:') !== false);

// -------------------------------------------------------------------
// TEST 6: Pewarisan Pengaturan (Inheritance Fallback)
// -------------------------------------------------------------------
echo "\n--- TEST 6: Hierarki Pewarisan (Fallback ke Standar Pusat) ---\n";

// Cabang 1 belum meng-override setting 'payslip_confidential_label'
$b1Inherited = makeApiCall('get_app_settings', 'GET', [], ['branch_id' => $branch1Id])['settings'] ?? [];
assertTest("Kunci yang tidak di-override mewarisi setting pusat secara transparan", !empty($b1Inherited['payslip_confidential_label']));

// Cabang 999 (cabang fiktif baru tanpa override sama sekali)
$b999 = makeApiCall('get_app_settings', 'GET', [], ['branch_id' => 999]);
assertTest("Cabang baru tanpa override memiliki has_override = false", ($b999['has_override'] ?? true) === false);
assertTest("Cabang baru tanpa override mewarisi nama pusat 'ESBAS HOLDING PUSAT'", ($b999['settings']['app_name'] ?? '') === 'ESBAS HOLDING PUSAT');

// -------------------------------------------------------------------
// TEST 7: Reset Pengaturan Cabang ke Standar Pusat
// -------------------------------------------------------------------
echo "\n--- TEST 7: Reset Pengaturan Cabang ke Standar Pusat ---\n";

$resReset = makeApiCall('reset_branch_settings', 'POST', ['branch_id' => $branch2Id]);
assertTest("Eksekusi reset_branch_settings Cabang 2 sukses", isset($resReset['success']) && $resReset['success'] === true);

$b2AfterReset = makeApiCall('get_app_settings', 'GET', [], ['branch_id' => $branch2Id]);
assertTest("Setelah reset, Cabang 2 memiliki has_override = false", ($b2AfterReset['has_override'] ?? true) === false);
assertTest("Setelah reset, nama aplikasi Cabang 2 kembali ke nama pusat", ($b2AfterReset['settings']['app_name'] ?? '') === 'ESBAS HOLDING PUSAT');

// SUMMARY
echo "\n=== TEST SUITE FINISHED ===\n";
echo "Total PASS: $passCount\n";
echo "Total FAIL: $failCount\n";

if ($failCount === 0) {
    echo "🎉 ALL PER-BRANCH SETTINGS TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED!\n";
    exit(1);
}
