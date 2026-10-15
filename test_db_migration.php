<?php
require_once __DIR__ . '/db.php';

echo "Testing Database Migration...\n";
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
echo "Tables found: " . implode(', ', $tables) . "\n";

$hasRecTable = in_array('customer_receivables', $tables);
$hasPayTable = in_array('receivable_payments', $tables);
echo "customer_receivables table exists: " . ($hasRecTable ? "YES" : "NO") . "\n";
echo "receivable_payments table exists: " . ($hasPayTable ? "YES" : "NO") . "\n";

$recCount = $pdo->query("SELECT COUNT(*) FROM customer_receivables")->fetchColumn();
echo "Total receivables records: " . $recCount . "\n";

$payCount = $pdo->query("SELECT COUNT(*) FROM receivable_payments")->fetchColumn();
echo "Total receivable payments records: " . $payCount . "\n";

$pmList = $pdo->query("SELECT code, name, is_active FROM payment_methods")->fetchAll(PDO::FETCH_ASSOC);
echo "Payment methods:\n";
foreach ($pmList as $pm) {
    echo " - [{$pm['code']}] {$pm['name']} (Active: {$pm['is_active']})\n";
}
