/* =============================================================
   EssenciaSmile — Patient booking wizard
   File: assets/js/portal-web-scheduling.js
   Page: portal-web-scheduling.php
   Depends on: FullCalendar 6, SweetAlert2
   Data:  window.BOOKING (branches, services, today, …) from the page
   APIs:  api/get_booked_days.php · api/get_timeslots.php
          api/portal_book_appointment.php
   Steps: 1 Branch → 2 Services → 3 Date & Time → 4 Review
   ============================================================= */

(function () {
    'use strict';

    const CFG       = window.BOOKING || {};
    const BRANCHES  = Array.isArray(CFG.branches) ? CFG.branches : [];
    const SERVICES  = Array.isArray(CFG.services) ? CFG.services : [];
    const MAX_SVC   = CFG.maxServices || 3;
    const TODAY     = CFG.today;               // YYYY-MM-DD in Manila (from the server)
    const LAST_STEP = 4;

    const state = {
        step: 1,
        branchId: null,
        serviceIds: [],
        date: null,
        time: null,
        slots: [],          // slots of the selected date
        fullDays: new Set(),
        submitting: false,
    };

    let calendar = null;
    let timesReq = 0;       // ignore out-of-date responses
    let daysReq  = 0;

    const $ = id => document.getElementById(id);

    // ── Helpers ──────────────────────────────────────────────────
    function esc(v) {
        return String(v ?? '').replace(/[&<>"']/g, c => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
    function peso(n) {
        return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function lengthLabel(min) {
        const h = Math.floor(min / 60), m = min % 60;
        if (h && m) return `${h} hr ${m} min`;
        return h ? `${h} hr` : `${m} min`;
    }
    function timeLabel(hhmm) {
        const [h, m] = hhmm.split(':').map(Number);
        return `${(h % 12) || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
    }
    function addMinutes(hhmm, mins) {
        const [h, m] = hhmm.split(':').map(Number);
        const t = h * 60 + m + mins;
        return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`;
    }
    function dateLong(iso) {
        return new Date(iso + 'T00:00:00').toLocaleDateString('en-PH',
            { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    }
    function dateShort(iso) {
        return new Date(iso + 'T00:00:00').toLocaleDateString('en-PH',
            { weekday: 'short', month: 'short', day: 'numeric' });
    }
    function isoOf(d) {
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    }
    function addDays(iso, n) {
        const d = new Date(iso + 'T00:00:00');
        d.setDate(d.getDate() + n);
        return isoOf(d);
    }

    const branch      = () => BRANCHES.find(b => b.id === state.branchId) || null;
    const selected    = () => state.serviceIds.map(id => SERVICES.find(s => s.service_id === id)).filter(Boolean);
    const visitLength = () => selected().reduce((t, s) => t + (Number(s.duration_minutes) || 30), 0);
    const visitTotal  = () => selected().reduce((t, s) => t + (Number(s.base_price) || 0), 0);
    const openLength  = b => b ? b.closeMin - b.openMin : 0;

    // ── Step validity ────────────────────────────────────────────
    // Returns '' when the step is complete, otherwise what is missing.
    function missing(step) {
        const b = branch();
        switch (step) {
            case 1: return !b ? 'Choose a branch to continue.'
                         : (!b.active ? 'This branch is not taking online bookings.' : '');
            case 2:
                if (!state.serviceIds.length) return 'Choose at least one service.';
                if (visitLength() > openLength(b)) return 'Your visit is longer than the branch is open in a day.';
                return '';
            case 3:
                if (!state.date || dayState(state.date) !== 'open') return 'Choose a date.';
                return !state.time ? 'Choose a start time.' : '';
            default: return '';
        }
    }
    function furthestReachable() {
        let s = 1;
        while (s < LAST_STEP && !missing(s)) s++;
        return s;
    }

    // ── Navigation ───────────────────────────────────────────────
    function goTo(step, { scroll = true } = {}) {
        step = Math.max(1, Math.min(step, furthestReachable()));
        state.step = step;

        document.querySelectorAll('.bk-step').forEach(el => { el.hidden = Number(el.dataset.step) !== step; });
        if (step === 2) renderServices();
        if (step === 3) showCalendar();
        if (step === 4) renderReview();

        renderProgress();
        renderNav();
        if (scroll) {
            const top = document.querySelector('.bk-progress').getBoundingClientRect().top + window.scrollY - 12;
            if (window.scrollY > top) window.scrollTo({ top, behavior: 'smooth' });
        }
    }

    function renderProgress() {
        const reach = furthestReachable();
        document.querySelectorAll('#bkProgress li').forEach(li => {
            const n = Number(li.dataset.step);
            li.classList.toggle('is-current', n === state.step);
            li.classList.toggle('is-done', n < state.step || (n !== state.step && !missing(n) && n < reach));
            const btn = li.querySelector('button');
            btn.disabled = n > reach;
            btn.setAttribute('aria-current', n === state.step ? 'step' : 'false');
        });
    }

    function renderNav() {
        const gap = missing(state.step);
        $('bkBack').style.visibility = state.step === 1 ? 'hidden' : 'visible';
        $('bkHint').textContent = state.step < LAST_STEP ? gap : '';

        const next = $('bkNext');
        if (state.step === LAST_STEP) {
            next.innerHTML = state.submitting
                ? '<span class="bk-spinner"></span> Confirming…'
                : '<i class="fa-solid fa-check"></i> Confirm Booking';
            next.disabled = state.submitting;
            next.classList.add('bk-btn-confirm');
        } else {
            next.innerHTML = 'Next <i class="fa-solid fa-arrow-right"></i>';
            next.disabled = !!gap;
            next.classList.remove('bk-btn-confirm');
        }
        renderSummary();
    }

    // ── Step 1 · Branches ────────────────────────────────────────
    function renderBranches() {
        $('bkBranchList').innerHTML = BRANCHES.length ? BRANCHES.map(b => {
            const sel = b.id === state.branchId;
            return `
            <button type="button" class="bk-branch${sel ? ' is-selected' : ''}" data-id="${b.id}"
                    role="radio" aria-checked="${sel}" ${b.active ? '' : 'disabled'}>
                <span class="bk-branch-icon"><i class="fa-solid fa-location-dot"></i></span>
                <span class="bk-branch-body">
                    <span class="bk-branch-name">${esc(b.name)}</span>
                    <span class="bk-branch-addr">${esc(b.address)}</span>
                    <span class="bk-branch-hours">
                        <i class="fa-regular fa-clock"></i> ${esc(b.daysText)} · ${esc(b.hours)}
                    </span>
                    ${b.active ? '' : '<span class="bk-tag bk-tag-muted">Not taking online bookings</span>'}
                </span>
                <span class="bk-check"><i class="fa-solid fa-check"></i></span>
            </button>`;
        }).join('') : '<div class="bk-empty">No branches are available right now. Please try again later.</div>';
    }

    function chooseBranch(id) {
        if (id === state.branchId) return;
        const hadDate = !!state.date;
        state.branchId = id;
        // Hours and open days differ per branch
        state.date = null;
        state.time = null;
        state.slots = [];
        state.fullDays = new Set();
        renderBranches();
        if (calendar) refreshCalendar();
        renderNav();
        renderProgress();
        if (hadDate) toastInfo('Branch changed — please pick your date and time again.');
    }

    // ── Step 2 · Services ────────────────────────────────────────
    function renderServices() {
        const q = $('bkSvcSearch').value.trim().toLowerCase();
        const full = state.serviceIds.length >= MAX_SVC;
        const list = SERVICES.filter(s => !q
            || s.service_name.toLowerCase().includes(q)
            || (s.service_type || '').toLowerCase().includes(q)
            || (s.description || '').toLowerCase().includes(q));

        $('bkSvcList').innerHTML = list.length ? list.map(s => {
            const sel = state.serviceIds.includes(s.service_id);
            const off = !sel && full;
            return `
            <button type="button" class="bk-svc${sel ? ' is-selected' : ''}" data-id="${s.service_id}"
                    aria-pressed="${sel}" ${off ? 'disabled title="You can choose up to ' + MAX_SVC + ' services"' : ''}>
                <span class="bk-svc-top">
                    <span class="bk-svc-name">${esc(s.service_name)}</span>
                    <span class="bk-check"><i class="fa-solid fa-check"></i></span>
                </span>
                ${s.description ? `<span class="bk-svc-desc">${esc(s.description)}</span>` : ''}
                <span class="bk-svc-meta">
                    ${s.service_type ? `<span class="bk-tag">${esc(s.service_type)}</span>` : ''}
                    <span><i class="fa-regular fa-clock"></i> ${lengthLabel(Number(s.duration_minutes) || 30)}</span>
                    <span class="bk-svc-price">${Number(s.base_price) > 0 ? peso(s.base_price) : 'Price varies'}</span>
                </span>
            </button>`;
        }).join('') : `<div class="bk-empty">${SERVICES.length ? 'No services match your search.' : 'No services are available right now.'}</div>`;

        $('bkSvcCount').textContent = `${state.serviceIds.length} of ${MAX_SVC} selected`;
        $('bkSvcCount').classList.toggle('is-full', full);
        renderVisitBar();
    }

    function renderVisitBar() {
        const has = state.serviceIds.length > 0;
        $('bkVisitBar').hidden = !has;
        if (has) {
            $('bkVisitLength').textContent = lengthLabel(visitLength());
            $('bkVisitTotal').textContent  = peso(visitTotal());
        }
        const b = branch();
        const tooLong = has && b && visitLength() > openLength(b);
        $('bkSvcAlert').hidden = !tooLong;
        if (tooLong) {
            $('bkSvcAlert').innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i>
                Your visit (${lengthLabel(visitLength())}) is longer than ${esc(b.name)} is open in a day
                (${esc(b.hours)}). Please remove a service — the clinic can schedule the rest as a follow-up.`;
        }
    }

    function toggleService(id) {
        const i = state.serviceIds.indexOf(id);
        if (i >= 0) state.serviceIds.splice(i, 1);
        else if (state.serviceIds.length < MAX_SVC) state.serviceIds.push(id);
        else return;

        // A different visit length changes which times fit
        state.time = null;
        state.fullDays = new Set();
        if (calendar) { applyDayClasses(); fetchFullDays(); }
        if (state.date) state.slots = [];
        renderServices();
        renderNav();
        renderProgress();
    }

    // ── Step 3 · Calendar ────────────────────────────────────────
    const lastBookable = () => addDays(TODAY, 180); // about six months ahead

    function dayState(iso) {
        const b = branch();
        if (!b || iso <= TODAY || iso > lastBookable()) return 'past';
        const dow = new Date(iso + 'T00:00:00').getDay();
        if (!b.days.includes(dow)) return 'closed';
        if (state.fullDays.has(iso)) return 'full';
        return 'open';
    }

    function showCalendar() {
        const b = branch();
        $('bkDateIntro').textContent = b
            ? `${b.name} is open ${b.daysText}, ${b.hours}. Your visit takes about ${lengthLabel(visitLength())}.`
            : 'Choose an open day, then a start time.';

        if (!calendar) {
            calendar = new FullCalendar.Calendar($('bkCalendar'), {
                initialView: 'dayGridMonth',
                initialDate: state.date || addDays(TODAY, 1),
                headerToolbar: { left: 'prev', center: 'title', right: 'next' },
                fixedWeekCount: false,
                showNonCurrentDates: false,
                height: 'auto',
                firstDay: 0,
                validRange: { start: addDays(TODAY, 1).slice(0, 8) + '01', end: addDays(lastBookable(), 1) },
                dayCellDidMount: info => paintDay(info.el),
                datesSet: () => { applyDayClasses(); fetchFullDays(); },
                dateClick: info => chooseDate(info.dateStr),
            });
            calendar.render();
        } else {
            calendar.updateSize();
            applyDayClasses();
            fetchFullDays();
        }
        if (state.date && !state.slots.length) loadTimes();
        else renderTimes();
    }

    function refreshCalendar() {
        applyDayClasses();
        renderTimes();
    }

    const DAY_TITLES = { past: 'Not available for booking', closed: 'Clinic closed', full: 'Fully booked', open: '' };

    function paintDay(cell) {
        const iso = cell.getAttribute('data-date');
        if (!iso) return;
        const st = dayState(iso);
        cell.classList.remove('bk-day-past', 'bk-day-closed', 'bk-day-full', 'bk-day-open', 'bk-day-selected');
        cell.classList.add('bk-day-' + st);
        if (iso === state.date) cell.classList.add('bk-day-selected');
        cell.title = DAY_TITLES[st] || '';
        cell.setAttribute('aria-disabled', st === 'open' ? 'false' : 'true');
        if (DAY_TITLES[st]) cell.setAttribute('aria-label', dateLong(iso) + ' — ' + DAY_TITLES[st]);
        else cell.removeAttribute('aria-label');
    }

    function applyDayClasses() {
        document.querySelectorAll('#bkCalendar .fc-daygrid-day[data-date]').forEach(paintDay);
    }

    function fetchFullDays() {
        if (!calendar || !state.branchId || !state.serviceIds.length) return;
        const v = calendar.view;
        const start = isoOf(v.activeStart);
        const end   = isoOf(v.activeEnd);
        const req   = ++daysReq;
        const qs = new URLSearchParams({ start, end, branch: state.branchId, services: state.serviceIds.join(',') });

        fetch('api/get_booked_days.php?' + qs)
            .then(r => r.json())
            .then(data => {
                if (req !== daysReq) return;
                (data.fully_booked || []).forEach(d => state.fullDays.add(d));
                // The chosen day may have just filled up: drop it so it can't be submitted
                if (state.date && state.fullDays.has(state.date)) clearChosenDate();
                applyDayClasses();
            })
            .catch(() => { /* calendar still works; times are checked per day */ });
    }

    function chooseDate(iso) {
        // Only open days can be chosen — checked before any state changes
        const st = dayState(iso);
        if (st === 'past' || st === 'full') return;
        if (st === 'closed') {
            toastInfo(`${branch().name} is closed on ${new Date(iso + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'long' })}s.`);
            return;
        }
        if (iso === state.date) return;
        state.date = iso;
        state.time = null;
        state.slots = [];
        applyDayClasses();
        loadTimes();
        renderNav();
        renderProgress();
        // On phones the time list is below the calendar
        if (window.innerWidth < 900) $('bkTimes').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function clearChosenDate() {
        state.date = null;
        state.time = null;
        state.slots = [];
        timesReq++; // ignore a times request still in flight for that day
        renderTimes();
        renderNav();
        renderProgress();
    }

    // ── Step 3 · Times ───────────────────────────────────────────
    function loadTimes() {
        if (!state.date || !state.branchId) return renderTimes();
        const req = ++timesReq;
        $('bkTimesTitle').textContent = dateShort(state.date);
        $('bkTimes').innerHTML = '<div class="bk-empty"><span class="bk-spinner bk-spinner-dark"></span>Loading times…</div>';

        const qs = new URLSearchParams({ date: state.date, branch: state.branchId, services: state.serviceIds.join(',') });
        fetch('api/get_timeslots.php?' + qs)
            .then(r => r.json())
            .then(data => {
                if (req !== timesReq) return;
                if (data.error) throw new Error(data.error);
                state.slots = Array.isArray(data.slots) ? data.slots : [];
                if (state.time && !state.slots.some(s => s.time === state.time && s.available)) state.time = null;
                if (!state.slots.some(s => s.available) && !data.unavailable) {
                    state.fullDays.add(state.date);
                    applyDayClasses();
                }
                renderTimes();
                renderNav();
                renderProgress();
            })
            .catch(err => {
                if (req !== timesReq) return;
                $('bkTimes').innerHTML = `<div class="bk-empty bk-empty-error">
                    <i class="fa-solid fa-circle-exclamation"></i>${esc(err.message || 'Could not load times.')}
                    <button type="button" class="bk-link" data-retry-times>Try again</button></div>`;
            });
    }

    function renderTimes() {
        const box = $('bkTimes');
        if (!state.date) {
            $('bkTimesTitle').textContent = 'Available times';
            box.innerHTML = '<div class="bk-empty"><i class="fa-regular fa-calendar"></i>Select a date to see available times.</div>';
            return;
        }
        $('bkTimesTitle').textContent = dateShort(state.date);
        if (!state.slots.length) return; // still loading

        const avail = state.slots.filter(s => s.available);
        if (!avail.length) {
            box.innerHTML = `<div class="bk-empty"><i class="fa-regular fa-calendar-xmark"></i>
                No start times left that fit your ${lengthLabel(visitLength())} visit on this day.<br>Please choose another date.</div>`;
            return;
        }

        const groups = [['Morning', s => s.time < '12:00'], ['Afternoon', s => s.time >= '12:00']];
        let html = '';
        groups.forEach(([label, test]) => {
            const items = state.slots.filter(test);
            if (!items.length) return;
            html += `<div class="bk-time-group"><div class="bk-time-group-label">${label}</div><div class="bk-time-grid">`;
            html += items.map(s => {
                const sel = s.time === state.time;
                const why = s.reason === 'closing' ? 'Would end after closing time' : (s.reason === 'booked' ? 'Already booked' : '');
                return `<button type="button" class="bk-time${sel ? ' is-selected' : ''}${s.available ? '' : ' is-off'}"
                            data-time="${esc(s.time)}" ${s.available ? '' : `disabled title="${why}"`}
                            aria-pressed="${sel}">${esc(s.label)}</button>`;
            }).join('');
            html += '</div></div>';
        });

        if (state.time) {
            html += `<div class="bk-time-picked"><i class="fa-solid fa-circle-check"></i>
                ${timeLabel(state.time)} – ${timeLabel(addMinutes(state.time, visitLength()))}
                <span>(${lengthLabel(visitLength())})</span></div>`;
        } else {
            html += `<p class="bk-time-note">${avail.length} start time${avail.length === 1 ? '' : 's'} fit your
                ${lengthLabel(visitLength())} visit. Greyed-out times are booked or would end after closing.</p>`;
        }
        box.innerHTML = html;
    }

    function chooseTime(t) {
        const slot = state.slots.find(s => s.time === t);
        if (!slot || !slot.available) return;
        state.time = t;
        renderTimes();
        renderNav();
        renderProgress();
    }

    // ── Summary + review ─────────────────────────────────────────
    function summaryRows() {
        const b = branch(), svcs = selected();
        return [
            ['Branch', 'fa-location-dot', b ? `<strong>${esc(b.name)}</strong><span>${esc(b.address)}</span>` : '', 1],
            ['Services', 'fa-tooth', svcs.length ? svcs.map(s => `<span class="bk-sum-line"><span>${esc(s.service_name)}</span><span>${Number(s.base_price) > 0 ? peso(s.base_price) : '—'}</span></span>`).join('') : '', 2],
            ['Visit length', 'fa-hourglass-half', svcs.length ? lengthLabel(visitLength()) : '', 2],
            ['Date', 'fa-calendar', state.date ? esc(dateLong(state.date)) : '', 3],
            ['Time', 'fa-clock', state.time ? `${timeLabel(state.time)} – ${timeLabel(addMinutes(state.time, visitLength()))}` : '', 3],
        ];
    }

    function renderSummary() {
        const rows = summaryRows();
        $('bkSummary').innerHTML = rows.map(([label, icon, val]) => `
            <div class="bk-sum-row${val ? '' : ' is-empty'}">
                <dt><i class="fa-solid ${icon}"></i>${label}</dt>
                <dd>${val || 'Not chosen yet'}</dd>
            </div>`).join('') + (state.serviceIds.length ? `
            <div class="bk-sum-total"><span>Estimated total</span><strong>${peso(visitTotal())}</strong></div>` : '');
    }

    function renderReview() {
        $('bkSubmitAlert').hidden = true;
        $('bkReview').innerHTML = summaryRows().map(([label, icon, val, step]) => `
            <div class="bk-review-row">
                <span class="bk-review-icon"><i class="fa-solid ${icon}"></i></span>
                <div class="bk-review-body"><span class="bk-review-label">${label}</span><div>${val}</div></div>
                <button type="button" class="bk-link" data-edit-step="${step}">Edit</button>
            </div>`).join('') + `
            <div class="bk-review-total"><span>Estimated total</span><strong>${peso(visitTotal())}</strong></div>`;
    }

    // ── Submit ───────────────────────────────────────────────────
    function submit() {
        if (state.submitting) return;
        for (let s = 1; s < LAST_STEP; s++) {
            if (missing(s)) return goTo(s);
        }
        state.submitting = true;
        $('bkSubmitAlert').hidden = true;
        renderNav();

        fetch('api/portal_book_appointment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                branch_id:   state.branchId,
                date:        state.date,
                time:        state.time,
                service_ids: state.serviceIds,
                notes:       $('bkNotes').value.trim(),
            }),
        })
        .then(r => r.json())
        .then(data => {
            state.submitting = false;
            if (data.success) return onBooked(data);

            if (data.code === 'slot_taken') {
                state.time = null;
                state.slots = [];
                state.fullDays = new Set();
                goTo(3);
                loadTimes();
                return Swal.fire({
                    icon: 'warning',
                    iconHtml: '<i class="fa-solid fa-clock-rotate-left es-swal-glyph"></i>',
                    title: 'Time No Longer Available',
                    html: `<p class="es-swal-text">${esc(data.message || 'That time was just taken.')}</p>` +
                          '<p class="es-swal-note"><i class="fa-solid fa-circle-info"></i>' +
                          '<span>Your branch, services and date are kept — just choose another time.</span></p>',
                    confirmButtonText: '<i class="fa-solid fa-calendar-day"></i> Pick Another Time',
                    buttonsStyling: false,
                    customClass: ES_SWAL_TONE('warn'),
                });
            }
            if (data.code === 'CONTACT_UNVERIFIED') {
                // Dashboard reopens the "verify email or phone" modal
                window.location.href = 'portal-dashboard.php?verify_contact=1';
                return;
            }
            if (data.code === 'profile_incomplete' || data.code === 'auth') {
                return Swal.fire({
                    icon: 'info',
                    iconHtml: `<i class="fa-solid ${data.code === 'auth' ? 'fa-right-to-bracket' : 'fa-user-pen'} es-swal-glyph"></i>`,
                    title: 'Almost There',
                    html: `<p class="es-swal-text">${esc(data.message || '')}</p>`,
                    confirmButtonText: data.code === 'auth' ? 'Sign In' : 'Go to My Profile',
                    buttonsStyling: false,
                    customClass: ES_SWAL_CLASS,
                }).then(() => { window.location.href = data.code === 'auth' ? 'portal-login.php' : 'portal-dashboard.php'; });
            }
            showSubmitError(data.message || 'Booking failed. Please try again.');
        })
        .catch(() => {
            state.submitting = false;
            showSubmitError('Something went wrong. Please check your connection and try again.');
        })
        .finally(renderNav);
    }

    function showSubmitError(msg) {
        $('bkSubmitAlert').innerHTML = `<i class="fa-solid fa-circle-exclamation"></i> ${esc(msg)}`;
        $('bkSubmitAlert').hidden = false;
    }

    // Copy text; returns a Promise<boolean>. Falls back to execCommand on
    // plain-http LAN addresses where navigator.clipboard isn't available.
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(() => true, () => false);
        }
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
            document.body.appendChild(ta);
            ta.select();
            const ok = document.execCommand('copy');
            ta.remove();
            return Promise.resolve(ok);
        } catch (e) {
            return Promise.resolve(false);
        }
    }

    // Success popup — shown only after the server saved the appointment.
    // Every detail comes from the saved row returned by the API.
    function onBooked(data) {
        const apt      = data.appointment || {};
        const code     = data.appointment_code || '';
        const status   = String(apt.status || 'pending').toLowerCase();
        const date     = apt.date || state.date;
        const time     = apt.time || state.time;
        const b        = branch();
        const branchNm = apt.branch_name || (b ? b.name : '');
        const services = Array.isArray(apt.services) ? apt.services : [];

        const copy = {
            pending:   { title: 'Appointment Request Received!',
                         badge: 'Pending clinic confirmation.', tone: 'pending',
                         desc:  'Thank you! Your request has been sent to the clinic.',
                         next:  'The clinic will review your request and confirm your schedule. We\'ll notify you here once it\'s confirmed.' },
            confirmed: { title: 'Appointment Confirmed!',
                         badge: 'Confirmed', tone: 'confirmed',
                         desc:  'Your appointment is booked and confirmed.',
                         next:  'Please arrive about 10 minutes early. You can view or manage this booking in My Appointments.' },
        }[status] || { title: 'Appointment Saved',
                       badge: status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()), tone: 'pending',
                       desc:  'Your appointment has been saved.',
                       next:  'You can check its latest status in My Appointments.' };

        const row = (icon, label, value) => `
            <div class="bk-done-row">
                <span class="bk-done-row-icon"><i class="fa-solid ${icon}"></i></span>
                <span class="bk-done-row-label">${label}</span>
                <span class="bk-done-row-value">${value}</span>
            </div>`;

        const svcHtml = services.length
            ? `<div class="bk-done-services">
                   <span class="bk-done-row-label">${services.length > 1 ? `Services (${services.length})` : 'Service'}</span>
                   <div class="bk-done-chips">${services.map(s => `<span class="bk-done-chip">${esc(s)}</span>`).join('')}</div>
               </div>`
            : '';

        Swal.fire({
            html: `
                <div class="bk-done">
                    <div class="bk-done-icon" aria-hidden="true"><i class="fa-solid fa-check"></i></div>
                    <h2 class="bk-done-title" id="bkDoneTitle">${esc(copy.title)}</h2>
                    <p class="bk-done-desc">${esc(copy.desc)}</p>
                    <span class="bk-done-badge is-${copy.tone}"><span class="bk-done-dot"></span>${esc(copy.badge)}</span>

                    <div class="bk-done-card">
                        <div class="bk-done-ref">
                            <div>
                                <span class="bk-done-row-label">Booking reference</span>
                                <strong class="bk-done-code">${esc(code)}</strong>
                            </div>
                            ${code ? `<button type="button" class="bk-done-copy" id="bkDoneCopy" aria-label="Copy booking reference ${esc(code)}">
                                <i class="fa-regular fa-copy"></i><span>Copy</span></button>` : ''}
                        </div>
                        ${row('fa-location-dot', 'Branch', esc(branchNm || '—'))}
                        ${row('fa-calendar-day', 'Date', esc(date ? dateLong(date) : '—'))}
                        ${row('fa-clock', 'Time', esc(time ? timeLabel(time) : '—'))}
                        ${svcHtml}
                    </div>

                    <p class="bk-done-next"><i class="fa-solid fa-circle-info"></i><span>${esc(copy.next)}</span></p>
                </div>`,
            showConfirmButton: true,
            confirmButtonText: '<i class="fa-solid fa-calendar-check"></i> View My Appointments',
            showCancelButton: true,
            cancelButtonText: 'Book Another Appointment',
            buttonsStyling: false,
            reverseButtons: false,
            allowOutsideClick: false,
            allowEscapeKey: false,
            width: 'min(460px, calc(100vw - 24px))',
            customClass: {
                popup: 'bk-done-popup',
                htmlContainer: 'bk-done-html',
                actions: 'bk-done-actions',
                confirmButton: 'bk-done-btn bk-done-btn-primary',
                cancelButton: 'bk-done-btn bk-done-btn-outline',
            },
            showClass: { popup: 'bk-done-in' },
            didOpen: (popup) => {
                popup.setAttribute('aria-labelledby', 'bkDoneTitle');
                const btn = popup.querySelector('#bkDoneCopy');
                if (!btn) return;
                btn.addEventListener('click', () => {
                    copyText(code).then(ok => {
                        btn.classList.toggle('is-copied', ok);
                        btn.innerHTML = ok ? '<i class="fa-solid fa-check"></i><span>Copied</span>'
                                           : '<i class="fa-regular fa-copy"></i><span>Copy failed</span>';
                        setTimeout(() => {
                            btn.classList.remove('is-copied');
                            btn.innerHTML = '<i class="fa-regular fa-copy"></i><span>Copy</span>';
                        }, 1800);
                    });
                });
            },
        }).then(r => {
            if (r.isConfirmed) { window.location.href = 'portal-dashboard.php#appointments'; return; }
            // Start again with the same branch
            state.serviceIds = [];
            state.date = null;
            state.time = null;
            state.slots = [];
            state.fullDays = new Set();
            $('bkNotes').value = '';
            $('bkSvcSearch').value = '';
            if (calendar) applyDayClasses();
            goTo(2);
        });
    }

    function toastInfo(text) {
        Swal.fire({ toast: true, position: 'top', icon: 'info', title: text, timer: 2600, showConfirmButton: false });
    }

    // ── Wiring ───────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        if (!$('bkBranchList')) return; // profile-incomplete view

        renderBranches();

        $('bkBranchList').addEventListener('click', e => {
            const card = e.target.closest('.bk-branch');
            if (card && !card.disabled) chooseBranch(Number(card.dataset.id));
        });
        $('bkSvcList').addEventListener('click', e => {
            const card = e.target.closest('.bk-svc');
            if (card && !card.disabled) toggleService(Number(card.dataset.id));
        });
        $('bkSvcSearch').addEventListener('input', renderServices);

        $('bkTimes').addEventListener('click', e => {
            if (e.target.closest('[data-retry-times]')) return loadTimes();
            const btn = e.target.closest('.bk-time');
            if (btn && !btn.disabled) chooseTime(btn.dataset.time);
        });
        $('bkReview').addEventListener('click', e => {
            const btn = e.target.closest('[data-edit-step]');
            if (btn) goTo(Number(btn.dataset.editStep));
        });
        document.querySelectorAll('#bkProgress li').forEach(li => {
            li.querySelector('button').addEventListener('click', () => goTo(Number(li.dataset.step)));
        });

        $('bkBack').addEventListener('click', () => goTo(state.step - 1));
        $('bkNext').addEventListener('click', () => {
            if (state.step === LAST_STEP) return submit();
            if (!missing(state.step)) goTo(state.step + 1);
        });

        // Only one active branch? Choose it for the patient.
        const active = BRANCHES.filter(b => b.active);
        if (active.length === 1) state.branchId = active[0].id, renderBranches();

        goTo(1, { scroll: false });
    });
})();
/* =============================================================
   Close this page when the account signs out in another tab.
   - portal-logout.php broadcasts on every logout path (Sign Out,
     idle timeout, max-duration timeout) via BroadcastChannel and a
     localStorage "storage" event (fallback for older browsers).
   - The session is also re-checked when the tab regains focus and
     every 60s, which covers expiry and logout from another device.
   Browsers only let a script close tabs that a script opened, so if
   window.close() is blocked the page goes to the login screen instead.
   The booking API also rejects requests without a valid session.
   ============================================================= */
(function () {
    'use strict';
    let done = false;

    function signedOut() {
        if (done) return;
        done = true;
        document.body.style.pointerEvents = 'none';   // nothing can be booked from here on
        document.body.style.opacity = '.4';
        if (window.Swal) Swal.close();
        window.close();
        // Still open (tab not script-opened) → leave the booking flow
        setTimeout(() => location.replace('portal-login.php'), 300);
    }

    try {
        new BroadcastChannel('essencia-portal').addEventListener('message', e => {
            if (e.data && e.data.type === 'logout') signedOut();
        });
    } catch (e) { /* BroadcastChannel unsupported — storage event below covers it */ }

    window.addEventListener('storage', e => {
        if (e.key === 'essencia-portal-logout') signedOut();
    });

    function checkSession() {
        if (done || document.hidden) return;
        fetch('api/portal_auth.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'check_session' }),
        })
            .then(r => r.json())
            .then(d => { if (d && d.logged_in === false) signedOut(); })
            .catch(() => { /* network blip — try again later */ });
    }
    document.addEventListener('visibilitychange', checkSession);
    window.addEventListener('focus', checkSession);
    setInterval(checkSession, 60000);
})();
