<?php if (!defined('TELE_VS_CODE')) exit; ?>
                    <?php if (empty($myTasks)): ?>
                        <div style="text-align: center; padding: 48px 16px; color: var(--text-muted);">
                            <div style="width: 44px; height: 44px; border-radius: 50%; background: var(--bg-surface-inset); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px; color: var(--text-dim);">
                                <?= renderSvg('tasks', 20) ?>
                            </div>
                            <p style="font-size: 13.5px; font-weight: 400; color: var(--text-secondary);">Belum ada tugas di antrean.</p>
                            <p style="font-size: 12px; margin-top: 4px;">Kirim pesan <code>/task &lt;instruksi&gt;</code> di Telegram atau klik <b>Tambah Manual</b> di atas.</p>
                        </div>
                    <?php else: ?>
                        <div class="task-cards-list" id="taskCardsContainer">
                            <?php foreach ($myTasks as $t): ?>
                                <?php 
                                    $pTz = !empty($t['project_timezone']) ? $t['project_timezone'] : (!empty($currentUser['timezone']) ? $currentUser['timezone'] : 'Asia/Jakarta');
                                    $tAbbr = getTimezoneAbbr($pTz);
                                    $resText = !empty($t['result']) ? $t['result'] : (!empty($t['notes']) && $t['status'] === 'DONE' ? $t['notes'] : '');
                                    
                                    // Deteksi dan bersihkan seluruh lampiran (mendukung multi-file dari attachments_json, kolom DB, maupun header instruksi)
                                    $taskAttachments = parseTaskAttachments($t);
                                    $t['attachments'] = $taskAttachments;
                                    $attCount = count($taskAttachments);
                                    $attUrl = $attCount > 0 ? $taskAttachments[0]['url'] : '';
                                    $attType = $attCount > 0 ? strtolower($taskAttachments[0]['type']) : '';
                                    $attName = $attCount > 0 ? $taskAttachments[0]['name'] : '';
                                    $cleanInstruction = stripAttachmentHeadersFromInstruction($t['instruction']);
                                    if ($cleanInstruction === '' && !empty($t['instruction'])) {
                                        $cleanInstruction = $t['instruction'];
                                    }

                                    // Tentukan teks command yang ditampilkan di Baris 2
                                    $cmdDisplay = !empty($t['command']) ? trim($t['command']) : '';
                                    if (!$cmdDisplay) {
                                        $pCode = !empty($t['project_code']) ? strtolower($t['project_code']) : 'a';
                                        if ($t['sender'] === 'IDE Chat' || $t['sender'] === 'VS Code') {
                                            $cmdDisplay = 'tele-task add --sender "' . $t['sender'] . '"';
                                        } elseif (!empty($cleanInstruction) && preg_match('/^\/([a-zA-Z0-9_-]+)/', $cleanInstruction, $mCmd)) {
                                            $cmdDisplay = '/' . $mCmd[1];
                                        } elseif (!empty($cleanInstruction) && preg_match('/^#([a-zA-Z0-9_-]+)/', $cleanInstruction, $mCmd)) {
                                            $cmdDisplay = '#' . $mCmd[1];
                                        } else {
                                            $cmdDisplay = '#' . $pCode;
                                        }
                                    }
                                    $isPhoto = ($attType === 'photo' || preg_match('/\.(jpg|jpeg|png|webp|gif)($|\?)/i', $attUrl));
                                    $isVideo = ($attType === 'video' || preg_match('/\.(mp4|mov|mkv|webm)($|\?)/i', $attUrl));
                                ?>
                                <!-- Card Akordion Default Minimized (Class: collapsed) -->
                                <div class="task-card collapsed" id="task-<?= htmlspecialchars($t['id']) ?>">
                                    <!-- ============================================================== -->
                                    <!-- BARIS 1: HEADER AKORDION (ID, WAKTU, PROYEK, SENDER, AKSI)     -->
                                    <!-- ============================================================== -->
                                    <div class="task-card-row-meta" onclick="toggleTaskAccordion('<?= htmlspecialchars($t['id']) ?>', event)" style="cursor: pointer;">
                                        <div class="task-meta-left">
                                            <code class="task-id-badge"><?= htmlspecialchars($t['id']) ?></code>
                                            <span class="task-time-text">
                                                <?= htmlspecialchars($t['created_at']) ?> <b><?= $tAbbr ?></b>
                                            </span>
                                            <span class="badge badge-project">
                                                <?= htmlspecialchars($t['project_name'] ?: 'Tanpa Proyek') ?>
                                            </span>
                                            <?php 
                                            $isIdeSender = (stripos($t['sender'], 'IDE') !== false || stripos($t['sender'], 'VS Code') !== false || ($t['command'] ?? '') === 'tele-task add');
                                            ?>
                                            <?php if ($isIdeSender): ?>
                                                <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.4); font-weight: 600; display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; border-radius: 6px;" title="Perintah dibuat dari IDE Chat / VS Code">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                                                    <span><?= htmlspecialchars($t['sender']) ?></span>
                                                </span>
                                            <?php else: ?>
                                                <span class="task-sender-badge">
                                                    <?= renderSvg('user', 12) ?>
                                                    <span><?= htmlspecialchars($t['sender']) ?></span>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($attCount > 1): ?>
                                                <span class="badge-media-indicator" style="background: rgba(56,189,248,0.14); color:#7dd3fc; border-color: rgba(56,189,248,0.35);" title="<?= $attCount ?> File Terlampir">
                                                    <?= renderSvg('file', 11) ?> <?= $attCount ?> Lampiran
                                                </span>
                                            <?php elseif ($attUrl && $isPhoto): ?>
                                                <span class="badge-media-indicator" title="Ada Lampiran Foto">
                                                    <?= renderSvg('photo', 11) ?> Foto
                                                </span>
                                            <?php elseif ($attUrl && $isVideo): ?>
                                                <span class="badge-media-indicator" style="background: rgba(168,85,247,0.12); color:#d8b4fe; border-color: rgba(168,85,247,0.3);" title="Ada Lampiran Video">
                                                    <?= renderSvg('video', 11) ?> Video
                                                </span>
                                            <?php elseif ($attUrl): ?>
                                                <span class="badge-media-indicator" style="background: rgba(16,185,129,0.12); color:#86efac; border-color: rgba(16,185,129,0.3);" title="Ada Lampiran File">
                                                    <?= renderSvg('file', 11) ?> File
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="task-meta-right">
                                            <?php if ($t['status'] === 'PENDING'): ?>
                                                <span class="badge badge-pending">PENDING</span>
                                            <?php elseif ($t['status'] === 'IN_PROGRESS'): ?>
                                                <span class="badge badge-progress">PROGRESS</span>
                                            <?php elseif ($t['status'] === 'DONE'): ?>
                                                <?php
                                                $durBadge = '';
                                                if (!empty($t['created_at']) && !empty($t['updated_at'])) {
                                                    $sec = max(0, strtotime($t['updated_at']) - strtotime($t['created_at']));
                                                    $h = floor($sec / 3600);
                                                    $m = floor(($sec % 3600) / 60);
                                                    $s = $sec % 60;
                                                    $durBadge = ($h > 0) ? "{$h}j {$m}m" : (($m > 0) ? "{$m}m {$s}s" : "{$s}s");
                                                }
                                                $agentName = !empty($t['assigned_agent']) ? $t['assigned_agent'] : 'Antigravity AI Agent';
                                                ?>
                                                <span class="badge badge-agent" style="background: rgba(168, 85, 247, 0.12); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.32); font-weight: 500; display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; border-radius: 6px; font-size: 11px;" title="Dikerjakan oleh: <?= htmlspecialchars($agentName) ?>">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/><line x1="8" y1="16" x2="8" y2="16"/><line x1="16" y1="16" x2="16" y2="16"/></svg>
                                                    <span><?= htmlspecialchars($agentName) ?></span>
                                                </span>
                                                <span class="badge badge-done" title="Durasi pengerjaan: <?= $durBadge ?>">DONE <?= $durBadge ? "($durBadge)" : "" ?></span>
                                            <?php else: ?>
                                                <span class="badge badge-failed"><?= htmlspecialchars($t['status']) ?></span>
                                            <?php endif; ?>

                                            <div class="task-card-actions">
                                                <button type="button" class="btn btn-icon btn-ghost" style="color: var(--accent-info);" onclick="openTaskDetail(<?= htmlspecialchars(json_encode($t, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($tAbbr), ENT_QUOTES, 'UTF-8') ?>)" title="Lihat & Edit Hasil">
                                                    <?= renderSvg('eye', 14) ?>
                                                </button>
                                                <?php if ($t['status'] !== 'DONE'): ?>
                                                    <button type="button" class="btn btn-icon btn-ghost" style="color: var(--accent-success);" onclick="promptMarkDone('<?= htmlspecialchars($t['id']) ?>')" title="Tandai Selesai (DONE)">
                                                        <?= renderSvg('check', 14) ?>
                                                    </button>
                                                <?php endif; ?>
                                                <a href="?action=delete_task&id=<?= urlencode($t['id']) ?>&csrf=<?= getCsrfToken() ?>" onclick="return confirm('Hapus tugas <?= htmlspecialchars($t['id']) ?> dari antrean?')" class="btn btn-icon btn-ghost" style="color: var(--accent-danger);" title="Hapus Tugas">
                                                    <?= renderSvg('trash', 14) ?>
                                                </a>
                                                <button type="button" class="btn btn-icon btn-ghost task-accordion-toggle" onclick="toggleTaskAccordion('<?= htmlspecialchars($t['id']) ?>', event)" title="Buka/Tutup Rincian">
                                                    <svg class="chevron-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="6 9 12 15 18 9"/></svg>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- PREVIEW 1-BARIS SAAT MINIMIZED -->
                                    <div class="task-card-preview" onclick="toggleTaskAccordion('<?= htmlspecialchars($t['id']) ?>', event)">
                                        <?= htmlspecialchars(mb_strimwidth($cleanInstruction, 0, 110, '...')) ?>
                                    </div>

                                    <!-- ============================================================== -->
                                    <!-- BODY AKORDION (MUNCUL SAAT DI-EXPAND / DIKLIK)                  -->
                                    <!-- ============================================================== -->
                                    <div class="task-card-body" id="body-task-<?= htmlspecialchars($t['id']) ?>">
                                        <!-- BARIS 2: INSTRUKSI LENGKAP -->
                                        <div class="task-card-row-body">
                                            <div class="task-card-instruction">
                                                <?= nl2br(htmlspecialchars($cleanInstruction)) ?>
                                            </div>

                                            <!-- TAMPILAN LAMPIRAN FOTO / VIDEO / AUDIO / DOKUMEN (MULTI-FILE SUPPORT) -->
                                            <?php if (!empty($taskAttachments)): ?>
                                                <div class="task-card-media-box" style="display: flex; flex-direction: column; gap: 10px;">
                                                    <?php if (count($taskAttachments) > 1): ?>
                                                        <div style="font-size: 11.5px; font-weight: 600; color: var(--accent-info); display: flex; align-items: center; gap: 6px; padding-bottom: 4px; border-bottom: 1px solid var(--border-subtle);">
                                                            <?= renderSvg('file', 12) ?>
                                                            <span>Daftar Lampiran Tugas (<?= count($taskAttachments) ?> File):</span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-start;">
                                                        <?php foreach ($taskAttachments as $idxAtt => $itemAtt):
                                                            $itUrl = $itemAtt['url'] ?? '';
                                                            if (!$itUrl) continue;
                                                            $itType = strtolower($itemAtt['type'] ?? 'document');
                                                            $itName = $itemAtt['name'] ?: basename(parse_url($itUrl, PHP_URL_PATH) ?: ('file_' . ($idxAtt + 1)));
                                                            $itIsPhoto = ($itType === 'photo' || preg_match('/\.(jpg|jpeg|png|webp|gif|bmp|svg)($|\?)/i', $itUrl));
                                                            $itIsVideo = ($itType === 'video' || preg_match('/\.(mp4|mov|mkv|webm)($|\?)/i', $itUrl));
                                                            $itIsAudio = ($itType === 'voice' || $itType === 'audio' || preg_match('/\.(ogg|oga|mp3|wav|m4a)($|\?)/i', $itUrl));
                                                        ?>
                                                            <?php if ($itIsPhoto): ?>
                                                                <div class="task-photo-wrap" style="max-width: 260px;">
                                                                    <a href="<?= htmlspecialchars($itUrl) ?>" target="_blank" class="task-photo-link" onclick="openImageModal(event, '<?= htmlspecialchars($itUrl) ?>', '<?= htmlspecialchars(addslashes($itName ?: 'Lampiran Foto')) ?>')">
                                                                        <img src="<?= htmlspecialchars($itUrl) ?>" alt="Lampiran Foto" class="task-photo-img" loading="lazy" onerror="this.parentElement.style.display='none';">
                                                                        <div class="task-photo-overlay">
                                                                            <?= renderSvg('eye', 13) ?> <span>Perbesar Foto</span>
                                                                        </div>
                                                                    </a>
                                                                    <div class="task-media-caption" style="gap: 8px;">
                                                                        <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 165px;" title="<?= htmlspecialchars($itName) ?>"><?= renderSvg('photo', 12) ?> <?= htmlspecialchars($itName ?: 'photo.jpg') ?></span>
                                                                        <a href="<?= htmlspecialchars($itUrl) ?>" target="_blank" download class="btn btn-secondary btn-xs" style="padding: 2px 8px; font-size: 11px; flex-shrink: 0;">
                                                                            <?= renderSvg('download', 11) ?> Unduh
                                                                        </a>
                                                                    </div>
                                                                </div>
                                                            <?php elseif ($itIsVideo): ?>
                                                                <div class="task-video-wrap" style="max-width: 360px;">
                                                                    <video controls preload="metadata" style="max-width: 100%; max-height: 220px; border-radius: var(--radius-sm); border: 1px solid var(--border-medium); background: #000;">
                                                                        <source src="<?= htmlspecialchars($itUrl) ?>">
                                                                        Browser Anda tidak mendukung pemutar video HTML5.
                                                                    </video>
                                                                    <div class="task-media-caption" style="margin-top: 4px; gap: 8px;">
                                                                        <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 240px;" title="<?= htmlspecialchars($itName) ?>"><?= renderSvg('video', 12) ?> <?= htmlspecialchars($itName ?: 'video.mp4') ?></span>
                                                                        <a href="<?= htmlspecialchars($itUrl) ?>" target="_blank" download class="btn btn-secondary btn-xs" style="padding: 2px 8px; font-size: 11px; flex-shrink: 0;">
                                                                            <?= renderSvg('download', 11) ?> Unduh
                                                                        </a>
                                                                    </div>
                                                                </div>
                                                            <?php elseif ($itIsAudio): ?>
                                                                <div class="task-audio-wrap" style="width: 100%; max-width: 420px; padding: 6px 0;">
                                                                    <audio controls preload="metadata" style="width: 100%; height: 38px; border-radius: var(--radius-sm);">
                                                                        <source src="<?= htmlspecialchars($itUrl) ?>">
                                                                        Browser Anda tidak mendukung pemutar audio.
                                                                    </audio>
                                                                    <div class="task-media-caption" style="margin-top: 6px; gap: 8px;">
                                                                        <span><?= renderSvg('play', 11) ?> Audio (<?= htmlspecialchars($itName ?: 'audio') ?>)</span>
                                                                        <a href="<?= htmlspecialchars($itUrl) ?>" target="_blank" download class="btn btn-secondary btn-xs" style="padding: 2px 8px; font-size: 11px;">
                                                                            <?= renderSvg('download', 11) ?> Unduh Audio
                                                                        </a>
                                                                    </div>
                                                                </div>
                                                            <?php else: ?>
                                                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; width: 100%; padding: 6px 10px; background: var(--bg-base); border: 1px solid var(--border-subtle); border-radius: var(--radius-sm);">
                                                                    <span style="font-size: 12.5px; color: var(--text-secondary); display: flex; align-items: center; gap: 6px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                                        <?= renderSvg('file', 14) ?> <?= htmlspecialchars($itName ?: 'Dokumen Terlampir') ?>
                                                                    </span>
                                                                    <a href="<?= htmlspecialchars($itUrl) ?>" target="_blank" download class="btn btn-secondary btn-xs" style="flex-shrink: 0;">
                                                                        <?= renderSvg('download', 11) ?> Unduh Dokumen
                                                                    </a>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <div class="task-card-command">
                                                <span class="task-cmd-prefix">
                                                    <?= renderSvg('terminal', 12) ?>
                                                    <span>Command:</span>
                                                </span>
                                                <code class="task-cmd-code"><?= htmlspecialchars($cmdDisplay) ?></code>
                                                <button type="button" class="btn btn-icon btn-ghost task-cmd-copy" onclick="copyText('<?= htmlspecialchars(addslashes($cmdDisplay)) ?>', 'Command')" title="Salin Command">
                                                    <?= renderSvg('copy', 12) ?>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- BARIS 3: RESULT PEKERJAAN -->
                                        <div class="task-card-row-result">
                                            <?php if (!empty($resText)): ?>
                                                <div class="task-result-box task-result-done">
                                                    <div class="res-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px;">
                                                        <div style="display: flex; align-items: center; gap: 6px;">
                                                            <?= renderSvg('check', 12) ?>
                                                            <span>Result:</span>
                                                        </div>
                                                        <div style="display: flex; align-items: center; gap: 5px; font-size: 11px; color: var(--text-secondary); font-weight: normal;">
                                                            <span>Eksekutor:</span>
                                                            <span style="color: #c084fc; font-weight: 600; background: rgba(168,85,247,0.12); border: 1px solid rgba(168,85,247,0.25); border-radius: 4px; padding: 1px 6px;">
                                                                🤖 <?= htmlspecialchars(!empty($t['assigned_agent']) ? $t['assigned_agent'] : 'Antigravity AI Agent') ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div class="task-result-content"><?= nl2br(htmlspecialchars($resText)) ?></div>
                                                </div>
                                            <?php elseif ($t['status'] === 'FAILED'): ?>
                                                <div class="task-result-box task-result-failed">
                                                    <div class="res-header" style="color: #fda4af;">
                                                        <?= renderSvg('alert', 12) ?>
                                                        <span>Kendala / Error:</span>
                                                    </div>
                                                    <div class="task-result-content"><?= nl2br(htmlspecialchars($t['notes'] ?: 'Gagal diproses')) ?></div>
                                                </div>
                                            <?php elseif ($t['status'] === 'IN_PROGRESS'): ?>
                                                <div class="task-result-box task-result-progress">
                                                    <div style="display: flex; align-items: center; gap: 6px; font-style: italic; color: #7dd3fc;">
                                                        <?= renderSvg('play', 11) ?>
                                                        <span>Sedang dikerjakan oleh AI Agent...</span>
                                                    </div>
                                                    <?php if (!empty($t['notes'])): ?>
                                                        <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 4px;">(<?= htmlspecialchars($t['notes']) ?>)</div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="task-result-box task-result-pending">
                                                    <div style="display: flex; align-items: center; gap: 6px; font-style: italic;">
                                                        <?= renderSvg('clock', 11) ?>
                                                        <span>Menunggu giliran eksekusi oleh Agent / Developer...</span>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- ============================================================== -->
                        <!-- BARIS PAGINATION DEFAULT 10 LIST                                -->
                        <!-- ============================================================== -->
                        <?php if ($totalPages > 1 || $totalTasksCount > 10): ?>
                            <div class="pagination-bar" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border-subtle);">
                                <div style="font-size: 12.5px; color: var(--text-muted);">
                                    Menampilkan <b><?= ($totalTasksCount > 0 ? $offset + 1 : 0) ?>-<?= min($offset + $perPage, $totalTasksCount) ?></b> dari <b><?= $totalTasksCount ?></b> tugas
                                    (Halaman <?= $page ?> dari <?= $totalPages ?>)
                                </div>

                                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                    <?php 
                                        $baseQuery = "?tab=tasks";
                                        if ($filterProjectId > 0) $baseQuery .= "&filter_project=" . $filterProjectId;
                                        if ($filterStatus) $baseQuery .= "&filter_status=" . urlencode($filterStatus);
                                        $baseQuery .= "&per_page=" . $perPage;
                                    ?>

                                    <?php if ($page > 1): ?>
                                        <a href="<?= $baseQuery ?>&page=1" class="btn btn-secondary btn-xs" title="Halaman Pertama">«</a>
                                        <a href="<?= $baseQuery ?>&page=<?= $page - 1 ?>" class="btn btn-secondary btn-xs" title="Halaman Sebelumnya">‹</a>
                                    <?php endif; ?>

                                    <?php
                                        $startP = max(1, $page - 2);
                                        $endP = min($totalPages, $page + 2);
                                        for ($pIdx = $startP; $pIdx <= $endP; $pIdx++):
                                    ?>
                                        <a href="<?= $baseQuery ?>&page=<?= $pIdx ?>" class="btn btn-xs <?= $pIdx === $page ? 'btn-primary' : 'btn-secondary' ?>" style="<?= $pIdx === $page ? 'font-weight:600;' : '' ?>">
                                            <?= $pIdx ?>
                                        </a>
                                    <?php endfor; ?>

                                    <?php if ($page < $totalPages): ?>
                                        <a href="<?= $baseQuery ?>&page=<?= $page + 1 ?>" class="btn btn-secondary btn-xs" title="Halaman Berikutnya">›</a>
                                        <a href="<?= $baseQuery ?>&page=<?= $totalPages ?>" class="btn btn-secondary btn-xs" title="Halaman Terakhir">»</a>
                                    <?php endif; ?>

                                    <!-- Selector Per Page -->
                                    <div style="display: flex; align-items: center; gap: 4px; margin-left: 10px; font-size: 11.5px; color: var(--text-muted);">
                                        <span>Per hal:</span>
                                        <?php foreach ([10, 25, 50] as $lim): ?>
                                            <?php 
                                                $limQuery = "?tab=tasks";
                                                if ($filterProjectId > 0) $limQuery .= "&filter_project=" . $filterProjectId;
                                                if ($filterStatus) $limQuery .= "&filter_status=" . urlencode($filterStatus);
                                                $limQuery .= "&per_page=" . $lim . "&page=1";
                                            ?>
                                            <a href="<?= $limQuery ?>" class="badge" style="cursor: pointer; padding: 2px 6px; <?= $perPage === $lim ? 'background: var(--ink-4); color: #fff; font-weight:600; border-color: var(--accent-primary);' : 'background: transparent; color: var(--text-dim);' ?>">
                                                <?= $lim ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
