<?php
declare(strict_types=1);

/*
 * Wysyłka e-maili (powiadomienia, reset hasła).
 *
 * Konfiguracja w config.php (bez niej poczta jest wyłączona):
 *   'mail' => [
 *       'from'      => 'testy@fullbase.pl',   // adres nadawcy — na home.pl skrzynka w tej samej domenie
 *       'from_name' => 'FULLBASE QA',
 *       'transport' => 'mail',                 // mail = funkcja mail() (home.pl; VPS przez msmtp), file = zapis do data/mail (testy), off = wyłączone
 *       'base_url'  => 'https://testy.fullbase.pl/',  // opcjonalnie; domyślnie adres z bieżącego żądania
 *   ],
 * Błąd wysyłki nigdy nie przerywa żądania — trafia tylko do dziennika błędów PHP.
 */

function qa_mail_config(): array
{
    $m = qa_config()['mail'] ?? [];
    $m = is_array($m) ? $m : [];
    $m['transport'] ??= empty($m['from']) ? 'off' : 'mail';
    if (empty($m['from'])) $m['from'] = 'no-reply@' . preg_replace('/[^a-z0-9.-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $m['from_name'] ??= 'FULLBASE QA';
    return $m;
}

function qa_mail_enabled(): bool
{
    return in_array(qa_mail_config()['transport'], ['mail', 'file'], true);
}

/** Adres aplikacji do linków w e-mailach, np. https://testy.fullbase.pl/ */
function qa_base_url(): string
{
    $cfg = qa_mail_config();
    if (!empty($cfg['base_url'])) return rtrim((string)$cfg['base_url'], '/') . '/';
    // Bez base_url adres z nagłówka Host działa tylko na tym komputerze — na serwerze podrobiony Host dałby link z tokenem do obcej domeny (audyt 26.11.2026).
    if (!in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true) && PHP_SAPI !== 'cli') return '';
    $host = preg_replace('/[^a-z0-9.:-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return (qa_is_https() ? 'https' : 'http') . '://' . $host . $dir . '/';
}

function qa_mail(string $to, string $subject, string $body): bool
{
    $cfg = qa_mail_config();
    $to = trim(str_replace(["\r", "\n"], '', $to));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !qa_mail_enabled()) return false;
    $from = str_replace(["\r", "\n"], '', (string)$cfg['from']);
    $fromName = str_replace(["\r", "\n"], '', (string)$cfg['from_name']);
    $subject = str_replace(["\r", "\n"], ' ', $subject);
    $body .= "\n\n—\nFULLBASE QA · " . qa_base_url() . "\nPowiadomienia możesz wyłączyć w „Moim koncie”.\n";
    $headers = [
        'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $from . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: FULLBASE-QA',
    ];
    try {
        if ($cfg['transport'] === 'file') {
            $dir = QA_DATA . '/mail';
            if (!is_dir($dir)) @mkdir($dir, 0770, true);
            $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
            return file_put_contents("$dir/$name", "To: $to\nSubject: $subject\n" . implode("\n", $headers) . "\n\n$body") !== false;
        }
        $extra = filter_var($from, FILTER_VALIDATE_EMAIL) ? '-f' . $from : '';
        $ok = mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $body, implode("\r\n", $headers), $extra);
        if (!$ok) error_log('FULLBASE QA: mail() nie wysłał wiadomości do ' . $to);
        return $ok;
    } catch (Throwable $e) {
        error_log('FULLBASE QA poczta: ' . $e->getMessage());
        return false;
    }
}
