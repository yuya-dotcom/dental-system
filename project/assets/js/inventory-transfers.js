/* =============================================================
   EssenciaSmile Admin — Stock Transfers JS
   File: assets/js/inventory-transfers.js  (inventory-transfers.php)
   APIs: api/inventory_transfers.php, api/inventory_data.php
   ============================================================= */
(function () {
    const $ = id => document.getElementById(id);
    const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
    const num = n => Number(n || 0).toLocaleString();
    const when = s => s ? new Date(s).toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '';
    const modal = id => bootstrap.Modal.getOrCreateInstance($(id));
    const UI = window.InvUI;
    const U = (u, n) => UI.unit(u, n);   // display unit: pcs → pieces
    const newKey = () => (crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2)).replace(/[^A-Za-z0-9_-]/g, '');
    const LABEL = { pending: 'Pending Approval', approved: 'Approved', in_transit: 'In Transit', received: 'Received', rejected: 'Rejected', cancelled: 'Cancelled' };
    const after = () => (window.esRefresh ? window.esRefresh() : location.reload());
    const api = body => fetch('api/inventory_transfers.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
        .then(r => r.json().catch(() => ({ success: false, message: 'Unexpected server response.' })));

    // ── Details ───────────────────────────────────────────────
    let _t = null;
    window.viewTransfer = function (id) {
        $('tvLoading').hidden = false; $('tvBody').hidden = true; $('tvNo').textContent = 'Transfer'; $('tvRoute').textContent = '—';
        resetFoot();
        modal('trfViewModal').show();
        fetch('api/inventory_transfers.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' }).then(r => r.json()).then(d => {
            if (!d.success) { $('tvLoading').textContent = d.message || 'Could not load the transfer.'; return; }
            _t = d; render(d);
        }).catch(() => { $('tvLoading').textContent = 'Could not connect to the server.'; });
    };
    function resetFoot() { $('tvFoot').innerHTML = '<span class="rec-foot-spacer"></span><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>'; }

    function render(d) {
        const t = d.transfer;
        $('tvNo').innerHTML = esc(t.transfer_no) + ` <span class="inv-flow f-${esc(t.status)} ms-2 align-middle">${esc(LABEL[t.status] || t.status)}</span>`;
        $('tvRoute').textContent = t.from_name + ' → ' + t.to_name;
        const showStock = d.lines.some(l => l.source_stock != null);
        $('tvStockTh').hidden = !showStock;
        $('tvLines').innerHTML = d.lines.map(l => {
            const short = l.source_stock != null && l.quantity > l.source_stock - 1;
            const sent = (l.sent_detail || []).filter(b => b.lot_number || b.expiry_date).map(b => `${esc(b.lot_number || 'Batch')} (${num(b.quantity)})`).join(', ');
            return `<tr><td>${esc(l.item_name)}</td><td class="text-end fw-semibold">${num(l.quantity)} ${esc(U(l.unit, l.quantity))}</td>
                <td class="small">${esc(l.batch || '') || sent || '<span class="text-muted">Automatic</span>'}</td>
                ${showStock ? `<td class="text-end ${short ? 'text-danger fw-semibold' : ''}">${l.source_stock == null ? '—' : num(l.source_stock)}${short ? ' <i class="feather-alert-triangle" title="Not enough stock to send (1 unit must stay)"></i>' : ''}</td>` : ''}</tr>`;
        }).join('');
        $('tvReason').textContent = t.reason;
        $('tvNotes').textContent = t.notes || '';
        const steps = [['Requested', t.requested_by_name, t.requested_at, true]];
        if (t.approved_at) steps.push(['Approved', t.approved_by_name, t.approved_at, true]);
        if (t.dispatched_at) steps.push(['Sent — stock left ' + t.from_name, t.dispatched_by_name, t.dispatched_at, true]);
        if (t.received_at) steps.push(['Received — stock added to ' + t.to_name, t.received_by_name, t.received_at, true]);
        if (t.rejected_at) steps.push(['Rejected' + (t.rejection_reason ? ': ' + t.rejection_reason : ''), t.rejected_by_name, t.rejected_at, false, true]);
        if (t.cancelled_at) steps.push(['Cancelled', t.cancelled_by_name, t.cancelled_at, false, true]);
        const next = { pending: 'Waiting for the owner to approve', approved: 'Waiting for ' + t.from_name + ' to send the stock', in_transit: 'Waiting for ' + t.to_name + ' to receive it' }[t.status];
        $('tvSteps').innerHTML = steps.map(s => `<li class="${s[4] ? 'bad' : 'done'}">${esc(s[0])}<span class="inv-step-when">${esc(s[1] || '')} · ${esc(when(s[2]))}</span></li>`).join('')
            + (next ? `<li>${esc(next)}</li>` : '');
        const tip = { send: 'Sending deducts the stock from ' + t.from_name + ' now.', receive: 'Receiving adds the stock to ' + t.to_name + '. Check the items first.' };
        $('tvAlert').innerHTML = d.can.send ? `<div class="inv-alert inv-alert-info"><i class="feather-info"></i><span>${esc(tip.send)}</span></div>`
            : d.can.receive ? `<div class="inv-alert inv-alert-info"><i class="feather-info"></i><span>${esc(tip.receive)}</span></div>` : '';

        // Footer: primary action LEFT, Close RIGHT
        const b = [];
        if (d.can.approve) b.push(`<button type="button" class="btn btn-primary" data-act="approve"><i class="feather-check me-1"></i> Approve</button>`);
        if (d.can.send)    b.push(`<button type="button" class="btn btn-primary" data-act="send"><i class="feather-send me-1"></i> Mark as Sent</button>`);
        if (d.can.receive) b.push(`<button type="button" class="btn btn-primary" data-act="receive"><i class="feather-download me-1"></i> Mark as Received</button>`);
        if (d.can.reject)  b.push(`<button type="button" class="btn inv-btn-danger" data-act="reject"><i class="feather-x me-1"></i> Reject</button>`);
        if (d.can.cancel)  b.push(`<button type="button" class="btn inv-btn-danger" data-act="cancel"><i class="feather-slash me-1"></i> Cancel Transfer</button>`);
        $('tvFoot').innerHTML = b.join('') + '<span class="rec-foot-spacer"></span><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>';
        $('tvLoading').hidden = true; $('tvBody').hidden = false;
    }

    // Workflow dialogs: each says exactly what will happen to the stock
    const ACT = {
        approve: t => ({ tone: 'success', glyph: 'feather-check-circle', title: 'Approve this transfer?', confirmText: 'Approve',
            text: `<strong>${esc(t.from_name)}</strong> can then send these items to <strong>${esc(t.to_name)}</strong>.` }),
        send: t => ({ tone: 'warn', glyph: 'feather-send', title: 'Mark as sent?', confirmText: 'Mark as Sent',
            text: `These items are deducted from <strong>${esc(t.from_name)}</strong> now.`, note: 'Only confirm once the items have physically left the branch.' }),
        receive: t => ({ tone: 'success', glyph: 'feather-download', title: 'Mark as received?', confirmText: 'Mark as Received',
            text: `These items are added to <strong>${esc(t.to_name)}</strong>'s stock.`, note: 'Check the items and quantities before confirming.' }),
        reject: t => ({ tone: 'danger', glyph: 'feather-x-circle', title: 'Reject this transfer?', confirmText: 'Reject Transfer',
            text: 'Nothing is moved. The branch sees your reason.', reason: { label: 'Reason for rejecting', placeholder: 'e.g. Bulacan needs these for next week' } }),
        cancel: t => ({ tone: 'danger', glyph: 'feather-slash', title: 'Cancel this transfer?', confirmText: 'Cancel Transfer', cancelText: 'Keep Transfer',
            text: 'Nothing has been moved yet. The transfer will be closed.' }),
    };
    document.addEventListener('click', e => {
        const btn = e.target.closest('#tvFoot [data-act]'); if (!btn || !_t) return;
        const act = btn.dataset.act, t = _t.transfer;
        const cfg = ACT[act](t);
        cfg.chip = t.transfer_no; cfg.chipIcon = 'feather-truck';
        if (act !== 'reject' && act !== 'cancel') cfg.list = _t.lines.map(l => `<i class="feather-package"></i><span class="inv-swal-li">${esc(l.item_name)}</span><strong>${num(l.quantity)} ${esc(U(l.unit, l.quantity))}</strong>`);
        cfg.preConfirm = v => api({ action: act, transfer_id: t.transfer_id, reason: v });
        UI.confirm(cfg).then(r => {
            if (!r.isConfirmed || !r.value) return;
            modal('trfViewModal').hide();
            UI.ok(r.value.message, { approve: 'Transfer Approved', send: 'Marked as Sent', receive: 'Transfer Received', reject: 'Transfer Rejected', cancel: 'Transfer Cancelled' }[act]);
            after();
        });
    });

    // ── New transfer ──────────────────────────────────────────
    let _items = [], _key = '';
    window.openTransferModal = function () {
        UI.resetForm($('trfForm')); $('trfError').style.display = 'none'; _key = newKey(); _items = [];
        if (!TRF.isOwner && TRF.myBranch) $('trf_from').value = String(TRF.myBranch);
        $('trfLines').innerHTML = '';
        trfLoadItems().then(() => trfAddLine());
        modal('trfModal').show();
    };
    window.trfLoadItems = function () {
        const from = $('trf_from').value;
        if (!from) { _items = []; refreshSelects(); return Promise.resolve(); }
        return fetch('api/inventory_data.php?action=branch_items&branch_id=' + encodeURIComponent(from), { credentials: 'same-origin' })
            .then(r => r.json()).then(d => { _items = d.success ? d.items : []; refreshSelects(); });
    };
    function itemOptions(sel) {
        return `<option value="">${$('trf_from').value ? 'Select item' : 'Choose the sending branch first'}</option>` + _items.map(i =>
            `<option value="${i.item_id}"${String(sel) === String(i.item_id) ? ' selected' : ''}>${esc(i.item_name)}${i.usable != null ? ` — can send ${num(i.usable)} ${esc(U(i.unit, i.usable))}` : ''}</option>`).join('');
    }
    function refreshSelects() { document.querySelectorAll('#trfLines [data-f="item"]').forEach(s => { s.innerHTML = itemOptions(s.value); }); }
    window.trfAddLine = function () {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td class="inv-line-item"><select class="form-select form-select-sm js-custom-select" data-cs-float data-cs-search data-cs-search-placeholder="Search items…" data-f="item" aria-label="Item">${itemOptions('')}</select></td>
            <td class="inv-line-qty"><input type="number" class="form-control form-control-sm" min="1" step="1" placeholder="Qty" data-f="qty" aria-label="Quantity"></td>
            <td class="inv-line-act"><button type="button" class="inv-line-del" title="Remove this item" aria-label="Remove line"><i class="feather-trash-2"></i></button></td>`;
        tr.querySelector('.inv-line-del').onclick = () => { tr.remove(); if (!$('trfLines').children.length) trfAddLine(); };
        $('trfLines').appendChild(tr);
        UI.enhance();
    };
    window.saveTransfer = function () {
        const err = m => { $('trfError').textContent = m; $('trfError').style.display = 'block'; };
        $('trfError').style.display = 'none';
        const from = +$('trf_from').value, to = +$('trf_to').value, reason = $('trf_reason').value.trim();
        if (!from || !to) return err('Choose both branches.');
        if (from === to) return err('Source and destination must be different branches.');
        if (!TRF.isOwner && from !== TRF.myBranch && to !== TRF.myBranch) return err('One of the branches must be your own.');
        const lines = [];
        for (const tr of $('trfLines').children) {
            // Named fields: the searchable item dropdown adds its own <input> (search box) to the row
            const qIn = tr.querySelector('[data-f="qty"]');
            const id = tr.querySelector('[data-f="item"]').value, q = parseInt(qIn.value, 10);
            if (!id && !qIn.value) continue;
            if (!id) return err('Choose an item on every line (or remove the empty line).');
            if (!(q > 0)) return err('Every item needs a quantity greater than 0.');
            const it = _items.find(i => String(i.item_id) === id);
            if (it && it.usable != null && q > it.usable) return err(`"${it.item_name}": only ${it.usable} can be sent (1 unit must stay).`);
            if (lines.some(l => l.item_id === id)) return err('The same item is listed twice. Combine the quantities.');
            lines.push({ item_id: id, quantity: q });
        }
        if (!lines.length) return err('Add at least one item.');
        if (!reason) return err('Please give a reason for the transfer.');
        const btn = $('trfSaveBtn');
        UI.busy(btn, true);
        api({ action: 'create', from_branch_id: from, to_branch_id: to, reason, notes: $('trf_notes').value.trim(), lines, request_key: _key })
            .then(d => {
                UI.busy(btn, false);
                if (!d.success) return err(d.message || 'Could not save.');
                modal('trfModal').hide();
                UI.ok(d.message, TRF.isOwner ? 'Transfer Created' : 'Transfer Requested');
                after();
            }).catch(() => { UI.busy(btn, false); err('Could not connect to the server.'); });
    };
})();
