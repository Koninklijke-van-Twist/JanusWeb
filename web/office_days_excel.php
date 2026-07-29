<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/localization.php';
require_once __DIR__ . '/hours_data.php';
require_once __DIR__ . '/office_days_lib.php';
require_once __DIR__ . '/janus_xlsx.php';

if (hours_current_user_email() === '') {
    http_response_code(403);
    echo 'Geen gebruiker in sessie.';
    exit;
}
auth_require_page_access('kantoordagen');

$payload = office_days_collect($_GET['start'] ?? null, $_GET['end'] ?? null);

try {
    $writer = new JanusXlsxWriter();
    $binary = $writer->buildOfficeDaysExport(
        $payload['startLabel'],
        $payload['endLabel'],
        $payload['rows']
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Excel-export mislukt.';
    exit;
}

$filename = sprintf(
    'kantoordagen_%s_%s.xlsx',
    $payload['start']->format('Y-m-d'),
    $payload['end']->format('Y-m-d')
);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) strlen($binary));
header('Cache-Control: no-store');
echo $binary;
exit;
