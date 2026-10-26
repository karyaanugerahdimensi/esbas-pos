<?php
/**
 * ESBAS POS Version Helper
 * Menyediakan informasi base version, commit hash, dan riwayat rollback untuk kemudahan audit & troubleshooting.
 */

if (!function_exists('getAppVersionData')) {
    function getAppVersionData() {
        $versionFile = __DIR__ . '/version.json';
        $data = [
            'app_name' => 'ESBAS POS Multicabang',
            'version' => '1.2.89',
            'build' => '20261026-44',
            'release_date' => '2026-10-26',
            'commit_hash' => '804cccb',
            'branch' => 'main',
            'history' => []
        ];

        if (file_exists($versionFile)) {
            $raw = file_get_contents($versionFile);
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $data = array_merge($data, $parsed);
            }
        }

        // Deteksi dinamis commit hash dan git tags dari direktori .git jika tersedia
        try {
            if (is_dir(__DIR__ . '/.git')) {
                // 1. Deteksi commit hash
                $headFile = __DIR__ . '/.git/HEAD';
                if (file_exists($headFile)) {
                    $head = trim(file_get_contents($headFile));
                    if (strpos($head, 'ref: ') === 0) {
                        $refPath = trim(substr($head, 5));
                        $refFile = __DIR__ . '/.git/' . $refPath;
                        if (file_exists($refFile)) {
                            $commit = trim(file_get_contents($refFile));
                            $data['commit_hash'] = substr($commit, 0, 7);
                        }
                    } elseif (strlen($head) >= 7) {
                        $data['commit_hash'] = substr($head, 0, 7);
                    }
                }

                // 2. Deteksi tag git terbaru secara dinamis
                $gitTags = [];
                $tagsDir = __DIR__ . '/.git/refs/tags';
                if (is_dir($tagsDir)) {
                    $tagEntries = scandir($tagsDir);
                    if (is_array($tagEntries)) {
                        foreach ($tagEntries as $entry) {
                            if ($entry !== '.' && $entry !== '..' && preg_match('/^v?(\d+\.\d+\.\d+(?:-[\w.]+)?)$/', $entry, $m)) {
                                $gitTags[] = $m[1];
                            }
                        }
                    }
                }
                $packedRefs = __DIR__ . '/.git/packed-refs';
                if (file_exists($packedRefs)) {
                    $lines = @file($packedRefs, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    if (is_array($lines)) {
                        foreach ($lines as $l) {
                            if (preg_match('#refs/tags/v?(\d+\.\d+\.\d+(?:-[\w.]+)?)#', $l, $m)) {
                                $gitTags[] = $m[1];
                            }
                        }
                    }
                }
                if (!empty($gitTags)) {
                    usort($gitTags, 'version_compare');
                    $latestTag = end($gitTags);
                    if ($latestTag && version_compare($latestTag, $data['version'], '>=')) {
                        $data['version'] = $latestTag;
                    }
                }
            }
        } catch (Exception $e) {}

        return $data;
    }
}
