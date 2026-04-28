window._applyFilters = function applyFilters(page, fieldMap) {
    const p = new URLSearchParams();
    for (const [key, elId] of Object.entries(fieldMap)) {
        const el = document.getElementById(elId);
        if (!el) continue;
        const val = el.value;
        if (val && val !== '0') p.set(key, val);
    }
    window.location.href = page + (p.toString() ? '?' + p.toString() : '');
};


function updateAppointmentStatus(appointmentId, newStatus, code) {
    const colors = {
        confirmed: '#1a7a5e',
        completed: '#0d6efd',
        cancelled: '#dc3545'
    };
    const labels = {
        confirmed: 'Confirm',
        completed: 'Mark as Completed',
        cancelled: 'Cancel'
    };

    Swal.fire({
        title: labels[newStatus] + ' Appointment?',
        html: `Appointment <strong>${code}</strong> will be set to <strong>${newStatus}</strong>.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: colors[newStatus],
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, proceed',
    }).then(r => {
        if (!r.isConfirmed) return;
        fetch('api/update_appointment_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ appointment_id: appointmentId, status: newStatus })
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Updated!',
                    text: `${code} is now ${newStatus}.`,
                    timer: 1800,
                    showConfirmButton: false
                }).then(() => location.reload());
            } else {
                Swal.fire('Error', d.message || 'Update failed.', 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'Could not connect to server.', 'error'));
    });
}


function confirmDelete(endpoint, payload, label, noun = 'record') {
    Swal.fire({
        title: `Delete ${noun}?`,
        html: `<strong>${label}</strong> will be permanently deleted.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete',
    }).then(r => {
        if (!r.isConfirmed) return;
        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...payload, action: 'delete' })
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Deleted!',
                    text: `${label} has been removed.`,
                    timer: 1600,
                    showConfirmButton: false
                }).then(() => location.reload());
            } else {
                Swal.fire('Error', d.message || 'Delete failed.', 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'Could not connect to server.', 'error'));
    });
}


/* ── Password Toggle (login page) ────────────────────────────── */
function togglePassword(inputId = 'passwordInput', iconId = 'eyeIcon') {
    const input   = document.getElementById(inputId);
    const icon    = document.getElementById(iconId);
    if (!input || !icon) return;
    const isHidden = input.type === 'password';
    input.type     = isHidden ? 'text' : 'password';
    icon.className = isHidden ? 'feather-eye-off text-muted' : 'feather-eye text-muted';
}