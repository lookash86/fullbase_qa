<?php
declare(strict_types=1);

/*
 * Import scenariuszy z plików CSV na serwerze, z linii poleceń (27.11.2026) — te same zasady co okno „Import CSV” w rejestrze (akcja cases_import):
 * nagłówki projekt, obszar, tytuł, warunki wstępne, kroki, oczekiwany wynik, priorytet, zestawy; scenariusz o tym samym tytule w projekcie nie powtarza się,
 * dostaje tylko brakujące zestawy. Autor — pierwszy aktywny administrator rejestru.
 *
 *   php narzedzia/import-scenariuszy.php [--probnie] plik.csv [plik.csv …]
 *   (na serwerze: sudo -u fb-qa php /srv/fullbase-qa/app/narzedzia/import-scenariuszy.php /tmp/*.csv)
 */

if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/bootstrap.php';
if (!function_exists('suites_in')) require dirname(__DIR__) . '/app/zestawy.php';   // starsza wersja rejestru na serwerze
if (!qa_config()) { fwrite(STDERR, "Rejestr nie jest zainstalowany (brak config.php).\n"); exit(2); }

$args = array_slice($argv, 1);
$probnie = in_array('--probnie', $args, true);
$pliki = array_values(array_filter($args, fn($a) => $a !== '--probnie'));
if (!$pliki) { fwrite(STDERR, "Podaj pliki CSV.\n"); exit(2); }

$p = db();
$autor = (int)$p->query("SELECT id FROM qa_users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$autor) { fwrite(STDERR, "Brak aktywnego administratora rejestru.\n"); exit(2); }

$norm = fn($s) => strtr(mb_strtolower(trim((string)$s)), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
$names = ['projekt' => 'project', 'obszar' => 'module', 'tytul' => 'title', 'warunki wstepne' => 'preconditions', 'kroki' => 'steps',
    'oczekiwany wynik' => 'expected', 'priorytet' => 'priority', 'zestawy' => 'suites', 'zestaw' => 'suites'];
$prioByLabel = [];
foreach (QA_PRIORITIES as $k => $l) { $prioByLabel[$norm($l)] = $k; $prioByLabel[$k] = $k; }
$projects = $p->query('SELECT id, code, name FROM qa_projects')->fetchAll();
$exists = $p->prepare('SELECT id, suites FROM qa_cases WHERE project_id = ? AND title = ?');
$ins = $p->prepare('INSERT INTO qa_cases(project_id,module,title,preconditions,steps,expected,priority,active,suites,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,1,?,?,?,?)');
$upd = $p->prepare('UPDATE qa_cases SET suites = ?, updated_at = ? WHERE id = ?');

$razem = ['nowe' => 0, 'zestawy' => 0, 'bez_zmian' => 0, 'bledy' => 0];
$p->beginTransaction();
foreach ($pliki as $plik) {
    $csv = (string)@file_get_contents($plik);
    if ($csv === '') { fwrite(STDERR, "$plik: pusty albo nie do odczytu.\n"); $razem['bledy']++; continue; }
    if (!mb_check_encoding($csv, 'UTF-8')) $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1250');
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $first = strtok($csv, "\n");
    $sep = substr_count((string)$first, ';') >= substr_count((string)$first, ',') ? ';' : ',';
    $h = fopen('php://temp', 'r+'); fwrite($h, $csv); rewind($h);
    $cols = [];
    foreach ((array)fgetcsv($h, 0, $sep, '"', '') as $i => $name) if (isset($names[$norm($name)])) $cols[$names[$norm($name)]] = $i;
    if (!isset($cols['project'], $cols['title'], $cols['steps'])) { fwrite(STDERR, "$plik: brak kolumn projekt, tytuł albo kroki.\n"); $razem['bledy']++; continue; }
    $w = ['nowe' => 0, 'zestawy' => 0, 'bez_zmian' => 0, 'bledy' => 0];
    $n = 1;
    while (($r = fgetcsv($h, 0, $sep, '"', '')) !== false) {
        $n++;
        if ($r === [null] || implode('', $r) === '') continue;
        $g = fn($k, $max) => mb_substr(trim((string)($r[$cols[$k] ?? -1] ?? '')), 0, $max);
        $pr = null;
        foreach ($projects as $x) if ($norm($x['code']) === $norm($g('project', 120)) || $norm($x['name']) === $norm($g('project', 120))) $pr = $x;
        $tytul = $g('title', 200); $kroki = $g('steps', 20000);
        if (!$pr || $tytul === '' || $kroki === '') { fwrite(STDERR, "$plik, wiersz $n: " . (!$pr ? 'nieznany projekt' : 'brak tytułu albo kroków') . "\n"); $w['bledy']++; continue; }
        $zestawy = suites_in($g('suites', 500));
        $exists->execute([$pr['id'], $tytul]);
        $jest = $exists->fetchAll();
        $teraz = qa_now();
        if ($jest) {
            $zm = false;
            foreach ($jest as $c) {
                $merged = suites_in([...suites_in((string)$c['suites']), ...$zestawy]);
                if (suites_str($merged) !== (string)$c['suites']) { $upd->execute([suites_str($merged), $teraz, $c['id']]); $zm = true; }
            }
            $w[$zm ? 'zestawy' : 'bez_zmian']++;
            continue;
        }
        $ins->execute([$pr['id'], $g('module', 120), $tytul, $g('preconditions', 10000), $kroki, $g('expected', 10000), $prioByLabel[$norm($g('priority', 30))] ?? 'medium',
            suites_str($zestawy), $autor, $teraz, $teraz]);
        $w['nowe']++;
    }
    fclose($h);
    echo basename($plik) . ": nowe {$w['nowe']}, dopisane zestawy {$w['zestawy']}, bez zmian {$w['bez_zmian']}, błędy {$w['bledy']}\n";
    foreach ($w as $k => $v) $razem[$k] += $v;
}
if ($probnie) { $p->rollBack(); echo "Próba — nic nie zapisano.\n"; }
else $p->commit();
echo "Razem: nowe {$razem['nowe']}, dopisane zestawy {$razem['zestawy']}, bez zmian {$razem['bez_zmian']}, błędy {$razem['bledy']}\n";
exit($razem['bledy'] ? 1 : 0);
