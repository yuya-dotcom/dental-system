/* =============================================================
   EssenciaSmile Admin — Inventory JS
   File: assets/js/inventory.js
   Depends on: admin.js, sweetalert2
   ============================================================= */


/* ── Open Add Item Modal ───────────────────────────────────────── */
function openAddItemModal() {
    document.getElementById('invModalTitle').textContent   = 'Add Inventory Item';
    document.getElementById('inv_id').value                = '';
    document.getElementById('inv_name').value              = '';
    document.getElementById('inv_category').value          = '';
    document.getElementById('inv_branch').value            = '';
    document.getElementById('inv_stock').value             = '0';
    document.getElementById('inv_min_stock').value         = '0';
    document.getElementById('inv_status').value            = 'active';
    document.getElementById('invModalError').style.display = 'none';
    new bootstrap.Modal(document.getElementById('inventoryModal')).show();
}


/* ── Open Edit Item Modal ──────────────────────────────────────── */
function openEditItemModal(item) {
    document.getElementById('invModalTitle').textContent   = 'Edit Inventory Item';
    document.getElementById('inv_id').value                = item.item_id;
    document.getElementById('inv_name').value              = item.item_name      || '';
    document.getElementById('inv_category').value          = item.category       || '';
    document.getElementById('inv_branch').value            = item.branch_id      || '';
    document.getElementById('inv_stock').value             = item.stock_quantity ?? 0;
    document.getElementById('inv_min_stock').value         = item.min_stock      ?? 0;
    document.getElementById('inv_status').value            = item.status         || 'active';
    document.getElementById('invModalError').style.display = 'none';
    new bootstrap.Modal(document.getElementById('inventoryModal')).show();
}


/* ── View Item Details ─────────────────────────────────────────── */
function viewItem(item) {
    document.getElementById('iv_code').textContent   = item.item_code      || '—';
    document.getElementById('iv_name').textContent   = item.item_name      || '—';
    document.getElementById('iv_cat').textContent    = item.category       || '—';
    document.getElementById('iv_branch').textContent = item.branch_name    || '—';
    document.getElementById('iv_stock').textContent  = item.stock_quantity ?? '—';
    document.getElementById('iv_min').textContent    = item.min_stock      ?? '—';
    document.getElementById('iv_status').textContent = item.status ? item.status.replace('_',' ').replace(/\b\w/g,c=>c.toUpperCase()) : '—';
    new bootstrap.Modal(document.getElementById('inventoryViewModal')).show();
}


/* ── Save Item (Add or Edit) ───────────────────────────────────── */
function saveItem() {
    const id     = document.getElementById('inv_id').value;
    const errBox = document.getElementById('invModalError');
    const btn    = document.getElementById('invSaveBtn');
    const payload = {
        action:         id ? 'edit' : 'add',
        item_id:        id,
        item_name:      document.getElementById('inv_name').value.trim(),
        category:       document.getElementById('inv_category').value.trim(),
        branch_id:      document.getElementById('inv_branch').value,
        stock_quantity: document.getElementById('inv_stock').value,
        min_stock:      document.getElementById('inv_min_stock').value,
        status:         document.getElementById('inv_status').value,
    };

    errBox.style.display = 'none';
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    fetch('api/inventory_crud.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            Swal.fire({ icon: 'success', title: 'Saved!', text: d.message, timer: 1600, showConfirmButton: false })
                .then(() => location.reload());
        } else {
            errBox.textContent   = d.message;
            errBox.style.display = 'block';
            btn.disabled         = false;
            btn.innerHTML        = '<i class="feather-save me-1"></i> Save';
        }
    })
    .catch(() => {
        errBox.textContent   = 'Could not connect to server.';
        errBox.style.display = 'block';
        btn.disabled         = false;
        btn.innerHTML        = '<i class="feather-save me-1"></i> Save';
    });
}


/* ── Delete Item ───────────────────────────────────────────────── */
function deleteItem(id, name) {
    confirmDelete('api/inventory_crud.php', { item_id: id }, name, 'Item');
}


/* ── Open Log Movement Modal ───────────────────────────────────── */
function openMovementModal(itemId, itemName, currentStock) {
    document.getElementById('mov_item_id').value            = itemId;
    document.getElementById('movItemName').textContent      = itemName;
    document.getElementById('movCurrentStock').textContent  = currentStock;
    document.getElementById('mov_type').value               = 'in';
    document.getElementById('mov_quantity').value           = '';
    document.getElementById('mov_reason').value             = '';
    document.getElementById('movModalError').style.display  = 'none';
    updateMovPreview();
    new bootstrap.Modal(document.getElementById('movementModal')).show();
}


/* ── Update Movement Preview ───────────────────────────────────── */
function updateMovPreview() {
    const type     = document.getElementById('mov_type').value;
    const qty      = parseInt(document.getElementById('mov_quantity').value) || 0;
    const current  = parseInt(document.getElementById('movCurrentStock').textContent) || 0;
    const change   = type === 'in' ? qty : -qty;
    const newStock = current + change;

    const preview = document.getElementById('movStockPreview');
    if (qty > 0) {
        preview.textContent  = `${current} → ${newStock}`;
        preview.className    = newStock < 0 ? 'fw-semibold text-danger' : 'fw-semibold text-success';
        preview.style.display = '';
    } else {
        preview.style.display = 'none';
    }
}


/* ── Save Movement ─────────────────────────────────────────────── */
function saveMovement() {
    const itemId  = document.getElementById('mov_item_id').value;
    const type    = document.getElementById('mov_type').value;
    const qty     = parseInt(document.getElementById('mov_quantity').value) || 0;
    const reason  = document.getElementById('mov_reason').value.trim();
    const errBox  = document.getElementById('movModalError');
    const btn     = document.getElementById('movSaveBtn');

    if (!qty || qty <= 0) {
        errBox.textContent   = 'Please enter a valid quantity (greater than 0).';
        errBox.style.display = 'block';
        return;
    }
    if (!reason) {
        errBox.textContent   = 'Reason is required.';
        errBox.style.display = 'block';
        return;
    }

    const change = type === 'in' ? qty : -qty;

    errBox.style.display = 'none';
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    fetch('api/inventory_crud.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'log_movement', item_id: itemId, quantity_change: change, reason })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            Swal.fire({ icon: 'success', title: 'Logged!', text: d.message, timer: 1800, showConfirmButton: false })
                .then(() => location.reload());
        } else {
            errBox.textContent   = d.message;
            errBox.style.display = 'block';
            btn.disabled         = false;
            btn.innerHTML        = '<i class="feather-check me-1"></i> Log Movement';
        }
    })
    .catch(() => {
        errBox.textContent   = 'Could not connect to server.';
        errBox.style.display = 'block';
        btn.disabled         = false;
        btn.innerHTML        = '<i class="feather-check me-1"></i> Log Movement';
    });
}