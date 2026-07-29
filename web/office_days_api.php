<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/localization.php';
require_once __DIR__ . '/hours_data.php';
require_once __DIR__ . '/office_days_lib.php';

function office_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (hours_current_user_email() === '') {
    office_json(['ok' => false, 'error' => 'Geen gebruiker in sessie.'], 403);
}
auth_require_page_access('kantoordagen');

$payload = office_days_collect($_GET['start'] ?? null, $_GET['end'] ?? null);

office_json([
    'ok' => true,
    'start' => $payload['start']->format('Y-m-d'),
    'end' => $payload['end']->format('Y-m-d'),
    'startLabel' => $payload['startLabel'],
    'endLabel' => $payload['endLabel'],
    'rows' => $payload['rows'],
]);
