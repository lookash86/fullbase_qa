<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (!qa_config()) { header('Location: install.php'); exit; }
qa_security_headers('text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'self'; img-src 'self' blob: data:; media-src 'self' blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'");
$v = QA_VERSION;
?><!doctype html>
<html lang="pl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#102d32">
<title>FULLBASE QA · Rejestr testów</title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
</head><body>
<div id="app"><div class="boot">Ładowanie…</div></div>
<div id="toast" role="status" aria-live="polite"></div>
<script src="assets/app.js?v=<?= $v ?>"></script>
</body></html>
