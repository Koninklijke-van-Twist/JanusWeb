<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/localization.php';
require_once __DIR__ . '/hours_data.php';

function tracker_mode_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$email = hours_current_user_email();
if ($email === '') {
    tracker_mode_json(['ok' => false, 'error' => 'Geen gebruiker in sessie.'], 403);
}
auth_require_page_access('urentracker');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tracker_mode_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$light = !empty($_POST['light']);
janus_set_light_mode($email, $light);

tracker_mode_json([
    'ok' => true,
    'lightMode' => $light,
]);
