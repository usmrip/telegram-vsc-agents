<?php
// includes/data.php - Dashboard Data Queries (Projects, Tasks, Whitelist, Stats)
if (!defined('TELE_VS_CODE')) exit;

    // Load Projects milik User
    $stmtP = $db->prepare("SELECT * FROM projects WHERE user_id = ? ORDER BY id ASC");
    $stmtP->execute([$currentUser['id']]);
    $projects = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    // Jika Superadmin sedang mengakses atau menguji proyek spesifik yang bukan miliknya
    if ($isSuperAdmin && !empty($_GET['id'])) {
        $extraPid = (int)$_GET['id'];
        $existsInList = false;
        foreach ($projects as $p) {
            if ($p['id'] == $extraPid) { $existsInList = true; break; }
        }
        if (!$existsInList) {
            $stmtExtra = $db->prepare("SELECT * FROM projects WHERE id = ?");
            $stmtExtra->execute([$extraPid]);
            $extraP = $stmtExtra->fetch(PDO::FETCH_ASSOC);
            if ($extraP) {
                $projects[] = $extraP;
            }
        }
    }

    // Cek kelayakan tombol donasi:
    // Khusus untuk yang login, memiliki proyek, dan proyek sudah dilayani oleh dan sukses notifikasi
    $userSuccessCount = 0;
    if (!empty($projects)) {
        $stmtDone = $db->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND (status = 'DONE' OR status = 'done')");
        $stmtDone->execute([$currentUser['id']]);
        $userSuccessCount = (int)$stmtDone->fetchColumn();
        if ($userSuccessCount === 0) {
            foreach ($projects as $p) {
                if (!empty($p['last_seen'])) {
                    $userSuccessCount = 1;
                    break;
                }
            }
        }
    }
    $canShowDonation = (!empty($currentUser) && !empty($projects) && $userSuccessCount > 0);

    // Load Whitelist Users milik User (Private Chat)
    $stmtW = $db->prepare("SELECT w.*, p.name as project_name FROM bot_whitelist w LEFT JOIN projects p ON w.project_id = p.id WHERE w.user_id = ? ORDER BY w.id DESC");
    $stmtW->execute([$currentUser['id']]);
    $whitelistUsers = $stmtW->fetchAll(PDO::FETCH_ASSOC);

    // Filter Tasks per Project & Pagination (Default 10)
    $filterProjectRaw = $_GET['filter_project'] ?? '';
    $filterOnlyIde = ($filterProjectRaw === 'ide');
    $filterProjectId = is_numeric($filterProjectRaw) ? (int)$filterProjectRaw : 0;
    if (!$filterProjectId && $filterProjectRaw && $filterProjectRaw !== 'all' && !$filterOnlyIde) {
        $cleanRaw = strtolower(ltrim(trim($filterProjectRaw), '#/'));
        $stmtFindP = $db->prepare("SELECT id FROM projects WHERE user_id = ? AND (project_key = ? OR LOWER(code) = ? OR LOWER(name) = ?) LIMIT 1");
        $stmtFindP->execute([$currentUser['id'], $filterProjectRaw, $cleanRaw, $cleanRaw]);
        $filterProjectId = (int)$stmtFindP->fetchColumn();
    }
    $filterStatus = $_GET['filter_status'] ?? '';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? max(5, min(100, (int)$_GET['per_page'])) : 10; // Default 10!

    $countSql = "SELECT COUNT(*) FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.user_id = ?";
    $taskSql = "SELECT t.*, p.name as project_name, p.code as project_code, p.timezone as project_timezone FROM tasks t LEFT JOIN projects p ON t.project_id = p.id WHERE t.user_id = ?";
    $taskParams = [$currentUser['id']];

    if ($filterOnlyIde) {
        $countSql .= " AND (t.sender LIKE '%IDE%' OR t.sender LIKE '%VS Code%' OR t.command = 'tele-task add')";
        $taskSql .= " AND (t.sender LIKE '%IDE%' OR t.sender LIKE '%VS Code%' OR t.command = 'tele-task add')";
    } elseif ($filterProjectId > 0) {
        $countSql .= " AND t.project_id = ?";
        $taskSql .= " AND t.project_id = ?";
        $taskParams[] = $filterProjectId;
    }
    if ($filterStatus && in_array($filterStatus, ['PENDING', 'IN_PROGRESS', 'DONE', 'FAILED'])) {
        $countSql .= " AND t.status = ?";
        $taskSql .= " AND t.status = ?";
        $taskParams[] = $filterStatus;
    }

    $stmtCountTotal = $db->prepare($countSql);
    $stmtCountTotal->execute($taskParams);
    $totalTasksCount = (int)$stmtCountTotal->fetchColumn();

    $totalPages = max(1, (int)ceil($totalTasksCount / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $taskSql .= " ORDER BY t.created_at DESC LIMIT $perPage OFFSET $offset";

    $stmtTasks = $db->prepare($taskSql);
    $stmtTasks->execute($taskParams);
    $myTasks = $stmtTasks->fetchAll(PDO::FETCH_ASSOC);

    // Hitung Statistik Tugas User (Parameterized Query - Safe from SQL Injection)
    $countSql = "SELECT status, count(*) as c FROM tasks WHERE user_id = ?";
    $countParams = [(int)$currentUser['id']];
    if ($filterProjectId > 0) {
        $countSql .= " AND project_id = ?";
        $countParams[] = (int)$filterProjectId;
    }
    $countSql .= " GROUP BY status";
    $stmtCount = $db->prepare($countSql);
    $stmtCount->execute($countParams);
    $taskStats = ['TOTAL' => 0, 'PENDING' => 0, 'IN_PROGRESS' => 0, 'DONE' => 0, 'FAILED' => 0];
    while ($sc = $stmtCount->fetch(PDO::FETCH_ASSOC)) {
        $taskStats[$sc['status']] = $sc['c'];
        $taskStats['TOTAL'] += $sc['c'];
    }

    // Data Khusus Superadmin (Tab Admin)
    $allUsers = [];
    $totalUsersCount = 0;
    $pageUser = 1;
    $perPageUser = 10;
    $totalPagesUser = 1;
    $offsetUser = 0;

    $adminStats = ['total_users' => 0, 'blocked_users' => 0, 'blocked_notify' => 0, 'total_projects' => 0, 'total_tasks' => 0];
    $analyticsStats = [
        'total_hits' => 0,
        'unique_ips' => 0,
        'countries_count' => 0,
        'cities_count' => 0,
        'mobile_hits' => 0,
        'desktop_hits' => 0
    ];
    $topCountries = [];
    $recentVisitors = [];
    $totalVisCount = 0;
    $pageVis = 1;
    $perPageVis = 20;
    $totalPagesVis = 1;
    $offsetVis = 0;

    if ($isSuperAdmin) {
        try {
            $totalUsersCount = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $adminStats['total_users'] = $totalUsersCount;
            $adminStats['blocked_users'] = (int)$db->query("SELECT COUNT(*) FROM users WHERE is_blocked = 1")->fetchColumn();
            $adminStats['blocked_notify'] = (int)$db->query("SELECT COUNT(*) FROM users WHERE block_notify = 1")->fetchColumn();
            $adminStats['total_projects'] = (int)$db->query("SELECT COUNT(*) FROM projects")->fetchColumn();
            $adminStats['total_tasks'] = (int)$db->query("SELECT COUNT(*) FROM tasks")->fetchColumn();

            $pageUser = max(1, (int)($_GET['page_user'] ?? 1));
            $perPageUser = max(5, min(100, (int)($_GET['per_page_user'] ?? 10)));
            $totalPagesUser = max(1, (int)ceil($totalUsersCount / $perPageUser));
            if ($pageUser > $totalPagesUser && $totalUsersCount > 0) $pageUser = $totalPagesUser;
            $offsetUser = ($pageUser - 1) * $perPageUser;

            $stmtUsers = $db->query("
                SELECT u.*, 
                       COUNT(DISTINCT p.id) as project_count, 
                       COUNT(DISTINCT t.id) as task_count 
                FROM users u 
                LEFT JOIN projects p ON u.id = p.user_id 
                LEFT JOIN tasks t ON u.id = t.user_id 
                GROUP BY u.id 
                ORDER BY u.id ASC 
                LIMIT $perPageUser OFFSET $offsetUser
            ");
            $allUsers = $stmtUsers ? $stmtUsers->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Exception $e) {}

        // Hitung Statistik Analitik Pengunjung & Lokasi Geografis
        try {
            $totalVisCount = (int)$db->query("SELECT COUNT(*) FROM visitor_analytics")->fetchColumn();
            $analyticsStats['total_hits'] = $totalVisCount;
            $analyticsStats['unique_ips'] = (int)$db->query("SELECT COUNT(DISTINCT ip) FROM visitor_analytics")->fetchColumn();
            $analyticsStats['countries_count'] = (int)$db->query("SELECT COUNT(DISTINCT country) FROM visitor_analytics WHERE country != '' AND country != 'Unknown' AND country != 'Localhost'")->fetchColumn();
            $analyticsStats['cities_count'] = (int)$db->query("SELECT COUNT(DISTINCT city) FROM visitor_analytics WHERE city != '' AND city != 'Local Machine'")->fetchColumn();
            $analyticsStats['mobile_hits'] = (int)$db->query("SELECT COUNT(*) FROM visitor_analytics WHERE device_type IN ('Mobile', 'Tablet')")->fetchColumn();
            $analyticsStats['desktop_hits'] = (int)$db->query("SELECT COUNT(*) FROM visitor_analytics WHERE device_type NOT IN ('Mobile', 'Tablet')")->fetchColumn();

            $topCountries = $db->query("
                SELECT country, country_code, COUNT(*) as hits 
                FROM visitor_analytics 
                WHERE country != '' AND country != 'Unknown' AND country != 'Localhost'
                GROUP BY country 
                ORDER BY hits DESC 
                LIMIT 5
            ")->fetchAll(PDO::FETCH_ASSOC);

            $pageVis = max(1, (int)($_GET['page_vis'] ?? 1));
            $perPageVis = max(10, min(100, (int)($_GET['per_page_vis'] ?? 20)));
            $totalPagesVis = max(1, (int)ceil($totalVisCount / $perPageVis));
            if ($pageVis > $totalPagesVis && $totalVisCount > 0) $pageVis = $totalPagesVis;
            $offsetVis = ($pageVis - 1) * $perPageVis;

            $recentVisitors = $db->query("
                SELECT * FROM visitor_analytics 
                ORDER BY id DESC 
                LIMIT $perPageVis OFFSET $offsetVis
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }
