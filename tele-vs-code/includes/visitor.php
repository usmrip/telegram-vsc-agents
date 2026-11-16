<?php
// includes/visitor.php - Visitor tracking, telemetry & geolocation
if (!defined('TELE_VS_CODE')) exit;

function getVisitorIp() {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

// Helper: Deteksi Device, OS, & Browser dari User-Agent
function parseUserAgentDetails($ua) {
    $device = 'Desktop';
    if (preg_match('/(mobile|android|iphone|ipad|phone)/i', $ua)) {
        $device = preg_match('/(ipad|tablet)/i', $ua) ? 'Tablet' : 'Mobile';
    }

    $os = 'Unknown OS';
    if (preg_match('/windows nt 10/i', $ua)) $os = 'Windows 10/11';
    elseif (preg_match('/windows nt/i', $ua)) $os = 'Windows';
    elseif (preg_match('/android/i', $ua)) $os = 'Android';
    elseif (preg_match('/iphone/i', $ua)) $os = 'iOS';
    elseif (preg_match('/macintosh|mac os x/i', $ua)) $os = 'macOS';
    elseif (preg_match('/linux/i', $ua)) $os = 'Linux';

    $browser = 'Other';
    if (preg_match('/edg/i', $ua)) $browser = 'Edge';
    elseif (preg_match('/chrome/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome/i', $ua)) $browser = 'Safari';
    elseif (preg_match('/firefox/i', $ua)) $browser = 'Firefox';
    elseif (preg_match('/opr|opera/i', $ua)) $browser = 'Opera';

    return ['device' => $device, 'os' => $os, 'browser' => $browser];
}

// Helper: Ambil Data Lokasi via Cloudflare / ip-api.com / ipwho.is dengan Cache Database Cepat
function getGeoLocationFromIp($db, $ip) {
    // Abaikan loopback / private IP
    if ($ip === '127.0.0.1' || $ip === '::1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0 || strpos($ip, '172.16.') === 0 || strpos($ip, '172.31.') === 0) {
        return [
            'country' => 'Localhost',
            'country_code' => 'ID',
            'region' => 'Development',
            'city' => 'Local Machine',
            'zip' => '-',
            'lat' => 0,
            'lon' => 0,
            'timezone' => 'Asia/Jakarta',
            'isp' => 'Internal Loopback',
            'org' => 'Localhost Network'
        ];
    }

    // 1. Cek Header Geolocation bawaan Cloudflare (jika request melewati proxy Cloudflare)
    $cfCountry = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';
    if (!empty($cfCountry) && strlen($cfCountry) === 2 && $cfCountry !== 'XX' && $cfCountry !== 'T1') {
        $cfCity = $_SERVER['HTTP_CF_IPCITY'] ?? '';
        $cfRegion = $_SERVER['HTTP_CF_REGION'] ?? ($_SERVER['HTTP_CF_REGION_CODE'] ?? '');
        $cfPostal = $_SERVER['HTTP_CF_POSTAL_CODE'] ?? '';
        $cfLat = isset($_SERVER['HTTP_CF_LATITUDE']) ? (float)$_SERVER['HTTP_CF_LATITUDE'] : 0;
        $cfLon = isset($_SERVER['HTTP_CF_LONGITUDE']) ? (float)$_SERVER['HTTP_CF_LONGITUDE'] : 0;
        $cfTz = $_SERVER['HTTP_CF_TIMEZONE'] ?? 'Asia/Jakarta';
        if (!empty($cfCity) || !empty($cfRegion) || $cfLat != 0) {
            return [
                'country' => $cfCountry === 'ID' ? 'Indonesia' : $cfCountry,
                'country_code' => $cfCountry,
                'region' => $cfRegion,
                'city' => $cfCity,
                'zip' => $cfPostal,
                'lat' => $cfLat,
                'lon' => $cfLon,
                'timezone' => $cfTz,
                'isp' => 'Cloudflare Proxy Network',
                'org' => 'Cloudflare Visitor'
            ];
        }
    }

    // 2. Cek riwayat IP di database untuk menghemat kuota ip-api.com
    try {
        $stmt = $db->prepare("SELECT country, country_code, region, city, zip, lat, lon, timezone, isp, org FROM visitor_analytics WHERE ip = ? AND country != '' AND country != 'Unknown' AND country != 'Localhost' LIMIT 1");
        $stmt->execute([$ip]);
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cached) {
            return $cached;
        }
    } catch (Exception $e) {}

    // 3. Query ke ip-api.com (Free HTTP Endpoint)
    $url = "http://ip-api.com/json/" . rawurlencode($ip) . "?fields=status,country,countryCode,regionName,city,zip,lat,lon,timezone,isp,org";
    $resp = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'TeleVsCode-Analytics/1.0');
        $resp = @curl_exec($ch);
        curl_close($ch);
    }

    if (!$resp && function_exists('file_get_contents') && ini_get('allow_url_fopen')) {
        $opts = ['http' => ['method' => 'GET', 'timeout' => 3, 'header' => "User-Agent: TeleVsCode-Analytics/1.0\r\n"]];
        $resp = @file_get_contents($url, false, stream_context_create($opts));
    }

    if ($resp) {
        $data = json_decode($resp, true);
        if ($data && isset($data['status']) && $data['status'] === 'success') {
            return [
                'country' => $data['country'] ?? '',
                'country_code' => $data['countryCode'] ?? '',
                'region' => $data['regionName'] ?? '',
                'city' => $data['city'] ?? '',
                'zip' => $data['zip'] ?? '',
                'lat' => (float)($data['lat'] ?? 0),
                'lon' => (float)($data['lon'] ?? 0),
                'timezone' => $data['timezone'] ?? '',
                'isp' => $data['isp'] ?? '',
                'org' => $data['org'] ?? ''
            ];
        }
    }

    // 4. Secondary Fallback: ipwho.is (HTTPS, Free)
    $urlFallback = "https://ipwho.is/" . rawurlencode($ip);
    $respFb = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($urlFallback);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'TeleVsCode-Analytics/1.0');
        $respFb = @curl_exec($ch);
        curl_close($ch);
    }
    if ($respFb) {
        $fb = json_decode($respFb, true);
        if ($fb && !empty($fb['success'])) {
            return [
                'country' => $fb['country'] ?? '',
                'country_code' => $fb['country_code'] ?? '',
                'region' => $fb['region'] ?? '',
                'city' => $fb['city'] ?? '',
                'zip' => $fb['postal'] ?? '',
                'lat' => (float)($fb['latitude'] ?? 0),
                'lon' => (float)($fb['longitude'] ?? 0),
                'timezone' => $fb['timezone']['id'] ?? '',
                'isp' => $fb['connection']['isp'] ?? ($fb['connection']['org'] ?? ''),
                'org' => $fb['connection']['org'] ?? ''
            ];
        }
    }

    return [
        'country' => 'Unknown',
        'country_code' => '',
        'region' => '',
        'city' => '',
        'zip' => '',
        'lat' => 0,
        'lon' => 0,
        'timezone' => '',
        'isp' => 'Unknown ISP',
        'org' => ''
    ];
}

// Helper: Rekam Kunjungan Pengunjung ke Database
function recordVisitorHit($db, $currentUser = null) {
    global $isApiOrWebhook;
    if (!empty($isApiOrWebhook)) {
        return;
    }

    $action = $_GET['action'] ?? ($_POST['action'] ?? '');
    if (in_array($action, [
        'sync_poll', 'status_stream', 'stream', 'pull', 'api_pull', 'push', 'api_push',
        'check_code_unique', 'check_bot_active', 'check_bot_verification', 'download_vsix',
        'clear_analytics', 'upload', 'upload_media', 'api_upload_media'
    ])) {
        return;
    }

    $ip = getVisitorIp();
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $page = $_SERVER['REQUEST_URI'] ?? '/';

    // Throttle 5 menit per IP dan Halaman agar tidak flooding
    try {
        $chk = $db->prepare("SELECT id FROM visitor_analytics WHERE ip = ? AND page_url = ? AND visited_at >= datetime('now', '-5 minutes') LIMIT 1");
        $chk->execute([$ip, $page]);
        if ($chk->fetch()) {
            return; // Sudah tercatat dalam 5 menit terakhir
        }
    } catch (Exception $e) {}

    $geo = getGeoLocationFromIp($db, $ip);
    $uaDetails = parseUserAgentDetails($ua);

    $userId = $currentUser['id'] ?? 0;
    $userEmail = $currentUser['email'] ?? '';

    try {
        $ins = $db->prepare("INSERT INTO visitor_analytics (
            ip, country, country_code, region, city, zip, lat, lon, timezone, isp, org,
            user_agent, device_type, browser, os, page_url, user_id, user_email, visited_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))");

        $ins->execute([
            $ip,
            $geo['country'],
            $geo['country_code'],
            $geo['region'],
            $geo['city'],
            $geo['zip'],
            $geo['lat'],
            $geo['lon'],
            $geo['timezone'],
            $geo['isp'],
            $geo['org'],
            $ua,
            $uaDetails['device'],
            $uaDetails['browser'],
            $uaDetails['os'],
            $page,
            $userId,
            $userEmail
        ]);
    } catch (Exception $e) {}
}

// Helper: Verifikasi Cloudflare Turnstile Token
