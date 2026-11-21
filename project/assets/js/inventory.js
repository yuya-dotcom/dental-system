/* =============================================================
   EssenciaSmile Admin — Inventory Records JS
   File: assets/js/inventory.js
   Depends on: admin.js, es-ajax.js (esRefresh), sweetalert2
   Page config: window.INV = { v2, canManage, isOwner, branches, categories, nearDays }
   APIs: api/inventory_data.php (read), api/inventory_crud.php (write)
   ============================================================= */
(function () {
    const CFG = window.INV || { v2: false, canManage: false, branches: [], categories: [] };
    const $ = id => document.getElementById(id);
    const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
    const num = n => Number(n || 0).toLocaleString();
    const peso = n => '₱' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const fmtDate = s => s ? new Date(String(s).length === 10 ? s + 'T00:00:00' : s).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
    const fmtDateTime = s => s ? new Date(s).toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—';
    const newKey = () => (crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2)).replace(/[^A-Za-z0-9_-]/g, '');
    // Stock status pill (same .inv-flow pill as transfers / orders)
    const STATUS = { in_stock: 'In Stock', low_stock: 'Low Stock', out_of_stock: 'Out of Stock' };
    const pill = (st, label) => `<span class="inv-flow f-${esc(st)}">${esc(label || STATUS[st] || st)}</span>`;
    const modal = id => bootstrap.Modal.getOrCreateInstance($(id));
    const UI = window.InvUI;
    const U = (u, n) => UI.unit(u, n);   // display unit: pcs → pieces

    // Hide enhancement-only fields until the database update has run
    document.addEventListener('DOMContentLoaded', () => {
        if (!CFG.v2) document.querySelectorAll('.inv-v2').forEach(el => el.classList.add('d-none'));
    });

    function getJSON(url) {
        return fetch(url, { credentials: 'same-origin' }).then(r => r.json().catch(() => ({ success: false, message: 'Unexpected server response.' })));
    }
    function postCrud(body) {
        return fetch('api/inventory_crud.php', {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        }).then(r => r.json().catch(() => ({ success: false, message: 'Unexpected server response.' })));
    }
    const toast = (msg, title) => UI.ok(msg, title);
    function after() { return window.esRefresh ? window.esRefresh() : location.reload(); }
    function showErr(boxId, msg) { const b = $(boxId); b.textContent = msg; b.style.display = 'block'; b.scrollIntoView({ block: 'nearest' }); }
    const busy = (btn, on) => UI.busy(btn, on);
    const offline = () => UI.fail('Could not connect', 'Please check your connection and try again.');

    // ── Lookups (suppliers + item masters), loaded once ─────────
    let _lookup = null, _lookupP = null;
    function lookup() {
        if (_lookup) return Promise.resolve(_lookup);
        if (_lookupP) return _lookupP;   // one request even when the preload and a click overlap
        return (_lookupP = getJSON('api/inventory_data.php?action=lookup')
            .then(d => (_lookup = d.success ? d : { masters: [], suppliers: [], units: [] }))
            .finally(() => { _lookupP = null; }));
    }
    function getItem(id) { return getJSON('api/inventory_data.php?action=item&id=' + encodeURIComponent(id)); }
    // Warm the dropdown data while the user reads the page, so Add / Edit open at once
    if (CFG.canManage && CFG.v2) {
        const warm = () => lookup().catch(() => { _lookup = null; });
        window.addEventListener('load', () => (window.requestIdleCallback ? requestIdleCallback(warm, { timeout: 3000 }) : setTimeout(warm, 800)));
    }

    // ══ DETAILS ═══════════════════════════════════════════════
    let _cur = null;
    window.viewItem = function (id) {
        $('ivLoading').hidden = false; $('ivBody').hidden = true;
        $('ivName').textContent = 'Item'; $('ivCode').textContent = '—'; $('ivBranch').textContent = '—';
        modal('inventoryViewModal').show();
        getItem(id).then(d => {
            if (!d.success) { $('ivLoading').textContent = d.message || 'Could not load the item.'; return; }
            _cur = d; renderDetails(d);
        }).catch(() => { $('ivLoading').textContent = 'Could not connect to the server.'; });
    };

    function renderDetails(d) {
        const it = d.item, unit = it.unit || '';
        $('ivName').textContent = it.item_name; $('ivCode').textContent = it.item_code || '—'; $('ivBranch').textContent = it.branch_name || '—';
        $('ivStatus').innerHTML = it.archived_at ? pill('archived', 'Archived') : pill(it.status);
        $('ivStock').textContent = num(it.stock) + ' ' + U(unit, it.stock);
        $('ivMin').textContent = num(it.min_stock) + (d.v2 ? ' / ' + (it.reorder_qty ? num(it.reorder_qty) : '—') : '');
        $('ivValueWrap').hidden = !d.v2 || !CFG.canManage;
        $('ivValue').textContent = it.stock_value == null ? 'No cost set' : peso(it.stock_value);
        $('ivCat').textContent = it.category || '—';
        $('ivUnit').textContent = U(unit) || '—';
        $('ivCost').textContent = it.unit_cost == null ? '—' : peso(it.unit_cost) + ' per ' + (U(unit, 1) || 'unit');
        $('ivSupplier').innerHTML = it.supplier ? esc(it.supplier.supplier_name) + (it.supplier.phone ? `<span class="d-block small text-muted">${esc(it.supplier.contact_person || '')} ${esc(it.supplier.phone)}</span>` : '') : '—';
        $('ivTrack').textContent = d.v2 ? (it.track_expiry ? 'Tracked (lot & expiry)' : 'Not tracked') : '—';
        $('ivUpdated').textContent = fmtDateTime(it.updated_at);

        // Alerts strip
        const alerts = [];
        const expired = d.batches.filter(b => b.state === 'expired'), soon = d.batches.filter(b => b.state === 'soon');
        if (it.stock <= 0) alerts.push(['danger', 'feather-x-octagon', 'Out of stock. ' + (it.reorder_qty ? `Suggested order: ${num(it.reorder_qty)} ${U(unit, it.reorder_qty)}.` : '')]);
        else if (it.stock <= it.min_stock) alerts.push(['warning', 'feather-alert-triangle', `Low stock — at or below the minimum of ${num(it.min_stock)}. ` + (it.reorder_qty ? `Suggested order: ${num(it.reorder_qty)} ${U(unit, it.reorder_qty)}.` : '')]);
        if (expired.length) alerts.push(['danger', 'feather-slash', `${num(expired.reduce((a, b) => a + +b.quantity_remaining, 0))} ${U(unit)} expired — record them with Log Movement → Expired Items.`]);
        if (soon.length) alerts.push(['warning', 'feather-clock', `${num(soon.reduce((a, b) => a + +b.quantity_remaining, 0))} ${U(unit)} expire within ${d.near_days} days.`]);
        $('ivAlerts').innerHTML = alerts.map(a => `<div class="inv-alert inv-alert-${a[0]}"><i class="${a[1]}"></i><span>${esc(a[2])}</span></div>`).join('');

        // Batches
        $('ivBatchCard').hidden = !d.v2 || (!it.track_expiry && !d.batches.length);
        const bRows = d.batches.map(b => {
            const cls = b.state === 'expired' ? 'text-danger fw-semibold' : (b.state === 'soon' ? 'text-warning fw-semibold' : '');
            const tag = b.state === 'expired' ? ' <span class="badge bg-soft-danger text-danger">Expired</span>' : (b.state === 'soon' ? ' <span class="badge bg-soft-warning text-warning">Soon</span>' : '');
            return `<tr><td>${esc(b.lot_number || '—')}</td><td class="${cls}">${b.expiry_date ? fmtDate(b.expiry_date) : '—'}${tag}</td>
                <td class="text-end">${num(b.quantity_remaining)} <span class="text-muted small">of ${num(b.quantity_received)}</span></td><td class="small text-muted">${fmtDate(b.received_at)}</td></tr>`;
        });
        if (it.no_batch_qty > 0) bRows.push(`<tr><td colspan="2" class="text-muted fst-italic">No batch information</td><td class="text-end">${num(it.no_batch_qty)}</td><td></td></tr>`);
        $('ivBatches').innerHTML = bRows.join('') || '<tr><td colspan="4" class="text-muted text-center py-3">No batches recorded yet.</td></tr>';

        // Movements
        $('ivMovements').innerHTML = d.movements.map(m => {
            const c = +m.quantity_change;
            const stock = m.stock_before != null ? `${num(m.stock_before)} → ${num(m.stock_after)}` : '—';
            return `<tr><td class="small text-nowrap">${fmtDate(m.movement_date || m.created_at)}</td>
                <td><span class="badge ${m.movement_type ? m.type_badge : (c >= 0 ? 'bg-soft-success text-success' : 'bg-soft-primary text-primary')}">${esc(m.movement_type ? m.type_label : (c >= 0 ? 'Stock In' : 'Stock Out'))}</span></td>
                <td class="text-end fw-semibold ${c >= 0 ? 'text-success' : 'text-danger'}">${c > 0 ? '+' : ''}${num(c)}</td>
                <td class="text-end small text-nowrap">${stock}</td>
                <td class="small">${esc(m.reason || '—')}${m.reference_no ? `<span class="d-block text-muted">Ref ${esc(m.reference_no)}</span>` : ''}</td>
                <td class="small">${esc(m.performed_by || '—')}</td></tr>`;
        }).join('') || '<tr><td colspan="6" class="text-muted text-center py-3">No movements yet.</td></tr>';
        $('ivAllMov').href = 'inventory-stock-movement.php?item=' + it.item_id;

        // Other branches (owner)
        $('ivOthersCard').hidden = !d.others.length;
        $('ivOthers').innerHTML = d.others.map(o => {
            return `<li><span class="inv-mini-name">${esc(o.branch)}</span><span class="inv-mini-val"><strong>${num(o.stock)}</strong> ${esc(U(unit, o.stock))} ${pill(o.status)}</span></li>`;
        }).join('');

        const archived = !!it.archived_at;
        if ($('ivMoveBtn')) { $('ivMoveBtn').hidden = archived; $('ivMoveBtn').onclick = () => { modal('inventoryViewModal').hide(); openMovementModal(it.item_id); }; }
        if ($('ivEditBtn')) { $('ivEditBtn').hidden = archived; $('ivEditBtn').onclick = () => { modal('inventoryViewModal').hide(); openEditItemModal(it.item_id); }; }
        $('ivLoading').hidden = true; $('ivBody').hidden = false;
    }

    // ══ ADD / EDIT ════════════════════════════════════════════
    function fillFormLists(lk, unitNow) {
        // Category dropdown: categories already in use (+ the master items'), then "Add new category…"
        const cats = [...new Set([...(CFG.categories || []), ...(lk.masters || []).map(m => m.category)]
            .map(c => String(c || '').trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b));
        $('inv_category').innerHTML = '<option value="">No category</option>'
            + cats.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('')
            + `<option value="${CAT_NEW}">+ Add new category…</option>`;
        $('inv_category_new').value = ''; $('inv_category_new').hidden = true;
        const units = (lk.units || ['pcs']).slice();
        if (unitNow && !units.includes(unitNow)) units.unshift(unitNow);
        $('inv_unit').innerHTML = units.map(u => `<option value="${esc(u)}">${esc(U(u))}</option>`).join('');
        $('inv_supplier').innerHTML = '<option value="">— None —</option>' + (lk.suppliers || [])
            .filter(s => s.is_active !== false).map(s => `<option value="${s.supplier_id}">${esc(s.supplier_name)}</option>`).join('');
        $('inv_branch').innerHTML = (CFG.branches.length > 1 ? '<option value="">— Select Branch —</option>' : '') +
            CFG.branches.map(b => `<option value="${b.id}">${esc(b.name)}</option>`).join('');
    }
    // ── Category: dropdown + "Add new category…" text box ──
    const CAT_NEW = '__new__';
    /** Show a stored category (adds it to the list if it isn't there yet). */
    function setCategory(v) {
        const sel = $('inv_category'), val = String(v || '').trim();
        if (val && ![...sel.options].some(o => o.value === val)) {
            const o = document.createElement('option'); o.value = val; o.textContent = val;
            sel.insertBefore(o, sel.querySelector(`option[value="${CAT_NEW}"]`));
        }
        sel.value = val;
        $('inv_category_new').value = ''; $('inv_category_new').hidden = true;
    }
    /** The category to save: the chosen one, or the typed new name. */
    function categoryValue() {
        const v = $('inv_category').value;
        return v === CAT_NEW ? $('inv_category_new').value.trim() : v.trim();
    }
    document.addEventListener('change', e => {
        if (e.target.id !== 'inv_category') return;
        const isNew = e.target.value === CAT_NEW;
        $('inv_category_new').hidden = !isNew;
        if (isNew) setTimeout(() => $('inv_category_new').focus(), 0);
    });

    function setShared(m, lock) {
        if (m) setCategory(m.category || '');
        if (m) { $('inv_unit').value = m.unit || 'pcs'; $('inv_supplier').value = m.supplier_id || ''; $('inv_cost').value = m.unit_cost ?? ''; $('inv_track').checked = !!m.track_expiry; }
        document.querySelectorAll('#inventoryForm .inv-shared').forEach(el => { el.disabled = !!lock; });
    }
    function findMaster(name) {
        const k = String(name || '').trim().toLowerCase();
        return (_lookup && _lookup.masters || []).find(m => m.item_name.trim().toLowerCase() === k) || null;
    }
    window.invToggleOpeningBatch = function () {
        const add = !$('inv_id').value;
        $('invOpeningBatch').hidden = !CFG.v2 || !add || !(+$('inv_stock').value > 0) || !$('inv_track').checked;
    };

    window.openAddItemModal = function () {
        lookup().then(lk => {
            fillFormLists(lk);
            $('invModalTitle').textContent = 'Add Inventory Item';
            $('invModalSub').textContent = 'An item code is assigned automatically.';
            UI.resetForm($('inventoryForm'));
            $('inv_id').value = ''; $('inv_stock').value = '0'; $('inv_min_stock').value = '0'; $('inv_reorder').value = '0';
            $('inv_unit').value = 'pcs';
            $('inv_branch').disabled = false; $('inv_stock').disabled = false; $('inv_name').disabled = false;
            if (CFG.branches.length === 1) $('inv_branch').value = CFG.branches[0].id;
            $('invStockLabel').textContent = 'Opening Stock';
            $('invStockHelp').textContent = 'Units on hand right now. Recorded as the first Stock In.';
            $('invNameHelp').hidden = !CFG.v2;
            $('invSharedNote').hidden = true;
            setShared(null, false);
            $('invModalError').style.display = 'none';
            invToggleOpeningBatch();
            modal('inventoryModal').show();
        });
    };
    document.addEventListener('input', e => {
        if (e.target.id !== 'inv_name' || $('inv_id').value || !CFG.v2) return;
        const m = findMaster(e.target.value);
        setShared(m, !!m);
        $('invSharedNote').hidden = !m;
        if (m) $('invSharedNote').querySelector('span').textContent = `"${m.item_name}" already exists — this adds it to the selected branch with the same shared details.`;
        invToggleOpeningBatch();
    });

    window.openEditItemModal = function (id) {
        Promise.all([lookup(), getItem(id)]).then(([lk, d]) => {
            if (!d.success) return UI.fail('Could not open the item', d.message || 'Item not found.');
            const it = d.item;
            fillFormLists(lk, it.unit);
            UI.resetForm($('inventoryForm'));
            $('invModalTitle').textContent = 'Edit ' + it.item_name;
            $('invModalSub').textContent = (it.item_code || '') + ' · ' + (it.branch_name || '');
            $('inv_id').value = it.item_id;
            $('inv_name').value = it.item_name; $('inv_name').disabled = false;
            setCategory(it.category || '');
            $('inv_unit').value = it.unit || 'pcs';
            $('inv_supplier').value = it.supplier_id || '';
            $('inv_cost').value = it.unit_cost ?? '';
            $('inv_track').checked = !!it.track_expiry;
            $('inv_branch').value = it.branch_id; $('inv_branch').disabled = CFG.v2;
            $('inv_stock').value = it.stock; $('inv_stock').disabled = CFG.v2;
            $('invStockLabel').textContent = 'Current Stock';
            $('invStockHelp').textContent = CFG.v2 ? 'Change stock with Log Movement so every change has a reason.' : 'Units on hand.';
            $('inv_min_stock').value = it.min_stock; $('inv_reorder').value = it.reorder_qty || 0;
            $('invNameHelp').hidden = true;
            $('invSharedNote').hidden = !CFG.v2;
            $('invSharedNote').querySelector('span').textContent = 'Name, category, unit, supplier, cost and expiry tracking are shared by every branch that stocks this item.';
            document.querySelectorAll('#inventoryForm .inv-shared').forEach(el => { el.disabled = false; });
            $('invModalError').style.display = 'none';
            invToggleOpeningBatch();
            modal('inventoryModal').show();
        }).catch(offline);
    };

    window.saveItem = function () {
        const id = $('inv_id').value, btn = $('invSaveBtn');
        $('invModalError').style.display = 'none';
        const name = $('inv_name').value.trim();
        if (!name) return showErr('invModalError', 'Item name is required.');
        if (!id && !$('inv_branch').value) return showErr('invModalError', 'Choose a branch.');
        const min = $('inv_min_stock').value, reo = $('inv_reorder').value, qty = $('inv_stock').value;
        if (min === '' || +min < 0) return showErr('invModalError', 'Minimum stock must be 0 or more.');
        if (CFG.v2 && (reo === '' || +reo < 0)) return showErr('invModalError', 'Reorder quantity must be 0 or more.');
        if (!id && (qty === '' || +qty < 0)) return showErr('invModalError', 'Opening stock must be 0 or more.');
        if (!id && CFG.v2 && $('inv_track').checked && +qty > 0 && !$('inv_exp').value) return showErr('invModalError', 'Enter the expiry date of the opening stock.');
        if ($('inv_category').value === CAT_NEW && !categoryValue()) return showErr('invModalError', 'Type the new category name, or choose a category from the list.');
        const payload = {
            action: id ? 'edit' : 'add', item_id: id, item_name: name,
            category: categoryValue(), branch_id: $('inv_branch').value,
            min_stock: min, reorder_qty: reo, unit: $('inv_unit').value, unit_cost: $('inv_cost').value,
            supplier_id: $('inv_supplier').value, track_expiry: $('inv_track').checked,
        };
        if (!id || !CFG.v2) payload.stock_quantity = qty;
        if (!id) Object.assign(payload, { lot_number: $('inv_lot').value.trim(), expiry_date: $('inv_exp').value, manufactured_date: $('inv_mfd').value });
        busy(btn, true);
        postCrud(payload).then(d => {
            busy(btn, false);
            if (!d.success) return showErr('invModalError', d.message || 'Could not save.');
            modal('inventoryModal').hide(); _lookup = null;
            toast(d.message || (id ? 'Item updated.' : 'Item added.'), id ? 'Item Updated' : 'Item Added'); after();
        }).catch(() => { busy(btn, false); showErr('invModalError', 'Could not connect to the server.'); });
    };

    // ══ DELETE / RESTORE ══════════════════════════════════════
    window.deleteItem = function (id, name) {
        UI.confirm({
            del: true, tone: 'danger', title: 'Delete this item?',
            text: 'It will be removed from the inventory list.', chip: name,
            note: 'If it has stock history it is <strong>archived</strong> instead, so the history is kept. Restore it any time from <em>Show: Archived Items</em>.',
            confirmText: 'Delete Item', cancelText: 'Keep Item',
        }).then(r => {
            if (!r.isConfirmed) return;
            postCrud({ action: 'delete', item_id: id }).then(d => {
                if (!d.success) return UI.fail('Could not delete', d.message || 'Delete failed.');
                toast(d.message || (d.archived ? name + ' was archived.' : name + ' was deleted.'), d.archived ? 'Item Archived' : 'Item Deleted'); after();
            }).catch(offline);
        });
    };
    // Restoring is harmless and can be undone: no confirmation, just feedback
    window.restoreItem = function (id, name) {
        postCrud({ action: 'restore', item_id: id }).then(d => {
            if (!d.success) return UI.fail('Could not restore', d.message || 'Restore failed.');
            toast(name + ' is back in the inventory list.', 'Item Restored'); after();
        }).catch(offline);
    };

    // ══ LOG MOVEMENT ══════════════════════════════════════════
    const TYPES = [
        ['stock_in',   'Stock In',         'Received or found stock',            'dot-green', +1],
        ['stock_out',  'Stock Out',        'Used or given out (1 unit stays)',    'dot-slate', -1],
        ['adjustment', 'Stock Adjustment', 'Set the counted quantity',            'dot-amber', 0],
        ['damaged',    'Damaged Items',    'Broken or unusable',                 'dot-red',   -1],
        ['expired',    'Expired Items',    'Past the expiry date',               'dot-red',   -1],
        ['wastage',    'Wastage',          'Spilled, contaminated, lost',        'dot-red',   -1],
    ];
    const REASONS = { stock_in: 'e.g. Restocked from supplier', stock_out: 'e.g. Used for clinic cleaning', adjustment: 'e.g. Monthly physical count',
        damaged: 'e.g. Box crushed during delivery', expired: 'e.g. Lot past expiry date', wastage: 'e.g. Spilled during preparation' };
    let _mov = null, _movKey = '';
    const movType = () => (document.querySelector('input[name="mov_type"]:checked') || {}).value || 'stock_in';

    window.openMovementModal = function (id) {
        getItem(id).then(d => {
            if (!d.success) return UI.fail('Could not open the item', d.message || 'Item not found.');
            _mov = d; _movKey = newKey();
            const it = d.item;
            $('mov_item_id').value = it.item_id;
            $('movItemName').textContent = it.item_name + ' · ' + (it.branch_name || '');
            $('movCurrentStock').textContent = num(it.stock) + ' ' + U(it.unit, it.stock);
            $('movTypes').innerHTML = TYPES.map((t, i) => `<input type="radio" name="mov_type" id="movT_${t[0]}" value="${t[0]}"${i === 0 ? ' checked' : ''}>
                <label for="movT_${t[0]}"><span class="rec-seg-title ${t[3]}">${t[1]}</span><span class="rec-seg-desc">${t[2]}</span></label>`).join('');
            if (!CFG.v2) $('movTypes').querySelectorAll('input:not([value="stock_in"]):not([value="stock_out"])').forEach(el => { el.disabled = true; el.nextElementSibling.classList.add('d-none'); });
            ['mov_quantity', 'mov_reason', 'mov_ref', 'mov_lot', 'mov_exp', 'mov_mfd'].forEach(f => { $(f).value = ''; });
            $('movModalError').style.display = 'none';
            onTypeChange();
            modal('movementModal').show();
        }).catch(offline);
    };
    document.addEventListener('change', e => { if (e.target.name === 'mov_type') onTypeChange(); });

    function onTypeChange() {
        if (!_mov) return;
        // A message about the previous type (e.g. the Stock Out "1 unit must stay" rule)
        // no longer applies: clear it. The rules themselves run again on Save.
        $('movModalError').style.display = 'none'; $('movModalError').textContent = '';
        const t = movType(), it = _mov.item, dir = (TYPES.find(x => x[0] === t) || [])[4];
        $('movQtyLabel').innerHTML = (t === 'adjustment' ? 'Counted Stock' : (dir > 0 ? 'Quantity to Add' : 'Quantity to Remove')) + ' <span class="apt-req" aria-hidden="true">*</span>';
        $('mov_quantity').min = t === 'adjustment' ? '0' : '1';
        $('mov_reason').placeholder = REASONS[t] || '';
        const showIn = CFG.v2 && t === 'stock_in';
        $('movBatchIn').hidden = !showIn;
        $('movExpReq').hidden = !it.track_expiry;
        const showOut = CFG.v2 && dir < 0 && _mov.batches.length > 0;
        $('movBatchOut').hidden = !showOut;
        if (showOut) {
            const list = t === 'expired' ? _mov.batches.filter(b => b.state === 'expired').concat(_mov.batches.filter(b => b.state !== 'expired')) : _mov.batches;
            $('mov_batch').innerHTML = `<option value="">Automatic — ${t === 'expired' ? 'expired batches first' : 'earliest expiry first'}</option>` + list.map(b =>
                `<option value="${b.batch_id}">${esc(b.lot_number || 'Batch #' + b.batch_id)} · ${b.expiry_date ? 'exp. ' + fmtDate(b.expiry_date) : 'no expiry'} · ${num(b.quantity_remaining)} left${b.state === 'expired' ? ' (EXPIRED)' : ''}</option>`).join('');
        }
        updateMovPreview();
    }

    window.updateMovPreview = function () {
        if (!_mov) return;
        const it = _mov.item, t = movType(), raw = $('mov_quantity').value, q = parseInt(raw, 10);
        const hint = $('movHint'), prev = $('movStockPreview');
        hint.hidden = true; hint.className = 'inv-hint';
        if (raw === '' || isNaN(q) || q < 0 || (t !== 'adjustment' && q === 0)) { prev.textContent = '—'; return; }
        const next = t === 'adjustment' ? q : (t === 'stock_in' ? it.stock + q : it.stock - q);
        prev.textContent = num(next) + ' ' + U(it.unit, next);
        prev.className = 'inv-current ' + (next < 0 ? 'text-danger' : next <= it.min_stock ? 'text-warning' : 'text-success');
        const say = (cls, msg) => { hint.hidden = false; hint.classList.add(cls); hint.textContent = msg; };
        const batchSel = $('mov_batch').value && _mov.batches.find(b => String(b.batch_id) === $('mov_batch').value);
        if (next < 0) say('is-bad', `Only ${num(it.stock)} ${U(it.unit, it.stock)} in stock.`);
        else if (t === 'stock_out' && next < 1) say('is-bad', 'Stock Out must leave at least 1 unit. To remove the last units, use Damaged, Expired, Wastage or Stock Adjustment.');
        else if (batchSel && t !== 'adjustment' && t !== 'stock_in' && q > +batchSel.quantity_remaining) say('is-bad', `That batch has only ${num(batchSel.quantity_remaining)} left.`);
        else if (t === 'adjustment' && next === it.stock) say('is-info', 'Same as the current stock — nothing to adjust.');
        else if (next === 0) say('is-warn', 'The item will be Out of Stock.');
        else if (next <= it.min_stock && it.stock > it.min_stock) say('is-warn', `Stock will fall to the minimum (${num(it.min_stock)}) — it will show as Low Stock.`);
        else if (t === 'adjustment') say('is-info', `Adjustment of ${next - it.stock > 0 ? '+' : ''}${num(next - it.stock)} ${U(it.unit, Math.abs(next - it.stock))} will be recorded.`);
    };

    window.saveMovement = function () {
        const it = _mov && _mov.item; if (!it) return;
        const t = movType(), raw = $('mov_quantity').value.trim(), q = parseInt(raw, 10), reason = $('mov_reason').value.trim(), btn = $('movSaveBtn');
        $('movModalError').style.display = 'none';
        if (raw === '' || isNaN(q) || q < 0 || (t !== 'adjustment' && q === 0)) return showErr('movModalError', t === 'adjustment' ? 'Enter the counted stock (0 or more).' : 'Enter a quantity greater than 0.');
        if (!reason) return showErr('movModalError', 'Reason is required.');
        if (CFG.v2 && t === 'stock_in' && it.track_expiry && !$('mov_exp').value) return showErr('movModalError', 'This item tracks expiry dates — enter the expiry date.');
        const next = t === 'adjustment' ? q : (t === 'stock_in' ? it.stock + q : it.stock - q);
        if (next < 0) return showErr('movModalError', `Only ${it.stock} ${U(it.unit, it.stock)} in stock.`);
        const payload = {
            action: 'log_movement', item_id: it.item_id, movement_type: t, quantity: q, reason,
            reference_no: $('mov_ref').value.trim(), request_key: _movKey,
            lot_number: $('mov_lot').value.trim(), expiry_date: $('mov_exp').value, manufactured_date: $('mov_mfd').value,
            batch_id: $('movBatchOut').hidden ? '' : $('mov_batch').value,
        };
        if (!CFG.v2) { delete payload.movement_type; payload.quantity_change = t === 'stock_in' ? q : -q; }
        const label = (TYPES.find(x => x[0] === t) || [])[1];
        const write = () => {
            busy(btn, true);
            postCrud(payload).then(d => {
                busy(btn, false);
                if (!d.success) return showErr('movModalError', d.message || 'Could not save.');
                modal('movementModal').hide(); _mov = null;
                toast(d.message || 'Saved.', 'Movement Recorded'); after();
            }).catch(() => { busy(btn, false); showErr('movModalError', 'Could not connect to the server.'); });
        };
        // Write-offs are confirmed once (they reduce stock permanently)
        if (['damaged', 'expired', 'wastage'].includes(t) || (t === 'adjustment' && next < it.stock)) {
            return UI.confirm({
                tone: 'warn', glyph: t === 'adjustment' ? 'feather-sliders' : 'feather-alert-triangle', title: `Record ${label}?`,
                text: `Stock goes from <strong>${num(it.stock)}</strong> to <strong>${num(next)}</strong> ${esc(U(it.unit, next))}.`, chip: it.item_name,
                note: 'Saved in the stock history. It cannot be edited later.',
                confirmText: 'Record It',
            }).then(r => { if (r.isConfirmed) write(); });
        }
        write();
    };
})();
