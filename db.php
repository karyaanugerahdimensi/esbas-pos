<?php
/**
 * Database connection & schema migration for ESBAS-POS (SQLite)
 * Includes: Multi-Branch, Users CRUD, Products/Categories/Variants/Types, Recipe/BOM, Stock Movements
 */

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} elseif (file_exists(__DIR__ . '/config.example.php')) {
    require_once __DIR__ . '/config.example.php';
}

$dbDriver = defined('DB_DRIVER') ? strtolower(DB_DRIVER) : 'sqlite';

/**
 * Universal PDO Wrapper to bridge MySQL and SQLite syntax seamlessly
 */
class AppPDO extends PDO {
    protected bool $isMysql = false;

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null) {
        parent::__construct($dsn, $username, $password, $options ?? []);
        $this->isMysql = (strpos($dsn, 'mysql:') === 0);
    }

    public function setIsMysql(bool $val): void {
        $this->isMysql = $val;
    }

    public function isMysql(): bool {
        return $this->isMysql;
    }

    public function adaptQuery(string $sql): string {
        if ($this->isMysql) {
            // 1. Replace SQLite datetime functions with MySQL NOW()
            $sql = preg_replace("/datetime\s*\(\s*'now'\s*,\s*'localtime'\s*\)/i", "NOW()", $sql);
            $sql = preg_replace("/datetime\s*\(\s*'now'\s*\)/i", "NOW()", $sql);

            // 2. Replace SQLite date functions with MySQL CURDATE()
            $sql = preg_replace("/date\s*\(\s*'now'\s*,\s*'localtime'\s*\)/i", "CURDATE()", $sql);
            $sql = preg_replace("/date\s*\(\s*'now'\s*\)/i", "CURDATE()", $sql);

            // 3. Replace SQLite time functions with MySQL CURTIME()
            $sql = preg_replace("/time\s*\(\s*'now'\s*,\s*'localtime'\s*\)/i", "CURTIME()", $sql);
            $sql = preg_replace("/time\s*\(\s*'now'\s*\)/i", "CURTIME()", $sql);

            // 4. Replace strftime formatting
            $sql = preg_replace("/strftime\('%Y-%m',\s*created_at\)\s*=\s*strftime\('%Y-%m',\s*'now'\)/i", "DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')", $sql);
            $sql = preg_replace("/strftime\('%Y-%m',\s*([^)]+)\)/i", "DATE_FORMAT($1, '%Y-%m')", $sql);

            // 5. Replace julianday aging difference
            $sql = preg_replace("/cast\s*\(\s*\(\s*julianday\s*\(\s*'now'(?:\s*,\s*'localtime')?\s*\)\s*-\s*julianday\s*\(\s*([^)]+)\s*\)\s*\)\s*as\s+integer\s*\)/i", "DATEDIFF(NOW(), $1)", $sql);
            $sql = preg_replace("/julianday\s*\(\s*'now'(?:\s*,\s*'localtime')?\s*\)\s*-\s*julianday\s*\(\s*([^)]+)\s*\)/i", "DATEDIFF(NOW(), $1)", $sql);

            // 6. Replace PRAGMA table_info
            if (preg_match('/PRAGMA\s+table_info\(([^)]+)\)/i', $sql, $m)) {
                $tableName = trim($m[1], '`"\' ');
                $sql = "SELECT COLUMN_NAME AS name, DATA_TYPE AS type FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tableName}'";
            }

            // 7. Replace ON CONFLICT ... DO UPDATE SET
            if (preg_match('/ON\s+CONFLICT\s*\([^)]*\)\s*DO\s+UPDATE\s+SET/i', $sql)) {
                $sql = preg_replace('/ON\s+CONFLICT\s*\([^)]*\)\s*DO\s+UPDATE\s+SET/i', 'ON DUPLICATE KEY UPDATE', $sql);
                $sql = preg_replace('/excluded\.([a-zA-Z0-9_]+)/i', 'VALUES($1)', $sql);
            }

            // 8. Replace SQLite INSERT OR REPLACE INTO with MySQL REPLACE INTO
            $sql = preg_replace('/INSERT\s+OR\s+REPLACE\s+INTO/i', 'REPLACE INTO', $sql);

            // 9. Replace SQLite INSERT OR IGNORE INTO with MySQL INSERT IGNORE INTO
            $sql = preg_replace('/INSERT\s+OR\s+IGNORE\s+INTO/i', 'INSERT IGNORE INTO', $sql);
        }
        return $sql;
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare($this->adaptQuery($query), $options);
    }

    #[\ReturnTypeWillChange]
    public function exec(string $statement): int|false {
        return parent::exec($this->adaptQuery($statement));
    }

    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $adapted = $this->adaptQuery($query);
        if ($fetchMode !== null) {
            return parent::query($adapted, $fetchMode, ...$fetchModeArgs);
        }
        return parent::query($adapted);
    }
}

try {
    if ($dbDriver === 'mysql') {
        $host = defined('DB_HOST') ? DB_HOST : 'localhost';
        $port = defined('DB_PORT') ? DB_PORT : '3306';
        $dbname = defined('DB_NAME') ? DB_NAME : 'esbas_pos';
        $user = defined('DB_USER') ? DB_USER : 'root';
        $pass = defined('DB_PASS') ? DB_PASS : '';

        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
        $pdo = new AppPDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        $pdo->exec("SET NAMES utf8mb4");
    } else {
        $dbPath = defined('DB_SQLITE_PATH') ? DB_SQLITE_PATH : (__DIR__ . '/database.sqlite');
        $pdo = new AppPDO("sqlite:" . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("PRAGMA foreign_keys = ON;");
        $pdo->exec("PRAGMA journal_mode = WAL;");
        $pdo->exec("PRAGMA busy_timeout = 5000;");
    }
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

function getDbTableColumns($pdo, $tableName) {
    global $dbDriver;
    if ($dbDriver === 'mysql') {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$tableName`");
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        } catch (Exception $e) {
            return [];
        }
    }
    try {
        return array_column($pdo->query("PRAGMA table_info($tableName)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    } catch (Exception $e) {
        return [];
    }
}

function initDatabaseSchema($pdo) {
    // 1. Branches
    $pdo->exec("CREATE TABLE IF NOT EXISTS branches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        address TEXT NOT NULL,
        latitude REAL NOT NULL,
        longitude REAL NOT NULL,
        radius_meters INTEGER DEFAULT 100,
        phone TEXT
    )");

    // 2. Users / Employees (Full CRUD Support)
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nik TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        role TEXT NOT NULL,
        branch_id INTEGER,
        email TEXT UNIQUE,
        phone TEXT,
        password TEXT DEFAULT 'password123',
        pin TEXT DEFAULT '123456',
        commission_rate REAL DEFAULT 0.015,
        default_work_shift_id INTEGER DEFAULT NULL,
        assigned_vehicle_id INTEGER DEFAULT NULL,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (branch_id) REFERENCES branches(id),
        FOREIGN KEY (default_work_shift_id) REFERENCES work_shifts(id),
        FOREIGN KEY (assigned_vehicle_id) REFERENCES courier_vehicles(id)
    )");

    // 3. Product Categories
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        icon_emoji TEXT DEFAULT '📁'
    )");

    // 4. Products
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku TEXT UNIQUE NOT NULL,
        barcode TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        category_id INTEGER,
        category TEXT NOT NULL,
        product_type TEXT DEFAULT 'FINISHED_GOODS',
        unit TEXT DEFAULT 'pcs',
        cogs REAL NOT NULL,
        selling_price REAL NOT NULL,
        image_emoji TEXT DEFAULT '📦',
        has_bom INTEGER DEFAULT 0,
        reorder_point INTEGER DEFAULT 10,
        is_taxable INTEGER DEFAULT 1,
        tax_id INTEGER DEFAULT 1,
        tax_percent REAL DEFAULT 11.0,
        is_delivery_required INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        FOREIGN KEY (category_id) REFERENCES categories(id)
    )");

    // 5. Product Variants
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_variants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        variant_name TEXT NOT NULL,
        sku_variant TEXT UNIQUE NOT NULL,
        additional_price REAL DEFAULT 0,
        additional_cogs REAL DEFAULT 0,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    )");

    // 6. Recipe / BOM (Bill of Materials) - Base Recipe
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_recipes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        finished_product_id INTEGER NOT NULL,
        ingredient_product_id INTEGER NOT NULL,
        qty_needed REAL NOT NULL,
        unit TEXT NOT NULL,
        FOREIGN KEY (finished_product_id) REFERENCES products(id) ON DELETE CASCADE,
        FOREIGN KEY (ingredient_product_id) REFERENCES products(id)
    )");

    // 6b. Variant Recipe / Variant BOM (Bahan Baku Spesifik Varian yang Memotong Stok)
    $pdo->exec("CREATE TABLE IF NOT EXISTS variant_recipes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        variant_id INTEGER NOT NULL,
        ingredient_product_id INTEGER NOT NULL,
        qty_needed REAL NOT NULL DEFAULT 1,
        unit TEXT NOT NULL DEFAULT 'pcs',
        notes TEXT,
        FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,
        FOREIGN KEY (ingredient_product_id) REFERENCES products(id)
    )");

    // 7. Branch Stocks
    $pdo->exec("CREATE TABLE IF NOT EXISTS branch_stocks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        stock REAL NOT NULL DEFAULT 0,
        local_cogs REAL NOT NULL,
        local_price REAL NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        is_delivery_required INTEGER DEFAULT NULL,
        last_updated DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(branch_id, product_id),
        FOREIGN KEY (branch_id) REFERENCES branches(id),
        FOREIGN KEY (product_id) REFERENCES products(id)
    )");

    // 8. Stock Movement
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_movements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        movement_type TEXT NOT NULL,
        qty_change REAL NOT NULL,
        stock_before REAL NOT NULL,
        stock_after REAL NOT NULL,
        reference_no TEXT,
        notes TEXT,
        user_id INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (branch_id) REFERENCES branches(id),
        FOREIGN KEY (product_id) REFERENCES products(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // 9. Cashier Shifts (Opening & Closing Sales with Material Stock Verification)
    $pdo->exec("CREATE TABLE IF NOT EXISTS cashier_shifts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        start_time DATETIME NOT NULL,
        end_time DATETIME,
        initial_cash REAL NOT NULL,
        expected_cash REAL DEFAULT 0,
        actual_cash REAL DEFAULT 0,
        cash_difference REAL DEFAULT 0,
        opening_raw_mineral INTEGER DEFAULT 0,
        opening_raw_ro INTEGER DEFAULT 0,
        opening_raw_plastik INTEGER DEFAULT 0,
        closing_night_mineral INTEGER DEFAULT 0,
        closing_night_ro INTEGER DEFAULT 0,
        closing_night_plastik INTEGER DEFAULT 0,
        closing_raw_mineral INTEGER DEFAULT NULL,
        closing_raw_ro INTEGER DEFAULT NULL,
        closing_raw_plastik INTEGER DEFAULT NULL,
        shift_number INTEGER DEFAULT 1,
        shift_name TEXT DEFAULT 'Shift 1',
        shift_type TEXT DEFAULT 'SHIFT_1',
        is_final_shift INTEGER DEFAULT 0,
        shift_notes TEXT DEFAULT NULL,
        opening_items_json TEXT DEFAULT NULL,
        closing_items_json TEXT DEFAULT NULL,
        status TEXT DEFAULT 'OPEN',
        FOREIGN KEY (branch_id) REFERENCES branches(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // 10. Sales
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_number TEXT UNIQUE NOT NULL,
        branch_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        shift_id INTEGER,
        subtotal REAL NOT NULL,
        discount REAL DEFAULT 0,
        tax REAL DEFAULT 0,
        grand_total REAL NOT NULL,
        total_cogs REAL NOT NULL,
        payment_method TEXT NOT NULL,
        payment_status TEXT DEFAULT 'PAID',
        cash_received REAL DEFAULT 0,
        cash_change REAL DEFAULT 0,
        status TEXT DEFAULT 'COMPLETED',
        cancellation_reason TEXT,
        cancelled_by INTEGER,
        cancelled_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (branch_id) REFERENCES branches(id),
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (cancelled_by) REFERENCES users(id)
    )");

    // 11. Sale Items
    $pdo->exec("CREATE TABLE IF NOT EXISTS sale_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        variant_id INTEGER,
        product_name TEXT NOT NULL,
        qty INTEGER NOT NULL,
        unit_price REAL NOT NULL,
        unit_cogs REAL NOT NULL,
        subtotal_price REAL NOT NULL,
        subtotal_cogs REAL NOT NULL,
        FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id)
    )");

    // 12. Attendance Logs (ESS)
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        branch_id INTEGER NOT NULL,
        clock_in DATETIME NOT NULL,
        clock_out DATETIME,
        latitude REAL,
        longitude REAL,
        distance_meters REAL,
        status TEXT DEFAULT 'ON_TIME',
        note TEXT,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (branch_id) REFERENCES branches(id)
    )");

    // 12b. Master Work Shifts (Jam Kerja CRUD)
    $pdo->exec("CREATE TABLE IF NOT EXISTS work_shifts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        start_time TEXT NOT NULL,
        end_time TEXT NOT NULL,
        late_tolerance_mins INTEGER DEFAULT 15,
        is_day_off INTEGER DEFAULT 0,
        color_tag TEXT DEFAULT '#3b82f6',
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 13. Shift Schedules (ESS Roster Bulanan)
    $pdo->exec("CREATE TABLE IF NOT EXISTS shift_schedules (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        shift_date DATE NOT NULL,
        work_shift_id INTEGER,
        shift_name TEXT NOT NULL,
        start_time TEXT NOT NULL,
        end_time TEXT NOT NULL,
        is_holiday INTEGER DEFAULT 0,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (branch_id) REFERENCES branches(id),
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (work_shift_id) REFERENCES work_shifts(id)
    )");

    // 14. Shift Swap Requests (ESS Tukar Jadwal)
    $pdo->exec("CREATE TABLE IF NOT EXISTS shift_swaps (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        requester_id INTEGER NOT NULL,
        target_id INTEGER NOT NULL,
        branch_id INTEGER NOT NULL,
        shift_date DATE NOT NULL,
        target_date DATE,
        requester_shift_name TEXT,
        target_shift_name TEXT,
        reason TEXT,
        status TEXT DEFAULT 'PENDING',
        approved_by INTEGER,
        approved_at DATETIME,
        rejection_reason TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (requester_id) REFERENCES users(id),
        FOREIGN KEY (target_id) REFERENCES users(id),
        FOREIGN KEY (branch_id) REFERENCES branches(id)
    )");

    // 15. Leave Requests (ESS Ketidakhadiran: Cuti / Izin / Sakit)
    $pdo->exec("CREATE TABLE IF NOT EXISTS leave_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        branch_id INTEGER NOT NULL,
        leave_type TEXT NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        reason TEXT NOT NULL,
        attachment_doc TEXT,
        status TEXT DEFAULT 'PENDING',
        approved_by INTEGER,
        approved_at DATETIME,
        rejection_reason TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (branch_id) REFERENCES branches(id)
    )");

    // 15b. Salary Loans / Kasbon Karyawan
    $pdo->exec("CREATE TABLE IF NOT EXISTS salary_loans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        branch_id INTEGER NOT NULL,
        loan_date DATE NOT NULL,
        amount REAL NOT NULL,
        reason TEXT NOT NULL,
        installment_months INTEGER DEFAULT 1,
        monthly_deduction REAL NOT NULL,
        status TEXT DEFAULT 'PENDING',
        remaining_amount REAL NOT NULL,
        approved_by INTEGER,
        approved_at DATETIME,
        rejection_reason TEXT,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (branch_id) REFERENCES branches(id)
    )");

    // 15c. Employee Digital Payroll / Slip Gaji Mandiri
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_payrolls (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        branch_id INTEGER NOT NULL,
        period_month TEXT NOT NULL,
        base_salary REAL NOT NULL,
        allowance REAL NOT NULL,
        attendance_days INTEGER DEFAULT 0,
        daily_transport_rate REAL DEFAULT 0,
        transport_total REAL DEFAULT 0,
        sales_commission REAL DEFAULT 0,
        overtime_pay REAL DEFAULT 0,
        bonus_pay REAL DEFAULT 0,
        gross_salary REAL NOT NULL,
        late_deduction REAL DEFAULT 0,
        absence_deduction REAL DEFAULT 0,
        loan_deduction REAL DEFAULT 0,
        tax_deduction REAL DEFAULT 0,
        other_deduction REAL DEFAULT 0,
        total_deductions REAL NOT NULL,
        net_salary REAL NOT NULL,
        payment_status TEXT DEFAULT 'GENERATED',
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (branch_id) REFERENCES branches(id)
    )");

    // 16. Reimbursements (ESS)
    $pdo->exec("CREATE TABLE IF NOT EXISTS reimbursements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        branch_id INTEGER NOT NULL,
        expense_date DATE NOT NULL,
        category TEXT NOT NULL,
        amount REAL NOT NULL,
        description TEXT NOT NULL,
        status TEXT DEFAULT 'PENDING',
        receipt_photo TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        disbursed_at DATETIME,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (branch_id) REFERENCES branches(id)
    )");

    // 17. Branch Expenses (Laba Rugi Linkage)
    $pdo->exec("CREATE TABLE IF NOT EXISTS branch_expenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER NOT NULL,
        category TEXT NOT NULL,
        amount REAL NOT NULL,
        expense_date DATE NOT NULL,
        source TEXT DEFAULT 'MANUAL',
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (branch_id) REFERENCES branches(id)
    )");

    // 17b. Expense Categories (Kategori Beban Operasional Master)
    $pdo->exec("CREATE TABLE IF NOT EXISTS expense_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT UNIQUE NOT NULL,
        group_type TEXT DEFAULT 'OPERASIONAL', -- 'PENJUALAN', 'UMUM_ADMIN', 'OPERASIONAL', 'LAINNYA'
        description TEXT,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 18. Customer Categories (Kategori Pelanggan)
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        default_tier TEXT DEFAULT 'REGULER', -- 'REGULER', 'MEMBER', 'RESELLER', 'MITRA'
        description TEXT,
        icon_emoji TEXT DEFAULT '👥',
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 19. Customers & Mitra
    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        phone TEXT,
        email TEXT,
        address TEXT,
        category TEXT DEFAULT 'REGULER', -- 'REGULER', 'MEMBER', 'RESELLER', 'MITRA'
        discount_percent REAL DEFAULT 0,
        branch_id INTEGER,
        notes TEXT,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (branch_id) REFERENCES branches(id)
    )");

    // 19. Mitra Custom Contract Pricing (Setiap mitra harga berbeda)
    $pdo->exec("CREATE TABLE IF NOT EXISTS mitra_custom_prices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        custom_price REAL NOT NULL,
        notes TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(customer_id, product_id),
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    )");

    // 20. Dynamic Delivery Settings (Biaya Kirim Dinamis per Cabang)
    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER UNIQUE NOT NULL,
        base_fee REAL DEFAULT 10000,
        base_distance_km REAL DEFAULT 3.0,
        per_km_rate REAL DEFAULT 2500,
        free_delivery_threshold REAL DEFAULT 150000,
        max_distance_km REAL DEFAULT 35.0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (branch_id) REFERENCES branches(id)
    )");

    // 21. Courier Vehicles (Armada Kurir dengan Rasio Bensin Konfigurabel)
    $pdo->exec("CREATE TABLE IF NOT EXISTS courier_vehicles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER NOT NULL,
        plate_number TEXT UNIQUE NOT NULL,
        vehicle_name TEXT NOT NULL,
        vehicle_type TEXT DEFAULT 'MOTOR', -- 'MOTOR', 'MOBIL'
        driver_name TEXT NOT NULL,
        current_odometer REAL NOT NULL DEFAULT 0,
        fuel_ratio REAL NOT NULL DEFAULT 35.0, -- Rasio efisiensi BBM (misal 35 km per 1 Liter, atau meter per liter yg dapat diubah)
        fuel_ratio_unit TEXT DEFAULT 'KM_PER_LITER', -- 'KM_PER_LITER' atau 'METER_PER_LITER'
        fuel_type TEXT DEFAULT 'Pertalite',
        status TEXT DEFAULT 'AVAILABLE', -- 'AVAILABLE', 'IN_DELIVERY', 'IN_SERVICE'
        notes TEXT,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (branch_id) REFERENCES branches(id)
    )");

    // 22. Vehicle Fuel Purchase Logs (Pencatatan Bensin Menggunakan Kilometer Motor)
    $pdo->exec("CREATE TABLE IF NOT EXISTS vehicle_fuel_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vehicle_id INTEGER NOT NULL,
        branch_id INTEGER NOT NULL,
        log_date DATE NOT NULL,
        last_odometer REAL NOT NULL,
        current_odometer REAL NOT NULL,
        distance_traveled REAL NOT NULL,
        liters_bought REAL NOT NULL,
        cost_per_liter REAL NOT NULL,
        total_cost REAL NOT NULL,
        configured_ratio REAL NOT NULL,
        expected_liters REAL NOT NULL,
        efficiency_percent REAL NOT NULL,
        fuel_type TEXT DEFAULT 'Pertalite',
        driver_name TEXT,
        expense_record_id INTEGER,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (vehicle_id) REFERENCES courier_vehicles(id),
        FOREIGN KEY (branch_id) REFERENCES branches(id),
        FOREIGN KEY (expense_record_id) REFERENCES branch_expenses(id)
    )");

    // 23. Vehicle Service & Maintenance Logs (Catatan Servis & Biaya yang Masuk Beban Laba Rugi)
    $pdo->exec("CREATE TABLE IF NOT EXISTS vehicle_service_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vehicle_id INTEGER NOT NULL,
        branch_id INTEGER NOT NULL,
        service_date DATE NOT NULL,
        odometer_km REAL NOT NULL,
        service_type TEXT NOT NULL,
        workshop_name TEXT NOT NULL,
        cost REAL NOT NULL,
        expense_record_id INTEGER,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (vehicle_id) REFERENCES courier_vehicles(id),
        FOREIGN KEY (branch_id) REFERENCES branches(id),
        FOREIGN KEY (expense_record_id) REFERENCES branch_expenses(id)
    )");

    // 24. Taxes Configuration (Master Pajak / Tax CRUD)
    $pdo->exec("CREATE TABLE IF NOT EXISTS taxes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        rate_percent REAL NOT NULL,
        description TEXT,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 25. Payment Methods (Master Metode Pembayaran CRUD)
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_methods (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        category TEXT DEFAULT 'OTHER',
        account_number TEXT,
        account_holder TEXT,
        description TEXT,
        is_cash INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        sort_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 25b. Cash Denominations (Pecahan Cepat Uang Kasir / Quick Cash POS Buttons)
    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_denominations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        label TEXT NOT NULL,
        amount REAL NOT NULL DEFAULT 0,
        action_type TEXT NOT NULL DEFAULT 'SET', -- 'EXACT', 'SET', 'ADD'
        color TEXT DEFAULT 'indigo',
        sort_order INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 26. Customer Receivables (Buku Pembantu Piutang Usaha Pelanggan / Accounts Receivable)
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_receivables (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        invoice_number TEXT NOT NULL,
        customer_id INTEGER NOT NULL,
        branch_id INTEGER NOT NULL,
        total_amount REAL NOT NULL,
        paid_amount REAL NOT NULL DEFAULT 0,
        remaining_amount REAL NOT NULL,
        due_date DATE,
        status TEXT NOT NULL DEFAULT 'BELUM_LUNAS', -- 'BELUM_LUNAS', 'SEBAGIAN', 'LUNAS'
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
        FOREIGN KEY (customer_id) REFERENCES customers(id),
        FOREIGN KEY (branch_id) REFERENCES branches(id)
    )");

    // 27. Receivable Payment Logs (Catatan Histori Pembayaran Cicilan / Pelunasan Piutang)
    $pdo->exec("CREATE TABLE IF NOT EXISTS receivable_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        receivable_id INTEGER NOT NULL,
        payment_date DATE NOT NULL,
        amount REAL NOT NULL,
        payment_method TEXT NOT NULL DEFAULT 'CASH',
        reference_no TEXT,
        cashier_user_id INTEGER,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (receivable_id) REFERENCES customer_receivables(id) ON DELETE CASCADE,
        FOREIGN KEY (cashier_user_id) REFERENCES users(id)
    )");

    // 28. Product Types (Master Jenis Produk CRUD)
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_types (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        prefix TEXT NOT NULL,
        is_stockable INTEGER DEFAULT 1,
        is_saleable INTEGER DEFAULT 1,
        icon_emoji TEXT DEFAULT '📦',
        description TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 29. Product Units (Master Satuan Produk CRUD)
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_units (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        description TEXT,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 30. App Settings (Konfigurasi Terpusat: Logo, Nama, Printer, Struk, Slip Gaji, Notifikasi WA/Telegram)
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key TEXT UNIQUE NOT NULL,
        setting_value TEXT,
        setting_group TEXT DEFAULT 'GENERAL', -- 'GENERAL', 'PRINTER', 'RECEIPT', 'PAYSLIP', 'NOTIFICATION'
        description TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 31. Role Menu Permissions (Hak Akses Menu per Role Login)
    $pdo->exec("CREATE TABLE IF NOT EXISTS role_menu_permissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        role TEXT NOT NULL,
        menu_key TEXT NOT NULL,
        menu_title TEXT NOT NULL,
        can_access INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(role, menu_key)
    )");
}

/**
 * Robust Migration: Auto-detects and adds missing columns/tables on existing databases
 */
function autoMigrateAndSync($pdo) {
    // 1. Ensure `users` columns exist
    $userCols = getDbTableColumns($pdo, 'users');
    try {
        if (!in_array('password', $userCols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN password TEXT DEFAULT 'password123'");
        }
    } catch (Exception $e) {}
    try {
        if (!in_array('is_active', $userCols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_active INTEGER DEFAULT 1");
        }
    } catch (Exception $e) {}
    try {
        if (!in_array('pin', $userCols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN pin TEXT DEFAULT '123456'");
        }
    } catch (Exception $e) {}
    try {
        if (!in_array('base_salary', $userCols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN base_salary REAL DEFAULT 3000000");
        }
    } catch (Exception $e) {}
    try {
        if (!in_array('allowance', $userCols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN allowance REAL DEFAULT 500000");
        }
    } catch (Exception $e) {}
    try {
        if (!in_array('daily_transport', $userCols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN daily_transport REAL DEFAULT 25000");
        }
    } catch (Exception $e) {}
    try {
        if (!in_array('default_work_shift_id', $userCols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN default_work_shift_id INT DEFAULT NULL");
        }
    } catch (Exception $e) {}
    try {
        if (!in_array('assigned_vehicle_id', $userCols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN assigned_vehicle_id INT DEFAULT NULL");
        }
    } catch (Exception $e) {}

    // Inisialisasi kredensial default & branch awal HANYA jika belum diset (mencegah penimpaan perubahan user)
    $pdo->exec("UPDATE users SET password = 'admin123' WHERE nik = 'ADM001' AND (password IS NULL OR password = '')");
    $pdo->exec("UPDATE users SET pin = '999999' WHERE nik = 'ADM001' AND (pin IS NULL OR pin = '')");

    $pdo->exec("UPDATE users SET password = 'manager123' WHERE nik = 'MGR001' AND (password IS NULL OR password = '')");
    $pdo->exec("UPDATE users SET pin = '888888' WHERE nik = 'MGR001' AND (pin IS NULL OR pin = '')");
    $pdo->exec("UPDATE users SET branch_id = 1 WHERE nik = 'MGR001' AND (branch_id IS NULL OR branch_id = 0)");

    $pdo->exec("UPDATE users SET password = 'kasir123' WHERE nik = 'CSR001' AND (password IS NULL OR password = '')");
    $pdo->exec("UPDATE users SET pin = '123456' WHERE nik = 'CSR001' AND (pin IS NULL OR pin = '')");
    $pdo->exec("UPDATE users SET branch_id = 1 WHERE nik = 'CSR001' AND (branch_id IS NULL OR branch_id = 0)");
    $pdo->exec("UPDATE users SET assigned_vehicle_id = 1 WHERE nik = 'CSR001' AND (assigned_vehicle_id IS NULL OR assigned_vehicle_id = 0)"); // Bima -> B 4821 KTZ

    $pdo->exec("UPDATE users SET password = 'kasir123' WHERE nik = 'CSR002' AND (password IS NULL OR password = '')");
    $pdo->exec("UPDATE users SET pin = '654321' WHERE nik = 'CSR002' AND (pin IS NULL OR pin = '')");
    $pdo->exec("UPDATE users SET branch_id = 2 WHERE nik = 'CSR002' AND (branch_id IS NULL OR branch_id = 0)");
    $pdo->exec("UPDATE users SET assigned_vehicle_id = 3 WHERE nik = 'CSR002' AND (assigned_vehicle_id IS NULL OR assigned_vehicle_id = 0)"); // Abdul Latif -> L 5520 YZ

    $pdo->exec("UPDATE users SET password = 'kasir123' WHERE nik = 'CSR003' AND (password IS NULL OR password = '')");
    $pdo->exec("UPDATE users SET pin = '112233' WHERE nik = 'CSR003' AND (pin IS NULL OR pin = '')");
    $pdo->exec("UPDATE users SET branch_id = 3 WHERE nik = 'CSR003' AND (branch_id IS NULL OR branch_id = 0)");
    $pdo->exec("UPDATE users SET assigned_vehicle_id = 4 WHERE nik = 'CSR003' AND (assigned_vehicle_id IS NULL OR assigned_vehicle_id = 0)"); // Shafiqa -> D 2914 ABJ

    $pdo->exec("UPDATE users SET password = 'staff123' WHERE nik = 'STF001' AND (password IS NULL OR password = '')");
    $pdo->exec("UPDATE users SET pin = '000000' WHERE nik = 'STF001' AND (pin IS NULL OR pin = '')");
    $pdo->exec("UPDATE users SET branch_id = 1 WHERE nik = 'STF001' AND (branch_id IS NULL OR branch_id = 0)");
    $pdo->exec("UPDATE users SET assigned_vehicle_id = 2 WHERE nik = 'STF001' AND (assigned_vehicle_id IS NULL OR assigned_vehicle_id = 0)"); // Keenan -> B 3192 SEB

    // 2. Ensure `products` columns exist
    $prodCols = array_column($pdo->query("PRAGMA table_info(products)")->fetchAll(), 'name');
    if (!in_array('product_type', $prodCols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN product_type TEXT DEFAULT 'FINISHED_GOODS'");
    }
    if (!in_array('has_bom', $prodCols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN has_bom INTEGER DEFAULT 0");
    }
    if (!in_array('category_id', $prodCols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN category_id INTEGER DEFAULT 1");
    }
    if (!in_array('reorder_point', $prodCols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN reorder_point INTEGER DEFAULT 10");
    }
    if (!in_array('is_active', $prodCols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN is_active INTEGER DEFAULT 1");
    }
    if (!in_array('is_delivery_required', $prodCols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN is_delivery_required INTEGER DEFAULT 0");
    }
    if (!in_array('is_delivery_included', $prodCols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN is_delivery_included INTEGER DEFAULT 0");
    }

    // Default: Air Mineral Galon 19 Liter dikenakan ongkos kirim wajib antar
    $pdo->exec("UPDATE products SET is_delivery_required = 1 WHERE sku = 'PRD-GLN-19L'");

    // 3. Ensure Categories exist
    $catCount = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if ($catCount == 0) {
        $pdo->exec("INSERT INTO categories (id, code, name, icon_emoji) VALUES
            (1, 'CAT-BEV', 'Minuman (Beverage)', '☕'),
            (2, 'CAT-BAK', 'Bakery & Pastry', '🥐'),
            (3, 'CAT-FOD', 'Makanan Utama', '🥪'),
            (4, 'CAT-RAW', 'Bahan Baku (Raw Material)', '🌾'),
            (5, 'CAT-MER', 'Merchandise', '🍶')
        ");
    }

    // 4. Ensure Raw Materials exist in products
    $rawCount = $pdo->query("SELECT COUNT(*) FROM products WHERE product_type = 'RAW_MATERIAL'")->fetchColumn();
    if ($rawCount == 0) {
        $rawMaterials = [
            ['ING-001', '8992001', 'Biji Kopi Arabika House Blend', 4, 'Bahan Baku (Raw Material)', 'RAW_MATERIAL', 'gram', 180, 0, '🫘', 0, 500],
            ['ING-002', '8992002', 'Susu Fresh Milk UHT', 4, 'Bahan Baku (Raw Material)', 'RAW_MATERIAL', 'ml', 22, 0, '🥛', 0, 1000],
            ['ING-003', '8992003', 'Sirup Karamel Premium', 4, 'Bahan Baku (Raw Material)', 'RAW_MATERIAL', 'ml', 75, 0, '🍯', 0, 500],
            ['ING-004', '8992004', 'Paper Cup 12oz & Lid', 4, 'Bahan Baku (Raw Material)', 'RAW_MATERIAL', 'pcs', 1200, 0, '🥤', 0, 100],
            ['ING-005', '8992005', 'Daging Smoked Beef Slice', 4, 'Bahan Baku (Raw Material)', 'RAW_MATERIAL', 'pcs', 4500, 0, '🥓', 0, 50],
            ['ING-006', '8992006', 'Keju Cheddar Slice', 4, 'Bahan Baku (Raw Material)', 'RAW_MATERIAL', 'pcs', 2500, 0, '🧀', 0, 50],
            ['ING-007', '8992007', 'Roti Tawar Gandum', 4, 'Bahan Baku (Raw Material)', 'RAW_MATERIAL', 'slice', 1500, 0, '🍞', 0, 100]
        ];
        $stmtRaw = $pdo->prepare("INSERT OR IGNORE INTO products (sku, barcode, name, category_id, category, product_type, unit, cogs, selling_price, image_emoji, has_bom, reorder_point) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($rawMaterials as $rm) {
            $stmtRaw->execute($rm);
        }

        // Add stocks for raw materials
        $branches = [1, 2, 3];
        $rawProds = $pdo->query("SELECT id, cogs, unit FROM products WHERE product_type = 'RAW_MATERIAL'")->fetchAll();
        $stmtStockRaw = $pdo->prepare("INSERT OR IGNORE INTO branch_stocks (branch_id, product_id, stock, local_cogs, local_price) VALUES (?, ?, ?, ?, 0)");
        foreach ($branches as $bId) {
            foreach ($rawProds as $rp) {
                $initStock = ($rp['unit'] === 'gram' || $rp['unit'] === 'ml') ? 10000 : 200;
                $stmtStockRaw->execute([$bId, $rp['id'], $initStock, $rp['cogs']]);
            }
        }
    }

    // 5. Ensure BOM Recipe exists
    $recipeCount = $pdo->query("SELECT COUNT(*) FROM product_recipes")->fetchColumn();
    if ($recipeCount == 0) {
        $p1 = $pdo->query("SELECT id FROM products WHERE sku = 'PRD-001'")->fetchColumn();
        $ing1 = $pdo->query("SELECT id FROM products WHERE sku = 'ING-001'")->fetchColumn();
        $ing2 = $pdo->query("SELECT id FROM products WHERE sku = 'ING-002'")->fetchColumn();
        $ing3 = $pdo->query("SELECT id FROM products WHERE sku = 'ING-003'")->fetchColumn();
        $ing4 = $pdo->query("SELECT id FROM products WHERE sku = 'ING-004'")->fetchColumn();
        if ($p1 && $ing1 && $ing2 && $ing3 && $ing4) {
            $pdo->exec("INSERT INTO product_recipes (finished_product_id, ingredient_product_id, qty_needed, unit) VALUES
                ($p1, $ing1, 20, 'gram'),
                ($p1, $ing2, 150, 'ml'),
                ($p1, $ing3, 20, 'ml'),
                ($p1, $ing4, 1, 'pcs')
            ");
            $pdo->exec("UPDATE products SET has_bom = 1 WHERE id = $p1");
        }
    }

    // 6. Ensure Variants exist
    $varCount = $pdo->query("SELECT COUNT(*) FROM product_variants")->fetchColumn();
    if ($varCount == 0) {
        $p1 = $pdo->query("SELECT id FROM products WHERE sku = 'PRD-001'")->fetchColumn();
        if ($p1) {
            $pdo->exec("INSERT INTO product_variants (product_id, variant_name, sku_variant, additional_price, additional_cogs) VALUES
                ($p1, 'Regular (12oz)', 'PRD-001-REG', 0, 0),
                ($p1, 'Large (16oz)', 'PRD-001-LRG', 8000, 2500)
            ");
        }
    }

    // 7. Ensure Price Tiers on `products` (Member, Reseller, Mitra)
    $prodCols = array_column($pdo->query("PRAGMA table_info(products)")->fetchAll(), 'name');
    if (!in_array('member_price', $prodCols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN member_price REAL DEFAULT 0");
    }
    if (!in_array('reseller_price', $prodCols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN reseller_price REAL DEFAULT 0");
    }
    if (!in_array('mitra_price', $prodCols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN mitra_price REAL DEFAULT 0");
    }
    if (!in_array('visible_categories', $prodCols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN visible_categories TEXT DEFAULT 'ALL'");
    }

    // Populate default tier prices if 0
    $pdo->exec("
        UPDATE products 
        SET member_price = CASE WHEN member_price = 0 THEN ROUND(selling_price * 0.9, -2) ELSE member_price END,
            reseller_price = CASE WHEN reseller_price = 0 THEN ROUND(selling_price * 0.8, -2) ELSE reseller_price END,
            mitra_price = CASE WHEN mitra_price = 0 THEN ROUND(selling_price * 0.72, -2) ELSE mitra_price END
        WHERE product_type = 'FINISHED_GOODS'
    ");

    // 8. Ensure Customer columns on `sales`
    $saleCols = array_column($pdo->query("PRAGMA table_info(sales)")->fetchAll(), 'name');
    if (!in_array('customer_id', $saleCols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN customer_id INTEGER DEFAULT NULL");
    }
    if (!in_array('customer_name', $saleCols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN customer_name TEXT DEFAULT 'Pelanggan Umum'");
    }
    if (!in_array('customer_category', $saleCols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN customer_category TEXT DEFAULT 'REGULER'");
    }
    if (!in_array('sales_type', $saleCols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN sales_type TEXT DEFAULT 'RETAIL'");
    }

    // 9. Ensure Customers Seeded
    $custCount = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    if ($custCount == 0) {
        $pdo->exec("INSERT INTO customers (id, code, name, phone, email, address, category, discount_percent, branch_id, notes, is_active) VALUES
            (1, 'CUST-001', 'Pelanggan Umum (Walk-in)', '-', '-', 'Area Toko', 'REGULER', 0, NULL, 'Pelanggan ritel harian umum', 1),
            (2, 'CUST-002', 'Anisa Maharani (VIP Member)', '0812-9988-7766', 'anisa@member.com', 'Jl. Rasuna Said No. 12, Jakarta', 'MEMBER', 10, 1, 'Member Terdaftar Gold Tier', 1),
            (3, 'CUST-003', 'Toko Berkah Coffee (Reseller)', '0813-1122-3344', 'berkah@reseller.id', 'Jl. Tebet Barat Dalam No. 45', 'RESELLER', 20, 1, 'Reseller paket seduh & bakery', 1),
            (4, 'CUST-004', 'PT Mitra Bahtera Abadi (Mitra A)', '0815-5566-7788', 'procurement@bahtera.co.id', 'Gedung Wisma Niaga Lt. 8, Jakarta', 'MITRA', 28, 1, 'Mitra Korporat A - Kontrak Suplai Bulanan', 1),
            (5, 'CUST-005', 'CV Mitra Nusantara Sejahtera (Mitra B)', '0816-6677-8899', 'admin@nusantara-partner.com', 'Komplek Pergudangan Margomulyo B-12, Surabaya', 'MITRA', 32, 2, 'Mitra Grosir B - Volume Tinggi Harga Spesial', 1)
        ");
    }

    // 10. Multi-Branch Price Differentiation (Setiap branch harga berbeda)
    // Jakarta (id=1): Standard Mall Price
    // Surabaya (id=2): Competitive Regional Price (Lebih hemat)
    // Bandung (id=3): Dago Lifestyle Price
    $p1 = $pdo->query("SELECT id FROM products WHERE sku = 'PRD-001'")->fetchColumn(); // Caramel Macchiato
    $p2 = $pdo->query("SELECT id FROM products WHERE sku = 'PRD-002'")->fetchColumn(); // Croissant Butter
    $p3 = $pdo->query("SELECT id FROM products WHERE sku = 'PRD-003'")->fetchColumn(); // Beef Sandwich
    $p4 = $pdo->query("SELECT id FROM products WHERE sku = 'PRD-004'")->fetchColumn(); // Espresso Blend
    $p5 = $pdo->query("SELECT id FROM products WHERE sku = 'PRD-005'")->fetchColumn(); // Matcha Latte
    $p6 = $pdo->query("SELECT id FROM products WHERE sku = 'PRD-006'")->fetchColumn(); // Choco Muffin

    // Only seed initial branch differentiated prices if not set yet (local_price = 0)
    $hasUnsetBranchPrices = $pdo->query("SELECT COUNT(*) FROM branch_stocks WHERE local_price = 0 AND branch_id IN (1,2,3)")->fetchColumn();
    if ($hasUnsetBranchPrices > 0 && $p1 && $p2 && $p3) {
        // Cabang 1: Jakarta Mall
        $pdo->exec("UPDATE branch_stocks SET local_price = 35000 WHERE branch_id = 1 AND product_id = $p1 AND (local_price = 0 OR local_price IS NULL)");
        $pdo->exec("UPDATE branch_stocks SET local_price = 24000 WHERE branch_id = 1 AND product_id = $p2 AND (local_price = 0 OR local_price IS NULL)");
        $pdo->exec("UPDATE branch_stocks SET local_price = 38000 WHERE branch_id = 1 AND product_id = $p3 AND (local_price = 0 OR local_price IS NULL)");
        if ($p4) $pdo->exec("UPDATE branch_stocks SET local_price = 28000 WHERE branch_id = 1 AND product_id = $p4 AND (local_price = 0 OR local_price IS NULL)");
        if ($p5) $pdo->exec("UPDATE branch_stocks SET local_price = 32000 WHERE branch_id = 1 AND product_id = $p5 AND (local_price = 0 OR local_price IS NULL)");
        if ($p6) $pdo->exec("UPDATE branch_stocks SET local_price = 22000 WHERE branch_id = 1 AND product_id = $p6 AND (local_price = 0 OR local_price IS NULL)");

        // Cabang 2: Surabaya Ruko (Harga berbeda / lebih bersaing)
        $pdo->exec("UPDATE branch_stocks SET local_price = 32000 WHERE branch_id = 2 AND product_id = $p1 AND (local_price = 0 OR local_price IS NULL)");
        $pdo->exec("UPDATE branch_stocks SET local_price = 22000 WHERE branch_id = 2 AND product_id = $p2 AND (local_price = 0 OR local_price IS NULL)");
        $pdo->exec("UPDATE branch_stocks SET local_price = 35000 WHERE branch_id = 2 AND product_id = $p3 AND (local_price = 0 OR local_price IS NULL)");
        if ($p4) $pdo->exec("UPDATE branch_stocks SET local_price = 25000 WHERE branch_id = 2 AND product_id = $p4 AND (local_price = 0 OR local_price IS NULL)");
        if ($p5) $pdo->exec("UPDATE branch_stocks SET local_price = 29000 WHERE branch_id = 2 AND product_id = $p5 AND (local_price = 0 OR local_price IS NULL)");
        if ($p6) $pdo->exec("UPDATE branch_stocks SET local_price = 20000 WHERE branch_id = 2 AND product_id = $p6 AND (local_price = 0 OR local_price IS NULL)");

        // Cabang 3: Bandung Dago (Harga berbeda)
        $pdo->exec("UPDATE branch_stocks SET local_price = 34000 WHERE branch_id = 3 AND product_id = $p1 AND (local_price = 0 OR local_price IS NULL)");
        $pdo->exec("UPDATE branch_stocks SET local_price = 23000 WHERE branch_id = 3 AND product_id = $p2 AND (local_price = 0 OR local_price IS NULL)");
        $pdo->exec("UPDATE branch_stocks SET local_price = 36500 WHERE branch_id = 3 AND product_id = $p3 AND (local_price = 0 OR local_price IS NULL)");
        if ($p4) $pdo->exec("UPDATE branch_stocks SET local_price = 27000 WHERE branch_id = 3 AND product_id = $p4 AND (local_price = 0 OR local_price IS NULL)");
        if ($p5) $pdo->exec("UPDATE branch_stocks SET local_price = 31000 WHERE branch_id = 3 AND product_id = $p5 AND (local_price = 0 OR local_price IS NULL)");
        if ($p6) $pdo->exec("UPDATE branch_stocks SET local_price = 21000 WHERE branch_id = 3 AND product_id = $p6 AND (local_price = 0 OR local_price IS NULL)");
    }

    // 11. Ensure Mitra Custom Contract Prices Seeded (Setiap mitra harga berbeda)
    $mitraPriceCount = $pdo->query("SELECT COUNT(*) FROM mitra_custom_prices")->fetchColumn();
    if ($mitraPriceCount == 0 && $p1 && $p2 && $p3) {
        // Mitra A (Customer id=4, PT Mitra Bahtera Abadi)
        $pdo->exec("INSERT OR REPLACE INTO mitra_custom_prices (customer_id, product_id, custom_price, notes) VALUES
            (4, $p1, 25000, 'Harga Kontrak Suplai Khusus Mitra A'),
            (4, $p2, 17500, 'Harga Kontrak Suplai Khusus Mitra A'),
            (4, $p3, 27000, 'Harga Kontrak Suplai Khusus Mitra A')
        ");
        // Mitra B (Customer id=5, CV Mitra Nusantara Sejahtera - Harga berbeda lebih murah karena volume tinggi)
        $pdo->exec("INSERT OR REPLACE INTO mitra_custom_prices (customer_id, product_id, custom_price, notes) VALUES
            (5, $p1, 23500, 'Harga Kontrak Tier Grosir Tinggi Mitra B'),
            (5, $p2, 16000, 'Harga Kontrak Tier Grosir Tinggi Mitra B'),
            (5, $p3, 25500, 'Harga Kontrak Tier Grosir Tinggi Mitra B')
        ");
    }

    // 12. Ensure Customer Coordinates (Latitude / Longitude Maps)
    $custCols = array_column($pdo->query("PRAGMA table_info(customers)")->fetchAll(), 'name');
    if (!in_array('latitude', $custCols)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN latitude REAL DEFAULT NULL");
    }
    if (!in_array('longitude', $custCols)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN longitude REAL DEFAULT NULL");
    }

    // Ensure branch_stocks has last_updated column
    $bsCols = array_column($pdo->query("PRAGMA table_info(branch_stocks)")->fetchAll(), 'name');
    if (!in_array('last_updated', $bsCols)) {
        $pdo->exec("ALTER TABLE branch_stocks ADD COLUMN last_updated DATETIME DEFAULT NULL");
    }

    // Ensure branches table has is_active column
    $branchCols = array_column($pdo->query("PRAGMA table_info(branches)")->fetchAll(), 'name');
    if (!in_array('is_active', $branchCols)) {
        $pdo->exec("ALTER TABLE branches ADD COLUMN is_active INTEGER DEFAULT 1");
    }

    // Ensure courier_vehicles has driver_user_id column (Relasi ke data karyawan cabang)
    $vehCols = array_column($pdo->query("PRAGMA table_info(courier_vehicles)")->fetchAll(), 'name');
    if (!in_array('driver_user_id', $vehCols)) {
        $pdo->exec("ALTER TABLE courier_vehicles ADD COLUMN driver_user_id INTEGER DEFAULT NULL");
    }

    // Sinkronkan penugasan kendaraan ke staf / kasir cabang agar otomatis matched saat kasir login
    $pdo->exec("UPDATE courier_vehicles SET driver_user_id = 3, driver_name = 'Bima (Kasir)' WHERE plate_number = 'B 4821 KTZ' AND (driver_user_id IS NULL OR driver_user_id = 0)");
    $pdo->exec("UPDATE courier_vehicles SET driver_user_id = 6, driver_name = 'Keenan (Staff)' WHERE plate_number = 'B 3192 SEB' AND (driver_user_id IS NULL OR driver_user_id = 0)");
    $pdo->exec("UPDATE courier_vehicles SET driver_user_id = 4, driver_name = 'Abdul Latif (Kasir)' WHERE plate_number = 'L 5520 YZ' AND (driver_user_id IS NULL OR driver_user_id = 0)");
    $pdo->exec("UPDATE courier_vehicles SET driver_user_id = 5, driver_name = 'Shafiqa (Kasir)' WHERE plate_number = 'D 2914 ABJ' AND (driver_user_id IS NULL OR driver_user_id = 0)");

    // 13. Ensure Sales Delivery Columns
    $saleCols = array_column($pdo->query("PRAGMA table_info(sales)")->fetchAll(), 'name');
    if (!in_array('delivery_fee', $saleCols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN delivery_fee REAL DEFAULT 0");
    }
    if (!in_array('delivery_distance_km', $saleCols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN delivery_distance_km REAL DEFAULT 0");
    }
    if (!in_array('courier_vehicle_id', $saleCols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN courier_vehicle_id INTEGER DEFAULT NULL");
    }
    if (!in_array('delivery_status', $saleCols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN delivery_status TEXT DEFAULT 'NONE'");
    }
    if (!in_array('driver_user_id', $saleCols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN driver_user_id INTEGER DEFAULT NULL");
    }
    if (!in_array('driver_name', $saleCols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN driver_name TEXT DEFAULT NULL");
    }
    if (!in_array('is_delivery_included', $saleCols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN is_delivery_included INTEGER DEFAULT 0");
    }

    // 14. Synchronize Precise Maps Coordinates for Outlets & Customers
    $pdo->exec("UPDATE branches SET latitude = -6.1950, longitude = 106.8230 WHERE id = 1"); // Grand Indonesia Jakarta
    $pdo->exec("UPDATE branches SET latitude = -7.2618, longitude = 112.7383 WHERE id = 2"); // Tunjungan Plaza Surabaya
    $pdo->exec("UPDATE branches SET latitude = -6.8893, longitude = 107.5960 WHERE id = 3"); // Paris Van Java Bandung

    $pdo->exec("UPDATE customers SET latitude = -6.1955, longitude = 106.8235 WHERE id = 1 AND (latitude IS NULL OR latitude = 0)"); // Walk-in (dekat toko)
    $pdo->exec("UPDATE customers SET latitude = -6.2155, longitude = 106.8315 WHERE id = 2 AND (latitude IS NULL OR latitude = 0)"); // Anisa (Rasuna Said ~2.5 km)
    $pdo->exec("UPDATE customers SET latitude = -6.2380, longitude = 106.8520 WHERE id = 3 AND (latitude IS NULL OR latitude = 0)"); // Toko Berkah Tebet (~5.8 km)
    $pdo->exec("UPDATE customers SET latitude = -6.2088, longitude = 106.8190 WHERE id = 4 AND (latitude IS NULL OR latitude = 0)"); // Mitra A Wisma Niaga (~1.6 km)
    $pdo->exec("UPDATE customers SET latitude = -7.2410, longitude = 112.6780 WHERE id = 5 AND (latitude IS NULL OR latitude = 0)"); // Mitra B Surabaya (~7.2 km)

    // 15. Ensure Dynamic Delivery Settings Seeded per Branch
    $delivCount = $pdo->query("SELECT COUNT(*) FROM delivery_settings")->fetchColumn();
    if ($delivCount == 0) {
        $pdo->exec("INSERT INTO delivery_settings (branch_id, base_fee, base_distance_km, per_km_rate, free_delivery_threshold, max_distance_km) VALUES
            (1, 12000, 3.0, 3000, 200000, 35.0),
            (2, 9000, 3.0, 2200, 150000, 35.0),
            (3, 10000, 3.0, 2500, 150000, 35.0)
        ");
    }

    // 16. Ensure Courier Vehicles Seeded (Rasio bensin bisa diatur per motor)
    $vehCount = $pdo->query("SELECT COUNT(*) FROM courier_vehicles")->fetchColumn();
    if ($vehCount == 0) {
        $pdo->exec("INSERT INTO courier_vehicles (branch_id, plate_number, vehicle_name, vehicle_type, driver_name, driver_user_id, current_odometer, fuel_ratio, fuel_ratio_unit, fuel_type, status, notes) VALUES
            (1, 'B 4821 KTZ', 'Honda Vario 125cc ESP', 'MOTOR', 'Bima (Kasir)', 3, 14250, 38.0, 'KM_PER_LITER', 'Pertalite', 'AVAILABLE', 'Kendaraan kurir utama area Jakpus & Jaksel. 1 Liter = 38 KM'),
            (1, 'B 3192 SEB', 'Honda Beat FI 110cc', 'MOTOR', 'Keenan (Staff)', 6, 21800, 45.0, 'KM_PER_LITER', 'Pertalite', 'AVAILABLE', 'Kendaraan kurir cadangan hemat bensin. 1 Liter = 45 KM'),
            (2, 'L 5520 YZ', 'Yamaha Mio M3 125', 'MOTOR', 'Abdul Latif (Kasir)', 4, 18400, 40.0, 'KM_PER_LITER', 'Pertalite', 'AVAILABLE', 'Armada kurir express wilayah Surabaya Pusat. 1 Liter = 40 KM'),
            (3, 'D 2914 ABJ', 'Honda Scoopy 110cc', 'MOTOR', 'Shafiqa (Kasir)', 5, 12100, 42.0, 'KM_PER_LITER', 'Pertalite', 'AVAILABLE', 'Armada kurir cabang Dago Bandung. 1 Liter = 42 KM')
        ");
    }

    // Sinkronkan penugasan kendaraan ke staf / kasir cabang HANYA jika belum ditugaskan
    $pdo->exec("UPDATE courier_vehicles SET branch_id = 1, driver_user_id = 3, driver_name = 'Bima (Kasir)' WHERE plate_number = 'B 4821 KTZ' AND (driver_user_id IS NULL OR driver_user_id = 0)");
    $pdo->exec("UPDATE courier_vehicles SET branch_id = 1, driver_user_id = 6, driver_name = 'Keenan (Staff)' WHERE plate_number = 'B 3192 SEB' AND (driver_user_id IS NULL OR driver_user_id = 0)");
    $pdo->exec("UPDATE courier_vehicles SET branch_id = 2, driver_user_id = 4, driver_name = 'Abdul Latif (Kasir)' WHERE plate_number = 'L 5520 YZ' AND (driver_user_id IS NULL OR driver_user_id = 0)");
    $pdo->exec("UPDATE courier_vehicles SET branch_id = 3, driver_user_id = 5, driver_name = 'Shafiqa (Kasir)' WHERE plate_number = 'D 2914 ABJ' AND (driver_user_id IS NULL OR driver_user_id = 0)");

    // 17. Ensure Sample Fuel Purchase Log Seeded (Terkoneksi ke Beban Laba Rugi)
    $fuelLogCount = $pdo->query("SELECT COUNT(*) FROM vehicle_fuel_logs")->fetchColumn();
    if ($fuelLogCount == 0) {
        $v1 = $pdo->query("SELECT id FROM courier_vehicles WHERE plate_number = 'B 4821 KTZ'")->fetchColumn();
        if ($v1) {
            $fuelDate = date('Y-m-d', strtotime('-2 days'));
            $stmtExp = $pdo->prepare("INSERT INTO branch_expenses (branch_id, category, amount, expense_date, source, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtExp->execute([1, 'Beban Bahan Bakar (BBM) Kurir', 35000, $fuelDate, 'VEHICLE_FUEL', 'Isi BBM Pertalite 3.5L (KM 14.120 s/d 14.250) - B 4821 KTZ']);
            $expId = $pdo->lastInsertId();

            $pdo->exec("INSERT INTO vehicle_fuel_logs (vehicle_id, branch_id, log_date, last_odometer, current_odometer, distance_traveled, liters_bought, cost_per_liter, total_cost, configured_ratio, expected_liters, efficiency_percent, fuel_type, driver_name, expense_record_id, notes) VALUES
                ($v1, 1, '$fuelDate', 14120, 14250, 130, 3.5, 10000, 35000, 38.0, 3.42, 97.7, 'Pertalite', 'Doni Prasetyo', $expId, 'Pengisian rutin SPBU Pertamina Rasuna Said (130 KM / 38 KM/L = 3.42 Liter teoretis)')
            ");
        }
    }

    // 18. Ensure Sample Vehicle Service Log Seeded (Terkoneksi ke Beban Laba Rugi)
    $serviceLogCount = $pdo->query("SELECT COUNT(*) FROM vehicle_service_logs")->fetchColumn();
    if ($serviceLogCount == 0) {
        $v1 = $pdo->query("SELECT id FROM courier_vehicles WHERE plate_number = 'B 4821 KTZ'")->fetchColumn();
        if ($v1) {
            $servDate = date('Y-m-d', strtotime('-5 days'));
            $stmtExp = $pdo->prepare("INSERT INTO branch_expenses (branch_id, category, amount, expense_date, source, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtExp->execute([1, 'Beban Servis & Perawatan Armada Kurir', 125000, $servDate, 'VEHICLE_SERVICE', 'Servis Berkala & Ganti Oli MPX2 di AHASS Menteng - B 4821 KTZ']);
            $expId = $pdo->lastInsertId();

            $pdo->exec("INSERT INTO vehicle_service_logs (vehicle_id, branch_id, service_date, odometer_km, service_type, workshop_name, cost, expense_record_id, notes) VALUES
                ($v1, 1, '$servDate', 14000, 'Ganti Oli Mesin SPX & Gardan + Tune Up Rutin', 'AHASS Honda Menteng Motor', 125000, $expId, 'Servis rutin 14.000 KM kondisi mesin prima, kampas rem depan baru')
            ");
        }
    }

    // 19. Ensure `variant_recipes` Table Exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS variant_recipes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        variant_id INTEGER NOT NULL,
        ingredient_product_id INTEGER NOT NULL,
        qty_needed REAL NOT NULL DEFAULT 1,
        unit TEXT NOT NULL DEFAULT 'pcs',
        notes TEXT,
        FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,
        FOREIGN KEY (ingredient_product_id) REFERENCES products(id)
    )");

    // 20. Ensure `sale_items.variant_id` and `sale_items.variant_name` exist
    $saleItemCols = array_column($pdo->query("PRAGMA table_info(sale_items)")->fetchAll(), 'name');
    if (!in_array('variant_id', $saleItemCols)) {
        $pdo->exec("ALTER TABLE sale_items ADD COLUMN variant_id INTEGER DEFAULT NULL");
    }
    if (!in_array('variant_name', $saleItemCols)) {
        $pdo->exec("ALTER TABLE sale_items ADD COLUMN variant_name TEXT DEFAULT NULL");
    }

    // 21. Seeding Kasus Nyata: Dihapus sesuai permintaan user (PRD-GLN-19L)
    // Bagian re-seeding dinonaktifkan agar data produk yang dihapus tidak muncul kembali saat migrasi.

    // 22. Ensure Taxes Table Exists & Seed Default Tax Rates
    $pdo->exec("CREATE TABLE IF NOT EXISTS taxes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        rate_percent REAL NOT NULL,
        description TEXT,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $taxCount = $pdo->query("SELECT COUNT(*) FROM taxes")->fetchColumn();
    if ($taxCount == 0) {
        $pdo->exec("INSERT INTO taxes (id, code, name, rate_percent, description, is_active) VALUES
            (1, 'PPN11', 'PPN 11%', 11.0, 'Pajak Pertambahan Nilai Standar Indonesia 11%', 1),
            (2, 'PB1-10', 'PB1 Resto 10%', 10.0, 'Pajak Daerah / Restoran dan Hiburan 10%', 1),
            (3, 'TAX-0', 'Bebas Pajak (0%)', 0.0, 'Barang Bebas Pajak / Non-BKP', 1)
        ");
    }

    // 23. Ensure Product Tax Columns Exist (Fitur Tax Per Item Produk)
    $prodCols = array_column($pdo->query("PRAGMA table_info(products)")->fetchAll(), 'name');
    if (!in_array('is_taxable', $prodCols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN is_taxable INTEGER DEFAULT 1");
    }
    if (!in_array('tax_id', $prodCols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN tax_id INTEGER DEFAULT 1");
    }
    if (!in_array('tax_percent', $prodCols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN tax_percent REAL DEFAULT 11.0");
    }

    // 24. Ensure Payment Methods Table Exists & Seed Default Payment Methods
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_methods (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        category TEXT DEFAULT 'OTHER',
        account_number TEXT,
        account_holder TEXT,
        description TEXT,
        is_cash INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        sort_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pmCount = $pdo->query("SELECT COUNT(*) FROM payment_methods")->fetchColumn();
    if ($pmCount == 0) {
        $pdo->exec("INSERT INTO payment_methods (id, code, name, category, account_number, account_holder, description, is_cash, is_active, sort_order) VALUES
            (1, 'CASH', 'Tunai (Cash)', 'CASH', '-', 'Kasir Toko', 'Pembayaran tunai uang kertas dan koin', 1, 1, 1),
            (2, 'QRIS', 'QRIS Dinamis', 'E-WALLET', 'NMID-9382103810', 'ESBAS RETAIL GROUP', 'Scan QRIS GoPay, OVO, Dana, ShopeePay, BCA', 0, 1, 2),
            (3, 'DEBIT', 'Kartu Debit (EDC)', 'CARD', 'EDC-BCA-01', 'Bank Central Asia', 'Kartu debit EDC GPN / Mastercard / Visa', 0, 1, 3),
            (4, 'TRANSFER', 'Transfer Bank', 'BANK_TRANSFER', '8830192831', 'PT ESBAS MULTI RETAIL', 'Transfer via BCA, Mandiri, BRI, BNI', 0, 1, 4),
            (5, 'PIUTANG', 'Piutang Cust', 'OTHER', '-', 'Pelanggan / Mitra', 'Metode pembayaran tempo / piutang usaha pelanggan', 0, 1, 5)
        ");
    } else {
        // Ensure PIUTANG exists if payment_methods already had other rows
        $hasPiutang = $pdo->query("SELECT id FROM payment_methods WHERE code = 'PIUTANG'")->fetchColumn();
        if (!$hasPiutang) {
            $pdo->exec("INSERT INTO payment_methods (code, name, category, account_number, account_holder, description, is_cash, is_active, sort_order) VALUES
                ('PIUTANG', 'Piutang Cust', 'OTHER', '-', 'Pelanggan / Mitra', 'Metode pembayaran tempo / piutang usaha pelanggan', 0, 1, 5)
            ");
        }
    }

    // Ensure cash_denominations table exists & seed defaults
    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_denominations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        label TEXT NOT NULL,
        amount REAL NOT NULL DEFAULT 0,
        action_type TEXT NOT NULL DEFAULT 'SET',
        color TEXT DEFAULT 'indigo',
        sort_order INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $cdCount = $pdo->query("SELECT COUNT(*) FROM cash_denominations")->fetchColumn();
    if ($cdCount == 0) {
        $pdo->exec("INSERT INTO cash_denominations (id, label, amount, action_type, color, sort_order, is_active) VALUES
            (1, 'Uang Pas', 0, 'EXACT', 'indigo', 1, 1),
            (2, '10K', 10000, 'SET', 'indigo', 2, 1),
            (3, '20K', 20000, 'SET', 'indigo', 3, 1),
            (4, '50K', 50000, 'SET', 'indigo', 4, 1),
            (5, '100K', 100000, 'SET', 'indigo', 5, 1)
        ");
    }

    // 25. Ensure `sales.payment_status` & `sales.status` columns exist ('PAID' / 'UNPAID', 'COMPLETED' / 'CANCELLED')
    $saleCols = array_column($pdo->query("PRAGMA table_info(sales)")->fetchAll(), 'name');
    if (!in_array('payment_status', $saleCols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN payment_status TEXT DEFAULT 'PAID'");
        $pdo->exec("UPDATE sales SET payment_status = 'UNPAID' WHERE payment_method = 'PIUTANG'");
    }
    if (!in_array('status', $saleCols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN status TEXT DEFAULT 'COMPLETED'");
        $pdo->exec("UPDATE sales SET status = 'COMPLETED' WHERE status IS NULL OR status = ''");
    }
    if (!in_array('cancellation_reason', $saleCols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN cancellation_reason TEXT DEFAULT NULL");
    }
    if (!in_array('cancelled_by', $saleCols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN cancelled_by INTEGER DEFAULT NULL");
    }
    if (!in_array('cancelled_at', $saleCols)) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN cancelled_at DATETIME DEFAULT NULL");
    }

    // 26. Ensure `customer_receivables` & `receivable_payments` tables exist in existing DB
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_receivables (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        invoice_number TEXT NOT NULL,
        customer_id INTEGER NOT NULL,
        branch_id INTEGER NOT NULL,
        total_amount REAL NOT NULL,
        paid_amount REAL NOT NULL DEFAULT 0,
        remaining_amount REAL NOT NULL,
        due_date DATE,
        status TEXT NOT NULL DEFAULT 'BELUM_LUNAS', -- 'BELUM_LUNAS', 'SEBAGIAN', 'LUNAS'
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
        FOREIGN KEY (customer_id) REFERENCES customers(id),
        FOREIGN KEY (branch_id) REFERENCES branches(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS receivable_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        receivable_id INTEGER NOT NULL,
        payment_date DATE NOT NULL,
        amount REAL NOT NULL,
        payment_method TEXT NOT NULL DEFAULT 'CASH',
        reference_no TEXT,
        cashier_user_id INTEGER,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (receivable_id) REFERENCES customer_receivables(id) ON DELETE CASCADE,
        FOREIGN KEY (cashier_user_id) REFERENCES users(id)
    )");

    // 27. Seed sample receivables for Toko Berkah & PT Mitra Bahtera Abadi if table empty
    $receivableCount = $pdo->query("SELECT COUNT(*) FROM customer_receivables")->fetchColumn();
    if ($receivableCount == 0) {
        $custBerkah = $pdo->query("SELECT id FROM customers WHERE code = 'CUST-003'")->fetchColumn() ?: 3;
        $custMitraA = $pdo->query("SELECT id FROM customers WHERE code = 'CUST-004'")->fetchColumn() ?: 4;
        $userCashier = $pdo->query("SELECT id FROM users WHERE role = 'CASHIER'")->fetchColumn() ?: 3;

        // Sample 1: Toko Berkah (Reseller) - Rp 350.000 (Belum Lunas)
        $inv1 = 'INV/JKT/' . date('Ymd') . '/9001';
        $p1 = $pdo->query("SELECT id, name, cogs, selling_price FROM products WHERE sku = 'PRD-001'")->fetch();
        if ($p1) {
            $stmtSale1 = $pdo->prepare("INSERT INTO sales (invoice_number, branch_id, user_id, customer_id, customer_name, customer_category, sales_type, subtotal, discount, tax, grand_total, total_cogs, payment_method, payment_status, cash_received, cash_change, created_at) VALUES (?, 1, ?, ?, 'Toko Berkah Coffee (Reseller)', 'RESELLER', 'RESELLER', 350000, 0, 0, 350000, 180000, 'PIUTANG', 'UNPAID', 0, 0, datetime('now', '-3 days', 'localtime'))");
            $stmtSale1->execute([$inv1, $userCashier, $custBerkah]);
            $saleId1 = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, qty, unit_price, unit_cogs, subtotal_price, subtotal_cogs) VALUES (?, ?, ?, 10, 35000, 18000, 350000, 180000)")
                ->execute([$saleId1, $p1['id'], $p1['name']]);

            $dueDate1 = date('Y-m-d', strtotime('+14 days'));
            $pdo->exec("INSERT INTO customer_receivables (sale_id, invoice_number, customer_id, branch_id, total_amount, paid_amount, remaining_amount, due_date, status, notes, created_at, updated_at) VALUES
                ($saleId1, '$inv1', $custBerkah, 1, 350000, 0, 350000, '$dueDate1', 'BELUM_LUNAS', 'Tempo 14 hari pesanan reseller biji kopi & konsinyasi', datetime('now', '-3 days', 'localtime'), datetime('now', '-3 days', 'localtime'))
            ");
        }

        // Sample 2: PT Mitra Bahtera Abadi (Mitra A) - Rp 750.000 (Sebagian Lunas, Cicilan Masuk Rp 300.000)
        $inv2 = 'INV/JKT/' . date('Ymd') . '/9002';
        if ($p1) {
            $stmtSale2 = $pdo->prepare("INSERT INTO sales (invoice_number, branch_id, user_id, customer_id, customer_name, customer_category, sales_type, subtotal, discount, tax, grand_total, total_cogs, payment_method, payment_status, cash_received, cash_change, created_at) VALUES (?, 1, ?, ?, 'PT Mitra Bahtera Abadi (Mitra A)', 'MITRA', 'B2B_PARTNER', 750000, 0, 0, 750000, 360000, 'PIUTANG', 'UNPAID', 0, 0, datetime('now', '-7 days', 'localtime'))");
            $stmtSale2->execute([$inv2, $userCashier, $custMitraA]);
            $saleId2 = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, qty, unit_price, unit_cogs, subtotal_price, subtotal_cogs) VALUES (?, ?, ?, 25, 30000, 14400, 750000, 360000)")
                ->execute([$saleId2, $p1['id'], $p1['name']]);

            $dueDate2 = date('Y-m-d', strtotime('+21 days'));
            $pdo->exec("INSERT INTO customer_receivables (sale_id, invoice_number, customer_id, branch_id, total_amount, paid_amount, remaining_amount, due_date, status, notes, created_at, updated_at) VALUES
                ($saleId2, '$inv2', $custMitraA, 1, 750000, 300000, 450000, '$dueDate2', 'SEBAGIAN', 'Kontrak suplai bulanan kantor lt. 8', datetime('now', '-7 days', 'localtime'), datetime('now', '-1 days', 'localtime'))
            ");
            $recId2 = $pdo->lastInsertId();

            // Insert initial payment log
            $payDate = date('Y-m-d', strtotime('-1 days'));
            $pdo->exec("INSERT INTO receivable_payments (receivable_id, payment_date, amount, payment_method, reference_no, cashier_user_id, notes, created_at) VALUES
                ($recId2, '$payDate', 300000, 'TRANSFER', 'TRF-BCA-918230', $userCashier, 'DP Cicilan pertama via transfer rekening BCA', datetime('now', '-1 days', 'localtime'))
            ");
        }
    }

    // 28. Seed Master Jenis Produk (Product Types) if empty
    $typeCount = $pdo->query("SELECT COUNT(*) FROM product_types")->fetchColumn();
    if ($typeCount == 0) {
        $pdo->exec("INSERT INTO product_types (code, name, prefix, is_stockable, is_saleable, icon_emoji, description) VALUES
            ('FINISHED_GOODS', 'Barang Jadi', 'PRD', 1, 1, '☕', 'Produk jadi siap jual langsung di kasir POS'),
            ('RAW_MATERIAL', 'Bahan Baku', 'RAW', 1, 0, '🌾', 'Bahan baku untuk formula resep (BOM)'),
            ('SERVICE', 'Jasa / Layanan', 'SRV', 0, 1, '🛠️', 'Jasa atau ongkos kirim tanpa stok fisik')
        ");
    }

    // 29. Seed Master Satuan Produk (Product Units) if empty
    $unitCount = $pdo->query("SELECT COUNT(*) FROM product_units")->fetchColumn();
    if ($unitCount == 0) {
        $pdo->exec("INSERT INTO product_units (code, name, description, is_active) VALUES
            ('pcs', 'Pieces / Buah', 'Satuan butir/buah', 1),
            ('cup', 'Cup / Gelas', 'Satuan gelas minuman', 1),
            ('botol', 'Botol', 'Satuan kemasan botol', 1),
            ('porsi', 'Porsi', 'Satuan porsi makanan', 1),
            ('gram', 'Gram (g)', 'Satuan berat gram', 1),
            ('kg', 'Kilogram (kg)', 'Satuan berat kilogram', 1),
            ('ml', 'Mililiter (ml)', 'Satuan volume mililiter', 1),
            ('liter', 'Liter (L)', 'Satuan volume liter', 1),
            ('pack', 'Pack / Bungkus', 'Satuan kemasan bungkus', 1),
            ('box', 'Box / Kotak', 'Satuan kemasan kotak', 1)
        ");
    }

    // 30. Ensure customer_categories Table Exists & Seed Defaults
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        default_tier TEXT DEFAULT 'REGULER',
        description TEXT,
        icon_emoji TEXT DEFAULT '👥',
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $custCatCount = $pdo->query("SELECT COUNT(*) FROM customer_categories")->fetchColumn();
    if ($custCatCount == 0) {
        $pdo->exec("INSERT INTO customer_categories (code, name, default_tier, description, icon_emoji, is_active) VALUES
            ('REGULER', 'Pelanggan Reguler', 'REGULER', 'Pelanggan umum ritel non-member tanpa diskon khusus tier', '👤', 1),
            ('MEMBER', 'Member Loyalitas', 'MEMBER', 'Pelanggan terdaftar dengan fasilitas harga tier member & poin', '⭐', 1),
            ('RESELLER', 'Reseller / Agen', 'RESELLER', 'Mitra pengecer dengan fasilitas harga khusus tier reseller/grosir', '🛍️', 1),
            ('MITRA', 'Mitra Bisnis B2B', 'MITRA', 'Mitra korporasi/B2B dengan fasilitas kontrak harga khusus', '🏢', 1)
        ");
    }

    // 31. Ensure branch_stocks.is_active Column Exists
    $bsCols = array_column($pdo->query("PRAGMA table_info(branch_stocks)")->fetchAll(), 'name');
    if (!in_array('is_active', $bsCols)) {
        $pdo->exec("ALTER TABLE branch_stocks ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1");
    }

    // 32. Ensure branch_stocks.is_delivery_required & is_delivery_included Columns Exist (Pengaturan ongkir per cabang)
    if (!in_array('is_delivery_required', $bsCols)) {
        $pdo->exec("ALTER TABLE branch_stocks ADD COLUMN is_delivery_required INTEGER DEFAULT NULL");
    }
    if (!in_array('is_delivery_included', $bsCols)) {
        $pdo->exec("ALTER TABLE branch_stocks ADD COLUMN is_delivery_included INTEGER DEFAULT NULL");
    }

    // 33. Ensure expense_categories Table Exists & Seed Standard Categories
    $pdo->exec("CREATE TABLE IF NOT EXISTS expense_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT UNIQUE NOT NULL,
        group_type TEXT DEFAULT 'OPERASIONAL', -- 'PENJUALAN', 'UMUM_ADMIN', 'OPERASIONAL', 'LAINNYA'
        description TEXT,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $expCatCount = $pdo->query("SELECT COUNT(*) FROM expense_categories")->fetchColumn();
    if ($expCatCount == 0) {
        $pdo->exec("INSERT INTO expense_categories (code, name, group_type, description, is_active) VALUES
            ('EXP-GAJI', 'Beban Gaji & Upah Karyawan', 'UMUM_ADMIN', 'Gaji pokok, lembur, dan tunjangan staf outlet', 1),
            ('EXP-UTIL', 'Beban Listrik, Air & Utilitas', 'OPERASIONAL', 'Token listrik PLN, tagihan air PDAM, dan utilitas cabang', 1),
            ('EXP-SEWA', 'Beban Sewa Tempat / Gedung', 'OPERASIONAL', 'Sewa ruko, kios, atau sewa ruang outlet bulanan/tahunan', 1),
            ('EXP-INET', 'Beban Internet & Komunikasi', 'OPERASIONAL', 'Langganan WiFi toko dan pulsa komunikasi operasional', 1),
            ('EXP-ATK', 'Beban Perlengkapan Toko & Kemasan', 'OPERASIONAL', 'Kertas struk kasir, plastik kantong, isolasi, lakban, dan ATK', 1),
            ('EXP-KEBERSIHAN', 'Beban Kebersihan & Keamanan', 'OPERASIONAL', 'Iuran kebersihan sampah lingkungan dan keamanan warga', 1),
            ('EXP-PROMOSI', 'Beban Pemasaran & Promosi', 'PENJUALAN', 'Spanduk promo, brosur cetak, dan iklan media sosial lokal', 1),
            ('EXP-RETRIBUSI', 'Beban Pajak & Retribusi Daerah', 'UMUM_ADMIN', 'Retribusi sampah dinas, izin usaha daerah, dan pungutan resmi', 1),
            ('EXP-RAWAT', 'Beban Pemeliharaan & Reparasi Toko', 'OPERASIONAL', 'Perbaikan rak display, perbaikan AC toko, servis kelistrikan', 1),
            ('EXP-BBM', 'Beban Bahan Bakar (BBM) Kurir', 'PENJUALAN', 'Bensin pengiriman delivery pesanan armada kurir', 1),
            ('EXP-SERVIS', 'Beban Servis & Perawatan Armada Kurir', 'PENJUALAN', 'Perawatan berkala dan servis motor armada outlet', 1),
            ('EXP-REIMBURSE', 'Beban Reimbursement Petty Cash ESS', 'OPERASIONAL', 'Klaim kas kecil darurat yang diajukan staf via ESS', 1),
            ('EXP-LAIN', 'Beban Operasional Lain-lain', 'LAINNYA', 'Pengeluaran operasional cabang non-rutin lainnya', 1)
        ");
    }

    // 34. Ensure Opening & Closing Shift Material Stock Columns Exist in cashier_shifts
    $shiftCols = array_column($pdo->query("PRAGMA table_info(cashier_shifts)")->fetchAll(), 'name');
    if (!in_array('opening_raw_mineral', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN opening_raw_mineral INTEGER DEFAULT 0");
    }
    if (!in_array('opening_raw_ro', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN opening_raw_ro INTEGER DEFAULT 0");
    }
    if (!in_array('opening_raw_plastik', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN opening_raw_plastik INTEGER DEFAULT 0");
    }
    if (!in_array('closing_night_mineral', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN closing_night_mineral INTEGER DEFAULT 0");
    }
    if (!in_array('closing_night_ro', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN closing_night_ro INTEGER DEFAULT 0");
    }
    if (!in_array('closing_night_plastik', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN closing_night_plastik INTEGER DEFAULT 0");
    }
    if (!in_array('closing_raw_mineral', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN closing_raw_mineral INTEGER DEFAULT NULL");
    }
    if (!in_array('closing_raw_ro', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN closing_raw_ro INTEGER DEFAULT NULL");
    }
    if (!in_array('closing_raw_plastik', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN closing_raw_plastik INTEGER DEFAULT NULL");
    }
    if (!in_array('shift_notes', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN shift_notes TEXT DEFAULT NULL");
    }
    if (!in_array('opening_items_json', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN opening_items_json TEXT DEFAULT NULL");
    }
    if (!in_array('closing_items_json', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN closing_items_json TEXT DEFAULT NULL");
    }
    if (!in_array('shift_number', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN shift_number INTEGER DEFAULT 1");
    }
    if (!in_array('shift_name', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN shift_name TEXT DEFAULT 'Shift 1'");
    }
    if (!in_array('shift_type', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN shift_type TEXT DEFAULT 'SHIFT_1'");
    }
    if (!in_array('is_final_shift', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN is_final_shift INTEGER DEFAULT 0");
    }
    if (!in_array('total_expenses', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN total_expenses REAL DEFAULT 0");
    }
    if (!in_array('total_kasbon', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN total_kasbon REAL DEFAULT 0");
    }
    if (!in_array('total_meal_allowance', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN total_meal_allowance REAL DEFAULT 0");
    }
    if (!in_array('shift_disbursements_json', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN shift_disbursements_json TEXT DEFAULT NULL");
    }

    // Meteran Air Display Counter Kolom
    if (!in_array('water_meter_yesterday', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN water_meter_yesterday REAL DEFAULT NULL");
    }
    if (!in_array('water_meter_today', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN water_meter_today REAL DEFAULT NULL");
    }
    if (!in_array('water_meter_difference', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN water_meter_difference REAL DEFAULT NULL");
    }
    if (!in_array('water_meter_adjustment', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN water_meter_adjustment REAL DEFAULT 0");
    }
    if (!in_array('water_meter_adjustment_type', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN water_meter_adjustment_type TEXT DEFAULT 'PULSE'");
    }
    if (!in_array('water_meter_adjustment_reason', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN water_meter_adjustment_reason TEXT DEFAULT NULL");
    }
    if (!in_array('water_meter_dispensed_galon', $shiftCols)) {
        $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN water_meter_dispensed_galon REAL DEFAULT NULL");
    }

    // Ensure branch_expenses has shift_id
    $expCols = array_column($pdo->query("PRAGMA table_info(branch_expenses)")->fetchAll(), 'name');
    if (!in_array('shift_id', $expCols)) {
        $pdo->exec("ALTER TABLE branch_expenses ADD COLUMN shift_id INTEGER DEFAULT NULL");
    }

    // Ensure salary_loans has shift_id
    $loanCols = array_column($pdo->query("PRAGMA table_info(salary_loans)")->fetchAll(), 'name');
    if (!in_array('shift_id', $loanCols)) {
        $pdo->exec("ALTER TABLE salary_loans ADD COLUMN shift_id INTEGER DEFAULT NULL");
    }

    // Ensure employee_meal_allowances table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_meal_allowances (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        shift_id INTEGER,
        user_id INTEGER NOT NULL,
        branch_id INTEGER NOT NULL,
        amount REAL NOT NULL,
        meal_date DATE NOT NULL,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (shift_id) REFERENCES cashier_shifts(id),
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (branch_id) REFERENCES branches(id)
    )");

    // 35. Ensure opening_shift_products Configuration Table Exists (Produk lainnya yang dilacak di Opening Sales)
    $pdo->exec("CREATE TABLE IF NOT EXISTS opening_shift_products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL UNIQUE,
        sort_order INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    )");

    // Seed default 3 produk bahan baku utama jika tabel konfigurasi masih kosong
    $ospCount = $pdo->query("SELECT COUNT(*) FROM opening_shift_products")->fetchColumn();
    if ($ospCount == 0) {
        $pMineral = $pdo->query("SELECT id FROM products WHERE sku = 'RAW-089'")->fetchColumn();
        $pRo = $pdo->query("SELECT id FROM products WHERE sku = 'RAW-020'")->fetchColumn();
        $pPlastik = $pdo->query("SELECT id FROM products WHERE sku = 'RAW-00092'")->fetchColumn();

        if ($pMineral) {
            $pdo->exec("INSERT OR IGNORE INTO opening_shift_products (product_id, sort_order, is_active) VALUES ($pMineral, 1, 1)");
        }
        if ($pRo) {
            $pdo->exec("INSERT OR IGNORE INTO opening_shift_products (product_id, sort_order, is_active) VALUES ($pRo, 2, 1)");
        }
        if ($pPlastik) {
            $pdo->exec("INSERT OR IGNORE INTO opening_shift_products (product_id, sort_order, is_active) VALUES ($pPlastik, 3, 1)");
        }
    }

    // 36. Ensure work_shifts exists & seed defaults
    $pdo->exec("CREATE TABLE IF NOT EXISTS work_shifts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        start_time TEXT NOT NULL,
        end_time TEXT NOT NULL,
        late_tolerance_mins INTEGER DEFAULT 15,
        is_day_off INTEGER DEFAULT 0,
        color_tag TEXT DEFAULT '#3b82f6',
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $wsCount = $pdo->query("SELECT COUNT(*) FROM work_shifts")->fetchColumn();
    if ($wsCount == 0) {
        $pdo->exec("INSERT INTO work_shifts (code, name, start_time, end_time, late_tolerance_mins, is_day_off, color_tag, is_active) VALUES
            ('S1', 'Shift 1 (Pagi)', '07:00', '15:00', 15, 0, '#3b82f6', 1),
            ('S2', 'Shift 2 (Siang/Sore)', '14:30', '22:30', 15, 0, '#8b5cf6', 1),
            ('SM', 'Shift Middle', '10:00', '18:00', 15, 0, '#06b6d4', 1),
            ('OFF', 'Libur / Off Day', '00:00', '00:00', 0, 1, '#94a3b8', 1)
        ");
    }

    // Inisialisasi default shift untuk kasir/staf operasional (jika shift tersedia dan belum diset)
    $firstShiftId = $pdo->query("SELECT id FROM work_shifts WHERE is_day_off = 0 ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($firstShiftId) {
        $pdo->exec("UPDATE users SET default_work_shift_id = {$firstShiftId} WHERE role IN ('CASHIER', 'STAFF') AND default_work_shift_id IS NULL");
    }
    // Pastikan Super Admin dan Branch Manager SELALU NULL (dikecualikan dari mapping shift kerja)
    $pdo->exec("UPDATE users SET default_work_shift_id = NULL WHERE role IN ('SUPER_ADMIN', 'SUPERADMIN', 'BRANCH_MANAGER', 'MANAGER')");

    // 37. Ensure shift_schedules columns exist
    $schedCols = array_column($pdo->query("PRAGMA table_info(shift_schedules)")->fetchAll(), 'name');
    if (!in_array('work_shift_id', $schedCols)) {
        $pdo->exec("ALTER TABLE shift_schedules ADD COLUMN work_shift_id INTEGER DEFAULT NULL");
    }
    if (!in_array('is_holiday', $schedCols)) {
        $pdo->exec("ALTER TABLE shift_schedules ADD COLUMN is_holiday INTEGER DEFAULT 0");
    }
    if (!in_array('notes', $schedCols)) {
        $pdo->exec("ALTER TABLE shift_schedules ADD COLUMN notes TEXT DEFAULT NULL");
    }

    // 38. Ensure shift_swaps columns exist
    $swapCols = array_column($pdo->query("PRAGMA table_info(shift_swaps)")->fetchAll(), 'name');
    if (!in_array('target_date', $swapCols)) {
        $pdo->exec("ALTER TABLE shift_swaps ADD COLUMN target_date DATE DEFAULT NULL");
    }
    if (!in_array('requester_shift_name', $swapCols)) {
        $pdo->exec("ALTER TABLE shift_swaps ADD COLUMN requester_shift_name TEXT DEFAULT NULL");
    }
    if (!in_array('target_shift_name', $swapCols)) {
        $pdo->exec("ALTER TABLE shift_swaps ADD COLUMN target_shift_name TEXT DEFAULT NULL");
    }
    if (!in_array('approved_by', $swapCols)) {
        $pdo->exec("ALTER TABLE shift_swaps ADD COLUMN approved_by INTEGER DEFAULT NULL");
    }
    if (!in_array('approved_at', $swapCols)) {
        $pdo->exec("ALTER TABLE shift_swaps ADD COLUMN approved_at DATETIME DEFAULT NULL");
    }
    if (!in_array('rejection_reason', $swapCols)) {
        $pdo->exec("ALTER TABLE shift_swaps ADD COLUMN rejection_reason TEXT DEFAULT NULL");
    }

    // 39. Ensure leave_requests columns exist
    $leaveCols = array_column($pdo->query("PRAGMA table_info(leave_requests)")->fetchAll(), 'name');
    if (!in_array('branch_id', $leaveCols)) {
        $pdo->exec("ALTER TABLE leave_requests ADD COLUMN branch_id INTEGER DEFAULT 1");
    }
    if (!in_array('attachment_doc', $leaveCols)) {
        $pdo->exec("ALTER TABLE leave_requests ADD COLUMN attachment_doc TEXT DEFAULT NULL");
    }
    if (!in_array('approved_by', $leaveCols)) {
        $pdo->exec("ALTER TABLE leave_requests ADD COLUMN approved_by INTEGER DEFAULT NULL");
    }
    if (!in_array('approved_at', $leaveCols)) {
        $pdo->exec("ALTER TABLE leave_requests ADD COLUMN approved_at DATETIME DEFAULT NULL");
    }
    if (!in_array('rejection_reason', $leaveCols)) {
        $pdo->exec("ALTER TABLE leave_requests ADD COLUMN rejection_reason TEXT DEFAULT NULL");
    }

    // 40. Ensure salary_loans table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS salary_loans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        branch_id INTEGER NOT NULL,
        loan_date DATE NOT NULL,
        amount REAL NOT NULL,
        reason TEXT NOT NULL,
        installment_months INTEGER DEFAULT 1,
        monthly_deduction REAL NOT NULL,
        status TEXT DEFAULT 'PENDING',
        remaining_amount REAL NOT NULL,
        approved_by INTEGER,
        approved_at DATETIME,
        rejection_reason TEXT,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (branch_id) REFERENCES branches(id)
    )");

    // 41. Ensure employee_payrolls table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_payrolls (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        branch_id INTEGER NOT NULL,
        period_month TEXT NOT NULL,
        base_salary REAL NOT NULL,
        allowance REAL NOT NULL,
        attendance_days INTEGER DEFAULT 0,
        daily_transport_rate REAL DEFAULT 0,
        transport_total REAL DEFAULT 0,
        sales_commission REAL DEFAULT 0,
        overtime_pay REAL DEFAULT 0,
        bonus_pay REAL DEFAULT 0,
        gross_salary REAL NOT NULL,
        late_deduction REAL DEFAULT 0,
        absence_deduction REAL DEFAULT 0,
        loan_deduction REAL DEFAULT 0,
        tax_deduction REAL DEFAULT 0,
        other_deduction REAL DEFAULT 0,
        total_deductions REAL NOT NULL,
        net_salary REAL NOT NULL,
        payment_status TEXT DEFAULT 'GENERATED',
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (branch_id) REFERENCES branches(id)
    )");
    // 42. Ensure app_settings table exists and seed defaults
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key TEXT UNIQUE NOT NULL,
        setting_value TEXT,
        setting_group TEXT DEFAULT 'GENERAL',
        description TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $defaultSettings = [
        // 1 & 2. Logo & Nama Aplikasi
        ['app_name', 'ESBAS RETAIL', 'GENERAL', 'Nama resmi aplikasi POS'],
        ['app_subtitle', 'POS, BOM & ESS', 'GENERAL', 'Subjudul / slogan aplikasi'],
        ['app_logo_emoji', '🛍️', 'GENERAL', 'Logo emoji / ikon aplikasi'],
        ['app_logo_url', '', 'GENERAL', 'URL / path logo gambar kustom (opsional)'],
        ['company_name', 'ESBAS RETAIL INDONESIA', 'GENERAL', 'Nama perusahaan atau institusi'],
        ['company_address', 'Jl. Jenderal Sudirman No. 88, Jakarta Pusat', 'GENERAL', 'Alamat kantor pusat'],
        ['company_phone', '0812-3456-7890', 'GENERAL', 'Nomor telepon resmi toko'],

        // 4. Setting Printer USB / IP
        ['printer_type', 'BROWSER', 'PRINTER', 'Tipe printer: BROWSER, USB, IP_NETWORK, BLUETOOTH'],
        ['printer_ip_address', '192.168.1.200', 'PRINTER', 'Alamat IP Printer Network'],
        ['printer_ip_port', '9100', 'PRINTER', 'Port Raw ESC/POS Printer (default 9100)'],
        ['printer_paper_width', '58mm', 'PRINTER', 'Lebar kertas kasir: 58mm atau 80mm'],
        ['printer_auto_cut', '1', 'PRINTER', 'Potong kertas otomatis setelah cetak: 1=Ya, 0=Tidak'],
        ['printer_cash_drawer', '1', 'PRINTER', 'Buka laci kasir otomatis saat cetak nota: 1=Ya, 0=Tidak'],

        // 5. Setting Layout Print Nota (Header, Body, Footer)
        ['receipt_header_title', 'ESBAS RETAIL TOKO', 'RECEIPT', 'Judul paling atas struk belanja'],
        ['receipt_header_subtitle', 'Pusat Belanja & Grosir Terpadu', 'RECEIPT', 'Subjudul header struk'],
        ['receipt_header_custom_text', 'NPWP: 01.234.567.8-901.000', 'RECEIPT', 'Teks tambahan di bawah alamat cabang'],
        ['receipt_show_logo', '1', 'RECEIPT', 'Tampilkan logo/emoji pada struk: 1=Ya, 0=Tidak'],
        ['receipt_body_show_sku', '0', 'RECEIPT', 'Tampilkan SKU produk pada baris item: 1=Ya, 0=Tidak'],
        ['receipt_body_show_cogs', '0', 'RECEIPT', 'Tampilkan HPP (khusus audit internal): 1=Ya, 0=Tidak'],
        ['receipt_body_compact_spacing', '0', 'RECEIPT', 'Gunakan spasi rapat hemat kertas: 1=Ya, 0=Tidak'],
        ['receipt_footer_line1', 'Terima Kasih Atas Kunjungan Anda!', 'RECEIPT', 'Baris teks ucapan footer 1'],
        ['receipt_footer_line2', 'Barang yang sudah dibeli tidak dapat ditukar/dikembalikan.', 'RECEIPT', 'Baris teks ketentuan retur footer 2'],
        ['receipt_footer_line3', 'Simpan struk ini sebagai bukti pembayaran yang sah.', 'RECEIPT', 'Baris catatan footer 3'],
        ['receipt_footer_qr_text', 'https://esbas.id/feedback', 'RECEIPT', 'Teks / tautan untuk QR Code feedback belanja'],

        // 6. Setting Layout Slip Gaji
        ['payslip_header_title', 'SLIP GAJI KARYAWAN', 'PAYSLIP', 'Judul dokumen resmi slip gaji'],
        ['payslip_company_name', 'PT ESBAS RETAIL NUSANTARA', 'PAYSLIP', 'Nama badan usaha pada slip gaji'],
        ['payslip_confidential_label', 'RAHASIA & PRIBADI (CONFIDENTIAL)', 'PAYSLIP', 'Label kerahasiaan slip gaji'],
        ['payslip_show_terbilang', '1', 'PAYSLIP', 'Tampilkan ejaan teks terbilang rupiah: 1=Ya, 0=Tidak'],
        ['payslip_show_attendance_detail', '1', 'PAYSLIP', 'Tampilkan rincian hari kerja & hadir: 1=Ya, 0=Tidak'],
        ['payslip_show_loan_detail', '1', 'PAYSLIP', 'Tampilkan rincian sisa pinjaman kasbon: 1=Ya, 0=Tidak'],
        ['payslip_signer_1_title', 'Penerima (Karyawan)', 'PAYSLIP', 'Jabatan penanda tangan kolom kiri'],
        ['payslip_signer_2_title', 'Finance & Payroll', 'PAYSLIP', 'Jabatan penanda tangan kolom kanan'],
        ['payslip_footer_notes', 'Slip gaji ini dicetak secara komputerisasi dan sah tanpa tanda tangan basah bila disertai stempel verifikasi.', 'PAYSLIP', 'Catatan kaki lembar slip gaji'],

        // 7. Setting Kirim Nota Elektronik via WA & Telegram
        ['wa_gateway_provider', 'FONNTE', 'NOTIFICATION', 'Penyedia gateway WhatsApp: FONNTE, WANOTIF, WA_DIRECT'],
        ['wa_api_url', 'https://api.fonnte.com/send', 'NOTIFICATION', 'Endpoint API WhatsApp Gateway'],
        ['wa_api_token', '', 'NOTIFICATION', 'API Key / Token WhatsApp Gateway'],
        ['wa_auto_send_on_checkout', '0', 'NOTIFICATION', 'Kirim nota WA otomatis setiap checkout kasir: 1=Ya, 0=Tidak'],
        ['wa_template_message', "Halo {customer_name},\nTerima kasih telah berbelanja di {store_name} ({branch_name})!\n\nNo Faktur: {invoice_number}\nTanggal: {date}\nTotal Belanja: {grand_total}\nMetode: {payment_method}\n\nDetail Belanja:\n{items_summary}\n\n{footer_note}", 'NOTIFICATION', 'Template pesan WhatsApp'],
        ['telegram_bot_token', '', 'NOTIFICATION', 'Token Bot Telegram dari @BotFather'],
        ['telegram_chat_id', '', 'NOTIFICATION', 'ID Chat / Channel Telegram Toko'],
        ['telegram_auto_send_on_checkout', '0', 'NOTIFICATION', 'Kirim nota Telegram otomatis ke channel/owner: 1=Ya, 0=Tidak'],
        ['telegram_template_message', "🛒 *TRANSAKSI KASIR BARU*\n\nOutlet: *{store_name} - {branch_name}*\nKasir: {cashier_name}\nFaktur: `{invoice_number}`\nPelanggan: {customer_name}\nTotal: *{grand_total}* ({payment_method})\n\nRincian Item:\n{items_summary}\n\n_Waktu: {date}_", 'NOTIFICATION', 'Template pesan Telegram']
    ];

    $stmtSet = $pdo->prepare("INSERT OR IGNORE INTO app_settings (setting_key, setting_value, setting_group, description) VALUES (?, ?, ?, ?)");
    foreach ($defaultSettings as $ds) {
        $stmtSet->execute($ds);
    }

    // 43. Ensure role_menu_permissions table exists and seed defaults
    $pdo->exec("CREATE TABLE IF NOT EXISTS role_menu_permissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        role TEXT NOT NULL,
        menu_key TEXT NOT NULL,
        menu_title TEXT NOT NULL,
        can_access INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(role, menu_key)
    )");

    $allMenus = [
        ['pos', '1. POS Penjualan Kasir'],
        ['reports', '2. Laporan Penjualan & Piutang'],
        ['ess', '3. Portal ESS Karyawan'],
        ['employees', '4. Kelola Karyawan (CRUD)'],
        ['products', '5. Master Produk & Varian'],
        ['bom', '6. Resep / BOM Bahan'],
        ['stocks', '7. Stok & Kartu Mutasi'],
        ['pnl', '8. Laporan Laba Rugi'],
        ['expenses', '9. Beban Operasional Cabang'],
        ['customers', '10. Pelanggan & Mitra CRM'],
        ['logistics', '11. Kurir & Biaya Kirim'],
        ['branches', '12. Kelola Cabang (CRUD)'],
        ['payments', '13. Metode Pembayaran'],
        ['sale-cancellations', '14. Pembatalan Penjualan (Void)'],
        ['settings', '15. Konfigurasi Sistem & Cetak']
    ];

    $roleAccessRules = [
        'SUPERADMIN'     => ['pos', 'reports', 'ess', 'employees', 'products', 'bom', 'stocks', 'pnl', 'expenses', 'customers', 'logistics', 'branches', 'payments', 'sale-cancellations', 'settings'],
        'SUPER_ADMIN'    => ['pos', 'reports', 'ess', 'employees', 'products', 'bom', 'stocks', 'pnl', 'expenses', 'customers', 'logistics', 'branches', 'payments', 'sale-cancellations', 'settings'],
        'BRANCH_MANAGER' => ['pos', 'reports', 'ess', 'employees', 'products', 'bom', 'stocks', 'pnl', 'expenses', 'customers', 'logistics', 'branches', 'payments', 'settings'],
        'MANAGER'        => ['pos', 'reports', 'ess', 'employees', 'products', 'bom', 'stocks', 'pnl', 'expenses', 'customers', 'logistics', 'branches', 'payments', 'settings'],
        'CASHIER'        => ['pos', 'reports', 'ess'],
        'STAFF'          => ['ess', 'stocks', 'logistics']
    ];

    $stmtRolePerm = $pdo->prepare("INSERT OR IGNORE INTO role_menu_permissions (role, menu_key, menu_title, can_access) VALUES (?, ?, ?, ?)");
    foreach ($roleAccessRules as $role => $allowedMenus) {
        foreach ($allMenus as $menu) {
            $mKey = $menu[0];
            $mTitle = $menu[1];
            $can = in_array($mKey, $allowedMenus) ? 1 : 0;
            $stmtRolePerm->execute([$role, $mKey, $mTitle, $can]);
        }
    }

    // 44. Ensure branch_settings table exists for per-branch configuration overrides
    $pdo->exec("CREATE TABLE IF NOT EXISTS branch_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER NOT NULL,
        setting_key TEXT NOT NULL,
        setting_value TEXT,
        setting_group TEXT DEFAULT 'GENERAL',
        description TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(branch_id, setting_key),
        FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
    )");

    // 45. Ensure water_meter_logs table exists (Pencatatan Display Counter Meteran Air Closing Harian)
    $pdo->exec("CREATE TABLE IF NOT EXISTS water_meter_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER NOT NULL,
        shift_id INTEGER DEFAULT NULL,
        user_id INTEGER NOT NULL,
        log_date DATE NOT NULL,
        meter_yesterday REAL NOT NULL DEFAULT 0,
        meter_today REAL NOT NULL DEFAULT 0,
        meter_difference REAL NOT NULL DEFAULT 0,
        meter_adjustment REAL NOT NULL DEFAULT 0,
        adjustment_type TEXT DEFAULT 'PULSE',
        adjustment_reason TEXT,
        water_dispensed_galon REAL NOT NULL DEFAULT 0,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (branch_id) REFERENCES branches(id),
        FOREIGN KEY (shift_id) REFERENCES cashier_shifts(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");
}

function autoMigrateMysql($pdo) {
    // 1. Kolom default_work_shift_id pada tabel users
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'default_work_shift_id'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `default_work_shift_id` INT DEFAULT NULL");
            $firstShift = $pdo->query("SELECT id FROM `work_shifts` WHERE `is_day_off` = 0 ORDER BY `id` ASC LIMIT 1")->fetch();
            if ($firstShift && !empty($firstShift['id'])) {
                $firstShiftId = (int)$firstShift['id'];
                $pdo->exec("UPDATE `users` SET default_work_shift_id = {$firstShiftId} WHERE role IN ('CASHIER', 'STAFF') AND default_work_shift_id IS NULL");
            }
        }
    } catch (Exception $e) {}

    // 2. Kolom water meter pada cashier_shifts
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `cashier_shifts` LIKE 'water_meter_start'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `cashier_shifts` ADD COLUMN `water_meter_start` DECIMAL(12,2) DEFAULT NULL, ADD COLUMN `water_meter_end` DECIMAL(12,2) DEFAULT NULL");
        }
    } catch (Exception $e) {}

    // 3. Tabel water_meter_logs jika belum ada
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `water_meter_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `branch_id` INT NOT NULL,
            `shift_id` INT DEFAULT NULL,
            `user_id` INT NOT NULL,
            `log_type` VARCHAR(20) NOT NULL,
            `meter_value` DECIMAL(12,2) NOT NULL,
            `photo_path` VARCHAR(255) DEFAULT NULL,
            `notes` TEXT,
            `recorded_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    // 4. Tabel cash_denominations pada MySQL jika belum ada & seed default
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `cash_denominations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `label` VARCHAR(50) NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `action_type` VARCHAR(20) NOT NULL DEFAULT 'SET',
            `color` VARCHAR(30) DEFAULT 'indigo',
            `sort_order` INT DEFAULT 0,
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $cdCount = $pdo->query("SELECT COUNT(*) FROM `cash_denominations`")->fetchColumn();
        if ($cdCount == 0) {
            $pdo->exec("INSERT INTO `cash_denominations` (`id`, `label`, `amount`, `action_type`, `color`, `sort_order`, `is_active`) VALUES
                (1, 'Uang Pas', 0, 'EXACT', 'indigo', 1, 1),
                (2, '10K', 10000, 'SET', 'indigo', 2, 1),
                (3, '20K', 20000, 'SET', 'indigo', 3, 1),
                (4, '50K', 50000, 'SET', 'indigo', 4, 1),
                (5, '100K', 100000, 'SET', 'indigo', 5, 1)
            ");
        }
    } catch (Exception $e) {}
}

if ($dbDriver === 'mysql') {
    // Pada MySQL, jika database masih kosong dan ada esbas_pos_mysql.sql, coba inisialisasi otomatis
    try {
        $hasBranches = $pdo->query("SHOW TABLES LIKE 'branches'")->fetch();
        if (!$hasBranches && file_exists(__DIR__ . '/esbas_pos_mysql.sql')) {
            $sql = file_get_contents(__DIR__ . '/esbas_pos_mysql.sql');
            $pdo->exec($sql);
        }
    } catch (Exception $e) {
        // Skema tabel di-import manual via phpMyAdmin
    }
    // Jalankan migrasi ringan khusus MySQL yang aman (bebas syntax error SQLite)
    autoMigrateMysql($pdo);
} else {
    initDatabaseSchema($pdo);
    autoMigrateAndSync($pdo);
}

if (!function_exists('getResolvedAppSettings')) {
    function getResolvedAppSettings($pdo, $branchId = null) {
        $settings = [];
        // 1. Ambil setting default global (Kantor Pusat)
        $stmtGlobal = $pdo->query("SELECT setting_key, setting_value FROM app_settings");
        while ($r = $stmtGlobal->fetch()) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }

        // 2. Jika branchId ditentukan (> 0), ambil override spesifik cabang bersangkutan
        if ($branchId && (int)$branchId > 0) {
            $stmtBranch = $pdo->prepare("SELECT setting_key, setting_value FROM branch_settings WHERE branch_id = ?");
            $stmtBranch->execute([(int)$branchId]);
            while ($r = $stmtBranch->fetch()) {
                if ($r['setting_value'] !== null && $r['setting_value'] !== '') {
                    $settings[$r['setting_key']] = $r['setting_value'];
                }
            }
        }
        return $settings;
    }
}

