/* =============================================================
   sw.js — EssenciaSmile Patient Portal service worker (Web Push).
   Registered by assets/js/portal-notify.js; its scope is this folder.
   Shows the notifications sent by controllers/notification_service.php
   and opens the portal when one is clicked. It does not cache pages,
   so patients always see live data.
   ============================================================= */
'use strict';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data ? event.data.text() : '' }; }
  const title = data.title || 'EssenciaSmile';
  event.waitUntil(self.registration.showNotification(title, {
    body: data.body || '',
    icon: 'assets/images/icons/icon-192.png',
    badge: 'assets/images/icons/badge-96.png',
    tag: data.tag || undefined,
    renotify: !!data.tag,
    data: { url: data.url || 'portal-dashboard.php' },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL((event.notification.data && event.notification.data.url) || 'portal-dashboard.php', self.registration.scope).href;
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
    for (const c of list) {
      if (c.url.startsWith(self.registration.scope) && 'focus' in c) {
        if ('navigate' in c) c.navigate(target);
        return c.focus();
      }
    }
    return self.clients.openWindow(target);
  }));
});

// The push service rotated this device's subscription: register the new one
self.addEventListener('pushsubscriptionchange', (event) => {
  const options = event.oldSubscription && event.oldSubscription.options;
  if (!options) return;
  event.waitUntil(self.registration.pushManager.subscribe(options).then((sub) =>
    fetch('api/portal_notification_settings.php', {
      method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'subscribe', subscription: sub.toJSON() }),
    })).catch(() => {}));
});
