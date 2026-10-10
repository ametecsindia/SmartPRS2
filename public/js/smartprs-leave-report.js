/*
 * SmartPRS 2 — LEAVE BALANCE REPORT (Leave → Leave Balance Report, Admin / HR) — 10 Oct 2026, Ejaz
 *
 * Filters: Leave year, Company, Branch, Department, Designation, Employee.
 * Table: Emp ID, Name, Company, Branch, Department, Opening Leave Balance,
 * Leaves Availed, Balance Leaves — every leave type listed inside the same cell.
 * Opening = yearly quota + carried forward (carry-forward types only).
 * Also holds the Leave year setting (Calendar Jan–Dec / Financial Apr–Mar),
 * which every leave balance in SmartPRS follows. Server: LeaveController::report.
 * Static file, so nothing here goes through the PHP heredoc.
 */
(function () {
    'use strict';
    var cfg = window.__SMARTPRS || {};
    var BASE = String(cfg.leaveBalancesBase || '/app/leaves/balances').replace(/\/balances\/?$/, '');
    var S = window.__LVR = window.__LVR || { f: {}, data: null, loading: false, sample: false };

    function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function say(m) { if (typeof toast === 'function') { toast(m); } else { window.alert(m); } }
    function rerender() { if (typeof window.render === 'function') { window.render(); } }
    function list(n) { try { return (typeof DB !== 'undefined' && DB && DB[n]) || []; } catch (e) { return []; } }
    function names(objs, empKey) {
        var seen = {}, out = [];
        function add(n) { n = String(n == null ? '' : n).trim(); if (n && !seen[n.toLowerCase()]) { seen[n.toLowerCase()] = 1; out.push(n); } }
        objs.forEach(function (o) { add(o && typeof o === 'object' ? o.name : o); });
        list('employees').forEach(function (e) { add(e && e[empKey]); });
        return out.sort(function (a, b) { return a.localeCompare(b); });
    }
    function num(v) { return v === null || v === undefined ? '—' : (Math.round(Number(v) * 10) / 10); }

    function load() {
        S.loading = true;
        var q = Object.keys(S.f).filter(function (k) { return S.f[k]; }).map(function (k) { return k + '=' + encodeURIComponent(S.f[k]); }).join('&');
        fetch(BASE + '/report' + (q ? '?' + q : ''), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; }); })
            .then(function (d) { S.loading = false; S.data = d || { ok: false }; if (d && d.year) { S.f.year = d.year; } rerender(); })
            .catch(function () { S.loading = false; S.data = { ok: false, error: 'Could not load the report' }; rerender(); });
    }

    var SAMPLE = {
        ok: true, label: 'FY 2026-27', mode: 'fy', rows: [
            { code: 'EMP101', name: 'Swathi Gupta', company: 'Ametecs India', branch: 'Hyderabad', department: 'Collections', types: [
                { type: 'Casual Leave', paid: true, opening: 12, carried: 0, availed: 10, pending: 0, balance: 2 },
                { type: 'Earned Leave', paid: true, opening: 21, carried: 6, availed: 4, pending: 2, balance: 17 },
                { type: 'Loss of Pay', paid: false, opening: null, carried: 0, availed: 3, pending: 0, balance: null }] },
            { code: 'EMP104', name: 'Sneha Das', company: 'Ametecs India', branch: 'Kondapur', department: 'HR', types: [
                { type: 'Casual Leave', paid: true, opening: 12, carried: 0, availed: 1, pending: 0, balance: 11 },
                { type: 'Earned Leave', paid: true, opening: 15, carried: 0, availed: 0, pending: 0, balance: 15 }] }
        ]
    };

    function cell(types, key) {
        return types.map(function (t) {
            var v = t[key];
            var col = key === 'balance' && v !== null && v <= 0 ? 'var(--red,#dc2626)' : 'var(--text)';
            var extra = key === 'opening' && t.carried ? ' <span style="color:var(--text3);font-size:11px">(' + num(t.carried) + ' carried)</span>'
                : (key === 'availed' && t.pending ? ' <span style="color:var(--text3);font-size:11px">(+' + num(t.pending) + ' pending)</span>' : '');
            var shown = (key !== 'availed' && !t.paid) ? 'Unpaid' : num(v);
            return '<div style="display:flex;justify-content:space-between;gap:10px;padding:2px 0;white-space:nowrap"><span style="color:var(--text3)">' + esc(t.type) + '</span><b style="color:' + col + '">' + shown + extra + '</b></div>';
        }).join('');
    }

    function csv(d) {
        var head = ['Emp ID', 'Name', 'Company', 'Branch', 'Department', 'Leave Type', 'Opening Leave Balance', 'Carried Forward', 'Leaves Availed', 'Pending', 'Balance Leaves'];
        var lines = [head];
        (d.rows || []).forEach(function (r) {
            (r.types || []).forEach(function (t) {
                lines.push([r.code, r.name, r.company, r.branch, r.department, t.type, t.paid ? t.opening : 'Unpaid', t.carried || 0, t.availed, t.pending || 0, t.paid ? t.balance : 'Unpaid']);
            });
        });
        var txt = lines.map(function (l) { return l.map(function (c) { c = String(c == null ? '' : c); return /[",\r\n]/.test(c) ? '"' + c.replace(/"/g, '""') + '"' : c; }).join(','); }).join('\r\n');
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob(['﻿' + txt], { type: 'text/csv' }));
        a.download = 'leave-balance-report-' + String(d.label || 'year').replace(/[^A-Za-z0-9-]+/g, '-') + '.csv';
        document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    }

    window.leaveReportScreen = function () {
        if (!S.data && !S.loading) { load(); }
        var inp = 'width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:9px;font-size:13.5px;background:#fff;box-sizing:border-box';
        var lbl = 'font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:4px';
        var d = S.sample ? SAMPLE : (S.data || {});
        var sel = function (k, title, opts, all) {
            return '<div><label style="' + lbl + '">' + title + '</label><select onchange="lvrSet(&#39;' + k + '&#39;,this.value)" style="' + inp + '"><option value="">' + all + '</option>'
                + opts.map(function (o) { var v = typeof o === 'object' ? o.v : o, t = typeof o === 'object' ? o.t : o; return '<option value="' + esc(v) + '"' + (String(S.f[k] || '') === String(v) ? ' selected' : '') + '>' + esc(t) + '</option>'; }).join('')
                + '</select></div>';
        };
        var years = ((S.data && S.data.years) || []).map(function (y) { return { v: y.value, t: y.label }; });
        var cos = list('companies').map(function (c) { return { v: c.id, t: c.name }; });
        var emps = list('employees').filter(function (e) { return !S.f.company_id || String(e.companyId) === String(S.f.company_id); })
            .map(function (e) { return { v: e.id, t: (e.name || '') + (e.id ? ' (' + e.id + ')' : '') }; });
        var br = list('branchesC'); br = br.length ? br : list('branches');
        var mode = (S.data && S.data.mode) || 'calendar';

        var filters = '<div class="card" style="padding:16px 18px;margin-bottom:14px">'
            + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px">'
            + '<div><label style="' + lbl + '">Leave year</label><select onchange="lvrSet(&#39;year&#39;,this.value)" style="' + inp + '">'
            + (years.length ? years.map(function (y) { return '<option value="' + esc(y.v) + '"' + (String(S.f.year || '') === String(y.v) ? ' selected' : '') + '>' + esc(y.t) + '</option>'; }).join('') : '<option>Current</option>') + '</select></div>'
            + sel('company_id', 'Company', cos, 'All companies')
            + sel('branch', 'Branch', names(br, 'branch'), 'All branches')
            + sel('department', 'Department', names(list('departments'), 'dept'), 'All departments')
            + sel('designation', 'Designation', names(list('designations'), 'designation'), 'All designations')
            + sel('employee', 'Employee', emps, 'All employees')
            + '</div>'
            + '<div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:space-between;align-items:flex-end;margin-top:12px">'
            + '<div style="font-size:12px;color:var(--text3)">Leave year follows: <select onchange="lvrMode(this.value)" style="padding:5px 8px;border:1.5px solid var(--border);border-radius:8px;font-size:12.5px">'
            + '<option value="calendar"' + (mode === 'calendar' ? ' selected' : '') + '>Calendar year (Jan&ndash;Dec)</option><option value="fy"' + (mode === 'fy' ? ' selected' : '') + '>Financial year (Apr&ndash;Mar)</option></select>'
            + ' &nbsp;<span title="Used by leave balances everywhere: the apply form, My leave balance and this report.">applies to all leave balances</span></div>'
            + '<span style="display:flex;gap:8px;flex-wrap:wrap">'
            + '<button class="btn btn-outline btn-sm" onclick="lvrSample()"><i class="fas fa-eye"></i> ' + (S.sample ? 'Back to live data' : 'View Sample Report') + '</button>'
            + '<button class="btn btn-outline btn-sm" onclick="lvrCsv()"><i class="fas fa-file-csv"></i> Download CSV</button>'
            + '<button class="btn btn-primary btn-sm" onclick="lvrLoad()"><i class="fas fa-rotate"></i> Refresh</button></span></div></div>';

        var body;
        if (!S.sample && S.loading && !S.data) {
            body = '<div class="card" style="padding:18px;color:var(--text3)">Loading&hellip;</div>';
        } else if (!S.sample && d.ok === false) {
            body = '<div class="card" style="padding:18px;color:var(--red,#dc2626)">' + esc(d.error || 'Could not load the report') + '</div>';
        } else {
            var rows = d.rows || [];
            var th = 'text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text2);border-bottom:1.5px solid var(--border);white-space:nowrap';
            var td = 'padding:10px 12px;border-bottom:1px solid var(--border);vertical-align:top;font-size:13px';
            body = '<div class="card" style="padding:0;overflow-x:auto">'
                + '<div style="padding:12px 14px;font-size:12px;color:var(--text3)">' + (S.sample ? '<b style="color:#b45309">SAMPLE DATA</b> &middot; ' : '') + esc(d.label || '') + ' &middot; ' + rows.length + ' employee(s). Opening = yearly quota + carried forward (carry-forward types only). Availed = approved leave; pending is shown in brackets and not yet deducted.</div>'
                + '<table style="width:100%;border-collapse:collapse;min-width:960px"><thead><tr>'
                + ['Emp ID', 'Name', 'Company', 'Branch', 'Department', 'Opening Leave Balance', 'Leaves Availed', 'Balance Leaves'].map(function (h) { return '<th style="' + th + '">' + h + '</th>'; }).join('')
                + '</tr></thead><tbody>'
                + (rows.length ? rows.map(function (r) {
                    return '<tr><td style="' + td + ';white-space:nowrap">' + esc(r.code) + '</td><td style="' + td + '">' + esc(r.name) + '</td><td style="' + td + '">' + esc(r.company || '—') + '</td>'
                        + '<td style="' + td + '">' + esc(r.branch || '—') + '</td><td style="' + td + '">' + esc(r.department || '—') + '</td>'
                        + '<td style="' + td + '">' + cell(r.types || [], 'opening') + '</td><td style="' + td + '">' + cell(r.types || [], 'availed') + '</td><td style="' + td + '">' + cell(r.types || [], 'balance') + '</td></tr>';
                }).join('') : '<tr><td colspan="8" style="' + td + ';text-align:center;color:var(--text3)">No employees match these filters.</td></tr>')
                + '</tbody></table></div>';
        }
        return '<div class="rm-screen">' + filters + body + '</div>';
    };
    window.lvrSet = function (k, v) {
        S.f[k] = v || '';
        if (k === 'company_id') { S.f.employee = ''; }
        S.sample = false; load(); rerender();
    };
    window.lvrLoad = function () { S.sample = false; load(); rerender(); };
    window.lvrSample = function () { S.sample = !S.sample; rerender(); };
    window.lvrCsv = function () { var d = S.sample ? SAMPLE : S.data; if (!d || !d.rows) { say('Nothing to download yet'); return; } csv(d); };
    window.lvrMode = function (m) {
        var label = m === 'fy' ? 'Financial year (Apr–Mar)' : 'Calendar year (Jan–Dec)';
        if (!window.confirm('Switch the leave year to ' + label + '?\n\nEvery leave balance (apply form, My leave balance, this report) will be counted on this year. Carry forward follows the new year boundaries.')) { rerender(); return; }
        fetch(BASE + '/year', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ mode: m }) })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .then(function (d) {
                if (d && d.ok) { say('Leave year set to ' + label); S.f.year = ''; S.data = null; window.__LEAVE = null; load(); }
                else { say((d && (d.error || d.message)) || 'Could not change the leave year'); rerender(); }
            }).catch(function () { say('Could not change the leave year'); rerender(); });
    };
})();
