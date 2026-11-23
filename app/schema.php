<?php
declare(strict_types=1);

/** Schemat bazy — wspólny dla SQLite i MySQL 8. Daty jako tekst 'RRRR-MM-DD GG:MM:SS' ustawiany przez PHP. */
function qa_schema(string $driver): array
{
    $my = $driver === 'mysql';
    $id = $my ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $ref = $my ? 'BIGINT UNSIGNED' : 'INTEGER';
    $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $dt = 'VARCHAR(19)';

    return [
        "CREATE TABLE qa_meta (k VARCHAR(50) PRIMARY KEY, v TEXT NOT NULL)$tail",
        "CREATE TABLE qa_users (
            id $id, username VARCHAR(80) NOT NULL UNIQUE, display_name VARCHAR(160) NOT NULL,
            email VARCHAR(190) NOT NULL DEFAULT '', password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'tester', active SMALLINT NOT NULL DEFAULT 1,
            must_change_pw SMALLINT NOT NULL DEFAULT 0, created_at $dt NOT NULL, last_login_at $dt NULL
        )$tail",
        "CREATE TABLE qa_projects (
            id $id, code VARCHAR(10) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL,
            modules TEXT NOT NULL, environments TEXT NOT NULL, active SMALLINT NOT NULL DEFAULT 1, position INT NOT NULL DEFAULT 0
        )$tail",
        "CREATE TABLE qa_issues (
            id $id, project_id $ref NOT NULL, kind VARCHAR(20) NOT NULL DEFAULT 'bug', title VARCHAR(200) NOT NULL,
            module VARCHAR(120) NOT NULL DEFAULT '', priority VARCHAR(12) NOT NULL DEFAULT 'medium', status VARCHAR(12) NOT NULL DEFAULT 'new',
            description TEXT NOT NULL, steps TEXT NOT NULL, expected TEXT NOT NULL,
            environment VARCHAR(80) NOT NULL DEFAULT '', version VARCHAR(60) NOT NULL DEFAULT '',
            device VARCHAR(80) NOT NULL DEFAULT '', browser VARCHAR(80) NOT NULL DEFAULT '', url VARCHAR(1000) NOT NULL DEFAULT '',
            solution TEXT NOT NULL, reporter_id $ref NOT NULL, assignee_id $ref NULL,
            created_at $dt NOT NULL, updated_at $dt NOT NULL, closed_at $dt NULL,
            FOREIGN KEY (project_id) REFERENCES qa_projects(id), FOREIGN KEY (reporter_id) REFERENCES qa_users(id),
            FOREIGN KEY (assignee_id) REFERENCES qa_users(id)
        )$tail",
        "CREATE INDEX qa_issues_status ON qa_issues(status)",
        "CREATE INDEX qa_issues_project ON qa_issues(project_id)",
        "CREATE TABLE qa_activity (
            id $id, issue_id $ref NOT NULL, user_id $ref NOT NULL, kind VARCHAR(20) NOT NULL, message TEXT NOT NULL, created_at $dt NOT NULL,
            FOREIGN KEY (issue_id) REFERENCES qa_issues(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES qa_users(id)
        )$tail",
        "CREATE INDEX qa_activity_issue ON qa_activity(issue_id)",
        "CREATE TABLE qa_attachments (
            id $id, issue_id $ref NOT NULL, user_id $ref NOT NULL, orig_name VARCHAR(200) NOT NULL, stored_name VARCHAR(80) NOT NULL,
            mime VARCHAR(80) NOT NULL, size INT NOT NULL, created_at $dt NOT NULL,
            FOREIGN KEY (issue_id) REFERENCES qa_issues(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES qa_users(id)
        )$tail",
        "CREATE TABLE qa_cases (
            id $id, project_id $ref NOT NULL, module VARCHAR(120) NOT NULL DEFAULT '', title VARCHAR(200) NOT NULL,
            preconditions TEXT NOT NULL, steps TEXT NOT NULL, expected TEXT NOT NULL, priority VARCHAR(12) NOT NULL DEFAULT 'medium',
            active SMALLINT NOT NULL DEFAULT 1, created_by $ref NOT NULL, created_at $dt NOT NULL, updated_at $dt NOT NULL,
            FOREIGN KEY (project_id) REFERENCES qa_projects(id), FOREIGN KEY (created_by) REFERENCES qa_users(id)
        )$tail",
        "CREATE TABLE qa_runs (
            id $id, project_id $ref NOT NULL, name VARCHAR(160) NOT NULL, environment VARCHAR(80) NOT NULL DEFAULT '',
            version VARCHAR(60) NOT NULL DEFAULT '', status VARCHAR(10) NOT NULL DEFAULT 'open', created_by $ref NOT NULL,
            created_at $dt NOT NULL, closed_at $dt NULL,
            FOREIGN KEY (project_id) REFERENCES qa_projects(id), FOREIGN KEY (created_by) REFERENCES qa_users(id)
        )$tail",
        "CREATE TABLE qa_results (
            id $id, run_id $ref NOT NULL, case_id $ref NOT NULL, result VARCHAR(10) NOT NULL DEFAULT 'pending', note TEXT NOT NULL,
            issue_id $ref NULL, tester_id $ref NULL, updated_at $dt NULL,
            UNIQUE (run_id, case_id),
            FOREIGN KEY (run_id) REFERENCES qa_runs(id) ON DELETE CASCADE, FOREIGN KEY (case_id) REFERENCES qa_cases(id) ON DELETE CASCADE
        )$tail",
        "CREATE TABLE qa_login_attempts (id $id, ip VARCHAR(64) NOT NULL, username VARCHAR(80) NOT NULL, created_at $dt NOT NULL)$tail",
        "CREATE INDEX qa_login_ip ON qa_login_attempts(ip)",
    ];
}

/** Startowe projekty FULLBASE — można je zmienić w panelu (Ustawienia → Projekty). */
function qa_seed_projects(PDO $pdo): void
{
    $pzball = "Logowanie i konta\nCzłonkowie / osoby\nKluby\nRozgrywki i terminarze\nMecze i wyniki\nSędziowie i obsada\nLicencje zawodników\nLicencje klubowe\nSzkolenia\nSprawy i kary\nOrgany i komisje\nKadra narodowa\nFinanse i opłaty\nStrona rozgrywek / redakcja\nStrona PZBall\nPortal klubowy\nPanel administracyjny\nSezony i ustawienia\nPowiadomienia i ankiety\nAplikacja mobilna\nInny obszar";
    $kluby = "Logowanie i konta\nLudzie / członkowie\nSekcje i zespoły\nKalendarz i treningi\nSkładki i wpłaty\nKomunikacja\nDokumenty\nSprzęt i obiekty\nRaporty\nStrona klubu\nUstawienia\nAplikacja mobilna\nInny obszar";
    $env = "demo.fullbase.pl\npzball.fullbase.pl\nkluby.fullbase.pl\nLokalnie";
    $q = $pdo->prepare('INSERT INTO qa_projects(code,name,modules,environments,active,position) VALUES(?,?,?,?,1,?)');
    $q->execute(['PZB', 'PZBall — system związku', $pzball, $env, 1]);
    $q->execute(['KLB', 'FULLBASE Kluby', $kluby, $env, 2]);
}
