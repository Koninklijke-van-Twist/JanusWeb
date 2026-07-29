<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/localization.php';
require_once __DIR__ . '/hours_data.php';

/**
 * Functies
 */

function janus_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function janus_timespan_to_input(string $timespan): string
{
    return substr($timespan, 0, 5);
}

function janus_input_to_timespan(string $value): string
{
    $value = trim($value);
    if (preg_match('/^(\d{1,2}):(\d{2})/', $value, $m) !== 1) {
        return '00:00:00';
    }

    return sprintf('%02d:%02d:00', min(23, (int) $m[1]), min(59, (int) $m[2]));
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

$userName = hours_current_user_name();
$data = hours_load($email, $userName);
$userName = hours_resolve_user_name($data, $email);
$dayParam = trim((string) ($_GET['day'] ?? $_POST['day'] ?? ''));
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data['MondayHours'] = janus_input_to_timespan((string) ($_POST['MondayHours'] ?? '08:00'));
    $data['TuesdayHours'] = janus_input_to_timespan((string) ($_POST['TuesdayHours'] ?? '08:00'));
    $data['WednesdayHours'] = janus_input_to_timespan((string) ($_POST['WednesdayHours'] ?? '08:00'));
    $data['ThursdayHours'] = janus_input_to_timespan((string) ($_POST['ThursdayHours'] ?? '08:00'));
    $data['FridayHours'] = janus_input_to_timespan((string) ($_POST['FridayHours'] ?? '08:00'));
    $data['SaturdayHours'] = janus_input_to_timespan((string) ($_POST['SaturdayHours'] ?? '00:00'));
    $data['SundayHours'] = janus_input_to_timespan((string) ($_POST['SundayHours'] ?? '00:00'));
    $data['KilometerHomeWork'] = max(0, (float) str_replace(',', '.', (string) ($_POST['KilometerHomeWork'] ?? 0)));
    $data['UserName'] = $userName;
    $data['UserEmail'] = $email;

    try {
        hours_save($email, $data);
        $message = 'Instellingen opgeslagen.';
    } catch (Throwable $e) {
        $error = 'Opslaan mislukt.';
    }
}

$km = (float) ($data['KilometerHomeWork'] ?? 0);
$backUrl = 'index.php' . ($dayParam !== '' ? ('?day=' . rawurlencode($dayParam)) : '');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instellingen — Janus</title>
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
        .panel {
            border: 1px solid var(--kvt-line);
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 12px;
        }
        label { display: block; font-size: 0.82rem; color: var(--kvt-muted); margin-bottom: 4px; }
        input[type="time"], input[type="number"] {
            width: 100%;
            padding: 8px;
            border: 1px solid var(--kvt-line);
            border-radius: 4px;
            margin-bottom: 10px;
            font-size: 1rem;
        }
        .week-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 10px;
        }
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
        .btn-primary {
            background: var(--kvt-main-blue);
            border-color: var(--kvt-main-blue);
            color: #fff;
            width: 100%;
        }
        .actions { display: flex; gap: 8px; margin-top: 8px; }
        .flash { padding: 8px 10px; border-radius: 4px; margin-bottom: 10px; font-size: 0.9rem; }
        .flash-ok { background: #e8f7ee; color: #146c2e; }
        .flash-err { background: #fdecec; color: #b42318; }
        .hint { font-size: 0.85rem; color: var(--kvt-muted); margin-top: -4px; margin-bottom: 10px; }
        code { font-size: 0.8rem; }
    </style>
</head>
<body>
<div class="wrap">
    <h1 class="brand-display">Instellingen</h1>
    <div class="sub">Janus · <?= janus_h($userName !== '' ? $userName : $email) ?></div>

    <?php if ($message !== ''): ?>
        <div class="flash flash-ok"><?= janus_h($message) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="flash flash-err"><?= janus_h($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="day" value="<?= janus_h($dayParam) ?>">

        <div class="panel">
            <label for="KilometerHomeWork">Kilometers woon-werk (enkele reis)</label>
            <input type="number" id="KilometerHomeWork" name="KilometerHomeWork" min="0" step="1" value="<?= janus_h((string) (int) $km) ?>">
            <div class="hint" id="kmHint">(<?= (int) ($km * 2) ?> km totaal heen en terug)</div>
        </div>

        <div class="panel">
            <strong>Werkuren per week</strong>
            <div class="week-grid" style="margin-top:10px">
                <?php
                $days = [
                    'MondayHours' => 'Maandag',
                    'TuesdayHours' => 'Dinsdag',
                    'WednesdayHours' => 'Woensdag',
                    'ThursdayHours' => 'Donderdag',
                    'FridayHours' => 'Vrijdag',
                    'SaturdayHours' => 'Zaterdag',
                    'SundayHours' => 'Zondag',
                ];
                foreach ($days as $key => $label):
                ?>
                    <div>
                        <label for="<?= janus_h($key) ?>"><?= janus_h($label) ?></label>
                        <input type="time" id="<?= janus_h($key) ?>" name="<?= janus_h($key) ?>"
                               value="<?= janus_h(janus_timespan_to_input((string) ($data[$key] ?? '00:00:00'))) ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="panel">
            <strong>Opslaglocatie</strong>
            <p class="hint" style="margin-top:8px">
                Webopslag (vast): <code>web/cache/hours/<?= janus_h($email) ?>.json</code><br>
                Compatibel met WinForms <code>savedata.json</code> (zelfde JSON-structuur).
            </p>
        </div>

        <button type="submit" class="btn btn-primary">Opslaan</button>
        <div class="actions">
            <a class="btn" href="<?= janus_h($backUrl) ?>">Terug</a>
        </div>
    </form>
</div>
<script>
document.getElementById('KilometerHomeWork').addEventListener('input', function (e) {
    var v = parseInt(e.target.value, 10) || 0;
    document.getElementById('kmHint').textContent = '(' + (v * 2) + ' km totaal heen en terug)';
});
</script>
</body>
</html>
