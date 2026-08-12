<?php
// Enable strict type checking for better code quality
declare(strict_types=1);

// Include database configuration and helper functions
require_once __DIR__ . '/config.php';

// Enforce POST method for login endpoint
require_method('POST');

// Get input data from request body
$input = get_input();

// Extract and normalize login credentials
$identifierRaw = (string)($input['identifier'] ?? '');
$password = (string)($input['password'] ?? '');

// Normalize identifier to Ethiopian phone format
$phone = normalize_ethiopia_phone($identifierRaw);
if ($phone === null) send_error('Invalid identifier. Expected 09XXXXXXXX or +2519XXXXXXXX');

// Look up user by phone number
$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM users WHERE phone = :ph LIMIT 1');
$stmt->execute([':ph' => $phone]);
$user = $stmt->fetch();

// Validate user exists and is not banned
if (!$user) send_error('Invalid phone or password', 401);
if (!empty($user['is_banned'])) send_error('Account is blocked', 403);

// Verify password against stored hash
if (!password_verify($password, (string)$user['password_hash'])) {
  send_error('Invalid phone or password', 401);
}

// Prepare authentication payload for session
$authUser = [
  'id' => (int)$user['id'],
  'full_name' => (string)$user['full_name'],
  'phone' => (string)$user['phone'],
  'role' => (string)$user['role'],
  'is_banned' => (int)$user['is_banned'],
];

// Create persistent session (allows multiple devices)
$authSession = create_user_session((int)$user['id'], true);

// Establish authenticated session and set remember cookie
establish_authenticated_session($authUser, (int)$authSession['id']);
set_remember_cookie((string)$authSession['cookie_token'], (int)$authSession['expires_ts']);

// Return success response with user data
json_response(['ok' => true, 'user' => $authUser]);
