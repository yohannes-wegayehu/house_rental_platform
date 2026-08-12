<?php
// Enable strict type checking for better code quality
declare(strict_types=1);

// Include database configuration and helper functions
require_once __DIR__ . '/config.php';

// Initialize database connection and get current session ID
$pdo = db();
$authSessionId = current_auth_session_id();

// Revoke current user session if active
if ($authSessionId > 0) {
  $pdo->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE id = :id')->execute([':id' => $authSessionId]);
}

// Get remember me cookie for session invalidation
$cookieRaw = (string)($_COOKIE[REMEMBER_COOKIE_NAME] ?? '');
if ($cookieRaw !== '' && str_contains($cookieRaw, ':')) {
  [$selector] = explode(':', $cookieRaw, 2);
  if (preg_match('/^[a-f0-9]{32}$/', $selector)) {
    $pdo->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE session_selector = :sel')->execute([':sel' => $selector]);
  }
}

// Clear PHP session and destroy it if active
$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
  session_destroy();
}

// Clear remember me cookie
clear_remember_cookie();

// Return success response
json_response(['ok' => true]);

