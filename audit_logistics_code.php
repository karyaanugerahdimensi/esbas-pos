<?php
$content = file_get_contents('index.php');
$checks = [
    'function switchLogisticsSubtab',
    'function setLogisticsSubtab',
    'function renderPosCourierSelect',
    'fuel-table-body',
    'fuel-logs-table-body',
    'service-table-body',
    'service-logs-table-body',
    'logistics-kpi-vehicles',
    'logistics-kpi-fuel-cost',
    'logistics-kpi-fuel',
    'logistics-kpi-service-cost',
    'logistics-kpi-service',
    'logistics-kpi-active'
];

foreach ($checks as $c) {
    $pos = strpos($content, $c);
    if ($pos !== false) {
        echo "FOUND: '$c'\n";
    } else {
        echo "NOT FOUND: '$c'\n";
    }
}
