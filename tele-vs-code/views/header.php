<?php
// views/header.php - Top Navigation, Meta, and Global CSS
if (!defined('TELE_VS_CODE')) exit;

$cfSiteKey = function_exists('getSetting') ? getSetting('turnstile_site_key', '1x00000000000000000000AA') : '1x00000000000000000000AA';
$isTurnstileActive = (!empty($cfSiteKey) && $cfSiteKey !== '1x00000000000000000000AA');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Tele-vs-Code - Notifikasi pekerjaan AGEN AI di Visual Studio Code dengan Telegram</title>
    <meta name="description" content="Kirim instruksi coding dari Telegram ke AI Agent di VS Code. Terima hasil eksekusi lengkap secara real-time tanpa menunggu di depan layar.">
    <meta name="theme-color" content="#0d0c0a">

    <!-- Open Graph & Twitter Card Meta Tags -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://biru.co.id/tele-vs-code/">
    <meta property="og:title" content="Tele-vs-Code - Agen AI Bekerja di Editor, Kabarnya Sampai di Saku">
    <meta property="og:description" content="Kirim instruksi dari Telegram, biarkan agen di VS Code mengerjakannya, lalu terima laporan selesai lengkap secara real-time.">
    <meta property="og:image" content="https://biru.co.id/tele-vs-code/assets/img/og-image.png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="Tele-vs-Code Dashboard Preview & AI Agent">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Tele-vs-Code - Agen AI Bekerja di Editor, Kabarnya Sampai di Saku">
    <meta name="twitter:description" content="Kirim instruksi dari Telegram, biarkan agen di VS Code mengerjakannya, lalu terima laporan selesai lengkap secara real-time.">
    <meta name="twitter:image" content="https://biru.co.id/tele-vs-code/assets/img/og-image.png">

    <link rel="icon" type="image/jpeg" href="assets/img/logo.jpg">
    <meta name="view-transition" content="same-origin">
    <script>
        if ('onpagereveal' in window) document.documentElement.classList.add('has-vt');
        window.addEventListener('unhandledrejection', function(e) {
            if (e.reason && (e.reason.name === 'InvalidStateError' || (typeof e.reason.message === 'string' && e.reason.message.indexOf('Transition was aborted') !== -1))) {
                e.preventDefault();
            }
        });
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@300..600&family=Geist+Mono:wght@400;500&family=Instrument+Serif:ital@0;1&display=swap">
    <!-- Studio Ink Theme -->
        <link rel="stylesheet" href="assets/css/app.css?v=<?= file_exists(__DIR__ . '/../assets/css/app.css') ? filemtime(__DIR__ . '/../assets/css/app.css') : time() ?>">
    <?php if ($isTurnstileActive): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
    <style>
    /* Critical Mobile & Auth Segmented Solid Hardening */
    html, body {
        overflow-x: hidden !important;
        width: 100% !important;
        max-width: 100vw !important;
        position: relative !important;
    }
    .container {
        width: 100% !important;
        max-width: 1120px !important;
        box-sizing: border-box !important;
        overflow-x: hidden !important;
    }
    .landing, .landing-copy, .landing-preview-col {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        overflow-x: hidden !important;
    }
    .landing-title {
        word-break: break-word !important;
        overflow-wrap: break-word !important;
    }
    .auth-segmented {
        background: rgba(255, 255, 255, 0.08) !important;
        border: 1px solid rgba(255, 255, 255, 0.15) !important;
        border-radius: 9999px !important;
        padding: 3px !important;
        gap: 3px !important;
        display: flex !important;
        box-sizing: border-box !important;
    }
    .auth-segmented .liquid-layer { display: none !important; }
    .auth-segmented-btn {
        flex: 1 !important;
        height: 36px !important;
        font-size: 13px !important;
        font-weight: 500 !important;
        border-radius: 9999px !important;
        border: none !important;
        color: #94a3b8 !important;
        background: transparent !important;
        box-sizing: border-box !important;
        cursor: pointer !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    .auth-segmented-btn:hover { color: #ffffff !important; }
    .auth-segmented-btn.active {
        background: #ece4d6 !important;
        color: #0d0c0a !important;
        font-weight: 700 !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4) !important;
    }
    .btn, .btn-sm, .btn-xs {
        box-sizing: border-box !important;
        max-width: 100% !important;
    }
    .navbar-actions .btn {
        flex: 0 0 auto !important;
        white-space: nowrap !important;
    }
    
    /* Single Animated Live Dot */
    @keyframes livePulse {
        0% {
            transform: scale(0.95);
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            opacity: 0.8;
        }
        50% {
            transform: scale(1.15);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0);
            opacity: 1;
        }
        100% {
            transform: scale(0.95);
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
            opacity: 0.8;
        }
    }
    .badge-live-sync {
        background: rgba(16, 185, 129, 0.12) !important;
        color: #86efac !important;
        border: 1px solid rgba(16, 185, 129, 0.3) !important;
        font-size: 11px !important;
        padding: 0 9px !important;
        border-radius: 12px !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 6px !important;
        height: 22px !important;
    }
    .badge-live-sync::before {
        content: "" !important;
        display: inline-block !important;
        width: 6px !important;
        height: 6px !important;
        border-radius: 50% !important;
        background: #10b981 !important;
        animation: livePulse 1.5s ease-in-out infinite !important;
        box-shadow: 0 0 6px #10b981;
    }

    /* ==========================================================================
       Task Card Accordion & Media Preview Styling
       ========================================================================== */
    /* Judul di Card: 1 kolom, rata kiri, lebar 100% */
    .card h1, .card h2, .card h3, .card h4, .card h5,
    .card-title, .card-header,
    .task-card .task-meta-left,
    .task-card-instruction,
    .task-card-preview {
        width: 100% !important;
        text-align: left !important;
        justify-content: flex-start !important;
    }
    .project-card-header {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        width: 100%;
        text-align: left;
        gap: 10px;
    }
    .project-card-title-box {
        cursor: pointer;
        width: 100%;
        text-align: left;
    }
    .project-card-actions-box {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        align-items: center;
        width: 100%;
        justify-content: flex-start;
        border-top: 1px dashed var(--border-subtle);
        padding-top: 10px;
    }
    .task-card {
        transition: all var(--transition-fast);
    }
    .task-card.collapsed {
        padding: 12px 18px 12px 22px;
        gap: 6px;
    }
    .task-card.collapsed .task-card-row-meta {
        border-bottom: none;
        padding-bottom: 0;
    }
    .task-card-preview {
        font-size: 13px;
        color: var(--text-secondary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        cursor: pointer;
        padding-top: 4px;
        display: none;
        line-height: 1.4;
    }
    .task-card.collapsed .task-card-preview {
        display: block;
    }
    .task-card:not(.collapsed) .task-card-preview {
        display: none;
    }
    .task-accordion-toggle {
        color: var(--text-muted);
        transition: transform 0.2s ease, color var(--transition-fast);
    }
    .task-accordion-toggle:hover {
        color: #fff;
    }
    .task-card .chevron-icon {
        transition: transform 0.2s ease;
    }
    .task-card:not(.collapsed) .chevron-icon {
        transform: rotate(180deg);
    }
    .task-card-body {
        display: flex;
        flex-direction: column;
        gap: 14px;
        width: 100%;
        margin-top: 4px;
    }
    .task-card.collapsed .task-card-body {
        display: none !important;
    }

    /* Media Box & Photo Thumbnail */
    .task-card-media-box {
        margin: 6px 0 10px 0;
        padding: 10px 12px;
        background: var(--bg-surface-inset);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-md);
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .task-photo-wrap {
        display: inline-block;
        max-width: 320px;
    }
    .task-photo-link {
        position: relative;
        display: block;
        overflow: hidden;
        border-radius: var(--radius-sm);
        border: 1px solid var(--border-medium);
        background: #000;
        cursor: pointer;
    }
    .task-photo-img {
        display: block;
        max-width: 100%;
        max-height: 200px;
        object-fit: cover;
        border-radius: var(--radius-sm);
        transition: transform 0.25s ease;
    }
    .task-photo-link:hover .task-photo-img {
        transform: scale(1.04);
    }
    .task-photo-overlay {
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, 0.45);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        color: #fff;
        font-size: 12px;
        font-weight: 500;
        opacity: 0;
        transition: opacity 0.2s ease;
    }
    .task-photo-link:hover .task-photo-overlay {
        opacity: 1;
    }
    .task-media-caption {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        font-size: 11.5px;
        color: var(--text-muted);
    }
    .badge-media-indicator {
        background: rgba(56, 189, 248, 0.12);
        color: #7dd3fc;
        border: 1px solid rgba(56, 189, 248, 0.3);
        font-size: 11px;
        padding: 2px 7px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    /* Styling tombol navigasi persis seperti tombol Tambah Manual (.btn.btn-secondary) */
    .navbar-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .navbar-actions .btn {
        border-radius: var(--radius-sm);
        height: 32px;
        font-family: var(--font-sans);
        font-size: 12.5px;
        font-weight: 500;
        background: var(--ink-3);
        border: 1px solid var(--border-subtle);
        color: var(--text-primary);
        padding: 0 13px;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        box-sizing: border-box;
        text-decoration: none;
        white-space: nowrap;
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .navbar-actions .btn:hover {
        background: var(--ink-4);
        border-color: var(--border-hover);
        color: #fff;
    }
    .user-chip {
        border-radius: var(--radius-sm);
        background: var(--ink-3);
        border: 1px solid var(--border-subtle);
        height: 32px;
        padding: 0 11px;
        box-sizing: border-box;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        font-size: 12px;
        color: var(--text-secondary);
    }
    .lang-switcher {
        height: 32px;
        box-sizing: border-box;
        border-radius: var(--radius-sm);
        background: var(--ink-3);
        border: 1px solid var(--border-subtle);
        display: inline-flex;
        align-items: center;
        padding: 2px 3px;
        gap: 2px;
    }
    .lang-switcher a {
        height: 26px;
        line-height: 26px;
        padding: 0 8px;
        border-radius: calc(var(--radius-sm) - 1px);
        font-size: 11.5px;
        font-weight: 500;
        color: var(--text-secondary);
        text-decoration: none;
        transition: all 0.15s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .lang-switcher a.active {
        background: var(--text-primary);
        color: var(--ink-0);
        font-weight: 600;
    }
    .lang-switcher a:hover:not(.active) {
        color: #fff;
        background: var(--ink-4);
    }
    .app-footer {
        margin-top: 48px;
        padding-top: 24px;
        padding-bottom: 28px;
        border-top: 1px solid var(--border-subtle);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
        width: 100%;
    }
    .footer-credit {
        font-size: 12px;
        color: var(--text-muted);
    }
    .footer-biru-link {
        color: #38bdf8;
        text-decoration: none;
        font-weight: 500;
    }
    .footer-biru-link:hover {
        text-decoration: underline;
    }
    .footer-links {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .footer-links .btn {
        border-radius: var(--radius-sm);
    }
    .social-links {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .social-icon-btn {
        width: 32px;
        height: 32px;
        border-radius: var(--radius-sm);
        background: var(--ink-3);
        border: 1px solid var(--border-subtle);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: var(--text-secondary);
        text-decoration: none;
        transition: all 0.2s ease;
    }
    .social-icon-btn:hover {
        background: var(--ink-4);
        color: #fff;
        border-color: var(--border-hover);
        transform: translateY(-1px);
    }
    /* Floating Feedback Button */
    .floating-feedback-btn {
        position: fixed;
        right: 0;
        top: 50%;
        transform: translateY(-50%);
        background: #0284c7;
        color: #ffffff !important;
        padding: 9px 12px;
        border-radius: var(--radius-sm) 0 0 var(--radius-sm);
        box-shadow: -2px 4px 18px rgba(2, 132, 199, 0.45);
        display: inline-flex;
        align-items: center;
        gap: 7px;
        font-size: 12px;
        font-weight: 600;
        text-decoration: none;
        z-index: 9999;
        transition: opacity 0.45s cubic-bezier(0.16, 1, 0.3, 1), transform 0.45s cubic-bezier(0.16, 1, 0.3, 1), background 0.2s ease;
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-right: none;
    }
    .floating-feedback-btn:hover {
        background: #0369a1;
        padding-right: 16px;
    }
    @media (max-width: 768px) {
        /* Di versi mobile: saat pertama kali dibuka tidak dimunculkan */
        .floating-feedback-btn {
            top: auto;
            bottom: 22px;
            right: 16px;
            border-radius: 9999px;
            border: 1px solid rgba(255, 255, 255, 0.25);
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.55), 0 0 16px rgba(2, 132, 199, 0.4);
            padding: 9px 16px;
            opacity: 0;
            pointer-events: none;
            transform: translateY(28px) scale(0.9);
            will-change: transform, opacity;
        }
        .floating-feedback-btn:hover {
            padding-right: 16px;
            background: #0369a1;
        }
        /* Saat discroll ke bawah, munculkan dengan animasi smooth */
        .floating-feedback-btn.is-visible {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0) scale(1);
        }
    }
    @media (max-width: 640px) {
        .navbar-actions {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            flex-wrap: wrap;
        }
        .navbar-actions .btn {
            padding: 0 10px;
            font-size: 11.5px;
            height: 32px;
            box-sizing: border-box;
            flex: 0 0 auto;
            max-width: 100%;
            white-space: nowrap;
        }
        .app-footer {
            flex-direction: column;
            align-items: flex-start;
            gap: 14px;
        }
    }
    
/* Dropzone Media Uploader & Priority Badges Styles */
.badge-priority-urgent {
    background: rgba(239, 68, 68, 0.22);
    color: #fca5a5;
    border: 1px solid rgba(239, 68, 68, 0.45);
    font-weight: 700;
    letter-spacing: 0.5px;
    box-shadow: 0 0 10px rgba(239, 68, 68, 0.2);
}
.badge-priority-high {
    background: rgba(249, 115, 22, 0.2);
    color: #fdba74;
    border: 1px solid rgba(249, 115, 22, 0.4);
    font-weight: 600;
}
.badge-priority-normal {
    background: rgba(59, 130, 246, 0.15);
    color: #93c5fd;
    border: 1px solid rgba(59, 130, 246, 0.3);
}
.badge-priority-low {
    background: rgba(148, 163, 184, 0.12);
    color: #cbd5e1;
    border: 1px solid rgba(148, 163, 184, 0.25);
}

.dropzone-area {
    border: 2px dashed rgba(255, 255, 255, 0.15);
    border-radius: var(--radius-md);
    background: rgba(255, 255, 255, 0.02);
    padding: 16px 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
    margin-top: 10px;
    position: relative;
}
.dropzone-area:hover, .dropzone-area.dragover {
    border-color: #3b82f6;
    background: rgba(59, 130, 246, 0.08);
}
.dropzone-prompt {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
}
.dropzone-icon {
    color: var(--text-muted);
    transition: transform 0.2s;
}
.dropzone-area:hover .dropzone-icon {
    color: #60a5fa;
    transform: translateY(-2px);
}
.dropzone-text {
    font-size: 13px;
    color: var(--text-primary);
}
.dropzone-browse {
    color: #60a5fa;
    text-decoration: underline;
    font-weight: 500;
}
.dropzone-hint {
    font-size: 11.5px;
    color: var(--text-muted);
}
.dropzone-preview {
    display: flex;
    align-items: center;
    gap: 14px;
    background: rgba(0, 0, 0, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.1);
    padding: 10px 14px;
    border-radius: var(--radius-sm);
    text-align: left;
}
.preview-media {
    width: 54px;
    height: 54px;
    border-radius: var(--radius-sm);
    background: #111;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
}
.preview-media img, .preview-media video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.preview-info {
    flex: 1;
    min-width: 0;
}
.preview-filename {
    font-size: 13px;
    font-weight: 500;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.preview-meta {
    font-size: 11.5px;
    color: var(--text-muted);
    margin-top: 2px;
}
.preview-progress-bar {
    height: 4px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 2px;
    overflow: hidden;
    margin-top: 6px;
}
.preview-progress-fill {
    height: 100%;
    width: 0%;
    background: #3b82f6;
    transition: width 0.2s ease;
}
.preview-remove-btn {
    color: #ef4444 !important;
    padding: 6px !important;
}

/* Multi-file Attachments Grid & Item Chips */
.dropzone-files-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 10px;
    margin-top: 12px;
}
.dropzone-file-item {
    display: flex;
    align-items: center;
    gap: 10px;
    background: rgba(15, 23, 42, 0.7);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: var(--radius-sm);
    padding: 8px 10px;
    text-align: left;
    position: relative;
    transition: border-color 0.2s;
}
.dropzone-file-item:hover {
    border-color: rgba(59, 130, 246, 0.4);
}
.dropzone-file-thumb {
    width: 44px;
    height: 44px;
    border-radius: 6px;
    background: #0b0f19;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
    font-size: 20px;
}
.dropzone-file-thumb img, .dropzone-file-thumb video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.dropzone-file-info {
    flex: 1;
    min-width: 0;
}
.dropzone-file-name {
    font-size: 12.5px;
    font-weight: 500;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dropzone-file-meta {
    font-size: 11px;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 2px;
}
.dropzone-file-del {
    color: #f87171 !important;
    padding: 4px !important;
    opacity: 0.8;
    transition: opacity 0.2s;
    background: transparent;
    border: none;
    cursor: pointer;
    flex-shrink: 0;
}
.dropzone-file-del:hover {
    opacity: 1;
    color: #ef4444 !important;
}

/* Multi-Attachment Presentation on Task Cards */
.task-attachments-block {
    background: rgba(0, 0, 0, 0.3);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm);
    padding: 12px 14px;
    margin-top: 8px;
    margin-bottom: 12px;
}
.task-attachments-header {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
    padding-bottom: 6px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}
.task-attachments-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 10px;
}
.task-attachment-card {
    background: var(--bg-surface-inset);
    border: 1px solid var(--border-subtle);
    border-radius: 6px;
    padding: 8px 10px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    transition: transform 0.15s, border-color 0.15s;
}
.task-attachment-card:hover {
    border-color: rgba(59, 130, 246, 0.4);
    transform: translateY(-1px);
}
.task-attachment-card-thumb {
    width: 100%;
    height: 110px;
    border-radius: 4px;
    background: #000;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}
.task-attachment-card-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.task-attachment-card-thumb video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.task-attachment-card-thumb .doc-icon {
    font-size: 36px;
}
.task-attachment-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
    font-size: 11.5px;
}
.task-attachment-card-name {
    color: #fff;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
}

</style>
</head>
<body>

<!-- Hidden Flash Containers for Sonner Toast Engine -->
<?php if (!empty($successMsg)): ?>
    <div id="flashSuccessMessage" style="display:none;"><?= htmlspecialchars(strip_tags($successMsg)) ?></div>
<?php endif; ?>
<?php if (!empty($errorMsg)): ?>
    <div id="flashErrorMessage" style="display:none;"><?= htmlspecialchars(strip_tags($errorMsg)) ?></div>
<?php endif; ?>

<div class="container">
    <!-- Navbar Header -->
    <header class="navbar">
        <a href="./" class="navbar-brand">
            <span class="brand-icon-wrapper">
                <?= renderSvg('bell', 16) ?>
            </span>
            <span>tele<span class="brand-slash">/</span>vs<span class="brand-slash">/</span>code</span>
        </a>
        <div class="navbar-actions">
            <?php if (!empty($canShowDonation)): ?>
                <!-- Tombol Donasi (sama seperti Tambah Manual) -->
                <button type="button" class="btn btn-secondary btn-sm" onclick="openDonationModal()" title="<?= __t('Dukung Pengembangan Tele-vs-Code', 'Support Tele-vs-Code Development') ?>">
                    <span style="font-size: 12px;">❤️</span>
                    <span><?= __t('Donasi', 'Donate') ?></span>
                </button>
            <?php endif; ?>

            <!-- Tombol About (sama seperti Tambah Manual) -->
            <button type="button" class="btn btn-secondary btn-sm" onclick="openAboutModal()" title="<?= __t('Profil Telegram VS Code & Tim Pengembang', 'Profile of Telegram VS Code & Developer Team') ?>">
                <?= renderSvg('user', 13) ?>
                <span><?= __t('Profil', 'Profile') ?></span>
            </button>

            <!-- Language Switcher (Dual Language ID / EN) -->
            <div class="lang-switcher" title="<?= __t('Ganti Bahasa', 'Switch Language') ?>">
                <a href="?lang=id<?= !empty($_SERVER['QUERY_STRING']) ? '&' . preg_replace('/(^|&)lang=[^&]*/', '', $_SERVER['QUERY_STRING']) : '' ?>" class="<?= $currentLang === 'id' ? 'active' : '' ?>">ID</a>
                <a href="?lang=en<?= !empty($_SERVER['QUERY_STRING']) ? '&' . preg_replace('/(^|&)lang=[^&]*/', '', $_SERVER['QUERY_STRING']) : '' ?>" class="<?= $currentLang === 'en' ? 'active' : '' ?>">EN</a>
            </div>

            <!-- Tombol Unduh VSIX (sama seperti Tambah Manual) -->
            <a class="btn btn-secondary btn-sm" href="?action=download_vsix" title="<?= __t('Download ekstensi VS Code offline (.VSIX)', 'Download offline VS Code extension (.VSIX)') ?>">
                <?= renderSvg('download', 13) ?>
                <span>Unduh VSIX</span>
            </a>

            <?php if ($currentUser): ?>
                <button type="button" class="btn btn-secondary btn-sm" onclick="openProfileModal()" title="<?= __t('Edit Profil & Password', 'Edit Profile & Password') ?>">
                    <?= renderSvg('user', 13) ?>
                    <span><?= htmlspecialchars(!empty($currentUser['name']) ? $currentUser['name'] : explode('@', $currentUser['email'])[0]) ?></span>
                    <?php if (!empty($isSuperAdmin)): ?>
                        <span class="badge badge-pending" style="height: 18px; font-size: 9.5px; padding: 0 5px; margin-left: 4px;">ADMIN</span>
                    <?php endif; ?>
                </button>
                <a class="btn btn-secondary btn-sm" href="?action=logout" title="<?= __t('Keluar dari akun', 'Sign Out') ?>">
                    <?= renderSvg('logout', 13) ?>
                    <span><?= __t('Keluar', 'Sign Out') ?></span>
                </a>
            <?php else: ?>
                <button type="button" class="btn btn-primary btn-sm" onclick="openAuthModal('login')" title="<?= __t('Masuk ke Dashboard', 'Sign In to Dashboard') ?>">
                    <?= renderSvg('user', 13) ?>
                    <span><?= __t('Masuk', 'Sign In') ?></span>
                </button>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!empty($currentUser)): ?>
        <!-- ==================================================================== -->
        <!-- USER DASHBOARD (iOS Minimalist Monochrome)                           -->
        <!-- ==================================================================== -->

        <?php
            $tzDash = !empty($currentUser['timezone']) ? $currentUser['timezone'] : 'Asia/Jakarta';
            try { $nowDash = new DateTime('now', new DateTimeZone($tzDash)); } catch (Exception $e) { $nowDash = new DateTime(); }
            $hDash = (int)$nowDash->format('G');
            $greet = $currentLang === 'en' 
                ? ($hDash < 12 ? 'Good morning' : ($hDash < 18 ? 'Good afternoon' : 'Good evening'))
                : ($hDash < 11 ? 'Selamat pagi' : ($hDash < 15 ? 'Selamat siang' : ($hDash < 19 ? 'Selamat sore' : 'Selamat malam')));
            $nameDash = ucfirst(strtok($currentUser['email'], '@'));
            $dayName = $currentLang === 'en'
                ? ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][(int)$nowDash->format('w')]
                : ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'][(int)$nowDash->format('w')];
            $openTasks = (int)$taskStats['PENDING'] + (int)$taskStats['IN_PROGRESS'];
            $openTasksMsg = $openTasks > 0 
                ? __t("$openTasks tugas masih berjalan.", "$openTasks tasks in progress.") 
                : __t("Semua antrean beres.", "All tasks completed.");
        ?>
        <div class="dash-head">
            <div>
                <div class="dash-eyebrow"><span class="dot"></span><?= $dayName ?>, <?= $nowDash->format('d.m.Y') ?> &middot; <?= $nowDash->format('H:i') ?> <?= htmlspecialchars(getTimezoneAbbr($tzDash)) ?></div>
                <h1 class="dash-greeting"><?= $greet ?>, <?= htmlspecialchars($nameDash) ?>.<br><em><?= $openTasksMsg ?></em></h1>
            </div>
        </div>

        <!-- Main Navigation (liquid tab bar) -->
        <nav class="main-nav" aria-label="Navigasi utama">
            <a href="?tab=tasks<?= $filterProjectId ? "&filter_project=$filterProjectId" : "" ?>" class="nav-pill <?= $currentTab === 'tasks' ? 'active' : '' ?>">
                <?= renderSvg('tasks', 14) ?>
                <span><?= __t('Antrean Tugas', 'Task Queue') ?> (<?= $taskStats['TOTAL'] ?>)</span>
            </a>
            <a href="?tab=projects" class="nav-pill <?= $currentTab === 'projects' ? 'active' : '' ?>">
                <?= renderSvg('settings', 14) ?>
                <span><?= __t('Pengaturan & Proyek', 'Settings & Projects') ?> (<?= count($projects) ?>)</span>
            </a>
            <a href="?tab=bantuan" class="nav-pill <?= $currentTab === 'bantuan' ? 'active' : '' ?>">
                <?= renderSvg('help', 14) ?>
                <span><?= __t('Bantuan & Panduan', 'Help & Guides') ?></span>
            </a>
            <?php if (!empty($isSuperAdmin)): ?>
                <a href="?tab=admin" class="nav-pill nav-pill-admin <?= $currentTab === 'admin' ? 'active' : '' ?>">
                    <?= renderSvg('shield', 14) ?>
                    <span>Superadmin</span>
                </a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
