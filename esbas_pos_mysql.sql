-- ========================================================
-- ESBAS POS Database Export (MySQL / MariaDB Compatible)
-- Generated: 2026-10-25 15:11:08
-- ========================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+07:00';

-- --------------------------------------------------------
-- Table structure for `branches`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `branches`;
CREATE TABLE `branches` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `address` TEXT NOT NULL,
    `latitude` DECIMAL(10, 7) NOT NULL,
    `longitude` DECIMAL(10, 7) NOT NULL,
    `radius_meters` INT DEFAULT 100,
    `phone` VARCHAR(255),
    `is_active` TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `branches`
INSERT INTO `branches` (`id`, `code`, `name`, `address`, `latitude`, `longitude`, `radius_meters`, `phone`, `is_active`) VALUES
(1, 'JKT-01', 'Pusat Cimahi', 'Jl. Panembakan No. 47/39', -6.195, 106.823, 100, '021-23580001', 1),
(2, 'BDG-01', 'Cabang Ciganitri', 'Jl. Ciganitri Mukti No. 13', -7.2618, 112.7383, 100, '031-53110002', 1),
(3, 'BDG-02', 'Cabang Buahbatu', 'Jl. Lio Mekarsari', -6.8893, 107.596, 100, '022-82060003', 1),
(6, 'CMH-02', 'Cabang Cimahi 1', 'Jl. Panembakan No 74', -6.879512, 107.529706, 100, '', 1);

-- --------------------------------------------------------
-- Table structure for `categories`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `icon_emoji` VARCHAR(255) DEFAULT '📁',
    `is_stockable` TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `categories`
INSERT INTO `categories` (`id`, `code`, `name`, `icon_emoji`, `is_stockable`) VALUES
(1, 'PRODUKJUAL', 'Produk Jual', '🏷️', 1),
(4, 'CAT-RAW', 'Bahan Baku (Raw Material)', '🌾', 1),
(8, 'KONSINYASI', 'Konsinyasi', '🏷️', 1);

-- --------------------------------------------------------
-- Table structure for `product_types`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `product_types`;
CREATE TABLE `product_types` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `prefix` VARCHAR(255) NOT NULL,
    `is_stockable` TINYINT(1) DEFAULT 1,
    `is_saleable` TINYINT(1) DEFAULT 1,
    `icon_emoji` VARCHAR(255) DEFAULT '📦',
    `description` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `product_types`
INSERT INTO `product_types` (`id`, `code`, `name`, `prefix`, `is_stockable`, `is_saleable`, `icon_emoji`, `description`, `created_at`) VALUES
(1, 'FINISHED_GOODS', 'Barang Jadi', 'PRD', 1, 1, '☕', 'Produk jadi siap jual langsung di kasir POS', '2026-10-11 04:13:40'),
(2, 'RAW_MATERIAL', 'Bahan Baku', 'RAW', 1, 0, '🌾', 'Bahan baku untuk formula resep (BOM)', '2026-10-11 04:13:40'),
(3, 'SERVICE', 'Jasa / Layanan', 'SRV', 0, 1, '🛠️', 'Jasa atau ongkos kirim tanpa stok fisik', '2026-10-11 04:13:40');

-- --------------------------------------------------------
-- Table structure for `product_units`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `product_units`;
CREATE TABLE `product_units` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `product_units`
INSERT INTO `product_units` (`id`, `code`, `name`, `description`, `is_active`, `created_at`) VALUES
(1, 'pcs', 'Pieces / Buah', 'Satuan butir/buah', 1, '2026-10-11 04:13:40'),
(3, 'galon', 'Galon', 'Satuan kemasan Galon', 1, '2026-10-11 04:13:40'),
(5, 'gram', 'Gram (g)', 'Satuan berat gram', 1, '2026-10-11 04:13:40'),
(7, 'ml', 'Mililiter (ml)', 'Satuan volume mililiter', 1, '2026-10-11 04:13:40'),
(8, 'liter', 'Liter (L)', 'Satuan volume liter', 1, '2026-10-11 04:13:40'),
(13, 'tabung', 'Tabung', '', 1, '2026-10-11 05:11:32'),
(14, 'ball', 'Ball', '', 1, '2026-10-11 06:17:37');

-- --------------------------------------------------------
-- Table structure for `taxes`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `taxes`;
CREATE TABLE `taxes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `rate_percent` DECIMAL(8, 4) NOT NULL,
    `description` TEXT,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `taxes`
INSERT INTO `taxes` (`id`, `code`, `name`, `rate_percent`, `description`, `is_active`, `created_at`) VALUES
(1, 'PPN11', 'PPN 11%', 11, 'Pajak Pertambahan Nilai Standar Indonesia 11%', 0, '2026-10-10 12:30:46'),
(2, 'PB1-10', 'PB1 Resto 10%', 10, 'Pajak Daerah / Restoran dan Hiburan 10%', 0, '2026-10-10 12:30:46'),
(3, 'TAX-0', 'Bebas Pajak (0%)', 0, 'Barang Bebas Pajak / Non-BKP', 0, '2026-10-10 12:30:46');

-- --------------------------------------------------------
-- Table structure for `payment_methods`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `payment_methods`;
CREATE TABLE `payment_methods` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `category` VARCHAR(255) DEFAULT 'OTHER',
    `account_number` VARCHAR(255),
    `account_holder` VARCHAR(255),
    `description` TEXT,
    `is_cash` TINYINT(1) DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `sort_order` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `payment_methods`
INSERT INTO `payment_methods` (`id`, `code`, `name`, `category`, `account_number`, `account_holder`, `description`, `is_cash`, `is_active`, `sort_order`, `created_at`) VALUES
(1, 'CASH', 'Tunai (Cash)', 'CASH', '-', 'Kasir Toko', 'Pembayaran tunai uang kertas dan koin', 1, 1, 1, '2026-10-10 13:06:56'),
(2, 'QRIS', 'QRIS Dinamis', 'E-WALLET', 'NMID-9382103810', 'ESBAS RETAIL GROUP', 'Scan QRIS GoPay, OVO, Dana, ShopeePay, BCA', 0, 0, 2, '2026-10-10 13:06:56'),
(3, 'DEBIT', 'Kartu Debit (EDC)', 'CARD', 'EDC-BCA-01', 'Bank Central Asia', 'Kartu debit EDC GPN / Mastercard / Visa', 0, 0, 3, '2026-10-10 13:06:56'),
(4, 'TRANSFER', 'Transfer Bank', 'BANK_TRANSFER', '8830192831', 'PT ESBAS MULTI RETAIL', 'Transfer via BCA, Mandiri, BRI, BNI', 0, 1, 4, '2026-10-10 13:06:56'),
(7, 'PIUTANG', 'Piutang Cust', 'OTHER', '', '', '', 0, 1, 5, '2026-10-11 00:54:49');

-- --------------------------------------------------------
-- Table structure for `work_shifts`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `work_shifts`;
CREATE TABLE `work_shifts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `start_time` VARCHAR(255) NOT NULL,
    `end_time` VARCHAR(255) NOT NULL,
    `late_tolerance_mins` INT DEFAULT 15,
    `is_day_off` TINYINT(1) DEFAULT 0,
    `color_tag` VARCHAR(255) DEFAULT '#3b82f6',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `work_shifts`
INSERT INTO `work_shifts` (`id`, `code`, `name`, `start_time`, `end_time`, `late_tolerance_mins`, `is_day_off`, `color_tag`, `is_active`, `created_at`) VALUES
(1, 'S1', 'Shift 1 (Pagi)', '07:00', '17:00', 10, 0, '#3b82f6', 1, '2026-10-12 11:56:06'),
(2, 'S2', 'Shift 2 (Siang/Sore)', '14:30', '22:30', 15, 0, '#8b5cf6', 0, '2026-10-12 11:56:06'),
(3, 'SM', 'Shift Middle', '08:00', '18:00', 10, 0, '#06b6d4', 1, '2026-10-12 11:56:06'),
(4, 'OFF', 'Libur / Off Day', '00:00', '00:00', 0, 1, '#94a3b8', 1, '2026-10-12 11:56:06'),
(13, 'SX', 'Shift Malam Ekstra', '23:00', '07:00', 20, 0, '#0f172a', 1, '2026-10-12 13:18:54');

-- --------------------------------------------------------
-- Table structure for `customer_categories`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `customer_categories`;
CREATE TABLE `customer_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `default_tier` VARCHAR(255) DEFAULT 'REGULER',
    `description` TEXT,
    `icon_emoji` VARCHAR(255) DEFAULT '👥',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `customer_categories`
INSERT INTO `customer_categories` (`id`, `code`, `name`, `default_tier`, `description`, `icon_emoji`, `is_active`, `created_at`) VALUES
(1, 'REGULER', 'Pelanggan Reguler', 'REGULER', 'Pelanggan umum ritel non-member tanpa diskon khusus tier', '👤', 1, '2026-10-11 14:43:56'),
(2, 'PREMIUM', 'PREMIUM', 'MEMBER', 'Pelanggan terdaftar dengan fasilitas harga tier member & poin', '⭐', 1, '2026-10-11 14:43:56'),
(3, 'RESELLER', 'Reseller / Agen', 'RESELLER', 'Mitra pengecer dengan fasilitas harga khusus tier reseller/grosir', '🛍️', 0, '2026-10-11 14:43:56'),
(4, 'MITRA', 'Mitra Bisnis B2B', 'MITRA', 'Mitra korporasi/B2B dengan fasilitas kontrak harga khusus', '🏢', 1, '2026-10-11 14:43:56');

-- --------------------------------------------------------
-- Table structure for `expense_categories`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `expense_categories`;
CREATE TABLE `expense_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `group_type` VARCHAR(255) DEFAULT 'OPERASIONAL',
    `description` TEXT,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `expense_categories`
INSERT INTO `expense_categories` (`id`, `code`, `name`, `group_type`, `description`, `is_active`, `created_at`) VALUES
(1, 'EXP-GAJI', 'Beban Gaji & Upah Karyawan', 'UMUM_ADMIN', 'Gaji pokok, lembur, dan tunjangan staf outlet', 1, '2026-10-11 16:25:50'),
(2, 'EXP-UTIL', 'Beban Listrik, Air & Utilitas', 'OPERASIONAL', 'Token listrik PLN, tagihan air PDAM, dan utilitas cabang', 1, '2026-10-11 16:25:50'),
(3, 'EXP-SEWA', 'Beban Sewa Tempat / Gedung', 'OPERASIONAL', 'Sewa ruko, kios, atau sewa ruang outlet bulanan/tahunan', 1, '2026-10-11 16:25:50'),
(4, 'EXP-INET', 'Beban Internet & Komunikasi', 'OPERASIONAL', 'Langganan WiFi toko dan pulsa komunikasi operasional', 1, '2026-10-11 16:25:50'),
(5, 'EXP-ATK', 'Beban Perlengkapan Toko & Kemasan', 'OPERASIONAL', 'Kertas struk kasir, plastik kantong, isolasi, lakban, dan ATK', 1, '2026-10-11 16:25:50'),
(6, 'EXP-KEBERSIHAN', 'Beban Kebersihan & Keamanan', 'OPERASIONAL', 'Iuran kebersihan sampah lingkungan dan keamanan warga', 1, '2026-10-11 16:25:50'),
(7, 'EXP-PROMOSI', 'Beban Pemasaran & Promosi', 'PENJUALAN', 'Spanduk promo, brosur cetak, dan iklan media sosial lokal', 1, '2026-10-11 16:25:50'),
(8, 'EXP-RETRIBUSI', 'Beban Pajak & Retribusi Daerah', 'UMUM_ADMIN', 'Retribusi sampah dinas, izin usaha daerah, dan pungutan resmi', 1, '2026-10-11 16:25:50'),
(9, 'EXP-RAWAT', 'Beban Pemeliharaan & Reparasi Toko', 'OPERASIONAL', 'Perbaikan rak display, perbaikan AC toko, servis kelistrikan', 1, '2026-10-11 16:25:50'),
(10, 'EXP-BBM', 'Beban Bahan Bakar (BBM) Kurir', 'PENJUALAN', 'Bensin pengiriman delivery pesanan armada kurir', 1, '2026-10-11 16:25:50'),
(11, 'EXP-SERVIS', 'Beban Servis & Perawatan Armada Kurir', 'PENJUALAN', 'Perawatan berkala dan servis motor armada outlet', 1, '2026-10-11 16:25:50'),
(12, 'EXP-REIMBURSE', 'Beban Reimbursement Petty Cash ESS', 'OPERASIONAL', 'Klaim kas kecil darurat yang diajukan staf via ESS', 1, '2026-10-11 16:25:50'),
(13, 'EXP-LAIN', 'Beban Operasional Lain-lain', 'LAINNYA', 'Pengeluaran operasional cabang non-rutin lainnya', 1, '2026-10-11 16:25:50');

-- --------------------------------------------------------
-- Table structure for `app_settings`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `app_settings`;
CREATE TABLE `app_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` LONGTEXT,
    `setting_group` VARCHAR(255) DEFAULT 'GENERAL',
    `description` TEXT,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `app_settings`
INSERT INTO `app_settings` (`id`, `setting_key`, `setting_value`, `setting_group`, `description`, `updated_at`) VALUES
(1, 'app_name', 'STARGROUP', 'GENERAL', 'Nama resmi aplikasi POS', '2026-10-23 10:26:23'),
(2, 'app_subtitle', 'Solusi POS & Manajemen Modern', 'GENERAL', 'Subjudul / slogan aplikasi', '2026-10-23 10:26:23'),
(3, 'app_logo_emoji', '⚡', 'GENERAL', 'Logo emoji / ikon aplikasi', '2026-10-23 10:26:23'),
(4, 'app_logo_url', '', 'GENERAL', 'URL / path logo gambar kustom (opsional)', '2026-10-12 12:40:25'),
(5, 'company_name', 'Karya Anugerah Dimensi Technology', 'GENERAL', 'Nama perusahaan atau institusi', '2026-10-23 10:26:23'),
(6, 'company_address', 'Jl. Ciganitri Mukti V No. 13', 'GENERAL', 'Alamat kantor pusat', '2026-10-23 10:26:23'),
(7, 'company_phone', '081220169030', 'GENERAL', 'Nomor telepon resmi toko', '2026-10-23 10:26:23'),
(8, 'printer_type', 'BROWSER', 'PRINTER', 'Tipe printer: BROWSER, USB, IP_NETWORK, BLUETOOTH', '2026-10-23 10:26:23'),
(9, 'printer_ip_address', '192.168.1.200', 'PRINTER', 'Alamat IP Printer Network', '2026-10-23 10:26:23'),
(10, 'printer_ip_port', '9100', 'PRINTER', 'Port Raw ESC/POS Printer (default 9100)', '2026-10-23 10:26:23'),
(11, 'printer_paper_width', '80mm', 'PRINTER', 'Lebar kertas kasir: 58mm atau 80mm', '2026-10-23 10:26:23'),
(12, 'printer_auto_cut', '1', 'PRINTER', 'Potong kertas otomatis setelah cetak: 1=Ya, 0=Tidak', '2026-10-23 10:26:23'),
(13, 'printer_cash_drawer', '1', 'PRINTER', 'Buka laci kasir otomatis saat cetak nota: 1=Ya, 0=Tidak', '2026-10-23 10:26:23'),
(14, 'receipt_header_title', 'ESBAS COFFEE & EATERY', 'RECEIPT', 'Judul paling atas struk belanja', '2026-10-23 10:26:23'),
(15, 'receipt_header_subtitle', 'Jl. Boulevard Raya No. 88, Jakarta', 'RECEIPT', 'Subjudul header struk', '2026-10-23 10:26:23'),
(16, 'receipt_header_custom_text', 'NPWP: 01.234.567.8-999.000', 'RECEIPT', 'Teks tambahan di bawah alamat cabang', '2026-10-23 10:26:23'),
(17, 'receipt_show_logo', '1', 'RECEIPT', 'Tampilkan logo/emoji pada struk: 1=Ya, 0=Tidak', '2026-10-23 10:26:23'),
(18, 'receipt_body_show_sku', '1', 'RECEIPT', 'Tampilkan SKU produk pada baris item: 1=Ya, 0=Tidak', '2026-10-23 10:26:23'),
(19, 'receipt_body_show_cogs', '0', 'RECEIPT', 'Tampilkan HPP (khusus audit internal): 1=Ya, 0=Tidak', '2026-10-12 12:40:25'),
(20, 'receipt_body_compact_spacing', '0', 'RECEIPT', 'Gunakan spasi rapat hemat kertas: 1=Ya, 0=Tidak', '2026-10-23 10:26:23'),
(21, 'receipt_footer_line1', 'Terima kasih atas kunjungan Anda!', 'RECEIPT', 'Baris teks ucapan footer 1', '2026-10-23 10:26:23'),
(22, 'receipt_footer_line2', 'Barang yang sudah dibeli tidak dapat ditukar', 'RECEIPT', 'Baris teks ketentuan retur footer 2', '2026-10-23 10:26:23'),
(23, 'receipt_footer_line3', 'Instagram: @esbaspos.official', 'RECEIPT', 'Baris catatan footer 3', '2026-10-23 10:26:23'),
(24, 'receipt_footer_qr_text', 'https://esbas.id/feedback', 'RECEIPT', 'Teks / tautan untuk QR Code feedback belanja', '2026-10-12 12:40:25'),
(25, 'payslip_header_title', 'SLIP GAJI KARYAWAN RESMI', 'PAYSLIP', 'Judul dokumen resmi slip gaji', '2026-10-23 10:26:23'),
(26, 'payslip_company_name', 'PT ESBAS RETAIL CORPORINDO', 'PAYSLIP', 'Nama badan usaha pada slip gaji', '2026-10-23 10:26:23'),
(27, 'payslip_confidential_label', 'RAHASIA & PRIBADI (CONFIDENTIAL)', 'PAYSLIP', 'Label kerahasiaan slip gaji', '2026-10-23 10:26:23'),
(28, 'payslip_show_terbilang', '1', 'PAYSLIP', 'Tampilkan ejaan teks terbilang rupiah: 1=Ya, 0=Tidak', '2026-10-23 10:26:23'),
(29, 'payslip_show_attendance_detail', '1', 'PAYSLIP', 'Tampilkan rincian hari kerja & hadir: 1=Ya, 0=Tidak', '2026-10-23 10:26:23'),
(30, 'payslip_show_loan_detail', '1', 'PAYSLIP', 'Tampilkan rincian sisa pinjaman kasbon: 1=Ya, 0=Tidak', '2026-10-12 12:40:25'),
(31, 'payslip_signer_1_title', 'Finance & Payroll Lead', 'PAYSLIP', 'Jabatan penanda tangan kolom kiri', '2026-10-23 10:26:23'),
(32, 'payslip_signer_2_title', 'Head of Human Resources', 'PAYSLIP', 'Jabatan penanda tangan kolom kanan', '2026-10-23 10:26:23'),
(33, 'payslip_footer_notes', 'Slip gaji ini dicetak secara komputerisasi dan sah tanpa tanda tangan basah bila disertai stempel verifikasi.', 'PAYSLIP', 'Catatan kaki lembar slip gaji', '2026-10-23 10:26:23'),
(34, 'wa_gateway_provider', 'FONNTE', 'NOTIFICATION', 'Penyedia gateway WhatsApp: FONNTE, WANOTIF, WA_DIRECT', '2026-10-23 10:26:23'),
(35, 'wa_api_url', 'https://api.fonnte.com/send', 'NOTIFICATION', 'Endpoint API WhatsApp Gateway', '2026-10-23 10:26:23'),
(36, 'wa_api_token', 'secret_dummy_fonnte_token_12345', 'NOTIFICATION', 'API Key / Token WhatsApp Gateway', '2026-10-23 10:26:23'),
(37, 'wa_auto_send_on_checkout', '0', 'NOTIFICATION', 'Kirim nota WA otomatis setiap checkout kasir: 1=Ya, 0=Tidak', '2026-10-12 12:40:25'),
(38, 'wa_template_message', 'Halo {customer_name}, terima kasih telah berbelanja di {store_name}!\nNo Struk: {invoice_number}\nTotal: {grand_total}\nTanggal: {date}\nSemoga harimu menyenangkan!', 'NOTIFICATION', 'Template pesan WhatsApp', '2026-10-23 10:26:23'),
(39, 'telegram_bot_token', '1234567890:ABCdefGHIjklMNOpqrsTUVwxyz', 'NOTIFICATION', 'Token Bot Telegram dari @BotFather', '2026-10-23 10:26:23'),
(40, 'telegram_chat_id', '-100987654321', 'NOTIFICATION', 'ID Chat / Channel Telegram Toko', '2026-10-23 10:26:23'),
(41, 'telegram_auto_send_on_checkout', '0', 'NOTIFICATION', 'Kirim nota Telegram otomatis ke channel/owner: 1=Ya, 0=Tidak', '2026-10-12 12:40:25'),
(42, 'telegram_template_message', '🧾 *NOTA TRANSAKSI {store_name}*\nPelanggan: {customer_name}\nKasir: {cashier_name}\nNo: `{invoice_number}`\nTotal: *{grand_total}*\nTerima Kasih!', 'NOTIFICATION', 'Template pesan Telegram', '2026-10-23 10:26:23'),
(211, 'payslip_legal_footer_note', 'Dokumen ini dibuat otomatis dan sah tanpa tanda tangan basah.', 'GENERAL', 'Custom Setting', '2026-10-12 21:27:34');

-- --------------------------------------------------------
-- Table structure for `role_menu_permissions`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `role_menu_permissions`;
CREATE TABLE `role_menu_permissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `role` VARCHAR(255) NOT NULL,
    `menu_key` VARCHAR(255) NOT NULL,
    `menu_title` VARCHAR(255) NOT NULL,
    `can_access` INT DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `role_menu_permissions`
INSERT INTO `role_menu_permissions` (`id`, `role`, `menu_key`, `menu_title`, `can_access`, `created_at`, `updated_at`) VALUES
(1, 'SUPERADMIN', 'pos', '1. POS Penjualan Kasir', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(2, 'SUPERADMIN', 'reports', '2. Laporan Penjualan & Piutang', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(3, 'SUPERADMIN', 'ess', '3. Portal ESS Karyawan', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(4, 'SUPERADMIN', 'employees', '4. Kelola Karyawan (CRUD)', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(5, 'SUPERADMIN', 'products', '5. Master Produk & Varian', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(6, 'SUPERADMIN', 'bom', '6. Resep / BOM Bahan', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(7, 'SUPERADMIN', 'stocks', '7. Stok & Kartu Mutasi', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(8, 'SUPERADMIN', 'pnl', '8. Laporan Laba Rugi', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(9, 'SUPERADMIN', 'expenses', '9. Beban Operasional Cabang', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(10, 'SUPERADMIN', 'customers', '10. Pelanggan & Mitra CRM', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(11, 'SUPERADMIN', 'logistics', '11. Kurir & Biaya Kirim', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(12, 'SUPERADMIN', 'branches', '12. Kelola Cabang (CRUD)', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(13, 'SUPERADMIN', 'payments', '13. Metode Pembayaran', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(14, 'SUPERADMIN', 'sale-cancellations', '14. Pembatalan Penjualan (Void)', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(15, 'SUPERADMIN', 'settings', '15. Konfigurasi Sistem & Cetak', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(16, 'MANAGER', 'pos', '1. POS Penjualan Kasir', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(17, 'MANAGER', 'reports', '2. Laporan Penjualan & Piutang', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(18, 'MANAGER', 'ess', '3. Portal ESS Karyawan', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(19, 'MANAGER', 'employees', '4. Kelola Karyawan (CRUD)', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(20, 'MANAGER', 'products', '5. Master Produk & Varian', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(21, 'MANAGER', 'bom', '6. Resep / BOM Bahan', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(22, 'MANAGER', 'stocks', '7. Stok & Kartu Mutasi', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(23, 'MANAGER', 'pnl', '8. Laporan Laba Rugi', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(24, 'MANAGER', 'expenses', '9. Beban Operasional Cabang', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(25, 'MANAGER', 'customers', '10. Pelanggan & Mitra CRM', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(26, 'MANAGER', 'logistics', '11. Kurir & Biaya Kirim', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(27, 'MANAGER', 'branches', '12. Kelola Cabang (CRUD)', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(28, 'MANAGER', 'payments', '13. Metode Pembayaran', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(29, 'MANAGER', 'sale-cancellations', '14. Pembatalan Penjualan (Void)', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(30, 'MANAGER', 'settings', '15. Konfigurasi Sistem & Cetak', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(31, 'CASHIER', 'pos', '1. POS Penjualan Kasir', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(32, 'CASHIER', 'reports', '2. Laporan Penjualan & Piutang', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(33, 'CASHIER', 'ess', '3. Portal ESS Karyawan', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(34, 'CASHIER', 'employees', '4. Kelola Karyawan (CRUD)', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(35, 'CASHIER', 'products', '5. Master Produk & Varian', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(36, 'CASHIER', 'bom', '6. Resep / BOM Bahan', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(37, 'CASHIER', 'stocks', '7. Stok & Kartu Mutasi', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(38, 'CASHIER', 'pnl', '8. Laporan Laba Rugi', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(39, 'CASHIER', 'expenses', '9. Beban Operasional Cabang', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(40, 'CASHIER', 'customers', '10. Pelanggan & Mitra CRM', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(41, 'CASHIER', 'logistics', '11. Kurir & Biaya Kirim', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(42, 'CASHIER', 'branches', '12. Kelola Cabang (CRUD)', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(43, 'CASHIER', 'payments', '13. Metode Pembayaran', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(44, 'CASHIER', 'sale-cancellations', '14. Pembatalan Penjualan (Void)', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(45, 'CASHIER', 'settings', '15. Konfigurasi Sistem & Cetak', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(46, 'STAFF', 'pos', '1. POS Penjualan Kasir', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(47, 'STAFF', 'reports', '2. Laporan Penjualan & Piutang', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(48, 'STAFF', 'ess', '3. Portal ESS Karyawan', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(49, 'STAFF', 'employees', '4. Kelola Karyawan (CRUD)', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(50, 'STAFF', 'products', '5. Master Produk & Varian', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(51, 'STAFF', 'bom', '6. Resep / BOM Bahan', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(52, 'STAFF', 'stocks', '7. Stok & Kartu Mutasi', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(53, 'STAFF', 'pnl', '8. Laporan Laba Rugi', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(54, 'STAFF', 'expenses', '9. Beban Operasional Cabang', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(55, 'STAFF', 'customers', '10. Pelanggan & Mitra CRM', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(56, 'STAFF', 'logistics', '11. Kurir & Biaya Kirim', 1, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(57, 'STAFF', 'branches', '12. Kelola Cabang (CRUD)', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(58, 'STAFF', 'payments', '13. Metode Pembayaran', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(59, 'STAFF', 'sale-cancellations', '14. Pembatalan Penjualan (Void)', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(60, 'STAFF', 'settings', '15. Konfigurasi Sistem & Cetak', 0, '2026-10-12 12:40:25', '2026-10-24 15:40:13'),
(301, '0', 'role', 'role', 1, '2026-10-12 12:43:15', '2026-10-12 19:43:15'),
(302, '0', 'menu_key', 'menu_key', 1, '2026-10-12 12:43:15', '2026-10-12 19:43:15'),
(303, '0', 'menu_title', 'menu_title', 1, '2026-10-12 12:43:15', '2026-10-12 19:43:15'),
(304, '0', 'can_access', 'can_access', 1, '2026-10-12 12:43:15', '2026-10-12 19:43:15'),
(305, '1', 'role', 'role', 1, '2026-10-12 12:43:15', '2026-10-12 19:43:15'),
(306, '1', 'menu_key', 'menu_key', 1, '2026-10-12 12:43:15', '2026-10-12 19:43:15'),
(307, '1', 'menu_title', 'menu_title', 1, '2026-10-12 12:43:15', '2026-10-12 19:43:15'),
(308, '1', 'can_access', 'can_access', 0, '2026-10-12 12:43:15', '2026-10-12 19:43:15'),
(309, '2', 'role', 'role', 1, '2026-10-12 12:43:15', '2026-10-12 19:43:15'),
(310, '2', 'menu_key', 'menu_key', 1, '2026-10-12 12:43:15', '2026-10-12 19:43:15'),
(311, '2', 'menu_title', 'menu_title', 1, '2026-10-12 12:43:15', '2026-10-12 19:43:15'),
(312, '2', 'can_access', 'can_access', 0, '2026-10-12 12:43:15', '2026-10-12 19:43:15'),
(313, 'SUPER_ADMIN', 'pos', '1. POS Penjualan Kasir', 1, '2026-10-23 18:03:20', '2026-10-24 15:40:13'),
(314, 'SUPER_ADMIN', 'reports', '2. Laporan Penjualan & Piutang', 1, '2026-10-23 18:03:20', '2026-10-24 15:40:13'),
(315, 'SUPER_ADMIN', 'ess', '3. Portal ESS Karyawan', 1, '2026-10-23 18:03:20', '2026-10-24 15:40:13'),
(316, 'SUPER_ADMIN', 'employees', '4. Kelola Karyawan (CRUD)', 1, '2026-10-23 18:03:20', '2026-10-24 15:40:13'),
(317, 'SUPER_ADMIN', 'products', '5. Master Produk & Varian', 1, '2026-10-23 18:03:20', '2026-10-24 15:40:13'),
(318, 'SUPER_ADMIN', 'bom', '6. Resep / BOM Bahan', 1, '2026-10-23 18:03:20', '2026-10-24 15:40:13'),
(319, 'SUPER_ADMIN', 'stocks', '7. Stok & Kartu Mutasi', 1, '2026-10-23 18:03:20', '2026-10-24 15:40:13'),
(320, 'SUPER_ADMIN', 'pnl', '8. Laporan Laba Rugi', 1, '2026-10-23 18:03:20', '2026-10-24 15:40:13'),
(321, 'SUPER_ADMIN', 'expenses', '9. Beban Operasional Cabang', 1, '2026-10-23 18:03:20', '2026-10-24 15:40:13'),
(322, 'SUPER_ADMIN', 'customers', '10. Pelanggan & Mitra CRM', 1, '2026-10-23 18:03:20', '2026-10-24 15:40:13'),
(323, 'SUPER_ADMIN', 'logistics', '11. Kurir & Biaya Kirim', 1, '2026-10-23 18:03:20', '2026-10-24 15:40:13'),
(324, 'SUPER_ADMIN', 'branches', '12. Kelola Cabang (CRUD)', 1, '2026-10-23 18:03:20', '2026-10-24 15:40:13'),
(325, 'SUPER_ADMIN', 'payments', '13. Metode Pembayaran', 1, '2026-10-23 18:03:20', '2026-10-24 15:40:13'),
(326, 'SUPER_ADMIN', 'sale-cancellations', '14. Pembatalan Penjualan (Void)', 1, '2026-10-23 18:03:20', '2026-10-24 15:40:13'),
(327, 'SUPER_ADMIN', 'settings', '15. Konfigurasi Sistem & Cetak', 1, '2026-10-23 18:03:20', '2026-10-24 15:40:13'),
(328, 'BRANCH_MANAGER', 'pos', '1. POS Penjualan Kasir', 1, '2026-10-23 18:03:21', '2026-10-24 15:40:13'),
(329, 'BRANCH_MANAGER', 'reports', '2. Laporan Penjualan & Piutang', 1, '2026-10-23 18:03:21', '2026-10-24 15:40:13'),
(330, 'BRANCH_MANAGER', 'ess', '3. Portal ESS Karyawan', 1, '2026-10-23 18:03:21', '2026-10-24 15:40:13'),
(331, 'BRANCH_MANAGER', 'employees', '4. Kelola Karyawan (CRUD)', 1, '2026-10-23 18:03:21', '2026-10-24 15:40:13'),
(332, 'BRANCH_MANAGER', 'products', '5. Master Produk & Varian', 1, '2026-10-23 18:03:21', '2026-10-24 15:40:13'),
(333, 'BRANCH_MANAGER', 'bom', '6. Resep / BOM Bahan', 1, '2026-10-23 18:03:21', '2026-10-24 15:40:13'),
(334, 'BRANCH_MANAGER', 'stocks', '7. Stok & Kartu Mutasi', 1, '2026-10-23 18:03:21', '2026-10-24 15:40:13'),
(335, 'BRANCH_MANAGER', 'pnl', '8. Laporan Laba Rugi', 1, '2026-10-23 18:03:21', '2026-10-24 15:40:13'),
(336, 'BRANCH_MANAGER', 'expenses', '9. Beban Operasional Cabang', 1, '2026-10-23 18:03:21', '2026-10-24 15:40:13'),
(337, 'BRANCH_MANAGER', 'customers', '10. Pelanggan & Mitra CRM', 1, '2026-10-23 18:03:21', '2026-10-24 15:40:13'),
(338, 'BRANCH_MANAGER', 'logistics', '11. Kurir & Biaya Kirim', 1, '2026-10-23 18:03:21', '2026-10-24 15:40:13'),
(339, 'BRANCH_MANAGER', 'branches', '12. Kelola Cabang (CRUD)', 0, '2026-10-23 18:03:21', '2026-10-24 15:40:13'),
(340, 'BRANCH_MANAGER', 'payments', '13. Metode Pembayaran', 0, '2026-10-23 18:03:21', '2026-10-24 15:40:13');
INSERT INTO `role_menu_permissions` (`id`, `role`, `menu_key`, `menu_title`, `can_access`, `created_at`, `updated_at`) VALUES
(341, 'BRANCH_MANAGER', 'sale-cancellations', '14. Pembatalan Penjualan (Void)', 0, '2026-10-23 18:03:21', '2026-10-24 15:40:13'),
(342, 'BRANCH_MANAGER', 'settings', '15. Konfigurasi Sistem & Cetak', 1, '2026-10-23 18:03:21', '2026-10-24 15:40:13'),
(88423, 'BRANCHMANAGER', 'pos', '1. POS Penjualan Kasir', 1, '2026-10-23 06:43:47', '2026-10-23 14:32:53'),
(88424, 'BRANCHMANAGER', 'reports', '2. Laporan Penjualan & Piutang', 1, '2026-10-23 06:43:47', '2026-10-23 14:32:53'),
(88425, 'BRANCHMANAGER', 'ess', '3. Portal ESS Karyawan', 1, '2026-10-23 06:43:47', '2026-10-23 14:32:53'),
(88426, 'BRANCHMANAGER', 'employees', '4. Kelola Karyawan (CRUD)', 1, '2026-10-23 06:43:47', '2026-10-23 14:32:53'),
(88427, 'BRANCHMANAGER', 'products', '5. Master Produk & Varian', 1, '2026-10-23 06:43:47', '2026-10-23 14:32:53'),
(88428, 'BRANCHMANAGER', 'bom', '6. Resep / BOM Bahan', 1, '2026-10-23 06:43:47', '2026-10-23 14:32:53'),
(88429, 'BRANCHMANAGER', 'stocks', '7. Stok & Kartu Mutasi', 1, '2026-10-23 06:43:47', '2026-10-23 14:32:53'),
(88430, 'BRANCHMANAGER', 'pnl', '8. Laporan Laba Rugi', 1, '2026-10-23 06:43:47', '2026-10-23 14:32:53'),
(88431, 'BRANCHMANAGER', 'expenses', '9. Beban Operasional Cabang', 1, '2026-10-23 06:43:47', '2026-10-23 14:32:53'),
(88432, 'BRANCHMANAGER', 'customers', '10. Pelanggan & Mitra CRM', 1, '2026-10-23 06:43:47', '2026-10-23 14:32:53'),
(88433, 'BRANCHMANAGER', 'logistics', '11. Kurir & Biaya Kirim', 1, '2026-10-23 06:43:47', '2026-10-23 14:32:53'),
(88434, 'BRANCHMANAGER', 'branches', '12. Kelola Cabang (CRUD)', 1, '2026-10-23 06:43:47', '2026-10-23 14:32:53'),
(88435, 'BRANCHMANAGER', 'payments', '13. Metode Pembayaran', 1, '2026-10-23 06:43:47', '2026-10-23 14:32:53'),
(88436, 'BRANCHMANAGER', 'sale-cancellations', '14. Pembatalan Penjualan (Void)', 0, '2026-10-23 06:43:47', '2026-10-23 14:32:53'),
(88437, 'BRANCHMANAGER', 'settings', '15. Konfigurasi Sistem & Cetak', 1, '2026-10-23 06:43:47', '2026-10-23 14:32:53');

-- --------------------------------------------------------
-- Table structure for `users`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nik` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `role` VARCHAR(255) NOT NULL,
    `branch_id` INT,
    `email` VARCHAR(191),
    `phone` VARCHAR(255),
    `pin` VARCHAR(255) DEFAULT '123456',
    `commission_rate` DECIMAL(8, 4) DEFAULT 0.01,
    `password` VARCHAR(255) DEFAULT 'password123',
    `is_active` TINYINT(1) DEFAULT 1,
    `base_salary` DECIMAL(15, 2) DEFAULT 3000000,
    `allowance` DECIMAL(15, 2) DEFAULT 500000,
    `daily_transport` DECIMAL(15, 2) DEFAULT 25000,
    `default_work_shift_id` INT DEFAULT NULL,
    `assigned_vehicle_id` INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `users`
INSERT INTO `users` (`id`, `nik`, `name`, `role`, `branch_id`, `email`, `phone`, `pin`, `commission_rate`, `password`, `is_active`, `base_salary`, `allowance`, `daily_transport`, `default_work_shift_id`, `assigned_vehicle_id`) VALUES
(1, 'ADM001', 'Muhamad Ismail', 'SUPER_ADMIN', 1, 'mailmamenster@gmail.com', '08111000111', '999999', 0, '999999', 1, 3000000, 500000, 25000, NULL, NULL),
(2, 'MGR001', 'Nina Indrayani', 'SUPER_ADMIN', 1, 'hendra@jkt.retail.com', '08122000222', '999999', 0, '999999', 1, 3000000, 500000, 25000, NULL, NULL),
(3, 'CSR001', 'Bima', 'CASHIER', 1, 'bima@gmail.com', '08133000333', '123456', 0, 'password123', 1, 3000000, 500000, 25000, 1, 1),
(4, 'CSR002', 'Abdul Latif', 'CASHIER', 1, 'abdul@gmail.com', '0822113344', '123456', 0.015, 'password123', 1, 3000000, 500000, 25000, 1, 3),
(5, 'CSR003', 'Shafiqa', 'CASHIER', 3, 'anisa@sby.retail.com', '08155000555', '123456', 0, 'password123', 1, 3000000, 500000, 25000, 1, 4),
(6, 'STF001', 'Keenan', 'STAFF', 3, 'rian@jkt.retail.com', '08166000666', '123456', 0, 'password123', 1, 3000000, 500000, 25000, 1, 2);

-- --------------------------------------------------------
-- Table structure for `products`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sku` VARCHAR(100) NOT NULL,
    `barcode` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `category` VARCHAR(255) NOT NULL,
    `unit` VARCHAR(255) DEFAULT 'pcs',
    `cogs` DECIMAL(15, 2) NOT NULL,
    `selling_price` DECIMAL(15, 2) NOT NULL,
    `image_emoji` VARCHAR(255) DEFAULT '📦',
    `product_type` VARCHAR(255) DEFAULT 'FINISHED_GOODS',
    `has_bom` TINYINT(1) DEFAULT 0,
    `category_id` INT DEFAULT 1,
    `reorder_point` INT DEFAULT 10,
    `is_active` TINYINT(1) DEFAULT 1,
    `member_price` DECIMAL(15, 2) DEFAULT 0,
    `reseller_price` DECIMAL(15, 2) DEFAULT 0,
    `mitra_price` DECIMAL(15, 2) DEFAULT 0,
    `visible_categories` VARCHAR(255) DEFAULT 'ALL',
    `is_taxable` TINYINT(1) DEFAULT 1,
    `tax_id` INT DEFAULT 1,
    `tax_percent` DECIMAL(8, 4) DEFAULT 11.0,
    `is_delivery_required` TINYINT(1) DEFAULT 0,
    `is_delivery_included` TINYINT(1) DEFAULT 0,
    `is_stockable` TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `products`
INSERT INTO `products` (`id`, `sku`, `barcode`, `name`, `category`, `unit`, `cogs`, `selling_price`, `image_emoji`, `product_type`, `has_bom`, `category_id`, `reorder_point`, `is_active`, `member_price`, `reseller_price`, `mitra_price`, `visible_categories`, `is_taxable`, `tax_id`, `tax_percent`, `is_delivery_required`, `is_delivery_included`, `is_stockable`) VALUES
(88, 'RAW-020', '899260000020', 'Tutup Galon RO', 'Bahan Baku (Raw Material)', 'pcs', 90, 0, '🔘', 'RAW_MATERIAL', 0, 4, 10, 1, 0, 0, 0, 'ALL', 0, 1, 11, 0, 0, 1),
(89, 'RAW-089', '899260000089', 'Tutup Galon Mineral', 'Bahan Baku (Raw Material)', 'pcs', 90, 0, '🔘', 'RAW_MATERIAL', 0, 4, 10, 1, 0, 0, 0, 'ALL', 0, 1, 11, 0, 0, 1),
(90, 'RAW-090', '899260000090', 'Tisu Galon CC2', 'Bahan Baku (Raw Material)', 'pcs', 85, 0, '🧻', 'RAW_MATERIAL', 0, 4, 10, 1, 0, 0, 0, 'ALL', 0, 1, 11, 0, 0, 1),
(91, 'RAW-091', '899260000091', 'Air Baku', 'Bahan Baku (Raw Material)', 'liter', 45, 0, '💧', 'RAW_MATERIAL', 0, 4, 10, 1, 0, 0, 0, 'ALL', 0, 1, 11, 0, 0, 1),
(98, 'PRD-00001', '899260000092', 'Air Mineral Galon 19 Liter', 'Produk Jual', 'galon', 985, 4500, '📦', 'FINISHED_GOODS', 1, 1, 10, 1, 4100, 3600, 3200, 'ALL', 0, 1, 11, 2, 1, 2),
(99, 'PRD-00002', '899260000099', 'Air RO Galon 19 Liter', 'Produk Jual', 'galon', 985, 6500, '📦', 'FINISHED_GOODS', 1, 1, 10, 1, 5900, 5200, 4700, 'ALL', 0, 1, 11, 2, 1, 2),
(100, 'PRD-00003', '899260000100', 'Air Mineral Galon 15 Liter', 'Produk Jual', 'galon', 805, 4500, '📦', 'FINISHED_GOODS', 1, 1, 10, 1, 4100, 3600, 3200, 'ALL', 0, 1, 11, 2, 1, 0),
(101, 'PRD-00004', '899260000101', 'Air RO Galon 15 Liter', 'Produk Jual', 'galon', 805, 6500, '📦', 'FINISHED_GOODS', 1, 1, 10, 1, 5900, 5200, 4700, 'ALL', 0, 1, 11, 2, 1, 0),
(102, 'PRD-00005', '899260000102', 'Gas 3 KG', 'Konsinyasi', 'tabung', 24000, 25000, '📦', 'FINISHED_GOODS', 0, 8, 10, 1, 22500, 20000, 18000, 'ALL', 0, 1, 11, 2, 1, 1),
(103, 'PRD-00006', '899260000103', 'Aqua Refill 19 Liter', 'Konsinyasi', 'galon', 19700, 23500, '📦', 'FINISHED_GOODS', 0, 8, 10, 1, 21200, 18800, 16900, 'ALL', 0, 1, 11, 2, 1, 1),
(104, 'PRD-00007', '899260000104', 'Es Kristal 5kg', 'Produk Jual', 'ball', 1360, 6500, '📦', 'FINISHED_GOODS', 1, 1, 10, 1, 5900, 5200, 4700, 'ALL', 0, 1, 11, 1, 0, 2),
(105, 'RAW-00092', '899260000105', 'Plastik PE 40 X 60', 'Bahan Baku (Raw Material)', 'pcs', 1000, 0, '📦', 'RAW_MATERIAL', 0, 4, 10, 1, 0, 0, 0, 'ALL', 0, 1, 11, 0, 0, 1),
(106, 'PRD-00008', '899260000106', 'Es Kristal 10 Kg', 'Produk Jual', 'ball', 2675, 12000, '📦', 'FINISHED_GOODS', 1, 1, 10, 1, 10800, 9600, 8600, 'ALL', 0, 1, 11, 1, 0, 0),
(107, 'PRD-00009', '899260000107', 'Galon Kosong Aqua 19 L', 'Produk Jual', 'galon', 25000, 35000, '📦', 'FINISHED_GOODS', 0, 1, 1, 1, 31500, 28000, 25200, 'ALL', 0, 1, 11, 0, 0, 1),
(108, 'PRD-00010', '899260000108', 'Galon Kosong Non Aqua', 'Produk Jual', 'galon', 15000, 25000, '📦', 'FINISHED_GOODS', 0, 1, 1, 1, 22500, 20000, 18000, 'ALL', 0, 1, 11, 0, 0, 1);

-- --------------------------------------------------------
-- Table structure for `product_variants`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `product_variants`;
CREATE TABLE `product_variants` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `variant_name` VARCHAR(255) NOT NULL,
    `sku_variant` VARCHAR(100) NOT NULL,
    `additional_price` DECIMAL(15, 2) DEFAULT 0,
    `additional_cogs` DECIMAL(15, 2) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `product_variants`
INSERT INTO `product_variants` (`id`, `product_id`, `variant_name`, `sku_variant`, `additional_price`, `additional_cogs`) VALUES
(19, 98, 'Pakai Tutup Mineral', 'PRD-00001-VAR694', 0, 0),
(20, 98, 'Tanpa Tutup Mineral', 'PRD-00001-VAR631', 0, 0),
(21, 99, 'Pakai Tutup RO', 'PRD-00002-VAR207', 0, 0),
(23, 99, 'Tanpa Tutup RO', 'PRD-00002-VAR261', 0, 0),
(35, 100, 'Pakai Tutup Mineral', 'PRD-00003-00001-VAR694', 0, 0),
(39, 100, 'Tanpa Tutup Mineral', 'PRD-00003-00001-VAR631', 0, 0),
(40, 101, 'Pakai Tutup RO', 'PRD-00004-00002-VAR207', 0, 0),
(41, 101, 'Tanpa Tutup RO', 'PRD-00004-00002-VAR261', 0, 0);

-- --------------------------------------------------------
-- Table structure for `product_recipes`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `product_recipes`;
CREATE TABLE `product_recipes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `finished_product_id` INT NOT NULL,
    `ingredient_product_id` INT NOT NULL,
    `qty_needed` DECIMAL(12, 4) NOT NULL,
    `unit` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `product_recipes`
INSERT INTO `product_recipes` (`id`, `finished_product_id`, `ingredient_product_id`, `qty_needed`, `unit`) VALUES
(43, 98, 91, 20, 'liter'),
(44, 99, 91, 20, 'liter'),
(46, 100, 91, 16, 'liter'),
(47, 100, 90, 1, 'pcs'),
(48, 98, 90, 1, 'pcs'),
(49, 101, 91, 16, 'liter'),
(50, 101, 90, 1, 'pcs'),
(51, 99, 90, 1, 'pcs'),
(52, 104, 91, 8, 'liter'),
(53, 104, 105, 1, 'pcs'),
(54, 106, 91, 15, 'liter'),
(55, 106, 105, 2, 'pcs'),
(69, 100, 89, 1, 'pcs');

-- --------------------------------------------------------
-- Table structure for `variant_recipes`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `variant_recipes`;
CREATE TABLE `variant_recipes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `variant_id` INT NOT NULL,
    `ingredient_product_id` INT NOT NULL,
    `qty_needed` DECIMAL(12, 4) NOT NULL DEFAULT 1,
    `unit` VARCHAR(255) NOT NULL DEFAULT 'pcs',
    `notes` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `variant_recipes`
INSERT INTO `variant_recipes` (`id`, `variant_id`, `ingredient_product_id`, `qty_needed`, `unit`, `notes`) VALUES
(9, 19, 89, 1, 'pcs', '1 pcs per penjualan varian ini'),
(10, 21, 88, 1, 'pcs', '1 pcs per penjualan varian ini'),
(17, 35, 89, 1, 'pcs', '1 pcs per penjualan varian ini'),
(18, 40, 88, 1, 'pcs', '1 pcs per penjualan varian ini');

-- --------------------------------------------------------
-- Table structure for `branch_stocks`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `branch_stocks`;
CREATE TABLE `branch_stocks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `stock` INT NOT NULL DEFAULT 0,
    `local_cogs` DECIMAL(15, 2) NOT NULL,
    `local_price` DECIMAL(15, 2) NOT NULL,
    `last_updated` DATETIME DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `is_delivery_required` TINYINT(1) DEFAULT NULL,
    `is_delivery_included` TINYINT(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `branch_stocks`
INSERT INTO `branch_stocks` (`id`, `branch_id`, `product_id`, `stock`, `local_cogs`, `local_price`, `last_updated`, `is_active`, `is_delivery_required`, `is_delivery_included`) VALUES
(292, 3, 88, 0, 90, 0, NULL, 1, NULL, NULL),
(294, 2, 88, 0, 90, 0, '2026-10-20 05:54:38', 1, NULL, NULL),
(295, 3, 89, 0, 90, 0, NULL, 1, NULL, NULL),
(297, 2, 89, 0, 90, 0, NULL, 1, NULL, NULL),
(298, 3, 90, 0, 85, 0, NULL, 1, NULL, NULL),
(299, 1, 90, 978, 85, 0, '2026-10-24 15:51:45', 1, NULL, NULL),
(300, 2, 90, 0, 85, 0, NULL, 1, NULL, NULL),
(301, 3, 91, 0, 45, 0, NULL, 1, NULL, NULL),
(302, 1, 91, 99560, 45, 0, '2026-10-24 15:51:30', 1, NULL, NULL),
(303, 2, 91, 0, 45, 0, NULL, 1, NULL, NULL),
(316, 3, 98, 0, 985, 5000, '2026-10-11 22:36:49', 1, NULL, NULL),
(317, 1, 98, 0, 985, 4500, '2026-10-24 15:51:23', 1, 2, 1),
(318, 2, 98, 0, 985, 5000, '2026-10-11 22:54:50', 1, 0, NULL),
(319, 3, 99, 0, 985, 7000, '2026-10-11 11:59:37', 1, NULL, NULL),
(320, 1, 99, 0, 985, 6500, '2026-10-24 12:50:20', 1, 2, 1),
(321, 2, 99, 0, 985, 9000, '2026-10-11 11:59:20', 1, NULL, NULL),
(322, 3, 100, 0, 805, 4500, '2026-10-24 08:55:06', 0, NULL, NULL),
(323, 1, 100, 0, 805, 4500, '2026-10-20 05:59:29', 1, 2, 1),
(324, 2, 100, 0, 805, 4500, '2026-10-11 22:36:07', 0, NULL, NULL),
(325, 3, 101, 0, 805, 6500, '2026-10-11 22:36:52', 0, NULL, NULL),
(326, 1, 101, 0, 805, 6500, '2026-10-20 13:45:56', 1, 2, 1),
(327, 2, 101, 0, 805, 6500, '2026-10-11 22:36:22', 0, NULL, NULL),
(328, 3, 102, 0, 24000, 25000, '2026-10-11 22:37:07', 0, NULL, NULL),
(329, 1, 102, 0, 24000, 25000, '2026-10-24 12:26:34', 1, 2, 1),
(330, 2, 102, 0, 24000, 25000, '2026-10-11 22:36:35', 0, NULL, NULL),
(332, 3, 103, 0, 19700, 23500, NULL, 1, NULL, NULL),
(333, 1, 103, 0, 19700, 23500, '2026-10-24 06:59:08', 1, 2, 1),
(334, 2, 103, 0, 19700, 22000, '2026-10-11 21:50:20', 1, NULL, NULL),
(335, 6, 88, 0, 90, 0, NULL, 1, NULL, NULL),
(336, 6, 89, 0, 90, 0, NULL, 1, NULL, NULL),
(337, 6, 90, 0, 85, 0, NULL, 1, NULL, NULL),
(338, 6, 91, 0, 45, 0, NULL, 1, NULL, NULL),
(339, 6, 98, 0, 985, 4500, '2026-10-11 23:26:45', 1, 0, NULL),
(340, 6, 99, 0, 985, 6500, '2026-10-11 23:26:54', 0, 0, NULL),
(341, 6, 100, 0, 805, 4500, '2026-10-24 08:54:57', 0, 0, NULL),
(342, 6, 101, 0, 805, 6500, '2026-10-11 23:26:49', 0, 0, NULL),
(343, 6, 102, 0, 24000, 25000, '2026-10-11 23:27:06', 0, 0, NULL),
(344, 6, 103, 0, 19700, 23500, '2026-10-11 23:26:59', 0, 0, NULL),
(345, 3, 104, 0, 1360, 6500, '2026-10-11 22:37:04', 0, NULL, NULL),
(346, 6, 104, 0, 1360, 6500, NULL, 1, NULL, NULL),
(347, 1, 104, 0, 1360, 6500, '2026-10-24 16:31:06', 1, 1, 0),
(348, 2, 104, 0, 1360, 7000, '2026-10-11 22:46:08', 1, 0, NULL),
(349, 3, 105, 0, 10000, 25000, NULL, 1, NULL, NULL),
(350, 6, 105, 0, 10000, 25000, NULL, 1, NULL, NULL),
(351, 1, 105, 0, 1000, 0, '2026-10-24 10:21:44', 1, NULL, NULL),
(352, 2, 105, 0, 10000, 25000, NULL, 1, NULL, NULL),
(353, 3, 106, 0, 2675, 12000, '2026-10-11 22:37:01', 0, NULL, NULL),
(354, 6, 106, 0, 2675, 12000, NULL, 1, NULL, NULL),
(355, 1, 106, 0, 2675, 12000, '2026-10-24 16:31:01', 1, 1, 0),
(356, 2, 106, 0, 2675, 12000, '2026-10-11 22:46:14', 1, 0, NULL),
(644, 1, 89, 40, 90, 0, '2026-10-24 16:32:31', 1, 0, 0),
(783, 1, 88, 0, 90, 0, '2026-10-24 16:32:37', 1, 0, 0),
(794, 2, 107, 0, 25000, 35000, NULL, 1, NULL, NULL),
(795, 2, 108, 0, 15000, 25000, NULL, 1, NULL, NULL),
(797, 3, 107, 0, 25000, 35000, NULL, 1, NULL, NULL),
(798, 3, 108, 0, 15000, 25000, NULL, 1, NULL, NULL),
(800, 6, 107, 0, 25000, 35000, NULL, 1, NULL, NULL),
(801, 6, 108, 0, 15000, 25000, NULL, 1, NULL, NULL),
(803, 1, 107, 0, 25000, 35000, NULL, 1, NULL, NULL),
(804, 1, 108, 0, 15000, 25000, NULL, 1, NULL, NULL);

-- --------------------------------------------------------
-- Table structure for `customers`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(255),
    `email` VARCHAR(191),
    `address` TEXT,
    `category` VARCHAR(255) DEFAULT 'REGULER',
    `discount_percent` DECIMAL(8, 4) DEFAULT 0,
    `branch_id` INT,
    `notes` TEXT,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `latitude` DECIMAL(10, 7) DEFAULT NULL,
    `longitude` DECIMAL(10, 7) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `customers`
INSERT INTO `customers` (`id`, `code`, `name`, `phone`, `email`, `address`, `category`, `discount_percent`, `branch_id`, `notes`, `is_active`, `created_at`, `latitude`, `longitude`) VALUES
(1, 'CUST-001', 'Bakso Suramadu', '085926232563', 'baso@gmail.com', 'Jl. Warung Contong Cimahi', 'REGULER', 0, 1, 'Pelanggan ritel harian umum', 1, '2026-10-09 16:09:56', -6.884401, 107.531931),
(2, 'CUST-002', 'Anisa Maharani (VIP Member)', '0812-9988-7766', 'anisa@member.com', 'Jl. Rasuna Said No. 12, Jakarta', 'PREMIUM', 0, 1, 'Member Terdaftar Gold Tier', 1, '2026-10-09 16:09:56', -6.2155, 106.8315),
(3, 'MTR-001', 'Warung Key', '0813-1122-3344', 'berkah@reseller.id', 'Jl. Padat karya', 'MITRA', 0, 1, 'Warung lama', 1, '2026-10-09 16:09:56', -6.96638, 107.653211),
(4, 'MTR-002', 'Warung Rusun 1', '0815-5566-7788', 'procurement@bahtera.co.id', 'Gedung Wisma Niaga Lt. 8, Jakarta', 'MITRA', 0, 1, 'Mitra baru', 1, '2026-10-09 16:09:56', -6.2088, 106.819),
(5, 'CUST-005', 'CV Mitra Nusantara Sejahtera (Mitra B)', '0816-6677-8899', 'admin@nusantara-partner.com', 'Komplek Pergudangan Margomulyo B-12, Surabaya', 'MITRA', 32, 2, 'Mitra Grosir B - Volume Tinggi Harga Spesial', 0, '2026-10-09 16:09:56', -7.241, 112.678),
(6, 'CUST-TEST-MEMBER', 'Pelanggan Test MEMBER', '08129999000', NULL, 'Jl. Test No. 1', 'MEMBER', 0, 1, NULL, 0, '2026-10-12 14:27:16', -6.2, 106.82),
(7, 'CUST-TEST-RESELLER', 'Pelanggan Test RESELLER', '08129999000', NULL, 'Jl. Test No. 1', 'RESELLER', 0, 1, NULL, 0, '2026-10-12 14:27:16', -6.2, 106.82),
(8, 'MTR-1127401', 'Warung RCB', '08978408831', 'rcb@gmail.com', 'Jl. Sangkuriang', 'MITRA', 0, 1, 'Mitra lama', 1, '2026-10-19 12:04:36', -6.867516, 107.537763),
(9, 'MTR-5836213', 'Warung Pempek Arnold', '083832437288', 'arnold@gmail.com', 'Jl. Lapang tembak', 'MITRA', 0, 1, 'Mitra lama', 1, '2026-10-19 12:18:47', -6.881544, 107.530993),
(10, 'MTR-9412327', 'Warung Enung', '081395440601', 'enung@gmail.com', 'Jl. Pojok gg depan kebon cau', 'MITRA', 0, 1, 'Mitra lama', 1, '2026-10-19 12:20:40', -6.871905, 107.537049);

-- --------------------------------------------------------
-- Table structure for `mitra_custom_prices`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `mitra_custom_prices`;
CREATE TABLE `mitra_custom_prices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `custom_price` DECIMAL(15, 2) NOT NULL,
    `notes` TEXT,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `mitra_custom_prices`
INSERT INTO `mitra_custom_prices` (`id`, `customer_id`, `product_id`, `custom_price`, `notes`, `updated_at`) VALUES
(17, 4, 98, 4000, 'Harga Kontrak Suplai Mitra B2B', '2026-10-11 12:01:35'),
(19, 3, 98, 3500, 'Harga Kontrak Suplai Mitra B2B', '2026-10-11 12:18:30'),
(20, 8, 98, 3500, 'Harga Kontrak Suplai Mitra B2B', '2026-10-19 05:30:24'),
(21, 9, 98, 3500, 'Harga Kontrak Suplai Mitra B2B', '2026-10-19 05:30:38'),
(22, 10, 98, 3500, 'Harga Kontrak Suplai Mitra B2B', '2026-10-19 05:30:52');

-- --------------------------------------------------------
-- Table structure for `delivery_settings`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `delivery_settings`;
CREATE TABLE `delivery_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT NOT NULL,
    `base_fee` DECIMAL(15, 2) DEFAULT 10000,
    `base_distance_km` DECIMAL(12, 4) DEFAULT 3.0,
    `per_km_rate` DECIMAL(8, 4) DEFAULT 2500,
    `free_delivery_threshold` DECIMAL(15, 2) DEFAULT 150000,
    `max_distance_km` DECIMAL(12, 4) DEFAULT 35.0,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `delivery_settings`
INSERT INTO `delivery_settings` (`id`, `branch_id`, `base_fee`, `base_distance_km`, `per_km_rate`, `free_delivery_threshold`, `max_distance_km`, `updated_at`) VALUES
(1, 1, 1000, 1, 1000, 200000, 5, '2026-10-19 10:13:15'),
(2, 2, 9000, 3, 2200, 150000, 35, '2026-10-09 16:35:24'),
(3, 3, 1000, 1, 1000, 0, 5, '2026-10-11 08:00:47'),
(7, 6, 10000, 3, 2500, 150000, 35, '2026-10-11 05:15:55');

-- --------------------------------------------------------
-- Table structure for `courier_vehicles`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `courier_vehicles`;
CREATE TABLE `courier_vehicles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT NOT NULL,
    `plate_number` VARCHAR(100) NOT NULL,
    `vehicle_name` VARCHAR(255) NOT NULL,
    `vehicle_type` VARCHAR(255) DEFAULT 'MOTOR',
    `driver_name` VARCHAR(255) NOT NULL,
    `current_odometer` DECIMAL(15, 2) NOT NULL DEFAULT 0,
    `fuel_ratio` DECIMAL(8, 4) NOT NULL DEFAULT 35.0,
    `fuel_ratio_unit` VARCHAR(255) DEFAULT 'KM_PER_LITER',
    `fuel_type` VARCHAR(255) DEFAULT 'Pertalite',
    `status` VARCHAR(255) DEFAULT 'AVAILABLE',
    `notes` TEXT,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `driver_user_id` INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `courier_vehicles`
INSERT INTO `courier_vehicles` (`id`, `branch_id`, `plate_number`, `vehicle_name`, `vehicle_type`, `driver_name`, `current_odometer`, `fuel_ratio`, `fuel_ratio_unit`, `fuel_type`, `status`, `notes`, `is_active`, `created_at`, `driver_user_id`) VALUES
(1, 1, 'D 5116 OZ', 'Honda Vario  110', 'MOTOR', 'Bima', 14832.91, 30, 'KM_PER_LITER', 'Pertalite', 'ACTIVE', 'Kendaraan kurir utama area Jakpus & Jaksel. 1 Liter = 38 KM', 1, '2026-10-09 16:35:24', 3),
(2, 1, 'B 3192 SEB', 'Honda Beat FI 110cc', 'MOTOR', 'Keenan (Staff)', 21800, 45, 'KM_PER_LITER', 'Pertalite', 'AVAILABLE', 'Kendaraan kurir cadangan hemat bensin. 1 Liter = 45 KM', 0, '2026-10-09 16:35:24', 6),
(3, 2, 'L 5520 YZ', 'Yamaha Mio M3 125', 'MOTOR', 'Abdul Latif (Kasir)', 18400, 40, 'KM_PER_LITER', 'Pertalite', 'AVAILABLE', 'Armada kurir express wilayah Surabaya Pusat. 1 Liter = 40 KM', 1, '2026-10-09 16:35:24', 4),
(4, 3, 'D 2914 ABJ', 'Honda Scoopy 110cc', 'MOTOR', 'Shafiqa (Kasir)', 12100, 42, 'KM_PER_LITER', 'Pertalite', 'ACTIVE', 'Armada kurir cabang Dago Bandung. 1 Liter = 42 KM', 0, '2026-10-09 16:35:24', 5),
(5, 1, 'D 2190 CZ', 'New Supra Fit', 'MOTOR', 'Abdul Latif', 0, 30, 'KM_PER_LITER', 'Pertalite', 'ACTIVE', '', 1, '2026-10-19 11:53:04', 4),
(7, 1, 'B 5617 DRV', 'Yamaha Aerox Kurir Express', 'MOTOR', 'Muhamad Ismail', 1250.5, 38, 'KM_PER_LITER', 'Pertalite', 'ACTIVE', 'Armada kurir resmi cabang Jakarta', 1, '2026-10-19 06:10:08', 1);

-- --------------------------------------------------------
-- Table structure for `cashier_shifts`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `cashier_shifts`;
CREATE TABLE `cashier_shifts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `start_time` DATETIME NOT NULL,
    `end_time` DATETIME,
    `initial_cash` DECIMAL(15, 2) NOT NULL,
    `expected_cash` DECIMAL(15, 2) DEFAULT 0,
    `actual_cash` DECIMAL(15, 2) DEFAULT 0,
    `cash_difference` DECIMAL(15, 2) DEFAULT 0,
    `status` VARCHAR(255) DEFAULT 'OPEN',
    `opening_raw_mineral` INT DEFAULT 0,
    `opening_raw_ro` INT DEFAULT 0,
    `opening_raw_plastik` INT DEFAULT 0,
    `closing_night_mineral` INT DEFAULT 0,
    `closing_night_ro` INT DEFAULT 0,
    `closing_night_plastik` INT DEFAULT 0,
    `closing_raw_mineral` INT DEFAULT NULL,
    `closing_raw_ro` INT DEFAULT NULL,
    `closing_raw_plastik` INT DEFAULT NULL,
    `shift_notes` VARCHAR(255) DEFAULT NULL,
    `opening_items_json` LONGTEXT DEFAULT NULL,
    `closing_items_json` LONGTEXT DEFAULT NULL,
    `shift_number` INT DEFAULT 1,
    `shift_name` VARCHAR(255) DEFAULT 'Shift 1',
    `shift_type` VARCHAR(255) DEFAULT 'SHIFT_1',
    `is_final_shift` TINYINT(1) DEFAULT 0,
    `total_expenses` DECIMAL(15, 2) DEFAULT 0,
    `total_kasbon` DECIMAL(15, 2) DEFAULT 0,
    `total_meal_allowance` DECIMAL(15, 2) DEFAULT 0,
    `shift_disbursements_json` LONGTEXT DEFAULT NULL,
    `water_meter_yesterday` DECIMAL(15, 2) DEFAULT NULL,
    `water_meter_today` DECIMAL(15, 2) DEFAULT NULL,
    `water_meter_difference` DECIMAL(15, 2) DEFAULT NULL,
    `water_meter_adjustment` DECIMAL(15, 2) DEFAULT 0,
    `water_meter_adjustment_type` VARCHAR(255) DEFAULT 'PULSE',
    `water_meter_adjustment_reason` VARCHAR(255) DEFAULT NULL,
    `water_meter_dispensed_galon` DECIMAL(15, 2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `cashier_shifts`
INSERT INTO `cashier_shifts` (`id`, `branch_id`, `user_id`, `start_time`, `end_time`, `initial_cash`, `expected_cash`, `actual_cash`, `cash_difference`, `status`, `opening_raw_mineral`, `opening_raw_ro`, `opening_raw_plastik`, `closing_night_mineral`, `closing_night_ro`, `closing_night_plastik`, `closing_raw_mineral`, `closing_raw_ro`, `closing_raw_plastik`, `shift_notes`, `opening_items_json`, `closing_items_json`, `shift_number`, `shift_name`, `shift_type`, `is_final_shift`, `total_expenses`, `total_kasbon`, `total_meal_allowance`, `shift_disbursements_json`, `water_meter_yesterday`, `water_meter_today`, `water_meter_difference`, `water_meter_adjustment`, `water_meter_adjustment_type`, `water_meter_adjustment_reason`, `water_meter_dispensed_galon`) VALUES
(87, 1, 3, '2026-10-24 15:51:57', '2026-10-24 15:54:22', 300000, 354000, 354000, 0, 'CLOSED', 50, 0, 0, 0, 0, 0, 38, 0, 0, '', '{\"89\":50,\"88\":0,\"98\":10,\"99\":0,\"105\":0}', '{\"89\":38,\"88\":0,\"98\":10,\"99\":0,\"105\":0}', 1, 'Shift 1 (Pagi)', 'SHIFT_1', 1, 0, 0, 0, '{\"expenses\":[],\"loans\":[],\"meals\":[],\"total_expenses\":0,\"total_loans\":0,\"total_meals\":0,\"total_disbursements\":0}', NULL, NULL, NULL, 0, 'PULSE', NULL, NULL),
(88, 1, 3, '2026-10-25 14:55:47', NULL, 300000, 0, 0, 0, 'OPEN', 50, 0, 0, 38, 0, 0, NULL, NULL, NULL, '', '{\"89\":50,\"88\":0,\"98\":10,\"99\":0,\"105\":0}', NULL, 1, 'Shift 1 (Pagi)', 'SHIFT_1', 0, 0, 0, 0, NULL, NULL, NULL, NULL, 0, 'PULSE', NULL, NULL);

-- --------------------------------------------------------
-- Table structure for `water_meter_logs`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `water_meter_logs`;
CREATE TABLE `water_meter_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT NOT NULL,
    `shift_id` INT DEFAULT NULL,
    `user_id` INT NOT NULL,
    `log_date` DATE NOT NULL,
    `meter_yesterday` DECIMAL(15, 2) NOT NULL DEFAULT 0,
    `meter_today` DECIMAL(15, 2) NOT NULL DEFAULT 0,
    `meter_difference` DECIMAL(15, 2) NOT NULL DEFAULT 0,
    `meter_adjustment` DECIMAL(15, 2) NOT NULL DEFAULT 0,
    `adjustment_type` VARCHAR(255) DEFAULT 'PULSE',
    `adjustment_reason` VARCHAR(255),
    `water_dispensed_galon` DECIMAL(15, 2) NOT NULL DEFAULT 0,
    `notes` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `opening_shift_products`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `opening_shift_products`;
CREATE TABLE `opening_shift_products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `sort_order` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `opening_shift_products`
INSERT INTO `opening_shift_products` (`id`, `product_id`, `sort_order`, `is_active`, `created_at`) VALUES
(10, 89, 1, 1, '2026-10-25 12:39:19'),
(11, 88, 2, 1, '2026-10-25 12:39:19'),
(12, 98, 3, 1, '2026-10-25 12:39:19'),
(13, 99, 4, 1, '2026-10-25 12:39:19'),
(14, 105, 5, 1, '2026-10-24 13:11:56');

-- --------------------------------------------------------
-- Table structure for `sales`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `sales`;
CREATE TABLE `sales` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_number` VARCHAR(100) NOT NULL,
    `branch_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `shift_id` INT,
    `subtotal` DECIMAL(15, 2) NOT NULL,
    `discount` DECIMAL(15, 2) DEFAULT 0,
    `tax` DECIMAL(15, 2) DEFAULT 0,
    `grand_total` DECIMAL(15, 2) NOT NULL,
    `total_cogs` DECIMAL(15, 2) NOT NULL,
    `payment_method` VARCHAR(255) NOT NULL,
    `cash_received` DECIMAL(15, 2) DEFAULT 0,
    `cash_change` DECIMAL(15, 2) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `customer_id` INT DEFAULT NULL,
    `customer_name` VARCHAR(255) DEFAULT 'Pelanggan Umum',
    `customer_category` VARCHAR(255) DEFAULT 'REGULER',
    `sales_type` VARCHAR(255) DEFAULT 'RETAIL',
    `delivery_fee` DECIMAL(15, 2) DEFAULT 0,
    `delivery_distance_km` DECIMAL(12, 4) DEFAULT 0,
    `courier_vehicle_id` INT DEFAULT NULL,
    `delivery_status` VARCHAR(255) DEFAULT 'NONE',
    `payment_status` VARCHAR(255) DEFAULT 'PAID',
    `status` VARCHAR(255) DEFAULT 'COMPLETED',
    `cancellation_reason` TEXT DEFAULT NULL,
    `cancelled_by` INT DEFAULT NULL,
    `cancelled_at` DATETIME DEFAULT NULL,
    `driver_user_id` INT DEFAULT NULL,
    `driver_name` VARCHAR(255) DEFAULT NULL,
    `is_delivery_included` TINYINT(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `sales`
INSERT INTO `sales` (`id`, `invoice_number`, `branch_id`, `user_id`, `shift_id`, `subtotal`, `discount`, `tax`, `grand_total`, `total_cogs`, `payment_method`, `cash_received`, `cash_change`, `created_at`, `customer_id`, `customer_name`, `customer_category`, `sales_type`, `delivery_fee`, `delivery_distance_km`, `courier_vehicle_id`, `delivery_status`, `payment_status`, `status`, `cancellation_reason`, `cancelled_by`, `cancelled_at`, `driver_user_id`, `driver_name`, `is_delivery_included`) VALUES
(25, 'INV/JKT-01/20261024/0001', 1, 3, 87, 54000, 0, 0, 54000, 11820, 'CASH', 54000, 0, '2026-10-24 20:52:29', 1, 'Bakso Suramadu', 'REGULER', 'REGULER', 0, 0, 1, 'ASSIGNED', 'PAID', 'COMPLETED', NULL, NULL, NULL, 3, 'Bima', 1),
(26, 'INV/JKT-01/20261025/0026', 1, 3, 88, 70000, 0, 0, 70000, 19700, 'PIUTANG', 0, 0, '2026-10-25 20:56:39', 3, 'Warung Key', 'MITRA', 'B2B_PARTNER', 0, 0, 1, 'ASSIGNED', 'UNPAID', 'COMPLETED', NULL, NULL, NULL, 3, 'Bima', 1);

-- --------------------------------------------------------
-- Table structure for `sale_items`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `sale_items`;
CREATE TABLE `sale_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sale_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `product_name` VARCHAR(255) NOT NULL,
    `qty` INT NOT NULL,
    `unit_price` DECIMAL(15, 2) NOT NULL,
    `unit_cogs` DECIMAL(15, 2) NOT NULL,
    `subtotal_price` DECIMAL(15, 2) NOT NULL,
    `subtotal_cogs` DECIMAL(15, 2) NOT NULL,
    `variant_name` VARCHAR(255) DEFAULT NULL,
    `variant_id` INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `sale_items`
INSERT INTO `sale_items` (`id`, `sale_id`, `product_id`, `product_name`, `qty`, `unit_price`, `unit_cogs`, `subtotal_price`, `subtotal_cogs`, `variant_name`, `variant_id`) VALUES
(25, 25, 98, 'Air Mineral Galon 19 Liter [Pakai Tutup Mineral]', 12, 4500, 985, 54000, 11820, 'Pakai Tutup Mineral', 19),
(26, 26, 98, 'Air Mineral Galon 19 Liter [Pakai Tutup Mineral]', 20, 3500, 985, 70000, 19700, 'Pakai Tutup Mineral', 19);

-- --------------------------------------------------------
-- Table structure for `stock_movements`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `stock_movements`;
CREATE TABLE `stock_movements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `movement_type` VARCHAR(255) NOT NULL,
    `qty_change` DECIMAL(12, 4) NOT NULL,
    `stock_before` DECIMAL(15, 2) NOT NULL,
    `stock_after` DECIMAL(15, 2) NOT NULL,
    `reference_no` VARCHAR(255),
    `notes` TEXT,
    `user_id` INT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `stock_movements`
INSERT INTO `stock_movements` (`id`, `branch_id`, `product_id`, `movement_type`, `qty_change`, `stock_before`, `stock_after`, `reference_no`, `notes`, `user_id`, `created_at`) VALUES
(474, 1, 98, 'INITIAL_STOCK', 10, 0, 10, 'INIT-20261024-155123', 'Setel stok awal cabang', 1, '2026-10-24 13:51:23'),
(475, 1, 91, 'INITIAL_STOCK', 100000, 0, 100000, 'INIT-20261024-155130', 'Setel stok awal cabang', 1, '2026-10-24 13:51:30'),
(476, 1, 90, 'INITIAL_STOCK', 1000, 0, 1000, 'INIT-20261024-155145', 'Setel stok awal cabang', 1, '2026-10-24 13:51:45'),
(477, 1, 98, 'STOCK_OUT_SALE', -12, 10, 0, 'INV/JKT-01/20261024/0001', 'Penjualan POS [REGULER] kpd Bakso Suramadu (Pakai Tutup Mineral)', 3, '2026-10-24 13:52:29'),
(478, 1, 91, 'BOM_DEDUCTION', -40, 100000, 99960, 'INV/JKT-01/20261024/0001', 'Konsumsi Base BOM (2x on-demand, 10x dari stok produk jadi) untuk 12x Air Mineral Galon 19 Liter [Pakai Tutup Mineral]', 3, '2026-10-24 13:52:29'),
(479, 1, 90, 'BOM_DEDUCTION', -2, 1000, 998, 'INV/JKT-01/20261024/0001', 'Konsumsi Base BOM (2x on-demand, 10x dari stok produk jadi) untuk 12x Air Mineral Galon 19 Liter [Pakai Tutup Mineral]', 3, '2026-10-24 13:52:29'),
(480, 1, 89, 'BOM_DEDUCTION', -2, 50, 48, 'INV/JKT-01/20261024/0001', 'Konsumsi BOM Varian [Pakai Tutup Mineral] (2x on-demand, 10x dari stok produk jadi) untuk 12x Air Mineral Galon 19 Liter', 3, '2026-10-24 13:52:29'),
(481, 1, 98, 'STOCK_OPNAME', 10, 0, 10, 'CLOSING-SHIFT-87', 'Penetapan fisik 10 galon produk jadi standby shift #1', 3, '2026-10-24 13:54:22'),
(482, 1, 89, 'BOM_DEDUCTION', -10, 48, 38, 'CLOSING-SHIFT-87', 'Konsumsi Variant BOM [Pakai Tutup Mineral] (10x Air Mineral Galon 19 Liter) untuk stok standby tutup toko', 3, '2026-10-24 13:54:22'),
(483, 1, 91, 'BOM_DEDUCTION', -200, 99960, 99760, 'CLOSING-SHIFT-87', 'Konsumsi Base BOM (10x Air Mineral Galon 19 Liter) untuk stok standby tutup toko', 3, '2026-10-24 13:54:22'),
(484, 1, 90, 'BOM_DEDUCTION', -10, 998, 988, 'CLOSING-SHIFT-87', 'Konsumsi Base BOM (10x Air Mineral Galon 19 Liter) untuk stok standby tutup toko', 3, '2026-10-24 13:54:22'),
(485, 1, 98, 'STOCK_OUT_SALE', -20, 10, 0, 'INV/JKT-01/20261025/0026', 'Penjualan POS [MITRA] kpd Warung Key (Pakai Tutup Mineral)', 3, '2026-10-25 13:56:39'),
(486, 1, 91, 'BOM_DEDUCTION', -200, 99760, 99560, 'INV/JKT-01/20261025/0026', 'Konsumsi Base BOM (10x on-demand, 10x dari stok produk jadi) untuk 20x Air Mineral Galon 19 Liter [Pakai Tutup Mineral]', 3, '2026-10-25 13:56:39'),
(487, 1, 90, 'BOM_DEDUCTION', -10, 988, 978, 'INV/JKT-01/20261025/0026', 'Konsumsi Base BOM (10x on-demand, 10x dari stok produk jadi) untuk 20x Air Mineral Galon 19 Liter [Pakai Tutup Mineral]', 3, '2026-10-25 13:56:39'),
(488, 1, 89, 'BOM_DEDUCTION', -10, 50, 40, 'INV/JKT-01/20261025/0026', 'Konsumsi BOM Varian [Pakai Tutup Mineral] (10x on-demand, 10x dari stok produk jadi) untuk 20x Air Mineral Galon 19 Liter', 3, '2026-10-25 13:56:39'),
(489, 1, 89, 'STOCK_OUT_RETURN', -38, 38, 0, 'RETUR-SHIFT-87', 'Retur fisik sisa 38 pcs Tutup Galon Mineral ke gudang saat tutup toko hari ke-1', 3, '2026-10-24 15:54:22'),
(490, 1, 89, 'STOCK_IN_RESTOCK', 50, 0, 50, 'OPENING-SHIFT-88', 'Pasokan Baru Masuk Pagi saat Buka Shift (Shift 1 (Pagi))', 3, '2026-10-25 14:55:47');

-- --------------------------------------------------------
-- Table structure for `attendance_logs`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `attendance_logs`;
CREATE TABLE `attendance_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `branch_id` INT NOT NULL,
    `clock_in` DATETIME NOT NULL,
    `clock_out` DATETIME,
    `latitude` DECIMAL(10, 7),
    `longitude` DECIMAL(10, 7),
    `distance_meters` DECIMAL(12, 4),
    `status` VARCHAR(255) DEFAULT 'ON_TIME',
    `note` VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `attendance_logs`
INSERT INTO `attendance_logs` (`id`, `user_id`, `branch_id`, `clock_in`, `clock_out`, `latitude`, `longitude`, `distance_meters`, `status`, `note`) VALUES
(1, 3, 1, '2026-10-09 09:53:25', NULL, -6.19515, 106.82092, 12.5, 'ON_TIME', 'Presensi tepat waktu via Geofencing GPS'),
(2, 3, 1, '2026-10-15 07:10:00', NULL, -6.87, 107.54, 12, 'ON_TIME', NULL),
(3, 3, 1, '2026-10-16 07:35:00', NULL, -6.87, 107.54, 15, 'LATE', NULL),
(4, 3, 1, '2026-10-15 07:10:00', NULL, -6.87, 107.54, 12, 'ON_TIME', NULL),
(5, 3, 1, '2026-10-16 07:35:00', NULL, -6.87, 107.54, 15, 'LATE', NULL),
(6, 3, 1, '2026-10-15 07:10:00', NULL, -6.87, 107.54, 12, 'ON_TIME', NULL),
(7, 3, 1, '2026-10-16 07:35:00', NULL, -6.87, 107.54, 15, 'LATE', NULL),
(8, 3, 1, '2026-10-15 07:10:00', NULL, -6.87, 107.54, 12, 'ON_TIME', NULL),
(9, 3, 1, '2026-10-16 07:35:00', NULL, -6.87, 107.54, 15, 'LATE', NULL),
(10, 3, 1, '2026-10-15 07:10:00', NULL, -6.87, 107.54, 12, 'ON_TIME', NULL),
(11, 3, 1, '2026-10-16 07:35:00', NULL, -6.87, 107.54, 15, 'LATE', NULL),
(12, 3, 1, '2026-10-15 07:10:00', NULL, -6.87, 107.54, 12, 'ON_TIME', NULL),
(13, 3, 1, '2026-10-16 07:35:00', NULL, -6.87, 107.54, 15, 'LATE', NULL),
(14, 3, 1, '2026-10-15 07:10:00', NULL, -6.87, 107.54, 12, 'ON_TIME', NULL),
(15, 3, 1, '2026-10-16 07:35:00', NULL, -6.87, 107.54, 15, 'LATE', NULL),
(16, 3, 1, '2026-10-15 07:10:00', NULL, -6.87, 107.54, 12, 'ON_TIME', NULL),
(17, 3, 1, '2026-10-16 07:35:00', NULL, -6.87, 107.54, 15, 'LATE', NULL),
(18, 3, 1, '2026-10-15 07:10:00', NULL, -6.87, 107.54, 12, 'ON_TIME', NULL),
(19, 3, 1, '2026-10-16 07:35:00', NULL, -6.87, 107.54, 15, 'LATE', NULL),
(20, 3, 1, '2026-10-15 07:10:00', NULL, -6.87, 107.54, 12, 'ON_TIME', NULL),
(21, 3, 1, '2026-10-16 07:35:00', NULL, -6.87, 107.54, 15, 'LATE', NULL),
(22, 3, 1, '2026-10-15 07:10:00', NULL, -6.87, 107.54, 12, 'ON_TIME', NULL),
(23, 3, 1, '2026-10-16 07:35:00', NULL, -6.87, 107.54, 15, 'LATE', NULL),
(24, 3, 1, '2026-10-15 07:10:00', NULL, -6.87, 107.54, 12, 'ON_TIME', NULL),
(25, 3, 1, '2026-10-16 07:35:00', NULL, -6.87, 107.54, 15, 'LATE', NULL),
(26, 3, 1, '2026-10-15 07:10:00', NULL, -6.87, 107.54, 12, 'ON_TIME', NULL),
(27, 3, 1, '2026-10-16 07:35:00', NULL, -6.87, 107.54, 15, 'LATE', NULL),
(28, 3, 1, '2026-10-15 07:10:00', NULL, -6.87, 107.54, 12, 'ON_TIME', NULL),
(29, 3, 1, '2026-10-16 07:35:00', NULL, -6.87, 107.54, 15, 'LATE', NULL),
(30, 3, 1, '2026-10-15 07:10:00', NULL, -6.87, 107.54, 12, 'ON_TIME', NULL),
(31, 3, 1, '2026-10-16 07:35:00', NULL, -6.87, 107.54, 15, 'LATE', NULL),
(32, 3, 1, '2026-10-15 07:10:00', NULL, -6.87, 107.54, 12, 'ON_TIME', NULL),
(33, 3, 1, '2026-10-16 07:35:00', NULL, -6.87, 107.54, 15, 'LATE', NULL);

-- --------------------------------------------------------
-- Table structure for `shift_schedules`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `shift_schedules`;
CREATE TABLE `shift_schedules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `shift_date` DATE NOT NULL,
    `shift_name` VARCHAR(255) NOT NULL,
    `start_time` VARCHAR(255) NOT NULL,
    `end_time` VARCHAR(255) NOT NULL,
    `work_shift_id` INT DEFAULT NULL,
    `is_holiday` TINYINT(1) DEFAULT 0,
    `notes` TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `shift_schedules`
INSERT INTO `shift_schedules` (`id`, `branch_id`, `user_id`, `shift_date`, `shift_name`, `start_time`, `end_time`, `work_shift_id`, `is_holiday`, `notes`) VALUES
(1, 1, 3, '2026-10-09', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(2, 1, 4, '2026-10-09', 'SORE (14:00 - 22:00)', '14:00', '22:00', NULL, 0, NULL),
(3, 1, 3, '2026-10-10', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(4, 1, 4, '2026-10-10', 'SORE (14:00 - 22:00)', '14:00', '22:00', NULL, 0, NULL),
(5, 1, 3, '2026-10-15', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, 'Siang Normal'),
(6, 1, 3, '2026-10-16', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, 'Siang Normal'),
(7, 1, 4, '2026-10-15', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, 'Pagi Normal'),
(8, 1, 4, '2026-10-16', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, 'Pagi Normal'),
(9, 1, 1, '2026-10-25', 'Libur / Off Day', '00:00', '00:00', 4, 1, 'Hari Libur Toko Maintenance'),
(10, 1, 2, '2026-10-25', 'Libur / Off Day', '00:00', '00:00', 4, 1, 'Hari Libur Toko Maintenance'),
(11, 1, 3, '2026-10-25', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, 'Hari Libur Toko Maintenance'),
(12, 1, 4, '2026-10-25', 'Libur / Off Day', '00:00', '00:00', 4, 1, 'Hari Libur Toko Maintenance'),
(13, 1, 3, '2026-10-17', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, 'Imported via Test'),
(14, 1, 4, '2026-10-17', 'Shift 2 (Siang/Sore)', '14:30', '22:30', 2, 0, 'Imported via Test'),
(15, 1, 3, '2026-10-20', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, '[SAKIT] Demam tinggi rawat jalan'),
(16, 1, 3, '2026-10-21', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, '[SAKIT] Demam tinggi rawat jalan'),
(17, 1, 3, '2026-10-01', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(18, 1, 3, '2026-10-02', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(19, 1, 3, '2026-10-03', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(20, 1, 3, '2026-10-04', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(21, 1, 3, '2026-10-05', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(22, 1, 3, '2026-10-06', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(23, 1, 3, '2026-10-07', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(24, 1, 3, '2026-10-08', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(25, 1, 3, '2026-10-11', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(26, 1, 3, '2026-10-12', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(27, 1, 3, '2026-10-13', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(28, 1, 3, '2026-10-14', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(29, 1, 3, '2026-10-18', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(30, 1, 3, '2026-10-19', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(31, 1, 3, '2026-10-22', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(32, 1, 3, '2026-10-23', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(33, 1, 3, '2026-10-24', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(34, 1, 3, '2026-10-26', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(35, 1, 3, '2026-10-27', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(36, 1, 3, '2026-10-28', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(37, 1, 3, '2026-10-29', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(38, 1, 3, '2026-10-30', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL),
(39, 1, 3, '2026-10-31', 'Shift 1 (Pagi)', '07:00', '17:00', 1, 0, NULL);

-- --------------------------------------------------------
-- Table structure for `shift_swaps`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `shift_swaps`;
CREATE TABLE `shift_swaps` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `requester_id` INT NOT NULL,
    `target_id` INT NOT NULL,
    `branch_id` INT NOT NULL,
    `shift_date` DATE NOT NULL,
    `reason` TEXT,
    `status` VARCHAR(255) DEFAULT 'PENDING',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `target_date` DATE DEFAULT NULL,
    `requester_shift_name` VARCHAR(255) DEFAULT NULL,
    `target_shift_name` VARCHAR(255) DEFAULT NULL,
    `approved_by` INT DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `rejection_reason` TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `shift_swaps`
INSERT INTO `shift_swaps` (`id`, `requester_id`, `target_id`, `branch_id`, `shift_date`, `reason`, `status`, `created_at`, `target_date`, `requester_shift_name`, `target_shift_name`, `approved_by`, `approved_at`, `rejection_reason`) VALUES
(16, 3, 4, 1, '2026-10-15', 'Ada keperluan keluarga pagi hari', 'APPROVED', '2026-10-19 05:29:23', '2026-10-15', 'Shift 1 (Pagi)', 'Shift 2 (Siang/Sore)', 1, '2026-10-19 12:29:23', NULL);

-- --------------------------------------------------------
-- Table structure for `leave_requests`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `leave_requests`;
CREATE TABLE `leave_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `leave_type` VARCHAR(255) NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `reason` TEXT NOT NULL,
    `status` VARCHAR(255) DEFAULT 'PENDING',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `branch_id` INT DEFAULT 1,
    `attachment_doc` TEXT DEFAULT NULL,
    `approved_by` INT DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `rejection_reason` TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `leave_requests`
INSERT INTO `leave_requests` (`id`, `user_id`, `leave_type`, `start_date`, `end_date`, `reason`, `status`, `created_at`, `branch_id`, `attachment_doc`, `approved_by`, `approved_at`, `rejection_reason`) VALUES
(16, 3, 'SAKIT', '2026-10-20', '2026-10-21', 'Demam tinggi rawat jalan', 'APPROVED', '2026-10-19 05:29:23', 1, 'Surat Dokter No. 445/MED/2026', 1, '2026-10-19 12:29:23', NULL);

-- --------------------------------------------------------
-- Table structure for `salary_loans`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `salary_loans`;
CREATE TABLE `salary_loans` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `branch_id` INT NOT NULL,
    `loan_date` DATE NOT NULL,
    `amount` DECIMAL(15, 2) NOT NULL,
    `reason` TEXT NOT NULL,
    `installment_months` INT DEFAULT 1,
    `monthly_deduction` DECIMAL(15, 2) NOT NULL,
    `status` VARCHAR(255) DEFAULT 'PENDING',
    `remaining_amount` DECIMAL(15, 2) NOT NULL,
    `approved_by` INT,
    `approved_at` DATETIME,
    `rejection_reason` TEXT,
    `notes` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `shift_id` INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `salary_loans`
INSERT INTO `salary_loans` (`id`, `user_id`, `branch_id`, `loan_date`, `amount`, `reason`, `installment_months`, `monthly_deduction`, `status`, `remaining_amount`, `approved_by`, `approved_at`, `rejection_reason`, `notes`, `created_at`, `shift_id`) VALUES
(7, 2, 1, '2026-10-12', 150000, 'Kasbon Kasir Shift #1: Kasbon tunai laci kasir darurat', 1, 150000, 'ACTIVE', 150000, 1, '2026-10-12 20:53:47', NULL, 'Kasbon dicairkan tunai dari laci kasir pada shift #40 (Shift Uji Kasir)', '2026-10-12 13:53:47', NULL),
(8, 2, 1, '2026-10-12', 150000, 'Kasbon Kasir Shift #1: Kasbon tunai laci kasir darurat', 1, 150000, 'ACTIVE', 150000, 1, '2026-10-12 20:54:10', NULL, 'Kasbon dicairkan tunai dari laci kasir pada shift #41 (Shift Uji Kasir)', '2026-10-12 13:54:10', NULL),
(9, 2, 1, '2026-10-12', 150000, 'Kasbon Kasir Shift #1: Kasbon tunai laci kasir darurat', 1, 150000, 'ACTIVE', 150000, 1, '2026-10-12 21:26:15', NULL, 'Kasbon dicairkan tunai dari laci kasir pada shift #42 (Shift Uji Kasir)', '2026-10-12 14:26:15', NULL),
(10, 2, 1, '2026-10-12', 150000, 'Kasbon Kasir Shift #1: Kasbon tunai laci kasir darurat', 1, 150000, 'ACTIVE', 150000, 1, '2026-10-12 21:27:30', NULL, 'Kasbon dicairkan tunai dari laci kasir pada shift #43 (Shift Uji Kasir)', '2026-10-12 14:27:30', NULL),
(23, 3, 1, '2026-10-19', 600000, 'Perbaikan kendaraan motor', 3, 200000, 'ACTIVE', 400000, 1, '2026-10-19 12:29:23', NULL, 'Potongan gaji bulan berjalan', '2026-10-19 05:29:23', NULL),
(25, 2, 1, '2026-10-19', 150000, 'Kasbon Kasir Shift #1: Kasbon tunai laci kasir darurat', 1, 150000, 'ACTIVE', 150000, 1, '2026-10-19 07:29:52', NULL, 'Kasbon dicairkan tunai dari laci kasir pada shift #53 (Shift Uji Kasir)', '2026-10-19 05:29:52', NULL);

-- --------------------------------------------------------
-- Table structure for `employee_payrolls`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `employee_payrolls`;
CREATE TABLE `employee_payrolls` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `branch_id` INT NOT NULL,
    `period_month` VARCHAR(255) NOT NULL,
    `base_salary` DECIMAL(15, 2) NOT NULL,
    `allowance` DECIMAL(15, 2) NOT NULL,
    `attendance_days` INT DEFAULT 0,
    `daily_transport_rate` DECIMAL(8, 4) DEFAULT 0,
    `transport_total` DECIMAL(15, 2) DEFAULT 0,
    `sales_commission` DECIMAL(15, 2) DEFAULT 0,
    `overtime_pay` DECIMAL(15, 2) DEFAULT 0,
    `bonus_pay` DECIMAL(15, 2) DEFAULT 0,
    `gross_salary` DECIMAL(15, 2) NOT NULL,
    `late_deduction` DECIMAL(15, 2) DEFAULT 0,
    `absence_deduction` DECIMAL(15, 2) DEFAULT 0,
    `loan_deduction` DECIMAL(15, 2) DEFAULT 0,
    `tax_deduction` DECIMAL(15, 2) DEFAULT 0,
    `other_deduction` DECIMAL(15, 2) DEFAULT 0,
    `total_deductions` DECIMAL(15, 2) NOT NULL,
    `net_salary` DECIMAL(15, 2) NOT NULL,
    `payment_status` VARCHAR(255) DEFAULT 'GENERATED',
    `notes` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `employee_payrolls`
INSERT INTO `employee_payrolls` (`id`, `user_id`, `branch_id`, `period_month`, `base_salary`, `allowance`, `attendance_days`, `daily_transport_rate`, `transport_total`, `sales_commission`, `overtime_pay`, `bonus_pay`, `gross_salary`, `late_deduction`, `absence_deduction`, `loan_deduction`, `tax_deduction`, `other_deduction`, `total_deductions`, `net_salary`, `payment_status`, `notes`, `created_at`) VALUES
(13, 3, 1, '2026-10', 3000000, 500000, 3, 25000, 75000, 0, 0, 0, 3575000, 240000, 0, 0, 125000, 0, 365000, 3210000, 'GENERATED', 'Slip Gaji Otomatis Terverifikasi', '2026-10-19 05:29:23');

-- --------------------------------------------------------
-- Table structure for `reimbursements`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `reimbursements`;
CREATE TABLE `reimbursements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `branch_id` INT NOT NULL,
    `expense_date` DATE NOT NULL,
    `category` VARCHAR(255) NOT NULL,
    `amount` DECIMAL(15, 2) NOT NULL,
    `description` TEXT NOT NULL,
    `status` VARCHAR(255) DEFAULT 'PENDING',
    `receipt_photo` LONGTEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `disbursed_at` DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `reimbursements`
INSERT INTO `reimbursements` (`id`, `user_id`, `branch_id`, `expense_date`, `category`, `amount`, `description`, `status`, `receipt_photo`, `created_at`, `disbursed_at`) VALUES
(1, 3, 1, '2026-10-09', 'KERTAS_KASIR', 65000, 'Beli 3 roll kertas thermal 80mm darurat karena stok habis', 'DISBURSED', NULL, '2026-10-09 14:53:25', '2026-10-09 12:53:25'),
(2, 4, 1, '2026-10-09', 'UTILITAS_DARURAT', 50000, 'Isi token listrik darurat toko saat alarm berbunyi', 'APPROVED_BM', NULL, '2026-10-09 14:53:25', NULL),
(3, 4, 1, '2026-10-15', 'BENSIN', 75000, 'Bensin antar barang Abdul Latif', 'DISBURSED', NULL, '2026-10-15 17:09:38', '2026-10-16 00:09:38'),
(4, 4, 1, '2026-10-15', 'BENSIN', 75000, 'Bensin antar barang Abdul Latif', 'PENDING', NULL, '2026-10-15 17:10:02', NULL);

-- --------------------------------------------------------
-- Table structure for `branch_expenses`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `branch_expenses`;
CREATE TABLE `branch_expenses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT NOT NULL,
    `category` VARCHAR(255) NOT NULL,
    `amount` DECIMAL(15, 2) NOT NULL,
    `expense_date` DATE NOT NULL,
    `source` VARCHAR(255) DEFAULT 'MANUAL',
    `notes` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `shift_id` INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `vehicle_fuel_logs`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `vehicle_fuel_logs`;
CREATE TABLE `vehicle_fuel_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_id` INT NOT NULL,
    `branch_id` INT NOT NULL,
    `log_date` DATE NOT NULL,
    `last_odometer` DECIMAL(15, 2) NOT NULL,
    `current_odometer` DECIMAL(15, 2) NOT NULL,
    `distance_traveled` DECIMAL(12, 4) NOT NULL,
    `liters_bought` DECIMAL(12, 4) NOT NULL,
    `cost_per_liter` DECIMAL(15, 2) NOT NULL,
    `total_cost` DECIMAL(15, 2) NOT NULL,
    `configured_ratio` DECIMAL(8, 4) NOT NULL,
    `expected_liters` DECIMAL(12, 4) NOT NULL,
    `efficiency_percent` DECIMAL(8, 4) NOT NULL,
    `fuel_type` VARCHAR(255) DEFAULT 'Pertalite',
    `driver_name` VARCHAR(255),
    `expense_record_id` INT,
    `notes` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `vehicle_fuel_logs`
INSERT INTO `vehicle_fuel_logs` (`id`, `vehicle_id`, `branch_id`, `log_date`, `last_odometer`, `current_odometer`, `distance_traveled`, `liters_bought`, `cost_per_liter`, `total_cost`, `configured_ratio`, `expected_liters`, `efficiency_percent`, `fuel_type`, `driver_name`, `expense_record_id`, `notes`, `created_at`) VALUES
(1, 1, 1, '2026-10-07', 14120, 14250, 130, 3.5, 10000, 35000, 38, 3.42, 97.7, 'Pertalite', 'Doni Prasetyo', NULL, 'Pengisian rutin SPBU Pertamina Rasuna Said (130 KM / 38 KM/L = 3.42 Liter teoretis)', '2026-10-09 16:35:24'),
(2, 1, 1, '2026-10-09', 14250, 14364, 114, 3, 10000, 30000, 38, 3, 100, 'Pertalite', NULL, NULL, NULL, '2026-10-09 23:57:54'),
(3, 1, 1, '2026-10-09', 14364, 14478, 114, 3, 10000, 30000, 38, 3, 100, 'Pertalite', NULL, NULL, NULL, '2026-10-09 23:58:06'),
(4, 1, 3, '2026-10-10', 14593.04, 14707.04, 114, 3, 10000, 30000, 38, 3, 100, 'Pertalite', NULL, NULL, NULL, '2026-10-10 19:19:33'),
(5, 1, 1, '2026-10-19', 14832.32, 14946.32, 114, 3, 10000, 30000, 38, 3, 100, 'Pertalite', NULL, NULL, NULL, '2026-10-19 12:29:24');

-- --------------------------------------------------------
-- Table structure for `vehicle_service_logs`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `vehicle_service_logs`;
CREATE TABLE `vehicle_service_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_id` INT NOT NULL,
    `branch_id` INT NOT NULL,
    `service_date` DATE NOT NULL,
    `odometer_km` DECIMAL(12, 4) NOT NULL,
    `service_type` VARCHAR(255) NOT NULL,
    `workshop_name` VARCHAR(255) NOT NULL,
    `cost` DECIMAL(15, 2) NOT NULL,
    `expense_record_id` INT,
    `notes` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `vehicle_service_logs`
INSERT INTO `vehicle_service_logs` (`id`, `vehicle_id`, `branch_id`, `service_date`, `odometer_km`, `service_type`, `workshop_name`, `cost`, `expense_record_id`, `notes`, `created_at`) VALUES
(1, 1, 1, '2026-10-04', 14000, 'Ganti Oli Mesin SPX & Gardan + Tune Up Rutin', 'AHASS Honda Menteng Motor', 125000, NULL, 'Servis rutin 14.000 KM kondisi mesin prima, kampas rem depan baru', '2026-10-09 16:35:24'),
(2, 1, 1, '2026-10-09', 14364, 'Ganti Oli Rutin & Tune Up', 'AHASS Honda Palmerah', 135000, NULL, 'Ganti oli mesin MPX2 + kampas rem depan', '2026-10-09 23:57:54'),
(3, 1, 1, '2026-10-09', 14478, 'Ganti Oli Rutin & Tune Up', 'AHASS Honda Palmerah', 135000, NULL, 'Ganti oli mesin MPX2 + kampas rem depan', '2026-10-09 23:58:06'),
(4, 1, 3, '2026-10-10', 14707.04, 'Ganti Oli Rutin & Tune Up', 'AHASS Honda Palmerah', 135000, NULL, 'Ganti oli mesin MPX2 + kampas rem depan', '2026-10-10 19:19:33'),
(5, 1, 1, '2026-10-19', 14946.32, 'Ganti Oli Rutin & Tune Up', 'AHASS Honda Palmerah', 135000, NULL, 'Ganti oli mesin MPX2 + kampas rem depan', '2026-10-19 12:29:24');

-- --------------------------------------------------------
-- Table structure for `customer_receivables`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `customer_receivables`;
CREATE TABLE `customer_receivables` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sale_id` INT NOT NULL,
    `invoice_number` VARCHAR(100) NOT NULL,
    `customer_id` INT NOT NULL,
    `branch_id` INT NOT NULL,
    `total_amount` DECIMAL(15, 2) NOT NULL,
    `paid_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0,
    `remaining_amount` DECIMAL(15, 2) NOT NULL,
    `due_date` DATE,
    `status` VARCHAR(255) NOT NULL DEFAULT 'BELUM_LUNAS',
    `notes` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `customer_receivables`
INSERT INTO `customer_receivables` (`id`, `sale_id`, `invoice_number`, `customer_id`, `branch_id`, `total_amount`, `paid_amount`, `remaining_amount`, `due_date`, `status`, `notes`, `created_at`, `updated_at`) VALUES
(4, 26, 'INV/JKT-01/20261025/0026', 3, 1, 70000, 0, 70000, '2026-11-24', 'BELUM_LUNAS', 'Piutang penjualan POS kpd Warung Key (MITRA)', '2026-10-25 20:56:39', '2026-10-25 20:56:39');

-- --------------------------------------------------------
-- Table structure for `receivable_payments`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `receivable_payments`;
CREATE TABLE `receivable_payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `receivable_id` INT NOT NULL,
    `payment_date` DATE NOT NULL,
    `amount` DECIMAL(15, 2) NOT NULL,
    `payment_method` VARCHAR(255) NOT NULL DEFAULT 'CASH',
    `reference_no` VARCHAR(255),
    `cashier_user_id` INT,
    `notes` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `branch_settings`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `branch_settings`;
CREATE TABLE `branch_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT NOT NULL,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` LONGTEXT,
    `setting_group` VARCHAR(255) DEFAULT 'GENERAL',
    `description` TEXT,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `branch_settings`
INSERT INTO `branch_settings` (`id`, `branch_id`, `setting_key`, `setting_value`, `setting_group`, `description`, `updated_at`) VALUES
(597, 1, 'app_name', 'ESBAS SUPERSTORE CIMAHI', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(598, 1, 'app_subtitle', 'Pusat Belanja & Grosir Cimahi', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(599, 1, 'app_logo_emoji', '⚡', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(600, 1, 'company_name', 'PT Esbas Cabang Cimahi', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(605, 1, 'printer_type', 'BROWSER', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(606, 1, 'printer_ip_address', '192.168.1.150', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(607, 1, 'printer_ip_port', '9100', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(608, 1, 'printer_paper_width', '80mm', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(609, 1, 'printer_auto_cut', '1', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(610, 1, 'printer_cash_drawer', '1', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(617, 1, 'receipt_header_title', 'ESBAS TOKO CABANG 1', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(618, 1, 'receipt_header_subtitle', 'Pusat Sembako & Kopi', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(619, 1, 'receipt_header_custom_text', 'NPWP CABANG CIMAHI: 01.111.111.1-111.000', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(620, 1, 'receipt_body_show_sku', '1', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(621, 1, 'receipt_footer_line1', 'Terima Kasih Atas Kunjungan Anda!', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(622, 1, 'receipt_footer_line3', 'IG: @esbas.cimahi', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(629, 1, 'payslip_header_title', 'SLIP GAJI KARYAWAN OUTLET CIMAHI', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(630, 1, 'payslip_company_name', 'PT ESBAS RETAIL - UNIT CIMAHI', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(631, 1, 'payslip_signer_1_title', 'Staf Cabang Cimahi', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(632, 1, 'payslip_signer_2_title', 'Branch Manager Cimahi', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(637, 1, 'wa_api_url', 'https://api.fonnte.com/send', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(638, 1, 'wa_api_token', 'token_secret_cimahi_111', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(639, 1, 'wa_template_message', 'Halo {customer_name}, terima kasih belanja di Cimahi! Faktur: {invoice_number} Total: {grand_total}', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(640, 1, 'telegram_bot_token', '111111:AAABBB_CimahiBot', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(641, 1, 'telegram_chat_id', '-100111111', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(642, 1, 'telegram_template_message', '🛒 NOTA CIMAHI: {invoice_number} oleh {cashier_name} Total: {grand_total}', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(649, 1, 'company_address', 'Jl. Ciganitri Mukti V No. 13', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(650, 1, 'company_phone', '081220169030', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(651, 1, 'receipt_show_logo', '1', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(652, 1, 'receipt_body_compact_spacing', '0', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(653, 1, 'receipt_footer_line2', 'Barang yang sudah dibeli tidak dapat ditukar', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(654, 1, 'payslip_confidential_label', 'RAHASIA & PRIBADI (CONFIDENTIAL)', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(655, 1, 'payslip_show_terbilang', '1', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(656, 1, 'payslip_show_attendance_detail', '1', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(657, 1, 'payslip_footer_notes', 'Slip gaji ini dicetak secara komputerisasi dan sah tanpa tanda tangan basah bila disertai stempel verifikasi.', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15'),
(658, 1, 'wa_gateway_provider', 'FONNTE', 'BRANCH', 'Pengaturan Khusus Cabang', '2026-10-23 12:43:15');

-- --------------------------------------------------------
-- Table structure for `employee_meal_allowances`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `employee_meal_allowances`;
CREATE TABLE `employee_meal_allowances` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `shift_id` INT,
    `user_id` INT NOT NULL,
    `branch_id` INT NOT NULL,
    `amount` DECIMAL(15, 2) NOT NULL,
    `meal_date` DATE NOT NULL,
    `notes` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `cash_denominations`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `cash_denominations`;
CREATE TABLE `cash_denominations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `label` VARCHAR(255) NOT NULL,
    `amount` DECIMAL(15, 2) NOT NULL DEFAULT 0,
    `action_type` VARCHAR(255) NOT NULL DEFAULT 'SET',
    `color` VARCHAR(255) DEFAULT 'indigo',
    `sort_order` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `cash_denominations`
INSERT INTO `cash_denominations` (`id`, `label`, `amount`, `action_type`, `color`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Uang Pas', 0, 'EXACT', 'indigo', 1, 1, '2026-10-19 10:59:31', '2026-10-19 10:59:31'),
(2, '10K', 10000, 'SET', 'indigo', 2, 1, '2026-10-19 10:59:31', '2026-10-19 10:59:31'),
(3, '20K', 20000, 'SET', 'indigo', 3, 1, '2026-10-19 10:59:31', '2026-10-19 10:59:31'),
(4, '50K', 50000, 'SET', 'indigo', 4, 1, '2026-10-19 10:59:31', '2026-10-19 10:59:31'),
(5, '100K', 100000, 'SET', 'indigo', 5, 1, '2026-10-19 10:59:31', '2026-10-19 10:59:31');

-- --------------------------------------------------------
-- Table structure for `user_roles`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `user_roles`;
CREATE TABLE `user_roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(100) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `is_system` TINYINT(1) DEFAULT 0,
    `can_access_all_branches` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `user_roles`
INSERT INTO `user_roles` (`id`, `code`, `name`, `description`, `is_system`, `can_access_all_branches`, `created_at`, `updated_at`) VALUES
(1, 'SUPER_ADMIN', 'Super Admin Pusat', 'Akses penuh ke seluruh cabang dan 15 modul sistem', 1, 1, '2026-10-23 18:02:44', '2026-10-23 18:02:44'),
(2, 'BRANCH_MANAGER', 'Branch Manager', 'Manajemen operasional dan laporan outlet cabang lokal', 1, 0, '2026-10-23 18:02:44', '2026-10-23 18:02:44'),
(3, 'CASHIER', 'Kasir (Cashier)', 'Operasional kasir POS penjualan, buka/tutup shift, dan cetak nota', 1, 0, '2026-10-23 18:02:44', '2026-10-23 18:02:44'),
(4, 'STAFF', 'Staf Gudang / Toko', 'Pengelolaan inventori barang, stok, dan resep produksi BOM', 1, 0, '2026-10-23 18:02:44', '2026-10-23 18:02:44');

SET FOREIGN_KEY_CHECKS = 1;

-- End of ESBAS POS MySQL Dump
