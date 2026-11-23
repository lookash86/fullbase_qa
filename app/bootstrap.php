<?php
declare(strict_types=1);

/*
 * FULLBASE QA — rdzeń: konfiguracja, baza, sesja, pomocnicze.
 * Katalog app/ i data/ NIE mogą być dostępne z WWW (domena wskazuje na public/).
 */

const QA_VERSION = '2.1.1';
define('QA_ROOT', dirname(__DIR__));
define('QA_DATA', QA_ROOT . '/data');
date_default_timezone_set('Europe/Warsaw');

const QA_ROLES = ['admin' => 'Administrator', 'tester' => 'Tester', 'viewer' => 'Obserwator'];
const QA_KINDS = ['bug' => 'Błąd', 'missing' => 'Brak funkcji', 'improvement' => 'Usprawnienie', 'question' => 'Pytanie'];
/** Waga = jak bardzo przeszkadza (ustawia tester). Priorytet = kiedy naprawiamy (ustawia administrator). */
const QA_SEVERITIES = ['blocker' => 'Blokuje pracę', 'data' => 'Psuje dane', 'annoying' => 'Utrudnia', 'cosmetic' => 'Kosmetyka'];
const QA_REJECT_REASONS = ['duplicate' => 'Duplikat', 'cannot_reproduce' => 'Nie da się odtworzyć', 'not_bug' => 'To nie błąd', 'idea' => 'Przeniesione do pomysłów'];
const QA_PRIORITIES = ['critical' => 'Krytyczny', 'high' => 'Wysoki', 'medium' => 'Średni', 'low' => 'Niski'];
const QA_STATUSES = [
    'new' => 'Nowe', 'confirmed' => 'Potwierdzone', 'progress' => 'W naprawie', 'fixed' => 'Do weryfikacji',
    'reopened' => 'Ponownie otwarte', 'closed' => 'Zamknięte', 'rejected' => 'Odrzucone',
];
const QA_RESULTS = ['pending' => 'Nie wykonano', 'pass' => 'Zaliczony', 'fail' => 'Niezaliczony', 'blocked' => 'Zablokowany', 'skip' => 'Pominięty'];
const QA_UPLOAD_MAX = 10 * 1024 * 1024;
const QA_UPLOAD_TYPES = [
    'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp',
    'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv', 'application/json' => 'json',
    'video/mp4' => 'mp4', 'video/webm' => 'webm', 'application/zip' => 'zip',
];

function qa_config(): ?array
{
    static $cfg = false;
    if ($cfg === false) {
        $file = QA_ROOT . '/config.php';
        $cfg = is_file($file) ? (require $file) : null;
        if ($cfg !== null && !is_array($cfg)) $cfg = null;
    }
    return $cfg;
}

function qa_now(): string { return date('Y-m-d H:i:s'); }

function qa_ensure_dirs(): void
{
    foreach ([QA_DATA, QA_DATA . '/sessions', QA_DATA . '/uploads'] as $d) {
        if (!is_dir($d)) @mkdir($d, 0770, true);
    }
    $ht = QA_DATA . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\n");
}

function qa_pdo(array $db): PDO
{
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if (($db['driver'] ?? 'sqlite') === 'mysql') {
        $dsn = 'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4';
        if (!empty($db['port'])) $dsn .= ';port=' . (int)$db['port'];
        $pdo = new PDO($dsn, $db['user'], $db['pass'], $opts);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . QA_DATA . '/qa.sqlite', null, null, $opts);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $cfg = qa_config();
    if (!$cfg) out(['error' => 'Aplikacja nie jest zainstalowana. Otwórz install.php.'], 503);
    try {
        $pdo = qa_pdo($cfg['db']);
    } catch (Throwable $e) {
        error_log('FULLBASE QA DB: ' . $e->getMessage());
        out(['error' => 'Nie można połączyć się z bazą danych.'], 503);
    }
    // Po wgraniu nowej wersji plików baza aktualizuje się sama (app/migrate.php).
    try {
        require_once __DIR__ . '/migrate.php';
        qa_migrate($pdo, $cfg['db']['driver'] ?? 'sqlite');
    } catch (Throwable $e) {
        error_log('FULLBASE QA migracja: ' . $e->getMessage());
        $pdo = null;
        out(['error' => 'Nie udało się zaktualizować bazy danych do nowej wersji. Dane są bezpieczne — skontaktuj się z administratorem.'], 503);
    }
    return $pdo;
}

function qa_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
}

function qa_session_start(): void
{
    qa_ensure_dirs();
    // home.pl: domyślny katalog sesji nie działa — sesje trzymamy we własnym katalogu.
    session_save_path(QA_DATA . '/sessions');
    ini_set('session.gc_maxlifetime', '43200');
    ini_set('session.use_strict_mode', '1');
    session_name('fbqa');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => qa_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    $idle = 12 * 3600;
    if (!empty($_SESSION['uid']) && (time() - (int)($_SESSION['seen'] ?? 0)) > $idle) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['seen'] = time();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function qa_security_headers(string $type = 'application/json; charset=utf-8'): void
{
    header('Content-Type: ' . $type);
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store');
}

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function qa_hash_password(string $pw): string { return password_hash($pw, PASSWORD_DEFAULT); }

function qa_password_problem(string $pw): ?string
{
    if (mb_strlen($pw) < 10) return 'Hasło musi mieć co najmniej 10 znaków.';
    if (mb_strlen($pw) > 200) return 'Hasło jest za długie.';
    return null;
}
