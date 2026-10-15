<?php
$content = file_get_contents('index.php');
$checks = [
    'updateDeliverySimulator',
    'testDeliveryFeeCalculator',
    'sim-distance-range',
    'sim-fee-result',
    'sim-formula-desc'
];

foreach ($checks as $c) {
    $pos = strpos($content, $c);
    if ($pos !== false) {
        echo "FOUND: '$c'\n";
    } else {
        echo "NOT FOUND: '$c'\n";
    }
}
