<?php

$supported = ['ht', 'fr'];
$candidate = strtolower(trim((string) ($_GET['lang'] ?? $_COOKIE['lang'] ?? '')));

if (! in_array($candidate, $supported, true)) {
    $accepted = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    $candidate = str_contains($accepted, 'fr') && ! str_contains($accepted, 'ht')
        ? 'fr'
        : 'ht';
}

service('request')->setLocale($candidate);

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($requestUri, PHP_URL_PATH);
$path = is_string($path) && $path !== '' ? $path : '/';
$isAdmin = str_starts_with($path, '/admin') || str_starts_with($path, '/index.php/admin');
$languagePath = htmlspecialchars($path, ENT_QUOTES, 'UTF-8');

return [
    'locale' => $candidate,
    'path' => $languagePath,
    'isAdmin' => $isAdmin,
];
