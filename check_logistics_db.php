<?php
require_once 'db.php';
// $pdo is initialized in db.php

echo "=== CHECKING LOGISTICS DATA ===\n";
$tables = ['courier_vehicles', 'vehicle_fuel_logs', 'vehicle_service_logs', 'delivery_settings'];
foreach ($tables as $t) {
    try {
        $cnt = $pdo->query("SELECT count(*) as c FROM $t")->fetchColumn();
        echo "$t: $cnt baris\n";
        $rows = $pdo->query("SELECT * FROM $t LIMIT 3")->fetchAll();
        print_r($rows);
    } catch (Exception $e) {
        echo "$t: ERROR - " . $e->getMessage() . "\n";
    }
}
