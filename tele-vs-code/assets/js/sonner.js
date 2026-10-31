/**
 * Sonner - Minimalist iOS-style Toast Notification Engine
 * Inspired by Emil Kowalski's Sonner for React, crafted in pure Vanilla JS.
 * Zero external dependencies.
 */
(function (root, factory) {
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.sonner = factory();
        root.toast = root.sonner;
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    let container = null;
    let toasts = [];
    const MAX_VISIBLE = 3;
    const DEFAULT_DURATION = 4000;

    const SVG_ICONS = {
        success: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>',
        error: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#f43f5e" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        warning: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        info: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#38bdf8" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
        loading: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#a1a1aa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="sonner-spin"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>',
        close: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
    };

    function initContainer() {
        if (container && document.body.contains(container)) return;
        container = document.createElement('section');
        container.className = 'sonner-toaster';
        container.setAttribute('aria-label', 'Notifications');
        container.setAttribute('tabindex', '-1');
        document.body.appendChild(container);

        // Hover expand stacking
        container.addEventListener('mouseenter', () => container.classList.add('sonner-expanded'));
        container.addEventListener('mouseleave', () => container.classList.remove('sonner-expanded'));
    }

    function createToastElement(item) {
        const el = document.createElement('div');
        el.className = `sonner-toast sonner-${item.type}`;
        el.setAttribute('data-id', item.id);
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', item.type === 'error' ? 'assertive' : 'polite');

        const iconHtml = SVG_ICONS[item.type] ? `<div class="sonner-icon">${SVG_ICONS[item.type]}</div>` : '';
        
        let descHtml = '';
        if (item.description) {
            descHtml = `<div class="sonner-description">${escapeHtml(item.description)}</div>`;
        }

        let actionHtml = '';
        if (item.action && typeof item.action.onClick === 'function') {
            actionHtml = `<button type="button" class="sonner-action-btn">${escapeHtml(item.action.label || 'Action')}</button>`;
        }

        el.innerHTML = `
            ${iconHtml}
            <div class="sonner-content">
                <div class="sonner-title">${item.title}</div>
                ${descHtml}
            </div>
            ${actionHtml}
            <button type="button" class="sonner-close-btn" aria-label="Tutup notifikasi">
                ${SVG_ICONS.close}
            </button>
        `;

        // Event listeners
        const closeBtn = el.querySelector('.sonner-close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                dismiss(item.id);
            });
        }

        if (item.action && typeof item.action.onClick === 'function') {
            const actBtn = el.querySelector('.sonner-action-btn');
            if (actBtn) {
                actBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    item.action.onClick();
                    dismiss(item.id);
                });
            }
        }

        return el;
    }

    function updateStackPositions() {
        if (!container) return;
        const visibleToasts = toasts.slice(-MAX_VISIBLE);
        const total = visibleToasts.length;

        visibleToasts.forEach((item, index) => {
            const offsetFromTop = total - 1 - index;
            const el = item.element;
            if (!el) return;

            el.style.setProperty('--index', offsetFromTop);
            el.style.setProperty('--total', total);
            el.setAttribute('data-visible', 'true');
            el.setAttribute('data-front', offsetFromTop === 0 ? 'true' : 'false');
        });

        // Hide older toasts out of MAX_VISIBLE limit
        const hiddenToasts = toasts.slice(0, Math.max(0, toasts.length - MAX_VISIBLE));
        hiddenToasts.forEach(item => {
            if (item.element) {
                item.element.setAttribute('data-visible', 'false');
            }
        });
    }

    function dismiss(id) {
        const idx = toasts.findIndex(t => t.id === id);
        if (idx === -1) return;
        const item = toasts[idx];

        if (item.timer) clearTimeout(item.timer);

        if (item.element) {
            item.element.classList.add('sonner-leaving');
            item.element.addEventListener('animationend', () => {
                if (item.element && item.element.parentNode) {
                    item.element.parentNode.removeChild(item.element);
                }
            }, { once: true });
        }

        toasts.splice(idx, 1);
        updateStackPositions();
    }

    function show(type, message, options = {}) {
        initContainer();

        const id = 'toast_' + Math.random().toString(36).substring(2, 9);
        const item = {
            id,
            type,
            title: message,
            description: options.description || '',
            action: options.action || null,
            duration: options.duration !== undefined ? options.duration : DEFAULT_DURATION
        };

        const el = createToastElement(item);
        item.element = el;
        container.appendChild(el);
        toasts.push(item);

        // Force reflow for entrance animation
        void el.offsetHeight;
        el.classList.add('sonner-entering');

        updateStackPositions();

        if (item.duration > 0 && type !== 'loading') {
            item.timer = setTimeout(() => {
                dismiss(id);
            }, item.duration);
        }

        return id;
    }

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    const sonner = {
        toast: (msg, opts) => show('info', msg, opts),
        success: (msg, opts) => show('success', msg, opts),
        error: (msg, opts) => show('error', msg, opts),
        warning: (msg, opts) => show('warning', msg, opts),
        info: (msg, opts) => show('info', msg, opts),
        loading: (msg, opts) => show('loading', msg, opts),
        dismiss: (id) => dismiss(id),
        clear: () => {
            [...toasts].forEach(t => dismiss(t.id));
        }
    };

    // Replace native window.alert with Sonner Toast
    if (typeof window !== 'undefined') {
        window.alert = function (msg) {
            sonner.info(String(msg));
        };
    }

    return sonner;
}));

