// FULLBASE QA — testy logiki frontendu (bez przeglądarki). Uruchamia je test-e2e.php, gdy jest node.
//   node narzedzia/test-js.js
// Funkcje wycinamy z public/assets/app.js po nazwach, więc test pilnuje prawdziwego kodu.
'use strict';
const src = require('fs').readFileSync(require('path').join(__dirname, '..', 'public', 'assets', 'app.js'), 'utf8');
const cut = (from, to) => { const a = src.indexOf(from), b = src.indexOf(to, a); if (a < 0 || b < 0) throw new Error('Nie znaleziono w app.js: ' + from); return src.slice(a, b); };
let bad = 0;
const ok = (c, n) => { console.log((c ? '  ok   ' : '  BŁĄD ') + n); if (!c) bad++; };

global.location = { href: 'https://testy.fullbase.pl/#/z/12' };
global.S = { meta: { kinds: { bug: 'Błąd' }, severities: { blocker: 'Blokuje pracę' }, priorities: { high: 'Wysoki' }, statuses: { new: 'Nowe' } }, issues: [] };
eval(cut('const PRI = {', 'function drawIssueList') + cut('const STATUS_ORDER', '/* Widoki listy') + cut('function issueMarkdown', 'async function copyText')
  + cut('function versionGroups', 'function viewVersions')
  + ';Object.assign(global,{sortIssues,issueMarkdown,versionGroups,releaseNotes});');

// sortowanie listy (1.15)
const L = [{ id: 1, title: 'b', severity: 'cosmetic', priority: 'low', status: 'closed', updated_at: '2026-01-02' }, { id: 2, title: 'a', severity: 'blocker', priority: 'high', status: 'new', updated_at: '2026-01-03' }, { id: 3, title: 'c', severity: 'blocker', priority: 'critical', status: 'fixed', updated_at: '2026-01-01' }];
const ids = (k, d) => sortIssues(L, { k, d }).map(i => i.id).join(',');
ok(ids('id', -1) === '3,2,1', 'sortowanie: numer malejąco');
ok(ids('sev', 1) === '3,2,1', 'sortowanie: najpoważniejsze pierwsze');
ok(ids('status', 1) === '2,3,1', 'sortowanie: status w kolejności pracy');
ok(ids('upd', -1) === '2,1,3', 'sortowanie: ostatnio zmienione');

// Markdown dla programisty (1.4)
const md = issueMarkdown({ id: 12, key: 'PZB-0012', title: 'Wynik znika', kind: 'bug', severity: 'blocker', priority: 'high', status: 'new', project_name: 'PZBall', module: 'Mecze',
  sys_role: 'Sędzia', sys_account: 'sedzia@pzball.test', environment: 'demo', version: '0.9', fixed_in: '', device: '', browser: 'Chrome', url: '', description: 'Znika', steps: '1. a',
  expected: 'Zostaje', suggestion: 'Sprawdzić remis', files: [{ id: 3, orig_name: 'zrzut.png' }], activity: [{ kind: 'comment', user_name: 'Ala', created_at: '2026-11-24 10:00:00', message: 'Też\nmam' }] });
ok(md.startsWith('# PZB-0012: Wynik znika') && md.includes('**Waga:** Blokuje pracę') && md.includes('Sędzia · sedzia@pzball.test'), 'Markdown: nagłówek, waga, konto');
ok(md.includes('(https://testy.fullbase.pl/api.php?action=file&id=3)') && md.includes('## Sugestia naprawy od testera\nSprawdzić remis'), 'Markdown: link do załącznika i sugestia');
ok(md.includes('Ala** (2026-11-24 10:00): Też mam') && md.includes('commit `fix PZB-0012: …`'), 'Markdown: komentarz w jednej linii i stopka');

// wersje i opis wydania (1.17)
S.issues = [
  { id: 1, key: 'PZB-0001', title: 'A', module: 'Mecze', project_id: 1, project_code: 'PZB', project_name: 'PZBall', fixed_in: '0.9.10', status: 'closed' },
  { id: 2, key: 'PZB-0002', title: 'B', module: '', project_id: 1, project_code: 'PZB', project_name: 'PZBall', fixed_in: '0.9.10', status: 'fixed' },
  { id: 3, key: 'PZB-0003', title: 'C', project_id: 1, project_code: 'PZB', project_name: 'PZBall', fixed_in: '0.9.9', status: 'closed' },
  { id: 4, key: 'PZB-0004', title: 'D', project_id: 1, project_code: 'PZB', project_name: 'PZBall', fixed_in: '0.9.9', status: 'reopened' },
  { id: 5, key: 'PZB-0005', title: 'E', project_id: 1, project_code: 'PZB', project_name: 'PZBall', fixed_in: '', status: 'closed' }];
const g = versionGroups('');
ok(g.map(v => v.version).join(',') === '0.9.10,0.9.9', 'wersje: kolejność numeryczna (0.9.10 przed 0.9.9)');
ok(g[1].items.length === 1, 'wersje: bez ponownie otwartych i bez zgłoszeń bez wersji');
const t = releaseNotes(g[0]);
ok(t.includes('## PZBall — wersja 0.9.10') && t.includes('(1)\n- PZB-0001 A (Mecze)') && t.includes('czeka na sprawdzenie (1)\n- PZB-0002 B'), 'opis wydania: obie sekcje');

process.exit(bad ? 1 : 0);
