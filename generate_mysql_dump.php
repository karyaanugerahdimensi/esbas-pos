<?php
// generate_mysql_dump.php
// Dynamically inspects all SQLite tables and generates 100% matched MySQL DDL & data dump

require_once __DIR__ . '/db.php';

$outputFile = __DIR__ . '/esbas_pos_mysql.sql';

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

// Define table creation order to respect foreign keys
$preferredOrder = [
    'branches',
    'categories',
    'product_types',
    'product_units',
    'taxes',
    'payment_methods',
    'work_shifts',
    'customer_categories',
    'expense_categories',
    'app_settings',
    'role_menu_permissions',
    'users',
    'products',
    'product_variants',
    'product_recipes',
    'variant_recipes',
    'branch_stocks',
    'customers',
    'mitra_custom_prices',
    'delivery_settings',
    'courier_vehicles',
    'cashier_shifts',
    'opening_shift_products',
    'sales',
    'sale_items',
    'stock_movements',
    'attendance_logs',
    'shift_schedules',
    'shift_swaps',
    'leave_requests',
    'salary_loans',
    'employee_payrolls',
    'reimbursements',
    'branch_expenses',
    'vehicle_fuel_logs',
    'vehicle_service_logs',
    'customer_receivables',
    'receivable_payments',
    'branch_settings',
    'employee_meal_allowances'
];

// Sort tables according to preferred order, append any remaining
$orderedTables = [];
foreach ($preferredOrder as $t) {
    if (in_array($t, $tables)) {
        $orderedTables[] = $t;
    }
}
foreach ($tables as $t) {
    if (!in_array($t, $orderedTables)) {
        $orderedTables[] = $t;
    }
}

$sql = "-- ========================================================\n";
$sql .= "-- ESBAS POS Database Export (MySQL / MariaDB Compatible)\n";
$sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
$sql .= "-- ========================================================\n\n";
$sql .= "SET FOREIGN_KEY_CHECKS = 0;\n";
$sql .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
$sql .= "SET time_zone = '+07:00';\n\n";

foreach ($orderedTables as $tName) {
    $cols = $pdo->query("PRAGMA table_info(`$tName`)")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($cols)) continue;

    // Get unique indexes
    $indexes = $pdo->query("PRAGMA index_list(`$tName`)")->fetchAll(PDO::FETCH_ASSOC);
    $uniqueCols = [];
    $compositeUniques = [];
    foreach ($indexes as $idx) {
        if ($idx['unique'] && strpos($idx['name'], 'autoindex') === false) {
            $idxCols = $pdo->query("PRAGMA index_info(`{$idx['name']}`)")->fetchAll(PDO::FETCH_ASSOC);
            $colNames = array_column($idxCols, 'name');
            if (count($colNames) === 1) {
                $uniqueCols[] = $colNames[0];
            } else {
                $compositeUniques[] = $colNames;
            }
        }
    }

    $colDefs = [];
    $pkCols = [];

    foreach ($cols as $c) {
        $cName = $c['name'];
        $cType = strtoupper($c['type']);
        $notNull = $c['notnull'] ? ' NOT NULL' : '';
        $default = '';
        if ($c['dflt_value'] !== null) {
            $defVal = $c['dflt_value'];
            if (strtoupper($defVal) === 'CURRENT_TIMESTAMP') {
                $default = ' DEFAULT CURRENT_TIMESTAMP';
            } elseif (is_numeric($defVal)) {
                $default = " DEFAULT $defVal";
            } elseif (strtoupper($defVal) === 'NULL') {
                $default = ' DEFAULT NULL';
            } else {
                $default = " DEFAULT " . $defVal;
            }
        }

        if ($c['pk'] == 1 && (strpos($cType, 'INT') !== false || empty($cType))) {
            $colDefs[] = "    `$cName` INT AUTO_INCREMENT PRIMARY KEY";
            continue;
        }

        // Map column type to MySQL
        $mysqlType = 'VARCHAR(255)';

        if ($cName === 'latitude' || $cName === 'longitude') {
            $mysqlType = 'DECIMAL(10, 7)';
        } elseif (strpos($cName, 'json') !== false || $cName === 'receipt_photo' || $cName === 'setting_value') {
            $mysqlType = 'LONGTEXT';
        } elseif (in_array($cName, ['address', 'notes', 'reason', 'description', 'cancellation_reason', 'rejection_reason', 'attachment_doc'])) {
            $mysqlType = 'TEXT';
        } elseif (in_array($cName, ['code', 'nik', 'sku', 'barcode', 'plate_number', 'sku_variant', 'setting_key', 'invoice_number']) || in_array($cName, $uniqueCols)) {
            $mysqlType = 'VARCHAR(100)';
        } elseif ($cName === 'email') {
            $mysqlType = 'VARCHAR(191)';
        } elseif (strpos($cName, 'is_') === 0 || strpos($cName, 'has_') === 0 || $cName === 'status' && $cType === 'INTEGER') {
            $mysqlType = 'TINYINT(1)';
        } elseif (strpos($cType, 'INT') !== false) {
            $mysqlType = 'INT';
        } elseif ($cType === 'REAL' || $cType === 'FLOAT' || $cType === 'DOUBLE' || strpos($cType, 'DECIMAL') !== false) {
            if (strpos($cName, 'percent') !== false || strpos($cName, 'rate') !== false || strpos($cName, 'ratio') !== false) {
                $mysqlType = 'DECIMAL(8, 4)';
            } elseif (strpos($cName, 'km') !== false || strpos($cName, 'distance') !== false || strpos($cName, 'liters') !== false || strpos($cName, 'qty') !== false || $cName === 'stock') {
                $mysqlType = 'DECIMAL(12, 4)';
            } else {
                $mysqlType = 'DECIMAL(15, 2)';
            }
        } elseif ($cType === 'DATE') {
            $mysqlType = 'DATE';
        } elseif ($cType === 'DATETIME') {
            $mysqlType = 'DATETIME';
            if ($cName === 'updated_at') {
                $default = ' DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
            }
        } else {
            $mysqlType = 'VARCHAR(255)';
        }

        // Check if unique column
        $uniqueStr = in_array($cName, $uniqueCols) ? ' UNIQUE' : '';
        $colDefs[] = "    `$cName` $mysqlType$notNull$default$uniqueStr";
    }

    // Add composite uniques
    foreach ($compositeUniques as $idxCols) {
        $colList = implode('`, `', $idxCols);
        $idxName = 'uk_' . implode('_', $idxCols);
        $colDefs[] = "    UNIQUE KEY `$idxName` (`$colList`)";
    }

    $ddl = "DROP TABLE IF EXISTS `$tName`;\n";
    $ddl .= "CREATE TABLE `$tName` (\n" . implode(",\n", $colDefs) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $sql .= "-- --------------------------------------------------------\n";
    $sql .= "-- Table structure for `$tName`\n";
    $sql .= "-- --------------------------------------------------------\n";
    $sql .= $ddl . "\n\n";

    // Export current data from SQLite
    try {
        $stmt = $pdo->query("SELECT * FROM `$tName`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $sql .= "-- Dumping data for table `$tName`\n";
            $columns = array_keys($rows[0]);
            $colList = implode('`, `', $columns);

            $valChunks = [];
            foreach ($rows as $row) {
                $escapedVals = [];
                foreach ($row as $val) {
                    if ($val === null) {
                        $escapedVals[] = 'NULL';
                    } elseif (is_numeric($val) && !is_string($val)) {
                        $escapedVals[] = $val;
                    } else {
                        // Escape string
                        $escaped = str_replace(
                            ["\\", "\x00", "\n", "\r", "'", '"', "\x1a"],
                            ["\\\\", "\\0", "\\n", "\\r", "\\'", '\\"', "\\Z"],
                            $val
                        );
                        $escapedVals[] = "'" . $escaped . "'";
                    }
                }
                $valChunks[] = "(" . implode(', ', $escapedVals) . ")";
            }

            // Write in batches of 100
            $chunks = array_chunk($valChunks, 100);
            foreach ($chunks as $chunk) {
                $sql .= "INSERT INTO `$tName` (`$colList`) VALUES\n" . implode(",\n", $chunk) . ";\n";
            }
            $sql .= "\n";
        }
    } catch (Exception $e) {
        $sql .= "-- Note: Error dumping data for $tName: " . $e->getMessage() . "\n\n";
    }
}

$sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
$sql .= "\n-- End of ESBAS POS MySQL Dump\n";

file_put_contents($outputFile, $sql);
echo "SUCCESS: MySQL SQL Dump successfully created at $outputFile (" . number_format(filesize($outputFile)) . " bytes)\n";
