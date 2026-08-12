<?php
// Enable strict type checking for better code quality
declare(strict_types=1);

// Include database configuration and helper functions
require_once __DIR__ . '/config.php';

// Enforce POST method for registration endpoint
require_method('POST');

// Get input data from request body
$input = get_input();

// Extract and trim registration fields
$fullName = trim((string)($input['full_name'] ?? ''));
$phoneRaw = (string)($input['phone'] ?? '');
$role = (string)($input['role'] ?? 'renter');
$password = (string)($input['password'] ?? '');
$confirmPassword = (string)($input['confirm_password'] ?? '');

// Validate full name requirement
if ($fullName === '') send_error('Full name is required');

// Normalize Ethiopian phone number format
$phone = normalize_ethiopia_phone($phoneRaw);
if ($phone === null) send_error('Invalid phone number. Expected 09XXXXXXXX or +2519XXXXXXXX');

// Validate user role (renter or owner)
if (!in_array($role, ['renter', 'owner'], true)) {
  send_error('Invalid role');
}

// Password validation rules
if (mb_strlen($password) < 6) send_error('Password must be at least 6 characters');
if ($password !== $confirmPassword) send_error('Password confirmation does not match');

// Initialize database connection
$pdo = db();

// Handle user registration with error handling
try {
  // Check if phone number already exists
  $stmt = $pdo->prepare('SELECT id FROM users WHERE phone = :p LIMIT 1');
  $stmt->execute([':p' => $phone]);
  if ($stmt->fetch()) {
    send_error('Phone number already registered', 409);
  }

  // Hash password for secure storage
  $passwordHash = password_hash($password, PASSWORD_BCRYPT);

  // Insert new user record
  $stmt = $pdo->prepare(
    'INSERT INTO users (full_name, phone, role, password_hash) VALUES (:fn, :ph, :r, :pw)'
  );
  $stmt->execute([
    ':fn' => $fullName,
    ':ph' => $phone,
    ':r' => $role,
    ':pw' => $passwordHash,
  ]);

  // Return success response
  json_response(['ok' => true]);
} catch (Throwable $e) {
  // Handle database errors with meaningful messages
  send_error('Database error', 500, ['details' => $e->getMessage()]);
}
