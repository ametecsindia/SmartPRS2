        // =================================================================
        // F1 — Statutory Configuration (effective-dated, scoped overrides)
        // =================================================================
        function scIntro() {
            return '<div class="card" style="margin-bottom:14px"><div style="padding:14px 18px;font-size:12.5px;color:var(--text3)">'
                + 'Set an override once and it applies from the date you choose, so regenerating an earlier month still uses that month rates. Narrowest scope wins: branch, then location, then company, then state, then the whole tenant. With nothing saved here, payroll uses exactly the rates it uses today.'
                + '</div></div>';
        }
        function scHistory(d) {
            var h = d.history || [];
            if (!h.length) { return '<div class="card" style="margin-top:14px">' + empty('No statutory overrides saved yet.') + '</div>'; }
            var rows = '';
            for (var i = 0; i < h.length; i++) {
                var r = h[i];
                var scopeTxt = r.scope === 'company' ? ('company #' + esc(r.company_id))
                    : (r.scope === 'tenant' ? 'whole tenant' : (esc(r.scope) + ' ' + esc(r.scope_value || '')));
                var vals = [];
                for (var k in (r.values || {})) {
                    var vv = r.values[k];
                    vals.push(esc(k) + '=' + esc((vv !== null && typeof vv === 'object') ? JSON.stringify(vv) : vv));
                }
                rows += '<tr>' + td(esc(r.effective_from || '-')) + td(esc(r.kind_label || r.kind))
                    + td(scopeTxt) + td(vals.join(', ')) + td(esc(r.by || '')) + '</tr>';
            }
            return '<div class="card" style="margin-top:14px;padding:0"><div style="padding:12px 16px;font-weight:700;font-size:13.5px">History</div>'
                + '<div style="overflow:auto">' + tblOpen(['Effective', 'Rates', 'Scope', 'Values', 'By']) + rows + tblClose + '</div></div>';
        }
        function ptSlabEditor(d) {
            var states = d.states || [];
            var o = '<option value="">Choose a state...</option>';
            for (var i = 0; i < states.length; i++) { o += '<option value="' + esc(states[i]) + '">' + esc(states[i]) + '</option>'; }
            var editor = '<div style="font-size:12.5px;color:var(--text3);margin-bottom:12px">Correct a state slab, add a state that is not built in, or mark a state Professional-Tax-free. Bands are entered as <b>upto:amount</b> pairs, comma separated, with <b>0</b> meaning everything above. Example: 7500:0, 10000:175, 0:200.</div>'
                + fieldRow('State', '<select id="sc-slab-state" class="form-input" style="width:260px">' + o + '</select>')
                + '<label style="display:block;margin-bottom:10px;font-size:13.5px"><input type="checkbox" id="sc-slab-free"> This state has no Professional Tax</label>'
                + fieldRow('Bands (upto:amount, comma separated; 0 = above all)', '<input type="text" id="sc-slab-bands" class="form-input" style="width:100%" placeholder="7500:0, 10000:175, 0:200">')
                + fieldRow('Effective from', '<input type="date" id="sc-slab-eff" class="form-input" style="width:200px" value="' + esc(window.__STCFG_UI.eff) + '" onchange="scSet(&#39;eff&#39;,this.value)">')
                + '<button class="btn btn-primary" onclick="scSaveSlab()"><i class="fas fa-floppy-disk"></i> Save PT slab</button>';
            var slabs = d.slabs || [];
            var rows = '';
            for (var i = 0; i < slabs.length; i++) {
                var s = slabs[i];
                var bt = 'PT-free';
                if (!s.pt_free) {
                    var parts = [];
                    for (var j = 0; j < (s.bands || []).length; j++) {
                        var b = s.bands[j];
                        parts.push((b.upto > 0 ? ('upto ' + b.upto) : 'above') + ' = ' + b.amt);
                    }
                    bt = parts.join('; ');
                }
                var src = s.source === 'built-in' ? pill('built-in', '#e5e7eb', '#4b5563')
                    : (s.source === 'configured' ? pill('changed', '#dbeafe', '#1e40af') : pill('added', '#dcfce7', '#15803d'));
                rows += '<tr>' + td(esc(s.state)) + td(bt) + td(src) + '</tr>';
            }
            var ref = slabs.length ? ('<div class="card" style="margin-top:14px;padding:0"><div style="padding:12px 16px;font-weight:700;font-size:13.5px">Current PT slabs</div>'
                + '<div style="overflow:auto">' + tblOpen(['State', 'Bands', 'Source']) + rows + tblClose + '</div></div>') : '';
            return editor + ref;
        }
        function scScopeArgs() {
            var ui = window.__STCFG_UI;
            var a = { scope: ui.scope, scope_value: '', company_id: '' };
            if (ui.scope === 'company') { a.company_id = ui.company_id; }
            else if (ui.scope === 'state') { a.scope_value = ui.state; }
            else if (ui.scope === 'branch') { a.scope_value = ui.branch_id; }
            else if (ui.scope === 'location') { a.scope_value = ui.location; }
            return a;
        }
        function scCollect() {
            var d = window.__STCFG, ui = window.__STCFG_UI, kinds = d.kinds || [], ck = null;
            for (var i = 0; i < kinds.length; i++) { if (kinds[i].key === ui.kind) { ck = kinds[i]; } }
            var p = {}, fs = (ck && ck.fields) || [];
            for (var i = 0; i < fs.length; i++) {
                var f = fs[i];
                if (f.type === 'toggle') { p[f.key] = checked('scf-' + f.key); }
                else { var raw = val('scf-' + f.key); if (raw !== '') { p[f.key] = parseFloat(raw); } }
            }
            return p;
        }
        window.scSet = function (k, v) {
            var ui = window.__STCFG_UI; ui[k] = v;
            if (k === 'kind' || k === 'scope') { repaint(); }
        };
        window.scSave = function () {
            var ui = window.__STCFG_UI, a = scScopeArgs(), payload = scCollect(), hasVal = false;
            for (var k in payload) { hasVal = true; }
            if (!hasVal) { say('Enter at least one rate value'); return; }
            if (!ui.eff) { say('Choose an effective-from date'); return; }
            if (a.scope === 'company' && !a.company_id) { say('Choose a company'); return; }
            if ((a.scope === 'state' || a.scope === 'branch' || a.scope === 'location') && !a.scope_value) { say('Choose the ' + a.scope); return; }
            post('/app/statutory-config/save', {
                kind: ui.kind, scope: a.scope, scope_value: a.scope_value, company_id: a.company_id,
                effective_from: ui.eff, payload: payload, note: val('sc-note')
            }).then(function (j) {
                if (!j.ok) { say(j.error || 'Could not save'); return; }
                say(j.message || 'Saved'); window.__STCFG = null; repaint();
            });
        };
        window.scPreview = function () {
            var ui = window.__STCFG_UI, a = scScopeArgs(), payload = scCollect();
            var box = document.getElementById('sc-preview');
            if (box) { box.innerHTML = '<span style="color:var(--text3)">Calculating...</span>'; }
            post('/app/statutory-config/preview', { company_id: a.company_id, payload: payload, month: ui.month }).then(function (j) {
                if (!box) { return; }
                if (!j.ok) { box.innerHTML = '<span style="color:var(--red)">' + esc(j.error || 'Could not preview') + '</span>'; return; }
                var rows = '', list = j.rows || [];
                for (var i = 0; i < list.length && i < 50; i++) {
                    var r = list[i];
                    rows += '<tr>' + td(esc(r.name)) + td(esc(r.state)) + td(esc(r.net_before))
                        + td(esc(r.net_after)) + td((r.delta >= 0 ? '+' : '') + esc(r.delta)) + '</tr>';
                }
                var tbl = list.length ? ('<div style="overflow:auto;margin-top:10px">' + tblOpen(['Employee', 'State', 'Net now', 'Net after', 'Change']) + rows + tblClose + '</div>')
                    : '<div style="font-size:12.5px;color:var(--text3);margin-top:8px">No payslip changes for ' + esc(j.month) + '.</div>';
                box.innerHTML = '<div style="border:1px solid var(--border);border-radius:8px;padding:14px">'
                    + '<div style="font-weight:700;margin-bottom:4px">Impact on ' + esc(j.month) + '</div>'
                    + '<div style="font-size:13px;color:var(--text3)">' + esc(j.employees) + ' employees priced, ' + esc(j.affected) + ' change, net total ' + esc(j.before) + ' to ' + esc(j.after) + ' (' + ((j.delta >= 0) ? '+' : '') + esc(j.delta) + ')</div>'
                    + tbl + '</div>';
            });
        };
        window.scSaveSlab = function () {
            var st = val('sc-slab-state'); if (!st) { say('Choose a state'); return; }
            var eff = val('sc-slab-eff'); if (!eff) { say('Choose an effective-from date'); return; }
            var free = checked('sc-slab-free');
            var body = { state: st, effective_from: eff, pt_free: free, scope: 'tenant' };
            if (!free) {
                var raw = val('sc-slab-bands'), bands = [], parts = raw.split(',');
                for (var i = 0; i < parts.length; i++) {
                    var seg = parts[i].split(':');
                    if (seg.length < 2) { continue; }
                    var upto = parseFloat(seg[0]), amt = parseFloat(seg[1]);
                    if (isNaN(upto) || isNaN(amt)) { continue; }
                    bands.push({ upto: upto, amt: amt });
                }
                if (!bands.length) { say('Enter at least one band, like 0:200'); return; }
                body.bands = bands;
            }
            post('/app/statutory-config/slab', body).then(function (j) {
                if (!j.ok) { say(j.error || 'Could not save slab'); return; }
                say(j.message || 'Slab saved'); window.__STCFG = null; repaint();
            });
        };
        function statutoryConfigScreen() {
            var d = window.__STCFG;
            if (!d) { getJson('/app/statutory-config', '__STCFG'); return loading('Statutory Configuration'); }
            if (!d.ok) { return errCard('Statutory Configuration', d.error); }
            var kinds = d.kinds || [];
            if (!window.__STCFG_UI) {
                window.__STCFG_UI = { kind: (kinds[0] && kinds[0].key) || 'pf', scope: 'tenant', company_id: '', state: '', branch_id: '', location: '', eff: '', month: d.month || '' };
            }
            var ui = window.__STCFG_UI, curKind = null;
            for (var i = 0; i < kinds.length; i++) { if (kinds[i].key === ui.kind) { curKind = kinds[i]; } }
            if (!curKind) { curKind = kinds[0] || { key: '', label: '', fields: [] }; ui.kind = curKind.key; }
            var kindOpts = '';
            for (var i = 0; i < kinds.length; i++) {
                kindOpts += '<option value="' + esc(kinds[i].key) + '"' + (kinds[i].key === ui.kind ? ' selected' : '') + '>' + esc(kinds[i].label) + '</option>';
            }
            var scopes = d.scopes || [];
            var scopeLabel = { tenant: 'Whole tenant (all companies)', state: 'One state', company: 'One company', branch: 'One branch', location: 'One location (branch city)' };
            var scopeOpts = '';
            for (var i = 0; i < scopes.length; i++) {
                scopeOpts += '<option value="' + esc(scopes[i]) + '"' + (scopes[i] === ui.scope ? ' selected' : '') + '>' + esc(scopeLabel[scopes[i]] || scopes[i]) + '</option>';
            }
            var scopeInput = '';
            if (ui.scope === 'company') {
                var o = '<option value="">Choose a company...</option>';
                for (var i = 0; i < (d.companies || []).length; i++) { var c = d.companies[i]; o += '<option value="' + c.id + '"' + (('' + c.id) === ('' + ui.company_id) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }
                scopeInput = fieldRow('Company', '<select id="sc-company" class="form-input" style="width:100%" onchange="scSet(&#39;company_id&#39;,this.value)">' + o + '</select>');
            } else if (ui.scope === 'state') {
                var o = '<option value="">Choose a state...</option>';
                for (var i = 0; i < (d.states || []).length; i++) { var s = d.states[i]; o += '<option value="' + esc(s) + '"' + (s === ui.state ? ' selected' : '') + '>' + esc(s) + '</option>'; }
                scopeInput = fieldRow('State', '<select id="sc-state" class="form-input" style="width:100%" onchange="scSet(&#39;state&#39;,this.value)">' + o + '</select>');
            } else if (ui.scope === 'branch') {
                var o = '<option value="">Choose a branch...</option>';
                for (var i = 0; i < (d.branches || []).length; i++) { var bb = d.branches[i]; var lbl = bb.name + (bb.city ? (' (' + bb.city + ')') : ''); o += '<option value="' + bb.id + '"' + (('' + bb.id) === ('' + ui.branch_id) ? ' selected' : '') + '>' + esc(lbl) + '</option>'; }
                scopeInput = fieldRow('Branch', '<select id="sc-branch" class="form-input" style="width:100%" onchange="scSet(&#39;branch_id&#39;,this.value)">' + o + '</select>');
            } else if (ui.scope === 'location') {
                var o = '<option value="">Choose a location...</option>';
                for (var i = 0; i < (d.locations || []).length; i++) { var L = d.locations[i]; o += '<option value="' + esc(L) + '"' + (L === ui.location ? ' selected' : '') + '>' + esc(L) + '</option>'; }
                scopeInput = fieldRow('Location (branch city)', '<select id="sc-location" class="form-input" style="width:100%" onchange="scSet(&#39;location&#39;,this.value)">' + o + '</select>');
            } else {
                scopeInput = '<div style="font-size:12.5px;color:var(--text3);margin-bottom:12px">Applies to every company in this tenant.</div>';
            }
            var bodyHtml = '';
            if (ui.kind === 'pt_slabs') {
                bodyHtml = ptSlabEditor(d);
            } else {
                var cur = d.current || {}, fh = '', fs = curKind.fields || [];
                if (!fs.length) { fh = '<div style="font-size:12.5px;color:var(--text3)">This area has no editable rates yet.</div>'; }
                for (var i = 0; i < fs.length; i++) {
                    var f = fs[i], cv = (cur[f.key] === null || cur[f.key] === undefined) ? '' : cur[f.key];
                    if (f.type === 'toggle') {
                        fh += '<label style="display:block;margin-bottom:10px;font-size:13.5px"><input type="checkbox" id="scf-' + esc(f.key) + '" ' + (cv ? 'checked' : '') + '> ' + esc(f.label) + '</label>';
                    } else {
                        fh += fieldRow(f.label + ' (now: ' + esc(cv === '' ? '-' : cv) + ')', '<input type="number" step="any" id="scf-' + esc(f.key) + '" class="form-input" value="' + esc(cv) + '" style="width:220px">');
                    }
                }
                bodyHtml = fh
                    + fieldRow('Effective from', '<input type="date" id="sc-eff" class="form-input" value="' + esc(ui.eff) + '" style="width:200px" onchange="scSet(&#39;eff&#39;,this.value)">')
                    + fieldRow('Note (optional)', '<input type="text" id="sc-note" class="form-input" style="width:100%" placeholder="Why this change">')
                    + '<div style="margin-top:6px">'
                    + '<button class="btn btn-secondary" onclick="scPreview()"><i class="fas fa-eye"></i> Preview impact</button> '
                    + '<button class="btn btn-primary" onclick="scSave()"><i class="fas fa-floppy-disk"></i> Save override</button>'
                    + '</div><div id="sc-preview" style="margin-top:12px"></div>';
            }
            var form = '<div class="card" style="margin-bottom:14px"><div style="padding:18px 20px;max-width:720px">'
                + '<div style="display:flex;gap:14px;flex-wrap:wrap">'
                + '<div style="flex:1;min-width:220px">' + fieldRow('Which rates', '<select id="sc-kind" class="form-input" style="width:100%" onchange="scSet(&#39;kind&#39;,this.value)">' + kindOpts + '</select>') + '</div>'
                + '<div style="flex:1;min-width:220px">' + fieldRow('Applies to', '<select id="sc-scope" class="form-input" style="width:100%" onchange="scSet(&#39;scope&#39;,this.value)">' + scopeOpts + '</select>') + '</div>'
                + '</div>' + scopeInput
                + '<hr style="border:none;border-top:1px solid var(--border);margin:6px 0 14px">'
                + bodyHtml + '</div></div>';
            return pghead('Statutory Configuration', 'Effective-dated PF / ESI / PT / TDS overrides by scope', '')
                + scIntro() + form + scHistory(d);
        }
