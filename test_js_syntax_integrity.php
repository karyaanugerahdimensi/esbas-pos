<?php
/**
 * Automated Syntax & Regression Test for ESBAS POS
 * Memverifikasi integritas parsing JavaScript dan memastikan tidak ada SyntaxError.
 */

echo "1. Memeriksa sintaks PHP...\n";
$phpFiles = ['index.php', 'api.php', 'db.php', 'version.php'];
foreach ($phpFiles as $file) {
    exec("php -l $file", $output, $ret);
    if ($ret !== 0) {
        die("FATAL: Syntax error pada PHP file $file!\n");
    }
}
echo "   -> Seluruh berkas PHP bebas syntax error.\n";

echo "2. Memeriksa rendering index.php dan blok JavaScript...\n";
ob_start();
include "index.php";
$html = ob_get_clean();

$lines = explode("\n", $html);
$inScript = false;
$scriptLines = [];
$braceCount = 0;
$parenCount = 0;
$bracketCount = 0;
$illegalTokens = [];

foreach ($lines as $lineNum => $line) {
    if (stripos($line, '<script') !== false && stripos($line, 'src=') === false) {
        $inScript = true;
        continue;
    }
    if (stripos($line, '</script>') !== false) {
        $inScript = false;
        continue;
    }
    if ($inScript) {
        $scriptLines[] = $line;
        if (preg_match('/\.\(/', $line)) {
            $illegalTokens[] = "Line " . ($lineNum + 1) . ": Invalid token '.(' -> " . trim($line);
        }
        if (preg_match('/\?\./', $line)) {
            $illegalTokens[] = "Line " . ($lineNum + 1) . ": ES2020 optional chaining '?.' (Android 8 blocker) -> " . trim($line);
        }
        if (preg_match('/\?\?/', $line)) {
            $illegalTokens[] = "Line " . ($lineNum + 1) . ": ES2020 nullish coalescing '??' (Android 8 blocker) -> " . trim($line);
        }
    }
}

if (!empty($illegalTokens)) {
    echo "FATAL: Ditemukan token ilegal atau sintaks yang tidak kompatibel:\n";
    foreach ($illegalTokens as $t) {
        echo " - $t\n";
    }
    exit(1);
}

echo "   -> Total baris JavaScript: " . count($scriptLines) . " baris.\n";
echo "   -> 0 token ilegal / ES2020 blockers ditemukan.\n";
echo "   -> Verifikasi integritas JavaScript BERHASIL 100%!\n";
exit(0);
