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
$dayParam = trim((string) ($_GET['day'] ?? $_POST['day'] ?? ''));
$backUrl = 'index.php' . ($dayParam !== '' ? ('?day=' . rawurlencode($dayParam)) : '');
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $minutes = max(0, (int) ($_POST['hours'] ?? 0)) * 60 + max(0, (int) ($_POST['minutes'] ?? 0));
    $seconds = ($_POST['direction'] ?? '') === 'subtract' ? -$minutes * 60 : $minutes * 60;
    try {
        hours_add_overtime_correction($data, $seconds, (string) ($_POST['reason'] ?? ''), $email);
        hours_save($email, $data);
        $message = 'Correctie opgeslagen.';
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = 'Opslaan mislukt.';
    }
}

$corrections = array_reverse(hours_get_overtime_corrections($data));
$correctionsTotal = hours_overtime_corrections_total_seconds($data);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Overuren corrigeren — Janus</title>
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
        label { display: block; font-size: 0.82rem; color: var(--kvt-muted); margin-bottom: 4px; }
        input[type="number"], input[type="text"] {
            width: 100%;
            padding: 8px;
            border: 1px solid var(--kvt-line);
            border-radius: 4px;
            margin-bottom: 10px;
            font-size: 1rem;
        }
        .direction { display: flex; gap: 14px; margin-bottom: 10px; }
        .direction label { display: inline-flex; align-items: center; gap: 6px; margin: 0; color: var(--kvt-text); font-size: 1rem; }
        .time-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 10px; }
        .btn, a.btn {
            appearance: none;
            border: 1px solid var(--kvt-line);
            background: #fff;
            color: var(--kvt-text);
            border-radius: 4px;
            padding: 8px 12px;
            text-decoration: none;
            display: inline-block;
            cursor: pointer;
            font-weight: 700;
        }
        .btn-primary { background: var(--kvt-main-blue); border-color: var(--kvt-main-blue); color: #fff; width: 100%; }
        .actions { display: flex; gap: 8px; margin-top: 8px; }
        .flash { padding: 8px 10px; border-radius: 4px; margin-bottom: 10px; font-size: 0.9rem; }
        .flash-ok { background: #e8f7ee; color: #146c2e; }
        .flash-err { background: #fdecec; color: #b42318; }
        .hint { font-size: 0.85rem; color: var(--kvt-muted); margin-bottom: 10px; }
        .plus { color: #006400; font-weight: 700; }
        .minus { color: #8b0000; font-weight: 700; }
        .history { list-style: none; margin: 8px 0 0; padding: 0; }
        .history li { padding: 8px 0; border-top: 1px solid var(--kvt-line); font-size: 0.9rem; }
        .history .meta { color: var(--kvt-muted); font-size: 0.8rem; }
    </style>
</head>
<body>
<div class="wrap">
    <h1 class="brand-display">Overuren corrigeren</h1>
    <div class="sub">Janus · <?= janus_h(hours_resolve_user_name($data, $email)) ?></div>

    <?php if ($message !== ''): ?>
        <div class="flash flash-ok"><?= janus_h($message) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="flash flash-err"><?= janus_h($error) ?></div>
    <?php endif; ?>

    <form method="post" class="panel">
        <input type="hidden" name="day" value="<?= janus_h($dayParam) ?>">
        <div class="hint">Een correctie wordt opgeteld bij het overurentotaal en blijft staan, ook als de maand ouder wordt. Je kunt een correctie niet verwijderen; maak zo nodig een tegengestelde correctie.</div>
        <div class="direction">
            <label><input type="radio" name="direction" value="add" checked> Erbij</label>
            <label><input type="radio" name="direction" value="subtract"> Eraf</label>
        </div>
        <div class="time-grid">
            <div>
                <label for="hours">Uren</label>
                <input type="number" id="hours" name="hours" min="0" max="999" step="1" value="0" required>
            </div>
            <div>
                <label for="minutes">Minuten</label>
                <input type="number" id="minutes" name="minutes" min="0" max="59" step="1" value="0" required>
            </div>
        </div>
        <label for="reason">Reden (verplicht)</label>
        <input type="text" id="reason" name="reason" maxlength="200" required>
        <button type="submit" class="btn btn-primary">Correctie opslaan</button>
        <div class="actions">
            <a class="btn" href="<?= janus_h($backUrl) ?>">Terug</a>
        </div>
    </form>

    <div class="panel">
        <strong>Historie</strong>
        <?php if ($corrections === []): ?>
            <div class="hint" style="margin:8px 0 0">Nog geen correcties.</div>
        <?php else: ?>
            <div class="hint" style="margin:8px 0 0">Totaal correcties:
                <span class="<?= $correctionsTotal >= 0 ? 'plus' : 'minus' ?>"><?= janus_h(hours_format_extra_label($correctionsTotal)) ?></span>
            </div>
            <ul class="history">
                <?php foreach ($corrections as $row): ?>
                    <li>
                        <span class="<?= $row['Seconds'] > 0 ? 'plus' : 'minus' ?>"><?= janus_h(hours_format_extra_label($row['Seconds'])) ?></span>
                        <?= janus_h($row['Reason']) ?>
                        <div class="meta"><?= janus_h($row['Date']) ?> · <?= janus_h($row['By']) ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
