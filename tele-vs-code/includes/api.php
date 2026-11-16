<?php
// includes/api.php - REST API & Attachment Handlers for VS Code Extension, Shell Runner & CLI
if (!defined('TELE_VS_CODE')) exit;

require_once __DIR__ . '/helpers.php';

if ($action === 'attachment') {
    $rawTid = $_GET['task'] ?? '';
    $cleanTid = preg_replace('/[^a-zA-Z0-9_#-]/', '', $rawTid);
    $fname = basename($_GET['file'] ?? '');
    $filePath = dirname(__DIR__) . '/uploads/' . $cleanTid . '/' . $fname;
    if (!file_exists($filePath)) {
        $noHash = ltrim($cleanTid, '#');
        if (file_exists(dirname(__DIR__) . '/uploads/#' . $noHash . '/' . $fname)) {
            $filePath = dirname(__DIR__) . '/uploads/#' . $noHash . '/' . $fname;
        } elseif (file_exists(dirname(__DIR__) . '/uploads/' . $noHash . '/' . $fname)) {
            $filePath = dirname(__DIR__) . '/uploads/' . $noHash . '/' . $fname;
        }
    }
    if ($cleanTid && $fname && file_exists($filePath)) {
        $mime = 'application/octet-stream';
        $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4',
            'mp4' => 'video/mp4', 'mkv' => 'video/x-matroska', 'avi' => 'video/x-msvideo', 'mov' => 'video/quicktime',
            'pdf' => 'application/pdf', 'txt' => 'text/plain', 'json' => 'application/json', 'csv' => 'text/csv',
            'zip' => 'application/zip', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ];
        if (isset($mimeTypes[$ext])) {
            $mime = $mimeTypes[$ext];
        } elseif (function_exists('mime_content_type')) {
            $mime = @mime_content_type($filePath) ?: 'application/octet-stream';
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($filePath));
        header('Content-Disposition: inline; filename="' . $fname . '"');
        readfile($filePath);
        exit;
    }
    http_response_code(404);
    echo "Lampiran tidak ditemukan.";
    exit;
}

// REST API untuk VS Code Extension & Worker Agent
$apiAliases = [
    'pull' => 'api_pull',
    'push' => 'api_push',
    'done' => 'api_done',
    'failed' => 'api_failed',
    'start' => 'api_in_progress',
    'in_progress' => 'api_in_progress',
    'status' => 'api_status',
    'projects' => 'api_projects',
    'upload_media' => 'api_upload_media',
    'media_list' => 'api_media_list',
    'get_task' => 'api_get_task'
];
if (isset($apiAliases[$action])) {
    $action = $apiAliases[$action];
}

if (in_array($action, ['api_pull', 'api_push', 'api_done', 'api_failed', 'api_in_progress', 'api_status', 'api_projects', 'api_upload_media', 'api_media_list', 'api_get_task'])) {
    $apiKey = $_GET['secret'] ?? ($_POST['secret'] ?? ($_GET['key'] ?? ($_POST['key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? ''))));
    
    // Pastikan session aktif jika dipanggil dari browser / dashboard
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            ini_set('session.cookie_secure', '1');
        }
        ini_set('session.cookie_samesite', 'Lax');
        @session_start();
    }

    $user = null;
    $authByProjectKey = false;

    // Dukung sesi web dashboard untuk upload media dari textarea / dropzone atau pemanggilan dari web
    $sessionUid = $_SESSION['tvsc_uid'] ?? ($_SESSION['tvsc_user_id'] ?? null);
    if (!empty($sessionUid)) {
        $stmtSess = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmtSess->execute([$sessionUid]);
        $sessionUser = $stmtSess->fetch(PDO::FETCH_ASSOC);
        if ($sessionUser) {
            $user = $sessionUser;
            if (empty($apiKey)) {
                $apiKey = $user['api_key'] ?? ('session_' . $user['id']);
            }
        }
    }

    // Jika belum terautentikasi lewat sesi, periksa API Key atau Project Key
    if (!$user) {
        // Fallback: Jika diupload dari dashboard web dengan project_id
        if (!$apiKey && !empty($_POST['project_id'])) {
            $prjFallbackId = (int)$_POST['project_id'];
            $stmtPrjFallback = $db->prepare("SELECT u.*, p.id as prj_id, p.project_key FROM projects p JOIN users u ON p.user_id = u.id WHERE p.id = ? LIMIT 1");
            $stmtPrjFallback->execute([$prjFallbackId]);
            $rowPrjFb = $stmtPrjFallback->fetch(PDO::FETCH_ASSOC);
            if ($rowPrjFb) {
                $user = $rowPrjFb;
                $apiKey = $user['api_key'] ?? $rowPrjFb['project_key'];
            }
        }

        if (!$apiKey && !$user) {
            http_response_code(401);
            echo json_encode(['error' => 'API Key wajib disertakan']);
            exit;
        }

        if (!$user) {
            $stmt = $db->prepare("SELECT * FROM users WHERE api_key = ?");
            $stmt->execute([$apiKey]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $stmtPrj = $db->prepare("SELECT u.*, p.id as prj_id, p.project_key FROM projects p JOIN users u ON p.user_id = u.id WHERE p.project_key = ? LIMIT 1");
                $stmtPrj->execute([$apiKey]);
                $rowPrj = $stmtPrj->fetch(PDO::FETCH_ASSOC);
                if ($rowPrj) {
                    $user = $rowPrj;
                    $authByProjectKey = $rowPrj['project_key'];
                }
            }
        }
    }

    if (!$user) {
        http_response_code(403);
        echo json_encode(['error' => 'API Key tidak valid']);
        exit;
    }

    // Blokir Akses API jika user diblokir oleh Superadmin
    if (!empty($user['is_blocked'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Akun Anda dinonaktifkan oleh Administrator']);
        exit;
    }

    header('Content-Type: application/json');

    // List Projects milik User
    if ($action === 'api_projects') {
        $stmtP = $db->prepare("SELECT id, project_key, name, code, bot_token, chat_id, timezone, executor_mode, last_workspace_folder, last_operator, last_seen FROM projects WHERE user_id = ?");
        $stmtP->execute([$user['id']]);
        echo json_encode(['ok' => true, 'projects' => $stmtP->fetchAll(PDO::FETCH_ASSOC), 'user_email' => $user['email']]);
        exit;
    }

    // Upload Media / File Dokumentasi dari IDE, CLI, atau Dashboard (Mendukung Single & Multi-File Upload)
    if ($action === 'api_upload_media') {
        $fileKey = null;
        foreach (['files', 'file', 'media', 'attachment', 'document', 'image'] as $k) {
            if (!empty($_FILES[$k]) && !empty($_FILES[$k]['tmp_name'])) {
                $fileKey = $k;
                break;
            }
        }

        if (!$fileKey || empty($_FILES[$fileKey]['tmp_name'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Tidak ada file atau media yang diunggah. Gunakan field "file" atau "files[]" dalam multipart/form-data.']);
            exit;
        }

        // Normalisasi $_FILES agar mendukung upload 1 file maupun banyak file sekaligus (file[] / files[])
        $rawFiles = $_FILES[$fileKey];
        $fileItems = [];
        if (is_array($rawFiles['tmp_name'])) {
            $countFiles = count($rawFiles['tmp_name']);
            for ($i = 0; $i < $countFiles; $i++) {
                if (empty($rawFiles['tmp_name'][$i]) && ($rawFiles['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $fileItems[] = [
                    'name'     => $rawFiles['name'][$i] ?? 'file',
                    'type'     => $rawFiles['type'][$i] ?? 'application/octet-stream',
                    'tmp_name' => $rawFiles['tmp_name'][$i] ?? '',
                    'error'    => $rawFiles['error'][$i] ?? UPLOAD_ERR_OK,
                    'size'     => (int)($rawFiles['size'][$i] ?? 0)
                ];
            }
        } else {
            $fileItems[] = [
                'name'     => $rawFiles['name'] ?? 'file',
                'type'     => $rawFiles['type'] ?? 'application/octet-stream',
                'tmp_name' => $rawFiles['tmp_name'] ?? '',
                'error'    => $rawFiles['error'] ?? UPLOAD_ERR_OK,
                'size'     => (int)($rawFiles['size'] ?? 0)
            ];
        }

        if (empty($fileItems)) {
            http_response_code(400);
            echo json_encode(['error' => 'Tidak ada berkas valid yang diterima.']);
            exit;
        }

        $maxBytes = 50 * 1024 * 1024; // 50MB per file
        foreach ($fileItems as $fi) {
            if ($fi['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['error' => 'Gagal mengunggah file "' . ($fi['name'] ?? '') . '" (Kode error PHP: ' . $fi['error'] . ')']);
                exit;
            }
            if ($fi['size'] > $maxBytes) {
                http_response_code(413);
                echo json_encode(['error' => 'Ukuran file "' . ($fi['name'] ?? '') . '" melebihi batas maksimum 50MB.']);
                exit;
            }
        }

        $prjInput = trim($_POST['project'] ?? ($_GET['project'] ?? ($_POST['project_id'] ?? ($_GET['project_id'] ?? ($_POST['project_key'] ?? ($_GET['project_key'] ?? ''))))));
        $targetProject = null;
        if ($prjInput) {
            if (is_numeric($prjInput)) {
                $stmtPrj = $db->prepare("SELECT * FROM projects WHERE user_id = ? AND id = ? LIMIT 1");
                $stmtPrj->execute([$user['id'], (int)$prjInput]);
            } else {
                $stmtPrj = $db->prepare("SELECT * FROM projects WHERE user_id = ? AND (project_key = ? OR LOWER(code) = ? OR LOWER(name) = ?) LIMIT 1");
                $stmtPrj->execute([$user['id'], $prjInput, strtolower($prjInput), strtolower($prjInput)]);
            }
            $targetProject = $stmtPrj->fetch(PDO::FETCH_ASSOC);
        }
        if (!$targetProject) {
            $stmtPrj = $db->prepare("SELECT * FROM projects WHERE user_id = ? ORDER BY id ASC LIMIT 1");
            $stmtPrj->execute([$user['id']]);
            $targetProject = $stmtPrj->fetch(PDO::FETCH_ASSOC);
        }

        $prjId = $targetProject ? (int)$targetProject['id'] : 0;
        $prjKey = $targetProject ? $targetProject['project_key'] : 'prj_general';
        $rawTaskIdInput = trim($_POST['task_id'] ?? ($_GET['task_id'] ?? ($_POST['id'] ?? ($_GET['id'] ?? ''))));
        $isDraftUpload = str_starts_with($rawTaskIdInput, 'draft_');
        $taskId = $isDraftUpload ? $rawTaskIdInput : resolveTaskId($db, $rawTaskIdInput);
        $caption = trim($_POST['caption'] ?? ($_GET['caption'] ?? ($_POST['description'] ?? ($_GET['description'] ?? ''))));
        $uploadedBy = trim($_POST['uploaded_by'] ?? ($_GET['uploaded_by'] ?? 'IDE'));

        $dateSub = date('Ymd');
        if ($taskId) {
            $cleanTid = preg_replace('/[^a-zA-Z0-9_#-]/', '', $taskId);
            $subFolder = $cleanTid;
        } else {
            $subFolder = $prjKey . '/' . $dateSub;
        }

        $uploadDir = dirname(__DIR__) . '/uploads/' . $subFolder;
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        $mainUploadDir = dirname(__DIR__) . '/uploads';
        if (!file_exists($mainUploadDir . '/.htaccess')) {
            $ht = "<FilesMatch \"(?i)\\.(php|phtml|phar|phps|inc|shtml|cgi|pl|py|sh|bat|cmd|exe|htaccess)$\">\n"
                . "    <IfModule mod_authz_core.c>\n"
                . "        Require all denied\n"
                . "    </IfModule>\n"
                . "</FilesMatch>\n"
                . "<IfModule mod_headers.c>\n"
                . "    Header set X-Content-Type-Options \"nosniff\"\n"
                . "</IfModule>\n";
            @file_put_contents($mainUploadDir . '/.htaccess', $ht);
        }

        $executableExts = [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps', 'inc',
            'shtml', 'cgi', 'pl', 'py', 'sh', 'bash', 'zsh', 'bat', 'cmd', 'ps1', 'psm1',
            'exe', 'com', 'scr', 'msi', 'vbs', 'vbe', 'wsf', 'wsh', 'htaccess', 'htpasswd',
            'ini', 'config', 'conf', 'asp', 'aspx', 'jsp', 'jspx', 'action', 'do', 'dll', 'so',
            'env', 'svgz'
        ];
        $extMime = [
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska',
            'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4',
            'pdf' => 'application/pdf', 'txt' => 'text/plain', 'log' => 'text/plain', 'md' => 'text/markdown', 'json' => 'application/json', 'csv' => 'text/csv', 'zip' => 'application/zip'
        ];

        $uploadedResults = [];
        $now = date('Y-m-d H:i:s');
        $ins = $db->prepare("INSERT INTO project_media (user_id, project_id, project_key, task_id, file_name, original_name, file_path, file_url, file_type, file_size, caption, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        foreach ($fileItems as $f) {
            $origName = basename(str_replace(['../', '..\\', chr(0)], '', $f['name']));
            $cleanName = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $origName);
            $cleanName = preg_replace('/\.(php[0-9]*|phtml|phar|phps|inc|shtml|cgi|pl|py|sh|bash|bat|cmd|ps1|exe|asp|aspx|jsp|env)\./i', '_$1.', $cleanName);

            $ext = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
            if (in_array($ext, $executableExts) || empty($ext)) {
                $cleanName .= '.txt';
                $ext = 'txt';
            }

            $baseStem = pathinfo($cleanName, PATHINFO_FILENAME);
            $finalName = $cleanName;
            $counter = 1;
            while (file_exists($uploadDir . '/' . $finalName)) {
                $finalName = $baseStem . '_' . date('His') . '_' . $counter . '.' . $ext;
                $counter++;
            }

            $targetPath = $uploadDir . '/' . $finalName;
            if (!move_uploaded_file($f['tmp_name'], $targetPath)) {
                if (!copy($f['tmp_name'], $targetPath)) {
                    http_response_code(500);
                    echo json_encode(['error' => 'Gagal menyimpan file ke direktori server']);
                    exit;
                }
                @unlink($f['tmp_name']);
            }

            $fileContentSample = @file_get_contents($targetPath, false, null, 0, 4096);
            if ($fileContentSample !== false && (preg_match('/<\?(php|=|\s)/i', $fileContentSample) || stripos($fileContentSample, '__halt_compiler') !== false)) {
                if (!str_ends_with(strtolower($finalName), '.txt')) {
                    $safeRenamed = $finalName . '.txt';
                    rename($targetPath, $uploadDir . '/' . $safeRenamed);
                    $finalName = $safeRenamed;
                    $targetPath = $uploadDir . '/' . $finalName;
                }
            }

            $subFolderParts = explode('/', $subFolder);
            $encodedSub = implode('/', array_map('rawurlencode', $subFolderParts));
            $fileUrl = "https://biru.co.id/tele-vs-code/uploads/" . $encodedSub . "/" . rawurlencode($finalName);

            $fileType = $f['type'] ?? '';
            if (!$fileType || $fileType === 'application/octet-stream') {
                $fileType = $extMime[$ext] ?? 'application/octet-stream';
            }
            $fileSize = (int)$f['size'];
            $attCategory = str_starts_with($fileType, 'image/') ? 'photo' : (str_starts_with($fileType, 'video/') ? 'video' : (str_starts_with($fileType, 'audio/') ? 'audio' : 'document'));

            $ins->execute([
                $user['id'],
                $prjId,
                $prjKey,
                $isDraftUpload ? '' : $taskId,
                $finalName,
                $origName,
                $targetPath,
                $fileUrl,
                $fileType,
                $fileSize,
                $caption,
                $uploadedBy,
                $now
            ]);
            $mediaId = (int)$db->lastInsertId();

            $uploadedResults[] = [
                'id' => $mediaId,
                'file_name' => $finalName,
                'original_name' => $origName,
                'file_url' => $fileUrl,
                'file_type' => $fileType,
                'attachment_type' => $attCategory,
                'file_size' => $fileSize
            ];
        }

        // Jika terkait ke tugas nyata (bukan draft), akumulasikan ke attachments_json tugas tersebut
        if ($taskId && !$isDraftUpload) {
            $stmtCurTask = $db->prepare("SELECT id, instruction, attachment_url, attachment_type, attachment_name, attachments_json FROM tasks WHERE id = ? AND user_id = ? LIMIT 1");
            $stmtCurTask->execute([$taskId, $user['id']]);
            $curTaskRow = $stmtCurTask->fetch(PDO::FETCH_ASSOC);
            if ($curTaskRow) {
                $existingAtts = parseTaskAttachments($curTaskRow);
                $seenUrls = [];
                foreach ($existingAtts as $ea) {
                    $seenUrls[strtolower($ea['url'])] = true;
                }
                foreach ($uploadedResults as $ur) {
                    $uKey = strtolower($ur['file_url']);
                    if (!isset($seenUrls[$uKey])) {
                        $seenUrls[$uKey] = true;
                        $existingAtts[] = [
                            'url' => $ur['file_url'],
                            'type' => $ur['attachment_type'],
                            'mime' => $ur['file_type'],
                            'name' => $ur['file_name'],
                            'size' => $ur['file_size'],
                            'media_id' => $ur['id']
                        ];
                    }
                }
                $firstAtt = $existingAtts[0] ?? null;
                $primaryUrl = !empty($curTaskRow['attachment_url']) ? $curTaskRow['attachment_url'] : ($firstAtt['url'] ?? '');
                $primaryType = !empty($curTaskRow['attachment_type']) ? $curTaskRow['attachment_type'] : ($firstAtt['type'] ?? '');
                $primaryName = !empty($curTaskRow['attachment_name']) ? $curTaskRow['attachment_name'] : ($firstAtt['name'] ?? '');

                $updT = $db->prepare("UPDATE tasks SET attachment_url = ?, attachment_name = ?, attachment_type = ?, attachments_json = ?, updated_at = ? WHERE id = ? AND user_id = ?");
                safeDbExecute($updT, [$primaryUrl, $primaryName, $primaryType, json_encode($existingAtts, JSON_UNESCAPED_SLASHES), $now, $taskId, $user['id']]);
            }
        }

        $notifyTele = !empty($_POST['notify_telegram']) || !empty($_GET['notify_telegram']);
        if ($notifyTele && $targetProject && !empty($targetProject['bot_token']) && !empty($targetProject['chat_id'])) {
            $pName = htmlspecialchars($targetProject['name'] ?: 'Tele-vs-Code');
            $pCode = !empty($targetProject['code']) ? (' (#' . htmlspecialchars($targetProject['code']) . ')') : '';
            foreach ($uploadedResults as $idx => $ur) {
                $fileHeader = count($uploadedResults) > 1 ? (" (" . ($idx + 1) . "/" . count($uploadedResults) . ")") : "";
                $teleCaption = "📁 <b>DOKUMENTASI MEDIA BARU{$fileHeader}</b>\n"
                             . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                             . "📁 <b>Proyek:</b> <code>$pName</code>$pCode\n"
                             . "📎 <b>Berkas:</b> <code>" . htmlspecialchars($ur['file_name']) . "</code> (" . round($ur['file_size'] / 1024, 1) . " KB)\n"
                             . "🕒 <b>Waktu:</b> " . date('d-m-Y H:i:s') . "\n"
                             . "👤 <b>Pengunggah:</b> " . htmlspecialchars($uploadedBy) . "\n";
                if ($caption) {
                    $teleCaption .= "📝 <b>Keterangan:</b> " . htmlspecialchars($caption) . "\n";
                }
                if ($taskId && !$isDraftUpload) {
                    $teleCaption .= "🆔 <b>ID Tugas:</b> <code>" . htmlspecialchars($taskId) . "</code>\n";
                }
                $teleCaption .= "\n🌐 <a href=\"{$ur['file_url']}\">Buka / Unduh Berkas Dokumentasi</a>\n"
                              . "🤖 <i>Tersimpan otomatis di Server Biru</i>";

                if (str_starts_with($ur['file_type'], 'image/') && strlen($teleCaption) <= 1024) {
                    callTelegram($targetProject['bot_token'], 'sendPhoto', [
                        'chat_id' => $targetProject['chat_id'],
                        'photo' => $ur['file_url'],
                        'caption' => $teleCaption,
                        'parse_mode' => 'HTML'
                    ]);
                } else {
                    callTelegram($targetProject['bot_token'], 'sendMessage', [
                        'chat_id' => $targetProject['chat_id'],
                        'text' => $teleCaption,
                        'parse_mode' => 'HTML',
                        'link_preview_options' => json_encode(['url' => $ur['file_url'], 'prefer_large_media' => true])
                    ]);
                }
            }
        }

        $firstRes = $uploadedResults[0];
        echo json_encode([
            'ok' => true,
            'id' => $firstRes['id'],
            'file_name' => $firstRes['file_name'],
            'original_name' => $firstRes['original_name'],
            'file_url' => $firstRes['file_url'],
            'file_type' => $firstRes['file_type'],
            'attachment_type' => $firstRes['attachment_type'],
            'file_size' => $firstRes['file_size'],
            'files' => $uploadedResults,
            'count' => count($uploadedResults),
            'task_id' => $taskId,
            'caption' => $caption,
            'message' => count($uploadedResults) > 1
                ? (count($uploadedResults) . ' berkas media dokumentasi berhasil disimpan di Server Biru')
                : 'Berkas media dokumentasi berhasil disimpan di Server Biru'
        ]);
        exit;
    }

    // List Media & Dokumen Proyek
    if ($action === 'api_media_list') {
        $prjInput = trim($_GET['project'] ?? ($_GET['project_key'] ?? ''));
        $sql = "SELECT m.*, p.name as project_name FROM project_media m LEFT JOIN projects p ON m.project_id = p.id WHERE m.user_id = ?";
        $params = [$user['id']];
        if ($prjInput) {
            $sql .= " AND (m.project_key = ? OR LOWER(p.code) = ? OR LOWER(p.name) = ?)";
            $params[] = $prjInput;
            $params[] = strtolower($prjInput);
            $params[] = strtolower($prjInput);
        }
        $sql .= " ORDER BY m.created_at DESC LIMIT 50";
        $stmtM = $db->prepare($sql);
        $stmtM->execute($params);
        echo json_encode(['ok' => true, 'media' => $stmtM->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // Pull Pending Tasks (Opsional filter by project_id / project_key / code / name)
    if ($action === 'api_pull') {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 1;
        $mark = ($_GET['mark'] ?? '0') === '1';
        $prjFilter = $_GET['project_id'] ?? ($_GET['project'] ?? ($_GET['code'] ?? ''));
        $wsFolder = trim($_GET['workspace_folder'] ?? ($_POST['workspace_folder'] ?? ''));
        $operator = trim($_GET['operator'] ?? ($_POST['operator'] ?? ''));

        // Auto-resolve project jika $prjFilter kosong tetapi $wsFolder ada (Remote SSH / server headless)
        if (!$prjFilter && $wsFolder) {
            $cleanWs = strtolower(rtrim(str_replace('\\', '/', $wsFolder), '/'));
            $wsBase = strtolower(basename($cleanWs));
            $stmtAllP = $db->prepare("SELECT * FROM projects WHERE user_id = ?");
            $stmtAllP->execute([$user['id']]);
            $candProjects = $stmtAllP->fetchAll(PDO::FETCH_ASSOC);
            foreach ($candProjects as $cp) {
                $pFold = strtolower(rtrim(str_replace('\\', '/', $cp['last_workspace_folder'] ?? ''), '/'));
                $pCode = strtolower(trim($cp['code'] ?? ''));
                $pName = strtolower(trim($cp['name'] ?? ''));
                if (($pFold && ($cleanWs === $pFold || substr($cleanWs, -strlen($pFold)) === $pFold || substr($pFold, -strlen($cleanWs)) === $cleanWs)) ||
                    ($pCode && $wsBase === $pCode) ||
                    ($pName && $wsBase === $pName)) {
                    $prjFilter = $cp['project_key'] ?: (string)$cp['id'];
                    break;
                }
            }
        }

        // Update Heartbeat Workspace / VS Code Instance untuk Proyek ini (Throttled & Non-Blocking)
        if ($wsFolder || $operator) {
            try {
                $now = date('Y-m-d H:i:s');
                if ($prjFilter) {
                    $cleanFilter = strtolower(trim($prjFilter));
                    if (is_numeric($prjFilter)) {
                        $updH = $db->prepare("UPDATE projects SET last_workspace_folder = ?, last_operator = ?, last_seen = ? 
                                              WHERE user_id = ? AND (id = ? OR LOWER(code) = ?) 
                                              AND (last_seen IS NULL OR last_seen < datetime('now', '-20 seconds') OR last_workspace_folder IS NULL OR last_workspace_folder != ? OR last_operator != ?)");
                        safeDbExecute($updH, [$wsFolder, $operator, $now, $user['id'], (int)$prjFilter, $cleanFilter, $wsFolder, $operator], 3, 100);
                    } else {
                        $updH = $db->prepare("UPDATE projects SET last_workspace_folder = ?, last_operator = ?, last_seen = ? 
                                              WHERE user_id = ? AND (project_key = ? OR LOWER(code) = ? OR LOWER(name) = ?) 
                                              AND (last_seen IS NULL OR last_seen < datetime('now', '-20 seconds') OR last_workspace_folder IS NULL OR last_workspace_folder != ? OR last_operator != ?)");
                        safeDbExecute($updH, [$wsFolder, $operator, $now, $user['id'], $prjFilter, $cleanFilter, $cleanFilter, $wsFolder, $operator], 3, 100);
                    }
                } else {
                    $updH = $db->prepare("UPDATE projects SET last_workspace_folder = ?, last_operator = ?, last_seen = ? 
                                          WHERE user_id = ? 
                                          AND (last_seen IS NULL OR last_seen < datetime('now', '-20 seconds') OR last_workspace_folder IS NULL OR last_workspace_folder != ? OR last_operator != ?) 
                                          ORDER BY id ASC LIMIT 1");
                    safeDbExecute($updH, [$wsFolder, $operator, $now, $user['id'], $wsFolder, $operator], 3, 100);
                }
            } catch (\Throwable $hbErr) {
                // Heartbeat adalah telemetri non-kritis; kegagalannya saat transient lock contention tidak boleh mematikan api_pull
                error_log("Heartbeat update ignored during lock contention: " . $hbErr->getMessage());
            }
        }

        $sql = "SELECT t.*, p.name as project_name, p.code as project_code, p.bot_token, p.chat_id FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.user_id = ? AND (t.status = 'PENDING' OR (t.status = 'IN_PROGRESS' AND t.updated_at < datetime('now', '-2 minutes')))";
        $params = [$user['id']];

        if ($prjFilter) {
            $cleanFilter = strtolower(ltrim(trim($prjFilter), '#/'));
            if (is_numeric($cleanFilter)) {
                $sql .= " AND (t.project_id = ? OR LOWER(p.code) = ?)";
                $params[] = (int)$cleanFilter;
                $params[] = $cleanFilter;
            } else {
                $sql .= " AND (p.project_key = ? OR LOWER(p.code) = ? OR LOWER(p.name) = ?)";
                $params[] = $prjFilter;
                $params[] = $cleanFilter;
                $params[] = $cleanFilter;
            }
        }
        $sql .= " ORDER BY CASE t.priority WHEN 'URGENT' THEN 1 WHEN 'HIGH' THEN 2 WHEN 'NORMAL' THEN 3 WHEN 'LOW' THEN 4 ELSE 3 END, t.created_at ASC LIMIT ?";
        $params[] = $limit;

        $stmtTasks = $db->prepare($sql);
        $stmtTasks->execute($params);
        $allPulled = $stmtTasks->fetchAll(PDO::FETCH_ASSOC);
        $pending = [];
        $nowTime = date('Y-m-d H:i:s');
        foreach ($allPulled as $pt) {
            if ($pt['status'] === 'IN_PROGRESS') {
                $curRetry = (int)($pt['retry_count'] ?? 0) + 1;
                if ($curRetry >= 3) {
                    // ATURAN MUTLAK: Tugas yang berkendala tidak boleh dieksekusi berulang-ulang!
                    $failReason = 'Otomatis ditandai KENDALA (melebihi batas 3x percobaan tanpa penyelesaian)';
                    $updF = $db->prepare("UPDATE tasks SET status = 'FAILED', updated_at = ?, notes = ?, result = ?, retry_count = ? WHERE id = ?");
                    safeDbExecute($updF, [$nowTime, $failReason, $failReason, $curRetry, $pt['id']]);
                    if (!empty($pt['bot_token']) && !empty($pt['chat_id']) && empty($user['block_notify'])) {
                        $pName = htmlspecialchars($pt['project_name'] ?: 'Tele-vs-Code');
                        callTelegram($pt['bot_token'], 'sendMessage', [
                            'chat_id' => $pt['chat_id'],
                            'text' => "<b>🚨 Tugas {$pt['id']}: KENDALA</b>\n\n📁 <b>Proyek:</b> <code>$pName</code>\n🕒 <b>Waktu:</b> $nowTime\n📋 <b>Penyebab Kendala:</b> $failReason\n\n🛑 <i>Tugas dihentikan untuk mencegah loop eksekusi berulang-ulang. Silakan kirim instruksi baru untuk mencoba kembali.</i>",
                            'parse_mode' => 'HTML'
                        ]);
                    }
                    continue; // Jangan masukkan ke daftar tugas aktif
                } else {
                    $updR = $db->prepare("UPDATE tasks SET retry_count = ?, updated_at = ? WHERE id = ?");
                    safeDbExecute($updR, [$curRetry, $nowTime, $pt['id']]);
                }
            }
            $pt['attachments'] = parseTaskAttachments($pt);
            $pending[] = $pt;
        }

        if ($mark && !empty($pending)) {
            $now = date('Y-m-d H:i:s');
            foreach ($pending as $pt) {
                $isNewlyStarted = ($pt['status'] === 'PENDING');
                // Aturan: api_pull hanya memperbarui last_seen tanpa mengubah status ke IN_PROGRESS
                $upd = $db->prepare("UPDATE tasks SET updated_at = ?, notes = 'Ditarik antrean oleh VS Code Worker' WHERE id = ?");
                safeDbExecute($upd, [$now, $pt['id']]);

                // Kirim notifikasi Telegram jika memulai pekerjaan 1x saja (cek apakah sudah pernah dikirim)
                if ($isNewlyStarted && empty($pt['start_notified']) && !empty($pt['bot_token']) && !empty($pt['chat_id']) && empty($pt['block_notify'])) {
                    $tz = !empty($pt['timezone']) ? $pt['timezone'] : (!empty($user['timezone']) ? $user['timezone'] : 'Asia/Jakarta');
                    $tzAbbr = getTimezoneAbbr($tz);
                    $instSnippet = htmlspecialchars($pt['instruction']);
                    $pName = htmlspecialchars($pt['project_name'] ?: 'Tele-vs-Code');
                    $pTag = !empty($pt['project_code']) ? (' (#' . htmlspecialchars($pt['project_code']) . ')') : '';

                    $startMsg = "⚡ <b>MEMULAI PEKERJAAN</b>\n"
                              . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                              . "🆔 <b>ID Tugas:</b> <code>{$pt['id']}</code>\n"
                              . "📁 <b>Proyek:</b> <code>$pName</code>$pTag\n"
                              . "🕒 <b>Waktu Mulai:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n"
                              . "👤 <b>Pemohon:</b> " . htmlspecialchars($pt['sender']) . "\n\n"
                              . "🤖 <b>Eksekutor:</b> <code>" . htmlspecialchars(!empty($pt['assigned_agent']) ? $pt['assigned_agent'] : 'Antigravity AI Agent') . "</code>\n\n"
                              . "📝 <b>Perintah / Instruksi:</b>\n<code>$instSnippet</code>\n\n"
                              . "🚀 <b>Status:</b> <code>[IN_PROGRESS]</code>\n"
                              . "🤖 <i>AI Agent di VS Code telah menerima tugas ini dan sedang mengeksekusi sampai tuntas 100%...</i>";

                    callTelegram($pt['bot_token'], 'sendMessage', [
                        'chat_id' => $pt['chat_id'],
                        'text' => $startMsg,
                        'parse_mode' => 'HTML'
                    ]);
                    $updNotif = $db->prepare("UPDATE tasks SET start_notified = 1 WHERE id = ?");
                    safeDbExecute($updNotif, [$pt['id']]);
                }
            }
        }
        echo json_encode(['ok' => true, 'count' => count($pending), 'tasks' => $pending, 'user_email' => $user['email']]);
        exit;
    }

    // Push Task Baru
    if ($action === 'api_push') {
        $checkInst = $_POST['instruction'] ?? ($_GET['instruction'] ?? '');
        if (isRestrictedDangerousCommand($checkInst) && !isAuthorizedSecurityUser($user['email'] ?? '')) {
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'error' => 'Akses ditolak: Perintah sistem berbahaya (reboot/shell) dicegah oleh Security Guard dan hanya diizinkan untuk akun terverifikasi iam@usm.rip.'
            ]);
            exit;
        }
        $instruction = $_POST['instruction'] ?? ($_GET['instruction'] ?? '');
        $sender = $_POST['sender'] ?? 'VS Code';
        $status = $_POST['status'] ?? 'IN_PROGRESS';
        $notes = $_POST['notes'] ?? 'Dibuat via VS Code';
        $command = trim($_POST['command'] ?? ($_GET['command'] ?? ''));
        if (!$command) {
            $command = ($sender === 'IDE Chat' || $sender === 'VS Code') ? 'tele-task add' : '/task';
        }
        $prjInput = $_POST['project_id'] ?? ($_GET['project_id'] ?? ($_POST['project'] ?? ($_GET['project'] ?? '')));
        if (!$prjInput && !empty($authByProjectKey)) {
            $prjInput = $authByProjectKey;
        }
        $prjId = 0;
        if ($prjInput) {
            $cleanPrj = strtolower(ltrim(trim($prjInput), '#/'));
            if (is_numeric($cleanPrj)) {
                $prjId = (int)$cleanPrj;
            } else {
                $stmtFind = $db->prepare("SELECT id FROM projects WHERE user_id = ? AND (project_key = ? OR LOWER(code) = ? OR LOWER(name) = ? OR LOWER(last_workspace_folder) LIKE ?) LIMIT 1");
                $stmtFind->execute([$user['id'], $prjInput, $cleanPrj, $cleanPrj, '%' . $cleanPrj . '%']);
                $f = $stmtFind->fetch(PDO::FETCH_ASSOC);
                if ($f) $prjId = (int)$f['id'];
            }
        }
        if (!$prjId) {
            $wsFolder = trim($_POST['workspace_folder'] ?? ($_GET['workspace_folder'] ?? ''));
            if ($wsFolder) {
                $cleanWs = strtolower(rtrim(str_replace('\\', '/', $wsFolder), '/'));
                $stmtAllP = $db->prepare("SELECT id, last_workspace_folder, code, name FROM projects WHERE user_id = ?");
                $stmtAllP->execute([$user['id']]);
                foreach ($stmtAllP->fetchAll(PDO::FETCH_ASSOC) as $cp) {
                    $pFold = strtolower(rtrim(str_replace('\\', '/', $cp['last_workspace_folder'] ?? ''), '/'));
                    if ($pFold && ($cleanWs === $pFold || str_ends_with($cleanWs, $pFold) || str_ends_with($pFold, $cleanWs))) {
                        $prjId = (int)$cp['id'];
                        break;
                    }
                }
            }
        }
        if (!$prjId) {
            $stmtFirst = $db->prepare("SELECT id FROM projects WHERE user_id = ? ORDER BY id ASC LIMIT 1");
            $stmtFirst->execute([$user['id']]);
            $firstP = $stmtFirst->fetch(PDO::FETCH_ASSOC);
            $prjId = $firstP ? (int)$firstP['id'] : 0;
        }

        if (!$instruction) {
            echo json_encode(['error' => 'Instruksi kosong']);
            exit;
        }

        $priority = strtoupper(trim($_POST['priority'] ?? ($_GET['priority'] ?? 'NORMAL')));
        if (!in_array($priority, ['URGENT', 'HIGH', 'NORMAL', 'LOW'])) {
            $priority = 'NORMAL';
        }

        $isIde = !empty($_POST['is_ide']) || !empty($_GET['is_ide']) || stripos($sender, 'IDE') !== false || stripos($sender, 'VS Code') !== false;
        $idPrefix = $isIde ? '#TASK-IDE-' : '#TASK-';
        $taskId = '#TASK-' . substr(bin2hex(random_bytes(2)), 0, 4);
        $assignedAgent = trim($_POST['agent'] ?? ($_GET['agent'] ?? ($_POST['assigned_agent'] ?? ($_GET['assigned_agent'] ?? ''))));
        if (!$assignedAgent) {
            if (preg_match('/(?:^|\s)@(\w+)/i', $instruction, $mAgt)) {
                $cAgent = strtolower($mAgt[1]);
                $knownAgents = ['antigravity', 'gemini', 'claude', 'copilot', 'codex', 'cursor', 'roo', 'cline'];
                if (in_array($cAgent, $knownAgents)) {
                    $assignedAgent = ucfirst($cAgent) . ' Agent';
                    $instruction = trim(preg_replace('/(?:^|\s)@' . preg_quote($cAgent, '/') . '/i', '', $instruction));
                }
            } elseif (preg_match('/(?:--agent|\[agent:\s*)([\w\s-]+)\]?/i', $instruction, $mAgt)) {
                $assignedAgent = trim($mAgt[1]);
                $instruction = trim(preg_replace('/(?:--agent|\[agent:\s*)([\w\s-]+)\]?/i', '', $instruction));
            }
        }
        if (!$assignedAgent) $assignedAgent = 'Antigravity AI Agent';

        // Quota Guard: Cek apakah instruksi serupa sedang aktif untuk project ini
        $cleanInstLower = strtolower(trim(preg_replace('/\s+/', ' ', $instruction)));
        if ($cleanInstLower !== '') {
            $stmtChk = $db->prepare("SELECT id, status FROM tasks WHERE user_id = ? AND project_id = ? AND status IN ('PENDING', 'WAITING', 'IN_PROGRESS') ORDER BY id DESC LIMIT 5");
            $stmtChk->execute([$user['id'], $prjId]);
            $existingActive = $stmtChk->fetchAll(PDO::FETCH_ASSOC);
            $stmtChk->closeCursor();

            foreach ($existingActive as $ea) {
                if (strtolower(trim(preg_replace('/\s+/', ' ', $ea['instruction'] ?? ''))) === $cleanInstLower) {
                    echo json_encode([
                        'ok' => true,
                        'id' => $ea['id'],
                        'message' => "Perintah serupa sudah ada di antrean aktif (" . $ea['id'] . ") dengan status " . $ea['status'] . ". Duplikasi dicegah untuk hemat kuota agent.",
                        'deduplicated' => true
                    ]);
                    exit;
                }
            }
        }

        $attUrl = trim($_POST['attachment_url'] ?? ($_GET['attachment_url'] ?? ''));
        $attType = trim($_POST['attachment_type'] ?? ($_GET['attachment_type'] ?? ''));
        $attName = trim($_POST['attachment_name'] ?? ($_GET['attachment_name'] ?? ''));
        $attachmentsJsonRaw = trim($_POST['attachments_json'] ?? ($_GET['attachments_json'] ?? ''));
        $attachmentsArr = [];
        if ($attachmentsJsonRaw !== '') {
            $dec = json_decode($attachmentsJsonRaw, true);
            if (is_array($dec)) {
                foreach ($dec as $it) {
                    if (is_array($it) && !empty($it['url'])) {
                        $attachmentsArr[] = [
                            'url' => trim((string)$it['url']),
                            'type' => trim((string)($it['type'] ?? 'document')),
                            'name' => trim((string)($it['name'] ?? basename(parse_url($it['url'], PHP_URL_PATH) ?: 'file'))),
                            'mime' => trim((string)($it['mime'] ?? '')),
                            'size' => isset($it['size']) ? (int)$it['size'] : 0,
                            'media_id' => isset($it['media_id']) ? (int)$it['media_id'] : 0
                        ];
                    }
                }
            }
        }
        if (empty($attachmentsArr) && $attUrl !== '') {
            $attachmentsArr[] = [
                'url' => $attUrl,
                'type' => $attType ?: 'document',
                'name' => $attName ?: basename(parse_url($attUrl, PHP_URL_PATH) ?: 'file')
            ];
        }
        if (!empty($attachmentsArr) && $attUrl === '') {
            $attUrl = $attachmentsArr[0]['url'];
            $attType = $attachmentsArr[0]['type'];
            $attName = $attachmentsArr[0]['name'];
        }
        $attachmentsJson = !empty($attachmentsArr) ? json_encode($attachmentsArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';

        // Selaraskan timezone dengan proyek / user agar created_at tidak mundur ke UTC
        $effectiveTz = 'Asia/Jakarta';
        if ($prjId > 0) {
            $stmtPrjTz = $db->prepare("SELECT timezone FROM projects WHERE id = ?");
            $stmtPrjTz->execute([$prjId]);
            $pTzVal = $stmtPrjTz->fetchColumn();
            if (!empty($pTzVal)) $effectiveTz = $pTzVal;
        }
        if (empty($effectiveTz) && !empty($user['timezone'])) {
            $effectiveTz = $user['timezone'];
        }
        if (function_exists('applyTimezone')) {
            applyTimezone($effectiveTz);
        } else {
            date_default_timezone_set($effectiveTz);
        }

        $now = date('Y-m-d H:i:s');
        $stmtIns = $db->prepare("INSERT INTO tasks (id, user_id, project_id, instruction, sender, status, created_at, updated_at, notes, command, assigned_agent, priority, attachment_url, attachment_type, attachment_name, attachments_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        safeDbExecute($stmtIns, [$taskId, $user['id'], $prjId, $instruction, $sender, $status, $now, $now, $notes, $command, $assignedAgent, $priority, $attUrl, $attType, $attName, $attachmentsJson]);

        // Kirim Notifikasi ke Telegram Bot Proyek agar user melihat update langsung di HP/Telegram
        $notifiedByServer = false;
        if ($prjId > 0) {
            $stmtPrj = $db->prepare("SELECT bot_token, chat_id, name FROM projects WHERE id = ?");
            $stmtPrj->execute([$prjId]);
            $pData = $stmtPrj->fetch(PDO::FETCH_ASSOC);
            if ($pData && !empty($pData['bot_token']) && !empty($pData['chat_id']) && empty($user['block_notify'])) {
                $statBadge = ($status === 'IN_PROGRESS') ? '⚡ SEDANG DIKERJAKAN' : ($status === 'DONE' ? '✅ SELESAI' : '⏳ MENUNGGU');
                $teleMsg = "💻 <b>PERINTAH DARI IDE CHAT / VS CODE</b>\n\n"
                         . "🆔 <b>ID:</b> <code>$taskId</code>\n"
                         . "📁 <b>Proyek:</b> <code>" . htmlspecialchars($pData['name']) . "</code>\n"
                         . "👤 <b>Pengirim:</b> " . htmlspecialchars($sender) . "\n"
                         . "🤖 <b>Agent:</b> " . htmlspecialchars($assignedAgent) . "\n"
                         . "📊 <b>Status:</b> <b>$statBadge</b>\n"
                         . "📝 <b>Instruksi:</b>\n" . htmlspecialchars($instruction) . "\n\n"
                         . '🌐 <a href="https://biru.co.id/tele-vs-code/?tab=tasks">Buka Dashboard Status</a>';
                $tRes = callTelegram($pData['bot_token'], 'sendMessage', [
                    'chat_id' => $pData['chat_id'],
                    'text' => $teleMsg,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true
                ]);
                $notifiedByServer = ($tRes && !empty($tRes['ok']));
            }
        }

        echo json_encode(['ok' => true, 'id' => $taskId, 'message' => "Tugas $taskId berhasil dicatat", 'notified_by_server' => $notifiedByServer]);
        exit;
    }

        // Update Status Tugas Menjadi IN_PROGRESS (Requirement: notifikasi telegram jika memulai pekerjaan detail termasuk perintah)
        if ($action === 'api_in_progress') {
        $taskId = resolveTaskId($db, $_GET['id'] ?? ($_POST['id'] ?? ''));
        $notes = trim($_POST['notes'] ?? ($_GET['notes'] ?? 'Sedang dikerjakan oleh AI Agent'));
        $now = date('Y-m-d H:i:s');

        // Ambil data tugas & proyek terlebih dahulu
        $stmtT = $db->prepare("SELECT t.*, p.bot_token, p.chat_id, p.name as project_name, p.code as project_code, p.timezone as project_timezone FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.id = ? AND t.user_id = ?");
        $stmtT->execute([$taskId, $user['id']]);
        $task = $stmtT->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Tugas tidak ditemukan.']);
            exit;
        }

        $pId = !empty($task['project_id']) ? intval($task['project_id']) : 0;

        // Aturan Sekuensial: Hanya satu tugas yang boleh [IN_PROGRESS] pada proyek yang sama pada satu waktu
        if ($pId > 0) {
            $stmtChk = $db->prepare("SELECT id FROM tasks WHERE user_id = ? AND project_id = ? AND status = 'IN_PROGRESS' AND id != ? LIMIT 1");
            $stmtChk->execute([$user['id'], $pId, $taskId]);
        } else {
            $stmtChk = $db->prepare("SELECT id FROM tasks WHERE user_id = ? AND status = 'IN_PROGRESS' AND id != ? LIMIT 1");
            $stmtChk->execute([$user['id'], $taskId]);
        }
        $existingProg = $stmtChk->fetch(PDO::FETCH_ASSOC);
        if ($existingProg && empty($_REQUEST['force'])) {
            http_response_code(409);
            echo json_encode([
                'ok' => false,
                'error' => "Tugas #{$existingProg['id']} masih berstatus [IN_PROGRESS] pada proyek ini. Berdasarkan aturan, tugas harus dikerjakan satu per satu secara sekuensial.",
                'active_task_id' => $existingProg['id']
            ]);
            exit;
        }

        $upd = $db->prepare("UPDATE tasks SET status = 'IN_PROGRESS', updated_at = ?, notes = ? WHERE id = ? AND user_id = ?");
        safeDbExecute($upd, [$now, $notes, $taskId, $user['id']]);

        $alreadyNotified = !empty($task['start_notified']);
        $notifiedByServer = false;

        // Kirim notifikasi Telegram jika memulai pekerjaan 1x saja (cek apakah sudah pernah dikirim)
        if (!$alreadyNotified && !empty($task['bot_token']) && !empty($task['chat_id']) && empty($user['block_notify'])) {
            $tz = !empty($task['project_timezone']) ? $task['project_timezone'] : (!empty($user['timezone']) ? $user['timezone'] : 'Asia/Jakarta');
            $tzAbbr = getTimezoneAbbr($tz);
            $instSnippet = htmlspecialchars($task['instruction']);
            $pName = htmlspecialchars($task['project_name'] ?: 'Tele-vs-Code');
            $pTag = !empty($task['project_code']) ? (' (#' . htmlspecialchars($task['project_code']) . ')') : '';

            $taskAttachments = parseTaskAttachments($task);
            $attachLine = "";
            if (!empty($taskAttachments)) {
                if (count($taskAttachments) === 1) {
                    $singleAtt = $taskAttachments[0];
                    $typeLabel = ($singleAtt['type'] === 'photo') ? 'Foto' : (($singleAtt['type'] === 'video') ? 'Video' : (($singleAtt['type'] === 'audio' || $singleAtt['type'] === 'voice') ? 'Audio' : 'Dokumen'));
                    $attachLine = "📎 <b>Lampiran:</b> " . htmlspecialchars($singleAtt['name']) . " (Tipe: $typeLabel)\n";
                    if (!empty($singleAtt['url'])) {
                        $attachLine .= "🔗 <b>Tautan File:</b> <a href=\"{$singleAtt['url']}\">Buka Lampiran</a>\n";
                    }
                    $attachLine .= "\n";
                } else {
                    $attachLine = "📎 <b>Lampiran (" . count($taskAttachments) . " File):</b>\n";
                    foreach ($taskAttachments as $idxAtt => $attItem) {
                        $typeLabel = ($attItem['type'] === 'photo') ? 'Foto' : (($attItem['type'] === 'video') ? 'Video' : (($attItem['type'] === 'audio' || $attItem['type'] === 'voice') ? 'Audio' : 'Dokumen'));
                        $nAtt = htmlspecialchars($attItem['name'] ?: ('File ' . ($idxAtt + 1)));
                        $uAtt = htmlspecialchars($attItem['url']);
                        $attachLine .= "  " . ($idxAtt + 1) . ". [$typeLabel] <a href=\"$uAtt\">$nAtt</a>\n";
                    }
                    $attachLine .= "\n";
                }
            }

            $startMsg = "⚡ <b>MEMULAI PEKERJAAN</b>\n"
                      . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                      . "🆔 <b>ID Tugas:</b> <code>{$task['id']}</code>\n"
                      . "📁 <b>Proyek:</b> <code>$pName</code>$pTag\n"
                      . "🕒 <b>Waktu Mulai:</b> " . date('d-m-Y H:i:s') . " $tzAbbr\n"
                      . "👤 <b>Pemohon:</b> " . htmlspecialchars($task['sender']) . "\n\n"
                      . "🤖 <b>Eksekutor:</b> <code>" . htmlspecialchars(!empty($task['assigned_agent']) ? $task['assigned_agent'] : 'Antigravity AI Agent') . "</code>\n\n"
                      . $attachLine
                      . "📝 <b>Perintah / Instruksi:</b>\n<code>$instSnippet</code>\n\n"
                      . "🚀 <b>Status:</b> <code>[IN_PROGRESS]</code>\n"
                      . "📌 <b>Catatan:</b> " . htmlspecialchars($notes) . "\n"
                      . "🤖 <i>AI Agent di VS Code sedang mengeksekusi tugas ini sampai tuntas 100%...</i>";

            sendTelegramWithMedia(
                $task['bot_token'],
                $task['chat_id'],
                $startMsg,
                $task['attachment_type'] ?? '',
                $task['attachment_url'] ?? '',
                ''
            );
            $db->prepare("UPDATE tasks SET start_notified = 1 WHERE id = ?")->execute([$taskId]);
            $notifiedByServer = true;
        }

        $parsedAttachments = parseTaskAttachments($task);
        echo json_encode([
            'ok' => true,
            'id' => $taskId,
            'status' => 'IN_PROGRESS',
            'instruction' => $task['instruction'] ?? '',
            'sender' => $task['sender'] ?? 'Telegram User',
            'agent' => !empty($task['assigned_agent']) ? $task['assigned_agent'] : 'Antigravity AI Agent',
            'attachment_url' => $task['attachment_url'] ?? ($parsedAttachments[0]['url'] ?? ''),
            'attachment_type' => $task['attachment_type'] ?? ($parsedAttachments[0]['type'] ?? ''),
            'attachment_name' => $task['attachment_name'] ?? ($parsedAttachments[0]['name'] ?? ''),
            'attachments' => $parsedAttachments,
            'created_at' => $task['created_at'] ?? '',
            'start_notified' => ($alreadyNotified || $notifiedByServer) ? 1 : 0,
            'already_notified' => $alreadyNotified ? true : false,
            'notified_now' => $notifiedByServer
        ]);
        exit;
    }

    // Ambil Data Spesifik Satu Tugas (Selain cari di lokal, cari juga di remote)
    if ($action === 'api_get_task') {
        $taskId = resolveTaskId($db, trim($_GET['id'] ?? ($_POST['id'] ?? '')));
        if (!$taskId) {
            echo json_encode(['ok' => false, 'error' => 'Parameter id tugas wajib diisi']);
            exit;
        }
        $cleanId = ltrim($taskId, '#');
        $stmtT = $db->prepare("SELECT t.*, p.name as project_name, p.code as project_code FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE (t.id = ? OR t.id = ? OR t.id LIKE ?) AND t.user_id = ? LIMIT 1");
        $stmtT->execute([$taskId, '#' . $cleanId, '%' . $cleanId . '%', $user['id']]);
        $taskRow = $stmtT->fetch(PDO::FETCH_ASSOC);
        if ($taskRow) {
            $parsedAttachments = parseTaskAttachments($taskRow);
            echo json_encode([
                'ok' => true,
                'task' => [
                    'id' => $taskRow['id'],
                    'instruction' => $taskRow['instruction'],
                    'sender' => $taskRow['sender'],
                    'status' => $taskRow['status'],
                    'agent' => !empty($taskRow['assigned_agent']) ? $taskRow['assigned_agent'] : 'Antigravity AI Agent',
                    'notes' => $taskRow['notes'],
                    'attachment_url' => $taskRow['attachment_url'] ?: ($parsedAttachments[0]['url'] ?? ''),
                    'attachment_type' => $taskRow['attachment_type'] ?: ($parsedAttachments[0]['type'] ?? ''),
                    'attachment_name' => $taskRow['attachment_name'] ?: ($parsedAttachments[0]['name'] ?? ''),
                    'attachments' => $parsedAttachments,
                    'created_at' => $taskRow['created_at'],
                    'updated_at' => $taskRow['updated_at'],
                    'project_name' => $taskRow['project_name'],
                    'project_code' => $taskRow['project_code']
                ]
            ]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Tugas tidak ditemukan di server remote']);
        }
        exit;
    }

    // Tandai Tugas Selesai / Gagal
    if ($action === 'api_done' || $action === 'api_failed') {
        $taskId = resolveTaskId($db, $_GET['id'] ?? ($_POST['id'] ?? ''));
        $result = trim($_POST['result'] ?? ($_GET['result'] ?? ''));
        $notes = trim($_POST['notes'] ?? ($_GET['notes'] ?? ''));
        $cmdParam = trim($_POST['command'] ?? ($_GET['command'] ?? ''));
        if (!$result && $notes) $result = $notes;
        if (!$notes && $result) $notes = $result;
        if (!$result) $result = ($action === 'api_done') ? 'Tugas selesai 100%' : 'Gagal';
        if (!$notes) $notes = $result;

        $stat = ($action === 'api_done') ? 'DONE' : 'FAILED';

        $stmtT = $db->prepare("SELECT t.*, p.bot_token, p.chat_id, p.name as project_name, p.timezone as project_timezone FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.id = ? AND t.user_id = ?");
        $stmtT->execute([$taskId, $user['id']]);
        $task = $stmtT->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            echo json_encode(['error' => 'Tugas tidak ditemukan']);
            exit;
        }

        $effectiveTz = !empty($task['project_timezone']) ? $task['project_timezone'] : (!empty($user['timezone']) ? $user['timezone'] : 'Asia/Jakarta');
        applyTimezone($effectiveTz);
        $tzAbbr = getTimezoneAbbr($effectiveTz);
        $now = date('Y-m-d H:i:s');

        // Hitung Durasi Pengerjaan Tugas
        $durationText = trim($_POST['duration'] ?? ($_GET['duration'] ?? ''));
        if (!$durationText && !empty($task['created_at'])) {
            $createdTs = strtotime($task['created_at']);
            $nowTs = strtotime($now);
            $diffSec = max(0, $nowTs - $createdTs);
            $h = floor($diffSec / 3600);
            $m = floor(($diffSec % 3600) / 60);
            $s = $diffSec % 60;
            if ($h > 0) $durationText = "{$h} jam {$m} menit {$s} detik";
            elseif ($m > 0) $durationText = "{$m} menit {$s} detik";
            else $durationText = "{$s} detik";
        }

        if ($cmdParam) {
            $upd = $db->prepare("UPDATE tasks SET status = ?, updated_at = ?, notes = ?, result = ?, command = ? WHERE id = ? AND user_id = ?");
            safeDbExecute($upd, [$stat, $now, $notes, $result, $cmdParam, $taskId, $user['id']]);
        } else {
            $upd = $db->prepare("UPDATE tasks SET status = ?, updated_at = ?, notes = ?, result = ? WHERE id = ? AND user_id = ?");
            safeDbExecute($upd, [$stat, $now, $notes, $result, $taskId, $user['id']]);
        }

        // Kirim Notifikasi ke Bot Telegram Proyek (Hanya jika TIDAK DIBLOKIR oleh Superadmin)
        $botToken = !empty($task['bot_token']) ? $task['bot_token'] : $user['bot_token'];
        $chatId = !empty($task['chat_id']) ? $task['chat_id'] : $user['chat_id'];
        $pName = !empty($task['project_name']) ? $task['project_name'] : $user['project_name'];
        $alreadyDoneNotified = !empty($task['done_notified']);
        $notifiedByServer = false;

        // Cegah notifikasi ganda ke Telegram jika status DONE/FAILED sudah pernah dikirim
        if ($botToken && $chatId && !$alreadyDoneNotified && empty($_REQUEST['skip_notify'])) {
            if (empty($user['block_notify'])) {
                $emoji = ($stat === 'DONE') ? '✅' : '🚨';
                
                // Analisis Cerdas & Solusi Otomatis jika Tugas Ada Kendala
                $solusiSection = "";
                if ($stat === 'FAILED') {
                    $combinedErr = strtolower($result . ' ' . $notes);
                    $solusiList = [];
                    if (strpos($combinedErr, 'syntax') !== false || strpos($combinedErr, 'parse') !== false || strpos($combinedErr, 'unexpected') !== false) {
                        $solusiList[] = "Periksa sintaks kode dan pastikan penutupan kurung, titik-koma, atau petik valid.";
                    }
                    if (strpos($combinedErr, 'not found') !== false || strpos($combinedErr, 'tidak ditemukan') !== false || strpos($combinedErr, 'enoent') !== false) {
                        $solusiList[] = "Pastikan path berkas, direktori, atau package dependensi tujuan sudah terpasang.";
                    }
                    if (strpos($combinedErr, 'permission') !== false || strpos($combinedErr, 'denied') !== false || strpos($combinedErr, 'eacces') !== false) {
                        $solusiList[] = "Buka izin tulis berkas/folder atau jalankan VS Code / terminal dengan hak akses cukup.";
                    }
                    if (strpos($combinedErr, 'timeout') !== false || strpos($combinedErr, 'network') !== false || strpos($combinedErr, 'connection') !== false) {
                        $solusiList[] = "Periksa stabilitas koneksi internet atau server lalu coba kirim ulang instruksi.";
                    }
                    if (empty($solusiList)) {
                        $solusiList[] = "Reply pesan notifikasi ini dengan instruksi tambahan (misal: '/task coba cara lain...').";
                        $solusiList[] = "Periksa detail log eksekusi di VS Code pada panel Output 'Tele-vs-Code'.";
                    }
                    $solusiSection = "💡 <b>Solusi & Rekomendasi Tindakan:</b>\n";
                    foreach ($solusiList as $s) {
                        $solusiSection .= "• " . htmlspecialchars($s) . "\n";
                    }
                    $solusiSection .= "\n🔄 <i>Ketik /task atau reply pesan ini untuk mencoba kembali dengan instruksi baru.</i>\n";
                }

                $durationLine = ($stat === 'DONE' && $durationText) ? "⏱️ <b>Durasi Pengerjaan:</b> <code>$durationText</code>\n" : "";
                $text = "<b>$emoji Tugas $taskId: " . ($stat === 'DONE' ? 'SELESAI' : 'KENDALA') . "</b>\n\n"
                      . "📁 <b>Proyek:</b> <code>" . htmlspecialchars($pName) . "</code>\n"
                      . "🕒 <b>Waktu Selesai:</b> $now $tzAbbr\n"
                      . $durationLine
                      . "🤖 <b>Eksekutor:</b> <code>" . htmlspecialchars(!empty($task['assigned_agent']) ? $task['assigned_agent'] : 'Antigravity AI Agent') . "</code>\n"
                      . "📋 <b>" . ($stat === 'DONE' ? 'Hasil Pekerjaan:' : 'Penyebab Kendala:') . "</b>\n" . htmlspecialchars($result) . "\n\n"
                      . $solusiSection
                      . "🤖 <i>Dikirim via tele-vs-code</i>";

                $resTel = callTelegram($botToken, 'sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML'
                ]);
                if ($resTel && !empty($resTel['ok'])) {
                    $notifiedByServer = true;
                    try {
                        $db->prepare("UPDATE tasks SET done_notified = 1 WHERE id = ?")->execute([$taskId]);
                    } catch (Exception $e) {}
                }
            }
        }

        echo json_encode([
            'ok' => true,
            'message' => "Tugas $taskId diperbarui ke $stat",
            'result' => $result,
            'notified_by_server' => ($alreadyDoneNotified || $notifiedByServer),
            'already_notified' => $alreadyDoneNotified
        ]);
        exit;
    }

    // Status Ringkasan
    if ($action === 'api_status') {
        $prjFilter = $_GET['project_id'] ?? '';
        $sql = "SELECT status, count(*) as c FROM tasks WHERE user_id = ?";
        $params = [$user['id']];
        if ($prjFilter) {
            $sql .= " AND project_id = ?";
            $params[] = (int)$prjFilter;
        }
        $sql .= " GROUP BY status";
        $stmtT = $db->prepare($sql);
        $stmtT->execute($params);
        $counts = ['PENDING' => 0, 'IN_PROGRESS' => 0, 'DONE' => 0, 'FAILED' => 0];
        while ($row = $stmtT->fetch(PDO::FETCH_ASSOC)) {
            $counts[$row['status']] = $row['c'];
        }
        echo json_encode(['ok' => true, 'summary' => $counts]);
        exit;
    }
}

// ------------------------------------------------------------------------------
