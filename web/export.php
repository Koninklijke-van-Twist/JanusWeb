<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/hours_data.php';
require_once __DIR__ . '/hours_pdf.php';

/**
 * Functies
 */

function janus_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function janus_parse_ymd(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    return $dt instanceof DateTimeImmutable ? $dt : null;
}

/**
 * Page load
 */

$sessionEmail = hours_current_user_email();
if ($sessionEmail === '') {
    http_response_code(403);
    echo 'Geen gebruiker in sessie.';
    exit;
}
$requestedEmail = strtolower(trim((string) ($_GET['user'] ?? $_POST['user'] ?? '')));
$email = $requestedEmail !== '' ? $requestedEmail : $sessionEmail;
if ($email !== $sessionEmail) {
    auth_require_page_access('kantoordagen');
} else {
    auth_require_page_access('urentracker');
}

$userName = hours_current_user_name();
$data = hours_load($email, $email === $sessionEmail ? $userName : '');
$userName = hours_resolve_user_name($data, $email);
// Repair polluted company-name once
if (trim((string) ($data['UserName'] ?? '')) !== $userName && $userName !== '') {
    $data['UserName'] = $userName;
}
$data['UserEmail'] = $email;

$mode = (string) ($_GET['mode'] ?? $_POST['mode'] ?? 'month');
$selectedDay = janus_parse_ymd($_GET['day'] ?? $_POST['day'] ?? null) ?? new DateTimeImmutable('today');
$logoPath = is_file(__DIR__ . '/kvt-logo-white.png')
    ? (__DIR__ . '/kvt-logo-white.png')
    : (__DIR__ . '/kvt-logo.png');
$error = '';

try {
    if ($mode === 'month' || ($mode === 'download' && (string) ($_POST['period'] ?? '') === 'month')) {
        $firstDay = new DateTimeImmutable($selectedDay->format('Y-m-01'));
        $lastDay = $firstDay->modify('last day of this month');
        $periodLabel = janus_nl_month_year($selectedDay);
        $fileName = sprintf(
            '%s%s %s - uren %s.pdf',
            $selectedDay->format('Y'),
            $selectedDay->format('m'),
            $data['UserName'] !== '' ? $data['UserName'] : 'uren',
            $periodLabel
        );

        $pdf = hours_build_pdf($data, $firstDay, $lastDay, (string) $data['UserName'], $email, $periodLabel, $logoPath);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $fileName) . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    if ($mode === 'download' && (string) ($_POST['period'] ?? '') === 'custom') {
        $start = janus_parse_ymd($_POST['start'] ?? null);
        $end = janus_parse_ymd($_POST['end'] ?? null);
        if (!$start || !$end || $end < $start->modify('+1 day')) {
            $error = 'Einddatum moet minstens één dag na startdatum liggen.';
        } else {
            $startLabel = janus_nl_long_date($start);
            $endLabel = janus_nl_long_date($end);
            $periodLabel = $startLabel . ' t/m ' . $endLabel;
            $fileName = sprintf(
                '%s - uren %s t∕m %s.pdf',
                $data['UserName'] !== '' ? $data['UserName'] : 'uren',
                $startLabel,
                $endLabel
            );
            $pdf = hours_build_pdf($data, $start, $end, (string) $data['UserName'], $email, $periodLabel, $logoPath);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $fileName) . '"');
            header('Content-Length: ' . strlen($pdf));
            echo $pdf;
            exit;
        }
    }
} catch (Throwable $e) {
    $error = 'PDF exporteren mislukt: ' . $e->getMessage();
}

// Custom period form (default: last 31 days → today)
$today = new DateTimeImmutable('today');
$defaultEnd = $today;
$defaultStart = $today->modify('-31 days');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Periode selecteren — Janus</title>
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="stylesheet" href="brand.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: var(--kvt-text); }
        .wrap { max-width: 360px; margin: 0 auto; padding: 16px; }
        h1 { margin: 0 0 12px; font-size: 1.25rem; color: var(--kvt-perkins-blue); }
        label { display: block; font-size: 0.85rem; color: var(--kvt-muted); margin-bottom: 4px; }
        input[type="date"] {
            width: 100%;
            padding: 8px;
            border: 1px solid var(--kvt-line);
            border-radius: 4px;
            margin-bottom: 12px;
            font-size: 1rem;
        }
        .actions { display: flex; gap: 8px; }
        .btn {
            appearance: none;
            border: 1px solid var(--kvt-line);
            background: #fff;
            border-radius: 4px;
            padding: 8px 12px;
            cursor: pointer;
            font-weight: 700;
            text-decoration: none;
            color: inherit;
        }
        .btn-primary {
            background: var(--kvt-main-blue);
            border-color: var(--kvt-main-blue);
            color: #fff;
        }
        .flash-err { background: #fdecec; color: #b42318; padding: 8px; border-radius: 4px; margin-bottom: 10px; }
    </style>
</head>
<body>
<div class="wrap">
    <h1 class="brand-display">Periode Selecteren</h1>
    <?php if ($error !== ''): ?>
        <div class="flash-err"><?= janus_h($error) ?></div>
        <p><a class="btn" href="index.php?day=<?= janus_h($selectedDay->format('Y-m-d')) ?>">Terug</a></p>
    <?php endif; ?>
    <?php if ($mode === 'custom' || $error !== ''): ?>
    <form method="post" action="export.php">
        <input type="hidden" name="mode" value="download">
        <input type="hidden" name="period" value="custom">
        <input type="hidden" name="day" value="<?= janus_h($selectedDay->format('Y-m-d')) ?>">
        <label for="start">Startdatum</label>
        <input type="date" id="start" name="start" required value="<?= janus_h($defaultStart->format('Y-m-d')) ?>">
        <label for="end">Einddatum</label>
        <input type="date" id="end" name="end" required value="<?= janus_h($defaultEnd->format('Y-m-d')) ?>">
        <div class="actions">
            <button type="submit" class="btn btn-primary">Opslaan</button>
            <a class="btn" href="index.php?day=<?= janus_h($selectedDay->format('Y-m-d')) ?>">Annuleren</a>
        </div>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
