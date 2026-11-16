<?php
/**
 * Tele-vs-Code Dashboard & Multi-Tenant Routing Engine
 * Modular Edition - Loads only required modules on-demand
 */

define('TELE_VS_CODE', true);

// ------------------------------------------------------------------------------
// 1. Core Modules (Konfigurasi, Database SQLite, Helper Fungsi)
// ------------------------------------------------------------------------------
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? 'index');
$reqUri = $_SERVER['REQUEST_URI'] ?? '';

// ------------------------------------------------------------------------------
// 2. Rute: Unduh Paket Ekstensi Resmi (.VSIX)
// ------------------------------------------------------------------------------
if ($action === 'download_vsix') {
    $vsixTarget = getLatestVsixFile();
    if ($vsixTarget && file_exists($vsixTarget)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($vsixTarget) . '"');
        header('Content-Length: ' . filesize($vsixTarget));
        readfile($vsixTarget);
        exit;
    } else {
        die('File VSIX rilis resmi tidak ditemukan di server.');
    }
}

// ------------------------------------------------------------------------------
// 3. Rute: Unduh Lampiran & REST API untuk Ekstensi VS Code, Shell Runner & CLI
// ------------------------------------------------------------------------------
$apiActions = [
    'attachment', 'pull', 'push', 'done', 'failed', 'start', 'in_progress',
    'status', 'projects', 'upload_media', 'media_list', 'get_task'
];
$isApi = ($action === 'attachment' || strpos($action, 'api_') === 0 || in_array($action, $apiActions));
if ($isApi) {
    require_once __DIR__ . '/includes/api.php';
    exit;
}

// ------------------------------------------------------------------------------
// 4. Rute: Multi-Tenant Telegram Bot Webhook
// Ringan & berkinerja tinggi, hanya memuat webhook handler tanpa memuat UI dashboard
// ------------------------------------------------------------------------------
$isWebhook = (
    isset($_GET['is_webhook']) ||
    $action === 'webhook' ||
    strpos($reqUri, '/tele-vs-code/webhook') !== false ||
    strpos($reqUri, '/webhook') !== false
);
if ($isWebhook) {
    $action = 'webhook';
    require_once __DIR__ . '/includes/webhook.php';
    exit;
}

// ------------------------------------------------------------------------------
// 5. Dashboard Web, Telemetri Visitor & Otentikasi
// ------------------------------------------------------------------------------
require_once __DIR__ . '/includes/visitor.php';
require_once __DIR__ . '/includes/mailer.php';

// Rekam jejak analitik pengunjung web
recordVisitorHit($db);

// Resolusi Sesi User yang Sedang Login
$currentUser = null;
if (!empty($_SESSION['tvsc_uid'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['tvsc_uid']]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($currentUser && !empty($currentUser['is_blocked'])) {
        session_unset();
        session_destroy();
        $currentUser = null;
    }
}

// Auto-generate API Key jika user belum memilikinya
if ($currentUser && empty($currentUser['api_key'])) {
    $currentUser['api_key'] = 'tvsc_' . bin2hex(random_bytes(16));
    $db->prepare("UPDATE users SET api_key = ? WHERE id = ?")->execute([$currentUser['api_key'], $currentUser['id']]);
}

$isSuperAdmin = $currentUser ? (strtolower($currentUser['email']) === strtolower(SUPERADMIN_EMAIL)) : false;

// ------------------------------------------------------------------------------
// 6. Rute: Handler Otentikasi (Login, Register, OTP, Reset Password)
// ------------------------------------------------------------------------------
$authActions = ['login', 'register', 'verify_otp', 'resend_otp', 'cancel_otp', 'logout', 'forgot_password', 'do_reset_password', 'reset_pass_form'];
if (in_array($action, $authActions)) {
    require_once __DIR__ . '/includes/auth.php';
}

// ------------------------------------------------------------------------------
// 7. Rute: Aksi Dashboard (Manajemen Proyek, Tugas, Pengaturan, Admin)
// ------------------------------------------------------------------------------
$ajaxActions = [
    'check_bot_active', 'check_bot_verification', 'check_code_unique',
    'connect_bot_commands', 'status_stream', 'sync_poll', 'check_task_updates',
    'render_task_cards'
];
if ((in_array($action, $ajaxActions) || isAjaxRequest()) && !$currentUser) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Sesi login telah berakhir. Silakan muat ulang halaman dan login kembali.',
        'description' => 'Sesi login telah berakhir. Silakan login kembali.'
    ]);
    exit;
}

if ($currentUser && ($action !== 'index' || $_SERVER['REQUEST_METHOD'] === 'POST')) {
    require_once __DIR__ . '/includes/actions.php';
}

// ------------------------------------------------------------------------------
// 8. Tampilan Antarmuka (Views)
// ------------------------------------------------------------------------------
$currentTab = $_GET['tab'] ?? 'tasks';
if ($currentTab === 'guide') $currentTab = 'bantuan';
if ($currentTab === 'admin' && !$isSuperAdmin) $currentTab = 'tasks';

// A. Jika Belum Login: Tampilkan Halaman Landing & Modal Login/Daftar
if (!$currentUser) {
    require_once __DIR__ . '/views/header.php';
    require_once __DIR__ . '/views/landing.php';
    require_once __DIR__ . '/views/footer.php';
    exit;
}

// B. Jika Sudah Login: Muat Data Dashboard & Tampilkan Views Tab Terkait
require_once __DIR__ . '/includes/data.php';

require_once __DIR__ . '/views/header.php';

if ($currentTab === 'tasks') {
    require_once __DIR__ . '/views/tab_tasks.php';
} elseif ($currentTab === 'projects') {
    require_once __DIR__ . '/views/tab_projects.php';
} elseif ($currentTab === 'bantuan' || $currentTab === 'guide' || $currentTab === 'commands') {
    require_once __DIR__ . '/views/tab_bantuan.php';
} elseif ($currentTab === 'admin' && $isSuperAdmin) {
    require_once __DIR__ . '/views/tab_admin.php';
} else {
    require_once __DIR__ . '/views/tab_tasks.php';
}

require_once __DIR__ . '/views/footer.php';
