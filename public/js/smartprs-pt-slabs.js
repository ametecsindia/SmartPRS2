/*
 * SmartPRS 2 — PROFESSIONAL TAX RULES (Payroll → Salary Structure)
 *
 * 8 Oct 2026: custom From / To / PT slabs for All companies or one company.
 * 10 Oct 2026 (Ejaz): any number of RULES, each narrowed by Company, Branches,
 * Designations and Gender (the employee form's Male / Female / Other), each with
 * its own slabs. The MOST SPECIFIC matching rule decides an employee's PT; on
 * a tie the rule higher in the list wins (AppDataController::ptCustomRows).
 *
 * Saved through /app/settings as rates.pt_custom_rules = [{company, branches[],
 * designations[], genders[], slabs: [[from, to, amt], ...]}]. The old
 * rates.pt_custom_slabs map is folded into rules on load and emptied on save.
 * Static file, so nothing here goes through the PHP heredoc.
 */
(function () {
    'use strict';
    var cfg = window.__SMARTPRS || {};
    var S = window.__PTS = window.__PTS || { rules: null };
    var GENDERS = ['Male', 'Female', 'Other'];

    function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function note(m) { if (typeof toast === 'function') { toast(m); } else { window.alert(m); } }
    function rerender() { if (typeof window.render === 'function') { window.render(); } }
    function list(name) { try { return (typeof DB !== 'undefined' && DB && DB[name]) || []; } catch (e) { return []; } }
    function companies() { return list('companies'); }
    function names(objs, empKey) {
        var seen = {}, out = [];
        function add(n) { n = String(n == null ? '' : n).trim(); if (n && !seen[n.toLowerCase()]) { seen[n.toLowerCase()] = 1; out.push(n); } }
        objs.forEach(function (o) { add(o && typeof o === 'object' ? o.name : o); });
        list('employees').forEach(function (e) { add(e && e[empKey]); });   // free-text values on employees too
        return out.sort(function (a, b) { return a.localeCompare(b); });
    }
    function branches() { var b = list('branchesC'); return names(b.length ? b : list('branches'), 'branch'); }
    function designations() { return names(list('designations'), 'designation'); }
    function slabRows(rows) {
        return (rows || []).map(function (r) { return { from: r[0], to: Number(r[1]) > 0 ? r[1] : '', amt: r[2] }; });
    }
    function load() {
        var R = window.__RATES || {};
        S.rules = (R.pt_custom_rules || []).map(function (x) {
            return { company: x.company ? String(x.company) : '', branches: (x.branches || []).slice(), designations: (x.designations || []).slice(), genders: (x.genders || []).slice(), rows: slabRows(x.slabs) };
        });
        var old = R.pt_custom_slabs || {};
        Object.keys(old).forEach(function (k) {
            if (old[k] && old[k].length) { S.rules.push({ company: k === 'all' ? '' : String(k), branches: [], designations: [], genders: [], rows: slabRows(old[k]) }); }
        });
        S.saved = S.rules.length > 0;
    }
    function summary(r) {
        var p = [];
        if (r.company) { var c = companies().filter(function (x) { return String(x.id) === String(r.company); })[0]; p.push(c ? c.name : 'Company #' + r.company); }
        if (r.branches.length) { p.push('Branch: ' + r.branches.join(', ')); }
        if (r.designations.length) { p.push('Designation: ' + r.designations.join(', ')); }
        if (r.genders.length) { p.push('Gender: ' + r.genders.join(', ')); }
        return p.length ? p.join(' &middot; ') : 'All employees of all companies';
    }

    var inp = 'width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;background:#f8fafc;box-sizing:border-box';
    var lbl = 'font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;letter-spacing:.4px';

    function picker(ri, field, title, options) {
        var sel = S.rules[ri][field];
        var chips = sel.map(function (v, k) {
            return '<span style="display:inline-flex;align-items:center;gap:6px;background:#eef2ff;color:#3730a3;border-radius:999px;padding:3px 10px;font-size:12px;margin:0 6px 6px 0">' + esc(v)
                + '<a onclick="ptRuleDrop(' + ri + ',&#39;' + field + '&#39;,' + k + ')" title="Remove" style="cursor:pointer;color:#6366f1">&times;</a></span>';
        }).join('');
        var left = options.filter(function (o) { return sel.indexOf(o) < 0; });
        return '<div><label style="' + lbl + ';display:block;margin-bottom:4px">' + title + '</label>'
            + '<div>' + (chips || '<span style="font-size:12px;color:var(--text3);display:inline-block;margin-bottom:6px">Any</span>') + '</div>'
            + (left.length ? '<select onchange="ptRulePick(' + ri + ',&#39;' + field + '&#39;,this.value)" style="' + inp + '"><option value="">+ Add ' + title.toLowerCase() + '&hellip;</option>'
                + left.map(function (o) { return '<option value="' + esc(o) + '">' + esc(o) + '</option>'; }).join('') + '</select>'
                : (options.length ? '' : '<div style="font-size:11px;color:var(--text3)">No ' + title.toLowerCase() + ' set up yet.</div>'))
            + '</div>';
    }

    function ruleCard(r, ri) {
        var coSel = '<select onchange="ptRuleCo(' + ri + ',this.value)" style="' + inp + '"><option value="">All companies</option>'
            + companies().map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(r.company) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select>';
        var gen = GENDERS.map(function (g) {
            return '<label style="display:inline-flex;align-items:center;gap:5px;margin-right:12px;font-size:13px;cursor:pointer"><input type="checkbox"' + (r.genders.indexOf(g) >= 0 ? ' checked' : '')
                + ' onchange="ptRuleGender(' + ri + ',&#39;' + g + '&#39;,this.checked)"> ' + g + '</label>';
        }).join('');
        var num = inp + ';text-align:right';
        var rows = r.rows.map(function (s, i) {
            return '<tr>'
                + '<td style="padding:5px 6px"><input type="number" min="0" step="1" value="' + esc(s.from) + '" oninput="ptSlabSet(' + ri + ',' + i + ',&#39;from&#39;,this.value)" style="' + num + '"></td>'
                + '<td style="padding:5px 6px"><input type="number" min="0" step="1" value="' + esc(s.to) + '" placeholder="and above" oninput="ptSlabSet(' + ri + ',' + i + ',&#39;to&#39;,this.value)" style="' + num + '"></td>'
                + '<td style="padding:5px 6px"><input type="number" min="0" step="1" value="' + esc(s.amt) + '" oninput="ptSlabSet(' + ri + ',' + i + ',&#39;amt&#39;,this.value)" style="' + num + '"></td>'
                + '<td style="padding:5px 6px;text-align:center"><a onclick="ptSlabDel(' + ri + ',' + i + ')" title="Remove this slab" style="cursor:pointer;color:var(--red,#dc2626)"><i class="fas fa-trash"></i></a></td></tr>';
        }).join('');
        return '<div style="border:1.5px solid var(--border);border-radius:12px;padding:14px 16px;margin-bottom:12px;background:#fff">'
            + '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:10px">'
            + '<div><div style="font-weight:700;font-size:14px">Rule ' + (ri + 1) + '</div><div style="font-size:12px;color:var(--text3)">Applies to: ' + summary(r) + '</div></div>'
            + '<span style="display:flex;gap:6px">'
            + (ri > 0 ? '<button class="btn btn-outline btn-sm" title="Move up" onclick="ptRuleMove(' + ri + ',-1)"><i class="fas fa-arrow-up"></i></button>' : '')
            + '<button class="btn btn-outline btn-sm" title="Remove this rule" onclick="ptRuleDel(' + ri + ')" style="color:var(--red,#dc2626)"><i class="fas fa-trash"></i></button></span></div>'
            + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:12px">'
            + '<div><label style="' + lbl + ';display:block;margin-bottom:4px">Company</label>' + coSel + '</div>'
            + picker(ri, 'branches', 'Branches', branches())
            + picker(ri, 'designations', 'Designations', designations())
            + '<div><label style="' + lbl + ';display:block;margin-bottom:6px">Gender</label>' + gen + '<div style="font-size:11px;color:var(--text3);margin-top:2px">None ticked = any gender</div></div>'
            + '</div>'
            + '<div style="overflow-x:auto"><table style="width:100%;max-width:640px;border-collapse:collapse">'
            + '<tr><th style="' + lbl + ';text-align:left;padding:4px 6px">From &#8377; (gross)</th><th style="' + lbl + ';text-align:left;padding:4px 6px">To &#8377; (gross)</th><th style="' + lbl + ';text-align:left;padding:4px 6px">PT &#8377; / month</th><th></th></tr>'
            + rows + '</table></div>'
            + '<button class="btn btn-outline btn-sm" style="margin-top:8px" onclick="ptSlabAdd(' + ri + ')"><i class="fas fa-plus"></i> Add slab</button>'
            + '</div>';
    }

    window.ptSlabCard = function () {
        if (!window.__RATES) {
            if (!S.loading) {
                S.loading = true;
                fetch(cfg.settingsUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then(function (x) { return x.json(); })
                    .then(function (d) { window.__RATES = (d && d.rates) || {}; S.loading = false; rerender(); })
                    .catch(function () { S.loading = false; });
            }
            return '<div class="card" style="padding:16px 18px;margin-top:14px;color:var(--text3)">Loading Professional Tax rules&hellip;</div>';
        }
        if (!S.rules) { load(); }
        return '<div class="card" style="padding:18px 20px;margin-top:14px">'
            + '<h3 style="margin:0 0 6px;font-size:15px"><i class="fas fa-landmark" style="color:#6366f1;margin-right:6px"></i>Professional Tax (PT) &mdash; custom rules</h3>'
            + '<p style="font-size:12px;color:var(--text3);margin:0 0 12px">Each rule says <b>who</b> it is for (company, branches, designations, gender &mdash; leave a filter empty for &ldquo;any&rdquo;) and the <b>monthly gross &rarr; PT</b> slabs for them. Leave <b>To</b> blank for &ldquo;and above&rdquo;; gross outside every range pays no PT. '
            + 'When an employee matches several rules, the <b>most specific</b> one decides (the rule with the most filters set); on a tie, the rule higher in the list. '
            + 'Example: <i>All employees 15,001+ &rarr; &#8377;200</i> and <i>Branch Hyderabad + Designation Field Executive 15,001+ &rarr; &#8377;100</i> &mdash; a Field Executive in Hyderabad pays &#8377;100, everyone else &#8377;200. '
            + 'Employees no rule matches follow the state-wise PT tables. Used by Generate Payroll, payslips, Live Salary, the Simulator and the PT report from the next run.</p>'
            + (S.rules.length ? S.rules.map(ruleCard).join('') : '<div style="padding:14px;border:1.5px dashed var(--border);border-radius:12px;color:var(--text3);font-size:13px;margin-bottom:12px">No custom PT rules &mdash; every employee follows the state-wise PT tables.</div>')
            + '<div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:space-between">'
            + '<button class="btn btn-outline btn-sm" onclick="ptRuleAdd()"><i class="fas fa-plus"></i> Add rule</button>'
            + '<button class="btn btn-primary" onclick="ptSlabSave()"><i class="fas fa-check"></i> Save PT Rules</button></div>'
            + '<div style="font-size:11px;color:var(--text3);margin-top:6px">' + (S.saved ? 'Saved rules are active.' : 'Nothing saved yet.') + ' Already-generated draft runs keep their figures until regenerated.</div>'
            + '</div>';
    };
    window.ptRuleAdd = function () {
        S.rules.push({ company: '', branches: [], designations: [], genders: [], rows: S.rules.length ? [{ from: 0, to: '', amt: '' }] : [{ from: 0, to: 15000, amt: 150 }, { from: 15001, to: '', amt: 200 }] });
        rerender();
    };
    window.ptRuleDel = function (ri) { if (window.confirm('Remove Rule ' + (ri + 1) + '? (Takes effect when you Save.)')) { S.rules.splice(ri, 1); rerender(); } };
    window.ptRuleMove = function (ri, d) { var t = S.rules[ri]; S.rules[ri] = S.rules[ri + d]; S.rules[ri + d] = t; rerender(); };
    window.ptRuleCo = function (ri, v) { S.rules[ri].company = v || ''; rerender(); };
    window.ptRulePick = function (ri, f, v) { if (v && S.rules[ri][f].indexOf(v) < 0) { S.rules[ri][f].push(v); } rerender(); };
    window.ptRuleDrop = function (ri, f, k) { S.rules[ri][f].splice(k, 1); rerender(); };
    window.ptRuleGender = function (ri, g, on) {
        var a = S.rules[ri].genders, k = a.indexOf(g);
        if (on && k < 0) { a.push(g); } else if (!on && k >= 0) { a.splice(k, 1); }
        rerender();
    };
    window.ptSlabSet = function (ri, i, k, v) { if (S.rules[ri] && S.rules[ri].rows[i]) { S.rules[ri].rows[i][k] = v; } };
    window.ptSlabAdd = function (ri) {
        var rows = S.rules[ri].rows, last = rows[rows.length - 1];
        rows.push({ from: last && Number(last.to) > 0 ? Number(last.to) + 1 : '', to: '', amt: '' });
        rerender();
    };
    window.ptSlabDel = function (ri, i) { S.rules[ri].rows.splice(i, 1); rerender(); };
    window.ptSlabSave = function () {
        var out = [];
        for (var ri = 0; ri < S.rules.length; ri++) {
            var r = S.rules[ri], slabs = [];
            for (var i = 0; i < r.rows.length; i++) {
                var s = r.rows[i], tag = 'Rule ' + (ri + 1) + ', row ' + (i + 1) + ': ';
                if (String(s.amt) === '' && String(s.from) === '' && String(s.to) === '') { continue; }
                if (String(s.amt) === '' || Number(s.amt) < 0) { note(tag + 'enter the PT amount'); return; }
                if (String(s.to) !== '' && Number(s.to) < Number(s.from || 0)) { note(tag + '"To" is less than "From"'); return; }
                slabs.push({ from: Number(s.from || 0), to: String(s.to) === '' ? 0 : Number(s.to), amt: Number(s.amt) });
            }
            if (!slabs.length) { note('Rule ' + (ri + 1) + ': add at least one slab, or remove the rule'); return; }
            out.push({ company: Number(r.company) || 0, branches: r.branches, designations: r.designations, genders: r.genders, slabs: slabs });
        }
        fetch(cfg.settingsSaveUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ pt_custom_rules: out }) })
            .then(function (x) { return x.json().catch(function () { return { ok: false }; }); })
            .then(function (d) {
                if (d && d.ok) { window.__RATES = d.rates; load(); note(out.length ? 'PT rules saved — used from the next payroll run' : 'Custom PT rules cleared — state-wise PT tables apply'); rerender(); }
                else { note((d && (d.error || d.message)) || 'Save failed — admin only'); }
            }).catch(function () { note('Save failed — admin only'); });
    };
})();
