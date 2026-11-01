<?php
/**
 * Automated Syntax & Regression Test for KAD Tech
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

// 3. Verifikasi Keseimbangan Token Bracket ({}, (), [])
preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $html, $matches, PREG_OFFSET_CAPTURE);
foreach ($matches[1] as $idx => $m) {
    $js = $m[0];
    $offset = $m[1];
    $startLine = substr_count(substr($html, 0, $offset), "\n") + 1;
    $stack = [];
    $len = strlen($js);
    $inString = false;
    $stringChar = '';
    $inComment = false;
    $commentType = '';
    $curLine = $startLine;

    for ($i = 0; $i < $len; $i++) {
        $c = $js[$i];
        $next = ($i + 1 < $len) ? $js[$i + 1] : '';

        if ($c === "\n") {
            $curLine++;
            if ($commentType === '//') {
                $inComment = false;
                $commentType = '';
            }
            continue;
        }

        if (!$inString && !$inComment) {
            if ($c === '/' && $next === '/') {
                $inComment = true;
                $commentType = '//';
                $i++;
                continue;
            }
            if ($c === '/' && $next === '*') {
                $inComment = true;
                $commentType = '/*';
                $i++;
                continue;
            }
        } elseif ($inComment) {
            if ($commentType === '/*' && $c === '*' && $next === '/') {
                $inComment = false;
                $commentType = '';
                $i++;
            }
            continue;
        }

        if (!$inComment) {
            if (!$inString) {
                if ($c === '"' || $c === "'" || $c === '`') {
                    $inString = true;
                    $stringChar = $c;
                    continue;
                }
            } else {
                if ($c === '\\') {
                    $i++;
                    continue;
                }
                if ($c === $stringChar) {
                    $inString = false;
                    $stringChar = '';
                    continue;
                }
                continue;
            }
        }

        if ($c === '{' || $c === '(' || $c === '[') {
            $stack[] = ['char' => $c, 'line' => $curLine];
        } elseif ($c === '}' || $c === ')' || $c === ']') {
            if (empty($stack)) {
                $illegalTokens[] = "Rendered Line $curLine: Unexpected closing token '$c' without matching open bracket!";
                break;
            }
            $last = array_pop($stack);
            $expected = ($last['char'] === '{' ? '}' : ($last['char'] === '(' ? ')' : ']'));
            if ($c !== $expected) {
                $illegalTokens[] = "Rendered Line $curLine: Mismatched token '$c', expected '$expected' (opened at line {$last['line']})!";
                break;
            }
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
