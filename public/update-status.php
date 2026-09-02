<?php
/**
 * SmartPRS update progress reader (2 Sep 2026).
 *
 * Deliberately standalone: while the updater runs, SmartPRS is in maintenance
 * mode and every route through Laravel answers 503, so the Updates screen could
 * not see its own progress. This file boots NOTHING — no framework, no
 * autoloader, no database. It reads the state file the updater writes and
 * returns it as JSON.
 *
 * Access is gated by the one-time token the screen received when it started the
 * update, so the file exposes nothing to a passer-by.
 *
 * Shipped in TWO places on purpose: public/update-status.php is the live one,
 * updater/update-status.php is the copy the updater restores from when an
 * update replaces public/.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

// Works from either location: public/ or updater/, both one level below the app root.
$stateFile = __DIR__.'/../storage/app/updates/state.json';
$token = isset($_GET['t']) ? (string) $_GET['t'] : '';

if (! is_file($stateFile)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'No update is in progress.']);
    exit;
}

$state = json_decode((string) file_get_contents($stateFile), true);
$state = is_array($state) ? $state : [];
$expected = (string) ($state['poll_token'] ?? '');

if ($expected === '' || $token === '' || ! hash_equals($expected, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Not authorised.']);
    exit;
}

$log = isset($state['log']) && is_array($state['log']) ? array_slice($state['log'], -25) : [];

echo json_encode([
    'ok' => true,
    'phase' => $state['phase'] ?? 'idle',
    'percent' => $state['percent'] ?? 0,
    'message' => $state['message'] ?? '',
    'current_version' => $state['current_version'] ?? null,
    'updated_at' => $state['updated_at'] ?? null,
    'log' => $log,
]);
