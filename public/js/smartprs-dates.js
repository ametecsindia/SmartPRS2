/*
 * 7 Oct 2026 (Ejaz) — ONE date format everywhere: DD-MM-YYYY.
 *
 * The app keeps ISO (YYYY-MM-DD) in the database, the API and every
 * <input>.value, so no screen logic changes. This file only changes what
 * people SEE and what the browser READS from Excel:
 *
 *  1. Text on screen — any ISO date rendered anywhere becomes DD-MM-YYYY
 *     (a MutationObserver rewrites text nodes as screens render). Skips
 *     <code>, <pre>, <textarea>, contenteditable and [data-raw-date].
 *  2. Date pickers — every <input type="date"> / "datetime-local" shows and
 *     accepts DD-MM-YYYY (flatpickr altInput, vendored in public/vendor so
 *     on-prem LANs without internet work). The real input stays ISO.
 *     If flatpickr fails to load, the native picker is left as it was.
 *  3. spXlsxDates(wb) — Excel date cells become "DD-MM-YYYY[ HH:MM]" text
 *     (read with { raw: true, cellNF: true } so CSV dates are never guessed
 *     as US month/day).
 *  4. spDate(s) — the same formatter for code that builds strings/files.
 *
 * Opt out on an element with data-raw-date (text) or data-nofp (picker).
 */
(function () {
    'use strict';
    if (window.__spDates) { return; }
    window.__spDates = true;

    var RX = /(^|[^\w\/-])((?:19|20)\d{2})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])(?:T(\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?(?![\w\/-])/g;
    var HAS = /\d{4}-\d{2}-\d{2}/;
    var p2 = function (n) { return (n < 10 ? '0' : '') + n; };

    function fmt(s) {
        if (s === null || s === undefined) { return s; }
        s = String(s);
        if (!HAS.test(s)) { return s; }
        return s.replace(RX, function (all, pre, y, m, d, hh, mi, zone) {
            if (hh && zone) {   // UTC / offset timestamp → local wall time
                var dt = new Date(all.slice(pre.length));
                if (!isNaN(dt.getTime())) {
                    return pre + p2(dt.getDate()) + '-' + p2(dt.getMonth() + 1) + '-' + dt.getFullYear() + ' ' + p2(dt.getHours()) + ':' + p2(dt.getMinutes());
                }
            }
            return pre + d + '-' + m + '-' + y + (hh ? ' ' + hh + ':' + mi : '');
        });
    }
    window.spDate = fmt;
    // Rewrite dates in the TEXT of an HTML string, never inside tags/attributes
    // (so <input value="2026-06-05"> stays valid). For print windows.
    window.spDateHtml = function (html) {
        return String(html).replace(/>([^<]+)</g, function (a, t) { return '>' + fmt(t) + '<'; });
    };

    var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, CODE: 1, PRE: 1, NOSCRIPT: 1 };
    function skipEl(el) {
        for (var e = el; e && e.nodeType === 1; e = e.parentNode) {
            if (SKIP[e.nodeName] || e.isContentEditable || (e.hasAttribute && e.hasAttribute('data-raw-date'))) { return true; }
        }
        return false;
    }
    function fixText(n) {
        var v = n.data;
        if (!HAS.test(v) || skipEl(n.parentNode)) { return; }
        var nv = fmt(v);
        if (nv !== v) { n.data = nv; }
    }
    function fixTree(root) {
        if (root.nodeType === 3) { fixText(root); return; }
        if (root.nodeType !== 1 && root.nodeType !== 11) { return; }
        if (root.nodeType === 1 && SKIP[root.nodeName]) { return; }
        if (!HAS.test(root.textContent || '')) { /* still may hold date inputs */ }
        else {
            var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null), t, list = [];
            while ((t = w.nextNode())) { if (HAS.test(t.data)) { list.push(t); } }
            list.forEach(fixText);
        }
        if (window.flatpickr && root.querySelectorAll) {
            if (root.matches && root.matches('input[type="date"],input[type="datetime-local"]')) { pick(root); }
            Array.prototype.forEach.call(root.querySelectorAll('input[type="date"],input[type="datetime-local"]'), pick);
        }
    }

    // ---- date pickers ------------------------------------------------------
    var live = [];
    var valDesc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
    function pick(el) {
        if (el._flatpickr || el.hasAttribute('data-nofp')) { return; }
        var isDT = el.type === 'datetime-local';
        var ro = el.readOnly;
        var fp;
        try {
            fp = window.flatpickr(el, {
                altInput: true,
                altInputClass: el.className + ' sp-date',
                altFormat: isDT ? 'd-m-Y H:i' : 'd-m-Y',
                dateFormat: isDT ? 'Y-m-d\\TH:i' : 'Y-m-d',
                enableTime: isDT, time_24hr: true,
                allowInput: !ro, clickOpens: !ro,
                disableMobile: true,
                minDate: el.min || null, maxDate: el.max || null
            });
        } catch (e) { return; }
        if (!fp || !fp.altInput) { return; }
        var a = fp.altInput;
        a.style.cssText = el.style.cssText;
        a.placeholder = el.placeholder || (isDT ? 'dd-mm-yyyy hh:mm' : 'dd-mm-yyyy');
        if (el.title) { a.title = el.title; }
        if (ro) { a.readOnly = true; }
        // Screen code sometimes sets .value after render — keep the visible box in sync.
        Object.defineProperty(el, 'value', {
            configurable: true,
            get: function () { return valDesc.get.call(el); },
            set: function (v) {
                valDesc.set.call(el, v);
                // flatpickr's own writes already match its selection — only react to outside writes.
                var cur = fp.selectedDates[0] ? fp.formatDate(fp.selectedDates[0], fp.config.dateFormat) : '';
                if (String(v || '') === cur) { return; }
                try { if (v) { fp.setDate(v, false); } else { fp.clear(false); } } catch (e) {}
            }
        });
        live.push(el);
    }
    function sweep() {   // calendars live on <body>; drop the ones whose input left the page
        if (!live.length) { return; }
        live = live.filter(function (el) {
            if (el.isConnected) { return true; }
            try { delete el.value; if (el._flatpickr) { el._flatpickr.destroy(); } } catch (e) {}
            return false;
        });
    }

    // ---- Excel ---------------------------------------------------------------
    window.spXlsxDates = function (wb) {
        var X = window.XLSX;
        if (!wb || !X || !X.SSF) { return wb; }
        (wb.SheetNames || []).forEach(function (n) {
            var ws = wb.Sheets[n];
            Object.keys(ws).forEach(function (k) {
                var c = ws[k];
                if (k.charAt(0) === '!' || !c || c.t !== 'n' || !c.z || !X.SSF.is_date(c.z)) { return; }
                var v = Number(c.v);
                if (!(v >= 1)) { return; }   // pure times stay numbers (fraction of a day)
                c.w = X.SSF.format((v % 1) > 1e-6 ? 'dd-mm-yyyy hh:mm' : 'dd-mm-yyyy', v);
                c.v = c.w; c.t = 's'; delete c.z;
            });
        });
        return wb;
    };

    // ---- boot ---------------------------------------------------------------
    function start() {
        fixTree(document.body);
        new MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                var m = muts[i];
                if (m.type === 'characterData') { fixText(m.target); continue; }
                for (var j = 0; j < m.addedNodes.length; j++) { fixTree(m.addedNodes[j]); }
                if (m.removedNodes.length) { sweep(); }
            }
        }).observe(document.body, { childList: true, subtree: true, characterData: true });
    }
    function loadPicker() {
        var me = document.currentScript && document.currentScript.src;
        var base = me ? me.replace(/js\/smartprs-dates\.js.*$/, 'vendor/flatpickr/') : '/vendor/flatpickr/';
        var css = document.createElement('link');
        css.rel = 'stylesheet'; css.href = base + 'flatpickr.min.css';
        document.head.appendChild(css);
        var st = document.createElement('style');
        st.textContent = '.flatpickr-calendar.open{z-index:200000}';
        document.head.appendChild(st);
        var s = document.createElement('script');
        s.src = base + 'flatpickr.min.js';
        s.onload = function () { if (document.body) { fixTree(document.body); } };
        document.head.appendChild(s);
    }
    loadPicker();
    if (document.body) { start(); } else { document.addEventListener('DOMContentLoaded', start); }
})();
