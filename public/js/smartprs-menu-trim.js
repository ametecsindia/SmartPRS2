/*
 * SmartPRS — menu trims (1 Oct 2026, Ejaz)
 *
 *  1. Escalation Desk stays ONLY under Main. Any other copy (e.g. under
 *     Field Force) is removed.
 *  2. Banks is removed from the Administration submenu.
 *
 * Plain static file loaded after the boot script (see AppController,
 * the $extraJs list), so nothing here passes through the PHP heredoc.
 * Nav items are injected late by several boot steps, so the trim re-runs
 * whenever the sidebar changes. Items are REMOVED, not hidden, because
 * gateNav() re-shows hidden items for roles with a permission row.
 * The Banks screen itself is untouched (still reachable by search / go()).
 */
(function () {
    'use strict';

    function isEscalation(el) {
        var id = (el.getAttribute('data-id') || '').toLowerCase();
        var txt = (el.textContent || '').trim().toLowerCase();
        return id === 'escalations' || txt === 'escalation desk' || txt === 'escalations';
    }

    function trim() {
        var items = document.querySelectorAll('.nav-item[data-id]');
        for (var i = 0; i < items.length; i++) {
            var el = items[i];
            if (!el.parentNode) { continue; }
            if (el.getAttribute('data-id') === 'banks') { el.parentNode.removeChild(el); continue; }
            if (isEscalation(el) && !el.closest('#g-main')) { el.parentNode.removeChild(el); }
        }
    }

    function start() {
        trim();
        var main = document.querySelector('#g-main');
        var nav = (main && main.closest('nav, aside, .sidebar')) || document.body;
        try {
            new MutationObserver(trim).observe(nav, { childList: true, subtree: true });
        } catch (e) {
            setTimeout(trim, 1000); setTimeout(trim, 3000); setTimeout(trim, 6000);
        }
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); }
    else { start(); }
})();
