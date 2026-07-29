<?php

/**
 * Constants
 */

const FLAG_SVGS = [
    'nl' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><rect width="900" height="600" fill="#AE1C28"/><rect width="900" height="400" fill="#fff"/><rect width="900" height="200" fill="#fff"/><rect width="900" height="200" y="0" fill="#AE1C28"/><rect width="900" height="200" y="200" fill="#fff"/><rect width="900" height="200" y="400" fill="#21468B"/></svg>',
    'en' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 40"><clipPath id="a"><path d="M0 0v40h60V0z"/></clipPath><clipPath id="b"><path d="M30 20h30v20zv20H0zH0V0zV0h30z"/></clipPath><g clip-path="url(#a)"><path d="M0 0v40h60V0z" fill="#012169"/><path d="M0 0l60 40m0-40L0 40" stroke="#fff" stroke-width="8"/><path d="M0 0l60 40m0-40L0 40" clip-path="url(#b)" stroke="#C8102E" stroke-width="5"/><path d="M30 0v40M0 20h60" stroke="#fff" stroke-width="13"/><path d="M30 0v40M0 20h60" stroke="#C8102E" stroke-width="8"/></g></svg>',
];

const SUPPORTED_LANGUAGES = [
    'nl' => ['flag' => '🇳🇱', 'label' => 'Nederlands'],
    'en' => ['flag' => '🇬🇧', 'label' => 'English'],
];

const LOCALE_BY_LANG = [
    'nl' => 'nl-NL',
    'en' => 'en-GB',
];

const TRANSLATIONS = [
    'nl' => [
        'lang.menu_aria' => 'Taal kiezen',
        'lang.switch_to' => 'Schakel naar %s',
        'app.title' => 'Janus',
        'janus.subtitle' => 'Urenregistratie',
    ],
    'en' => [
        'lang.menu_aria' => 'Choose language',
        'lang.switch_to' => 'Switch to %s',
        'app.title' => 'Janus',
        'janus.subtitle' => 'Time tracking',
    ],
];

/**
 * Functies
 */

function getUserPrefsPath(string $email): ?string
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $dir = __DIR__ . '/data/user_prefs';
    $filename = preg_replace('/[^a-z0-9._\-]/', '_', $email) . '.json';
    return $dir . '/' . $filename;
}

function loadUserPrefs(string $email): array
{
    $path = getUserPrefsPath($email);
    if ($path === null || !is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function saveUserPref(string $email, string $key, mixed $value): void
{
    $path = getUserPrefsPath($email);
    if ($path === null) {
        return;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $prefs = loadUserPrefs($email);
    $prefs[$key] = $value;
    file_put_contents($path, json_encode($prefs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/**
 * Light mode preference. Default true for first-time users without full tracker history.
 */
function janus_is_light_mode(string $email): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return true;
    }

    $prefs = loadUserPrefs($email);
    if (array_key_exists('lightMode', $prefs)) {
        return (bool) $prefs['lightMode'];
    }

    if (!function_exists('hours_load_existing')) {
        return true;
    }

    $data = hours_load_existing($email);
    if ($data === null || empty($data['SavedDays']) || !is_array($data['SavedDays'])) {
        return true;
    }

    foreach ($data['SavedDays'] as $day) {
        if (is_array($day) && function_exists('hours_is_full_tracker_day') && hours_is_full_tracker_day($day)) {
            return false;
        }
    }

    return true;
}

function janus_set_light_mode(string $email, bool $enabled): void
{
    saveUserPref($email, 'lightMode', $enabled);
}

function janus_light_setup_done(string $email): bool
{
    $prefs = loadUserPrefs($email);

    return !empty($prefs['lightSetupDone']);
}

function janus_set_light_setup_done(string $email, bool $done = true): void
{
    saveUserPref($email, 'lightSetupDone', $done);
}

/**
 * First light visit: ask for default office days before creating hours JSON.
 */
function janus_needs_light_setup(string $email): bool
{
    if (!janus_is_light_mode($email)) {
        return false;
    }

    return !janus_light_setup_done($email);
}

function getCurrentLanguage(): string
{
    $lang = strtolower(trim((string) ($_GET['lang'] ?? '')));
    if ($lang !== '' && isset(SUPPORTED_LANGUAGES[$lang])) {
        return $lang;
    }

    $email = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    if ($email !== '') {
        $prefs = loadUserPrefs($email);
        $prefLang = strtolower(trim((string) ($prefs['lang'] ?? '')));
        if ($prefLang !== '' && isset(SUPPORTED_LANGUAGES[$prefLang])) {
            return $prefLang;
        }
    }

    return 'nl';
}

function LOC(string $key, ...$args): string
{
    static $lang = null;
    if ($lang === null) {
        $lang = getCurrentLanguage();
    }

    $text = TRANSLATIONS[$lang][$key] ?? TRANSLATIONS['nl'][$key] ?? $key;
    if ($args !== []) {
        return sprintf($text, ...$args);
    }

    return $text;
}

// Persist language switch when requested
if (isset($_GET['lang'])) {
    $requested = strtolower(trim((string) $_GET['lang']));
    if (isset(SUPPORTED_LANGUAGES[$requested])) {
        $email = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
        if ($email !== '') {
            saveUserPref($email, 'lang', $requested);
        }
    }
}
