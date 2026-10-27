'use strict';

// -------- Util --------
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function parseUtc(s) { return new Date(s.replace(' ', 'T') + 'Z'); }
function fmtHMS(sec) {
  const sign = sec < 0 ? '-' : '';
  sec = Math.abs(sec);
  const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
  return h > 0 ? `${sign}${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}` : `${sign}${m}:${String(s).padStart(2,'0')}`;
}

// -------- Timer (server-authoritative) --------
async function initTimer() {
  const card = $('#timer-card');
  if (!card) return;
  const summary = JSON.parse(card.dataset.summary || '{}');
  if (!summary.active) return;

  const serverNowAtLoad = parseUtc(card.dataset.serverNow);
  const clientNowAtLoad = Date.now();
  const offsetMs = serverNowAtLoad.getTime() - clientNowAtLoad;
  const startedAtMs = parseUtc(summary.started_at).getTime();
  const schedEndMs = parseUtc(summary.scheduled_end).getTime();
  const elapsedEl = $('#elapsed');
  const cdEl = $('#countdown');
  const otEl = $('#overtime');
  const otRow = $('#overtime-row');
  const statusEl = $('#status-label');

  function tick() {
    const nowMs = Date.now() + offsetMs;
    const elapsedSec = Math.max(0, Math.floor((nowMs - startedAtMs) / 1000));
    const remainSec = Math.floor((schedEndMs - nowMs) / 1000);
    if (elapsedEl) elapsedEl.textContent = fmtHMS(elapsedSec);
    if (remainSec > 0) {
      if (cdEl) cdEl.textContent = fmtHMS(remainSec);
      if (otRow) otRow.hidden = true;
      if (statusEl) statusEl.textContent = remainSec <= 1800 ? 'Ending Soon' : 'On Shift';
    } else {
      if (cdEl) cdEl.textContent = '0:00';
      if (otRow) otRow.hidden = false;
      if (otEl) otEl.textContent = fmtHMS(-remainSec);
      if (statusEl) statusEl.textContent = 'Shift Over';
    }
  }
  tick();
  setInterval(tick, 1000);
}

// -------- Actions --------
async function postJson(url, body) {
  const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
  return r.json();
}

async function initActions() {
  const ack = $('#ack-ot');
  if (ack) ack.addEventListener('click', async () => {
    const shiftId = Number(ack.dataset.shift);
    await postJson('/api/shift/ack-overtime', { shift_id: shiftId });
    ack.disabled = true; ack.textContent = 'Overtime acknowledged';
  });
  const brk = $('#break-toggle');
  if (brk) brk.addEventListener('click', async () => {
    const card = $('#timer-card');
    const s = JSON.parse(card.dataset.summary || '{}');
    const res = await postJson('/api/shift/break-toggle', { shift_id: s.id });
    brk.textContent = res.running ? 'End Break' : 'Start Break';
  });
}

// -------- Web Push --------
function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(base64);
  return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
}

async function initPush() {
  const state = $('#push-state');
  if (!state) return;
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
    state.textContent = 'Push is not supported by this browser. Email reminders will still work.';
    return;
  }
  const reg = await navigator.serviceWorker.register('/sw.js');
  state.textContent = 'Service worker ready. Permission: ' + Notification.permission;

  $('#push-enable').addEventListener('click', async () => {
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') { state.textContent = 'Permission denied. Enable notifications in browser settings.'; return; }
    const r = await fetch('/api/state'); const j = await r.json();
    if (!j.vapid_public) { state.textContent = 'VAPID keys are not configured on the server.'; return; }
    const sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(j.vapid_public),
    });
    await fetch('/api/push/subscribe', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(sub) });
    state.textContent = 'Push enabled on this device.';
  });

  $('#push-test').addEventListener('click', async () => {
    const r = await postJson('/api/push/test');
    state.textContent = 'Test result: ' + JSON.stringify(r);
  });

  $('#push-disable').addEventListener('click', async () => {
    const sub = await reg.pushManager.getSubscription();
    if (sub) {
      await fetch('/api/push/unsubscribe', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: sub.endpoint }) });
      await sub.unsubscribe();
    }
    state.textContent = 'This device removed.';
  });
}

// -------- Offline indicator --------
function initOffline() {
  const el = $('#net-status'); if (!el) return;
  const update = () => { el.hidden = navigator.onLine; };
  window.addEventListener('online', update); window.addEventListener('offline', update); update();
}

// -------- Local alarm (app open only) --------
function initLocalAlarm() {
  const card = $('#timer-card'); if (!card) return;
  const summary = JSON.parse(card.dataset.summary || '{}');
  if (!summary.active) return;
  let lastState = null;
  setInterval(() => {
    const sched = parseUtc(summary.scheduled_end).getTime();
    const ms = Date.now();
    const remaining = sched - ms;
    const state = remaining > 0 && remaining <= 60000 ? 'ending' : (remaining <= 0 ? 'over' : null);
    if (state && state !== lastState) {
      lastState = state;
      if (Notification.permission === 'granted') {
        new Notification(state === 'over' ? 'Shift over' : 'Shift ending soon', {
          body: state === 'over' ? 'Scheduled end reached. Overtime is being recorded.' : '1 minute until scheduled end.',
          tag: 'sg-local-' + state,
        });
      }
    }
  }, 15000);
}

// -------- Boot --------
document.addEventListener('DOMContentLoaded', () => {
  initTimer();
  initActions();
  initPush();
  initOffline();
  initLocalAlarm();
});