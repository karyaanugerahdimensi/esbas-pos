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
            'version' => '1.1.0',
            'build' => '20261015-02',
            'release_date' => '2026-10-15',
            'commit_hash' => 'adebd44',
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

        // Deteksi dinamis commit hash saat ini dari direktori .git jika tersedia
        try {
            if (is_dir(__DIR__ . '/.git')) {
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
            }
        } catch (Exception $e) {}

        return $data;
    }
}
