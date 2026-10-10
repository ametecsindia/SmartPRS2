/*
 * SmartPRS 2 — Raise a Complaint: evidence + POSH Internal Committee (30 Sep 2026, Ejaz)
 *
 * Hooked into the generic master screen for type 'posh' (AppController MASTER_MAP):
 *   cmpHeadBtns(d)        header button "Internal Committee" (Admin / Super Admin)
 *   cmpAfterSave(type,id) uploads files chosen in the form's Evidence field
 *   cmpEvidence(id)       row link: list / download / add evidence
 *   cmpCommittee()        edit who sits on the POSH Internal Committee
 * The server (ComplaintController) decides who may see what; nothing here
 * widens access.
 */
(function () {
    'use strict';
    var cfg = window.__SMARTPRS || {};
    var BASE = String(cfg.masterBase || '/app/master').replace(/\/app\/master\/?$/, '') + '/app/complaints';
    var ROLES = ['Presiding Officer', 'Member', 'External Member'];

    function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function say(m) { if (typeof toast === 'function') { toast(m); } else { window.alert(m); } }
    function reload() { if (window.__MASTER) { window.__MASTER.posh = null; } if (typeof render === 'function') { render(); } }
    function getJ(u) { return fetch(u, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } }).then(function (r) { return r.json().catch(function () { return { ok: false, error: r.status === 404 ? 'Not found or not permitted' : 'HTTP ' + r.status }; }); }); }
    function post(u, body, isForm) {
        var h = { 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
        if (!isForm) { h['Content-Type'] = 'application/json'; }
        return fetch(u, { method: 'POST', credentials: 'same-origin', headers: h, body: isForm ? body : JSON.stringify(body) })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; }); })
            .then(function (j) { if (j && j.errors) { j.ok = false; j.error = Object.keys(j.errors).map(function (k) { return j.errors[k][0]; })[0]; } return j; });
    }
    var INP = 'width:100%;box-sizing:border-box;padding:8px 10px;border:1.5px solid var(--border);border-radius:9px;font-size:13.5px;background:#fff';
    function modal(html) {
        var m = document.getElementById('cmp-modal');
        if (!m) { m = document.createElement('div'); m.id = 'cmp-modal'; document.body.appendChild(m); }
        m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9000;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto';
        m.innerHTML = '<div class="card" style="max-width:640px;width:100%;padding:22px 24px;margin:auto">' + html + '</div>';
        m.onclick = function (e) { if (e.target === m) { close(); } };
    }
    function close() { var m = document.getElementById('cmp-modal'); if (m) { m.style.display = 'none'; m.innerHTML = ''; } }
    window.cmpClose = close;
    function header(t) { return '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px"><h3 style="margin:0;font-size:17px">' + t + '</h3><button class="btn btn-outline btn-sm" onclick="cmpClose()"><i class="fas fa-xmark"></i></button></div>'; }
    function kb(n) { n = Number(n || 0); return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }

    function upload(id, files) {
        var fd = new FormData();
        for (var i = 0; i < files.length; i++) { fd.append('files[]', files[i]); }
        return post(BASE + '/' + id + '/evidence', fd, true);
    }

    // ---- header button
    window.cmpHeadBtns = function (d) {
        if (!d || !d.canCommittee) { return ''; }
        return '<button class="btn btn-outline" onclick="cmpCommittee()"' + (d.hasCommittee ? '' : ' style="border-color:#dc2626;color:#dc2626" title="No Internal Committee yet — POSH complaints currently reach Admin"') + '><i class="fas fa-people-group"></i> Internal Committee' + (d.hasCommittee ? '' : ' — set up') + '</button> ';
    };

    // ---- files chosen in the Raise a Complaint form
    window.cmpAfterSave = function (type, id) {
        var el = document.getElementById('ms_evidence');
        var files = el && el.files ? Array.prototype.slice.call(el.files) : [];
        if (!files.length || !id) { return; }
        say('Uploading ' + files.length + ' evidence file(s)…');
        upload(id, files).then(function (j) { say(j && j.ok ? (j.message || 'Evidence attached') : ('Complaint saved, but evidence failed: ' + ((j && j.error) || 'upload error'))); reload(); });
    };

    // ---- evidence dialog
    window.cmpEvidence = function (id) {
        modal(header('Evidence — complaint #' + id) + '<div style="padding:24px;text-align:center;color:var(--text3)">Loading…</div>');
        getJ(BASE + '/' + id + '/evidence').then(function (j) {
            if (!j || !j.ok) { modal(header('Evidence — complaint #' + id) + '<div style="color:#991b1b">' + esc((j && j.error) || 'Could not load') + '</div>'); return; }
            var files = j.files || [];
            var list = files.length ? files.map(function (f) {
                return '<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;padding:9px 0;border-bottom:1px solid var(--border)">'
                    + '<div style="min-width:0"><div style="font-weight:600;font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><i class="fas fa-file" style="color:var(--text3);margin-right:6px"></i>' + esc(f.original_name) + '</div>'
                    + '<div style="font-size:11.5px;color:var(--text3)">' + kb(f.size) + ' · ' + esc(f.uploaded_by_name || '') + ' · ' + esc(String(f.created_at || '').slice(0, 16)) + '</div></div>'
                    + '<a class="btn btn-outline btn-sm" href="' + BASE + '/evidence/' + f.id + '"><i class="fas fa-download"></i> Download</a></div>';
            }).join('') : '<div style="padding:16px 0;color:var(--text3);font-size:13.5px">No evidence attached yet.</div>';
            var room = (j.max || 10) - files.length;
            modal(header('Evidence — complaint #' + id + (j.subject ? ' · ' + esc(j.subject) : ''))
                + '<div style="font-size:12.5px;color:var(--text3);margin-bottom:6px">Visible only to the people who can see this complaint. Files are kept private on the server.</div>'
                + list
                + (room > 0 ? '<div style="margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap"><input id="cmp_files" type="file" multiple accept="image/*,.pdf,.doc,.docx,.txt,audio/*,video/*" style="' + INP + ';flex:1"><button class="btn btn-primary" onclick="cmpUpload(' + id + ')"><i class="fas fa-upload"></i> Attach</button></div>'
                    + '<div style="font-size:11.5px;color:var(--text3);margin-top:6px">Up to ' + room + ' more file(s), 10 MB each — images, PDF, Word, text, audio or video.</div>' : '<div style="margin-top:12px;font-size:12.5px;color:#92400e">This complaint already has the maximum of ' + (j.max || 10) + ' files.</div>')
                + '<div style="display:flex;justify-content:flex-end;margin-top:16px"><button class="btn btn-outline" onclick="cmpClose()">Close</button></div>');
        });
    };
    window.cmpUpload = function (id) {
        var el = document.getElementById('cmp_files');
        var files = el && el.files ? Array.prototype.slice.call(el.files) : [];
        if (!files.length) { say('Choose one or more files first'); return; }
        upload(id, files).then(function (j) {
            say(j && j.ok ? (j.message || 'Attached') : ((j && j.error) || 'Upload failed'));
            if (j && j.ok) { reload(); window.cmpEvidence(id); }
        });
    };

    // ---- Internal Committee editor (Admin / Super Admin)
    var C = null;
    function committeeRows() {
        var users = C.users || [], cos = C.companies || [];
        return C.members.map(function (m, i) {
            return '<div style="display:grid;grid-template-columns:2fr 1.4fr 1.3fr 36px;gap:8px;margin-top:8px">'
                + '<select onchange="cmpC(' + i + ',&#39;user_id&#39;,this.value)" style="' + INP + '"><option value="">Select login…</option>' + users.map(function (u) { return '<option value="' + u.id + '"' + (String(u.id) === String(m.user_id) ? ' selected' : '') + '>' + esc(u.name) + ' — ' + esc(u.email) + '</option>'; }).join('') + '</select>'
                + '<select onchange="cmpC(' + i + ',&#39;company&#39;,this.value)" style="' + INP + '"><option value="">All companies</option>' + cos.map(function (c) { return '<option' + (c === m.company ? ' selected' : '') + '>' + esc(c) + '</option>'; }).join('') + '</select>'
                + '<select onchange="cmpC(' + i + ',&#39;role&#39;,this.value)" style="' + INP + '">' + ROLES.map(function (r) { return '<option' + (r === m.role ? ' selected' : '') + '>' + r + '</option>'; }).join('') + '</select>'
                + '<button class="btn btn-outline btn-sm" style="color:var(--red)" title="Remove" onclick="cmpCDel(' + i + ')"><i class="fas fa-xmark"></i></button></div>';
        }).join('');
    }
    function drawCommittee() {
        var ro = !C.canEdit;
        modal(header('POSH Internal Committee')
            + '<div style="font-size:12.5px;color:var(--text2);line-height:1.55;margin-bottom:10px">Only these logins see POSH complaints, their evidence and the bell alerts — Admin / HR do not. Pick a company to limit a member to that company\'s complaints. The POSH Act expects a Presiding Officer (a senior woman employee), at least two employee members and one external member.</div>'
            + (ro ? C.members.map(function (m) { return '<div style="padding:7px 0;border-bottom:1px solid var(--border);font-size:13.5px"><b>' + esc(m.name || ('#' + m.user_id)) + '</b> · ' + esc(m.role || 'Member') + ' · ' + esc(m.company || 'All companies') + '</div>'; }).join('')
                : '<div style="display:grid;grid-template-columns:2fr 1.4fr 1.3fr 36px;gap:8px;font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase"><div>Login</div><div>Company</div><div>Role</div><div></div></div>'
                + (C.members.length ? committeeRows() : '<div style="padding:14px 0;color:var(--text3);font-size:13px">No members yet.</div>')
                + '<button class="btn btn-outline btn-sm" style="margin-top:10px" onclick="cmpCAdd()"><i class="fas fa-plus"></i> Add member</button>')
            + '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px"><button class="btn btn-outline" onclick="cmpClose()">Close</button>' + (ro ? '' : '<button class="btn btn-primary" onclick="cmpCSave()"><i class="fas fa-check"></i> Save committee</button>') + '</div>');
    }
    window.cmpCommittee = function () {
        getJ(BASE + '/committee').then(function (j) {
            if (!j || !j.ok) { say((j && j.error) || 'Could not load the committee'); return; }
            C = { canEdit: j.canEdit, users: j.users || [], companies: j.companies || [], members: (j.members || []).map(function (m) { return { user_id: String(m.user_id), company: m.company_name || '', role: m.role || 'Member', name: m.name }; }) };
            drawCommittee();
        });
    };
    window.cmpC = function (i, k, v) { if (C && C.members[i]) { C.members[i][k] = v; } };
    window.cmpCAdd = function () { C.members.push({ user_id: '', company: '', role: C.members.length ? 'Member' : 'Presiding Officer' }); drawCommittee(); };
    window.cmpCDel = function (i) { C.members.splice(i, 1); drawCommittee(); };
    window.cmpCSave = function () {
        var members = C.members.filter(function (m) { return m.user_id; });
        if (C.members.length !== members.length && !window.confirm('Rows without a login will be dropped. Continue?')) { return; }
        if (!members.length && !window.confirm('Save an EMPTY committee? POSH complaints will then go to Admin again.')) { return; }
        post(BASE + '/committee', { members: members }).then(function (j) {
            say(j && j.ok ? (j.message || 'Saved') : ((j && j.error) || 'Could not save'));
            if (j && j.ok) { close(); reload(); }
        });
    };
})();
