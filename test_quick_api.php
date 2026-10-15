<?php
require_once 'db.php';

// Test 1: get_opening_shift_data
$_GET['action'] = 'get_opening_shift_data';
$_GET['branch_id'] = 1;
ob_start();
include 'api.php';
$res1 = json_decode(ob_get_clean(), true);
echo "Test 1 get_opening_shift_data:\n";
echo "Success: " . ($res1['success'] ? 'YES' : 'NO') . "\n";
echo "Next Shift Number: " . ($res1['next_shift_number'] ?? 'N/A') . "\n";
echo "Next Shift Name: " . ($res1['next_shift_name'] ?? 'N/A') . "\n";
echo "Shift Ref Title: " . ($res1['shift_reference_title'] ?? 'N/A') . "\n";

// Test 2: get_shift_reports
unset($_GET);
$_GET['action'] = 'get_shift_reports';
$_GET['branch_id'] = 1;
$_GET['date'] = date('Y-m-d');
ob_start();
include 'api.php';
$res2 = json_decode(ob_get_clean(), true);
echo "\nTest 2 get_shift_reports:\n";
echo "Success: " . ($res2['success'] ? 'YES' : 'NO') . "\n";
echo "Total shifts today: " . count($res2['shifts'] ?? []) . "\n";
echo "Daily summary total revenue: " . ($res2['daily_summary']['total_revenue'] ?? 0) . "\n";
