/**
 * Tele-vs-Code UI Application Controller
 * Handles iOS-style tab transitions, dialog modals, clipboard utilities,
 * and seamless Sonner toast notifications.
 */

(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // 1. Auth Segmented Control Switcher (Login / Register / Forgot)
    // -------------------------------------------------------------------------
    window.switchAuthTab = function (tab) {
        const boxLogin = document.getElementById('boxLogin');
        const boxRegister = document.getElementById('boxRegister');
        const boxForgot = document.getElementById('boxForgot');
        const boxReset = document.getElementById('boxResetPass');

        const btnLogin = document.getElementById('btnAuthLogin');
        const btnRegister = document.getElementById('btnAuthRegister');

        // Hide all with smooth animation reset
        [boxLogin, boxRegister, boxForgot, boxReset].forEach(el => {
            if (el) {
                el.style.display = 'none';
                el.classList.remove('auth-active');
            }
        });

        // Activate selected
        let targetEl = null;
        if (tab === 'login') targetEl = boxLogin;
        else if (tab === 'register') targetEl = boxRegister;
        else if (tab === 'forgot') targetEl = boxForgot;
        else if (tab === 'reset') targetEl = boxReset;

        if (targetEl) {
            targetEl.style.display = 'block';
            targetEl.classList.add('auth-active');
        }

        // Update segmented button pills
        if (btnLogin && btnRegister) {
            if (tab === 'login') {
                btnLogin.classList.add('active');
                btnRegister.classList.remove('active');
            } else if (tab === 'register') {
                btnLogin.classList.remove('active');
                btnRegister.classList.add('active');
            } else {
                btnLogin.classList.remove('active');
                btnRegister.classList.remove('active');
            }
        }
    };

    // -------------------------------------------------------------------------
    // 2. Sub-Tab Switcher (e.g. Bantuan: Perintah vs Panduan)
    // -------------------------------------------------------------------------
    window.switchSubTab = function (subTabName) {
        const paneCommands = document.getElementById('subPaneCommands');
        const paneGuide = document.getElementById('subPaneGuide');
        const btnCommands = document.getElementById('btnSubCommands');
        const btnGuide = document.getElementById('btnSubGuide');

        if (subTabName === 'commands') {
            if (paneCommands) paneCommands.style.display = 'block';
            if (paneGuide) paneGuide.style.display = 'none';
            if (btnCommands) btnCommands.classList.add('active');
            if (btnGuide) btnGuide.classList.remove('active');
        } else {
            if (paneCommands) paneCommands.style.display = 'none';
            if (paneGuide) paneGuide.style.display = 'block';
            if (btnCommands) btnCommands.classList.remove('active');
            if (btnGuide) btnGuide.classList.add('active');
        }
    };

    // -------------------------------------------------------------------------
    // 3. Task Detail Modal & Result Manager
    // -------------------------------------------------------------------------
    window.openTaskDetail = function (task, tzAbbr) {
        if (!task) return;

        const modal = document.getElementById('modalTaskDetail');
        if (!modal) return;

        const mTaskId = document.getElementById('mTaskId');
        const mFormTaskId = document.getElementById('mFormTaskId');
        const mTaskProject = document.getElementById('mTaskProject');
        const mTaskSender = document.getElementById('mTaskSender');
        const mTaskTime = document.getElementById('mTaskTime');
        const mTaskInstruction = document.getElementById('mTaskInstruction');
        const mTaskCommand = document.getElementById('mTaskCommand');
        const mTaskResult = document.getElementById('mTaskResult');
        const mTaskStatusSelect = document.getElementById('mTaskStatusSelect');
        const badgeContainer = document.getElementById('mTaskStatusBadge');

        if (mTaskId) mTaskId.textContent = task.id || '';
        if (mFormTaskId) mFormTaskId.value = task.id || '';
        if (mTaskProject) mTaskProject.textContent = task.project_name || 'Tanpa Proyek';
        if (mTaskSender) mTaskSender.textContent = task.sender || 'Unknown';
        if (mTaskTime) mTaskTime.textContent = (task.created_at || '') + ' ' + (tzAbbr || 'WIB');
        if (mTaskInstruction) mTaskInstruction.textContent = task.instruction || '';
        if (mTaskCommand) mTaskCommand.value = task.command || '';

        const resVal = task.result || task.notes || '';
        if (mTaskResult) mTaskResult.value = resVal;

        if (badgeContainer) {
            badgeContainer.innerHTML = '';
            let badgeClass = 'badge-pending';
            if (task.status === 'IN_PROGRESS') badgeClass = 'badge-progress';
            else if (task.status === 'DONE') badgeClass = 'badge-done';
            else if (task.status === 'FAILED') badgeClass = 'badge-failed';

            const badgeSpan = document.createElement('span');
            badgeSpan.className = 'badge ' + badgeClass;
            badgeSpan.textContent = task.status || 'PENDING';
            badgeContainer.appendChild(badgeSpan);
        }

        if (mTaskStatusSelect) {
            mTaskStatusSelect.value = task.status || 'PENDING';
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    };

    window.closeTaskDetail = function () {
        const modal = document.getElementById('modalTaskDetail');
        if (modal) modal.style.display = 'none';
        document.body.style.overflow = '';
    };

    window.promptMarkDone = function (taskId) {
        const modal = document.getElementById('modalMarkDone');
        const idInput = document.getElementById('markDoneTaskId');
        const label = document.getElementById('markDoneTaskLabel');
        if (modal && idInput) {
            idInput.value = taskId;
            if (label) label.textContent = taskId;
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        } else if (window.sonner) {
            window.sonner.error('Modal tandai selesai tidak dapat dimuat.');
        }
    };

    window.closeMarkDoneModal = function () {
        const modal = document.getElementById('modalMarkDone');
        if (modal) modal.style.display = 'none';
        document.body.style.overflow = '';
    };

    window.promptResetPass = function (uid, email) {
        const modal = document.getElementById('modalResetPass');
        const idInput = document.getElementById('resetPassUserId');
        const emailLabel = document.getElementById('resetPassUserEmail');
        const passInput = document.getElementById('resetPassNewPassword');
        if (modal && idInput) {
            idInput.value = uid;
            if (emailLabel) emailLabel.textContent = email;
            if (passInput) passInput.value = '';
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        } else if (window.sonner) {
            window.sonner.error('Modal reset password tidak dapat dimuat.');
        }
    };

    window.closeResetPassModal = function () {
        const modal = document.getElementById('modalResetPass');
        if (modal) modal.style.display = 'none';
        document.body.style.overflow = '';
    };

    // -------------------------------------------------------------------------
    // 4. Clipboard Utility with Sonner Feedback
    // -------------------------------------------------------------------------
    window.copyText = function (text, label) {
        if (!text) return;
        const itemLabel = label || 'Teks';
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(() => {
                if (window.sonner) {
                    window.sonner.success(`${itemLabel} berhasil disalin!`);
                }
            }).catch(() => {
                fallbackCopy(text, itemLabel);
            });
        } else {
            fallbackCopy(text, itemLabel);
        }
    };

    function fallbackCopy(text, itemLabel) {
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            if (window.sonner) {
                window.sonner.success(`${itemLabel} berhasil disalin!`);
            }
        } catch (e) {
            if (window.sonner) {
                window.sonner.error('Gagal menyalin ke clipboard');
            }
        }
    }

    window.copyResultText = function () {
        const el = document.getElementById('mTaskResult');
        if (el && el.value) {
            window.copyText(el.value, 'Hasil pekerjaan');
        } else {
            if (window.sonner) window.sonner.info('Belum ada hasil pekerjaan untuk disalin');
        }
    };

    // -------------------------------------------------------------------------
    // 5. Global Keyboard Shortcuts (Escape to close modals)
    // -------------------------------------------------------------------------
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            window.closeTaskDetail();
            window.closeMarkDoneModal();
        }
    });

    // -------------------------------------------------------------------------
    // 6. Flash Messages to Sonner Toast on Page Load
    // -------------------------------------------------------------------------
    window.addEventListener('DOMContentLoaded', function () {
        const flashSuccess = document.getElementById('flashSuccessMessage');
        const flashError = document.getElementById('flashErrorMessage');

        if (window.sonner) {
            if (flashSuccess && flashSuccess.textContent.trim()) {
                window.sonner.success(flashSuccess.textContent.trim(), { duration: 5000 });
            }
            if (flashError && flashError.textContent.trim()) {
                window.sonner.error(flashError.textContent.trim(), { duration: 6000 });
            }
        }
    });

})();
