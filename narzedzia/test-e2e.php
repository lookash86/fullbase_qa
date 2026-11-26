<?php
declare(strict_types=1);

/*
 * FULLBASE QA — test całej ścieżki przez API (admin + tester + obserwator).
 *
 *   php narzedzia/test-e2e.php            test na czystej bazie SQLite
 *   php narzedzia/test-e2e.php --mysql    to samo na MySQL 8 / MariaDB (tymczasowe bazy fbqa_e2e_*, usuwane na końcu)
 *   php narzedzia/test-e2e.php --zachowaj nie usuwa katalogu tymczasowego ani baz (do oglądania)
 *
 * Dane MySQL tylko ze zmiennych środowiskowych (nigdy w repozytorium):
 *   FBQA_MYSQL_HOST (127.0.0.1), FBQA_MYSQL_PORT (3306), FBQA_MYSQL_USER (root), FBQA_MYSQL_PASS (puste)
 * Użytkownik musi móc zakładać i usuwać bazy (CREATE / DROP DATABASE).
 *
 * Skrypt kopiuje app/ i public/ do katalogu tymczasowego, uruchamia tam wbudowany serwer PHP,
 * instaluje aplikację przez install.php i przechodzi przez API. Twojej lokalnej bazy nie dotyka.
 * Kod wyjścia: 0 = wszystko zaliczone, 1 = są błędy.
 */

const ZRODLO = __DIR__ . '/..';
const WERSJA_2_0_1 = '10b7c55'; // commit „FULLBASE QA 2.0.1” — punkt wyjścia testu migracji
$opcje = array_slice($argv, 1);
$zachowaj = in_array('--zachowaj', $opcje, true);
$MYSQL = in_array('--mysql', $opcje, true);

/* ---------- MySQL (opcja --mysql) ---------- */

$MY = null;        // dane serwera MySQL
$BAZY_MY = [];     // bazy tymczasowe do usunięcia
if ($MYSQL) {
    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
        fwrite(STDERR, "Brak rozszerzenia pdo_mysql w tym PHP (" . PHP_BINARY . ").\nWłącz je w php.ini (extension=pdo_mysql) i uruchom ponownie.\n");
        exit(2);
    }
    $MY = ['host' => getenv('FBQA_MYSQL_HOST') ?: '127.0.0.1', 'port' => (int)(getenv('FBQA_MYSQL_PORT') ?: 3306),
        'user' => getenv('FBQA_MYSQL_USER') ?: 'root', 'pass' => (string)(getenv('FBQA_MYSQL_PASS') ?: '')];
    try {
        $srvMy = new PDO("mysql:host={$MY['host']};port={$MY['port']};charset=utf8mb4", $MY['user'], $MY['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable $e) {
        fwrite(STDERR, "Nie można połączyć się z MySQL ({$MY['host']}:{$MY['port']}, użytkownik {$MY['user']}): " . $e->getMessage() . "\n");
        exit(2);
    }
}
/** Zakłada pustą bazę MySQL dla jednego testu i zwraca dane do instalatora. */
function nowaBazaMysql(): array
{
    global $MY, $srvMy, $BAZY_MY;
    $name = 'fbqa_e2e_' . bin2hex(random_bytes(4));
    $srvMy->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $BAZY_MY[] = $name;
    return ['driver' => 'mysql'] + $MY + ['name' => $name];
}
/* ---------- przygotowanie środowiska ---------- */

function kopiuj(string $z, string $do): void
{
    if (is_dir($z)) {
        @mkdir($do, 0777, true);
        foreach (scandir($z) as $e) if ($e !== '.' && $e !== '..') kopiuj("$z/$e", "$do/$e");
    } else {
        copy($z, $do);
    }
}
function usun(string $s): void
{
    if (is_dir($s) && !is_link($s)) {
        foreach (scandir($s) as $e) if ($e !== '.' && $e !== '..') usun("$s/$e");
        @rmdir($s);
    } elseif (file_exists($s)) {
        @unlink($s);
    }
}

/** Kopiuje bieżące app/ i public/ do katalogu (podmiana plików jak przy aktualizacji na serwerze). */
function wgrajBiezace(string $kat): void
{
    foreach (['app', 'public'] as $d) { usun("$kat/$d"); kopiuj(ZRODLO . "/$d", "$kat/$d"); }
    @mkdir("$kat/data", 0777, true);
}

/** Wypakowuje app/ i public/ z podanego commita (np. wersja 2.0.1). Zwraca false, gdy git niedostępny. */
function wgrajZGita(string $kat, string $commit): bool
{
    $tar = "$kat.tar";
    $cmd = 'git -C ' . escapeshellarg(ZRODLO) . ' archive --format=tar -o ' . escapeshellarg($tar) . ' ' . escapeshellarg($commit) . ' app public';
    exec($cmd . ' 2>&1', $o, $kod);
    if ($kod !== 0 || !is_file($tar)) return false;
    (new PharData($tar))->extractTo($kat, null, true);
    @unlink($tar);
    @mkdir("$kat/data", 0777, true);
    return true;
}

/** @return array{0:resource,1:string} proces serwera i adres bazowy */
function uruchomSerwer(string $kat): array
{
    $gn = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($gn, false), ':'), 1);
    fclose($gn);
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $p = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$kat/public"], [['pipe', 'r'], ['file', $null, 'w'], ['file', $null, 'w']], $rury);
    for ($i = 0; $i < 50; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
        if ($s) { fclose($s); break; }
        usleep(100000);
    }
    return [$p, "http://127.0.0.1:$port"];
}
function zatrzymajSerwer($p): void
{
    if (is_resource($p)) { proc_terminate($p); proc_close($p); usleep(300000); }
}

$KATALOGI = [];
$SERWERY = [];
register_shutdown_function(function () use (&$KATALOGI, &$SERWERY, $zachowaj) {
    global $BAZY_MY, $srvMy;
    foreach ($SERWERY as $s) zatrzymajSerwer($s);
    foreach ($BAZY_MY as $b) {
        if ($zachowaj) echo "Baza testu zachowana: $b\n";
        else try { $srvMy->exec("DROP DATABASE `$b`"); } catch (Throwable) { echo "Nie usunięto bazy $b\n"; }
    }
    foreach ($KATALOGI as $k) {
        if ($zachowaj) echo "Katalog testu zachowany: $k\n";
        else usun($k);
    }
});

$kat = $KATALOGI[] = sys_get_temp_dir() . '/fbqa-e2e-' . bin2hex(random_bytes(4));
wgrajBiezace($kat);
[$serwer, $BAZA] = uruchomSerwer($kat);
$SERWERY[] = $serwer;

/* ---------- klient HTTP ---------- */

final class Klient
{
    public string $ciastko = '';
    public string $csrf = '';

    public function __construct(private string $baza, public string $nazwa) {}

    /** @return array{0:int,1:array,2:string} kod, JSON, surowa treść */
    public function zadanie(string $metoda, string $sciezka, string $tresc = '', string $typ = 'application/json', bool $zCsrf = true): array
    {
        $h = ['Accept: application/json'];
        if ($this->ciastko !== '') $h[] = 'Cookie: ' . $this->ciastko;
        if ($metoda === 'POST') {
            $h[] = 'Content-Type: ' . $typ;
            if ($zCsrf && $this->csrf !== '') $h[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        $ctx = stream_context_create(['http' => ['method' => $metoda, 'header' => implode("\r\n", $h), 'content' => $tresc,
            'ignore_errors' => true, 'timeout' => 30]]);
        $odp = @file_get_contents($this->baza . $sciezka, false, $ctx);
        $nag = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        $kod = preg_match('#^HTTP/\S+\s+(\d+)#', $nag[0] ?? '', $m) ? (int)$m[1] : 0;
        foreach ($nag as $l) if (preg_match('/^Set-Cookie:\s*fbqa=([^;]*)/i', $l, $mm)) $this->ciastko = 'fbqa=' . $mm[1];
        $json = json_decode((string)$odp, true);
        return [$kod, is_array($json) ? $json : [], (string)$odp];
    }
    public function get(string $akcja): array { return $this->zadanie('GET', '/api.php?action=' . $akcja); }
    public function post(string $akcja, array $dane = [], bool $zCsrf = true): array
    {
        return $this->zadanie('POST', '/api.php?action=' . $akcja, json_encode($dane), 'application/json', $zCsrf);
    }
    public function zaloguj(string $login, string $haslo): array
    {
        $this->get('session');
        $r = $this->post('login', ['username' => $login, 'password' => $haslo]);
        if (!empty($r[1]['csrf'])) $this->csrf = $r[1]['csrf'];
        return $r;
    }
    public function wyslijPlik(int $idZgloszenia, string $nazwa, string $dane, string $mime): array
    {
        $g = '----fbqa' . bin2hex(random_bytes(8));
        $t = "--$g\r\nContent-Disposition: form-data; name=\"files[]\"; filename=\"$nazwa\"\r\nContent-Type: $mime\r\n\r\n$dane\r\n--$g--\r\n";
        return $this->zadanie('POST', '/api.php?action=upload&id=' . $idZgloszenia, $t, "multipart/form-data; boundary=$g");
    }
}

/* ---------- asercje ---------- */

$zaliczone = 0;
$bledy = [];
function sprawdz(bool $warunek, string $opis, string $szczegoly = ''): void
{
    global $zaliczone, $bledy;
    if ($warunek) { $zaliczone++; echo "  ok   $opis\n"; }
    else { $bledy[] = $opis; echo "  BŁĄD $opis" . ($szczegoly !== '' ? "  →  $szczegoly" : '') . "\n"; }
}
function kod(array $r, int $oczekiwany, string $opis): array
{
    sprawdz($r[0] === $oczekiwany, $opis . " (HTTP $oczekiwany)", "HTTP {$r[0]} " . mb_substr($r[2], 0, 200));
    return $r[1];
}
function sekcja(string $t): void { echo "\n== $t\n"; }

/* ---------- 1. instalacja ---------- */

/** Instaluje aplikację przez install.php (SQLite). Zwraca HTML odpowiedzi. */
function zainstaluj(string $baza, string $haslo, ?array $db = null): string
{
    $db ??= ['driver' => 'sqlite'];
    $inst = new Klient($baza, 'instalator');
    [$k, , $html] = $inst->zadanie('GET', '/install.php');
    if ($k !== 200 || !preg_match('/name="csrf" value="([0-9a-f]+)"/', $html, $m)) return 'brak formularza: HTTP ' . $k;
    return $inst->zadanie('POST', '/install.php', http_build_query([
        'csrf' => $m[1], 'driver' => $db['driver'], 'host' => $db['host'] ?? '', 'port' => $db['port'] ?? '', 'name' => $db['name'] ?? '',
        'user' => $db['user'] ?? '', 'pass' => $db['pass'] ?? '', 'admin_login' => 'admin', 'admin_name' => 'Admin Testowy',
        'admin_email' => 'admin@example.test', 'mail_from' => $db['mail_from'] ?? '', 'admin_pass' => $haslo, 'admin_pass2' => $haslo,
    ]), 'application/x-www-form-urlencoded')[2];
}

// Hasła losowe przy każdym uruchomieniu — w repozytorium nie ma żadnych haseł.
$los = fn() => 'Los-' . bin2hex(random_bytes(8));
$H = array_map(fn() => $los(), array_flip(['T1_TMP', 'T2_TMP', 'OBS_TMP', 'X_TMP', 'T1_NOWE', 'T2_NOWE', 'OBS_NOWE', 'ZLE']));
$hasloAdmina = $los();

$DB = $MYSQL ? nowaBazaMysql() : ['driver' => 'sqlite'];
/** Bezpośredni dostęp do bazy testu (np. żeby „przesunąć czas”). */
function bazaTestu(): PDO
{
    global $DB, $kat;
    return $DB['driver'] === 'mysql'
        ? new PDO("mysql:host={$DB['host']};port={$DB['port']};dbname={$DB['name']};charset=utf8mb4", $DB['user'], $DB['pass'])
        : new PDO('sqlite:' . $kat . '/data/qa.sqlite');
}

sekcja('Kod źródłowy');
$node = trim((string)@shell_exec((PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null')));
if ($node === '') echo "  —    brak node — pomijam sprawdzenie składni JS\n";
else {
    exec('node --check ' . escapeshellarg(ZRODLO . '/public/assets/app.js') . ' 2>&1', $wy, $kodJs);
    sprawdz($kodJs === 0, 'składnia public/assets/app.js (node --check)', implode(' ', $wy));
    exec('node ' . escapeshellarg(ZRODLO . '/narzedzia/test-js.js') . ' 2>&1', $wyJs, $kodJs2);
    sprawdz($kodJs2 === 0, 'logika frontendu (narzedzia/test-js.js)', implode(' | ', array_filter($wyJs, fn($l) => str_contains($l, 'BŁĄD'))));
}
foreach (['app/bootstrap.php', 'app/schema.php', 'app/migrate.php', 'app/mail.php', 'app/backup.php', 'narzedzia/qa.php', 'public/api.php', 'public/install.php', 'public/index.php'] as $plikPhp) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg(ZRODLO . '/' . $plikPhp) . ' 2>&1', $wy2, $kodPhp);
    sprawdz($kodPhp === 0, "składnia $plikPhp");
}

sekcja('Instalacja (' . ($MYSQL ? 'MySQL ' . $DB['name'] : 'SQLite') . ')');
$inst = new Klient($BAZA, 'instalator');
$html = zainstaluj($BAZA, $hasloAdmina, $DB + ['mail_from' => 'testy@example.test']);
sprawdz(str_contains($html, 'Gotowe'), 'instalacja kończy się sukcesem', strip_tags(mb_substr($html, 0, 300)));
// Poczta w teście trafia do plików data/mail (żadnych prawdziwych wysyłek).
$cfg = require "$kat/config.php";
sprawdz(($cfg['mail']['from'] ?? '') === 'testy@example.test' && $cfg['mail']['transport'] === 'mail', 'instalator zapisuje adres nadawcy e-maili');
$cfg['mail'] = ['transport' => 'file', 'from' => 'testy@example.test', 'base_url' => 'https://testy.example.test/'];
file_put_contents("$kat/config.php", "<?php\nreturn " . var_export($cfg, true) . ";\n");
/** Zwraca e-maile zapisane od ostatniego wywołania (i czyści skrzynkę). */
function poczta(string $kat): array
{
    $out = [];
    foreach (glob("$kat/data/mail/*.eml") ?: [] as $p) { $out[] = file_get_contents($p); unlink($p); }
    return $out;
}
function doKogo(array $maile): array
{
    return array_map(fn($m) => preg_match('/^To: (\S+)/m', $m, $x) ? $x[1] : '?', $maile);
}
[, , $html] = $inst->zadanie('GET', '/install.php');
sprawdz(str_contains($html, 'już zainstalowana'), 'instalator blokuje się po instalacji');
[$k, , $html] = $inst->zadanie('GET', '/');
sprawdz($k === 200 && str_contains($html, 'app.js'), 'strona główna się otwiera');
[$k, , $js] = $inst->zadanie('GET', '/assets/app.js');
sprawdz($k === 200 && strlen($js) > 1000, 'skrypt aplikacji jest dostępny');

/* ---------- 2. bez logowania ---------- */

sekcja('Bez logowania');
$gosc = new Klient($BAZA, 'gość');
$s = $gosc->get('session')[1];
sprawdz(array_key_exists('user', $s) && $s['user'] === null, 'sesja bez użytkownika');
kod($gosc->get('issues'), 401, 'lista zgłoszeń wymaga logowania');
kod($gosc->get('file&id=1'), 401, 'pobranie pliku wymaga logowania');
kod($gosc->post('login', ['username' => 'admin', 'password' => $H['ZLE']]), 401, 'złe hasło odrzucone');

/* ---------- 3. administrator zakłada konta ---------- */

sekcja('Administrator');
$admin = new Klient($BAZA, 'admin');
kod($admin->zaloguj('admin', $hasloAdmina), 200, 'admin się loguje');
$mailem = new Klient($BAZA, 'admin e-mailem');
kod($mailem->zaloguj('Admin@Example.test', $hasloAdmina), 200, 'logowanie adresem e-mail (bez wielkości liter)');
kod($mailem->zaloguj('admin@example.test', $H['ZLE']), 401, 'logowanie e-mailem ze złym hasłem odrzucone');
$meta = kod($admin->get('meta'), 200, 'słowniki');
$pzb = null;
foreach ($meta['projects'] ?? [] as $pr) if ($pr['code'] === 'PZB') $pzb = $pr;
sprawdz($pzb !== null, 'projekt startowy PZB istnieje');
$pzbId = (int)($pzb['id'] ?? 0);
kod($admin->post('user_save', ['username' => 'tester1', 'display_name' => 'Tester Jeden', 'email' => 'tester1@example.test', 'role' => 'tester', 'active' => 1, 'password' => $H['T1_TMP']]), 200, 'admin zakłada testera');
kod($admin->post('user_save', ['username' => 'tester2', 'display_name' => 'Tester Dwa', 'email' => 'tester2@example.test', 'role' => 'tester', 'active' => 1, 'password' => $H['T2_TMP']]), 200, 'admin zakłada drugiego testera');
kod($admin->post('user_save', ['username' => 'obserwator', 'display_name' => 'Obserwator', 'role' => 'viewer', 'active' => 1, 'password' => $H['OBS_TMP']]), 200, 'admin zakłada obserwatora');
kod($admin->post('user_save', ['username' => 'tester3@example.test', 'display_name' => 'Tester Trzy', 'email' => 'tester3@example.test', 'role' => 'tester', 'active' => 1, 'password' => $H['T2_TMP']]), 200, 'login może być adresem e-mail');
kod($admin->post('user_save', ['username' => 'Zły Login!', 'display_name' => 'X', 'role' => 'tester', 'active' => 1, 'password' => $H['T2_TMP']]), 422, 'login ze spacją i wykrzyknikiem odrzucony');
kod($admin->post('user_save', ['username' => 'x1', 'display_name' => 'X', 'role' => 'tester', 'active' => 1, 'password' => $H['X_TMP']], false), 419, 'zapis bez tokenu CSRF odrzucony');

/* ---------- 4. tester: pierwsze logowanie ---------- */

sekcja('Tester — pierwsze logowanie');
$tester = new Klient($BAZA, 'tester1');
$r = kod($tester->zaloguj('tester1', $H['T1_TMP']), 200, 'tester się loguje hasłem tymczasowym');
sprawdz(!empty($r['user']['must_change_pw']), 'tester musi zmienić hasło');
kod($tester->get('issues'), 403, 'bez zmiany hasła nie ma dostępu');
kod($tester->post('password', ['current' => $H['T1_TMP'], 'new' => $H['T1_NOWE']]), 200, 'tester ustawia własne hasło');
kod($tester->get('issues'), 200, 'po zmianie hasła lista działa');

$tester2 = new Klient($BAZA, 'tester2');
$tester2->zaloguj('tester2', $H['T2_TMP']);
$tester2->post('password', ['current' => $H['T2_TMP'], 'new' => $H['T2_NOWE']]);
$obs = new Klient($BAZA, 'obserwator');
$obs->zaloguj('obserwator', $H['OBS_TMP']);
$obs->post('password', ['current' => $H['OBS_TMP'], 'new' => $H['OBS_NOWE']]);

/* ---------- 5. zgłoszenie z załącznikiem ---------- */

sekcja('Zgłoszenie z załącznikiem');
$noweZgl = ['project_id' => $pzbId, 'kind' => 'bug', 'title' => 'Wynik meczu nie zapisuje się', 'module' => 'Mecze i wyniki', 'priority' => 'critical', 'severity' => 'blocker',
    'description' => 'Po kliknięciu Zapisz wynik znika.', 'steps' => "1. Otwórz mecz\n2. Wpisz 80:75\n3. Zapisz", 'expected' => 'Wynik zapisany.',
    'environment' => 'demo.fullbase.pl', 'version' => '0.9', 'url' => 'https://demo.fullbase.pl/mecze/1',
    'sys_role' => 'Zarząd klubu', 'sys_account' => 'klub@pzball.test', 'suggestion' => 'Zapisuj wynik także przy remisie.'];
$z = kod($tester->post('issue_create', $noweZgl), 200, 'tester tworzy zgłoszenie');
$idZgl = (int)($z['issue']['id'] ?? 0);
sprawdz(($z['issue']['key'] ?? '') === sprintf('PZB-%04d', $idZgl), 'numer zgłoszenia w formacie PZB-0001');

sekcja('1.1 Waga i priorytet');
sprawdz(($z['issue']['severity'] ?? '') === 'blocker', 'tester ustawia wagę (blokuje pracę)');
sprawdz(($z['issue']['priority'] ?? '') === 'medium', 'priorytet od testera jest ignorowany (ustawia go administrator)');
sprawdz(isset($meta['severities']['data']), 'słownik wag w meta');
$zw = kod($tester->post('issue_create', ['project_id' => $pzbId, 'title' => 'Zła waga', 'description' => 'x', 'severity' => 'hakerska']), 200, 'nieznana waga');
sprawdz(($zw['issue']['severity'] ?? '') === 'annoying', 'nieznana waga zamienia się na „Utrudnia”');
$z = kod($tester->post('issue_update&id=' . $idZgl, ['priority' => 'low', 'severity' => 'data'] + $noweZgl), 200, 'tester zmienia wagę we własnym zgłoszeniu');
sprawdz(($z['issue']['severity'] ?? '') === 'data' && $z['issue']['priority'] === 'medium', 'tester zmienia wagę, ale nie priorytet');
$z = kod($admin->post('issue_update&id=' . $idZgl, ['priority' => 'critical', 'severity' => 'blocker'] + $noweZgl), 200, 'admin ustawia priorytet');
sprawdz(($z['issue']['priority'] ?? '') === 'critical', 'priorytet ustawiony przez administratora');
$l = $admin->get('issues')[1]['issues'] ?? [];
sprawdz(($l[1]['severity'] ?? $l[0]['severity'] ?? '') !== '', 'lista zgłoszeń zawiera wagę');
[, , $csv] = $admin->get('export');
sprawdz(str_contains($csv, ';Waga;') && str_contains($csv, 'Blokuje pracę'), 'eksport CSV zawiera wagę');

sekcja('1.11 Sugestia naprawy');
$d = $tester->get('issue&id=' . $idZgl)[1]['issue'] ?? [];
sprawdz(($d['suggestion'] ?? '') === 'Zapisuj wynik także przy remisie.', 'zgłoszenie zapamiętuje sugestię naprawy');
[, , $csv] = $admin->get('export');
sprawdz(str_contains($csv, 'Sugestia naprawy') && str_contains($csv, 'przy remisie'), 'eksport CSV zawiera sugestię');

sekcja('1.1b Rola i konto w testowanym systemie');
sprawdz(in_array('Zarząd klubu', $pzb['tested_roles'] ?? [], true) && in_array('Sędzia', $pzb['tested_roles'] ?? [], true), 'projekt PZB ma startową listę ról');
$d = $tester->get('issue&id=' . $idZgl)[1]['issue'] ?? [];
sprawdz(($d['sys_role'] ?? '') === 'Zarząd klubu' && ($d['sys_account'] ?? '') === 'klub@pzball.test', 'zgłoszenie zapamiętuje rolę i konto');
$l = $tester->get('issues')[1]['issues'] ?? [];
sprawdz(in_array('klub@pzball.test', array_column($l, 'sys_account'), true), 'lista zawiera konto testowe');
kod($tester->post('issue_create', ['project_id' => $pzbId, 'title' => 'x', 'description' => 'x', 'sys_account' => 'klub@pzball.test hasło: abc']), 422, 'hasło w polu konta odrzucone');
[, , $csv] = $admin->get('export');
sprawdz(str_contains($csv, 'Rola w testowanym systemie') && str_contains($csv, 'klub@pzball.test'), 'eksport CSV zawiera rolę i konto');
$pzbZapis = ['id' => $pzbId, 'code' => 'PZB', 'name' => $pzb['name'] ?? 'PZB', 'modules' => implode("\n", $pzb['modules'] ?? []),
    'environments' => implode("\n", $pzb['environments'] ?? []), 'tested_roles' => "Zarząd klubu\nSędzia\nNowa rola", 'active' => 1];
kod($tester->post('project_save', $pzbZapis), 403, 'tester nie zmienia listy ról projektu');
kod($admin->post('project_save', $pzbZapis), 200, 'admin zmienia listę ról projektu');
$pp = array_values(array_filter($admin->get('meta')[1]['projects'] ?? [], fn($p) => $p['id'] === $pzbId))[0] ?? [];
sprawdz(($pp['tested_roles'] ?? []) === ['Zarząd klubu', 'Sędzia', 'Nowa rola'], 'lista ról zapisana');
kod($obs->post('issue_create', $noweZgl), 403, 'obserwator nie może zgłaszać');
kod($tester->post('issue_create', ['project_id' => $pzbId, 'title' => '', 'description' => '']), 422, 'puste zgłoszenie odrzucone');

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
kod($tester->wyslijPlik($idZgl, 'zrzut.png', $png, 'image/png'), 200, 'tester dodaje zrzut ekranu');
kod($tester->wyslijPlik($idZgl, 'skrypt.php', '<?php echo 1;', 'image/png'), 415, 'plik PHP udający obraz odrzucony');
kod($obs->wyslijPlik($idZgl, 'zrzut.png', $png, 'image/png'), 403, 'obserwator nie dodaje plików');
$szcz = kod($tester->get('issue&id=' . $idZgl), 200, 'szczegóły zgłoszenia');
$idPliku = (int)($szcz['issue']['files'][0]['id'] ?? 0);
sprawdz($idPliku > 0, 'załącznik jest przy zgłoszeniu');
[$k, , $plik] = $obs->get('file&id=' . $idPliku);
sprawdz($k === 200 && $plik === $png, 'zalogowany pobiera plik w całości');
kod($tester2->post('file_delete&id=' . $idPliku), 403, 'cudzego załącznika nie można usunąć');

/* ---------- 6. uprawnienia do zgłoszenia ---------- */

sekcja('Uprawnienia');
kod($tester->post('issue_status&id=' . $idZgl, ['status' => 'closed']), 403, 'tester nie zamyka zgłoszenia ze statusu Nowe');
kod($tester2->post('issue_status&id=' . $idZgl, ['status' => 'closed']), 403, 'tester nie zamyka cudzego zgłoszenia ze statusu Nowe');
kod($obs->post('issue_status&id=' . $idZgl, ['status' => 'confirmed']), 403, 'obserwator nie zmienia statusu');
kod($tester2->post('issue_update&id=' . $idZgl, $noweZgl), 403, 'tester nie edytuje cudzego zgłoszenia');
kod($tester->post('issue_update&id=' . $idZgl, ['title' => 'Wynik meczu nie zapisuje się po kliknięciu Zapisz'] + $noweZgl), 200, 'tester edytuje własne nowe zgłoszenie');
kod($tester->get('users'), 403, 'tester nie widzi listy użytkowników');
kod($tester->post('issue_delete&id=' . $idZgl), 403, 'tester nie usuwa zgłoszeń');
kod($obs->post('comment&id=' . $idZgl, ['message' => 'Zarząd: ważne przed sezonem.']), 200, 'obserwator komentuje');

/* ---------- 7. naprawa i weryfikacja ---------- */

sekcja('Naprawa i weryfikacja');
kod($admin->post('issue_status&id=' . $idZgl, ['status' => 'confirmed']), 200, 'admin potwierdza');
kod($admin->post('issue_status&id=' . $idZgl, ['status' => 'progress']), 200, 'admin: w naprawie');
kod($tester->post('issue_update&id=' . $idZgl, $noweZgl), 403, 'tester nie edytuje zgłoszenia w naprawie');
kod($admin->post('issue_status&id=' . $idZgl, ['status' => 'fixed', 'fixed_in' => '0.9.3']), 200, 'admin: do weryfikacji');
kod($tester->post('issue_status&id=' . $idZgl, ['status' => 'reopened']), 422, 'odrzucenie poprawki wymaga komentarza');
$d = $tester->get('issue&id=' . $idZgl)[1]['issue'] ?? [];
sprawdz(($d['fixed_in'] ?? '') === '0.9.3', '1.3: zgłoszenie pamięta wersję z poprawką');
$bez = kod($tester->post('issue_create', ['project_id' => $pzbId, 'title' => 'Bez wersji', 'description' => 'x']), 200, '1.3: zgłoszenie pomocnicze')['issue']['id'] ?? 0;
kod($admin->post('issue_status&id=' . $bez, ['status' => 'fixed']), 422, '1.3: „Do weryfikacji” bez wersji odrzucone');
kod($tester->post('issue_status&id=' . $bez, ['status' => 'fixed', 'fixed_in' => '1.0']), 403, '1.3: tester nie ustawia „Do weryfikacji”');
kod($tester->post('issue_status&id=' . $idZgl, ['status' => 'reopened', 'comment' => 'Dalej znika przy remisie.']), 200, 'tester odrzuca poprawkę');
$admin->post('issue_status&id=' . $idZgl, ['status' => 'fixed', 'fixed_in' => '0.9.3']);
$z = kod($tester->post('issue_status&id=' . $idZgl, ['status' => 'closed']), 200, 'tester potwierdza poprawkę');
sprawdz(($z['issue']['status'] ?? '') === 'closed' && !empty($z['issue']['closed_at']), 'zgłoszenie zamknięte z datą');
$hist = $tester->get('issue&id=' . $idZgl)[1]['issue']['activity'] ?? [];
sprawdz(count(array_filter($hist, fn($a) => $a['kind'] === 'verify')) === 2, 'historia zawiera obie weryfikacje');

/* ---------- 1.6 powiadomienia e-mail ---------- */

sekcja('1.6 Powiadomienia e-mail');
poczta($kat);
$t2id = 0;
foreach ($admin->get('users')[1]['users'] ?? [] as $x) if ($x['username'] === 'tester2') $t2id = $x['id'];
$zn = kod($tester->post('issue_create', ['project_id' => $pzbId, 'title' => 'Dane zawodnika znikają', 'description' => 'x', 'severity' => 'data']), 200, 'zgłoszenie z wagą „psuje dane”')['issue'] ?? [];
$m = poczta($kat);
sprawdz(doKogo($m) === ['admin@example.test'] && str_contains($m[0] ?? '', '[' . ($zn['key'] ?? '') . ']') && str_contains($m[0] ?? '', 'https://testy.example.test/#/z/'), 'administrator dostaje e-mail o krytycznym zgłoszeniu (z linkiem)');
kod($tester->post('issue_create', ['project_id' => $pzbId, 'title' => 'Literówka', 'description' => 'x', 'severity' => 'cosmetic']), 200, 'zgłoszenie kosmetyczne');
sprawdz(poczta($kat) === [], 'kosmetyka nie wysyła e-maila');
kod($admin->post('issue_update&id=' . ($zn['id'] ?? 0), ['assignee_id' => $t2id, 'project_id' => $pzbId, 'title' => $zn['title'] ?? 'x', 'description' => 'x', 'severity' => 'data']), 200, 'admin przypisuje zgłoszenie');
sprawdz(doKogo(poczta($kat)) === ['tester2@example.test'], 'przypisany dostaje e-mail');
kod($admin->post('issue_status&id=' . ($zn['id'] ?? 0), ['status' => 'fixed', 'fixed_in' => '1.0']), 200, 'admin: do weryfikacji');
$m = poczta($kat);
sprawdz(doKogo($m) === ['tester1@example.test'] && str_contains($m[0] ?? '', 'weryfikacj') && str_contains($m[0] ?? '', '1.0'), 'zgłaszający dostaje e-mail „poprawka czeka na weryfikację”');
kod($tester->post('issue_status&id=' . ($zn['id'] ?? 0), ['status' => 'reopened', 'comment' => 'Dalej znika.']), 200, 'tester otwiera ponownie');
$k = doKogo(poczta($kat)); sort($k);
sprawdz($k === ['admin@example.test', 'tester2@example.test'], 'ponowne otwarcie: e-mail do administratora i przypisanego', implode(',', $k));
kod($gosc->post('account_save', ['notify' => false]), 401, 'ustawienia konta wymagają logowania');
kod($tester->post('account_save', ['notify' => false], false), 419, 'ustawienia konta bez tokenu CSRF odrzucone');
kod($tester->post('account_save', ['notify' => false]), 200, 'tester wyłącza powiadomienia');
sprawdz(($tester->get('session')[1]['user']['notify'] ?? true) === false, 'wyłączenie zapisane');
$admin->post('issue_status&id=' . ($zn['id'] ?? 0), ['status' => 'fixed', 'fixed_in' => '1.1']);
sprawdz(poczta($kat) === [], 'po wyłączeniu powiadomień tester nie dostaje e-maili');
kod($tester->post('account_save', ['notify' => true]), 200, 'tester włącza powiadomienia z powrotem');
kod($obs->post('account_save', ['notify' => false]), 200, 'obserwator też może wyłączyć powiadomienia');

/* ---------- 1.7 reset hasła ---------- */

sekcja('1.7 Reset hasła przez e-mail');
poczta($kat);
$anon = new Klient($BAZA, 'anonim');
$anon->csrf = $anon->get('session')[1]['csrf'] ?? '';
kod($anon->post('reset_request', ['login' => 'tester2'], false), 419, 'prośba o reset bez tokenu CSRF odrzucona');
$r1 = kod($anon->post('reset_request', ['login' => 'tester2']), 200, 'prośba o reset (istniejące konto)');
$r2 = kod($anon->post('reset_request', ['login' => 'nie-ma-takiego']), 200, 'prośba o reset (nieistniejące konto)');
sprawdz(($r1['message'] ?? 'a') === ($r2['message'] ?? 'b'), 'odpowiedź nie zdradza, czy konto istnieje');
$m = poczta($kat);
sprawdz(doKogo($m) === ['tester2@example.test'], 'e-mail z linkiem tylko do istniejącego konta');
$tok = preg_match('~#/reset/([0-9a-f]{64})~', $m[0] ?? '', $x) ? $x[1] : '';
sprawdz($tok !== '', 'e-mail zawiera link z tokenem');
kod($anon->post('reset_confirm', ['token' => $tok, 'new' => 'krotkie']), 422, 'za krótkie hasło odrzucone');
kod($anon->post('reset_confirm', ['token' => str_repeat('a', 64), 'new' => 'Nowe-Haslo-Reset-1']), 422, 'zmyślony token odrzucony');
$noweH = $los();
kod($anon->post('reset_confirm', ['token' => $tok, 'new' => $noweH]), 200, 'ustawienie nowego hasła z linku');
kod($anon->post('reset_confirm', ['token' => $tok, 'new' => $los()]), 422, 'link działa tylko raz');
$t2b = new Klient($BAZA, 'tester2b');
kod($t2b->zaloguj('tester2', $noweH), 200, 'logowanie nowym hasłem');
// link po godzinie
$anon->post('reset_request', ['login' => 'tester2@example.test']);
$m = poczta($kat);
$tok2 = preg_match('~#/reset/([0-9a-f]{64})~', $m[0] ?? '', $x) ? $x[1] : '';
sprawdz($tok2 !== '', 'reset po adresie e-mail też działa');
bazaTestu()->prepare('UPDATE qa_password_resets SET expires_at=? WHERE token_hash=?')->execute([date('Y-m-d H:i:s', time() - 1), hash('sha256', $tok2)]);
kod($anon->post('reset_confirm', ['token' => $tok2, 'new' => $los()]), 422, 'wygasły link (po 1 godzinie) odrzucony');
for ($i = 0; $i < 3; $i++) $anon->post('reset_request', ['login' => 'tester2']);
kod($anon->post('reset_request', ['login' => 'tester2']), 429, 'limit próśb o reset z jednego adresu');
poczta($kat);

/* ---------- 1.8 import scenariuszy z CSV ---------- */

sekcja('1.8 Import scenariuszy z CSV');
$plik = file_get_contents(ZRODLO . '/docs/scenariusze_krytyczne_PZBall.csv');
kod($tester->post('cases_import', ['csv' => $plik]), 403, 'tester nie importuje scenariuszy');
kod($admin->post('cases_import', ['csv' => $plik], false), 419, 'import bez tokenu CSRF odrzucony');
$przed = count($admin->get('cases')[1]['cases'] ?? []);
$pg = kod($admin->post('cases_import', ['csv' => $plik]), 200, 'podgląd importu scenariuszy z 0.6');
sprawdz(($pg['valid'] ?? 0) === 18 && ($pg['saved'] ?? -1) === 0, 'podgląd: 18 do zapisu, nic nie zapisano', json_encode(array_slice(array_filter($pg['rows'] ?? [], fn($x) => $x['status'] !== 'ok'), 0, 2), JSON_UNESCAPED_UNICODE));
sprawdz(count($admin->get('cases')[1]['cases'] ?? []) === $przed, 'podgląd niczego nie zapisuje');
sprawdz(!array_filter($pg['rows'] ?? [], fn($x) => str_contains($x['note'], 'Obszaru nie ma')), 'wszystkie obszary z pliku są na liście projektu PZB');
$zp = kod($admin->post('cases_import', ['csv' => $plik, 'commit' => true]), 200, 'zapis importu');
sprawdz(($zp['saved'] ?? 0) === 18 && count($admin->get('cases')[1]['cases'] ?? []) === $przed + 18, 'zapisano 18 scenariuszy');
$c1 = array_values(array_filter($admin->get('cases')[1]['cases'] ?? [], fn($c) => $c['title'] === 'Klub nie widzi danych innego klubu'))[0] ?? [];
sprawdz(($c1['priority'] ?? '') === 'critical' && str_contains($c1['steps'] ?? '', "\n2. "), 'priorytet z polskiej nazwy i wieloliniowe kroki zachowane');
$po = kod($admin->post('cases_import', ['csv' => $plik, 'commit' => true]), 200, 'ponowny import tego samego pliku');
sprawdz(($po['saved'] ?? -1) === 0 && count(array_filter($po['rows'] ?? [], fn($x) => $x['status'] === 'duplicate')) === 18, 'ponowny import pomija duplikaty');
$zly = "projekt,tytuł,kroki,priorytet\nXYZ,Coś,1. a,Wysoki\nPZB,,1. a,Wysoki\nPZB,Dobry,1. a,Pilne\n";
$zw = kod($admin->post('cases_import', ['csv' => $zly]), 200, 'plik z błędami (przecinki)');
sprawdz(array_column($zw['rows'] ?? [], 'status') === ['error', 'error', 'ok'] && ($zw['separator'] ?? '') === ',', 'błędne wiersze oznaczone, separator przecinek wykryty');
kod($admin->post('cases_import', ['csv' => "a;b\n1;2\n"]), 422, 'plik bez wymaganych kolumn odrzucony');
sekcja('1.16 Zestawy scenariuszy');
$c1 = array_values(array_filter($admin->get('cases')[1]['cases'] ?? [], fn($c) => $c['title'] === 'Klub nie widzi danych innego klubu'))[0] ?? [];
sprawdz(($c1['suites'] ?? []) === ['Szybki test', 'Pełna regresja'], 'zestawy z kolumny CSV zapisane', json_encode($c1['suites'] ?? null, JSON_UNESCAPED_UNICODE));
$cz = kod($tester->post('case_save', ['project_id' => $pzbId, 'title' => 'Zestaw test', 'steps' => '1. a', 'active' => 1, 'suites' => ' Telefon ;telefon, Szybki test ']), 200, 'tester zapisuje scenariusz z zestawami');
$cs = array_values(array_filter($tester->get('cases')[1]['cases'] ?? [], fn($c) => $c['id'] === ($cz['id'] ?? -1)))[0] ?? [];
sprawdz(($cs['suites'] ?? []) === ['Telefon', 'Szybki test'], 'zestawy oczyszczone (spacje, powtórzenia, różne separatory)');
$szybkie = count(array_filter($admin->get('cases')[1]['cases'] ?? [], fn($c) => $c['active'] && $c['project_id'] === $pzbId && in_array('Szybki test', $c['suites'], true)));
$rz = kod($tester->post('run_create', ['project_id' => $pzbId, 'name' => 'Szybki', 'suite' => 'szybki test']), 200, 'przebieg według zestawu (wielkość liter bez znaczenia)');
sprawdz(count($tester->get('run&id=' . (int)($rz['id'] ?? 0))[1]['run']['results'] ?? []) === $szybkie && $szybkie === 19, "przebieg ma tylko scenariusze z zestawu ($szybkie)");
kod($tester->post('run_create', ['project_id' => $pzbId, 'name' => 'X', 'suite' => 'Nie ma takiego']), 422, 'pusty zestaw — czytelny błąd');
$dopisz = "projekt;tytuł;kroki;zestawy\nPZB;Klub nie widzi danych innego klubu;1. a;Uprawnienia\n";
$rt = kod($admin->post('cases_import', ['csv' => $dopisz, 'commit' => true]), 200, 'ponowny import z nowym zestawem');
$c1 = array_values(array_filter($admin->get('cases')[1]['cases'] ?? [], fn($c) => $c['title'] === 'Klub nie widzi danych innego klubu'))[0] ?? [];
sprawdz(($rt['tagged'] ?? 0) === 1 && ($rt['saved'] ?? -1) === 0 && ($c1['suites'] ?? []) === ['Szybki test', 'Pełna regresja', 'Uprawnienia'], 'import dopisuje zestaw do istniejącego scenariusza, nie tworzy kopii');

/* ---------- 1.12 masowe akcje ---------- */

sekcja('1.12 Masowe akcje');
$m1 = kod($tester->post('issue_create', ['project_id' => $pzbId, 'title' => 'Masowe 1', 'description' => 'x']), 200, 'zgłoszenie do masowej zmiany 1')['issue']['id'] ?? 0;
$m2 = kod($tester->post('issue_create', ['project_id' => $pzbId, 'title' => 'Masowe 2', 'description' => 'x']), 200, 'zgłoszenie do masowej zmiany 2')['issue']['id'] ?? 0;
kod($tester->post('issues_bulk', ['ids' => [$m1, $m2], 'what' => 'priority', 'priority' => 'high']), 403, 'tester nie robi masowych zmian');
kod($admin->post('issues_bulk', ['ids' => [$m1, $m2], 'what' => 'priority', 'priority' => 'high'], false), 419, 'masowa zmiana bez tokenu CSRF odrzucona');
$r = kod($admin->post('issues_bulk', ['ids' => [$m1, $m2], 'what' => 'priority', 'priority' => 'high']), 200, 'admin zmienia priorytet dwóch zgłoszeń');
sprawdz(($r['done'] ?? 0) === 2, 'zmieniono 2 zgłoszenia');
$r = kod($admin->post('issues_bulk', ['ids' => [$m1, $m2], 'what' => 'status', 'status' => 'fixed']), 200, 'masowe „Do weryfikacji” bez wersji');
sprawdz(($r['done'] ?? -1) === 0 && count($r['errors'] ?? []) === 2, 'bez wersji nic nie zmieniono (błąd przy każdym)');
$r = kod($admin->post('issues_bulk', ['ids' => [$m1, $m2], 'what' => 'status', 'status' => 'fixed', 'fixed_in' => '2.0', 'comment' => 'Wydanie 2.0']), 200, 'masowe „Do weryfikacji” z wersją');
$d = $admin->get('issue&id=' . $m2)[1]['issue'] ?? [];
sprawdz(($r['done'] ?? 0) === 2 && $d['status'] === 'fixed' && $d['fixed_in'] === '2.0' && $d['priority'] === 'high', 'oba mają status, wersję i priorytet');
sprawdz(count(array_filter($d['activity'], fn($a) => $a['message'] === 'Wydanie 2.0')) === 1, 'komentarz dopisany do zgłoszenia');
$r = kod($admin->post('issues_bulk', ['ids' => [$m1], 'what' => 'status', 'status' => 'rejected']), 200, 'masowe odrzucenie');
sprawdz(($r['done'] ?? -1) === 0, 'masowe odrzucenie zablokowane (wymaga powodu)');
$r = kod($admin->post('issues_bulk', ['ids' => [$m1, $m2], 'what' => 'assignee', 'assignee_id' => $t2id]), 200, 'masowe przypisanie');
sprawdz(($admin->get('issue&id=' . $m1)[1]['issue']['assignee_id'] ?? 0) === $t2id, 'przypisanie zapisane');
poczta($kat);

/* ---------- 1.5 scenariusz regresji ---------- */

sekcja('1.5 Scenariusz regresji');
kod($obs->post('case_from_issue&id=' . $idZgl), 403, 'obserwator nie tworzy scenariusza regresji');
kod($tester->post('case_from_issue&id=' . $idZgl, [], false), 419, 'bez tokenu CSRF odrzucone');
$nz = kod($tester->post('issue_create', ['project_id' => $pzbId, 'title' => 'Otwarte', 'description' => 'x']), 200, 'zgłoszenie otwarte (pomocnicze)')['issue']['id'] ?? 0;
kod($tester->post('case_from_issue&id=' . $nz), 422, 'z otwartego zgłoszenia nie tworzy scenariusza');
$rc = kod($tester->post('case_from_issue&id=' . $idZgl), 200, 'tester tworzy scenariusz regresji z zamkniętego zgłoszenia');
$cs = array_values(array_filter($tester->get('cases')[1]['cases'] ?? [], fn($c) => $c['id'] === ($rc['id'] ?? -1)))[0] ?? [];
sprawdz(($cs['issue_id'] ?? 0) === $idZgl && str_contains($cs['title'] ?? '', 'PZB-') && str_contains($cs['steps'] ?? '', 'Wpisz 80:75'), 'scenariusz ma kroki zgłoszenia i powiązanie');
sprawdz(str_contains($cs['preconditions'] ?? '', 'klub@pzball.test'), 'warunki wstępne zawierają konto testowe');
$rc2 = kod($tester->post('case_from_issue&id=' . $idZgl), 200, 'drugie kliknięcie');
sprawdz(($rc2['existed'] ?? false) === true && ($rc2['id'] ?? 0) === ($rc['id'] ?? -1), 'drugie kliknięcie nie tworzy kopii');
sprawdz(($tester->get('issue&id=' . $idZgl)[1]['issue']['regression_case'] ?? 0) === ($rc['id'] ?? -1), 'zgłoszenie wskazuje swój scenariusz regresji');

/* ---------- 1.2 powody odrzucenia ---------- */

sekcja('1.2 Powody odrzucenia');
$oryg = kod($tester->post('issue_create', ['project_id' => $pzbId, 'title' => 'Oryginał', 'description' => 'x']), 200, 'zgłoszenie-oryginał')['issue'] ?? [];
$dup = kod($tester2->post('issue_create', ['project_id' => $pzbId, 'title' => 'To samo jeszcze raz', 'description' => 'x']), 200, 'zgłoszenie-duplikat')['issue'] ?? [];
$idO = (int)($oryg['id'] ?? 0); $idD = (int)($dup['id'] ?? 0);
kod($admin->post('issue_status&id=' . $idD, ['status' => 'rejected']), 422, 'odrzucenie bez powodu odrzucone');
kod($admin->post('issue_status&id=' . $idD, ['status' => 'rejected', 'reject_reason' => 'duplicate']), 422, 'duplikat wymaga numeru oryginału');
kod($admin->post('issue_status&id=' . $idD, ['status' => 'rejected', 'reject_reason' => 'duplicate', 'duplicate_of' => 'PZB-9999']), 422, 'nieistniejący oryginał odrzucony');
kod($admin->post('issue_status&id=' . $idD, ['status' => 'rejected', 'reject_reason' => 'duplicate', 'duplicate_of' => $dup['key'] ?? '']), 422, 'duplikat samego siebie odrzucony');
kod($tester->post('issue_status&id=' . $idD, ['status' => 'rejected', 'reject_reason' => 'not_bug']), 403, 'tester nie odrzuca zgłoszeń');
$z = kod($admin->post('issue_status&id=' . $idD, ['status' => 'rejected', 'reject_reason' => 'duplicate', 'duplicate_of' => $oryg['key'] ?? '']), 200, 'admin oznacza duplikat');
sprawdz(($z['issue']['reject_reason'] ?? '') === 'duplicate' && ($z['issue']['duplicate_of'] ?? 0) === $idO && ($z['issue']['duplicate_key'] ?? '') === ($oryg['key'] ?? '-'), 'duplikat wskazuje oryginał');
$o = $admin->get('issue&id=' . $idO)[1]['issue'] ?? [];
sprawdz(($o['duplicates'][0]['id'] ?? 0) === $idD, 'oryginał pokazuje swój duplikat (link w obie strony)');
sprawdz(str_contains(implode(' ', array_column($o['activity'] ?? [], 'message')), 'duplikat'), 'historia oryginału zawiera wpis o duplikacie');
$l = array_values(array_filter($admin->get('issues')[1]['issues'] ?? [], fn($i) => $i['id'] === $idD))[0] ?? [];
sprawdz(($l['reject_reason'] ?? '') === 'duplicate' && ($l['duplicate_key'] ?? '') === ($oryg['key'] ?? '-'), 'lista pokazuje powód odrzucenia');
[, , $csv] = $admin->get('export');
sprawdz(str_contains($csv, 'Duplikat ' . ($oryg['key'] ?? '-')), 'eksport CSV zawiera powód odrzucenia');
$z = kod($tester2->post('issue_status&id=' . $idD, ['status' => 'reopened', 'comment' => 'To jednak inny problem.']), 200, 'tester otwiera odrzucone ponownie');
sprawdz(($z['issue']['reject_reason'] ?? 'x') === '' && array_key_exists('duplicate_of', $z['issue'] ?? []) && $z['issue']['duplicate_of'] === null, 'ponowne otwarcie czyści powód');
kod($admin->post('issue_status&id=' . $idD, ['status' => 'rejected', 'reject_reason' => 'cannot_reproduce']), 200, 'odrzucenie bez komentarza, z powodem');

/* ---------- 8. scenariusze i przebieg testów ---------- */

sekcja('Scenariusze i przebieg testów');
$c = kod($tester->post('case_save', ['project_id' => $pzbId, 'module' => 'Mecze i wyniki', 'title' => 'Wpisanie wyniku meczu',
    'preconditions' => 'Mecz zaplanowany', 'steps' => "1. Otwórz mecz\n2. Wpisz wynik", 'expected' => 'Wynik widoczny', 'priority' => 'high', 'active' => 1]), 200, 'tester dodaje scenariusz');
$idCase = (int)($c['id'] ?? 0);
kod($obs->post('case_save', ['project_id' => $pzbId, 'title' => 'x', 'steps' => 'y', 'active' => 1]), 403, 'obserwator nie dodaje scenariuszy');
$run = kod($tester->post('run_create', ['project_id' => $pzbId, 'name' => 'Regresja testowa', 'environment' => 'demo.fullbase.pl', 'version' => '0.9']), 200, 'tester tworzy przebieg');
$idRun = (int)($run['id'] ?? 0);
kod($tester->post('result_save', ['run_id' => $idRun, 'case_id' => $idCase, 'result' => 'fail', 'note' => 'Wynik znika']), 200, 'tester zapisuje wynik: niezaliczony');
// 1.13: zrzut przy wyniku
$wyslijDoWyniku = function (Klient $k) use ($idRun, $idCase, $png) {
    $g = '----fbqa' . bin2hex(random_bytes(8));
    $t = "--$g\r\nContent-Disposition: form-data; name=\"files[]\"; filename=\"wynik.png\"\r\nContent-Type: image/png\r\n\r\n$png\r\n--$g--\r\n";
    return $k->zadanie('POST', "/api.php?action=result_upload&run=$idRun&case=$idCase", $t, "multipart/form-data; boundary=$g");
};
kod($wyslijDoWyniku($obs), 403, '1.13: obserwator nie dodaje zrzutów do wyników');
kod($wyslijDoWyniku($tester), 200, '1.13: tester dodaje zrzut przy wyniku');
$rr = array_values(array_filter($tester->get('run&id=' . $idRun)[1]['run']['results'] ?? [], fn($x) => $x['case_id'] === $idCase))[0] ?? [];
$rfId = (int)($rr['files'][0]['id'] ?? 0);
sprawdz($rfId > 0, '1.13: zrzut widoczny przy scenariuszu w przebiegu');
[$k, , $plik] = $obs->get('rfile&id=' . $rfId);
sprawdz($k === 200 && $plik === $png, '1.13: zalogowany pobiera zrzut wyniku');
kod($gosc->get('rfile&id=' . $rfId), 401, '1.13: zrzut wyniku wymaga logowania');
kod($tester2->post('rfile_delete&id=' . $rfId), 403, '1.13: cudzego zrzutu nie można usunąć');
$z2 = kod($tester->post('issue_create', ['project_id' => $pzbId, 'title' => 'Niezaliczony: Wpisanie wyniku meczu', 'description' => 'Wynik znika',
    'steps' => '1. Otwórz mecz', 'run_id' => $idRun, 'case_id' => $idCase]), 200, 'zgłoszenie z niezaliczonego scenariusza');
$r = kod($tester->get('run&id=' . $idRun), 200, 'szczegóły przebiegu');
$wynik = array_values(array_filter($r['run']['results'] ?? [], fn($x) => $x['case_id'] === $idCase))[0] ?? [];
sprawdz((int)($wynik['issue_id'] ?? 0) === (int)($z2['issue']['id'] ?? -1), 'wynik testu powiązany ze zgłoszeniem');
$zf = $tester->get('issue&id=' . (int)($z2['issue']['id'] ?? 0))[1]['issue']['files'] ?? [];
sprawdz(count($zf) === 1 && $zf[0]['orig_name'] === 'wynik.png', '1.13: zrzut z wyniku przeszedł do zgłoszenia');
kod($tester->post('run_status', ['id' => $idRun, 'status' => 'closed']), 200, 'tester zamyka przebieg');
kod($obs->post('run_rerun', ['id' => $idRun]), 403, '1.14: obserwator nie tworzy powtórki');
kod($tester->post('run_rerun', ['id' => $idRun], false), 419, '1.14: powtórka bez tokenu CSRF odrzucona');
$rr = kod($tester->post('run_rerun', ['id' => $idRun, 'version' => '0.9.4']), 200, '1.14: powtórka niezaliczonych');
$nr = $tester->get('run&id=' . (int)($rr['id'] ?? 0))[1]['run'] ?? [];
sprawdz(count($nr['results'] ?? []) === 1 && ($nr['results'][0]['case_id'] ?? 0) === $idCase && ($nr['version'] ?? '') === '0.9.4' && str_starts_with($nr['name'] ?? '', 'Powtórka:'), '1.14: w powtórce jest tylko niezaliczony scenariusz, z nową wersją');
$tester->post('result_save', ['run_id' => (int)($rr['id'] ?? 0), 'case_id' => $idCase, 'result' => 'pass']);
kod($tester->post('run_rerun', ['id' => (int)($rr['id'] ?? 0)]), 422, '1.14: gdy wszystko zaliczone — nie ma czego powtarzać');
kod($tester->post('result_save', ['run_id' => $idRun, 'case_id' => $idCase, 'result' => 'pass']), 422, 'zamknięty przebieg nie przyjmuje wyników');
if ($rfId) kod($tester->post('rfile_delete&id=' . $rfId), 422, 'z zamkniętego przebiegu tester nie usuwa zrzutów');
kod($tester->post('run_status', ['id' => 999999, 'status' => 'closed']), 404, 'zamknięcie nieistniejącego przebiegu: 404');
kod($tester->post('case_delete&id=' . $idCase), 403, 'tester nie usuwa scenariuszy');

/* ---------- 1.18 gotowość wydania ---------- */

sekcja('1.18 Gotowość wydania');
kod($gosc->get('readiness&project_id=' . $pzbId), 401, 'gotowość wymaga logowania');
$g = kod($obs->get('readiness&project_id=' . $pzbId), 200, 'obserwator widzi gotowość wydania');
sprawdz(($g['ready'] ?? true) === false && count($g['open_critical'] ?? []) > 0, 'z otwartymi krytycznymi zgłoszeniami — „jeszcze nie”');
sprawdz(count($g['critical_cases'] ?? []) >= 6, 'lista scenariuszy krytycznych (z importu)');
// projekt bez niczego jest gotowy; potem jeden niezaliczony scenariusz krytyczny psuje gotowość
$admin->post('project_save', ['code' => 'GOT', 'name' => 'Projekt gotowości', 'modules' => 'A', 'environments' => 'demo', 'active' => 1]);
$gid = 0; foreach ($admin->get('meta')[1]['projects'] ?? [] as $pr) if ($pr['code'] === 'GOT') $gid = $pr['id'];
sprawdz(($admin->get('readiness&project_id=' . $gid)[1]['ready'] ?? false) === true, 'pusty projekt — gotowy');
$gc = $admin->post('case_save', ['project_id' => $gid, 'title' => 'Krytyczny', 'steps' => '1. a', 'priority' => 'critical', 'active' => 1])[1]['id'] ?? 0;
$gr = $admin->post('run_create', ['project_id' => $gid, 'name' => 'R1', 'version' => '1.0'])[1]['id'] ?? 0;
$admin->post('result_save', ['run_id' => $gr, 'case_id' => $gc, 'result' => 'fail']);
$g = $admin->get('readiness&project_id=' . $gid)[1];
sprawdz(($g['ready'] ?? true) === false && ($g['failed'] ?? 0) === 1 && ($g['critical_cases'][0]['run_name'] ?? '') === 'R1', 'niezaliczony scenariusz krytyczny blokuje wydanie');
$gr2 = $admin->post('run_create', ['project_id' => $gid, 'name' => 'R2', 'version' => '1.1'])[1]['id'] ?? 0;
$admin->post('result_save', ['run_id' => $gr2, 'case_id' => $gc, 'result' => 'pass']);
$g = $admin->get('readiness&project_id=' . $gid)[1];
sprawdz(($g['ready'] ?? false) === true && ($g['critical_cases'][0]['version'] ?? '') === '1.1', 'liczy się ostatni wynik — po zaliczeniu gotowe');

/* ---------- 3.2 miary jakości ---------- */

sekcja('3.2 Miary jakości');
kod($gosc->get('metrics'), 401, 'miary wymagają logowania');
$mm = kod($obs->get('metrics'), 200, 'obserwator widzi miary');
sprawdz(($mm['closed_90'] ?? 0) >= 1 && is_numeric($mm['avg_days'] ?? null), 'średni czas do zamknięcia policzony');
sprawdz(($mm['reopened_90'] ?? 0) >= 1 && ($mm['verified_90'] ?? 0) >= ($mm['reopened_90'] ?? 0) && is_int($mm['reopen_rate'] ?? null), 'odsetek odrzuconych poprawek policzony', json_encode($mm));
$obszar = array_values(array_filter($mm['areas_30'] ?? [], fn($a) => $a['module'] === 'Mecze i wyniki'))[0] ?? [];
sprawdz(($obszar['count'] ?? 0) >= 1 && ($obszar['critical'] ?? 0) >= 1, 'błędy według obszaru z liczbą krytycznych');
sprawdz(($admin->get('metrics&project_id=' . $gid)[1]['closed_90'] ?? -1) === 0, 'filtr projektu działa');

/* ---------- 1.20 kopie zapasowe ---------- */

sekcja('1.20 Kopie zapasowe');
$bk = kod($admin->get('backups'), 200, 'admin widzi listę kopii');
sprawdz(count($bk['backups'] ?? []) >= 1 && !empty($bk['last']), 'pierwsza kopia zrobiła się sama (raz na dobę)');
kod($tester->get('backups'), 403, 'tester nie widzi kopii');
kod($tester->post('backup_now'), 403, 'tester nie robi kopii');
kod($admin->post('backup_now', [], false), 419, 'kopia bez tokenu CSRF odrzucona');
$nb = kod($admin->post('backup_now'), 200, 'admin robi kopię teraz');
[$k, , $plik] = $admin->get('backup_file&name=' . urlencode((string)($nb['name'] ?? '')));
sprawdz($k === 200 && ($MYSQL ? str_contains($plik, 'fullbase-qa-mysql-json') : str_starts_with($plik, 'SQLite format 3')), 'pobrana kopia to prawdziwa baza');
if (!$MYSQL) {
    $tmp = tempnam(sys_get_temp_dir(), 'fbqa'); file_put_contents($tmp, $plik);
    $ile = (int)(new PDO('sqlite:' . $tmp))->query('SELECT COUNT(*) FROM qa_issues')->fetchColumn(); @unlink($tmp);
    sprawdz($ile > 5, "kopia zawiera zgłoszenia ($ile)");
}
kod($tester->get('backup_file&name=' . urlencode((string)($nb['name'] ?? ''))), 403, 'tester nie pobiera kopii');
kod($admin->get('backup_file&name=' . urlencode('../config.php')), 404, 'nie da się pobrać pliku spoza katalogu kopii');
for ($i = 0; $i < 15; $i++) $admin->post('backup_now');
sprawdz(count($admin->get('backups')[1]['backups'] ?? []) === 14, 'zostaje 14 ostatnich kopii');

/* ---------- 1.19 klucze API i narzędzie dla Claude Code ---------- */

sekcja('1.19 Klucze API (Claude Code)');
kod($tester->post('api_key_create', ['name' => 'x']), 403, 'tester nie tworzy kluczy API');
kod($admin->post('api_key_create', ['name' => 'x'], false), 419, 'klucz bez tokenu CSRF odrzucony');
$kr = kod($admin->post('api_key_create', ['name' => 'Claude Code — test']), 200, 'admin tworzy klucz API');
$KLUCZ = (string)($kr['key'] ?? '');
sprawdz((bool)preg_match('/^fbqa_[0-9a-f]{40}$/', $KLUCZ), 'klucz ma właściwą postać');
$lk = $admin->get('api_keys')[1]['keys'] ?? [];
sprawdz(count($lk) === 1 && !isset($lk[0]['key_hash']) && str_starts_with($KLUCZ, $lk[0]['prefix']), 'lista kluczy pokazuje tylko początek, bez skrótu');
$kc = new Klient($BAZA, 'klucz');
$zKluczem = function (string $met, string $akcja, ?array $dane = null, string $k = '') use ($BAZA, $KLUCZ): array {
    $ctx = stream_context_create(['http' => ['method' => $met, 'header' => "X-QA-Key: " . ($k ?: $KLUCZ) . "\r\nContent-Type: application/json", 'content' => $dane === null ? '' : json_encode($dane), 'ignore_errors' => true]]);
    $o = (string)@file_get_contents("$BAZA/api.php?action=$akcja", false, $ctx);
    $nag = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    return [preg_match('#^HTTP/\S+\s+(\d+)#', $nag[0] ?? '', $m) ? (int)$m[1] : 0, json_decode($o, true) ?: [], $o];
};
kod($zKluczem('GET', 'issues'), 200, 'kluczem: lista zgłoszeń');
kod($zKluczem('GET', 'issue&id=' . $idZgl), 200, 'kluczem: szczegóły zgłoszenia');
kod($zKluczem('POST', 'comment&id=' . $idZgl, ['message' => 'Komentarz z Claude Code']), 200, 'kluczem: komentarz bez tokenu CSRF (nagłówek, nie ciasteczko)');
kod($zKluczem('GET', 'users'), 403, 'kluczem: brak dostępu do użytkowników');
kod($zKluczem('POST', 'user_save', ['username' => 'zly', 'display_name' => 'x', 'role' => 'admin', 'password' => 'Bardzo-dlugie-1']), 403, 'kluczem: nie można zakładać kont');
kod($zKluczem('POST', 'issue_delete&id=' . $idZgl, []), 403, 'kluczem: nie można usuwać zgłoszeń');
kod($zKluczem('GET', 'issues', null, 'fbqa_' . str_repeat('0', 40)), 401, 'zmyślony klucz odrzucony');
// narzędzie qa.php
$cel = (int)($tester->post('issue_create', ['project_id' => $pzbId, 'title' => 'Do naprawy przez Claude', 'description' => 'Opis', 'steps' => '1. krok'])[1]['issue']['id'] ?? 0);
$tester->wyslijPlik($cel, 'ekran.png', $png, 'image/png');
$admin->post('issue_status&id=' . $cel, ['status' => 'confirmed']);
putenv("FBQA_URL=$BAZA/"); putenv("FBQA_KEY=$KLUCZ");
$cli = fn(string $a) => shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ZRODLO . '/narzedzia/qa.php') . " $a 2>&1") ?? '';
$stary = getcwd(); chdir($kat);
$o = $cli('pokaz ' . sprintf('PZB-%04d', $cel));
sprawdz(str_contains($o, '# ' . sprintf('PZB-%04d', $cel) . ': Do naprawy przez Claude') && str_contains($o, '## Kroki do odtworzenia problemu'), 'qa.php pokaz: zgłoszenie w Markdownie', mb_substr($o, 0, 200));
$pob = glob($kat . '/.fbqa-pliki/' . sprintf('PZB-%04d', $cel) . '/*ekran.png');
sprawdz($pob && file_get_contents($pob[0]) === $png, 'qa.php pokaz: załącznik pobrany na dysk');
sprawdz(str_contains($cli('lista --projekt=PZB'), sprintf('PZB-%04d', $cel)), 'qa.php lista: zgłoszenie na liście otwartych');
$o = $cli('do-weryfikacji ' . sprintf('PZB-%04d', $cel) . ' 3.0 "Poprawione w walidacji"');
$d = $admin->get('issue&id=' . $cel)[1]['issue'] ?? [];
sprawdz($d['status'] === 'fixed' && $d['fixed_in'] === '3.0' && in_array('Poprawione w walidacji', array_column($d['activity'], 'message'), true), 'qa.php do-weryfikacji: status, wersja i komentarz', trim($o));
sprawdz(str_contains($cli('gotowosc PZB'), 'otwarte krytyczne'), 'qa.php gotowosc');
chdir($stary);
kod($admin->post('api_key_revoke', ['id' => $lk[0]['id'] ?? 0]), 200, 'admin wycofuje klucz');
kod($zKluczem('GET', 'issues'), 401, 'wycofany klucz nie działa');
putenv('FBQA_KEY'); putenv('FBQA_URL');
poczta($kat);

/* ---------- 9. eksport i wylogowanie ---------- */

sekcja('Eksport i wylogowanie');
[$k, , $csv] = $admin->get('export');
sprawdz($k === 200 && str_contains($csv, 'PZB-0001'), 'eksport CSV zawiera zgłoszenie');
kod($tester->post('logout'), 200, 'wylogowanie');
kod($tester->get('issues'), 401, 'po wylogowaniu brak dostępu');

/* ---------- 10. aktualizacja z wersji 2.0.1 (migracje) ---------- */

sekcja('Aktualizacja bazy z wersji 2.0.1 po podmianie plików');
$SCHEMAT = (function () { require_once ZRODLO . '/app/bootstrap.php'; require_once ZRODLO . '/app/migrate.php'; return qa_schema_target(); })();
$katS = $KATALOGI[] = sys_get_temp_dir() . '/fbqa-e2e-stara-' . bin2hex(random_bytes(4));
if (!wgrajZGita($katS, WERSJA_2_0_1)) {
    sprawdz(false, 'pobranie wersji 2.0.1 z gita', 'brak gita albo commita ' . WERSJA_2_0_1);
} else {
    [$srv, $adr] = uruchomSerwer($katS);
    $SERWERY[] = $srv;
    sprawdz(str_contains(zainstaluj($adr, $hasloAdmina, $MYSQL ? nowaBazaMysql() : null), 'Gotowe'), 'instalacja wersji 2.0.1');
    $a = new Klient($adr, 'admin');
    $a->zaloguj('admin', $hasloAdmina);
    $m = $a->get('meta')[1];
    $z = kod($a->post('issue_create', ['project_id' => (int)($m['projects'][0]['id'] ?? 0), 'title' => 'Zgłoszenie sprzed aktualizacji',
        'description' => 'Ma przetrwać migrację.', 'priority' => 'critical']), 200, 'zgłoszenie w wersji 2.0.1');
    zatrzymajSerwer($srv);

    wgrajBiezace($katS); // podmiana plików, data/ i config.php zostają
    [$srv, $adr] = uruchomSerwer($katS);
    $SERWERY[] = $srv;
    $a = new Klient($adr, 'admin');
    kod($a->zaloguj('admin', $hasloAdmina), 200, 'logowanie po podmianie plików');
    $s = $a->get('session')[1];
    sprawdz(($s['schema'] ?? 0) === $SCHEMAT, "schemat bazy podniesiony do wersji $SCHEMAT", 'jest: ' . json_encode($s['schema'] ?? null));
    $l = kod($a->get('issues'), 200, 'lista zgłoszeń po aktualizacji');
    sprawdz(($l['issues'][0]['title'] ?? '') === 'Zgłoszenie sprzed aktualizacji', 'dane sprzed aktualizacji zostały');
    sprawdz(($l['issues'][0]['severity'] ?? '') === 'blocker', 'stare krytyczne zgłoszenie dostało wagę „blokuje pracę”');
    kod($a->post('issue_create', ['project_id' => (int)($m['projects'][0]['id'] ?? 0), 'title' => 'Nowe po aktualizacji',
        'description' => 'x']), 200, 'nowe zgłoszenie po aktualizacji');
    $a2 = new Klient($adr, 'admin2');
    $a2->zaloguj('admin', $hasloAdmina);
    sprawdz(($a2->get('session')[1]['schema'] ?? 0) === $SCHEMAT, 'kolejne żądanie nie migruje drugi raz (wersja stała)');
}

/* ---------- podsumowanie ---------- */

echo "\n" . str_repeat('-', 50) . "\n";
if ($bledy) {
    echo "NIEZALICZONE: " . count($bledy) . " (zaliczone: $zaliczone)\n";
    foreach ($bledy as $b) echo "  - $b\n";
    exit(1);
}
echo "WSZYSTKO ZALICZONE: $zaliczone sprawdzeń\n";
exit(0);
