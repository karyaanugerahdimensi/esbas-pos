<?php
$lines = file('index.php');
foreach ($lines as $num => $line) {
    if (strpos($line, 'updateDeliverySimulator') !== false) {
        echo "Line " . ($num + 1) . ": " . trim($line) . "\n";
    }
}
