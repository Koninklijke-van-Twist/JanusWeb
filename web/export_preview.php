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
 * Functions
 */
function preview_parse_ymd(?string $value): ?DateTimeImmutable
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
$requestedEmail = strtolower(trim((string) ($_GET['user'] ?? '')));
$email = $requestedEmail !== '' ? $requestedEmail : $sessionEmail;
if ($email !== $sessionEmail) {
    auth_require_page_access('kantoordagen');
} else {
    auth_require_page_access('urentracker');
}

$mode = (string) ($_GET['mode'] ?? 'month');
$selectedDay = preview_parse_ymd($_GET['day'] ?? null) ?? new DateTimeImmutable('today');
$data = hours_load($email, $email === $sessionEmail ? hours_current_user_name() : '');
$userName = hours_resolve_user_name($data, $email);
$logoPath = is_file(__DIR__ . '/kvt-logo-white.png')
    ? (__DIR__ . '/kvt-logo-white.png')
    : (__DIR__ . '/kvt-logo.png');

if ($mode === 'custom') {
    $start = preview_parse_ymd($_GET['start'] ?? null) ?? (new DateTimeImmutable('today'))->modify('-31 days');
    $end = preview_parse_ymd($_GET['end'] ?? null) ?? new DateTimeImmutable('today');
    if ($end < $start->modify('+1 day')) {
        $end = $start->modify('+1 day');
    }
    $firstDay = $start;
    $lastDay = $end;
    $periodLabel = janus_nl_long_date($start) . ' t/m ' . janus_nl_long_date($end);
} else {
    $firstDay = new DateTimeImmutable($selectedDay->format('Y-m-01'));
    $lastDay = $firstDay->modify('last day of this month');
    $periodLabel = janus_nl_month_year($selectedDay);
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$html = hours_build_pdf_html(
    $data,
    $firstDay,
    $lastDay,
    $userName,
    $email,
    $periodLabel,
    $logoPath
);

if ((string) ($_GET['fragment'] ?? '') === '1') {
    $style = '';
    if (preg_match('/<style>(.*?)<\/style>/s', $html, $m) === 1) {
        $style = '<style>' . $m[1] . '</style>';
    }
    $body = $html;
    if (preg_match('/<body>(.*?)<\/body>/s', $html, $m) === 1) {
        $body = $m[1];
    }
    echo $style . $body;
    exit;
}

echo $html;

