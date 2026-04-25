/* =============================================================
   EssenciaSmile — Billing JS
   File: assets/js/billing.js
   ============================================================= */

let _currentInvoiceId   = null;
let _currentInvoiceCode = null;
let _currentBalance     = 0;

function openBillingDetails(invoiceId) {
    _currentInvoiceId = invoiceId;
    ['bv_code','bv_patient','bv_branch','bv_treatment','bv_date',
     'bv_total','bv_paid','bv_balance','bv_status'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = '';
    });
    document.getElementById('bv_payments_loading').style.display = '';
    document.getElementById('bv_payments_wrap').style.display    = 'none';
    new bootstrap.Modal(document.getElementById('billingViewModal')).show();

    fetch(`api/billing.php?id=${invoiceId}`)
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.message);
            const inv = res.data;
            _currentBalance     = parseFloat(inv.balance ?? 0);
            _currentInvoiceCode = inv.invoice_code;

            document.getElementById('bv_code').textContent      = inv.invoice_code             || '—';
            document.getElementById('bv_patient').textContent   = inv.patients?.full_name       || '—';
            document.getElementById('bv_branch').textContent    = inv.branches?.branch_name     || '—';
            document.getElementById('bv_treatment').textContent = inv.treatments?.treatment_code || '—';
            document.getElementById('bv_date').textContent      = inv.invoice_date              || '—';
            document.getElementById('bv_total').textContent     = _formatCost(inv.total_amount);
            document.getElementById('bv_paid').textContent      = _formatCost(inv.amount_paid);
            document.getElementById('bv_balance').textContent   = _formatCost(inv.balance);
            document.getElementById('bv_status').innerHTML      = _paymentBadge(inv.payment_status);

            const addBtn = document.getElementById('bv_add_payment_btn');
            if (addBtn) addBtn.style.display = inv.payment_status === 'paid' ? 'none' : '';

            _renderPaymentHistory(res.payments || []);
        })
        .catch(err => {
            document.getElementById('bv_payments_loading').style.display = 'none';
            Swal.fire('Error', err.message || 'Failed to load invoice.', 'error');
        });
}

function _renderPaymentHistory(payments) {
    document.getElementById('bv_payments_loading').style.display = 'none';
    document.getElementById('bv_payments_wrap').style.display    = '';
    const tbody   = document.getElementById('bv_payments_body');
    const noItems = document.getElementById('bv_no_payments');
    tbody.innerHTML = '';

    if (!payments || payments.length === 0) { noItems.style.display = ''; return; }
    noItems.style.display = 'none';

    const isOwner = window._isOwner || false;
    payments.forEach(p => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="small">${p.payment_date || '—'}</td>
            <td class="small fw-semibold text-success">${_formatCost(p.amount)}</td>
            <td class="small">${p.payment_method ? _capitalize(p.payment_method.replace(/_/g,' ')) : '—'}</td>
            <td class="small">${p.recorded_by || 'System'}</td>
            <td class="small text-muted">${p.notes || '—'}</td>
            ${isOwner ? `<td class="text-end">
                <button style="width:24px;height:24px;border:1px solid #dc3545;border-radius:4px;background:#fff;color:#dc3545;cursor:pointer;font-size:11px;padding-left:3px;padding-top:3px;"
                    onclick="deletePayment(${p.payment_id})"
                    onmouseover="this.style.background='#dc3545';this.style.color='#fff'"
                    onmouseout="this.style.background='#fff';this.style.color='#dc3545'">
                    <i class="feather-x"></i>
                </button>
            </td>` : '<td></td>'}`;
        tbody.appendChild(tr);
    });
}

function openAddPaymentFromView() {
    bootstrap.Modal.getInstance(document.getElementById('billingViewModal'))?.hide();
    setTimeout(() => openAddPaymentModal(_currentInvoiceId, _currentInvoiceCode, _currentBalance), 300);
}

function openAddPaymentModal(invoiceId, invoiceCode, balance) {
    _currentInvoiceId   = invoiceId;
    _currentInvoiceCode = invoiceCode;
    _currentBalance     = parseFloat(balance) || 0;

    document.getElementById('pm_invoice_id').value         = invoiceId;
    document.getElementById('pm_max_balance').value        = _currentBalance;
    document.getElementById('pm_invoice_code').textContent = invoiceCode;
    document.getElementById('pm_balance').textContent      = _formatCost(_currentBalance);
    document.getElementById('pm_amount').value             = '';
    document.getElementById('pm_method').value             = '';
    document.getElementById('pm_date').value               = new Date().toISOString().split('T')[0];
    document.getElementById('pm_recorded_by').value        = window._currentUser || '';
    document.getElementById('pm_notes').value              = '';
    document.getElementById('pm_amount_hint').textContent  = `Max payable: ${_formatCost(_currentBalance)}`;
    document.getElementById('pmModalError').style.display  = 'none';

    new bootstrap.Modal(document.getElementById('addPaymentModal')).show();
}

function setPaymentAmount(type) {
    const balance = parseFloat(document.getElementById('pm_max_balance').value) || 0;
    if (type === 'full') document.getElementById('pm_amount').value = balance.toFixed(2);
    if (type === 'half') document.getElementById('pm_amount').value = (balance / 2).toFixed(2);
}

function savePayment() {
    const errBox  = document.getElementById('pmModalError');
    const btn     = document.getElementById('pmSaveBtn');
    const amount  = parseFloat(document.getElementById('pm_amount').value) || 0;
    const balance = parseFloat(document.getElementById('pm_max_balance').value) || 0;
    errBox.style.display = 'none';

    if (amount <= 0)      { errBox.textContent = 'Please enter a valid amount.';                        errBox.style.display = 'block'; return; }
    if (amount > balance) { errBox.textContent = `Amount exceeds balance of ${_formatCost(balance)}.`; errBox.style.display = 'block'; return; }

    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    fetch('api/payments.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            invoice_id:     parseInt(document.getElementById('pm_invoice_id').value),
            amount,
            payment_method: document.getElementById('pm_method').value      || null,
            payment_date:   document.getElementById('pm_date').value,
            recorded_by:    window._currentUser || document.getElementById('pm_recorded_by').value || null,
            notes:          document.getElementById('pm_notes').value       || null,
        }),
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="feather-check me-1"></i> Record Payment';
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('addPaymentModal'))?.hide();
            Swal.fire({
                icon: 'success', title: 'Payment Recorded!',
                html: `Payment of <strong>${_formatCost(amount)}</strong> recorded.<br>
                       <span class="text-muted small">Balance: <strong>${_formatCost(res.new_balance)}</strong> · Status: <strong>${_capitalize(res.new_status)}</strong></span>`,
                timer: 2500, showConfirmButton: false,
            }).then(() => location.reload());
        } else {
            errBox.textContent   = res.message || 'Failed to record payment.';
            errBox.style.display = 'block';
        }
    })
    .catch(() => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="feather-check me-1"></i> Record Payment';
        errBox.textContent   = 'Network error. Please try again.';
        errBox.style.display = 'block';
    });
}

function deletePayment(paymentId) {
    Swal.fire({
        title: 'Remove Payment?', text: 'This will reverse the payment and update the invoice balance.',
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545', cancelButtonColor: '#6c757d', confirmButtonText: 'Yes, Remove',
    }).then(result => {
        if (!result.isConfirmed) return;
        fetch(`api/payments.php?id=${paymentId}`, { method: 'DELETE' })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Removed!', timer: 1500, showConfirmButton: false })
                        .then(() => openBillingDetails(_currentInvoiceId));
                } else {
                    Swal.fire('Error', res.message || 'Could not remove payment.', 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Network error.', 'error'));
    });
}

function deleteBilling(id, code) {
    Swal.fire({
        title: 'Delete Invoice?', html: `Delete invoice <strong>${code}</strong>? This cannot be undone.`,
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545', cancelButtonColor: '#6c757d', confirmButtonText: 'Yes, Delete',
    }).then(result => {
        if (!result.isConfirmed) return;
        fetch(`api/billing.php?id=${id}`, { method: 'DELETE' })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Deleted!', timer: 1500, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error', res.message || 'Could not delete invoice.', 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Network error.', 'error'));
    });
}

function _formatCost(amount) {
    if (amount === null || amount === undefined || amount === '') return '—';
    return '₱' + parseFloat(amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function _capitalize(str) { return str ? str.charAt(0).toUpperCase() + str.slice(1) : '—'; }
function _paymentBadge(status) {
    const map = { paid: 'bg-soft-success text-success', partial: 'bg-soft-warning text-warning', unpaid: 'bg-soft-danger text-danger' };
    return `<span class="badge ${map[status] || 'bg-soft-secondary text-secondary'}">${_capitalize(status || 'unpaid')}</span>`;
}