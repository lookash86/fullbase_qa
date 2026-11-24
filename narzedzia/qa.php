<?php
declare(strict_types=1);

/*
 * FULLBASE QA — rejestr z linii poleceń (dla Claude Code przy naprawianiu zgłoszeń).
 *
 * Konfiguracja (zmienne środowiskowe, nigdy w repozytorium):
 *   FBQA_URL  adres rejestru, np. https://testy.fullbase.pl/
 *   FBQA_KEY  klucz API (Użytkownicy → Klucze API)
 *
 * Polecenia:
 *   php narzedzia/qa.php lista [--status=new|confirmed|progress|fixed|reopened|otwarte] [--projekt=PZB] [--waga=krytyczne]
 *   php narzedzia/qa.php pokaz PZB-0012          zgłoszenie w Markdownie + pobranie załączników do .fbqa-pliki/PZB-0012/
 *   php narzedzia/qa.php komentarz PZB-0012 "tekst"
 *   php narzedzia/qa.php status PZB-0012 progress   (confirmed, progress, …)
 *   php narzedzia/qa.php do-weryfikacji PZB-0012 0.9.3 "co zmieniono i jak sprawdzić"
 *   php narzedzia/qa.php gotowosc [PZB]
 */

$url = rtrim((string)getenv('FBQA_URL'), '/');
$key = (string)getenv('FBQA_KEY');
$args = array_slice($argv, 1);
$cmd = array_shift($args) ?? 'pomoc';

function blad(string $m, int $kod = 1): never { fwrite(STDERR, "Błąd: $m\n"); exit($kod); }
if ($cmd === 'pomoc' || $cmd === '--help' || $cmd === '-h') {
    preg_match('~/\*(.*?)\*/~s', (string)file_get_contents(__FILE__), $m);
    echo trim(preg_replace('/^ \* ?/m', '', $m[1] ?? '')), "\n";
    exit(0);
}
if ($url === '' || $key === '') blad("ustaw zmienne FBQA_URL i FBQA_KEY (klucz: Użytkownicy → Klucze API w rejestrze).\n  PowerShell: setx FBQA_URL \"https://testy.fullbase.pl/\"; setx FBQA_KEY \"fbqa_…\" — potem otwórz terminal ponownie.", 2);

/** @return array{0:int,1:string} */
function http(string $method, string $path, ?array $body = null): array
{
    global $url, $key;
    $h = ['X-QA-Key: ' . $key, 'Accept: application/json'];
    if ($body !== null) $h[] = 'Content-Type: application/json';
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $h), 'content' => $body === null ? '' : json_encode($body),
        'ignore_errors' => true, 'timeout' => 60]]);
    $out = @file_get_contents($url . '/' . ltrim($path, '/'), false, $ctx);
    if ($out === false) blad('brak połączenia z ' . $url);
    $nag = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    $kod = preg_match('#^HTTP/\S+\s+(\d+)#', $nag[0] ?? '', $m) ? (int)$m[1] : 0;
    return [$kod, $out];
}
function api(string $action, array $params = [], ?array $body = null): array
{
    [$kod, $out] = http($body === null ? 'GET' : 'POST', 'api.php?' . http_build_query(['action' => $action] + $params), $body);
    $j = json_decode($out, true);
    if ($kod >= 400 || !is_array($j)) blad(($j['error'] ?? 'odpowiedź serwera ' . $kod) . " ($kod)");
    return $j;
}
function id_z(string $k): int
{
    if (!preg_match('/(\d+)\s*$/', $k, $m)) blad("podaj numer zgłoszenia, np. PZB-0012 (było: „{$k}”)");
    return (int)$m[1];
}

switch ($cmd) {
    case 'lista':
        $o = [];
        foreach ($args as $a) if (preg_match('/^--(\w+)=(.*)$/u', $a, $m)) $o[$m[1]] = $m[2];
        $meta = api('meta');
        $st = $o['status'] ?? 'otwarte';
        $list = array_filter(api('issues')['issues'], fn($i) => ($st === 'otwarte' ? in_array($i['status'], ['new', 'confirmed', 'progress', 'reopened'], true) : ($st === 'wszystkie' || $i['status'] === $st))
            && (empty($o['projekt']) || strcasecmp($i['project_code'], $o['projekt']) === 0)
            && (($o['waga'] ?? '') !== 'krytyczne' || in_array($i['severity'], ['blocker', 'data'], true)));
        usort($list, fn($a, $b) => (['blocker' => 0, 'data' => 1, 'annoying' => 2, 'cosmetic' => 3][$a['severity']] <=> ['blocker' => 0, 'data' => 1, 'annoying' => 2, 'cosmetic' => 3][$b['severity']]) ?: $a['id'] <=> $b['id']);
        foreach ($list as $i) printf("%-9s %-17s %-15s %-14s %s\n", $i['key'], $meta['statuses'][$i['status']], $meta['severities'][$i['severity']] ?? '', mb_substr($i['module'] ?: '—', 0, 14), $i['title']);
        echo count($list) . " zgłoszeń\n";
        break;

    case 'pokaz':
        $x = api('issue', ['id' => id_z($args[0] ?? '')])['issue'];
        $meta = api('meta');
        $kat = getcwd() . '/.fbqa-pliki/' . $x['key'];
        $row = fn($k, $v) => $v ? "- **$k:** $v\n" : '';
        $sec = fn($t, $v) => $v ? "\n## $t\n$v\n" : '';
        echo "# {$x['key']}: {$x['title']}\n\n"
            . $row('Rodzaj', $meta['kinds'][$x['kind']] ?? '') . $row('Waga', $meta['severities'][$x['severity']] ?? '') . $row('Priorytet', $meta['priorities'][$x['priority']] ?? '')
            . $row('Status', $meta['statuses'][$x['status']] ?? '') . $row('Projekt / obszar', implode(' / ', array_filter([$x['project_name'], $x['module']])))
            . $row('Rola i konto w testowanym systemie', implode(' · ', array_filter([$x['sys_role'], $x['sys_account']])))
            . $row('Środowisko', $x['environment']) . $row('Wersja', $x['version']) . $row('Naprawiono w wersji', $x['fixed_in'])
            . $row('Urządzenie / przeglądarka', implode(' · ', array_filter([$x['device'], $x['browser']]))) . $row('Adres', $x['url'])
            . $row('W rejestrze', $url . '/#/z/' . $x['id'])
            . $sec('Co się dzieje (faktyczny wynik)', $x['description']) . $sec('Kroki do odtworzenia problemu', $x['steps'])
            . $sec('Oczekiwany wynik', $x['expected']) . $sec('Sugestia naprawy od testera', $x['suggestion'] ?? '');
        $kom = array_values(array_filter($x['activity'], fn($a) => $a['kind'] === 'comment'));
        if ($kom) echo "\n## Komentarze\n" . implode("\n", array_map(fn($c) => "- **{$c['user_name']}** (" . substr($c['created_at'], 0, 16) . '): ' . preg_replace('/\s*\R\s*/u', ' ', $c['message']), $kom)) . "\n";
        if ($x['files']) {
            if (!is_dir($kat)) mkdir($kat, 0777, true);
            echo "\n## Załączniki (pobrane — obrazy możesz obejrzeć narzędziem Read)\n";
            foreach ($x['files'] as $f) {
                [$kod, $dane] = http('GET', 'api.php?action=file&id=' . $f['id']);
                $nazwa = $f['id'] . '-' . preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $f['orig_name']);
                if ($kod === 200) { file_put_contents("$kat/$nazwa", $dane); echo "- $kat/$nazwa\n"; }
                else echo "- {$f['orig_name']}: nie udało się pobrać ($kod)\n";
            }
        }
        echo "\n---\nPo naprawie: commit `fix {$x['key']}: …`, test, potem: php narzedzia/qa.php do-weryfikacji {$x['key']} <wersja> \"co zmieniono\"\n";
        break;

    case 'komentarz':
        $id = id_z($args[0] ?? '');
        if (trim($args[1] ?? '') === '') blad('podaj treść komentarza w cudzysłowie');
        api('comment', ['id' => $id], ['message' => $args[1]]);
        echo "Dodano komentarz do zgłoszenia #$id.\n";
        break;

    case 'status':
        $x = api('issue_status', ['id' => id_z($args[0] ?? '')], ['status' => $args[1] ?? '', 'comment' => $args[2] ?? ''])['issue'];
        echo "{$x['key']}: status = {$x['status']}\n";
        break;

    case 'do-weryfikacji':
        if (trim($args[1] ?? '') === '') blad('podaj wersję z poprawką, np. 0.9.3');
        $x = api('issue_status', ['id' => id_z($args[0] ?? '')], ['status' => 'fixed', 'fixed_in' => $args[1], 'comment' => $args[2] ?? ''])['issue'];
        echo "{$x['key']}: Do weryfikacji, wersja {$x['fixed_in']}. Zgłaszający dostanie powiadomienie.\n";
        break;

    case 'gotowosc':
        $meta = api('meta');
        $pid = 0;
        foreach ($meta['projects'] as $p) if (strcasecmp($p['code'], $args[0] ?? 'PZB') === 0) $pid = $p['id'];
        if (!$pid) blad('nie ma projektu ' . ($args[0] ?? 'PZB'));
        $g = api('readiness', ['project_id' => $pid]);
        echo ($g['ready'] ? 'GOTOWE do wydania' : 'JESZCZE NIE') . " — otwarte krytyczne: " . count($g['open_critical']) . ", niezaliczone krytyczne: {$g['failed']}, bez wyniku: {$g['untested']}\n";
        foreach ($g['open_critical'] as $i) echo "  {$i['key']} {$i['title']} ({$meta['statuses'][$i['status']]})\n";
        break;

    default:
        blad("nieznane polecenie „{$cmd}”. Pomoc: php narzedzia/qa.php pomoc");
}
