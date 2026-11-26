<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/mail.php';
require dirname(__DIR__) . '/app/backup.php';

qa_security_headers();
if (!qa_config()) out(['error' => 'Aplikacja nie jest zainstalowana.', 'install' => true], 503);

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/*
 * Klucz API (1.19) — dla narzędzi, np. Claude Code (narzedzia/qa.php). Nagłówek X-QA-Key (albo Authorization: Bearer).
 * Bez sesji i bez CSRF (klucz nie jest wysyłany przez przeglądarkę automatycznie), tylko akcje z listy poniżej,
 * z uprawnieniami użytkownika, który utworzył klucz.
 */
const QA_KEY_ACTIONS = ['meta', 'issues', 'issue', 'file', 'rfile', 'comment', 'issue_status', 'cases', 'runs', 'run', 'readiness'];
$API_KEY_USER = null;
$rawKey = (string)($_SERVER['HTTP_X_QA_KEY'] ?? '');
if ($rawKey === '' && preg_match('/^Bearer\s+(fbqa_[0-9a-f]{40})$/', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''), $km)) $rawKey = $km[1];
if ($rawKey !== '') {
    $kdb = db();
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    $since = date('Y-m-d H:i:s', time() - 900);
    $q = $kdb->prepare("SELECT COUNT(*) FROM qa_login_attempts WHERE ip=? AND username='#apikey' AND created_at>?");
    $q->execute([$ip, $since]);
    if ((int)$q->fetchColumn() >= 8) out(['error' => 'Zbyt wiele błędnych kluczy. Spróbuj za 15 minut.'], 429);
    $q = $kdb->prepare('SELECT k.id kid, u.id, u.username, u.display_name, u.email, u.role, u.notify FROM qa_api_keys k JOIN qa_users u ON u.id=k.user_id
        WHERE k.key_hash=? AND k.revoked_at IS NULL AND u.active=1');
    $q->execute([hash('sha256', $rawKey)]);
    $kr = $q->fetch();
    if (!$kr) {
        $kdb->prepare('INSERT INTO qa_login_attempts(ip,username,created_at) VALUES(?,?,?)')->execute([$ip, '#apikey', qa_now()]);
        out(['error' => 'Nieprawidłowy albo wycofany klucz API.'], 401);
    }
    if (!in_array($action, QA_KEY_ACTIONS, true)) out(['error' => 'Kluczem API nie można wykonać tej czynności.'], 403);
    $kdb->prepare('UPDATE qa_api_keys SET last_used_at=? WHERE id=?')->execute([qa_now(), $kr['kid']]);
    $API_KEY_USER = ['id' => (int)$kr['id'], 'username' => $kr['username'], 'name' => $kr['display_name'], 'email' => $kr['email'],
        'role' => $kr['role'], 'must_change_pw' => false, 'notify' => (bool)$kr['notify'], 'api_key' => true];
    $_SESSION = [];
} else {
    qa_session_start();
}

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
    if ($GLOBALS['API_KEY_USER']) return; // klucz API: bez ciasteczek, więc bez ryzyka CSRF
    check_origin();
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($t === '' || !hash_equals($_SESSION['csrf'] ?? '', $t)) out(['error' => 'Sesja wygasła. Odśwież stronę.', 'csrf' => true], 419);
}
function current_user(): ?array
{
    static $u = false;
    if ($GLOBALS['API_KEY_USER']) return $GLOBALS['API_KEY_USER'];
    if ($u !== false) return $u;
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $q = db()->prepare('SELECT id,username,display_name,email,role,active,must_change_pw,notify FROM qa_users WHERE id=?');
        $q->execute([(int)$_SESSION['uid']]);
        $r = $q->fetch();
        if ($r && (int)$r['active'] === 1) {
            $u = ['id' => (int)$r['id'], 'username' => $r['username'], 'name' => $r['display_name'], 'email' => $r['email'],
                'role' => $r['role'], 'must_change_pw' => (bool)$r['must_change_pw'], 'notify' => (bool)$r['notify']];
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
    $q = db()->prepare('SELECT i.*, p.code project_code, p.name project_name, r.display_name reporter_name, a.display_name assignee_name,
        (SELECT dp.code FROM qa_issues d JOIN qa_projects dp ON dp.id=d.project_id WHERE d.id=i.duplicate_of) dup_code
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
    if (array_key_exists('duplicate_of', $r)) $r['duplicate_of'] = $r['duplicate_of'] === null ? null : (int)$r['duplicate_of'];
    if (!empty($r['dup_code'])) $r['duplicate_key'] = $r['dup_code'] . '-' . str_pad((string)$r['duplicate_of'], 4, '0', STR_PAD_LEFT);
    unset($r['dup_code']);
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
        'suggestion' => str_in($b, 'suggestion', 4000),
        'environment' => str_in($b, 'environment', 80),
        'version' => str_in($b, 'version', 60),
        'device' => str_in($b, 'device', 80),
        'browser' => str_in($b, 'browser', 80),
        'url' => str_in($b, 'url', 1000),
        'sys_role' => str_in($b, 'sys_role', 80),
        'sys_account' => str_in($b, 'sys_account', 120),
    ];
    if (preg_match('/has(ł|l)o|password|pass\s*[:=]/iu', $f['sys_account'])) out(['error' => 'W polu „Konto testowe” wpisz tylko nazwę konta (np. klub@pzball.test), bez hasła.'], 422);
    if ($f['title'] === '' || $f['description'] === '') out(['error' => 'Uzupełnij tytuł i opis problemu.'], 422);
    if ($f['url'] !== '' && !preg_match('~^https?://~i', $f['url'])) out(['error' => 'Adres strony musi zaczynać się od http:// lub https://'], 422);
    return $f;
}
/** Zamienia „PZB-0012”, „#12” albo „12” na id zgłoszenia (0, gdy nie da się odczytać). */
function issue_id_from_key(string $key): int
{
    return preg_match('/(\d+)\s*$/', trim($key), $m) ? (int)$m[1] : 0;
}
function issue_key_of(int $id): string
{
    $q = db()->prepare('SELECT p.code FROM qa_issues i JOIN qa_projects p ON p.id=i.project_id WHERE i.id=?');
    $q->execute([$id]);
    $c = $q->fetchColumn();
    return $c === false ? '#' . $id : $c . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}
function user_exists_active(?int $id): ?int
{
    if (!$id) return null;
    $q = db()->prepare('SELECT id FROM qa_users WHERE id=? AND active=1');
    $q->execute([$id]);
    return $q->fetchColumn() ? $id : null;
}
/* ---------- powiadomienia e-mail (1.6) ---------- */

/** Wysyła powiadomienie do wskazanych osób (aktywne, z e-mailem, z włączonymi powiadomieniami), z pominięciem autora zmiany. */
function notify(array $userIds, int $actorId, array $issue, string $subject, string $text): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($x) => $x > 0 && $x !== $actorId)));
    if (!$ids || !qa_mail_enabled()) return;
    $q = db()->prepare('SELECT email FROM qa_users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ") AND active=1 AND notify=1 AND email<>''");
    $q->execute($ids);
    $link = qa_base_url() . '#/z/' . $issue['id'];
    $body = $text . "\n\n" . $issue['key'] . ': ' . $issue['title'] . "\nWaga: " . (QA_SEVERITIES[$issue['severity'] ?? ''] ?? '—')
        . ' · Status: ' . (QA_STATUSES[$issue['status']] ?? $issue['status']) . "\n\nOtwórz: $link";
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $email) qa_mail((string)$email, '[' . $issue['key'] . '] ' . $subject, $body);
}
function admin_ids(): array
{
    return array_map('intval', db()->query("SELECT id FROM qa_users WHERE role='admin' AND active=1")->fetchAll(PDO::FETCH_COLUMN));
}

/** Zestawy scenariuszy: z tekstu („a; b, c”) albo tablicy → uporządkowana lista bez powtórzeń. */
function suites_in(mixed $v): array
{
    $list = is_array($v) ? $v : preg_split('/[;,\n]+/u', (string)$v);
    $out = [];
    foreach ($list as $x) {
        $x = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$x)), 0, 40);
        if ($x !== '' && !in_array(mb_strtolower($x), array_map('mb_strtolower', $out), true)) $out[] = $x;
    }
    return array_slice($out, 0, 12);
}
function suites_str(array $s): string { return mb_substr(implode('; ', $s), 0, 500); }

function lines(string $s): array
{
    // Flaga /u jest konieczna: bez niej \R łapie bajt 0x85, który jest częścią litery „ą” (c4 85), i psuje UTF-8.
    return array_values(array_filter(array_map('trim', preg_split('/\R/u', $s) ?: []), fn($x) => $x !== ''));
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
    // Login albo adres e-mail konta (26.11.2026); dopasowanie po loginie ma pierwszeństwo przed e-mailem.
    $name = mb_strtolower(str_in($b, 'username', 190));
    $pw = (string)($b['password'] ?? '');
    if ($name === '' || $pw === '') out(['error' => 'Wpisz login albo e-mail i hasło.'], 422);
    $p = db();
    $since = date('Y-m-d H:i:s', time() - 900);
    $q = $p->prepare('SELECT COUNT(*) FROM qa_login_attempts WHERE (ip=? OR username=?) AND created_at>?');
    $q->execute([client_ip(), mb_substr($name, 0, 80), $since]);
    if ((int)$q->fetchColumn() >= 8) out(['error' => 'Zbyt wiele nieudanych prób. Spróbuj ponownie za 15 minut.'], 429);
    $q = $p->prepare("SELECT * FROM qa_users WHERE username=? OR (email<>'' AND LOWER(email)=?) ORDER BY CASE WHEN username=? THEN 0 ELSE 1 END, id LIMIT 1");
    $q->execute([$name, $name, $name]);
    $r = $q->fetch();
    if (!$r || (int)$r['active'] !== 1 || !password_verify($pw, $r['password_hash'])) {
        $p->prepare('INSERT INTO qa_login_attempts(ip,username,created_at) VALUES(?,?,?)')->execute([client_ip(), mb_substr($name, 0, 80), qa_now()]);
        usleep(400000);
        out(['error' => 'Nieprawidłowy login, e-mail lub hasło.'], 401);
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

/* ---------- reset hasła (1.7) — bez logowania ---------- */

if ($action === 'reset_request') {
    require_post(); // token CSRF z sesji niezalogowanej (akcja session)
    $who = mb_strtolower(str_in(body(), 'login', 190));
    $same = ['ok' => true, 'message' => 'Jeśli konto istnieje i ma adres e-mail, wysłaliśmy na niego link do ustawienia nowego hasła. Link jest ważny 1 godzinę.'];
    if ($who === '') out(['error' => 'Wpisz login albo adres e-mail.'], 422);
    $p = db();
    $q = $p->prepare('SELECT COUNT(*) FROM qa_password_resets WHERE ip=? AND created_at>?');
    $q->execute([client_ip(), date('Y-m-d H:i:s', time() - 900)]);
    if ((int)$q->fetchColumn() >= 5) out(['error' => 'Zbyt wiele prób. Spróbuj ponownie za 15 minut.'], 429);
    $q = $p->prepare("SELECT id, email, display_name FROM qa_users WHERE active=1 AND email<>'' AND (LOWER(username)=? OR LOWER(email)=?)");
    $q->execute([$who, $who]);
    $user = $q->fetch();
    $userId = $user ? (int)$user['id'] : 0;
    // Dla nieistniejącego konta też zapisujemy próbę (user_id NULL, token nieużywalny) — ten sam limit i podobny czas odpowiedzi.
    $token = bin2hex(random_bytes(32));
    if ($user) $p->prepare('UPDATE qa_password_resets SET used_at=? WHERE user_id=? AND used_at IS NULL')->execute([qa_now(), $userId]);
    if ($user) {
        $p->prepare('INSERT INTO qa_password_resets(user_id,token_hash,ip,created_at,expires_at) VALUES(?,?,?,?,?)')
            ->execute([$userId, hash('sha256', $token), client_ip(), qa_now(), date('Y-m-d H:i:s', time() + 3600)]);
        qa_mail((string)$user['email'], 'Ustawienie nowego hasła — FULLBASE QA',
            "Dzień dobry,\n\nktoś (prawdopodobnie Ty) poprosił o ustawienie nowego hasła do FULLBASE QA.\n\nOtwórz link, aby ustawić nowe hasło (ważny 1 godzinę, działa jeden raz):\n"
            . qa_base_url() . '#/reset/' . $token . "\n\nJeśli to nie Ty — zignoruj tę wiadomość; hasło się nie zmieni.");
    } else {
        $p->prepare('INSERT INTO qa_password_resets(user_id,token_hash,ip,created_at,expires_at,used_at) VALUES(NULL,?,?,?,?,?)')
            ->execute([hash('sha256', $token), client_ip(), qa_now(), qa_now(), qa_now()]);
        usleep(150000);
    }
    out($same);
}

if ($action === 'reset_confirm') {
    require_post();
    $b = body();
    $token = (string)($b['token'] ?? '');
    $new = (string)($b['new'] ?? '');
    $bad = 'Link jest nieważny albo wygasł. Poproś o nowy na stronie logowania („Nie pamiętasz hasła?”).';
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) out(['error' => $bad], 422);
    if ($e = qa_password_problem($new)) out(['error' => $e], 422);
    $p = db();
    $q = $p->prepare('SELECT r.id, r.user_id FROM qa_password_resets r JOIN qa_users u ON u.id=r.user_id
        WHERE r.token_hash=? AND r.used_at IS NULL AND r.expires_at>? AND u.active=1');
    $q->execute([hash('sha256', $token), qa_now()]);
    $r = $q->fetch();
    if (!$r) out(['error' => $bad], 422);
    $p->beginTransaction();
    $p->prepare('UPDATE qa_password_resets SET used_at=? WHERE id=?')->execute([qa_now(), $r['id']]);
    $p->prepare('UPDATE qa_users SET password_hash=?, must_change_pw=0 WHERE id=?')->execute([qa_hash_password($new), $r['user_id']]);
    $p->prepare('DELETE FROM qa_login_attempts WHERE username=(SELECT username FROM qa_users WHERE id=?)')->execute([$r['user_id']]);
    $p->commit();
    out(['ok' => true]);
}

$u = auth();
$p = db();
$DRIVER = (qa_config()['db']['driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
if (!$API_KEY_USER) qa_backup_maybe($p, $DRIVER); // raz na dobę

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

if ($action === 'account_save') {
    require_post();
    $b = body();
    $p->prepare('UPDATE qa_users SET notify=? WHERE id=?')->execute([!empty($b['notify']) ? 1 : 0, $u['id']]);
    out(['ok' => true, 'notify' => !empty($b['notify'])]);
}

/* ---------- słowniki ---------- */

if ($action === 'meta') {
    $projects = $p->query('SELECT * FROM qa_projects ORDER BY position, id')->fetchAll();
    foreach ($projects as &$pr) {
        $pr['id'] = (int)$pr['id'];
        $pr['active'] = (int)$pr['active'];
        $pr['modules'] = lines($pr['modules']);
        $pr['environments'] = lines($pr['environments']);
        $pr['tested_roles'] = lines((string)($pr['tested_roles'] ?? ''));
    }
    $users = $p->query("SELECT id, display_name name, role, active FROM qa_users ORDER BY display_name")->fetchAll();
    foreach ($users as &$x) { $x['id'] = (int)$x['id']; $x['active'] = (int)$x['active']; }
    out(['projects' => $projects, 'users' => $users, 'roles' => QA_ROLES, 'kinds' => QA_KINDS, 'severities' => QA_SEVERITIES, 'priorities' => QA_PRIORITIES,
        'statuses' => QA_STATUSES, 'reject_reasons' => QA_REJECT_REASONS, 'results' => QA_RESULTS, 'upload_max' => min(QA_UPLOAD_MAX, upload_limit()), 'mail_enabled' => qa_mail_enabled()]);
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
    $rows = $p->query('SELECT i.id,i.project_id,i.kind,i.title,i.module,i.priority,i.severity,i.status,i.environment,i.version,i.device,i.browser,i.sys_role,i.sys_account,i.reject_reason,i.duplicate_of,i.fixed_in,
            (SELECT dp.code FROM qa_issues d JOIN qa_projects dp ON dp.id=d.project_id WHERE d.id=i.duplicate_of) dup_code,
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
    $q = $p->prepare('SELECT id FROM qa_cases WHERE issue_id=?');
    $q->execute([$issue['id']]);
    $issue['regression_case'] = ($c = $q->fetchColumn()) ? (int)$c : null;
    $q = $p->prepare('SELECT i.id, i.title, i.status, p.code FROM qa_issues i JOIN qa_projects p ON p.id=i.project_id WHERE i.duplicate_of=? ORDER BY i.id');
    $q->execute([$issue['id']]);
    $issue['duplicates'] =array_map(fn($d) => ['id' => (int)$d['id'], 'key' => $d['code'] . '-' . str_pad((string)$d['id'], 4, '0', STR_PAD_LEFT), 'title' => $d['title'], 'status' => $d['status']], $q->fetchAll());
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
    $q = $p->prepare('INSERT INTO qa_issues(project_id,kind,title,module,priority,severity,status,description,steps,expected,suggestion,environment,version,device,browser,url,sys_role,sys_account,solution,reporter_id,assignee_id,created_at,updated_at)
        VALUES(?,?,?,?,?,?,\'new\',?,?,?,?,?,?,?,?,?,?,?,\'\',?,NULL,?,?)');
    $q->execute([$project['id'], $f['kind'], $f['title'], $f['module'], $f['priority'], $f['severity'], $f['description'], $f['steps'], $f['expected'], $f['suggestion'],
        $f['environment'], $f['version'], $f['device'], $f['browser'], $f['url'], $f['sys_role'], $f['sys_account'], $u['id'], $now, $now]);
    $id = (int)$p->lastInsertId();
    log_act($id, $u['id'], 'created', 'Utworzono zgłoszenie.');
    // powiązanie z wynikiem testu
    if (!empty($b['run_id']) && !empty($b['case_id'])) {
        $q = $p->prepare('UPDATE qa_results SET issue_id=?, result=CASE WHEN result IN (\'pending\',\'pass\') THEN \'fail\' ELSE result END, tester_id=?, updated_at=? WHERE run_id=? AND case_id=?');
        $q->execute([$id, $u['id'], $now, (int)$b['run_id'], (int)$b['case_id']]);
        if ($q->rowCount()) {
            log_act($id, $u['id'], 'field', 'Powiązano z przebiegiem testów #' . (int)$b['run_id'] . '.');
            // zrzuty dodane przy wyniku przechodzą do zgłoszenia (kopia pliku)
            $q = $p->prepare('SELECT * FROM qa_result_files WHERE run_id=? AND case_id=? ORDER BY id');
            $q->execute([(int)$b['run_id'], (int)$b['case_id']]);
            foreach ($q->fetchAll() as $rf) {
                $src = QA_DATA . '/uploads/' . basename($rf['stored_name']);
                $dst = bin2hex(random_bytes(16)) . '.' . pathinfo($rf['stored_name'], PATHINFO_EXTENSION);
                if (is_file($src) && copy($src, QA_DATA . '/uploads/' . $dst)) {
                    $p->prepare('INSERT INTO qa_attachments(issue_id,user_id,orig_name,stored_name,mime,size,created_at) VALUES(?,?,?,?,?,?,?)')
                        ->execute([$id, $rf['user_id'], $rf['orig_name'], $dst, $rf['mime'], $rf['size'], $now]);
                }
            }
        }
    }
    $p->commit();
    $new = issue_row($id);
    if (in_array($new['severity'], ['blocker', 'data'], true)) {
        notify(admin_ids(), $u['id'], $new, 'Nowe zgłoszenie: ' . QA_SEVERITIES[$new['severity']], $u['name'] . ' zgłosił(a) problem o wadze „' . QA_SEVERITIES[$new['severity']] . '”.');
    }
    out(['issue' => $new]);
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
        'expected' => 'oczekiwany wynik', 'suggestion' => 'sugestia naprawy', 'environment' => 'środowisko', 'version' => 'wersja', 'device' => 'urządzenie', 'browser' => 'przeglądarka',
        'url' => 'adres', 'sys_role' => 'rola w testowanym systemie', 'sys_account' => 'konto testowe', 'solution' => 'rozwiązanie', 'assignee_id' => 'osoba naprawiająca', 'project_id' => 'projekt'];
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
        if ($sets['assignee_id']) notify([$sets['assignee_id']], $u['id'], issue_row($id), 'Przypisano Ci zgłoszenie', $u['name'] . ' przypisał(a) Ci zgłoszenie do naprawy.');
        $changed = array_values(array_diff($changed, ['osoba naprawiająca']));
    }
    if ($changed) log_act($id, $u['id'], 'field', 'Zmieniono: ' . implode(', ', $changed) . '.');
    out(['issue' => issue_row($id)]);
}

/** Zmiana statusu z pełnymi regułami (uprawnienia, powody, wersja, historia, e-maile). Błąd → QaError. */
function change_status(array $u, int $id, array $b): array
{
    $p = db();
    $issue = issue_row($id);
    $to = (string)($b['status'] ?? '');
    $comment = str_in($b, 'comment', 20000);
    if (!array_key_exists($to, QA_STATUSES)) throw new QaError('Nieznany status.', 422);
    $from = $issue['status'];
    if ($from === $to) return $issue;
    $allowed = false;
    if ($u['role'] === 'admin') $allowed = true;
    elseif ($u['role'] === 'tester') {
        // Tester weryfikuje poprawki i może ponownie otworzyć zamknięte zgłoszenie.
        $allowed = ($from === 'fixed' && in_array($to, ['closed', 'reopened'], true))
            || (in_array($from, ['closed', 'rejected'], true) && $to === 'reopened');
    }
    if (!$allowed) throw new QaError('Nie możesz zmienić statusu z „' . QA_STATUSES[$from] . '” na „' . QA_STATUSES[$to] . '”.', 403);
    if ($to === 'reopened' && $comment === '') throw new QaError('Dopisz komentarz — co dalej nie działa.', 422);
    $reason = '';
    $dupOf = null;
    if ($to === 'rejected') {
        $reason = (string)($b['reject_reason'] ?? '');
        if (!array_key_exists($reason, QA_REJECT_REASONS)) throw new QaError('Wybierz powód odrzucenia.', 422);
        if ($reason === 'duplicate') {
            $dupOf = issue_id_from_key((string)($b['duplicate_of'] ?? ''));
            if ($dupOf === $id) throw new QaError('Zgłoszenie nie może być duplikatem samego siebie.', 422);
            $q = $p->prepare('SELECT id FROM qa_issues WHERE id=?');
            $q->execute([$dupOf]);
            if (!$dupOf || !$q->fetchColumn()) throw new QaError('Podaj numer oryginalnego zgłoszenia, np. PZB-0012.', 422);
        }
    }
    $fixedIn = $issue['fixed_in'];
    if ($to === 'fixed') {
        $fixedIn = str_in($b, 'fixed_in', 60) ?: $fixedIn;
        if ($fixedIn === '') throw new QaError('Wpisz wersję, w której jest poprawka (np. 0.9.3). Ustawiaj „Do weryfikacji” dopiero po wgraniu poprawki na demo.', 422);
        if ($fixedIn !== $issue['fixed_in']) $p->prepare('UPDATE qa_issues SET fixed_in=? WHERE id=?')->execute([$fixedIn, $id]);
    }
    $closed = in_array($to, ['closed', 'rejected'], true) ? qa_now() : null;
    // Ponowne otwarcie czyści powód odrzucenia; historia zostaje w dzienniku zmian.
    $p->prepare('UPDATE qa_issues SET status=?, closed_at=?, reject_reason=?, duplicate_of=?, updated_at=? WHERE id=?')->execute([$to, $closed, $reason, $dupOf, qa_now(), $id]);
    $msg = 'Status: ' . QA_STATUSES[$from] . ' → ' . QA_STATUSES[$to] . '.';
    if ($to === 'rejected') {
        $msg = 'Odrzucone — powód: ' . QA_REJECT_REASONS[$reason] . ($dupOf ? ' ' . issue_key_of($dupOf) : '') . '.';
        if ($dupOf) {
            log_act($dupOf, $u['id'], 'field', 'Zgłoszenie ' . $issue['key'] . ' oznaczono jako duplikat tego zgłoszenia.');
            touch_issue($dupOf);
        }
    }
    if ($to === 'fixed') $msg .= ' Poprawka w wersji ' . $fixedIn . '.';
    if ($from === 'fixed' && $to === 'closed') $msg = 'Weryfikacja OK — poprawka działa. Zgłoszenie zamknięte.';
    if ($from === 'fixed' && $to === 'reopened') $msg = 'Weryfikacja negatywna — poprawka nie działa. Ponownie otwarte.';
    log_act($id, $u['id'], $from === 'fixed' && in_array($to, ['closed', 'reopened'], true) ? 'verify' : 'status', $msg);
    if ($comment !== '') log_act($id, $u['id'], 'comment', $comment);
    $after = issue_row($id);
    if ($to === 'fixed') {
        notify([$after['reporter_id']], $u['id'], $after, 'Poprawka czeka na Twoją weryfikację',
            'Poprawka Twojego zgłoszenia jest gotowa' . ($after['fixed_in'] ? ' (wersja ' . $after['fixed_in'] . ')' : '') . '. Sprawdź ją i kliknij „Działa — zamknij” albo „Nadal nie działa”.'
            . ($comment !== '' ? "\n\nKomentarz: " . $comment : ''));
    }
    if ($to === 'reopened') {
        notify([...admin_ids(), (int)$after['assignee_id']], $u['id'], $after, 'Zgłoszenie otwarte ponownie',
            $u['name'] . ' otworzył(a) zgłoszenie ponownie.' . ($comment !== '' ? "\n\nKomentarz: " . $comment : ''));
    }
    return $after;
}


final class QaError extends RuntimeException {}

if ($action === 'issue_status') {
    require_post();
    try { out(['issue' => change_status($u, (int)($_GET['id'] ?? 0), body())]); }
    catch (QaError $e) { out(['error' => $e->getMessage()], $e->getCode()); }
}

/* ---------- masowe akcje (1.12) ---------- */

if ($action === 'issues_bulk') {
    require_post();
    need_role($u, 'admin');
    $b = body();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($b['ids'] ?? [])))));
    if (!$ids) out(['error' => 'Zaznacz co najmniej jedno zgłoszenie.'], 422);
    if (count($ids) > 200) out(['error' => 'Najwyżej 200 zgłoszeń naraz.'], 422);
    $what = (string)($b['what'] ?? '');
    $done = 0;
    $errors = [];
    foreach ($ids as $id) {
        try {
            if ($what === 'status') {
                $to = (string)($b['status'] ?? '');
                if ($to === 'rejected') throw new QaError('Odrzucenie wymaga powodu — zrób je pojedynczo.', 422);
                change_status($u, $id, ['status' => $to, 'fixed_in' => $b['fixed_in'] ?? '', 'comment' => $b['comment'] ?? '']);
            } elseif ($what === 'assignee' || $what === 'priority') {
                $before = issue_row($id);
                if ($what === 'priority') {
                    $v = (string)($b['priority'] ?? '');
                    if (!isset(QA_PRIORITIES[$v])) throw new QaError('Nieznany priorytet.', 422);
                    if ($v === $before['priority']) continue;
                    $p->prepare('UPDATE qa_issues SET priority=?, updated_at=? WHERE id=?')->execute([$v, qa_now(), $id]);
                    log_act($id, $u['id'], 'field', 'Priorytet: ' . QA_PRIORITIES[$before['priority']] . ' → ' . QA_PRIORITIES[$v] . '.');
                } else {
                    $v = user_exists_active(isset($b['assignee_id']) ? (int)$b['assignee_id'] : null);
                    if ($v === $before['assignee_id']) continue;
                    $p->prepare('UPDATE qa_issues SET assignee_id=?, updated_at=? WHERE id=?')->execute([$v, qa_now(), $id]);
                    $n = '—';
                    if ($v) { $q = $p->prepare('SELECT display_name FROM qa_users WHERE id=?'); $q->execute([$v]); $n = (string)$q->fetchColumn(); }
                    log_act($id, $u['id'], 'field', 'Przypisano: ' . $n . '.');
                    if ($v) notify([$v], $u['id'], issue_row($id), 'Przypisano Ci zgłoszenie', $u['name'] . ' przypisał(a) Ci zgłoszenie do naprawy.');
                }
            } else {
                out(['error' => 'Nieznana akcja masowa.'], 422);
            }
            $done++;
        } catch (QaError $e) {
            $errors[] = issue_key_of($id) . ': ' . $e->getMessage();
        }
    }
    out(['ok' => true, 'done' => $done, 'errors' => $errors]);
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

/** Sprawdza i zapisuje przesłane pliki w data/uploads. Najpierw kontrola wszystkich, potem zapis — zły plik nie zostawia połowy. */
function store_uploads(): array
{
    $files = $_FILES['files'] ?? null;
    if (!$files || !is_array($files['name'])) out(['error' => 'Nie wybrano plików (lub plik przekracza limit serwera).'], 422);
    $count = count($files['name']);
    if ($count > 10) out(['error' => 'Najwyżej 10 plików naraz.'], 422);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $ok = [];
    for ($i = 0; $i < $count; $i++) {
        $name = (string)$files['name'][$i];
        if ($files['error'][$i] !== UPLOAD_ERR_OK) out(['error' => "Plik „{$name}” nie został przesłany (za duży albo przerwany)."], 422);
        if ($files['size'][$i] > QA_UPLOAD_MAX) out(['error' => "Plik „{$name}” przekracza 10 MB."], 413);
        $mime = (string)$finfo->file($files['tmp_name'][$i]);
        if ($mime === 'text/x-log' || ($mime === 'text/plain' && preg_match('/\.(log|txt|csv|json)$/i', $name))) $mime = 'text/plain';
        if (!isset(QA_UPLOAD_TYPES[$mime])) out(['error' => "Plik „{$name}” ma niedozwolony typ ($mime). Dozwolone: obrazy, PDF, TXT/LOG, CSV, JSON, MP4/WEBM, ZIP."], 415);
        $ok[] = ['tmp' => $files['tmp_name'][$i], 'orig' => mb_substr(preg_replace('/[^\p{L}\p{N}._ ()-]+/u', '_', $name) ?: 'plik', 0, 200),
            'mime' => $mime, 'size' => (int)$files['size'][$i], 'stored' => bin2hex(random_bytes(16)) . '.' . QA_UPLOAD_TYPES[$mime]];
    }
    foreach ($ok as $x) if (!move_uploaded_file($x['tmp'], QA_DATA . '/uploads/' . $x['stored'])) out(['error' => 'Nie udało się zapisać pliku na serwerze.'], 500);
    return $ok;
}
/** Wydaje plik z data/uploads z bezpiecznymi nagłówkami. */
function serve_file(?array $f): never
{
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

if ($action === 'upload') {
    require_post();
    need_role($u, 'admin', 'tester');
    $id = (int)($_GET['id'] ?? 0);
    issue_row($id);
    $saved = [];
    foreach (store_uploads() as $x) {
        $p->prepare('INSERT INTO qa_attachments(issue_id,user_id,orig_name,stored_name,mime,size,created_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$id, $u['id'], $x['orig'], $x['stored'], $x['mime'], $x['size'], qa_now()]);
        $saved[] = $x['orig'];
    }
    log_act($id, $u['id'], 'attachment', 'Dodano załączniki: ' . implode(', ', $saved) . '.');
    touch_issue($id);
    out(['ok' => true, 'saved' => count($saved)]);
}

if ($action === 'result_upload') {
    require_post();
    need_role($u, 'admin', 'tester');
    $runId = (int)($_GET['run'] ?? 0);
    $caseId = (int)($_GET['case'] ?? 0);
    $q = $p->prepare('SELECT n.status FROM qa_results r JOIN qa_runs n ON n.id=r.run_id WHERE r.run_id=? AND r.case_id=?');
    $q->execute([$runId, $caseId]);
    $st = $q->fetchColumn();
    if (!$st) out(['error' => 'Nie znaleziono scenariusza w tym przebiegu.'], 404);
    if ($st !== 'open') out(['error' => 'Przebieg jest zamknięty.'], 422);
    $n = 0;
    foreach (store_uploads() as $x) {
        $p->prepare('INSERT INTO qa_result_files(run_id,case_id,user_id,orig_name,stored_name,mime,size,created_at) VALUES(?,?,?,?,?,?,?,?)')
            ->execute([$runId, $caseId, $u['id'], $x['orig'], $x['stored'], $x['mime'], $x['size'], qa_now()]);
        $n++;
    }
    out(['ok' => true, 'saved' => $n]);
}

if ($action === 'rfile') {
    $q = $p->prepare('SELECT * FROM qa_result_files WHERE id=?');
    $q->execute([(int)($_GET['id'] ?? 0)]);
    serve_file($q->fetch() ?: null);
}

if ($action === 'rfile_delete') {
    require_post();
    $q = $p->prepare('SELECT f.*, n.status run_status FROM qa_result_files f JOIN qa_runs n ON n.id=f.run_id WHERE f.id=?');
    $q->execute([(int)($_GET['id'] ?? 0)]);
    $f = $q->fetch();
    if (!$f) out(['error' => 'Nie znaleziono pliku.'], 404);
    if ($u['role'] !== 'admin' && (int)$f['user_id'] !== $u['id']) out(['error' => 'Możesz usuwać tylko własne pliki.'], 403);
    if ($f['run_status'] !== 'open' && $u['role'] !== 'admin') out(['error' => 'Przebieg jest zamknięty. Pliki może usunąć tylko administrator.'], 422);
    $p->prepare('DELETE FROM qa_result_files WHERE id=?')->execute([$f['id']]);
    @unlink(QA_DATA . '/uploads/' . basename($f['stored_name']));
    out(['ok' => true]);
}

if ($action === 'file') {
    $q = $p->prepare('SELECT * FROM qa_attachments WHERE id=?');
    $q->execute([(int)($_GET['id'] ?? 0)]);
    serve_file($q->fetch() ?: null);
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
    $rows = $p->query('SELECT i.*, p.code project_code, p.name project_name, r.display_name reporter_name, a.display_name assignee_name,
        (SELECT dp.code FROM qa_issues d JOIN qa_projects dp ON dp.id=d.project_id WHERE d.id=i.duplicate_of) dup_code
        FROM qa_issues i JOIN qa_projects p ON p.id=i.project_id JOIN qa_users r ON r.id=i.reporter_id LEFT JOIN qa_users a ON a.id=i.assignee_id ORDER BY i.id')->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="zgloszenia-' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v; // ochrona przed formułami w Excelu
    fputcsv($o, ['Numer', 'Projekt', 'Rodzaj', 'Tytuł', 'Obszar', 'Waga', 'Priorytet', 'Status', 'Powód odrzucenia', 'Opis', 'Kroki do odtworzenia problemu', 'Oczekiwane', 'Sugestia naprawy', 'Środowisko', 'Wersja', 'Naprawiono w wersji', 'Rola w testowanym systemie', 'Konto testowe', 'Urządzenie', 'Przeglądarka', 'Adres', 'Rozwiązanie', 'Zgłosił', 'Naprawia', 'Utworzono', 'Zmieniono', 'Zamknięto'], ';');
    foreach ($rows as $r) {
        $r = cast_issue($r);
        fputcsv($o, array_map($safe, [$r['key'], $r['project_name'], QA_KINDS[$r['kind']] ?? $r['kind'], $r['title'], $r['module'], QA_SEVERITIES[$r['severity']] ?? '', QA_PRIORITIES[$r['priority']] ?? '', QA_STATUSES[$r['status']] ?? '', (QA_REJECT_REASONS[$r['reject_reason']] ?? '') . ($r['duplicate_of'] ? ' ' . ($r['duplicate_key'] ?? '#' . $r['duplicate_of']) : ''),
            $r['description'], $r['steps'], $r['expected'], $r['suggestion'], $r['environment'], $r['version'], $r['fixed_in'], $r['sys_role'], $r['sys_account'], $r['device'], $r['browser'], $r['url'], $r['solution'],
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

/* ---------- kopie zapasowe (1.20) ---------- */

if ($action === 'backups') {
    need_role($u, 'admin');
    out(['backups' => qa_backup_list(), 'keep' => QA_BACKUP_KEEP, 'last' => qa_meta_get($p, 'backup_at')]);
}

if ($action === 'backup_now') {
    require_post();
    need_role($u, 'admin');
    try { out(['ok' => true, 'name' => qa_backup_create($p, $DRIVER)]); }
    catch (Throwable $e) { error_log('FULLBASE QA kopia: ' . $e->getMessage()); out(['error' => 'Nie udało się zrobić kopii. Szczegóły są w dzienniku błędów serwera.'], 500); }
}

if ($action === 'backup_file') {
    need_role($u, 'admin');
    $name = (string)($_GET['name'] ?? '');
    $path = QA_BACKUP_DIR . '/' . $name;
    if (!preg_match('/^qa-[0-9a-f-]+\.(sqlite|json)$/', $name) || !is_file($path)) out(['error' => 'Nie ma takiej kopii.'], 404);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    readfile($path);
    exit;
}

/* ---------- klucze API (1.19) ---------- */

if ($action === 'api_keys') {
    need_role($u, 'admin');
    $rows = $p->query('SELECT k.id, k.name, k.prefix, k.created_at, k.last_used_at, k.revoked_at, u.display_name user_name FROM qa_api_keys k JOIN qa_users u ON u.id=k.user_id ORDER BY k.revoked_at IS NOT NULL, k.id DESC')->fetchAll();
    foreach ($rows as &$r) $r['id'] = (int)$r['id'];
    out(['keys' => $rows]);
}

if ($action === 'api_key_create') {
    require_post();
    need_role($u, 'admin');
    $name = str_in(body(), 'name', 80);
    if ($name === '') out(['error' => 'Nazwij klucz, np. „Claude Code — laptop”.'], 422);
    $key = 'fbqa_' . bin2hex(random_bytes(20));
    $p->prepare('INSERT INTO qa_api_keys(name,key_hash,prefix,user_id,created_at) VALUES(?,?,?,?,?)')
        ->execute([$name, hash('sha256', $key), substr($key, 0, 12), $u['id'], qa_now()]);
    out(['ok' => true, 'key' => $key]); // pokazujemy jeden raz — w bazie zostaje tylko skrót
}

if ($action === 'api_key_revoke') {
    require_post();
    need_role($u, 'admin');
    $p->prepare('UPDATE qa_api_keys SET revoked_at=? WHERE id=? AND revoked_at IS NULL')->execute([qa_now(), (int)(body()['id'] ?? 0)]);
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
    $roles = mb_substr(implode("\n", array_map(fn($x) => mb_substr($x, 0, 80), array_slice(lines((string)($b['tested_roles'] ?? '')), 0, 60))), 0, 2000);
    $active = !empty($b['active']) ? 1 : 0;
    if ($id) $p->prepare('UPDATE qa_projects SET code=?,name=?,modules=?,environments=?,tested_roles=?,active=? WHERE id=?')->execute([$code, $name, $modules, $envs, $roles, $active, $id]);
    else {
        $pos = (int)$p->query('SELECT COALESCE(MAX(position),0) FROM qa_projects')->fetchColumn() + 1;
        $p->prepare('INSERT INTO qa_projects(code,name,modules,environments,tested_roles,active,position) VALUES(?,?,?,?,?,?,?)')
            ->execute([$code, $name, $modules, $envs, $roles, $active, $pos]);
    }
    out(['ok' => true]);
}

/* ---------- scenariusze testów ---------- */

if ($action === 'cases') {
    $rows = $p->query('SELECT c.*, u.display_name author FROM qa_cases c JOIN qa_users u ON u.id=c.created_by ORDER BY c.project_id, c.module, c.id')->fetchAll();
    foreach ($rows as &$r) { foreach (['id', 'project_id', 'active', 'created_by'] as $k) $r[$k] = (int)$r[$k]; $r['issue_id'] = $r['issue_id'] === null ? null : (int)$r['issue_id']; $r['suites'] = suites_in($r['suites'] ?? ''); }
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
        'suites' => suites_str(suites_in($b['suites'] ?? '')),
    ];
    if ($f['title'] === '' || $f['steps'] === '') out(['error' => 'Scenariusz potrzebuje tytułu i kroków.'], 422);
    $now = qa_now();
    if ($id) {
        $cols = implode(',', array_map(fn($k) => "$k=?", array_keys($f)));
        $p->prepare("UPDATE qa_cases SET $cols, updated_at=? WHERE id=?")->execute([...array_values($f), $now, $id]);
    } else {
        $p->prepare('INSERT INTO qa_cases(project_id,module,title,preconditions,steps,expected,priority,active,suites,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([...array_values($f), $u['id'], $now, $now]);
        $id = (int)$p->lastInsertId();
    }
    out(['ok' => true, 'id' => $id]);
}

if ($action === 'cases_import') {
    require_post();
    need_role($u, 'admin');
    $b = body();
    $csv = (string)($b['csv'] ?? '');
    $commit = !empty($b['commit']);
    if ($csv === '') out(['error' => 'Plik jest pusty.'], 422);
    if (strlen($csv) > 1048576) out(['error' => 'Plik jest za duży (najwyżej 1 MB).'], 413);
    if (!mb_check_encoding($csv, 'UTF-8')) $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1250'); // CSV z polskiego Excela
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $first = strtok($csv, "\n");
    $sep = substr_count((string)$first, ';') >= substr_count((string)$first, ',') ? ';' : ',';
    $h = fopen('php://temp', 'r+');
    fwrite($h, $csv);
    rewind($h);
    $norm = fn($s) => strtr(mb_strtolower(trim((string)$s)), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
    $names = ['projekt' => 'project', 'obszar' => 'module', 'tytul' => 'title', 'warunki wstepne' => 'preconditions', 'kroki' => 'steps',
        'oczekiwany wynik' => 'expected', 'priorytet' => 'priority', 'zestawy' => 'suites', 'zestaw' => 'suites'];
    $head = fgetcsv($h, 0, $sep, '"', '');
    $cols = [];
    foreach ((array)$head as $i => $name) if (isset($names[$norm($name)])) $cols[$names[$norm($name)]] = $i;
    foreach (['project', 'title', 'steps'] as $need) {
        if (!isset($cols[$need])) out(['error' => 'Brak kolumny „' . array_search($need, $names, true) . '”. Wymagane nagłówki: projekt, obszar, tytuł, warunki wstępne, kroki, oczekiwany wynik, priorytet.'], 422);
    }
    $projects = $p->query('SELECT id, code, name, modules FROM qa_projects')->fetchAll();
    $prioByLabel = [];
    foreach (QA_PRIORITIES as $k => $l) { $prioByLabel[$norm($l)] = $k; $prioByLabel[$k] = $k; }
    $exists = $p->prepare('SELECT COUNT(*) FROM qa_cases WHERE project_id=? AND title=?');
    $rows = [];
    $seen = [];
    $n = 1;
    while (($r = fgetcsv($h, 0, $sep, '"', '')) !== false) {
        $n++;
        if ($r === [null] || implode('', $r) === '') continue;
        $g = fn($k, $max) => mb_substr(trim((string)($r[$cols[$k] ?? -1] ?? '')), 0, $max);
        $row = ['line' => $n, 'project' => $g('project', 120), 'module' => $g('module', 120), 'title' => $g('title', 200), 'preconditions' => $g('preconditions', 10000),
            'steps' => $g('steps', 20000), 'expected' => $g('expected', 10000), 'suites' => suites_in($g('suites', 500)), 'priority' => 'medium', 'status' => 'ok', 'note' => ''];
        $pr = null;
        foreach ($projects as $x) if ($norm($x['code']) === $norm($row['project']) || $norm($x['name']) === $norm($row['project'])) $pr = $x;
        $pl = $norm($g('priority', 30));
        if ($pl !== '' && !isset($prioByLabel[$pl])) $row['note'] = 'Nieznany priorytet „' . $g('priority', 30) . '” — ustawiono Średni. ';
        $row['priority'] = $prioByLabel[$pl] ?? 'medium';
        if (!$pr) { $row['status'] = 'error'; $row['note'] = 'Nieznany projekt „' . $row['project'] . '”.'; }
        elseif ($row['title'] === '' || $row['steps'] === '') { $row['status'] = 'error'; $row['note'] = 'Brak tytułu albo kroków.'; }
        else {
            $row['project_id'] = (int)$pr['id'];
            $row['project'] = $pr['code'];
            if ($row['module'] !== '' && !in_array($row['module'], lines($pr['modules']), true)) $row['note'] .= 'Obszaru nie ma na liście projektu (zapiszemy go mimo to).';
            $exists->execute([$pr['id'], $row['title']]);
            $key = $pr['id'] . '|' . $row['title'];
            if ((int)$exists->fetchColumn() > 0 || isset($seen[$key])) { $row['status'] = 'duplicate'; $row['note'] = $row['suites'] ? 'Taki scenariusz już jest — dopiszemy tylko brakujące zestawy.' : 'Taki scenariusz już jest — pominiemy.'; }
            $seen[$key] = true;
        }
        $rows[] = $row;
        if (count($rows) > 500) out(['error' => 'Najwyżej 500 scenariuszy w jednym pliku.'], 422);
    }
    fclose($h);
    $ok = array_values(array_filter($rows, fn($x) => $x['status'] === 'ok'));
    $saved = 0;
    $tagged = 0;
    if ($commit) {
        $now = qa_now();
        $ins = $p->prepare('INSERT INTO qa_cases(project_id,module,title,preconditions,steps,expected,priority,active,suites,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,1,?,?,?,?)');
        $sel = $p->prepare('SELECT id, suites FROM qa_cases WHERE project_id=? AND title=?');
        $upd = $p->prepare('UPDATE qa_cases SET suites=?, updated_at=? WHERE id=?');
        $p->beginTransaction();
        foreach ($ok as $x) { $ins->execute([$x['project_id'], $x['module'], $x['title'], $x['preconditions'], $x['steps'], $x['expected'], $x['priority'], suites_str($x['suites']), $u['id'], $now, $now]); $saved++; }
        foreach ($rows as $x) {
            if ($x['status'] !== 'duplicate' || !$x['suites']) continue;
            $sel->execute([$x['project_id'], $x['title']]);
            foreach ($sel->fetchAll() as $c) {
                $merged = suites_in([...suites_in($c['suites']), ...$x['suites']]);
                if (suites_str($merged) !== $c['suites']) { $upd->execute([suites_str($merged), $now, $c['id']]); $tagged++; }
            }
        }
        $p->commit();
    }
    out(['rows' => $rows, 'valid' => count($ok), 'saved' => $saved, 'tagged' => $tagged, 'separator' => $sep]);
}

if ($action === 'case_from_issue') {
    require_post();
    need_role($u, 'admin', 'tester');
    $issue = issue_row((int)($_GET['id'] ?? 0));
    if ($issue['status'] !== 'closed') out(['error' => 'Scenariusz regresji tworzymy z zamkniętego zgłoszenia (po potwierdzeniu poprawki).'], 422);
    $q = $p->prepare('SELECT id FROM qa_cases WHERE issue_id=?');
    $q->execute([$issue['id']]);
    if ($existing = $q->fetchColumn()) out(['ok' => true, 'id' => (int)$existing, 'existed' => true]);
    $pre = array_filter([
        $issue['sys_role'] || $issue['sys_account'] ? 'Konto: ' . implode(' · ', array_filter([$issue['sys_role'], $issue['sys_account']])) : '',
        $issue['environment'] ? 'Środowisko: ' . $issue['environment'] . ($issue['fixed_in'] ? ', wersja ≥ ' . $issue['fixed_in'] : '') : '',
        'Regresja zgłoszenia ' . $issue['key'] . '.',
    ]);
    $now = qa_now();
    $p->prepare('INSERT INTO qa_cases(project_id,module,title,preconditions,steps,expected,priority,active,created_by,created_at,updated_at,issue_id) VALUES(?,?,?,?,?,?,?,1,?,?,?,?)')
        ->execute([$issue['project_id'], $issue['module'], mb_substr('Regresja ' . $issue['key'] . ': ' . $issue['title'], 0, 200), implode("\n", $pre),
            $issue['steps'] !== '' ? $issue['steps'] : $issue['description'], $issue['expected'], in_array($issue['severity'], ['blocker', 'data'], true) ? 'high' : $issue['priority'],
            $u['id'], $now, $now, $issue['id']]);
    $cid = (int)$p->lastInsertId();
    log_act($issue['id'], $u['id'], 'field', 'Utworzono scenariusz regresji TC-' . str_pad((string)$cid, 3, '0', STR_PAD_LEFT) . '.');
    out(['ok' => true, 'id' => $cid, 'existed' => false]);
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
    $q = $p->prepare('SELECT id, module, suites FROM qa_cases WHERE project_id=? AND active=1 ORDER BY module, id');
    $q->execute([$project['id']]);
    $suite = mb_strtolower(str_in($b, 'suite', 40));
    $cases = array_filter($q->fetchAll(), fn($c) => (!$modules || in_array($c['module'], $modules, true))
        && ($suite === '' || in_array($suite, array_map('mb_strtolower', suites_in($c['suites'])), true)));
    if (!$cases) out(['error' => 'Brak aktywnych scenariuszy dla wybranego projektu, obszarów i zestawu. Najpierw dodaj scenariusze.'], 422);
    $p->beginTransaction();
    $p->prepare('INSERT INTO qa_runs(project_id,name,environment,version,status,created_by,created_at) VALUES(?,?,?,?,\'open\',?,?)')
        ->execute([$project['id'], $name, str_in($b, 'environment', 80), str_in($b, 'version', 60), $u['id'], qa_now()]);
    $runId = (int)$p->lastInsertId();
    $ins = $p->prepare('INSERT INTO qa_results(run_id,case_id,result,note) VALUES(?,?,\'pending\',\'\')');
    foreach ($cases as $c) $ins->execute([$runId, $c['id']]);
    $p->commit();
    out(['ok' => true, 'id' => $runId]);
}

/* ---------- miary jakości (3.2) ---------- */

if ($action === 'metrics') {
    $pid = (int)($_GET['project_id'] ?? 0);
    $w = $pid ? ' AND i.project_id=' . $pid : ''; // liczba całkowita — bezpieczne
    $since90 = date('Y-m-d H:i:s', time() - 90 * 86400);
    $since30 = date('Y-m-d H:i:s', time() - 30 * 86400);
    // średni czas od zgłoszenia do zamknięcia (zamknięte w ostatnich 90 dniach, bez odrzuconych)
    $q = $p->prepare("SELECT created_at, closed_at FROM qa_issues i WHERE status='closed' AND closed_at>=?$w");
    $q->execute([$since90]);
    $days = array_map(fn($r) => max(0, strtotime($r['closed_at']) - strtotime($r['created_at'])) / 86400, $q->fetchAll());
    sort($days);
    $avg = $days ? array_sum($days) / count($days) : null;
    $median = $days ? $days[intdiv(count($days), 2)] : null;
    // odsetek ponownych otwarć: ile zgłoszeń, które przeszły weryfikację, choć raz jej nie przeszło
    $q = $p->prepare("SELECT a.issue_id, MAX(CASE WHEN a.message LIKE 'Weryfikacja negatywna%' THEN 1 ELSE 0 END) neg
        FROM qa_activity a JOIN qa_issues i ON i.id=a.issue_id WHERE a.kind='verify' AND a.created_at>=?$w GROUP BY a.issue_id");
    $q->execute([$since90]);
    $ver = $q->fetchAll();
    $reopened = count(array_filter($ver, fn($r) => (int)$r['neg'] === 1));
    // błędy według obszaru — zgłoszone w ostatnich 30 dniach (rodzaj „błąd”)
    $q = $p->prepare("SELECT i.module, p.code, COUNT(*) n, SUM(CASE WHEN i.severity IN ('blocker','data') THEN 1 ELSE 0 END) crit
        FROM qa_issues i JOIN qa_projects p ON p.id=i.project_id WHERE i.kind='bug' AND i.created_at>=?$w GROUP BY i.module, p.code ORDER BY n DESC, crit DESC");
    $q->execute([$since30]);
    $areas = array_map(fn($r) => ['module' => $r['module'] ?: 'Bez obszaru', 'project' => $r['code'], 'count' => (int)$r['n'], 'critical' => (int)$r['crit']], $q->fetchAll());
    out(['closed_90' => count($days), 'avg_days' => $avg === null ? null : round($avg, 1), 'median_days' => $median === null ? null : round($median, 1),
        'verified_90' => count($ver), 'reopened_90' => $reopened, 'reopen_rate' => $ver ? round($reopened / count($ver) * 100) : null,
        'areas_30' => $areas]);
}

/* ---------- gotowość wydania (1.18 = 3.1) ---------- */

if ($action === 'readiness') {
    $project = project_row((int)($_GET['project_id'] ?? 0));
    $q = $p->prepare("SELECT id, title, status, severity, module, fixed_in FROM qa_issues
        WHERE project_id=? AND severity IN ('blocker','data') AND status NOT IN ('closed','rejected') ORDER BY id");
    $q->execute([$project['id']]);
    $issues = array_map(fn($r) => ['id' => (int)$r['id'], 'key' => $project['code'] . '-' . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT), 'title' => $r['title'],
        'status' => $r['status'], 'severity' => $r['severity'], 'module' => $r['module'], 'fixed_in' => $r['fixed_in']], $q->fetchAll());
    $q = $p->prepare("SELECT id, title, module FROM qa_cases WHERE project_id=? AND active=1 AND priority='critical' ORDER BY module, id");
    $q->execute([$project['id']]);
    $cases = [];
    foreach ($q->fetchAll() as $c) $cases[(int)$c['id']] = ['id' => (int)$c['id'], 'title' => $c['title'], 'module' => $c['module'], 'result' => null, 'run_id' => null, 'run_name' => null, 'version' => null, 'at' => null];
    if ($cases) {
        $q = $p->prepare("SELECT r.case_id, r.result, r.updated_at, n.id run_id, n.name run_name, n.version FROM qa_results r JOIN qa_runs n ON n.id=r.run_id
            WHERE n.project_id=? AND r.result<>'pending' AND r.updated_at IS NOT NULL ORDER BY r.updated_at, r.id");
        $q->execute([$project['id']]);
        foreach ($q->fetchAll() as $r) {
            $cid = (int)$r['case_id'];
            if (isset($cases[$cid])) $cases[$cid] = ['result' => $r['result'], 'run_id' => (int)$r['run_id'], 'run_name' => $r['run_name'], 'version' => $r['version'], 'at' => $r['updated_at']] + $cases[$cid];
        }
    }
    $cases = array_values($cases);
    $failed = array_filter($cases, fn($c) => in_array($c['result'], ['fail', 'blocked'], true));
    $untested = array_filter($cases, fn($c) => $c['result'] === null || $c['result'] === 'skip');
    out(['project' => ['id' => (int)$project['id'], 'code' => $project['code'], 'name' => $project['name']],
        'ready' => !$issues && !$failed, 'open_critical' => $issues, 'critical_cases' => $cases,
        'failed' => count($failed), 'untested' => count($untested)]);
}

if ($action === 'run_rerun') {
    require_post();
    need_role($u, 'admin', 'tester');
    $b = body();
    $q = $p->prepare('SELECT * FROM qa_runs WHERE id=?');
    $q->execute([(int)($b['id'] ?? 0)]);
    $src = $q->fetch();
    if (!$src) out(['error' => 'Nie znaleziono przebiegu.'], 404);
    $q = $p->prepare("SELECT r.case_id FROM qa_results r JOIN qa_cases c ON c.id=r.case_id WHERE r.run_id=? AND r.result IN ('fail','blocked') AND c.active=1 ORDER BY c.module, c.id");
    $q->execute([$src['id']]);
    $cases = $q->fetchAll(PDO::FETCH_COLUMN);
    if (!$cases) out(['error' => 'W tym przebiegu nie ma niezaliczonych ani zablokowanych scenariuszy.'], 422);
    $name = str_in($b, 'name', 160) ?: mb_substr('Powtórka: ' . $src['name'], 0, 160);
    $p->beginTransaction();
    $p->prepare('INSERT INTO qa_runs(project_id,name,environment,version,status,created_by,created_at) VALUES(?,?,?,?,\'open\',?,?)')
        ->execute([$src['project_id'], $name, $src['environment'], str_in($b, 'version', 60) ?: $src['version'], $u['id'], qa_now()]);
    $runId = (int)$p->lastInsertId();
    $ins = $p->prepare('INSERT INTO qa_results(run_id,case_id,result,note) VALUES(?,?,\'pending\',\'\')');
    foreach ($cases as $c) $ins->execute([$runId, (int)$c]);
    $p->commit();
    out(['ok' => true, 'id' => $runId, 'cases' => count($cases)]);
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
    $q = $p->prepare('SELECT id, case_id, orig_name, mime, size, user_id FROM qa_result_files WHERE run_id=? ORDER BY id');
    $q->execute([$id]);
    $rf = [];
    foreach ($q->fetchAll() as $x) $rf[(int)$x['case_id']][] = ['id' => (int)$x['id'], 'orig_name' => $x['orig_name'], 'mime' => $x['mime'], 'size' => (int)$x['size'], 'user_id' => (int)$x['user_id']];
    foreach ($run['results'] as &$x) $x['files'] = $rf[$x['case_id']] ?? [];
    unset($x);
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
    $q = $p->prepare('UPDATE qa_runs SET status=?, closed_at=? WHERE id=?');
    $q->execute([$status, $status === 'closed' ? qa_now() : null, (int)($b['id'] ?? 0)]);
    if (!$q->rowCount() && !$p->query('SELECT 1 FROM qa_runs WHERE id=' . (int)($b['id'] ?? 0))->fetchColumn()) out(['error' => 'Nie znaleziono przebiegu.'], 404);
    out(['ok' => true]);
}

out(['error' => 'Nieznana operacja.'], 404);
