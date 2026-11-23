<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

qa_security_headers();
if (!qa_config()) out(['error' => 'Aplikacja nie jest zainstalowana.', 'install' => true], 503);
qa_session_start();

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ---------- pomocnicze ---------- */

function body(): array
{
    static $b = null;
    if ($b !== null) return $b;
    $raw = file_get_contents('php://input') ?: '';
    $b = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($b)) out(['error' => 'Nieprawidłowe dane żądania.'], 400);
    return $b;
}
function str_in(array $b, string $k, int $max = 5000): string
{
    $v = trim((string)($b[$k] ?? ''));
    return mb_substr($v, 0, $max);
}
function enum_in(array $b, string $k, array $allowed, string $default): string
{
    $v = (string)($b[$k] ?? $default);
    return array_key_exists($v, $allowed) ? $v : $default;
}
function check_origin(): void
{
    $o = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($o !== '' && parse_url($o, PHP_URL_HOST) !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
        out(['error' => 'Odrzucono żądanie z innej strony.'], 403);
    }
}
function require_post(): void
{
    global $method;
    if ($method !== 'POST') out(['error' => 'Niedozwolona metoda.'], 405);
    check_origin();
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($t === '' || !hash_equals($_SESSION['csrf'] ?? '', $t)) out(['error' => 'Sesja wygasła. Odśwież stronę.', 'csrf' => true], 419);
}
function current_user(): ?array
{
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $q = db()->prepare('SELECT id,username,display_name,email,role,active,must_change_pw FROM qa_users WHERE id=?');
        $q->execute([(int)$_SESSION['uid']]);
        $r = $q->fetch();
        if ($r && (int)$r['active'] === 1) {
            $u = ['id' => (int)$r['id'], 'username' => $r['username'], 'name' => $r['display_name'], 'email' => $r['email'],
                'role' => $r['role'], 'must_change_pw' => (bool)$r['must_change_pw']];
        } else {
            unset($_SESSION['uid']);
        }
    }
    return $u;
}
function auth(): array
{
    $u = current_user();
    if (!$u) out(['error' => 'Zaloguj się ponownie.', 'login' => true], 401);
    global $action;
    if ($u['must_change_pw'] && !in_array($action, ['password', 'logout', 'session'], true)) {
        out(['error' => 'Najpierw ustaw nowe hasło.', 'must_change_pw' => true], 403);
    }
    return $u;
}
function need_role(array $u, string ...$roles): void
{
    if (!in_array($u['role'], $roles, true)) out(['error' => 'Brak uprawnień do tej czynności.'], 403);
}
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64); }
function log_act(int $issueId, int $uid, string $kind, string $msg): void
{
    db()->prepare('INSERT INTO qa_activity(issue_id,user_id,kind,message,created_at) VALUES(?,?,?,?,?)')
        ->execute([$issueId, $uid, $kind, $msg, qa_now()]);
}
function touch_issue(int $id): void
{
    db()->prepare('UPDATE qa_issues SET updated_at=? WHERE id=?')->execute([qa_now(), $id]);
}
function project_row(int $id): array
{
    $q = db()->prepare('SELECT * FROM qa_projects WHERE id=?');
    $q->execute([$id]);
    $r = $q->fetch();
    if (!$r) out(['error' => 'Nie znaleziono projektu.'], 404);
    return $r;
}
function issue_row(int $id): array
{
    $q = db()->prepare('SELECT i.*, p.code project_code, p.name project_name, r.display_name reporter_name, a.display_name assignee_name
        FROM qa_issues i JOIN qa_projects p ON p.id=i.project_id JOIN qa_users r ON r.id=i.reporter_id LEFT JOIN qa_users a ON a.id=i.assignee_id WHERE i.id=?');
    $q->execute([$id]);
    $r = $q->fetch();
    if (!$r) out(['error' => 'Nie znaleziono zgłoszenia.'], 404);
    return cast_issue($r);
}
function cast_issue(array $r): array
{
    foreach (['id', 'project_id', 'reporter_id'] as $k) $r[$k] = (int)$r[$k];
    $r['assignee_id'] = $r['assignee_id'] === null ? null : (int)$r['assignee_id'];
    $r['key'] = $r['project_code'] . '-' . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT);
    return $r;
}
function issue_fields(array $b): array
{
    $f = [
        'kind' => enum_in($b, 'kind', QA_KINDS, 'bug'),
        'title' => str_in($b, 'title', 200),
        'module' => str_in($b, 'module', 120),
        'priority' => enum_in($b, 'priority', QA_PRIORITIES, 'medium'),
        'severity' => enum_in($b, 'severity', QA_SEVERITIES, 'annoying'),
        'description' => str_in($b, 'description', 20000),
        'steps' => str_in($b, 'steps', 20000),
        'expected' => str_in($b, 'expected', 20000),
        'environment' => str_in($b, 'environment', 80),
        'version' => str_in($b, 'version', 60),
        'device' => str_in($b, 'device', 80),
        'browser' => str_in($b, 'browser', 80),
        'url' => str_in($b, 'url', 1000),
    ];
    if ($f['title'] === '' || $f['description'] === '') out(['error' => 'Uzupełnij tytuł i opis problemu.'], 422);
    if ($f['url'] !== '' && !preg_match('~^https?://~i', $f['url'])) out(['error' => 'Adres strony musi zaczynać się od http:// lub https://'], 422);
    return $f;
}
function user_exists_active(?int $id): ?int
{
    if (!$id) return null;
    $q = db()->prepare('SELECT id FROM qa_users WHERE id=? AND active=1');
    $q->execute([$id]);
    return $q->fetchColumn() ? $id : null;
}
function lines(string $s): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $s) ?: []), fn($x) => $x !== ''));
}

/* ---------- sesja i logowanie ---------- */

if ($action === 'session') {
    $u = current_user();
    out(['user' => $u, 'csrf' => $_SESSION['csrf'], 'version' => QA_VERSION, 'schema' => $u ? (int)qa_meta_get(db(), 'schema') : null]);
}

if ($action === 'login') {
    if ($method !== 'POST') out(['error' => 'Niedozwolona metoda.'], 405);
    check_origin();
    $b = body();
    $name = mb_strtolower(str_in($b, 'username', 80));
    $pw = (string)($b['password'] ?? '');
    if ($name === '' || $pw === '') out(['error' => 'Wpisz login i hasło.'], 422);
    $p = db();
    $since = date('Y-m-d H:i:s', time() - 900);
    $q = $p->prepare('SELECT COUNT(*) FROM qa_login_attempts WHERE (ip=? OR username=?) AND created_at>?');
    $q->execute([client_ip(), $name, $since]);
    if ((int)$q->fetchColumn() >= 8) out(['error' => 'Zbyt wiele nieudanych prób. Spróbuj ponownie za 15 minut.'], 429);
    $q = $p->prepare('SELECT * FROM qa_users WHERE username=?');
    $q->execute([$name]);
    $r = $q->fetch();
    if (!$r || (int)$r['active'] !== 1 || !password_verify($pw, $r['password_hash'])) {
        $p->prepare('INSERT INTO qa_login_attempts(ip,username,created_at) VALUES(?,?,?)')->execute([client_ip(), $name, qa_now()]);
        usleep(400000);
        out(['error' => 'Nieprawidłowy login lub hasło.'], 401);
    }
    if (password_needs_rehash($r['password_hash'], PASSWORD_DEFAULT)) {
        $p->prepare('UPDATE qa_users SET password_hash=? WHERE id=?')->execute([qa_hash_password($pw), $r['id']]);
    }
    $p->prepare('DELETE FROM qa_login_attempts WHERE username=? OR created_at<?')->execute([$name, $since]);
    $p->prepare('UPDATE qa_users SET last_login_at=? WHERE id=?')->execute([qa_now(), $r['id']]);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$r['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    out(['user' => current_user(), 'csrf' => $_SESSION['csrf']]);
}

if ($action === 'logout') {
    require_post();
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    out(['ok' => true, 'csrf' => $_SESSION['csrf']]);
}

$u = auth();
$p = db();

if ($action === 'password') {
    require_post();
    $b = body();
    $q = $p->prepare('SELECT password_hash FROM qa_users WHERE id=?');
    $q->execute([$u['id']]);
    if (!password_verify((string)($b['current'] ?? ''), (string)$q->fetchColumn())) out(['error' => 'Obecne hasło jest nieprawidłowe.'], 422);
    $new = (string)($b['new'] ?? '');
    if ($e = qa_password_problem($new)) out(['error' => $e], 422);
    if (hash_equals((string)($b['current'] ?? ''), $new)) out(['error' => 'Nowe hasło musi różnić się od obecnego.'], 422);
    $p->prepare('UPDATE qa_users SET password_hash=?, must_change_pw=0 WHERE id=?')->execute([qa_hash_password($new), $u['id']]);
    session_regenerate_id(true);
    out(['ok' => true]);
}

/* ---------- słowniki ---------- */

if ($action === 'meta') {
    $projects = $p->query('SELECT * FROM qa_projects ORDER BY position, id')->fetchAll();
    foreach ($projects as &$pr) {
        $pr['id'] = (int)$pr['id'];
        $pr['active'] = (int)$pr['active'];
        $pr['modules'] = lines($pr['modules']);
        $pr['environments'] = lines($pr['environments']);
    }
    $users = $p->query("SELECT id, display_name name, role, active FROM qa_users ORDER BY display_name")->fetchAll();
    foreach ($users as &$x) { $x['id'] = (int)$x['id']; $x['active'] = (int)$x['active']; }
    out(['projects' => $projects, 'users' => $users, 'roles' => QA_ROLES, 'kinds' => QA_KINDS, 'severities' => QA_SEVERITIES, 'priorities' => QA_PRIORITIES,
        'statuses' => QA_STATUSES, 'results' => QA_RESULTS, 'upload_max' => min(QA_UPLOAD_MAX, upload_limit())]);
}
function upload_limit(): int
{
    $v = ini_get('upload_max_filesize') ?: '2M';
    $n = (int)$v;
    $unit = strtoupper(substr(trim($v), -1));
    return match ($unit) { 'G' => $n * 1073741824, 'M' => $n * 1048576, 'K' => $n * 1024, default => $n };
}

/* ---------- zgłoszenia ---------- */

if ($action === 'issues') {
    $rows = $p->query('SELECT i.id,i.project_id,i.kind,i.title,i.module,i.priority,i.severity,i.status,i.environment,i.version,i.device,i.browser,
            i.reporter_id,i.assignee_id,i.created_at,i.updated_at,i.closed_at, p.code project_code, p.name project_name,
            r.display_name reporter_name, a.display_name assignee_name,
            (SELECT COUNT(*) FROM qa_attachments t WHERE t.issue_id=i.id) attachments,
            (SELECT COUNT(*) FROM qa_activity c WHERE c.issue_id=i.id AND c.kind=\'comment\') comments
        FROM qa_issues i JOIN qa_projects p ON p.id=i.project_id JOIN qa_users r ON r.id=i.reporter_id LEFT JOIN qa_users a ON a.id=i.assignee_id
        ORDER BY i.id DESC LIMIT 5000')->fetchAll();
    out(['issues' => array_map(function ($r) {
        $r = cast_issue($r);
        $r['attachments'] = (int)$r['attachments'];
        $r['comments'] = (int)$r['comments'];
        return $r;
    }, $rows)]);
}

if ($action === 'issue') {
    $issue = issue_row((int)($_GET['id'] ?? 0));
    $q = $p->prepare('SELECT a.id,a.kind,a.message,a.created_at,a.user_id,u.display_name user_name FROM qa_activity a JOIN qa_users u ON u.id=a.user_id WHERE a.issue_id=? ORDER BY a.id');
    $q->execute([$issue['id']]);
    $issue['activity'] = $q->fetchAll();
    $q = $p->prepare('SELECT t.id,t.orig_name,t.mime,t.size,t.created_at,u.display_name user_name,t.user_id FROM qa_attachments t JOIN qa_users u ON u.id=t.user_id WHERE t.issue_id=? ORDER BY t.id');
    $q->execute([$issue['id']]);
    $issue['files'] = $q->fetchAll();
    $q = $p->prepare('SELECT r.run_id, r.case_id, c.title case_title, n.name run_name FROM qa_results r JOIN qa_cases c ON c.id=r.case_id JOIN qa_runs n ON n.id=r.run_id WHERE r.issue_id=?');
    $q->execute([$issue['id']]);
    $issue['test_links'] = $q->fetchAll();
    out(['issue' => $issue]);
}

if ($action === 'issue_create') {
    require_post();
    need_role($u, 'admin', 'tester');
    $b = body();
    $f = issue_fields($b);
    if ($u['role'] !== 'admin') $f['priority'] = 'medium'; // priorytet ustawia tylko administrator
    $project = project_row((int)($b['project_id'] ?? 0));
    $now = qa_now();
    $p->beginTransaction();
    $q = $p->prepare('INSERT INTO qa_issues(project_id,kind,title,module,priority,severity,status,description,steps,expected,environment,version,device,browser,url,solution,reporter_id,assignee_id,created_at,updated_at)
        VALUES(?,?,?,?,?,?,\'new\',?,?,?,?,?,?,?,?,\'\',?,NULL,?,?)');
    $q->execute([$project['id'], $f['kind'], $f['title'], $f['module'], $f['priority'], $f['severity'], $f['description'], $f['steps'], $f['expected'],
        $f['environment'], $f['version'], $f['device'], $f['browser'], $f['url'], $u['id'], $now, $now]);
    $id = (int)$p->lastInsertId();
    log_act($id, $u['id'], 'created', 'Utworzono zgłoszenie.');
    // powiązanie z wynikiem testu
    if (!empty($b['run_id']) && !empty($b['case_id'])) {
        $q = $p->prepare('UPDATE qa_results SET issue_id=?, result=CASE WHEN result IN (\'pending\',\'pass\') THEN \'fail\' ELSE result END, tester_id=?, updated_at=? WHERE run_id=? AND case_id=?');
        $q->execute([$id, $u['id'], $now, (int)$b['run_id'], (int)$b['case_id']]);
        if ($q->rowCount()) log_act($id, $u['id'], 'field', 'Powiązano z przebiegiem testów #' . (int)$b['run_id'] . '.');
    }
    $p->commit();
    out(['issue' => issue_row($id)]);
}

if ($action === 'issue_update') {
    require_post();
    $id = (int)($_GET['id'] ?? 0);
    $before = issue_row($id);
    $b = body();
    $isAdmin = $u['role'] === 'admin';
    $ownNew = $u['role'] === 'tester' && $before['reporter_id'] === $u['id'] && in_array($before['status'], ['new', 'reopened'], true);
    if (!$isAdmin && !$ownNew) out(['error' => 'Możesz edytować tylko własne zgłoszenia, zanim trafią do naprawy.'], 403);
    $f = issue_fields($b);
    if (!$isAdmin) $f['priority'] = $before['priority'];
    $projectId = $isAdmin ? project_row((int)($b['project_id'] ?? $before['project_id']))['id'] : $before['project_id'];
    $sets = $f + ['project_id' => $projectId];
    if ($isAdmin) {
        $sets['solution'] = str_in($b, 'solution', 20000);
        $sets['assignee_id'] = user_exists_active(isset($b['assignee_id']) ? (int)$b['assignee_id'] : null);
    }
    $labels = ['title' => 'tytuł', 'kind' => 'rodzaj', 'module' => 'obszar', 'priority' => 'priorytet', 'severity' => 'waga', 'description' => 'opis', 'steps' => 'kroki do odtworzenia problemu',
        'expected' => 'oczekiwany wynik', 'environment' => 'środowisko', 'version' => 'wersja', 'device' => 'urządzenie', 'browser' => 'przeglądarka',
        'url' => 'adres', 'solution' => 'rozwiązanie', 'assignee_id' => 'osoba naprawiająca', 'project_id' => 'projekt'];
    $changed = [];
    foreach ($sets as $k => $v) if ((string)$before[$k] !== (string)$v) $changed[] = $labels[$k] ?? $k;
    if (!$changed) out(['issue' => $before]);
    $sets['updated_at'] = qa_now();
    $cols = implode(',', array_map(fn($k) => "$k=?", array_keys($sets)));
    $p->prepare("UPDATE qa_issues SET $cols WHERE id=?")->execute([...array_values($sets), $id]);
    if (in_array('priorytet', $changed, true)) {
        log_act($id, $u['id'], 'field', 'Priorytet: ' . QA_PRIORITIES[$before['priority']] . ' → ' . QA_PRIORITIES[$sets['priority']] . '.');
        $changed = array_values(array_diff($changed, ['priorytet']));
    }
    if (in_array('waga', $changed, true)) {
        log_act($id, $u['id'], 'field', 'Waga: ' . (QA_SEVERITIES[$before['severity']] ?? '—') . ' → ' . QA_SEVERITIES[$sets['severity']] . '.');
        $changed = array_values(array_diff($changed, ['waga']));
    }
    if (in_array('osoba naprawiająca', $changed, true)) {
        $n = '—';
        if ($sets['assignee_id']) { $q = $p->prepare('SELECT display_name FROM qa_users WHERE id=?'); $q->execute([$sets['assignee_id']]); $n = (string)$q->fetchColumn(); }
        log_act($id, $u['id'], 'field', 'Przypisano: ' . $n . '.');
        $changed = array_values(array_diff($changed, ['osoba naprawiająca']));
    }
    if ($changed) log_act($id, $u['id'], 'field', 'Zmieniono: ' . implode(', ', $changed) . '.');
    out(['issue' => issue_row($id)]);
}

if ($action === 'issue_status') {
    require_post();
    $id = (int)($_GET['id'] ?? 0);
    $issue = issue_row($id);
    $b = body();
    $to = (string)($b['status'] ?? '');
    $comment = str_in($b, 'comment', 20000);
    if (!array_key_exists($to, QA_STATUSES)) out(['error' => 'Nieznany status.'], 422);
    $from = $issue['status'];
    if ($from === $to) out(['issue' => $issue]);
    $allowed = false;
    if ($u['role'] === 'admin') $allowed = true;
    elseif ($u['role'] === 'tester') {
        // Tester weryfikuje poprawki i może ponownie otworzyć zamknięte zgłoszenie.
        $allowed = ($from === 'fixed' && in_array($to, ['closed', 'reopened'], true))
            || (in_array($from, ['closed', 'rejected'], true) && $to === 'reopened');
    }
    if (!$allowed) out(['error' => 'Nie możesz zmienić statusu z „' . QA_STATUSES[$from] . '” na „' . QA_STATUSES[$to] . '”.'], 403);
    if (in_array($to, ['reopened', 'rejected'], true) && $comment === '') out(['error' => 'Dopisz komentarz — co dalej nie działa albo dlaczego odrzucasz.'], 422);
    $closed = in_array($to, ['closed', 'rejected'], true) ? qa_now() : null;
    $p->prepare('UPDATE qa_issues SET status=?, closed_at=?, updated_at=? WHERE id=?')->execute([$to, $closed, qa_now(), $id]);
    $msg = 'Status: ' . QA_STATUSES[$from] . ' → ' . QA_STATUSES[$to] . '.';
    if ($from === 'fixed' && $to === 'closed') $msg = 'Weryfikacja OK — poprawka działa. Zgłoszenie zamknięte.';
    if ($from === 'fixed' && $to === 'reopened') $msg = 'Weryfikacja negatywna — poprawka nie działa. Ponownie otwarte.';
    log_act($id, $u['id'], $from === 'fixed' && in_array($to, ['closed', 'reopened'], true) ? 'verify' : 'status', $msg);
    if ($comment !== '') log_act($id, $u['id'], 'comment', $comment);
    out(['issue' => issue_row($id)]);
}

if ($action === 'comment') {
    require_post();
    $id = (int)($_GET['id'] ?? 0);
    issue_row($id);
    $msg = str_in(body(), 'message', 20000);
    if ($msg === '') out(['error' => 'Komentarz jest pusty.'], 422);
    log_act($id, $u['id'], 'comment', $msg);
    touch_issue($id);
    out(['ok' => true]);
}

if ($action === 'issue_delete') {
    require_post();
    need_role($u, 'admin');
    $id = (int)($_GET['id'] ?? 0);
    issue_row($id);
    $q = $p->prepare('SELECT stored_name FROM qa_attachments WHERE issue_id=?');
    $q->execute([$id]);
    $files = $q->fetchAll(PDO::FETCH_COLUMN);
    $p->beginTransaction();
    $p->prepare('UPDATE qa_results SET issue_id=NULL WHERE issue_id=?')->execute([$id]);
    $p->prepare('DELETE FROM qa_activity WHERE issue_id=?')->execute([$id]);
    $p->prepare('DELETE FROM qa_attachments WHERE issue_id=?')->execute([$id]);
    $p->prepare('DELETE FROM qa_issues WHERE id=?')->execute([$id]);
    $p->commit();
    foreach ($files as $f) @unlink(QA_DATA . '/uploads/' . basename($f));
    out(['ok' => true]);
}

/* ---------- załączniki ---------- */

if ($action === 'upload') {
    require_post();
    need_role($u, 'admin', 'tester');
    $id = (int)($_GET['id'] ?? 0);
    issue_row($id);
    $files = $_FILES['files'] ?? null;
    if (!$files || !is_array($files['name'])) out(['error' => 'Nie wybrano plików (lub plik przekracza limit serwera).'], 422);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $saved = [];
    $count = count($files['name']);
    if ($count > 10) out(['error' => 'Najwyżej 10 plików naraz.'], 422);
    for ($i = 0; $i < $count; $i++) {
        $name = (string)$files['name'][$i];
        if ($files['error'][$i] !== UPLOAD_ERR_OK) out(['error' => "Plik „{$name}” nie został przesłany (za duży albo przerwany)."], 422);
        if ($files['size'][$i] > QA_UPLOAD_MAX) out(['error' => "Plik „{$name}” przekracza 10 MB."], 413);
        $mime = (string)$finfo->file($files['tmp_name'][$i]);
        if ($mime === 'text/x-log' || ($mime === 'text/plain' && preg_match('/\.(log|txt|csv|json)$/i', $name))) $mime = 'text/plain';
        if (!isset(QA_UPLOAD_TYPES[$mime])) out(['error' => "Plik „{$name}” ma niedozwolony typ ($mime). Dozwolone: obrazy, PDF, TXT/LOG, CSV, JSON, MP4/WEBM, ZIP."], 415);
        $stored = bin2hex(random_bytes(16)) . '.' . QA_UPLOAD_TYPES[$mime];
        if (!move_uploaded_file($files['tmp_name'][$i], QA_DATA . '/uploads/' . $stored)) out(['error' => 'Nie udało się zapisać pliku na serwerze.'], 500);
        $clean = mb_substr(preg_replace('/[^\p{L}\p{N}._ ()-]+/u', '_', $name) ?: 'plik', 0, 200);
        $p->prepare('INSERT INTO qa_attachments(issue_id,user_id,orig_name,stored_name,mime,size,created_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$id, $u['id'], $clean, $stored, $mime, (int)$files['size'][$i], qa_now()]);
        $saved[] = $clean;
    }
    log_act($id, $u['id'], 'attachment', 'Dodano załączniki: ' . implode(', ', $saved) . '.');
    touch_issue($id);
    out(['ok' => true, 'saved' => count($saved)]);
}

if ($action === 'file') {
    $q = $p->prepare('SELECT * FROM qa_attachments WHERE id=?');
    $q->execute([(int)($_GET['id'] ?? 0)]);
    $f = $q->fetch();
    $path = $f ? QA_DATA . '/uploads/' . basename($f['stored_name']) : '';
    if (!$f || !is_file($path)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Nie znaleziono pliku.'; exit; }
    $inline = str_starts_with($f['mime'], 'image/') || in_array($f['mime'], ['application/pdf', 'video/mp4', 'video/webm'], true);
    header('Content-Type: ' . $f['mime']);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=3600');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($f['orig_name']));
    readfile($path);
    exit;
}

if ($action === 'file_delete') {
    require_post();
    $q = $p->prepare('SELECT * FROM qa_attachments WHERE id=?');
    $q->execute([(int)($_GET['id'] ?? 0)]);
    $f = $q->fetch();
    if (!$f) out(['error' => 'Nie znaleziono pliku.'], 404);
    if ($u['role'] !== 'admin' && (int)$f['user_id'] !== $u['id']) out(['error' => 'Możesz usuwać tylko własne załączniki.'], 403);
    $p->prepare('DELETE FROM qa_attachments WHERE id=?')->execute([$f['id']]);
    @unlink(QA_DATA . '/uploads/' . basename($f['stored_name']));
    log_act((int)$f['issue_id'], $u['id'], 'attachment', 'Usunięto załącznik: ' . $f['orig_name'] . '.');
    out(['ok' => true]);
}

/* ---------- eksport ---------- */

if ($action === 'export') {
    $rows = $p->query('SELECT i.*, p.code project_code, p.name project_name, r.display_name reporter_name, a.display_name assignee_name
        FROM qa_issues i JOIN qa_projects p ON p.id=i.project_id JOIN qa_users r ON r.id=i.reporter_id LEFT JOIN qa_users a ON a.id=i.assignee_id ORDER BY i.id')->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="zgloszenia-' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v; // ochrona przed formułami w Excelu
    fputcsv($o, ['Numer', 'Projekt', 'Rodzaj', 'Tytuł', 'Obszar', 'Waga', 'Priorytet', 'Status', 'Opis', 'Kroki do odtworzenia problemu', 'Oczekiwane', 'Środowisko', 'Wersja', 'Urządzenie', 'Przeglądarka', 'Adres', 'Rozwiązanie', 'Zgłosił', 'Naprawia', 'Utworzono', 'Zmieniono', 'Zamknięto'], ';');
    foreach ($rows as $r) {
        $r = cast_issue($r);
        fputcsv($o, array_map($safe, [$r['key'], $r['project_name'], QA_KINDS[$r['kind']] ?? $r['kind'], $r['title'], $r['module'], QA_SEVERITIES[$r['severity']] ?? '', QA_PRIORITIES[$r['priority']] ?? '', QA_STATUSES[$r['status']] ?? '',
            $r['description'], $r['steps'], $r['expected'], $r['environment'], $r['version'], $r['device'], $r['browser'], $r['url'], $r['solution'],
            $r['reporter_name'], $r['assignee_name'] ?? '', $r['created_at'], $r['updated_at'], $r['closed_at'] ?? '']), ';');
    }
    exit;
}

/* ---------- użytkownicy ---------- */

if ($action === 'users') {
    need_role($u, 'admin');
    $rows = $p->query('SELECT id,username,display_name,email,role,active,must_change_pw,created_at,last_login_at FROM qa_users ORDER BY active DESC, display_name')->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['active'] = (int)$r['active']; $r['must_change_pw'] = (int)$r['must_change_pw']; }
    out(['users' => $rows]);
}

if ($action === 'user_save') {
    require_post();
    need_role($u, 'admin');
    $b = body();
    $id = (int)($b['id'] ?? 0);
    $username = mb_strtolower(str_in($b, 'username', 80));
    $name = str_in($b, 'display_name', 160);
    $email = str_in($b, 'email', 190);
    $role = enum_in($b, 'role', QA_ROLES, 'tester');
    $active = !empty($b['active']) ? 1 : 0;
    $pw = (string)($b['password'] ?? '');
    if (!preg_match('/^[a-z0-9._-]{3,80}$/', $username)) out(['error' => 'Login: 3–80 znaków, tylko małe litery, cyfry, kropka, myślnik i podkreślenie.'], 422);
    if ($name === '') out(['error' => 'Wpisz imię i nazwisko.'], 422);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) out(['error' => 'Adres e-mail jest nieprawidłowy.'], 422);
    $q = $p->prepare('SELECT id FROM qa_users WHERE username=? AND id<>?');
    $q->execute([$username, $id]);
    if ($q->fetchColumn()) out(['error' => 'Taki login już istnieje.'], 422);
    if ($id === $u['id'] && ($role !== 'admin' || !$active)) out(['error' => 'Nie możesz odebrać sobie roli administratora ani zablokować własnego konta.'], 422);
    if ($id === 0) {
        if ($e = qa_password_problem($pw)) out(['error' => $e], 422);
        $p->prepare('INSERT INTO qa_users(username,display_name,email,password_hash,role,active,must_change_pw,created_at) VALUES(?,?,?,?,?,?,1,?)')
            ->execute([$username, $name, $email, qa_hash_password($pw), $role, $active, qa_now()]);
    } else {
        $p->prepare('UPDATE qa_users SET username=?,display_name=?,email=?,role=?,active=? WHERE id=?')->execute([$username, $name, $email, $role, $active, $id]);
        if ($pw !== '') {
            if ($e = qa_password_problem($pw)) out(['error' => $e], 422);
            $p->prepare('UPDATE qa_users SET password_hash=?, must_change_pw=1 WHERE id=?')->execute([qa_hash_password($pw), $id]);
        }
    }
    out(['ok' => true]);
}

/* ---------- projekty ---------- */

if ($action === 'project_save') {
    require_post();
    need_role($u, 'admin');
    $b = body();
    $id = (int)($b['id'] ?? 0);
    $code = strtoupper(str_in($b, 'code', 10));
    $name = str_in($b, 'name', 120);
    if (!preg_match('/^[A-Z0-9]{2,10}$/', $code)) out(['error' => 'Skrót projektu: 2–10 wielkich liter lub cyfr (np. PZB).'], 422);
    if ($name === '') out(['error' => 'Wpisz nazwę projektu.'], 422);
    $q = $p->prepare('SELECT id FROM qa_projects WHERE code=? AND id<>?');
    $q->execute([$code, $id]);
    if ($q->fetchColumn()) out(['error' => 'Taki skrót już istnieje.'], 422);
    $modules = implode("\n", array_slice(lines((string)($b['modules'] ?? '')), 0, 200));
    $envs = implode("\n", array_slice(lines((string)($b['environments'] ?? '')), 0, 50));
    $active = !empty($b['active']) ? 1 : 0;
    if ($id) $p->prepare('UPDATE qa_projects SET code=?,name=?,modules=?,environments=?,active=? WHERE id=?')->execute([$code, $name, $modules, $envs, $active, $id]);
    else {
        $pos = (int)$p->query('SELECT COALESCE(MAX(position),0) FROM qa_projects')->fetchColumn() + 1;
        $p->prepare('INSERT INTO qa_projects(code,name,modules,environments,active,position) VALUES(?,?,?,?,?,?)')
            ->execute([$code, $name, $modules, $envs, $active, $pos]);
    }
    out(['ok' => true]);
}

/* ---------- scenariusze testów ---------- */

if ($action === 'cases') {
    $rows = $p->query('SELECT c.*, u.display_name author FROM qa_cases c JOIN qa_users u ON u.id=c.created_by ORDER BY c.project_id, c.module, c.id')->fetchAll();
    foreach ($rows as &$r) { foreach (['id', 'project_id', 'active', 'created_by'] as $k) $r[$k] = (int)$r[$k]; }
    out(['cases' => $rows]);
}

if ($action === 'case_save') {
    require_post();
    need_role($u, 'admin', 'tester');
    $b = body();
    $id = (int)($b['id'] ?? 0);
    $project = project_row((int)($b['project_id'] ?? 0));
    $f = [
        'project_id' => $project['id'], 'module' => str_in($b, 'module', 120), 'title' => str_in($b, 'title', 200),
        'preconditions' => str_in($b, 'preconditions', 10000), 'steps' => str_in($b, 'steps', 20000), 'expected' => str_in($b, 'expected', 10000),
        'priority' => enum_in($b, 'priority', QA_PRIORITIES, 'medium'), 'active' => !empty($b['active']) ? 1 : 0,
    ];
    if ($f['title'] === '' || $f['steps'] === '') out(['error' => 'Scenariusz potrzebuje tytułu i kroków.'], 422);
    $now = qa_now();
    if ($id) {
        $cols = implode(',', array_map(fn($k) => "$k=?", array_keys($f)));
        $p->prepare("UPDATE qa_cases SET $cols, updated_at=? WHERE id=?")->execute([...array_values($f), $now, $id]);
    } else {
        $p->prepare('INSERT INTO qa_cases(project_id,module,title,preconditions,steps,expected,priority,active,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([...array_values($f), $u['id'], $now, $now]);
        $id = (int)$p->lastInsertId();
    }
    out(['ok' => true, 'id' => $id]);
}

if ($action === 'case_delete') {
    require_post();
    need_role($u, 'admin');
    $id = (int)($_GET['id'] ?? 0);
    $q = $p->prepare('SELECT COUNT(*) FROM qa_results WHERE case_id=? AND result<>\'pending\'');
    $q->execute([$id]);
    if ((int)$q->fetchColumn() > 0) out(['error' => 'Scenariusz ma wyniki w przebiegach testów — zamiast usuwać, oznacz go jako nieaktywny.'], 422);
    $p->prepare('DELETE FROM qa_cases WHERE id=?')->execute([$id]);
    out(['ok' => true]);
}

/* ---------- przebiegi testów ---------- */

if ($action === 'runs') {
    $rows = $p->query("SELECT n.*, u.display_name author, p.code project_code, p.name project_name,
            (SELECT COUNT(*) FROM qa_results r WHERE r.run_id=n.id) total,
            (SELECT COUNT(*) FROM qa_results r WHERE r.run_id=n.id AND r.result='pass') pass,
            (SELECT COUNT(*) FROM qa_results r WHERE r.run_id=n.id AND r.result='fail') fail,
            (SELECT COUNT(*) FROM qa_results r WHERE r.run_id=n.id AND r.result='blocked') blocked,
            (SELECT COUNT(*) FROM qa_results r WHERE r.run_id=n.id AND r.result='skip') skip
        FROM qa_runs n JOIN qa_users u ON u.id=n.created_by JOIN qa_projects p ON p.id=n.project_id ORDER BY n.id DESC")->fetchAll();
    foreach ($rows as &$r) foreach (['id', 'project_id', 'created_by', 'total', 'pass', 'fail', 'blocked', 'skip'] as $k) $r[$k] = (int)$r[$k];
    out(['runs' => $rows]);
}

if ($action === 'run_create') {
    require_post();
    need_role($u, 'admin', 'tester');
    $b = body();
    $project = project_row((int)($b['project_id'] ?? 0));
    $name = str_in($b, 'name', 160);
    if ($name === '') out(['error' => 'Nazwij przebieg, np. „Test regresji v0.8”.'], 422);
    $modules = array_values(array_filter(array_map('strval', (array)($b['modules'] ?? []))));
    $q = $p->prepare('SELECT id, module FROM qa_cases WHERE project_id=? AND active=1 ORDER BY module, id');
    $q->execute([$project['id']]);
    $cases = array_filter($q->fetchAll(), fn($c) => !$modules || in_array($c['module'], $modules, true));
    if (!$cases) out(['error' => 'Brak aktywnych scenariuszy dla wybranego projektu i obszarów. Najpierw dodaj scenariusze.'], 422);
    $p->beginTransaction();
    $p->prepare('INSERT INTO qa_runs(project_id,name,environment,version,status,created_by,created_at) VALUES(?,?,?,?,\'open\',?,?)')
        ->execute([$project['id'], $name, str_in($b, 'environment', 80), str_in($b, 'version', 60), $u['id'], qa_now()]);
    $runId = (int)$p->lastInsertId();
    $ins = $p->prepare('INSERT INTO qa_results(run_id,case_id,result,note) VALUES(?,?,\'pending\',\'\')');
    foreach ($cases as $c) $ins->execute([$runId, $c['id']]);
    $p->commit();
    out(['ok' => true, 'id' => $runId]);
}

if ($action === 'run') {
    $id = (int)($_GET['id'] ?? 0);
    $q = $p->prepare('SELECT n.*, u.display_name author, p.code project_code, p.name project_name FROM qa_runs n JOIN qa_users u ON u.id=n.created_by JOIN qa_projects p ON p.id=n.project_id WHERE n.id=?');
    $q->execute([$id]);
    $run = $q->fetch();
    if (!$run) out(['error' => 'Nie znaleziono przebiegu.'], 404);
    $q = $p->prepare('SELECT r.case_id, r.result, r.note, r.issue_id, r.updated_at, t.display_name tester_name,
            c.module, c.title, c.preconditions, c.steps, c.expected, c.priority, i.status issue_status
        FROM qa_results r JOIN qa_cases c ON c.id=r.case_id LEFT JOIN qa_users t ON t.id=r.tester_id LEFT JOIN qa_issues i ON i.id=r.issue_id
        WHERE r.run_id=? ORDER BY c.module, c.id');
    $q->execute([$id]);
    $run['results'] = array_map(function ($r) use ($run) {
        $r['case_id'] = (int)$r['case_id'];
        $r['issue_id'] = $r['issue_id'] === null ? null : (int)$r['issue_id'];
        $r['issue_key'] = $r['issue_id'] ? $run['project_code'] . '-' . str_pad((string)$r['issue_id'], 4, '0', STR_PAD_LEFT) : null;
        return $r;
    }, $q->fetchAll());
    $run['id'] = (int)$run['id'];
    $run['project_id'] = (int)$run['project_id'];
    out(['run' => $run]);
}

if ($action === 'result_save') {
    require_post();
    need_role($u, 'admin', 'tester');
    $b = body();
    $runId = (int)($b['run_id'] ?? 0);
    $q = $p->prepare('SELECT status FROM qa_runs WHERE id=?');
    $q->execute([$runId]);
    $st = $q->fetchColumn();
    if (!$st) out(['error' => 'Nie znaleziono przebiegu.'], 404);
    if ($st !== 'open') out(['error' => 'Przebieg jest zamknięty.'], 422);
    $result = enum_in($b, 'result', QA_RESULTS, 'pending');
    $q = $p->prepare('UPDATE qa_results SET result=?, note=?, tester_id=?, updated_at=? WHERE run_id=? AND case_id=?');
    $q->execute([$result, str_in($b, 'note', 10000), $u['id'], qa_now(), $runId, (int)($b['case_id'] ?? 0)]);
    out(['ok' => true]);
}

if ($action === 'run_status') {
    require_post();
    need_role($u, 'admin', 'tester');
    $b = body();
    $status = ($b['status'] ?? '') === 'closed' ? 'closed' : 'open';
    $p->prepare('UPDATE qa_runs SET status=?, closed_at=? WHERE id=?')->execute([$status, $status === 'closed' ? qa_now() : null, (int)($b['id'] ?? 0)]);
    out(['ok' => true]);
}

out(['error' => 'Nieznana operacja.'], 404);
