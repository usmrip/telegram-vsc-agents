<?php
// views/tab_admin.php - Superadmin Panel & User Management
if (!defined('TELE_VS_CODE')) exit;
?>
            <!-- ================================================================ -->
            <!-- TAB 4: SUPERADMIN USER MANAGEMENT                                -->
            <!-- ================================================================ -->
            <section class="tab-pane">
                <div class="grid-4" style="margin-bottom: 22px;">
                    <div class="stat-card">
                        <div class="val" style="color: #fef08a;"><?= $adminStats['total_users'] ?></div>
                        <div class="lbl">Total Pengguna</div>
                    </div>
                    <div class="stat-card">
                        <div class="val"><?= $adminStats['total_projects'] ?></div>
                        <div class="lbl">Total Proyek</div>
                    </div>
                    <div class="stat-card">
                        <div class="val" style="color: <?= $adminStats['blocked_users'] > 0 ? '#fda4af' : '#86efac' ?>;"><?= $adminStats['blocked_users'] ?></div>
                        <div class="lbl">Akses Diblokir</div>
                    </div>
                    <div class="stat-card">
                        <div class="val" style="color: <?= $adminStats['blocked_notify'] > 0 ? '#fda4af' : '#86efac' ?>;"><?= $adminStats['blocked_notify'] ?></div>
                        <div class="lbl">Notif Diblokir</div>
                    </div>
                </div>


                <!-- ================================================================ -->
                <!-- KARTU ANALITIK PENGUNJUNG & LOKASI IP-API (KHUSUS ADMIN)          -->
                <!-- ================================================================ -->
                <div class="card" style="margin-bottom: 24px; border: 1px solid rgba(56, 189, 248, 0.25); background: linear-gradient(180deg, rgba(15, 23, 42, 0.8) 0%, rgba(10, 15, 30, 0.95) 100%);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <h3 style="font-size: 16px; font-weight: 600; color: #38bdf8; display: flex; align-items: center; gap: 8px; margin: 0 0 4px 0;">
                                <?= renderSvg('globe', 18) ?>
                                <span><?= __t('Analitik Pengunjung & Lokasi Geografis (ip-api.com)', 'Visitor Analytics & Geolocation (ip-api.com)') ?></span>
                                <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); font-size: 10px;">ADMIN ONLY</span>
                            </h3>
                            <p style="font-size: 12px; color: var(--text-muted); margin: 0;">
                                <?= __t('Pencatatan real-time lokasi IP, negara, kota, ISP, dan tipe perangkat pengunjung menggunakan engine ip-api.com.', 'Real-time logging of visitor IP geolocation, country, city, ISP, and device type via ip-api.com engine.') ?>
                            </p>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <form method="POST" action="?action=clear_analytics" onsubmit="return confirm('Apakah Anda yakin ingin menghapus seluruh log analitik pengunjung?');" style="margin: 0;">
                                <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                                <button type="submit" class="btn btn-secondary btn-xs" style="color: #f87171; border-color: rgba(239, 68, 68, 0.3);">
                                    <?= renderSvg('trash', 12) ?>
                                    <span><?= __t('Bersihkan Log', 'Clear Logs') ?></span>
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- KPI Cards Analitik -->
                    <div class="grid-4" style="margin-bottom: 20px; gap: 10px;">
                        <div class="stat-card" style="padding: 14px 16px;">
                            <div class="val" style="color: #38bdf8; font-size: 28px;"><?= number_format($analyticsStats['total_hits']) ?></div>
                            <div class="lbl"><?= __t('Total Kunjungan (Hits)', 'Total Page Hits') ?></div>
                        </div>
                        <div class="stat-card" style="padding: 14px 16px;">
                            <div class="val" style="color: #34d399; font-size: 28px;"><?= number_format($analyticsStats['unique_ips']) ?></div>
                            <div class="lbl"><?= __t('Pengunjung Unik (IP)', 'Unique Visitors (IP)') ?></div>
                        </div>
                        <div class="stat-card" style="padding: 14px 16px;">
                            <div class="val" style="color: #fbbf24; font-size: 28px;"><?= number_format($analyticsStats['countries_count']) ?></div>
                            <div class="lbl"><?= __t('Negara Terdeteksi', 'Countries Detected') ?></div>
                        </div>
                        <div class="stat-card" style="padding: 14px 16px;">
                            <div class="val" style="color: #a78bfa; font-size: 28px;"><?= number_format($analyticsStats['cities_count']) ?></div>
                            <div class="lbl"><?= __t('Kota Terdeteksi', 'Cities Detected') ?></div>
                        </div>
                    </div>

                    <!-- Top Negara Breakdown -->
                    <?php if (!empty($topCountries)): ?>
                        <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 12px 16px; margin-bottom: 20px;">
                            <div style="font-size: 11.5px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 10px; display: flex; justify-content: space-between;">
                                <span><?= __t('Top 5 Asal Negara Pengunjung', 'Top 5 Visitor Countries') ?></span>
                                <span><?= __t('Perangkat: Mobile (' . $analyticsStats['mobile_hits'] . ') vs Desktop (' . $analyticsStats['desktop_hits'] . ')', 'Devices: Mobile (' . $analyticsStats['mobile_hits'] . ') vs Desktop (' . $analyticsStats['desktop_hits'] . ')') ?></span>
                            </div>
                            <div style="display: flex; gap: 14px; flex-wrap: wrap;">
                                <?php foreach ($topCountries as $tc): ?>
                                    <div style="flex: 1; min-width: 140px; background: var(--bg-surface-inset); border: 1px solid var(--border-subtle); border-radius: 6px; padding: 8px 12px; display: flex; justify-content: space-between; align-items: center;">
                                        <div style="font-size: 13px; font-weight: 500; color: #fff; display: flex; align-items: center; gap: 6px;">
                                            <span>🌐</span>
                                            <span><?= htmlspecialchars($tc['country']) ?></span>
                                        </div>
                                        <span class="badge badge-secondary" style="font-size: 11px;"><?= $tc['hits'] ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Tabel Log Pengunjung -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <h4 style="font-size: 13.5px; font-weight: 500; color: #fff; margin: 0;">
                            <?= __t('Daftar Kunjungan Pengunjung', 'Visitor Traffic Logs') ?>
                        </h4>
                        <span style="font-size: 11.5px; color: var(--text-muted);">
                            <?= __t('Total ' . number_format($totalVisCount) . ' data kunjungan', 'Total ' . number_format($totalVisCount) . ' visit logs') ?>
                        </span>
                    </div>

                    <div class="table-wrapper">
                        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                            <thead>
                                <tr style="border-bottom: 1px solid var(--border-medium); text-align: left; color: var(--text-muted);">
                                    <th style="padding: 10px 8px;"><?= __t('Waktu', 'Time') ?></th>
                                    <th style="padding: 10px 8px;"><?= __t('IP Address', 'IP Address') ?></th>
                                    <th style="padding: 10px 8px;"><?= __t('Lokasi (Kota, Wilayah, Negara)', 'Location (City, Region, Country)') ?></th>
                                    <th style="padding: 10px 8px;"><?= __t('ISP / Provider', 'ISP / Provider') ?></th>
                                    <th style="padding: 10px 8px;"><?= __t('Perangkat & OS', 'Device & OS') ?></th>
                                    <th style="padding: 10px 8px;"><?= __t('Pengguna / Sesi', 'User / Session') ?></th>
                                    <th style="padding: 10px 8px; text-align: center;"><?= __t('Peta', 'Map') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentVisitors)): ?>
                                    <tr>
                                        <td colspan="7" style="padding: 24px; text-align: center; color: var(--text-muted);">
                                            <?= __t('Belum ada riwayat kunjungan yang tercatat.', 'No visitor logs recorded yet.') ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentVisitors as $v): ?>
                                        <tr style="border-bottom: 1px solid var(--border-subtle);">
                                            <td style="padding: 8px; color: var(--text-muted); white-space: nowrap; font-size: 11px;">
                                                <?php
                                                    $ts = strtotime($v['visited_at'] . ' UTC');
                                                    if (!$ts || $ts < 0) $ts = strtotime($v['visited_at']);
                                                    echo htmlspecialchars(date('d M Y H:i', $ts));
                                                ?>
                                            </td>
                                            <td style="padding: 8px; font-family: var(--font-mono); font-size: 11.5px; color: #38bdf8;">
                                                <?= htmlspecialchars($v['ip']) ?>
                                            </td>
                                            <td style="padding: 8px; color: #fff;">
                                                <?php if (!empty($v['city']) || !empty($v['country'])): ?>
                                                    <strong><?= htmlspecialchars($v['city'] ?: '-') ?></strong>, 
                                                    <span style="color: var(--text-secondary);"><?= htmlspecialchars($v['region'] ?: '') ?></span>
                                                    <span style="color: #fbbf24; font-size: 11px; margin-left: 2px;">(<?= htmlspecialchars($v['country'] ?: '-') ?>)</span>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 8px; color: var(--text-secondary); max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?= htmlspecialchars($v['isp'] ?: '-') ?>
                                            </td>
                                            <td style="padding: 8px; white-space: nowrap;">
                                                <span class="badge <?= $v['device_type'] === 'Mobile' ? 'badge-primary' : 'badge-secondary' ?>" style="font-size: 10px; padding: 2px 6px;">
                                                    <?= htmlspecialchars($v['device_type']) ?>
                                                </span>
                                                <span style="color: var(--text-muted); font-size: 11px; margin-left: 4px;">
                                                    <?= htmlspecialchars($v['os'] . ' · ' . $v['browser']) ?>
                                                </span>
                                            </td>
                                            <td style="padding: 8px; font-size: 11.5px;">
                                                <?php if (!empty($v['user_email'])): ?>
                                                    <span style="color: #34d399; font-weight: 500;">👤 <?= htmlspecialchars($v['user_email']) ?></span>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted);">Tamu (Guest)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 8px; text-align: center;">
                                                <?php if (!empty($v['lat']) && !empty($v['lon'])): ?>
                                                    <a href="https://www.google.com/maps?q=<?= urlencode($v['lat'] . ',' . $v['lon']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-xs" style="padding: 2px 7px; font-size: 10.5px;" title="<?= __t('Buka Koordinat GPS di Google Maps', 'Open GPS coordinates in Google Maps') ?>">
                                                        📍 Peta
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted);">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Log Pengunjung -->
                    <?php if ($totalPagesVis > 1 || $totalVisCount > 10): ?>
                        <div class="pagination-bar" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 16px; padding-top: 12px; border-top: 1px solid var(--border-subtle);">
                            <div style="font-size: 12px; color: var(--text-muted);">
                                <?= __t('Menampilkan', 'Showing') ?> <b><?= ($totalVisCount > 0 ? $offsetVis + 1 : 0) ?>-<?= min($offsetVis + $perPageVis, $totalVisCount) ?></b> <?= __t('dari', 'of') ?> <b><?= number_format($totalVisCount) ?></b> <?= __t('data kunjungan', 'visits') ?>
                                (<?= __t('Halaman', 'Page') ?> <?= $pageVis ?> <?= __t('dari', 'of') ?> <?= $totalPagesVis ?>)
                            </div>

                            <div style="display: flex; align-items: center; gap: 5px; flex-wrap: wrap;">
                                <?php 
                                    $baseQueryVis = "?tab=admin&page_user=" . $pageUser . "&per_page_user=" . $perPageUser . "&per_page_vis=" . $perPageVis;
                                ?>

                                <?php if ($pageVis > 1): ?>
                                    <a href="<?= $baseQueryVis ?>&page_vis=1" class="btn btn-secondary btn-xs" title="<?= __t('Halaman Pertama', 'First Page') ?>">«</a>
                                    <a href="<?= $baseQueryVis ?>&page_vis=<?= $pageVis - 1 ?>" class="btn btn-secondary btn-xs" title="<?= __t('Halaman Sebelumnya', 'Previous Page') ?>">‹</a>
                                <?php endif; ?>

                                <?php
                                    $startPVis = max(1, $pageVis - 2);
                                    $endPVis = min($totalPagesVis, $pageVis + 2);
                                    for ($pIdx = $startPVis; $pIdx <= $endPVis; $pIdx++):
                                ?>
                                    <a href="<?= $baseQueryVis ?>&page_vis=<?= $pIdx ?>" class="btn btn-xs <?= $pIdx === $pageVis ? 'btn-primary' : 'btn-secondary' ?>" style="<?= $pIdx === $pageVis ? 'font-weight:600;' : '' ?>">
                                        <?= $pIdx ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($pageVis < $totalPagesVis): ?>
                                    <a href="<?= $baseQueryVis ?>&page_vis=<?= $pageVis + 1 ?>" class="btn btn-secondary btn-xs" title="<?= __t('Halaman Berikutnya', 'Next Page') ?>">›</a>
                                    <a href="<?= $baseQueryVis ?>&page_vis=<?= $totalPagesVis ?>" class="btn btn-secondary btn-xs" title="<?= __t('Halaman Terakhir', 'Last Page') ?>">»</a>
                                <?php endif; ?>

                                <!-- Selector Per Page Visitor -->
                                <div style="display: flex; align-items: center; gap: 4px; margin-left: 8px; font-size: 11px; color: var(--text-muted);">
                                    <span><?= __t('Per hal:', 'Per page:') ?></span>
                                    <?php foreach ([10, 20, 50, 100] as $limV): ?>
                                        <a href="?tab=admin&page_user=<?= $pageUser ?>&per_page_user=<?= $perPageUser ?>&per_page_vis=<?= $limV ?>&page_vis=1" class="badge" style="cursor: pointer; padding: 2px 5px; <?= $perPageVis === $limV ? 'background: var(--ink-4); color: #fff; font-weight:600; border-color: var(--accent-primary);' : 'background: transparent; color: var(--text-dim);' ?>">
                                            <?= $limV ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Tombol Tambah Pengguna Manual -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h3 style="font-size: 16px; font-weight: 500; color: #fff; display: flex; align-items: center; gap: 8px;">
                        <?= renderSvg('shield', 16) ?>
                        <span>Manajemen Pengguna & Kontrol Akses</span>
                    </h3>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="const b = document.getElementById('boxAdminNewUser'); b.style.display = b.style.display === 'none' ? 'block' : 'none';">
                        <?= renderSvg('plus', 13) ?>
                        <span>Daftarkan Pengguna Baru</span>
                    </button>
                </div>

                <!-- Form Tambah User Manual (Collapsible) -->
                <div class="card" id="boxAdminNewUser" style="display: none; margin-bottom: 22px;">
                    <h4 style="font-size: 14px; font-weight: 500; color: #fff; margin-bottom: 14px;">Tambah Pengguna Baru Manual</h4>
                    <form method="POST" action="?action=admin_add_user">
                        <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                        <div class="grid-3">
                            <div class="form-group">
                                <label>Email Pengguna * :</label>
                                <input type="email" name="email" placeholder="user@email.com" required>
                            </div>
                            <div class="form-group">
                                <label>Nomor WhatsApp * :</label>
                                <input type="text" name="whatsapp" placeholder="081234567890" required>
                            </div>
                            <div class="form-group">
                                <label>Password * :</label>
                                <input type="password" name="password" minlength="6" placeholder="Minimal 6 karakter" required>
                            </div>
                        </div>
                        <div style="display: flex; gap: 8px; margin-top: 14px;">
                            <button type="submit" class="btn btn-primary btn-sm">Buat Pengguna</button>
                            <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('boxAdminNewUser').style.display='none'">Batal</button>
                        </div>
                    </form>
                </div>

                <!-- Tabel Seluruh Pengguna -->
                <div class="card" style="margin-bottom: 24px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                        <h4 style="font-size: 14px; font-weight: 500; color: #fff;">Daftar Akun Pengguna</h4>
                        <span style="font-size: 12px; color: var(--text-muted);"><?= number_format($totalUsersCount) ?> Pengguna Terdaftar</span>
                    </div>

                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 50px;">ID</th>
                                    <th>Email & Kontak</th>
                                    <th style="width: 90px; text-align: center;">Proyek</th>
                                    <th style="width: 90px; text-align: center;">Tugas</th>
                                    <th style="width: 140px; text-align: center;">Akses</th>
                                    <th style="width: 140px; text-align: center;">Notif Telegram</th>
                                    <th style="width: 140px; text-align: center;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($allUsers)): ?>
                                    <tr>
                                        <td colspan="7" style="padding: 24px; text-align: center; color: var(--text-muted);">
                                            <?= __t('Belum ada pengguna terdaftar.', 'No registered users found.') ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($allUsers as $u): ?>
                                        <?php $isTargetAdmin = (strtolower($u['email']) === strtolower(SUPERADMIN_EMAIL) || !empty($u['is_admin'])); ?>
                                        <tr>
                                            <td><code>#<?= $u['id'] ?></code></td>
                                            <td>
                                                <b><?= htmlspecialchars($u['email']) ?></b>
                                                <?php if ($isTargetAdmin): ?>
                                                    <span class="badge" style="background: rgba(245, 158, 11, 0.2); color: #fef08a; font-size: 10px;">SUPERADMIN</span>
                                                <?php endif; ?>
                                                <br>
                                                <small style="color: var(--text-muted); font-size: 11px;">WA: <code><?= htmlspecialchars($u['whatsapp']) ?></code> &bull; Terdaftar: <?= htmlspecialchars(substr($u['created_at'], 0, 10)) ?></small>
                                            </td>
                                            <td style="text-align: center;">
                                                <span class="badge badge-project"><?= $u['project_count'] ?> Proyek</span>
                                            </td>
                                            <td style="text-align: center;">
                                                <span class="badge badge-done"><?= $u['task_count'] ?> Tugas</span>
                                            </td>
                                            <td style="text-align: center;">
                                                <?php if (!empty($u['is_blocked'])): ?>
                                                    <span class="badge badge-failed" style="margin-bottom: 4px;">DIBLOKIR</span><br>
                                                    <a href="?action=admin_toggle_block&user_id=<?= $u['id'] ?>&csrf=<?= getCsrfToken() ?>" class="btn btn-sm btn-outline" style="color: #6ee7b7; font-size: 11px;">Buka Blokir</a>
                                                <?php else: ?>
                                                    <span class="badge badge-done" style="margin-bottom: 4px;">AKTIF</span><br>
                                                    <?php if (!$isTargetAdmin): ?>
                                                        <a href="?action=admin_toggle_block&user_id=<?= $u['id'] ?>&csrf=<?= getCsrfToken() ?>" onclick="return confirm('Blokir akses untuk <?= htmlspecialchars($u['email']) ?>?')" class="btn btn-sm btn-outline" style="color: var(--accent-danger); font-size: 11px;">Blokir</a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: center;">
                                                <?php if (!empty($u['block_notify'])): ?>
                                                    <span class="badge badge-failed" style="margin-bottom: 4px;">BLOCKED</span><br>
                                                    <a href="?action=admin_toggle_notify&user_id=<?= $u['id'] ?>&csrf=<?= getCsrfToken() ?>" class="btn btn-sm btn-outline" style="color: #6ee7b7; font-size: 11px;">Izinkan</a>
                                                <?php else: ?>
                                                    <span class="badge badge-done" style="margin-bottom: 4px;">AKTIF</span><br>
                                                    <a href="?action=admin_toggle_notify&user_id=<?= $u['id'] ?>&csrf=<?= getCsrfToken() ?>" onclick="return confirm('Blokir notifikasi untuk <?= htmlspecialchars($u['email']) ?>?')" class="btn btn-sm btn-outline" style="color: var(--accent-danger); font-size: 11px;">Blokir</a>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: center;">
                                                <div style="display: flex; gap: 4px; justify-content: center;">
                                                    <button type="button" class="btn btn-sm btn-outline" onclick="promptResetPass(<?= $u['id'] ?>, '<?= htmlspecialchars($u['email']) ?>')" title="Reset Password">
                                                        <?= renderSvg('key', 12) ?>
                                                    </button>
                                                    <?php if (!$isTargetAdmin): ?>
                                                        <a href="?action=admin_delete_user&user_id=<?= $u['id'] ?>&csrf=<?= getCsrfToken() ?>" onclick="return confirm('Hapus akun <?= htmlspecialchars($u['email']) ?> secara permanen?')" class="btn btn-sm btn-outline" style="color: var(--accent-danger);" title="Hapus Akun">
                                                            <?= renderSvg('trash', 12) ?>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Daftar Pengguna -->
                    <?php if ($totalPagesUser > 1 || $totalUsersCount > 5): ?>
                        <div class="pagination-bar" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 16px; padding-top: 12px; border-top: 1px solid var(--border-subtle);">
                            <div style="font-size: 12px; color: var(--text-muted);">
                                <?= __t('Menampilkan', 'Showing') ?> <b><?= ($totalUsersCount > 0 ? $offsetUser + 1 : 0) ?>-<?= min($offsetUser + $perPageUser, $totalUsersCount) ?></b> <?= __t('dari', 'of') ?> <b><?= number_format($totalUsersCount) ?></b> <?= __t('pengguna', 'users') ?>
                                (<?= __t('Halaman', 'Page') ?> <?= $pageUser ?> <?= __t('dari', 'of') ?> <?= $totalPagesUser ?>)
                            </div>

                            <div style="display: flex; align-items: center; gap: 5px; flex-wrap: wrap;">
                                <?php 
                                    $baseQueryUser = "?tab=admin&page_vis=" . $pageVis . "&per_page_vis=" . $perPageVis . "&per_page_user=" . $perPageUser;
                                ?>

                                <?php if ($pageUser > 1): ?>
                                    <a href="<?= $baseQueryUser ?>&page_user=1" class="btn btn-secondary btn-xs" title="<?= __t('Halaman Pertama', 'First Page') ?>">«</a>
                                    <a href="<?= $baseQueryUser ?>&page_user=<?= $pageUser - 1 ?>" class="btn btn-secondary btn-xs" title="<?= __t('Halaman Sebelumnya', 'Previous Page') ?>">‹</a>
                                <?php endif; ?>

                                <?php
                                    $startPUser = max(1, $pageUser - 2);
                                    $endPUser = min($totalPagesUser, $pageUser + 2);
                                    for ($pIdx = $startPUser; $pIdx <= $endPUser; $pIdx++):
                                ?>
                                    <a href="<?= $baseQueryUser ?>&page_user=<?= $pIdx ?>" class="btn btn-xs <?= $pIdx === $pageUser ? 'btn-primary' : 'btn-secondary' ?>" style="<?= $pIdx === $pageUser ? 'font-weight:600;' : '' ?>">
                                        <?= $pIdx ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($pageUser < $totalPagesUser): ?>
                                    <a href="<?= $baseQueryUser ?>&page_user=<?= $pageUser + 1 ?>" class="btn btn-secondary btn-xs" title="<?= __t('Halaman Berikutnya', 'Next Page') ?>">›</a>
                                    <a href="<?= $baseQueryUser ?>&page_user=<?= $totalPagesUser ?>" class="btn btn-secondary btn-xs" title="<?= __t('Halaman Terakhir', 'Last Page') ?>">»</a>
                                <?php endif; ?>

                                <!-- Selector Per Page User -->
                                <div style="display: flex; align-items: center; gap: 4px; margin-left: 8px; font-size: 11px; color: var(--text-muted);">
                                    <span><?= __t('Per hal:', 'Per page:') ?></span>
                                    <?php foreach ([5, 10, 25, 50] as $limU): ?>
                                        <a href="?tab=admin&page_vis=<?= $pageVis ?>&per_page_vis=<?= $perPageVis ?>&per_page_user=<?= $limU ?>&page_user=1" class="badge" style="cursor: pointer; padding: 2px 5px; <?= $perPageUser === $limU ? 'background: var(--ink-4); color: #fff; font-weight:600; border-color: var(--accent-primary);' : 'background: transparent; color: var(--text-dim);' ?>">
                                            <?= $limU ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Konfigurasi Server SMTP Email -->
                <div class="card">
                    <h3 style="font-size: 15px; font-weight: 500; color: #fff; margin-bottom: 6px;">Konfigurasi Server SMTP Email</h3>
                    <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 16px;">Untuk pengiriman email Lupa Password dan notifikasi sistem.</p>

                    <form method="POST" action="?action=save_smtp">
                        <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                        <div class="grid-3">
                            <div class="form-group">
                                <label>SMTP Host * :</label>
                                <input type="text" name="smtp_host" value="<?= htmlspecialchars(getSetting('smtp_host', '')) ?>" placeholder="mail.biru.co.id" required>
                            </div>
                            <div class="form-group">
                                <label>SMTP Port * :</label>
                                <input type="number" name="smtp_port" value="<?= htmlspecialchars(getSetting('smtp_port', '465')) ?>" placeholder="465" required>
                            </div>
                            <div class="form-group">
                                <label>Enkripsi Protokol :</label>
                                <?php $curCrypto = strtolower(getSetting('smtp_crypto', 'ssl')); ?>
                                <select name="smtp_crypto">
                                    <option value="ssl" <?= $curCrypto === 'ssl' ? 'selected' : '' ?>>SSL (Port 465 - Recommended)</option>
                                    <option value="tls" <?= $curCrypto === 'tls' ? 'selected' : '' ?>>TLS (Port 587)</option>
                                    <option value="none" <?= $curCrypto === 'none' ? 'selected' : '' ?>>None (Port 25)</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label>SMTP Username / Email :</label>
                                <input type="text" name="smtp_user" value="<?= htmlspecialchars(getSetting('smtp_user', '')) ?>" placeholder="no-reply@biru.co.id">
                            </div>
                            <div class="form-group">
                                <label>SMTP Password :</label>
                                <input type="password" name="smtp_pass" placeholder="<?= getSetting('smtp_pass') ? '(Password tersimpan)' : 'Password SMTP' ?>">
                            </div>
                        </div>

                        <div class="grid-2" style="margin-bottom: 16px;">
                            <div class="form-group">
                                <label>Nama Pengirim (From Name) :</label>
                                <input type="text" name="smtp_from_name" value="<?= htmlspecialchars(getSetting('smtp_from_name', 'Tele-vs-Code')) ?>" placeholder="Tele-vs-Code">
                            </div>
                            <div class="form-group">
                                <label>Email Pengirim (From Email) :</label>
                                <input type="email" name="smtp_from_email" value="<?= htmlspecialchars(getSetting('smtp_from_email', '')) ?>" placeholder="no-reply@biru.co.id">
                            </div>
                        </div>

                        <div style="border-top: 1px dashed var(--border-subtle); margin: 20px 0 16px; padding-top: 16px;">
                            <h4 style="font-size: 13.5px; font-weight: 600; color: #fff; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                                <span>🛡️</span> <?= __t('Cloudflare Turnstile (Proteksi Bot)', 'Cloudflare Turnstile (Bot Protection)') ?>
                            </h4>
                            <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 12px;">
                                <?= __t('Kunci Turnstile untuk verifikasi pendaftaran & login. Default menggunakan Cloudflare test key (selalu lolos).', 'Turnstile keys for registration & login verification. Defaults to Cloudflare test key (always passes).') ?>
                            </p>
                            <div class="grid-2" style="gap: 12px; margin-bottom: 8px;">
                                <div class="form-group">
                                    <label><?= __t('Site Key:', 'Site Key:') ?></label>
                                    <input type="text" name="turnstile_site_key" value="<?= htmlspecialchars(getSetting('turnstile_site_key', '1x00000000000000000000AA')) ?>" placeholder="1x00000000000000000000AA">
                                </div>
                                <div class="form-group">
                                    <label><?= __t('Secret Key:', 'Secret Key:') ?></label>
                                    <input type="password" name="turnstile_secret_key" value="<?= htmlspecialchars(getSetting('turnstile_secret_key', '1x0000000000000000000000000000000AA')) ?>" placeholder="1x0000000000000000000000000000000AA">
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-sm"><?= __t('Simpan Konfigurasi SMTP & Turnstile', 'Save SMTP & Turnstile Configuration') ?></button>
                    </form>

                    <hr style="border: 0; border-top: 1px solid var(--border-subtle); margin: 24px 0 20px;">

                    <h4 style="font-size: 14px; font-weight: 500; color: #fff; margin-bottom: 6px; display: flex; align-items: center; gap: 8px;">
                        <span>🧪</span> <?= __t('Uji Koneksi & Kirim Email Tes SMTP', 'Test SMTP Connection & Send Test Email') ?>
                    </h4>
                    <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 14px;">
                        <?= __t('Kirimkan email uji coba langsung untuk memverifikasi socket koneksi SMTP, autentikasi user/password, dan kesiapan pengiriman.', 'Send an immediate test email to verify SMTP socket connection, authentication, and delivery readiness.') ?>
                    </p>

                    <form method="POST" action="?action=test_smtp" style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 16px;">
                        <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                        <div class="form-group" style="flex: 1; min-width: 260px; margin-bottom: 0;">
                            <label><?= __t('Email Penerima Tes * :', 'Test Recipient Email * :') ?></label>
                            <input type="email" name="test_email" value="<?= htmlspecialchars(!empty($_SESSION['tvsc_smtp_diag']['email']) ? $_SESSION['tvsc_smtp_diag']['email'] : $currentUser['email']) ?>" placeholder="admin@domain.com" required>
                        </div>
                        <button type="submit" class="btn btn-secondary btn-sm" style="height: 38px;">
                            <span>🚀</span> <?= __t('Kirim Email Tes Sekarang', 'Send Test Email Now') ?>
                        </button>
                    </form>

                    <?php if (!empty($_SESSION['tvsc_smtp_diag'])): ?>
                        <?php 
                            $diag = $_SESSION['tvsc_smtp_diag']; 
                            $isDiagOk = !empty($diag['ok']);
                        ?>
                        <div style="background: <?= $isDiagOk ? 'rgba(16, 185, 129, 0.08)' : 'rgba(239, 68, 68, 0.08)' ?>; border: 1px solid <?= $isDiagOk ? 'rgba(16, 185, 129, 0.3)' : 'rgba(239, 68, 68, 0.3)' ?>; border-radius: 8px; padding: 14px; margin-top: 14px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span class="badge" style="background: <?= $isDiagOk ? '#10b981' : '#ef4444' ?>; color: #fff; font-weight: 600; font-size: 11px; padding: 2px 8px; border-radius: 4px;">
                                        <?= $isDiagOk ? '✅ ' . __t('BERHASIL', 'SUCCESS') : '❌ ' . __t('GAGAL', 'FAILED') ?>
                                    </span>
                                    <span style="font-size: 12.5px; color: #f8fafc; font-weight: 500;">
                                        <?= __t('Hasil Uji Coba Terakhir:', 'Last Diagnostic Result:') ?> <b><?= htmlspecialchars($diag['email']) ?></b> (<?= htmlspecialchars($diag['time']) ?>)
                                    </span>
                                </div>
                                <a href="?action=clear_smtp_diag" class="btn btn-secondary btn-xs" style="text-decoration: none;"><?= __t('Tutup Log', 'Dismiss Log') ?></a>
                            </div>

                            <?php if (!$isDiagOk && !empty($diag['error'])): ?>
                                <div style="color: #f87171; font-size: 12px; margin-bottom: 8px; font-weight: 500;">
                                    ⚠️ <?= htmlspecialchars($diag['error']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($diag['log'])): ?>
                                <details style="margin-top: 6px;">
                                    <summary style="font-size: 11.5px; color: var(--text-muted); cursor: pointer; user-select: none;">
                                        <?= __t('Lihat Rincian Socket Handshake Log (Technical Trace)', 'View Socket Handshake Technical Trace') ?>
                                    </summary>
                                    <pre style="background: #090d16; color: #86efac; padding: 12px; border-radius: 6px; font-size: 11.5px; font-family: monospace; max-height: 200px; overflow-y: auto; margin-top: 8px; white-space: pre-wrap; line-height: 1.4;"><?= htmlspecialchars($diag['log']) ?></pre>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
