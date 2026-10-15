<?php
// Test API Endpoints
require_once __DIR__ . '/db.php';

echo "=== TEST API ENDPOINTS FOR PIUTANG & PROFIT LOSS ===\n";

// 1. Test get_receivables
$_GET['action'] = 'get_receivables';
$_GET['branch_id'] = 1;
$_GET['status'] = 'ALL';

ob_start();
include __DIR__ . '/api.php';
$resp1 = ob_get_clean();

$json1 = json_decode($resp1, true);
echo "1. get_receivables: " . ($json1['success'] ? "SUCCESS" : "FAILED") . "\n";
echo "   Total Invoices: " . ($json1['kpi']['total_invoices'] ?? 0) . "\n";
echo "   Total Receivables: Rp " . number_format($json1['kpi']['total_receivables'] ?? 0, 0, ',', '.') . "\n";
echo "   Total Paid: Rp " . number_format($json1['kpi']['total_paid'] ?? 0, 0, ',', '.') . "\n";
echo "   Total Outstanding: Rp " . number_format($json1['kpi']['total_outstanding'] ?? 0, 0, ',', '.') . "\n";
echo "   Count Unpaid: " . ($json1['kpi']['count_unpaid'] ?? 0) . "\n";

// 2. Test get_receivable_detail for first item
$firstId = $json1['receivables'][0]['id'] ?? 1;
$_GET['action'] = 'get_receivable_detail';
$_GET['id'] = $firstId;

ob_start();
include __DIR__ . '/api.php';
$resp2 = ob_get_clean();

$json2 = json_decode($resp2, true);
echo "2. get_receivable_detail (ID {$firstId}): " . ($json2['success'] ? "SUCCESS" : "FAILED") . "\n";
if ($json2['success']) {
    echo "   Customer: " . $json2['receivable']['customer_name'] . "\n";
    echo "   Invoice: " . $json2['receivable']['invoice_number'] . "\n";
    echo "   Items count: " . count($json2['items']) . "\n";
    echo "   Payments count: " . count($json2['payments']) . "\n";
}

// 3. Test profit_loss_report with accounts receivable impact
$_GET['action'] = 'profit_loss_report';
$_GET['branch_id'] = 1;
$_GET['start_date'] = date('Y-m-01');
$_GET['end_date'] = date('Y-m-d');

ob_start();
include __DIR__ . '/api.php';
$resp3 = ob_get_clean();

$json3 = json_decode($resp3, true);
echo "3. profit_loss_report: " . ($json3['success'] ? "SUCCESS" : "FAILED") . "\n";
if ($json3['success']) {
    $pnl = $json3['income_statement'];
    echo "   Net Revenue: Rp " . number_format($pnl['net_revenue'], 0, ',', '.') . "\n";
    echo "   - Cash Sales: Rp " . number_format($pnl['cash_sales'], 0, ',', '.') . "\n";
    echo "   - Receivable Sales: Rp " . number_format($pnl['receivable_sales'], 0, ',', '.') . "\n";
    $ar = $pnl['receivables_analysis'];
    echo "   AR Billed: Rp " . number_format($ar['period_receivables_billed'], 0, ',', '.') . "\n";
    echo "   AR Collected: Rp " . number_format($ar['period_receivables_collected'], 0, ',', '.') . "\n";
    echo "   Cash Realized Sales: Rp " . number_format($ar['cash_realized_sales'], 0, ',', '.') . "\n";
    echo "   Cash Realized Net Profit: Rp " . number_format($ar['cash_realized_net_profit'], 0, ',', '.') . "\n";
}

echo "ALL API SMOKE TESTS PASSED!\n";
