<?php
// test_app_settings.php
// Automation test script for App Settings & Role Permissions module

require_once __DIR__ . '/db.php';

// Bersihkan override branch_settings agar pengujian global berjalan murni
$pdo->exec("DELETE FROM branch_settings");

echo "=== STARTING APP SETTINGS & ROLE PERMISSIONS TEST SUITE ===\n\n";

function makeApiCall($action, $method = 'GET', $data = []) {
    global $pdo;
    $_GET = ['action' => $action];
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

// -------------------------------------------------------------
// TEST 1: Identitas Aplikasi & Logo (Fitur 1 & 2)
// -------------------------------------------------------------
echo "--- TEST 1: Identitas Aplikasi & Logo (app_name, logo_emoji, company_name) ---\n";
$newIdentitySettings = [
    'app_name' => 'ESBAS PRO ENTERPRISE',
    'app_subtitle' => 'Solusi POS & Manajemen Modern',
    'app_logo_emoji' => '⚡',
    'company_name' => 'PT Esbas Retail Corporindo'
];

$resSaveIdentity = makeApiCall('save_app_settings', 'POST', [
    'settings' => $newIdentitySettings
]);
assertTest("Simpan konfigurasi nama dan logo aplikasi", isset($resSaveIdentity['success']) && $resSaveIdentity['success'] === true);

$resGetSettings = makeApiCall('get_app_settings', 'GET');
assertTest("Ambil konfigurasi aplikasi berhasil", isset($resGetSettings['success']) && $resGetSettings['success'] === true);
$settingsMap = $resGetSettings['settings'] ?? [];

assertTest("Verifikasi nama aplikasi terupdate", ($settingsMap['app_name'] ?? '') === 'ESBAS PRO ENTERPRISE', "Got: " . ($settingsMap['app_name'] ?? ''));
assertTest("Verifikasi logo emoji terupdate", ($settingsMap['app_logo_emoji'] ?? '') === '⚡', "Got: " . ($settingsMap['app_logo_emoji'] ?? ''));
assertTest("Verifikasi company name terupdate", ($settingsMap['company_name'] ?? '') === 'PT Esbas Retail Corporindo', "Got: " . ($settingsMap['company_name'] ?? ''));

// -------------------------------------------------------------
// TEST 2: Hak Akses Role / Matrix Menu Permissions (Fitur 3)
// -------------------------------------------------------------
echo "\n--- TEST 2: Hak Akses Role & Menu Permissions ---\n";
$resGetPerms = makeApiCall('get_role_permissions', 'GET');
assertTest("Ambil matriks hak akses menu", isset($resGetPerms['success']) && $resGetPerms['success'] === true);
$rawPerms = $resGetPerms['permissions'] ?? [];
assertTest("Memiliki daftar role terdaftar", count($rawPerms) > 0, "Ditemukan " . count($rawPerms) . " entries");

// Format save_role_permissions: [ 'role' => [ 'menu_key' => 1/0 ] ]
$testRolePerms = [
    'CASHIER' => [
        'pos' => 1,
        'settings' => 0,
        'reports' => 0
    ],
    'STAFF' => [
        'ess' => 1,
        'settings' => 0
    ]
];

$resSavePerms = makeApiCall('save_role_permissions', 'POST', [
    'permissions' => $testRolePerms
]);
assertTest("Simpan matriks hak akses role", isset($resSavePerms['success']) && $resSavePerms['success'] === true);

// Verifikasi ulang get_role_permissions
$resGetPerms2 = makeApiCall('get_role_permissions', 'GET');
$groupedPerms = $resGetPerms2['grouped'] ?? [];

assertTest("Hak akses POS CASHIER aktif (1)", ($groupedPerms['CASHIER']['pos'] ?? null) === 1);
assertTest("Hak akses Settings CASHIER dinonaktifkan (0)", ($groupedPerms['CASHIER']['settings'] ?? null) === 0);
assertTest("Hak akses Reports CASHIER dinonaktifkan (0)", ($groupedPerms['CASHIER']['reports'] ?? null) === 0);
assertTest("Hak akses ESS STAFF aktif (1)", ($groupedPerms['STAFF']['ess'] ?? null) === 1);


// -------------------------------------------------------------
// TEST 3: Pengaturan Printer USB / IP Network (Fitur 4)
// -------------------------------------------------------------
echo "\n--- TEST 3: Setting Printer USB / IP Network ---\n";
$printerSettings = [
    'printer_type' => 'IP',
    'printer_ip_address' => '192.168.1.200',
    'printer_ip_port' => '9100',
    'printer_paper_width' => '80mm',
    'printer_auto_cut' => '1',
    'printer_cash_drawer' => '1'
];
$resSavePrinter = makeApiCall('save_app_settings', 'POST', [
    'settings' => $printerSettings
]);
assertTest("Simpan setting printer thermal IP network", isset($resSavePrinter['success']) && $resSavePrinter['success'] === true);

$resGetPrinter = makeApiCall('get_app_settings', 'GET');
$pMap = $resGetPrinter['settings'] ?? [];
assertTest("Verifikasi printer_type IP", ($pMap['printer_type'] ?? '') === 'IP');
assertTest("Verifikasi printer_ip_address", ($pMap['printer_ip_address'] ?? '') === '192.168.1.200');
assertTest("Verifikasi printer_ip_port 9100", ($pMap['printer_ip_port'] ?? '') === '9100');
assertTest("Verifikasi printer_paper_width 80mm", ($pMap['printer_paper_width'] ?? '') === '80mm');
assertTest("Verifikasi printer_auto_cut aktif", ($pMap['printer_auto_cut'] ?? '') === '1');

// -------------------------------------------------------------
// TEST 4: Setting Layout Nota Struk (Header, Body, Footer) (Fitur 5)
// -------------------------------------------------------------
echo "\n--- TEST 4: Setting Layout Print Nota Struk ---\n";
$receiptSettings = [
    'receipt_header_title' => 'ESBAS COFFEE & EATERY',
    'receipt_header_subtitle' => 'Jl. Boulevard Raya No. 88, Jakarta',
    'receipt_header_custom_text' => 'NPWP: 01.234.567.8-999.000',
    'receipt_show_logo' => '1',
    'receipt_body_show_sku' => '1',
    'receipt_body_compact_spacing' => '0',
    'receipt_footer_line1' => 'Terima kasih atas kunjungan Anda!',
    'receipt_footer_line2' => 'Barang yang sudah dibeli tidak dapat ditukar',
    'receipt_footer_line3' => 'Instagram: @esbaspos.official'
];
$resSaveReceipt = makeApiCall('save_app_settings', 'POST', [
    'settings' => $receiptSettings
]);
assertTest("Simpan setting layout nota struk", isset($resSaveReceipt['success']) && $resSaveReceipt['success'] === true);

$resGetReceipt = makeApiCall('get_app_settings', 'GET');
$rMap = $resGetReceipt['settings'] ?? [];
assertTest("Verifikasi receipt_header_title", ($rMap['receipt_header_title'] ?? '') === 'ESBAS COFFEE & EATERY');
assertTest("Verifikasi receipt_header_custom_text NPWP", ($rMap['receipt_header_custom_text'] ?? '') === 'NPWP: 01.234.567.8-999.000');
assertTest("Verifikasi receipt_body_show_sku aktif", ($rMap['receipt_body_show_sku'] ?? '') === '1');
assertTest("Verifikasi receipt_footer_line3", ($rMap['receipt_footer_line3'] ?? '') === 'Instagram: @esbaspos.official');

// -------------------------------------------------------------
// TEST 5: Setting Layout Slip Gaji (Fitur 6)
// -------------------------------------------------------------
echo "\n--- TEST 5: Setting Layout Slip Gaji ---\n";
$payslipSettings = [
    'payslip_header_title' => 'SLIP GAJI KARYAWAN RESMI',
    'payslip_company_name' => 'PT ESBAS RETAIL CORPORINDO',
    'payslip_show_terbilang' => '1',
    'payslip_show_attendance_detail' => '1',
    'payslip_signer_1_title' => 'Finance & Payroll Lead',
    'payslip_signer_2_title' => 'Head of Human Resources',
    'payslip_legal_footer_note' => 'Dokumen ini dibuat otomatis dan sah tanpa tanda tangan basah.'
];
$resSavePayslip = makeApiCall('save_app_settings', 'POST', [
    'settings' => $payslipSettings
]);
assertTest("Simpan setting layout slip gaji", isset($resSavePayslip['success']) && $resSavePayslip['success'] === true);

$resGetPayslip = makeApiCall('get_app_settings', 'GET');
$psMap = $resGetPayslip['settings'] ?? [];
assertTest("Verifikasi payslip_header_title", ($psMap['payslip_header_title'] ?? '') === 'SLIP GAJI KARYAWAN RESMI');
assertTest("Verifikasi payslip_signer_1_title", ($psMap['payslip_signer_1_title'] ?? '') === 'Finance & Payroll Lead');
assertTest("Verifikasi payslip_legal_footer_note", ($psMap['payslip_legal_footer_note'] ?? '') === 'Dokumen ini dibuat otomatis dan sah tanpa tanda tangan basah.');

// -------------------------------------------------------------
// TEST 6: Notifikasi Nota Elektronik WhatsApp Gateway (Fitur 7A)
// -------------------------------------------------------------
echo "\n--- TEST 6: Notifikasi & Simulasi Nota WA Gateway ---\n";
$waSettings = [
    'wa_gateway_provider' => 'FONNTE',
    'wa_api_url' => 'https://api.fonnte.com/send',
    'wa_api_token' => 'secret_dummy_fonnte_token_12345',
    'wa_template_message' => "Halo {customer_name}, terima kasih telah berbelanja di {store_name}!\nNo Struk: {invoice_number}\nTotal: {grand_total}\nTanggal: {date}\nSemoga harimu menyenangkan!"
];
makeApiCall('save_app_settings', 'POST', ['settings' => $waSettings]);

$resSendWA = makeApiCall('test_send_electronic_receipt', 'POST', [
    'channel' => 'WA',
    'destination' => '081234567890',
    'invoice_number' => 'INV-TEST-999'
]);
assertTest("Eksekusi simulasi kirim nota WhatsApp", isset($resSendWA['success']) && $resSendWA['success'] === true);
$renderedMessageWA = $resSendWA['message_rendered'] ?? '';
assertTest("Variabel customer_name terisi dalam template WA", strpos($renderedMessageWA, 'Pelanggan Uji Coba') !== false);
assertTest("Variabel store_name terisi dalam template WA", strpos($renderedMessageWA, 'ESBAS PRO ENTERPRISE') !== false);
assertTest("Variabel invoice_number terisi dalam template WA", strpos($renderedMessageWA, 'INV-TEST-999') !== false);
assertTest("Gateway configured flag valid", ($resSendWA['gateway_configured'] ?? false) === true);

// -------------------------------------------------------------
// TEST 7: Notifikasi Nota Elektronik Telegram Bot (Fitur 7B)
// -------------------------------------------------------------
echo "\n--- TEST 7: Notifikasi & Simulasi Nota Telegram Bot ---\n";
$tgSettings = [
    'telegram_bot_token' => '1234567890:ABCdefGHIjklMNOpqrsTUVwxyz',
    'telegram_chat_id' => '-100987654321',
    'telegram_template_message' => "🧾 *NOTA TRANSAKSI {store_name}*\nPelanggan: {customer_name}\nKasir: {cashier_name}\nNo: `{invoice_number}`\nTotal: *{grand_total}*\nTerima Kasih!"
];
makeApiCall('save_app_settings', 'POST', ['settings' => $tgSettings]);

$resSendTG = makeApiCall('test_send_electronic_receipt', 'POST', [
    'channel' => 'TELEGRAM',
    'destination' => '-100987654321',
    'invoice_number' => 'INV-TEST-999'
]);
assertTest("Eksekusi simulasi kirim nota Telegram", isset($resSendTG['success']) && $resSendTG['success'] === true);
$renderedMessageTG = $resSendTG['message_rendered'] ?? '';
assertTest("Variabel store_name terisi dalam template TG", strpos($renderedMessageTG, 'ESBAS PRO ENTERPRISE') !== false);
assertTest("Variabel customer_name terisi dalam template TG", strpos($renderedMessageTG, 'Pelanggan Uji Coba') !== false);
assertTest("Variabel invoice_number terisi dalam template TG", strpos($renderedMessageTG, 'INV-TEST-999') !== false);
assertTest("Telegram Bot gateway configured flag valid", ($resSendTG['gateway_configured'] ?? false) === true);

// -------------------------------------------------------------
// TEST 8: Verifikasi Bootstrap Payload
// -------------------------------------------------------------
echo "\n--- TEST 8: Verifikasi Integrasi Bootstrap Endpoint ---\n";
$resBootstrap = makeApiCall('bootstrap', 'GET');
assertTest("Bootstrap API response success", isset($resBootstrap['success']) && $resBootstrap['success'] === true);
$bootAppSettings = $resBootstrap['app_settings'] ?? [];
$bootRolePerms = $resBootstrap['role_permissions'] ?? [];
$bootUserPerms = $resBootstrap['user_permissions'] ?? [];

assertTest("Bootstrap memuat konfigurasi app_settings map", isset($bootAppSettings['app_name']) && $bootAppSettings['app_name'] === 'ESBAS PRO ENTERPRISE');
assertTest("Bootstrap memuat role_permissions matriks", count($bootRolePerms) > 0);
assertTest("Bootstrap memuat user_permissions untuk active session", is_array($bootUserPerms));

// SUMMARY
echo "\n=== TEST SUITE FINISHED ===\n";
echo "Total PASS: $passCount\n";
echo "Total FAIL: $failCount\n";

if ($failCount === 0) {
    echo "🎉 ALL TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED!\n";
    exit(1);
}
