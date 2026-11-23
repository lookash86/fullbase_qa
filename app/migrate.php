<?php
declare(strict_types=1);

/*
 * Migracje schematu — SQLite i MySQL 8.
 *
 * app/schema.php opisuje schemat w wersji 1 (wersja 2.0). Każda późniejsza zmiana to kolejny wpis w qa_migrations().
 * Numer wersji schematu bazy leży w qa_meta (k='schema'). Przy każdym połączeniu porównujemy go z kodem
 * i wykonujemy brakujące kroki. Kroki muszą być idempotentne (sprawdzają, czy kolumna/tabela już jest),
 * bo w MySQL DDL nie da się wycofać transakcją, a dwa równoległe żądania mogą ruszyć jednocześnie.
 *
 * Nowy krok: dopisz na końcu tablicy funkcję i NIGDY nie zmieniaj ani nie usuwaj kroków już wydanych.
 */

/** @return array<int, callable(PDO, string): void> numer wersji schematu => krok dochodzący do tej wersji */
function qa_migrations(): array
{
    return [
        // 2 — wersja 2.1: punkt 1.0 wprowadza tylko mechanizm; zapis daty aktualizacji.
        2 => function (PDO $pdo, string $driver): void {
            qa_meta_set($pdo, 'migrated_at', qa_now());
        },
        // 3 — 1.1: waga zgłoszenia obok priorytetu. Stare krytyczne dostają wagę „blokuje pracę”.
        3 => function (PDO $pdo, string $driver): void {
            qa_add_column($pdo, $driver, 'qa_issues', 'severity', "VARCHAR(12) NOT NULL DEFAULT 'annoying'");
            $pdo->exec("UPDATE qa_issues SET severity='blocker' WHERE priority='critical'");
            qa_add_index($pdo, $driver, 'qa_issues', 'qa_issues_severity', 'severity');
        },
        // 4 — 1.1b: rola i konto w testowanym systemie; lista ról definiowana w projekcie.
        4 => function (PDO $pdo, string $driver): void {
            qa_add_column($pdo, $driver, 'qa_projects', 'tested_roles', "VARCHAR(2000) NOT NULL DEFAULT ''");
            qa_add_column($pdo, $driver, 'qa_issues', 'sys_role', "VARCHAR(80) NOT NULL DEFAULT ''");
            qa_add_column($pdo, $driver, 'qa_issues', 'sys_account', "VARCHAR(120) NOT NULL DEFAULT ''");
            $roles = [
                'PZB' => "Administrator związku\nWydział gier (biuro)\nWydział ds. Sędziów\nWydział Dyscypliny\nZarząd związku\nKomisja licencyjna\nRedaktor strony\nSelekcjoner kadry\nZarząd klubu\nTrener\nAsystent trenera\nSędzia\nRodzic\nZawodnik / osoba\nKonto bez roli\nNiezalogowany (kibic)",
                'KLB' => "Prezes\nSekretarz\nSkarbnik\nTrener\nRodzic\nCzłonek / zawodnik\nNiezalogowany",
            ];
            $q = $pdo->prepare("UPDATE qa_projects SET tested_roles=? WHERE code=? AND tested_roles=''");
            foreach ($roles as $code => $list) $q->execute([$list, $code]);
        },
        // 5 — 1.2: powód odrzucenia i powiązanie duplikatu z oryginałem.
        5 => function (PDO $pdo, string $driver): void {
            qa_add_column($pdo, $driver, 'qa_issues', 'reject_reason', "VARCHAR(20) NOT NULL DEFAULT ''");
            qa_add_column($pdo, $driver, 'qa_issues', 'duplicate_of', $driver === 'mysql' ? 'BIGINT UNSIGNED NULL' : 'INTEGER NULL');
            qa_add_index($pdo, $driver, 'qa_issues', 'qa_issues_duplicate', 'duplicate_of');
        },
        // 6 — 1.3: wersja, w której wgrano poprawkę (wymagana przy przejściu na „Do weryfikacji”).
        6 => function (PDO $pdo, string $driver): void {
            qa_add_column($pdo, $driver, 'qa_issues', 'fixed_in', "VARCHAR(60) NOT NULL DEFAULT ''");
        },
    ];
}

function qa_schema_target(): int
{
    return max(array_keys(qa_migrations()) ?: [1]);
}

function qa_meta_get(PDO $pdo, string $k): ?string
{
    $q = $pdo->prepare('SELECT v FROM qa_meta WHERE k=?');
    $q->execute([$k]);
    $v = $q->fetchColumn();
    return $v === false ? null : (string)$v;
}

function qa_meta_set(PDO $pdo, string $k, string $v): void
{
    $u = $pdo->prepare('UPDATE qa_meta SET v=? WHERE k=?');
    $u->execute([$v, $k]);
    if ($u->rowCount() === 0 && qa_meta_get($pdo, $k) === null) {
        $pdo->prepare('INSERT INTO qa_meta(k,v) VALUES(?,?)')->execute([$k, $v]);
    }
}

function qa_has_table(PDO $pdo, string $driver, string $table): bool
{
    if ($driver === 'mysql') {
        $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    } else {
        $q = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
    }
    $q->execute([$table]);
    return (int)$q->fetchColumn() > 0;
}

function qa_has_column(PDO $pdo, string $driver, string $table, string $column): bool
{
    if ($driver === 'mysql') {
        $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $q->execute([$table, $column]);
        return (int)$q->fetchColumn() > 0;
    }
    if (!preg_match('/^[a-z_]+$/', $table)) throw new InvalidArgumentException('Zła nazwa tabeli.');
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $c) if ($c['name'] === $column) return true;
    return false;
}

/** Dodaje kolumnę, jeśli jej nie ma. $def bez nazwy, np. "VARCHAR(20) NOT NULL DEFAULT ''" (NOT NULL wymaga DEFAULT w SQLite). */
function qa_add_column(PDO $pdo, string $driver, string $table, string $column, string $def): void
{
    if (!qa_has_column($pdo, $driver, $table, $column)) $pdo->exec("ALTER TABLE $table ADD COLUMN $column $def");
}

/** Tworzy indeks, jeśli go nie ma (MySQL 8 nie zna CREATE INDEX IF NOT EXISTS). */
function qa_add_index(PDO $pdo, string $driver, string $table, string $name, string $cols): void
{
    if ($driver === 'mysql') {
        $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $q->execute([$table, $name]);
        if ((int)$q->fetchColumn() === 0) $pdo->exec("CREATE INDEX $name ON $table($cols)");
    } else {
        $pdo->exec("CREATE INDEX IF NOT EXISTS $name ON $table($cols)");
    }
}

/** Doprowadza bazę do wersji z kodu. Zwraca numer wersji po migracji. */
function qa_migrate(PDO $pdo, string $driver): int
{
    $current = (int)(qa_meta_get($pdo, 'schema') ?? '1');
    $target = qa_schema_target();
    if ($current >= $target) return $current;
    foreach (qa_migrations() as $version => $step) {
        if ($version <= $current) continue;
        $step($pdo, $driver);
        qa_meta_set($pdo, 'schema', (string)$version);
        $current = $version;
    }
    return $current;
}
