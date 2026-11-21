/* =============================================================
   EssenciaSmile Admin — Procurement JS
   File: assets/js/inventory-procurement.js  (inventory-procurement.php)
   APIs: api/procurement.php, api/inventory_data.php (lookup)
   Every dialog uses the one #pcModal shell, switched between the form
   layout (.apt-form-dialog) and the details layout (.apt-record-dialog).
   Dialogs (system SweetAlert design): assets/js/inventory-ui.js (InvUI).
   Buttons: primary LEFT, Cancel / Close RIGHT.
   ============================================================= */
(function () {
    const $ = id => document.getElementById(id);
    const UI = window.InvUI;
    const esc = UI.esc;
    const U = (u, n) => UI.unit(u, n);   // display unit: pcs → pieces
    const num = n => Number(n || 0).toLocaleString();
    const peso = n => '₱' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const day = s => s ? new Date(String(s).length === 10 ? s + 'T00:00:00' : s).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
    const when = s => s ? new Date(s).toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '';
    const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const newKey = () => (crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2)).replace(/[^A-Za-z0-9_-]/g, '');
    const modal = () => bootstrap.Modal.getOrCreateInstance($('pcModal'));
    const after = () => (window.esRefresh ? window.esRefresh() : location.reload());
    const PR_ST = { pending: 'Pending Approval', approved: 'Approved', ordered: 'Ordered', rejected: 'Rejected', cancelled: 'Cancelled' };
    const PO_ST = { ordered: 'Ordered', partially_received: 'Partially Received', received: 'Received', cancelled: 'Cancelled' };
    const branchName = id => (PC.branches.find(b => b.id === +id) || {}).name || '—';
    const post = body => fetch('api/procurement.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
        .then(r => r.json().catch(() => ({ success: false, message: 'Unexpected server response.' })));
    const get = q => fetch('api/procurement.php?' + q, { credentials: 'same-origin' }).then(r => r.json());
    const offline = () => UI.fail('Could not connect', 'Please check your connection and try again.');

    let _lk = null;
    let _lkP = null;   // one request even when the preload and a click overlap
    const lookup = () => _lk ? Promise.resolve(_lk) : (_lkP = _lkP || fetch('api/inventory_data.php?action=lookup', { credentials: 'same-origin' })
        .then(r => r.json()).then(d => (_lk = d)).finally(() => { _lkP = null; }));
    // Warm the dropdown data while the user reads the page, so the forms open at once
    if (PC.canEdit) window.addEventListener('load', () => {
        const warm = () => lookup().catch(() => { _lk = null; });
        window.requestIdleCallback ? requestIdleCallback(warm, { timeout: 3000 }) : setTimeout(warm, 800);
    });

    // mode: 'view' (details) | 'form' | 'form-wide' (line tables with several columns)
    const DIALOG = {
        view: 'apt-record-dialog rec-detail-dialog inv-detail-dialog',
        form: 'apt-form-dialog rec-form-dialog',
        'form-wide': 'apt-form-dialog rec-form-dialog inv-form-wide',
    };
    function shell(icon, title, sub, body, foot, onSubmit, mode = 'view') {
        const dlg = $('pcModal').querySelector('.modal-dialog');
        dlg.className = 'modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down ' + DIALOG[mode];
        $('pcIcon').className = icon; $('pcTitle').innerHTML = title; $('pcSub').textContent = sub || '';
        $('pcBody').className = mode === 'view' ? 'modal-body apt-record-body p-0' : 'modal-body apt-form-body';
        $('pcBody').innerHTML = (mode === 'view' ? '' : `<div id="pcErr" class="apt-form-alert" role="alert" style="display:none;"></div>`) + body;
        $('pcFoot').className = 'modal-footer apt-record-footer' + (mode === 'view' ? '' : ' apt-form-footer');
        $('pcFoot').innerHTML = foot;
        $('pcFoot').onclick = null;
        $('pcForm').onsubmit = e => { e.preventDefault(); if (onSubmit) onSubmit(); };
        UI.enhance();
        modal().show();
    }
    const closeBtn = () => `<span class="rec-foot-spacer"></span><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>`;
    const formFoot = primary => `<span class="apt-form-hint"><span class="apt-req">*</span> Required</span>
        <div class="d-flex gap-2">${primary}<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button></div>`;
    const err = m => { const b = $('pcErr'); b.textContent = m; b.style.display = 'block'; b.scrollIntoView({ block: 'nearest' }); };
    const busy = (btn, on, label) => UI.busy(btn, on, label);
    function done(msg, title) { modal().hide(); UI.ok(msg, title); after(); }
    const loading = (icon, title) => shell(icon, title, '', '<div class="inv-loading"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>', closeBtn());
    const card = (icon, title, inner, wide) => `<section class="ar-card${wide ? ' ar-card-wide' : ''}"><div class="ar-card-header"><span class="ar-card-icon"><i class="${icon}"></i></span><h6 class="ar-card-title">${title}</h6></div>${inner}</section>`;
    const pill = (st, label) => `<span class="inv-flow f-${esc(st)}">${esc(label)}</span>`;
    const delBtn = '<button type="button" class="inv-line-del" data-f="del" title="Remove this item" aria-label="Remove line"><i class="feather-trash-2"></i></button>';

    // ── Line editor (items + qty [+ cost]) ─────────────────────
    function lineRow(withCost, l = {}) {
        const opts = '<option value="">Select item</option>' + (_lk.masters || []).map(m => `<option value="${m.master_id}"${+l.master_id === m.master_id ? ' selected' : ''}>${esc(m.item_name)} (${esc(U(m.unit))})</option>`).join('');
        return `<tr><td class="inv-line-item"><select class="form-select form-select-sm js-custom-select" data-cs-float data-cs-search data-cs-search-placeholder="Search items…" data-f="master" aria-label="Item">${opts}</select></td>
            <td class="inv-line-qty"><input type="number" class="form-control form-control-sm" min="1" placeholder="Qty" data-f="qty" value="${l.quantity || ''}" aria-label="Quantity"></td>
            ${withCost ? `<td class="inv-line-cost"><input type="number" class="form-control form-control-sm" min="0" step="0.01" placeholder="0.00" data-f="cost" value="${l.unit_cost ?? ''}" aria-label="Unit cost"></td>` : ''}
            <td class="inv-line-act">${delBtn}</td></tr>`;
    }
    function bindLines(withCost) {
        const tb = $('pcLines');
        const addRow = l => { tb.insertAdjacentHTML('beforeend', lineRow(withCost, l)); UI.enhance(); };
        tb.addEventListener('click', e => { if (e.target.closest('[data-f="del"]')) { e.target.closest('tr').remove(); if (!tb.children.length) addRow(); total(); } });
        tb.addEventListener('change', e => {
            if (withCost && e.target.dataset.f === 'master') {
                const m = (_lk.masters || []).find(x => x.master_id === +e.target.value), c = e.target.closest('tr').querySelector('[data-f="cost"]');
                if (m && c && c.value === '' && m.unit_cost != null) c.value = m.unit_cost;
            }
            total();
        });
        tb.addEventListener('input', total);
        $('pcAddLine').onclick = () => addRow();
        function total() { if (!withCost) return; let t = 0; tb.querySelectorAll('tr').forEach(tr => { t += (+tr.querySelector('[data-f="qty"]').value || 0) * (+tr.querySelector('[data-f="cost"]').value || 0); }); $('pcTotal').textContent = peso(t); }
        total();
        return addRow;
    }
    function readLines(withCost) {
        const out = [];
        for (const tr of $('pcLines').querySelectorAll('tr')) {
            const m = tr.querySelector('[data-f="master"]').value, q = tr.querySelector('[data-f="qty"]').value, c = withCost ? tr.querySelector('[data-f="cost"]').value : '';
            if (!m && !q) continue;
            if (!m) throw 'Choose an item on every line (or remove the empty line).';
            if (!(+q > 0)) throw 'Every item needs a quantity greater than 0.';
            if (withCost && (c === '' || +c < 0)) throw 'Enter the unit cost of every item (0 or more).';
            if (out.some(x => x.master_id === m)) throw 'The same item is listed twice. Combine the quantities.';
            out.push(withCost ? { master_id: m, quantity: +q, unit_cost: +c } : { master_id: m, quantity: +q });
        }
        if (!out.length) throw 'Add at least one item.';
        return out;
    }
    const lineHead = cols => `<thead><tr>${cols.map(c => `<th${c[1] ? ` class="${c[1]}"` : ''}>${c[0]}</th>`).join('')}</tr></thead>`;
    const branchSelect = (id, sel, lockOwn) => `<select id="${id}" class="form-select js-custom-select" data-cs-float${lockOwn && !PC.isOwner ? ' disabled' : ''}>${PC.isOwner ? '<option value="">Select branch</option>' : ''}`
        + PC.branches.filter(b => PC.isOwner || b.id === PC.myBranch).map(b => `<option value="${b.id}"${+sel === b.id ? ' selected' : ''}>${esc(b.name)}</option>`).join('') + '</select>';

    // ══ PURCHASE REQUEST — new ═════════════════════════════════
    window.openPrModal = function () {
        lookup().then(() => {
            const key = newKey();
            const body = `<div class="rec-form-stack">
                ${card('feather-map-pin', 'Request', `<div class="apt-form-card-body"><div class="row g-3">
                    <div class="col-md-6"><div class="apt-field mb-0"><label class="apt-label" for="pr_branch">Branch <span class="apt-req">*</span></label>${branchSelect('pr_branch', PC.myBranch, true)}</div></div>
                    <div class="col-md-6"><div class="apt-field mb-0"><label class="apt-label" for="pr_need">Needed By</label><input type="date" id="pr_need" class="form-control js-date-picker" min="${today()}"></div></div>
                    <div class="col-12"><div class="apt-field mb-0"><label class="apt-label" for="pr_reason">Reason <span class="apt-req">*</span></label><input type="text" id="pr_reason" class="form-control" maxlength="250" placeholder="e.g. Monthly restock — gloves and anesthetic running low"></div></div>
                </div></div>`)}
                ${card('feather-package', 'Items Needed', `<div class="apt-form-card-body"><table class="inv-lines">${lineHead([['Item'], ['Quantity'], ['<span class="visually-hidden">Remove</span>']])}<tbody id="pcLines">${lineRow(false)}</tbody></table>
                    <div class="inv-lines-foot"><button type="button" class="btn btn-light-brand btn-sm" id="pcAddLine"><i class="feather-plus me-1"></i> Add Item</button>
                    <button type="button" class="btn btn-light-brand btn-sm" id="pcSuggest"><i class="feather-zap me-1"></i> Add All Low-Stock Items</button></div>
                    <div class="rec-help">"Add All Low-Stock Items" fills in items at or below their minimum, using each item's reorder quantity.</div></div>`)}
                </div>`;
            shell('feather-file-plus', 'Request Supplies', PC.isOwner ? 'Created by the owner = approved right away.' : 'The owner reviews and orders it.', body,
                formFoot('<button type="submit" class="btn btn-primary px-4" id="pcSave"><i class="feather-send me-1"></i> Submit Request</button>'), save, 'form');
            const addRow = bindLines(false);
            $('pcSuggest').onclick = () => {
                const b = $('pr_branch').value; if (!b) return err('Choose a branch first.');
                const btn = $('pcSuggest'); busy(btn, true, 'Loading…');
                get('what=suggest&branch_id=' + b).then(d => {
                    busy(btn, false);
                    if (!d.success) return err(d.message || 'Could not load low-stock items.');
                    if (!d.items.length) return UI.info('No item at ' + branchName(b) + ' is at or below its minimum stock.', 'Nothing Is Low');
                    const tb = $('pcLines');
                    tb.querySelectorAll('tr').forEach(tr => { if (!tr.querySelector('[data-f="master"]').value) tr.remove(); });
                    const have = [...tb.querySelectorAll('[data-f="master"]')].map(s => +s.value);
                    const add = d.items.filter(i => !have.includes(i.master_id));
                    add.forEach(i => addRow({ master_id: i.master_id, quantity: i.suggested }));
                    if (!tb.children.length) addRow();
                    UI.ok(add.length ? `${add.length} low-stock item${add.length === 1 ? ' was' : 's were'} added to the request. Check the quantities before submitting.` : 'All low-stock items are already listed.', add.length ? 'Low-Stock Items Added' : 'Already Listed');
                }).catch(() => { busy(btn, false); err('Could not connect to the server.'); });
            };
            function save() {
                let lines; try { lines = readLines(false); } catch (m) { return err(m); }
                const branch = $('pr_branch').value, reason = $('pr_reason').value.trim();
                if (!branch) return err('Choose a branch.');
                if (!reason) return err('Please give a reason.');
                const btn = $('pcSave'); busy(btn, true);
                post({ action: 'pr_create', branch_id: branch, needed_by: $('pr_need').value, reason, lines, request_key: key })
                    .then(d => { busy(btn, false); d.success ? done(d.message, PC.isOwner ? 'Request Created' : 'Request Submitted') : err(d.message || 'Could not save.'); })
                    .catch(() => { busy(btn, false); err('Could not connect to the server.'); });
            }
        }).catch(offline);
    };

    // ══ PURCHASE REQUEST — view ═══════════════════════════════
    window.viewPr = function (id) {
        loading('feather-file-plus', 'Purchase Request');
        get('what=pr&id=' + id).then(d => {
            if (!d.success) return shell('feather-file-plus', 'Purchase Request', '', `<div class="inv-loading">${esc(d.message || 'Not found.')}</div>`, closeBtn());
            const p = d.pr;
            const steps = [['Requested by ' + p.requested_by_name, p.requested_at]];
            if (p.approved_at) steps.push(['Approved by ' + p.approved_by_name, p.approved_at]);
            if (p.rejected_at) steps.push(['Rejected by ' + p.rejected_by_name + (p.rejection_reason ? ': ' + p.rejection_reason : ''), p.rejected_at, 1]);
            if (p.cancelled_at) steps.push(['Cancelled by ' + p.cancelled_by_name, p.cancelled_at, 1]);
            if (p.status === 'ordered') steps.push(['Ordered from a supplier', p.updated_at]);
            const next = { pending: 'Waiting for the owner to approve', approved: 'Waiting for the owner to order it' }[p.status];
            const body = `<div class="ar-view-body"><div class="ar-cards-grid">
                ${card('feather-package', 'Items', `<div class="ar-card-fields p-0"><div class="table-responsive"><table class="table inv-mini-table mb-0">
                    <thead><tr><th>Item</th><th class="text-end">Requested</th><th class="text-end">In Stock at ${esc(p.branch_name)}</th></tr></thead><tbody>
                    ${d.lines.map(l => `<tr><td>${esc(l.item_name)}</td><td class="text-end fw-semibold">${num(l.quantity)} ${esc(U(l.unit, l.quantity))}</td><td class="text-end">${l.stock == null ? '—' : num(l.stock)}</td></tr>`).join('')}
                    </tbody></table></div></div>`, true)}
                ${card('feather-file-text', 'Reason', `<div class="ar-card-fields"><p class="inv-text mb-2">${esc(p.reason)}</p><div class="ar-card-field"><span class="ar-card-label">Needed by</span><span class="ar-card-value">${day(p.needed_by)}</span></div></div>`)}
                ${card('feather-clock', 'History', `<div class="ar-card-fields"><ul class="inv-steps">${steps.map(s => `<li class="${s[2] ? 'bad' : 'done'}">${esc(s[0])}<span class="inv-step-when">${esc(when(s[1]))}</span></li>`).join('')}${next ? `<li>${esc(next)}</li>` : ''}</ul></div>`)}
            </div></div>`;
            const b = [];
            if (d.can.order)   b.push('<button type="button" class="btn btn-primary" data-pr="order"><i class="feather-shopping-cart me-1"></i> Create Purchase Order</button>');
            if (d.can.approve) b.push('<button type="button" class="btn btn-primary" data-pr="pr_approve"><i class="feather-check me-1"></i> Approve</button>');
            if (d.can.reject)  b.push('<button type="button" class="btn inv-btn-danger" data-pr="pr_reject"><i class="feather-x me-1"></i> Reject</button>');
            if (d.can.cancel)  b.push('<button type="button" class="btn inv-btn-danger" data-pr="pr_cancel"><i class="feather-slash me-1"></i> Cancel Request</button>');
            shell('feather-file-plus', `${esc(p.pr_no)} ${pill(p.status, PR_ST[p.status] || p.status)}`, p.branch_name, body, b.join('') + closeBtn());
            $('pcFoot').onclick = e => {
                const a = e.target.closest('[data-pr]'); if (!a) return;
                if (a.dataset.pr === 'order') return openPoModal(d);
                const items = d.lines.map(l => `<i class="feather-package"></i><span class="inv-swal-li">${esc(l.item_name)}</span><strong>${num(l.quantity)} ${esc(U(l.unit, l.quantity))}</strong>`);
                confirmAct(a.dataset.pr, { pr_id: p.pr_id }, Object.assign({ chip: p.pr_no, chipIcon: 'feather-file-plus' }, {
                    pr_approve: { tone: 'success', glyph: 'feather-check-circle', title: 'Approve this request?', text: `Supplies for <strong>${esc(p.branch_name)}</strong>. You can then order them from a supplier.`, list: items, confirmText: 'Approve' },
                    pr_reject:  { tone: 'danger', glyph: 'feather-x-circle', title: 'Reject this request?', text: 'The branch sees your reason.', reason: { label: 'Reason for rejecting', placeholder: 'e.g. Enough stock at the moment' }, confirmText: 'Reject Request' },
                    pr_cancel:  { tone: 'danger', glyph: 'feather-slash', title: 'Cancel this request?', text: 'It will be closed without ordering.', confirmText: 'Cancel Request', cancelText: 'Keep Request' },
                }[a.dataset.pr]));
            };
        }).catch(offline);
    };

    function confirmAct(action, ids, cfg) {
        cfg.preConfirm = v => post(Object.assign({ action, reason: v }, ids));
        const title = { pr_approve: 'Request Approved', pr_reject: 'Request Rejected', pr_cancel: 'Request Cancelled', po_cancel: 'Order Cancelled', supplier_toggle: 'Supplier Deactivated' }[action];
        return UI.confirm(cfg).then(r => { if (r.isConfirmed && r.value) done(r.value.message, title); });
    }

    // ══ PURCHASE ORDER — new (optionally from an approved request) ═══
    window.openPoModal = function (fromPr) {
        lookup().then(() => {
            const key = newKey(), pr = fromPr ? fromPr.pr : null;
            const sups = (_lk.suppliers || []).filter(s => s.is_active !== false);
            const firstSup = fromPr ? (fromPr.lines.find(l => l.supplier_id) || {}).supplier_id : '';
            const body = `<div class="rec-form-stack">
                ${!sups.length ? '<div class="inv-alert inv-alert-warning mb-0"><i class="feather-alert-triangle"></i><span>Add a supplier first (Suppliers tab).</span></div>' : ''}
                ${card('feather-briefcase', 'Order Details', `<div class="apt-form-card-body"><div class="row g-3">
                    <div class="col-md-6"><div class="apt-field mb-0"><label class="apt-label" for="po_sup">Supplier <span class="apt-req">*</span></label>
                        <select id="po_sup" class="form-select js-custom-select" data-cs-float data-cs-search data-cs-search-placeholder="Search suppliers…"><option value="">Select supplier</option>${sups.map(s => `<option value="${s.supplier_id}"${+firstSup === s.supplier_id ? ' selected' : ''}>${esc(s.supplier_name)}</option>`).join('')}</select></div></div>
                    <div class="col-md-6"><div class="apt-field mb-0"><label class="apt-label" for="po_branch">Deliver To <span class="apt-req">*</span></label>${pr ? `<select id="po_branch" class="form-select js-custom-select" data-cs-float disabled><option value="${pr.branch_id}">${esc(pr.branch_name)}</option></select>` : branchSelect('po_branch', '', false)}</div></div>
                    <div class="col-md-6"><div class="apt-field mb-0"><label class="apt-label" for="po_date">Order Date</label><input type="date" id="po_date" class="form-control js-date-picker" value="${today()}"></div></div>
                    <div class="col-md-6"><div class="apt-field mb-0"><label class="apt-label" for="po_exp">Expected Delivery</label><input type="date" id="po_exp" class="form-control js-date-picker" min="${today()}"></div></div>
                    <div class="col-12"><div class="apt-field mb-0"><label class="apt-label" for="po_notes">Notes</label><input type="text" id="po_notes" class="form-control" maxlength="500" placeholder="Optional — terms, payment, contact"></div></div>
                </div></div>`)}
                ${card('feather-package', 'Items Ordered', `<div class="apt-form-card-body"><table class="inv-lines">${lineHead([['Item'], ['Quantity'], ['Unit Cost (₱)'], ['<span class="visually-hidden">Remove</span>']])}<tbody id="pcLines">${fromPr ? fromPr.lines.map(l => lineRow(true, { master_id: l.master_id, quantity: l.quantity, unit_cost: l.unit_cost })).join('') : lineRow(true)}</tbody></table>
                    <div class="inv-lines-foot"><button type="button" class="btn btn-light-brand btn-sm" id="pcAddLine"><i class="feather-plus me-1"></i> Add Item</button>
                    <span class="inv-total">Total <strong id="pcTotal">₱0.00</strong></span></div></div>`)}
            </div>`;
            shell('feather-shopping-cart', 'New Purchase Order', pr ? 'From ' + pr.pr_no : 'Stock is added when the delivery is received.', body,
                formFoot(`<button type="submit" class="btn btn-primary px-4" id="pcSave"${sups.length ? '' : ' disabled'}><i class="feather-check me-1"></i> Create Order</button>`), save, 'form-wide');
            bindLines(true);
            function save() {
                let lines; try { lines = readLines(true); } catch (m) { return err(m); }
                if (!$('po_sup').value) return err('Choose a supplier.');
                if (!$('po_branch').value) return err('Choose the branch the delivery goes to.');
                const btn = $('pcSave'); busy(btn, true);
                post({ action: 'po_create', supplier_id: $('po_sup').value, branch_id: $('po_branch').value, pr_id: pr ? pr.pr_id : null,
                       order_date: $('po_date').value, expected_date: $('po_exp').value, notes: $('po_notes').value.trim(), lines, request_key: key })
                    .then(d => { busy(btn, false); d.success ? done(d.message, 'Purchase Order Created') : err(d.message || 'Could not save.'); })
                    .catch(() => { busy(btn, false); err('Could not connect to the server.'); });
            }
        }).catch(offline);
    };

    // ══ PURCHASE ORDER — view ═════════════════════════════════
    window.viewPo = function (id) {
        loading('feather-shopping-cart', 'Purchase Order');
        get('what=po&id=' + id).then(d => {
            if (!d.success) return shell('feather-shopping-cart', 'Purchase Order', '', `<div class="inv-loading">${esc(d.message || 'Not found.')}</div>`, closeBtn());
            const p = d.po, s = p.suppliers || {};
            const body = `<div class="ar-view-body"><div class="ar-cards-grid">
                ${card('feather-package', 'Items', `<div class="ar-card-fields p-0"><div class="table-responsive"><table class="table inv-mini-table mb-0">
                    <thead><tr><th>Item</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Unit Cost</th><th class="text-end">Subtotal</th></tr></thead><tbody>
                    ${d.lines.map(l => `<tr><td>${esc(l.item_name)}${l.track_expiry ? ' <i class="feather-clock text-muted" title="Expiry tracked"></i>' : ''}</td><td class="text-end">${num(l.quantity_ordered)} ${esc(U(l.unit, l.quantity_ordered))}</td>
                        <td class="text-end ${l.quantity_received >= l.quantity_ordered ? 'text-success fw-semibold' : ''}">${num(l.quantity_received)}</td><td class="text-end">${peso(l.unit_cost)}</td><td class="text-end">${peso(l.quantity_ordered * l.unit_cost)}</td></tr>`).join('')}
                    </tbody><tfoot><tr><td colspan="4" class="text-end">Total</td><td class="text-end">${peso(p.total)}</td></tr></tfoot></table></div></div>`, true)}
                ${card('feather-briefcase', 'Supplier', `<div class="ar-card-fields"><div class="ar-card-field"><span class="ar-card-label">Name</span><span class="ar-card-value">${esc(s.supplier_name || '—')}</span></div>
                    <div class="ar-card-field"><span class="ar-card-label">Contact</span><span class="ar-card-value">${esc([s.contact_person, s.phone, s.email].filter(Boolean).join(' · ') || '—')}</span></div>
                    <div class="ar-card-field"><span class="ar-card-label">Ordered</span><span class="ar-card-value">${day(p.order_date)} by ${esc(p.created_by_name || '')}</span></div>
                    <div class="ar-card-field"><span class="ar-card-label">Expected</span><span class="ar-card-value">${day(p.expected_date)}</span></div>
                    ${p.purchase_requests ? `<div class="ar-card-field"><span class="ar-card-label">From request</span><span class="ar-card-value">${esc(p.purchase_requests.pr_no)}</span></div>` : ''}
                    ${p.notes ? `<div class="ar-card-field"><span class="ar-card-label">Notes</span><span class="ar-card-value">${esc(p.notes)}</span></div>` : ''}
                    ${p.cancelled_at ? `<div class="ar-card-field"><span class="ar-card-label">Cancelled</span><span class="ar-card-value text-danger">${esc(p.cancelled_by_name)} · ${esc(p.cancel_reason || '')}</span></div>` : ''}</div>`)}
                ${card('feather-download', 'Deliveries', `<div class="ar-card-fields">${d.receipts.length ? `<ul class="inv-steps">${d.receipts.map(r => `<li class="done">${esc(r.receipt_no)}${r.delivery_ref ? ' · Ref ' + esc(r.delivery_ref) : ''} — ${(r.purchase_receipt_items || []).reduce((a, x) => a + +x.quantity, 0)} unit(s)
                    <span class="inv-step-when">${esc(r.received_by_name || '')} · ${esc(when(r.received_at))}</span></li>`).join('')}</ul>` : '<p class="inv-empty-line">Nothing received yet.</p>'}</div>`)}
            </div></div>`;
            const b = [];
            if (d.can.receive) b.push('<button type="button" class="btn btn-primary" data-po="receive"><i class="feather-download me-1"></i> Receive Delivery</button>');
            if (d.can.cancel)  b.push(`<button type="button" class="btn inv-btn-danger" data-po="cancel"><i class="feather-x me-1"></i> ${p.status === 'partially_received' ? 'Cancel the Rest' : 'Cancel Order'}</button>`);
            shell('feather-shopping-cart', `${esc(p.po_no)} ${pill(p.status, PO_ST[p.status] || p.status)}`, 'Deliver to ' + p.branch_name, body, b.join('') + closeBtn());
            $('pcFoot').onclick = e => {
                const a = e.target.closest('[data-po]'); if (!a) return;
                if (a.dataset.po === 'receive') return openReceive(d);
                const part = p.status === 'partially_received';
                confirmAct('po_cancel', { po_id: p.po_id }, {
                    tone: 'danger', glyph: 'feather-x-circle', chip: p.po_no, chipIcon: 'feather-shopping-cart',
                    title: part ? 'Cancel the undelivered rest?' : 'Cancel this order?',
                    text: part ? 'Stock already received stays in inventory.' : 'Nothing has been received yet.',
                    reason: { label: 'Reason for cancelling', placeholder: 'e.g. Supplier is out of stock' },
                    confirmText: part ? 'Cancel the Rest' : 'Cancel Order', cancelText: 'Keep Order',
                });
            };
        }).catch(offline);
    };

    // ══ RECEIVE DELIVERY ══════════════════════════════════════
    function openReceive(d) {
        const p = d.po, key = newKey();
        const due = d.lines.filter(l => l.quantity_ordered > l.quantity_received);
        const rows = due.map(l => {
            const left = l.quantity_ordered - l.quantity_received;
            return `<tr data-line="${l.line_id}" data-left="${left}" data-track="${l.track_expiry ? 1 : 0}" data-name="${esc(l.item_name)}">
                <td class="inv-line-item"><span class="inv-line-name">${esc(l.item_name)}</span><div class="inv-line-help">${num(left)} ${esc(U(l.unit, left))} still due</div></td>
                <td class="inv-line-qty"><input type="number" class="form-control form-control-sm" min="0" max="${left}" value="${left}" data-f="qty" aria-label="Quantity received"></td>
                <td class="inv-line-lot"><input type="text" class="form-control form-control-sm" maxlength="60" placeholder="Lot no. (optional)" data-f="lot" aria-label="Lot number"></td>
                <td class="inv-line-exp"><input type="date" class="form-control form-control-sm js-date-picker" data-f="exp" aria-label="Expiry date">${l.track_expiry ? '<div class="inv-line-help text-danger">Required</div>' : ''}</td></tr>`;
        }).join('');
        const body = `<div class="rec-form-stack">
            <div class="inv-alert inv-alert-info mb-0"><i class="feather-info"></i><span>The quantities below are added to <strong>${esc(p.branch_name)}</strong>'s inventory. Enter 0 for items that did not arrive.</span></div>
            ${card('feather-package', 'Delivered Items', `<div class="apt-form-card-body"><div class="inv-lines-scroll"><table class="inv-lines">${lineHead([['Item'], ['Received'], ['Lot / Batch No.'], ['Expiry Date']])}<tbody>${rows}</tbody></table></div></div>`)}
            ${card('feather-file-text', 'Delivery Reference', `<div class="apt-form-card-body"><div class="row g-3">
                <div class="col-md-6"><div class="apt-field mb-0"><label class="apt-label" for="rc_ref">Delivery Receipt / Invoice No.</label><input type="text" id="rc_ref" class="form-control" maxlength="60" placeholder="e.g. DR-20931"></div></div>
                <div class="col-md-6"><div class="apt-field mb-0"><label class="apt-label" for="rc_notes">Notes</label><input type="text" id="rc_notes" class="form-control" maxlength="500" placeholder="Optional"></div></div>
            </div></div>`)}
        </div>`;
        shell('feather-download', 'Receive Delivery — ' + esc(p.po_no), (p.suppliers || {}).supplier_name || '', body,
            formFoot('<button type="submit" class="btn btn-primary px-4" id="pcSave"><i class="feather-check me-1"></i> Add to Inventory</button>'), save, 'form-wide');
        function save() {
            const lines = [];
            for (const tr of $('pcBody').querySelectorAll('tr[data-line]')) {
                const q = parseInt(tr.querySelector('[data-f="qty"]').value || '0', 10), left = +tr.dataset.left, name = tr.dataset.name;
                if (isNaN(q) || q < 0) return err(`"${name}": enter 0 or more.`);
                if (q > left) return err(`"${name}": only ${left} still due.`);
                if (!q) continue;
                const exp = tr.querySelector('[data-f="exp"]').value;
                if (tr.dataset.track === '1' && !exp) return err(`"${name}" tracks expiry dates — enter the expiry date.`);
                lines.push({ line_id: +tr.dataset.line, quantity: q, lot_number: tr.querySelector('[data-f="lot"]').value.trim(), expiry_date: exp });
            }
            if (!lines.length) return err('Enter the quantity delivered for at least one item.');
            const btn = $('pcSave'); busy(btn, true);
            post({ action: 'po_receive', po_id: p.po_id, lines, delivery_ref: $('rc_ref').value.trim(), notes: $('rc_notes').value.trim(), request_key: key })
                .then(r => { busy(btn, false); r.success ? done(r.message, 'Delivery Received') : err(r.message || 'Could not save.'); })
                .catch(() => { busy(btn, false); err('Could not connect to the server.'); });
        }
    }

    // ══ SUPPLIERS ═════════════════════════════════════════════
    window.openSupplierModal = function (s) {
        s = s || {};
        const f = (id, label, val, attrs = '') => `<div class="apt-field mb-0"><label class="apt-label" for="${id}">${label}</label><input type="text" id="${id}" class="form-control" value="${esc(val || '')}" ${attrs}></div>`;
        const body = `<div class="rec-form-stack">` + card('feather-briefcase', 'Supplier Details', `<div class="apt-form-card-body"><div class="row g-3">
            <div class="col-12">${f('sp_name', 'Supplier Name <span class="apt-req">*</span>', s.supplier_name, 'maxlength="120" placeholder="e.g. Dental Depot PH"')}</div>
            <div class="col-md-6">${f('sp_contact', 'Contact Person', s.contact_person, 'maxlength="100" placeholder="Optional"')}</div>
            <div class="col-md-6">${f('sp_phone', 'Phone', s.phone, 'maxlength="20" inputmode="tel" placeholder="e.g. 0917 123 4567"')}</div>
            <div class="col-md-6">${f('sp_email', 'Email', s.email, 'maxlength="120" inputmode="email" placeholder="Optional"')}</div>
            <div class="col-md-6">${f('sp_addr', 'Address', s.address, 'maxlength="250" placeholder="Optional"')}</div>
            <div class="col-12">${f('sp_notes', 'Notes', s.notes, 'maxlength="500" placeholder="e.g. Delivers every Tuesday; 30-day terms"')}</div>
        </div></div>`) + `</div>`;
        shell('feather-briefcase', s.supplier_id ? 'Edit Supplier' : 'Add Supplier', s.supplier_id ? s.supplier_name : 'A company you buy supplies from.', body,
            formFoot(`<button type="submit" class="btn btn-primary px-4" id="pcSave"><i class="feather-save me-1"></i> ${s.supplier_id ? 'Save Changes' : 'Add Supplier'}</button>`), () => {
                if (!$('sp_name').value.trim()) return err('Supplier name is required.');
                const btn = $('pcSave'); busy(btn, true);
                post({ action: 'supplier_save', supplier_id: s.supplier_id || 0, supplier_name: $('sp_name').value.trim(), contact_person: $('sp_contact').value.trim(),
                       phone: $('sp_phone').value.trim(), email: $('sp_email').value.trim(), address: $('sp_addr').value.trim(), notes: $('sp_notes').value.trim() })
                    .then(d => { busy(btn, false); if (d.success) { _lk = null; done(d.message, s.supplier_id ? 'Supplier Updated' : 'Supplier Added'); } else err(d.message || 'Could not save.'); })
                    .catch(() => { busy(btn, false); err('Could not connect to the server.'); });
            }, 'form');
    };
    window.toggleSupplier = function (id, active, name) {
        // Reactivating is harmless: no confirmation. Deactivating hides it from new orders: confirm once.
        if (active) {
            return post({ action: 'supplier_toggle', supplier_id: id, is_active: true })
                .then(d => { if (!d.success) return UI.fail('Could not reactivate', d.message); _lk = null; UI.ok(d.message || name + ' was reactivated.', 'Supplier Reactivated'); after(); })
                .catch(offline);
        }
        confirmAct('supplier_toggle', { supplier_id: id, is_active: false }, {
            tone: 'danger', glyph: 'feather-slash', title: 'Deactivate this supplier?', chip: name, chipIcon: 'feather-briefcase',
            text: 'It can no longer be chosen for new orders. Past orders are kept.', confirmText: 'Deactivate', cancelText: 'Keep Active',
        }).then(() => { _lk = null; });
    };
})();
