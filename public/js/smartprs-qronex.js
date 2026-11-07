/*
 * SmartPRS — "QR Attendance" menu link (7 Nov 2026). Opens the QRONEX attendance module at /qronex
 * (packages/qronex-module). The module checks access itself; this only adds the link next to Daily Attendance.
 */
(function () {
    'use strict';
    var cfg = window.__SMARTPRS || {};
    var ROLES = ['Super Admin', 'Admin', 'HR Manager', 'HR', 'Accountant'];
    if (ROLES.indexOf(String(cfg.role || '')) < 0) { return; }
    function inject() {
        if (document.querySelector('.nav-item[data-id="qr-attendance"]')) { return; }
        var anchor = document.querySelector('.nav-item[data-id="att-daily"]');
        if (!anchor || !anchor.parentNode) { return; }
        var d = document.createElement('div');
        d.className = 'nav-item';
        d.setAttribute('data-id', 'qr-attendance');
        d.onclick = function () { window.location.href = '/qronex'; };
        if (anchor.parentNode.classList && anchor.parentNode.classList.contains('nav-sub')) {
            var i = document.createElement('i'); i.className = 'fas fa-qrcode sp-subic'; d.appendChild(i);
        }
        d.appendChild(document.createTextNode('QR Attendance'));
        anchor.parentNode.insertBefore(d, anchor.nextSibling);
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { setTimeout(inject, 0); }); } else { inject(); }
})();
