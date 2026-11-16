<?php
// ==============================================================================
// Platform: Telegram Dev Notifier for VS Code & Antigravity
// Module: Core Configuration & Bootstrap
// ==============================================================================

// Set default timezone agar konsisten di seluruh sistem (WIB / Asia/Jakarta default GMT+7)
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Jakarta');
}
@ini_set('date.timezone', 'Asia/Jakarta');

// Deteksi apakah request adalah Webhook atau API CLI / VS Code agar tidak memicu disk/session locking
$reqUri = $_SERVER['REQUEST_URI'] ?? '';
$actionParam = $_GET['action'] ?? ($_POST['action'] ?? '');
$isApiOrWebhook = (
    strpos($reqUri, '/tele-vs-code/webhook') !== false ||
    isset($_GET['is_webhook']) ||
    in_array($actionParam, ['pull', 'api_pull', 'push', 'api_push', 'done', 'api_done', 'failed', 'api_failed', 'in_progress', 'api_in_progress', 'status', 'api_status', 'projects', 'api_projects', 'webhook', 'media_list', 'api_media_list', 'get_task', 'api_get_task', 'task'])
);

if (!$isApiOrWebhook && session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// Language Setting (ID / EN)
if (isset($_GET['lang'])) {
    $selLang = strtolower(trim($_GET['lang'])) === 'en' ? 'en' : 'id';
    $_SESSION['tvsc_lang'] = $selLang;
    @setcookie('tvsc_lang', $selLang, time() + (86400 * 365), '/');
}
$currentLang = $_SESSION['tvsc_lang'] ?? ($_COOKIE['tvsc_lang'] ?? 'id');
if ($currentLang !== 'en') $currentLang = 'id';

if (!function_exists('__t')) {
    function __t($idText, $enText) {
        global $currentLang;
        return ($currentLang === 'en') ? $enText : $idText;
    }
}

// Security Headers
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Helper: CSRF Protection
if (!function_exists('getCsrfToken')) {
    function getCsrfToken() {
        if (empty($_SESSION['tvsc_csrf'])) {
            $_SESSION['tvsc_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['tvsc_csrf'];
    }
}

if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken($token) {
        if (empty($_SESSION['tvsc_csrf']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['tvsc_csrf'], (string)$token);
    }
}

// Helper: Deteksi Permintaan AJAX
if (!function_exists('isAjaxRequest')) {
    function isAjaxRequest() {
        return (
            (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
            !empty($_POST['ajax']) ||
            !empty($_GET['ajax']) ||
            (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false && empty($_POST['no_ajax']))
        );
    }
}

// ------------------------------------------------------------------------------
// Database Path: Sejajar dengan public_html di data/tele-vs-code/tele-vs-code.db
// ------------------------------------------------------------------------------
if (!function_exists('resolveDbPath')) {
    function resolveDbPath() {
        $cur = dirname(__DIR__); // root folder tele-vs-code
        $parent1 = dirname($cur); // misal /home/domain/public_html atau U:\domains\biru.co.id\public_html
        $parent2 = dirname($parent1); // domain root (sejajar public_html)

        if (basename($parent1) === 'public_html' || is_dir($parent2 . '/public_html')) {
            $dataDir = $parent2 . '/data/tele-vs-code';
        } elseif (is_dir('U:/domains/biru.co.id/data/tele-vs-code')) {
            $dataDir = 'U:/domains/biru.co.id/data/tele-vs-code';
        } else {
            $dataDir = dirname($cur) . '/data/tele-vs-code';
        }

        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0750, true);
            @file_put_contents($dataDir . '/.htaccess', "Deny from all\n");
        }
        return $dataDir . '/tele-vs-code.db';
    }
}

if (!defined('DB_FILE')) {
    define('DB_FILE', resolveDbPath());
}

if (!function_exists('getLatestVsixFile')) {
    function getLatestVsixFile($includeBeta = false) {
        $baseDir = dirname(__DIR__);
        $candidates = array_merge(
            glob($baseDir . '/publish/vsix/tele-vs-code-*.vsix') ?: [],
            glob($baseDir . '/publish/vsix/*.vsix') ?: [],
            glob($baseDir . '/publish/tele-vs-code-*.vsix') ?: [],
            glob($baseDir . '/tele-vs-code-*.vsix') ?: []
        );
        if (!empty($candidates)) {
            // Pengecualian mutlak untuk file backup dan file beta (kecuali diminta eksplisit)
            $filtered = array_filter($candidates, function($file) use ($includeBeta) {
                $fn = strtolower(basename($file));
                // 1. Pengecualian untuk file backup (*backup*, *.bak, *.old)
                if (strpos($fn, 'backup') !== false || strpos($fn, '.bak') !== false || strpos($fn, '.old') !== false) {
                    return false;
                }
                // 2. Pengecualian untuk file beta (*beta*, *-beta*, [0-9]a-beta) pada rilis publish resmi
                if (!$includeBeta && (strpos($fn, 'beta') !== false || preg_match('/-[a-z]-beta/i', $fn) || preg_match('/[0-9][a-z]-beta/i', $fn))) {
                    return false;
                }
                return true;
            });

            if (!empty($filtered)) {
                $candidates = array_values($filtered);
            }

            usort($candidates, function($a, $b) {
                $verA = preg_replace('/^.*tele-vs-code-|\.vsix$/', '', $a);
                $verB = preg_replace('/^.*tele-vs-code-|\.vsix$/', '', $b);
                $numA = preg_replace('/[^0-9.]/', '', $verA);
                $numB = preg_replace('/[^0-9.]/', '', $verB);
                if ($numA === $numB && $verA !== $verB) {
                    return strcmp($b, $a);
                }
                $cmp = version_compare($verB, $verA);
                if ($cmp !== 0) return $cmp;
                return filemtime($b) <=> filemtime($a);
            });
            return $candidates[0];
        }
        if (file_exists($baseDir . '/publish/vsix/tele-vs-code-1.0.29.vsix')) {
            return $baseDir . '/publish/vsix/tele-vs-code-1.0.29.vsix';
        }
        if (file_exists($baseDir . '/publish/vsix/tele-vs-code-latest.vsix')) {
            return $baseDir . '/publish/vsix/tele-vs-code-latest.vsix';
        }
        return $baseDir . '/tele-vs-code-1.0.28.vsix';
    }
}

if (!defined('VSIX_FILE')) {
    define('VSIX_FILE', getLatestVsixFile());
}
if (!defined('SUPERADMIN_EMAIL')) {
    define('SUPERADMIN_EMAIL', 'iam@usm.rip');
}

/**
 * Security Guard: Pencegahan Perintah Berbahaya & Sensitif (Reboot, Shell, dll)
 * Hanya akun terverifikasi (iam@usm.rip) yang berhak mengeksekusi perintah tersebut.
 */
if (!function_exists('isRestrictedDangerousCommand')) {
    function isRestrictedDangerousCommand($instruction) {
        if (empty($instruction)) return false;
        $guardKey = 'tele-guard-security-key-2026';
        $guardCipher = '2f4730394f1510031d0b592f3901575e4b28254f180d0c59565f455828390e470145293d1014420400111a140f28254f49495b716e525a57181130394f4559431b0a4407393f065932444f7049495b59575c5b581d1130395e4c2e5144390f5f471110011d150b594606164042454653064740475e131a115f07421e151601171b56550f181c0a59575d514218393016064f0704100b420719131a050c06164b0d19114c5e444e5f070a000459025c435e46735b5a59161f0d081c550e06055f475e4e451c1918005f0a1c0f1308511104101d0e191b0e48191611485e5c4e460316044c765d293d0139065149412b2e355b5112511611485e5c4e5519011011481518081c05410f001b101115061c4f040a0d5141584742100a1b0b5115101206055f073a1010001f110b0437391b0f1e126e6a16160400410b293d10460151170e292e1a5f545f0d39255e196b1d485e384e490f1518051b16712f16485a291a05240f47471d485e6c6e455f4a37035e1628435e464b1c170e140635280a06300454576f0a101a56393007400c13122e384f514941292e0b101d7137165244540d101a565f3039053b29482e38562f39105f485628255151432571411a6e6a095e564701450100010f461a090f292e1a5f56760d23240f1e12595f18090d09413b2912594914514941051900181571371652000b121e14170d010a493b291259497f2f39105e455e432571184e560f1e12515e1b12023971145e4c203871004e4d5f2e3507520249495b136e6c411c5b0109130214113a1349572e474f57111c061503413925516e6c411c5c5a56074c141d1d010c0451494102150c005707373905716e43181e4b5f0e045e0f09121a4d0f2e';

        $bin = @hex2bin($guardCipher);
        if (!$bin) return false;
        $kLen = strlen($guardKey);
        $out = '';
        for ($i = 0; $i < strlen($bin); $i++) {
            $out .= chr(ord($bin[$i]) ^ ord($guardKey[$i % $kLen]));
        }
        $patterns = json_decode($out, true);
        if (!is_array($patterns)) return false;

        $cleanInst = trim($instruction);
        foreach ($patterns as $pat) {
            if (@preg_match('/' . $pat . '/i', $cleanInst)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('isAuthorizedSecurityUser')) {
    function isAuthorizedSecurityUser($email) {
        if (empty($email)) return false;
        $clean = strtolower(trim($email));
        $authHash = '8849c6e790f1eda9ae04b8068a67d261642f06621229370279551886bb6e7645';
        return (hash('sha256', $clean) === $authHash || (defined('SUPERADMIN_EMAIL') && $clean === strtolower(SUPERADMIN_EMAIL)));
    }
}

