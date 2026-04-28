/* =============================================================
   EssenciaSmile — Service Materials JS
   File: assets/js/service-materials.js
   Depends on: admin.js, sweetalert2
   ============================================================= */

let _activeServiceId   = null;
let _activeServiceName = null;

// ── Load materials for selected service ──────────────────────
function loadServiceMaterials(serviceId, serviceName) {
    _activeServiceId   = serviceId;
    _activeServiceName = serviceName;

    // Highlight active service in list
    document.querySelectorAll('.service-item').forEach(el => {
        el.classList.toggle('active', el.dataset.serviceId == serviceId);
    });

    // Update panel title
    document.getElementById('materialsTitle').innerHTML =
        `<i class="feather-package me-2 text-primary"></i>${serviceName} — Materials`;
    document.getElementById('addMaterialBtn').style.display = '';

    // Show loading
    document.getElementById('materialsEmpty').style.display   = 'none';
    document.getElementById('materialsLoading').style.display = '';
    document.getElementById('materialsTableWrap').style.display = 'none';

    fetch(`api/service_materials.php?service_id=${serviceId}`)
        .then(r => r.json())
        .then(materials => {
            document.getElementById('materialsLoading').style.display   = 'none';
            document.getElementById('materialsTableWrap').style.display = '';
            renderMaterialsTable(materials);
        })
        .catch(() => {
            document.getElementById('materialsLoading').style.display = 'none';
            document.getElementById('materialsEmpty').style.display   = '';
            document.getElementById('materialsEmpty').innerHTML =
                '<i class="feather-alert-circle fs-2 mb-2 d-block text-danger"></i>Failed to load materials.';
        });
}

// ── Render the materials table ───────────────────────────────
function renderMaterialsTable(materials) {
    const tbody    = document.getElementById('materialsTableBody');
    const noItems  = document.getElementById('materialsNoItems');
    tbody.innerHTML = '';

    if (!materials || materials.length === 0) {
        noItems.style.display = '';
        return;
    }
    noItems.style.display = 'none';

    materials.forEach(mat => {
        const inv   = mat.inventory || {};
        const stock = parseInt(inv.stock_quantity ?? 0);
        const stockBadge = stock === 0
            ? 'bg-soft-danger text-danger'
            : stock <= 5
                ? 'bg-soft-warning text-warning'
                : 'bg-soft-success text-success';

        const tr = document.createElement('tr');
        tr.id = `mat_row_${mat.material_id}`;
        tr.innerHTML = `
            <td>
                <div class="fw-semibold small">${inv.item_name || '—'}</div>
                <div class="text-muted fs-12">${inv.item_code || ''}</div>
            </td>
            <td><span class="text-muted small">${inv.category || '—'}</span></td>
            <td><span class="badge ${stockBadge}">${stock} in stock</span></td>
            <td style="overflow:visible; padding-bottom:8px;">
                <div class="d-flex align-items-center gap-2" style="overflow:visible;">
                    <input type="number" class="mat-qty-input"
                        value="${mat.quantity}" min="1"
                        style="width:75px; padding:4px 8px; border:1px solid #ced4da; border-radius:6px; font-size:13px; background:#fff;"
                        data-material-id="${mat.material_id}"
                        onchange="updateMaterialQty(${mat.material_id}, this.value)">
                    <span class="text-muted small">units</span>
                </div>
            </td>
            <td class="text-end" style="overflow:visible; padding-bottom:8px;">
                <button style="width:32px;height:32px;border:1px solid #dc3545;border-radius:6px;background:#fff;color:#dc3545;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;"
                    onclick="deleteMaterial(${mat.material_id}, '${(inv.item_name || '').replace(/'/g, "\\'")}')"
                    onmouseover="this.style.background='#dc3545';this.style.color='#fff'"
                    onmouseout="this.style.background='#fff';this.style.color='#dc3545'"
                    title="Remove">
                    <i class="feather-trash-2" style="font-size:13px;"></i>
                </button>
            </td>`
        tbody.appendChild(tr);
    });
}

// ── Open Add Material Modal ──────────────────────────────────
function openAddMaterialModal() {
    if (!_activeServiceId) return;
    document.getElementById('addMat_serviceId').value       = _activeServiceId;
    document.getElementById('addMat_serviceName').textContent = _activeServiceName;
    document.getElementById('addMat_item').value            = '';
    document.getElementById('addMat_qty').value             = '1';
    document.getElementById('addMat_stockPreview').textContent = '—';
    document.getElementById('addMatError').style.display    = 'none';

    // Stock preview on item change
    document.getElementById('addMat_item').onchange = function () {
        const opt   = this.options[this.selectedIndex];
        const stock = opt?.dataset.stock ?? '—';
        document.getElementById('addMat_stockPreview').textContent =
            stock !== '—' ? `${stock} units` : '—';
    };

    new bootstrap.Modal(document.getElementById('addMaterialModal')).show();
}

// ── Save Material ────────────────────────────────────────────
function saveMaterial() {
    const errBox    = document.getElementById('addMatError');
    const btn       = document.getElementById('addMatSaveBtn');
    const serviceId = document.getElementById('addMat_serviceId').value;
    const itemId    = document.getElementById('addMat_item').value;
    const qty       = parseInt(document.getElementById('addMat_qty').value) || 0;

    errBox.style.display = 'none';
    if (!itemId)   { errBox.textContent = 'Please select an inventory item.'; errBox.style.display = 'block'; return; }
    if (qty < 1)   { errBox.textContent = 'Quantity must be at least 1.';    errBox.style.display = 'block'; return; }

    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    fetch('api/service_materials.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ service_id: serviceId, item_id: itemId, quantity: qty }),
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="feather-save me-1"></i> Save';
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('addMaterialModal'))?.hide();
            Swal.fire({ icon: 'success', title: 'Material Added!', timer: 1500, showConfirmButton: false })
                .then(() => loadServiceMaterials(_activeServiceId, _activeServiceName));
        } else {
            errBox.textContent   = res.message || 'Failed to add material.';
            errBox.style.display = 'block';
        }
    })
    .catch(() => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="feather-save me-1"></i> Save';
        errBox.textContent   = 'Network error. Please try again.';
        errBox.style.display = 'block';
    });
}

// ── Update Quantity inline ───────────────────────────────────
function updateMaterialQty(materialId, newQty) {
    newQty = parseInt(newQty);
    if (!newQty || newQty < 1) {
        // Reset to 1 if invalid
        const input = document.querySelector(`input[data-material-id="${materialId}"]`);
        if (input) input.value = 1;
        newQty = 1;
    }

    // We use DELETE + POST to update (Supabase REST PATCH on a junction table)
    // Instead, call a dedicated PATCH on service_materials
    fetch(`api/service_materials.php?id=${materialId}`, {
        method:  'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ quantity: newQty }),
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            Swal.fire({ icon: 'error', title: 'Update Failed', text: res.message, timer: 2000, showConfirmButton: false });
            loadServiceMaterials(_activeServiceId, _activeServiceName); // reload to reset
        }
        // Success — no toast needed for inline edit, it's already visible
    })
    .catch(() => {
        Swal.fire({ icon: 'error', title: 'Network error', timer: 1800, showConfirmButton: false });
    });
}

// ── Delete Material ──────────────────────────────────────────
function deleteMaterial(materialId, itemName) {
    Swal.fire({
        title: 'Remove Material?',
        html:  `Remove <strong>${itemName}</strong> from this service?`,
        icon:  'warning',
        showCancelButton:   true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor:  '#6c757d',
        confirmButtonText:  'Yes, Remove',
        cancelButtonText:   'Cancel',
    }).then(result => {
        if (!result.isConfirmed) return;
        fetch(`api/service_materials.php?id=${materialId}`, { method: 'DELETE' })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    document.getElementById(`mat_row_${materialId}`)?.remove();
                    // Show empty state if no rows left
                    if (document.getElementById('materialsTableBody').children.length === 0) {
                        document.getElementById('materialsNoItems').style.display = '';
                    }
                    Swal.fire({ icon: 'success', title: 'Removed!', timer: 1400, showConfirmButton: false });
                } else {
                    Swal.fire('Error', res.message || 'Could not remove material.', 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Network error. Please try again.', 'error'));
    });
}