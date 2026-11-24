<?php
declare(strict_types=1);

/*
 * Kopie zapasowe bazy (1.20) — w data/kopie/, 14 ostatnich.
 * SQLite: VACUUM INTO (spójny plik bazy, także przy trybie WAL). MySQL: zrzut tabel qa_* do JSON.
 * Załączniki (data/uploads) nie wchodzą do kopii — kopiuj je przez FTP razem z całym data/.
 */

const QA_BACKUP_KEEP = 14;
const QA_BACKUP_DIR = QA_DATA . '/kopie';

function qa_backup_create(PDO $pdo, string $driver): string
{
    if (!is_dir(QA_BACKUP_DIR)) @mkdir(QA_BACKUP_DIR, 0770, true);
    $base = 'qa-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
    if ($driver === 'mysql') {
        $name = $base . '.json';
        $dump = ['format' => 'fullbase-qa-mysql-json', 'created_at' => qa_now(), 'schema' => qa_meta_get($pdo, 'schema'), 'tables' => []];
        foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'qa\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            if (!preg_match('/^qa_[a-z_]+$/', (string)$t)) continue;
            $dump['tables'][$t] = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        }
        if (file_put_contents(QA_BACKUP_DIR . '/' . $name, json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX) === false) throw new RuntimeException('Nie udało się zapisać kopii.');
    } else {
        $name = $base . '.sqlite';
        $pdo->exec('VACUUM INTO ' . $pdo->quote(QA_BACKUP_DIR . '/' . $name));
    }
    qa_meta_set($pdo, 'backup_at', qa_now());
    qa_backup_prune();
    return $name;
}

function qa_backup_list(): array
{
    $out = [];
    foreach (glob(QA_BACKUP_DIR . '/qa-*') ?: [] as $p) if (preg_match('/^qa-[0-9a-f-]+\.(sqlite|json)$/', basename($p))) $out[] = ['name' => basename($p), 'size' => filesize($p), 'created_at' => date('Y-m-d H:i:s', filemtime($p))];
    usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
    return $out;
}

function qa_backup_prune(): void
{
    foreach (array_slice(qa_backup_list(), QA_BACKUP_KEEP) as $old) @unlink(QA_BACKUP_DIR . '/' . $old['name']);
}

/** Raz na dobę, przy zwykłym żądaniu zalogowanej osoby. Najpierw zapis znacznika czasu — dwa równoległe żądania nie zrobią dwóch kopii. */
function qa_backup_maybe(PDO $pdo, string $driver): void
{
    try {
        $last = qa_meta_get($pdo, 'backup_at');
        if ($last !== null && strtotime($last) > time() - 86400) return;
        qa_meta_set($pdo, 'backup_at', qa_now());
        qa_backup_create($pdo, $driver);
    } catch (Throwable $e) {
        error_log('FULLBASE QA kopia: ' . $e->getMessage());
    }
}
