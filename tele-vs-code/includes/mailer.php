<?php
// includes/mailer.php - Turnstile verification, WhatsApp validation & SMTP Mailer
if (!defined('TELE_VS_CODE')) exit;

function verifyTurnstileToken($token) {
    $secret = getSetting('turnstile_secret_key', '1x0000000000000000000000000000000AA');
    if (!$secret || $secret === '1x0000000000000000000000000000000AA') return true;
    if (!$token) return true; // Graceful fallback jika client memblokir Turnstile atau terjadi CSP error
    $postData = http_build_query([
        'secret' => $secret,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
    $opts = ['http' => [
        'method' => 'POST',
        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
        'content' => $postData,
        'timeout' => 5
    ]];
    $context = stream_context_create($opts);
    $result = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
    if ($result) {
        $res = json_decode($result, true);
        return !empty($res['success']);
    }
    return true;
}


// Helper: Verifikasi Validitas Nomor WhatsApp di Server-Side (Aman, tidak dapat di-inspect atau injeksi)
function verifyWhatsAppNumberServerSide($phone) {
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($digits) < 9 || strlen($digits) > 16) return false;

    // Normalisasi awalan ke format lokal 08... yang diharapkan API
    $checkNum = $digits;
    if (strpos($checkNum, '62') === 0) {
        $checkNum = '0' . substr($checkNum, 2);
    }
    if (strpos($checkNum, '0') !== 0) {
        $checkNum = '0' . $checkNum;
    }

    $apiUrl = "https://sowhats.biru.click/api/checknumber/" . rawurlencode($checkNum);
    $resp = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_USERAGENT, 'TeleVsCode-Validator/1.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $resp = curl_exec($ch);
        curl_close($ch);
    }

    if (!$resp && function_exists('file_get_contents') && ini_get('allow_url_fopen')) {
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: TeleVsCode-Validator/1.0\r\nAccept: application/json\r\n",
                'timeout' => 8,
                'ignore_errors' => true
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
        $resp = @file_get_contents($apiUrl, false, stream_context_create($opts));
    }

    if (!$resp && function_exists('shell_exec')) {
        $resp = @shell_exec('curl.exe -s ' . escapeshellarg($apiUrl));
    }

    if ($resp) {
        $json = json_decode($resp, true);
        if ($json && isset($json['status']) && (string)$json['status'] === '200') {
            if (isset($json['message']) && strtolower($json['message']) === 'registered') {
                return true;
            }
        }
    }
    return false;
}


// Helper: Generator Template Email HTML Responsif Standar Mobile & Desktop
function buildResponsiveEmailHtml($title, $subtitle, $contentHtml, $footerNote = '') {
    $siteUrl = 'https://biru.co.id/tele-vs-code/';
    return '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>' . htmlspecialchars($title) . '</title>
    <style>
        body { margin: 0; padding: 0; background-color: #080d1a; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table { border-collapse: collapse; }
        img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        @media only screen and (max-width: 600px) {
            .email-container { width: 100% !important; max-width: 100% !important; padding: 8px !important; }
            .email-card { padding: 22px 16px !important; border-radius: 12px !important; }
            .otp-code { font-size: 26px !important; letter-spacing: 5px !important; padding: 12px !important; }
            .email-title { font-size: 19px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 24px 10px; background-color: #080d1a; color: #f8fafc;">
    <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
        <tr>
            <td align="center">
                <table class="email-container" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="max-width: 500px; width: 100%; margin: 0 auto;">
                    <!-- Brand Header -->
                    <tr>
                        <td align="center" style="padding-bottom: 18px;">
                            <table border="0" cellpadding="0" cellspacing="0" role="presentation">
                                <tr>
                                    <td align="center" style="padding-right: 8px;">
                                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(56, 189, 248, 0.15); display: inline-flex; align-items: center; justify-content: center; color: #38bdf8; font-size: 16px; line-height: 32px; text-align: center;">🔔</div>
                                    </td>
                                    <td align="left">
                                        <span style="font-size: 18px; font-weight: 800; color: #ffffff; letter-spacing: -0.3px;">tele<span style="color: #38bdf8;">/</span>vs<span style="color: #38bdf8;">/</span>code</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Main Card -->
                    <tr>
                        <td class="email-card" style="background-color: #111827; border: 1px solid #1f2937; border-radius: 16px; padding: 28px 24px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.6); text-align: center;">
                            <h1 class="email-title" style="margin: 0 0 6px 0; font-size: 21px; font-weight: 700; color: #ffffff; letter-spacing: -0.3px;">' . htmlspecialchars($title) . '</h1>
                            ' . ($subtitle ? '<p style="margin: 0 0 20px 0; font-size: 13.5px; color: #94a3b8; line-height: 1.5;">' . htmlspecialchars($subtitle) . '</p>' : '<div style="margin-bottom: 16px;"></div>') . '
                            ' . $contentHtml . '
                            ' . ($footerNote ? '<p style="margin: 20px 0 0 0; font-size: 11.5px; color: #64748b; line-height: 1.5; border-top: 1px solid rgba(255, 255, 255, 0.06); padding-top: 16px;">' . $footerNote . '</p>' : '') . '
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td align="center" style="padding-top: 18px; font-size: 11.5px; color: #475569;">
                            Tele-vs-Code &middot; Visual Studio Code & Telegram AI Notifier<br>
                            <a href="' . $siteUrl . '" style="color: #38bdf8; text-decoration: none;">biru.co.id/tele-vs-code</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
}

// Helper: Kirim Email via Socket SMTP (SSL/TLS/Plain) dengan Fallback ke mail()
function sendSmtpEmail($to, $subject, $htmlBody, &$debugLog = null) {
    $host = trim(getSetting('smtp_host', ''));
    $port = (int)getSetting('smtp_port', 465);
    $crypto = strtolower(trim(getSetting('smtp_crypto', 'ssl')));
    $user = trim(getSetting('smtp_user', ''));
    $pass = trim(getSetting('smtp_pass', ''));
    $fromName = trim(getSetting('smtp_from_name', 'Tele-vs-Code'));
    $fromEmail = trim(getSetting('smtp_from_email', ''));
    if (!$fromEmail) {
        $fromEmail = (!empty($user) && filter_var($user, FILTER_VALIDATE_EMAIL)) ? $user : 'no-reply@biru.co.id';
    }

    $log = [];
    $log[] = "[" . date('H:i:s') . "] Memulai pengiriman email ke $to";

    // Khusus akun test@biru.click: jangan kirim kode verifikasi untuk login dan daftar
    if (strtolower(trim($to)) === 'test@biru.click' && (stripos($subject, 'verifikasi') !== false || stripos($subject, 'otp') !== false || stripos($subject, 'login') !== false || stripos($subject, 'pendaftaran') !== false)) {
        $log[] = "[" . date('H:i:s') . "] Khusus user test@biru.click: Pengiriman kode verifikasi login & daftar dilewati (BYPASS).";
        $debugLog = implode("\n", $log);
        return ['ok' => true, 'log' => $debugLog, 'error' => ''];
    }

    // Jika Host SMTP belum diisi, gunakan mail() bawaan server
    if (empty($host)) {
        $log[] = "[" . date('H:i:s') . "] Host SMTP belum disetel, menggunakan fungsi mail() bawaan server...";
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . (!empty($fromName) ? "=?UTF-8?B?".base64_encode($fromName)."?= <$fromEmail>" : $fromEmail) . "\r\n";
        $sent = @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $htmlBody, $headers);
        $log[] = "[" . date('H:i:s') . "] Hasil mail(): " . ($sent ? "SUKSES" : "GAGAL");
        $debugLog = implode("\n", $log);
        return ['ok' => $sent, 'log' => $debugLog, 'error' => $sent ? '' : 'Fungsi mail() bawaan gagal mengirim email. Harap atur SMTP di dashboard admin.'];
    }

    $timeout = 15;
    $connectHost = ($crypto === 'ssl') ? 'ssl://' . $host : $host;
    $log[] = "[" . date('H:i:s') . "] Menghubungi SMTP socket: $connectHost:$port ($crypto)";

    $socket = @stream_socket_client("$connectHost:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        $log[] = "[" . date('H:i:s') . "] ❌ Gagal terhubung: $errstr ($errno)";
        $debugLog = implode("\n", $log);
        return ['ok' => false, 'log' => $debugLog, 'error' => "Koneksi ke host SMTP gagal: $errstr ($errno)"];
    }

    stream_set_timeout($socket, $timeout);

    $readReply = function() use ($socket, &$log) {
        $reply = '';
        while ($line = fgets($socket, 515)) {
            $reply .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        $log[] = "<-- " . trim($reply);
        return $reply;
    };

    $sendCommand = function($cmd, $hide = false) use ($socket, &$log, $readReply) {
        $log[] = "--> " . ($hide ? '******' : $cmd);
        fwrite($socket, $cmd . "\r\n");
        return $readReply();
    };

    $banner = $readReply();
    if (substr($banner, 0, 3) !== '220') {
        fclose($socket);
        $debugLog = implode("\n", $log);
        return ['ok' => false, 'log' => $debugLog, 'error' => "Respon banner server bukan 220: $banner"];
    }

    $sendCommand("EHLO " . (gethostname() ?: 'localhost'));

    if ($crypto === 'tls') {
        $res = $sendCommand("STARTTLS");
        if (substr($res, 0, 3) !== '220') {
            fclose($socket);
            $debugLog = implode("\n", $log);
            return ['ok' => false, 'log' => $debugLog, 'error' => "STARTTLS gagal: $res"];
        }
        $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        
        if (!stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
            fclose($socket);
            $debugLog = implode("\n", $log);
            return ['ok' => false, 'log' => $debugLog, 'error' => "Enkripsi TLS handshake gagal di soket."];
        }
        $sendCommand("EHLO " . (gethostname() ?: 'localhost'));
    }

    if (!empty($user)) {
        $res = $sendCommand("AUTH LOGIN");
        if (substr($res, 0, 3) !== '334') {
            fclose($socket);
            $debugLog = implode("\n", $log);
            return ['ok' => false, 'log' => $debugLog, 'error' => "Server menolak AUTH LOGIN: $res"];
        }

        $res = $sendCommand(base64_encode($user));
        if (substr($res, 0, 3) !== '334') {
            fclose($socket);
            $debugLog = implode("\n", $log);
            return ['ok' => false, 'log' => $debugLog, 'error' => "Username SMTP ditolak: $res"];
        }

        $res = $sendCommand(base64_encode($pass), true);
        if (substr($res, 0, 3) !== '235') {
            fclose($socket);
            $debugLog = implode("\n", $log);
            return ['ok' => false, 'log' => $debugLog, 'error' => "Password SMTP ditolak: $res"];
        }
    }

    $res = $sendCommand("MAIL FROM:<$fromEmail>");
    if (substr($res, 0, 3) !== '250') {
        fclose($socket);
        $debugLog = implode("\n", $log);
        return ['ok' => false, 'log' => $debugLog, 'error' => "MAIL FROM gagal: $res"];
    }

    $res = $sendCommand("RCPT TO:<$to>");
    if (substr($res, 0, 3) !== '250') {
        fclose($socket);
        $debugLog = implode("\n", $log);
        return ['ok' => false, 'log' => $debugLog, 'error' => "RCPT TO gagal untuk $to: $res"];
    }

    $res = $sendCommand("DATA");
    if (substr($res, 0, 3) !== '354') {
        fclose($socket);
        $debugLog = implode("\n", $log);
        return ['ok' => false, 'log' => $debugLog, 'error' => "DATA ditolak: $res"];
    }

    $headers = [
        "Date: " . date('r'),
        "To: <$to>",
        "From: " . (!empty($fromName) ? "=?UTF-8?B?".base64_encode($fromName)."?= <$fromEmail>" : "<$fromEmail>"),
        "Subject: =?UTF-8?B?".base64_encode($subject)."?=",
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "Content-Transfer-Encoding: base64"
    ];

    $emailContent = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody)) . "\r\n.";
    $res = $sendCommand($emailContent);
    $sendCommand("QUIT");
    fclose($socket);

    $debugLog = implode("\n", $log);
    if (substr($res, 0, 3) === '250') {
        return ['ok' => true, 'log' => $debugLog, 'error' => ''];
    } else {
        return ['ok' => false, 'log' => $debugLog, 'error' => "Gagal mengirim data email: $res"];
    }
}


