/**
 * Tele-vs-Code — Liquid Indicator & Page Transitions
 * ---------------------------------------------------------------
 * - Gooey "liquid" blob that flows between tabs / segmented buttons.
 *   The blob stretches toward its target (leading edge first), squashes,
 *   and leaves a trailing droplet that merges back through an SVG
 *   goo filter.
 * - Page navigation: cross-document View Transitions where supported
 *   (directional by tab order), graceful fade fallback elsewhere.
 */
(function () {
    'use strict';

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const supportsVT = 'onpagereveal' in window;
    const STORE_KEY = 'tvc:tab-index';

    // ---------------------------------------------------------------------
    // SVG goo filter (injected once)
    // ---------------------------------------------------------------------
    function injectGooFilter() {
        if (document.getElementById('liquid-goo')) return;
        const ns = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('width', '0');
        svg.setAttribute('height', '0');
        svg.style.position = 'absolute';
        svg.innerHTML =
            '<defs><filter id="liquid-goo" x="-20%" y="-50%" width="140%" height="200%">' +
            '<feGaussianBlur in="SourceGraphic" stdDeviation="5" result="b"/>' +
            '<feColorMatrix in="b" mode="matrix" values="1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  0 0 0 20 -9" result="g"/>' +
            '<feComposite in="SourceGraphic" in2="g" operator="atop"/>' +
            '</filter></defs>';
        document.body.appendChild(svg);
    }

    // ---------------------------------------------------------------------
    // Liquid controller
    // ---------------------------------------------------------------------
    class Liquid {
        constructor(host, itemSelector) {
            this.host = host;
            this.itemSelector = itemSelector;
            this.current = null;

            host.classList.add('liquid-host');
            this.layer = document.createElement('div');
            this.layer.className = 'liquid-layer';
            this.blob = document.createElement('span');
            this.blob.className = 'liquid-blob';
            this.drop = document.createElement('span');
            this.drop.className = 'liquid-drop';
            this.layer.append(this.drop, this.blob);
            host.prepend(this.layer);

            const active = this.activeItem();
            if (active) this.place(active);
            host.classList.add('liquid-ready');

            // Keep in place on resize / font load
            const re = () => { const a = this.activeItem(); if (a) this.place(a); };
            window.addEventListener('resize', re, { passive: true });
            if (document.fonts && document.fonts.ready) document.fonts.ready.then(re);

            // Observe class changes so existing switchers (switchAuthTab, switchSubTab)
            // automatically drive the liquid without code changes.
            const mo = new MutationObserver(() => {
                const a = this.activeItem();
                if (a && a !== this.current) this.flowTo(a);
                else if (!a) this.hide();
            });
            this.items().forEach(el => mo.observe(el, { attributes: true, attributeFilter: ['class'] }));
        }

        items() { return Array.from(this.host.querySelectorAll(this.itemSelector)); }
        activeItem() { return this.items().find(el => el.classList.contains('active')) || null; }

        geom(el) {
            return { x: el.offsetLeft, w: el.offsetWidth, h: el.offsetHeight, y: el.offsetTop };
        }

        sizeLayer() {
            this.layer.style.width = this.host.scrollWidth + 'px';
        }

        setBox(node, g) {
            node.style.width = g.w + 'px';
            node.style.top = g.y + 'px';
            node.style.height = g.h + 'px';
            node.style.bottom = 'auto';
            node.style.transform = 'translateX(' + g.x + 'px)';
        }

        place(el) {
            this.sizeLayer();
            const g = this.geom(el);
            this.blob.getAnimations().forEach(a => a.cancel());
            this.setBox(this.blob, g);
            this.blob.style.opacity = '1';
            this.current = el;
        }

        hide() {
            this.blob.style.opacity = '0';
            this.current = null;
        }

        flowTo(el) {
            if (!this.current || reduceMotion || !this.blob.animate) { this.place(el); return; }
            this.sizeLayer();

            const a = this.geom(this.current);
            const b = this.geom(el);
            if (a.x === b.x && a.w === b.w) { this.place(el); return; }

            const dir = b.x > a.x ? 1 : -1;
            const left = Math.min(a.x, b.x);
            const right = Math.max(a.x + a.w, b.x + b.w);
            const span = right - left;
            const dist = Math.abs(b.x - a.x);
            const duration = Math.min(720, 420 + dist * 0.6);

            // Leading edge races ahead, trailing edge follows: liquid stretch.
            const midX = dir > 0 ? a.x + span * 0.12 : left;
            const midW = span * 0.88;

            this.setBox(this.blob, b);
            this.blob.style.opacity = '1';
            this.blob.animate([
                { transform: `translateX(${a.x}px) scaleY(1)`, width: a.w + 'px' },
                { transform: `translateX(${midX}px) scaleY(0.78)`, width: midW + 'px', offset: 0.42 },
                { transform: `translateX(${b.x - dir * 4}px) scaleY(1.06)`, width: (b.w + 8) + 'px', offset: 0.78 },
                { transform: `translateX(${b.x}px) scaleY(1)`, width: b.w + 'px' }
            ], { duration, easing: 'cubic-bezier(.45,.05,.2,1)' });

            // Trailing droplet: detaches from origin then is absorbed.
            const dSize = Math.min(a.h, 22);
            const dY = a.y + (a.h - dSize) / 2;
            this.drop.style.top = dY + 'px';
            this.drop.style.height = dSize + 'px';
            this.drop.style.bottom = 'auto';
            this.drop.style.width = dSize + 'px';
            const startX = a.x + a.w / 2 - dSize / 2;
            const endX = b.x + b.w / 2 - dSize / 2;
            this.drop.animate([
                { transform: `translateX(${startX}px) scale(1.4)`, opacity: 1 },
                { transform: `translateX(${startX + (endX - startX) * 0.35}px) scale(0.9)`, opacity: 1, offset: 0.55 },
                { transform: `translateX(${endX}px) scale(0.2)`, opacity: 0 }
            ], { duration: duration * 1.1, easing: 'cubic-bezier(.6,0,.2,1)' });

            this.current = el;
        }
    }

    // ---------------------------------------------------------------------
    // Page transitions
    // ---------------------------------------------------------------------
    function tabIndexOf(href) {
        const nav = document.querySelector('.main-nav');
        if (!nav) return -1;
        const links = Array.from(nav.querySelectorAll('.nav-pill'));
        try {
            const target = new URL(href, location.href).searchParams.get('tab') || 'tasks';
            return links.findIndex(l => (new URL(l.href).searchParams.get('tab') || 'tasks') === target);
        } catch (e) { return -1; }
    }

    function currentTabIndex() {
        const nav = document.querySelector('.main-nav');
        if (!nav) return -1;
        return Array.from(nav.querySelectorAll('.nav-pill')).findIndex(l => l.classList.contains('active'));
    }

    function shouldIntercept(a, e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return false;
        if (!a || a.target === '_blank' || a.hasAttribute('download')) return false;
        const href = a.getAttribute('href') || '';
        if (!href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:')) return false;
        if (/download_vsix/.test(href)) return false;
        try { return new URL(a.href).origin === location.origin; } catch (err) { return false; }
    }

    function initNavigation(tabLiquid) {
        // Direction for cross-document transitions
        window.addEventListener('pagereveal', (e) => {
            if (!e.viewTransition) return;
            let from = -1;
            try { from = parseInt(sessionStorage.getItem(STORE_KEY) || '-1', 10); } catch (err) { /* noop */ }
            const to = currentTabIndex();
            if (from >= 0 && to >= 0 && from !== to) {
                e.viewTransition.types.add(to > from ? 'forward' : 'back');
            }
        });

        document.addEventListener('click', (e) => {
            const a = e.target.closest('a');
            if (!shouldIntercept(a, e)) return;

            try { sessionStorage.setItem(STORE_KEY, String(currentTabIndex())); } catch (err) { /* noop */ }

            const isTab = tabLiquid && a.classList.contains('nav-pill');

            if (isTab && !a.classList.contains('active')) {
                // Let the liquid flow first, then navigate.
                e.preventDefault();
                tabLiquid.items().forEach(i => i.classList.remove('active'));
                a.classList.add('active');
                a.setAttribute('aria-current', 'page');
                setTimeout(() => go(a.href), reduceMotion ? 0 : 260);
                return;
            }

            if (!supportsVT && !reduceMotion) {
                e.preventDefault();
                go(a.href);
            }
        });

        function go(url) {
            if (!supportsVT && !reduceMotion) {
                document.documentElement.classList.add('is-leaving');
                setTimeout(() => { location.href = url; }, 200);
            } else {
                location.href = url;
            }
        }

        // Back/forward cache: undo leaving state
        window.addEventListener('pageshow', (e) => {
            if (e.persisted) document.documentElement.classList.remove('is-leaving');
        });
    }

    // ---------------------------------------------------------------------
    // Boot
    // ---------------------------------------------------------------------
    function boot() {
        injectGooFilter();
        let tabLiquid = null;
        const mainNav = document.querySelector('.main-nav');
        if (mainNav) {
            const active = mainNav.querySelector('.nav-pill.active');
            if (active) active.setAttribute('aria-current', 'page');
            tabLiquid = new Liquid(mainNav, '.nav-pill');
            // keep active tab visible on narrow screens
            if (active && mainNav.scrollWidth > mainNav.clientWidth) {
                mainNav.scrollLeft = active.offsetLeft - 16;
            }
        }
        document.querySelectorAll('.auth-segmented').forEach(h => new Liquid(h, '.auth-segmented-btn'));
        document.querySelectorAll('.sub-nav').forEach(h => new Liquid(h, '.sub-pill'));
        initNavigation(tabLiquid);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();

