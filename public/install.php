<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/schema.php';
require dirname(__DIR__) . '/app/migrate.php';

qa_security_headers('text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");

$installed = qa_config() !== null;
$errors = [];
$done = false;

$checks = [
    'PHP 8.1 lub nowszy' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'Rozszerzenie PDO' => class_exists('PDO'),
    'Rozszerzenie fileinfo (załączniki)' => class_exists('finfo'),
    'Zapis w katalogu aplikacji (config.php)' => is_writable(QA_ROOT),
];
$hasSqlite = class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true);
$hasMysql = class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers(), true);

if (!$installed) {
    qa_ensure_dirs();
    $checks['Zapis w katalogu data/'] = is_dir(QA_DATA) && is_writable(QA_DATA);
    qa_session_start();
}

$v = fn(string $k, string $d = '') => htmlspecialchars((string)($_POST[$k] ?? $d), ENT_QUOTES);

if (!$installed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) $errors[] = 'Formularz wygasł — odśwież stronę.';
    $driver = ($_POST['driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
    $db = ['driver' => $driver];
    if ($driver === 'mysql') {
        $db += ['host' => trim((string)$_POST['host']), 'port' => (int)($_POST['port'] ?? 0) ?: null, 'name' => trim((string)$_POST['name']),
            'user' => trim((string)$_POST['user']), 'pass' => (string)$_POST['pass']];
        if (!$db['host'] || !$db['name'] || !$db['user']) $errors[] = 'Uzupełnij dane bazy MySQL.';
        if (!$hasMysql) $errors[] = 'Serwer nie ma sterownika pdo_mysql.';
    } elseif (!$hasSqlite) $errors[] = 'Serwer nie ma sterownika pdo_sqlite — wybierz MySQL.';
    $login = mb_strtolower(trim((string)($_POST['admin_login'] ?? '')));
    $aname = trim((string)($_POST['admin_name'] ?? ''));
    $pw = (string)($_POST['admin_pass'] ?? '');
    if (!preg_match('/^[a-z0-9._@+-]{3,80}$/', $login)) $errors[] = 'Login administratora: adres e-mail albo 3–80 znaków (małe litery, cyfry, . _ -).';
    if ($aname === '') $errors[] = 'Wpisz imię i nazwisko administratora.';
    if ($e = qa_password_problem($pw)) $errors[] = $e;
    if ($pw !== (string)($_POST['admin_pass2'] ?? '')) $errors[] = 'Hasła nie są takie same.';
    $mailFrom = trim((string)($_POST['mail_from'] ?? ''));
    if ($mailFrom !== '' && !filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) $errors[] = 'Adres nadawcy e-maili jest nieprawidłowy.';
    foreach ($checks as $label => $ok) if (!$ok) $errors[] = 'Wymaganie niespełnione: ' . $label;

    if (!$errors) {
        try {
            $pdo = qa_pdo($db);
            $exists = false;
            try { $pdo->query('SELECT 1 FROM qa_users LIMIT 1'); $exists = true; } catch (Throwable) {}
            if ($exists) throw new RuntimeException('W tej bazie są już tabele aplikacji (qa_*). Użyj pustej bazy albo usuń te tabele.');
            foreach (qa_schema($driver) as $sql) $pdo->exec($sql);
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO qa_meta(k,v) VALUES(?,?)')->execute(['schema', '1']);
            $pdo->prepare('INSERT INTO qa_meta(k,v) VALUES(?,?)')->execute(['installed_at', qa_now()]);
            $pdo->prepare('INSERT INTO qa_users(username,display_name,email,password_hash,role,active,must_change_pw,created_at) VALUES(?,?,?,?,\'admin\',1,0,?)')
                ->execute([$login, $aname, trim((string)($_POST['admin_email'] ?? '')), qa_hash_password($pw), qa_now()]);
            qa_seed_projects($pdo);
            $pdo->commit();
            qa_migrate($pdo, $driver); // schemat bazowy (wersja 1) + wszystkie migracje
            $config = "<?php\n// FULLBASE QA — konfiguracja. Plik zawiera dane dostępu: nie publikuj, nie wysyłaj w czacie.\n"
                . "date_default_timezone_set('Europe/Warsaw');\nreturn " . var_export(['db' => $db, 'installed' => qa_now()] + ($mailFrom !== '' ? ['mail' => ['from' => $mailFrom, 'from_name' => 'FULLBASE QA', 'transport' => 'mail']] : []), true) . ";\n";
            if (file_put_contents(QA_ROOT . '/config.php', $config, LOCK_EX) === false) throw new RuntimeException('Nie udało się zapisać config.php.');
            @chmod(QA_ROOT . '/config.php', 0640);
            $done = true;
        } catch (Throwable $e) {
            $errors[] = 'Instalacja przerwana: ' . $e->getMessage();
            if ($driver === 'sqlite' && is_file(QA_DATA . '/qa.sqlite') && !is_file(QA_ROOT . '/config.php')) {
                @unlink(QA_DATA . '/qa.sqlite'); @unlink(QA_DATA . '/qa.sqlite-wal'); @unlink(QA_DATA . '/qa.sqlite-shm');
            }
        }
    }
}
?><!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Instalacja · FULLBASE QA</title><link rel="stylesheet" href="assets/app.css?v=<?= QA_VERSION ?>"></head>
<body class="authPage"><main class="authCard wide">
<div class="brand dark"><div class="brandmark">F</div><div class="brandname">FULLBASE<span>.</span><small>QA · INSTALACJA</small></div></div>
<?php if ($installed): ?>
  <h1>Aplikacja jest już zainstalowana</h1>
  <p class="muted">Instalator jest zablokowany, bo istnieje plik <code>config.php</code>. Aby zainstalować od nowa, usuń ten plik na serwerze (dane w bazie pozostaną).</p>
  <a class="btn primary" href="./">Przejdź do logowania</a>
<?php elseif ($done): ?>
  <h1>Gotowe ✓</h1>
  <p class="muted">Baza utworzona, konto administratora założone, dodano startowe projekty: PZBall i FULLBASE Kluby (zmienisz je w Ustawieniach).</p>
  <ol class="muted"><li>Zaloguj się jako administrator.</li><li>W zakładce <b>Użytkownicy</b> załóż konto testera — przy pierwszym logowaniu ustawi własne hasło.</li><li>Dodaj scenariusze testów i utwórz pierwszy przebieg.</li></ol>
  <a class="btn primary" href="./">Przejdź do logowania</a>
<?php else: ?>
  <h1>Instalacja</h1>
  <p class="muted">Jednorazowa konfiguracja bazy i konta administratora. Po instalacji ta strona się zablokuje.</p>
  <ul class="checks"><?php foreach ($checks as $label => $ok): ?><li class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓' : '✗' ?> <?= htmlspecialchars($label) ?></li><?php endforeach; ?></ul>
  <?php foreach ($errors as $e): ?><div class="authError show"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  <form method="post" class="installForm" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
    <fieldset><legend>Baza danych</legend>
      <label class="radio"><input type="radio" name="driver" value="sqlite" <?= ($_POST['driver'] ?? ($hasSqlite ? 'sqlite' : 'mysql')) === 'sqlite' ? 'checked' : '' ?> <?= $hasSqlite ? '' : 'disabled' ?>> SQLite — plik w katalogu <code>data/</code>, bez konfiguracji (wystarczy dla zespołu testowego)</label>
      <label class="radio"><input type="radio" name="driver" value="mysql" <?= ($_POST['driver'] ?? '') === 'mysql' || !$hasSqlite ? 'checked' : '' ?> <?= $hasMysql ? '' : 'disabled' ?>> MySQL / MariaDB</label>
      <div class="grid2">
        <label>Host MySQL<input name="host" value="<?= $v('host', 'localhost') ?>"></label>
        <label>Port (opcjonalnie)<input name="port" inputmode="numeric" value="<?= $v('port') ?>"></label>
        <label>Nazwa bazy<input name="name" value="<?= $v('name') ?>"></label>
        <label>Użytkownik bazy<input name="user" value="<?= $v('user') ?>"></label>
        <label class="full">Hasło bazy<input name="pass" type="password" autocomplete="new-password"></label>
      </div>
    </fieldset>
    <fieldset><legend>Konto administratora</legend>
      <div class="grid2">
        <label>Login<input name="admin_login" required value="<?= $v('admin_login') ?>" placeholder="np. lukasz"></label>
        <label>Imię i nazwisko<input name="admin_name" required value="<?= $v('admin_name') ?>"></label>
        <label class="full">E-mail (opcjonalnie)<input name="admin_email" type="email" value="<?= $v('admin_email') ?>"></label>
        <label>Hasło (min. 10 znaków)<input name="admin_pass" type="password" required minlength="10" autocomplete="new-password"></label>
        <label>Powtórz hasło<input name="admin_pass2" type="password" required minlength="10" autocomplete="new-password"></label>
      </div>
    </fieldset>
    <fieldset><legend>Powiadomienia e-mail (opcjonalnie)</legend>
      <label>Adres nadawcy<input name="mail_from" type="email" value="<?= $v('mail_from') ?>" placeholder="np. testy@fullbase.pl"></label>
      <p class="muted" style="margin:6px 0 0">Skrzynka w domenie serwera (na home.pl załóż ją w panelu). Puste = bez e-maili i bez resetu hasła przez e-mail; można dopisać później w <code>config.php</code>.</p>
    </fieldset>
    <button class="btn primary wide" type="submit">Zainstaluj</button>
  </form>
<?php endif; ?>
</main></body></html>
