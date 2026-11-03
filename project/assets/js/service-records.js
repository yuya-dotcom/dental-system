/* =============================================================
   EssenciaSmile — Services (details + materials)
   File: assets/js/service-records.js
   Page: service-records.php
   Depends on: bootstrap (vendors.min.js), sweetalert2, admin.js
   APIs:  api/service_crud.php      (services)
          api/service_materials.php (materials — unchanged)
   ============================================================= */

(function () {
    'use strict';

    const DATA     = window.SR_DATA || { services: [], isOwner: false, needsMigration: false };
    const IS_OWNER = !!DATA.isOwner;
    let services   = Array.isArray(DATA.services) ? DATA.services : [];

    let currentId  = null;   // selected service_id, or null
    let isNew      = false;  // the panel shows the "new service" form
    let savedForm  = null;   // form values at load/save, for the unsaved-changes check
    let saving     = false;

    const $ = id => document.getElementById(id);

    // ── Formatting ───────────────────────────────────────────────
    function esc(v) {
        return String(v ?? '').replace(/[&<>"']/g, c => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
    function peso(n) {
        return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function duration(min) {
        min = parseInt(min, 10) || 0;
        const h = Math.floor(min / 60), m = min % 60;
        if (h && m) return `${h} hr ${m} min`;
        if (h) return `${h} hr`;
        return `${m} min`;
    }
    function findService(id) {
        return services.find(s => Number(s.service_id) === Number(id)) || null;
    }
    // ── Alerts: the system's es-swal look (assets/css/es-swal.css, assets/js/es-swal.js) ──
    function swalHtml(text, chip) {
        return `<p class="es-swal-text">${text}</p>` +
               (chip ? `<span class="es-swal-chip"><i class="feather-activity"></i> ${esc(chip)}</span>` : '');
    }
    // Short success notice that closes itself
    function toast(title, text, chip) {
        return Swal.fire({
            icon: 'success',
            iconHtml: '<i class="feather-check-circle es-swal-glyph"></i>',
            title,
            html: swalHtml(esc(text || ''), chip),
            confirmButtonText: 'Okay',
            timer: 1600,
            timerProgressBar: true,
            buttonsStyling: false,
            customClass: ES_SWAL_TONE('success'),
        });
    }
    function errorAlert(title, text) {
        return Swal.fire({
            icon: 'error',
            iconHtml: '<i class="feather-alert-circle es-swal-glyph"></i>',
            title,
            html: swalHtml(esc(text || 'Something went wrong. Please try again.')),
            confirmButtonText: 'Okay',
            buttonsStyling: false,
            customClass: ES_SWAL_TONE('warn'),
        });
    }
    // Yes/no question. tone 'danger' = red confirm button. Resolves true when confirmed.
    function confirmAlert({ tone = 'danger', icon = 'feather-alert-triangle', title, html, chip, confirmText, cancelText = 'Go Back' }) {
        return Swal.fire({
            icon: 'question',
            iconHtml: `<i class="${icon} es-swal-glyph"></i>`,
            title,
            html: swalHtml(html, chip),
            showCancelButton: true,
            confirmButtonText: confirmText,
            cancelButtonText: cancelText,
            reverseButtons: true,
            focusCancel: true,
            buttonsStyling: false,
            customClass: ES_SWAL_TONE(tone),
        }).then(r => r.isConfirmed);
    }

    // ── List ─────────────────────────────────────────────────────
    function filtered() {
        const q      = $('srSearch').value.trim().toLowerCase();
        const type   = $('srFilterType').value;
        const status = $('srFilterStatus').value;
        return services.filter(s =>
            (!type   || s.service_type === type) &&
            (!status || s.status === status) &&
            (!q || s.service_name.toLowerCase().includes(q) || (s.description || '').toLowerCase().includes(q))
        );
    }

    function renderList() {
        const list = filtered();
        $('srCount').textContent = list.length;

        if (!list.length) {
            $('srList').innerHTML = `<div class="text-center py-5 text-muted small">
                <i class="feather-search fs-3 d-block mb-2"></i>No services match your filters.</div>`;
            return;
        }

        $('srList').innerHTML = list.map(s => {
            const active = !isNew && Number(s.service_id) === Number(currentId);
            const mats   = Number(s.material_count) || 0;
            return `
            <a href="javascript:void(0);" class="list-group-item list-group-item-action sr-item${active ? ' active' : ''}"
               data-id="${Number(s.service_id)}" role="option" aria-selected="${active}">
                <div class="d-flex align-items-center gap-2">
                    <div class="avatar-text avatar-sm bg-soft-primary text-primary rounded-circle flex-shrink-0">
                        <i class="feather-activity fs-12"></i>
                    </div>
                    <div class="overflow-hidden flex-grow-1">
                        <div class="sr-item-name text-truncate">${esc(s.service_name)}</div>
                        <div class="sr-item-meta">
                            ${esc(s.service_type)} · ${peso(s.base_price)} · <i class="feather-clock"></i> ${duration(s.duration_minutes)}
                        </div>
                    </div>
                    <div class="d-flex flex-column align-items-end gap-1 flex-shrink-0">
                        ${s.status === 'inactive' ? '<span class="badge bg-soft-danger text-danger">Inactive</span>' : ''}
                        <span class="sr-item-mats" title="${mats} material${mats === 1 ? '' : 's'}">
                            <i class="feather-package"></i> ${mats}
                        </span>
                    </div>
                </div>
            </a>`;
        }).join('');
    }

    // ── Panel ────────────────────────────────────────────────────
    function formValues() {
        return {
            service_name:     $('sr_name').value.trim(),
            service_type:     $('sr_type').value,
            base_price:       $('sr_price').value.trim(),
            duration_minutes: $('sr_duration').value,
            description:      $('sr_description').value.trim(),
            chart_outcome:    $('sr_chart_outcome').value,
            status:           $('sr_status').value,
        };
    }
    function isDirty() {
        return !!savedForm && JSON.stringify(formValues()) !== JSON.stringify(savedForm);
    }
    function updateDirtyNote() {
        const dirty = isDirty();
        $('srDirtyNote').textContent = isNew ? 'New service — not saved yet'
                                     : (dirty ? 'You have unsaved changes' : 'No unsaved changes');
        $('srDirtyNote').classList.toggle('text-warning', dirty || isNew);
        $('srSaveBtn').disabled = saving || (!isNew && !dirty);
    }

    function fillForm(s) {
        $('sr_name').value        = s ? s.service_name : '';
        $('sr_type').value        = s ? s.service_type : 'Consultation';
        $('sr_price').value       = s ? Number(s.base_price).toFixed(2) : '';
        $('sr_duration').value    = String(s ? s.duration_minutes : 30);
        if (!$('sr_duration').value) $('sr_duration').value = '30';
        $('sr_description').value = s ? (s.description || '') : '';
        $('sr_chart_outcome').value = s ? (s.chart_outcome || '') : '';
        $('sr_status').value      = s ? s.status : 'active';
        $('srFormError').style.display = 'none';
        savedForm = formValues();
    }

    function renderHeader(s) {
        $('srPanelTitle').textContent = s ? s.service_name : 'New Service';
        $('srPanelSub').textContent   = s
            ? `${s.service_type} · ${peso(s.base_price)} · ${duration(s.duration_minutes)}`
            : 'Fill in the details, then save to add materials.';
        const badge = $('srPanelStatus');
        if (!s) {
            badge.className = 'badge bg-soft-primary text-primary';
            badge.textContent = 'New';
        } else {
            badge.className = 'badge ' + (s.status === 'active' ? 'bg-soft-success text-success' : 'bg-soft-danger text-danger');
            badge.textContent = s.status === 'active' ? 'Active' : 'Inactive';
        }
        $('srMatCount').textContent = s ? (Number(s.material_count) || 0) : 0;
    }

    function applyPermissions() {
        const lockOwnerFields = !IS_OWNER;
        $('sr_name').readOnly  = lockOwnerFields;
        $('sr_price').readOnly = lockOwnerFields;
        $('srOwnerNote').style.display = lockOwnerFields ? '' : 'none';
        $('srStatusRow').style.display = isNew ? 'none' : '';
        if ($('srDeleteBtn')) $('srDeleteBtn').style.display = isNew ? 'none' : '';
        $('srCancelBtn').textContent = isNew ? 'Discard' : 'Cancel';

        const matBtn = $('srTabMaterialsBtn');
        matBtn.disabled = isNew;
        matBtn.title    = isNew ? 'Save the service first to add materials' : '';
    }

    function showTab(which) {
        const btn = $(which === 'materials' ? 'srTabMaterialsBtn' : 'srTabDetailsBtn');
        if (window.bootstrap && bootstrap.Tab) bootstrap.Tab.getOrCreateInstance(btn).show();
    }

    function openPanel() {
        $('srEmpty').style.display = 'none';
        $('srPanel').style.display = '';
        // On narrow screens the panel sits below the list
        if (window.innerWidth < 992) $('srPanelCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function closePanel() {
        currentId = null;
        isNew = false;
        savedForm = null;
        $('srPanel').style.display = 'none';
        $('srEmpty').style.display = '';
        renderList();
    }

    // Ask before throwing away unsaved edits. Resolves true when it's OK to continue.
    function confirmDiscard() {
        if (!isNew && !isDirty()) return Promise.resolve(true);
        if (isNew && !formValues().service_name && !formValues().base_price) return Promise.resolve(true);
        return confirmAlert({
            tone: 'warn',
            icon: 'feather-edit-3',
            title: 'Discard changes?',
            html: 'Your unsaved changes to this service will be lost.',
            confirmText: 'Discard',
            cancelText: 'Keep Editing',
        });
    }

    function selectService(id) {
        if (!isNew && Number(id) === Number(currentId)) return;
        confirmDiscard().then(ok => {
            if (!ok) return;
            const s = findService(id);
            if (!s) return;
            currentId = s.service_id;
            isNew = false;
            renderHeader(s);
            fillForm(s);
            applyPermissions();
            updateDirtyNote();
            renderList();
            openPanel();
            srLoadMaterials();
        });
    }

    window.srNewService = function () {
        if (!IS_OWNER) return;
        confirmDiscard().then(ok => {
            if (!ok) return;
            currentId = null;
            isNew = true;
            renderHeader(null);
            fillForm(null);
            applyPermissions();
            showTab('details');
            updateDirtyNote();
            renderList();
            openPanel();
            $('sr_name').focus();
        });
    };

    window.srCancelEdit = function () {
        if (isNew) {
            confirmDiscard().then(ok => { if (ok) closePanel(); });
            return;
        }
        const s = findService(currentId);
        if (s) { fillForm(s); updateDirtyNote(); }
    };

    // ── Save / delete ────────────────────────────────────────────
    function showFormError(msg) {
        $('srFormError').textContent = msg;
        $('srFormError').style.display = 'block';
    }

    window.srSaveService = function () {
        if (saving) return;
        const v = formValues();
        $('srFormError').style.display = 'none';

        if (!v.service_name)                                   return showFormError('Service name is required.');
        if (v.base_price === '' || isNaN(v.base_price) || Number(v.base_price) < 0)
                                                               return showFormError('Please enter a valid base price (0 or more).');
        if (DATA.needsMigration && isNew)                      return showFormError('Run the service-durations SQL file in Supabase first, then refresh this page.');

        const payload = {
            action:       isNew ? 'add' : 'edit',
            service_type: v.service_type,
            description:  v.description,
            chart_outcome: v.chart_outcome,
        };
        // Until the SQL file is run there is no duration column to save into
        if (!DATA.needsMigration) payload.duration_minutes = parseInt(v.duration_minutes, 10);
        if (IS_OWNER) {
            payload.service_name = v.service_name;
            payload.base_price   = Number(v.base_price);
        }
        if (!isNew) {
            payload.service_id = currentId;
            payload.status     = v.status;
        }

        const btn = $('srSaveBtn');
        saving = true;
        btn.disabled  = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

        fetch('api/service_crud.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.message || 'Could not save the service.');
            const saved = res.service;
            const existing = findService(saved.service_id);
            if (existing) Object.assign(existing, saved, { material_count: existing.material_count });
            else services.push(Object.assign({ material_count: 0 }, saved));
            services.sort((a, b) => a.service_name.localeCompare(b.service_name));

            const wasNew = isNew;
            isNew = false;
            currentId = saved.service_id;

            // Keep the saved service visible even if the status filter would hide it
            const st = $('srFilterStatus').value;
            if (st && st !== saved.status) $('srFilterStatus').value = '';

            const s = findService(currentId);
            renderHeader(s);
            fillForm(s);
            applyPermissions();
            renderList();
            if (wasNew) srLoadMaterials();
            toast(wasNew ? 'Service Added' : 'Changes Saved',
                  wasNew ? 'The service was created. You can now add its materials.' : 'The service details were updated.',
                  saved.service_name);
        })
        .catch(err => showFormError(err.message || 'Network error. Please try again.'))
        .finally(() => {
            saving = false;
            btn.innerHTML = '<i class="feather-save me-1"></i> Save Changes';
            updateDirtyNote();
        });
    };

    window.srDeleteService = function () {
        const s = findService(currentId);
        if (!s || !IS_OWNER) return;
        confirmAlert({
            icon: 'feather-trash-2',
            title: 'Delete Service?',
            html: 'This service and its material list will be permanently removed. This cannot be undone.',
            chip: s.service_name,
            confirmText: 'Yes, Delete',
        }).then(ok => {
            if (!ok) return;
            fetch('api/service_crud.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', service_id: s.service_id }),
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    services = services.filter(x => Number(x.service_id) !== Number(s.service_id));
                    savedForm = null;
                    closePanel();
                    toast('Service Deleted', 'The service was removed.', s.service_name);
                } else if (res.in_use && s.status === 'active') {
                    confirmAlert({
                        tone: 'warn',
                        icon: 'feather-info',
                        title: "Can't Delete This Service",
                        html: esc(res.message),
                        chip: s.service_name,
                        confirmText: 'Deactivate Instead',
                        cancelText: 'Close',
                    }).then(ok => { if (ok) setStatus(s, 'inactive'); });
                } else {
                    errorAlert('Delete Failed', res.message || 'Could not delete the service.');
                }
            })
            .catch(() => errorAlert('Delete Failed', 'Network error. Please check your connection and try again.'));
        });
    };

    function setStatus(s, status) {
        fetch('api/service_crud.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'status', service_id: s.service_id, status }),
        })
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.message);
            s.status = status;
            const st = $('srFilterStatus').value;
            if (st && st !== status) $('srFilterStatus').value = '';
            if (Number(currentId) === Number(s.service_id)) {
                renderHeader(s);
                fillForm(s);
                updateDirtyNote();
            }
            renderList();
            toast(status === 'active' ? 'Service Activated' : 'Service Deactivated', res.message, s.service_name);
        })
        .catch(err => errorAlert('Update Failed', err.message || 'Network error. Please check your connection and try again.'));
    }

    // ── Materials (same API and behaviour as the old Service Materials page) ──
    function setMatCount(n) {
        const s = findService(currentId);
        if (s) s.material_count = n;
        $('srMatCount').textContent = n;
        renderList();
    }

    window.srLoadMaterials = function () {
        if (!currentId) return;
        const forId = currentId;
        $('srMatLoading').style.display = '';
        $('srMatError').style.display   = 'none';
        $('srMatWrap').style.display    = 'none';

        fetch(`api/service_materials.php?service_id=${encodeURIComponent(forId)}`)
            .then(r => r.json())
            .then(materials => {
                if (forId !== currentId) return; // another service was picked meanwhile
                $('srMatLoading').style.display = 'none';
                $('srMatWrap').style.display    = '';
                renderMaterials(Array.isArray(materials) ? materials : []);
            })
            .catch(() => {
                if (forId !== currentId) return;
                $('srMatLoading').style.display = 'none';
                $('srMatError').style.display   = '';
            });
    };

    function renderMaterials(materials) {
        const tbody = $('srMatBody');
        $('srMatNone').style.display = materials.length ? 'none' : '';
        setMatCount(materials.length);

        tbody.innerHTML = materials.map(mat => {
            const inv   = mat.inventory || {};
            const stock = parseInt(inv.stock_quantity ?? 0, 10) || 0;
            const stockBadge = stock === 0 ? 'bg-soft-danger text-danger'
                             : stock <= 5  ? 'bg-soft-warning text-warning'
                             :               'bg-soft-success text-success';
            const id = Number(mat.material_id);
            return `
            <tr id="mat_row_${id}">
                <td>
                    <div class="fw-semibold small">${esc(inv.item_name || '—')}</div>
                    <div class="text-muted fs-12">${esc(inv.item_code || '')}</div>
                </td>
                <td><span class="text-muted small">${esc(inv.category || '—')}</span></td>
                <td><span class="badge ${stockBadge}">${stock} in stock</span></td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <input type="number" class="sr-qty-input" value="${Number(mat.quantity) || 1}" min="1"
                               data-material-id="${id}" aria-label="Quantity to deduct">
                        <span class="text-muted small">units</span>
                    </div>
                </td>
                <td class="text-end">
                    <button type="button" class="sr-mat-remove" data-material-id="${id}"
                            data-name="${esc(inv.item_name || '')}" title="Remove">
                        <i class="feather-trash-2"></i>
                    </button>
                </td>
            </tr>`;
        }).join('');
    }

    window.srOpenAddMaterial = function () {
        const s = findService(currentId);
        if (!s) return;
        $('addMat_serviceName').textContent = s.service_name;
        $('addMat_item').value = '';
        $('addMat_qty').value = '1';
        $('addMat_stockPreview').textContent = '—';
        $('addMatError').style.display = 'none';
        bootstrap.Modal.getOrCreateInstance($('addMaterialModal')).show();
    };

    window.srSaveMaterial = function () {
        const errBox = $('addMatError');
        const btn    = $('addMatSaveBtn');
        const itemId = $('addMat_item').value;
        const qty    = parseInt($('addMat_qty').value, 10) || 0;

        errBox.style.display = 'none';
        if (!itemId)  { errBox.textContent = 'Please select an inventory item.'; errBox.style.display = 'block'; return; }
        if (qty < 1)  { errBox.textContent = 'Quantity must be at least 1.';     errBox.style.display = 'block'; return; }

        btn.disabled  = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

        fetch('api/service_materials.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ service_id: currentId, item_id: itemId, quantity: qty }),
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                bootstrap.Modal.getInstance($('addMaterialModal'))?.hide();
                const sel = $('addMat_item');
                const itemName = (sel.options[sel.selectedIndex]?.text || '').split('(')[0].trim();
                toast('Material Added', `${qty} unit${qty === 1 ? '' : 's'} will be deducted each time this service is completed.`, itemName);
                srLoadMaterials();
            } else {
                errBox.textContent = res.message || 'Failed to add material.';
                errBox.style.display = 'block';
            }
        })
        .catch(() => {
            errBox.textContent = 'Network error. Please try again.';
            errBox.style.display = 'block';
        })
        .finally(() => {
            btn.disabled  = false;
            btn.innerHTML = '<i class="feather-save me-1"></i> Save';
        });
    };

    function updateMaterialQty(input) {
        const materialId = input.dataset.materialId;
        let qty = parseInt(input.value, 10);
        if (!qty || qty < 1) { qty = 1; input.value = 1; }

        fetch(`api/service_materials.php?id=${encodeURIComponent(materialId)}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ quantity: qty }),
        })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                errorAlert('Update Failed', res.message || 'Could not update the quantity.');
                srLoadMaterials();
            }
        })
        .catch(() => errorAlert('Update Failed', 'Network error. Please check your connection and try again.'));
    }

    function deleteMaterial(materialId, itemName) {
        confirmAlert({
            icon: 'feather-package',
            title: 'Remove Material?',
            html: 'This item will no longer be deducted from inventory when this service is completed.',
            chip: itemName,
            confirmText: 'Yes, Remove',
        }).then(ok => {
            if (!ok) return;
            fetch(`api/service_materials.php?id=${encodeURIComponent(materialId)}`, { method: 'DELETE' })
                .then(r => r.json())
                .then(res => {
                    if (!res.success) throw new Error(res.message);
                    $(`mat_row_${materialId}`)?.remove();
                    const left = $('srMatBody').children.length;
                    $('srMatNone').style.display = left ? 'none' : '';
                    setMatCount(left);
                    toast('Material Removed', 'It was removed from this service.', itemName);
                })
                .catch(err => errorAlert('Remove Failed', err.message || 'Could not remove the material.'));
        });
    }

    // ── Wiring ───────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        renderList();

        $('srList').addEventListener('click', e => {
            const item = e.target.closest('.sr-item');
            if (item) selectService(item.dataset.id);
        });
        $('srSearch').addEventListener('input', renderList);
        $('srFilterType').addEventListener('change', renderList);
        $('srFilterStatus').addEventListener('change', renderList);

        // Every editable field enables Save Changes when it changes (chart outcome was missing)
        ['sr_name', 'sr_type', 'sr_price', 'sr_duration', 'sr_description', 'sr_chart_outcome', 'sr_status'].forEach(id => {
            $(id).addEventListener('input', updateDirtyNote);
            $(id).addEventListener('change', updateDirtyNote);
        });

        $('srMatBody').addEventListener('change', e => {
            if (e.target.classList.contains('sr-qty-input')) updateMaterialQty(e.target);
        });
        $('srMatBody').addEventListener('click', e => {
            const b = e.target.closest('.sr-mat-remove');
            if (b) deleteMaterial(b.dataset.materialId, b.dataset.name);
        });
        $('addMat_item').addEventListener('change', function () {
            const stock = this.options[this.selectedIndex]?.dataset.stock;
            $('addMat_stockPreview').textContent = stock !== undefined ? `${stock} units` : '—';
        });

        // Warn before leaving the page with unsaved edits
        window.addEventListener('beforeunload', e => {
            if (isDirty() && !saving) { e.preventDefault(); e.returnValue = ''; }
        });
    });
})();