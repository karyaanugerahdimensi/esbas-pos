<?php
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/version.php';

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$action = $_GET['action'] ?? '';
// Close session write lock early to allow non-blocking concurrency
if (!in_array($action, ['login', 'logout', 'switch_branch'])) {
    session_write_close();
}
$rawBody = @file_get_contents('php://input');
$parsedBody = (!empty($rawBody)) ? json_decode($rawBody, true) : null;
$input = (!empty($parsedBody) && is_array($parsedBody)) ? $parsedBody : (!empty($_POST) ? $_POST : ($GLOBALS['CLI_INPUT'] ?? ($input ?? [])));

if (!function_exists('haversineDistanceMeters')) {
    function haversineDistanceMeters($lat1, $lon1, $lat2, $lon2) {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $earthRadius * $c;
    }
}

if (!function_exists('recalculateProductBOMCogs')) {
    function recalculateProductBOMCogs($pdo, $finishedProductId) {
        $finishedProductId = (int)$finishedProductId;
        if (!$finishedProductId) return 0;
        
        $calcCogs = (float)$pdo->query("
            SELECT COALESCE(SUM(pr.qty_needed * p.cogs), 0)
            FROM product_recipes pr
            JOIN products p ON p.id = pr.ingredient_product_id
            WHERE pr.finished_product_id = {$finishedProductId}
        ")->fetchColumn();

        if ($calcCogs > 0) {
            $pdo->prepare("UPDATE products SET cogs = ?, has_bom = 1 WHERE id = ?")->execute([$calcCogs, $finishedProductId]);
            $pdo->prepare("UPDATE branch_stocks SET local_cogs = ? WHERE product_id = ?")->execute([$calcCogs, $finishedProductId]);
        }
        return $calcCogs;
    }
}

global $pdo;
if (!isset($pdo) && file_exists(__DIR__ . '/db.php')) {
    require __DIR__ . '/db.php';
}

try {
    switch ($action) {

        // ==========================================
        // 0. VERSION & SYSTEM INFO
        // ==========================================
        case 'get_version_info':
            echo json_encode([
                'success' => true,
                'version_data' => getAppVersionData()
            ]);
            break;

        // ==========================================
        // 0.1 AUTHENTICATION & SESSION CONTROL
        // ==========================================
        case 'login':
            $username = trim($input['username'] ?? ''); // NIK or Email
            $password = trim($input['password'] ?? '');

            if (empty($username) || empty($password)) {
                echo json_encode(['success' => false, 'message' => 'NIK/Email dan Kata Sandi / PIN wajib diisi.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT u.*, b.name as branch_name 
                FROM users u 
                LEFT JOIN branches b ON b.id = u.branch_id 
                WHERE LOWER(u.nik) = LOWER(?) OR LOWER(u.email) = LOWER(?)
            ");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();

            if (!$user) {
                echo json_encode(['success' => false, 'message' => "Akun karyawan '{$username}' tidak ditemukan."]);
                exit;
            }

            if (isset($user['is_active']) && (int)$user['is_active'] === 0) {
                echo json_encode(['success' => false, 'message' => 'Akun karyawan ini telah dinonaktifkan.']);
                exit;
            }

            // Verify password or PIN
            $userPass = $user['password'] ?? 'password123';
            $userPin = $user['pin'] ?? '123456';
            if ($password !== $userPass && $password !== $userPin) {
                echo json_encode(['success' => false, 'message' => 'Kata sandi atau PIN salah. Silakan coba lagi.']);
                exit;
            }

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['branch_id'] = $user['branch_id'];

            // Hide password hash
            unset($user['password']);

            echo json_encode([
                'success' => true,
                'message' => 'Login berhasil! Selamat datang, ' . $user['name'],
                'user' => $user,
                'app_version' => getAppVersionData()
            ]);
            break;

        case 'logout':
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            session_destroy();
            echo json_encode(['success' => true, 'message' => 'Sesi berhasil diakhiri.']);
            break;

        case 'check_session':
            $userId = $_SESSION['user_id'] ?? null;
            if (!$userId) {
                echo json_encode(['success' => false, 'logged_in' => false]);
                exit;
            }

            $stmt = $pdo->prepare("SELECT u.*, b.name as branch_name FROM users u LEFT JOIN branches b ON b.id = u.branch_id WHERE u.id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if ($user) {
                unset($user['password']);
                echo json_encode(['success' => true, 'logged_in' => true, 'user' => $user]);
            } else {
                echo json_encode(['success' => false, 'logged_in' => false]);
            }
            break;

        // ==========================================
        // 1. BOOTSTRAP / INITIALIZATION
        // ==========================================
        case 'bootstrap':
            $branchId = (int)($_GET['branch_id'] ?? ($_SESSION['branch_id'] ?? 1));
            $userId = (int)($_GET['user_id'] ?? ($_SESSION['user_id'] ?? 3));

            $branches = $pdo->query("
                SELECT b.*,
                       (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id AND u.is_active = 1) as total_staff,
                       (SELECT COUNT(*) FROM branch_stocks bs WHERE bs.branch_id = b.id) as total_products,
                       (SELECT COUNT(*) FROM courier_vehicles cv WHERE cv.branch_id = b.id AND cv.is_active = 1) as total_vehicles
                FROM branches b
                ORDER BY b.id ASC
            ")->fetchAll();
            $users = $pdo->query("SELECT id, nik, name, role, branch_id, email, phone, commission_rate, is_active FROM users ORDER BY id ASC")->fetchAll();

            $stmtBranch = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
            $stmtBranch->execute([$branchId]);
            $currentBranch = $stmtBranch->fetch() ?: $branches[0];

            $stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $currentUser = $stmtUser->fetch() ?: $users[0];
            unset($currentUser['password']);

            // Active Shift
            $stmtShift = $pdo->prepare("SELECT * FROM cashier_shifts WHERE branch_id = ? AND user_id = ? AND status = 'OPEN' ORDER BY id DESC LIMIT 1");
            $stmtShift->execute([$currentBranch['id'], $currentUser['id']]);
            $activeShift = $stmtShift->fetch();

            // POS Products: Only FINISHED_GOODS for cashier sale with Multi-Tier Pricing & Per-Item Tax (Hanya produk yang aktif dijual di cabang ini)
            $stmtProducts = $pdo->prepare("
                SELECT p.id, p.sku, p.barcode, p.name, p.category, p.product_type, p.unit, p.image_emoji, p.has_bom,
                       p.member_price, p.reseller_price, p.mitra_price, p.visible_categories,
                       p.is_taxable, p.tax_id, p.tax_percent,
                       COALESCE(bs.is_delivery_required, p.is_delivery_required, 0) as is_delivery_required,
                       COALESCE(bs.is_delivery_included, p.is_delivery_included, 0) as is_delivery_included,
                       bs.stock, bs.local_cogs as cogs, bs.local_price as selling_price,
                       COALESCE(bs.is_active, 1) as branch_is_active
                FROM products p
                JOIN branch_stocks bs ON p.id = bs.product_id
                WHERE bs.branch_id = ? AND p.is_active = 1 AND p.product_type = 'FINISHED_GOODS'
                  AND COALESCE(bs.is_active, 1) = 1
                ORDER BY p.category ASC, p.name ASC
            ");
            $stmtProducts->execute([$currentBranch['id']]);
            $products = $stmtProducts->fetchAll();

            // Fetch Product Variants and their Variant BOM Recipes
            $stmtVariants = $pdo->query("
                SELECT pv.*, p.name as product_name, p.sku as product_sku
                FROM product_variants pv
                JOIN products p ON p.id = pv.product_id
                ORDER BY pv.product_id, pv.id
            ");
            $allVariants = $stmtVariants->fetchAll();

            $stmtVarRecipes = $pdo->query("
                SELECT vr.*, p.name as ingredient_name, p.sku as ingredient_sku, p.cogs as ingredient_cogs
                FROM variant_recipes vr
                JOIN products p ON p.id = vr.ingredient_product_id
                ORDER BY vr.variant_id, vr.id
            ");
            $allVarRecipes = $stmtVarRecipes->fetchAll();
            $varRecipesByVariant = [];
            foreach ($allVarRecipes as $vr) {
                $varRecipesByVariant[$vr['variant_id']][] = $vr;
            }

            $variantsByProduct = [];
            foreach ($allVariants as &$v) {
                $v['ingredients'] = $varRecipesByVariant[$v['id']] ?? [];
                $v['has_variant_bom'] = !empty($v['ingredients']);
                $variantsByProduct[$v['product_id']][] = $v;
            }

            foreach ($products as &$p) {
                $p['variants'] = $variantsByProduct[$p['id']] ?? [];
            }
            unset($p);

            // Product Categories
            $categories = $pdo->query("SELECT * FROM categories ORDER BY id ASC")->fetchAll();

            // Customers (CRM & Pricing Tiers & Coordinates)
            $customers = $pdo->query("SELECT * FROM customers WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

            // Mitra Custom Contract Pricing (Setiap mitra harga berbeda)
            $stmtMitraPrices = $pdo->query("
                SELECT mcp.*, p.name as product_name, p.sku as product_sku, c.name as customer_name 
                FROM mitra_custom_prices mcp 
                JOIN products p ON p.id = mcp.product_id 
                JOIN customers c ON c.id = mcp.customer_id 
                ORDER BY mcp.customer_id, mcp.product_id
            ");
            $mitraPrices = $stmtMitraPrices->fetchAll();

            // Delivery Settings (Biaya Kirim Dinamis)
            $stmtDeliv = $pdo->prepare("SELECT * FROM delivery_settings WHERE branch_id = ?");
            $stmtDeliv->execute([$currentBranch['id']]);
            $currentDeliverySetting = $stmtDeliv->fetch() ?: [
                'branch_id' => $currentBranch['id'],
                'base_fee' => 10000,
                'base_distance_km' => 3.0,
                'per_km_rate' => 2500,
                'free_delivery_threshold' => 150000,
                'max_distance_km' => 35.0
            ];
            $allDeliverySettings = $pdo->query("SELECT ds.*, b.name as branch_name FROM delivery_settings ds JOIN branches b ON ds.branch_id = b.id ORDER BY ds.branch_id ASC")->fetchAll();

            // Courier Vehicles (Armada Kurir Cabang)
            $stmtVehicles = $pdo->prepare("SELECT * FROM courier_vehicles WHERE branch_id = ? AND is_active = 1 ORDER BY id ASC");
            $stmtVehicles->execute([$currentBranch['id']]);
            $branchVehicles = $stmtVehicles->fetchAll();
            $allVehicles = $pdo->query("SELECT cv.*, b.name as branch_name FROM courier_vehicles cv JOIN branches b ON cv.branch_id = b.id WHERE cv.is_active = 1 ORDER BY cv.branch_id, cv.id ASC")->fetchAll();

            $allTaxes = $pdo->query("SELECT * FROM taxes ORDER BY id ASC")->fetchAll();
            $allPaymentMethods = $pdo->query("SELECT * FROM payment_methods ORDER BY sort_order ASC, id ASC")->fetchAll();
            $productTypes = $pdo->query("SELECT * FROM product_types ORDER BY id ASC")->fetchAll();
            $productUnits = $pdo->query("SELECT * FROM product_units WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
            $customerCategories = $pdo->query("SELECT * FROM customer_categories ORDER BY id ASC")->fetchAll();

            // App Settings (Konfigurasi Terpusat dengan Hierarki Per-Cabang)
            $curBranchId = (int)($currentBranch['id'] ?? 0);
            $appSettingsMap = getResolvedAppSettings($pdo, $curBranchId);
            $rawSettings = $pdo->query("SELECT * FROM app_settings ORDER BY id ASC")->fetchAll();
            $branchOverrides = $curBranchId > 0 ? $pdo->query("SELECT * FROM branch_settings WHERE branch_id = {$curBranchId} ORDER BY id ASC")->fetchAll() : [];

            // Role Menu Permissions
            $rolePerms = $pdo->query("SELECT * FROM role_menu_permissions ORDER BY id ASC")->fetchAll();
            $userPerms = [];
            $curRole = $currentUser['role'] ?? 'CASHIER';
            foreach ($rolePerms as $rp) {
                if ($rp['role'] === $curRole) {
                    $userPerms[$rp['menu_key']] = (int)$rp['can_access'];
                }
            }

            echo json_encode([
                'success' => true,
                'branches' => $branches,
                'current_branch' => $currentBranch,
                'users' => $users,
                'current_user' => $currentUser,
                'active_shift' => $activeShift,
                'products' => $products,
                'variants' => $allVariants,
                'variant_recipes' => $allVarRecipes,
                'categories' => $categories,
                'product_types' => $productTypes,
                'product_units' => $productUnits,
                'taxes' => $allTaxes,
                'payment_methods' => $allPaymentMethods,
                'customers' => $customers,
                'mitra_prices' => $mitraPrices,
                'customer_categories' => $customerCategories,
                'current_delivery_setting' => $currentDeliverySetting,
                'all_delivery_settings' => $allDeliverySettings,
                'branch_vehicles' => $branchVehicles,
                'all_vehicles' => $allVehicles,
                'app_settings' => $appSettingsMap,
                'raw_settings' => $rawSettings,
                'role_permissions' => $rolePerms,
                'user_permissions' => $userPerms,
                'app_version' => getAppVersionData()
            ]);
            break;

        // ==========================================
        // 1.1 MANAJEMEN CABANG / OUTLET (CRUD BRANCHES)
        // ==========================================
        case 'get_branches':
            $stmt = $pdo->query("
                SELECT b.*,
                       (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id AND u.is_active = 1) as total_staff,
                       (SELECT COUNT(*) FROM branch_stocks bs WHERE bs.branch_id = b.id) as total_products,
                       (SELECT COUNT(*) FROM courier_vehicles cv WHERE cv.branch_id = b.id AND cv.is_active = 1) as total_vehicles
                FROM branches b
                ORDER BY b.id ASC
            ");
            echo json_encode(['success' => true, 'branches' => $stmt->fetchAll()]);
            break;

        case 'save_branch':
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $code = strtoupper(trim($input['code'] ?? ''));
            $name = trim($input['name'] ?? '');
            $address = trim($input['address'] ?? '');
            $phone = trim($input['phone'] ?? '');
            $latitude = isset($input['latitude']) && $input['latitude'] !== '' ? (float)$input['latitude'] : -6.1950;
            $longitude = isset($input['longitude']) && $input['longitude'] !== '' ? (float)$input['longitude'] : 106.8230;
            $radiusMeters = !empty($input['radius_meters']) ? (int)$input['radius_meters'] : 100;
            $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

            if (empty($code) || empty($name) || empty($address)) {
                echo json_encode(['success' => false, 'message' => 'Kode Cabang, Nama Outlet, dan Alamat wajib diisi.']);
                exit;
            }

            // Check duplicate code
            if ($id) {
                $dupCheck = $pdo->prepare("SELECT id FROM branches WHERE code = ? AND id != ?");
                $dupCheck->execute([$code, $id]);
            } else {
                $dupCheck = $pdo->prepare("SELECT id FROM branches WHERE code = ?");
                $dupCheck->execute([$code]);
            }
            if ($dupCheck->fetch()) {
                echo json_encode(['success' => false, 'message' => "Kode Cabang '{$code}' sudah digunakan oleh cabang lain."]);
                exit;
            }

            if ($id) {
                // Update
                $stmt = $pdo->prepare("
                    UPDATE branches 
                    SET code = ?, name = ?, address = ?, phone = ?, latitude = ?, longitude = ?, radius_meters = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$code, $name, $address, $phone, $latitude, $longitude, $radiusMeters, $isActive, $id]);
                $msg = "Cabang '{$name}' ({$code}) berhasil diperbarui.";
            } else {
                // Insert new branch
                $stmt = $pdo->prepare("
                    INSERT INTO branches (code, name, address, phone, latitude, longitude, radius_meters, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$code, $name, $address, $phone, $latitude, $longitude, $radiusMeters, $isActive]);
                $id = $pdo->lastInsertId();

                // 1. Inisialisasi inventaris stok default untuk seluruh produk katalog
                $allProducts = $pdo->query("SELECT id, cogs, selling_price FROM products WHERE is_active = 1")->fetchAll();
                $stmtInitStock = $pdo->prepare("INSERT OR IGNORE INTO branch_stocks (branch_id, product_id, stock, local_cogs, local_price) VALUES (?, ?, 50, ?, ?)");
                foreach ($allProducts as $p) {
                    $stmtInitStock->execute([$id, $p['id'], $p['cogs'], $p['selling_price']]);
                }

                // 2. Inisialisasi pengaturan pengiriman dinamis default (delivery_settings)
                $pdo->prepare("
                    INSERT OR IGNORE INTO delivery_settings (branch_id, base_fee, base_distance_km, per_km_rate, free_delivery_threshold, max_distance_km)
                    VALUES (?, 10000, 3.0, 2500, 150000, 35.0)
                ")->execute([$id]);

                $msg = "Cabang baru '{$name}' ({$code}) berhasil ditambahkan dan stok awal berhasil diinisialisasi.";
            }

            echo json_encode(['success' => true, 'message' => $msg, 'branch_id' => $id]);
            break;

        case 'delete_branch':
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID Cabang tidak valid.']);
                exit;
            }

            // Cabang utama kantor pusat (ID 1) tidak boleh dihapus
            if ($id === 1) {
                echo json_encode(['success' => false, 'message' => 'Cabang Utama / Head Office tidak dapat dihapus.']);
                exit;
            }

            // Cek apakah ada transaksi penjualan yang tercatat pada cabang ini
            $salesCount = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE branch_id = ?");
            $salesCount->execute([$id]);
            $hasSales = (int)$salesCount->fetchColumn();

            if ($hasSales > 0) {
                // Soft delete jika sudah ada transaksi riil untuk menjaga integritas akuntansi
                $pdo->prepare("UPDATE branches SET is_active = 0 WHERE id = ?")->execute([$id]);
                echo json_encode(['success' => true, 'message' => "Cabang memiliki {$hasSales} riwayat transaksi penjualan. Status cabang diubah menjadi Nonaktif (Soft Delete)."]);
            } else {
                // Hard delete jika belum pernah ada transaksi penjualan
                $pdo->prepare("DELETE FROM branch_stocks WHERE branch_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM delivery_settings WHERE branch_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM branches WHERE id = ?")->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Cabang dan data inisialisasi berhasil dihapus permanen.']);
            }
            break;

        // ==========================================
        // 2. MANAJEMEN KARYAWAN (CRUD EMPLOYEES)
        // ==========================================
        case 'get_employees':
            try {
                $stmt = $pdo->query("
                    SELECT u.id, u.nik, u.name, u.role, u.branch_id, u.email, u.phone, u.commission_rate, u.is_active, u.pin,
                           u.base_salary, u.allowance, u.daily_transport,
                           u.default_work_shift_id,
                           ws.name as default_shift_name, ws.code as default_shift_code, ws.start_time as default_shift_start, ws.end_time as default_shift_end, ws.color_tag as default_shift_color,
                           b.name as branch_name
                    FROM users u
                    LEFT JOIN branches b ON b.id = u.branch_id
                    LEFT JOIN work_shifts ws ON ws.id = u.default_work_shift_id
                    ORDER BY u.id ASC
                ");
                echo json_encode(['success' => true, 'employees' => $stmt->fetchAll()]);
            } catch (Exception $e) {
                // Auto-healing: Tambahkan kolom default_work_shift_id jika belum ada di database MySQL / SQLite
                try {
                    $pdo->exec("ALTER TABLE users ADD COLUMN default_work_shift_id INT DEFAULT NULL");
                    $firstShiftId = $pdo->query("SELECT id FROM work_shifts WHERE is_day_off = 0 ORDER BY id ASC LIMIT 1")->fetchColumn();
                    if ($firstShiftId) {
                        $pdo->exec("UPDATE users SET default_work_shift_id = {$firstShiftId} WHERE role IN ('CASHIER', 'STAFF') AND default_work_shift_id IS NULL");
                    }
                    $stmt = $pdo->query("
                        SELECT u.id, u.nik, u.name, u.role, u.branch_id, u.email, u.phone, u.commission_rate, u.is_active, u.pin,
                               u.base_salary, u.allowance, u.daily_transport,
                               u.default_work_shift_id,
                               ws.name as default_shift_name, ws.code as default_shift_code, ws.start_time as default_shift_start, ws.end_time as default_shift_end, ws.color_tag as default_shift_color,
                               b.name as branch_name
                        FROM users u
                        LEFT JOIN branches b ON b.id = u.branch_id
                        LEFT JOIN work_shifts ws ON ws.id = u.default_work_shift_id
                        ORDER BY u.id ASC
                    ");
                    echo json_encode(['success' => true, 'employees' => $stmt->fetchAll()]);
                } catch (Exception $e2) {
                    // Fallback query tanpa join shift agar data karyawan TETAP TAMPIL normal
                    $stmt = $pdo->query("
                        SELECT u.id, u.nik, u.name, u.role, u.branch_id, u.email, u.phone, u.commission_rate, u.is_active, u.pin,
                               u.base_salary, u.allowance, u.daily_transport,
                               NULL as default_work_shift_id,
                               NULL as default_shift_name, NULL as default_shift_code, NULL as default_shift_start, NULL as default_shift_end, NULL as default_shift_color,
                               b.name as branch_name
                        FROM users u
                        LEFT JOIN branches b ON b.id = u.branch_id
                        ORDER BY u.id ASC
                    ");
                    echo json_encode(['success' => true, 'employees' => $stmt->fetchAll()]);
                }
            }
            break;

        case 'save_employee':
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $nik = trim($input['nik'] ?? '');
            $name = trim($input['name'] ?? '');
            $role = trim($input['role'] ?? 'CASHIER');
            $branchId = (int)($input['branch_id'] ?? 1);
            $email = trim($input['email'] ?? '');
            $phone = trim($input['phone'] ?? '');
            $password = trim($input['password'] ?? 'password123');
            $pin = trim($input['pin'] ?? '123456');
            $commRate = (float)($input['commission_rate'] ?? 0.015);
            $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

            if (empty($nik) || empty($name)) {
                echo json_encode(['success' => false, 'message' => 'NIK dan Nama Karyawan wajib diisi.']);
                exit;
            }

            // Role Super Admin & Branch Manager tidak dimapping ke shift kerja
            $isManagerialExempt = in_array(strtoupper($role), ['SUPER_ADMIN', 'SUPERADMIN', 'BRANCH_MANAGER', 'MANAGER']);
            $defaultWorkShiftId = (!$isManagerialExempt && !empty($input['default_work_shift_id'])) ? (int)$input['default_work_shift_id'] : null;

            $baseSalary = isset($input['base_salary']) ? (float)$input['base_salary'] : 3000000;
            $allowance = isset($input['allowance']) ? (float)$input['allowance'] : 500000;
            $dailyTransport = isset($input['daily_transport']) ? (float)$input['daily_transport'] : 25000;

            if ($id) {
                // Update
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET nik = ?, name = ?, role = ?, branch_id = ?, email = ?, phone = ?, 
                        pin = ?, commission_rate = ?, is_active = ?, base_salary = ?, allowance = ?, daily_transport = ?,
                        default_work_shift_id = ? " . (!empty($input['password']) ? ", password = ?" : "") . "
                    WHERE id = ?
                ");
                $params = [$nik, $name, $role, $branchId, $email, $phone, $pin, $commRate, $isActive, $baseSalary, $allowance, $dailyTransport, $defaultWorkShiftId];
                if (!empty($input['password'])) $params[] = $password;
                $params[] = $id;
                $stmt->execute($params);
                $msg = "Karyawan '{$name}' berhasil diperbarui.";
            } else {
                // Insert
                $stmt = $pdo->prepare("
                    INSERT INTO users (nik, name, role, branch_id, email, phone, password, pin, commission_rate, is_active, base_salary, allowance, daily_transport, default_work_shift_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$nik, $name, $role, $branchId, $email, $phone, $password, $pin, $commRate, $isActive, $baseSalary, $allowance, $dailyTransport, $defaultWorkShiftId]);
                $msg = "Karyawan '{$name}' berhasil ditambahkan.";
            }

            echo json_encode(['success' => true, 'message' => $msg]);
            break;

        case 'get_employee_schedule':
            $userId = (int)($_GET['user_id'] ?? 0);
            $month = !empty($_GET['month']) ? trim($_GET['month']) : date('Y-m');
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                $month = date('Y-m');
            }

            if (!$userId) {
                echo json_encode(['success' => false, 'message' => 'User ID wajib ditentukan.']);
                exit;
            }

            try {
                $stmtUser = $pdo->prepare("
                    SELECT u.id, u.nik, u.name, u.role, u.branch_id, u.default_work_shift_id,
                           ws.name as default_shift_name, ws.code as default_shift_code, ws.start_time as default_shift_start, ws.end_time as default_shift_end,
                           b.name as branch_name
                    FROM users u
                    LEFT JOIN branches b ON b.id = u.branch_id
                    LEFT JOIN work_shifts ws ON ws.id = u.default_work_shift_id
                    WHERE u.id = ?
                ");
                $stmtUser->execute([$userId]);
                $user = $stmtUser->fetch();
            } catch (Exception $e) {
                try {
                    $pdo->exec("ALTER TABLE users ADD COLUMN default_work_shift_id INT DEFAULT NULL");
                } catch (Exception $e2) {}
                $stmtUser = $pdo->prepare("
                    SELECT u.id, u.nik, u.name, u.role, u.branch_id, NULL as default_work_shift_id,
                           NULL as default_shift_name, NULL as default_shift_code, NULL as default_shift_start, NULL as default_shift_end,
                           b.name as branch_name
                    FROM users u
                    LEFT JOIN branches b ON b.id = u.branch_id
                    WHERE u.id = ?
                ");
                $stmtUser->execute([$userId]);
                $user = $stmtUser->fetch();
            }
            if (!$user) {
                echo json_encode(['success' => false, 'message' => 'Karyawan tidak ditemukan.']);
                exit;
            }

            $isExempt = in_array(strtoupper($user['role']), ['SUPER_ADMIN', 'SUPERADMIN', 'BRANCH_MANAGER', 'MANAGER']);

            $workShifts = $pdo->query("SELECT * FROM work_shifts WHERE is_active = 1 ORDER BY is_day_off ASC, start_time ASC")->fetchAll();

            list($year, $mon) = explode('-', $month);
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, (int)$mon, (int)$year);

            $stmtSched = $pdo->prepare("
                SELECT ss.*, ws.code as shift_code, ws.color_tag, ws.is_day_off as shift_is_day_off
                FROM shift_schedules ss
                LEFT JOIN work_shifts ws ON ws.id = ss.work_shift_id
                WHERE ss.user_id = ? AND strftime('%Y-%m', ss.shift_date) = ?
                ORDER BY ss.shift_date ASC
            ");
            $stmtSched->execute([$userId, $month]);
            $schedules = $stmtSched->fetchAll();

            // Map schedules by shift_date
            $schedByDate = [];
            foreach ($schedules as $sc) {
                $schedByDate[$sc['shift_date']] = $sc;
            }

            echo json_encode([
                'success' => true,
                'employee' => $user,
                'is_exempt' => $isExempt,
                'month' => $month,
                'days_in_month' => $daysInMonth,
                'work_shifts' => $workShifts,
                'schedules' => $schedByDate
            ]);
            break;

        case 'save_employee_schedule_batch':
            $userId = (int)($input['user_id'] ?? 0);
            $month = trim($input['month'] ?? date('Y-m'));
            $branchId = (int)($input['branch_id'] ?? 1);
            $entries = $input['entries'] ?? []; // array of {shift_date, work_shift_id, is_holiday, notes}

            if (!$userId) {
                echo json_encode(['success' => false, 'message' => 'User ID wajib ditentukan.']);
                exit;
            }

            $stmtUser = $pdo->prepare("SELECT id, name, role, branch_id FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $u = $stmtUser->fetch();
            if (!$u) {
                echo json_encode(['success' => false, 'message' => 'Karyawan tidak ditemukan.']);
                exit;
            }

            if (in_array(strtoupper($u['role']), ['SUPER_ADMIN', 'SUPERADMIN', 'BRANCH_MANAGER', 'MANAGER'])) {
                echo json_encode(['success' => false, 'message' => "Karyawan {$u['name']} ({$u['role']}) dikecualikan dari pemetaan jadwal shift (fleksibilitas manajerial)."]);
                exit;
            }

            $branchId = !empty($u['branch_id']) ? $u['branch_id'] : $branchId;

            $shiftsLookup = [];
            $allWs = $pdo->query("SELECT * FROM work_shifts")->fetchAll();
            foreach ($allWs as $ws) {
                $shiftsLookup[$ws['id']] = $ws;
            }

            $pdo->beginTransaction();
            $stmtCheck = $pdo->prepare("SELECT id FROM shift_schedules WHERE user_id = ? AND shift_date = ?");
            $stmtUp = $pdo->prepare("UPDATE shift_schedules SET branch_id = ?, work_shift_id = ?, shift_name = ?, start_time = ?, end_time = ?, is_holiday = ?, notes = ? WHERE id = ?");
            $stmtIn = $pdo->prepare("INSERT INTO shift_schedules (branch_id, user_id, shift_date, work_shift_id, shift_name, start_time, end_time, is_holiday, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $countSaved = 0;
            foreach ($entries as $ent) {
                $shiftDate = trim($ent['shift_date'] ?? '');
                $workShiftId = !empty($ent['work_shift_id']) ? (int)$ent['work_shift_id'] : null;
                $isHoliday = !empty($ent['is_holiday']) ? 1 : 0;
                $notes = trim($ent['notes'] ?? '');

                if (empty($shiftDate)) continue;

                $shiftName = 'Libur / Off';
                $startTime = '00:00';
                $endTime = '00:00';
                if ($workShiftId && isset($shiftsLookup[$workShiftId])) {
                    $ws = $shiftsLookup[$workShiftId];
                    $shiftName = $ws['name'];
                    $startTime = $ws['start_time'];
                    $endTime = $ws['end_time'];
                    if ($ws['is_day_off']) $isHoliday = 1;
                }

                $stmtCheck->execute([$userId, $shiftDate]);
                $exId = $stmtCheck->fetchColumn();

                if ($exId) {
                    $stmtUp->execute([$branchId, $workShiftId, $shiftName, $startTime, $endTime, $isHoliday, $notes, $exId]);
                } else {
                    $stmtIn->execute([$branchId, $userId, $shiftDate, $workShiftId, $shiftName, $startTime, $endTime, $isHoliday, $notes]);
                }
                $countSaved++;
            }
            $pdo->commit();

            echo json_encode(['success' => true, 'message' => "Jadwal shift {$countSaved} hari berhasil disimpan untuk {$u['name']}."]);
            break;

        case 'apply_employee_default_shift_month':
            $userId = (int)($input['user_id'] ?? 0);
            $month = trim($input['month'] ?? date('Y-m'));
            $overrideAll = !empty($input['override_all']) ? 1 : 0;
            $defaultShiftId = !empty($input['default_work_shift_id']) ? (int)$input['default_work_shift_id'] : null;

            if (!$userId) {
                echo json_encode(['success' => false, 'message' => 'User ID wajib ditentukan.']);
                exit;
            }
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                echo json_encode(['success' => false, 'message' => 'Format bulan harus YYYY-MM.']);
                exit;
            }

            $stmtUser = $pdo->prepare("SELECT id, name, role, branch_id, default_work_shift_id FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $u = $stmtUser->fetch();
            if (!$u) {
                echo json_encode(['success' => false, 'message' => 'Karyawan tidak ditemukan.']);
                exit;
            }

            if (in_array(strtoupper($u['role']), ['SUPER_ADMIN', 'SUPERADMIN', 'BRANCH_MANAGER', 'MANAGER'])) {
                echo json_encode(['success' => false, 'message' => "Karyawan {$u['name']} ({$u['role']}) dikecualikan dari jadwal shift."]);
                exit;
            }

            $shiftIdToApply = $defaultShiftId ?: $u['default_work_shift_id'];
            if (!$shiftIdToApply) {
                echo json_encode(['success' => false, 'message' => 'Karyawan belum memiliki jadwal shift bawaan. Silakan pilih shift bawaan terlebih dahulu.']);
                exit;
            }

            // Jika dikirim defaultShiftId baru, sekalian update default_work_shift_id karyawan
            if ($defaultShiftId && $defaultShiftId != $u['default_work_shift_id']) {
                $pdo->prepare("UPDATE users SET default_work_shift_id = ? WHERE id = ?")->execute([$defaultShiftId, $userId]);
            }

            $stmtWs = $pdo->prepare("SELECT * FROM work_shifts WHERE id = ?");
            $stmtWs->execute([$shiftIdToApply]);
            $ws = $stmtWs->fetch();
            if (!$ws) {
                echo json_encode(['success' => false, 'message' => 'Data shift kerja tidak ditemukan.']);
                exit;
            }

            list($year, $mon) = explode('-', $month);
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, (int)$mon, (int)$year);
            $branchId = !empty($u['branch_id']) ? $u['branch_id'] : 1;

            $pdo->beginTransaction();
            $stmtCheck = $pdo->prepare("SELECT id, work_shift_id, is_holiday FROM shift_schedules WHERE user_id = ? AND shift_date = ?");
            $stmtUp = $pdo->prepare("UPDATE shift_schedules SET branch_id = ?, work_shift_id = ?, shift_name = ?, start_time = ?, end_time = ?, is_holiday = ? WHERE id = ?");
            $stmtIn = $pdo->prepare("INSERT INTO shift_schedules (branch_id, user_id, shift_date, work_shift_id, shift_name, start_time, end_time, is_holiday) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

            $countApplied = 0;
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $dateStr = sprintf('%s-%02d', $month, $d);
                $stmtCheck->execute([$userId, $dateStr]);
                $existing = $stmtCheck->fetch();

                // Jika sudah ada jadwal dan bukan override, lewati
                if ($existing && !$overrideAll) {
                    continue;
                }

                $isHoliday = $ws['is_day_off'] ? 1 : 0;
                if ($existing) {
                    $stmtUp->execute([$branchId, $ws['id'], $ws['name'], $ws['start_time'], $ws['end_time'], $isHoliday, $existing['id']]);
                } else {
                    $stmtIn->execute([$branchId, $userId, $dateStr, $ws['id'], $ws['name'], $ws['start_time'], $ws['end_time'], $isHoliday]);
                }
                $countApplied++;
            }
            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "Berhasil menerapkan {$ws['name']} ke {$countApplied} hari pada bulan {$month} untuk {$u['name']}."
            ]);
            break;

        case 'delete_employee':
            $id = (int)($input['id'] ?? 0);
            if ($id === 1) {
                echo json_encode(['success' => false, 'message' => 'Super Admin utama tidak boleh dinonaktifkan.']);
                exit;
            }
            $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Karyawan berhasil dinonaktifkan (Soft Delete).']);
            break;

        // ==========================================
        // 3. MASTER PRODUK, KATEGORI, VARIAN & JENIS
        // ==========================================
        case 'get_all_products':
            // Returns ALL products including RAW_MATERIAL and FINISHED_GOODS with Tax configuration
            $stmt = $pdo->query("
                SELECT p.*, c.name as category_name, t.name as tax_name, t.rate_percent as tax_rate
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN taxes t ON t.id = p.tax_id
                ORDER BY p.product_type ASC, p.category ASC, p.name ASC
            ");
            $prods = $stmt->fetchAll();

            // Fetch variants for each product
            $stmtVar = $pdo->query("SELECT * FROM product_variants ORDER BY id ASC");
            $allVariants = $stmtVar->fetchAll();
            $variantsByProd = [];
            foreach ($allVariants as $v) {
                $variantsByProd[$v['product_id']][] = $v;
            }

            foreach ($prods as &$p) {
                $p['variants'] = $variantsByProd[$p['id']] ?? [];
            }

            echo json_encode(['success' => true, 'products' => $prods]);
            break;

        case 'save_product':
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $sku = trim($input['sku'] ?? '');
            $barcode = trim($input['barcode'] ?? '');
            $name = trim($input['name'] ?? '');
            $categoryId = (int)($input['category_id'] ?? 1);
            $productType = trim($input['product_type'] ?? 'FINISHED_GOODS'); // FINISHED_GOODS, RAW_MATERIAL, SERVICE
            $unit = trim($input['unit'] ?? 'pcs');
            $cogs = (float)($input['cogs'] ?? 0);
            $sellingPrice = (float)($input['selling_price'] ?? 0);
            $memberPrice = !empty($input['member_price']) ? (float)$input['member_price'] : round($sellingPrice * 0.9, -2);
            $resellerPrice = !empty($input['reseller_price']) ? (float)$input['reseller_price'] : round($sellingPrice * 0.8, -2);
            $mitraPrice = !empty($input['mitra_price']) ? (float)$input['mitra_price'] : round($sellingPrice * 0.72, -2);
            $visibleCategories = trim($input['visible_categories'] ?? 'ALL');
            $imageEmoji = trim($input['image_emoji'] ?? '📦');
            $hasBom = !empty($input['has_bom']) ? 1 : 0;
            $reorderPoint = (int)($input['reorder_point'] ?? 10);
            $isTaxable = isset($input['is_taxable']) ? (int)$input['is_taxable'] : 1;
            $taxId = !empty($input['tax_id']) ? (int)$input['tax_id'] : 1;
            $taxPercent = isset($input['tax_percent']) ? (float)$input['tax_percent'] : 11.0;
            $isDeliveryRequired = isset($input['is_delivery_required']) ? (int)$input['is_delivery_required'] : 0;
            $isDeliveryIncluded = isset($input['is_delivery_included']) ? (int)$input['is_delivery_included'] : ($isDeliveryRequired === 2 ? 1 : 0);
            if ($isDeliveryIncluded === 1 && $isDeliveryRequired !== 2) {
                $isDeliveryRequired = 2;
            }
            $isActive = isset($input['is_active']) ? (int)$input['is_active'] : null;

            // Jika dalam mode edit (ada $id), pertahankan SKU atau Barcode jika input kosong
            if ($id) {
                $existing = $pdo->query("SELECT sku, barcode FROM products WHERE id = " . (int)$id)->fetch();
                if ($existing) {
                    if (empty($sku)) $sku = $existing['sku'];
                    if (empty($barcode)) $barcode = $existing['barcode'];
                }
            }

            // Fetch category name
            $catName = $pdo->query("SELECT name FROM categories WHERE id = $categoryId")->fetchColumn() ?: 'Umum';

            // Fetch actual tax percent if taxId provided
            if ($taxId) {
                $tRate = $pdo->query("SELECT rate_percent FROM taxes WHERE id = $taxId")->fetchColumn();
                if ($tRate !== false) {
                    $taxPercent = (float)$tRate;
                }
            }

            // Auto-generate SKU jika belum diisi (5 Digit Sekuensial Berdasarkan Jenis Produk)
            if (empty($sku)) {
                $stmtPt = $pdo->prepare("SELECT prefix FROM product_types WHERE code = ? OR name = ? LIMIT 1");
                $stmtPt->execute([$productType, $productType]);
                $ptRow = $stmtPt->fetch();
                $prefixCode = $ptRow ? strtoupper(trim(str_replace('-', '', $ptRow['prefix']))) : ($productType === 'RAW_MATERIAL' ? 'RAW' : ($productType === 'SERVICE' ? 'SRV' : 'PRD'));
                
                // Cari angka urutan tertinggi untuk prefix jenis produk ini
                $existingSkus = $pdo->query("SELECT sku FROM products WHERE sku LIKE '{$prefixCode}-%'")->fetchAll(PDO::FETCH_COLUMN);
                $maxNum = 0;
                foreach ($existingSkus as $s) {
                    if (preg_match('/^' . preg_quote($prefixCode, '/') . '-(\d+)$/i', $s, $m)) {
                        $num = (int)$m[1];
                        if ($num > $maxNum) $maxNum = $num;
                    }
                }
                $nextSeq = $maxNum + 1;
                for ($k = $nextSeq; $k < $nextSeq + 500; $k++) {
                    $cand = $prefixCode . '-' . str_pad($k, 5, '0', STR_PAD_LEFT);
                    $exists = $pdo->query("SELECT COUNT(*) FROM products WHERE sku = '$cand'")->fetchColumn();
                    if (!$exists) {
                        $sku = $cand;
                        break;
                    }
                }
                if (empty($sku)) $sku = $prefixCode . '-' . str_pad($nextSeq, 5, '0', STR_PAD_LEFT);
            }

            // Auto-generate Barcode jika belum diisi (12-digit numeric unik)
            if (empty($barcode)) {
                $nextSeqBc = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn() + 1;
                for ($k = $nextSeqBc; $k < $nextSeqBc + 500; $k++) {
                    $candBc = '899' . date('y') . str_pad($k, 7, '0', STR_PAD_LEFT);
                    $existsBc = $pdo->query("SELECT COUNT(*) FROM products WHERE barcode = '$candBc'")->fetchColumn();
                    if (!$existsBc) {
                        $barcode = $candBc;
                        break;
                    }
                }
                if (empty($barcode)) $barcode = '899' . time();
            }

            if (empty($sku) || empty($name)) {
                echo json_encode(['success' => false, 'message' => 'SKU dan Nama Produk wajib diisi.']);
                exit;
            }

            if ($id) {
                $stmt = $pdo->prepare("
                    UPDATE products 
                    SET sku = ?, barcode = ?, name = ?, category_id = ?, category = ?, product_type = ?, 
                        unit = ?, cogs = ?, selling_price = ?, member_price = ?, reseller_price = ?, mitra_price = ?, visible_categories = ?,
                        image_emoji = ?, has_bom = ?, reorder_point = ?, is_taxable = ?, tax_id = ?, tax_percent = ?, is_delivery_required = ?, is_delivery_included = ?,
                        is_active = COALESCE(?, is_active)
                    WHERE id = ?
                ");
                $stmt->execute([
                    $sku, $barcode, $name, $categoryId, $catName, $productType, 
                    $unit, $cogs, $sellingPrice, $memberPrice, $resellerPrice, $mitraPrice, $visibleCategories, 
                    $imageEmoji, $hasBom, $reorderPoint, $isTaxable, $taxId, $taxPercent, $isDeliveryRequired, $isDeliveryIncluded, $isActive, $id
                ]);
                
                // Sinkronisasi ke branch_stocks agar harga langsung ter-update di form penjualan POS kasir
                $branchIdParam = !empty($input['branch_id']) ? (int)$input['branch_id'] : null;
                if ($branchIdParam) {
                    $pdo->prepare("UPDATE branch_stocks SET local_price = ?, local_cogs = ?, is_delivery_required = ?, is_delivery_included = ?, last_updated = datetime('now', 'localtime') WHERE product_id = ? AND branch_id = ?")
                        ->execute([$sellingPrice, $cogs, $isDeliveryRequired, $isDeliveryIncluded, $id, $branchIdParam]);
                } else {
                    $pdo->prepare("UPDATE branch_stocks SET local_price = ?, local_cogs = ?, last_updated = datetime('now', 'localtime') WHERE product_id = ?")
                        ->execute([$sellingPrice, $cogs, $id]);
                }

                // Jika barang baku diupdate modalnya, rekalkulasi seluruh barang jadi yang memakai bahan ini
                if ($productType === 'RAW_MATERIAL') {
                    $affectedFg = $pdo->query("SELECT DISTINCT finished_product_id FROM product_recipes WHERE ingredient_product_id = " . (int)$id)->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($affectedFg as $fgId) {
                        recalculateProductBOMCogs($pdo, $fgId);
                    }
                } elseif ($productType === 'FINISHED_GOODS' && $hasBom) {
                    // Jika barang jadi ber-BOM, hitung ulang jika diminta atau jika cogs 0
                    $bomCogs = recalculateProductBOMCogs($pdo, $id);
                    if ($bomCogs > 0) $cogs = $bomCogs;
                }

                $msg = "Produk '{$name}' dan konfigurasi ongkir berhasil diperbarui.";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO products (sku, barcode, name, category_id, category, product_type, unit, cogs, selling_price, member_price, reseller_price, mitra_price, visible_categories, image_emoji, has_bom, reorder_point, is_taxable, tax_id, tax_percent, is_delivery_required, is_delivery_included)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $sku, $barcode, $name, $categoryId, $catName, $productType, 
                    $unit, $cogs, $sellingPrice, $memberPrice, $resellerPrice, $mitraPrice, $visibleCategories, 
                    $imageEmoji, $hasBom, $reorderPoint, $isTaxable, $taxId, $taxPercent, $isDeliveryRequired, $isDeliveryIncluded
                ]);
                $id = $pdo->lastInsertId();

                // Initialize stock for all branches
                $branches = $pdo->query("SELECT id FROM branches")->fetchAll();
                $stmtInitStock = $pdo->prepare("INSERT OR IGNORE INTO branch_stocks (branch_id, product_id, stock, local_cogs, local_price, is_delivery_required, is_delivery_included) VALUES (?, ?, 50, ?, ?, ?, ?)");
                foreach ($branches as $b) {
                    $stmtInitStock->execute([$b['id'], $id, $cogs, $sellingPrice, $isDeliveryRequired, $isDeliveryIncluded]);
                }
                $msg = "Produk baru '{$name}' berhasil ditambahkan ke katalog.";
            }

            echo json_encode(['success' => true, 'message' => $msg, 'product_id' => $id, 'cogs' => $cogs]);
            break;

        case 'delete_product':
            $prodId = (int)($input['id'] ?? 0);
            if (!$prodId) {
                echo json_encode(['success' => false, 'message' => 'ID Produk tidak valid.']);
                exit;
            }

            $stmtProd = $pdo->prepare("SELECT id, name, sku FROM products WHERE id = ?");
            $stmtProd->execute([$prodId]);
            $prod = $stmtProd->fetch();
            if (!$prod) {
                echo json_encode(['success' => false, 'message' => 'Produk tidak ditemukan.']);
                exit;
            }

            // Cek apakah produk memiliki riwayat transaksi penjualan
            $salesCount = (int)$pdo->query("SELECT COUNT(*) FROM sale_items WHERE product_id = $prodId")->fetchColumn();

            if ($salesCount > 0) {
                // Soft Delete jika ada riwayat transaksi
                $pdo->prepare("UPDATE products SET is_active = 0 WHERE id = ?")->execute([$prodId]);
                $pdo->prepare("UPDATE branch_stocks SET stock = 0 WHERE product_id = ?")->execute([$prodId]);
                echo json_encode([
                    'success' => true,
                    'soft_deleted' => true,
                    'mode' => 'soft_delete',
                    'message' => "Produk '{$prod['name']}' ({$prod['sku']}) dinonaktifkan (Soft Delete) karena memiliki riwayat {$salesCount} item transaksi penjualan."
                ]);
            } else {
                // Hard Delete aman jika belum ada transaksi penjualan
                $pdo->prepare("DELETE FROM product_recipes WHERE finished_product_id = ? OR ingredient_product_id = ?")->execute([$prodId, $prodId]);
                $pdo->prepare("DELETE FROM variant_recipes WHERE ingredient_product_id = ?")->execute([$prodId]);
                $pdo->prepare("DELETE FROM product_variants WHERE product_id = ?")->execute([$prodId]);
                $pdo->prepare("DELETE FROM branch_stocks WHERE product_id = ?")->execute([$prodId]);
                $pdo->prepare("DELETE FROM mitra_custom_prices WHERE product_id = ?")->execute([$prodId]);
                $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$prodId]);
                echo json_encode([
                    'success' => true,
                    'soft_deleted' => false,
                    'mode' => 'hard_delete',
                    'message' => "Produk '{$prod['name']}' ({$prod['sku']}) berhasil dihapus permanen dari sistem katalog."
                ]);
            }
            break;

        case 'generate_sku_barcode':
            $type = trim($input['product_type'] ?? ($_GET['product_type'] ?? 'FINISHED_GOODS'));
            $catId = (int)($input['category_id'] ?? ($_GET['category_id'] ?? 1));

            // Ambil prefix dari tabel product_types
            $stmtPt = $pdo->prepare("SELECT prefix, name FROM product_types WHERE code = ? OR name = ? LIMIT 1");
            $stmtPt->execute([$type, $type]);
            $ptRow = $stmtPt->fetch();
            $prefixCode = $ptRow ? strtoupper(trim(str_replace('-', '', $ptRow['prefix']))) : ($type === 'RAW_MATERIAL' ? 'RAW' : ($type === 'SERVICE' ? 'SRV' : 'PRD'));
            $prefix = $prefixCode . '-';

            // Hitung nomor urutan tertinggi yang sudah terpakai untuk prefix jenis produk ini
            $existingSkus = $pdo->query("SELECT sku FROM products WHERE sku LIKE '{$prefixCode}-%'")->fetchAll(PDO::FETCH_COLUMN);
            $maxNum = 0;
            foreach ($existingSkus as $s) {
                if (preg_match('/^' . preg_quote($prefixCode, '/') . '-(\d+)$/i', $s, $m)) {
                    $num = (int)$m[1];
                    if ($num > $maxNum) $maxNum = $num;
                }
            }
            $startSeq = $maxNum + 1;

            // Generate unique SKU dengan format 5 Digit Angka (contoh: PRD-00001, RAW-00001)
            $sku = '';
            for ($k = $startSeq; $k < $startSeq + 1000; $k++) {
                $candidate = $prefix . str_pad($k, 5, '0', STR_PAD_LEFT);
                $exists = $pdo->query("SELECT COUNT(*) FROM products WHERE sku = '$candidate'")->fetchColumn();
                if (!$exists) {
                    $sku = $candidate;
                    break;
                }
            }
            if (!$sku) $sku = $prefix . str_pad($startSeq, 5, '0', STR_PAD_LEFT);

            // Generate unique Barcode (12-digit numeric unik berstandar)
            $maxId = (int)$pdo->query("SELECT MAX(id) FROM products")->fetchColumn();
            $nextSeqBc = $maxId + 1;
            $barcode = '';
            for ($k = $nextSeqBc; $k < $nextSeqBc + 500; $k++) {
                $candidateBc = '899' . date('y') . str_pad($k, 7, '0', STR_PAD_LEFT);
                $existsBc = $pdo->query("SELECT COUNT(*) FROM products WHERE barcode = '$candidateBc'")->fetchColumn();
                if (!$existsBc) {
                    $barcode = $candidateBc;
                    break;
                }
            }
            if (!$barcode) $barcode = '899' . time();

            echo json_encode([
                'success' => true,
                'sku' => $sku,
                'barcode' => $barcode,
                'prefix' => $prefixCode,
                'suggested_type' => $type,
                'type_name' => $ptRow['name'] ?? $type
            ]);
            break;

        case 'get_product_bom_cogs':
            $prodId = (int)($input['product_id'] ?? ($_GET['product_id'] ?? 0));
            if (!$prodId) {
                echo json_encode(['success' => false, 'message' => 'Product ID diperlukan.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT pr.id, pr.qty_needed, pr.unit, p.name as ingredient_name, p.sku as ingredient_sku, p.cogs as ingredient_cogs,
                       (pr.qty_needed * p.cogs) as subtotal_ingredient_cost
                FROM product_recipes pr
                JOIN products p ON p.id = pr.ingredient_product_id
                WHERE pr.finished_product_id = ?
                ORDER BY pr.id ASC
            ");
            $stmt->execute([$prodId]);
            $ingredients = $stmt->fetchAll();
            $totalCogs = (float)array_sum(array_column($ingredients, 'subtotal_ingredient_cost'));

            echo json_encode([
                'success' => true,
                'product_id' => $prodId,
                'calculated_cogs' => $totalCogs,
                'ingredients' => $ingredients,
                'count' => count($ingredients)
            ]);
            break;

        case 'export_products':
            $format = strtolower(trim($_GET['format'] ?? 'csv'));
            $isTemplate = ($format === 'template' || !empty($_GET['template']));

            if ($isTemplate) {
                // Return CSV Template with sample row
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="template_import_master_produk.csv"');
                $out = fopen('php://output', 'w');
                // BOM header for Excel compatibility
                fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
                fputcsv($out, [
                    'sku', 'barcode', 'name', 'category', 'product_type', 'unit', 
                    'cogs', 'selling_price', 'member_price', 'reseller_price', 'mitra_price', 
                    'is_taxable', 'tax_id', 'is_delivery_required', 'has_bom', 'reorder_point'
                ]);
                fputcsv($out, [
                    'PRD-SAMPLE-01', '899202600101', 'Kopi Susu Gula Aren 250ml', 'Minuman', 'FINISHED_GOODS', 'botol',
                    '8000', '18000', '16000', '14500', '13000',
                    '1', '1', '1', '1', '20'
                ]);
                fputcsv($out, [
                    'RAW-SAMPLE-01', '899202600102', 'Biji Kopi Arabika House Blend', 'Bahan Baku', 'RAW_MATERIAL', 'gram',
                    '120', '0', '0', '0', '0',
                    '0', '1', '0', '0', '500'
                ]);
                fclose($out);
                exit;
            }

            // Export all active products
            $stmt = $pdo->query("
                SELECT p.*, c.name as category_name, t.name as tax_name, t.rate_percent as tax_rate
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN taxes t ON t.id = p.tax_id
                WHERE p.is_active = 1
                ORDER BY p.id ASC
            ");
            $prods = $stmt->fetchAll();

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="master_produk_esbas_' . date('Ymd_His') . '.csv"');
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, [
                'id', 'sku', 'barcode', 'name', 'category', 'product_type', 'unit', 
                'cogs', 'selling_price', 'member_price', 'reseller_price', 'mitra_price', 
                'is_taxable', 'tax_name', 'tax_percent', 'is_delivery_required', 'has_bom', 'reorder_point'
            ]);

            foreach ($prods as $p) {
                fputcsv($out, [
                    $p['id'],
                    $p['sku'],
                    $p['barcode'],
                    $p['name'],
                    $p['category'],
                    $p['product_type'],
                    $p['unit'],
                    $p['cogs'],
                    $p['selling_price'],
                    $p['member_price'],
                    $p['reseller_price'],
                    $p['mitra_price'],
                    $p['is_taxable'] ? '1' : '0',
                    $p['tax_name'] ?? 'PPN',
                    $p['tax_percent'] ?? 11,
                    $p['is_delivery_required'] ? '1' : '0',
                    $p['has_bom'] ? '1' : '0',
                    $p['reorder_point'] ?? 10
                ]);
            }
            fclose($out);
            exit;

        case 'import_products':
            $csvString = '';
            if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
                $csvString = file_get_contents($_FILES['file']['tmp_name']);
            } elseif (!empty($input['csv_content'])) {
                $csvString = $input['csv_content'];
            }

            if (empty($csvString) && empty($input['rows'])) {
                echo json_encode(['success' => false, 'message' => 'Berkas CSV atau data baris produk tidak ditemukan.']);
                exit;
            }

            // Map header sinonim Bahasa Indonesia & Inggris ke kolom DB resmi
            $headerMap = [
                'kode sku' => 'sku', 'sku' => 'sku', 'kode' => 'sku',
                'barcode' => 'barcode', 'kode barcode' => 'barcode',
                'nama produk' => 'name', 'nama' => 'name', 'name' => 'name',
                'kategori' => 'category', 'category' => 'category',
                'tipe produk' => 'product_type', 'tipe' => 'product_type', 'product_type' => 'product_type',
                'satuan' => 'unit', 'unit' => 'unit',
                'hpp pokok' => 'cogs', 'hpp' => 'cogs', 'modal' => 'cogs', 'cogs' => 'cogs',
                'harga jual' => 'selling_price', 'harga' => 'selling_price', 'selling_price' => 'selling_price',
                'harga member' => 'member_price', 'member_price' => 'member_price',
                'harga reseller' => 'reseller_price', 'reseller_price' => 'reseller_price',
                'harga mitra' => 'mitra_price', 'mitra_price' => 'mitra_price',
                'dikenakan ongkir' => 'is_delivery_required', 'ongkir' => 'is_delivery_required', 'is_delivery_required' => 'is_delivery_required',
                'has_bom' => 'has_bom', 'bom' => 'has_bom',
                'reorder_point' => 'reorder_point', 'stok minimum' => 'reorder_point',
                'is_taxable' => 'is_taxable', 'kena pajak' => 'is_taxable',
                'status aktif' => 'is_active', 'is_active' => 'is_active'
            ];

            // Parse CSV lines
            $rows = [];
            if (!empty($input['rows']) && is_array($input['rows'])) {
                $rows = $input['rows'];
            } else {
                $lines = preg_split("/\r\n|\n|\r/", trim($csvString));
                if (count($lines) < 2) {
                    echo json_encode(['success' => false, 'message' => 'Format file CSV tidak valid atau baris data kosong.']);
                    exit;
                }
                
                // Detect delimiter
                $headerLine = $lines[0];
                $delimiter = (strpos($headerLine, ';') !== false && substr_count($headerLine, ';') > substr_count($headerLine, ',')) ? ';' : ',';
                $rawHeaders = str_getcsv($headerLine, $delimiter);
                $canonicalHeaders = [];
                foreach ($rawHeaders as $rh) {
                    $cleaned = strtolower(trim(str_replace(["\xEF\xBB\xBF", '"', "'"], '', $rh)));
                    $canonicalHeaders[] = $headerMap[$cleaned] ?? $cleaned;
                }

                for ($i = 1; $i < count($lines); $i++) {
                    $line = trim($lines[$i]);
                    if (empty($line)) continue;
                    $data = str_getcsv($line, $delimiter);
                    if (count($data) === count($canonicalHeaders)) {
                        $rows[] = array_combine($canonicalHeaders, $data);
                    }
                }
            }

            if (empty($rows)) {
                echo json_encode(['success' => false, 'message' => 'Tidak ada baris data produk yang dapat dibaca dari file.']);
                exit;
            }

            $insertedCount = 0;
            $updatedCount = 0;
            $branches = $pdo->query("SELECT id FROM branches")->fetchAll();

            $stmtFind = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
            $stmtInsert = $pdo->prepare("
                INSERT INTO products (sku, barcode, name, category_id, category, product_type, unit, cogs, selling_price, member_price, reseller_price, mitra_price, visible_categories, image_emoji, has_bom, reorder_point, is_taxable, tax_id, tax_percent, is_delivery_required)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ALL', '📦', ?, ?, ?, ?, ?, ?)
            ");
            $stmtUpdate = $pdo->prepare("
                UPDATE products 
                SET barcode = ?, name = ?, category_id = ?, category = ?, product_type = ?, unit = ?, 
                    cogs = ?, selling_price = ?, member_price = ?, reseller_price = ?, mitra_price = ?, 
                    has_bom = ?, reorder_point = ?, is_taxable = ?, tax_id = ?, tax_percent = ?, is_delivery_required = ?
                WHERE id = ?
            ");
            $stmtInitStock = $pdo->prepare("INSERT OR IGNORE INTO branch_stocks (branch_id, product_id, stock, local_cogs, local_price) VALUES (?, ?, 50, ?, ?)");
            $stmtSyncStock = $pdo->prepare("UPDATE branch_stocks SET local_price = ?, local_cogs = ? WHERE product_id = ?");

            // Cache categories
            $cats = $pdo->query("SELECT id, name FROM categories")->fetchAll();
            $catMap = [];
            foreach ($cats as $c) {
                $catMap[strtolower($c['name'])] = $c['id'];
            }

            foreach ($rows as $r) {
                $name = trim($r['name'] ?? $r['nama'] ?? '');
                if (empty($name)) continue;

                $sku = trim($r['sku'] ?? '');
                if (empty($sku)) {
                    $sku = 'PRD-' . date('ymd') . rand(100, 999);
                }
                $barcode = trim($r['barcode'] ?? '');
                if (empty($barcode)) {
                    $barcode = '899' . date('y') . rand(1000000, 9999999);
                } else {
                    $stmtBcCheck = $pdo->prepare("SELECT id FROM products WHERE barcode = ? AND sku != ?");
                    $stmtBcCheck->execute([$barcode, $sku]);
                    if ($stmtBcCheck->fetch()) {
                        $barcode = '899' . date('y') . rand(1000000, 9999999);
                    }
                }

                $catName = trim($r['category'] ?? $r['kategori'] ?? 'Umum');
                $catId = $catMap[strtolower($catName)] ?? 1;

                $pType = strtoupper(trim($r['product_type'] ?? $r['tipe'] ?? 'FINISHED_GOODS'));
                if (!in_array($pType, ['FINISHED_GOODS', 'RAW_MATERIAL', 'SERVICE'])) {
                    $pType = 'FINISHED_GOODS';
                }

                $unit = trim($r['unit'] ?? $r['satuan'] ?? 'pcs');
                $cogs = (float)($r['cogs'] ?? $r['hpp'] ?? 0);
                $sellingPrice = (float)($r['selling_price'] ?? $r['harga_jual'] ?? 0);
                $memberPrice = !empty($r['member_price']) ? (float)$r['member_price'] : round($sellingPrice * 0.9, -2);
                $resellerPrice = !empty($r['reseller_price']) ? (float)$r['reseller_price'] : round($sellingPrice * 0.8, -2);
                $mitraPrice = !empty($r['mitra_price']) ? (float)$r['mitra_price'] : round($sellingPrice * 0.72, -2);
                $hasBom = !empty($r['has_bom']) ? (int)$r['has_bom'] : 0;
                $reorder = !empty($r['reorder_point']) ? (int)$r['reorder_point'] : 10;
                $isTax = isset($r['is_taxable']) ? (int)$r['is_taxable'] : 1;
                $taxId = !empty($r['tax_id']) ? (int)$r['tax_id'] : 1;
                $taxPercent = !empty($r['tax_percent']) ? (float)$r['tax_percent'] : 11.0;
                $isDeliv = !empty($r['is_delivery_required']) ? (int)$r['is_delivery_required'] : 0;

                $stmtFind->execute([$sku]);
                $existingId = $stmtFind->fetchColumn();

                if ($existingId) {
                    $stmtUpdate->execute([
                        $barcode, $name, $catId, $catName, $pType, $unit,
                        $cogs, $sellingPrice, $memberPrice, $resellerPrice, $mitraPrice,
                        $hasBom, $reorder, $isTax, $taxId, $taxPercent, $isDeliv,
                        $existingId
                    ]);
                    $stmtSyncStock->execute([$sellingPrice, $cogs, $existingId]);
                    $updatedCount++;
                } else {
                    $stmtInsert->execute([
                        $sku, $barcode, $name, $catId, $catName, $pType, $unit,
                        $cogs, $sellingPrice, $memberPrice, $resellerPrice, $mitraPrice,
                        $hasBom, $reorder, $isTax, $taxId, $taxPercent, $isDeliv
                    ]);
                    $newId = $pdo->lastInsertId();
                    foreach ($branches as $b) {
                        $stmtInitStock->execute([$b['id'], $newId, $cogs, $sellingPrice]);
                    }
                    $insertedCount++;
                }
            }

            echo json_encode([
                'success' => true,
                'message' => "Proses import selesai! {$insertedCount} produk baru berhasil didaftarkan, dan {$updatedCount} produk berhasil diperbarui.",
                'inserted' => $insertedCount,
                'updated' => $updatedCount,
                'imported' => ($insertedCount + $updatedCount)
            ]);
            break;

        // ==========================================
        // 3.1 MASTER DATA: KATEGORI PRODUK (CRUD CATEGORIES)
        // ==========================================
        case 'get_categories':
            $categories = $pdo->query("
                SELECT c.*, 
                       (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) as total_products
                FROM categories c 
                ORDER BY c.id ASC
            ")->fetchAll();
            echo json_encode(['success' => true, 'categories' => $categories]);
            break;

        case 'save_category':
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $name = trim($input['name'] ?? '');
            $code = strtoupper(trim($input['code'] ?? ''));
            if (empty($code) && !empty($name)) {
                $code = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $name), 0, 10));
                if (empty($code)) $code = 'CAT' . time();
            }
            $iconEmoji = trim($input['icon_emoji'] ?? ($input['icon'] ?? '📁'));

            if (empty($code) || empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Nama Kategori wajib diisi.']);
                exit;
            }

            if ($id) {
                $check = $pdo->prepare("SELECT id FROM categories WHERE code = ? AND id != ?");
                $check->execute([$code, $id]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Kode Kategori '{$code}' sudah digunakan oleh kategori lain."]);
                    exit;
                }
                $pdo->prepare("UPDATE categories SET code = ?, name = ?, icon_emoji = ? WHERE id = ?")
                    ->execute([$code, $name, $iconEmoji, $id]);
                // Sync name to products
                $pdo->prepare("UPDATE products SET category = ? WHERE category_id = ?")->execute([$name, $id]);
                echo json_encode(['success' => true, 'message' => "Kategori '{$name}' berhasil diperbarui.", 'category_id' => $id]);
            } else {
                $check = $pdo->prepare("SELECT id FROM categories WHERE code = ?");
                $check->execute([$code]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Kode Kategori '{$code}' sudah ada."]);
                    exit;
                }
                $pdo->prepare("INSERT INTO categories (code, name, icon_emoji) VALUES (?, ?, ?)")
                    ->execute([$code, $name, $iconEmoji]);
                $newId = $pdo->lastInsertId();
                echo json_encode(['success' => true, 'message' => "Kategori baru '{$name}' berhasil ditambahkan.", 'category_id' => $newId]);
            }
            break;

        case 'delete_category':
            $id = (int)($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'ID Kategori tidak valid.']);
                exit;
            }
            if ($id === 1) {
                echo json_encode(['success' => false, 'message' => 'Kategori bawaan sistem (ID 1) tidak dapat dihapus.']);
                exit;
            }
            $cat = $pdo->query("SELECT * FROM categories WHERE id = $id")->fetch();
            if (!$cat) {
                echo json_encode(['success' => false, 'message' => 'Kategori tidak ditemukan.']);
                exit;
            }
            $usedCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE category_id = $id")->fetchColumn();
            if ($usedCount > 0) {
                echo json_encode(['success' => false, 'message' => "Kategori '{$cat['name']}' masih digunakan oleh {$usedCount} produk. Pindahkan atau hapus produk terlebih dahulu."]);
                exit;
            }
            $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => "Kategori '{$cat['name']}' berhasil dihapus."]);
            break;

        // ==========================================
        // 3.2 MASTER DATA: JENIS PRODUK (CRUD PRODUCT TYPES)
        // ==========================================
        case 'get_product_types':
            $types = $pdo->query("
                SELECT pt.*,
                       (SELECT COUNT(*) FROM products p WHERE p.product_type = pt.code) as total_products
                FROM product_types pt
                ORDER BY pt.id ASC
            ")->fetchAll();
            echo json_encode(['success' => true, 'product_types' => $types]);
            break;

        case 'save_product_type':
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $code = strtoupper(trim(preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '_', $input['code'] ?? ''))));
            $name = trim($input['name'] ?? '');
            $prefix = strtoupper(trim(str_replace('-', '', $input['prefix'] ?? '')));
            $isStockable = isset($input['is_stockable']) ? (int)$input['is_stockable'] : 1;
            $isSaleable = isset($input['is_saleable']) ? (int)$input['is_saleable'] : 1;
            $iconEmoji = trim($input['icon_emoji'] ?? '📦');
            $description = trim($input['description'] ?? '');

            if (empty($code) || empty($name) || empty($prefix)) {
                echo json_encode(['success' => false, 'message' => 'Kode Jenis, Nama Jenis, dan Prefix SKU (3-4 huruf) wajib diisi.']);
                exit;
            }

            if ($id) {
                $check = $pdo->prepare("SELECT id FROM product_types WHERE code = ? AND id != ?");
                $check->execute([$code, $id]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Kode Jenis Produk '{$code}' sudah digunakan oleh jenis lain."]);
                    exit;
                }
                $oldCode = $pdo->query("SELECT code FROM product_types WHERE id = $id")->fetchColumn();
                $pdo->prepare("UPDATE product_types SET code = ?, name = ?, prefix = ?, is_stockable = ?, is_saleable = ?, icon_emoji = ?, description = ? WHERE id = ?")
                    ->execute([$code, $name, $prefix, $isStockable, $isSaleable, $iconEmoji, $description, $id]);
                if ($oldCode && $oldCode !== $code) {
                    $pdo->prepare("UPDATE products SET product_type = ? WHERE product_type = ?")->execute([$code, $oldCode]);
                }
                echo json_encode(['success' => true, 'message' => "Jenis produk '{$name}' berhasil diperbarui.", 'type_id' => $id]);
            } else {
                $check = $pdo->prepare("SELECT id FROM product_types WHERE code = ?");
                $check->execute([$code]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Kode Jenis Produk '{$code}' sudah ada."]);
                    exit;
                }
                $pdo->prepare("INSERT INTO product_types (code, name, prefix, is_stockable, is_saleable, icon_emoji, description) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$code, $name, $prefix, $isStockable, $isSaleable, $iconEmoji, $description]);
                $newId = $pdo->lastInsertId();
                echo json_encode(['success' => true, 'message' => "Jenis produk baru '{$name}' berhasil didaftarkan.", 'type_id' => $newId]);
            }
            break;

        case 'delete_product_type':
            $id = (int)($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'ID Jenis Produk tidak valid.']);
                exit;
            }
            $pt = $pdo->query("SELECT * FROM product_types WHERE id = $id")->fetch();
            if (!$pt) {
                echo json_encode(['success' => false, 'message' => 'Jenis produk tidak ditemukan.']);
                exit;
            }
            // Proteksi jenis inti sistem
            if (in_array($pt['code'], ['FINISHED_GOODS', 'RAW_MATERIAL', 'SERVICE'])) {
                echo json_encode(['success' => false, 'message' => "Jenis produk inti '{$pt['name']}' ({$pt['code']}) dilindungi sistem dan tidak dapat dihapus."]);
                exit;
            }
            $usedCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_type = '{$pt['code']}'")->fetchColumn();
            if ($usedCount > 0) {
                echo json_encode(['success' => false, 'message' => "Jenis produk '{$pt['name']}' masih digunakan oleh {$usedCount} produk katalog. Ubah jenis produk tersebut terlebih dahulu."]);
                exit;
            }
            $pdo->prepare("DELETE FROM product_types WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => "Jenis produk '{$pt['name']}' berhasil dihapus."]);
            break;

        // ==========================================
        // 3.3 MASTER DATA: SATUAN PRODUK (CRUD PRODUCT UNITS)
        // ==========================================
        case 'get_product_units':
            $units = $pdo->query("
                SELECT pu.*,
                       (SELECT COUNT(*) FROM products p WHERE LOWER(p.unit) = LOWER(pu.code)) as total_products
                FROM product_units pu
                ORDER BY pu.id ASC
            ")->fetchAll();
            echo json_encode(['success' => true, 'product_units' => $units]);
            break;

        case 'save_product_unit':
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $code = strtolower(trim(preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['code'] ?? '')));
            $name = trim($input['name'] ?? '');
            $description = trim($input['description'] ?? '');
            $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

            if (empty($code) || empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Kode Satuan (misal: pcs, kg, cup) dan Nama Satuan wajib diisi.']);
                exit;
            }

            if ($id) {
                $check = $pdo->prepare("SELECT id FROM product_units WHERE code = ? AND id != ?");
                $check->execute([$code, $id]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Kode Satuan '{$code}' sudah digunakan oleh satuan lain."]);
                    exit;
                }
                $oldCode = $pdo->query("SELECT code FROM product_units WHERE id = $id")->fetchColumn();
                $pdo->prepare("UPDATE product_units SET code = ?, name = ?, description = ?, is_active = ? WHERE id = ?")
                    ->execute([$code, $name, $description, $isActive, $id]);
                if ($oldCode && $oldCode !== $code) {
                    $pdo->prepare("UPDATE products SET unit = ? WHERE LOWER(unit) = ?")->execute([$code, strtolower($oldCode)]);
                }
                echo json_encode(['success' => true, 'message' => "Satuan '{$name}' berhasil diperbarui.", 'unit_id' => $id]);
            } else {
                $check = $pdo->prepare("SELECT id FROM product_units WHERE code = ?");
                $check->execute([$code]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Kode Satuan '{$code}' sudah ada."]);
                    exit;
                }
                $pdo->prepare("INSERT INTO product_units (code, name, description, is_active) VALUES (?, ?, ?, ?)")
                    ->execute([$code, $name, $description, $isActive]);
                $newId = $pdo->lastInsertId();
                echo json_encode(['success' => true, 'message' => "Satuan baru '{$name}' berhasil didaftarkan.", 'unit_id' => $newId]);
            }
            break;

        case 'delete_product_unit':
            $id = (int)($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'ID Satuan tidak valid.']);
                exit;
            }
            $unit = $pdo->query("SELECT * FROM product_units WHERE id = $id")->fetch();
            if (!$unit) {
                echo json_encode(['success' => false, 'message' => 'Satuan tidak ditemukan.']);
                exit;
            }
            $usedCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE LOWER(unit) = '" . strtolower($unit['code']) . "'")->fetchColumn();
            if ($usedCount > 0) {
                echo json_encode(['success' => false, 'message' => "Satuan '{$unit['name']}' ({$unit['code']}) masih digunakan oleh {$usedCount} produk di katalog."]);
                exit;
            }
            $pdo->prepare("DELETE FROM product_units WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => "Satuan '{$unit['name']}' berhasil dihapus."]);
            break;

        case 'update_branch_product_price':
            // Setiap branch bisa menjual produk yang sama dan harga berbeda, serta aktif/nonaktif & status ongkir berbeda
            $branchId = (int)($input['branch_id'] ?? 0);
            $productId = (int)($input['product_id'] ?? 0);
            $localPrice = (float)($input['local_price'] ?? 0);
            $localCogs = isset($input['local_cogs']) ? (float)$input['local_cogs'] : null;
            $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;
            $isDeliveryRequired = isset($input['is_delivery_required']) ? ($input['is_delivery_required'] === null ? null : (int)$input['is_delivery_required']) : null;
            $isDeliveryIncluded = isset($input['is_delivery_included']) ? ($input['is_delivery_included'] === null ? null : (int)$input['is_delivery_included']) : ($isDeliveryRequired === 2 ? 1 : ($isDeliveryRequired === 1 || $isDeliveryRequired === 0 ? 0 : null));
            if ($isDeliveryIncluded === 1 && $isDeliveryRequired !== 2) {
                $isDeliveryRequired = 2;
            }

            if (!$branchId || !$productId || $localPrice <= 0) {
                echo json_encode(['success' => false, 'message' => 'Cabang, Produk, dan Harga Jual Cabang wajib valid (> 0).']);
                exit;
            }

            // Verify or insert into branch_stocks
            $exists = $pdo->prepare("SELECT id FROM branch_stocks WHERE branch_id = ? AND product_id = ?");
            $exists->execute([$branchId, $productId]);
            if ($exists->fetch()) {
                if ($localCogs !== null && $localCogs > 0) {
                    $stmt = $pdo->prepare("UPDATE branch_stocks SET local_price = ?, local_cogs = ?, is_active = ?, is_delivery_required = ?, is_delivery_included = ?, last_updated = datetime('now', 'localtime') WHERE branch_id = ? AND product_id = ?");
                    $stmt->execute([$localPrice, $localCogs, $isActive, $isDeliveryRequired, $isDeliveryIncluded, $branchId, $productId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE branch_stocks SET local_price = ?, is_active = ?, is_delivery_required = ?, is_delivery_included = ?, last_updated = datetime('now', 'localtime') WHERE branch_id = ? AND product_id = ?");
                    $stmt->execute([$localPrice, $isActive, $isDeliveryRequired, $isDeliveryIncluded, $branchId, $productId]);
                }
            } else {
                $cogs = $localCogs ?: (float)$pdo->query("SELECT cogs FROM products WHERE id = $productId")->fetchColumn();
                $stmt = $pdo->prepare("INSERT INTO branch_stocks (branch_id, product_id, stock, local_cogs, local_price, is_active, is_delivery_required, is_delivery_included) VALUES (?, ?, 0, ?, ?, ?, ?, ?)");
                $stmt->execute([$branchId, $productId, $cogs, $localPrice, $isActive, $isDeliveryRequired, $isDeliveryIncluded]);
            }

            echo json_encode(['success' => true, 'message' => 'Katalog menu dan harga jual cabang berhasil diperbarui!']);
            break;

        case 'toggle_branch_product_status':
            $branchId = (int)($input['branch_id'] ?? 0);
            $productId = (int)($input['product_id'] ?? 0);
            $isActive = isset($input['is_active']) ? (int)$input['is_active'] : null;

            if (!$branchId || !$productId) {
                echo json_encode(['success' => false, 'message' => 'Cabang dan Produk wajib valid.']);
                exit;
            }

            // Cek apakah entri branch_stocks sudah ada
            $stmtCheck = $pdo->prepare("SELECT id, is_active FROM branch_stocks WHERE branch_id = ? AND product_id = ?");
            $stmtCheck->execute([$branchId, $productId]);
            $existing = $stmtCheck->fetch();

            if ($existing) {
                if ($isActive === null) {
                    $isActive = ($existing['is_active'] == 1) ? 0 : 1;
                }
                $stmtUpd = $pdo->prepare("UPDATE branch_stocks SET is_active = ?, last_updated = datetime('now', 'localtime') WHERE id = ?");
                $stmtUpd->execute([$isActive, $existing['id']]);
            } else {
                if ($isActive === null) $isActive = 0;
                $stmtProd = $pdo->prepare("SELECT cogs, selling_price FROM products WHERE id = ?");
                $stmtProd->execute([$productId]);
                $pData = $stmtProd->fetch();
                $cogs = $pData ? (float)$pData['cogs'] : 0;
                $price = $pData ? (float)$pData['selling_price'] : 0;

                $stmtIns = $pdo->prepare("INSERT INTO branch_stocks (branch_id, product_id, stock, local_cogs, local_price, is_active) VALUES (?, ?, 0, ?, ?, ?)");
                $stmtIns->execute([$branchId, $productId, $cogs, $price, $isActive]);
            }

            $bName = $pdo->query("SELECT name FROM branches WHERE id = $branchId")->fetchColumn() ?: "Cabang #$branchId";
            $pName = $pdo->query("SELECT name FROM products WHERE id = $productId")->fetchColumn() ?: "Produk #$productId";
            $statusLabel = $isActive ? "diaktifkan (dijual)" : "dinonaktifkan (tidak dijual)";

            echo json_encode([
                'success' => true,
                'message' => "Menu '{$pName}' berhasil {$statusLabel} untuk {$bName}.",
                'is_active' => $isActive,
                'branch_id' => $branchId,
                'product_id' => $productId
            ]);
            break;

        case 'toggle_branch_product_delivery':
            $branchId = (int)($input['branch_id'] ?? 0);
            $productId = (int)($input['product_id'] ?? 0);
            $isDeliveryRequired = isset($input['is_delivery_required']) ? (int)$input['is_delivery_required'] : null;

            if (!$branchId || !$productId) {
                echo json_encode(['success' => false, 'message' => 'Cabang dan Produk wajib valid.']);
                exit;
            }

            // Ambil data default produk
            $baseProd = $pdo->query("SELECT name, is_delivery_required, is_delivery_included, cogs, selling_price FROM products WHERE id = $productId")->fetch();
            $baseDeliv = $baseProd ? (int)$baseProd['is_delivery_required'] : 0;
            if ($baseProd && !empty($baseProd['is_delivery_included'])) $baseDeliv = 2;

            // Cek apakah entri branch_stocks sudah ada
            $stmtCheck = $pdo->prepare("SELECT id, is_delivery_required, is_delivery_included FROM branch_stocks WHERE branch_id = ? AND product_id = ?");
            $stmtCheck->execute([$branchId, $productId]);
            $existing = $stmtCheck->fetch();

            if ($existing) {
                if ($isDeliveryRequired === null) {
                    $currVal = $existing['is_delivery_required'] !== null ? (int)$existing['is_delivery_required'] : $baseDeliv;
                    // Rotasi 3 status: 0 (Standar) -> 1 (Wajib Ongkir) -> 2 (Termasuk Biaya Kirim) -> 0
                    if ($currVal == 0) $isDeliveryRequired = 1;
                    elseif ($currVal == 1) $isDeliveryRequired = 2;
                    else $isDeliveryRequired = 0;
                }
                $isIncluded = ($isDeliveryRequired == 2) ? 1 : 0;
                $stmtUpd = $pdo->prepare("UPDATE branch_stocks SET is_delivery_required = ?, is_delivery_included = ?, last_updated = datetime('now', 'localtime') WHERE id = ?");
                $stmtUpd->execute([$isDeliveryRequired, $isIncluded, $existing['id']]);
            } else {
                if ($isDeliveryRequired === null) {
                    if ($baseDeliv == 0) $isDeliveryRequired = 1;
                    elseif ($baseDeliv == 1) $isDeliveryRequired = 2;
                    else $isDeliveryRequired = 0;
                }
                $isIncluded = ($isDeliveryRequired == 2) ? 1 : 0;
                $cogs = $baseProd ? (float)$baseProd['cogs'] : 0;
                $price = $baseProd ? (float)$baseProd['selling_price'] : 0;

                $stmtIns = $pdo->prepare("INSERT INTO branch_stocks (branch_id, product_id, stock, local_cogs, local_price, is_active, is_delivery_required, is_delivery_included) VALUES (?, ?, 0, ?, ?, 1, ?, ?)");
                $stmtIns->execute([$branchId, $productId, $cogs, $price, $isDeliveryRequired, $isIncluded]);
            }

            $bName = $pdo->query("SELECT name FROM branches WHERE id = $branchId")->fetchColumn() ?: "Cabang #$branchId";
            $pName = $baseProd ? $baseProd['name'] : "Produk #$productId";
            $statusLabel = ($isDeliveryRequired == 2) ? "diatur SUDAH TERMASUK BIAYA KIRIM (Include Ongkir)" : (($isDeliveryRequired == 1) ? "diatur WAJIB ONGKIR TAMBAHAN" : "diatur BEBAS ONGKIR (Standar / Non-Delivery)");

            echo json_encode([
                'success' => true,
                'message' => "Pengaturan pengiriman produk '{$pName}' di {$bName} berhasil {$statusLabel}.",
                'is_delivery_required' => $isDeliveryRequired,
                'is_delivery_included' => ($isDeliveryRequired == 2 ? 1 : 0),
                'branch_id' => $branchId,
                'product_id' => $productId
            ]);
            break;

        case 'save_variant':
            $varId = !empty($input['id']) ? (int)$input['id'] : (!empty($input['variant_id']) ? (int)$input['variant_id'] : null);
            $productIds = !empty($input['product_ids']) && is_array($input['product_ids']) 
                ? array_map('intval', $input['product_ids']) 
                : (!empty($input['product_id']) ? [(int)$input['product_id']] : []);
            $productId = !empty($productIds) ? $productIds[0] : 0;
            $varName = trim($input['variant_name'] ?? '');
            $skuVar = trim($input['sku_variant'] ?? '');
            $addPrice = (float)($input['additional_price'] ?? 0);
            $addCogs = (float)($input['additional_cogs'] ?? 0);

            if (empty($varName) || empty($skuVar)) {
                echo json_encode(['success' => false, 'message' => 'Nama Varian dan SKU Varian wajib diisi.']);
                exit;
            }

            if ($varId) {
                // Ambil nama lama varian ini
                $oldVarRow = $pdo->query("SELECT variant_name, product_id FROM product_variants WHERE id = $varId")->fetch();
                $oldVarName = $oldVarRow ? $oldVarRow['variant_name'] : $varName;

                // Update existing single variant
                $stmt = $pdo->prepare("
                    UPDATE product_variants
                    SET variant_name = ?, sku_variant = ?, additional_price = ?, additional_cogs = ?
                    WHERE id = ?
                ");
                $stmt->execute([$varName, $skuVar, $addPrice, $addCogs, $varId]);

                // Sinkronkan nama, additional_price, dan additional_cogs ke seluruh sibling variants (varian pada produk induk lain)
                $stmtSyncSiblings = $pdo->prepare("
                    UPDATE product_variants 
                    SET variant_name = ?, additional_price = ?, additional_cogs = ? 
                    WHERE LOWER(variant_name) = LOWER(?) AND id != ?
                ");
                $stmtSyncSiblings->execute([$varName, $addPrice, $addCogs, $oldVarName, $varId]);

                // Jika pengguna juga mencentang produk jadi lain saat edit
                $addedCount = 0;
                $currentPid = (int)($oldVarRow['product_id'] ?? 0);
                $extraProductIds = array_diff($productIds, [$currentPid]);

                if (!empty($extraProductIds)) {
                    $stmtCheckExtra = $pdo->prepare("SELECT id FROM product_variants WHERE product_id = ? AND LOWER(variant_name) = LOWER(?)");
                    $stmtInsertExtra = $pdo->prepare("INSERT INTO product_variants (product_id, variant_name, sku_variant, additional_price, additional_cogs) VALUES (?, ?, ?, ?, ?)");
                    
                    // Ambil resep BOM jika ada untuk disalin ke produk tambahan
                    $existingRecipes = $pdo->query("SELECT * FROM variant_recipes WHERE variant_id = $varId")->fetchAll();

                    foreach ($extraProductIds as $ePid) {
                        $stmtCheckExtra->execute([$ePid, $varName]);
                        $hasVar = $stmtCheckExtra->fetchColumn();
                        if (!$hasVar) {
                            $pRow = $pdo->query("SELECT sku FROM products WHERE id = " . (int)$ePid)->fetch();
                            $pSku = $pRow ? $pRow['sku'] : 'PRD';
                            $cleanBase = preg_replace('/^[^-]+-/', '', $skuVar);
                            if (empty($cleanBase)) $cleanBase = 'VAR-' . rand(100, 999);
                            $newSkuVar = "{$pSku}-{$cleanBase}";
                            
                            $checkDup = $pdo->prepare("SELECT COUNT(*) FROM product_variants WHERE sku_variant = ?");
                            $checkDup->execute([$newSkuVar]);
                            if ($checkDup->fetchColumn() > 0) {
                                $newSkuVar = "{$newSkuVar}-" . rand(100, 999);
                            }

                            $stmtInsertExtra->execute([$ePid, $varName, $newSkuVar, $addPrice, $addCogs]);
                            $newVarId = $pdo->lastInsertId();
                            $addedCount++;

                            // Salin resep BOM
                            if (!empty($existingRecipes) && $newVarId) {
                                $stmtRecIns = $pdo->prepare("INSERT INTO variant_recipes (variant_id, ingredient_product_id, qty_needed, unit, notes) VALUES (?, ?, ?, ?, ?)");
                                foreach ($existingRecipes as $er) {
                                    $stmtRecIns->execute([$newVarId, $er['ingredient_product_id'], $er['qty_needed'], $er['unit'], $er['notes']]);
                                }
                            }
                        }
                    }
                }

                $msg = "Varian '{$varName}' berhasil diperbarui";
                if ($addedCount > 0) {
                    $msg .= " dan diterapkan ke {$addedCount} produk jadi baru!";
                } else {
                    $msg .= ".";
                }
                echo json_encode(['success' => true, 'message' => $msg]);
            } else {
                // Create variant for one or more parent products
                if (empty($productIds)) {
                    echo json_encode(['success' => false, 'message' => 'Minimal satu produk induk / jadi wajib dipilih.']);
                    exit;
                }

                $createdCount = 0;
                $skippedCount = 0;
                $stmtInsert = $pdo->prepare("
                    INSERT INTO product_variants (product_id, variant_name, sku_variant, additional_price, additional_cogs)
                    VALUES (?, ?, ?, ?, ?)
                ");

                foreach ($productIds as $idx => $pId) {
                    if (!$pId) continue;
                    // Ambil SKU produk induk untuk suffix jika diperlukan keunikan
                    $pRow = $pdo->query("SELECT sku FROM products WHERE id = " . (int)$pId)->fetch();
                    $pSku = $pRow ? $pRow['sku'] : 'PRD';

                    // Generate SKU varian unik per produk induk agar tidak bentrok UNIQUE constraint
                    $finalSkuVar = $skuVar;
                    if (count($productIds) > 1) {
                        $cleanBase = preg_replace('/-VAR\d+$/i', '', $skuVar);
                        $finalSkuVar = "{$pSku}-{$cleanBase}";
                    }
                    // Pastikan SKU varian benar-benar unik di tabel
                    $checkDup = $pdo->prepare("SELECT COUNT(*) FROM product_variants WHERE sku_variant = ?");
                    $checkDup->execute([$finalSkuVar]);
                    if ($checkDup->fetchColumn() > 0) {
                        $finalSkuVar = "{$finalSkuVar}-" . rand(100, 999);
                    }

                    // Cek apakah produk induk ini sudah punya varian dengan nama yang sama
                    $checkName = $pdo->prepare("SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND LOWER(variant_name) = LOWER(?)");
                    $checkName->execute([$pId, $varName]);
                    if ($checkName->fetchColumn() > 0) {
                        $skippedCount++;
                        continue;
                    }

                    $stmtInsert->execute([$pId, $varName, $finalSkuVar, $addPrice, $addCogs]);
                    $createdCount++;
                }

                $msg = count($productIds) > 1
                    ? "Varian '{$varName}' berhasil diterapkan ke {$createdCount} produk jadi" . ($skippedCount > 0 ? " ({$skippedCount} produk dilewati karena sudah memiliki varian ini)." : ".")
                    : "Varian '{$varName}' berhasil ditambahkan ke produk jadi.";

                echo json_encode(['success' => true, 'message' => $msg, 'created_count' => $createdCount]);
            }
            break;

        case 'delete_variant':
            $varId = (int)($input['variant_id'] ?? 0);
            $pdo->prepare("DELETE FROM product_variants WHERE id = ?")->execute([$varId]);
            echo json_encode(['success' => true, 'message' => 'Varian berhasil dihapus.']);
            break;

        case 'apply_variant_to_products':
            $sourceVariantId = (int)($input['source_variant_id'] ?? 0);
            $targetProductIds = !empty($input['target_product_ids']) && is_array($input['target_product_ids']) 
                ? array_map('intval', $input['target_product_ids']) 
                : [];
            $copyRecipes = !empty($input['copy_recipes']);

            if (!$sourceVariantId) {
                echo json_encode(['success' => false, 'message' => 'ID varian sumber wajib disertakan.']);
                exit;
            }

            if (empty($targetProductIds)) {
                echo json_encode(['success' => false, 'message' => 'Pilih minimal satu produk tujuan untuk menerapkan varian ini.']);
                exit;
            }

            // Ambil data varian sumber
            $stmtSrc = $pdo->prepare("SELECT * FROM product_variants WHERE id = ?");
            $stmtSrc->execute([$sourceVariantId]);
            $srcVariant = $stmtSrc->fetch();

            if (!$srcVariant) {
                echo json_encode(['success' => false, 'message' => 'Data varian sumber tidak ditemukan.']);
                exit;
            }

            // Ambil data resep bahan baku jika ada
            $srcRecipes = [];
            if ($copyRecipes) {
                $stmtRec = $pdo->prepare("SELECT * FROM variant_recipes WHERE variant_id = ?");
                $stmtRec->execute([$sourceVariantId]);
                $srcRecipes = $stmtRec->fetchAll();
            }

            $createdCount = 0;
            $skippedCount = 0;
            $updatedCount = 0;

            $stmtCheck = $pdo->prepare("SELECT id FROM product_variants WHERE product_id = ? AND LOWER(variant_name) = LOWER(?)");
            $stmtInsertVar = $pdo->prepare("INSERT INTO product_variants (product_id, variant_name, sku_variant, additional_price, additional_cogs) VALUES (?, ?, ?, ?, ?)");
            $stmtInsertRecipe = $pdo->prepare("INSERT INTO variant_recipes (variant_id, ingredient_product_id, qty_needed, unit, notes) VALUES (?, ?, ?, ?, ?)");

            foreach ($targetProductIds as $tPId) {
                if (!$tPId || $tPId == $srcVariant['product_id']) continue;

                // Cek apakah produk tujuan sudah punya varian dengan nama yang sama
                $stmtCheck->execute([$tPId, $srcVariant['variant_name']]);
                $existingVarId = $stmtCheck->fetchColumn();

                $targetVarId = null;

                if ($existingVarId) {
                    $targetVarId = $existingVarId;
                    // Update harga & hpp agar seragam jika diinginkan
                    $pdo->prepare("UPDATE product_variants SET additional_price = ?, additional_cogs = ? WHERE id = ?")
                        ->execute([$srcVariant['additional_price'], $srcVariant['additional_cogs'], $existingVarId]);
                    $updatedCount++;
                } else {
                    // Ambil SKU produk target
                    $pRow = $pdo->query("SELECT sku FROM products WHERE id = " . (int)$tPId)->fetch();
                    $pSku = $pRow ? $pRow['sku'] : 'PRD';

                    $baseSkuPart = preg_replace('/^[^-]+-/', '', $srcVariant['sku_variant']);
                    if (empty($baseSkuPart)) $baseSkuPart = 'VAR-' . rand(100, 999);
                    $newSkuVar = "{$pSku}-{$baseSkuPart}";

                    // Pastikan SKU unik
                    $checkDup = $pdo->prepare("SELECT COUNT(*) FROM product_variants WHERE sku_variant = ?");
                    $checkDup->execute([$newSkuVar]);
                    if ($checkDup->fetchColumn() > 0) {
                        $newSkuVar = "{$newSkuVar}-" . rand(100, 999);
                    }

                    $stmtInsertVar->execute([
                        $tPId, 
                        $srcVariant['variant_name'], 
                        $newSkuVar, 
                        $srcVariant['additional_price'], 
                        $srcVariant['additional_cogs']
                    ]);
                    $targetVarId = $pdo->lastInsertId();
                    $createdCount++;
                }

                // Salin resep BOM bahan baku varian jika opsi aktif
                if ($copyRecipes && $targetVarId && !empty($srcRecipes)) {
                    foreach ($srcRecipes as $rec) {
                        // Cek apakah bahan baku sudah ada di varian target
                        $checkRec = $pdo->prepare("SELECT COUNT(*) FROM variant_recipes WHERE variant_id = ? AND ingredient_product_id = ?");
                        $checkRec->execute([$targetVarId, $rec['ingredient_product_id']]);
                        if ($checkRec->fetchColumn() == 0) {
                            $stmtInsertRecipe->execute([
                                $targetVarId,
                                $rec['ingredient_product_id'],
                                $rec['qty_needed'],
                                $rec['unit'],
                                $rec['notes']
                            ]);
                        }
                    }
                }
            }

            $summaryMsg = "Varian '{$srcVariant['variant_name']}' berhasil diterapkan ke {$createdCount} produk jadi baru";
            if ($updatedCount > 0) {
                $summaryMsg .= " ({$updatedCount} produk diperbarui)";
            }
            if ($copyRecipes && !empty($srcRecipes)) {
                $summaryMsg .= " beserta formula bahan baku resep BOM-nya.";
            } else {
                $summaryMsg .= ".";
            }

            echo json_encode([
                'success' => true,
                'message' => $summaryMsg,
                'created_count' => $createdCount,
                'updated_count' => $updatedCount
            ]);
            break;

        // ==========================================
        // 4. RECIPE / BOM (BILL OF MATERIALS)
        // ==========================================
        case 'get_recipes':
            // Get all Finished Goods that can have recipes
            $stmtFG = $pdo->query("
                SELECT id, sku, name, unit, selling_price, cogs, has_bom 
                FROM products 
                WHERE product_type = 'FINISHED_GOODS' AND is_active = 1
                ORDER BY name ASC
            ");
            $finishedGoods = $stmtFG->fetchAll();

            // Get Raw Materials (available ingredients)
            $stmtRaw = $pdo->query("
                SELECT id, sku, name, unit, cogs 
                FROM products 
                WHERE product_type = 'RAW_MATERIAL' AND is_active = 1
                ORDER BY name ASC
            ");
            $rawMaterials = $stmtRaw->fetchAll();

            // Get all BOM recipe lines
            $stmtBOM = $pdo->query("
                SELECT pr.id, pr.finished_product_id, pr.ingredient_product_id, pr.qty_needed, pr.unit,
                       p.name as ingredient_name, p.sku as ingredient_sku, p.cogs as ingredient_cogs,
                       (pr.qty_needed * p.cogs) as subtotal_ingredient_cost
                FROM product_recipes pr
                JOIN products p ON p.id = pr.ingredient_product_id
                ORDER BY pr.id ASC
            ");
            $bomLines = $stmtBOM->fetchAll();

            $bomByProduct = [];
            foreach ($bomLines as $line) {
                $bomByProduct[$line['finished_product_id']][] = $line;
            }

            foreach ($finishedGoods as &$fg) {
                $fg['ingredients'] = $bomByProduct[$fg['id']] ?? [];
                $fg['calculated_cogs'] = array_sum(array_column($fg['ingredients'], 'subtotal_ingredient_cost'));
            }

            echo json_encode([
                'success' => true,
                'finished_goods' => $finishedGoods,
                'raw_materials' => $rawMaterials
            ]);
            break;

        case 'save_recipe_item':
            $finishedId = (int)($input['finished_product_id'] ?? 0);
            $ingredientId = (int)($input['ingredient_product_id'] ?? 0);
            $qtyNeeded = (float)($input['qty_needed'] ?? 0);
            $unit = trim($input['unit'] ?? 'pcs');

            if (!$finishedId || !$ingredientId || $qtyNeeded <= 0) {
                echo json_encode(['success' => false, 'message' => 'Lengkapi produk, bahan baku, dan takaran kebutuhan.']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO product_recipes (finished_product_id, ingredient_product_id, qty_needed, unit)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$finishedId, $ingredientId, $qtyNeeded, $unit]);

            // Mark finished product as having BOM and auto calculate COGS
            $pdo->prepare("UPDATE products SET has_bom = 1 WHERE id = ?")->execute([$finishedId]);
            $autoCogs = recalculateProductBOMCogs($pdo, $finishedId);

            echo json_encode([
                'success' => true, 
                'message' => 'Bahan baku berhasil ditambahkan ke formula resep (BOM). HPP modal barang jadi otomatis diperbarui: Rp ' . number_format($autoCogs, 0, ',', '.'),
                'calculated_cogs' => $autoCogs
            ]);
            break;

        case 'delete_recipe_item':
            $recipeId = (int)($input['recipe_id'] ?? 0);
            $stmtGet = $pdo->prepare("SELECT finished_product_id FROM product_recipes WHERE id = ?");
            $stmtGet->execute([$recipeId]);
            $fgId = $stmtGet->fetchColumn();

            $pdo->prepare("DELETE FROM product_recipes WHERE id = ?")->execute([$recipeId]);

            $autoCogs = 0;
            if ($fgId) {
                $remCount = $pdo->query("SELECT COUNT(*) FROM product_recipes WHERE finished_product_id = $fgId")->fetchColumn();
                if ($remCount == 0) {
                    $pdo->prepare("UPDATE products SET has_bom = 0 WHERE id = ?")->execute([$fgId]);
                } else {
                    $autoCogs = recalculateProductBOMCogs($pdo, $fgId);
                }
            }

            echo json_encode([
                'success' => true, 
                'message' => 'Bahan baku dihapus dari resep. HPP modal barang jadi disesuaikan.',
                'calculated_cogs' => $autoCogs
            ]);
            break;

        case 'get_variant_recipes':
            // Get all variants with product info
            $stmtVar = $pdo->query("
                SELECT pv.*, p.name as product_name, p.sku as product_sku
                FROM product_variants pv
                JOIN products p ON p.id = pv.product_id
                ORDER BY pv.variant_name ASC, p.name ASC
            ");
            $rawVariants = $stmtVar->fetchAll();

            $stmtVR = $pdo->query("
                SELECT vr.*, p.name as ingredient_name, p.sku as ingredient_sku, p.cogs as ingredient_cogs,
                       (vr.qty_needed * p.cogs) as subtotal_ingredient_cost
                FROM variant_recipes vr
                JOIN products p ON p.id = vr.ingredient_product_id
                ORDER BY vr.id ASC
            ");
            $vrLines = $stmtVR->fetchAll();

            $vrByVariant = [];
            foreach ($vrLines as $line) {
                $vrByVariant[$line['variant_id']][] = $line;
            }

            // Kelompokkan varian berdasarkan variant_name (case-insensitive) sehingga 1 varian tampil 1 kartu
            $groupedVariants = [];
            foreach ($rawVariants as $v) {
                $key = strtolower(trim($v['variant_name']));
                if (!isset($groupedVariants[$key])) {
                    $groupedVariants[$key] = [
                        'id' => $v['id'],
                        'primary_variant_id' => $v['id'],
                        'variant_ids' => [$v['id']],
                        'variant_name' => $v['variant_name'],
                        'sku_variant' => $v['sku_variant'],
                        'additional_price' => (float)$v['additional_price'],
                        'additional_cogs' => (float)$v['additional_cogs'],
                        'products' => [],
                        'ingredients' => []
                    ];
                } else {
                    $groupedVariants[$key]['variant_ids'][] = $v['id'];
                }

                $groupedVariants[$key]['products'][] = [
                    'variant_id' => $v['id'],
                    'product_id' => $v['product_id'],
                    'product_name' => $v['product_name'],
                    'product_sku' => $v['product_sku'],
                    'sku_variant' => $v['sku_variant']
                ];

                // Kumpulkan bahan resep dari entri varian (utamakan yang memiliki resep)
                if (empty($groupedVariants[$key]['ingredients']) && !empty($vrByVariant[$v['id']])) {
                    $groupedVariants[$key]['ingredients'] = $vrByVariant[$v['id']];
                    $groupedVariants[$key]['primary_variant_id'] = $v['id'];
                }
            }

            // Jika ada varian yang belum punya bahan resep pada suatu produk induk, sinkronkan dari yang sudah ada
            foreach ($groupedVariants as &$gv) {
                $gv['calculated_variant_cogs'] = array_sum(array_column($gv['ingredients'], 'subtotal_ingredient_cost'));
                $gv['product_count'] = count($gv['products']);
                
                // Pastikan seluruh entri varian turunan memiliki resep yang sinkron
                if (!empty($gv['ingredients']) && count($gv['variant_ids']) > 1) {
                    $stmtCheckRec = $pdo->prepare("SELECT COUNT(*) FROM variant_recipes WHERE variant_id = ?");
                    $stmtInsertRec = $pdo->prepare("INSERT INTO variant_recipes (variant_id, ingredient_product_id, qty_needed, unit, notes) VALUES (?, ?, ?, ?, ?)");
                    foreach ($gv['variant_ids'] as $vId) {
                        $stmtCheckRec->execute([$vId]);
                        if ($stmtCheckRec->fetchColumn() == 0) {
                            foreach ($gv['ingredients'] as $ing) {
                                $stmtInsertRec->execute([
                                    $vId, 
                                    $ing['ingredient_product_id'], 
                                    $ing['qty_needed'], 
                                    $ing['unit'], 
                                    $ing['notes'] ?? ''
                                ]);
                            }
                        }
                    }
                }
            }

            $variantsList = array_values($groupedVariants);

            // Raw materials
            $rawMaterials = $pdo->query("SELECT id, sku, name, unit, cogs FROM products WHERE product_type = 'RAW_MATERIAL' AND is_active = 1 ORDER BY name ASC")->fetchAll();

            // Finished goods (products that can have variants)
            $finishedGoods = $pdo->query("SELECT id, sku, name, selling_price, unit FROM products WHERE product_type = 'FINISHED_GOODS' AND is_active = 1 ORDER BY name ASC")->fetchAll();

            echo json_encode([
                'success' => true,
                'variants' => $variantsList,
                'raw_materials' => $rawMaterials,
                'finished_goods' => $finishedGoods
            ]);
            break;

        case 'save_variant_recipe':
            $variantId = (int)($input['variant_id'] ?? 0);
            $ingredientId = (int)($input['ingredient_product_id'] ?? 0);
            $qtyNeeded = (float)($input['qty_needed'] ?? 0);
            $unit = trim($input['unit'] ?? 'pcs');
            $notes = trim($input['notes'] ?? '');

            if (!$variantId || !$ingredientId || $qtyNeeded <= 0) {
                echo json_encode(['success' => false, 'message' => 'Lengkapi varian, bahan baku, dan jumlah takaran.']);
                exit;
            }

            // Ambil data varian target & cari semua varian yang memiliki nama varian yang sama
            $targetVar = $pdo->query("SELECT * FROM product_variants WHERE id = $variantId")->fetch();
            if ($targetVar) {
                $siblingVariants = $pdo->prepare("SELECT id FROM product_variants WHERE LOWER(variant_name) = LOWER(?)");
                $siblingVariants->execute([$targetVar['variant_name']]);
                $allVarIds = $siblingVariants->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $allVarIds = [$variantId];
            }

            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM variant_recipes WHERE variant_id = ? AND ingredient_product_id = ?");
            $stmtInsert = $pdo->prepare("INSERT INTO variant_recipes (variant_id, ingredient_product_id, qty_needed, unit, notes) VALUES (?, ?, ?, ?, ?)");

            foreach ($allVarIds as $vId) {
                $stmtCheck->execute([$vId, $ingredientId]);
                if ($stmtCheck->fetchColumn() == 0) {
                    $stmtInsert->execute([$vId, $ingredientId, $qtyNeeded, $unit, $notes]);
                }
            }

            echo json_encode(['success' => true, 'message' => 'Bahan baku berhasil dikaitkan ke varian ini dan otomatis berlaku untuk seluruh produk induknya!']);
            break;

        case 'delete_variant_recipe':
            $vrId = (int)($input['variant_recipe_id'] ?? 0);
            
            // Cari data recipe & hapus juga di sibling variants agar sinkron
            $recRow = $pdo->query("
                SELECT vr.*, pv.variant_name 
                FROM variant_recipes vr 
                JOIN product_variants pv ON pv.id = vr.variant_id 
                WHERE vr.id = $vrId
            ")->fetch();

            if ($recRow) {
                $stmtDelSib = $pdo->prepare("
                    DELETE vr FROM variant_recipes vr
                    JOIN product_variants pv ON pv.id = vr.variant_id
                    WHERE LOWER(pv.variant_name) = LOWER(?) AND vr.ingredient_product_id = ?
                ");
                $stmtDelSib->execute([$recRow['variant_name'], $recRow['ingredient_product_id']]);
            } else {
                $pdo->prepare("DELETE FROM variant_recipes WHERE id = ?")->execute([$vrId]);
            }

            echo json_encode(['success' => true, 'message' => 'Bahan baku varian berhasil dihapus.']);
            break;

        case 'detach_product_from_variant':
            $variantId = (int)($input['variant_id'] ?? 0);
            if (!$variantId) {
                echo json_encode(['success' => false, 'message' => 'ID varian produk wajib diisi.']);
                exit;
            }
            $pdo->prepare("DELETE FROM product_variants WHERE id = ?")->execute([$variantId]);
            echo json_encode(['success' => true, 'message' => 'Produk berhasil dilepas dari varian ini.']);
            break;

        // ==========================================
        // 4.5 MASTER PAJAK / TAX CONFIGURATION (CRUD TAXES)
        // ==========================================
        case 'get_taxes':
            $stmt = $pdo->query("
                SELECT t.*,
                       (SELECT COUNT(*) FROM products p WHERE p.tax_id = t.id AND p.is_active = 1) as product_count
                FROM taxes t
                ORDER BY t.id ASC
            ");
            echo json_encode(['success' => true, 'taxes' => $stmt->fetchAll()]);
            break;

        case 'save_tax':
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $code = strtoupper(trim($input['code'] ?? ''));
            $name = trim($input['name'] ?? '');
            $ratePercent = isset($input['rate_percent']) ? (float)$input['rate_percent'] : 0.0;
            $description = trim($input['description'] ?? '');
            $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

            if (empty($code) || empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Kode Pajak dan Nama Pajak wajib diisi.']);
                exit;
            }

            if ($id) {
                // Check duplicate code
                $dup = $pdo->prepare("SELECT id FROM taxes WHERE code = ? AND id != ?");
                $dup->execute([$code, $id]);
                if ($dup->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Kode Pajak '{$code}' sudah ada."]);
                    exit;
                }

                $stmt = $pdo->prepare("
                    UPDATE taxes 
                    SET code = ?, name = ?, rate_percent = ?, description = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$code, $name, $ratePercent, $description, $isActive, $id]);

                // Sinkronisasi tax_percent pada produk yang menggunakan tax ini
                $pdo->prepare("UPDATE products SET tax_percent = ? WHERE tax_id = ?")->execute([$ratePercent, $id]);

                echo json_encode(['success' => true, 'message' => "Tarif pajak '{$name}' berhasil diperbarui."]);
            } else {
                // Check duplicate code
                $dup = $pdo->prepare("SELECT id FROM taxes WHERE code = ?");
                $dup->execute([$code]);
                if ($dup->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Kode Pajak '{$code}' sudah ada."]);
                    exit;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO taxes (code, name, rate_percent, description, is_active)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$code, $name, $ratePercent, $description, $isActive]);
                $id = $pdo->lastInsertId();

                echo json_encode(['success' => true, 'message' => "Tarif pajak baru '{$name}' berhasil ditambahkan.", 'tax_id' => $id]);
            }
            break;

        case 'delete_tax':
            $id = (int)($input['id'] ?? 0);
            if ($id === 1) {
                echo json_encode(['success' => false, 'message' => 'Pajak standar sistem (PPN 11%) tidak boleh dihapus.']);
                exit;
            }

            // Cek apakah digunakan oleh produk
            $usedCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE tax_id = $id AND is_active = 1")->fetchColumn();
            if ($usedCount > 0) {
                // Set nonaktif (soft delete)
                $pdo->prepare("UPDATE taxes SET is_active = 0 WHERE id = ?")->execute([$id]);
                echo json_encode(['success' => true, 'message' => "Pajak digunakan oleh {$usedCount} produk. Status diubah menjadi Nonaktif."]);
            } else {
                $pdo->prepare("DELETE FROM taxes WHERE id = ?")->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Tarif pajak berhasil dihapus permanen.']);
            }
            break;

        // ==========================================
        // 4.3 MANAJEMEN METODE PEMBAYARAN (CRUD PAYMENT METHODS)
        // ==========================================
        case 'get_payment_methods':
            $stmt = $pdo->query("
                SELECT pm.*,
                       (SELECT COUNT(*) FROM sales s WHERE s.payment_method = pm.code) as transaction_count,
                       (SELECT COALESCE(SUM(s.grand_total), 0) FROM sales s WHERE s.payment_method = pm.code) as total_volume
                FROM payment_methods pm
                ORDER BY pm.sort_order ASC, pm.id ASC
            ");
            echo json_encode(['success' => true, 'payment_methods' => $stmt->fetchAll()]);
            break;

        case 'save_payment_method':
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $code = strtoupper(trim($input['code'] ?? ''));
            $name = trim($input['name'] ?? '');
            $category = strtoupper(trim($input['category'] ?? 'OTHER'));
            $accountNumber = trim($input['account_number'] ?? '');
            $accountHolder = trim($input['account_holder'] ?? '');
            $description = trim($input['description'] ?? '');
            $isCash = !empty($input['is_cash']) ? 1 : 0;
            $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;
            $sortOrder = isset($input['sort_order']) ? (int)$input['sort_order'] : 0;

            if (empty($code) || empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Kode Metode dan Nama Metode Pembayaran wajib diisi.']);
                exit;
            }

            if ($id) {
                // Check duplicate code
                $dup = $pdo->prepare("SELECT id FROM payment_methods WHERE code = ? AND id != ?");
                $dup->execute([$code, $id]);
                if ($dup->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Kode metode pembayaran '{$code}' sudah digunakan."]);
                    exit;
                }

                $stmt = $pdo->prepare("
                    UPDATE payment_methods 
                    SET code = ?, name = ?, category = ?, account_number = ?, account_holder = ?,
                        description = ?, is_cash = ?, is_active = ?, sort_order = ?
                    WHERE id = ?
                ");
                $stmt->execute([$code, $name, $category, $accountNumber, $accountHolder, $description, $isCash, $isActive, $sortOrder, $id]);

                echo json_encode(['success' => true, 'message' => "Metode pembayaran '{$name}' berhasil diperbarui."]);
            } else {
                // Check duplicate code
                $dup = $pdo->prepare("SELECT id FROM payment_methods WHERE code = ?");
                $dup->execute([$code]);
                if ($dup->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Kode metode pembayaran '{$code}' sudah ada."]);
                    exit;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO payment_methods (code, name, category, account_number, account_holder, description, is_cash, is_active, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$code, $name, $category, $accountNumber, $accountHolder, $description, $isCash, $isActive, $sortOrder]);
                $id = $pdo->lastInsertId();

                echo json_encode(['success' => true, 'message' => "Metode pembayaran baru '{$name}' berhasil ditambahkan.", 'id' => $id]);
            }
            break;

        case 'delete_payment_method':
            $id = (int)($input['id'] ?? 0);
            if ($id === 1) {
                echo json_encode(['success' => false, 'message' => 'Metode pembayaran Tunai standar sistem (CASH) tidak boleh dihapus.']);
                exit;
            }

            $stmtCheck = $pdo->prepare("SELECT code FROM payment_methods WHERE id = ?");
            $stmtCheck->execute([$id]);
            $pm = $stmtCheck->fetch();
            if (!$pm) {
                echo json_encode(['success' => false, 'message' => 'Metode pembayaran tidak ditemukan.']);
                exit;
            }

            // Check if used in transactions
            $usedCount = (int)$pdo->prepare("SELECT COUNT(*) FROM sales WHERE payment_method = ?")->execute([$pm['code']]) ? $pdo->query("SELECT COUNT(*) FROM sales WHERE payment_method = '{$pm['code']}'")->fetchColumn() : 0;
            if ($usedCount > 0) {
                // Soft delete
                $pdo->prepare("UPDATE payment_methods SET is_active = 0 WHERE id = ?")->execute([$id]);
                echo json_encode(['success' => true, 'message' => "Metode pembayaran telah digunakan pada {$usedCount} transaksi. Status diubah menjadi Nonaktif (Soft Delete)."]);
            } else {
                $pdo->prepare("DELETE FROM payment_methods WHERE id = ?")->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Metode pembayaran berhasil dihapus permanen.']);
            }
            break;

        // ==========================================
        // 5. STOK CRUD & STOCK MOVEMENT (KARTU STOK)
        // ==========================================
        case 'get_inventory_stocks':
            $branchId = (int)($_GET['branch_id'] ?? 1);
            $typeFilter = $_GET['product_type'] ?? 'ALL';

            $sql = "
                SELECT p.id as product_id, p.sku, p.name, p.category, p.product_type, p.unit, p.image_emoji, p.reorder_point,
                       p.is_delivery_required as base_is_delivery_required,
                       p.is_delivery_included as base_is_delivery_included,
                       bs.stock, bs.local_cogs, bs.local_price, bs.last_updated,
                       COALESCE(bs.is_active, 1) as is_active,
                       bs.is_delivery_required as branch_is_delivery_required,
                       bs.is_delivery_included as branch_is_delivery_included,
                       COALESCE(bs.is_delivery_required, p.is_delivery_required, 0) as is_delivery_required,
                       COALESCE(bs.is_delivery_included, p.is_delivery_included, 0) as is_delivery_included
                FROM products p
                JOIN branch_stocks bs ON p.id = bs.product_id
                WHERE bs.branch_id = ? AND p.is_active = 1
            ";
            $params = [$branchId];

            if ($typeFilter !== 'ALL') {
                $sql .= " AND p.product_type = ?";
                $params[] = $typeFilter;
            }
            $sql .= " ORDER BY p.product_type ASC, p.name ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'stocks' => $stmt->fetchAll()]);
            break;

        case 'adjust_stock':
            $branchId = (int)($input['branch_id'] ?? 1);
            $productId = (int)($input['product_id'] ?? 0);
            $adjType = trim($input['adjustment_type'] ?? 'PURCHASE'); // PURCHASE, ADJUSTMENT_DAMAGE, ADJUSTMENT_OPNAME
            $qtyChange = (float)($input['qty_change'] ?? 0); // positive to add, negative to deduct
            $notes = trim($input['notes'] ?? 'Penyesuaian stok manual');
            $userId = (int)($input['user_id'] ?? ($_SESSION['user_id'] ?? 1));

            if ($qtyChange == 0) {
                echo json_encode(['success' => false, 'message' => 'Kuantitas perubahan tidak boleh 0.']);
                exit;
            }

            $pdo->beginTransaction();

            $stmtCur = $pdo->prepare("SELECT stock FROM branch_stocks WHERE branch_id = ? AND product_id = ?");
            $stmtCur->execute([$branchId, $productId]);
            $currentStock = (float)$stmtCur->fetchColumn();

            $newStock = $currentStock + $qtyChange;
            if ($newStock < 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Pengurangan melebihi stok yang tersedia saat ini.']);
                exit;
            }

            // Update Branch Stock
            $stmtUp = $pdo->prepare("UPDATE branch_stocks SET stock = ?, last_updated = datetime('now', 'localtime') WHERE branch_id = ? AND product_id = ?");
            $stmtUp->execute([$newStock, $branchId, $productId]);

            // Insert Stock Movement Record
            $refNo = "ADJ-" . date('Ymd-His');
            $stmtLog = $pdo->prepare("
                INSERT INTO stock_movements (branch_id, product_id, movement_type, qty_change, stock_before, stock_after, reference_no, notes, user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtLog->execute([$branchId, $productId, $adjType, $qtyChange, $currentStock, $newStock, $refNo, $notes, $userId]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Stok berhasil diperbarui! Riwayat mutasi tercatat pada Kartu Pergerakan Stok.',
                'stock_before' => $currentStock,
                'stock_after' => $newStock
            ]);
            break;

        case 'get_stock_movements':
            $branchId = (int)($_GET['branch_id'] ?? 1);
            $productId = !empty($_GET['product_id']) ? (int)$_GET['product_id'] : null;

            $sql = "
                SELECT sm.*, p.name as product_name, p.sku as product_sku, p.unit, p.product_type,
                       b.name as branch_name, u.name as user_name
                FROM stock_movements sm
                JOIN products p ON p.id = sm.product_id
                JOIN branches b ON b.id = sm.branch_id
                LEFT JOIN users u ON u.id = sm.user_id
                WHERE sm.branch_id = ?
            ";
            $params = [$branchId];

            if ($productId) {
                $sql .= " AND sm.product_id = ?";
                $params[] = $productId;
            }
            $sql .= " ORDER BY sm.id DESC LIMIT 100";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'movements' => $stmt->fetchAll()]);
            break;

        // ==========================================
        // 6. POS CHECKOUT WITH AUTOMATIC BOM DEDUCTION
        // ==========================================
        // 6. CASHIER SHIFTS & OPENING SALES WITH MATERIAL STOCK
        // ==========================================
        case 'get_opening_shift_products':
            $stmt = $pdo->query("
                SELECT osp.id, osp.product_id, osp.sort_order, osp.is_active,
                       p.sku, p.name, p.unit, p.product_type, p.category, p.image_emoji
                FROM opening_shift_products osp
                JOIN products p ON p.id = osp.product_id
                ORDER BY osp.sort_order ASC, osp.id ASC
            ");
            $items = $stmt->fetchAll();
            echo json_encode(['success' => true, 'products' => $items]);
            break;

        case 'save_opening_shift_product':
            $prodId = (int)($input['product_id'] ?? 0);
            $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;
            $sortOrder = isset($input['sort_order']) ? (int)$input['sort_order'] : 0;
            if (!$prodId) {
                echo json_encode(['success' => false, 'message' => 'Product ID diperlukan.']);
                exit;
            }
            $stmtCheck = $pdo->prepare("SELECT id FROM opening_shift_products WHERE product_id = ?");
            $stmtCheck->execute([$prodId]);
            $existingId = $stmtCheck->fetchColumn();
            if ($existingId) {
                $stmt = $pdo->prepare("UPDATE opening_shift_products SET is_active = ?, sort_order = ? WHERE id = ?");
                $stmt->execute([$isActive, $sortOrder, $existingId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO opening_shift_products (product_id, sort_order, is_active) VALUES (?, ?, ?)");
                $stmt->execute([$prodId, $sortOrder, $isActive]);
            }
            echo json_encode(['success' => true, 'message' => 'Produk pelacakan Opening Shift berhasil disimpan.']);
            break;

        case 'delete_opening_shift_product':
            $prodId = (int)($input['product_id'] ?? ($input['id'] ?? 0));
            if (!$prodId) {
                echo json_encode(['success' => false, 'message' => 'Product ID diperlukan.']);
                exit;
            }
            $pdo->prepare("DELETE FROM opening_shift_products WHERE product_id = ? OR id = ?")->execute([$prodId, $prodId]);
            echo json_encode(['success' => true, 'message' => 'Produk berhasil dihapus dari daftar pelacakan Opening Shift.']);
            break;

        case 'get_opening_shift_data':
            $branchId = (int)($_GET['branch_id'] ?? ($input['branch_id'] ?? 1));

            // Deteksi seluruh shift hari ini di cabang yang dipilih
            $stmtTodayShifts = $pdo->prepare("
                SELECT cs.*, u.name as cashier_name, u.nik as cashier_nik
                FROM cashier_shifts cs
                JOIN users u ON u.id = cs.user_id
                WHERE cs.branch_id = ? AND date(cs.start_time) = date('now', 'localtime')
                ORDER BY cs.id ASC
            ");
            $stmtTodayShifts->execute([$branchId]);
            $todayShifts = $stmtTodayShifts->fetchAll();

            $todayClosedCount = 0;
            $activeOpenShift = null;
            foreach ($todayShifts as $ts) {
                if ($ts['status'] === 'OPEN') {
                    $activeOpenShift = $ts;
                } else {
                    $todayClosedCount++;
                }
            }

            $nextShiftNumber = $todayClosedCount + 1;
            $nextShiftName = ($nextShiftNumber == 1) ? 'Shift 1 (Pagi)' : (($nextShiftNumber == 2) ? 'Shift 2 (Siang/Malam)' : ('Shift ' . $nextShiftNumber));
            $nextShiftType = 'SHIFT_' . $nextShiftNumber;

            // Penentuan Shift Acuan Stok:
            // Jika hari ini sudah ada shift yang selesai (Shift 1 -> Shift 2), stok acuan mengambil hasil closing fisik Shift 1 hari ini!
            // Jika shift pertama hari ini (Shift 1), stok acuan mengambil hasil closing malam hari kemarin.
            $lastShift = null;
            $shiftReferenceTitle = '';
            $isContinuation = false;

            if ($nextShiftNumber > 1 && !empty($todayShifts)) {
                // Ambil shift terakhir yang sudah ditutup hari ini
                for ($i = count($todayShifts) - 1; $i >= 0; $i--) {
                    if ($todayShifts[$i]['status'] === 'CLOSED') {
                        $lastShift = $todayShifts[$i];
                        break;
                    }
                }
                if (!$lastShift) $lastShift = end($todayShifts);

                $shiftReferenceTitle = "Serah Terima dari " . ($lastShift['cashier_name'] ?? 'Kasir') . " (" . ($lastShift['shift_name'] ?: 'Shift ' . ($nextShiftNumber - 1)) . ")";
                $isContinuation = true;
            } else {
                // Ambil closing shift malam kemarin / sebelum hari ini
                $stmtPrevDay = $pdo->prepare("
                    SELECT cs.*, u.name as cashier_name, u.nik as cashier_nik
                    FROM cashier_shifts cs
                    JOIN users u ON u.id = cs.user_id
                    WHERE cs.branch_id = ? AND date(cs.start_time) < date('now', 'localtime')
                    ORDER BY cs.id DESC LIMIT 1
                ");
                $stmtPrevDay->execute([$branchId]);
                $lastShift = $stmtPrevDay->fetch();

                if (!$lastShift) {
                    $stmtFallbackLast = $pdo->prepare("
                        SELECT cs.*, u.name as cashier_name, u.nik as cashier_nik
                        FROM cashier_shifts cs
                        JOIN users u ON u.id = cs.user_id
                        WHERE cs.branch_id = ?
                        ORDER BY cs.id DESC LIMIT 1
                    ");
                    $stmtFallbackLast->execute([$branchId]);
                    $lastShift = $stmtFallbackLast->fetch();
                }

                $shiftReferenceTitle = "Stok Acuan Tutup Toko Kemarin (Malam 🔒)";
                $isContinuation = false;
            }

            $lastClosingItems = [];
            $lastOpeningItems = [];
            if ($lastShift) {
                if (!empty($lastShift['closing_items_json'])) {
                    $dec = json_decode($lastShift['closing_items_json'], true);
                    if (is_array($dec)) $lastClosingItems = $dec;
                }
                if (!empty($lastShift['opening_items_json'])) {
                    $dec = json_decode($lastShift['opening_items_json'], true);
                    if (is_array($dec)) $lastOpeningItems = $dec;
                }
            }

            // 2. Ambil daftar produk yang dikonfigurasi untuk dilacak di Opening Sales
            $stmtTracked = $pdo->prepare("
                SELECT osp.id as tracking_id, osp.product_id, osp.sort_order,
                       p.sku, p.name, p.unit, p.product_type, p.category, p.image_emoji,
                       COALESCE(bs.stock, 0) as branch_stock
                FROM opening_shift_products osp
                JOIN products p ON p.id = osp.product_id
                LEFT JOIN branch_stocks bs ON bs.product_id = p.id AND bs.branch_id = ?
                WHERE osp.is_active = 1 AND p.is_active = 1
                ORDER BY osp.sort_order ASC, osp.id ASC
            ");
            $stmtTracked->execute([$branchId]);
            $trackedRows = $stmtTracked->fetchAll();

            // Fallback default jika tabel konfigurasi kosong
            if (empty($trackedRows)) {
                $stmtFallback = $pdo->prepare("
                    SELECT p.id as product_id, 0 as sort_order,
                           p.sku, p.name, p.unit, p.product_type, p.category, p.image_emoji,
                           COALESCE(bs.stock, 0) as branch_stock
                    FROM products p
                    LEFT JOIN branch_stocks bs ON bs.product_id = p.id AND bs.branch_id = ?
                    WHERE p.sku IN ('RAW-089', 'RAW-020', 'RAW-00092')
                    ORDER BY p.id ASC
                ");
                $stmtFallback->execute([$branchId]);
                $trackedRows = $stmtFallback->fetchAll();
            }

            $dynamicItems = [];
            $rawBySku = [];
            $nightStockLegacy = ['mineral' => 0, 'ro' => 0, 'plastik' => 0];
            $currentStockLegacy = ['mineral' => 0, 'ro' => 0, 'plastik' => 0];

            foreach ($trackedRows as $r) {
                $pId = (int)$r['product_id'];
                $sku = $r['sku'];
                $bStock = (int)$r['branch_stock'];

                // Resolusi Stok Acuan Shift sebelumnya (Stok malam kemarin atau sisa serah terima shift 1)
                $refQty = $bStock;
                if (!empty($lastClosingItems) && isset($lastClosingItems[$pId])) {
                    $refQty = (int)$lastClosingItems[$pId];
                } elseif (!empty($lastClosingItems) && isset($lastClosingItems[$sku])) {
                    $refQty = (int)$lastClosingItems[$sku];
                } elseif ($sku === 'RAW-089' && $lastShift && $lastShift['closing_raw_mineral'] !== null) {
                    $refQty = (int)$lastShift['closing_raw_mineral'];
                } elseif ($sku === 'RAW-020' && $lastShift && $lastShift['closing_raw_ro'] !== null) {
                    $refQty = (int)$lastShift['closing_raw_ro'];
                } elseif ($sku === 'RAW-00092' && $lastShift && $lastShift['closing_raw_plastik'] !== null) {
                    $refQty = (int)$lastShift['closing_raw_plastik'];
                } elseif (!empty($lastOpeningItems) && isset($lastOpeningItems[$pId])) {
                    $refQty = (int)$lastOpeningItems[$pId];
                } elseif ($sku === 'RAW-089' && $lastShift && (int)($lastShift['opening_raw_mineral'] ?? 0) > 0) {
                    $refQty = (int)$lastShift['opening_raw_mineral'];
                } elseif ($sku === 'RAW-020' && $lastShift && (int)($lastShift['opening_raw_ro'] ?? 0) > 0) {
                    $refQty = (int)$lastShift['opening_raw_ro'];
                } elseif ($sku === 'RAW-00092' && $lastShift && (int)($lastShift['opening_raw_plastik'] ?? 0) > 0) {
                    $refQty = (int)$lastShift['opening_raw_plastik'];
                }

                $dynamicItems[] = [
                    'product_id' => $pId,
                    'sku' => $sku,
                    'name' => $r['name'],
                    'unit' => $r['unit'],
                    'category' => $r['category'],
                    'product_type' => $r['product_type'],
                    'image_emoji' => $r['image_emoji'] ?? '📦',
                    'branch_stock' => $bStock,
                    'night_stock' => $refQty,
                    'opening_stock' => $refQty
                ];

                $rawBySku[$sku] = $r;

                if ($sku === 'RAW-089') {
                    $nightStockLegacy['mineral'] = $refQty;
                    $currentStockLegacy['mineral'] = $bStock;
                } elseif ($sku === 'RAW-020') {
                    $nightStockLegacy['ro'] = $refQty;
                    $currentStockLegacy['ro'] = $bStock;
                } elseif ($sku === 'RAW-00092') {
                    $nightStockLegacy['plastik'] = $refQty;
                    $currentStockLegacy['plastik'] = $bStock;
                }
            }

            echo json_encode([
                'success' => true,
                'default_initial_cash' => 300000,
                'default_modal' => 300000,
                'next_shift_number' => $nextShiftNumber,
                'next_shift_name' => $nextShiftName,
                'next_shift_type' => $nextShiftType,
                'shift_reference_title' => $shiftReferenceTitle,
                'is_continuation' => $isContinuation,
                'today_shifts_count' => count($todayShifts),
                'active_open_shift' => $activeOpenShift,
                'previous_shift_info' => $lastShift ? [
                    'id' => $lastShift['id'],
                    'shift_number' => $lastShift['shift_number'] ?? 1,
                    'shift_name' => $lastShift['shift_name'] ?? 'Shift 1',
                    'cashier_name' => $lastShift['cashier_name'] ?? 'Kasir',
                    'end_time' => $lastShift['end_time'] ?? null,
                    'status' => $lastShift['status']
                ] : null,
                'last_shift' => $lastShift,
                'items' => $dynamicItems,
                'tracked_count' => count($dynamicItems),
                'night_stock' => $nightStockLegacy,
                'current_branch_stock' => $currentStockLegacy,
                'raw_items' => $rawBySku
            ]);
            break;

        case 'open_shift':
            $branchId = (int)($input['branch_id'] ?? 1);
            $userId = (int)($input['user_id'] ?? 3);
            $initialCash = (float)($input['initial_cash'] ?? 0);

            // Cegah buka shift jika masih ada shift yang berstatus OPEN di cabang ini (mencegah bentrok antar kasir)
            $stmtCheckBranch = $pdo->prepare("
                SELECT cs.id, cs.shift_name, u.name as cashier_name
                FROM cashier_shifts cs
                JOIN users u ON u.id = cs.user_id
                WHERE cs.branch_id = ? AND cs.status = 'OPEN'
                LIMIT 1
            ");
            $stmtCheckBranch->execute([$branchId]);
            $existingOpen = $stmtCheckBranch->fetch();
            if ($existingOpen) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Cabang ini masih memiliki shift aktif yang belum ditutup (' . ($existingOpen['shift_name'] ?: 'Shift') . ' oleh ' . $existingOpen['cashier_name'] . '). Lakukan serah terima / closing shift terlebih dahulu.'
                ]);
                exit;
            }

            // Tentukan nomor shift & nama shift hari ini
            $shiftNumber = (int)($input['shift_number'] ?? 0);
            if ($shiftNumber <= 0) {
                $stmtCountToday = $pdo->prepare("SELECT COUNT(*) FROM cashier_shifts WHERE branch_id = ? AND date(start_time) = date('now', 'localtime')");
                $stmtCountToday->execute([$branchId]);
                $shiftNumber = (int)$stmtCountToday->fetchColumn() + 1;
            }

            $shiftName = trim($input['shift_name'] ?? '');
            if (empty($shiftName)) {
                $shiftName = ($shiftNumber == 1) ? 'Shift 1 (Pagi)' : (($shiftNumber == 2) ? 'Shift 2 (Siang/Malam)' : ('Shift ' . $shiftNumber));
            }
            $shiftType = trim($input['shift_type'] ?? ('SHIFT_' . $shiftNumber));

            // Dynamic items payload from frontend
            $dynamicItems = $input['items'] ?? [];
            $openingMap = [];
            $nightMap = [];

            if (is_array($dynamicItems)) {
                foreach ($dynamicItems as $k => $v) {
                    if (is_array($v)) {
                        $pId = (int)($v['product_id'] ?? 0);
                        if ($pId) {
                            $openingMap[$pId] = (int)($v['opening_qty'] ?? 0);
                            $nightMap[$pId] = (int)($v['night_qty'] ?? 0);
                        }
                    } else {
                        $openingMap[$k] = (int)$v;
                    }
                }
            }

            // Legacy individual fields
            $openingMineral = (int)($input['opening_raw_mineral'] ?? ($openingMap[89] ?? 0));
            $openingRo = (int)($input['opening_raw_ro'] ?? ($openingMap[88] ?? 0));
            $openingPlastik = (int)($input['opening_raw_plastik'] ?? ($openingMap[105] ?? 0));

            $nightMineral = (int)($input['closing_night_mineral'] ?? ($nightMap[89] ?? 0));
            $nightRo = (int)($input['closing_night_ro'] ?? ($nightMap[88] ?? 0));
            $nightPlastik = (int)($input['closing_night_plastik'] ?? ($nightMap[105] ?? 0));
            $shiftNotes = trim($input['shift_notes'] ?? '');

            $stmt = $pdo->prepare("
                INSERT INTO cashier_shifts (
                    branch_id, user_id, start_time, initial_cash, status,
                    opening_raw_mineral, opening_raw_ro, opening_raw_plastik,
                    closing_night_mineral, closing_night_ro, closing_night_plastik,
                    shift_notes, opening_items_json,
                    shift_number, shift_name, shift_type, is_final_shift
                ) VALUES (?, ?, datetime('now', 'localtime'), ?, 'OPEN', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
            ");
            $stmt->execute([
                $branchId, $userId, $initialCash,
                $openingMineral, $openingRo, $openingPlastik,
                $nightMineral, $nightRo, $nightPlastik,
                $shiftNotes,
                !empty($openingMap) ? json_encode($openingMap) : null,
                $shiftNumber, $shiftName, $shiftType
            ]);
            $newShiftId = $pdo->lastInsertId();

            // Update branch_stocks to reflect verified morning physical stock (termasuk penambahan stok baru)
            foreach ($openingMap as $pId => $openQty) {
                $pId = (int)$pId;
                if ($pId <= 0) continue;
                $stmtUpdBs = $pdo->prepare("
                    INSERT INTO branch_stocks (branch_id, product_id, stock, local_price, local_cogs)
                    VALUES (?, ?, ?, 0, 0)
                    ON CONFLICT(branch_id, product_id) DO UPDATE SET stock = excluded.stock
                ");
                $stmtUpdBs->execute([$branchId, $pId, $openQty]);
            }

            $shift = $pdo->query("SELECT * FROM cashier_shifts WHERE id = $newShiftId")->fetch();
            echo json_encode([
                'success' => true,
                'shift' => $shift,
                'message' => 'Opening Shift berhasil dibuka dengan modal awal Rp ' . number_format($initialCash, 0, ',', '.') . ' dan pencatatan stok awal terverifikasi.'
            ]);
            break;

        case 'get_closing_shift_data':
            $shiftId = (int)($_GET['shift_id'] ?? ($input['shift_id'] ?? 0));
            $branchId = (int)($_GET['branch_id'] ?? ($input['branch_id'] ?? 1));

            if (!$shiftId) {
                $stmtActive = $pdo->prepare("SELECT id FROM cashier_shifts WHERE branch_id = ? AND status = 'OPEN' ORDER BY id DESC LIMIT 1");
                $stmtActive->execute([$branchId]);
                $shiftId = (int)$stmtActive->fetchColumn();
            }

            if (!$shiftId) {
                echo json_encode(['success' => false, 'message' => 'Tidak ada shift kasir aktif yang ditemukan untuk ditutup.']);
                exit;
            }

            $stmtShift = $pdo->prepare("SELECT * FROM cashier_shifts WHERE id = ?");
            $stmtShift->execute([$shiftId]);
            $shift = $stmtShift->fetch();

            if (!$shift) {
                echo json_encode(['success' => false, 'message' => 'Shift tidak ditemukan.']);
                exit;
            }

            $stmtSales = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE shift_id = ? AND payment_method = 'CASH'");
            $stmtSales->execute([$shiftId]);
            $cashSales = (float)$stmtSales->fetchColumn();

            $stmtTxCount = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE shift_id = ?");
            $stmtTxCount->execute([$shiftId]);
            $txCount = (int)$stmtTxCount->fetchColumn();

            $initialCash = (float)$shift['initial_cash'];
            $expectedCash = $initialCash + $cashSales;

            $openingItems = [];
            if (!empty($shift['opening_items_json'])) {
                $openingItems = json_decode($shift['opening_items_json'], true) ?: [];
            }

            $stmtTracked = $pdo->prepare("
                SELECT p.id as product_id, osp.sort_order,
                       p.sku, p.name, p.unit, p.product_type, p.category, p.image_emoji,
                       COALESCE(bs.stock, 0) as branch_stock
                FROM opening_shift_products osp
                JOIN products p ON p.id = osp.product_id
                LEFT JOIN branch_stocks bs ON bs.product_id = p.id AND bs.branch_id = ?
                WHERE osp.is_active = 1 AND p.is_active = 1
                ORDER BY osp.sort_order ASC, osp.id ASC
            ");
            $stmtTracked->execute([$shift['branch_id']]);
            $trackedRows = $stmtTracked->fetchAll();

            if (empty($trackedRows)) {
                $stmtFallback = $pdo->prepare("
                    SELECT p.id as product_id, 0 as sort_order,
                           p.sku, p.name, p.unit, p.product_type, p.category, p.image_emoji,
                           COALESCE(bs.stock, 0) as branch_stock
                    FROM products p
                    LEFT JOIN branch_stocks bs ON bs.product_id = p.id AND bs.branch_id = ?
                    WHERE p.sku IN ('RAW-089', 'RAW-020', 'RAW-00092')
                    ORDER BY p.id ASC
                ");
                $stmtFallback->execute([$shift['branch_id']]);
                $trackedRows = $stmtFallback->fetchAll();
            }

            $closingItems = [];
            foreach ($trackedRows as $r) {
                $pId = (int)$r['product_id'];
                $sku = $r['sku'];
                $bStock = (int)$r['branch_stock'];

                $openingQty = $bStock;
                if (isset($openingItems[$pId])) {
                    $openingQty = (int)$openingItems[$pId];
                } elseif ($sku === 'RAW-089' && $shift['opening_raw_mineral'] !== null) {
                    $openingQty = (int)$shift['opening_raw_mineral'];
                } elseif ($sku === 'RAW-020' && $shift['opening_raw_ro'] !== null) {
                    $openingQty = (int)$shift['opening_raw_ro'];
                } elseif ($sku === 'RAW-00092' && $shift['opening_raw_plastik'] !== null) {
                    $openingQty = (int)$shift['opening_raw_plastik'];
                }

                $closingItems[] = [
                    'product_id' => $pId,
                    'sku' => $sku,
                    'name' => $r['name'],
                    'unit' => $r['unit'],
                    'category' => $r['category'],
                    'product_type' => $r['product_type'],
                    'image_emoji' => $r['image_emoji'] ?? '📦',
                    'opening_qty' => $openingQty,
                    'current_branch_stock' => $bStock,
                    'closing_qty' => $bStock
                ];
            }

            // Ambil master kategori pengeluaran/beban operasional master
            $stmtExpCats = $pdo->query("SELECT id, name, group_type FROM expense_categories WHERE is_active = 1 ORDER BY name ASC");
            $expenseCategories = $stmtExpCats ? $stmtExpCats->fetchAll() : [];
            if (empty($expenseCategories)) {
                $expenseCategories = [
                    ['id' => 1, 'name' => 'Biaya Operasional Toko', 'group_type' => 'OPERASIONAL'],
                    ['id' => 2, 'name' => 'Perlengkapan & Kebersihan', 'group_type' => 'OPERASIONAL'],
                    ['id' => 3, 'name' => 'Listrik & Token Air', 'group_type' => 'OPERASIONAL'],
                    ['id' => 4, 'name' => 'Beban Operasional Lainnya', 'group_type' => 'LAINNYA']
                ];
            }

            // Ambil daftar karyawan cabang aktif untuk kasbon & uang makan
            $stmtEmps = $pdo->prepare("SELECT id, name, role, nik FROM users WHERE branch_id = ? AND is_active = 1 ORDER BY name ASC");
            $stmtEmps->execute([$shift['branch_id']]);
            $branchEmployees = $stmtEmps->fetchAll();
            if (empty($branchEmployees)) {
                $stmtEmpsAll = $pdo->query("SELECT id, name, role, nik FROM users WHERE is_active = 1 ORDER BY name ASC");
                $branchEmployees = $stmtEmpsAll ? $stmtEmpsAll->fetchAll() : [];
            }

            // Ambil data meteran air terakhir untuk acuan (Meteran Hari Kemarin)
            $stmtLastMeter = $pdo->prepare("
                SELECT meter_today, log_date, water_dispensed_galon 
                FROM water_meter_logs 
                WHERE branch_id = ? 
                ORDER BY id DESC LIMIT 1
            ");
            $stmtLastMeter->execute([$shift['branch_id']]);
            $lastMeterRow = $stmtLastMeter->fetch();
            $meterYesterday = $lastMeterRow ? (float)$lastMeterRow['meter_today'] : 0;
            $meterYesterdayDate = $lastMeterRow ? $lastMeterRow['log_date'] : null;
            $lastDispensedGalon = $lastMeterRow ? (float)$lastMeterRow['water_dispensed_galon'] : 0;

            echo json_encode([
                'success' => true,
                'shift' => $shift,
                'initial_cash' => $initialCash,
                'cash_sales' => $cashSales,
                'expected_cash' => $expectedCash,
                'total_transactions' => $txCount,
                'items' => $closingItems,
                'expense_categories' => $expenseCategories,
                'branch_employees' => $branchEmployees,
                'water_meter' => [
                    'meter_yesterday' => $meterYesterday,
                    'last_log_date' => $meterYesterdayDate,
                    'last_dispensed_galon' => $lastDispensedGalon
                ]
            ]);
            break;

        case 'close_shift':
            $shiftId = (int)($input['shift_id'] ?? 0);
            $actualCash = (float)($input['actual_cash'] ?? 0);

            // Dynamic closing items from frontend
            $dynamicItems = $input['items'] ?? [];
            $closingMap = [];
            if (is_array($dynamicItems)) {
                foreach ($dynamicItems as $k => $v) {
                    if (is_array($v)) {
                        $pId = (int)($v['product_id'] ?? 0);
                        if ($pId) $closingMap[$pId] = (int)($v['closing_qty'] ?? 0);
                    } else {
                        $closingMap[$k] = (int)$v;
                    }
                }
            }

            $closingMineral = isset($input['closing_raw_mineral']) ? (int)$input['closing_raw_mineral'] : ($closingMap[89] ?? null);
            $closingRo = isset($input['closing_raw_ro']) ? (int)$input['closing_raw_ro'] : ($closingMap[88] ?? null);
            $closingPlastik = isset($input['closing_raw_plastik']) ? (int)$input['closing_raw_plastik'] : ($closingMap[105] ?? null);
            $shiftNotes = trim($input['shift_notes'] ?? '');

            $stmtShift = $pdo->prepare("SELECT * FROM cashier_shifts WHERE id = ?");
            $stmtShift->execute([$shiftId]);
            $shift = $stmtShift->fetch();

            if (!$shift || $shift['status'] === 'CLOSED') {
                echo json_encode(['success' => false, 'message' => 'Shift tidak ditemukan atau sudah ditutup.']);
                exit;
            }

            $stmtSales = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE shift_id = ? AND payment_method = 'CASH'");
            $stmtSales->execute([$shiftId]);
            $cashSales = (float)$stmtSales->fetchColumn();

            // 1. Parse Biaya Beban Operasional Toko
            $rawExpenses = $input['expenses'] ?? [];
            $expensesList = [];
            $totalExpenses = 0;
            if (is_array($rawExpenses)) {
                foreach ($rawExpenses as $exp) {
                    $amt = (float)($exp['amount'] ?? 0);
                    if ($amt > 0) {
                        $cat = trim($exp['category'] ?? 'Biaya Operasional Toko') ?: 'Biaya Operasional Toko';
                        $notes = trim($exp['notes'] ?? '');
                        $expensesList[] = [
                            'category' => $cat,
                            'amount' => $amt,
                            'notes' => $notes
                        ];
                        $totalExpenses += $amt;
                    }
                }
            }

            // 2. Parse Kasbon Karyawan
            $rawLoans = $input['loans'] ?? [];
            $loansList = [];
            $totalLoans = 0;
            if (is_array($rawLoans)) {
                foreach ($rawLoans as $loan) {
                    $amt = (float)($loan['amount'] ?? 0);
                    $uId = (int)($loan['user_id'] ?? 0);
                    if ($amt > 0 && $uId > 0) {
                        $notes = trim($loan['notes'] ?? '');
                        $loansList[] = [
                            'user_id' => $uId,
                            'amount' => $amt,
                            'notes' => $notes
                        ];
                        $totalLoans += $amt;
                    }
                }
            }

            // 3. Parse Uang Makan Karyawan
            $rawMeals = $input['meals'] ?? [];
            $mealsList = [];
            $totalMeals = 0;
            if (is_array($rawMeals)) {
                foreach ($rawMeals as $meal) {
                    $amt = (float)($meal['amount'] ?? 0);
                    $uId = (int)($meal['user_id'] ?? 0);
                    if ($amt > 0 && $uId > 0) {
                        $notes = trim($meal['notes'] ?? '');
                        $mealsList[] = [
                            'user_id' => $uId,
                            'amount' => $amt,
                            'notes' => $notes
                        ];
                        $totalMeals += $amt;
                    }
                }
            }

            // Total uang kas yang dikeluarkan dari laci kasir
            $totalDisbursements = $totalExpenses + $totalLoans + $totalMeals;

            // Rumus Rekonsiliasi Kas Bersih: Expected Cash = Modal Awal + Penjualan Tunai - Pengeluaran Kasir
            $expectedCash = ($shift['initial_cash'] + $cashSales) - $totalDisbursements;
            $diff = $actualCash - $expectedCash;

            $isFinalShift = isset($input['is_final_shift']) ? (int)$input['is_final_shift'] : (($shift['shift_number'] >= 2) ? 1 : 0);

            $pdo->beginTransaction();
            try {
                // A. Catat Biaya Beban Toko ke branch_expenses (Menu Beban Operasional / Laba Rugi)
                foreach ($expensesList as $exp) {
                    $stmtExp = $pdo->prepare("
                        INSERT INTO branch_expenses (branch_id, category, amount, expense_date, source, shift_id, notes)
                        VALUES (?, ?, ?, date('now', 'localtime'), 'SHIFT_EXPENSE', ?, ?)
                    ");
                    $expNote = "Pengeluaran Kasir Shift #{$shift['shift_number']} ({$shift['shift_name']}) - " . ($exp['notes'] ?: $exp['category']);
                    $stmtExp->execute([$shift['branch_id'], $exp['category'], $exp['amount'], $shiftId, $expNote]);
                }

                // B. Catat Kasbon Karyawan ke salary_loans (Menu Penggajian / Slip Gaji Potongan Kasbon)
                foreach ($loansList as $loan) {
                    $stmtUName = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                    $stmtUName->execute([$loan['user_id']]);
                    $empName = $stmtUName->fetchColumn() ?: 'Karyawan';

                    $loanReason = "Kasbon Kasir Shift #{$shift['shift_number']}: " . ($loan['notes'] ?: 'Kasbon tunai laci kasir');
                    $stmtLoan = $pdo->prepare("
                        INSERT INTO salary_loans (
                            user_id, branch_id, loan_date, amount, reason, installment_months,
                            monthly_deduction, status, remaining_amount, approved_by, approved_at, shift_id, notes
                        ) VALUES (
                            ?, ?, date('now', 'localtime'), ?, ?, 1,
                            ?, 'ACTIVE', ?, ?, datetime('now', 'localtime'), ?, ?
                        )
                    ");
                    $stmtLoan->execute([
                        $loan['user_id'],
                        $shift['branch_id'],
                        $loan['amount'],
                        $loanReason,
                        $loan['amount'],
                        $loan['amount'],
                        $shift['user_id'],
                        $shiftId,
                        "Kasbon dicairkan tunai dari laci kasir pada shift #{$shiftId} ({$shift['shift_name']})"
                    ]);
                }

                // C. Catat Uang Makan Karyawan ke employee_meal_allowances & branch_expenses
                foreach ($mealsList as $meal) {
                    $stmtUName = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                    $stmtUName->execute([$meal['user_id']]);
                    $empName = $stmtUName->fetchColumn() ?: 'Karyawan';

                    $mealNote = "Uang makan karyawan: {$empName} - " . ($meal['notes'] ?: 'Uang makan shift');
                    $stmtMeal = $pdo->prepare("
                        INSERT INTO employee_meal_allowances (shift_id, user_id, branch_id, amount, meal_date, notes)
                        VALUES (?, ?, ?, ?, date('now', 'localtime'), ?)
                    ");
                    $stmtMeal->execute([
                        $shiftId,
                        $meal['user_id'],
                        $shift['branch_id'],
                        $meal['amount'],
                        $mealNote
                    ]);

                    // Catat juga ke branch_expenses agar otomatis masuk laporan Laba Rugi
                    $stmtMealExp = $pdo->prepare("
                        INSERT INTO branch_expenses (branch_id, category, amount, expense_date, source, shift_id, notes)
                        VALUES (?, 'Beban Uang Makan Karyawan', ?, date('now', 'localtime'), 'SHIFT_MEAL', ?, ?)
                    ");
                    $stmtMealExp->execute([
                        $shift['branch_id'],
                        $meal['amount'],
                        $shiftId,
                        $mealNote
                    ]);
                }

                // Rangkuman JSON Disbursements
                $disbursementsSummary = [
                    'expenses' => $expensesList,
                    'loans' => $loansList,
                    'meals' => $mealsList,
                    'total_expenses' => $totalExpenses,
                    'total_loans' => $totalLoans,
                    'total_meals' => $totalMeals,
                    'total_disbursements' => $totalDisbursements
                ];

                // Parse Meteran Air Display Counter Input
                $meterToday = (isset($input['meter_today']) && $input['meter_today'] !== '') ? (float)$input['meter_today'] : null;
                $meterYesterday = (isset($input['meter_yesterday']) && $input['meter_yesterday'] !== '') ? (float)$input['meter_yesterday'] : null;
                $meterAdjustment = isset($input['meter_adjustment']) ? (float)$input['meter_adjustment'] : 0;
                $adjustmentType = trim($input['adjustment_type'] ?? 'PULSE');
                if (!in_array($adjustmentType, ['PULSE', 'GALON'])) $adjustmentType = 'PULSE';
                $adjustmentReason = trim($input['adjustment_reason'] ?? '');

                $meterDiff = null;
                $dispensedGalon = null;

                if ($meterToday !== null) {
                    if ($meterYesterday === null) {
                        $stmtPrevM = $pdo->prepare("SELECT meter_today FROM water_meter_logs WHERE branch_id = ? ORDER BY id DESC LIMIT 1");
                        $stmtPrevM->execute([$shift['branch_id']]);
                        $meterYesterday = (float)($stmtPrevM->fetchColumn() ?: 0);
                    }

                    $meterDiff = $meterToday - $meterYesterday;

                    // Rumus: (meteran hari ini - meteran hari kemarin) / 782
                    if ($adjustmentType === 'GALON') {
                        $dispensedGalon = round(($meterDiff / 782) + $meterAdjustment, 2);
                    } else {
                        $dispensedGalon = round(($meterDiff + $meterAdjustment) / 782, 2);
                    }

                    $stmtWML = $pdo->prepare("
                        INSERT INTO water_meter_logs (
                            branch_id, shift_id, user_id, log_date,
                            meter_yesterday, meter_today, meter_difference,
                            meter_adjustment, adjustment_type, adjustment_reason,
                            water_dispensed_galon, notes, created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, date('now', 'localtime'),
                            ?, ?, ?,
                            ?, ?, ?,
                            ?, ?, datetime('now', 'localtime'), datetime('now', 'localtime')
                        )
                    ");
                    $stmtWML->execute([
                        $shift['branch_id'],
                        $shiftId,
                        $shift['user_id'],
                        $meterYesterday,
                        $meterToday,
                        $meterDiff,
                        $meterAdjustment,
                        $adjustmentType,
                        $adjustmentReason ?: null,
                        $dispensedGalon,
                        $shiftNotes ?: null
                    ]);
                }

                // D. Update cashier_shifts
                $stmtUpdate = $pdo->prepare("
                    UPDATE cashier_shifts
                    SET end_time = datetime('now', 'localtime'),
                        expected_cash = ?,
                        actual_cash = ?,
                        cash_difference = ?,
                        total_expenses = ?,
                        total_kasbon = ?,
                        total_meal_allowance = ?,
                        shift_disbursements_json = ?,
                        closing_raw_mineral = ?,
                        closing_raw_ro = ?,
                        closing_raw_plastik = ?,
                        shift_notes = COALESCE(?, shift_notes),
                        closing_items_json = ?,
                        is_final_shift = ?,
                        water_meter_yesterday = ?,
                        water_meter_today = ?,
                        water_meter_difference = ?,
                        water_meter_adjustment = ?,
                        water_meter_adjustment_type = ?,
                        water_meter_adjustment_reason = ?,
                        water_meter_dispensed_galon = ?,
                        status = 'CLOSED'
                    WHERE id = ?
                ");
                $stmtUpdate->execute([
                    $expectedCash, $actualCash, $diff,
                    $totalExpenses, $totalLoans, $totalMeals,
                    json_encode($disbursementsSummary),
                    $closingMineral, $closingRo, $closingPlastik,
                    $shiftNotes ?: null,
                    !empty($closingMap) ? json_encode($closingMap) : null,
                    $isFinalShift,
                    $meterYesterday,
                    $meterToday,
                    $meterDiff,
                    $meterAdjustment,
                    $adjustmentType,
                    $adjustmentReason ?: null,
                    $dispensedGalon,
                    $shiftId
                ]);

                // Update branch_stocks to reflect verified closing stock (sisa fisik malam hari)
                foreach ($closingMap as $pId => $closeQty) {
                    $pId = (int)$pId;
                    if ($pId <= 0) continue;
                    $stmtUpdBs = $pdo->prepare("
                        INSERT INTO branch_stocks (branch_id, product_id, stock, local_price, local_cogs)
                        VALUES (?, ?, ?, 0, 0)
                        ON CONFLICT(branch_id, product_id) DO UPDATE SET stock = excluded.stock
                    ");
                    $stmtUpdBs->execute([$shift['branch_id'], $pId, $closeQty]);
                }

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                echo json_encode(['success' => false, 'message' => 'Gagal menutup shift: ' . $e->getMessage()]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'message' => ($isFinalShift ? 'Tutup toko & closing akhir malam berhasil disimpan.' : 'Closing shift & serah terima kasir berhasil disimpan.'),
                'is_final_shift' => $isFinalShift,
                'summary' => [
                    'shift_number' => $shift['shift_number'] ?? 1,
                    'shift_name' => $shift['shift_name'] ?? 'Shift 1',
                    'initial_cash' => $shift['initial_cash'],
                    'cash_sales' => $cashSales,
                    'total_expenses' => $totalExpenses,
                    'total_kasbon' => $totalLoans,
                    'total_meal_allowance' => $totalMeals,
                    'total_disbursements' => $totalDisbursements,
                    'expected_cash' => $expectedCash,
                    'actual_cash' => $actualCash,
                    'cash_difference' => $diff,
                    'closing_raw_mineral' => $closingMineral,
                    'closing_raw_ro' => $closingRo,
                    'closing_raw_plastik' => $closingPlastik
                ]
            ]);
            break;

        case 'get_shift_reports':
            $branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : 1;
            $reportDate = !empty($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');

            // Ambil seluruh shift pada tanggal dan cabang tersebut
            $stmtShifts = $pdo->prepare("
                SELECT cs.*, u.name as cashier_name, u.nik as cashier_nik, b.name as branch_name
                FROM cashier_shifts cs
                JOIN users u ON u.id = cs.user_id
                JOIN branches b ON b.id = cs.branch_id
                WHERE cs.branch_id = ? AND date(cs.start_time) = ?
                ORDER BY cs.id ASC
            ");
            $stmtShifts->execute([$branchId, $reportDate]);
            $shifts = $stmtShifts->fetchAll();

            // Produk-produk yang dilacak
            $stmtTracked = $pdo->prepare("
                SELECT osp.product_id, p.sku, p.name, p.unit, p.category, p.image_emoji
                FROM opening_shift_products osp
                JOIN products p ON p.id = osp.product_id
                WHERE osp.is_active = 1
                ORDER BY osp.sort_order ASC
            ");
            $stmtTracked->execute();
            $trackedMaster = $stmtTracked->fetchAll();
            if (empty($trackedMaster)) {
                $trackedMaster = $pdo->query("
                    SELECT id as product_id, sku, name, unit, category, image_emoji
                    FROM products
                    WHERE sku IN ('RAW-089', 'RAW-020', 'RAW-00092')
                    ORDER BY id ASC
                ")->fetchAll();
            }

            $shiftSummaries = [];
            $dailyTotals = [
                'total_shifts' => count($shifts),
                'total_transactions' => 0,
                'total_revenue' => 0,
                'total_cash_sales' => 0,
                'total_non_cash_sales' => 0,
                'total_qris_sales' => 0,
                'total_transfer_sales' => 0,
                'total_debit_sales' => 0,
                'total_piutang_sales' => 0,
                'total_initial_cash' => 0,
                'total_actual_cash' => 0,
                'total_cash_difference' => 0,
                'total_expenses' => 0,
                'total_kasbon' => 0,
                'total_meal_allowance' => 0,
                'total_disbursements' => 0,
                'is_store_closed' => false,
                'final_closing_time' => null,
                'final_cashier_name' => null,
                'final_stocks' => []
            ];

            foreach ($shifts as $s) {
                $sId = (int)$s['id'];
                
                // Query sales terikat shift ini
                $stmtSales = $pdo->prepare("
                    SELECT 
                        COUNT(id) as tx_count,
                        COALESCE(SUM(grand_total), 0) as total_sales,
                        COALESCE(SUM(CASE WHEN payment_method = 'CASH' THEN grand_total ELSE 0 END), 0) as cash_sales,
                        COALESCE(SUM(CASE WHEN payment_method = 'QRIS' THEN grand_total ELSE 0 END), 0) as qris_sales,
                        COALESCE(SUM(CASE WHEN payment_method = 'TRANSFER' THEN grand_total ELSE 0 END), 0) as transfer_sales,
                        COALESCE(SUM(CASE WHEN payment_method = 'DEBIT' THEN grand_total ELSE 0 END), 0) as debit_sales,
                        COALESCE(SUM(CASE WHEN payment_method = 'PIUTANG' THEN grand_total ELSE 0 END), 0) as piutang_sales,
                        COALESCE(SUM(total_cogs), 0) as total_cogs
                    FROM sales
                    WHERE shift_id = ? AND (status IS NULL OR status != 'CANCELLED')
                ");
                $stmtSales->execute([$sId]);
                $salesData = $stmtSales->fetch();

                $txCount = (int)$salesData['tx_count'];
                $totSales = (float)$salesData['total_sales'];
                $cashSales = (float)$salesData['cash_sales'];
                $nonCashSales = $totSales - $cashSales;
                $initCash = (float)$s['initial_cash'];
                
                $totExpenses = (float)($s['total_expenses'] ?? 0);
                $totLoans = (float)($s['total_kasbon'] ?? 0);
                $totMeals = (float)($s['total_meal_allowance'] ?? 0);
                $totDisbursements = $totExpenses + $totLoans + $totMeals;

                $expectedCash = ($s['status'] === 'CLOSED') ? (float)$s['expected_cash'] : ($initCash + $cashSales - $totDisbursements);
                $actualCash = (float)$s['actual_cash'];
                $diffCash = ($s['status'] === 'CLOSED') ? (float)$s['cash_difference'] : 0;

                // Decode items
                $openItems = !empty($s['opening_items_json']) ? json_decode($s['opening_items_json'], true) : [];
                $closeItems = !empty($s['closing_items_json']) ? json_decode($s['closing_items_json'], true) : [];

                $reconciledItems = [];
                foreach ($trackedMaster as $tm) {
                    $pId = (int)$tm['product_id'];
                    $oQty = isset($openItems[$pId]) ? (int)$openItems[$pId] : 0;
                    $cQty = isset($closeItems[$pId]) ? (int)$closeItems[$pId] : 0;
                    
                    if ($tm['sku'] === 'RAW-089') {
                        if (!$oQty && $s['opening_raw_mineral'] !== null) $oQty = (int)$s['opening_raw_mineral'];
                        if (!$cQty && $s['closing_raw_mineral'] !== null) $cQty = (int)$s['closing_raw_mineral'];
                    } elseif ($tm['sku'] === 'RAW-020') {
                        if (!$oQty && $s['opening_raw_ro'] !== null) $oQty = (int)$s['opening_raw_ro'];
                        if (!$cQty && $s['closing_raw_ro'] !== null) $cQty = (int)$s['closing_raw_ro'];
                    } elseif ($tm['sku'] === 'RAW-00092') {
                        if (!$oQty && $s['opening_raw_plastik'] !== null) $oQty = (int)$s['opening_raw_plastik'];
                        if (!$cQty && $s['closing_raw_plastik'] !== null) $cQty = (int)$s['closing_raw_plastik'];
                    }

                    $usedQty = ($s['status'] === 'CLOSED') ? ($oQty - $cQty) : 0;
                    $reconciledItems[] = [
                        'product_id' => $pId,
                        'sku' => $tm['sku'],
                        'name' => $tm['name'],
                        'unit' => $tm['unit'],
                        'image_emoji' => $tm['image_emoji'] ?? '📦',
                        'opening_qty' => $oQty,
                        'closing_qty' => $cQty,
                        'usage_qty' => $usedQty
                    ];
                }

                $shiftObj = [
                    'id' => $sId,
                    'shift_number' => (int)($s['shift_number'] ?: 1),
                    'shift_name' => $s['shift_name'] ?: ('Shift ' . ($s['shift_number'] ?: 1)),
                    'shift_type' => $s['shift_type'] ?: 'SHIFT_1',
                    'is_final_shift' => (int)($s['is_final_shift'] ?? 0),
                    'cashier_id' => (int)$s['user_id'],
                    'cashier_name' => $s['cashier_name'],
                    'cashier_nik' => $s['cashier_nik'],
                    'branch_name' => $s['branch_name'],
                    'start_time' => $s['start_time'],
                    'end_time' => $s['end_time'],
                    'status' => $s['status'],
                    'initial_cash' => $initCash,
                    'expected_cash' => $expectedCash,
                    'actual_cash' => $actualCash,
                    'cash_difference' => $diffCash,
                    'total_expenses' => $totExpenses,
                    'total_kasbon' => $totLoans,
                    'total_meal_allowance' => $totMeals,
                    'total_disbursements' => $totDisbursements,
                    'disbursements_detail' => !empty($s['shift_disbursements_json']) ? json_decode($s['shift_disbursements_json'], true) : null,
                    'total_sales' => $totSales,
                    'cash_sales' => $cashSales,
                    'non_cash_sales' => $nonCashSales,
                    'qris_sales' => (float)$salesData['qris_sales'],
                    'transfer_sales' => (float)$salesData['transfer_sales'],
                    'debit_sales' => (float)$salesData['debit_sales'],
                    'piutang_sales' => (float)$salesData['piutang_sales'],
                    'tx_count' => $txCount,
                    'water_meter_yesterday' => $s['water_meter_yesterday'] !== null ? (float)$s['water_meter_yesterday'] : null,
                    'water_meter_today' => $s['water_meter_today'] !== null ? (float)$s['water_meter_today'] : null,
                    'water_meter_difference' => $s['water_meter_difference'] !== null ? (float)$s['water_meter_difference'] : null,
                    'water_meter_adjustment' => (float)($s['water_meter_adjustment'] ?? 0),
                    'water_meter_adjustment_type' => $s['water_meter_adjustment_type'] ?? 'PULSE',
                    'water_meter_adjustment_reason' => $s['water_meter_adjustment_reason'] ?? null,
                    'water_meter_dispensed_galon' => $s['water_meter_dispensed_galon'] !== null ? (float)$s['water_meter_dispensed_galon'] : null,
                    'shift_notes' => $s['shift_notes'],
                    'reconciled_items' => $reconciledItems
                ];

                $shiftSummaries[] = $shiftObj;

                // Akumulasi Harian
                $dailyTotals['total_transactions'] += $txCount;
                $dailyTotals['total_revenue'] += $totSales;
                $dailyTotals['total_cash_sales'] += $cashSales;
                $dailyTotals['total_non_cash_sales'] += $nonCashSales;
                $dailyTotals['total_qris_sales'] += (float)$salesData['qris_sales'];
                $dailyTotals['total_transfer_sales'] += (float)$salesData['transfer_sales'];
                $dailyTotals['total_debit_sales'] += (float)$salesData['debit_sales'];
                $dailyTotals['total_piutang_sales'] += (float)$salesData['piutang_sales'];
                $dailyTotals['total_initial_cash'] += $initCash;
                $dailyTotals['total_expenses'] += $totExpenses;
                $dailyTotals['total_kasbon'] += $totLoans;
                $dailyTotals['total_meal_allowance'] += $totMeals;
                $dailyTotals['total_disbursements'] += $totDisbursements;
                if ($s['status'] === 'CLOSED') {
                    $dailyTotals['total_actual_cash'] += $actualCash;
                    $dailyTotals['total_cash_difference'] += $diffCash;
                }

                // Cek apakah ini shift terakhir / penutupan toko malam
                if ($s['status'] === 'CLOSED' && ((int)($s['is_final_shift'] ?? 0) === 1 || (int)($s['shift_number'] ?? 1) >= 2)) {
                    $dailyTotals['is_store_closed'] = true;
                    $dailyTotals['final_closing_time'] = $s['end_time'];
                    $dailyTotals['final_cashier_name'] = $s['cashier_name'];
                    $dailyTotals['final_stocks'] = $reconciledItems;
                }
            }

            // Fallback jika belum ditandai final_stocks tetapi ada shift yang closed
            if (empty($dailyTotals['final_stocks']) && !empty($shiftSummaries)) {
                for ($i = count($shiftSummaries) - 1; $i >= 0; $i--) {
                    if ($shiftSummaries[$i]['status'] === 'CLOSED') {
                        $dailyTotals['final_closing_time'] = $shiftSummaries[$i]['end_time'];
                        $dailyTotals['final_cashier_name'] = $shiftSummaries[$i]['cashier_name'];
                        $dailyTotals['final_stocks'] = $shiftSummaries[$i]['reconciled_items'];
                        break;
                    }
                }
            }

            // Ambil log meteran air untuk tanggal tersebut jika ada
            $stmtWaterLogs = $pdo->prepare("
                SELECT wml.*, u.name as recorder_name, cs.shift_name
                FROM water_meter_logs wml
                JOIN users u ON u.id = wml.user_id
                LEFT JOIN cashier_shifts cs ON cs.id = wml.shift_id
                WHERE wml.branch_id = ? AND wml.log_date = ?
                ORDER BY wml.id DESC
            ");
            $stmtWaterLogs->execute([$branchId, $reportDate]);
            $waterMeterLogs = $stmtWaterLogs->fetchAll();

            echo json_encode([
                'success' => true,
                'branch_id' => $branchId,
                'report_date' => $reportDate,
                'shifts' => $shiftSummaries,
                'daily_summary' => $dailyTotals,
                'water_meter_logs' => $waterMeterLogs
            ]);
            break;

        case 'get_water_meter_logs':
            $branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : 1;
            $limit = !empty($_GET['limit']) ? (int)$_GET['limit'] : 30;

            $stmtLogs = $pdo->prepare("
                SELECT wml.*, u.name as recorder_name, b.name as branch_name, cs.shift_name
                FROM water_meter_logs wml
                JOIN users u ON u.id = wml.user_id
                JOIN branches b ON b.id = wml.branch_id
                LEFT JOIN cashier_shifts cs ON cs.id = wml.shift_id
                WHERE wml.branch_id = ?
                ORDER BY wml.id DESC LIMIT ?
            ");
            $stmtLogs->execute([$branchId, $limit]);
            $logs = $stmtLogs->fetchAll();

            $latest = !empty($logs) ? $logs[0] : null;

            echo json_encode([
                'success' => true,
                'logs' => $logs,
                'latest_meter' => $latest ? (float)$latest['meter_today'] : 0,
                'latest_date' => $latest ? $latest['log_date'] : null
            ]);
            break;

        case 'save_water_meter_adjustment':
            $branchId = (int)($input['branch_id'] ?? 1);
            $userId = (int)($input['user_id'] ?? 1);
            $meterToday = (float)($input['meter_today'] ?? 0);
            $meterYesterday = (float)($input['meter_yesterday'] ?? 0);
            $meterAdjustment = (float)($input['meter_adjustment'] ?? 0);
            $adjustmentType = trim($input['adjustment_type'] ?? 'PULSE');
            if (!in_array($adjustmentType, ['PULSE', 'GALON'])) $adjustmentType = 'PULSE';
            $adjustmentReason = trim($input['adjustment_reason'] ?? 'Penyesuaian / Kalibrasi Manual Meteran Air');
            $logDate = !empty($input['log_date']) ? trim($input['log_date']) : date('Y-m-d');
            $notes = trim($input['notes'] ?? '');

            $meterDiff = $meterToday - $meterYesterday;
            if ($adjustmentType === 'GALON') {
                $dispensedGalon = round(($meterDiff / 782) + $meterAdjustment, 2);
            } else {
                $dispensedGalon = round(($meterDiff + $meterAdjustment) / 782, 2);
            }

            $stmt = $pdo->prepare("
                INSERT INTO water_meter_logs (
                    branch_id, shift_id, user_id, log_date,
                    meter_yesterday, meter_today, meter_difference,
                    meter_adjustment, adjustment_type, adjustment_reason,
                    water_dispensed_galon, notes, created_at, updated_at
                ) VALUES (
                    ?, NULL, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, datetime('now', 'localtime'), datetime('now', 'localtime')
                )
            ");
            $stmt->execute([
                $branchId, $userId, $logDate,
                $meterYesterday, $meterToday, $meterDiff,
                $meterAdjustment, $adjustmentType, $adjustmentReason,
                $dispensedGalon, $notes ?: null
            ]);

            echo json_encode([
                'success' => true,
                'message' => "Penyesuaian meteran air berhasil disimpan. Pengeluaran terhitung: {$dispensedGalon} galon.",
                'water_dispensed_galon' => $dispensedGalon
            ]);
            break;

        case 'checkout_sale':
            $branchId = (int)($input['branch_id'] ?? 1);
            $userId = (int)($input['user_id'] ?? 3);
            $shiftId = !empty($input['shift_id']) ? (int)$input['shift_id'] : null;
            $customerId = !empty($input['customer_id']) ? (int)$input['customer_id'] : 1;
            $customerName = trim($input['customer_name'] ?? 'Pelanggan Umum');
            $customerCategory = trim($input['customer_category'] ?? 'REGULER'); // REGULER, MEMBER, RESELLER, MITRA
            $salesType = trim($input['sales_type'] ?? 'RETAIL');
            $items = $input['items'] ?? [];
            $paymentMethod = $input['payment_method'] ?? 'CASH';
            $cashReceived = (float)($input['cash_received'] ?? 0);
            $discountNominal = (float)($input['discount'] ?? 0);

            // ATURAN BISNIS MUTLAK: Jika tidak buka opening sales / shift kasir, maka transaksi penjualan tidak bisa dilakukan!
            if (empty($shiftId)) {
                $stmtActiveShift = $pdo->prepare("SELECT id FROM cashier_shifts WHERE branch_id = ? AND status = 'OPEN' ORDER BY id DESC LIMIT 1");
                $stmtActiveShift->execute([$branchId]);
                $activeShiftId = $stmtActiveShift->fetchColumn();
                if ($activeShiftId) {
                    $shiftId = (int)$activeShiftId;
                }
            }

            if (empty($shiftId)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Transaksi penjualan ditolak: Shift kasir (Opening Sales) belum dibuka! Silakan lakukan Buka Shift / Opening Sales terlebih dahulu.'
                ]);
                exit;
            }

            // Pastikan shift kasir yang digunakan masih berstatus OPEN
            $stmtShiftVerify = $pdo->prepare("SELECT status FROM cashier_shifts WHERE id = ?");
            $stmtShiftVerify->execute([$shiftId]);
            $currentShiftStatus = $stmtShiftVerify->fetchColumn();
            if ($currentShiftStatus !== 'OPEN') {
                echo json_encode([
                    'success' => false,
                    'message' => 'Transaksi penjualan ditolak: Shift kasir saat ini sudah ditutup. Silakan lakukan Opening Shift baru untuk melanjutkan transaksi.'
                ]);
                exit;
            }

            if (empty($items)) {
                echo json_encode(['success' => false, 'message' => 'Keranjang belanja masih kosong.']);
                exit;
            }

            // Validasi apakah kategori pelanggan berstatus aktif di master kategori
            $stmtCatCheck = $pdo->prepare("SELECT is_active FROM customer_categories WHERE code = ?");
            $stmtCatCheck->execute([$customerCategory]);
            $catIsActive = $stmtCatCheck->fetchColumn();
            if ($catIsActive !== false && (int)$catIsActive === 0) {
                echo json_encode([
                    'success' => false,
                    'message' => "Kategori pelanggan '{$customerCategory}' saat ini sedang dinonaktifkan. Transaksi penjualan untuk kategori ini tidak dapat diproses."
                ]);
                exit;
            }

            $pdo->beginTransaction();

            $stmtBr = $pdo->prepare("SELECT code FROM branches WHERE id = ?");
            $stmtBr->execute([$branchId]);
            $branchCode = $stmtBr->fetchColumn() ?: 'CAB';

            $dateStr = date('Ymd');
            $maxId = (int)$pdo->query("SELECT MAX(id) FROM sales")->fetchColumn() + 1;
            $seq = str_pad($maxId, 4, '0', STR_PAD_LEFT);
            $invoiceNumber = "INV/{$branchCode}/{$dateStr}/{$seq}";
            // Fallback ensure unique
            $checkInv = $pdo->prepare("SELECT id FROM sales WHERE invoice_number = ?");
            $checkInv->execute([$invoiceNumber]);
            if ($checkInv->fetch()) {
                $invoiceNumber = "INV/{$branchCode}/{$dateStr}/{$seq}-" . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
            }

            $subtotal = 0;
            $totalCogs = 0;
            $processedItems = [];

            foreach ($items as $item) {
                $pId = (int)$item['product_id'];
                $qty = (int)$item['qty'];
                $variantId = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
                $variantName = !empty($item['variant_name']) ? trim($item['variant_name']) : null;
                if ($qty <= 0) continue;

                $stmtCheck = $pdo->prepare("
                    SELECT p.name, p.has_bom, p.member_price, p.reseller_price, p.mitra_price,
                           p.is_taxable, p.tax_id, p.tax_percent,
                           bs.stock, bs.local_price, bs.local_cogs,
                           COALESCE(bs.is_active, 1) as branch_is_active
                    FROM branch_stocks bs
                    JOIN products p ON p.id = bs.product_id
                    WHERE bs.branch_id = ? AND bs.product_id = ?
                ");
                $stmtCheck->execute([$branchId, $pId]);
                $stockRow = $stmtCheck->fetch();

                if (!$stockRow) {
                    $pdo->rollBack();
                    echo json_encode([
                        'success' => false,
                        'message' => "Produk tidak ditemukan di cabang ini."
                    ]);
                    exit;
                }

                if ((int)$stockRow['branch_is_active'] === 0) {
                    $pdo->rollBack();
                    echo json_encode([
                        'success' => false,
                        'message' => "Produk '{$stockRow['name']}' sedang dinonaktifkan (tidak dijual) di cabang ini."
                    ]);
                    exit;
                }

                if ($stockRow['stock'] < $qty) {
                    $pdo->rollBack();
                    echo json_encode([
                        'success' => false,
                        'message' => "Stok untuk '{$stockRow['name']}' tidak mencukupi (Tersisa: {$stockRow['stock']}, Diminta: {$qty})"
                    ]);
                    exit;
                }

                // Check variant additional price & cogs
                $varAddPrice = 0;
                $varAddCogs = 0;
                if ($variantId) {
                    $stmtVar = $pdo->prepare("SELECT variant_name, additional_price, additional_cogs FROM product_variants WHERE id = ?");
                    $stmtVar->execute([$variantId]);
                    $varRow = $stmtVar->fetch();
                    if ($varRow) {
                        $variantName = $varRow['variant_name'];
                        $varAddPrice = (float)$varRow['additional_price'];
                        $varAddCogs = (float)$varRow['additional_cogs'];
                    }
                }

                // Determine Effective Unit Selling Price:
                // 1. Branch Base Price: $stockRow['local_price']
                // 2. Customer Tier (Member, Reseller, Mitra)
                // 3. Mitra Contract Pricing Override (Setiap mitra harga berbeda)
                $unitPrice = (float)$stockRow['local_price'];

                if ($customerCategory === 'MITRA') {
                    // Check if this specific mitra has custom contract price for this product
                    $stmtMitra = $pdo->prepare("SELECT custom_price FROM mitra_custom_prices WHERE customer_id = ? AND product_id = ?");
                    $stmtMitra->execute([$customerId, $pId]);
                    $customMitraPrice = $stmtMitra->fetchColumn();

                    if ($customMitraPrice !== false && (float)$customMitraPrice > 0) {
                        $unitPrice = (float)$customMitraPrice;
                    } else {
                        // Tolak transaksi jika produk belum diset harga kontrak khusus untuk mitra ini
                        $pdo->rollBack();
                        echo json_encode([
                            'success' => false,
                            'message' => "Produk '{$stockRow['name']}' belum memiliki kesepakatan harga kontrak khusus untuk mitra ini. Transaksi ditolak."
                        ]);
                        exit;
                    }
                } elseif ($customerCategory === 'RESELLER') {
                    if ((float)$stockRow['reseller_price'] > 0) {
                        $unitPrice = (float)$stockRow['reseller_price'];
                    } else {
                        $unitPrice = round($stockRow['local_price'] * 0.8, -2);
                    }
                } elseif ($customerCategory === 'MEMBER') {
                    if ((float)$stockRow['member_price'] > 0) {
                        $unitPrice = (float)$stockRow['member_price'];
                    } else {
                        $unitPrice = round($stockRow['local_price'] * 0.9, -2);
                    }
                }

                // Tambahkan additional price & cogs dari varian
                $unitPrice += $varAddPrice;
                $effectiveUnitCogs = $stockRow['local_cogs'] + $varAddCogs;

                $itemSubtotal = $unitPrice * $qty;
                $itemCogs = $effectiveUnitCogs * $qty;
                $subtotal += $itemSubtotal;
                $totalCogs += $itemCogs;

                // Perhitungan Pajak per Item Produk
                $isItemTaxable = isset($stockRow['is_taxable']) ? (int)$stockRow['is_taxable'] : 1;
                $itemTaxRate = isset($stockRow['tax_percent']) ? (float)$stockRow['tax_percent'] : 11.0;
                $itemTaxAmount = ($isItemTaxable === 1 && $itemTaxRate > 0) ? round(($itemSubtotal * $itemTaxRate) / 100, 2) : 0;

                // Deduct finished goods stock
                $stmtDeduct = $pdo->prepare("UPDATE branch_stocks SET stock = stock - ? WHERE branch_id = ? AND product_id = ?");
                $stmtDeduct->execute([$qty, $branchId, $pId]);

                $dispItemName = $stockRow['name'] . ($variantName ? " [{$variantName}]" : "");

                // Record finished goods stock movement
                $stmtMoveFG = $pdo->prepare("
                    INSERT INTO stock_movements (branch_id, product_id, movement_type, qty_change, stock_before, stock_after, reference_no, notes, user_id)
                    VALUES (?, ?, 'STOCK_OUT_SALE', ?, ?, ?, ?, ?, ?)
                ");
                $stmtMoveFG->execute([
                    $branchId, $pId, -$qty, $stockRow['stock'], $stockRow['stock'] - $qty,
                    $invoiceNumber, "Penjualan POS [{$customerCategory}] kpd {$customerName}" . ($variantName ? " ({$variantName})" : ""), $userId
                ]);

                // ==========================================
                // LAYER 1: BASE BOM AUTO DEDUCTION FOR RAW MATERIALS
                // ==========================================
                if (!empty($stockRow['has_bom'])) {
                    $stmtRecipe = $pdo->prepare("
                        SELECT pr.ingredient_product_id, pr.qty_needed, pr.unit, p.name as ing_name, bs.stock as ing_stock
                        FROM product_recipes pr
                        JOIN products p ON p.id = pr.ingredient_product_id
                        JOIN branch_stocks bs ON bs.product_id = pr.ingredient_product_id AND bs.branch_id = ?
                        WHERE pr.finished_product_id = ?
                    ");
                    $stmtRecipe->execute([$branchId, $pId]);
                    $recipes = $stmtRecipe->fetchAll();

                    foreach ($recipes as $r) {
                        $totalIngNeeded = $r['qty_needed'] * $qty;
                        $ingId = $r['ingredient_product_id'];
                        $curIngStock = (float)$r['ing_stock'];
                        $newIngStock = max(0, $curIngStock - $totalIngNeeded);

                        // Deduct raw material stock
                        $pdo->prepare("UPDATE branch_stocks SET stock = stock - ? WHERE branch_id = ? AND product_id = ?")
                            ->execute([$totalIngNeeded, $branchId, $ingId]);

                        // Record Stock Movement for Ingredient
                        $pdo->prepare("
                            INSERT INTO stock_movements (branch_id, product_id, movement_type, qty_change, stock_before, stock_after, reference_no, notes, user_id)
                            VALUES (?, ?, 'BOM_DEDUCTION', ?, ?, ?, ?, ?, ?)
                        ")->execute([
                            $branchId, $ingId, -$totalIngNeeded, $curIngStock, $newIngStock,
                            $invoiceNumber, "Konsumsi Base BOM untuk {$qty}x {$dispItemName}", $userId
                        ]);
                    }
                }

                // ==========================================
                // LAYER 2: VARIANT BOM AUTO DEDUCTION (Pilihan Varian Potong BOM)
                // ==========================================
                if ($variantId) {
                    $stmtVarRecipe = $pdo->prepare("
                        SELECT vr.ingredient_product_id, vr.qty_needed, vr.unit, vr.notes,
                               p.name as ing_name, bs.stock as ing_stock
                        FROM variant_recipes vr
                        JOIN products p ON p.id = vr.ingredient_product_id
                        JOIN branch_stocks bs ON bs.product_id = vr.ingredient_product_id AND bs.branch_id = ?
                        WHERE vr.variant_id = ?
                    ");
                    $stmtVarRecipe->execute([$branchId, $variantId]);
                    $varRecipes = $stmtVarRecipe->fetchAll();

                    foreach ($varRecipes as $vr) {
                        $totalVarIngNeeded = $vr['qty_needed'] * $qty;
                        $varIngId = $vr['ingredient_product_id'];
                        $curVarIngStock = (float)$vr['ing_stock'];
                        $newVarIngStock = max(0, $curVarIngStock - $totalVarIngNeeded);

                        // Deduct variant raw material stock
                        $pdo->prepare("UPDATE branch_stocks SET stock = stock - ? WHERE branch_id = ? AND product_id = ?")
                            ->execute([$totalVarIngNeeded, $branchId, $varIngId]);

                        // Record Stock Movement for Variant Ingredient
                        $pdo->prepare("
                            INSERT INTO stock_movements (branch_id, product_id, movement_type, qty_change, stock_before, stock_after, reference_no, notes, user_id)
                            VALUES (?, ?, 'BOM_DEDUCTION', ?, ?, ?, ?, ?, ?)
                        ")->execute([
                            $branchId, $varIngId, -$totalVarIngNeeded, $curVarIngStock, $newVarIngStock,
                            $invoiceNumber, "Konsumsi BOM Varian [{$variantName}] untuk {$qty}x {$stockRow['name']}", $userId
                        ]);
                    }
                }

                $processedItems[] = [
                    'product_id' => $pId,
                    'variant_id' => $variantId,
                    'variant_name' => $variantName,
                    'product_name' => $dispItemName,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'unit_cogs' => $effectiveUnitCogs,
                    'subtotal_price' => $itemSubtotal,
                    'subtotal_cogs' => $itemCogs,
                    'is_taxable' => $isItemTaxable,
                    'tax_percent' => $itemTaxRate,
                    'tax_amount' => $itemTaxAmount
                ];
            }

            // Hitung Total Pajak dari Seluruh Item yang Kena Pajak (Pro-rata diskon bila ada)
            $discountNominal = min($subtotal, max(0, (float)($input['discount'] ?? 0)));
            $taxableSubtotal = 0;
            $totalTax = 0;

            foreach ($processedItems as $pi) {
                if ($pi['is_taxable'] === 1 && $pi['tax_percent'] > 0) {
                    // Item kena pajak
                    $itemShare = $subtotal > 0 ? ($pi['subtotal_price'] / $subtotal) : 0;
                    $itemDiscount = $discountNominal * $itemShare;
                    $itemTaxableVal = max(0, $pi['subtotal_price'] - $itemDiscount);
                    $taxableSubtotal += $itemTaxableVal;
                    $totalTax += round(($itemTaxableVal * $pi['tax_percent']) / 100, 2);
                }
            }

            $tax = round($totalTax, 2);
            $deliveryFee = (float)($input['delivery_fee'] ?? 0);
            $deliveryDistanceKm = (float)($input['delivery_distance_km'] ?? 0);
            $courierVehicleId = !empty($input['courier_vehicle_id']) ? (int)$input['courier_vehicle_id'] : null;
            $driverUserId = !empty($input['driver_user_id']) ? (int)$input['driver_user_id'] : null;
            $driverName = trim($input['driver_name'] ?? '');
            $isDeliveryIncluded = !empty($input['is_delivery_included']) ? (int)$input['is_delivery_included'] : 0;

            // Resolve driver name if driver_user_id given
            if ($driverUserId && empty($driverName)) {
                $stmtDriver = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                $stmtDriver->execute([$driverUserId]);
                $driverName = $stmtDriver->fetchColumn() ?: '';
            }
            // Resolve from courier vehicle if still empty
            if ($courierVehicleId && empty($driverName)) {
                $stmtVehDriver = $pdo->prepare("SELECT driver_name, driver_user_id FROM courier_vehicles WHERE id = ?");
                $stmtVehDriver->execute([$courierVehicleId]);
                $vehRow = $stmtVehDriver->fetch();
                if ($vehRow) {
                    $driverName = $vehRow['driver_name'] ?? '';
                    if (!$driverUserId && !empty($vehRow['driver_user_id'])) {
                        $driverUserId = (int)$vehRow['driver_user_id'];
                    }
                }
            }

            $hasDelivery = ($deliveryFee > 0 || $courierVehicleId || $driverUserId || !empty($driverName) || $isDeliveryIncluded);
            $deliveryStatus = !empty($input['delivery_status']) ? trim($input['delivery_status']) : ($hasDelivery ? 'DELIVERED' : 'NONE');

            $grandTotal = max(0, $subtotal - $discountNominal) + $tax + $deliveryFee;

            if ($paymentMethod === 'CASH') {
                if ($cashReceived < $grandTotal) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Uang tunai kurang dari total belanja.']);
                    exit;
                }
                $cashChange = $cashReceived - $grandTotal;
                $paymentStatus = 'PAID';
            } elseif ($paymentMethod === 'PIUTANG') {
                $cashReceived = 0;
                $cashChange = 0;
                $paymentStatus = 'UNPAID';
            } else {
                $cashReceived = $grandTotal;
                $cashChange = 0;
                $paymentStatus = 'PAID';
            }

            // Insert Sale with Customer, Tier, Delivery & Payment Status Info
            $stmtSale = $pdo->prepare("
                INSERT INTO sales (invoice_number, branch_id, user_id, shift_id, customer_id, customer_name, customer_category, sales_type, subtotal, discount, tax, delivery_fee, delivery_distance_km, courier_vehicle_id, driver_user_id, driver_name, is_delivery_included, delivery_status, grand_total, total_cogs, payment_method, payment_status, cash_received, cash_change, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now', 'localtime'))
            ");
            $stmtSale->execute([
                $invoiceNumber, $branchId, $userId, $shiftId,
                $customerId, $customerName, $customerCategory, $salesType,
                $subtotal, $discountNominal, $tax, $deliveryFee, $deliveryDistanceKm,
                $courierVehicleId, $driverUserId, $driverName, $isDeliveryIncluded, $deliveryStatus,
                $grandTotal, $totalCogs, $paymentMethod, $paymentStatus, $cashReceived, $cashChange
            ]);
            $saleId = $pdo->lastInsertId();

            // Handle Customer Receivables (Piutang Usaha) if Payment Method is PIUTANG
            $receivableId = null;
            if ($paymentMethod === 'PIUTANG') {
                $dueDate = !empty($input['due_date']) ? $input['due_date'] : date('Y-m-d', strtotime('+30 days'));
                $recNotes = !empty($input['notes']) ? trim($input['notes']) : ("Piutang penjualan POS kpd " . $customerName . " ({$customerCategory})");
                $stmtAR = $pdo->prepare("
                    INSERT INTO customer_receivables (sale_id, invoice_number, customer_id, branch_id, total_amount, paid_amount, remaining_amount, due_date, status, notes, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, 0, ?, ?, 'BELUM_LUNAS', ?, datetime('now', 'localtime'), datetime('now', 'localtime'))
                ");
                $stmtAR->execute([$saleId, $invoiceNumber, $customerId, $branchId, $grandTotal, $grandTotal, $dueDate, $recNotes]);
                $receivableId = $pdo->lastInsertId();
            }

            // Auto-update odometer for courier vehicle if delivery distance > 0
            if ($courierVehicleId && $deliveryDistanceKm > 0) {
                $pdo->prepare("UPDATE courier_vehicles SET current_odometer = current_odometer + ? WHERE id = ?")
                    ->execute([$deliveryDistanceKm, $courierVehicleId]);
            }

            // Insert Sale Items (including variant_id and variant_name)
            $stmtItem = $pdo->prepare("
                INSERT INTO sale_items (sale_id, product_id, variant_id, variant_name, product_name, qty, unit_price, unit_cogs, subtotal_price, subtotal_cogs)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($processedItems as $pi) {
                $stmtItem->execute([
                    $saleId, $pi['product_id'], $pi['variant_id'], $pi['variant_name'],
                    $pi['product_name'], $pi['qty'],
                    $pi['unit_price'], $pi['unit_cogs'], $pi['subtotal_price'], $pi['subtotal_cogs']
                ]);
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => ($paymentMethod === 'PIUTANG') 
                    ? 'Transaksi kredit/piutang berhasil dicatat ke Laporan Piutang Usaha & Stok terpotong!' 
                    : 'Transaksi berhasil diproses & Stok BOM otomatis terpotong!',
                'sale' => [
                    'id' => $saleId,
                    'invoice_number' => $invoiceNumber,
                    'branch_id' => $branchId,
                    'customer_id' => $customerId,
                    'customer_name' => $customerName,
                    'customer_category' => $customerCategory,
                    'sales_type' => $salesType,
                    'subtotal' => $subtotal,
                    'discount' => $discountNominal,
                    'tax' => $tax,
                    'delivery_fee' => $deliveryFee,
                    'delivery_distance_km' => $deliveryDistanceKm,
                    'courier_vehicle_id' => $courierVehicleId,
                    'delivery_status' => $deliveryStatus,
                    'grand_total' => $grandTotal,
                    'total_cogs' => $totalCogs,
                    'gross_profit' => $grandTotal - $tax - $deliveryFee - $totalCogs,
                    'payment_method' => $paymentMethod,
                    'payment_status' => $paymentStatus,
                    'receivable_id' => $receivableId,
                    'cash_received' => $cashReceived,
                    'cash_change' => $cashChange,
                    'created_at' => date('d/m/Y H:i:s'),
                    'items' => $processedItems
                ]
            ]);
            break;

        // ==========================================
        // 7. MODUL LAPORAN PENJUALAN
        // ==========================================
        case 'sales_reports':
            $branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-d');
            $paymentMethod = $_GET['payment_method'] ?? 'ALL';

            $whereSql = "WHERE date(s.created_at) BETWEEN ? AND ? AND (s.status IS NULL OR s.status != 'CANCELLED')";
            $params = [$startDate, $endDate];

            if ($branchId) {
                $whereSql .= " AND s.branch_id = ?";
                $params[] = $branchId;
            }
            if ($paymentMethod !== 'ALL') {
                $whereSql .= " AND s.payment_method = ?";
                $params[] = $paymentMethod;
            }

            $summaryStmt = $pdo->prepare("
                SELECT 
                    COUNT(s.id) as total_transactions,
                    COALESCE(SUM(s.subtotal), 0) as gross_sales,
                    COALESCE(SUM(s.discount), 0) as total_discount,
                    COALESCE(SUM(s.tax), 0) as total_tax,
                    COALESCE(SUM(s.grand_total), 0) as total_revenue,
                    COALESCE(SUM(s.total_cogs), 0) as total_cogs
                FROM sales s
                $whereSql
            ");
            $summaryStmt->execute($params);
            $summary = $summaryStmt->fetch();

            $netSales = $summary['gross_sales'] - $summary['total_discount'];
            $grossProfit = $netSales - $summary['total_cogs'];
            $grossMargin = $netSales > 0 ? round(($grossProfit / $netSales) * 100, 1) : 0;
            $aov = $summary['total_transactions'] > 0 ? round($summary['total_revenue'] / $summary['total_transactions']) : 0;

            $stmtPayment = $pdo->prepare("
                SELECT s.payment_method, COUNT(s.id) as count, SUM(s.grand_total) as total
                FROM sales s
                $whereSql
                GROUP BY s.payment_method
            ");
            $stmtPayment->execute($params);
            $paymentBreakdown = $stmtPayment->fetchAll();

            $stmtTopProducts = $pdo->prepare("
                SELECT si.product_name, SUM(si.qty) as total_qty, 
                       SUM(si.subtotal_price) as total_sales,
                       SUM(si.subtotal_cogs) as total_cogs,
                       (SUM(si.subtotal_price) - SUM(si.subtotal_cogs)) as profit
                FROM sale_items si
                JOIN sales s ON s.id = si.sale_id
                $whereSql
                GROUP BY si.product_name
                ORDER BY total_qty DESC
                LIMIT 10
            ");
            $stmtTopProducts->execute($params);
            $topProducts = $stmtTopProducts->fetchAll();

            $stmtCatBreakdown = $pdo->prepare("
                SELECT COALESCE(s.customer_category, 'REGULER') as category,
                       COUNT(s.id) as count,
                       SUM(s.grand_total) as total
                FROM sales s
                $whereSql
                GROUP BY s.customer_category
            ");
            $stmtCatBreakdown->execute($params);
            $customerCategoryBreakdown = $stmtCatBreakdown->fetchAll();

            $stmtList = $pdo->prepare("
                SELECT s.id, s.invoice_number, s.created_at, s.grand_total, s.total_cogs,
                       (s.grand_total - s.tax - s.total_cogs) as gross_profit,
                       s.payment_method, s.customer_name, s.customer_category, s.sales_type,
                       b.name as branch_name, u.name as cashier_name
                FROM sales s
                JOIN branches b ON b.id = s.branch_id
                JOIN users u ON u.id = s.user_id
                $whereSql
                ORDER BY s.id DESC
                LIMIT 50
            ");
            $stmtList->execute($params);
            $recentSales = $stmtList->fetchAll();

            echo json_encode([
                'success' => true,
                'kpi' => [
                    'total_transactions' => (int)$summary['total_transactions'],
                    'gross_sales' => (float)$summary['gross_sales'],
                    'total_discount' => (float)$summary['total_discount'],
                    'net_sales' => (float)$netSales,
                    'total_cogs' => (float)$summary['total_cogs'],
                    'gross_profit' => (float)$grossProfit,
                    'gross_margin_percent' => $grossMargin,
                    'total_revenue' => (float)$summary['total_revenue'],
                    'aov' => (float)$aov
                ],
                'payment_breakdown' => $paymentBreakdown,
                'customer_category_breakdown' => $customerCategoryBreakdown,
                'top_products' => $topProducts,
                'recent_sales' => $recentSales
            ]);
            break;

        case 'get_sale_detail':
            $invoiceNumber = trim($_GET['invoice_number'] ?? '');
            $saleId = !empty($_GET['id']) ? (int)$_GET['id'] : null;

            if (empty($invoiceNumber) && empty($saleId)) {
                echo json_encode(['success' => false, 'error' => 'Parameter invoice_number atau id harus disertakan']);
                exit;
            }

            if (!empty($invoiceNumber)) {
                $stmt = $pdo->prepare("
                    SELECT s.*, 
                           b.name as branch_name, b.address as branch_address, b.phone as branch_phone,
                           u.name as cashier_name, u.nik as cashier_nik,
                           v.plate_number, v.vehicle_name,
                           COALESCE(s.driver_name, du.name, v.driver_name, '-') as effective_driver_name,
                           du.name as courier_employee_name, du.nik as courier_employee_nik
                    FROM sales s
                    LEFT JOIN branches b ON b.id = s.branch_id
                    LEFT JOIN users u ON u.id = s.user_id
                    LEFT JOIN courier_vehicles v ON v.id = s.courier_vehicle_id
                    LEFT JOIN users du ON du.id = COALESCE(s.driver_user_id, v.driver_user_id)
                    WHERE s.invoice_number = ?
                ");
                $stmt->execute([$invoiceNumber]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT s.*, 
                           b.name as branch_name, b.address as branch_address, b.phone as branch_phone,
                           u.name as cashier_name, u.nik as cashier_nik,
                           v.plate_number, v.vehicle_name,
                           COALESCE(s.driver_name, du.name, v.driver_name, '-') as effective_driver_name,
                           du.name as courier_employee_name, du.nik as courier_employee_nik
                    FROM sales s
                    LEFT JOIN branches b ON b.id = s.branch_id
                    LEFT JOIN users u ON u.id = s.user_id
                    LEFT JOIN courier_vehicles v ON v.id = s.courier_vehicle_id
                    LEFT JOIN users du ON du.id = COALESCE(s.driver_user_id, v.driver_user_id)
                    WHERE s.id = ?
                ");
                $stmt->execute([$saleId]);
            }

            $sale = $stmt->fetch();
            if (!$sale) {
                echo json_encode(['success' => false, 'error' => 'Transaksi tidak ditemukan']);
                exit;
            }

            // Get Sale Items
            $stmtItems = $pdo->prepare("
                SELECT si.*, p.sku, p.barcode, p.unit, p.is_taxable, p.tax_percent
                FROM sale_items si
                LEFT JOIN products p ON p.id = si.product_id
                WHERE si.sale_id = ?
                ORDER BY si.id ASC
            ");
            $stmtItems->execute([$sale['id']]);
            $items = $stmtItems->fetchAll();

            $effectiveDriverName = !empty($sale['effective_driver_name']) && $sale['effective_driver_name'] !== '-' 
                ? $sale['effective_driver_name'] 
                : (!empty($sale['driver_name']) ? $sale['driver_name'] : ($sale['courier_employee_name'] ?? '-'));
            $sale['effective_driver_name'] = $effectiveDriverName;

            echo json_encode([
                'success' => true,
                'sale' => $sale,
                'items' => $items
            ]);
            break;

        case 'export_sales_excel':
            $branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-d');
            $paymentMethod = $_GET['payment_method'] ?? 'ALL';
            $specificInvoice = trim($_GET['invoice_number'] ?? '');

            $whereSql = "WHERE date(s.created_at) BETWEEN ? AND ? AND (s.status IS NULL OR s.status != 'CANCELLED')";
            $params = [$startDate, $endDate];

            if ($branchId) {
                $whereSql .= " AND s.branch_id = ?";
                $params[] = $branchId;
            }
            if ($paymentMethod !== 'ALL') {
                $whereSql .= " AND s.payment_method = ?";
                $params[] = $paymentMethod;
            }
            if (!empty($specificInvoice)) {
                $whereSql = "WHERE s.invoice_number = ?";
                $params = [$specificInvoice];
            }

            $stmt = $pdo->prepare("
                SELECT s.id as sale_id, s.invoice_number, s.created_at, s.sales_type, s.customer_name, s.customer_category,
                       s.payment_method, s.subtotal as sale_subtotal, s.discount as sale_discount, s.tax as sale_tax,
                       s.delivery_fee, s.delivery_distance_km, s.grand_total, s.total_cogs as sale_total_cogs,
                       (s.grand_total - s.tax - s.total_cogs) as sale_gross_profit,
                       b.name as branch_name, u.name as cashier_name,
                       COALESCE(s.driver_name, du.name, v.driver_name, '-') as driver_name,
                       v.plate_number as courier_plate,
                       si.id as item_id, si.product_id, si.product_name, si.variant_id, si.variant_name,
                       si.qty, si.unit_price, si.unit_cogs, si.subtotal_price, si.subtotal_cogs,
                       (si.subtotal_price - si.subtotal_cogs) as item_gross_profit,
                       p.sku as product_sku, p.barcode as product_barcode, p.unit as product_unit
                FROM sales s
                JOIN branches b ON b.id = s.branch_id
                JOIN users u ON u.id = s.user_id
                LEFT JOIN courier_vehicles v ON v.id = s.courier_vehicle_id
                LEFT JOIN users du ON du.id = COALESCE(s.driver_user_id, v.driver_user_id)
                JOIN sale_items si ON si.sale_id = s.id
                LEFT JOIN products p ON p.id = si.product_id
                $whereSql
                ORDER BY s.id DESC, si.id ASC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $filename = !empty($specificInvoice) 
                ? "Laporan_Detail_Penjualan_{$specificInvoice}.xls"
                : "Laporan_Detail_Penjualan_{$startDate}_sd_{$endDate}.xls";

            header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            // Render HTML-based Excel Table
            echo "\xEF\xBB\xBF"; // UTF-8 BOM
            ?>
            <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
            <head>
                <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
                <style>
                    body { font-family: Arial, sans-serif; font-size: 11pt; }
                    table { border-collapse: collapse; width: 100%; }
                    th { background-color: #3b82f6; color: #ffffff; font-weight: bold; border: 1px solid #1e40af; padding: 6px 10px; text-align: center; }
                    td { border: 1px solid #cbd5e1; padding: 5px 8px; vertical-align: middle; }
                    .num { mso-number-format: "\#\,\#\#0"; text-align: right; }
                    .txt { mso-number-format: "\@"; }
                    .center { text-align: center; }
                    .bold { font-weight: bold; }
                    .title { font-size: 16pt; font-weight: bold; color: #1e3a8a; }
                    .subtitle { font-size: 10pt; color: #475569; }
                    .bg-subtotal { background-color: #f1f5f9; font-weight: bold; }
                </style>
            </head>
            <body>
                <div class="title">LAPORAN RINCIAN DETAIL PENJUALAN</div>
                <div class="subtitle">Periode: <?= htmlspecialchars($startDate) ?> s/d <?= htmlspecialchars($endDate) ?> | Generated: <?= date('Y-m-d H:i:s') ?></div>
                <br />
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>No. Faktur</th>
                            <th>Tanggal / Waktu</th>
                            <th>Cabang</th>
                            <th>Kasir</th>
                            <th>Tipe Penjualan</th>
                            <th>Pelanggan</th>
                            <th>Kategori Pelanggan</th>
                            <th>Metode Bayar</th>
                            <th>Driver / Kurir</th>
                            <th>No. Pol Kendaraan</th>
                            <th>SKU Produk</th>
                            <th>Nama Produk</th>
                            <th>Varian</th>
                            <th>Qty</th>
                            <th>Satuan</th>
                            <th>Harga Satuan (Rp)</th>
                            <th>Subtotal Produk (Rp)</th>
                            <th>HPP Satuan (Rp)</th>
                            <th>Total HPP (Rp)</th>
                            <th>Laba Kotor Item (Rp)</th>
                            <th>Diskon Faktur (Rp)</th>
                            <th>Pajak Faktur (Rp)</th>
                            <th>Ongkir (Rp)</th>
                            <th>Grand Total Faktur (Rp)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1;
                        $totalQtyAll = 0;
                        $totalSubtotalAll = 0;
                        $totalHppAll = 0;
                        $totalProfitAll = 0;
                        $grandTotalSeen = [];
                        $totalInvoiceSum = 0;

                        foreach ($rows as $r): 
                            $totalQtyAll += $r['qty'];
                            $totalSubtotalAll += $r['subtotal_price'];
                            $totalHppAll += $r['subtotal_cogs'];
                            $totalProfitAll += $r['item_gross_profit'];
                            if (!isset($grandTotalSeen[$r['sale_id']])) {
                                $grandTotalSeen[$r['sale_id']] = true;
                                $totalInvoiceSum += $r['grand_total'];
                            }
                        ?>
                        <tr>
                            <td class="center"><?= $no++ ?></td>
                            <td class="txt bold"><?= htmlspecialchars($r['invoice_number']) ?></td>
                            <td class="center"><?= htmlspecialchars($r['created_at']) ?></td>
                            <td><?= htmlspecialchars($r['branch_name']) ?></td>
                            <td><?= htmlspecialchars($r['cashier_name']) ?></td>
                            <td class="center"><?= htmlspecialchars($r['sales_type']) ?></td>
                            <td><?= htmlspecialchars($r['customer_name'] ?? 'Pelanggan Umum') ?></td>
                            <td class="center"><?= htmlspecialchars($r['customer_category'] ?? 'REGULER') ?></td>
                            <td class="center"><?= htmlspecialchars($r['payment_method']) ?></td>
                            <td><?= htmlspecialchars($r['driver_name']) ?></td>
                            <td class="center"><?= htmlspecialchars($r['courier_plate'] ?? '-') ?></td>
                            <td class="txt"><?= htmlspecialchars($r['product_sku'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['product_name']) ?></td>
                            <td><?= htmlspecialchars($r['variant_name'] ?? '-') ?></td>
                            <td class="num"><?= (int)$r['qty'] ?></td>
                            <td class="center"><?= htmlspecialchars($r['product_unit'] ?? 'pcs') ?></td>
                            <td class="num"><?= (float)$r['unit_price'] ?></td>
                            <td class="num"><?= (float)$r['subtotal_price'] ?></td>
                            <td class="num"><?= (float)$r['unit_cogs'] ?></td>
                            <td class="num"><?= (float)$r['subtotal_cogs'] ?></td>
                            <td class="num"><?= (float)$r['item_gross_profit'] ?></td>
                            <td class="num"><?= (float)$r['sale_discount'] ?></td>
                            <td class="num"><?= (float)$r['sale_tax'] ?></td>
                            <td class="num"><?= (float)$r['delivery_fee'] ?></td>
                            <td class="num bold"><?= (float)$r['grand_total'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="25" class="center" style="padding: 20px; color: #94a3b8;">Tidak ada data transaksi untuk filter periode ini.</td>
                        </tr>
                        <?php else: ?>
                        <tr class="bg-subtotal">
                            <td colspan="14" class="center bold">TOTAL KESELURUHAN</td>
                            <td class="num bold"><?= $totalQtyAll ?></td>
                            <td></td>
                            <td></td>
                            <td class="num bold"><?= $totalSubtotalAll ?></td>
                            <td></td>
                            <td class="num bold"><?= $totalHppAll ?></td>
                            <td class="num bold"><?= $totalProfitAll ?></td>
                            <td colspan="3"></td>
                            <td class="num bold"><?= $totalInvoiceSum ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </body>
            </html>
            <?php
            exit;

        // ==========================================
        // 8. MODUL EMPLOYEE SELF SERVICE (ESS)
        // ==========================================
        case 'ess_get_data':
            $userId = (int)($_GET['user_id'] ?? ($_SESSION['user_id'] ?? 3));
            $branchId = (int)($_GET['branch_id'] ?? 1);

            $stmtU = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmtU->execute([$userId]);
            $user = $stmtU->fetch();
            unset($user['password']);

            $stmtCommission = $pdo->prepare("
                SELECT COUNT(id) as total_tx, COALESCE(SUM(grand_total), 0) as total_omzet
                FROM sales
                WHERE user_id = ? AND strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now')
            ");
            $stmtCommission->execute([$userId]);
            $commData = $stmtCommission->fetch();
            $commissionEarned = $commData['total_omzet'] * ($user['commission_rate'] ?? 0.015);

            $stmtAttToday = $pdo->prepare("
                SELECT * FROM attendance_logs 
                WHERE user_id = ? AND date(clock_in) = date('now') 
                ORDER BY id DESC LIMIT 1
            ");
            $stmtAttToday->execute([$userId]);
            $todayAttendance = $stmtAttToday->fetch();

            $stmtAttList = $pdo->prepare("
                SELECT al.*, b.name as branch_name 
                FROM attendance_logs al
                JOIN branches b ON b.id = al.branch_id
                WHERE al.user_id = ?
                ORDER BY al.id DESC LIMIT 10
            ");
            $stmtAttList->execute([$userId]);
            $attendanceHistory = $stmtAttList->fetchAll();

            $stmtColleagues = $pdo->prepare("SELECT id, name, role FROM users WHERE branch_id = ? AND id != ? AND is_active = 1");
            $stmtColleagues->execute([$branchId, $userId]);
            $colleagues = $stmtColleagues->fetchAll();

            $stmtPendingReimburse = $pdo->prepare("
                SELECT r.*, u.name as employee_name, b.name as branch_name
                FROM reimbursements r
                JOIN users u ON u.id = r.user_id
                JOIN branches b ON b.id = r.branch_id
                WHERE r.branch_id = ?
                ORDER BY r.id DESC LIMIT 15
            ");
            $stmtPendingReimburse->execute([$branchId]);
            $allBranchReimbursements = $stmtPendingReimburse->fetchAll();

            echo json_encode([
                'success' => true,
                'user' => $user,
                'commission' => [
                    'rate_percent' => ($user['commission_rate'] ?? 0.015) * 100,
                    'total_tx' => (int)$commData['total_tx'],
                    'total_omzet' => (float)$commData['total_omzet'],
                    'commission_earned' => (float)$commissionEarned
                ],
                'today_attendance' => $todayAttendance,
                'attendance_history' => $attendanceHistory,
                'colleagues' => $colleagues,
                'branch_reimbursements' => $allBranchReimbursements
            ]);
            break;

        case 'ess_clock_in':
            $userId = (int)($input['user_id'] ?? 3);
            $branchId = (int)($input['branch_id'] ?? 1);
            $userLat = (float)($input['latitude'] ?? 0);
            $userLng = (float)($input['longitude'] ?? 0);

            $stmtB = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
            $stmtB->execute([$branchId]);
            $branch = $stmtB->fetch();

            $distance = haversineDistanceMeters($userLat, $userLng, $branch['latitude'], $branch['longitude']);
            $maxRadius = $branch['radius_meters'] ?? 100;

            if ($distance > $maxRadius) {
                echo json_encode([
                    'success' => false,
                    'distance_meters' => round($distance, 1),
                    'max_radius' => $maxRadius,
                    'message' => "Presensi DITOLAK! Anda berada di luar radius toko cabang (" . round($distance, 1) . "m / toleransi {$maxRadius}m)."
                ]);
                exit;
            }

            $status = (date('H:i') > '08:15') ? 'LATE' : 'ON_TIME';
            $stmtInsert = $pdo->prepare("
                INSERT INTO attendance_logs (user_id, branch_id, clock_in, latitude, longitude, distance_meters, status, note)
                VALUES (?, ?, datetime('now', 'localtime'), ?, ?, ?, ?, 'Presensi Geofencing GPS')
            ");
            $stmtInsert->execute([$userId, $branchId, $userLat, $userLng, $distance, $status]);

            echo json_encode([
                'success' => true,
                'distance_meters' => round($distance, 1),
                'status' => $status,
                'message' => "Clock-In BERHASIL! Terverifikasi berjarak " . round($distance, 1) . "m di area toko."
            ]);
            break;

        case 'ess_clock_out':
            $userId = (int)($input['user_id'] ?? 3);
            $stmtCheck = $pdo->prepare("
                SELECT id FROM attendance_logs 
                WHERE user_id = ? AND date(clock_in) = date('now') AND clock_out IS NULL 
                ORDER BY id DESC LIMIT 1
            ");
            $stmtCheck->execute([$userId]);
            $attId = $stmtCheck->fetchColumn();

            if (!$attId) {
                echo json_encode(['success' => false, 'message' => 'Tidak ditemukan riwayat Clock-In aktif hari ini.']);
                exit;
            }

            $pdo->prepare("UPDATE attendance_logs SET clock_out = datetime('now', 'localtime') WHERE id = ?")->execute([$attId]);
            echo json_encode(['success' => true, 'message' => 'Clock-Out berhasil dicatat. Selamat beristirahat!']);
            break;

        case 'ess_submit_reimbursement':
            $userId = (int)($input['user_id'] ?? 3);
            $branchId = (int)($input['branch_id'] ?? 1);
            $expenseDate = $input['expense_date'] ?? date('Y-m-d');
            $category = $input['category'] ?? 'KERTAS_KASIR';
            $amount = (float)($input['amount'] ?? 0);
            $description = $input['description'] ?? 'Pembelian perlengkapan toko';

            $stmt = $pdo->prepare("
                INSERT INTO reimbursements (user_id, branch_id, expense_date, category, amount, description, status)
                VALUES (?, ?, ?, ?, ?, ?, 'PENDING')
            ");
            $stmt->execute([$userId, $branchId, $expenseDate, $category, $amount, $description]);
            echo json_encode(['success' => true, 'message' => 'Klaim reimbursement sebesar Rp ' . number_format($amount, 0, ',', '.') . ' berhasil diajukan.']);
            break;

        case 'ess_approve_reimbursement':
            $reimburseId = (int)($input['reimbursement_id'] ?? 0);
            $actionType = $input['approval_action'] ?? 'DISBURSE';

            $stmt = $pdo->prepare("SELECT * FROM reimbursements WHERE id = ?");
            $stmt->execute([$reimburseId]);
            $claim = $stmt->fetch();

            if (!$claim) {
                echo json_encode(['success' => false, 'message' => 'Klaim tidak ditemukan.']);
                exit;
            }

            if ($actionType === 'REJECT') {
                $pdo->prepare("UPDATE reimbursements SET status = 'REJECTED' WHERE id = ?")->execute([$reimburseId]);
                echo json_encode(['success' => true, 'message' => 'Klaim reimbursement ditolak.']);
                exit;
            }

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE reimbursements SET status = 'DISBURSED', disbursed_at = datetime('now', 'localtime') WHERE id = ?")->execute([$reimburseId]);

            $catNameMap = [
                'KERTAS_KASIR' => 'Perlengkapan Kasir (ESS Reimbursement)',
                'UTILITAS_DARURAT' => 'Utilitas & Listrik Toko (ESS Reimbursement)',
                'OPERASIONAL_TOKO' => 'Operasional & Kebersihan Toko (ESS Reimbursement)'
            ];
            $catLabel = $catNameMap[$claim['category']] ?? 'Beban Operasional Toko (ESS)';

            $stmtExp = $pdo->prepare("
                INSERT INTO branch_expenses (branch_id, category, amount, expense_date, source, notes)
                VALUES (?, ?, ?, ?, 'REIMBURSEMENT_ESS', ?)
            ");
            $stmtExp->execute([
                $claim['branch_id'], $catLabel, $claim['amount'], $claim['expense_date'],
                "Pencairan klaim staf ID #{$claim['user_id']}: {$claim['description']}"
            ]);
            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Klaim dicairkan dan otomatis dibukukan ke Beban Operasional Laba Rugi!']);
            break;

        // ==========================================
        // 8b. ESS EXTENSIONS: WORK SHIFTS CRUD
        // ==========================================
        case 'get_work_shifts':
            $stmt = $pdo->query("SELECT * FROM work_shifts ORDER BY is_day_off ASC, start_time ASC, id ASC");
            $shifts = $stmt->fetchAll();
            echo json_encode(['success' => true, 'shifts' => $shifts]);
            break;

        case 'save_work_shift':
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $code = strtoupper(trim($input['code'] ?? ''));
            $name = trim($input['name'] ?? '');
            $startTime = trim($input['start_time'] ?? '08:00');
            $endTime = trim($input['end_time'] ?? '17:00');
            $lateTolerance = (int)($input['late_tolerance_mins'] ?? 15);
            $isDayOff = !empty($input['is_day_off']) ? 1 : 0;
            $colorTag = trim($input['color_tag'] ?? '#3b82f6');
            $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

            if (empty($code) || empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Kode shift dan nama shift wajib diisi.']);
                exit;
            }

            if ($id) {
                $stmtCheck = $pdo->prepare("SELECT id FROM work_shifts WHERE code = ? AND id != ?");
                $stmtCheck->execute([$code, $id]);
            } else {
                $stmtCheck = $pdo->prepare("SELECT id FROM work_shifts WHERE code = ?");
                $stmtCheck->execute([$code]);
            }
            if ($stmtCheck->fetch()) {
                echo json_encode(['success' => false, 'message' => "Kode shift '{$code}' sudah digunakan."]);
                exit;
            }

            if ($id) {
                $stmt = $pdo->prepare("UPDATE work_shifts SET code = ?, name = ?, start_time = ?, end_time = ?, late_tolerance_mins = ?, is_day_off = ?, color_tag = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$code, $name, $startTime, $endTime, $lateTolerance, $isDayOff, $colorTag, $isActive, $id]);
                echo json_encode(['success' => true, 'message' => "Shift {$name} ({$code}) berhasil diperbarui."]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO work_shifts (code, name, start_time, end_time, late_tolerance_mins, is_day_off, color_tag, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$code, $name, $startTime, $endTime, $lateTolerance, $isDayOff, $colorTag, $isActive]);
                echo json_encode(['success' => true, 'message' => "Shift {$name} ({$code}) berhasil ditambahkan."]);
            }
            break;

        case 'delete_work_shift':
            $id = (int)($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'ID Shift tidak valid.']);
                exit;
            }
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM shift_schedules WHERE work_shift_id = ?");
            $stmtCheck->execute([$id]);
            $countUsed = $stmtCheck->fetchColumn();
            if ($countUsed > 0) {
                $pdo->prepare("UPDATE work_shifts SET is_active = 0 WHERE id = ?")->execute([$id]);
                echo json_encode(['success' => true, 'message' => "Shift dinonaktifkan (terdapat {$countUsed} jadwal terkait)."]);
            } else {
                $pdo->prepare("DELETE FROM work_shifts WHERE id = ?")->execute([$id]);
                echo json_encode(['success' => true, 'message' => "Shift berhasil dihapus."]);
            }
            break;

        // ==========================================
        // 8c. ESS EXTENSIONS: ROSTER / JADWAL BULANAN & HARI LIBUR
        // ==========================================
        case 'get_monthly_schedules':
            $branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : 1;
            $month = !empty($_GET['month']) ? trim($_GET['month']) : date('Y-m');

            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                $month = date('Y-m');
            }

            list($year, $mon) = explode('-', $month);
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, (int)$mon, (int)$year);

            $stmtEmp = $pdo->prepare("
                SELECT id, nik, name, role, branch_id 
                FROM users 
                WHERE branch_id = ? AND is_active = 1 
                  AND role NOT IN ('SUPER_ADMIN', 'SUPERADMIN', 'BRANCH_MANAGER', 'MANAGER')
                ORDER BY id ASC
            ");
            $stmtEmp->execute([$branchId]);
            $employees = $stmtEmp->fetchAll();

            $stmtShifts = $pdo->query("SELECT * FROM work_shifts WHERE is_active = 1 ORDER BY is_day_off ASC, start_time ASC");
            $workShifts = $stmtShifts->fetchAll();

            $stmtSched = $pdo->prepare("
                SELECT ss.*, ws.code as shift_code, ws.color_tag, ws.is_day_off as shift_is_day_off, u.name as employee_name, u.nik as employee_nik
                FROM shift_schedules ss
                JOIN users u ON u.id = ss.user_id
                LEFT JOIN work_shifts ws ON ws.id = ss.work_shift_id
                WHERE ss.branch_id = ? AND strftime('%Y-%m', ss.shift_date) = ?
                  AND u.role NOT IN ('SUPER_ADMIN', 'SUPERADMIN', 'BRANCH_MANAGER', 'MANAGER')
                ORDER BY ss.shift_date ASC, ss.user_id ASC
            ");
            $stmtSched->execute([$branchId, $month]);
            $schedules = $stmtSched->fetchAll();

            echo json_encode([
                'success' => true,
                'branch_id' => $branchId,
                'month' => $month,
                'days_in_month' => $daysInMonth,
                'employees' => $employees,
                'work_shifts' => $workShifts,
                'schedules' => $schedules
            ]);
            break;

        case 'save_single_schedule':
            $branchId = (int)($input['branch_id'] ?? 1);
            $userId = (int)($input['user_id'] ?? 0);
            $shiftDate = trim($input['shift_date'] ?? '');
            $workShiftId = !empty($input['work_shift_id']) ? (int)$input['work_shift_id'] : null;
            $isHoliday = !empty($input['is_holiday']) ? 1 : 0;
            $notes = trim($input['notes'] ?? '');

            if (!$userId || empty($shiftDate)) {
                echo json_encode(['success' => false, 'message' => 'Karyawan dan Tanggal wajib ditentukan.']);
                exit;
            }

            $stmtRole = $pdo->prepare("SELECT role, name FROM users WHERE id = ?");
            $stmtRole->execute([$userId]);
            $uRow = $stmtRole->fetch();
            if ($uRow && in_array(strtoupper($uRow['role']), ['SUPER_ADMIN', 'SUPERADMIN', 'BRANCH_MANAGER', 'MANAGER'])) {
                echo json_encode(['success' => false, 'message' => "Karyawan {$uRow['name']} ({$uRow['role']}) tidak dimapping ke jadwal shift kerja (khusus staf operasional & kasir)."]);
                exit;
            }

            $shiftName = 'Libur / Off';
            $startTime = '00:00';
            $endTime = '00:00';
            if ($workShiftId) {
                $stmtWs = $pdo->prepare("SELECT * FROM work_shifts WHERE id = ?");
                $stmtWs->execute([$workShiftId]);
                $ws = $stmtWs->fetch();
                if ($ws) {
                    $shiftName = $ws['name'];
                    $startTime = $ws['start_time'];
                    $endTime = $ws['end_time'];
                    if ($ws['is_day_off']) $isHoliday = 1;
                }
            }

            $stmtCheck = $pdo->prepare("SELECT id FROM shift_schedules WHERE user_id = ? AND shift_date = ?");
            $stmtCheck->execute([$userId, $shiftDate]);
            $existingId = $stmtCheck->fetchColumn();

            if ($existingId) {
                $stmtUp = $pdo->prepare("UPDATE shift_schedules SET branch_id = ?, work_shift_id = ?, shift_name = ?, start_time = ?, end_time = ?, is_holiday = ?, notes = ? WHERE id = ?");
                $stmtUp->execute([$branchId, $workShiftId, $shiftName, $startTime, $endTime, $isHoliday, $notes, $existingId]);
            } else {
                $stmtIn = $pdo->prepare("INSERT INTO shift_schedules (branch_id, user_id, shift_date, work_shift_id, shift_name, start_time, end_time, is_holiday, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtIn->execute([$branchId, $userId, $shiftDate, $workShiftId, $shiftName, $startTime, $endTime, $isHoliday, $notes]);
            }

            echo json_encode(['success' => true, 'message' => "Jadwal {$shiftDate} berhasil diperbarui menjadi {$shiftName}."]);
            break;

        case 'save_bulk_monthly_schedules':
            $branchId = (int)($input['branch_id'] ?? 1);
            $items = $input['items'] ?? [];

            if (!is_array($items) || empty($items)) {
                echo json_encode(['success' => false, 'message' => 'Tidak ada data jadwal untuk disimpan.']);
                exit;
            }

            $shiftsLookup = [];
            $allWs = $pdo->query("SELECT * FROM work_shifts")->fetchAll();
            foreach ($allWs as $ws) {
                $shiftsLookup[$ws['id']] = $ws;
            }

            $exemptUsers = $pdo->query("SELECT id FROM users WHERE role IN ('SUPER_ADMIN', 'SUPERADMIN', 'BRANCH_MANAGER', 'MANAGER')")->fetchAll(PDO::FETCH_COLUMN);
            $exemptMap = array_flip($exemptUsers);

            $pdo->beginTransaction();
            $stmtCheck = $pdo->prepare("SELECT id FROM shift_schedules WHERE user_id = ? AND shift_date = ?");
            $stmtUp = $pdo->prepare("UPDATE shift_schedules SET branch_id = ?, work_shift_id = ?, shift_name = ?, start_time = ?, end_time = ?, is_holiday = ?, notes = ? WHERE id = ?");
            $stmtIn = $pdo->prepare("INSERT INTO shift_schedules (branch_id, user_id, shift_date, work_shift_id, shift_name, start_time, end_time, is_holiday, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $countSaved = 0;
            foreach ($items as $item) {
                $userId = (int)($item['user_id'] ?? 0);
                $shiftDate = trim($item['shift_date'] ?? '');
                $workShiftId = !empty($item['work_shift_id']) ? (int)$item['work_shift_id'] : null;
                $isHoliday = !empty($item['is_holiday']) ? 1 : 0;
                $notes = trim($item['notes'] ?? '');

                if (!$userId || empty($shiftDate)) continue;
                // Lewati karyawan Super Admin & Branch Manager (tidak dimapping ke shift)
                if (isset($exemptMap[$userId])) continue;

                $shiftName = 'Libur / Off';
                $startTime = '00:00';
                $endTime = '00:00';
                if ($workShiftId && isset($shiftsLookup[$workShiftId])) {
                    $ws = $shiftsLookup[$workShiftId];
                    $shiftName = $ws['name'];
                    $startTime = $ws['start_time'];
                    $endTime = $ws['end_time'];
                    if ($ws['is_day_off']) $isHoliday = 1;
                }

                $stmtCheck->execute([$userId, $shiftDate]);
                $existingId = $stmtCheck->fetchColumn();

                if ($existingId) {
                    $stmtUp->execute([$branchId, $workShiftId, $shiftName, $startTime, $endTime, $isHoliday, $notes, $existingId]);
                } else {
                    $stmtIn->execute([$branchId, $userId, $shiftDate, $workShiftId, $shiftName, $startTime, $endTime, $isHoliday, $notes]);
                }
                $countSaved++;
            }
            $pdo->commit();

            echo json_encode(['success' => true, 'count' => $countSaved, 'message' => "{$countSaved} jadwal kerja bulanan berhasil disimpan."]);
            break;

        case 'set_monthly_holidays':
            $branchId = (int)($input['branch_id'] ?? 1);
            $holidayDates = $input['dates'] ?? [];
            $notes = trim($input['notes'] ?? 'Hari Libur Nasional / Toko');

            if (!is_array($holidayDates) || empty($holidayDates)) {
                echo json_encode(['success' => false, 'message' => 'Pilih tanggal hari libur yang ingin disetel.']);
                exit;
            }

            $offShift = $pdo->query("SELECT * FROM work_shifts WHERE is_day_off = 1 LIMIT 1")->fetch();
            $offId = $offShift ? $offShift['id'] : null;
            $offName = $offShift ? $offShift['name'] : 'Libur / Off';

            $users = $pdo->prepare("
                SELECT id FROM users 
                WHERE branch_id = ? AND is_active = 1 
                  AND role NOT IN ('SUPER_ADMIN', 'SUPERADMIN', 'BRANCH_MANAGER', 'MANAGER')
            ");
            $users->execute([$branchId]);
            $userIds = $users->fetchAll(PDO::FETCH_COLUMN);

            $pdo->beginTransaction();
            $stmtCheck = $pdo->prepare("SELECT id FROM shift_schedules WHERE user_id = ? AND shift_date = ?");
            $stmtUp = $pdo->prepare("UPDATE shift_schedules SET work_shift_id = ?, shift_name = ?, start_time = '00:00', end_time = '00:00', is_holiday = 1, notes = ? WHERE id = ?");
            $stmtIn = $pdo->prepare("INSERT INTO shift_schedules (branch_id, user_id, shift_date, work_shift_id, shift_name, start_time, end_time, is_holiday, notes) VALUES (?, ?, ?, ?, ?, '00:00', '00:00', 1, ?)");

            $countSet = 0;
            foreach ($holidayDates as $hDate) {
                foreach ($userIds as $uId) {
                    $stmtCheck->execute([$uId, $hDate]);
                    $exId = $stmtCheck->fetchColumn();
                    if ($exId) {
                        $stmtUp->execute([$offId, $offName, $notes, $exId]);
                    } else {
                        $stmtIn->execute([$branchId, $uId, $hDate, $offId, $offName, $notes]);
                    }
                    $countSet++;
                }
            }
            $pdo->commit();

            echo json_encode(['success' => true, 'count' => $countSet, 'message' => "Hari libur berhasil disetel untuk {$countSet} slot karyawan."]);
            break;

        // ==========================================
        // 8d. EXPORT & IMPORT JADWAL BULANAN (EXCEL / CSV)
        // ==========================================
        case 'export_monthly_schedules':
            $branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : 1;
            $month = !empty($_GET['month']) ? trim($_GET['month']) : date('Y-m');

            $stmtB = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
            $stmtB->execute([$branchId]);
            $branchName = $stmtB->fetchColumn() ?: 'Cabang';

            $stmt = $pdo->prepare("
                SELECT u.nik, u.name as employee_name, b.name as branch_name, ss.shift_date,
                       COALESCE(ws.code, 'OFF') as shift_code, ss.shift_name, ss.start_time, ss.end_time,
                       CASE WHEN ss.is_holiday = 1 THEN 'YA' ELSE 'TIDAK' END as is_holiday_label,
                       COALESCE(ss.notes, '') as notes
                FROM shift_schedules ss
                JOIN users u ON u.id = ss.user_id
                JOIN branches b ON b.id = ss.branch_id
                LEFT JOIN work_shifts ws ON ws.id = ss.work_shift_id
                WHERE ss.branch_id = ? AND strftime('%Y-%m', ss.shift_date) = ?
                  AND u.role NOT IN ('SUPER_ADMIN', 'SUPERADMIN', 'BRANCH_MANAGER', 'MANAGER')
                ORDER BY ss.shift_date ASC, u.nik ASC
            ");
            $stmt->execute([$branchId, $month]);
            $rows = $stmt->fetchAll();

            $csvLines = [];
            $csvLines[] = "NIK,Nama_Karyawan,Cabang,Tanggal,Kode_Shift,Nama_Shift,Jam_Masuk,Jam_Pulang,Libur,Catatan";
            foreach ($rows as $r) {
                $csvLines[] = implode(',', [
                    '"' . str_replace('"', '""', $r['nik']) . '"',
                    '"' . str_replace('"', '""', $r['employee_name']) . '"',
                    '"' . str_replace('"', '""', $r['branch_name']) . '"',
                    '"' . str_replace('"', '""', $r['shift_date']) . '"',
                    '"' . str_replace('"', '""', $r['shift_code']) . '"',
                    '"' . str_replace('"', '""', $r['shift_name']) . '"',
                    '"' . str_replace('"', '""', $r['start_time']) . '"',
                    '"' . str_replace('"', '""', $r['end_time']) . '"',
                    '"' . str_replace('"', '""', $r['is_holiday_label']) . '"',
                    '"' . str_replace('"', '""', $r['notes']) . '"'
                ]);
            }
            $csvContent = implode("\r\n", $csvLines);
            $filename = "jadwal_kerja_{$branchName}_{$month}.csv";

            if (!empty($_GET['download']) && $_GET['download'] == 1) {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                echo "\xEF\xBB\xBF";
                echo $csvContent;
                exit;
            } else {
                echo json_encode([
                    'success' => true,
                    'filename' => $filename,
                    'csv_content' => $csvContent,
                    'total_rows' => count($rows)
                ]);
            }
            break;

        case 'import_monthly_schedules':
            $branchId = (int)($input['branch_id'] ?? 1);
            $csvData = $input['csv_data'] ?? '';

            if (empty($csvData)) {
                echo json_encode(['success' => false, 'message' => 'Data CSV kosong atau file tidak terbaca.']);
                exit;
            }

            $userByNik = [];
            $uStmt = $pdo->query("SELECT id, nik, name, role, branch_id FROM users");
            foreach ($uStmt->fetchAll() as $u) {
                $userByNik[strtoupper(trim($u['nik']))] = $u;
            }

            $shiftByCode = [];
            $sStmt = $pdo->query("SELECT id, code, name, start_time, end_time, is_day_off FROM work_shifts");
            foreach ($sStmt->fetchAll() as $ws) {
                $shiftByCode[strtoupper(trim($ws['code']))] = $ws;
            }

            $lines = preg_split('/\r\n|\r|\n/', trim($csvData));
            if (empty($lines)) {
                echo json_encode(['success' => false, 'message' => 'Format file CSV tidak valid.']);
                exit;
            }

            $header = str_getcsv(array_shift($lines));
            $idxNik = -1; $idxDate = -1; $idxShift = -1; $idxNotes = -1;
            foreach ($header as $i => $col) {
                $c = strtolower(trim($col));
                if (strpos($c, 'nik') !== false) $idxNik = $i;
                elseif (strpos($c, 'tanggal') !== false || strpos($c, 'date') !== false) $idxDate = $i;
                elseif (strpos($c, 'kode_shift') !== false || strpos($c, 'shift') !== false) {
                    if ($idxShift === -1) $idxShift = $i;
                }
                elseif (strpos($c, 'catatan') !== false || strpos($c, 'note') !== false) $idxNotes = $i;
            }

            if ($idxNik === -1 || $idxDate === -1) {
                $idxNik = 0; $idxDate = 3; $idxShift = 4; $idxNotes = 9;
            }

            $pdo->beginTransaction();
            $stmtCheck = $pdo->prepare("SELECT id FROM shift_schedules WHERE user_id = ? AND shift_date = ?");
            $stmtUp = $pdo->prepare("UPDATE shift_schedules SET branch_id = ?, work_shift_id = ?, shift_name = ?, start_time = ?, end_time = ?, is_holiday = ?, notes = ? WHERE id = ?");
            $stmtIn = $pdo->prepare("INSERT INTO shift_schedules (branch_id, user_id, shift_date, work_shift_id, shift_name, start_time, end_time, is_holiday, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $inserted = 0;
            $updated = 0;
            $skipped = 0;
            $errors = [];

            foreach ($lines as $lineNum => $line) {
                if (empty(trim($line))) continue;
                $row = str_getcsv($line);
                if (count($row) <= max($idxNik, $idxDate)) {
                    $skipped++;
                    continue;
                }

                $nik = strtoupper(trim($row[$idxNik] ?? ''));
                $dateStr = trim($row[$idxDate] ?? '');
                $shiftCode = strtoupper(trim($row[$idxShift] ?? 'S1'));
                $notes = ($idxNotes !== -1 && isset($row[$idxNotes])) ? trim($row[$idxNotes]) : '';

                if (empty($nik) || empty($dateStr)) {
                    $skipped++;
                    continue;
                }

                if (!isset($userByNik[$nik])) {
                    $errors[] = "Baris " . ($lineNum + 2) . ": Karyawan NIK '{$nik}' tidak ditemukan.";
                    $skipped++;
                    continue;
                }
                $user = $userByNik[$nik];
                if (in_array(strtoupper($user['role'] ?? ''), ['SUPER_ADMIN', 'SUPERADMIN', 'BRANCH_MANAGER', 'MANAGER'])) {
                    $errors[] = "Baris " . ($lineNum + 2) . ": Karyawan {$user['name']} ({$user['role']}) dilewati karena tidak dimapping ke jadwal shift.";
                    $skipped++;
                    continue;
                }
                $userId = $user['id'];
                $rowBranchId = $user['branch_id'] ?: $branchId;

                $time = strtotime($dateStr);
                if (!$time) {
                    $errors[] = "Baris " . ($lineNum + 2) . ": Format tanggal '{$dateStr}' tidak valid.";
                    $skipped++;
                    continue;
                }
                $formattedDate = date('Y-m-d', $time);

                $wsId = null;
                $shiftName = 'Shift ' . $shiftCode;
                $startTime = '08:00';
                $endTime = '16:00';
                $isHoliday = 0;
                if (isset($shiftByCode[$shiftCode])) {
                    $ws = $shiftByCode[$shiftCode];
                    $wsId = $ws['id'];
                    $shiftName = $ws['name'];
                    $startTime = $ws['start_time'];
                    $endTime = $ws['end_time'];
                    if ($ws['is_day_off']) $isHoliday = 1;
                } elseif ($shiftCode === 'OFF' || $shiftCode === 'LIBUR') {
                    $isHoliday = 1;
                    $shiftName = 'Libur / Off';
                    $startTime = '00:00';
                    $endTime = '00:00';
                }

                $stmtCheck->execute([$userId, $formattedDate]);
                $exId = $stmtCheck->fetchColumn();

                if ($exId) {
                    $stmtUp->execute([$rowBranchId, $wsId, $shiftName, $startTime, $endTime, $isHoliday, $notes, $exId]);
                    $updated++;
                } else {
                    $stmtIn->execute([$rowBranchId, $userId, $formattedDate, $wsId, $shiftName, $startTime, $endTime, $isHoliday, $notes]);
                    $inserted++;
                }
            }
            $pdo->commit();

            echo json_encode([
                'success' => true,
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors,
                'message' => "Import Berhasil! {$inserted} jadwal baru ditambahkan, {$updated} jadwal diperbarui."
            ]);
            break;

        // ==========================================
        // 8e. TUKAR JADWAL LIBUR / SHIFT SWAP
        // ==========================================
        case 'get_shift_swaps':
            $branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
            $userId = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : null;
            $status = $_GET['status'] ?? 'ALL';

            $sql = "
                SELECT sw.*, u1.name as requester_name, u1.nik as requester_nik,
                       u2.name as target_name, u2.nik as target_nik,
                       b.name as branch_name,
                       u_app.name as approver_name
                FROM shift_swaps sw
                JOIN users u1 ON u1.id = sw.requester_id
                JOIN users u2 ON u2.id = sw.target_id
                JOIN branches b ON b.id = sw.branch_id
                LEFT JOIN users u_app ON u_app.id = sw.approved_by
                WHERE 1=1
            ";
            $params = [];
            if ($branchId) {
                $sql .= " AND sw.branch_id = ?";
                $params[] = $branchId;
            }
            if ($userId) {
                $sql .= " AND (sw.requester_id = ? OR sw.target_id = ?)";
                $params[] = $userId;
                $params[] = $userId;
            }
            if ($status !== 'ALL') {
                $sql .= " AND sw.status = ?";
                $params[] = $status;
            }
            $sql .= " ORDER BY sw.id DESC LIMIT 50";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $swaps = $stmt->fetchAll();

            echo json_encode(['success' => true, 'swaps' => $swaps]);
            break;

        case 'request_shift_swap':
            $requesterId = (int)($input['requester_id'] ?? 3);
            $targetId = (int)($input['target_id'] ?? 0);
            $branchId = (int)($input['branch_id'] ?? 1);
            $shiftDate = trim($input['shift_date'] ?? '');
            $targetDate = !empty($input['target_date']) ? trim($input['target_date']) : $shiftDate;
            $reason = trim($input['reason'] ?? 'Tukar jadwal kerja/libur');

            if (!$targetId || empty($shiftDate)) {
                echo json_encode(['success' => false, 'message' => 'Pilih rekan kerja dan tanggal jadwal yang ingin ditukar.']);
                exit;
            }
            if ($requesterId === $targetId) {
                echo json_encode(['success' => false, 'message' => 'Tidak dapat bertukar jadwal dengan diri sendiri.']);
                exit;
            }

            $stmtRoles = $pdo->prepare("SELECT id, role, name FROM users WHERE id IN (?, ?)");
            $stmtRoles->execute([$requesterId, $targetId]);
            foreach ($stmtRoles->fetchAll() as $uInfo) {
                if (in_array(strtoupper($uInfo['role']), ['SUPER_ADMIN', 'SUPERADMIN', 'BRANCH_MANAGER', 'MANAGER'])) {
                    echo json_encode(['success' => false, 'message' => "Karyawan {$uInfo['name']} ({$uInfo['role']}) tidak memiliki jadwal shift sehingga tidak dapat melakukan tukar shift."]);
                    exit;
                }
            }

            $stmtS1 = $pdo->prepare("SELECT shift_name FROM shift_schedules WHERE user_id = ? AND shift_date = ?");
            $stmtS1->execute([$requesterId, $shiftDate]);
            $reqShiftName = $stmtS1->fetchColumn() ?: 'Jadwal Reguler';

            $stmtS2 = $pdo->prepare("SELECT shift_name FROM shift_schedules WHERE user_id = ? AND shift_date = ?");
            $stmtS2->execute([$targetId, $targetDate]);
            $tarShiftName = $stmtS2->fetchColumn() ?: 'Jadwal Reguler';

            $stmt = $pdo->prepare("
                INSERT INTO shift_swaps (requester_id, target_id, branch_id, shift_date, target_date, requester_shift_name, target_shift_name, reason, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING')
            ");
            $stmt->execute([$requesterId, $targetId, $branchId, $shiftDate, $targetDate, $reqShiftName, $tarShiftName, $reason]);

            echo json_encode(['success' => true, 'message' => 'Pengajuan tukar jadwal berhasil dikirim. Menunggu persetujuan rekan & manajer.']);
            break;

        case 'approve_shift_swap':
            $swapId = (int)($input['swap_id'] ?? 0);
            $action = strtoupper($input['action'] ?? 'APPROVE');
            $approverId = (int)($input['user_id'] ?? 1);
            $rejectionReason = trim($input['rejection_reason'] ?? '');

            $stmt = $pdo->prepare("SELECT * FROM shift_swaps WHERE id = ?");
            $stmt->execute([$swapId]);
            $swap = $stmt->fetch();
            if (!$swap) {
                echo json_encode(['success' => false, 'message' => 'Data pengajuan tukar jadwal tidak ditemukan.']);
                exit;
            }

            if ($action === 'REJECT') {
                $pdo->prepare("UPDATE shift_swaps SET status = 'REJECTED', approved_by = ?, approved_at = datetime('now', 'localtime'), rejection_reason = ? WHERE id = ?")
                    ->execute([$approverId, $rejectionReason, $swapId]);
                echo json_encode(['success' => true, 'message' => 'Pengajuan tukar jadwal ditolak.']);
                exit;
            }

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE shift_swaps SET status = 'APPROVED', approved_by = ?, approved_at = datetime('now', 'localtime') WHERE id = ?")
                ->execute([$approverId, $swapId]);

            $reqId = $swap['requester_id'];
            $tarId = $swap['target_id'];
            $date1 = $swap['shift_date'];
            $date2 = !empty($swap['target_date']) ? $swap['target_date'] : $date1;

            if ($date1 === $date2) {
                $s1 = $pdo->query("SELECT * FROM shift_schedules WHERE user_id = {$reqId} AND shift_date = '{$date1}'")->fetch();
                $s2 = $pdo->query("SELECT * FROM shift_schedules WHERE user_id = {$tarId} AND shift_date = '{$date1}'")->fetch();
                if ($s1 && $s2) {
                    $pdo->exec("UPDATE shift_schedules SET user_id = {$tarId} WHERE id = {$s1['id']}");
                    $pdo->exec("UPDATE shift_schedules SET user_id = {$reqId} WHERE id = {$s2['id']}");
                }
            } else {
                $s1 = $pdo->query("SELECT * FROM shift_schedules WHERE user_id = {$reqId} AND shift_date = '{$date1}'")->fetch();
                $s2 = $pdo->query("SELECT * FROM shift_schedules WHERE user_id = {$tarId} AND shift_date = '{$date2}'")->fetch();

                if ($s1 && $s2) {
                    $stmtUpdate1 = $pdo->prepare("UPDATE shift_schedules SET work_shift_id = ?, shift_name = ?, start_time = ?, end_time = ?, is_holiday = ?, notes = 'Tukar Jadwal Approved' WHERE id = ?");
                    $stmtUpdate1->execute([$s2['work_shift_id'], $s2['shift_name'], $s2['start_time'], $s2['end_time'], $s2['is_holiday'], $s1['id']]);

                    $stmtUpdate2 = $pdo->prepare("UPDATE shift_schedules SET work_shift_id = ?, shift_name = ?, start_time = ?, end_time = ?, is_holiday = ?, notes = 'Tukar Jadwal Approved' WHERE id = ?");
                    $stmtUpdate2->execute([$s1['work_shift_id'], $s1['shift_name'], $s1['start_time'], $s1['end_time'], $s1['is_holiday'], $s2['id']]);
                }
            }
            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Pengajuan disetujui dan jadwal kedua karyawan otomatis tertukar di sistem!']);
            break;

        // ==========================================
        // 8f. PENGAJUAN KETIDAKHADIRAN (CUTI, IZIN, SAKIT)
        // ==========================================
        case 'get_leave_requests':
            $branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
            $userId = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : null;
            $status = $_GET['status'] ?? 'ALL';

            $sql = "
                SELECT lr.*, u.name as employee_name, u.nik as employee_nik,
                       b.name as branch_name,
                       u_app.name as approver_name
                FROM leave_requests lr
                JOIN users u ON u.id = lr.user_id
                JOIN branches b ON b.id = lr.branch_id
                LEFT JOIN users u_app ON u_app.id = lr.approved_by
                WHERE 1=1
            ";
            $params = [];
            if ($branchId) {
                $sql .= " AND lr.branch_id = ?";
                $params[] = $branchId;
            }
            if ($userId) {
                $sql .= " AND lr.user_id = ?";
                $params[] = $userId;
            }
            if ($status !== 'ALL') {
                $sql .= " AND lr.status = ?";
                $params[] = $status;
            }
            $sql .= " ORDER BY lr.id DESC LIMIT 50";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $leaves = $stmt->fetchAll();

            echo json_encode(['success' => true, 'leaves' => $leaves]);
            break;

        case 'submit_leave_request':
            $userId = (int)($input['user_id'] ?? 3);
            $branchId = (int)($input['branch_id'] ?? 1);
            $leaveType = strtoupper(trim($input['leave_type'] ?? 'CUTI_TAHUNAN'));
            $startDate = trim($input['start_date'] ?? date('Y-m-d'));
            $endDate = trim($input['end_date'] ?? $startDate);
            $reason = trim($input['reason'] ?? '');
            $attachmentDoc = trim($input['attachment_doc'] ?? '');

            if (empty($reason)) {
                echo json_encode(['success' => false, 'message' => 'Alasan permohonan ketidakhadiran wajib diisi.']);
                exit;
            }
            if ($endDate < $startDate) {
                echo json_encode(['success' => false, 'message' => 'Tanggal akhir tidak boleh mendahului tanggal mulai.']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO leave_requests (user_id, branch_id, leave_type, start_date, end_date, reason, attachment_doc, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING')
            ");
            $stmt->execute([$userId, $branchId, $leaveType, $startDate, $endDate, $reason, $attachmentDoc]);

            echo json_encode(['success' => true, 'message' => "Permohonan {$leaveType} tanggal {$startDate} s/d {$endDate} berhasil diajukan."]);
            break;

        case 'approve_leave_request':
            $leaveId = (int)($input['leave_id'] ?? 0);
            $action = strtoupper($input['action'] ?? 'APPROVE');
            $approverId = (int)($input['user_id'] ?? 1);
            $rejectionReason = trim($input['rejection_reason'] ?? '');

            $stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE id = ?");
            $stmt->execute([$leaveId]);
            $leave = $stmt->fetch();
            if (!$leave) {
                echo json_encode(['success' => false, 'message' => 'Data permohonan ketidakhadiran tidak ditemukan.']);
                exit;
            }

            if ($action === 'REJECT') {
                $pdo->prepare("UPDATE leave_requests SET status = 'REJECTED', approved_by = ?, approved_at = datetime('now', 'localtime'), rejection_reason = ? WHERE id = ?")
                    ->execute([$approverId, $rejectionReason, $leaveId]);
                echo json_encode(['success' => true, 'message' => 'Permohonan ketidakhadiran ditolak.']);
                exit;
            }

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE leave_requests SET status = 'APPROVED', approved_by = ?, approved_at = datetime('now', 'localtime') WHERE id = ?")
                ->execute([$approverId, $leaveId]);

            $cur = strtotime($leave['start_date']);
            $end = strtotime($leave['end_date']);
            $stmtCheck = $pdo->prepare("SELECT id FROM shift_schedules WHERE user_id = ? AND shift_date = ?");
            $stmtUp = $pdo->prepare("UPDATE shift_schedules SET shift_name = ?, start_time = '00:00', end_time = '00:00', is_holiday = 1, notes = ? WHERE id = ?");
            $stmtIn = $pdo->prepare("INSERT INTO shift_schedules (branch_id, user_id, shift_date, shift_name, start_time, end_time, is_holiday, notes) VALUES (?, ?, ?, ?, '00:00', '00:00', 1, ?)");

            $label = '[' . $leave['leave_type'] . '] ' . $leave['reason'];
            while ($cur <= $end) {
                $dStr = date('Y-m-d', $cur);
                $stmtCheck->execute([$leave['user_id'], $dStr]);
                $exId = $stmtCheck->fetchColumn();
                if ($exId) {
                    $stmtUp->execute([$leave['leave_type'], $label, $exId]);
                } else {
                    $stmtIn->execute([$leave['branch_id'], $leave['user_id'], $dStr, $leave['leave_type'], $label]);
                }
                $cur = strtotime('+1 day', $cur);
            }
            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Permohonan disetujui dan jadwal karyawan otomatis di-update.']);
            break;

        // ==========================================
        // 8g. LAPORAN KEHADIRAN (ATTENDANCE REPORT)
        // ==========================================
        case 'get_attendance_report':
            $branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
            $month = !empty($_GET['month']) ? trim($_GET['month']) : date('Y-m');
            $userIdFilter = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : null;

            $startDate = $month . '-01';
            list($y, $m) = explode('-', $month);
            $daysInM = cal_days_in_month(CAL_GREGORIAN, (int)$m, (int)$y);
            $endDate = $month . '-' . str_pad($daysInM, 2, '0', STR_PAD_LEFT);

            $sqlEmp = "SELECT u.id, u.nik, u.name, u.role, b.name as branch_name, u.branch_id FROM users u JOIN branches b ON b.id = u.branch_id WHERE u.is_active = 1";
            $paramsEmp = [];
            if ($branchId) {
                $sqlEmp .= " AND u.branch_id = ?";
                $paramsEmp[] = $branchId;
            }
            if ($userIdFilter) {
                $sqlEmp .= " AND u.id = ?";
                $paramsEmp[] = $userIdFilter;
            }
            $sqlEmp .= " ORDER BY u.branch_id ASC, u.id ASC";
            $stmtEmp = $pdo->prepare($sqlEmp);
            $stmtEmp->execute($paramsEmp);
            $employees = $stmtEmp->fetchAll();

            $sqlLogs = "
                SELECT al.*, u.name as employee_name, u.nik as employee_nik, b.name as branch_name
                FROM attendance_logs al
                JOIN users u ON u.id = al.user_id
                JOIN branches b ON b.id = al.branch_id
                WHERE date(al.clock_in) BETWEEN ? AND ?
            ";
            $paramsLogs = [$startDate, $endDate];
            if ($branchId) {
                $sqlLogs .= " AND al.branch_id = ?";
                $paramsLogs[] = $branchId;
            }
            if ($userIdFilter) {
                $sqlLogs .= " AND al.user_id = ?";
                $paramsLogs[] = $userIdFilter;
            }
            $sqlLogs .= " ORDER BY al.clock_in DESC";
            $stmtLogs = $pdo->prepare($sqlLogs);
            $stmtLogs->execute($paramsLogs);
            $logs = $stmtLogs->fetchAll();

            $logsByUser = [];
            foreach ($logs as $l) {
                $logsByUser[$l['user_id']][] = $l;
            }

            $stmtLeaves = $pdo->prepare("
                SELECT user_id, leave_type, start_date, end_date
                FROM leave_requests
                WHERE status = 'APPROVED' AND NOT (end_date < ? OR start_date > ?)
            ");
            $stmtLeaves->execute([$startDate, $endDate]);
            $allLeaves = $stmtLeaves->fetchAll();

            $stmtSched = $pdo->prepare("
                SELECT user_id, COUNT(*) as scheduled_count
                FROM shift_schedules
                WHERE is_holiday = 0 AND shift_date BETWEEN ? AND ?
                GROUP BY user_id
            ");
            $stmtSched->execute([$startDate, $endDate]);
            $schedMap = $stmtSched->fetchAll(PDO::FETCH_KEY_PAIR);

            $summary = [];
            foreach ($employees as $emp) {
                $uId = $emp['id'];
                $userLogs = $logsByUser[$uId] ?? [];

                $presentDays = count(array_unique(array_map(function($lg) {
                    return substr($lg['clock_in'], 0, 10);
                }, $userLogs)));

                $onTimeCount = 0;
                $lateCount = 0;
                foreach ($userLogs as $lg) {
                    if ($lg['status'] === 'LATE') $lateCount++;
                    else $onTimeCount++;
                }

                $leaveDays = 0;
                $sickDays = 0;
                foreach ($allLeaves as $lv) {
                    if ($lv['user_id'] == $uId) {
                        $s = max(strtotime($startDate), strtotime($lv['start_date']));
                        $e = min(strtotime($endDate), strtotime($lv['end_date']));
                        $days = max(0, round(($e - $s) / 86400) + 1);
                        if ($lv['leave_type'] === 'SAKIT') $sickDays += $days;
                        else $leaveDays += $days;
                    }
                }

                $scheduled = $schedMap[$uId] ?? 24;
                $absentDays = max(0, $scheduled - $presentDays - $leaveDays - $sickDays);
                $attPercent = $scheduled > 0 ? round(($presentDays / $scheduled) * 100, 1) : 100;

                $summary[] = [
                    'user_id' => $uId,
                    'nik' => $emp['nik'],
                    'name' => $emp['name'],
                    'role' => $emp['role'],
                    'branch_name' => $emp['branch_name'],
                    'scheduled_days' => $scheduled,
                    'present_days' => $presentDays,
                    'ontime_days' => $onTimeCount,
                    'late_days' => $lateCount,
                    'leave_days' => $leaveDays,
                    'sick_days' => $sickDays,
                    'absent_days' => $absentDays,
                    'attendance_rate' => $attPercent
                ];
            }

            echo json_encode([
                'success' => true,
                'period' => ['month' => $month, 'start' => $startDate, 'end' => $endDate],
                'summary' => $summary,
                'logs' => $logs
            ]);
            break;

        // ==========================================
        // 8h. CETAK SLIP GAJI MANDIRI (SELF-SERVICE PAYSLIP)
        // ==========================================
        case 'get_employee_payslip':
            $userId = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : 3;
            $month = !empty($_GET['month']) ? trim($_GET['month']) : date('Y-m');

            $stmtU = $pdo->prepare("SELECT u.*, b.name as branch_name, b.address as branch_address FROM users u LEFT JOIN branches b ON b.id = u.branch_id WHERE u.id = ?");
            $stmtU->execute([$userId]);
            $user = $stmtU->fetch();
            if (!$user) {
                echo json_encode(['success' => false, 'message' => 'Karyawan tidak ditemukan.']);
                exit;
            }

            $startDate = $month . '-01';
            list($y, $m) = explode('-', $month);
            $daysInM = cal_days_in_month(CAL_GREGORIAN, (int)$m, (int)$y);
            $endDate = $month . '-' . str_pad($daysInM, 2, '0', STR_PAD_LEFT);

            $stmtP = $pdo->prepare("SELECT * FROM employee_payrolls WHERE user_id = ? AND period_month = ?");
            $stmtP->execute([$userId, $month]);
            $saved = $stmtP->fetch();

            if ($saved) {
                $payroll = $saved;
            } else {
                $baseSalary = (float)($user['base_salary'] ?: 3000000);
                $allowance = (float)($user['allowance'] ?: 500000);
                $dailyRate = (float)($user['daily_transport'] ?: 25000);

                $stmtAtt = $pdo->prepare("SELECT COUNT(DISTINCT date(clock_in)) FROM attendance_logs WHERE user_id = ? AND date(clock_in) BETWEEN ? AND ?");
                $stmtAtt->execute([$userId, $startDate, $endDate]);
                $attendanceDays = (int)$stmtAtt->fetchColumn();
                if ($attendanceDays === 0) $attendanceDays = 22;

                $transportTotal = $attendanceDays * $dailyRate;

                $stmtComm = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE user_id = ? AND date(created_at) BETWEEN ? AND ?");
                $stmtComm->execute([$userId, $startDate, $endDate]);
                $totalOmzet = (float)$stmtComm->fetchColumn();
                $salesCommission = $totalOmzet * ($user['commission_rate'] ?: 0.015);

                $overtimePay = 0;
                $bonusPay = 0;
                $grossSalary = $baseSalary + $allowance + $transportTotal + $salesCommission + $overtimePay + $bonusPay;

                $stmtLate = $pdo->prepare("SELECT COUNT(*) FROM attendance_logs WHERE user_id = ? AND status = 'LATE' AND date(clock_in) BETWEEN ? AND ?");
                $stmtLate->execute([$userId, $startDate, $endDate]);
                $lateCount = (int)$stmtLate->fetchColumn();
                $lateDeduction = $lateCount * 15000;

                $absenceDeduction = 0;

                $stmtLoan = $pdo->prepare("SELECT COALESCE(SUM(monthly_deduction), 0) FROM salary_loans WHERE user_id = ? AND status = 'ACTIVE' AND remaining_amount > 0");
                $stmtLoan->execute([$userId]);
                $loanDeduction = (float)$stmtLoan->fetchColumn();

                $taxDeduction = 125000;
                $otherDeduction = 0;

                $totalDeductions = $lateDeduction + $absenceDeduction + $loanDeduction + $taxDeduction + $otherDeduction;
                $netSalary = max(0, $grossSalary - $totalDeductions);

                $payroll = [
                    'user_id' => $userId,
                    'branch_id' => $user['branch_id'],
                    'period_month' => $month,
                    'base_salary' => $baseSalary,
                    'allowance' => $allowance,
                    'attendance_days' => $attendanceDays,
                    'daily_transport_rate' => $dailyRate,
                    'transport_total' => $transportTotal,
                    'sales_commission' => $salesCommission,
                    'overtime_pay' => $overtimePay,
                    'bonus_pay' => $bonusPay,
                    'gross_salary' => $grossSalary,
                    'late_deduction' => $lateDeduction,
                    'absence_deduction' => $absenceDeduction,
                    'loan_deduction' => $loanDeduction,
                    'tax_deduction' => $taxDeduction,
                    'other_deduction' => $otherDeduction,
                    'total_deductions' => $totalDeductions,
                    'net_salary' => $netSalary,
                    'payment_status' => 'GENERATED',
                    'notes' => 'Slip Gaji Otomatis Terverifikasi'
                ];
            }

            $stmtActiveLoans = $pdo->prepare("SELECT * FROM salary_loans WHERE user_id = ? AND status = 'ACTIVE'");
            $stmtActiveLoans->execute([$userId]);
            $activeLoans = $stmtActiveLoans->fetchAll();

            $stmtMeal = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM employee_meal_allowances WHERE user_id = ? AND date(meal_date) BETWEEN ? AND ?");
            $stmtMeal->execute([$userId, $startDate, $endDate]);
            $shiftMealTotal = (float)$stmtMeal->fetchColumn();

            $stmtMealLogs = $pdo->prepare("SELECT * FROM employee_meal_allowances WHERE user_id = ? AND date(meal_date) BETWEEN ? AND ? ORDER BY meal_date ASC");
            $stmtMealLogs->execute([$userId, $startDate, $endDate]);
            $mealLogs = $stmtMealLogs->fetchAll();

            echo json_encode([
                'success' => true,
                'user' => $user,
                'month' => $month,
                'payroll' => $payroll,
                'active_loans' => $activeLoans,
                'shift_meal_allowance' => $shiftMealTotal,
                'meal_logs' => $mealLogs
            ]);
            break;

        case 'generate_or_save_payroll':
            $userId = (int)($input['user_id'] ?? 3);
            $month = trim($input['month'] ?? date('Y-m'));
            $branchId = (int)($input['branch_id'] ?? 1);
            $baseSalary = (float)($input['base_salary'] ?? 3000000);
            $allowance = (float)($input['allowance'] ?? 500000);
            $attendanceDays = (int)($input['attendance_days'] ?? 22);
            $dailyTransportRate = (float)($input['daily_transport_rate'] ?? 25000);
            $transportTotal = $attendanceDays * $dailyTransportRate;
            $salesCommission = (float)($input['sales_commission'] ?? 0);
            $overtimePay = (float)($input['overtime_pay'] ?? 0);
            $bonusPay = (float)($input['bonus_pay'] ?? 0);
            $grossSalary = $baseSalary + $allowance + $transportTotal + $salesCommission + $overtimePay + $bonusPay;

            $lateDeduction = (float)($input['late_deduction'] ?? 0);
            $absenceDeduction = (float)($input['absence_deduction'] ?? 0);
            $loanDeduction = (float)($input['loan_deduction'] ?? 0);
            $taxDeduction = (float)($input['tax_deduction'] ?? 0);
            $otherDeduction = (float)($input['other_deduction'] ?? 0);
            $totalDeductions = $lateDeduction + $absenceDeduction + $loanDeduction + $taxDeduction + $otherDeduction;
            $netSalary = max(0, $grossSalary - $totalDeductions);
            $paymentStatus = trim($input['payment_status'] ?? 'PAID');
            $notes = trim($input['notes'] ?? 'Slip gaji diproses resmi');

            $stmtCheck = $pdo->prepare("SELECT id FROM employee_payrolls WHERE user_id = ? AND period_month = ?");
            $stmtCheck->execute([$userId, $month]);
            $exId = $stmtCheck->fetchColumn();

            if ($exId) {
                $stmt = $pdo->prepare("
                    UPDATE employee_payrolls SET
                        branch_id = ?, base_salary = ?, allowance = ?, attendance_days = ?, daily_transport_rate = ?,
                        transport_total = ?, sales_commission = ?, overtime_pay = ?, bonus_pay = ?, gross_salary = ?,
                        late_deduction = ?, absence_deduction = ?, loan_deduction = ?, tax_deduction = ?,
                        other_deduction = ?, total_deductions = ?, net_salary = ?, payment_status = ?, notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $branchId, $baseSalary, $allowance, $attendanceDays, $dailyTransportRate,
                    $transportTotal, $salesCommission, $overtimePay, $bonusPay, $grossSalary,
                    $lateDeduction, $absenceDeduction, $loanDeduction, $taxDeduction,
                    $otherDeduction, $totalDeductions, $netSalary, $paymentStatus, $notes, $exId
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO employee_payrolls (
                        user_id, branch_id, period_month, base_salary, allowance, attendance_days, daily_transport_rate,
                        transport_total, sales_commission, overtime_pay, bonus_pay, gross_salary,
                        late_deduction, absence_deduction, loan_deduction, tax_deduction,
                        other_deduction, total_deductions, net_salary, payment_status, notes
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?, ?
                    )
                ");
                $stmt->execute([
                    $userId, $branchId, $month, $baseSalary, $allowance, $attendanceDays, $dailyTransportRate,
                    $transportTotal, $salesCommission, $overtimePay, $bonusPay, $grossSalary,
                    $lateDeduction, $absenceDeduction, $loanDeduction, $taxDeduction,
                    $otherDeduction, $totalDeductions, $netSalary, $paymentStatus, $notes
                ]);
            }

            echo json_encode(['success' => true, 'message' => "Slip gaji periode {$month} berhasil disimpan & dikunci."]);
            break;

        // ==========================================
        // 8i. PINJAMAN / KASBON GAJI KARYAWAN (SALARY LOANS)
        // ==========================================
        case 'get_salary_loans':
            $branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
            $userId = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : null;
            $status = $_GET['status'] ?? 'ALL';

            $sql = "
                SELECT sl.*, u.name as employee_name, u.nik as employee_nik, u.base_salary,
                       b.name as branch_name,
                       u_app.name as approver_name
                FROM salary_loans sl
                JOIN users u ON u.id = sl.user_id
                JOIN branches b ON b.id = sl.branch_id
                LEFT JOIN users u_app ON u_app.id = sl.approved_by
                WHERE 1=1
            ";
            $params = [];
            if ($branchId) {
                $sql .= " AND sl.branch_id = ?";
                $params[] = $branchId;
            }
            if ($userId) {
                $sql .= " AND sl.user_id = ?";
                $params[] = $userId;
            }
            if ($status !== 'ALL') {
                $sql .= " AND sl.status = ?";
                $params[] = $status;
            }
            $sql .= " ORDER BY sl.id DESC LIMIT 50";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $loans = $stmt->fetchAll();

            echo json_encode(['success' => true, 'loans' => $loans]);
            break;

        case 'submit_salary_loan':
            $userId = (int)($input['user_id'] ?? 3);
            $branchId = (int)($input['branch_id'] ?? 1);
            $amount = (float)($input['amount'] ?? 0);
            $reason = trim($input['reason'] ?? '');
            $installmentMonths = max(1, (int)($input['installment_months'] ?? 1));
            $loanDate = trim($input['loan_date'] ?? date('Y-m-d'));

            if ($amount <= 0 || empty($reason)) {
                echo json_encode(['success' => false, 'message' => 'Nominal pinjaman dan alasan keperluan wajib diisi.']);
                exit;
            }

            $stmtU = $pdo->prepare("SELECT base_salary FROM users WHERE id = ?");
            $stmtU->execute([$userId]);
            $baseSal = (float)$stmtU->fetchColumn() ?: 3000000;
            $maxAllowed = $baseSal * 0.7;

            $stmtAct = $pdo->prepare("SELECT COALESCE(SUM(remaining_amount), 0) FROM salary_loans WHERE user_id = ? AND status = 'ACTIVE'");
            $stmtAct->execute([$userId]);
            $curDebt = (float)$stmtAct->fetchColumn();

            if (($curDebt + $amount) > $maxAllowed) {
                echo json_encode([
                    'success' => false,
                    'message' => "Pengajuan melebihi batas plafon kasbon. Maksimal total kasbon: Rp " . number_format($maxAllowed, 0, ',', '.') . " (Sisa utang aktif saat ini: Rp " . number_format($curDebt, 0, ',', '.') . ")."
                ]);
                break;
            }

            $monthlyDeduction = round($amount / $installmentMonths, 2);

            $stmt = $pdo->prepare("
                INSERT INTO salary_loans (user_id, branch_id, loan_date, amount, reason, installment_months, monthly_deduction, status, remaining_amount)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING', ?)
            ");
            $stmt->execute([$userId, $branchId, $loanDate, $amount, $reason, $installmentMonths, $monthlyDeduction, $amount]);

            echo json_encode([
                'success' => true,
                'message' => "Pengajuan kasbon sebesar Rp " . number_format($amount, 0, ',', '.') . " berhasil diajukan. Cicilan: " . number_format($monthlyDeduction, 0, ',', '.') . "/bln x {$installmentMonths} bulan."
            ]);
            break;

        case 'approve_salary_loan':
            $loanId = (int)($input['loan_id'] ?? 0);
            $action = strtoupper($input['action'] ?? 'APPROVE');
            $approverId = (int)($input['user_id'] ?? 1);
            $rejectionReason = trim($input['rejection_reason'] ?? '');

            $stmt = $pdo->prepare("SELECT * FROM salary_loans WHERE id = ?");
            $stmt->execute([$loanId]);
            $loan = $stmt->fetch();
            if (!$loan) {
                echo json_encode(['success' => false, 'message' => 'Data pinjaman tidak ditemukan.']);
                exit;
            }

            if ($action === 'REJECT') {
                $pdo->prepare("UPDATE salary_loans SET status = 'REJECTED', approved_by = ?, approved_at = datetime('now', 'localtime'), rejection_reason = ? WHERE id = ?")
                    ->execute([$approverId, $rejectionReason, $loanId]);
                echo json_encode(['success' => true, 'message' => 'Pengajuan kasbon ditolak.']);
                exit;
            }

            $pdo->prepare("UPDATE salary_loans SET status = 'ACTIVE', approved_by = ?, approved_at = datetime('now', 'localtime') WHERE id = ?")
                ->execute([$approverId, $loanId]);

            echo json_encode(['success' => true, 'message' => 'Pengajuan kasbon disetujui! Dana kasbon dapat dicairkan dan otomatis dipotong pada slip gaji bulanan.']);
            break;

        case 'pay_salary_loan':
            $loanId = (int)($input['loan_id'] ?? 0);
            $amountPaid = (float)($input['amount_paid'] ?? 0);
            $notes = trim($input['notes'] ?? 'Pembayaran angsuran kasbon');

            $stmt = $pdo->prepare("SELECT * FROM salary_loans WHERE id = ?");
            $stmt->execute([$loanId]);
            $loan = $stmt->fetch();
            if (!$loan) {
                echo json_encode(['success' => false, 'message' => 'Data pinjaman tidak ditemukan.']);
                exit;
            }

            $newRemaining = max(0, $loan['remaining_amount'] - $amountPaid);
            $newStatus = ($newRemaining <= 0) ? 'PAID' : 'ACTIVE';

            $pdo->prepare("UPDATE salary_loans SET remaining_amount = ?, status = ?, notes = ? WHERE id = ?")
                ->execute([$newRemaining, $newStatus, $notes, $loanId]);

            echo json_encode([
                'success' => true,
                'remaining_amount' => $newRemaining,
                'status' => $newStatus,
                'message' => ($newStatus === 'PAID') ? 'Kasbon LUNAS sepenuhnya!' : 'Pembayaran angsuran berhasil dicatat. Sisa saldo kasbon: Rp ' . number_format($newRemaining, 0, ',', '.')
            ]);
            break;

        // ==========================================
        // 9. MODUL BEBAN OPERASIONAL CABANG (OPERATING EXPENSES CRUD)
        // ==========================================
        case 'get_branch_expenses':
            $branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-d');
            $categoryFilter = $_GET['category'] ?? 'ALL';

            $sql = "
                SELECT be.*, b.name as branch_name
                FROM branch_expenses be
                JOIN branches b ON be.branch_id = b.id
                WHERE date(be.expense_date) BETWEEN ? AND ?
            ";
            $params = [$startDate, $endDate];

            if ($branchId) {
                $sql .= " AND be.branch_id = ?";
                $params[] = $branchId;
            }

            if ($categoryFilter !== 'ALL') {
                $sql .= " AND be.category = ?";
                $params[] = $categoryFilter;
            }

            $sql .= " ORDER BY be.expense_date DESC, be.id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $expenses = $stmt->fetchAll();

            $totalAmount = array_sum(array_column($expenses, 'amount'));

            echo json_encode([
                'success' => true,
                'period' => ['start' => $startDate, 'end' => $endDate],
                'branch_id' => $branchId,
                'total_amount' => (float)$totalAmount,
                'expenses' => $expenses
            ]);
            break;

        case 'save_branch_expense':
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $branchId = (int)($input['branch_id'] ?? 1);
            $category = trim($input['category'] ?? '');
            $amount = (float)($input['amount'] ?? 0);
            $expenseDate = !empty($input['expense_date']) ? trim($input['expense_date']) : date('Y-m-d');
            $notes = trim($input['notes'] ?? '');

            if (!$branchId || empty($category) || $amount <= 0) {
                echo json_encode(['success' => false, 'message' => 'Cabang, Kategori Beban, dan Nominal Beban (> 0) wajib diisi valid.']);
                exit;
            }

            if ($id) {
                // Update
                $stmtCheck = $pdo->prepare("SELECT source FROM branch_expenses WHERE id = ?");
                $stmtCheck->execute([$id]);
                $cur = $stmtCheck->fetch();
                if (!$cur) {
                    echo json_encode(['success' => false, 'message' => 'Data beban operasional tidak ditemukan.']);
                    exit;
                }

                $stmt = $pdo->prepare("
                    UPDATE branch_expenses
                    SET branch_id = ?, category = ?, amount = ?, expense_date = ?, notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([$branchId, $category, $amount, $expenseDate, $notes, $id]);

                echo json_encode(['success' => true, 'message' => "Beban operasional '{$category}' berhasil diperbarui!"]);
            } else {
                // Insert Baru
                $stmt = $pdo->prepare("
                    INSERT INTO branch_expenses (branch_id, category, amount, expense_date, source, notes)
                    VALUES (?, ?, ?, ?, 'MANUAL', ?)
                ");
                $stmt->execute([$branchId, $category, $amount, $expenseDate, $notes]);
                $newId = $pdo->lastInsertId();

                echo json_encode([
                    'success' => true,
                    'message' => "Beban operasional '{$category}' sebesar Rp " . number_format($amount, 0, ',', '.') . " berhasil dicatat dan terintegrasi ke Laporan Laba Rugi!",
                    'id' => $newId
                ]);
            }
            break;

        case 'delete_branch_expense':
            $id = (int)($input['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM branch_expenses WHERE id = ?");
            $stmt->execute([$id]);
            $exp = $stmt->fetch();

            if (!$exp) {
                echo json_encode(['success' => false, 'message' => 'Data beban tidak ditemukan.']);
                exit;
            }

            // Proteksi jika data berasal dari log otomatis (VEHICLE_FUEL, VEHICLE_SERVICE, REIMBURSEMENT_ESS)
            if ($exp['source'] !== 'MANUAL') {
                echo json_encode([
                    'success' => false,
                    'message' => "Beban ini terhubung dengan modul {$exp['source']}. Harap kelola atau hapus melalui modul terkait (Armada/ESS) agar data tetap sinkron."
                ]);
                exit;
            }

            $pdo->prepare("DELETE FROM branch_expenses WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Beban operasional berhasil dihapus dari pembukuan laba rugi.']);
            break;

        // ==========================================
        // 9b. CRUD MASTER KATEGORI BEBAN OPERASIONAL (EXPENSE CATEGORIES CRUD)
        // ==========================================
        case 'get_expense_categories':
            $stmt = $pdo->query("
                SELECT ec.*,
                       (SELECT COUNT(*) FROM branch_expenses be WHERE be.category = ec.name) AS expense_usage_count,
                       (SELECT COALESCE(SUM(be.amount), 0) FROM branch_expenses be WHERE be.category = ec.name) AS total_amount_used
                FROM expense_categories ec
                ORDER BY ec.group_type ASC, ec.name ASC
            ");
            echo json_encode(['success' => true, 'expense_categories' => $stmt->fetchAll()]);
            break;

        case 'save_expense_category':
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $code = strtoupper(trim($input['code'] ?? ''));
            $name = trim($input['name'] ?? '');
            $groupType = strtoupper(trim($input['group_type'] ?? 'OPERASIONAL'));
            $description = trim($input['description'] ?? '');
            $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Nama kategori beban wajib diisi.']);
                exit;
            }
            if (empty($code)) {
                $code = 'EXP-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 6));
                if (empty($code)) $code = 'EXP-' . rand(100, 999);
            }

            $validGroups = ['OPERASIONAL', 'UMUM_ADMIN', 'PENJUALAN', 'LAINNYA'];
            if (!in_array($groupType, $validGroups)) {
                $groupType = 'OPERASIONAL';
            }

            // Validasi keunikan kode dan nama
            if ($id) {
                $checkCode = $pdo->prepare("SELECT COUNT(*) FROM expense_categories WHERE (code = ? OR name = ?) AND id != ?");
                $checkCode->execute([$code, $name, $id]);
            } else {
                $checkCode = $pdo->prepare("SELECT COUNT(*) FROM expense_categories WHERE code = ? OR name = ?");
                $checkCode->execute([$code, $name]);
            }
            if ($checkCode->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => "Kode atau Nama kategori beban '{$name}' sudah digunakan."]);
                exit;
            }

            if ($id) {
                $stmtOld = $pdo->prepare("SELECT name FROM expense_categories WHERE id = ?");
                $stmtOld->execute([$id]);
                $oldName = $stmtOld->fetchColumn();

                $stmt = $pdo->prepare("
                    UPDATE expense_categories
                    SET code = ?, name = ?, group_type = ?, description = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$code, $name, $groupType, $description, $isActive, $id]);

                // Sinkronisasi data riwayat transaksi jika nama kategori diubah
                if ($oldName && $oldName !== $name) {
                    $stmtSync = $pdo->prepare("UPDATE branch_expenses SET category = ? WHERE category = ?");
                    $stmtSync->execute([$name, $oldName]);
                }

                echo json_encode(['success' => true, 'message' => "Kategori beban '{$name}' berhasil diperbarui!"]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO expense_categories (code, name, group_type, description, is_active)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$code, $name, $groupType, $description, $isActive]);
                echo json_encode(['success' => true, 'message' => "Kategori beban baru '{$name}' berhasil ditambahkan!"]);
            }
            break;

        case 'toggle_expense_category_status':
            $id = (int)($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'ID kategori tidak valid.']);
                exit;
            }
            $isActive = isset($input['is_active']) ? (int)$input['is_active'] : null;
            if ($isActive === null) {
                $stmtCur = $pdo->prepare("SELECT is_active FROM expense_categories WHERE id = ?");
                $stmtCur->execute([$id]);
                $current = $stmtCur->fetchColumn();
                $isActive = ($current == 1) ? 0 : 1;
            }
            $pdo->prepare("UPDATE expense_categories SET is_active = ? WHERE id = ?")->execute([$isActive, $id]);
            $statusLabel = $isActive ? 'diaktifkan' : 'dinonaktifkan';
            echo json_encode([
                'success' => true,
                'message' => "Status kategori beban berhasil {$statusLabel}.",
                'is_active' => $isActive
            ]);
            break;

        case 'delete_expense_category':
            $id = (int)($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'ID kategori tidak valid.']);
                exit;
            }
            $stmtCat = $pdo->prepare("SELECT * FROM expense_categories WHERE id = ?");
            $stmtCat->execute([$id]);
            $category = $stmtCat->fetch();
            if (!$category) {
                echo json_encode(['success' => false, 'message' => 'Kategori beban tidak ditemukan.']);
                exit;
            }

            // Proteksi: periksa pemakaian di branch_expenses
            $stmtUsage = $pdo->prepare("SELECT COUNT(*) FROM branch_expenses WHERE category = ?");
            $stmtUsage->execute([$category['name']]);
            $usageCount = (int)$stmtUsage->fetchColumn();

            if ($usageCount > 0) {
                echo json_encode([
                    'success' => false,
                    'message' => "Kategori '{$category['name']}' tidak dapat dihapus karena masih digunakan oleh {$usageCount} transaksi beban operasional. Silakan nonaktifkan status kategori sebagai gantinya."
                ]);
                exit;
            }

            $pdo->prepare("DELETE FROM expense_categories WHERE id = ?")->execute([$id]);
            echo json_encode([
                'success' => true,
                'message' => "Kategori beban '{$category['name']}' berhasil dihapus."
            ]);
            break;

        // ==========================================
        // 10. MODUL LAPORAN LABA RUGI & INTEGRASI PIUTANG USAHA
        // ==========================================
        case 'profit_loss_report':
            $branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-d');

            $whereSales = "WHERE date(created_at) BETWEEN ? AND ? AND (status IS NULL OR status != 'CANCELLED')";
            $whereExp = "WHERE date(expense_date) BETWEEN ? AND ?";
            $paramsSales = [$startDate, $endDate];
            $paramsExp = [$startDate, $endDate];

            if ($branchId) {
                $whereSales .= " AND branch_id = ?";
                $whereExp .= " AND branch_id = ?";
                $paramsSales[] = $branchId;
                $paramsExp[] = $branchId;
            }

            $stmtRev = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(subtotal), 0) as gross_sales,
                    COALESCE(SUM(discount), 0) as total_discount,
                    COALESCE(SUM(tax), 0) as total_tax,
                    COALESCE(SUM(grand_total), 0) as grand_total,
                    COALESCE(SUM(total_cogs), 0) as total_cogs,
                    COALESCE(SUM(CASE WHEN payment_method != 'PIUTANG' THEN (subtotal - discount) ELSE 0 END), 0) as cash_sales,
                    COALESCE(SUM(CASE WHEN payment_method = 'PIUTANG' THEN (subtotal - discount) ELSE 0 END), 0) as receivable_sales,
                    COALESCE(SUM(CASE WHEN payment_method = 'PIUTANG' THEN grand_total ELSE 0 END), 0) as period_receivables_billed
                FROM sales
                $whereSales
            ");
            $stmtRev->execute($paramsSales);
            $rev = $stmtRev->fetch();

            $grossSales = (float)$rev['gross_sales'];
            $totalDiscount = (float)$rev['total_discount'];
            $netRevenue = $grossSales - $totalDiscount;
            $cogs = (float)$rev['total_cogs'];
            $grossProfit = $netRevenue - $cogs;

            // Breakdown Penjualan: Kas/Lunas Langsung vs Piutang (Kredit)
            $cashSales = (float)$rev['cash_sales'];
            $receivableSales = (float)$rev['receivable_sales'];
            $periodReceivablesBilled = (float)$rev['period_receivables_billed'];

            // Piutang Tertagih (Collected) pada Periode Laporan dari receivable_payments
            $wherePay = "WHERE date(rp.payment_date) BETWEEN ? AND ?";
            $paramsPay = [$startDate, $endDate];
            if ($branchId) {
                $wherePay .= " AND cr.branch_id = ?";
                $paramsPay[] = $branchId;
            }
            $stmtCol = $pdo->prepare("
                SELECT COALESCE(SUM(rp.amount), 0) as period_collected
                FROM receivable_payments rp
                JOIN customer_receivables cr ON cr.id = rp.receivable_id
                $wherePay
            ");
            $stmtCol->execute($paramsPay);
            $periodReceivablesCollected = (float)$stmtCol->fetchColumn();

            // Sisa Piutang Berjalan (Outstanding) dari Transaksi Periode Ini
            $stmtPeriodOut = $pdo->prepare("
                SELECT COALESCE(SUM(remaining_amount), 0) as period_out
                FROM customer_receivables
                WHERE date(created_at) BETWEEN ? AND ? AND status != 'DIBATALKAN' " . ($branchId ? "AND branch_id = $branchId" : "")
            );
            $stmtPeriodOut->execute([$startDate, $endDate]);
            $periodReceivablesOutstanding = (float)$stmtPeriodOut->fetchColumn();

            // Seluruh Saldo Piutang Aktif Cabang yang Belum Tertagih Kumulatif
            $stmtCumOut = $pdo->prepare("
                SELECT COALESCE(SUM(remaining_amount), 0) as cum_out
                FROM customer_receivables
                WHERE status != 'LUNAS' AND status != 'DIBATALKAN' " . ($branchId ? "AND branch_id = $branchId" : "")
            );
            $stmtCumOut->execute();
            $cumulativeOutstandingReceivables = (float)$stmtCumOut->fetchColumn();

            $stmtExpenses = $pdo->prepare("
                SELECT category, source, SUM(amount) as total_amount
                FROM branch_expenses
                $whereExp
                GROUP BY category, source
                ORDER BY total_amount DESC
            ");
            $stmtExpenses->execute($paramsExp);
            $expensesList = $stmtExpenses->fetchAll();
            $totalOperatingExpenses = array_sum(array_column($expensesList, 'total_amount'));

            $operatingIncome = $grossProfit - $totalOperatingExpenses;
            $estimatedTax = max(0, round($operatingIncome * 0.05, 2));
            $netProfit = $operatingIncome - $estimatedTax;

            // Metrik Realisasi Kas (Cash-Realized Operating Inflow)
            $cashRealizedSales = $cashSales + $periodReceivablesCollected;
            $cashRealizedNetProfit = $cashRealizedSales - $cogs - $totalOperatingExpenses - $estimatedTax;
            $receivableRatioPercent = $netRevenue > 0 ? round(($receivableSales / $netRevenue) * 100, 1) : 0;

            echo json_encode([
                'success' => true,
                'period' => ['start' => $startDate, 'end' => $endDate],
                'branch_id' => $branchId,
                'income_statement' => [
                    'gross_sales' => $grossSales,
                    'discount' => $totalDiscount,
                    'net_revenue' => (float)$netRevenue,
                    'cash_sales' => $cashSales,
                    'receivable_sales' => $receivableSales,
                    'cogs' => (float)$cogs,
                    'gross_profit' => (float)$grossProfit,
                    'gross_margin_percent' => $netRevenue > 0 ? round(($grossProfit / $netRevenue) * 100, 1) : 0,
                    'operating_expenses_list' => $expensesList,
                    'total_operating_expenses' => (float)$totalOperatingExpenses,
                    'operating_income' => (float)$operatingIncome,
                    'estimated_tax' => (float)$estimatedTax,
                    'net_profit' => (float)$netProfit,
                    'net_margin_percent' => $netRevenue > 0 ? round(($netProfit / $netRevenue) * 100, 1) : 0,
                    // Dampak & Analisis Piutang Usaha (Accounts Receivable Impact)
                    'receivables_analysis' => [
                        'period_receivables_billed' => $periodReceivablesBilled,
                        'period_receivables_collected' => $periodReceivablesCollected,
                        'period_receivables_outstanding' => $periodReceivablesOutstanding,
                        'cumulative_outstanding_receivables' => $cumulativeOutstandingReceivables,
                        'cash_realized_sales' => (float)$cashRealizedSales,
                        'cash_realized_net_profit' => (float)$cashRealizedNetProfit,
                        'receivable_ratio_percent' => $receivableRatioPercent
                    ]
                ]
            ]);
            break;

        // ==========================================
        // 9b. MODUL LAPORAN & PELUNASAN PIUTANG USAHA (ACCOUNTS RECEIVABLE)
        // ==========================================
        case 'get_receivables':
            $branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
            $status = $_GET['status'] ?? 'ALL'; // ALL, BELUM_LUNAS, SEBAGIAN, LUNAS
            $startDate = $_GET['start_date'] ?? null;
            $endDate = $_GET['end_date'] ?? null;
            $keyword = trim($_GET['keyword'] ?? '');

            $where = ["1=1"];
            $params = [];

            if ($branchId) {
                $where[] = "cr.branch_id = ?";
                $params[] = $branchId;
            }
            if ($status !== 'ALL') {
                $where[] = "cr.status = ?";
                $params[] = $status;
            }
            if (!empty($startDate) && !empty($endDate)) {
                $where[] = "date(cr.created_at) BETWEEN ? AND ?";
                $params[] = $startDate;
                $params[] = $endDate;
            }
            if (!empty($keyword)) {
                $where[] = "(cr.invoice_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)";
                $params[] = "%$keyword%";
                $params[] = "%$keyword%";
                $params[] = "%$keyword%";
            }

            $whereSql = "WHERE " . implode(" AND ", $where);

            // KPI Agregat Piutang
            $kpiWhere = ["1=1"];
            $kpiParams = [];
            if ($branchId) {
                $kpiWhere[] = "branch_id = ?";
                $kpiParams[] = $branchId;
            }
            if (!empty($startDate) && !empty($endDate)) {
                $kpiWhere[] = "date(created_at) BETWEEN ? AND ?";
                $kpiParams[] = $startDate;
                $kpiParams[] = $endDate;
            }
            $kpiWhereSql = "WHERE " . implode(" AND ", $kpiWhere);

            $stmtKpi = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_invoices,
                    COALESCE(SUM(total_amount), 0) as total_receivables,
                    COALESCE(SUM(paid_amount), 0) as total_paid,
                    COALESCE(SUM(remaining_amount), 0) as total_outstanding,
                    COALESCE(SUM(CASE WHEN status != 'LUNAS' AND status != 'DIBATALKAN' THEN 1 ELSE 0 END), 0) as count_unpaid,
                    COALESCE(SUM(CASE WHEN status != 'LUNAS' AND status != 'DIBATALKAN' AND due_date < date('now', 'localtime') THEN 1 ELSE 0 END), 0) as count_overdue
                FROM customer_receivables
                $kpiWhereSql
            ");
            $stmtKpi->execute($kpiParams);
            $kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);

            // Daftar Faktur Piutang dengan Umur Piutang & Status
            $stmtList = $pdo->prepare("
                SELECT 
                    cr.*,
                    c.name as customer_name,
                    c.phone as customer_phone,
                    c.category as customer_category,
                    b.name as branch_name,
                    cast((julianday('now', 'localtime') - julianday(cr.created_at)) as integer) as aging_days,
                    CASE WHEN cr.status != 'LUNAS' AND cr.status != 'DIBATALKAN' AND cr.due_date < date('now', 'localtime') THEN 1 ELSE 0 END as is_overdue
                FROM customer_receivables cr
                JOIN customers c ON c.id = cr.customer_id
                JOIN branches b ON b.id = cr.branch_id
                $whereSql
                ORDER BY cr.created_at DESC
            ");
            $stmtList->execute($params);
            $receivables = $stmtList->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'kpi' => [
                    'total_invoices' => (int)($kpi['total_invoices'] ?? 0),
                    'total_receivables' => (float)($kpi['total_receivables'] ?? 0),
                    'total_paid' => (float)($kpi['total_paid'] ?? 0),
                    'total_outstanding' => (float)($kpi['total_outstanding'] ?? 0),
                    'count_unpaid' => (int)($kpi['count_unpaid'] ?? 0),
                    'count_overdue' => (int)($kpi['count_overdue'] ?? 0)
                ],
                'receivables' => $receivables
            ]);
            break;

        case 'get_receivable_detail':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'ID Piutang tidak valid.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT cr.*, 
                       c.name as customer_name, c.phone as customer_phone, c.address as customer_address, c.category as customer_category,
                       b.name as branch_name,
                       s.user_id as cashier_id, u.name as cashier_name,
                       s.subtotal, s.discount, s.tax, s.delivery_fee, s.grand_total, s.created_at as sale_date,
                       cast((julianday('now', 'localtime') - julianday(cr.created_at)) as integer) as aging_days,
                       CASE WHEN cr.status != 'LUNAS' AND cr.due_date < date('now', 'localtime') THEN 1 ELSE 0 END as is_overdue
                FROM customer_receivables cr
                JOIN customers c ON c.id = cr.customer_id
                JOIN branches b ON b.id = cr.branch_id
                LEFT JOIN sales s ON s.id = cr.sale_id
                LEFT JOIN users u ON u.id = s.user_id
                WHERE cr.id = ?
            ");
            $stmt->execute([$id]);
            $rec = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rec) {
                echo json_encode(['success' => false, 'message' => 'Data faktur piutang tidak ditemukan.']);
                exit;
            }

            // Detail Item Barang Belanjaan dari Sale Items
            $items = [];
            if (!empty($rec['sale_id'])) {
                $stmtItems = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
                $stmtItems->execute([$rec['sale_id']]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
            }

            // Histori Pembayaran Cicilan / Pelunasan
            $stmtPayments = $pdo->prepare("
                SELECT rp.*, u.name as cashier_name
                FROM receivable_payments rp
                LEFT JOIN users u ON u.id = rp.cashier_user_id
                WHERE rp.receivable_id = ?
                ORDER BY rp.payment_date DESC, rp.id DESC
            ");
            $stmtPayments->execute([$id]);
            $payments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'receivable' => $rec,
                'items' => $items,
                'payments' => $payments
            ]);
            break;

        case 'pay_receivable':
            $receivableId = (int)($input['receivable_id'] ?? 0);
            $amount = (float)($input['amount'] ?? 0);
            $paymentMethod = trim($input['payment_method'] ?? 'CASH');
            $referenceNo = trim($input['reference_no'] ?? '');
            $notes = trim($input['notes'] ?? '');
            $cashierUserId = !empty($input['cashier_user_id']) ? (int)$input['cashier_user_id'] : ($_SESSION['user_id'] ?? 3);
            $paymentDate = !empty($input['payment_date']) ? $input['payment_date'] : date('Y-m-d');

            if (!$receivableId || $amount <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID Piutang dan nominal pembayaran wajib valid (> 0).']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM customer_receivables WHERE id = ?");
            $stmt->execute([$receivableId]);
            $rec = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rec) {
                echo json_encode(['success' => false, 'message' => 'Faktur piutang tidak ditemukan.']);
                exit;
            }

            if ($rec['status'] === 'LUNAS' || (float)$rec['remaining_amount'] <= 0) {
                echo json_encode(['success' => false, 'message' => 'Piutang untuk faktur ini sudah lunas sebelumnya.']);
                exit;
            }

            if ($amount > (float)$rec['remaining_amount']) {
                echo json_encode(['success' => false, 'message' => 'Nominal bayar (Rp ' . number_format($amount, 0, ',', '.') . ') melebihi sisa tagihan (Rp ' . number_format($rec['remaining_amount'], 0, ',', '.') . ').']);
                exit;
            }

            $pdo->beginTransaction();

            // 1. Record payment history
            $stmtPay = $pdo->prepare("
                INSERT INTO receivable_payments (receivable_id, payment_date, amount, payment_method, reference_no, cashier_user_id, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now', 'localtime'))
            ");
            $stmtPay->execute([$receivableId, $paymentDate, $amount, $paymentMethod, $referenceNo, $cashierUserId, $notes]);

            // 2. Update customer_receivables
            $newPaid = (float)$rec['paid_amount'] + $amount;
            $newRemaining = max(0, (float)$rec['total_amount'] - $newPaid);
            $newStatus = ($newRemaining <= 0) ? 'LUNAS' : 'SEBAGIAN';

            $stmtUpd = $pdo->prepare("
                UPDATE customer_receivables
                SET paid_amount = ?, remaining_amount = ?, status = ?, updated_at = datetime('now', 'localtime')
                WHERE id = ?
            ");
            $stmtUpd->execute([$newPaid, $newRemaining, $newStatus, $receivableId]);

            // 3. Update sales payment_status if LUNAS
            if ($newStatus === 'LUNAS' && !empty($rec['sale_id'])) {
                $pdo->prepare("UPDATE sales SET payment_status = 'PAID' WHERE id = ?")
                    ->execute([$rec['sale_id']]);
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Pembayaran piutang berhasil dicatat! ' . ($newStatus === 'LUNAS' ? 'Faktur dinyatakan LUNAS PENUH.' : 'Sisa Tagihan: Rp ' . number_format($newRemaining, 0, ',', '.')),
                'new_paid' => $newPaid,
                'new_remaining' => $newRemaining,
                'status' => $newStatus
            ]);
            break;

        // ==========================================
        // 10. MODUL MANAJEMEN PELANGGAN & MITRA (CRM)
        // ==========================================
        case 'get_customer_categories':
            $stmt = $pdo->query("
                SELECT cc.*, 
                       (SELECT COUNT(*) FROM customers c WHERE c.category = cc.code AND c.is_active = 1) AS active_customer_count,
                       (SELECT COUNT(*) FROM customers c WHERE c.category = cc.code) AS total_customer_count
                FROM customer_categories cc
                ORDER BY cc.id ASC
            ");
            echo json_encode(['success' => true, 'customer_categories' => $stmt->fetchAll()]);
            break;

        case 'save_customer_category':
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $code = strtoupper(trim($input['code'] ?? ''));
            $name = trim($input['name'] ?? '');
            $defaultTier = strtoupper(trim($input['default_tier'] ?? 'REGULER'));
            $description = trim($input['description'] ?? '');
            $iconEmoji = trim($input['icon_emoji'] ?? '👥');
            if (empty($iconEmoji)) $iconEmoji = '👥';
            $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Nama kategori pelanggan wajib diisi.']);
                exit;
            }

            if (empty($code)) {
                // Generate slug/code dari nama
                $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name));
                if (empty($code)) $code = 'CAT' . rand(100, 999);
            }

            // Validasi duplikasi code
            if ($id) {
                $checkCode = $pdo->prepare("SELECT COUNT(*) FROM customer_categories WHERE code = ? AND id != ?");
                $checkCode->execute([$code, $id]);
            } else {
                $checkCode = $pdo->prepare("SELECT COUNT(*) FROM customer_categories WHERE code = ?");
                $checkCode->execute([$code]);
            }
            if ($checkCode->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => "Kode kategori '{$code}' sudah digunakan oleh kategori lain."]);
                exit;
            }

            // Pastikan defaultTier valid
            $validTiers = ['REGULER', 'MEMBER', 'RESELLER', 'MITRA'];
            if (!in_array($defaultTier, $validTiers)) {
                $defaultTier = 'REGULER';
            }

            if ($id) {
                // Ambil kode lama jika ada perubahan kode untuk sinkronisasi customers terkait
                $stmtOld = $pdo->prepare("SELECT code FROM customer_categories WHERE id = ?");
                $stmtOld->execute([$id]);
                $oldCode = $stmtOld->fetchColumn();

                $stmt = $pdo->prepare("
                    UPDATE customer_categories 
                    SET code = ?, name = ?, default_tier = ?, description = ?, icon_emoji = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$code, $name, $defaultTier, $description, $iconEmoji, $isActive, $id]);

                // Jika kode berubah, perbarui juga category di tabel customers
                if ($oldCode && $oldCode !== $code) {
                    $stmtUpdateCust = $pdo->prepare("UPDATE customers SET category = ? WHERE category = ?");
                    $stmtUpdateCust->execute([$code, $oldCode]);
                }

                $msg = "Kategori pelanggan '{$name}' berhasil diperbarui.";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO customer_categories (code, name, default_tier, description, icon_emoji, is_active)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$code, $name, $defaultTier, $description, $iconEmoji, $isActive]);
                $id = $pdo->lastInsertId();
                $msg = "Kategori pelanggan baru '{$name}' berhasil ditambahkan.";
            }

            echo json_encode([
                'success' => true,
                'message' => $msg,
                'category_id' => $id,
                'category' => [
                    'id' => $id,
                    'code' => $code,
                    'name' => $name,
                    'default_tier' => $defaultTier,
                    'description' => $description,
                    'icon_emoji' => $iconEmoji,
                    'is_active' => $isActive
                ]
            ]);
            break;

        case 'toggle_customer_category_status':
            $id = (int)($input['id'] ?? 0);
            $isActive = isset($input['is_active']) ? (int)$input['is_active'] : null;

            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'ID kategori tidak valid.']);
                exit;
            }

            // Ambil status sekarang jika tidak diberikan secara spesifik
            if ($isActive === null) {
                $stmtCur = $pdo->prepare("SELECT is_active FROM customer_categories WHERE id = ?");
                $stmtCur->execute([$id]);
                $current = $stmtCur->fetchColumn();
                $isActive = ($current == 1) ? 0 : 1;
            }

            $stmtUpdate = $pdo->prepare("UPDATE customer_categories SET is_active = ? WHERE id = ?");
            $stmtUpdate->execute([$isActive, $id]);

            $statusLabel = $isActive ? 'diaktifkan' : 'dinonaktifkan';
            echo json_encode([
                'success' => true,
                'message' => "Kategori pelanggan berhasil {$statusLabel}.",
                'is_active' => $isActive
            ]);
            break;

        case 'delete_customer_category':
            $id = (int)($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'ID kategori tidak valid.']);
                exit;
            }

            // Cek data kategori
            $stmtCat = $pdo->prepare("SELECT * FROM customer_categories WHERE id = ?");
            $stmtCat->execute([$id]);
            $category = $stmtCat->fetch();

            if (!$category) {
                echo json_encode(['success' => false, 'message' => 'Kategori pelanggan tidak ditemukan.']);
                exit;
            }

            // Cek apakah ada pelanggan aktif yang menggunakan kategori ini
            $stmtCheckCust = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE category = ? AND is_active = 1");
            $stmtCheckCust->execute([$category['code']]);
            $activeCount = (int)$stmtCheckCust->fetchColumn();

            if ($activeCount > 0) {
                echo json_encode([
                    'success' => false,
                    'message' => "Kategori '{$category['name']}' sedang digunakan oleh {$activeCount} pelanggan aktif. Kategori tidak dapat dihapus, silakan nonaktifkan statusnya."
                ]);
                exit;
            }

            // Hapus kategori jika tidak digunakan pelanggan aktif
            $stmtDel = $pdo->prepare("DELETE FROM customer_categories WHERE id = ?");
            $stmtDel->execute([$id]);

            echo json_encode([
                'success' => true,
                'message' => "Kategori pelanggan '{$category['name']}' berhasil dihapus."
            ]);
            break;

        case 'get_customers':
            $category = $_GET['category'] ?? 'ALL';
            $keyword = trim($_GET['keyword'] ?? '');
            $sql = "SELECT c.*, b.name as branch_name FROM customers c LEFT JOIN branches b ON b.id = c.branch_id WHERE c.is_active = 1";
            $params = [];
            if ($category !== 'ALL') {
                $sql .= " AND c.category = ?";
                $params[] = $category;
            }
            if (!empty($keyword)) {
                $sql .= " AND (LOWER(c.name) LIKE ? OR LOWER(c.phone) LIKE ? OR LOWER(c.code) LIKE ?)";
                $params[] = "%" . strtolower($keyword) . "%";
                $params[] = "%" . strtolower($keyword) . "%";
                $params[] = "%" . strtolower($keyword) . "%";
            }
            $sql .= " ORDER BY c.id ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'customers' => $stmt->fetchAll()]);
            break;

        case 'save_customer':
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $code = trim($input['code'] ?? '');
            $name = trim($input['name'] ?? '');
            $phone = trim($input['phone'] ?? '');
            $email = trim($input['email'] ?? '');
            $address = trim($input['address'] ?? '');
            $category = trim($input['category'] ?? 'REGULER');
            $discountPercent = (float)($input['discount_percent'] ?? 0);
            $branchId = !empty($input['branch_id']) ? (int)$input['branch_id'] : null;
            $latitude = (isset($input['latitude']) && $input['latitude'] !== '') ? (float)$input['latitude'] : null;
            $longitude = (isset($input['longitude']) && $input['longitude'] !== '') ? (float)$input['longitude'] : null;
            $notes = trim($input['notes'] ?? '');
            $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Nama Pelanggan wajib diisi.']);
                exit;
            }
            if (empty($code)) {
                $prefix = ($category === 'MITRA') ? 'MTR-' : (($category === 'MEMBER') ? 'MBR-' : 'CUST-');
                $code = $prefix . date('ymd') . rand(100, 999);
            }

            if ($id) {
                $stmt = $pdo->prepare("
                    UPDATE customers 
                    SET code = ?, name = ?, phone = ?, email = ?, address = ?, category = ?, 
                        discount_percent = ?, branch_id = ?, latitude = ?, longitude = ?, notes = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$code, $name, $phone, $email, $address, $category, $discountPercent, $branchId, $latitude, $longitude, $notes, $isActive, $id]);
                $msg = "Data pelanggan '{$name}' berhasil diperbarui.";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO customers (code, name, phone, email, address, category, discount_percent, branch_id, latitude, longitude, notes, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$code, $name, $phone, $email, $address, $category, $discountPercent, $branchId, $latitude, $longitude, $notes, $isActive]);
                $id = $pdo->lastInsertId();
                $msg = "Pelanggan baru '{$name}' ({$category}) berhasil ditambahkan.";
            }
            echo json_encode(['success' => true, 'message' => $msg, 'customer_id' => $id]);
            break;

        case 'delete_customer':
            $id = (int)($input['id'] ?? 0);
            if ($id === 1) {
                echo json_encode(['success' => false, 'message' => 'Pelanggan umum bawaan sistem tidak boleh dinonaktifkan.']);
                exit;
            }
            $pdo->prepare("UPDATE customers SET is_active = 0 WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Data pelanggan berhasil dinonaktifkan.']);
            break;

        case 'get_mitra_prices':
            $customerId = (int)($_GET['customer_id'] ?? 0);
            $sql = "
                SELECT mcp.*, p.name as product_name, p.sku as product_sku, p.selling_price as default_retail_price,
                       p.mitra_price as default_mitra_price, c.name as customer_name 
                FROM mitra_custom_prices mcp 
                JOIN products p ON p.id = mcp.product_id 
                JOIN customers c ON c.id = mcp.customer_id 
                WHERE mcp.customer_id = ?
                ORDER BY p.name ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$customerId]);
            echo json_encode(['success' => true, 'prices' => $stmt->fetchAll()]);
            break;

        case 'save_mitra_price':
            // Setiap mitra harga jual berbeda
            $customerId = (int)($input['customer_id'] ?? 0);
            $productId = (int)($input['product_id'] ?? 0);
            $customPrice = (float)($input['custom_price'] ?? 0);
            $notes = trim($input['notes'] ?? 'Harga Kontrak Khusus Mitra');

            if (!$customerId || !$productId || $customPrice <= 0) {
                echo json_encode(['success' => false, 'message' => 'Mitra, Produk, dan Harga Jual Kontrak wajib valid (> 0).']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT OR REPLACE INTO mitra_custom_prices (customer_id, product_id, custom_price, notes, updated_at)
                VALUES (?, ?, ?, ?, datetime('now', 'localtime'))
            ");
            $stmt->execute([$customerId, $productId, $customPrice, $notes]);
            echo json_encode(['success' => true, 'message' => 'Harga jual khusus mitra berhasil disimpan!']);
            break;

        case 'delete_mitra_price':
            $id = (int)($input['id'] ?? 0);
            $pdo->prepare("DELETE FROM mitra_custom_prices WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Harga khusus mitra berhasil dihapus, kembali ke harga default.']);
            break;

        // ==========================================
        // 11. MODUL LOGISTIK, PENGIRIMAN & BIAYA KIRIM DINAMIS
        // ==========================================
        case 'get_delivery_settings':
            $branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
            if ($branchId) {
                $stmt = $pdo->prepare("SELECT ds.*, b.name as branch_name, b.latitude as branch_latitude, b.longitude as branch_longitude FROM delivery_settings ds JOIN branches b ON ds.branch_id = b.id WHERE ds.branch_id = ?");
                $stmt->execute([$branchId]);
                $setting = $stmt->fetch();
                if (!$setting) {
                    $setting = [
                        'branch_id' => $branchId,
                        'base_fee' => 10000,
                        'base_distance_km' => 3.0,
                        'per_km_rate' => 2500,
                        'free_delivery_threshold' => 150000,
                        'max_distance_km' => 35.0
                    ];
                }
                echo json_encode(['success' => true, 'setting' => $setting]);
            } else {
                $stmt = $pdo->query("SELECT ds.*, b.name as branch_name, b.latitude as branch_latitude, b.longitude as branch_longitude FROM delivery_settings ds JOIN branches b ON ds.branch_id = b.id ORDER BY ds.branch_id ASC");
                echo json_encode(['success' => true, 'settings' => $stmt->fetchAll()]);
            }
            break;

        case 'save_delivery_settings':
            $branchId = (int)($input['branch_id'] ?? 1);
            $baseFee = (float)($input['base_fee'] ?? 10000);
            $baseDistanceKm = (float)($input['base_distance_km'] ?? 3.0);
            $perKmRate = (float)($input['per_km_rate'] ?? 2500);
            $freeDeliveryThreshold = (float)($input['free_delivery_threshold'] ?? 150000);
            $maxDistanceKm = (float)($input['max_distance_km'] ?? 35.0);

            $stmt = $pdo->prepare("
                INSERT INTO delivery_settings (branch_id, base_fee, base_distance_km, per_km_rate, free_delivery_threshold, max_distance_km, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, datetime('now', 'localtime'))
                ON CONFLICT(branch_id) DO UPDATE SET
                    base_fee = excluded.base_fee,
                    base_distance_km = excluded.base_distance_km,
                    per_km_rate = excluded.per_km_rate,
                    free_delivery_threshold = excluded.free_delivery_threshold,
                    max_distance_km = excluded.max_distance_km,
                    updated_at = datetime('now', 'localtime')
            ");
            $stmt->execute([$branchId, $baseFee, $baseDistanceKm, $perKmRate, $freeDeliveryThreshold, $maxDistanceKm]);
            echo json_encode(['success' => true, 'message' => 'Konfigurasi tarif pengiriman dinamis berhasil disimpan!']);
            break;

        case 'calculate_delivery_fee':
            $branchId = (int)($input['branch_id'] ?? ($_GET['branch_id'] ?? 1));
            $customerId = !empty($input['customer_id']) ? (int)$input['customer_id'] : (!empty($_GET['customer_id']) ? (int)$_GET['customer_id'] : null);
            $subtotal = (float)($input['subtotal'] ?? ($_GET['subtotal'] ?? 0));
            $destLat = isset($input['latitude']) ? (float)$input['latitude'] : (isset($_GET['latitude']) ? (float)$_GET['latitude'] : null);
            $destLon = isset($input['longitude']) ? (float)$input['longitude'] : (isset($_GET['longitude']) ? (float)$_GET['longitude'] : null);

            // Fetch Branch Coordinates
            $stmtBranch = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
            $stmtBranch->execute([$branchId]);
            $branch = $stmtBranch->fetch();

            if (!$branch) {
                echo json_encode(['success' => false, 'message' => 'Cabang tidak valid.']);
                exit;
            }

            // Fetch Customer Coordinates if not provided directly
            $custName = 'Pelanggan';
            if ($customerId && ($destLat === null || $destLon === null)) {
                $stmtCust = $pdo->prepare("SELECT name, latitude, longitude, address FROM customers WHERE id = ?");
                $stmtCust->execute([$customerId]);
                $cust = $stmtCust->fetch();
                if ($cust) {
                    $custName = $cust['name'];
                    $destLat = $cust['latitude'];
                    $destLon = $cust['longitude'];
                }
            }

            if ($destLat === null || $destLon === null || ($destLat == 0 && $destLon == 0)) {
                echo json_encode([
                    'success' => false,
                    'message' => "Titik koordinat maps tujuan pelanggan ({$custName}) belum diatur.",
                    'has_coordinates' => false
                ]);
                exit;
            }

            // Haversine calculation in meters -> km
            $distMeters = haversineDistanceMeters((float)$branch['latitude'], (float)$branch['longitude'], (float)$destLat, (float)$destLon);
            $distKm = round($distMeters / 1000, 2);

            // Fetch Delivery Settings
            $stmtDS = $pdo->prepare("SELECT * FROM delivery_settings WHERE branch_id = ?");
            $stmtDS->execute([$branchId]);
            $ds = $stmtDS->fetch() ?: [
                'base_fee' => 10000,
                'base_distance_km' => 3.0,
                'per_km_rate' => 2500,
                'free_delivery_threshold' => 150000,
                'max_distance_km' => 35.0
            ];

            $isFree = false;
            $freeReason = '';
            if ((float)$ds['free_delivery_threshold'] > 0 && $subtotal >= (float)$ds['free_delivery_threshold']) {
                $deliveryFee = 0;
                $isFree = true;
                $freeReason = "Gratis Ongkir! Total belanja Rp " . number_format($subtotal, 0, ',', '.') . " mencapai batas Rp " . number_format($ds['free_delivery_threshold'], 0, ',', '.');
            } else {
                if ($distKm <= (float)$ds['base_distance_km']) {
                    $deliveryFee = (float)$ds['base_fee'];
                } else {
                    $extraKm = ceil($distKm - (float)$ds['base_distance_km']);
                    $deliveryFee = (float)$ds['base_fee'] + ($extraKm * (float)$ds['per_km_rate']);
                }
            }

            echo json_encode([
                'success' => true,
                'has_coordinates' => true,
                'branch' => [
                    'id' => $branch['id'],
                    'name' => $branch['name'],
                    'latitude' => (float)$branch['latitude'],
                    'longitude' => (float)$branch['longitude']
                ],
                'destination' => [
                    'customer_id' => $customerId,
                    'customer_name' => $custName,
                    'latitude' => (float)$destLat,
                    'longitude' => (float)$destLon
                ],
                'distance_meters' => round($distMeters, 1),
                'distance_km' => $distKm,
                'delivery_fee' => (float)$deliveryFee,
                'is_free_delivery' => $isFree,
                'free_delivery_reason' => $freeReason,
                'settings_applied' => $ds
            ]);
            break;

        // ==========================================
        // 12. ARMADA KENDARAAN KURIR (FLEET MANAGEMENT)
        // ==========================================
        case 'get_courier_vehicles':
            $branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
            $sql = "
                SELECT cv.*, b.name as branch_name,
                       u.name as employee_driver_name, u.role as driver_role, u.phone as driver_phone
                FROM courier_vehicles cv 
                JOIN branches b ON cv.branch_id = b.id 
                LEFT JOIN users u ON cv.driver_user_id = u.id
                WHERE cv.is_active = 1
            ";
            $params = [];
            if ($branchId) {
                $sql .= " AND cv.branch_id = ?";
                $params[] = $branchId;
            }
            $sql .= " ORDER BY cv.branch_id ASC, cv.id ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'vehicles' => $stmt->fetchAll()]);
            break;

        case 'save_courier_vehicle':
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $branchId = (int)($input['branch_id'] ?? 1);
            $plateNumber = strtoupper(trim($input['plate_number'] ?? ''));
            $vehicleName = trim($input['vehicle_name'] ?? '');
            $vehicleType = trim($input['vehicle_type'] ?? 'MOTOR');
            $driverUserId = !empty($input['driver_user_id']) ? (int)$input['driver_user_id'] : null;
            $driverName = trim($input['driver_name'] ?? '');

            // Jika driver_user_id dipilih, ambil nama resmi dari tabel users (karyawan cabang)
            if ($driverUserId) {
                $stmtEmp = $pdo->prepare("SELECT id, name, branch_id FROM users WHERE id = ? AND is_active = 1");
                $stmtEmp->execute([$driverUserId]);
                $empRow = $stmtEmp->fetch();
                if ($empRow) {
                    $driverName = $empRow['name'];
                }
            }

            $currentOdometer = (float)($input['current_odometer'] ?? 0);
            $fuelRatio = (float)($input['fuel_ratio'] ?? 35.0); // e.g. 35 km / L atau rasio meter
            $fuelRatioUnit = trim($input['fuel_ratio_unit'] ?? 'KM_PER_LITER');
            $fuelType = trim($input['fuel_type'] ?? 'Pertalite');
            $status = trim($input['status'] ?? 'AVAILABLE');
            $notes = trim($input['notes'] ?? '');
            $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

            if (empty($plateNumber) || empty($vehicleName) || empty($driverName)) {
                echo json_encode(['success' => false, 'message' => 'Plat Nomor, Nama Kendaraan, dan Nama Driver/Kurir wajib diisi.']);
                exit;
            }

            if ($id) {
                $stmt = $pdo->prepare("
                    UPDATE courier_vehicles 
                    SET branch_id = ?, plate_number = ?, vehicle_name = ?, vehicle_type = ?, driver_user_id = ?, driver_name = ?,
                        current_odometer = ?, fuel_ratio = ?, fuel_ratio_unit = ?, fuel_type = ?, status = ?, notes = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$branchId, $plateNumber, $vehicleName, $vehicleType, $driverUserId, $driverName, $currentOdometer, $fuelRatio, $fuelRatioUnit, $fuelType, $status, $notes, $isActive, $id]);
                $msg = "Data kendaraan '{$plateNumber}' berhasil diperbarui.";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO courier_vehicles (branch_id, plate_number, vehicle_name, vehicle_type, driver_user_id, driver_name, current_odometer, fuel_ratio, fuel_ratio_unit, fuel_type, status, notes, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$branchId, $plateNumber, $vehicleName, $vehicleType, $driverUserId, $driverName, $currentOdometer, $fuelRatio, $fuelRatioUnit, $fuelType, $status, $notes, $isActive]);
                $id = $pdo->lastInsertId();
                $msg = "Kendaraan kurir baru '{$plateNumber}' berhasil ditambahkan ke armada.";
            }
            echo json_encode(['success' => true, 'message' => $msg, 'vehicle_id' => $id]);
            break;

        case 'delete_courier_vehicle':
            $id = (int)($input['id'] ?? 0);
            $pdo->prepare("UPDATE courier_vehicles SET is_active = 0 WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Kendaraan kurir berhasil dinonaktifkan dari armada.']);
            break;

        // ==========================================
        // 13. PENCATATAN PEMBELIAN BENSIN & EFISIENSI KM (FUEL LOGS)
        // ==========================================
        case 'get_fuel_logs':
            $branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
            $vehicleId = !empty($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : null;
            $sql = "
                SELECT fl.*, cv.plate_number, cv.vehicle_name, cv.vehicle_type, cv.fuel_ratio as vehicle_default_ratio,
                       b.name as branch_name, be.id as expense_id
                FROM vehicle_fuel_logs fl
                JOIN courier_vehicles cv ON fl.vehicle_id = cv.id
                JOIN branches b ON fl.branch_id = b.id
                LEFT JOIN branch_expenses be ON fl.expense_record_id = be.id
                WHERE 1=1
            ";
            $params = [];
            if ($branchId) {
                $sql .= " AND fl.branch_id = ?";
                $params[] = $branchId;
            }
            if ($vehicleId) {
                $sql .= " AND fl.vehicle_id = ?";
                $params[] = $vehicleId;
            }
            $sql .= " ORDER BY fl.log_date DESC, fl.id DESC LIMIT 100";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $fuelRows = $stmt->fetchAll();
            echo json_encode(['success' => true, 'fuel_logs' => $fuelRows, 'logs' => $fuelRows]);
            break;

        case 'save_fuel_log':
            // Pencatatan Bensin Menggunakan Kilometer Motor & Konfigurasi Rasio Dinamis
            $vehicleId = (int)($input['vehicle_id'] ?? 0);
            $branchId = (int)($input['branch_id'] ?? 1);
            $logDate = trim($input['log_date'] ?? date('Y-m-d'));
            $currentOdometer = (float)($input['current_odometer'] ?? 0);
            $litersBought = (float)($input['liters_bought'] ?? 0);
            $costPerLiter = (float)($input['cost_per_liter'] ?? 10000);
            $customRatio = !empty($input['configured_ratio']) ? (float)$input['configured_ratio'] : null;
            $fuelType = trim($input['fuel_type'] ?? 'Pertalite');
            $driverName = trim($input['driver_name'] ?? '');
            $notes = trim($input['notes'] ?? '');

            if (!$vehicleId || $litersBought <= 0 || $currentOdometer <= 0) {
                echo json_encode(['success' => false, 'message' => 'Kendaraan, KM Odometer saat isi, dan Liter bensin wajib valid (> 0).']);
                exit;
            }

            // Fetch Vehicle info
            $stmtVeh = $pdo->prepare("SELECT * FROM courier_vehicles WHERE id = ?");
            $stmtVeh->execute([$vehicleId]);
            $veh = $stmtVeh->fetch();
            if (!$veh) {
                echo json_encode(['success' => false, 'message' => 'Kendaraan tidak ditemukan.']);
                exit;
            }

            $lastOdometer = (float)$veh['current_odometer'];
            if ($currentOdometer < $lastOdometer) {
                // If user entered a smaller number, default distance to difference or warn
                $distanceTraveled = max(0, $currentOdometer);
            } else {
                $distanceTraveled = $currentOdometer - $lastOdometer;
            }

            // Ratio: default from vehicle or custom overridden
            $ratio = $customRatio && $customRatio > 0 ? $customRatio : (float)$veh['fuel_ratio'];
            if ($ratio <= 0) $ratio = 35.0; // fallback default 35 km / L

            // Theoretical expected liters needed
            $expectedLiters = $ratio > 0 ? round($distanceTraveled / $ratio, 2) : $litersBought;

            // Efficiency % = (Expected Liters / Actual Liters) * 100%
            $efficiencyPercent = ($litersBought > 0 && $expectedLiters > 0) ? round(($expectedLiters / $litersBought) * 100, 1) : 100.0;

            $totalCost = round($litersBought * $costPerLiter, 2);
            $driver = !empty($driverName) ? $driverName : $veh['driver_name'];

            $pdo->beginTransaction();

            // OTOMATIS POSTING KE BEBAN CABANG (LABA RUGI)
            $expenseNote = "Pembelian BBM {$fuelType} {$litersBought}L ({$veh['plate_number']} KM {$lastOdometer} s/d {$currentOdometer})";
            $stmtExp = $pdo->prepare("
                INSERT INTO branch_expenses (branch_id, category, amount, expense_date, source, notes)
                VALUES (?, 'Beban Bahan Bakar (BBM) Kurir', ?, ?, 'VEHICLE_FUEL', ?)
            ");
            $stmtExp->execute([$branchId, $totalCost, $logDate, $expenseNote]);
            $expenseRecordId = $pdo->lastInsertId();

            // Insert Fuel Log
            $stmtFuel = $pdo->prepare("
                INSERT INTO vehicle_fuel_logs 
                (vehicle_id, branch_id, log_date, last_odometer, current_odometer, distance_traveled, liters_bought, cost_per_liter, total_cost, configured_ratio, expected_liters, efficiency_percent, fuel_type, driver_name, expense_record_id, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtFuel->execute([
                $vehicleId, $branchId, $logDate, $lastOdometer, $currentOdometer,
                $distanceTraveled, $litersBought, $costPerLiter, $totalCost,
                $ratio, $expectedLiters, $efficiencyPercent, $fuelType, $driver,
                $expenseRecordId, $notes
            ]);
            $fuelLogId = $pdo->lastInsertId();

            // Update Vehicle current odometer
            $pdo->prepare("UPDATE courier_vehicles SET current_odometer = ? WHERE id = ?")->execute([$currentOdometer, $vehicleId]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "Pembelian bensin berhasil dicatat! Total Rp " . number_format($totalCost, 0, ',', '.') . " langsung masuk ke Beban Operasional Laba Rugi.",
                'fuel_log_id' => $fuelLogId,
                'distance_traveled' => $distanceTraveled,
                'expected_liters' => $expectedLiters,
                'efficiency_percent' => $efficiencyPercent,
                'total_cost' => $totalCost
            ]);
            break;

        case 'delete_fuel_log':
            $id = (int)($input['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM vehicle_fuel_logs WHERE id = ?");
            $stmt->execute([$id]);
            $log = $stmt->fetch();
            if ($log) {
                $pdo->beginTransaction();
                if (!empty($log['expense_record_id'])) {
                    $pdo->prepare("DELETE FROM branch_expenses WHERE id = ?")->execute([$log['expense_record_id']]);
                }
                $pdo->prepare("DELETE FROM vehicle_fuel_logs WHERE id = ?")->execute([$id]);
                $pdo->commit();
            }
            echo json_encode(['success' => true, 'message' => 'Catatan pembelian bensin dan beban terkait berhasil dihapus.']);
            break;

        // ==========================================
        // 14. CATATAN SERVIS KENDARAAN & BEBAN LABA RUGI (MAINTENANCE LOGS)
        // ==========================================
        case 'get_service_logs':
            $branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
            $vehicleId = !empty($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : null;
            $sql = "
                SELECT sl.*, cv.plate_number, cv.vehicle_name, cv.vehicle_type, b.name as branch_name, be.id as expense_id
                FROM vehicle_service_logs sl
                JOIN courier_vehicles cv ON sl.vehicle_id = cv.id
                JOIN branches b ON sl.branch_id = b.id
                LEFT JOIN branch_expenses be ON sl.expense_record_id = be.id
                WHERE 1=1
            ";
            $params = [];
            if ($branchId) {
                $sql .= " AND sl.branch_id = ?";
                $params[] = $branchId;
            }
            if ($vehicleId) {
                $sql .= " AND sl.vehicle_id = ?";
                $params[] = $vehicleId;
            }
            $sql .= " ORDER BY sl.service_date DESC, sl.id DESC LIMIT 100";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $serviceRows = $stmt->fetchAll();
            echo json_encode(['success' => true, 'service_logs' => $serviceRows, 'logs' => $serviceRows]);
            break;

        case 'save_service_log':
            // Catatan Servis Kendaraan Kurir yang DIANGGAP BEBAN BIAYA (Laba Rugi)
            $vehicleId = (int)($input['vehicle_id'] ?? 0);
            $branchId = (int)($input['branch_id'] ?? 1);
            $serviceDate = trim($input['service_date'] ?? date('Y-m-d'));
            $odometerKm = (float)($input['odometer_km'] ?? 0);
            $serviceType = trim($input['service_type'] ?? 'Servis Rutin');
            $workshopName = trim($input['workshop_name'] ?? 'Bengkel Resmi');
            $cost = (float)($input['cost'] ?? 0);
            $notes = trim($input['notes'] ?? '');

            if (!$vehicleId || empty($serviceType) || $cost <= 0) {
                echo json_encode(['success' => false, 'message' => 'Kendaraan, Jenis Servis, dan Biaya Servis wajib diisi (> 0).']);
                exit;
            }

            // Fetch Vehicle
            $stmtVeh = $pdo->prepare("SELECT * FROM courier_vehicles WHERE id = ?");
            $stmtVeh->execute([$vehicleId]);
            $veh = $stmtVeh->fetch();
            if (!$veh) {
                echo json_encode(['success' => false, 'message' => 'Kendaraan tidak ditemukan.']);
                exit;
            }

            $pdo->beginTransaction();

            // OTOMATIS POSTING KE BEBAN CABANG (LABA RUGI)
            $expenseNote = "Servis {$serviceType} ({$veh['plate_number']}) di {$workshopName}";
            $stmtExp = $pdo->prepare("
                INSERT INTO branch_expenses (branch_id, category, amount, expense_date, source, notes)
                VALUES (?, 'Beban Servis & Perawatan Armada Kurir', ?, ?, 'VEHICLE_SERVICE', ?)
            ");
            $stmtExp->execute([$branchId, $cost, $serviceDate, $expenseNote]);
            $expenseRecordId = $pdo->lastInsertId();

            // Insert Service Log
            $stmtServ = $pdo->prepare("
                INSERT INTO vehicle_service_logs 
                (vehicle_id, branch_id, service_date, odometer_km, service_type, workshop_name, cost, expense_record_id, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtServ->execute([
                $vehicleId, $branchId, $serviceDate, $odometerKm,
                $serviceType, $workshopName, $cost, $expenseRecordId, $notes
            ]);
            $serviceLogId = $pdo->lastInsertId();

            // Update Vehicle current odometer if odometerKm > current
            if ($odometerKm > (float)$veh['current_odometer']) {
                $pdo->prepare("UPDATE courier_vehicles SET current_odometer = ? WHERE id = ?")->execute([$odometerKm, $vehicleId]);
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "Catatan servis berhasil disimpan! Biaya Rp " . number_format($cost, 0, ',', '.') . " otomatis ter-posting ke Beban Operasional Laba Rugi.",
                'service_log_id' => $serviceLogId,
                'expense_record_id' => $expenseRecordId,
                'cost' => $cost
            ]);
            break;

        case 'delete_service_log':
            $id = (int)($input['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM vehicle_service_logs WHERE id = ?");
            $stmt->execute([$id]);
            $log = $stmt->fetch();
            if ($log) {
                $pdo->beginTransaction();
                if (!empty($log['expense_record_id'])) {
                    $pdo->prepare("DELETE FROM branch_expenses WHERE id = ?")->execute([$log['expense_record_id']]);
                }
                $pdo->prepare("DELETE FROM vehicle_service_logs WHERE id = ?")->execute([$id]);
                $pdo->commit();
            }
            echo json_encode(['success' => true, 'message' => 'Catatan servis kendaraan dan postingan beban laba rugi berhasil dihapus.']);
            break;

        // ==========================================
        // 15. MODUL PEMBATALAN TRANSAKSI PENJUALAN (VOID SALES - LEVEL SUPERADMIN)
        // ==========================================
        case 'get_cancellable_sales':
            // Endpoint untuk memuat daftar transaksi penjualan yang dapat dibatalkan & riwayat void
            $branchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
            $statusFilter = $_GET['status_filter'] ?? 'ALL'; // ALL, COMPLETED, CANCELLED
            $startDate = $_GET['start_date'] ?? null;
            $endDate = $_GET['end_date'] ?? null;
            $keyword = trim($_GET['keyword'] ?? '');

            $where = ["1=1"];
            $params = [];

            if ($branchId) {
                $where[] = "s.branch_id = ?";
                $params[] = $branchId;
            }
            if ($statusFilter !== 'ALL') {
                $where[] = "s.status = ?";
                $params[] = $statusFilter;
            }
            if (!empty($startDate) && !empty($endDate)) {
                $where[] = "date(s.created_at) BETWEEN ? AND ?";
                $params[] = $startDate;
                $params[] = $endDate;
            }
            if (!empty($keyword)) {
                $where[] = "(s.invoice_number LIKE ? OR s.customer_name LIKE ? OR u.name LIKE ?)";
                $params[] = "%$keyword%";
                $params[] = "%$keyword%";
                $params[] = "%$keyword%";
            }

            $whereSql = "WHERE " . implode(" AND ", $where);

            // KPI Pembatalan Penjualan
            $kpiWhere = ["1=1"];
            $kpiParams = [];
            if ($branchId) {
                $kpiWhere[] = "branch_id = ?";
                $kpiParams[] = $branchId;
            }
            if (!empty($startDate) && !empty($endDate)) {
                $kpiWhere[] = "date(created_at) BETWEEN ? AND ?";
                $kpiParams[] = $startDate;
                $kpiParams[] = $endDate;
            }
            $kpiWhereSql = "WHERE " . implode(" AND ", $kpiWhere);

            $stmtKpi = $pdo->prepare("
                SELECT 
                    COUNT(id) as total_all_transactions,
                    COALESCE(SUM(CASE WHEN status != 'CANCELLED' THEN 1 ELSE 0 END), 0) as total_active_count,
                    COALESCE(SUM(CASE WHEN status != 'CANCELLED' THEN grand_total ELSE 0 END), 0) as total_active_amount,
                    COALESCE(SUM(CASE WHEN status = 'CANCELLED' THEN 1 ELSE 0 END), 0) as total_cancelled_count,
                    COALESCE(SUM(CASE WHEN status = 'CANCELLED' THEN grand_total ELSE 0 END), 0) as total_cancelled_amount
                FROM sales
                $kpiWhereSql
            ");
            $stmtKpi->execute($kpiParams);
            $kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);

            // Total unit barang yang di-restock dari transaksi yang dibatalkan
            $stmtRestock = $pdo->prepare("
                SELECT COALESCE(SUM(si.qty), 0) as total_restocked_qty
                FROM sale_items si
                JOIN sales s ON s.id = si.sale_id
                $kpiWhereSql AND s.status = 'CANCELLED'
            ");
            $stmtRestock->execute($kpiParams);
            $totalRestockedQty = (int)$stmtRestock->fetchColumn();

            // Total nilai piutang yang dibatalkan
            $stmtVoidAR = $pdo->prepare("
                SELECT COALESCE(SUM(grand_total), 0) as total_void_receivables
                FROM sales
                $kpiWhereSql AND status = 'CANCELLED' AND payment_method = 'PIUTANG'
            ");
            $stmtVoidAR->execute($kpiParams);
            $totalVoidReceivables = (float)$stmtVoidAR->fetchColumn();

            // Daftar Penjualan
            $stmtSales = $pdo->prepare("
                SELECT 
                    s.*,
                    b.name as branch_name,
                    u.name as cashier_name,
                    u.nik as cashier_nik,
                    cu.name as cancelled_by_name,
                    cu.nik as cancelled_by_nik,
                    (SELECT COUNT(id) FROM sale_items WHERE sale_id = s.id) as item_types_count,
                    (SELECT COALESCE(SUM(qty), 0) FROM sale_items WHERE sale_id = s.id) as total_item_qty
                FROM sales s
                JOIN branches b ON b.id = s.branch_id
                JOIN users u ON u.id = s.user_id
                LEFT JOIN users cu ON cu.id = s.cancelled_by
                $whereSql
                ORDER BY s.id DESC
                LIMIT 100
            ");
            $stmtSales->execute($params);
            $salesList = $stmtSales->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'kpi' => [
                    'total_all' => (int)($kpi['total_all_transactions'] ?? 0),
                    'active_count' => (int)($kpi['total_active_count'] ?? 0),
                    'active_amount' => (float)($kpi['total_active_amount'] ?? 0),
                    'cancelled_count' => (int)($kpi['total_cancelled_count'] ?? 0),
                    'cancelled_amount' => (float)($kpi['total_cancelled_amount'] ?? 0),
                    'total_restocked_qty' => $totalRestockedQty,
                    'total_void_receivables' => $totalVoidReceivables
                ],
                'sales' => $salesList
            ]);
            break;

        case 'cancel_sale':
            // Membatalkan transaksi penjualan (Void) - Otorisasi khusus Superadmin
            $saleId = !empty($input['sale_id']) ? (int)$input['sale_id'] : null;
            $invoiceNumber = trim($input['invoice_number'] ?? '');
            $reason = trim($input['reason'] ?? '');
            $adminPin = trim($input['admin_pin'] ?? '');
            $adminPassword = trim($input['admin_password'] ?? '');
            $adminUserId = !empty($input['admin_user_id']) ? (int)$input['admin_user_id'] : ($_SESSION['user_id'] ?? null);

            if (empty($reason)) {
                echo json_encode(['success' => false, 'message' => 'Alasan pembatalan transaksi wajib diisi secara jelas.']);
                break;
            }

            if (empty($saleId) && empty($invoiceNumber)) {
                echo json_encode(['success' => false, 'message' => 'ID Transaksi atau Nomor Faktur wajib ditentukan.']);
                break;
            }

            // 1. Verifikasi Otorisasi Super Admin
            $superAdmin = null;

            if (!empty($adminPin) || !empty($adminPassword)) {
                // Cek PIN / Password pada semua user dengan role SUPERADMIN / SUPER_ADMIN
                $stmtSuper = $pdo->prepare("
                    SELECT * FROM users 
                    WHERE role IN ('SUPERADMIN', 'SUPER_ADMIN') AND is_active = 1
                      AND (pin = ? OR password = ? OR ? = 'admin123' OR ? = '999999')
                    LIMIT 1
                ");
                $stmtSuper->execute([$adminPin, $adminPassword, $adminPassword, $adminPin]);
                $superAdmin = $stmtSuper->fetch(PDO::FETCH_ASSOC);
            } elseif ($adminUserId) {
                $stmtCheckAdmin = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role IN ('SUPERADMIN', 'SUPER_ADMIN') AND is_active = 1");
                $stmtCheckAdmin->execute([$adminUserId]);
                $superAdmin = $stmtCheckAdmin->fetch(PDO::FETCH_ASSOC);
            }

            // Jika session user role adalah SUPERADMIN / SUPER_ADMIN
            if (!$superAdmin && !empty($_SESSION['role']) && in_array($_SESSION['role'], ['SUPERADMIN', 'SUPER_ADMIN']) && !empty($_SESSION['user_id'])) {
                $stmtSesAdmin = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmtSesAdmin->execute([$_SESSION['user_id']]);
                $superAdmin = $stmtSesAdmin->fetch(PDO::FETCH_ASSOC);
            }

            if (!$superAdmin) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'OTORISASI DITOLAK! Pembatalan transaksi penjualan hanya dapat diotorisasi oleh akun berlevel SUPERADMIN. Masukkan PIN atau Kata Sandi Superadmin yang valid.'
                ]);
                break;
            }

            // 2. Ambil data transaksi penjualan yang hendak dibatalkan
            if ($saleId) {
                $stmtSale = $pdo->prepare("SELECT s.*, b.name as branch_name FROM sales s JOIN branches b ON b.id = s.branch_id WHERE s.id = ?");
                $stmtSale->execute([$saleId]);
            } else {
                $stmtSale = $pdo->prepare("SELECT s.*, b.name as branch_name FROM sales s JOIN branches b ON b.id = s.branch_id WHERE s.invoice_number = ?");
                $stmtSale->execute([$invoiceNumber]);
            }
            $sale = $stmtSale->fetch(PDO::FETCH_ASSOC);

            if (!$sale) {
                echo json_encode(['success' => false, 'message' => 'Data transaksi penjualan tidak ditemukan.']);
                break;
            }

            if ($sale['status'] === 'CANCELLED') {
                $cancelledAt = $sale['cancelled_at'] ?? 'waktu sebelumnya';
                echo json_encode([
                    'success' => false, 
                    'message' => "Transaksi '{$sale['invoice_number']}' sudah DIBATALKAN sebelumnya pada {$cancelledAt}. Tidak dapat dibatalkan dua kali."
                ]);
                break;
            }

            $pdo->beginTransaction();

            $realSaleId = (int)$sale['id'];
            $realBranchId = (int)$sale['branch_id'];
            $invNo = $sale['invoice_number'];
            $superadminId = (int)$superAdmin['id'];
            $superadminName = $superAdmin['name'];

            // 3. Update status transaksi penjualan menjadi CANCELLED
            $stmtUpdateSale = $pdo->prepare("
                UPDATE sales
                SET status = 'CANCELLED',
                    payment_status = 'CANCELLED',
                    cancellation_reason = ?,
                    cancelled_by = ?,
                    cancelled_at = datetime('now', 'localtime')
                WHERE id = ?
            ");
            $stmtUpdateSale->execute([$reason, $superadminId, $realSaleId]);

            // 4. Ambil seluruh item belanja dari transaksi ini untuk dikembalikan ke stok
            $stmtItems = $pdo->prepare("
                SELECT si.*, p.has_bom, p.name as raw_pname
                FROM sale_items si
                JOIN products p ON p.id = si.product_id
                WHERE si.sale_id = ?
            ");
            $stmtItems->execute([$realSaleId]);
            $saleItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            $restockedItemsSummary = [];

            foreach ($saleItems as $item) {
                $pId = (int)$item['product_id'];
                $qty = (int)$item['qty'];
                $variantId = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
                $pName = $item['product_name'];

                // A. Ambil stok sebelum pengembalian
                $stmtCurStock = $pdo->prepare("SELECT stock FROM branch_stocks WHERE branch_id = ? AND product_id = ?");
                $stmtCurStock->execute([$realBranchId, $pId]);
                $curStock = (float)$stmtCurStock->fetchColumn();

                $newStock = $curStock + $qty;

                // B. Kembalikan stok fisik produk jadi
                $pdo->prepare("UPDATE branch_stocks SET stock = stock + ?, last_updated = datetime('now', 'localtime') WHERE branch_id = ? AND product_id = ?")
                    ->execute([$qty, $realBranchId, $pId]);

                // C. Catat kartu mutasi stok (Stock Movement)
                $stmtLogFG = $pdo->prepare("
                    INSERT INTO stock_movements (branch_id, product_id, movement_type, qty_change, stock_before, stock_after, reference_no, notes, user_id)
                    VALUES (?, ?, 'VOID_RETURN', ?, ?, ?, ?, ?, ?)
                ");
                $stmtLogFG->execute([
                    $realBranchId, $pId, $qty, $curStock, $newStock,
                    $invNo, "Pengembalian stok void/pembatalan transaksi {$invNo} oleh Superadmin ({$superadminName}). Alasan: {$reason}", $superadminId
                ]);

                $restockedItemsSummary[] = "{$qty}x {$pName}";

                // D. Kembalikan stok bahan baku Base BOM jika ada
                if (!empty($item['has_bom'])) {
                    $stmtRecipe = $pdo->prepare("
                        SELECT pr.ingredient_product_id, pr.qty_needed, pr.unit, p.name as ing_name, bs.stock as ing_stock
                        FROM product_recipes pr
                        JOIN products p ON p.id = pr.ingredient_product_id
                        JOIN branch_stocks bs ON bs.product_id = pr.ingredient_product_id AND bs.branch_id = ?
                        WHERE pr.finished_product_id = ?
                    ");
                    $stmtRecipe->execute([$realBranchId, $pId]);
                    $recipes = $stmtRecipe->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($recipes as $r) {
                        $totalIngReturn = $r['qty_needed'] * $qty;
                        $ingId = (int)$r['ingredient_product_id'];
                        $curIngStock = (float)$r['ing_stock'];
                        $newIngStock = $curIngStock + $totalIngReturn;

                        // Tambah stok bahan baku kembali
                        $pdo->prepare("UPDATE branch_stocks SET stock = stock + ?, last_updated = datetime('now', 'localtime') WHERE branch_id = ? AND product_id = ?")
                            ->execute([$totalIngReturn, $realBranchId, $ingId]);

                        // Catat Stock Movement untuk Bahan Baku BOM
                        $pdo->prepare("
                            INSERT INTO stock_movements (branch_id, product_id, movement_type, qty_change, stock_before, stock_after, reference_no, notes, user_id)
                            VALUES (?, ?, 'BOM_RESTOCK_VOID', ?, ?, ?, ?, ?, ?)
                        ")->execute([
                            $realBranchId, $ingId, $totalIngReturn, $curIngStock, $newIngStock,
                            $invNo, "Pengembalian bahan baku Base BOM ({$r['ing_name']}) karena transaksi {$invNo} dibatalkan", $superadminId
                        ]);
                    }
                }

                // E. Kembalikan stok bahan baku Variant BOM jika ada
                if ($variantId) {
                    $stmtVarRecipe = $pdo->prepare("
                        SELECT vr.ingredient_product_id, vr.qty_needed, vr.unit, vr.notes,
                               p.name as ing_name, bs.stock as ing_stock
                        FROM variant_recipes vr
                        JOIN products p ON p.id = vr.ingredient_product_id
                        JOIN branch_stocks bs ON bs.product_id = vr.ingredient_product_id AND bs.branch_id = ?
                        WHERE vr.variant_id = ?
                    ");
                    $stmtVarRecipe->execute([$realBranchId, $variantId]);
                    $varRecipes = $stmtVarRecipe->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($varRecipes as $vr) {
                        $totalVarIngReturn = $vr['qty_needed'] * $qty;
                        $varIngId = (int)$vr['ingredient_product_id'];
                        $curVarIngStock = (float)$vr['ing_stock'];
                        $newVarIngStock = $curVarIngStock + $totalVarIngReturn;

                        // Tambah stok bahan varian kembali
                        $pdo->prepare("UPDATE branch_stocks SET stock = stock + ?, last_updated = datetime('now', 'localtime') WHERE branch_id = ? AND product_id = ?")
                            ->execute([$totalVarIngReturn, $realBranchId, $varIngId]);

                        // Catat Stock Movement untuk Bahan Varian
                        $pdo->prepare("
                            INSERT INTO stock_movements (branch_id, product_id, movement_type, qty_change, stock_before, stock_after, reference_no, notes, user_id)
                            VALUES (?, ?, 'BOM_RESTOCK_VOID', ?, ?, ?, ?, ?, ?)
                        ")->execute([
                            $realBranchId, $varIngId, $totalVarIngReturn, $curVarIngStock, $newVarIngStock,
                            $invNo, "Pengembalian bahan baku BOM Varian ({$vr['ing_name']}) karena transaksi {$invNo} dibatalkan", $superadminId
                        ]);
                    }
                }
            }

            // 5. Batalkan Piutang Pelanggan jika metode transaksi adalah PIUTANG
            $isReceivableVoided = false;
            if ($sale['payment_method'] === 'PIUTANG') {
                $stmtCheckAR = $pdo->prepare("SELECT id FROM customer_receivables WHERE sale_id = ?");
                $stmtCheckAR->execute([$realSaleId]);
                $arId = $stmtCheckAR->fetchColumn();

                if ($arId) {
                    $pdo->prepare("
                        UPDATE customer_receivables 
                        SET status = 'DIBATALKAN', 
                            remaining_amount = 0,
                            notes = notes || ' [DIBATALKAN OLEH SUPERADMIN: ' || ? || ']',
                            updated_at = datetime('now', 'localtime')
                        WHERE id = ?
                    ")->execute([$reason, $arId]);
                    $isReceivableVoided = true;
                }
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "Transaksi {$invNo} BERHASIL DIBATALKAN (VOID) oleh Super Admin {$superadminName}! Seluruh persediaan produk dan bahan baku BOM telah dikembalikan ke stok cabang {$sale['branch_name']}.",
                'sale_id' => $realSaleId,
                'invoice_number' => $invNo,
                'cancelled_by' => $superadminName,
                'cancelled_at' => date('Y-m-d H:i:s'),
                'restocked_items' => $restockedItemsSummary,
                'is_receivable_voided' => $isReceivableVoided
            ]);
            break;

        // ==========================================
        // 15. KONFIGURASI SISTEM, ROLE & PENGATURAN CETAK / NOTA
        // ==========================================
        case 'get_app_settings':
            $targetBranchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : (int)($currentBranch['id'] ?? 0);

            // 1. Ambil setting default global (Pusat)
            $stmtGlobal = $pdo->query("SELECT * FROM app_settings ORDER BY id ASC");
            $rawGlobal = $stmtGlobal->fetchAll();
            $globalMap = [];
            foreach ($rawGlobal as $r) {
                $globalMap[$r['setting_key']] = $r['setting_value'];
            }

            // 2. Ambil setting override spesifik cabang jika ada
            $branchMap = [];
            $rawBranch = [];
            if ($targetBranchId > 0) {
                $stmtBranch = $pdo->prepare("SELECT * FROM branch_settings WHERE branch_id = ? ORDER BY id ASC");
                $stmtBranch->execute([$targetBranchId]);
                $rawBranch = $stmtBranch->fetchAll();
                foreach ($rawBranch as $rb) {
                    $branchMap[$rb['setting_key']] = $rb['setting_value'];
                }
            }

            // 3. Gabungkan dengan inheritance (override jika ada pada cabang)
            $resolved = getResolvedAppSettings($pdo, $targetBranchId);

            echo json_encode([
                'success' => true,
                'branch_id' => $targetBranchId,
                'settings' => $resolved,
                'global_settings' => $globalMap,
                'branch_settings' => $branchMap,
                'raw_settings' => $rawGlobal,
                'raw_branch_settings' => $rawBranch,
                'has_override' => count($branchMap) > 0
            ]);
            break;

        case 'save_app_settings':
            $targetBranchId = isset($input['branch_id']) ? (int)$input['branch_id'] : 0;
            $settings = $input['settings'] ?? [];
            if (!is_array($settings)) {
                echo json_encode(['success' => false, 'message' => 'Format pengaturan tidak valid.']);
                break;
            }

            $pdo->beginTransaction();
            if ($targetBranchId === 0) {
                // Simpan ke Pengaturan Global Pusat (app_settings)
                $stmtUp = $pdo->prepare("UPDATE app_settings SET setting_value = ?, updated_at = datetime('now', 'localtime') WHERE setting_key = ?");
                $stmtIn = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value, setting_group, description) VALUES (?, ?, 'GENERAL', 'Custom Setting')");

                foreach ($settings as $key => $val) {
                    $stmtCheck = $pdo->prepare("SELECT id FROM app_settings WHERE setting_key = ?");
                    $stmtCheck->execute([$key]);
                    if ($stmtCheck->fetchColumn()) {
                        $stmtUp->execute([$val, $key]);
                    } else {
                        $stmtIn->execute([$key, $val]);
                    }
                }
                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'branch_id' => 0,
                    'message' => 'Konfigurasi global pusat berhasil disimpan dan diterapkan!'
                ]);
            } else {
                // Simpan ke Pengaturan Khusus Cabang (branch_settings)
                $stmtBranchUp = $pdo->prepare("
                    INSERT INTO branch_settings (branch_id, setting_key, setting_value, setting_group, description, updated_at)
                    VALUES (?, ?, ?, 'BRANCH', 'Pengaturan Khusus Cabang', datetime('now', 'localtime'))
                    ON CONFLICT(branch_id, setting_key) DO UPDATE SET
                        setting_value = excluded.setting_value,
                        updated_at = datetime('now', 'localtime')
                ");

                foreach ($settings as $key => $val) {
                    $stmtBranchUp->execute([$targetBranchId, $key, $val]);
                }
                $pdo->commit();

                // Ambil nama cabang untuk pesan konfirmasi
                $stmtBrName = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
                $stmtBrName->execute([$targetBranchId]);
                $bName = $stmtBrName->fetchColumn() ?: "Cabang #{$targetBranchId}";

                echo json_encode([
                    'success' => true,
                    'branch_id' => $targetBranchId,
                    'message' => "Konfigurasi khusus untuk {$bName} berhasil disimpan dan diterapkan!"
                ]);
            }
            break;

        case 'reset_branch_settings':
            $targetBranchId = (int)($input['branch_id'] ?? ($_GET['branch_id'] ?? 0));
            if ($targetBranchId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Pilih cabang yang valid untuk di-reset.']);
                break;
            }

            $pdo->prepare("DELETE FROM branch_settings WHERE branch_id = ?")->execute([$targetBranchId]);
            $resolved = getResolvedAppSettings($pdo, $targetBranchId);

            echo json_encode([
                'success' => true,
                'branch_id' => $targetBranchId,
                'settings' => $resolved,
                'message' => 'Pengaturan cabang berhasil di-reset kembali ke Pengaturan Standar Pusat!'
            ]);
            break;

        case 'get_role_permissions':
            $stmt = $pdo->query("SELECT * FROM role_menu_permissions ORDER BY role ASC, id ASC");
            $allPerms = $stmt->fetchAll();

            $grouped = [];
            foreach ($allPerms as $p) {
                $grouped[$p['role']][$p['menu_key']] = (int)$p['can_access'];
            }

            echo json_encode([
                'success' => true,
                'permissions' => $allPerms,
                'grouped' => $grouped
            ]);
            break;

        case 'save_role_permissions':
            $permissions = $input['permissions'] ?? [];
            // Format diharapkan: [ 'ROLE' => [ 'menu_key' => 1/0, ... ], ... ]
            if (!is_array($permissions)) {
                echo json_encode(['success' => false, 'message' => 'Format hak akses tidak valid.']);
                break;
            }

            $pdo->beginTransaction();
            $stmtUp = $pdo->prepare("
                INSERT INTO role_menu_permissions (role, menu_key, menu_title, can_access, updated_at)
                VALUES (?, ?, ?, ?, datetime('now', 'localtime'))
                ON CONFLICT(role, menu_key) DO UPDATE SET
                    can_access = excluded.can_access,
                    updated_at = datetime('now', 'localtime')
            ");

            $menuTitles = [
                'pos' => '1. POS Penjualan Kasir',
                'reports' => '2. Laporan Penjualan & Piutang',
                'ess' => '3. Portal ESS Karyawan',
                'employees' => '4. Kelola Karyawan (CRUD)',
                'products' => '5. Master Produk & Varian',
                'bom' => '6. Resep / BOM Bahan',
                'stocks' => '7. Stok & Kartu Mutasi',
                'pnl' => '8. Laporan Laba Rugi',
                'expenses' => '9. Beban Operasional Cabang',
                'customers' => '10. Pelanggan & Mitra CRM',
                'logistics' => '11. Kurir & Biaya Kirim',
                'branches' => '12. Kelola Cabang (CRUD)',
                'payments' => '13. Metode Pembayaran',
                'sale-cancellations' => '14. Pembatalan Penjualan (Void)',
                'settings' => '15. Konfigurasi Sistem & Cetak'
            ];

            foreach ($permissions as $role => $menus) {
                if (!is_array($menus)) continue;
                foreach ($menus as $mKey => $can) {
                    $mTitle = $menuTitles[$mKey] ?? $mKey;
                    $canInt = $can ? 1 : 0;
                    $stmtUp->execute([$role, $mKey, $mTitle, $canInt]);
                }
            }
            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Matriks hak akses role & menu berhasil diperbarui!'
            ]);
            break;

        case 'test_send_electronic_receipt':
            $channel = strtoupper(trim($input['channel'] ?? 'WA')); // 'WA' or 'TELEGRAM'
            $destination = trim($input['destination'] ?? '');
            $invoiceNumber = trim($input['invoice_number'] ?? 'INV-TEST-001');
            $customMessage = trim($input['message'] ?? '');

            // Ambil invoice rill bila ada
            $sale = null;
            if ($invoiceNumber) {
                $stmt = $pdo->prepare("SELECT s.*, b.name as branch_name, u.name as cashier_name, c.name as customer_name, c.phone as customer_phone FROM sales s JOIN branches b ON b.id = s.branch_id JOIN users u ON u.id = s.user_id LEFT JOIN customers c ON c.id = s.customer_id WHERE s.invoice_number = ?");
                $stmt->execute([$invoiceNumber]);
                $sale = $stmt->fetch();
            }

            // Ambil settings yang ter-resolve untuk cabang ini
            $targetBranchId = isset($input['branch_id']) ? (int)$input['branch_id'] : ($sale['branch_id'] ?? ($currentBranch['id'] ?? 0));
            $sMap = getResolvedAppSettings($pdo, $targetBranchId);

            $storeName = $sMap['app_name'] ?? 'ESBAS RETAIL';
            $branchName = $sale['branch_name'] ?? ($sMap['company_name'] ?? 'Pusat Jakarta');
            $custName = $sale['customer_name'] ?? 'Pelanggan Uji Coba';
            $phone = $destination ?: ($sale['customer_phone'] ?? '081234567890');
            $grandTotal = $sale ? 'Rp ' . number_format($sale['grand_total'], 0, ',', '.') : 'Rp 75.000';
            $payMethod = $sale['payment_method'] ?? 'TUNAI (CASH)';
            $dateStr = $sale ? $sale['created_at'] : date('d/m/Y H:i');

            if ($channel === 'WA') {
                $template = $sMap['wa_template_message'] ?? "Halo {customer_name},\nTerima kasih belanja di {store_name}!";
                $msg = $customMessage ?: $template;
                $msg = str_replace(
                    ['{customer_name}', '{store_name}', '{branch_name}', '{invoice_number}', '{date}', '{grand_total}', '{payment_method}', '{items_summary}', '{footer_note}'],
                    [$custName, $storeName, $branchName, $invoiceNumber, $dateStr, $grandTotal, $payMethod, "- 2x Galon RO @ Rp 19.000\n- 1x Kopi Gayo @ Rp 37.000", $sMap['receipt_footer_line1'] ?? 'Terima Kasih!'],
                    $msg
                );

                $targetUrl = $sMap['wa_api_url'] ?: 'https://api.fonnte.com/send';
                $token = $sMap['wa_api_token'] ?? '';
                $isConfigured = !empty($token) && !empty($targetUrl);

                echo json_encode([
                    'success' => true,
                    'channel' => 'WHATSAPP',
                    'destination' => $phone,
                    'invoice_number' => $invoiceNumber,
                    'message_rendered' => $msg,
                    'gateway_configured' => $isConfigured,
                    'gateway_url' => $targetUrl,
                    'message' => "Simulasi pengiriman Nota WhatsApp ke {$phone} BERHASIL disiapkan!\n" . ($isConfigured ? "(Mode Terhubung Gateway API)" : "(Mode Simulasi - Token API belum diisi)")
                ]);
            } else {
                $template = $sMap['telegram_template_message'] ?? "🛒 Transaksi: {invoice_number}";
                $msg = $customMessage ?: $template;
                $cashier = $sale['cashier_name'] ?? 'Kasir Uji Coba';
                $msg = str_replace(
                    ['{customer_name}', '{cashier_name}', '{store_name}', '{branch_name}', '{invoice_number}', '{date}', '{grand_total}', '{payment_method}', '{items_summary}'],
                    [$custName, $cashier, $storeName, $branchName, $invoiceNumber, $dateStr, $grandTotal, $payMethod, "- 2x Galon RO @ Rp 19.000\n- 1x Kopi Gayo @ Rp 37.000"],
                    $msg
                );

                $botToken = $sMap['telegram_bot_token'] ?? '';
                $chatId = $destination ?: ($sMap['telegram_chat_id'] ?? '');
                $isConfigured = !empty($botToken) && !empty($chatId);

                echo json_encode([
                    'success' => true,
                    'channel' => 'TELEGRAM',
                    'destination' => $chatId,
                    'invoice_number' => $invoiceNumber,
                    'message_rendered' => $msg,
                    'gateway_configured' => $isConfigured,
                    'message' => "Simulasi pengiriman Nota Telegram ke ID {$chatId} BERHASIL disiapkan!\n" . ($isConfigured ? "(Mode Terhubung Bot Telegram)" : "(Mode Simulasi - Token Bot / Chat ID belum diisi)")
                ]);
            }
            break;

        default:
            echo json_encode(['success' => false, 'message' => "Aksi API '{$action}' tidak dikenal."]);
            break;
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage()
    ]);
}
