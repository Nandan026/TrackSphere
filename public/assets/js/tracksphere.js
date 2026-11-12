/**
 * TrackSphere UI behaviours
 *  - Sidebar toggle (mobile off-canvas)
 *  - Guide panel collapse state persisted in localStorage
 *  - Confirm dialogs for destructive actions
 *  - Flash alert auto-dismiss
 */
(function () {
    'use strict';

    var STORAGE_PREFIX = 'tracksphere.guide.';
    var FLASH_TIMEOUT_MS = 6000;
    var MOBILE_BREAKPOINT = 992;

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function safeStorage() {
        try {
            var k = '__ts_test__';
            window.localStorage.setItem(k, '1');
            window.localStorage.removeItem(k);
            return window.localStorage;
        } catch (e) {
            return null;
        }
    }

    var storage = safeStorage();

    /* ---------------------------------------------------------------
       Sidebar
       --------------------------------------------------------------- */
    function initSidebar() {
        var app = document.querySelector('.ts-app');
        if (!app) { return; }

        var toggles = document.querySelectorAll('[data-ts-sidebar-toggle]');
        var backdrop = document.querySelector('.ts-backdrop');

        function setOpen(open) {
            app.classList.toggle('ts-sidebar-open', open);
            toggles.forEach(function (btn) {
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.body.style.overflow = open && window.innerWidth < MOBILE_BREAKPOINT ? 'hidden' : '';
        }

        toggles.forEach(function (btn) {
            btn.addEventListener('click', function () {
                setOpen(!app.classList.contains('ts-sidebar-open'));
            });
        });

        if (backdrop) {
            backdrop.addEventListener('click', function () { setOpen(false); });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { setOpen(false); }
        });

        // Close drawer after navigating on mobile; reset when resizing up.
        document.querySelectorAll('.ts-sidebar .ts-nav-link').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth < MOBILE_BREAKPOINT) { setOpen(false); }
            });
        });
        window.addEventListener('resize', function () {
            if (window.innerWidth >= MOBILE_BREAKPOINT) { setOpen(false); }
        });

        // Keep the active link in view inside the scrollable nav.
        var active = document.querySelector('.ts-sidebar .ts-nav-link.active');
        if (active && active.scrollIntoView) {
            active.scrollIntoView({ block: 'nearest' });
        }
    }

    /* ---------------------------------------------------------------
       Guide panel (Bootstrap collapse + localStorage persistence)
       --------------------------------------------------------------- */
    function initGuides() {
        document.querySelectorAll('.ts-guide').forEach(function (guide) {
            var panel = guide.querySelector('.collapse');
            var button = guide.querySelector('[data-bs-toggle="collapse"]');
            if (!panel || !panel.id) { return; }

            var key = STORAGE_PREFIX + panel.id;
            var saved = storage ? storage.getItem(key) : null;

            // Default is expanded on first visit; honour stored preference afterwards.
            var shouldShow = saved !== 'closed';
            panel.classList.toggle('show', shouldShow);
            if (button) { button.setAttribute('aria-expanded', shouldShow ? 'true' : 'false'); }

            // Reveal now that state is known (prevents a flash of wrong state).
            guide.classList.remove('ts-guide--pending');

            panel.addEventListener('shown.bs.collapse', function () {
                if (storage) { storage.setItem(key, 'open'); }
            });
            panel.addEventListener('hidden.bs.collapse', function () {
                if (storage) { storage.setItem(key, 'closed'); }
            });
        });
    }

    /* ---------------------------------------------------------------
       Confirm destructive actions
       Usage: <form data-confirm="Delete this customer?"> or
              <button data-confirm="..."> / <a data-confirm="...">
       --------------------------------------------------------------- */
    function initConfirm() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!(form instanceof HTMLFormElement)) { return; }
            var msg = form.getAttribute('data-confirm');
            if (msg && !window.confirm(msg)) {
                e.preventDefault();
                e.stopPropagation();
            }
        }, true);

        document.addEventListener('click', function (e) {
            var el = e.target.closest('a[data-confirm], button[data-confirm]');
            if (!el) { return; }
            var msg = el.getAttribute('data-confirm');
            if (msg && !window.confirm(msg)) {
                e.preventDefault();
                e.stopPropagation();
            }
        }, true);
    }

    /* ---------------------------------------------------------------
       Flash alerts
       --------------------------------------------------------------- */
    function dismissFlash(el) {
        if (!el || el.classList.contains('ts-flash--leaving')) { return; }
        el.classList.add('ts-flash--leaving');
        window.setTimeout(function () {
            if (el.parentNode) { el.parentNode.removeChild(el); }
        }, 380);
    }

    function initFlash() {
        document.querySelectorAll('.ts-flash').forEach(function (el) {
            var closeBtn = el.querySelector('[data-ts-dismiss]');
            if (closeBtn) {
                closeBtn.addEventListener('click', function () { dismissFlash(el); });
            }

            // Errors and validation lists stay until dismissed.
            if (el.hasAttribute('data-sticky')) { return; }

            var timer = window.setTimeout(function () { dismissFlash(el); }, FLASH_TIMEOUT_MS);
            el.addEventListener('mouseenter', function () { window.clearTimeout(timer); });
            el.addEventListener('mouseleave', function () {
                timer = window.setTimeout(function () { dismissFlash(el); }, 2500);
            });
        });
    }

    /* ---------------------------------------------------------------
       Small helpers
       --------------------------------------------------------------- */
    function initDemoFill() {
        document.querySelectorAll('[data-ts-fill]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var email = document.querySelector('input[name="email"]');
                var pass = document.querySelector('input[name="password"]');
                if (email) { email.value = btn.getAttribute('data-email') || ''; }
                if (pass) { pass.value = btn.getAttribute('data-password') || ''; }
                if (pass) { pass.focus(); }
            });
        });

        document.querySelectorAll('[data-ts-toggle-password]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.querySelector(btn.getAttribute('data-ts-toggle-password'));
                if (!input) { return; }
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                var icon = btn.querySelector('i');
                if (icon) {
                    icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
                }
            });
        });

        // Prevent double-submits.
        document.querySelectorAll('form[data-ts-once]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var btn = form.querySelector('[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    btn.dataset.originalText = btn.innerHTML;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Please wait…';
                }
            });
        });
    }

    ready(function () {
        initSidebar();
        initGuides();
        initConfirm();
        initFlash();
        initDemoFill();
    });
})();
