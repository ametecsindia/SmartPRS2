/*
 * SmartPRS 2 — ASSET STOCK + ASSET ISSUE & RETURNS (30 Sep 2026, Ejaz)
 *
 * Loaded by AppController right AFTER the boot script, as a plain static file, so
 * none of this goes through the PHP heredoc (no $ / \n traps). It runs before
 * DOMContentLoaded, i.e. before boot(): the nav items exist in time for the
 * "restore last screen" step, and boot's own renderCustom wrapper falls through
 * to ours for the two new screen ids.
 *
 * Screens (Administration, after "Asset Register"):
 *   asset-stock  — Asset Stock: add units in bulk (auto Asset IDs), edit, retire.
 *   asset-issue  — Asset Issue & Returns: issue SEVERAL items to one employee in
 *                  one go (+ Add another item), return one or many, CSV/PDF export.
 * Backend: App\Http\Controllers\AssetController, routes/assets.php.
 */
(function () {
    'use strict';
    var cfg = window.__SMARTPRS || {};
    var ROLE = String(cfg.role || '');
    var IS_ADMIN = ['Admin', 'Super Admin', 'HR Manager'].indexOf(ROLE) >= 0;
    var BASE = String(cfg.masterBase || '/app/master').replace(/\/app\/master\/?$/, '') + '/app/asset-mgmt';

    var S = window.__ASSETS = window.__ASSETS || {
        stock: null, stockErr: null, emps: null, issues: null, issuesErr: null,
        view: 'open', heldBy: '', sel: {},
        form: { emp: '', on: today(), purpose: '', lines: [{ id: '', cond: 'new' }] },
        stockF: { q: '', status: '', cat: '' }, stockSel: {}, addOpen: false, busy: false
    };

    // ---------------------------------------------------------------- helpers
    function today() { var d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 10); }
    function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    function money(n) { return '₹' + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function note(m) { if (typeof toast === 'function') { toast(m); } else { window.alert(m); } }
    function cur() { try { return (typeof CURRENT !== 'undefined') ? CURRENT : null; } catch (e) { return null; } }
    function rerender(ids) { var c = cur(); if (ids.indexOf(c) >= 0 && typeof render === 'function') { render(); } }
    function head(title, sub, actions) {
        if (typeof pghead === 'function') { return pghead(title, sub, actions || ''); }
        return '<div class="pg-head"><div><div class="pg-title">' + title + '</div><div class="pg-sub">' + sub + '</div></div><div>' + (actions || '') + '</div></div>';
    }
    function getJ(url) {
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; }); });
    }
    function postJ(url, body) {
        return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body || {}) })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; }); });
    }
    var INP = 'padding:9px 11px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;background:#fffbeb;width:100%;box-sizing:border-box';
    function field(label, ctrl, w) { return '<div style="' + (w ? 'flex:' + w : 'flex:1') + ';min-width:120px"><label style="font-size:12px;font-weight:600;color:var(--text2);display:block;margin-bottom:5px">' + label + '</label>' + ctrl + '</div>'; }
    var COND = [['new', 'New'], ['good', 'Good'], ['fair', 'Fair'], ['damaged', 'Damaged']];
    function condSel(val, attrs) { return '<select ' + attrs + ' style="' + INP + '">' + COND.map(function (c) { return '<option value="' + c[0] + '"' + (c[0] === val ? ' selected' : '') + '>' + c[1] + '</option>'; }).join('') + '</select>'; }
    var ST = { available: ['#dcfce7', '#166534', 'Available'], issued: ['#dbeafe', '#1d4ed8', 'Issued'], repair: ['#fef3c7', '#92400e', 'Under repair'], lost: ['#fee2e2', '#991b1b', 'Lost'], retired: ['#f1f5f9', '#64748b', 'Retired'], returned: ['#f1f5f9', '#475569', 'Returned'] };
    function chip(s) { var c = ST[s] || ST.retired; return '<span style="background:' + c[0] + ';color:' + c[1] + ';font-size:11px;font-weight:700;padding:3px 9px;border-radius:99px;white-space:nowrap">' + c[2] + '</span>'; }
    function tag(v) { return v ? '<span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 7px;border-radius:6px;white-space:nowrap">' + esc(v) + '</span>' : '<span style="color:var(--text3)">—</span>'; }
    function modal(html) {
        var m = document.getElementById('ast-modal');
        if (!m) { m = document.createElement('div'); m.id = 'ast-modal'; document.body.appendChild(m); }
        m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9000;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto';
        m.innerHTML = '<div class="card" style="max-width:560px;width:100%;padding:22px 24px;margin:auto">' + html + '</div>';
        m.onclick = function (e) { if (e.target === m) { closeModal(); } };
    }
    function closeModal() { var m = document.getElementById('ast-modal'); if (m) { m.style.display = 'none'; m.innerHTML = ''; } }
    window.astModalClose = closeModal;

    // ---------------------------------------------------------------- loading
    var inflight = {};
    function once(key, fn) {
        if (inflight[key]) { return inflight[key]; }
        inflight[key] = fn().then(function (x) { inflight[key] = null; return x; }, function (e) { inflight[key] = null; throw e; });
        return inflight[key];
    }
    function loadStock() { return once('stock', loadStock0); }
    function loadEmps() { return once('emps', loadEmps0); }
    function loadIssues() { return once('issues:' + S.view, loadIssues0); }
    function loadStock0() {
        return getJ(BASE + '/stock').then(function (j) {
            if (j && j.ok) { S.stock = j; S.stockErr = null; } else { S.stock = { rows: [], summary: {}, categories: [] }; S.stockErr = (j && (j.error || j.message)) || 'Could not load stock'; }
            rerender(['asset-stock', 'asset-issue']);
        }).catch(function () { S.stock = { rows: [], summary: {}, categories: [] }; S.stockErr = 'Could not reach the server'; rerender(['asset-stock', 'asset-issue']); });
    }
    function loadEmps0() {
        return getJ(BASE + '/employees').then(function (j) { S.emps = (j && j.rows) || []; rerender(['asset-issue']); }).catch(function () { S.emps = []; });
    }
    function loadIssues0() {
        S.issuesLoading = true;
        return getJ(BASE + '/issues?scope=' + encodeURIComponent(S.view)).then(function (j) {
            if (j && j.ok) { S.issues = j.rows || []; S.issuesErr = null; } else { S.issues = []; S.issuesErr = (j && (j.error || j.message)) || 'Could not load'; }
            S.sel = {}; S.issuesLoading = false;
            rerender(['asset-issue']);
        }).catch(function () { S.issuesLoading = false; S.issues = []; S.issuesErr = 'Could not reach the server'; rerender(['asset-issue']); });
    }
    window.astReload = function () { S.stock = null; S.issues = null; S.emps = null; rerender(['asset-stock', 'asset-issue']); };

    function summaryPills() {
        var s = (S.stock && S.stock.summary) || {};
        return '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
            + '<span onclick="astToStock(&#39;available&#39;)" title="Show available units in Asset Stock" style="cursor:pointer;background:#f1f5f9;color:#0f172a;font-size:12px;font-weight:700;padding:5px 11px;border-radius:99px">● ' + (s.available || 0) + ' Available</span>'
            + '<span onclick="astToIssue(&#39;&#39;)" title="Show what is out with employees" style="cursor:pointer;background:#dcfce7;color:#166534;font-size:12px;font-weight:700;padding:5px 11px;border-radius:99px">● ' + (s.issued || 0) + ' Issued</span>'
            + '</div>';
    }

    // ================================================================= ASSET STOCK
    function stockScreen() {
        if (!S.stock) { setTimeout(function () { if (!S.stock) { loadStock(); } }, 10); return head('Asset Stock', 'Loading…') + '<div class="card"><div style="padding:40px;text-align:center;color:var(--text3)">Loading stock…</div></div>'; }
        var d = S.stock, sm = d.summary || {}, F = S.stockF;
        var err = S.stockErr ? '<div class="card" style="padding:12px 16px;margin-bottom:12px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-size:13px"><i class="fas fa-triangle-exclamation"></i> ' + esc(S.stockErr) + '</div>' : '';
        var kpi = function (l, v, c, st) { return '<div' + (st !== undefined ? ' onclick="astStockF(&#39;status&#39;,&#39;' + st + '&#39;,1)" title="Filter the list"' : '') + ' style="' + (st !== undefined ? 'cursor:pointer;' : '') + (st && F.status === st ? 'outline:2px solid ' + c + ';' : '') + 'flex:1;min-width:120px;background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:12px;text-align:center"><div style="font-size:20px;font-weight:800;color:' + c + '">' + v + '</div><div style="font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.4px">' + l + '</div></div>'; };
        var kpis = '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">'
            + kpi('Total units', sm.total || 0, 'var(--text)', '') + kpi('Available', sm.available || 0, '#166534', 'available') + kpi('Issued', sm.issued || 0, '#1d4ed8', 'issued')
            + kpi('Under repair', sm.repair || 0, '#92400e', 'repair') + kpi('Lost / Retired', (sm.lost || 0) + (sm.retired || 0), '#991b1b', 'lost') + kpi('Stock value', money(sm.value || 0), 'var(--text)') + '</div>';

        var cats = (d.categories || []).map(function (c) { return c.category; });
        var catList = '<datalist id="ast-cats">' + cats.concat(['Laptop', 'Desktop', 'CPU', 'Monitor', 'Keyboard', 'Mouse', 'Headset', 'Mobile', 'SIM', 'ID Card', 'Access Card', 'Charger', 'Tablet']).filter(function (v, i, a) { return a.indexOf(v) === i; }).map(function (c) { return '<option value="' + esc(c) + '">'; }).join('') + '</datalist>';
        var add = '<div class="card" style="padding:16px 18px;margin-bottom:16px">'
            + '<div style="display:flex;justify-content:space-between;align-items:center;cursor:pointer" onclick="astToggleAdd()"><div><div style="font-weight:700;color:var(--accent);font-size:15px">Add to stock</div><div style="font-size:12.5px;color:var(--text3)">One row per physical unit. Enter a quantity and (optionally) the serial numbers, one per line — each unit gets its own Asset ID.</div></div><i class="fas fa-chevron-' + (S.addOpen ? 'up' : 'down') + '" style="color:var(--text3)"></i></div>'
            + (S.addOpen ? ('<div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:14px">' + catList
                + field('Category *', '<input id="ast_cat" list="ast-cats" placeholder="Laptop, Keyboard, SIM…" style="' + INP + '">', 1.2)
                + field('Make / Model', '<input id="ast_mm" placeholder="Logitech K120" style="' + INP + '">', 1.4)
                + field('Quantity', '<input id="ast_qty" type="number" min="1" max="500" value="1" style="' + INP + '">', 0.6)
                + field('Cost per unit (₹)', '<input id="ast_cost" type="number" min="0" step="0.01" placeholder="0.00" style="' + INP + '">', 0.8)
                + field('Purchased on', '<input id="ast_pd" type="date" style="' + INP + '">', 0.9)
                + '</div><div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:12px">'
                + field('Vendor', '<input id="ast_vendor" style="' + INP + '">', 1)
                + field('Invoice no.', '<input id="ast_inv" style="' + INP + '">', 0.8)
                + field('ID prefix', '<input id="ast_prefix" maxlength="8" placeholder="auto (KBD, LAP…)" style="' + INP + ';text-transform:uppercase">', 0.7)
                + field('Serial numbers (one per line)', '<textarea id="ast_serials" rows="3" placeholder="Optional" style="' + INP + ';font-family:monospace;resize:vertical"></textarea>', 1.6)
                + '</div><div style="display:flex;justify-content:flex-end;margin-top:12px"><button class="btn btn-primary" onclick="astAddStock()"' + (S.busy ? ' disabled' : '') + '><i class="fas fa-plus"></i> Add to stock</button></div>') : '')
            + '</div>';

        var catTbl = (d.categories || []).length ? ('<div class="card" style="padding:0;overflow-x:auto;margin-bottom:16px"><div style="padding:12px 16px;font-weight:700;color:var(--accent)">By category</div><table class="tbl" style="width:100%"><thead><tr><th>Category</th><th style="text-align:right">Total</th><th style="text-align:right">Available</th><th style="text-align:right">Issued</th><th style="text-align:right">Repair / Lost / Retired</th><th style="text-align:right">Value</th></tr></thead><tbody>'
            + d.categories.map(function (c) { return '<tr style="cursor:pointer" onclick="astStockF(&#39;cat&#39;,' + esc(JSON.stringify(c.category)).replace(/&quot;/g, '&#34;') + ')"><td><b>' + esc(c.category) + '</b></td><td style="text-align:right">' + c.total + '</td><td style="text-align:right;color:#166534;font-weight:700">' + c.available + '</td><td style="text-align:right;color:#1d4ed8">' + c.issued + '</td><td style="text-align:right;color:var(--text3)">' + c.other + '</td><td style="text-align:right">' + money(c.value) + '</td></tr>'; }).join('')
            + '</tbody></table></div>') : '';

        var q = F.q.toLowerCase();
        var rows = (d.rows || []).filter(function (r) {
            if (F.status && r.status !== F.status && !(F.status === 'lost' && r.status === 'retired')) { return false; }
            if (F.cat && r.category !== F.cat) { return false; }
            if (q && [r.code, r.category, r.make_model, r.serial, r.vendor, r.holder && r.holder.name, r.holder && r.holder.code].join(' ').toLowerCase().indexOf(q) < 0) { return false; }
            return true;
        });
        var availRows = rows.filter(function (r) { return r.status === 'available'; });
        var nSel = Object.keys(S.stockSel).filter(function (k) { return S.stockSel[k]; }).length;
        var filt = '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border)">'
            + '<div style="font-weight:700;color:var(--accent);margin-right:auto">Units <span style="color:var(--text3);font-weight:400">(' + rows.length + ')</span>'
            + (nSel ? ' <button class="btn btn-primary btn-sm" style="margin-left:10px" onclick="astIssueSelected()"><i class="fas fa-right-from-bracket"></i> Issue selected (' + nSel + ')</button> <a onclick="astStockSelClear()" style="font-size:12px;color:var(--text3);cursor:pointer;font-weight:400">clear</a>' : '')
            + '</div>'
            + '<input placeholder="Search ID, serial, model, holder…" value="' + esc(F.q) + '" oninput="astStockQ(this.value)" id="ast_q" style="' + INP + ';width:240px;background:#f8fafc">'
            + '<select onchange="astStockF(&#39;cat&#39;,this.value)" style="' + INP + ';width:160px;background:#f8fafc"><option value="">All categories</option>' + cats.map(function (c) { return '<option' + (c === F.cat ? ' selected' : '') + ' value="' + esc(c) + '">' + esc(c) + '</option>'; }).join('') + '</select>'
            + '<select onchange="astStockF(&#39;status&#39;,this.value)" style="' + INP + ';width:150px;background:#f8fafc"><option value="">All statuses</option>' + ['available', 'issued', 'repair', 'lost', 'retired'].map(function (s) { return '<option value="' + s + '"' + (s === F.status ? ' selected' : '') + '>' + ST[s][2] + '</option>'; }).join('') + '</select>'
            + '<a class="btn btn-outline" href="' + BASE + '/export?scope=stock&format=csv" style="color:#166534;border-color:#166534"><i class="fas fa-file-csv"></i> CSV</a>'
            + '<a class="btn btn-outline" href="' + BASE + '/export?scope=stock&format=pdf" style="color:#b91c1c;border-color:#b91c1c"><i class="fas fa-file-pdf"></i> PDF</a>'
            + '</div>';
        var body = rows.map(function (r) {
            return '<tr><td>' + (r.status === 'available' ? '<input type="checkbox" title="Select to issue" onclick="astStockSel(' + r.id + ',this.checked)"' + (S.stockSel[r.id] ? ' checked' : '') + '>' : '') + '</td><td>' + tag(r.code) + '</td><td>' + esc(r.category) + '</td><td>' + esc(r.make_model || '') + '</td><td>' + tag(r.serial) + '</td>'
                + '<td style="text-align:right">' + money(r.cost) + '</td><td style="white-space:nowrap">' + esc(r.purchase_date || '') + '</td>'
                + '<td>' + chip(r.status) + '</td>'
                + '<td style="font-size:12.5px">' + (r.holder ? ('<a onclick="astToIssue(' + esc(JSON.stringify(r.holder.code)) + ')" title="See everything this employee holds" style="cursor:pointer;color:var(--accent)"><b>' + esc(r.holder.code) + '</b> ' + esc(r.holder.name) + '</a><div style="font-size:11px;color:var(--text3)">since ' + esc(r.holder.since) + ' · ' + esc(r.holder.ref) + '</div>') : '<span style="color:var(--text3)">—</span>') + '</td>'
                + '<td style="white-space:nowrap">'
                + (r.status === 'available' ? '<button class="btn btn-primary btn-sm" onclick="astIssueItems([' + r.id + '])" title="Issue this unit"><i class="fas fa-right-from-bracket"></i> Issue</button> ' : '')
                + (r.status === 'issued' && r.holder && r.holder.issue_id ? '<button class="btn btn-outline btn-sm" onclick="astGoReturn(' + r.holder.issue_id + ')" title="Take it back"><i class="fas fa-rotate-left"></i> Return</button> ' : '')
                + '<button class="btn btn-outline btn-sm" onclick="astEditStock(' + r.id + ')" title="Edit"><i class="fas fa-pen"></i></button> '
                + (r.status !== 'issued' ? '<button class="btn btn-outline btn-sm" style="color:var(--red)" onclick="astDelStock(' + r.id + ')" title="Delete (only if never issued)"><i class="fas fa-trash"></i></button>' : '')
                + '</td></tr>';
        }).join('');
        var tbl = '<div class="card" style="padding:0;overflow-x:auto">' + filt + '<table class="tbl" style="width:100%"><thead><tr><th style="width:30px">' + (availRows.length ? '<input type="checkbox" title="Select all available" onclick="astStockSelAll(this.checked)"' + (nSel && nSel === availRows.length ? ' checked' : '') + '>' : '') + '</th><th>Asset ID</th><th>Category</th><th>Make / Model</th><th>Serial Number</th><th style="text-align:right">Cost</th><th>Purchased</th><th>Status</th><th>With</th><th></th></tr></thead><tbody>'
            + (body || '<tr><td colspan="10" style="text-align:center;color:var(--text3);padding:30px">' + ((d.rows || []).length ? 'No units match this filter.' : 'No stock yet — use <b>Add to stock</b> above.') + '</td></tr>')
            + '</tbody></table></div>';

        return err + head('Asset Stock', 'Everything the company owns, one row per unit. Tick available units and press <b>Issue selected</b>, or use <a onclick="astToIssue(&#39;&#39;)" style="color:var(--accent);cursor:pointer">Asset Issue &amp; Returns</a>; the numbers here move with every issue and return.', summaryPills())
            + kpis + add + catTbl + tbl;
    }
    window.astToggleAdd = function () { S.addOpen = !S.addOpen; rerender(['asset-stock']); };
    var qT = null;
    window.astStockQ = function (v) { S.stockF.q = v; clearTimeout(qT); qT = setTimeout(function () { rerender(['asset-stock']); var el = document.getElementById('ast_q'); if (el) { el.focus(); el.setSelectionRange(v.length, v.length); } }, 250); };
    window.astStockF = function (k, v, toggle) { S.stockF[k] = (toggle || k === 'cat') && v && S.stockF[k] === v ? '' : v; rerender(['asset-stock']); };
    // ---- cross-tab wiring -------------------------------------------------
    function navEl(id) { return document.querySelector('.nav-item[data-id="' + id + '"]'); }
    window.astToStock = function (status) { S.stockF = { q: '', status: status || '', cat: '' }; go('asset-stock', navEl('asset-stock')); };
    window.astToStockQ = function (q) { S.stockF = { q: q || '', status: '', cat: '' }; go('asset-stock', navEl('asset-stock')); };
    window.astToIssue = function (empCode) { S.view = 'open'; S.heldBy = empCode || ''; S.sel = {}; go('asset-issue', navEl('asset-issue')); };
    window.astHeldBy = function (empCode) { if (S.view !== 'open') { S.view = 'open'; S.issues = null; } S.heldBy = empCode || ''; S.sel = {}; rerender(['asset-issue']); setTimeout(function () { var t = document.getElementById('ast-register'); if (t) { t.scrollIntoView({ behavior: 'smooth', block: 'start' }); } }, 60); };
    window.astStockSel = function (id, on) { S.stockSel[id] = !!on; rerender(['asset-stock']); };
    window.astStockSelClear = function () { S.stockSel = {}; rerender(['asset-stock']); };
    window.astStockSelAll = function (on) {
        S.stockSel = {};
        if (on) { ((S.stock && S.stock.rows) || []).forEach(function (r) { if (r.status === 'available' && (!S.stockF.cat || r.category === S.stockF.cat)) { S.stockSel[r.id] = true; } }); }
        rerender(['asset-stock']);
    };
    window.astIssueSelected = function () { window.astIssueItems(Object.keys(S.stockSel).filter(function (k) { return S.stockSel[k]; }).map(Number)); };
    // Put units into the issue form (keeping anything already picked there) and open it.
    window.astIssueItems = function (ids) {
        var L = S.form.lines.filter(function (l) { return l.id; });
        var have = {}; L.forEach(function (l) { have[String(l.id)] = 1; });
        ids.forEach(function (id) { if (!have[String(id)]) { L.push({ id: String(id), cond: 'good' }); have[String(id)] = 1; } });
        (S.stock && S.stock.rows || []).forEach(function (r) { L.forEach(function (l) { if (String(l.id) === String(r.id)) { l.cond = r.condition === 'new' ? 'new' : (r.condition || 'good'); } }); });
        S.form.lines = L.length ? L : [{ id: '', cond: 'new' }];
        S.stockSel = {};
        S.view = 'open'; S.heldBy = '';
        go('asset-issue', navEl('asset-issue'));
        setTimeout(function () { var e = document.querySelector('input[list=ast-emps]'); if (e && !S.form.emp) { e.focus(); } }, 120);
        note(ids.length + ' item' + (ids.length === 1 ? '' : 's') + ' added to the issue form — pick the employee');
    };
    // Open the Return dialog for one open issue, from the Stock tab.
    window.astGoReturn = function (issueId) {
        S.view = 'open'; S.heldBy = ''; S.sel = {};
        go('asset-issue', navEl('asset-issue'));
        loadIssues().then(function () { window.astReturn([issueId]); });
    };
    window.astAddStock = function () {
        var g = function (id) { var el = document.getElementById(id); return el ? el.value : ''; };
        var body = { category: g('ast_cat').trim(), make_model: g('ast_mm'), qty: parseInt(g('ast_qty'), 10) || 1, cost: g('ast_cost'), purchase_date: g('ast_pd'), vendor: g('ast_vendor'), invoice_no: g('ast_inv'), prefix: g('ast_prefix'), serials: g('ast_serials') };
        if (!body.category) { note('Category is required'); return; }
        var serialCount = body.serials.split(/[\r\n,]+/).filter(function (x) { return x.trim(); }).length;
        if (serialCount > body.qty) { body.qty = serialCount; }
        if (body.qty > 1 && !window.confirm('Add ' + body.qty + ' units of ' + body.category + '? Each gets its own Asset ID.')) { return; }
        S.busy = true;
        postJ(BASE + '/stock', body).then(function (j) {
            S.busy = false;
            if (j && j.ok) { note(j.message || 'Added'); S.addOpen = false; rerender(['asset-stock']); loadStock(); }
            else { note((j && (j.error || j.message)) || 'Could not add'); rerender(['asset-stock']); }
        }).catch(function () { S.busy = false; note('Could not reach the server'); });
    };
    window.astEditStock = function (id) {
        var r = ((S.stock && S.stock.rows) || []).find(function (x) { return x.id === id; });
        if (!r) { return; }
        var stOpts = (r.status === 'issued' ? ['issued'] : ['available', 'repair', 'lost', 'retired']).map(function (s) { return '<option value="' + s + '"' + (s === r.status ? ' selected' : '') + '>' + ST[s][2] + '</option>'; }).join('');
        modal('<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px"><h3 style="margin:0;font-size:17px">Edit ' + esc(r.code) + '</h3><button class="btn btn-outline btn-sm" onclick="astModalClose()"><i class="fas fa-xmark"></i></button></div>'
            + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">'
            + field('Category', '<input id="ase_cat" value="' + esc(r.category) + '" style="' + INP + '">')
            + field('Make / Model', '<input id="ase_mm" value="' + esc(r.make_model || '') + '" style="' + INP + '">')
            + field('Serial number', '<input id="ase_sn" value="' + esc(r.serial || '') + '" style="' + INP + '">')
            + field('Cost (₹)', '<input id="ase_cost" type="number" step="0.01" min="0" value="' + esc(r.cost) + '" style="' + INP + '">')
            + field('Purchased on', '<input id="ase_pd" type="date" value="' + esc(r.purchase_date || '') + '" style="' + INP + '">')
            + field('Vendor', '<input id="ase_vendor" value="' + esc(r.vendor || '') + '" style="' + INP + '">')
            + field('Invoice no.', '<input id="ase_inv" value="' + esc(r.invoice_no || '') + '" style="' + INP + '">')
            + field('Status', '<select id="ase_st" style="' + INP + '"' + (r.status === 'issued' ? ' disabled title="Return it from Asset Issue &amp; Returns"' : '') + '>' + stOpts + '</select>')
            + field('Condition', condSel(r.condition, 'id="ase_cond"').replace('</select>', '<option value="lost"' + (r.condition === 'lost' ? ' selected' : '') + '>Lost</option></select>'))
            + field('Notes', '<input id="ase_notes" value="' + esc(r.notes || '') + '" style="' + INP + '">')
            + '</div><div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px"><button class="btn btn-outline" onclick="astModalClose()">Cancel</button><button class="btn btn-primary" onclick="astSaveEdit(' + r.id + ')"><i class="fas fa-check"></i> Save</button></div>');
    };
    window.astSaveEdit = function (id) {
        var g = function (k) { var el = document.getElementById(k); return el ? el.value : ''; };
        postJ(BASE + '/stock', { id: id, category: g('ase_cat'), make_model: g('ase_mm'), serial: g('ase_sn'), cost: g('ase_cost'), purchase_date: g('ase_pd'), vendor: g('ase_vendor'), invoice_no: g('ase_inv'), status: g('ase_st'), condition: g('ase_cond'), notes: g('ase_notes') })
            .then(function (j) { if (j && j.ok) { closeModal(); note(j.message || 'Saved'); loadStock(); } else { note((j && (j.error || j.message)) || 'Could not save'); } });
    };
    window.astDelStock = function (id) {
        var r = ((S.stock && S.stock.rows) || []).find(function (x) { return x.id === id; });
        if (!r || !window.confirm('Delete ' + r.code + ' from stock? Only possible if it was never issued — otherwise mark it Retired.')) { return; }
        postJ(BASE + '/stock/' + id + '/delete', {}).then(function (j) { note((j && (j.message || j.error)) || 'Done'); if (j && j.ok) { loadStock(); } });
    };

    // ======================================================= ASSET ISSUE & RETURNS
    function empLabel(e) { return e.code + ' · ' + e.name; }
    function findEmp(v) {
        var list = S.emps || []; v = String(v || '').trim(); if (!v) { return null; }
        var code = v.split(' · ')[0].trim().toLowerCase();
        return list.find(function (e) { return String(e.code).toLowerCase() === code; })
            || list.find(function (e) { return empLabel(e).toLowerCase() === v.toLowerCase() || String(e.name).toLowerCase() === v.toLowerCase(); }) || null;
    }
    function empMeta(e) {
        if (!e) { return ''; }
        var bits = [['Company', e.company], ['Branch', e.branch], ['Department', e.department], ['Designation', e.designation], ['Reporting manager', e.manager]].filter(function (b) { return b[1]; });
        return '<b>' + esc(e.code) + ' · ' + esc(e.name) + '</b>' + bits.map(function (b) { return ' · ' + b[0] + ': ' + esc(b[1]); }).join('');
    }
    function held(id) { var n = 0; ((S.stock && S.stock.rows) || []).forEach(function (r) { if (r.status === 'issued' && r.holder && r.holder.code === id) { n++; } }); return n; }

    function itemSelect(idx) {
        var L = S.form.lines, line = L[idx];
        var taken = {}; L.forEach(function (l, i) { if (i !== idx && l.id) { taken[l.id] = 1; } });
        var avail = ((S.stock && S.stock.rows) || []).filter(function (r) { return r.status === 'available' && !taken[r.id]; });
        var groups = {}; var order = [];
        avail.forEach(function (r) { if (!groups[r.category]) { groups[r.category] = []; order.push(r.category); } groups[r.category].push(r); });
        order.sort();
        return '<select onchange="astLine(' + idx + ',&#39;id&#39;,this.value)" style="' + INP + '"><option value="">— pick an item —</option>'
            + order.map(function (c) {
                return '<optgroup label="' + esc(c) + ' (' + groups[c].length + ' left)">' + groups[c].map(function (r) {
                    return '<option value="' + r.id + '"' + (String(r.id) === String(line.id) ? ' selected' : '') + '>' + esc(r.code) + (r.serial ? ' · S/N ' + esc(r.serial) : '') + (r.make_model ? ' · ' + esc(r.make_model) : '') + '</option>';
                }).join('') + '</optgroup>';
            }).join('') + '</select>';
    }

    function issueScreen() {
        if (!S.stock) { setTimeout(function () { if (!S.stock) { loadStock(); } }, 10); }
        if (!S.emps) { setTimeout(function () { if (!S.emps) { S.emps = []; loadEmps(); } }, 10); }
        if (!S.issues) { S.issues = []; S.issuesLoading = true; setTimeout(loadIssues, 10); }
        var F = S.form, emp = findEmp(F.emp);
        var sm = (S.stock && S.stock.summary) || {};

        var exportBar = '<div class="card" style="padding:12px 16px;margin-bottom:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">'
            + '<span style="font-size:13px;color:var(--text2)"><i class="fas fa-download"></i> Export</span>'
            + '<select id="ast_exp" style="' + INP + ';width:240px"><option value="open">Issued — to be returned</option><option value="returned">Returned</option><option value="all">All movements</option><option value="stock">Asset Stock</option></select>'
            + '<button class="btn btn-outline" onclick="astExport(&#39;csv&#39;)" style="color:#166534;border-color:#166534"><i class="fas fa-file-csv"></i> CSV</button>'
            + '<button class="btn btn-outline" onclick="astExport(&#39;pdf&#39;)" style="color:#b91c1c;border-color:#b91c1c"><i class="fas fa-file-pdf"></i> PDF</button>'
            + '</div>';

        // ---- issue form: one employee, many items
        var empOpts = '<datalist id="ast-emps">' + (S.emps || []).map(function (e) { return '<option value="' + esc(empLabel(e)) + '">'; }).join('') + '</datalist>';
        var lines = F.lines.map(function (l, i) {
            return '<div style="display:flex;gap:10px;align-items:flex-end;margin-top:' + (i ? 8 : 0) + 'px">'
                + '<div style="width:26px;height:38px;display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--text3);font-size:13px">' + (i + 1) + '.</div>'
                + field(i ? '' : 'Item *', itemSelect(i), 2.4)
                + field(i ? '' : 'Condition', condSel(l.cond, 'onchange="astLine(' + i + ',&#39;cond&#39;,this.value)"'), 0.8)
                + '<div style="width:38px">' + (F.lines.length > 1 ? '<button class="btn btn-outline btn-sm" title="Remove this item" onclick="astDelLine(' + i + ')" style="color:var(--red);height:38px;width:38px"><i class="fas fa-xmark"></i></button>' : '') + '</div>'
                + '</div>';
        }).join('');
        var nAvail = sm.available || 0;
        var picked = F.lines.filter(function (l) { return l.id; }).length;
        var canAdd = F.lines.length < nAvail && F.lines.length < 50;
        var form = '<div class="card" style="padding:16px 18px;margin-bottom:16px">'
            + '<div style="font-weight:700;color:var(--accent);font-size:15px">Issue assets</div>'
            + '<div style="font-size:12.5px;color:var(--text3);margin-bottom:12px">Only items available on the shelf are listed, with how many of each are left. Add as many items as the employee is taking — they are issued together under one reference.</div>'
            + '<div style="display:flex;flex-wrap:wrap;gap:12px">' + empOpts
            + field('Employee ID / name *', '<input list="ast-emps" value="' + esc(F.emp) + '" placeholder="Type ID or name…" onchange="astForm(&#39;emp&#39;,this.value,1)" style="' + INP + '">', 1.6)
            + field('Issued on', '<input type="date" value="' + esc(F.on) + '" max="' + today() + '" onchange="astForm(&#39;on&#39;,this.value)" style="' + INP + '">', 0.8)
            + field('Purpose', '<input value="' + esc(F.purpose) + '" placeholder="e.g. WFH setup" oninput="astForm(&#39;purpose&#39;,this.value)" style="' + INP + '">', 1.4)
            + '</div>'
            + '<div style="font-size:12.5px;color:var(--text2);margin:8px 0 14px;min-height:16px">' + (emp ? empMeta(emp) + (held(emp.code) ? ' · <a onclick="astHeldBy(' + esc(JSON.stringify(emp.code)) + ')" style="cursor:pointer;color:#1d4ed8;font-weight:600">already holds ' + held(emp.code) + ' item(s)</a>' : '') : (F.emp ? '<span style="color:var(--red)">No active employee matches “' + esc(F.emp) + '”.</span>' : '')) + '</div>'
            + '<div style="background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:12px">' + lines
            + '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;flex-wrap:wrap;gap:10px">'
            + '<button class="btn btn-outline" onclick="astAddLine()"' + (canAdd ? '' : ' disabled title="No more available items"') + '><i class="fas fa-plus"></i> Add another item</button>'
            + '<div style="display:flex;align-items:center;gap:12px"><span style="font-size:12.5px;color:var(--text3)">' + picked + ' item' + (picked === 1 ? '' : 's') + ' selected</span>'
            + '<button class="btn btn-primary" onclick="astIssue()"' + (S.busy ? ' disabled' : '') + '><i class="fas fa-right-from-bracket"></i> Issue' + (picked > 1 ? ' ' + picked + ' items' : '') + '</button></div>'
            + '</div></div>'
            + (S.stock && !nAvail ? '<div style="margin-top:10px;font-size:12.5px;color:#92400e"><i class="fas fa-circle-info"></i> Nothing is available on the shelf. Add units in <a onclick="astToStock(&#39;&#39;)" style="color:var(--accent);cursor:pointer">Asset Stock</a>.</div>' : '')
            + '</div>';

        // ---- register
        var all = S.issues || [];
        var holders = {}; all.forEach(function (r) { if (!holders[r.emp_code]) { holders[r.emp_code] = r.emp_name; } });
        var rows = all.filter(function (r) { return !S.heldBy || r.emp_code === S.heldBy; });
        var open = S.view === 'open';
        var selIds = Object.keys(S.sel).filter(function (k) { return S.sel[k]; });
        var VIEWS = [['open', 'Issued — to be returned'], ['returned', 'Returned'], ['all', 'All movements']];
        var ttl = VIEWS.filter(function (v) { return v[0] === S.view; })[0][1];
        var thS = 'white-space:nowrap';
        var th = (open ? '<th style="width:30px"><input type="checkbox" onclick="astSelAll(this.checked)"' + (rows.length && selIds.length === rows.length ? ' checked' : '') + '></th>' : '')
            + ['Ref', 'Asset ID', 'Category', 'Make / Model', 'Serial Number', 'Cost', 'Issued On', 'Employee ID', 'Employee Name', 'Designation', 'Department', 'Reporting Manager', 'Company', 'Branch', 'Condition', 'Purpose'].map(function (h) { return '<th style="' + thS + (h === 'Cost' ? ';text-align:right' : '') + '">' + h + '</th>'; }).join('')
            + (open ? '<th></th>' : '<th>Status</th><th style="' + thS + '">Returned On</th><th style="' + thS + '">Return Condition</th><th>Remarks</th>');
        var tot = 0;
        var body = rows.map(function (r) {
            tot += Number(r.cost || 0);
            return '<tr>' + (open ? '<td><input type="checkbox" onclick="astSel(' + r.id + ',this.checked)"' + (S.sel[r.id] ? ' checked' : '') + '></td>' : '')
                + '<td style="font-size:11.5px;color:var(--text3);white-space:nowrap">' + esc(r.ref) + '</td><td><a onclick="astToStockQ(' + esc(JSON.stringify(r.code)) + ')" title="Open in Asset Stock" style="cursor:pointer">' + tag(r.code) + '</a></td><td>' + esc(r.category) + '</td><td>' + esc(r.make_model) + '</td><td>' + tag(r.serial) + '</td>'
                + '<td style="text-align:right;white-space:nowrap">' + money(r.cost) + '</td><td style="white-space:nowrap">' + esc(r.issued_on) + '</td>'
                + '<td style="white-space:nowrap">' + esc(r.emp_code) + '</td><td style="white-space:nowrap"><b>' + esc(r.emp_name) + '</b></td><td>' + esc(r.designation) + '</td><td>' + esc(r.department) + '</td><td>' + esc(r.manager) + '</td><td>' + esc(r.company) + '</td><td>' + esc(r.branch) + '</td>'
                + '<td>' + esc((r.condition || '').replace(/^./, function (c) { return c.toUpperCase(); })) + '</td><td style="font-size:12.5px">' + esc(r.purpose || '') + '</td>'
                + (open ? '<td><button class="btn btn-outline btn-sm" onclick="astReturn([' + r.id + '])"><i class="fas fa-rotate-left"></i> Return</button></td>'
                    : '<td>' + chip(r.status) + '</td><td style="white-space:nowrap">' + esc(r.returned_on || '') + '</td><td>' + esc((r.return_condition || '').replace(/^./, function (c) { return c.toUpperCase(); })) + '</td><td style="font-size:12.5px">' + esc(r.return_remarks || '') + '</td>')
                + '</tr>';
        }).join('');
        var cols = open ? 18 : 20;
        var reg = '<div class="card" id="ast-register" style="padding:0">'
            + '<div style="padding:14px 16px 10px"><div style="font-weight:700;color:var(--accent);font-size:15px">' + ttl + ' <span style="color:var(--text3);font-weight:400">(' + rows.length + ')</span></div>'
            + '<div style="font-size:12.5px;color:var(--text3)">' + (open ? 'Everything currently with an employee. Returning an item puts it back into Asset Stock. Tick several to return them together.' : 'Issue and return history.') + '</div></div>'
            + '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;padding:0 16px 12px">'
            + field('View', '<select onchange="astView(this.value)" style="' + INP + '">' + VIEWS.map(function (v) { return '<option value="' + v[0] + '"' + (v[0] === S.view ? ' selected' : '') + '>' + v[1] + '</option>'; }).join('') + '</select>', 1)
            + field('Held by', '<select id="ast_held" style="' + INP + '"><option value="">ALL employees</option>' + Object.keys(holders).sort(function (a, b) { return holders[a] < holders[b] ? -1 : 1; }).map(function (c) { return '<option value="' + esc(c) + '"' + (c === S.heldBy ? ' selected' : '') + '>' + esc(c + ' · ' + holders[c]) + '</option>'; }).join('') + '</select>', 2)
            + '<button class="btn btn-primary" onclick="astShow()">Show</button>'
            + (open && selIds.length ? '<button class="btn btn-outline" onclick="astReturn(null)" style="margin-left:auto"><i class="fas fa-rotate-left"></i> Return selected (' + selIds.length + ')</button>' : '')
            + '</div>'
            + (S.issuesErr ? '<div style="padding:10px 16px;color:#991b1b;font-size:13px"><i class="fas fa-triangle-exclamation"></i> ' + esc(S.issuesErr) + '</div>' : '')
            + '<div style="overflow-x:auto"><table class="tbl" style="width:100%;font-size:13px"><thead><tr style="font-size:11px">' + th + '</tr></thead><tbody>'
            + (body || '<tr><td colspan="' + cols + '" style="text-align:center;color:var(--text3);padding:30px">' + (S.issuesLoading ? 'Loading…' : (open ? 'Nothing is out with anyone right now.' : 'No movements yet.')) + '</td></tr>')
            + (rows.length ? '<tr style="background:#f8fafc;font-weight:700"><td colspan="' + (open ? 6 : 5) + '">Total</td><td style="text-align:right;white-space:nowrap">' + money(tot) + '</td><td colspan="' + (cols - (open ? 7 : 6)) + '"></td></tr>' : '')
            + '</tbody></table></div></div>';

        return head('Asset Issue &amp; Returns', 'Hand out what is available, take it back when it returns. Stock numbers live on <a onclick="astToStock(&#39;&#39;)" style="color:var(--accent);cursor:pointer">Asset Stock</a> and update with every move here.', summaryPills())
            + exportBar + form + reg;
    }

    window.astForm = function (k, v, re) { S.form[k] = v; if (re) { rerender(['asset-issue']); } };
    window.astLine = function (i, k, v) {
        if (S.form.lines[i]) { S.form.lines[i][k] = v; }
        if (k === 'id') {
            var r = ((S.stock && S.stock.rows) || []).find(function (x) { return String(x.id) === String(v); });
            if (r && r.condition && r.condition !== 'lost') { S.form.lines[i].cond = r.condition; }
            rerender(['asset-issue']);
        }
    };
    window.astAddLine = function () {
        var last = S.form.lines[S.form.lines.length - 1];
        S.form.lines.push({ id: '', cond: last ? last.cond : 'new' });
        rerender(['asset-issue']);
    };
    window.astDelLine = function (i) { S.form.lines.splice(i, 1); if (!S.form.lines.length) { S.form.lines.push({ id: '', cond: 'new' }); } rerender(['asset-issue']); };
    window.astIssue = function () {
        var emp = findEmp(S.form.emp);
        if (!emp) { note('Pick an employee from the list'); return; }
        var items = S.form.lines.filter(function (l) { return l.id; }).map(function (l) { return { id: parseInt(l.id, 10), condition: l.cond }; });
        if (!items.length) { note('Pick at least one item'); return; }
        if (S.form.lines.some(function (l) { return !l.id; }) && !window.confirm('Some rows have no item picked — issue only the ' + items.length + ' picked item(s)?')) { return; }
        S.busy = true; rerender(['asset-issue']);
        postJ(BASE + '/issue', { emp_code: emp.code, issued_on: S.form.on, purpose: S.form.purpose, items: items }).then(function (j) {
            S.busy = false;
            if (j && j.ok) {
                note(j.message || 'Issued');
                S.form = { emp: '', on: S.form.on, purpose: '', lines: [{ id: '', cond: 'new' }] };
                S.view = 'open'; S.heldBy = '';
                loadStock(); loadIssues();
            } else {
                note((j && (j.error || j.message)) || 'Could not issue');
                loadStock();
            }
        }).catch(function () { S.busy = false; note('Could not reach the server'); rerender(['asset-issue']); });
    };
    window.astView = function (v) { S.view = v; S.heldBy = ''; S.issues = null; S.sel = {}; rerender(['asset-issue']); };
    window.astShow = function () { var el = document.getElementById('ast_held'); S.heldBy = el ? el.value : ''; S.sel = {}; rerender(['asset-issue']); };
    window.astSel = function (id, on) { S.sel[id] = !!on; rerender(['asset-issue']); };
    window.astSelAll = function (on) { S.sel = {}; if (on) { (S.issues || []).forEach(function (r) { if (!S.heldBy || r.emp_code === S.heldBy) { S.sel[r.id] = true; } }); } rerender(['asset-issue']); };
    window.astExport = function (fmt) {
        var el = document.getElementById('ast_exp'); var scope = el ? el.value : 'open';
        window.location.href = BASE + '/export?scope=' + encodeURIComponent(scope) + '&format=' + fmt;
    };
    window.astReturn = function (ids) {
        ids = ids || Object.keys(S.sel).filter(function (k) { return S.sel[k]; }).map(Number);
        var rows = (S.issues || []).filter(function (r) { return ids.indexOf(r.id) >= 0; });
        if (!rows.length) { return; }
        var list = rows.map(function (r) { return '<li>' + tag(r.code) + ' ' + esc(r.category) + (r.make_model ? ' · ' + esc(r.make_model) : '') + ' — <b>' + esc(r.emp_name) + '</b> <span style="color:var(--text3)">(since ' + esc(r.issued_on) + ')</span></li>'; }).join('');
        modal('<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px"><h3 style="margin:0;font-size:17px">Return ' + (rows.length === 1 ? 'item' : rows.length + ' items') + '</h3><button class="btn btn-outline btn-sm" onclick="astModalClose()"><i class="fas fa-xmark"></i></button></div>'
            + '<ul style="margin:0 0 14px 18px;padding:0;font-size:13px;line-height:1.9;max-height:220px;overflow:auto">' + list + '</ul>'
            + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">'
            + field('Returned on', '<input id="asr_on" type="date" value="' + today() + '" max="' + today() + '" style="' + INP + '">')
            + field('Condition on return', '<select id="asr_cond" style="' + INP + '"><option value="good">Good — back to stock</option><option value="fair">Fair — back to stock</option><option value="new">New — back to stock</option><option value="damaged">Damaged — send to repair</option><option value="lost">Lost / not returned</option></select>')
            + '</div><div style="margin-top:12px">' + field('Remarks', '<input id="asr_rem" placeholder="Optional" style="' + INP + '">') + '</div>'
            + '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px"><button class="btn btn-outline" onclick="astModalClose()">Cancel</button><button class="btn btn-primary" onclick="astDoReturn(' + esc(JSON.stringify(ids)) + ')"><i class="fas fa-rotate-left"></i> Return</button></div>');
    };
    window.astDoReturn = function (ids) {
        var g = function (k) { var el = document.getElementById(k); return el ? el.value : ''; };
        postJ(BASE + '/return', { ids: ids, returned_on: g('asr_on'), condition: g('asr_cond'), remarks: g('asr_rem') }).then(function (j) {
            if (j && j.ok) { closeModal(); note(j.message || 'Returned'); S.sel = {}; loadStock(); loadIssues(); }
            else { note((j && (j.error || j.message)) || 'Could not return'); }
        }).catch(function () { note('Could not reach the server'); });
    };

    // ============================================== MY ASSETS (staff, own items only)
    // Every non-admin role gets "My Assets": what the server says is issued to THEM
    // (/mine resolves the employee from the login — the page never asks for anyone
    // else's). Admin / HR use the two full screens instead.
    function myAssetsScreen() {
        var d = S.mine;
        if (!d) { S.mine = { loading: true }; getJ(BASE + '/mine').then(function (j) { S.mine = j || { rows: [] }; rerender(['my-assets']); }).catch(function () { S.mine = { rows: [], error: 'Could not reach the server' }; rerender(['my-assets']); }); d = S.mine; }
        var sub = 'Company assets issued to you. Speak to HR / Admin to return an item or report a problem.';
        if (d.loading) { return head('My Assets', sub) + '<div class="card"><div style="padding:40px;text-align:center;color:var(--text3)">Loading…</div></div>'; }
        if (d.ok === false || d.error) { return head('My Assets', sub) + '<div class="card" style="padding:14px 16px;color:#991b1b">' + esc(d.error || d.message || 'Could not load your assets') + '</div>'; }
        if (!d.employee) { return head('My Assets', sub) + '<div class="card"><div style="padding:36px;text-align:center;color:var(--text3)">Your login is not linked to an employee record, so no assets can be shown. Please contact HR.</div></div>'; }
        var rows = d.rows || [];
        var withMe = rows.filter(function (r) { return r.status === 'issued'; });
        var cap = function (v) { return esc(String(v || '').replace(/^./, function (c) { return c.toUpperCase(); })); };
        var tbl = function (list, returned) {
            return '<div style="overflow-x:auto"><table class="tbl" style="width:100%"><thead><tr><th>Asset ID</th><th>Category</th><th>Make / Model</th><th>Serial Number</th><th>Issued On</th><th>Condition</th><th>Purpose</th>' + (returned ? '<th>Returned On</th><th>Return Condition</th>' : '<th>Ref</th>') + '</tr></thead><tbody>'
                + list.map(function (r) {
                    return '<tr><td>' + tag(r.code) + '</td><td>' + esc(r.category) + '</td><td>' + esc(r.make_model) + '</td><td>' + tag(r.serial) + '</td><td style="white-space:nowrap">' + esc(r.issued_on) + '</td><td>' + cap(r.condition) + '</td><td>' + esc(r.purpose || '') + '</td>'
                        + (returned ? '<td style="white-space:nowrap">' + esc(r.returned_on || '') + '</td><td>' + cap(r.return_condition) + '</td>' : '<td style="font-size:11.5px;color:var(--text3)">' + esc(r.ref) + '</td>') + '</tr>';
                }).join('') + '</tbody></table></div>';
        };
        var back = rows.filter(function (r) { return r.status !== 'issued'; });
        return head('My Assets', sub, '<span style="background:#dbeafe;color:#1d4ed8;font-size:12px;font-weight:700;padding:5px 11px;border-radius:99px">' + withMe.length + ' with me</span>')
            + '<div class="card" style="padding:0;margin-bottom:16px"><div style="padding:14px 16px;font-weight:700;color:var(--accent)">With me now <span style="color:var(--text3);font-weight:400">(' + withMe.length + ') · ' + esc(d.employee.code) + ' · ' + esc(d.employee.name) + '</span></div>'
            + (withMe.length ? tbl(withMe, false) : '<div style="padding:26px;text-align:center;color:var(--text3)">No company assets are issued to you.</div>') + '</div>'
            + (back.length ? '<div class="card" style="padding:0"><div style="padding:14px 16px;font-weight:700;color:var(--text2)">Returned <span style="color:var(--text3);font-weight:400">(' + back.length + ')</span></div>' + tbl(back, true) + '</div>' : '');
    }
    function staffSetup() {
        try {
            if (typeof SCREENS !== 'undefined') { delete SCREENS['assets']; SCREENS['my-assets'] = { title: 'My Assets', type: 'custom' }; }
            // roleAllows() hides any nav id missing from the role's list — let every role see its own.
            if (typeof ROLES !== 'undefined') { Object.keys(ROLES).forEach(function (r) { if (ROLES[r].indexOf('my-assets') < 0) { ROLES[r].push('my-assets'); } }); }
        } catch (e) {}
        var wrapMine = function () {
            if (typeof window.renderCustom !== 'function' || window.renderCustom.__astMine) { return; }
            var _rc = window.renderCustom;
            window.renderCustom = function (id) { return id === 'my-assets' ? myAssetsScreen() : _rc.apply(this, arguments); };
            window.renderCustom.__astMine = true;
        };
        var nav = function () {
            document.querySelectorAll('.nav-item[data-id="assets"]').forEach(function (el) { el.parentNode && el.parentNode.removeChild(el); });
            try { if (localStorage.getItem('sp_screen') === 'assets') { localStorage.setItem('sp_screen', 'my-assets'); } } catch (e) {}
            if (document.querySelector('.nav-item[data-id="my-assets"]')) { return; }
            var anchor = navEl('payslip') || navEl('leave-apply') || navEl('dashboard');
            if (!anchor || !anchor.parentNode) { return; }
            var d = document.createElement('div');
            d.className = 'nav-item'; d.setAttribute('data-id', 'my-assets'); d.setAttribute('onclick', "go('my-assets',this)");
            if (anchor.parentNode.classList && anchor.parentNode.classList.contains('nav-sub')) { var ic = document.createElement('i'); ic.className = 'fas fa-laptop sp-subic'; d.appendChild(ic); }
            d.appendChild(document.createTextNode('My Assets'));
            anchor.parentNode.insertBefore(d, anchor.nextSibling);
        };
        wrapMine();
        try { nav(); } catch (e) {}
        if (typeof window.go === 'function' && !window.go.__astMine) {
            var _go = window.go;
            window.go = function (id, el) {
                if (id === 'assets') { id = 'my-assets'; el = navEl('my-assets'); }
                if (id === 'my-assets') { S.mine = null; }   // fresh on every visit
                return _go.call(this, id, el);
            };
            window.go.__astMine = true;
        }
        // boot() wraps renderCustom after us: wrap again so we stay outermost, and
        // repaint if boot already restored this screen before that.
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(function () { wrapMine(); try { nav(); } catch (e) {} if (cur() === 'my-assets' && typeof render === 'function') { render(); } }, 0);
        });
    }
    if (!IS_ADMIN) { staffSetup(); return; }

    // ---------------------------------------------------------------- register screens + nav
    try {
        if (typeof SCREENS !== 'undefined') {
            SCREENS['asset-stock'] = { title: 'Asset Stock', type: 'custom' };
            SCREENS['asset-issue'] = { title: 'Asset Issue & Returns', type: 'custom' };
            // 30 Sep 2026 (Ejaz): the old "Asset Register" is retired. Removing it here
            // (before boot) also keeps it out of the module search; its records are
            // carried into Asset Stock by AssetController::importLegacy().
            delete SCREENS['assets'];
        }
    } catch (e) {}
    // Every visit to either tab pulls fresh numbers (another admin may have issued
    // or returned meanwhile); the last data stays on screen until the reply lands.
    // A stale go('assets') (old bookmark / saved screen / old link) lands on Issue & Returns.
    function wrapGo() {
        if (typeof window.go !== 'function' || window.go.__astWrapped) { return; }
        var _go = window.go;
        window.go = function (id, el) {
            if (id === 'assets') { id = 'asset-issue'; el = navEl('asset-issue'); }
            if (id === 'asset-stock' || id === 'asset-issue') {
                if (cur() !== id) { S.busy = false; }
                loadStock();
                if (id === 'asset-issue') { loadEmps(); if (S.issues) { loadIssues(); } }
            }
            return _go.call(this, id, el);
        };
        for (var k in _go) { if (Object.prototype.hasOwnProperty.call(_go, k)) { window.go[k] = _go[k]; } }
        window.go.__astWrapped = true;
    }
    try { wrapGo(); } catch (e) {}
    function wrapRender() {
        if (typeof window.renderCustom !== 'function' || window.renderCustom.__astWrapped) { return; }
        var _rc = window.renderCustom;
        window.renderCustom = function (id) {
            if (id === 'asset-stock') { return stockScreen(); }
            if (id === 'asset-issue') { return issueScreen(); }
            return _rc.apply(this, arguments);
        };
        // Keep flags set by earlier wrappers so boot's own checks still behave.
        for (var k in _rc) { if (Object.prototype.hasOwnProperty.call(_rc, k)) { window.renderCustom[k] = _rc[k]; } }
        window.renderCustom.__astWrapped = true;
    }
    wrapRender();
    // boot() may re-wrap renderCustom later; its wrapper falls through to ours. Belt and braces:
    document.addEventListener('DOMContentLoaded', function () { setTimeout(wrapRender, 0); });

    function injectNav() {
        if (document.querySelector('.nav-item[data-id="asset-stock"]')) { removeOldNav(); return; }
        var anchor = document.querySelector('.nav-item[data-id="assets"]')
            || document.querySelector('.nav-item[data-id="banks"]')
            || document.querySelector('.nav-item[data-id="users"]');
        if (!anchor || !anchor.parentNode) { return; }
        var mk = function (id, label, icon) {
            var d = document.createElement('div');
            d.className = 'nav-item';
            d.setAttribute('data-id', id);
            d.setAttribute('onclick', "go('" + id + "',this)");
            if (anchor.parentNode.classList && anchor.parentNode.classList.contains('nav-sub')) {
                var i = document.createElement('i'); i.className = 'fas ' + icon + ' sp-subic'; d.appendChild(i);
            }
            d.appendChild(document.createTextNode(label));
            return d;
        };
        var a = mk('asset-stock', 'Asset Stock', 'fa-boxes-stacked');
        var b = mk('asset-issue', 'Asset Issue & Returns', 'fa-right-left');
        anchor.parentNode.insertBefore(a, anchor.nextSibling);
        anchor.parentNode.insertBefore(b, a.nextSibling);
        removeOldNav();
    }
    function removeOldNav() {
        document.querySelectorAll('.nav-item[data-id="assets"]').forEach(function (el) { el.parentNode && el.parentNode.removeChild(el); });
        try { if (localStorage.getItem('sp_screen') === 'assets') { localStorage.setItem('sp_screen', 'asset-issue'); } } catch (e) {}
    }
    try { injectNav(); } catch (e) {}
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { try { injectNav(); } catch (e) {} }); }
})();
