/*
 * SmartPRS 2 — request View / Edit dialogs (30 Sep 2026, Ejaz)
 *
 * Expense Claims, Salary Advance, Loans and Leave: before Admin / HR decide, they
 * can open EVERYTHING the employee submitted (reason included), and adjust only
 * the approver-side fields of a PENDING request:
 *   Expense  → approved amount, expense date
 *   Advance  → sanctioned amount
 *   Loan     → sanctioned amount, EMI, tenure (months)
 *   Leave    → From / To dates (days recomputed on the server)
 * The employee's reason / purpose / remarks are shown read-only and never sent.
 * Rows come from the screens' own data (window.__REQ[navId], window.__LEAVE);
 * setting those to null makes the screen reload itself.
 * Backend: RequestController::editFields, LeaveController::edit (routes/approvals.php).
 */
(function () {
    'use strict';
    var cfg = window.__SMARTPRS || {};
    var MOD = { expenses: 'expenses', advance: 'advances', loans: 'loans' };
    var TITLE = { expenses: 'Expense Claim', advance: 'Salary Advance', loans: 'Loan' };

    function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function inr(n) { return n == null || n === '' ? '—' : '₹' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function say(m) { if (typeof toast === 'function') { toast(m); } else { window.alert(m); } }
    function repaint() { if (typeof render === 'function') { render(); } }
    var INP = 'width:100%;box-sizing:border-box;padding:9px 11px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;background:#fffbeb';

    function modal(html) {
        var m = document.getElementById('rq-modal');
        if (!m) { m = document.createElement('div'); m.id = 'rq-modal'; document.body.appendChild(m); }
        m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9000;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto';
        m.innerHTML = '<div class="card" style="max-width:600px;width:100%;padding:22px 24px;margin:auto">' + html + '</div>';
        m.onclick = function (e) { if (e.target === m) { close(); } };
    }
    function close() { var m = document.getElementById('rq-modal'); if (m) { m.style.display = 'none'; m.innerHTML = ''; } }
    window.rqModalClose = close;
    function header(t) { return '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px"><h3 style="margin:0;font-size:17px">' + t + '</h3><button class="btn btn-outline btn-sm" onclick="rqModalClose()"><i class="fas fa-xmark"></i></button></div>'; }
    function row(a, b) { return '<div style="display:flex;justify-content:space-between;gap:16px;padding:7px 0;border-bottom:1px solid var(--border);font-size:13.5px"><span style="color:var(--text3);white-space:nowrap">' + a + '</span><b style="text-align:right">' + (b === '' || b == null ? '—' : b) + '</b></div>'; }
    function block(label, text) {
        return '<div style="margin-top:12px"><div style="font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px">' + label + '</div>'
            + '<div style="background:#f8fafc;border:1px solid var(--border);border-radius:9px;padding:10px 12px;font-size:13.5px;line-height:1.55;white-space:pre-wrap">' + (text ? esc(text) : '<span style="color:var(--text3)">Not provided</span>') + '</div></div>';
    }
    function field(label, ctrl) { return '<div><label style="font-size:12px;font-weight:600;color:var(--text2);display:block;margin-bottom:5px">' + label + '</label>' + ctrl + '</div>'; }
    function trail(r) {
        return row('Status', esc(r.status)) + row('Approver', esc(r.approver))
            + (r.decidedBy ? row('Decided by', esc(r.decidedBy) + (r.decidedAt ? ' · ' + esc(r.decidedAt) : '')) : '')
            + (r.remarks ? row('Approver remarks', esc(r.remarks)) : '')
            + (r.editedBy ? row('Edited by (Admin/HR)', esc(r.editedBy) + (r.editedAt ? ' · ' + esc(r.editedAt) : '')) : '');
    }
    function buttons(extra) { return '<div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;margin-top:16px">' + extra + '<button class="btn btn-outline" onclick="rqModalClose()">Close</button></div>'; }

    // ------------------------------------------------ money requests
    function findReq(navId, id) { var d = (window.__REQ || {})[navId]; return ((d && d.rows) || []).find(function (x) { return String(x.id) === String(id); }); }

    window.reqView = function (navId, id) {
        var r = findReq(navId, id); if (!r) { return; }
        var m = MOD[navId], asked = r.requestedAmount != null ? r.requestedAmount : r.amount;
        var h = header(TITLE[navId] + ' — ' + esc(r.employee)) + row('Employee', esc(r.employee) + (r.empCode ? ' (' + esc(r.empCode) + ')' : '')) + row('Company', esc(r.company)) + row('Submitted on', esc(r.submittedAt));
        if (navId === 'expenses') { h += row('Expense type', esc(r.type)) + row('Expense date', esc(r.expenseDate)) + row('Amount claimed', inr(asked)) + row('Approved amount', inr(r.amount)); }
        if (navId === 'advance') { h += row('Amount requested', inr(asked)) + row('Sanctioned amount', inr(r.amount)); }
        if (navId === 'loans') { h += row('Amount requested', inr(asked)) + row('Sanctioned amount', inr(r.amount)) + row('EMI', inr(r.emi)) + row('Tenure', r.tenureMonths ? r.tenureMonths + ' months' : ''); }
        h += trail(r) + block(navId === 'loans' ? 'Purpose — as written by the employee' : 'Reason — as written by the employee', r.reason);
        var btn = '';
        if (r.canEdit) { btn += '<button class="btn btn-outline" onclick="reqEdit(\'' + navId + '\',' + r.id + ')"><i class="fas fa-pen"></i> Edit</button>'; }
        if (r.canDecide && typeof window.reqDecide === 'function') {
            btn += '<button class="btn btn-outline" style="color:var(--red)" onclick="rqModalClose();reqDecide(\'' + m + '\',' + r.id + ',\'reject\')">Reject</button>'
                + '<button class="btn btn-primary" onclick="rqModalClose();reqDecide(\'' + m + '\',' + r.id + ',\'approve\')"><i class="fas fa-check"></i> Approve</button>';
        }
        modal(h + buttons(btn));
    };

    window.reqEdit = function (navId, id) {
        var r = findReq(navId, id); if (!r) { return; }
        var asked = r.requestedAmount != null ? r.requestedAmount : r.amount;
        var f = '';
        if (navId === 'expenses') {
            f += field('Approved amount (₹) — claimed ' + inr(asked), '<input id="rqe_amount" type="number" min="0" step="0.01" value="' + esc(r.amount) + '" style="' + INP + '">')
                + field('Expense date', '<input id="rqe_date" type="date" value="' + esc(r.expenseDate) + '" style="' + INP + '">');
        } else {
            f += field('Sanctioned amount (₹) — requested ' + inr(asked), '<input id="rqe_amount" type="number" min="0" step="0.01" value="' + esc(r.amount) + '" style="' + INP + '"' + (navId === 'loans' ? ' oninput="rqEmiCalc()"' : '') + '>');
        }
        if (navId === 'loans') {
            f += field('Tenure (months)', '<input id="rqe_tenure_months" type="number" min="0" step="1" value="' + esc(r.tenureMonths || '') + '" style="' + INP + '" oninput="rqEmiCalc()">')
                + field('EMI (₹) <a onclick="rqEmiCalc(1)" style="cursor:pointer;color:var(--accent);font-weight:400">= amount ÷ tenure</a>', '<input id="rqe_emi" type="number" min="0" step="0.01" value="' + esc(r.emi || '') + '" style="' + INP + '">');
        }
        modal(header('Edit ' + TITLE[navId] + ' — ' + esc(r.employee))
            + '<div style="font-size:12.5px;color:var(--text3);margin-bottom:12px">Only the approver-side figures can change. The employee\'s own reason stays exactly as written.</div>'
            + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' + f + '</div>'
            + block(navId === 'loans' ? 'Purpose — employee (read-only)' : 'Reason — employee (read-only)', r.reason)
            + buttons('<button class="btn btn-primary" onclick="reqEditSave(\'' + navId + '\',' + r.id + ')"><i class="fas fa-check"></i> Save changes</button>'));
    };
    window.rqEmiCalc = function (force) {
        var a = parseFloat((document.getElementById('rqe_amount') || {}).value), t = parseInt((document.getElementById('rqe_tenure_months') || {}).value, 10), e = document.getElementById('rqe_emi');
        if (e && a > 0 && t > 0 && (force || !e.value)) { e.value = (Math.round(a / t * 100) / 100).toFixed(2); }
    };
    window.reqEditSave = function (navId, id) {
        var body = {};
        ['amount', 'date', 'emi', 'tenure_months'].forEach(function (k) { var el = document.getElementById('rqe_' + k); if (el) { body[k] = el.value; } });
        fetch(cfg.requestsBase + '/' + MOD[navId] + '/' + id + '/edit', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body) })
            .then(function (x) { return x.json(); })
            .then(function (j) {
                if (j && j.ok) { close(); say(j.message || 'Saved'); window.__REQ[navId] = null; repaint(); }
                else { say((j && (j.error || j.message)) || 'Could not save'); }
            }).catch(function () { say('Could not reach the server'); });
    };

    // ------------------------------------------------ leave
    function findLeave(id) { var d = window.__LEAVE; return ((d && d.rows) || []).find(function (x) { return String(x.id) === String(id); }); }

    window.leaveView = function (id) {
        var r = findLeave(id); if (!r) { return; }
        var h = header('Leave — ' + esc(r.employee)) + row('Employee', esc(r.employee) + (r.empCode ? ' (' + esc(r.empCode) + ')' : '')) + row('Company', esc(r.company))
            + row('Leave type', esc(r.type)) + row('From', esc(r.from)) + row('To', esc(r.to)) + row('Days', esc(r.days)) + row('Applied on', esc(r.appliedAt))
            + trail(r) + block('Reason — as written by the employee', r.reason);
        var btn = '';
        if (r.canEdit) { btn += '<button class="btn btn-outline" onclick="leaveEdit(' + r.id + ')"><i class="fas fa-pen"></i> Edit dates</button>'; }
        if (r.canDecide && typeof window.leaveDecide === 'function') {
            btn += '<button class="btn btn-outline" style="color:var(--red)" onclick="rqModalClose();leaveDecide(' + r.id + ',\'reject\')">Reject</button>'
                + '<button class="btn btn-primary" onclick="rqModalClose();leaveDecide(' + r.id + ',\'approve\')"><i class="fas fa-check"></i> Approve</button>';
        }
        modal(h + buttons(btn));
    };
    window.leaveEdit = function (id) {
        var r = findLeave(id); if (!r) { return; }
        modal(header('Edit leave dates — ' + esc(r.employee))
            + '<div style="font-size:12.5px;color:var(--text3);margin-bottom:12px">' + esc(r.type) + ' · currently ' + esc(r.from) + ' → ' + esc(r.to) + ' (' + esc(r.days) + ' day(s)). Days are recalculated when you save.</div>'
            + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">'
            + field('From', '<input id="lve_from" type="date" value="' + esc(String(r.from).slice(0, 10)) + '" style="' + INP + '">')
            + field('To', '<input id="lve_to" type="date" value="' + esc(String(r.to).slice(0, 10)) + '" style="' + INP + '">') + '</div>'
            + block('Reason — employee (read-only)', r.reason)
            + buttons('<button class="btn btn-primary" onclick="leaveEditSave(' + r.id + ')"><i class="fas fa-check"></i> Save dates</button>'));
    };
    window.leaveEditSave = function (id) {
        var f = (document.getElementById('lve_from') || {}).value, t = (document.getElementById('lve_to') || {}).value;
        if (!f || !t) { say('Pick both dates'); return; }
        if (t < f) { say('To date cannot be before From date'); return; }
        fetch(String(cfg.leaveDecideUrl || '/app/leaves') + '/' + id + '/edit', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ from: f, to: t }) })
            .then(function (x) { return x.json(); })
            .then(function (j) {
                if (j && j.ok) { close(); say(j.message || 'Saved'); window.__LEAVE = null; repaint(); }
                else { say((j && (j.error || j.message)) || 'Could not save'); }
            }).catch(function () { say('Could not reach the server'); });
    };
})();
