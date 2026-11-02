<?php
/**
 * ====================================================================
 * KAD Tech - Enterprise Application Security Shield & WAF
 * ====================================================================
 * Pelindung Keamanan Siber Terpadu untuk Aplikasi POS & Multi-Cabang Publik:
 * 1. HTTP Security Headers (Anti-Clickjacking, Anti-MIME Sniff, HSTS, XSS Protection)
 * 2. Application-Level WAF (Deteksi & Blokir SQLi, XSS, RCE, LFI/Path Traversal)
 * 3. Anti-Brute Force Engine (Pembatasan Percobaan Login, Lockout Otomatis & Tarpitting)
 * 4. Anti-Takedown / Anti-Flood Rate Limiting (Mencegah DoS / HTTP Flood Per IP)
 * 5. Malicious Bot & Scanner Blocker (Mendeteksi sqlmap, nikto, dirbuster, dll)
 * 6. IP Blacklisting & Temporary Ban Engine
 * 7. Logging & Audit Insiden Keamanan Realtime
 * ====================================================================
 */

if (!defined('SECURITY_SHIELD_LOADED')) {
    define('SECURITY_SHIELD_LOADED', true);
}

// Deteksi IP Klien yang Valid dan Aman (Mendukung Proxy/Cloudflare)
if (!function_exists('getSecurityClientIp')) {
    function getSecurityClientIp() {
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP',        // Nginx reverse proxy
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $rawIp = trim($_SERVER[$header]);
                // Jika terdapat banyak IP terpisah koma, ambil yang pertama
                if (strpos($rawIp, ',') !== false) {
                    $parts = explode(',', $rawIp);
                    $rawIp = trim($parts[0]);
                }
                if (filter_var($rawIp, FILTER_VALIDATE_IP)) {
                    return $rawIp;
                }
            }
        }

        // Fallback X-Forwarded-For
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            foreach ($ips as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}

// Pengiriman HTTP Security Response Headers
if (!function_exists('applySecurityHeaders')) {
    function applySecurityHeaders() {
        if (headers_sent()) return;

        // Cegah Clickjacking (iframe embedding)
        header('X-Frame-Options: SAMEORIGIN');

        // Cegah MIME type sniffing exploit
        header('X-Content-Type-Options: nosniff');

        // Aktifkan browser XSS filter
        header('X-XSS-Protection: 1; mode=block');

        // Atur kebijakan referrer agar token URL tidak bocor ke pihak luar
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Batasi akses sensor perangkat yang tidak relevan
        header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');

        // HSTS jika koneksi berjalan di atas protokol HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        // Hilangkan header fingerprint versi PHP
        if (function_exists('header_remove')) {
            @header_remove('X-Powered-By');
        }
    }
}

// Inisialisasi Berkas Penyimpanan Cache Keamanan Cepat
if (!function_exists('getSecurityStorageDir')) {
    function getSecurityStorageDir() {
        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'sessions' . DIRECTORY_SEPARATOR . 'security';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
}

// Pencatatan Log Insiden Keamanan
if (!function_exists('logSecurityIncident')) {
    function logSecurityIncident($eventType, $details = '', $ip = null) {
        $ip = $ip ?: getSecurityClientIp();
        $timestamp = date('Y-m-d H:i:s');
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);
        $requestUri = substr($_SERVER['REQUEST_URI'] ?? '', 0, 255);

        // 1. Tulis ke file log harian lokal
        try {
            $logDir = getSecurityStorageDir();
            $logFile = $logDir . DIRECTORY_SEPARATOR . 'security_' . date('Y-m') . '.log';
            $logEntry = json_encode([
                'timestamp' => $timestamp,
                'ip' => $ip,
                'type' => $eventType,
                'details' => $details,
                'uri' => $requestUri,
                'user_agent' => $userAgent
            ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
            @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {}

        // 2. Tulis ke basis data jika koneksi $pdo aktif
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO security_audit_logs (ip_address, event_type, details, request_uri, user_agent, created_at)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$ip, $eventType, substr((string)$details, 0, 1000), $requestUri, $userAgent, $timestamp]);
            } catch (Throwable $e) {}
        }
    }
}

// Pengecekan Status Pemblokiran IP (IP Ban Check)
if (!function_exists('isSecurityIpBanned')) {
    function isSecurityIpBanned($ip = null) {
        $ip = $ip ?: getSecurityClientIp();

        // White-list IP lokal development agar tidak terkunci sendiri
        if (in_array($ip, ['127.0.0.1', '::1', 'localhost'])) {
            return false;
        }

        $now = time();

        // 1. Cek cache berkas cepat (O(1))
        $banFile = getSecurityStorageDir() . DIRECTORY_SEPARATOR . 'ban_' . md5($ip) . '.json';
        if (file_exists($banFile)) {
            $data = @json_decode(file_get_contents($banFile), true);
            if (!empty($data['banned_until']) && $data['banned_until'] > $now) {
                return $data;
            } elseif (!empty($data['banned_until']) && $data['banned_until'] <= $now) {
                @unlink($banFile);
            }
        }

        // 2. Cek basis data jika $pdo tersedia
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("
                    SELECT * FROM security_ip_bans 
                    WHERE ip_address = ? AND is_active = 1 AND banned_until > ? 
                    LIMIT 1
                ");
                $stmt->execute([$ip, date('Y-m-d H:i:s')]);
                $banRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($banRow) {
                    // Simpan ke cache file cepat agar request berikutnya super kilat
                    @file_put_contents($banFile, json_encode([
                        'ip' => $ip,
                        'reason' => $banRow['reason'],
                        'banned_until' => strtotime($banRow['banned_until'])
                    ]));
                    return $banRow;
                }
            } catch (Throwable $e) {}
        }

        return false;
    }
}

// Pemblokiran IP Sementara (Auto-Ban)
if (!function_exists('banSecurityIp')) {
    function banSecurityIp($ip, $durationSeconds = 3600, $reason = 'Aktivitas mencurigakan / percobaan serangan') {
        if (in_array($ip, ['127.0.0.1', '::1', 'localhost'])) return;

        $now = time();
        $bannedUntil = $now + $durationSeconds;
        $bannedUntilStr = date('Y-m-d H:i:s', $bannedUntil);

        // 1. Tulis cache berkas cepat
        $banFile = getSecurityStorageDir() . DIRECTORY_SEPARATOR . 'ban_' . md5($ip) . '.json';
        @file_put_contents($banFile, json_encode([
            'ip' => $ip,
            'reason' => $reason,
            'banned_at' => $now,
            'banned_until' => $bannedUntil
        ]));

        // 2. Tulis ke basis data
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO security_ip_bans (ip_address, reason, banned_at, banned_until, is_active)
                    VALUES (?, ?, ?, ?, 1)
                ");
                $stmt->execute([$ip, $reason, date('Y-m-d H:i:s', $now), $bannedUntilStr]);
            } catch (Throwable $e) {}
        }

        logSecurityIncident('IP_BANNED', "Durasi: {$durationSeconds}s | Alasan: {$reason}", $ip);
    }
}

// Pembatalan Pemblokiran IP (Unban) oleh Administrator
if (!function_exists('unbanSecurityIp')) {
    function unbanSecurityIp($ip) {
        $banFile = getSecurityStorageDir() . DIRECTORY_SEPARATOR . 'ban_' . md5($ip) . '.json';
        if (file_exists($banFile)) {
            @unlink($banFile);
        }

        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("UPDATE security_ip_bans SET is_active = 0 WHERE ip_address = ?");
                $stmt->execute([$ip]);
            } catch (Throwable $e) {}
        }

        logSecurityIncident('IP_UNBANNED', "Pemblokiran IP dicabut oleh Administrator", $ip);
        return true;
    }
}

// Rate Limiter Universal (Anti-Takedown / Anti-Flood Protection)
if (!function_exists('enforceSecurityRateLimit')) {
    function enforceSecurityRateLimit($maxRequests = 120, $windowSeconds = 60) {
        $ip = getSecurityClientIp();
        if (in_array($ip, ['127.0.0.1', '::1', 'localhost'])) return;

        $dir = getSecurityStorageDir();
        $rateFile = $dir . DIRECTORY_SEPARATOR . 'rate_' . md5($ip) . '.json';
        $now = time();

        $data = ['count' => 0, 'reset_at' => $now + $windowSeconds];
        if (file_exists($rateFile)) {
            $content = @file_get_contents($rateFile);
            $parsed = @json_decode($content, true);
            if (!empty($parsed['reset_at']) && $parsed['reset_at'] > $now) {
                $data = $parsed;
            }
        }

        $data['count']++;
        @file_put_contents($rateFile, json_encode($data), LOCK_EX);

        if ($data['count'] > $maxRequests) {
            $retryAfter = max(1, $data['reset_at'] - $now);
            if (!headers_sent()) {
                http_response_code(429);
                header('Retry-After: ' . $retryAfter);
                header('Content-Type: application/json; charset=utf-8');
            }

            // Jika melebihi 2x lipat batas rate limit, kenakan auto-ban sementara
            if ($data['count'] > ($maxRequests * 2)) {
                banSecurityIp($ip, 900, 'HTTP Flood / Potensi serangan DoS Takedown');
            }

            logSecurityIncident('RATE_LIMIT_EXCEEDED', "Request count: {$data['count']}/{$maxRequests}", $ip);
            echo json_encode([
                'success' => false,
                'security_blocked' => true,
                'message' => "Terlalu banyak permintaan (Rate limit exceeded). Tunggu {$retryAfter} detik sebelum mencoba lagi."
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}

// Web Application Firewall (WAF) - Pemeriksaan Pola Serangan
if (!function_exists('inspectSecurityPayloads')) {
    function inspectSecurityPayloads() {
        $ip = getSecurityClientIp();
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // 1. Blokir Scanner Tools & Malicious Bots
        $badBots = [
            'sqlmap', 'nikto', 'dirbuster', 'gobuster', 'wpscan', 'masscan', 
            'acunetix', 'havij', 'nmap', 'censys', 'shodan', 'zgrab', 'hydra'
        ];
        foreach ($badBots as $bot) {
            if (stripos($userAgent, $bot) !== false) {
                banSecurityIp($ip, 7200, "Malicious Scanner Tool terdeteksi: {$bot}");
                logSecurityIncident('SCANNER_BLOCKED', "User-Agent: {$userAgent}", $ip);
                if (!headers_sent()) {
                    http_response_code(403);
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Scanner otomatis tidak diizinkan.'], JSON_UNESCAPED_SLASHES);
                exit;
            }
        }

        // 2. Kumpulkan seluruh input parameter untuk dipindai
        $inputs = [];
        $inputs['URI'] = rawurldecode($uri);
        foreach ($_GET as $k => $v) {
            if (is_string($v)) $inputs["GET_{$k}"] = $v;
        }
        foreach ($_COOKIE as $k => $v) {
            if (is_string($v)) $inputs["COOKIE_{$k}"] = $v;
        }

        // Pindai POST (lewati token CSRF / password hash panjang / base64 image data)
        foreach ($_POST as $k => $v) {
            if (is_string($v)) {
                // Lewati data gambar base64 atau JSON layout resmi yang valid
                if (strpos($v, 'data:image/') === 0 || strlen($v) > 50000) continue;
                $inputs["POST_{$k}"] = $v;
            }
        }

        // Daftar Pola Serangan Berbahaya (RegEx Signatures)
        $maliciousPatterns = [
            'SQL_INJECTION' => [
                'pattern' => '/\b(union\s+select|information_schema|benchmark\s*\(|sleep\s*\(|waitfor\s+delay|load_file\s*\(|into\s+(?:out|dump)file|concat\s*\(\s*0x[0-9a-f]+)\b/i',
                'severity' => 'HIGH'
            ],
            'REMOTE_CODE_EXECUTION' => [
                'pattern' => '/(?:\b(?:passthru|shell_exec|proc_open|popen|system)\s*\(|`[a-z0-9_\-\.\s]{3,}`)/i',
                'severity' => 'CRITICAL'
            ],
            'PATH_TRAVERSAL' => [
                'pattern' => '/(?:\.\.\/|\.\.\\|\/etc\/(?:passwd|shadow)|boot\.ini|win\.ini|php:\/\/(?:filter|input)|phar:\/\/)/i',
                'severity' => 'HIGH'
            ],
            'XSS_SCRIPT_TAG' => [
                'pattern' => '/<\s*script\b[^>]*>|<\/\s*script\s*>|javascript\s*:\s*[a-z0-9_\(\)]/i',
                'severity' => 'MEDIUM'
            ]
        ];

        foreach ($inputs as $source => $value) {
            if (empty($value) || !is_string($value)) continue;

            foreach ($maliciousPatterns as $attackType => $rule) {
                if (preg_match($rule['pattern'], $value)) {
                    $detail = "Sumber: {$source} | Pola: {$attackType} | Cuplikan: " . substr($value, 0, 150);
                    logSecurityIncident($attackType, $detail, $ip);

                    // Tambahkan pelanggaran WAF ke IP
                    $wafCounterFile = getSecurityStorageDir() . DIRECTORY_SEPARATOR . 'waf_viol_' . md5($ip) . '.json';
                    $violations = 1;
                    if (file_exists($wafCounterFile)) {
                        $vData = @json_decode(file_get_contents($wafCounterFile), true);
                        if (!empty($vData['reset']) && $vData['reset'] > time()) {
                            $violations = (int)($vData['count'] ?? 0) + 1;
                        }
                    }
                    @file_put_contents($wafCounterFile, json_encode(['count' => $violations, 'reset' => time() + 3600]));

                    // Jika percobaan serangan kritis atau berulang >= 2 kali, langsung ban IP
                    if ($rule['severity'] === 'CRITICAL' || $violations >= 2) {
                        banSecurityIp($ip, 7200, "Serangan siber {$attackType} terdeteksi oleh WAF");
                    }

                    if (!headers_sent()) {
                        http_response_code(403);
                        header('Content-Type: application/json; charset=utf-8');
                    }
                    echo json_encode([
                        'success' => false,
                        'security_blocked' => true,
                        'message' => 'Permintaan diblokir oleh sistem keamanan aplikasi (WAF Triggered).'
                    ], JSON_UNESCAPED_SLASHES);
                    exit;
                }
            }
        }
    }
}

// Pengecekan Anti-Brute Force Login
if (!function_exists('checkSecurityLoginBruteForce')) {
    function checkSecurityLoginBruteForce($username) {
        $ip = getSecurityClientIp();
        $now = time();
        $dir = getSecurityStorageDir();
        $cleanUser = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower($username));
        $attemptFile = $dir . DIRECTORY_SEPARATOR . 'login_att_' . md5($ip . '_' . $cleanUser) . '.json';

        if (file_exists($attemptFile)) {
            $data = @json_decode(file_get_contents($attemptFile), true);
            $lockoutUntil = (int)($data['lockout_until'] ?? 0);
            if ($lockoutUntil > $now) {
                $remMinutes = max(1, ceil(($lockoutUntil - $now) / 60));
                logSecurityIncident('BRUTE_FORCE_BLOCKED', "User: {$username} | Sisa lock: {$remMinutes}m", $ip);
                return [
                    'allowed' => false,
                    'message' => "Akun / IP Anda ditangguhkan sementara selama {$remMinutes} menit karena terlalu banyak percobaan login yang gagal. Silakan coba beberapa saat lagi demi keamanan."
                ];
            }

            // Tarpitting / Progressive Slowdown (setelah gagal >= 2 kali, tahan respon 0.5 - 1 detik)
            $failedCount = (int)($data['failed_count'] ?? 0);
            if ($failedCount >= 2 && $failedCount < 5) {
                usleep(500000); // 0.5 detik
            }
        }

        return ['allowed' => true];
    }
}

// Pencatatan Percobaan Login (Anti-Brute Force Counter)
if (!function_exists('recordSecurityLoginAttempt')) {
    function recordSecurityLoginAttempt($username, $isSuccess) {
        $ip = getSecurityClientIp();
        $now = time();
        $dir = getSecurityStorageDir();
        $cleanUser = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower($username));
        $attemptFile = $dir . DIRECTORY_SEPARATOR . 'login_att_' . md5($ip . '_' . $cleanUser) . '.json';

        if ($isSuccess) {
            // Jika berhasil, bersihkan riwayat kegagalan
            if (file_exists($attemptFile)) {
                @unlink($attemptFile);
            }
            // Regenerasi ID Session untuk mencegah Session Fixation
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_regenerate_id(true);
            }
            logSecurityIncident('LOGIN_SUCCESS', "User: {$username}", $ip);
        } else {
            // Jika gagal, tambahkan hitungan
            $failedCount = 1;
            if (file_exists($attemptFile)) {
                $data = @json_decode(file_get_contents($attemptFile), true);
                if (!empty($data['window_reset']) && $data['window_reset'] > $now) {
                    $failedCount = (int)($data['failed_count'] ?? 0) + 1;
                }
            }

            $lockoutDuration = 0;
            $lockoutUntil = 0;
            // Batas Toleransi: 5x Gagal -> Lockout 10 Menit (600 detik)
            if ($failedCount >= 5) {
                $lockoutDuration = 600; // 10 menit
                $lockoutUntil = $now + $lockoutDuration;
                logSecurityIncident('LOGIN_LOCKOUT', "User: {$username} | Gagal {$failedCount}x -> Lockout 10m", $ip);
            } else {
                logSecurityIncident('LOGIN_FAILED', "User: {$username} | Percobaan ke-{$failedCount}", $ip);
            }

            @file_put_contents($attemptFile, json_encode([
                'failed_count' => $failedCount,
                'window_reset' => $now + 600, // Window 10 menit
                'lockout_until' => $lockoutUntil
            ]), LOCK_EX);
        }
    }
}

// Inisialisasi Otomatis Tabel Keamanan di Basis Data
if (!function_exists('ensureSecurityTables')) {
    function ensureSecurityTables($pdo) {
        if (!$pdo || !($pdo instanceof PDO)) return;

        try {
            // 1. Tabel IP Bans
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `security_ip_bans` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `ip_address` VARCHAR(64) NOT NULL,
                    `reason` VARCHAR(255) DEFAULT NULL,
                    `banned_at` DATETIME NOT NULL,
                    `banned_until` DATETIME NOT NULL,
                    `is_active` TINYINT(1) DEFAULT 1,
                    INDEX `idx_sec_ip` (`ip_address`),
                    INDEX `idx_sec_active` (`is_active`, `banned_until`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        } catch (Throwable $e) {
            // Fallback SQLite
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS security_ip_bans (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        ip_address TEXT NOT NULL,
                        reason TEXT,
                        banned_at DATETIME NOT NULL,
                        banned_until DATETIME NOT NULL,
                        is_active INTEGER DEFAULT 1
                    );
                    CREATE INDEX IF NOT EXISTS idx_sec_ip ON security_ip_bans (ip_address);
                ");
            } catch (Throwable $e2) {}
        }

        try {
            // 2. Tabel Audit Logs
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `security_audit_logs` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `ip_address` VARCHAR(64) NOT NULL,
                    `event_type` VARCHAR(64) NOT NULL,
                    `details` TEXT DEFAULT NULL,
                    `request_uri` VARCHAR(255) DEFAULT NULL,
                    `user_agent` VARCHAR(255) DEFAULT NULL,
                    `created_at` DATETIME NOT NULL,
                    INDEX `idx_sec_event` (`event_type`),
                    INDEX `idx_sec_time` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        } catch (Throwable $e) {
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS security_audit_logs (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        ip_address TEXT NOT NULL,
                        event_type TEXT NOT NULL,
                        details TEXT,
                        request_uri TEXT,
                        user_agent TEXT,
                        created_at DATETIME NOT NULL
                    );
                ");
            } catch (Throwable $e2) {}
        }
    }
}

// Inisialisasi Perisai Keamanan Utama (Main Execution Entrypoint)
if (!function_exists('initSecurityShield')) {
    function initSecurityShield() {
        // 1. Terapkan HTTP Security Headers
        applySecurityHeaders();

        // 2. Proteksi Batas Ukuran Payload (Mencegah Memory Exhaustion DoS)
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 15 * 1024 * 1024) { // 15MB
            if (!headers_sent()) http_response_code(413);
            echo json_encode(['success' => false, 'message' => 'Ukuran payload melebihi batas maksimal (15MB).'], JSON_UNESCAPED_SLASHES);
            exit;
        }

        // 3. Cek Status Pemblokiran IP (Banned IP Guard)
        $banInfo = isSecurityIpBanned();
        if ($banInfo) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
            }
            $remTime = !empty($banInfo['banned_until']) ? max(1, ceil(($banInfo['banned_until'] - time()) / 60)) : 60;
            echo json_encode([
                'success' => false,
                'security_blocked' => true,
                'message' => "Akses ditolak: Alamat IP Anda sedang diblokir sementara ({$remTime} menit tersisa) karena aktivitas yang mencurigakan. Hubungi Administrator jika ini adalah kesalahan."
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        // 4. Batasi Laju Permintaan (Universal Rate Limiter: Maksimal 120 req / menit per IP)
        enforceSecurityRateLimit(120, 60);

        // 5. Pindai Muatan & URL (WAF Inspector)
        inspectSecurityPayloads();
    }
}
