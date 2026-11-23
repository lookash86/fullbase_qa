/* FULLBASE QA — rejestr błędów, braków i testów. Bez zewnętrznych bibliotek. */
(() => {
'use strict';

const S = { user: null, csrf: '', meta: null, issues: [], cases: null, runs: null, view: { mode: 'list' }, pendingFiles: [] };
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const app = () => $('#app');
const OPEN = ['new', 'confirmed', 'progress', 'reopened'];
const BOARD = [['new', 'Nowe'], ['confirmed', 'Potwierdzone'], ['progress', 'W naprawie'], ['reopened', 'Ponownie otwarte'], ['fixed', 'Do weryfikacji']];
const DEVICES = ['Komputer · Windows', 'Komputer · macOS', 'Telefon · Android', 'Telefon · iPhone', 'Tablet · Android', 'Tablet · iPad', 'Inne', 'Nie dotyczy'];
const BROWSERS = ['Chrome', 'Edge', 'Firefox', 'Safari', 'Samsung Internet', 'Opera', 'Aplikacja mobilna', 'Inna'];

/* ---------- narzędzia ---------- */
const store = {
  get(k, d = null) { try { const v = localStorage.getItem('fbqa.' + k); return v === null ? d : JSON.parse(v); } catch { return d; } },
  set(k, v) { try { localStorage.setItem('fbqa.' + k, JSON.stringify(v)); } catch { /* brak pamięci przeglądarki */ } },
};
function toast(msg, err = false) {
  const t = $('#toast'); t.textContent = msg; t.className = 'show' + (err ? ' err' : '');
  clearTimeout(toast.t); toast.t = setTimeout(() => t.className = '', err ? 5000 : 2600);
}
function fmtDate(s, withTime = true) {
  if (!s) return '—';
  const d = new Date(s.replace(' ', 'T'));
  return d.toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit', year: 'numeric' }) + (withTime ? ' ' + d.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' }) : '');
}
function ago(s) {
  if (!s) return '';
  const m = Math.round((Date.now() - new Date(s.replace(' ', 'T')).getTime()) / 60000);
  if (m < 1) return 'przed chwilą'; if (m < 60) return m + ' min temu';
  const h = Math.round(m / 60); if (h < 24) return h + ' godz. temu';
  const d = Math.round(h / 24); return d < 8 ? d + ' dni temu' : fmtDate(s, false);
}
const initials = n => String(n || '?').split(/\s+/).map(x => x[0]).slice(0, 2).join('').toUpperCase();
const fmtSize = b => b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
const badge = (cls, txt) => `<span class="badge ${cls}">${esc(txt)}</span>`;
const stB = s => badge('st-' + s, S.meta.statuses[s] || s);
const prB = p => badge('pr-' + p, S.meta.priorities[p] || p);
const svB = s => badge('sv-' + s, S.meta.severities[s] || s);
const rejectInfo = i => i.status === 'rejected' && i.reject_reason ? `<div class="sub" style="margin-top:4px">${esc(S.meta.reject_reasons[i.reject_reason] || '')}${i.duplicate_key ? ' ' + esc(i.duplicate_key) : ''}</div>` : '';
const CRIT = ['blocker', 'data']; // waga „krytyczna”: blokuje pracę albo psuje dane
const kdB = k => badge('kd-' + k, S.meta.kinds[k] || k);
const rsB = r => badge('rs-' + r, S.meta.results[r] || r);
const isAdmin = () => S.user?.role === 'admin';
const canWrite = () => ['admin', 'tester'].includes(S.user?.role);
const project = id => S.meta.projects.find(p => p.id === Number(id));
const activeProjects = () => S.meta.projects.filter(p => p.active);
const opt = (v, label, sel) => `<option value="${esc(v)}"${String(v) === String(sel ?? '') ? ' selected' : ''}>${esc(label)}</option>`;
const opts = (list, sel, empty) => (empty !== undefined ? opt('', empty, sel) : '') + list.map(x => Array.isArray(x) ? opt(x[0], x[1], sel) : opt(x, x, sel)).join('');
function guessDevice() {
  const u = navigator.userAgent;
  if (/iPad/.test(u)) return 'Tablet · iPad'; if (/iPhone/.test(u)) return 'Telefon · iPhone';
  if (/Android/.test(u)) return /Mobile/.test(u) ? 'Telefon · Android' : 'Tablet · Android';
  if (/Mac OS X/.test(u)) return 'Komputer · macOS'; if (/Windows/.test(u)) return 'Komputer · Windows'; return '';
}
function guessBrowser() {
  const u = navigator.userAgent;
  if (/SamsungBrowser/.test(u)) return 'Samsung Internet'; if (/Edg\//.test(u)) return 'Edge'; if (/OPR\//.test(u)) return 'Opera';
  if (/Firefox\//.test(u)) return 'Firefox'; if (/Chrome\//.test(u)) return 'Chrome'; if (/Safari\//.test(u)) return 'Safari'; return '';
}

/* ---------- API ---------- */
async function api(action, { body, params = {}, form, retry = true } = {}) {
  const qs = new URLSearchParams({ action, ...params });
  const post = body !== undefined || form !== undefined;
  const headers = { Accept: 'application/json' };
  if (post) headers['X-CSRF-Token'] = S.csrf;
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  let res;
  try {
    res = await fetch('api.php?' + qs, { method: post ? 'POST' : 'GET', credentials: 'same-origin', headers, body: form ?? (body !== undefined ? JSON.stringify(body) : undefined) });
  } catch { throw new Error('Brak połączenia z serwerem. Sprawdź internet i spróbuj ponownie.'); }
  const data = await res.json().catch(() => ({ error: 'Serwer zwrócił nieoczekiwaną odpowiedź (' + res.status + ').' }));
  if (res.status === 419 && retry) { const s = await api('session'); S.csrf = s.csrf; return api(action, { body, params, form, retry: false }); }
  if (res.status === 401 && data.login) { S.user = null; renderLogin('Sesja wygasła — zaloguj się ponownie.'); throw new Error('Zaloguj się ponownie.'); }
  if (res.status === 403 && data.must_change_pw) { S.user.must_change_pw = true; renderMustChange(); throw new Error(data.error); }
  if (!res.ok) throw new Error(data.error || 'Błąd serwera (' + res.status + ').');
  return data;
}
async function busy(btn, fn) {
  const old = btn?.innerHTML; if (btn) { btn.disabled = true; btn.innerHTML = 'Chwila…'; }
  try { return await fn(); } catch (e) { toast(e.message, true); } finally { if (btn && btn.isConnected) { btn.disabled = false; btn.innerHTML = old; } }
}
async function loadIssues() { S.issues = (await api('issues')).issues; }
async function loadCases(force = false) { if (!S.cases || force) S.cases = (await api('cases')).cases; }
async function loadRuns(force = false) { if (!S.runs || force) S.runs = (await api('runs')).runs; }
async function loadMeta() { S.meta = await api('meta'); }

/* ---------- start ---------- */
async function boot() {
  try {
    const s = await api('session');
    S.csrf = s.csrf; S.user = s.user;
    if (!S.user) return renderLogin();
    if (S.user.must_change_pw) return renderMustChange();
    await startApp();
  } catch (e) { renderLogin(e.message); }
}
async function startApp() {
  await Promise.all([loadMeta(), loadIssues()]);
  renderShell(); route();
}
window.addEventListener('hashchange', () => { if (S.user && S.meta) route(); });

/* ---------- logowanie ---------- */
function authFrame(inner) {
  document.body.className = 'authPage';
  app().innerHTML = `<main class="authCard"><div class="brand dark"><div class="brandmark">F</div><div class="brandname">FULLBASE<span>.</span><small>QA · REJESTR TESTÓW</small></div></div>${inner}</main>`;
}
function renderLogin(msg = '') {
  authFrame(`<h1>Zaloguj się</h1><p class="muted">Konto zakłada administrator systemu.</p>
    <form id="loginForm"><label>Login<input id="lgUser" autocomplete="username" autocapitalize="none" required></label>
    <label>Hasło<input id="lgPass" type="password" autocomplete="current-password" required></label>
    <div class="authError ${msg ? 'show' : ''}" id="lgErr" role="alert">${esc(msg)}</div>
    <button class="btn primary wide" type="submit">Zaloguj</button></form>`);
  $('#lgUser').focus();
  $('#loginForm').onsubmit = async e => {
    e.preventDefault();
    const btn = e.submitter, err = $('#lgErr'); err.className = 'authError'; btn.disabled = true;
    try {
      const r = await api('login', { body: { username: $('#lgUser').value, password: $('#lgPass').value } });
      S.user = r.user; S.csrf = r.csrf;
      if (S.user.must_change_pw) return renderMustChange();
      document.body.className = ''; await startApp();
    } catch (ex) { err.textContent = ex.message; err.className = 'authError show'; btn.disabled = false; }
  };
}
function renderMustChange() {
  authFrame(`<h1>Ustaw własne hasło</h1><p class="muted">Dostałeś hasło tymczasowe. Przed rozpoczęciem pracy ustaw własne — co najmniej 10 znaków.</p>
    <form id="pwForm"><label>Hasło tymczasowe<input id="pwCur" type="password" autocomplete="current-password" required></label>
    <label>Nowe hasło<input id="pwNew" type="password" minlength="10" autocomplete="new-password" required></label>
    <label>Powtórz nowe hasło<input id="pwNew2" type="password" minlength="10" autocomplete="new-password" required></label>
    <div class="authError" id="pwErr" role="alert"></div><button class="btn primary wide" type="submit">Zapisz i przejdź dalej</button>
    <button class="btn ghost wide" type="button" id="pwOut">Wyloguj</button></form>`);
  $('#pwOut').onclick = logout;
  $('#pwForm').onsubmit = async e => {
    e.preventDefault(); const err = $('#pwErr');
    if ($('#pwNew').value !== $('#pwNew2').value) { err.textContent = 'Nowe hasła nie są takie same.'; err.className = 'authError show'; return; }
    try { await api('password', { body: { current: $('#pwCur').value, new: $('#pwNew').value } }); S.user.must_change_pw = false; document.body.className = ''; toast('Hasło zapisane.'); await startApp(); }
    catch (ex) { err.textContent = ex.message; err.className = 'authError show'; }
  };
}
async function logout() { try { const r = await api('logout', { body: {} }); S.csrf = r.csrf; } catch { /* i tak wylogowujemy */ } S.user = null; S.cases = S.runs = null; location.hash = ''; renderLogin(); }

/* ---------- powłoka ---------- */
function renderShell() {
  document.body.className = '';
  const u = S.user;
  app().innerHTML = `
  <aside class="sidebar" id="sidebar">
    <div class="brand"><div class="brandmark">F</div><div class="brandname">FULLBASE<span>.</span><small>QA · REJESTR TESTÓW</small></div></div>
    <nav class="nav" id="nav">
      <div class="navlabel">Praca</div>
      <a href="#/pulpit" data-nav="pulpit"><span class="ico">◧</span>Pulpit</a>
      <a href="#/zgloszenia" data-nav="zgloszenia"><span class="ico">▦</span>Zgłoszenia<span class="count" id="cOpen"></span></a>
      <a href="#/weryfikacja" data-nav="weryfikacja"><span class="ico">✓</span>Do weryfikacji<span class="count hot" id="cFixed"></span></a>
      <a href="#/moje" data-nav="moje"><span class="ico">☺</span>Moje zgłoszenia</a>
      ${canWrite() ? '<a href="#/nowe" data-nav="nowe"><span class="ico">＋</span>Nowe zgłoszenie</a>' : ''}
      <div class="navlabel">Testy</div>
      <a href="#/przebiegi" data-nav="przebiegi"><span class="ico">▶</span>Przebiegi testów</a>
      <a href="#/scenariusze" data-nav="scenariusze"><span class="ico">☰</span>Scenariusze</a>
      ${isAdmin() ? `<div class="navlabel">Ustawienia</div>
      <a href="#/uzytkownicy" data-nav="uzytkownicy"><span class="ico">◎</span>Użytkownicy</a>
      <a href="#/projekty" data-nav="projekty"><span class="ico">◇</span>Projekty i obszary</a>` : ''}
    </nav>
    <div class="sideuser"><div class="avatar">${esc(initials(u.name))}</div><div class="who"><b>${esc(u.name)}</b><small>${esc(S.meta.roles[u.role])} · <a href="#/konto" style="color:#cfe0dd">konto</a></small></div><button id="outBtn">Wyloguj</button></div>
  </aside>
  <div class="scrim" id="scrim"></div>
  <div class="main"><div class="topbar"><button id="menuBtn" aria-label="Menu">☰</button><div class="brandname">FULLBASE<span>.</span> QA</div></div><div class="content" id="view"></div></div>`;
  $('#outBtn').onclick = logout;
  const toggle = open => { $('#sidebar').classList.toggle('open', open); $('#scrim').classList.toggle('show', open); };
  $('#menuBtn').onclick = () => toggle(true); $('#scrim').onclick = () => toggle(false);
  $('#nav').addEventListener('click', e => { if (e.target.closest('a')) toggle(false); });
  updateCounts();
}
function updateCounts() {
  const o = S.issues.filter(i => OPEN.includes(i.status)).length, f = S.issues.filter(i => i.status === 'fixed').length;
  const a = $('#cOpen'), b = $('#cFixed'); if (a) a.textContent = o || ''; if (b) { b.textContent = f || ''; b.style.display = f ? '' : 'none'; }
}
function setNav(key) { $$('#nav a').forEach(a => a.classList.toggle('active', a.dataset.nav === key)); }
function view(html) { const v = $('#view'); v.innerHTML = html; window.scrollTo(0, 0); return v; }
function parseHash() {
  const h = location.hash.replace(/^#\/?/, '');
  const [path, q] = h.split('?');
  return { parts: path.split('/').filter(Boolean), q: new URLSearchParams(q || '') };
}
async function route() {
  const { parts, q } = parseHash();
  const [r, id] = parts;
  try {
    // listy zawsze świeże — inni użytkownicy mogli coś zmienić w międzyczasie
    if ([undefined, 'pulpit', 'zgloszenia', 'weryfikacja', 'moje', 'projekty'].includes(r)) { await loadIssues(); updateCounts(); }
    switch (r) {
      case undefined: case 'pulpit': return await viewDashboard();
      case 'zgloszenia': return viewIssues('zgloszenia');
      case 'weryfikacja': return viewIssues('weryfikacja');
      case 'moje': return viewIssues('moje');
      case 'nowe': return await viewIssueForm(null, q);
      case 'z': return await viewIssue(Number(id));
      case 'edytuj': return await viewIssueForm(Number(id));
      case 'scenariusze': return await viewCases();
      case 'przebiegi': return await viewRuns();
      case 'przebieg': return await viewRun(Number(id));
      case 'uzytkownicy': return await viewUsers();
      case 'projekty': return viewProjects();
      case 'konto': return viewAccount();
      default: location.hash = '#/pulpit';
    }
  } catch (e) { view(`<div class="card empty"><b>Nie udało się wczytać widoku</b>${esc(e.message)}</div>`); }
}

/* ---------- pulpit ---------- */
async function viewDashboard() {
  setNav('pulpit');
  await loadRuns(true).catch(() => { S.runs = []; });
  const is = S.issues, cnt = f => is.filter(f).length;
  const week = Date.now() - 7 * 864e5;
  const openIs = is.filter(i => OPEN.includes(i.status));
  const byModule = {};
  openIs.forEach(i => { const k = (i.module || 'Bez obszaru') + ' · ' + i.project_code; byModule[k] = (byModule[k] || 0) + 1; });
  const mods = Object.entries(byModule).sort((a, b) => b[1] - a[1]).slice(0, 8), max = mods[0]?.[1] || 1;
  const byKind = Object.keys(S.meta.kinds).map(k => [k, openIs.filter(i => i.kind === k).length]);
  const recent = [...is].sort((a, b) => b.updated_at.localeCompare(a.updated_at)).slice(0, 8);
  const openRuns = S.runs.filter(r => r.status === 'open').slice(0, 4);
  const mineFixed = cnt(i => i.status === 'fixed' && i.reporter_id === S.user.id);
  view(`
  <div class="pagehead"><div><div class="eyebrow">Jakość systemu</div><h1>Dzień dobry, ${esc(S.user.name.split(' ')[0])}</h1>
    <p>${mineFixed ? `<b>${mineFixed}</b> Twoich zgłoszeń czeka na sprawdzenie poprawki.` : 'Uchwyć problem teraz — rozwiązanie przyjdzie we właściwym czasie.'}</p></div>
    <div class="actions">${canWrite() ? '<a class="btn primary" href="#/nowe">＋ Nowe zgłoszenie</a>' : ''}</div></div>
  <div class="stats">
    <a class="stat" href="#/zgloszenia"><small>Nowe i ponownie otwarte</small><b>${cnt(i => ['new', 'reopened'].includes(i.status))}</b></a>
    <a class="stat" href="#/zgloszenia"><small>Potwierdzone / w naprawie</small><b>${cnt(i => ['confirmed', 'progress'].includes(i.status))}</b></a>
    <a class="stat focus" href="#/weryfikacja"><small>Do weryfikacji</small><b>${cnt(i => i.status === 'fixed')}</b></a>
    <a class="stat ${cnt(i => OPEN.includes(i.status) && CRIT.includes(i.severity)) ? 'alert' : ''}" href="#/zgloszenia?severity=crit*" title="Waga: blokuje pracę albo psuje dane"><small>Krytyczne otwarte</small><b>${cnt(i => OPEN.includes(i.status) && CRIT.includes(i.severity))}</b></a>
    <a class="stat" href="#/zgloszenia?status=closed"><small>Zamknięte (7 dni)</small><b>${cnt(i => i.status === 'closed' && i.closed_at && new Date(i.closed_at.replace(' ', 'T')) > week)}</b></a>
  </div>
  <div class="grid2c">
    <div class="stack">
      <div class="card"><div class="section"><h3>Ostatnio zmienione</h3>${recent.length ? `<table><tbody>${recent.map(i => `<tr class="row" data-go="#/z/${i.id}"><td class="key">${esc(i.key)}</td><td><div class="ttl">${esc(i.title)}</div><div class="sub">${esc(i.module || '')} · ${ago(i.updated_at)}</div></td><td>${stB(i.status)}</td></tr>`).join('')}</tbody></table>` : '<div class="empty"><b>Jeszcze nic tu nie ma</b>Dodaj pierwsze zgłoszenie albo uruchom przebieg testów.</div>'}</div></div>
      <div class="card"><div class="section"><h3>Trwające przebiegi testów</h3>${openRuns.length ? openRuns.map(runMini).join('') : `<p class="muted small" style="margin:0">Brak otwartych przebiegów. <a href="#/przebiegi">Rozpocznij przebieg</a>, żeby przejść scenariusze krok po kroku.</p>`}</div></div>
    </div>
    <div class="stack">
      <div class="card"><div class="section"><h3>Otwarte wg obszaru</h3>${mods.length ? mods.map(([k, v]) => `<div class="barrow"><span>${esc(k)}</span><div class="bar"><i style="width:${Math.round(v / max * 100)}%"></i></div><b>${v}</b></div>`).join('') : '<p class="muted small" style="margin:0">Brak otwartych zgłoszeń.</p>'}</div></div>
      <div class="card"><div class="section"><h3>Otwarte wg rodzaju</h3>${byKind.map(([k, v]) => `<div class="barrow"><span>${kdB(k)}</span><div class="bar"><i style="width:${openIs.length ? Math.round(v / openIs.length * 100) : 0}%"></i></div><b>${v}</b></div>`).join('')}</div></div>
    </div>
  </div>`);
  bindRowLinks();
}
function runMini(r) {
  const done = r.pass + r.fail + r.blocked + r.skip, pc = x => r.total ? (x / r.total * 100) : 0;
  return `<a class="card runcard" href="#/przebieg/${r.id}" style="box-shadow:none"><div><div class="ttl">${esc(r.name)}</div><div class="sub">${esc(r.project_code)} · ${esc(r.environment || 'środowisko nieokreślone')}${r.version ? ' · ' + esc(r.version) : ''} · ${done}/${r.total} wykonano</div></div>
    <div><div class="progress"><i class="p-pass" style="width:${pc(r.pass)}%"></i><i class="p-fail" style="width:${pc(r.fail)}%"></i><i class="p-blocked" style="width:${pc(r.blocked)}%"></i><i class="p-skip" style="width:${pc(r.skip)}%"></i></div>
    <div class="sub">✓ ${r.pass} · ✗ ${r.fail} · ⛔ ${r.blocked}</div></div></a>`;
}
function bindRowLinks(root = document) { $$('[data-go]', root).forEach(el => el.onclick = e => { if (e.target.closest('a,button')) return; location.hash = el.dataset.go; }); }

/* ---------- lista zgłoszeń ---------- */
function viewIssues(kind) {
  setNav(kind);
  const { q } = parseHash();
  const saved = store.get('filters', {});
  const F = S.view.filters = {
    text: q.get('q') ?? '', project: q.get('project') ?? saved.project ?? '', status: q.get('status') ?? (kind === 'weryfikacja' ? 'fixed' : (kind === 'moje' ? '' : 'open*')),
    kind: q.get('kind') ?? '', role: q.get('role') ?? '', severity: q.get('severity') ?? '', priority: q.get('priority') ?? '', module: q.get('module') ?? '', who: kind === 'moje' ? 'me' : (q.get('who') ?? ''),
  };
  const titles = { zgloszenia: ['Rejestr', 'Zgłoszenia', 'Błędy, braki funkcji i usprawnienia zgłoszone przez zespół.'],
    weryfikacja: ['Weryfikacja', 'Do weryfikacji', 'Poprawki gotowe do sprawdzenia. Sprawdź i potwierdź albo otwórz ponownie z komentarzem.'],
    moje: ['Twoja praca', 'Moje zgłoszenia', 'Zgłoszenia, które dodałeś lub które są do Ciebie przypisane.'] }[kind];
  const mode = store.get('mode', 'list');
  const modules = [...new Set(S.meta.projects.filter(p => !F.project || p.id === Number(F.project)).flatMap(p => p.modules))];
  const roles = [...new Set([...S.meta.projects.filter(p => !F.project || p.id === Number(F.project)).flatMap(p => p.tested_roles || []), ...S.issues.map(i => i.sys_role).filter(Boolean)])];
  view(`
  <div class="pagehead"><div><div class="eyebrow">${titles[0]}</div><h1>${titles[1]}</h1><p>${titles[2]}</p></div>
    <div class="actions"><div class="seg"><button data-mode="list" class="${mode === 'list' ? 'on' : ''}">Lista</button><button data-mode="board" class="${mode === 'board' ? 'on' : ''}">Tablica</button></div>
    <a class="btn secondary" href="api.php?action=export" title="Pobierz wszystkie zgłoszenia (CSV do Excela)">↧ CSV</a>
    ${canWrite() ? '<a class="btn primary" href="#/nowe">＋ Nowe</a>' : ''}</div></div>
  <div class="filters">
    <input class="control search" id="fText" placeholder="Szukaj: numer, tytuł, obszar, osoba…" value="${esc(F.text)}">
    <select class="control" id="fProject">${opts(S.meta.projects.map(p => [p.id, p.code + ' · ' + p.name]), F.project, 'Każdy projekt')}</select>
    <select class="control" id="fStatus">${opts([['open*', 'Otwarte (wszystkie)'], ...Object.entries(S.meta.statuses)], F.status, 'Każdy status')}</select>
    <select class="control" id="fKind">${opts(Object.entries(S.meta.kinds), F.kind, 'Każdy rodzaj')}</select>
    <select class="control" id="fSeverity">${opts([['crit*', 'Krytyczne (blokuje / psuje dane)'], ...Object.entries(S.meta.severities)], F.severity, 'Każda waga')}</select>
    <select class="control" id="fPriority">${opts(Object.entries(S.meta.priorities), F.priority, 'Każdy priorytet')}</select>
    <select class="control" id="fModule">${opts(modules, F.module, 'Każdy obszar')}</select>
    <select class="control" id="fRole">${opts(roles, F.role, 'Każda rola')}</select>
    ${kind === 'moje' ? '' : `<select class="control" id="fWho">${opts([['me', 'Moje'], ['assigned', 'Przypisane do mnie']], F.who, 'Wszyscy')}</select>`}
  </div>
  <div id="issueList"></div>`);
  const bind = (id, key) => { const el = $('#' + id); if (!el) return; el.addEventListener(el.tagName === 'INPUT' ? 'input' : 'change', () => { F[key] = el.value; if (key === 'project') store.set('filters', { project: el.value }); drawIssueList(); }); };
  bind('fText', 'text'); bind('fProject', 'project'); bind('fStatus', 'status'); bind('fKind', 'kind'); bind('fSeverity', 'severity'); bind('fPriority', 'priority'); bind('fModule', 'module'); bind('fRole', 'role'); bind('fWho', 'who');
  $$('[data-mode]').forEach(b => b.onclick = () => { store.set('mode', b.dataset.mode); $$('[data-mode]').forEach(x => x.classList.toggle('on', x === b)); drawIssueList(); });
  drawIssueList();
}
function filteredIssues() {
  const F = S.view.filters, t = F.text.trim().toLowerCase();
  return S.issues.filter(i => {
    if (F.project && i.project_id !== Number(F.project)) return false;
    if (F.status === 'open*' ? !OPEN.includes(i.status) && i.status !== 'fixed' : F.status && i.status !== F.status) return false;
    if (F.kind && i.kind !== F.kind) return false;
    if (F.severity === 'crit*' ? !CRIT.includes(i.severity) : F.severity && i.severity !== F.severity) return false;
    if (F.priority && i.priority !== F.priority) return false;
    if (F.module && i.module !== F.module) return false;
    if (F.role && i.sys_role !== F.role) return false;
    if (F.who === 'me' && i.reporter_id !== S.user.id && i.assignee_id !== S.user.id) return false;
    if (F.who === 'assigned' && i.assignee_id !== S.user.id) return false;
    if (t && ![i.key, i.title, i.module, i.reporter_name, i.assignee_name, i.environment, i.version, i.sys_role, i.sys_account].join(' ').toLowerCase().includes(t)) return false;
    return true;
  });
}
const PRI = { critical: 0, high: 1, medium: 2, low: 3 };
const SEV = { blocker: 0, data: 1, annoying: 2, cosmetic: 3 };
function drawIssueList() {
  const list = filteredIssues(), box = $('#issueList'), mode = store.get('mode', 'list');
  if (mode === 'board') {
    box.innerHTML = `<div class="board">${BOARD.map(([st, label]) => { const items = list.filter(i => i.status === st).sort((a, b) => (SEV[a.severity] - SEV[b.severity]) || (PRI[a.priority] - PRI[b.priority]));
      return `<div class="col"><h4>${label}<span class="muted">${items.length}</span></h4>${items.map(i => `<div class="tile" data-go="#/z/${i.id}"><div class="key">${esc(i.key)}</div><div class="ttl">${esc(i.title)}</div><div class="meta">${svB(i.severity)}${prB(i.priority)}${i.kind !== 'bug' ? kdB(i.kind) : ''}${i.assignee_name ? `<span class="badge">→ ${esc(i.assignee_name.split(' ')[0])}</span>` : ''}</div></div>`).join('')}</div>`; }).join('')}</div>
      <p class="muted small">Tablica pokazuje zgłoszenia w toku. Zamknięte i odrzucone znajdziesz w widoku listy.</p>`;
    return bindRowLinks(box);
  }
  box.innerHTML = `<div class="tablewrap">${list.length ? `<table><thead><tr><th>Numer</th><th>Problem</th><th class="hide-m">Obszar</th><th>Waga / priorytet</th><th>Status</th><th class="hide-m">Zgłosił</th><th class="hide-m">Zmiana</th></tr></thead><tbody>
    ${list.map(i => `<tr class="row" data-go="#/z/${i.id}"><td class="key">${esc(i.key)}</td><td><div class="ttl">${esc(i.title)}</div><div class="sub">${i.kind !== 'bug' ? kdB(i.kind) + ' ' : ''}${i.attachments ? '📎 ' + i.attachments + ' ' : ''}${i.comments ? '💬 ' + i.comments + ' ' : ''}${i.sys_role || i.sys_account ? '👤 ' + esc([i.sys_role, i.sys_account].filter(Boolean).join(' · ')) + ' ' : ''}${i.assignee_name ? '→ ' + esc(i.assignee_name) : ''}${i.status === 'fixed' && i.fixed_in ? ' · sprawdzaj na ≥ ' + esc(i.fixed_in) : ''}</div></td>
      <td class="hide-m">${esc(i.module || '—')}<div class="sub">${esc(i.project_code)}</div></td><td>${svB(i.severity)}<div class="sub" style="margin-top:4px">${prB(i.priority)}</div></td><td>${stB(i.status)}${rejectInfo(i)}</td><td class="hide-m">${esc(i.reporter_name)}</td><td class="hide-m sub">${ago(i.updated_at)}</td></tr>`).join('')}
    </tbody></table>` : `<div class="empty"><b>Brak zgłoszeń dla tych filtrów</b>Zmień filtry${canWrite() ? ' albo <a href="#/nowe">dodaj nowe zgłoszenie</a>' : ''}.</div>`}
    <div class="listfoot"><span>${list.length} z ${S.issues.length}</span><span>Kliknij wiersz, aby otworzyć</span></div></div>`;
  bindRowLinks(box);
}

/* ---------- formularz zgłoszenia ---------- */
async function viewIssueForm(id, q = new URLSearchParams()) {
  setNav(id ? 'zgloszenia' : 'nowe');
  if (!canWrite()) { view('<div class="card empty"><b>Brak uprawnień</b>Konto obserwatora może tylko przeglądać i komentować.</div>'); return; }
  let x = null;
  if (id) x = (await api('issue', { params: { id } })).issue;
  const last = store.get('last', {});
  let caseInfo = null;
  if (!x && q.get('run') && q.get('case')) {
    const run = (await api('run', { params: { id: q.get('run') } })).run;
    caseInfo = run.results.find(r => r.case_id === Number(q.get('case')));
    if (caseInfo) x = { project_id: run.project_id, kind: 'bug', title: '', module: caseInfo.module, priority: caseInfo.priority, environment: run.environment, version: run.version,
      steps: [caseInfo.preconditions ? 'Warunki wstępne:\n' + caseInfo.preconditions : '', caseInfo.steps].filter(Boolean).join('\n\n'), expected: caseInfo.expected,
      description: caseInfo.note || '', _run: run.id, _case: caseInfo.case_id, _caseTitle: caseInfo.title };
  }
  const v = x || { project_id: last.project || activeProjects()[0]?.id, kind: 'bug', priority: 'medium', severity: 'annoying', environment: last.environment || '', version: last.version || '', sys_role: last.sys_role || '', sys_account: last.sys_account || '', device: guessDevice(), browser: guessBrowser(), url: q.get('url') || '' };
  const editing = !!(x && x.id);
  S.pendingFiles = [];
  const projectOptions = (editing && !isAdmin() ? S.meta.projects.filter(p => p.id === v.project_id) : S.meta.projects.filter(p => p.active || p.id === v.project_id)).map(p => [p.id, p.code + ' · ' + p.name]);
  const kindHelp = { bug: 'Coś działa źle', missing: 'Czegoś brakuje w systemie', improvement: 'Działa, ale może lepiej', question: 'Nie wiem, czy to błąd' };
  const users = S.meta.users.filter(u => u.active && u.role !== 'viewer');
  view(`
  <div class="pagehead"><div><div class="eyebrow">${editing ? esc(x.key) : 'Nowe zgłoszenie'}</div><h1>${editing ? 'Edycja zgłoszenia' : 'Co znalazłeś?'}</h1>
    <p>${caseInfo ? `Zgłoszenie ze scenariusza: <b>${esc(v._caseTitle)}</b> — kroki i oczekiwany wynik są już wpisane.` : 'Opisz konkretnie — dobre zgłoszenie da się odtworzyć bez dopytywania.'}</p></div></div>
  <form class="card pad" id="issueForm" novalidate>
    <div class="form">
      <div class="field full"><span class="lbl">Rodzaj zgłoszenia</span><div class="kindpick">${Object.entries(S.meta.kinds).map(([k, l]) => `<label><input type="radio" name="kind" value="${k}" ${v.kind === k ? 'checked' : ''}>${esc(l)}<small>${kindHelp[k]}</small></label>`).join('')}</div></div>
      <div class="field"><label for="fp">Projekt <i>*</i></label><select id="fp" name="project_id">${opts(projectOptions, v.project_id)}</select></div>
      <div class="field"><label for="fm">Obszar systemu</label><select id="fm" name="module"></select></div>
      <div class="field full"><label for="ft">Tytuł — krótko, co jest nie tak <i>*</i></label><input id="ft" name="title" maxlength="200" required value="${esc(v.title)}" placeholder="np. Po zmianie terminu meczu znika obsada sędziów"></div>
      <div class="field full"><label for="fd">Co się dzieje? (faktyczny wynik) <i>*</i></label><textarea id="fd" name="description" required placeholder="Co zobaczyłeś? Treść komunikatu błędu, co się nie zapisało, czego brakuje…">${esc(v.description)}</textarea></div>
      <div class="field"><label for="fs">Kroki do odtworzenia problemu</label><textarea id="fs" name="steps" placeholder="1. Zaloguj się jako…&#10;2. Wejdź w…&#10;3. Kliknij…">${esc(v.steps)}</textarea><span class="hint">Co klikałeś po kolei, aż pojawił się problem — tak, żeby ktoś inny mógł to powtórzyć.</span></div>
      <div class="field"><label for="fe">Czego się spodziewałeś? (oczekiwany wynik)</label><textarea id="fe" name="expected" placeholder="Co powinno się wydarzyć?">${esc(v.expected)}</textarea></div>
      <div class="field"><label for="fsv">Waga — jak bardzo przeszkadza</label><select id="fsv" name="severity">${opts(Object.entries(S.meta.severities), v.severity || 'annoying')}</select><span class="hint">Blokuje pracę: nie da się dokończyć czynności. Psuje dane: dane znikają, są złe albo widzi je ktoś niepowołany.</span></div>
      ${isAdmin() ? `<div class="field"><label for="fpr">Priorytet — kiedy naprawiamy</label><select id="fpr" name="priority">${opts(Object.entries(S.meta.priorities), v.priority)}</select><span class="hint">Ustawia administrator.</span></div>` : ''}
      <div class="field"><label for="fen">Środowisko</label><select id="fen" name="environment"></select></div>
      <div class="field"><label for="frole">Rola w testowanym systemie</label><select id="frole" name="sys_role"></select><span class="hint">Na jakiej roli byłeś zalogowany, gdy pojawił się problem.</span></div>
      <div class="field"><label for="facc">Konto testowe (bez hasła)</label><input id="facc" name="sys_account" maxlength="120" value="${esc(v.sys_account)}" placeholder="np. klub@pzball.test" autocomplete="off" autocapitalize="none"><span class="hint">Tylko nazwa konta. Nigdy nie wpisuj hasła.</span></div>
      <div class="field"><label for="fv">Wersja systemu (jeśli widać)</label><input id="fv" name="version" maxlength="60" value="${esc(v.version)}" placeholder="np. v0.8-demo"></div>
      <div class="field"><label for="fu">Adres strony</label><input id="fu" name="url" type="url" maxlength="1000" value="${esc(v.url)}" placeholder="https://…"></div>
      <div class="field"><label for="fdev">Urządzenie</label><select id="fdev" name="device">${opts(DEVICES, v.device, '—')}</select></div>
      <div class="field"><label for="fbr">Przeglądarka</label><select id="fbr" name="browser">${opts(BROWSERS, v.browser, '—')}</select></div>
      ${editing ? '' : `<div class="field full"><span class="lbl">Zrzuty ekranu i pliki</span>${dropZone()}</div>`}
      ${isAdmin() && editing ? `<div class="formsection field full"><h3>Naprawa (administrator)</h3></div>
      <div class="field"><label for="fa">Osoba naprawiająca</label><select id="fa" name="assignee_id">${opts(users.map(u => [u.id, u.name]), v.assignee_id, '— nieprzypisane —')}</select></div><div class="field"></div>
      <div class="field full"><label for="fsol">Rozwiązanie / co poprawiono</label><textarea id="fsol" name="solution" placeholder="Co zmieniono i jak sprawdzić poprawkę — tester przeczyta to przy weryfikacji.">${esc(v.solution)}</textarea></div>` : ''}
    </div>
    <div class="formbar"><a class="btn secondary" href="${editing ? '#/z/' + x.id : '#/zgloszenia'}">Anuluj</a><button class="btn primary" type="submit">${editing ? 'Zapisz zmiany' : 'Wyślij zgłoszenie'}</button></div>
  </form>`);
  const fillProject = () => {
    const p = project($('#fp').value) || { modules: [], environments: [], tested_roles: [] };
    const curM = $('#fm').value || v.module, curE = $('#fen').value || v.environment, curR = $('#frole').value || v.sys_role;
    const rs = [...(p.tested_roles || [])]; if (curR && !rs.includes(curR)) rs.push(curR);
    $('#frole').innerHTML = opts(rs, curR, '— wybierz —');
    const ms = [...p.modules]; if (curM && !ms.includes(curM)) ms.push(curM);
    const es = [...p.environments]; if (curE && !es.includes(curE)) es.push(curE);
    $('#fm').innerHTML = opts(ms, curM, '— wybierz —'); $('#fen').innerHTML = opts(es, curE, '—');
  };
  fillProject(); $('#fp').onchange = fillProject;
  if (!editing) bindDrop($('#view'));
  $('#ft').focus();
  $('#issueForm').onsubmit = async e => {
    e.preventDefault();
    const fd = new FormData(e.target), data = Object.fromEntries(fd.entries());
    if (!data.title.trim() || !data.description.trim()) return toast('Uzupełnij tytuł i opis „Co się dzieje?”.', true);
    data.project_id = Number(data.project_id);
    if (data.assignee_id !== undefined) data.assignee_id = data.assignee_id ? Number(data.assignee_id) : null;
    if (x?._run) { data.run_id = x._run; data.case_id = x._case; }
    await busy(e.submitter, async () => {
      let issue;
      if (editing) issue = (await api('issue_update', { params: { id: x.id }, body: data })).issue;
      else {
        issue = (await api('issue_create', { body: data })).issue;
        store.set('last', { project: data.project_id, environment: data.environment, version: data.version, sys_role: data.sys_role, sys_account: data.sys_account });
        if (S.pendingFiles.length) await uploadFiles(issue.id).catch(err => toast('Zgłoszenie zapisane, ale pliki nie: ' + err.message, true));
      }
      await loadIssues(); updateCounts();
      toast(editing ? 'Zapisano zmiany.' : `Dodano ${issue.key}. Dziękujemy!`);
      location.hash = x?._run && !editing ? '#/przebieg/' + x._run : '#/z/' + issue.id;
    });
  };
}
function dropZone() {
  return `<div class="drop" id="drop"><b>Wklej screenshot (Ctrl+V)</b>, przeciągnij pliki tutaj albo <label style="color:var(--teal);cursor:pointer;font-weight:700">wybierz z dysku<input type="file" id="fileIn" multiple hidden accept="image/*,.pdf,.txt,.log,.csv,.json,.mp4,.webm,.zip"></label>
    <div class="hint">Obrazy, PDF, logi, krótkie nagrania ekranu · do 10 plików · maks. ${fmtSize(S.meta.upload_max)} każdy</div><div class="pending" id="pending"></div></div>`;
}
function bindDrop(root, onChange) {
  const drop = $('#drop', root);
  const add = async files => {
    for (let f of files) {
      if (f.type.startsWith('image/') && f.type !== 'image/gif' && f.size > 1.5 * 1048576) f = await shrinkImage(f);
      if (f.size > S.meta.upload_max) { toast(`„${f.name}” jest za duży (${fmtSize(f.size)}).`, true); continue; }
      if (S.pendingFiles.length >= 10) { toast('Najwyżej 10 plików naraz.', true); break; }
      const named = f.name && f.name !== 'image.png' ? f : new File([f], 'zrzut-' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-') + '.' + ((f.type.split('/')[1] || 'png').replace('jpeg', 'jpg')), { type: f.type });
      S.pendingFiles.push(named);
    }
    drawPending(); onChange?.();
  };
  const drawPending = () => { $('#pending', root).innerHTML = S.pendingFiles.map((f, i) => `<span class="chipfile">${f.type.startsWith('image/') ? '🖼' : '📄'} ${esc(f.name)} · ${fmtSize(f.size)}<button type="button" data-rm="${i}" title="Usuń">×</button></span>`).join('');
    $$('[data-rm]', root).forEach(b => b.onclick = () => { S.pendingFiles.splice(Number(b.dataset.rm), 1); drawPending(); onChange?.(); }); };
  $('#fileIn', root).onchange = e => { add(e.target.files); e.target.value = ''; };
  drop.ondragover = e => { e.preventDefault(); drop.classList.add('over'); };
  drop.ondragleave = () => drop.classList.remove('over');
  drop.ondrop = e => { e.preventDefault(); drop.classList.remove('over'); add(e.dataTransfer.files); };
  document.onpaste = e => {
    if (!$('#drop')) { document.onpaste = null; return; }
    const files = [...(e.clipboardData?.items || [])].filter(it => it.kind === 'file').map(it => it.getAsFile()).filter(Boolean);
    if (files.length) { e.preventDefault(); add(files); toast('Dodano zrzut ekranu ze schowka.'); }
  };
}
/* Duże zdjęcia z telefonu zmniejszamy w przeglądarce (maks. 2400 px, JPEG), żeby zmieściły się w limicie serwera. */
async function shrinkImage(file) {
  try {
    const bmp = await createImageBitmap(file), k = Math.min(1, 2400 / Math.max(bmp.width, bmp.height));
    const c = document.createElement('canvas'); c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
    c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
    const blob = await new Promise(r => c.toBlob(r, 'image/jpeg', 0.85));
    return blob && blob.size < file.size ? new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }) : file;
  } catch { return file; }
}
async function uploadFiles(issueId) {
  const fd = new FormData(); S.pendingFiles.forEach(f => fd.append('files[]', f, f.name));
  await api('upload', { params: { id: issueId }, form: fd }); S.pendingFiles = [];
}

/* ---------- szczegóły zgłoszenia ---------- */
async function viewIssue(id) {
  setNav('zgloszenia');
  const x = (await api('issue', { params: { id } })).issue;
  const me = S.user, admin = isAdmin(), tester = me.role === 'tester';
  const canEdit = admin || (tester && x.reporter_id === me.id && ['new', 'reopened'].includes(x.status));
  const canVerify = (admin || tester) && x.status === 'fixed';
  const canReopen = (admin || tester) && ['closed', 'rejected'].includes(x.status);
  const sysIcon = { created: '＋', status: '⇄', field: '✎', attachment: '📎', verify: '✓' };
  const timeline = x.activity.map(a => a.kind === 'comment'
    ? `<div class="ev comment"><div class="avatar">${esc(initials(a.user_name))}</div><div><small><b>${esc(a.user_name)}</b> · ${fmtDate(a.created_at)}</small><div class="msg">${esc(a.message)}</div></div></div>`
    : `<div class="ev sys ev-${a.kind}"><div class="avatar" style="background:#f0f3f2">${sysIcon[a.kind] || '•'}</div><div><small><b>${esc(a.user_name)}</b> · ${fmtDate(a.created_at)}</small><div class="msg">${esc(a.message)}</div></div></div>`).join('');
  const files = x.files.map(f => { const url = 'api.php?action=file&id=' + f.id, img = f.mime.startsWith('image/');
    return `<div class="file"><a href="${url}" target="_blank" rel="noopener">${img ? `<img src="${url}" alt="${esc(f.orig_name)}" loading="lazy">` : `<div class="ficon">${f.mime.startsWith('video/') ? '▶' : '📄'}</div>`}<div class="fname" title="${esc(f.orig_name)} · ${esc(f.user_name)}">${esc(f.orig_name)}</div></a>
    ${admin || f.user_id === me.id ? `<button class="del" data-delfile="${f.id}" title="Usuń plik">×</button>` : ''}</div>`; }).join('');
  const block = (t, v) => v ? `<div class="section"><h3>${t}</h3><div class="prose">${esc(v)}</div></div>` : '';
  view(`
  <div class="dhead"><a href="#/zgloszenia" class="small">← Wszystkie zgłoszenia</a>
    <div class="key" style="margin-top:10px">${esc(x.key)} · ${esc(x.project_name)}</div><h1>${esc(x.title)}</h1>
    <div class="badges">${stB(x.status)} ${svB(x.severity)} ${prB(x.priority)} ${kdB(x.kind)} ${x.module ? `<span class="badge">${esc(x.module)}</span>` : ''}</div></div>
  <div class="detail">
    <div class="stack">
      ${canVerify ? `<div class="verify"><b>Poprawka czeka na weryfikację</b><p>${x.fixed_in ? `Sprawdzaj na wersji <b>≥ ${esc(x.fixed_in)}</b> (wersję widać w stopce systemu). ` : ''}Sprawdź na środowisku${x.environment ? ' <b>' + esc(x.environment) + '</b>' : ''}, czy problem zniknął.${x.solution ? ' Opis poprawki znajdziesz niżej.' : ''}</p>
        <div class="actions"><button class="btn ok" data-st="closed">✓ Działa — zamknij</button><button class="btn bad" data-st="reopened">✗ Nadal nie działa</button></div></div>` : ''}
      <div class="card">
        ${block('Co się dzieje', x.description)}${block('Kroki do odtworzenia problemu', x.steps)}${block('Oczekiwany wynik', x.expected)}
        ${x.solution ? `<div class="section" style="background:#f7fbf9"><h3>Rozwiązanie</h3><div class="prose">${esc(x.solution)}</div></div>` : ''}
        <div class="section"><h3>Załączniki ${x.files.length ? '(' + x.files.length + ')' : ''}</h3>${files ? `<div class="files">${files}</div>` : '<p class="muted small" style="margin:0 0 10px">Brak załączników.</p>'}
          ${canWrite() ? `<div style="margin-top:12px">${dropZone()}<div class="actions" style="margin-top:8px;justify-content:flex-end"><button class="btn soft sm" id="upBtn" hidden>Wyślij pliki</button></div></div>` : ''}</div>
      </div>
      <div class="card"><div class="section"><h3>Historia i komentarze</h3><div class="timeline">${timeline}</div>
        <form id="cForm" style="margin-top:16px"><div class="field"><textarea id="cText" maxlength="20000" placeholder="Dodaj komentarz, wynik ponownego testu, dodatkowe informacje…" required></textarea></div>
        <div class="actions" style="justify-content:flex-end;margin-top:8px"><button class="btn primary sm" type="submit">Dodaj komentarz</button></div></form></div></div>
    </div>
    <div class="stack">
      <div class="card pad"><div class="kv">
        <div><small>Zgłosił/a</small><span>${esc(x.reporter_name)}</span><div class="sub">${fmtDate(x.created_at)}</div></div>
        <div><small>Naprawia</small><span>${esc(x.assignee_name || '—')}</span></div>
        <div><small>Rola i konto w testowanym systemie</small><span>${esc([x.sys_role, x.sys_account].filter(Boolean).join(' · ') || '—')}</span></div>
        <div><small>Środowisko</small><span>${esc(x.environment || '—')}${x.version ? ' · ' + esc(x.version) : ''}</span></div>
        ${x.fixed_in ? `<div><small>Naprawiono w wersji</small><span>${esc(x.fixed_in)}</span></div>` : ''}
        <div><small>Urządzenie / przeglądarka</small><span>${esc([x.device, x.browser].filter(Boolean).join(' · ') || '—')}</span></div>
        ${x.url ? `<div><small>Adres</small><span><a href="${esc(x.url)}" target="_blank" rel="noopener noreferrer">${esc(x.url)}</a></span></div>` : ''}
        ${x.status === 'rejected' && x.reject_reason ? `<div><small>Powód odrzucenia</small><span>${esc(S.meta.reject_reasons[x.reject_reason])}${x.duplicate_of ? ` — oryginał: <a href="#/z/${x.duplicate_of}">${esc(x.duplicate_key || '#' + x.duplicate_of)}</a>` : ''}</span></div>` : ''}
        ${x.duplicates.length ? `<div><small>Duplikaty tego zgłoszenia</small>${x.duplicates.map(d => `<span><a href="#/z/${d.id}">${esc(d.key)}</a> — ${esc(d.title)}</span>`).join('<br>')}</div>` : ''}
        ${x.regression_case ? `<div><small>Scenariusz regresji</small><span><a href="#/scenariusze">TC-${String(x.regression_case).padStart(3, '0')}</a></span></div>` : ''}
        ${x.test_links.length ? `<div><small>Z przebiegu testów</small>${x.test_links.map(t => `<span><a href="#/przebieg/${t.run_id}">${esc(t.run_name)}</a> — ${esc(t.case_title)}</span>`).join('<br>')}</div>` : ''}
        <div><small>Ostatnia zmiana</small><span>${fmtDate(x.updated_at)}</span></div>
      </div></div>
      ${admin ? `<div class="card pad"><h3>Status (administrator)</h3><div class="field"><select id="stSel">${opts(Object.entries(S.meta.statuses), x.status)}</select></div>
        <button class="btn primary sm wide" id="stBtn" style="margin-top:8px">Zmień status</button>
        <p class="hint" style="margin:8px 0 0">„Do weryfikacji” ustawiaj dopiero po wgraniu poprawki na demo — z numerem wersji. Tester potwierdzi albo otworzy ponownie.</p></div>` : ''}
      <div class="actions"><button class="btn secondary" id="mdBtn" title="Kopiuje zgłoszenie w Markdownie — do wklejenia w Claude Code">⧉ Kopiuj dla programisty</button>${canEdit ? `<a class="btn secondary" href="#/edytuj/${x.id}">✎ Edytuj</a>` : ''}${canReopen ? '<button class="btn secondary" data-st="reopened">↺ Otwórz ponownie</button>' : ''}${canWrite() && x.status === 'closed' && !x.regression_case ? '<button class="btn soft" id="regBtn" title="Scenariusz z tymi samymi krokami, żeby błąd nie wrócił">＋ Utwórz scenariusz regresji</button>' : ''}
        ${admin ? '<button class="btn danger" id="delBtn">Usuń</button>' : ''}</div>
    </div>
  </div>`);
  const reload = async msg => { await loadIssues(); updateCounts(); if (msg) toast(msg); await viewIssue(id); };
  $$('[data-st]').forEach(b => b.onclick = () => changeStatus(x, b.dataset.st, reload));
  $('#regBtn') && ($('#regBtn').onclick = e => busy(e.currentTarget, () => createRegression(x, reload)));
  $('#mdBtn').onclick = async () => {
    const md = issueMarkdown(x);
    if (await copyText(md)) return toast('Skopiowano ' + x.key + ' — wklej w Claude Code.');
    modal('Skopiuj ręcznie', `<div class="field"><textarea id="mdText" style="min-height:300px;font-family:monospace;font-size:12px">${esc(md)}</textarea></div>`, null, null, true);
    $('#mdText').select();
  };
  $('#stBtn') && ($('#stBtn').onclick = () => changeStatus(x, $('#stSel').value, reload));
  $('#cForm').onsubmit = async e => { e.preventDefault(); const t = $('#cText').value.trim(); if (!t) return;
    await busy(e.submitter, async () => { await api('comment', { params: { id }, body: { message: t } }); await reload('Dodano komentarz.'); }); };
  if ($('#drop')) { S.pendingFiles = []; bindDrop($('#view'), () => { $('#upBtn').hidden = !S.pendingFiles.length; });
    $('#upBtn').onclick = e => busy(e.currentTarget, async () => { await uploadFiles(id); await reload('Dodano pliki.'); }); }
  $$('[data-delfile]').forEach(b => b.onclick = async e => { e.preventDefault(); if (!confirm('Usunąć ten plik?')) return;
    await busy(b, async () => { await api('file_delete', { params: { id: b.dataset.delfile }, body: {} }); await reload('Usunięto plik.'); }); });
  $('#delBtn') && ($('#delBtn').onclick = async e => { if (!confirm(`Usunąć ${x.key} na stałe razem z komentarzami i plikami? Tego nie da się cofnąć.`)) return;
    await busy(e.currentTarget, async () => { await api('issue_delete', { params: { id }, body: {} }); await loadIssues(); updateCounts(); toast('Usunięto zgłoszenie.'); location.hash = '#/zgloszenia'; }); });
}
/* Zgłoszenie w Markdownie dla programisty (Claude Code): wszystko, czego trzeba do naprawy, bez dopytywania. */
function issueMarkdown(x) {
  const base = location.href.split('#')[0].replace(/[^/]*$/, '');
  const sec = (t, v) => v ? `\n## ${t}\n${v}\n` : '';
  const row = (k, v) => v ? `- **${k}:** ${v}\n` : '';
  const comments = x.activity.filter(a => a.kind === 'comment').slice(-5);
  return `# ${x.key}: ${x.title}\n\n`
    + row('Rodzaj', S.meta.kinds[x.kind]) + row('Waga', S.meta.severities[x.severity]) + row('Priorytet', S.meta.priorities[x.priority])
    + row('Status', S.meta.statuses[x.status]) + row('Projekt / obszar', [x.project_name, x.module].filter(Boolean).join(' / '))
    + row('Rola i konto w testowanym systemie', [x.sys_role, x.sys_account].filter(Boolean).join(' · '))
    + row('Środowisko', x.environment) + row('Wersja', x.version) + row('Naprawiono w wersji', x.fixed_in)
    + row('Urządzenie / przeglądarka', [x.device, x.browser].filter(Boolean).join(' · ')) + row('Adres', x.url)
    + row('Zgłoszenie w rejestrze', base + '#/z/' + x.id)
    + sec('Co się dzieje (faktyczny wynik)', x.description) + sec('Kroki do odtworzenia problemu', x.steps) + sec('Oczekiwany wynik', x.expected)
    + sec('Załączniki', x.files.map(f => `- [${f.orig_name}](${base}api.php?action=file&id=${f.id})`).join('\n'))
    + sec('Ostatnie komentarze', comments.map(c => `- **${c.user_name}** (${c.created_at.slice(0, 16)}): ${c.message.replace(/\n+/g, ' ')}`).join('\n'))
    + `\n---\nPo naprawie: commit \`fix ${x.key}: …\`, dodaj test, który nie pozwoli temu błędowi wrócić.\n`;
}
async function createRegression(x, reload) {
  const r = await api('case_from_issue', { params: { id: x.id }, body: {} });
  S.cases = null;
  await reload(r.existed ? 'Scenariusz regresji już istnieje: TC-' + String(r.id).padStart(3, '0') + '.' : 'Utworzono scenariusz regresji TC-' + String(r.id).padStart(3, '0') + '.');
}
async function copyText(t) {
  try { await navigator.clipboard.writeText(t); return true; } catch { /* np. strona bez HTTPS — próbujemy starszym sposobem */ }
  const ta = document.createElement('textarea'); ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.append(ta); ta.select();
  let ok = false; try { ok = document.execCommand('copy'); } catch { ok = false; } ta.remove(); return ok;
}
function changeStatus(x, to, reload) {
  if (to === x.status) return;
  if (to === 'rejected') return rejectModal(x, reload);
  if (to === 'fixed') return fixedModal(x, reload);
  const needs = to === 'reopened';
  const title = { closed: x.status === 'fixed' ? 'Potwierdzasz, że poprawka działa?' : 'Zamknąć zgłoszenie?', reopened: 'Co nadal nie działa?', rejected: 'Dlaczego odrzucasz?' }[to] || 'Zmiana statusu: ' + S.meta.statuses[to];
  modal(title, `<div class="field"><label for="mComment">Komentarz ${needs ? '<i>*</i>' : '(opcjonalnie)'}</label><textarea id="mComment" placeholder="${needs ? 'Opisz, co sprawdziłeś i co dalej jest nie tak.' : 'Np. sprawdzone na telefonie i komputerze.'}"></textarea></div>`,
    'Zapisz', async (btn, close) => {
      const comment = $('#mComment').value.trim();
      if (needs && !comment) return toast('Dopisz komentarz.', true);
      await busy(btn, async () => {
        await api('issue_status', { params: { id: x.id }, body: { status: to, comment } }); close();
        if (to === 'closed' && x.status === 'fixed' && confirm('Poprawka potwierdzona. Utworzyć scenariusz regresji z tymi samymi krokami, żeby ten błąd nie wrócił?')) return createRegression(x, reload).catch(e => toast(e.message, true));
        await reload('Status: ' + S.meta.statuses[to] + '.');
      });
    });
}

function fixedModal(x, reload) {
  const o = modal('Poprawka gotowa do weryfikacji', `<p class="small" style="margin-top:0">Ustaw „Do weryfikacji” <b>dopiero po wgraniu poprawki na demo</b>. Tester zobaczy, na jakiej wersji ma sprawdzać.</p>
    <div class="field"><label for="mFix">Naprawiono w wersji <i>*</i></label><input id="mFix" maxlength="60" value="${esc(x.fixed_in || '')}" placeholder="np. 0.9.3"></div>
    <div class="field" style="margin-top:10px"><label for="mComment">Komentarz dla testera (opcjonalnie)</label><textarea id="mComment" placeholder="Co zmieniono i jak sprawdzić."></textarea></div>`,
    'Do weryfikacji', async (btn, close) => {
      const v = $('#mFix', o).value.trim();
      if (!v) return toast('Wpisz wersję z poprawką.', true);
      await busy(btn, async () => { await api('issue_status', { params: { id: x.id }, body: { status: 'fixed', fixed_in: v, comment: $('#mComment', o).value.trim() } }); close(); await reload('Do weryfikacji — wersja ' + v + '.'); });
    });
}
function rejectModal(x, reload) {
  const o = modal('Odrzucenie zgłoszenia ' + x.key, `<div class="field"><label for="mReason">Powód <i>*</i></label><select id="mReason">${opts(Object.entries(S.meta.reject_reasons), '', '— wybierz —')}</select></div>
    <div class="field" id="mDupBox" hidden style="margin-top:10px"><label for="mDup">Numer oryginalnego zgłoszenia <i>*</i></label><input id="mDup" placeholder="np. ${esc(x.project_code)}-0012" autocapitalize="characters"><span class="hint">W oryginale pojawi się informacja o tym duplikacie.</span></div>
    <div class="field" style="margin-top:10px"><label for="mComment">Komentarz (opcjonalnie)</label><textarea id="mComment" placeholder="Np. dlaczego to nie błąd albo gdzie zapisano pomysł."></textarea></div>`,
    'Odrzuć', async (btn, close) => {
      const reason = $('#mReason', o).value, dup = $('#mDup', o).value.trim();
      if (!reason) return toast('Wybierz powód odrzucenia.', true);
      if (reason === 'duplicate' && !dup) return toast('Podaj numer oryginalnego zgłoszenia.', true);
      await busy(btn, async () => { await api('issue_status', { params: { id: x.id }, body: { status: 'rejected', reject_reason: reason, duplicate_of: dup, comment: $('#mComment', o).value.trim() } }); close(); await reload('Zgłoszenie odrzucone.'); });
    });
  $('#mReason', o).onchange = e => { $('#mDupBox', o).hidden = e.target.value !== 'duplicate'; };
}

/* ---------- okno modalne ---------- */
function modal(title, bodyHtml, okLabel, onOk, wide = false) {
  const o = document.createElement('div'); o.className = 'overlay';
  o.innerHTML = `<div class="modal" style="${wide ? 'max-width:760px' : ''}" role="dialog" aria-modal="true"><div class="mh"><h2>${esc(title)}</h2><button class="xbtn" type="button" aria-label="Zamknij">×</button></div>
    <form class="mb" novalidate>${bodyHtml}</form><div class="mf"><button class="btn secondary" type="button" data-x>Anuluj</button>${okLabel ? `<button class="btn primary" type="button" data-ok>${esc(okLabel)}</button>` : ''}</div></div>`;
  document.body.append(o);
  const close = () => { o.remove(); document.removeEventListener('keydown', key); };
  const key = e => { if (e.key === 'Escape') close(); };
  document.addEventListener('keydown', key);
  $('.xbtn', o).onclick = close; $('[data-x]', o).onclick = close;
  o.addEventListener('mousedown', e => { if (e.target === o) close(); });
  const ok = $('[data-ok]', o); if (ok) ok.onclick = () => onOk(ok, close, o);
  $('form', o).onsubmit = e => { e.preventDefault(); ok?.click(); };
  setTimeout(() => $('input,textarea,select', o)?.focus(), 30);
  return o;
}

/* ---------- scenariusze ---------- */
async function viewCases() {
  setNav('scenariusze');
  await loadCases(true);
  const F = S.view.caseF ??= { project: store.get('filters', {}).project || '', module: '', text: '', inactive: false };
  view(`
  <div class="pagehead"><div><div class="eyebrow">Testy</div><h1>Scenariusze testów</h1><p>Opisane krok po kroku sprawdzenia funkcji. Z nich tworzysz przebiegi testów.</p></div>
    <div class="actions">${canWrite() ? '<button class="btn primary" id="newCase">＋ Nowy scenariusz</button>' : ''}</div></div>
  <div class="filters"><input class="control search" id="cfText" placeholder="Szukaj w scenariuszach…" value="${esc(F.text)}">
    <select class="control" id="cfProject">${opts(S.meta.projects.map(p => [p.id, p.code + ' · ' + p.name]), F.project, 'Każdy projekt')}</select>
    <select class="control" id="cfModule"></select>
    <label class="control" style="display:flex;gap:6px;align-items:center"><input type="checkbox" id="cfInactive" ${F.inactive ? 'checked' : ''}> nieaktywne</label></div>
  <div id="caseList"></div>`);
  const fillMods = () => { const mods = [...new Set(S.cases.filter(c => !F.project || c.project_id === Number(F.project)).map(c => c.module).filter(Boolean))].sort();
    $('#cfModule').innerHTML = opts(mods, F.module, 'Każdy obszar'); };
  const draw = () => {
    const t = F.text.toLowerCase();
    const list = S.cases.filter(c => (!F.project || c.project_id === Number(F.project)) && (!F.module || c.module === F.module) && (F.inactive || c.active) && (!t || (c.title + ' ' + c.steps + ' ' + c.module).toLowerCase().includes(t)));
    const groups = {}; list.forEach(c => { const k = (project(c.project_id)?.code || '') + ' · ' + (c.module || 'Bez obszaru'); (groups[k] ||= []).push(c); });
    $('#caseList').innerHTML = list.length ? Object.entries(groups).map(([g, cs]) => `<div class="casegroup"><h3>${esc(g)} (${cs.length})</h3><div class="tablewrap"><table><tbody>${cs.map(c => `<tr class="row" data-case="${c.id}"><td style="width:90px" class="key">TC-${String(c.id).padStart(3, '0')}</td><td><div class="ttl">${esc(c.title)}${c.active ? '' : ' <span class="badge">nieaktywny</span>'}</div><div class="sub">${esc(c.expected).slice(0, 140)}</div></td><td style="width:110px">${prB(c.priority)}</td></tr>`).join('')}</tbody></table></div></div>`).join('')
      : `<div class="card empty"><b>Brak scenariuszy</b>${canWrite() ? 'Dodaj pierwszy scenariusz — np. „Logowanie poprawnym hasłem” — i opisz kroki oraz oczekiwany wynik.' : ''}</div>`;
    $$('[data-case]').forEach(r => r.onclick = () => caseModal(S.cases.find(c => c.id === Number(r.dataset.case)), viewCases));
  };
  $('#cfText').oninput = e => { F.text = e.target.value; draw(); };
  $('#cfProject').onchange = e => { F.project = e.target.value; F.module = ''; fillMods(); draw(); };
  $('#cfModule').onchange = e => { F.module = e.target.value; draw(); };
  $('#cfInactive').onchange = e => { F.inactive = e.target.checked; draw(); };
  $('#newCase') && ($('#newCase').onclick = () => caseModal({ project_id: Number(F.project) || activeProjects()[0]?.id, module: F.module, priority: 'medium', active: 1 }, viewCases));
  fillMods(); draw();
}
function caseModal(c, done) {
  const ro = !canWrite();
  const o = modal(c.id ? (ro ? 'Scenariusz' : 'Edycja scenariusza') + ' TC-' + String(c.id).padStart(3, '0') : 'Nowy scenariusz', `<div class="form">
    <div class="field"><label>Projekt</label><select id="cp" ${ro ? 'disabled' : ''}>${opts(S.meta.projects.filter(p => p.active || p.id === c.project_id).map(p => [p.id, p.code + ' · ' + p.name]), c.project_id)}</select></div>
    <div class="field"><label>Obszar</label><select id="cm" ${ro ? 'disabled' : ''}></select></div>
    <div class="field full"><label>Tytuł <i>*</i></label><input id="ct" maxlength="200" value="${esc(c.title)}" placeholder="np. Dodanie zawodnika do składu meczowego" ${ro ? 'readonly' : ''}></div>
    <div class="field full"><label>Warunki wstępne</label><textarea id="cpre" style="min-height:60px" placeholder="np. Zalogowany jako sekretarz klubu, istnieje mecz w terminarzu" ${ro ? 'readonly' : ''}>${esc(c.preconditions)}</textarea></div>
    <div class="field full"><label>Kroki <i>*</i></label><textarea id="cst" placeholder="1. …&#10;2. …&#10;3. …" ${ro ? 'readonly' : ''}>${esc(c.steps)}</textarea></div>
    <div class="field full"><label>Oczekiwany wynik</label><textarea id="cex" style="min-height:70px" ${ro ? 'readonly' : ''}>${esc(c.expected)}</textarea></div>
    <div class="field"><label>Priorytet</label><select id="cpr" ${ro ? 'disabled' : ''}>${opts(Object.entries(S.meta.priorities), c.priority)}</select></div>
    <div class="field"><label>&nbsp;</label><label style="display:flex;gap:8px;align-items:center;font-weight:600"><input type="checkbox" id="cact" ${c.active ? 'checked' : ''} ${ro ? 'disabled' : ''}> Aktywny (trafia do nowych przebiegów)</label></div>
    ${c.id ? `<div class="field full hint">Autor: ${esc(c.author)} · zmiana ${fmtDate(c.updated_at)}</div>` : ''}
  </div>`, ro ? null : 'Zapisz', async (btn, close) => {
    const body = { id: c.id || 0, project_id: Number($('#cp', o).value), module: $('#cm', o).value, title: $('#ct', o).value, preconditions: $('#cpre', o).value, steps: $('#cst', o).value, expected: $('#cex', o).value, priority: $('#cpr', o).value, active: $('#cact', o).checked };
    if (!body.title.trim() || !body.steps.trim()) return toast('Uzupełnij tytuł i kroki.', true);
    await busy(btn, async () => { await api('case_save', { body }); close(); toast('Zapisano scenariusz.'); await loadCases(true); done(); });
  }, true);
  const fill = () => { const p = project($('#cp', o).value); const ms = [...(p?.modules || [])]; if (c.module && !ms.includes(c.module)) ms.push(c.module); $('#cm', o).innerHTML = opts(ms, $('#cm', o).value || c.module, '— wybierz —'); };
  fill(); $('#cp', o).onchange = fill;
  if (c.id && isAdmin()) { const d = document.createElement('button'); d.className = 'btn danger'; d.type = 'button'; d.textContent = 'Usuń'; d.style.marginRight = 'auto';
    d.onclick = () => { if (confirm('Usunąć scenariusz?')) busy(d, async () => { await api('case_delete', { params: { id: c.id }, body: {} }); o.remove(); toast('Usunięto.'); await loadCases(true); done(); }); };
    $('.mf', o).prepend(d); }
}

/* ---------- przebiegi ---------- */
async function viewRuns() {
  setNav('przebiegi');
  await loadRuns(true);
  const open = S.runs.filter(r => r.status === 'open'), closed = S.runs.filter(r => r.status !== 'open');
  view(`
  <div class="pagehead"><div><div class="eyebrow">Testy</div><h1>Przebiegi testów</h1><p>Przebieg = jedna sesja testowania wybranych scenariuszy na konkretnym środowisku i wersji.</p></div>
    <div class="actions">${canWrite() ? '<button class="btn primary" id="newRun">▶ Nowy przebieg</button>' : ''}</div></div>
  <h3>W toku (${open.length})</h3>${open.length ? open.map(runMini).join('') : '<div class="card empty" style="margin-bottom:18px"><b>Brak otwartych przebiegów</b>Utwórz przebieg, żeby przejść scenariusze krok po kroku i odhaczać wyniki.</div>'}
  ${closed.length ? `<h3 style="margin-top:22px">Zakończone (${closed.length})</h3>${closed.map(runMini).join('')}` : ''}`);
  $('#newRun') && ($('#newRun').onclick = async () => {
    await loadCases(true);
    const last = store.get('last', {});
    const o = modal('Nowy przebieg testów', `<div class="form">
      <div class="field full"><label>Nazwa <i>*</i></label><input id="rn" maxlength="160" placeholder="np. Regresja przed v0.8 — telefon" value="Test ${new Date().toLocaleDateString('pl-PL')}"></div>
      <div class="field"><label>Projekt</label><select id="rp">${opts(activeProjects().map(p => [p.id, p.code + ' · ' + p.name]), last.project)}</select></div>
      <div class="field"><label>Środowisko</label><select id="re"></select></div>
      <div class="field"><label>Wersja</label><input id="rv" maxlength="60" value="${esc(last.version || '')}" placeholder="np. v0.8-demo"></div><div class="field"></div>
      <div class="field full"><span class="lbl">Obszary (puste = wszystkie aktywne scenariusze)</span><div id="rmods" class="filters" style="margin:0"></div><div class="hint" id="rcount"></div></div></div>`,
      'Utwórz przebieg', async (btn, close) => {
        const modules = $$('#rmods input:checked', o).map(i => i.value);
        await busy(btn, async () => { const r = await api('run_create', { body: { name: $('#rn', o).value, project_id: Number($('#rp', o).value), environment: $('#re', o).value, version: $('#rv', o).value, modules } });
          close(); toast('Przebieg utworzony.'); location.hash = '#/przebieg/' + r.id; });
      }, true);
    const fill = () => { const p = project($('#rp', o).value), cs = S.cases.filter(c => c.active && c.project_id === p?.id);
      $('#re', o).innerHTML = opts(p?.environments || [], last.environment, '—');
      const mods = [...new Set(cs.map(c => c.module || ''))];
      $('#rmods', o).innerHTML = mods.length ? mods.map(m => `<label class="control" style="display:flex;gap:6px;align-items:center"><input type="checkbox" value="${esc(m)}"> ${esc(m || 'Bez obszaru')} <span class="muted">(${cs.filter(c => (c.module || '') === m).length})</span></label>`).join('') : '<span class="muted small">Ten projekt nie ma jeszcze aktywnych scenariuszy — dodaj je w zakładce Scenariusze.</span>';
      const cnt = () => { const sel = $$('#rmods input:checked', o).map(i => i.value); $('#rcount', o).textContent = 'Scenariuszy w przebiegu: ' + cs.filter(c => !sel.length || sel.includes(c.module || '')).length; };
      $$('#rmods input', o).forEach(i => i.onchange = cnt); cnt(); };
    fill(); $('#rp', o).onchange = fill;
  });
}
async function viewRun(id) {
  setNav('przebiegi');
  const r = (await api('run', { params: { id } })).run;
  const open = r.status === 'open' && canWrite();
  const F = S.view.runF ??= { show: 'all' };
  const counts = k => r.results.filter(x => x.result === k).length;
  const total = r.results.length, done = total - counts('pending'), pc = k => total ? counts(k) / total * 100 : 0;
  view(`
  <div class="dhead"><a href="#/przebiegi" class="small">← Przebiegi testów</a>
    <div class="key" style="margin-top:10px">${esc(r.project_code)} · ${esc(r.environment || 'środowisko nieokreślone')}${r.version ? ' · ' + esc(r.version) : ''} · utworzył ${esc(r.author)} ${fmtDate(r.created_at, false)}</div>
    <h1>${esc(r.name)}</h1><div class="badges">${r.status === 'open' ? badge('st-progress', 'W toku') : badge('st-closed', 'Zakończony ' + fmtDate(r.closed_at, false))}</div></div>
  <div class="card pad" style="margin-bottom:14px"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px"><b>${done} z ${total} wykonano</b>
    <span class="small">✓ ${counts('pass')} zaliczone · ✗ ${counts('fail')} niezaliczone · ⛔ ${counts('blocked')} zablokowane · ↷ ${counts('skip')} pominięte</span></div>
    <div class="progress"><i class="p-pass" style="width:${pc('pass')}%"></i><i class="p-fail" style="width:${pc('fail')}%"></i><i class="p-blocked" style="width:${pc('blocked')}%"></i><i class="p-skip" style="width:${pc('skip')}%"></i></div></div>
  <div class="pagehead" style="margin-bottom:12px"><div class="seg">${[['all', 'Wszystkie'], ['pending', 'Do wykonania'], ['fail', 'Problemy']].map(([k, l]) => `<button data-show="${k}" class="${F.show === k ? 'on' : ''}">${l}</button>`).join('')}</div>
    <div class="actions">${canWrite() ? (r.status === 'open' ? '<button class="btn secondary" id="runClose">Zakończ przebieg</button>' : '<button class="btn secondary" id="runOpen">Wznów przebieg</button>') : ''}</div></div>
  <div id="cases"></div>`);
  const draw = () => {
    const list = r.results.filter(x => F.show === 'all' || (F.show === 'pending' ? x.result === 'pending' : ['fail', 'blocked'].includes(x.result)));
    $('#cases').innerHTML = list.length ? list.map(x => `<div class="runcase res-${x.result}" data-c="${x.case_id}">
      <div class="top"><span class="key">TC-${String(x.case_id).padStart(3, '0')}</span><div class="ttl">${esc(x.title)}<div class="sub">${esc(x.module || 'Bez obszaru')}${x.tester_name ? ' · ' + esc(x.tester_name) + ', ' + ago(x.updated_at) : ''}</div></div>
        ${x.issue_key ? `<a class="badge st-${x.issue_status}" href="#/z/${x.issue_id}">${esc(x.issue_key)}</a>` : ''}${rsB(x.result)}</div>
      <div class="body"><div class="steps3"><div><h3>Warunki wstępne</h3><div class="prose">${esc(x.preconditions || '—')}</div></div><div><h3>Kroki</h3><div class="prose">${esc(x.steps)}</div></div><div><h3>Oczekiwany wynik</h3><div class="prose">${esc(x.expected || '—')}</div></div></div>
        ${open ? `<div class="resbtns">${[['pass', '✓ Zaliczony'], ['fail', '✗ Niezaliczony'], ['blocked', '⛔ Zablokowany'], ['skip', '↷ Pominięty']].map(([k, l]) => `<button type="button" class="${k}${x.result === k ? ' on' : ''}" data-res="${k}">${l}</button>`).join('')}${x.result !== 'pending' ? '<button type="button" data-res="pending" class="btn ghost">wyczyść</button>' : ''}</div>
        <div class="field"><textarea class="note" placeholder="Notatka z testu (co sprawdziłeś, co zauważyłeś)…" style="min-height:60px">${esc(x.note)}</textarea></div>
        <div class="actions" style="margin-top:8px;justify-content:flex-end">${x.issue_key ? '' : `<a class="btn soft sm" href="#/nowe?run=${r.id}&case=${x.case_id}">＋ Zgłoś błąd z tego scenariusza</a>`}</div>`
        : (x.note ? `<div class="section" style="padding:10px 0 0"><h3>Notatka</h3><div class="prose">${esc(x.note)}</div></div>` : '')}</div></div>`).join('')
      : '<div class="card empty"><b>Nic do pokazania</b>Zmień filtr powyżej.</div>';
    $$('.runcase').forEach(el => {
      const cid = Number(el.dataset.c), item = r.results.find(x => x.case_id === cid);
      if (S.view.openCase === cid) el.classList.add('open');
      $('.top', el).onclick = e => { if (e.target.closest('a')) return; el.classList.toggle('open'); S.view.openCase = el.classList.contains('open') ? cid : null; };
      const save = async (result, note) => {
        try { await api('result_save', { body: { run_id: r.id, case_id: cid, result, note } }); item.result = result; item.note = note; item.tester_name = S.user.name; item.updated_at = new Date().toISOString().slice(0, 19).replace('T', ' '); }
        catch (e) { toast(e.message, true); throw e; }
      };
      $$('[data-res]', el).forEach(b => b.onclick = async () => {
        const res = b.dataset.res; await save(res, $('.note', el).value).catch(() => null);
        if (res === 'fail' || res === 'blocked') { S.view.openCase = cid; if (res === 'fail' && !item.issue_key) toast('Oznaczono jako niezaliczony — zgłoś błąd przyciskiem poniżej.'); }
        else if (res !== 'pending') { const next = r.results.find(x => x.result === 'pending'); S.view.openCase = next?.case_id ?? null; }
        viewRun(id);
      });
      const note = $('.note', el); if (note) note.onblur = () => { if (note.value !== item.note) save(item.result, note.value).then(() => toast('Zapisano notatkę.')).catch(() => null); };
    });
  };
  $$('[data-show]').forEach(b => b.onclick = () => { F.show = b.dataset.show; $$('[data-show]').forEach(x => x.classList.toggle('on', x === b)); draw(); });
  const setStatus = (status, btn) => busy(btn, async () => { await api('run_status', { body: { id, status } }); toast(status === 'closed' ? 'Przebieg zakończony.' : 'Przebieg wznowiony.'); viewRun(id); });
  $('#runClose') && ($('#runClose').onclick = e => { const p = counts('pending'); if (p && !confirm(`${p} scenariuszy nie wykonano. Zakończyć mimo to?`)) return; setStatus('closed', e.currentTarget); });
  $('#runOpen') && ($('#runOpen').onclick = e => setStatus('open', e.currentTarget));
  if (S.view.openCase == null && open) S.view.openCase = r.results.find(x => x.result === 'pending')?.case_id ?? null;
  draw();
  const opened = $('.runcase.open'); if (opened && S.view.scrollToCase !== false) opened.scrollIntoView({ block: 'center' });
}

/* ---------- użytkownicy ---------- */
async function viewUsers() {
  setNav('uzytkownicy');
  if (!isAdmin()) return view('<div class="card empty"><b>Brak uprawnień</b></div>');
  const users = (await api('users')).users;
  view(`
  <div class="pagehead"><div><div class="eyebrow">Ustawienia</div><h1>Użytkownicy</h1><p>Konta testerów i osób z dostępem. Nowe konto dostaje hasło tymczasowe — przy pierwszym logowaniu użytkownik ustawia własne.</p></div>
    <div class="actions"><button class="btn primary" id="newUser">＋ Dodaj użytkownika</button></div></div>
  <div class="tablewrap"><table><thead><tr><th>Osoba</th><th>Login</th><th>Rola</th><th class="hide-m">Ostatnie logowanie</th><th>Stan</th></tr></thead><tbody>
  ${users.map(u => `<tr class="row" data-u="${u.id}"><td><div class="ttl">${esc(u.display_name)}</div><div class="sub">${esc(u.email || '')}</div></td><td class="key">${esc(u.username)}</td><td>${esc(S.meta.roles[u.role])}</td>
    <td class="hide-m sub">${u.last_login_at ? fmtDate(u.last_login_at) : 'jeszcze nie'}</td><td>${u.active ? (u.must_change_pw ? badge('st-progress', 'czeka na hasło') : badge('st-closed', 'aktywne')) : badge('st-rejected', 'zablokowane')}</td></tr>`).join('')}
  </tbody></table></div>
  <div class="card pad" style="margin-top:16px"><h3>Role</h3><p class="small" style="margin:0"><b>Administrator</b> — wszystko: statusy, przypisania, rozwiązania, użytkownicy, projekty, usuwanie.<br><b>Tester</b> — zgłasza błędy i braki, dodaje pliki i komentarze, edytuje własne nowe zgłoszenia, weryfikuje poprawki (zamyka lub otwiera ponownie), pisze scenariusze i prowadzi przebiegi testów.<br><b>Obserwator</b> — przegląda i komentuje (np. zarząd PZBall).</p></div>`);
  const edit = u => userModal(u || { role: 'tester', active: 1 });
  $('#newUser').onclick = () => edit(null);
  $$('[data-u]').forEach(r => r.onclick = () => edit(users.find(u => u.id === Number(r.dataset.u))));
}
function genPassword() {
  const a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789', b = new Uint32Array(14); crypto.getRandomValues(b);
  return [...b].map(n => a[n % a.length]).join('');
}
function userModal(u) {
  const isNew = !u.id;
  const o = modal(isNew ? 'Nowy użytkownik' : 'Edycja: ' + u.display_name, `<div class="form">
    <div class="field"><label>Imię i nazwisko <i>*</i></label><input id="un" value="${esc(u.display_name)}"></div>
    <div class="field"><label>Login <i>*</i></label><input id="ul" value="${esc(u.username)}" autocapitalize="none" placeholder="np. anna.kowalska"></div>
    <div class="field"><label>E-mail</label><input id="ue" type="email" value="${esc(u.email)}"></div>
    <div class="field"><label>Rola</label><select id="ur">${opts(Object.entries(S.meta.roles), u.role)}</select></div>
    <div class="field full"><label>${isNew ? 'Hasło tymczasowe <i>*</i>' : 'Nowe hasło tymczasowe (zostaw puste, by nie zmieniać)'}</label>
      <div style="display:flex;gap:8px"><input id="up" style="flex:1" autocomplete="off" value="${isNew ? genPassword() : ''}"><button class="btn secondary sm" type="button" id="ugen">Losuj</button></div>
      <span class="hint">Przekaż je osobie innym kanałem niż login (np. SMS). Przy pierwszym logowaniu ustawi własne.</span></div>
    <div class="field full"><label style="display:flex;gap:8px;align-items:center;font-weight:600"><input type="checkbox" id="ua" ${u.active ? 'checked' : ''}> Konto aktywne (odznacz, by zablokować dostęp)</label></div></div>`,
    'Zapisz', async (btn, close) => {
      const body = { id: u.id || 0, display_name: $('#un', o).value, username: $('#ul', o).value, email: $('#ue', o).value, role: $('#ur', o).value, password: $('#up', o).value, active: $('#ua', o).checked };
      await busy(btn, async () => { await api('user_save', { body }); close(); toast('Zapisano użytkownika.'); await loadMeta(); viewUsers(); });
    }, true);
  $('#ugen', o).onclick = () => { $('#up', o).value = genPassword(); };
}

/* ---------- projekty ---------- */
function viewProjects() {
  setNav('projekty');
  if (!isAdmin()) return view('<div class="card empty"><b>Brak uprawnień</b></div>');
  view(`
  <div class="pagehead"><div><div class="eyebrow">Ustawienia</div><h1>Projekty i obszary</h1><p>Systemy, które testujemy. Skrót tworzy numer zgłoszenia (np. PZB-0012). Obszary i środowiska pojawiają się na listach w formularzach.</p></div>
    <div class="actions"><button class="btn primary" id="newProj">＋ Dodaj projekt</button></div></div>
  ${S.meta.projects.map(p => `<div class="card runcard" data-p="${p.id}" style="cursor:pointer"><div><div class="ttl">${esc(p.code)} · ${esc(p.name)} ${p.active ? '' : badge('st-rejected', 'nieaktywny')}</div><div class="sub">${p.modules.length} obszarów · ${(p.tested_roles || []).length} ról · środowiska: ${esc(p.environments.join(', ') || '—')}</div></div>
    <div class="sub">${S.issues.filter(i => i.project_id === p.id).length} zgłoszeń</div></div>`).join('')}`);
  const edit = p => {
    const o = modal(p.id ? 'Edycja projektu' : 'Nowy projekt', `<div class="form">
      <div class="field"><label>Skrót (do numerów) <i>*</i></label><input id="pc" maxlength="10" value="${esc(p.code)}" placeholder="PZB"></div>
      <div class="field"><label>Nazwa <i>*</i></label><input id="pn" value="${esc(p.name)}"></div>
      <div class="field full"><label>Obszary systemu — jeden w linii</label><textarea id="pm" style="min-height:200px">${esc((p.modules || []).join('\n'))}</textarea></div>
      <div class="field full"><label>Środowiska — jedno w linii</label><textarea id="pe" style="min-height:80px">${esc((p.environments || []).join('\n'))}</textarea></div>
      <div class="field full"><label>Role w testowanym systemie — jedna w linii</label><textarea id="pr" style="min-height:120px" placeholder="np. Administrator związku&#10;Zarząd klubu&#10;Trener&#10;Sędzia">${esc((p.tested_roles || []).join('\n'))}</textarea><span class="hint">Tester wybiera z tej listy, na jakiej roli był zalogowany, gdy pojawił się problem.</span></div>
      <div class="field full"><label style="display:flex;gap:8px;align-items:center;font-weight:600"><input type="checkbox" id="pa" ${p.active ? 'checked' : ''}> Aktywny (widoczny w formularzach)</label></div></div>`,
      'Zapisz', async (btn, close) => {
        await busy(btn, async () => { await api('project_save', { body: { id: p.id || 0, code: $('#pc', o).value, name: $('#pn', o).value, modules: $('#pm', o).value, environments: $('#pe', o).value, tested_roles: $('#pr', o).value, active: $('#pa', o).checked } });
          close(); toast('Zapisano projekt.'); await Promise.all([loadMeta(), loadIssues()]); viewProjects(); });
      }, true);
  };
  $('#newProj').onclick = () => edit({ active: 1, environments: S.meta.projects[0]?.environments || [] });
  $$('[data-p]').forEach(el => el.onclick = () => edit(project(el.dataset.p)));
}

/* ---------- konto ---------- */
function viewAccount() {
  setNav('');
  view(`<div class="pagehead"><div><div class="eyebrow">Twoje konto</div><h1>${esc(S.user.name)}</h1><p>Login: <b>${esc(S.user.username)}</b> · rola: ${esc(S.meta.roles[S.user.role])}</p></div></div>
  <form class="card pad" id="accForm" style="max-width:520px"><h3>Zmiana hasła</h3><div class="form" style="grid-template-columns:1fr">
    <div class="field"><label>Obecne hasło</label><input id="ac" type="password" autocomplete="current-password" required></div>
    <div class="field"><label>Nowe hasło (min. 10 znaków)</label><input id="an" type="password" minlength="10" autocomplete="new-password" required></div>
    <div class="field"><label>Powtórz nowe hasło</label><input id="an2" type="password" minlength="10" autocomplete="new-password" required></div></div>
    <div class="formbar"><span></span><button class="btn primary" type="submit">Zmień hasło</button></div></form>`);
  $('#accForm').onsubmit = e => { e.preventDefault(); if ($('#an').value !== $('#an2').value) return toast('Nowe hasła nie są takie same.', true);
    busy(e.submitter, async () => { await api('password', { body: { current: $('#ac').value, new: $('#an').value } }); e.target.reset(); toast('Hasło zmienione.'); }); };
}

boot();
})();
