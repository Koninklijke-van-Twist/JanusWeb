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

/**
 * Functies
 */

function janus_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Page load
 */

$email = hours_current_user_email();
if ($email === '') {
    http_response_code(403);
    echo 'Geen gebruiker in sessie.';
    exit;
}
auth_require_page_access('urentracker');

$data = hours_load($email, hours_current_user_name());
$history = hours_extra_history($data);
try {
    hours_save($email, $data);
} catch (Throwable $e) {
    // geschiedenis blijft zichtbaar; opgeslagen maandtotalen worden bij de volgende keer bijgewerkt
}

$dayParam = trim((string) ($_GET['day'] ?? ''));
$backUrl = 'index.php' . ($dayParam !== '' ? ('?day=' . rawurlencode($dayParam)) : '');
$rows = array_reverse($history);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Overuren historie — Janus</title>
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="stylesheet" href="brand.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: var(--kvt-text); }
        .wrap { max-width: 420px; margin: 0 auto; padding: 14px; }
        h1 { margin: 0 0 6px; font-size: 1.35rem; color: var(--kvt-perkins-blue); }
        .sub { color: var(--kvt-muted); margin-bottom: 14px; font-size: 0.9rem; }
        .panel { border: 1px solid var(--kvt-line); border-radius: 6px; padding: 12px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 0.82rem; color: var(--kvt-muted); font-weight: 400; }
        th + th, td + td { text-align: right; }
        th, td { padding: 6px 0; border-bottom: 1px solid var(--kvt-line); }
        .plus { color: #006400; font-weight: 700; }
        .minus { color: #8b0000; font-weight: 700; }
        .zero { color: #111; font-weight: 700; }
        .btn, a.btn {
            border: 1px solid var(--kvt-line);
            background: #fff;
            color: var(--kvt-text);
            border-radius: 4px;
            padding: 8px 12px;
            text-decoration: none;
            display: inline-block;
            font-weight: 700;
        }
    </style>
</head>
<body>
<div class="wrap">
    <h1 class="brand-display">Overuren historie</h1>
    <div class="sub">Janus · <?= janus_h(hours_resolve_user_name($data, $email)) ?></div>

    <div class="panel">
        <table>
            <thead>
                <tr><th>Maand</th><th>Overuren</th><th>Totaal</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= janus_h(janus_nl_month_abbrev($row['month']) . ' ' . $row['year']) ?></td>
                        <td class="<?= $row['seconds'] > 0 ? 'plus' : ($row['seconds'] < 0 ? 'minus' : 'zero') ?>"><?= janus_h(hours_format_extra_label($row['seconds'])) ?></td>
                        <td class="<?= $row['cumulative'] > 0 ? 'plus' : ($row['cumulative'] < 0 ? 'minus' : 'zero') ?>"><?= janus_h(hours_format_extra_label($row['cumulative'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <a class="btn" href="<?= janus_h($backUrl) ?>">Terug</a>
</div>
</body>
</html>
