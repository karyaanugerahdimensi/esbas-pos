<?php
require __DIR__ . '/db.php';

echo "--- PAYMENT METHODS ---\n";
$pms = $pdo->query("SELECT * FROM payment_methods")->fetchAll();
print_r($pms);

echo "--- SALES SCHEMA ---\n";
$salesCols = $pdo->query("PRAGMA table_info(sales)")->fetchAll();
print_r($salesCols);

echo "--- RECENT SALES WITH CUSTOMER & PAYMENT METHOD ---\n";
$sales = $pdo->query("SELECT id, invoice_number, customer_id, customer_name, customer_category, grand_total, payment_method, created_at FROM sales ORDER BY id DESC LIMIT 5")->fetchAll();
print_r($sales);
