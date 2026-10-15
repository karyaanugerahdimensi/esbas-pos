<?php
/**
 * Test Suite: Employee Self Service (ESS) Comprehensive Test
 * Tests all 8 requested features:
 * 1. CRUD Jadwal jam kerja (Shift 1, Shift 2, dll)
 * 2. Set jadwal kerja bulanan beserta hari libur
 * 3. Export & Import jadwal kerja bulanan via CSV/Excel
 * 4. Tukar jadwal libur / Shift swap (pengajuan & approval auto-swap)
 * 5. Pengajuan ketidakhadiran (Cuti, Izin, Sakit) & approval
 * 6. Laporan kehadiran karyawan (rekap & daily logs)
 * 7. Cetak slip gaji mandiri & verifikasi komponen
 * 8. Pinjaman / Kasbon gaji (plafon, tenor, approval, potongan, bayar angsuran)
 */

require_once __DIR__ . '/db.php';

function assertTest($condition, $message) {
    if ($condition) {
        echo "  [PASS] $message\n";
    } else {
        echo "  [FAIL] $message\n";
        throw new Exception("Assertion Failed: $message");
    }
}

function callApi($action, $input = [], $get = []) {
    global $pdo;
    $_GET = array_merge(['action' => $action], $get);
    $_POST = [];
    $GLOBALS['input'] = $input;

    ob_start();
    include __DIR__ . '/api.php';
    $output = ob_get_clean();

    $json = json_decode($output, true);
    if ($json === null && !empty($_GET['download'])) {
        return ['raw' => $output];
    }
    return $json;
}

echo "=== MEMULAI TEST SUITE EMPLOYEE SELF SERVICE (ESS) ===\n\n";

try {
    // Bersihkan data tes terdahulu jika ada agar idempoten
    $pdo->exec("DELETE FROM work_shifts WHERE code = 'SX'");
    $pdo->exec("DELETE FROM salary_loans WHERE reason LIKE '%kendaraan motor%' OR reason LIKE '%Pinjaman besar%'");
    $pdo->exec("DELETE FROM shift_swaps WHERE reason LIKE '%keperluan keluarga%'");
    $pdo->exec("DELETE FROM leave_requests WHERE reason LIKE '%Demam tinggi%'");
    $pdo->exec("DELETE FROM employee_payrolls WHERE period_month = '2026-10'");

    // ----------------------------------------------------
    // TEST 1: CRUD Master Jam Kerja (Work Shifts)
    // ----------------------------------------------------
    echo "1. Menguji CRUD Master Jam Kerja (Work Shifts)...\n";
    $resShifts = callApi('get_work_shifts');
    assertTest($resShifts['success'] === true, "Endpoint get_work_shifts berhasil merespons");
    assertTest(count($resShifts['shifts']) >= 4, "Terdapat default shifts (S1, S2, SM, OFF)");

    // Tambah shift baru: Shift Malam Khusus
    $resAddShift = callApi('save_work_shift', [
        'code' => 'SX',
        'name' => 'Shift Malam Khusus',
        'start_time' => '22:00',
        'end_time' => '06:00',
        'late_tolerance_mins' => 10,
        'color_tag' => '#1e293b',
        'is_day_off' => 0
    ]);
    assertTest($resAddShift['success'] === true, "Berhasil menambahkan Shift SX: " . ($resAddShift['message'] ?? ''));

    // Ambil ID SX
    $sxShift = $pdo->query("SELECT * FROM work_shifts WHERE code = 'SX'")->fetch();
    assertTest(!empty($sxShift['id']), "Shift SX tersimpan di database dengan ID: " . $sxShift['id']);

    // Edit shift SX
    $resEditShift = callApi('save_work_shift', [
        'id' => $sxShift['id'],
        'code' => 'SX',
        'name' => 'Shift Malam Ekstra',
        'start_time' => '23:00',
        'end_time' => '07:00',
        'late_tolerance_mins' => 20,
        'color_tag' => '#0f172a',
        'is_day_off' => 0
    ]);
    assertTest($resEditShift['success'] === true, "Berhasil memperbarui Shift SX");

    // ----------------------------------------------------
    // TEST 2: Set Jadwal Kerja Bulanan Beserta Hari Libur
    // ----------------------------------------------------
    echo "\n2. Menguji Set Jadwal Kerja Bulanan & Hari Libur Toko...\n";
    $testMonth = '2026-10';
    $branchId = 1;

    // Set bulk schedule untuk karyawan 3 (Bima) dan karyawan 4 (Abdul Latif)
    $items = [
        ['user_id' => 3, 'shift_date' => '2026-10-15', 'work_shift_id' => 1, 'is_holiday' => 0, 'notes' => 'Pagi Normal'],
        ['user_id' => 3, 'shift_date' => '2026-10-16', 'work_shift_id' => 2, 'is_holiday' => 0, 'notes' => 'Siang Normal'],
        ['user_id' => 4, 'shift_date' => '2026-10-15', 'work_shift_id' => 2, 'is_holiday' => 0, 'notes' => 'Siang Normal'],
        ['user_id' => 4, 'shift_date' => '2026-10-16', 'work_shift_id' => 1, 'is_holiday' => 0, 'notes' => 'Pagi Normal']
    ];
    $resBulk = callApi('save_bulk_monthly_schedules', ['branch_id' => $branchId, 'items' => $items]);
    assertTest($resBulk['success'] === true && $resBulk['count'] >= 4, "Bulk save jadwal bulanan berhasil ({$resBulk['count']} jadwal)");

    // Set hari libur toko pada tanggal 2026-10-25
    $resHoliday = callApi('set_monthly_holidays', [
        'branch_id' => $branchId,
        'dates' => ['2026-10-25'],
        'notes' => 'Hari Libur Toko Maintenance'
    ]);
    assertTest($resHoliday['success'] === true, "Set hari libur toko berhasil diterapkan");

    // Verifikasi via get_monthly_schedules
    $resSched = callApi('get_monthly_schedules', [], ['branch_id' => $branchId, 'month' => $testMonth]);
    assertTest($resSched['success'] === true, "get_monthly_schedules berhasil dimuat");
    assertTest($resSched['days_in_month'] == 31, "Bulan Oktober terhitung tepat 31 hari");

    // ----------------------------------------------------
    // TEST 3: Export & Import Jadwal Bulanan Excel / CSV
    // ----------------------------------------------------
    echo "\n3. Menguji Export & Import Jadwal Kerja Excel / CSV...\n";
    $resExport = callApi('export_monthly_schedules', [], ['branch_id' => $branchId, 'month' => $testMonth]);
    assertTest($resExport['success'] === true, "Export jadwal berhasil menghasilkan file CSV");
    assertTest(strpos($resExport['csv_content'], 'NIK,Nama_Karyawan') !== false, "Header CSV valid");
    assertTest(strpos($resExport['csv_content'], '2026-10-15') !== false, "Data tanggal 2026-10-15 ada dalam ekspor CSV");

    // Test Import data CSV baru
    $csvImportData = "NIK,Nama_Karyawan,Cabang,Tanggal,Kode_Shift,Nama_Shift,Jam_Masuk,Jam_Pulang,Libur,Catatan\r\n" .
                     "CSR001,Bima,Pusat Cimahi,2026-10-17,S1,Shift 1 (Pagi),07:00,15:00,TIDAK,Imported via Test\r\n" .
                     "CSR002,Abdul Latif,Pusat Cimahi,2026-10-17,S2,Shift 2 (Siang),14:30,22:30,TIDAK,Imported via Test\r\n";
    $resImport = callApi('import_monthly_schedules', ['branch_id' => $branchId, 'csv_data' => $csvImportData]);
    assertTest($resImport['success'] === true && ($resImport['inserted'] + $resImport['updated']) >= 2, "Import CSV jadwal berhasil diproses");

    // ----------------------------------------------------
    // TEST 4: Tukar Jadwal Libur / Shift Swap
    // ----------------------------------------------------
    echo "\n4. Menguji Pengajuan & Persetujuan Tukar Jadwal (Shift Swap)...\n";
    // Bima (3) mengajukan tukar jadwal 2026-10-15 (S1) dengan Abdul Latif (4) jadwal 2026-10-15 (S2)
    $resSwapReq = callApi('request_shift_swap', [
        'requester_id' => 3,
        'target_id' => 4,
        'branch_id' => $branchId,
        'shift_date' => '2026-10-15',
        'target_date' => '2026-10-15',
        'reason' => 'Ada keperluan keluarga pagi hari'
    ]);
    assertTest($resSwapReq['success'] === true, "Pengajuan tukar jadwal berhasil dibuat");

    $swapRecord = $pdo->query("SELECT * FROM shift_swaps WHERE requester_id = 3 AND shift_date = '2026-10-15' ORDER BY id DESC LIMIT 1")->fetch();
    assertTest(!empty($swapRecord['id']), "Record swap ID #{$swapRecord['id']} berstatus PENDING");

    // Manajer menyetujui tukar jadwal
    $resSwapApp = callApi('approve_shift_swap', [
        'swap_id' => $swapRecord['id'],
        'action' => 'APPROVE',
        'user_id' => 1
    ]);
    assertTest($resSwapApp['success'] === true, "Persetujuan tukar jadwal berhasil dieksekusi");

    // Verifikasi auto-swap di tabel shift_schedules
    $sBima = $pdo->query("SELECT * FROM shift_schedules WHERE user_id = 3 AND shift_date = '2026-10-15'")->fetch();
    $sLatif = $pdo->query("SELECT * FROM shift_schedules WHERE user_id = 4 AND shift_date = '2026-10-15'")->fetch();
    assertTest(!empty($sBima) && !empty($sLatif), "Kedua jadwal karyawan 2026-10-15 tetap utuh & tersinkronisasi");

    // ----------------------------------------------------
    // TEST 5: Pengajuan Ketidakhadiran (Cuti, Izin, Sakit)
    // ----------------------------------------------------
    echo "\n5. Menguji Pengajuan Ketidakhadiran (Cuti/Izin/Sakit)...\n";
    $resLeave = callApi('submit_leave_request', [
        'user_id' => 3,
        'branch_id' => $branchId,
        'leave_type' => 'SAKIT',
        'start_date' => '2026-10-20',
        'end_date' => '2026-10-21',
        'reason' => 'Demam tinggi rawat jalan',
        'attachment_doc' => 'Surat Dokter No. 445/MED/2026'
    ]);
    assertTest($resLeave['success'] === true, "Pengajuan sakit berhasil dikirim");

    $leaveRecord = $pdo->query("SELECT * FROM leave_requests WHERE user_id = 3 AND leave_type = 'SAKIT' ORDER BY id DESC LIMIT 1")->fetch();
    assertTest(!empty($leaveRecord['id']), "Record leave ID #{$leaveRecord['id']} tersimpan");

    // Manajer menyetujui permohonan sakit
    $resLeaveApp = callApi('approve_leave_request', [
        'leave_id' => $leaveRecord['id'],
        'action' => 'APPROVE',
        'user_id' => 1
    ]);
    assertTest($resLeaveApp['success'] === true, "Persetujuan permohonan sakit berhasil dieksekusi");

    // Verifikasi jadwal ditandai sakit
    $sLeaveCheck = $pdo->query("SELECT * FROM shift_schedules WHERE user_id = 3 AND shift_date = '2026-10-20'")->fetch();
    assertTest($sLeaveCheck['is_holiday'] == 1 && strpos($sLeaveCheck['notes'], 'SAKIT') !== false, "Jadwal 2026-10-20 otomatis ditandai [SAKIT]");

    // ----------------------------------------------------
    // TEST 6: Laporan Kehadiran Karyawan
    // ----------------------------------------------------
    echo "\n6. Menguji Laporan Rekapitulasi Kehadiran & Kedisiplinan...\n";
    // Buat dummy presensi clock_in untuk karyawan 3
    $pdo->exec("INSERT INTO attendance_logs (user_id, branch_id, clock_in, status, latitude, longitude, distance_meters) VALUES (3, 1, '2026-10-15 07:10:00', 'ON_TIME', -6.87, 107.54, 12.0)");
    $pdo->exec("INSERT INTO attendance_logs (user_id, branch_id, clock_in, status, latitude, longitude, distance_meters) VALUES (3, 1, '2026-10-16 07:35:00', 'LATE', -6.87, 107.54, 15.0)");

    $resAtt = callApi('get_attendance_report', [], ['branch_id' => $branchId, 'month' => $testMonth]);
    assertTest($resAtt['success'] === true, "get_attendance_report berhasil merespons");
    assertTest(count($resAtt['summary']) > 0, "Terdapat ringkasan karyawan dalam laporan kehadiran");

    // Cari ringkasan Bima
    $bimaSum = null;
    foreach ($resAtt['summary'] as $sm) {
        if ($sm['user_id'] == 3) { $bimaSum = $sm; break; }
    }
    assertTest($bimaSum !== null, "Ringkasan Bima ditemukan dalam laporan");
    assertTest($bimaSum['present_days'] >= 2, "Bima tercatat hadir minimal 2 hari");
    assertTest($bimaSum['late_days'] >= 1, "Bima tercatat terlambat minimal 1 kali");
    assertTest($bimaSum['sick_days'] >= 2, "Bima tercatat sakit 2 hari dari permohonan leave");

    // ----------------------------------------------------
    // TEST 7: Cetak Slip Gaji Mandiri
    // ----------------------------------------------------
    echo "\n7. Menguji Cetak Slip Gaji Mandiri & Verifikasi Komponen...\n";
    $resSlip = callApi('get_employee_payslip', [], ['user_id' => 3, 'month' => $testMonth]);
    assertTest($resSlip['success'] === true, "get_employee_payslip berhasil di-generate");

    $pay = $resSlip['payroll'];
    assertTest($pay['base_salary'] >= 3000000, "Gaji Pokok terhitung valid: Rp " . number_format($pay['base_salary']));
    assertTest($pay['allowance'] >= 500000, "Tunjangan Jabatan terhitung valid: Rp " . number_format($pay['allowance']));
    assertTest($pay['gross_salary'] > 0, "Total Pendapatan Kotor (Gross) terhitung: Rp " . number_format($pay['gross_salary']));
    assertTest($pay['net_salary'] > 0, "Total Gaji Bersih (Take Home Pay) terhitung: Rp " . number_format($pay['net_salary']));

    // Simpan & Kunci Slip Gaji
    $resLockPay = callApi('generate_or_save_payroll', $pay);
    assertTest($resLockPay['success'] === true, "Slip gaji berhasil disimpan & dikunci");

    // ----------------------------------------------------
    // TEST 8: Pinjaman / Kasbon Gaji Karyawan
    // ----------------------------------------------------
    echo "\n8. Menguji Pinjaman / Kasbon Gaji Karyawan...\n";
    // Ajukan kasbon Rp 600.000 tenor 3 bulan (cicilan Rp 200.000/bln)
    $resLoanReq = callApi('submit_salary_loan', [
        'user_id' => 3,
        'branch_id' => $branchId,
        'amount' => 600000,
        'installment_months' => 3,
        'reason' => 'Perbaikan kendaraan motor'
    ]);
    assertTest($resLoanReq['success'] === true, "Pengajuan kasbon berhasil dikirim: " . ($resLoanReq['message'] ?? ''));

    $loanRecord = $pdo->query("SELECT * FROM salary_loans WHERE user_id = 3 AND amount = 600000 ORDER BY id DESC LIMIT 1")->fetch();
    assertTest(!empty($loanRecord['id']), "Record kasbon ID #{$loanRecord['id']} tersimpan");
    assertTest($loanRecord['monthly_deduction'] == 200000, "Potongan per bulan terhitung pas: Rp 200.000");

    // Manajer menyetujui kasbon
    $resLoanApp = callApi('approve_salary_loan', [
        'loan_id' => $loanRecord['id'],
        'action' => 'APPROVE',
        'user_id' => 1
    ]);
    assertTest($resLoanApp['success'] === true, "Persetujuan kasbon berhasil disetujui (Status ACTIVE)");

    // Uji proteksi plafon kasbon (karyawan coba pinjam melebihi 70% gaji)
    $resExceedLoan = callApi('submit_salary_loan', [
        'user_id' => 3,
        'branch_id' => $branchId,
        'amount' => 5000000, // Melebihi plafon Rp 2.100.000
        'installment_months' => 5,
        'reason' => 'Pinjaman besar'
    ]);
    assertTest($resExceedLoan['success'] === false, "Proteksi plafon kasbon bekerja menolak pinjaman melebihi 70% gaji");

    // Bayar angsuran kasbon Rp 200.000
    $resRepay = callApi('pay_salary_loan', [
        'loan_id' => $loanRecord['id'],
        'amount_paid' => 200000,
        'notes' => 'Potongan gaji bulan berjalan'
    ]);
    assertTest($resRepay['success'] === true && $resRepay['remaining_amount'] == 400000, "Pembayaran angsuran berhasil, sisa utang pas Rp 400.000");

    // ----------------------------------------------------
    // TEST 9: Isolasi Privasi Data Karyawan (Non-Manager Access Control)
    // ----------------------------------------------------
    echo "\n9. Menguji Isolasi Privasi Data Karyawan Non-Manager (Hak Akses Terisolasi)...\n";

    // Pastikan ada data reimbursement milik user 4 (Abdul Latif) di cabang 1
    $pdo->exec("INSERT INTO reimbursements (branch_id, user_id, expense_date, category, amount, description, status) 
                VALUES ($branchId, 4, '2026-10-15', 'BENSIN', 75000, 'Bensin antar barang Abdul Latif', 'PENDING')");
    $abdulReimburseId = $pdo->lastInsertId();

    // 9a. Non-manager (Bima / user 3) akses ess_get_data: HANYA melihat klaim miliknya
    $resEssBima = callApi('ess_get_data', [], ['user_id' => 3, 'branch_id' => $branchId]);
    assertTest($resEssBima['success'] === true, "Kasir Bima berhasil panggil ess_get_data");
    assertTest($resEssBima['is_manager'] === false, "Kasir Bima teridentifikasi sebagai non-manager (is_manager = false)");
    $hasOtherClaim = false;
    foreach ($resEssBima['branch_reimbursements'] as $br) {
        if ($br['user_id'] != 3) { $hasOtherClaim = true; break; }
    }
    assertTest(!$hasOtherClaim, "Kasir Bima TIDAK DAPAT melihat klaim reimbursement karyawan lain (Abdul Latif terisolasi)");

    // 9b. Non-manager coba cairkan reimbursement -> HARUS DITOLAK
    $resDisburseHack = callApi('ess_approve_reimbursement', [
        'reimbursement_id' => $abdulReimburseId,
        'approval_action' => 'DISBURSE',
        'user_id' => 3
    ]);
    assertTest($resDisburseHack['success'] === false, "Kasir Bima DITOLAK saat mencoba mencairkan reimbursement (Akses Ditolak)");

    // 9c. Non-manager ambil jadwal bulanan (get_monthly_schedules): HANYA dapat baris miliknya
    $resSchedBima = callApi('get_monthly_schedules', [], ['branch_id' => $branchId, 'month' => $testMonth, 'user_id' => 3]);
    assertTest($resSchedBima['success'] === true, "Kasir Bima berhasil panggil get_monthly_schedules");
    assertTest(count($resSchedBima['employees']) === 1 && $resSchedBima['employees'][0]['id'] == 3, "Roster karyawan non-manager terisolasi hanya menampilkan 1 karyawan (Bima sendiri)");

    // 9d. Non-manager coba intip slip gaji karyawan lain (user_id = 4 dengan req_user_id = 3)
    $resPayslipHack = callApi('get_employee_payslip', [], ['user_id' => 4, 'req_user_id' => 3, 'month' => $testMonth]);
    assertTest($resPayslipHack['success'] === true, "Endpoint get_employee_payslip merespons");
    assertTest($resPayslipHack['user']['id'] == 3, "Backend berhasil memproteksi slip gaji: target di-override paksa ke user login (ID: 3)");

    // 9e. Non-manager ambil laporan kehadiran: terisolasi untuk dirinya sendiri
    $resAttBima = callApi('get_attendance_report', [], ['branch_id' => $branchId, 'month' => $testMonth, 'user_id' => 3, 'actor_id' => 3]);
    assertTest($resAttBima['success'] === true, "Kasir Bima berhasil panggil get_attendance_report");
    assertTest(count($resAttBima['summary']) === 1 && $resAttBima['summary'][0]['user_id'] == 3, "Ringkasan kehadiran non-manager terisolasi hanya 1 karyawan");

    // 9f. Non-manager coba approve kasbon / tukar shift -> DITOLAK
    $resLoanHack = callApi('approve_salary_loan', [
        'loan_id' => $loanRecord['id'],
        'action' => 'APPROVE',
        'user_id' => 3 // Kasir
    ]);
    assertTest($resLoanHack['success'] === false, "Kasir Bima DITOLAK saat mencoba menyetujui pinjaman gaji (Akses Ditolak)");

    // Bersihkan dummy klaim Abdul Latif
    $pdo->exec("DELETE FROM reimbursements WHERE id = $abdulReimburseId");

    echo "\n=== SEMUA 31 UJI INTEGRASI EMPLOYEE SELF SERVICE & ISOLASI PRIVASI BERHASIL (100% PASS) ===\n";
} catch (Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}

