<?php
/**
 * ====================================================================
 * ESBAS POS - Template Konfigurasi Database (Contoh)
 * ====================================================================
 * Salin file ini menjadi config.php dan sesuaikan kredensialnya.
 */

// 1. Tipe Driver: 'mysql' (hosting produksi) atau 'sqlite' (lokal laptop)
define('DB_DRIVER', 'sqlite');

// 2. Kredensial MySQL
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'esbas_pos');
define('DB_USER', 'root');
define('DB_PASS', '');

// 3. Kredensial SQLite
define('DB_SQLITE_PATH', __DIR__ . '/database.sqlite');

// 4. Konfigurasi Sinkronisasi Master Hosting & Lokal
define('HOSTING_API_URL', 'https://kadtech.my.id');
define('SYNC_SECRET_KEY', 'esbas_master_sync_2026_kadtech');
