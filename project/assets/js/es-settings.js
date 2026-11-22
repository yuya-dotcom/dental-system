// =============================================================
//  assets/js/es-settings.js — Settings page (settings.php)
//
//  • Pages: one section at a time; the menu switches them and the URL
//    hash keeps the page on reload / Back (settings.php#security).
//  • Owner: live backup / connection status (api/settings_api.php?action=status)
//    and the two downloads (api/settings_backup.php). The server makes and
//    checks the file; this script only asks for it and saves what comes
//    back. A JSON answer is always an error — never saved as a backup.
//    Complete backup = 2 steps: (1) what's inside + keep-it-private
//    confirmation, (2) password + file protection.
//  • Everyone: change own password (api/settings_api.php, change_password).
//  Dialogs: primary action on the LEFT, Cancel on the RIGHT.
// =============================================================
(function () {
  'use strict';

  var page = document.getElementById('setPage');
  if (!page) return;
  var isOwner = page.dataset.owner === '1';
  var CFG = window.ES_SETTINGS || { zipAes: false, zipMin: 8 };
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  var SW = function (extra) { return Object.assign({}, window.ES_SWAL_CLASS || {}, extra || {}); };

  // ── Pages: one section at a time ───────────────────────────
  var links = Array.prototype.slice.call(document.querySelectorAll('[data-set-nav]'));
  var sections = Array.prototype.slice.call(document.querySelectorAll('.set-section'));
  var ids = sections.map(function (s) { return s.id; });
  function showPage(id, focus) {
    if (ids.indexOf(id) === -1) id = ids[0];
    sections.forEach(function (s) { s.hidden = s.id !== id; });
    links.forEach(function (a) {
      var on = a.dataset.setNav === id;
      a.classList.toggle('is-active', on);
      if (on) {
        a.setAttribute('aria-current', 'page');
        // keep the active chip visible in the phone's scrolling bar
        // (sideways only: scrollIntoView would also scroll the page)
        var bar = a.parentNode;
        if (bar.scrollWidth > bar.clientWidth) {
          var left = a.offsetLeft - bar.offsetLeft, right = left + a.offsetWidth;
          if (left < bar.scrollLeft) bar.scrollLeft = left - 8;
          else if (right > bar.scrollLeft + bar.clientWidth) bar.scrollLeft = right - bar.clientWidth + 8;
        }
      } else a.removeAttribute('aria-current');
    });
    page.classList.add('is-paged');
    if (focus) {
      window.scrollTo({ top: 0, behavior: 'auto' });
      var h = document.getElementById(id).querySelector('h2');
      if (h) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); }
    }
  }
  links.forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      if (location.hash.slice(1) !== a.dataset.setNav) {
        try { history.pushState(null, '', '#' + a.dataset.setNav); } catch (err) { /* ignore */ }
      }
      showPage(a.dataset.setNav, true);
    });
  });
  window.addEventListener('popstate', function () { showPage(location.hash.slice(1), true); });
  window.addEventListener('hashchange', function () { showPage(location.hash.slice(1), true); });
  showPage(location.hash.slice(1), false);

  // ── Change password (form opens from its button) ───────────
  var pwForm = $('pwForm'), pwOpen = $('pwOpen'), pwPanel = $('pwPanel'), pwDone = $('pwDone');
  function pwSetOpen(open) {
    if (!pwPanel) return;
    pwPanel.hidden = !open;
    pwOpen.hidden = open;                       // Cancel / Save close it again
    pwOpen.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      pwDone.hidden = true;
      pwPanel.classList.remove('is-opening'); void pwPanel.offsetWidth; pwPanel.classList.add('is-opening');
      pwForm.elements.current_password.focus();
    } else {
      pwForm.reset();
      $('pwMsg').hidden = true;
      ['current_password', 'new_password', 'confirm_password'].forEach(function (n) { pwForm.elements[n].classList.remove('is-invalid'); });
    }
  }
  if (pwOpen) {
    pwOpen.addEventListener('click', function () { pwSetOpen(true); });
    $('pwCancel').addEventListener('click', function () { pwSetOpen(false); pwOpen.focus(); });
  }
  if (pwForm) {
    pwForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      var msg = $('pwMsg'), btn = $('pwSave');
      var f = pwForm.elements;
      ['current_password', 'new_password', 'confirm_password'].forEach(function (n) { f[n].classList.remove('is-invalid'); });
      var show = function (text, ok, field) {
        msg.hidden = false; msg.textContent = text;
        msg.className = 'set-form-msg ' + (ok ? 'is-ok' : 'is-bad');
        if (field && f[field]) { f[field].classList.add('is-invalid'); f[field].focus(); }
      };
      if (!f.current_password.value) return show('Please enter your current password.', false, 'current_password');
      if (f.new_password.value.length < 8) return show('Your new password needs at least 8 characters.', false, 'new_password');
      if (f.new_password.value !== f.confirm_password.value) return show('The two new passwords are not the same. Please type them again.', false, 'confirm_password');

      btn.disabled = true;
      var label = btn.innerHTML;
      btn.innerHTML = '<span class="es-spin"></span><span>Saving…</span>';
      try {
        var res = await fetch('api/settings_api.php', {
          method: 'POST', credentials: 'same-origin', cache: 'no-store',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'change_password',
            current_password: f.current_password.value,
            new_password: f.new_password.value,
            confirm_password: f.confirm_password.value,
          }),
        });
        if (res.status === 401) { location.href = 'login.php'; return; }
        var j = await res.json().catch(function () { return {}; });
        if (j.success) {
          pwSetOpen(false);                     // close the form, keep the confirmation visible
          pwDone.hidden = false;
          pwDone.textContent = j.message || 'Your password was changed.';
          pwOpen.focus();
        } else {
          show(j.message || "Your password couldn't be changed.", false, j.field);
        }
      } catch (err) {
        show("We couldn't reach the server. Please check your internet connection and try again.", false);
      } finally {
        btn.disabled = false;
        btn.innerHTML = label;
      }
    });
  }

  if (!isOwner) return;

  // ── Owner: live status ─────────────────────────────────────
  var btnBackup = $('btnBackup'), btnSchema = $('btnSchema');
  var pill = function (text, tone) { return '<span class="set-pill is-' + tone + '">' + esc(text) + '</span>'; };
  var num = function (n) { return Number(n || 0).toLocaleString(); };

  var recheckLabel = $('btnRecheck') ? $('btnRecheck').innerHTML : '';
  async function loadStatus() {
    var recheck = $('btnRecheck');
    var started = Date.now();
    if (recheck) {
      // Spinner on the button + on the connection row while the check runs
      recheck.disabled = true;
      recheck.setAttribute('aria-busy', 'true');
      recheck.innerHTML = '<span class="es-spin"></span><span>Checking…</span>';
      $('sysChecked').textContent = '';
      $('sysConn').innerHTML = '<span class="set-checking"><span class="es-spin"></span>Checking connection…</span>';
    }
    try {
      var res = await fetch('api/settings_api.php?action=status', { credentials: 'same-origin', cache: 'no-store' });
      // keep the spinner visible long enough to be noticed on a fast connection
      var left = 600 - (Date.now() - started);
      if (left > 0) await new Promise(function (r) { setTimeout(r, left); });
      if (res.status === 401) { location.href = 'login.php'; return; }
      var j = await res.json();
      if (!j.success) throw new Error(j.message || 'status');
      renderStatus(j);
    } catch (err) {
      $('stConn').innerHTML = pill('Unknown', 'warn');
      $('stConnSub').textContent = "Couldn't check right now";
      $('sysConn').innerHTML = pill('Unknown', 'warn');
      ['stLast', 'stState', 'stSize'].forEach(function (id) { $(id).textContent = '—'; });
      if (recheck) $('sysChecked').textContent = "Couldn't check — try again in a moment";
    } finally {
      if (recheck) {
        recheck.disabled = false;
        recheck.removeAttribute('aria-busy');
        recheck.innerHTML = recheckLabel;
      }
    }
  }

  function renderStatus(j) {
    var db = j.database, bk = j.backup;

    // Connection
    if (db.connected) {
      $('stConn').innerHTML = pill('Connected', 'ok');
      $('stConnSub').textContent = db.ms > 3000 ? 'Working, but slow right now' : 'Working normally';
      $('sysConn').innerHTML = pill('Connected', 'ok');
    } else {
      $('stConn').innerHTML = pill('Not reachable', 'bad');
      $('stConnSub').textContent = 'Check your internet connection';
      $('sysConn').innerHTML = pill('Not reachable', 'bad');
    }
    $('sysDbVer').textContent = db.server_version ? ' · ' + db.server_version.replace('PostgreSQL ', 'PostgreSQL ') : '';
    $('sysChecked').textContent = 'Last checked at ' + j.checked_at;

    // Size
    $('stSize').textContent = db.size || '—';
    $('stSizeSub').textContent = db.tables != null ? 'About ' + num(db.rows_estimate) + ' records' : (db.installed ? '' : 'Shown after setup');
    $('stTables').textContent = db.tables != null ? db.tables : '—';

    // Setup notice + buttons
    $('setSetup').hidden = !(db.connected && !db.installed);
    var ready = db.connected && db.installed;
    btnBackup.disabled = !ready;
    btnSchema.disabled = !ready;
    btnBackup.title = ready ? '' : (db.connected ? 'Backups need a one-time setup first' : 'The system is not reachable right now');
    btnSchema.title = btnBackup.title;

    // Last backup + status
    var last = bk.last;
    if (last) {
      // "Nov 22, 2026 · 5:07 PM" → date as the value, time + who underneath (no awkward wrap)
      var parts = String(last.at_label).split(' · ');
      $('stLast').textContent = parts[0];
      $('stLastBy').textContent = (parts[1] ? parts[1] + ' · ' : '') + 'by ' + last.by + (last.encrypted ? ' · locked' : '');
      $('stHash').textContent = last.sha256 ? 'SHA-256 ' + last.sha256 : '—';
    } else {
      $('stLast').textContent = 'Never';
      $('stLastBy').textContent = 'No backup downloaded yet';
      $('stHash').textContent = '—';
    }
    var tone = { ok: 'ok', due: 'warn', never: 'bad', unknown: 'warn' }[bk.state] || 'warn';
    $('stState').innerHTML = pill(bk.label, tone);
    $('stStateSub').textContent = bk.state === 'ok' ? (bk.days === 0 ? 'Backed up today' : 'Backed up ' + bk.days + ' day' + (bk.days === 1 ? '' : 's') + ' ago')
      : bk.state === 'due' ? 'Last one was ' + bk.days + ' days ago'
      : bk.state === 'never' ? 'We recommend one every week' : '';
    $('stLastSchema').textContent = bk.last_schema ? bk.last_schema.at_label : 'never';
  }

  $('btnRecheck') && $('btnRecheck').addEventListener('click', loadStatus);
  loadStatus();

  // ── Download helper: the answer is a file, or JSON = error ──
  async function requestFile(payload) {
    var res;
    try {
      res = await fetch('api/settings_backup.php', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
    } catch (err) {
      return { ok: false, message: "We couldn't reach the server. Please check your internet connection and try again." };
    }
    if (res.status === 401) { location.href = 'login.php'; return { ok: false, message: 'Please sign in again.' }; }
    var ct = (res.headers.get('Content-Type') || '').toLowerCase();
    if (ct.indexOf('application/json') !== -1 || !res.ok) {
      var j = await res.json().catch(function () { return {}; });
      return { ok: false, message: j.message || 'The file could not be created. Nothing was downloaded.', field: j.field, code: j.code };
    }
    var blob = await res.blob();
    var m = /filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') || '');
    var name = m ? m[1] : 'essenciasmile-export.sql';
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = name; a.rel = 'noopener';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
    return {
      ok: true, name: name, size: blob.size,
      sha: res.headers.get('X-Backup-Sha256') || '',
      tables: res.headers.get('X-Backup-Tables'),
      rows: res.headers.get('X-Backup-Rows'),
    };
  }

  function sizeLabel(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
    return (b / 1048576).toFixed(1) + ' MB';
  }

  function showResult(title, intro, r, note) {
    var row = function (k, v) { return '<div><dt>' + k + '</dt><dd>' + v + '</dd></div>'; };
    return Swal.fire({
      icon: 'success',
      iconHtml: '<i class="feather-check es-swal-glyph"></i>',
      title: title,
      html: '<div class="set-result"><p class="es-swal-text">' + intro + '</p><dl class="set-dl">' +
        row('File name', '<code>' + esc(r.name) + '</code>') +
        row('Size', esc(sizeLabel(r.size))) +
        (r.rows != null ? row('Records', esc(num(r.rows))) : row('Tables', esc(r.tables))) +
        '</dl>' + (note ? '<div class="set-dlg-warn"><i class="feather-lock"></i><span>' + note + '</span></div>' : '') + '</div>',
      confirmButtonText: 'Done',
      buttonsStyling: false,
      customClass: SW(),
    });
  }

  function busy(btn, on, text) {
    if (!btn._label) btn._label = btn.innerHTML;
    btn.disabled = on;
    btn.innerHTML = on ? '<span class="es-spin"></span><span>' + esc(text) + '</span>' : btn._label;
  }

  // ── Database structure (no patient records) ────────────────
  btnSchema.addEventListener('click', async function () {
    busy(btnSchema, true, 'Preparing file…');
    var r = await requestFile({ kind: 'schema' });
    busy(btnSchema, false);
    if (!r.ok) {
      Swal.fire({ icon: 'error', title: "The download didn't finish", text: r.message });   // es-swal.js gives it the system look
      return;
    }
    await showResult('Structure downloaded', 'The file is in your <strong>Downloads</strong> folder. It contains no patient records.', r, '');
    loadStatus();
  });

  // ── Complete data backup: step 1 — what it is, keep it private ──
  btnBackup.addEventListener('click', async function () {
    var step1 = await Swal.fire({
      icon: 'warning',
      iconHtml: '<i class="feather-shield es-swal-glyph"></i>',
      title: 'Download a complete backup?',
      html: '<div class="set-dlg">' +
        '<p class="es-swal-text">This file is a full copy of your clinic\'s information.</p>' +
        '<div class="set-dlg-box"><span class="set-block-label">It includes</span><ul class="set-checks">' +
          '<li><i class="feather-check"></i>Patient names, contact details and medical records</li>' +
          '<li><i class="feather-check"></i>Appointments, treatments, billing and payments</li>' +
          '<li><i class="feather-check"></i>Staff accounts, inventory and activity history</li>' +
        '</ul></div>' +
        '<div class="set-dlg-warn"><i class="feather-alert-triangle"></i><span><strong>Keep it private.</strong> Anyone who has this file can see your patients\' information. Store it in a safe place and never send it by email or chat.</span></div>' +
        '<label class="set-dlg-ack"><input type="checkbox" id="setAck"><span>I understand this file is confidential and I will keep it secure.</span></label>' +
        '</div>',
      showCancelButton: true,
      confirmButtonText: 'Continue',
      cancelButtonText: 'Cancel',
      reverseButtons: false,                // primary left, Cancel right
      focusConfirm: false,
      buttonsStyling: false,
      customClass: SW({ validationMessage: 'es-swal-validation' }),
      preConfirm: function () {
        if (!$('setAck').checked) { Swal.showValidationMessage('Please tick the box to confirm you will keep the file secure.'); return false; }
        return true;
      },
    });
    if (!step1.isConfirmed) return;
    askPasswordAndDownload();
  });

  // ── step 2 — password + file protection, then download ──
  async function askPasswordAndDownload() {
    var zipBlock = CFG.zipAes
      ? '<div class="set-dlg-opts">' +
          '<label class="set-dlg-opt"><input type="radio" name="setFmt" value="zip" checked>' +
            '<span><strong>Lock the file with a password <span class="set-pill is-ok">Recommended</span></strong>' +
            '<small>The backup is saved as a locked ZIP file. To open it later, use a free program such as 7-Zip and enter this password.</small></span></label>' +
          '<label class="set-dlg-opt"><input type="radio" name="setFmt" value="sql">' +
            '<span><strong>Save without a password</strong><small>Anyone who gets the file can read it. Only choose this if your IT support asked for it.</small></span></label>' +
        '</div>' +
        '<div class="set-dlg-zip" id="setZipBox">' +
          '<label class="set-field"><span class="set-label">Password for the file</span><input type="password" class="form-control" id="setZipPw" autocomplete="new-password" maxlength="128" placeholder="At least ' + CFG.zipMin + ' characters"></label>' +
          '<label class="set-field"><span class="set-label">Type it again</span><input type="password" class="form-control" id="setZipPw2" autocomplete="new-password" maxlength="128"></label>' +
          '<p class="set-dlg-hint"><strong>Write this password down and keep it safe.</strong> Without it the backup cannot be opened, and it cannot be recovered.</p>' +
        '</div>'
      : '<p class="set-dlg-hint">This server can\'t lock files with a password, so the backup will be a regular file. Store it on a password-protected drive.</p>';

    var r = await Swal.fire({
      icon: 'question',
      iconHtml: '<i class="feather-lock es-swal-glyph"></i>',
      title: 'Confirm it\'s you',
      html: '<div class="set-dlg">' +
        '<label class="set-field"><span class="set-label">Your account password</span><input type="password" class="form-control" id="setOwnPw" autocomplete="current-password" maxlength="200"><span class="set-help">The same password you use to sign in.</span></label>' +
        zipBlock +
        '<div class="set-dlg-err" id="setDlgErr" role="alert" hidden></div>' +
        '</div>',
      showCancelButton: true,
      confirmButtonText: '<i class="feather-download"></i> Download Backup',
      cancelButtonText: 'Cancel',
      reverseButtons: false,                // primary left, Cancel right
      focusConfirm: false,
      showLoaderOnConfirm: true,
      allowOutsideClick: function () { return !Swal.isLoading(); },
      allowEscapeKey: function () { return !Swal.isLoading(); },
      buttonsStyling: false,
      customClass: SW({ popup: 'es-swal-popup es-swal-popup-pw', actions: 'es-swal-actions es-swal-actions-compact' }),
      didOpen: function () {
        $('setOwnPw').focus();
        document.querySelectorAll('input[name="setFmt"]').forEach(function (el) {
          el.addEventListener('change', function () { $('setZipBox').hidden = !(el.value === 'zip' && el.checked); });
        });
      },
      preConfirm: async function () {
        var err = $('setDlgErr');
        var fail = function (text, focusId) {
          err.hidden = false; err.textContent = text;
          if (focusId && $(focusId)) $(focusId).focus();
          return false;
        };
        err.hidden = true;
        var pw = $('setOwnPw').value;
        var fmt = (document.querySelector('input[name="setFmt"]:checked') || { value: 'sql' }).value;
        var zipPw = fmt === 'zip' ? $('setZipPw').value : '';
        if (!pw) return fail('Please enter your account password.', 'setOwnPw');
        if (fmt === 'zip') {
          if (zipPw.length < CFG.zipMin) return fail('The file password needs at least ' + CFG.zipMin + ' characters.', 'setZipPw');
          if (zipPw !== $('setZipPw2').value) return fail('The two file passwords are not the same.', 'setZipPw2');
          if (zipPw === pw) return fail('Please use a file password that is different from your account password.', 'setZipPw');
        }
        Swal.getConfirmButton().innerHTML = '<span class="es-spin"></span> Preparing backup… this may take a minute';
        var res = await requestFile({ kind: 'full', password: pw, protect: fmt === 'zip', zip_password: zipPw });
        Swal.getConfirmButton().innerHTML = '<i class="feather-download"></i> Download Backup';
        if (!res.ok) {
          if (res.field === 'password') $('setOwnPw').value = '';
          return fail(res.message, res.field === 'password' ? 'setOwnPw' : (res.field === 'zip_password' ? 'setZipPw' : null));
        }
        res.encrypted = fmt === 'zip';
        return res;
      },
    });
    if (!r.isConfirmed || !r.value) return;
    await showResult('Backup downloaded',
      'Your complete backup is in your <strong>Downloads</strong> folder. It was checked and is complete.',
      r.value,
      r.value.encrypted
        ? 'The file is locked with the password you chose. Move it to a safe place and delete any extra copies.'
        : '<strong>This file is not locked.</strong> Move it to a safe, private place now and delete any extra copies.');
    loadStatus();
  }
})();
