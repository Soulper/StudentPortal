// Theme toggle: light / dark, persisted in localStorage, OS-preference default.
(function () {
    'use strict';
    var KEY = 'theme';

    function preferred() {
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    function current() {
        return document.documentElement.getAttribute('data-bs-theme') || 'light';
    }
    function sync() {
        var theme = current();
        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            btn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
            btn.querySelectorAll('[data-icon]').forEach(function (icon) {
                icon.classList.toggle('d-none', icon.getAttribute('data-icon') !== theme);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        sync();
        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var next = current() === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-bs-theme', next);
                try { localStorage.setItem(KEY, next); } catch (e) {}
                sync();
            });
        });
        // Follow OS changes only when the user hasn't chosen explicitly.
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
            var stored;
            try { stored = localStorage.getItem(KEY); } catch (err) { stored = null; }
            if (!stored) {
                document.documentElement.setAttribute('data-bs-theme', e.matches ? 'dark' : 'light');
                sync();
            }
        });
    });
})();
