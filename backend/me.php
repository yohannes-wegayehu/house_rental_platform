<?php
// Enable strict type checking for better code quality
declare(strict_types=1);

// Include database configuration and helper functions
require_once __DIR__ . '/config.php';

// Initialize database connection
$pdo = db();
// Determine the action to perform (GET/POST parameter, defaults to 'get')
$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'get');

// Route the request based on the action parameter
switch ($action) {
  case 'get':
    // Ensure this is a GET request for fetching user data
    require_method('GET');
    // Get the currently authenticated user from session
    $user = app_get_current_user();
    // Check if user is authenticated
    if (!$user) {
      send_error('Unauthorized', 401);
    }
    // Return user data in JSON format
    json_response(['ok' => true, 'user' => $user]);
    break;

  case 'update_profile':
    // Ensure this is a POST request for updating profile
    require_method('POST');
    // Require authentication and get user data
    $user = require_auth();
    // Get the POST input data
    $input = get_input();

    // Extract and sanitize the full name from input
    $fullName = trim((string)($input['full_name'] ?? ''));
    // Validate that full name is not empty
    if ($fullName === '') send_error('Full name is required');
    // Validate that full name doesn't exceed 100 characters
    if (mb_strlen($fullName) > 100) send_error('Full name is too long');

    // Prepare SQL statement to update user's full name
    $stmt = $pdo->prepare('UPDATE users SET full_name = :fn WHERE id = :id');
    // Execute the update with sanitized parameters
    $stmt->execute([
      ':fn' => $fullName,
      ':id' => (int)$user['id'],
    ]);

    // Fetch updated user data to return to client
    $freshStmt = $pdo->prepare('SELECT id, full_name, phone, role, is_banned FROM users WHERE id = :id LIMIT 1');
    $freshStmt->execute([':id' => (int)$user['id']]);
    $fresh = $freshStmt->fetch();
    // Verify user still exists after update
    if (!$fresh) send_error('User not found', 404);

    // Return success response with updated user data
    json_response([
      'ok' => true,
      'message' => 'Profile updated successfully',
      'user' => $fresh,
    ]);
    break;

  case 'change_password':
    // Ensure this is a POST request for password change
    require_method('POST');
    // Require authentication and get user data
    $user = require_auth();
    // Get the POST input data
    $input = get_input();

    // Extract current and new passwords from input
    $currentPassword = (string)($input['current_password'] ?? '');
    $newPassword = (string)($input['new_password'] ?? '');

    // Validate current password is provided
    if ($currentPassword === '') send_error('Current password is required');
    // Validate new password is provided
    if ($newPassword === '') send_error('New password is required');
    // Validate new password minimum length
    if (strlen($newPassword) < 6) send_error('New password must be at least 6 characters');
    // Ensure new password is different from current
    if ($currentPassword === $newPassword) send_error('New password must be different from current password');

    // Fetch current password hash from database
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int)$user['id']]);
    $dbUser = $stmt->fetch();
    // Verify user exists in database
    if (!$dbUser) send_error('User not found', 404);

    // Get stored password hash and verify current password
    $storedHash = (string)($dbUser['password_hash'] ?? '');
    if ($storedHash === '' || !password_verify($currentPassword, $storedHash)) {
      send_error('Current password is incorrect', 401);
    }

    // Hash the new password using bcrypt
    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
    // Update password hash in database
    $upd = $pdo->prepare('UPDATE users SET password_hash = :ph WHERE id = :id');
    $upd->execute([
      ':ph' => $newHash,
      ':id' => (int)$user['id'],
    ]);

    // Return success response for password change
    json_response(['ok' => true, 'message' => 'Password changed successfully']);
    break;

  default:
    // Handle unknown action requests
    send_error('Unknown action', 400);
}

