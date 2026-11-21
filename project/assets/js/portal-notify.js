/* =============================================================
   assets/js/portal-notify.js — Patient Portal notification settings
   + Web Push on this device.

   • Profile › Notifications card: Email / SMS / Push switches
     (api/portal_notification_settings.php).
   • Push permission is ONLY requested after the patient switches push
     on (or taps "Turn on" in the Appointments banner) — never on load.
   • iPhone / iPad: Web Push works only after "Add to Home Screen"
     (iOS 16.4+), so the switch explains that instead of failing.
   • The service worker (sw.js) shows the notifications.
   ============================================================= */
(function () {
  'use strict';
  const API = 'api/portal_notification_settings.php';
  const card = document.getElementById('pnCard');
  if (!card) return;
  const $ = (id) => document.getElementById(id);
  const sw = { email: $('pnEmail'), sms: $('pnSms'), push: $('pnPush') };
  const hint = $('pnHint');
  const BANNER_KEY = 'es.pn.banner.dismissed';

  const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const pushSupported = window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  let state = null, registration = null;

  const showHint = (html) => { hint.innerHTML = html; hint.hidden = !html; };
  const api = (body) => fetch(API, { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: body ? { 'Content-Type': 'application/json' } : {},
    body: body ? JSON.stringify(body) : undefined }).then((r) => r.json().catch(() => ({ success: false })).then((j) => ({ status: r.status, ...j })));
  const keyBytes = (b64) => { const s = (b64 + '='.repeat((4 - b64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/'); return Uint8Array.from(atob(s), (c) => c.charCodeAt(0)); };
  const busy = (el, on) => { el.setAttribute('aria-busy', String(on)); el.disabled = on || el.dataset.locked === '1'; };

  /** Why push can't be used here (or '' when it can). */
  function pushBlocker() {
    if (!state.push_available) return 'Push notifications are not available yet.';
    if (isIOS && !standalone) return '<i class="fa-solid fa-circle-info"></i>On iPhone or iPad: tap <b>Share</b> then <b>Add to Home Screen</b>, open EssenciaSmile from your Home Screen, and turn push on there (iOS 16.4 or later).';
    if (!pushSupported) return '<i class="fa-solid fa-circle-info"></i>This browser does not support push notifications. Email and SMS still work.';
    if (Notification.permission === 'denied') return '<i class="fa-solid fa-circle-info"></i>Notifications are blocked for this site. Allow them in your browser’s site settings, then turn push on again.';
    return '';
  }

  async function currentSubscription() {
    if (!pushSupported) return null;
    try { registration = registration || await navigator.serviceWorker.register('sw.js'); return await registration.pushManager.getSubscription(); }
    catch (e) { return null; }
  }

  async function render() {
    sw.email.checked = !!state.prefs.email;
    sw.sms.checked = !!state.prefs.sms;
    $('pnEmailNote').textContent = state.email_ready ? 'To your verified email address' : 'Verify your email address to receive emails';
    $('pnEmailNote').classList.toggle('warn', !state.email_ready);
    $('pnSmsNote').textContent = state.phone_ready ? 'Text messages to your mobile number' : 'Add a valid mobile number to receive SMS';
    $('pnSmsNote').classList.toggle('warn', !state.phone_ready);
    const blocker = pushBlocker();
    const sub = blocker ? null : await currentSubscription();
    sw.push.checked = !!(sub && state.prefs.push && state.endpoints.includes(sub.endpoint));
    sw.push.dataset.locked = blocker ? '1' : '';
    sw.push.disabled = !!blocker;
    showHint(blocker);
    card.hidden = false;
    renderBanner(blocker, sw.push.checked);
  }

  async function savePrefs(field, el) {
    busy(el, true);
    const r = await api({ action: 'save_prefs', [field]: el.checked });
    busy(el, false);
    if (!r.success) { el.checked = !el.checked; showHint('<i class="fa-solid fa-triangle-exclamation"></i>' + (r.message || 'Could not save. Please try again.')); return; }
    state.prefs = r.prefs || state.prefs;
    showHint(pushBlocker());
  }

  async function enablePush() {
    const perm = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
    if (perm !== 'granted') throw new Error(perm === 'denied' ? 'Notifications were blocked. You can allow them in your browser’s site settings.' : 'Notifications were not allowed.');
    registration = registration || await navigator.serviceWorker.register('sw.js');
    await navigator.serviceWorker.ready;
    let sub = await registration.pushManager.getSubscription();
    if (!sub) sub = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(state.vapid_public) });
    const r = await api({ action: 'subscribe', subscription: sub.toJSON() });
    if (!r.success) throw new Error(r.message || 'This device could not be registered.');
    state.prefs.push = true;
    state.endpoints = [...new Set([...state.endpoints, sub.endpoint])];
  }

  async function disablePush() {
    const sub = await currentSubscription();
    const endpoint = sub ? sub.endpoint : '';
    if (sub) { try { await sub.unsubscribe(); } catch (e) { /* already gone */ } }
    const r = await api({ action: 'unsubscribe', endpoint });
    if (!r.success) throw new Error(r.message || 'Could not turn push off.');
    state.endpoints = state.endpoints.filter((e) => e !== endpoint);
  }

  async function togglePush(on) {
    busy(sw.push, true);
    try {
      if (on) await enablePush(); else await disablePush();
      sw.push.checked = on;
      showHint(on ? '<i class="fa-solid fa-circle-check"></i>Push is on. You’ll get appointment updates on this device.' : '');
    } catch (e) {
      sw.push.checked = !on;
      showHint('<i class="fa-solid fa-triangle-exclamation"></i>' + (e && e.message ? e.message : 'Something went wrong. Please try again.'));
    }
    busy(sw.push, false);
    renderBanner(pushBlocker(), sw.push.checked);
  }

  // ── "Get reminders on this device" banner (Appointments page) ──
  function renderBanner(blocker, on) {
    let banner = $('pnBanner');
    let dismissed = false;
    try { dismissed = localStorage.getItem(BANNER_KEY) === '1'; } catch (e) { /* storage blocked */ }
    const show = !blocker && !on && Notification.permission === 'default' && !dismissed;
    if (!show) { if (banner) banner.hidden = true; return; }
    const page = $('page-appointments');
    if (!page) return;
    if (!banner) {
      banner = document.createElement('div');
      banner.id = 'pnBanner';
      banner.className = 'pn-banner';
      banner.setAttribute('role', 'region');
      banner.setAttribute('aria-label', 'Appointment reminders on this device');
      banner.innerHTML = '<span class="pn-banner-ico" aria-hidden="true"><i class="fa-solid fa-bell"></i></span>' +
        '<div class="pn-banner-txt"><strong>Get appointment reminders on this device</strong>We’ll let you know when an appointment is confirmed, changed or coming up, even when the portal is closed.</div>' +
        '<div class="pn-banner-actions"><button type="button" class="pn-btn pn-btn-primary" data-pn="on">Turn on</button><button type="button" class="pn-btn pn-btn-ghost" data-pn="later">Not now</button></div>';
      page.insertBefore(banner, page.firstElementChild);
      banner.addEventListener('click', (e) => {
        const b = e.target.closest('[data-pn]');
        if (!b) return;
        if (b.dataset.pn === 'later') { try { localStorage.setItem(BANNER_KEY, '1'); } catch (err) { /* ignore */ } banner.hidden = true; return; }
        togglePush(true);
      });
    }
    banner.hidden = false;
  }

  sw.email.addEventListener('change', () => savePrefs('email', sw.email));
  sw.sms.addEventListener('change', () => savePrefs('sms', sw.sms));
  sw.push.addEventListener('change', () => togglePush(sw.push.checked));

  api().then((r) => {
    if (!r.success) return;            // not set up yet (migration) → card stays hidden
    state = r.data;
    return render();
  }).catch(() => { /* offline: keep hidden */ });
})();
