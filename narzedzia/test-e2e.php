<?php
declare(strict_types=1);

/*
 * FULLBASE QA — test całej ścieżki przez API (admin + tester + obserwator).
 *
 *   php narzedzia/test-e2e.php            test na czystej bazie SQLite
 *   php narzedzia/test-e2e.php --zachowaj nie usuwa katalogu tymczasowego (do oglądania bazy)
 *
 * Skrypt kopiuje app/ i public/ do katalogu tymczasowego, uruchamia tam wbudowany serwer PHP,
 * instaluje aplikację przez install.php i przechodzi przez API. Twojej lokalnej bazy nie dotyka.
 * Kod wyjścia: 0 = wszystko zaliczone, 1 = są błędy.
 */

const ZRODLO = __DIR__ . '/..';
const WERSJA_2_0_1 = '10b7c55'; // commit „FULLBASE QA 2.0.1” — punkt wyjścia testu migracji
$opcje = array_slice($argv, 1);
$zachowaj = in_array('--zachowaj', $opcje, true);

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
    foreach ($SERWERY as $s) zatrzymajSerwer($s);
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
function zainstaluj(string $baza, string $haslo): string
{
    $inst = new Klient($baza, 'instalator');
    [$k, , $html] = $inst->zadanie('GET', '/install.php');
    if ($k !== 200 || !preg_match('/name="csrf" value="([0-9a-f]+)"/', $html, $m)) return 'brak formularza: HTTP ' . $k;
    return $inst->zadanie('POST', '/install.php', http_build_query([
        'csrf' => $m[1], 'driver' => 'sqlite', 'admin_login' => 'admin', 'admin_name' => 'Admin Testowy',
        'admin_email' => '', 'admin_pass' => $haslo, 'admin_pass2' => $haslo,
    ]), 'application/x-www-form-urlencoded')[2];
}

// Hasła losowe przy każdym uruchomieniu — w repozytorium nie ma żadnych haseł.
$los = fn() => 'Los-' . bin2hex(random_bytes(8));
$H = array_map(fn() => $los(), array_flip(['T1_TMP', 'T2_TMP', 'OBS_TMP', 'X_TMP', 'T1_NOWE', 'T2_NOWE', 'OBS_NOWE', 'ZLE']));
$hasloAdmina = $los();

sekcja('Instalacja (SQLite)');
$inst = new Klient($BAZA, 'instalator');
$html = zainstaluj($BAZA, $hasloAdmina);
sprawdz(str_contains($html, 'Gotowe'), 'instalacja kończy się sukcesem', strip_tags(mb_substr($html, 0, 300)));
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
$meta = kod($admin->get('meta'), 200, 'słowniki');
$pzb = null;
foreach ($meta['projects'] ?? [] as $pr) if ($pr['code'] === 'PZB') $pzb = $pr;
sprawdz($pzb !== null, 'projekt startowy PZB istnieje');
$pzbId = (int)($pzb['id'] ?? 0);
kod($admin->post('user_save', ['username' => 'tester1', 'display_name' => 'Tester Jeden', 'role' => 'tester', 'active' => 1, 'password' => $H['T1_TMP']]), 200, 'admin zakłada testera');
kod($admin->post('user_save', ['username' => 'tester2', 'display_name' => 'Tester Dwa', 'role' => 'tester', 'active' => 1, 'password' => $H['T2_TMP']]), 200, 'admin zakłada drugiego testera');
kod($admin->post('user_save', ['username' => 'obserwator', 'display_name' => 'Obserwator', 'role' => 'viewer', 'active' => 1, 'password' => $H['OBS_TMP']]), 200, 'admin zakłada obserwatora');
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
    'sys_role' => 'Zarząd klubu', 'sys_account' => 'klub@pzball.test'];
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
$z2 = kod($tester->post('issue_create', ['project_id' => $pzbId, 'title' => 'Niezaliczony: Wpisanie wyniku meczu', 'description' => 'Wynik znika',
    'steps' => '1. Otwórz mecz', 'run_id' => $idRun, 'case_id' => $idCase]), 200, 'zgłoszenie z niezaliczonego scenariusza');
$r = kod($tester->get('run&id=' . $idRun), 200, 'szczegóły przebiegu');
$wynik = array_values(array_filter($r['run']['results'] ?? [], fn($x) => $x['case_id'] === $idCase))[0] ?? [];
sprawdz((int)($wynik['issue_id'] ?? 0) === (int)($z2['issue']['id'] ?? -1), 'wynik testu powiązany ze zgłoszeniem');
kod($tester->post('run_status', ['id' => $idRun, 'status' => 'closed']), 200, 'tester zamyka przebieg');
kod($tester->post('result_save', ['run_id' => $idRun, 'case_id' => $idCase, 'result' => 'pass']), 422, 'zamknięty przebieg nie przyjmuje wyników');
kod($tester->post('case_delete&id=' . $idCase), 403, 'tester nie usuwa scenariuszy');

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
    sprawdz(str_contains(zainstaluj($adr, $hasloAdmina), 'Gotowe'), 'instalacja wersji 2.0.1');
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
