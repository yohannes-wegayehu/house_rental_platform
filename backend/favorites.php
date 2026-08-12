<?php
// Enable strict type checking for better code quality
declare(strict_types=1);

// Include database configuration and helper functions
require_once __DIR__ . '/config.php';

// Initialize database connection and get action parameter
$pdo = db();
$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');

// Main router for favorites system actions
switch ($action) {
  case 'list':
    // List user's favorite properties
    require_method('GET');
    $user = require_auth(['renter', 'owner', 'admin']);

    // Query to get favorite properties with cover photos
    $stmt = $pdo->prepare(
      "SELECT
        f.property_id,
        p.city,
        p.subcity,
        p.real_address,
        p.property_type,
        p.price,
        p.views_count,
        p.status,
        (
          SELECT pp.image_path FROM property_photos pp
          WHERE pp.property_id = p.id
          ORDER BY pp.sort_order ASC, pp.id ASC
          LIMIT 1
        ) AS cover_photo
      FROM favorites f
      JOIN properties p ON p.id = f.property_id
      WHERE f.user_id = :uid
      ORDER BY f.created_at DESC
      LIMIT 100"
    );
    $stmt->execute([':uid' => (int)$user['id']]);
    json_response(['ok' => true, 'items' => $stmt->fetchAll()]);
    break;

  case 'add':
    // Add a property to user's favorites
    require_method('POST');
    $user = require_auth(['renter', 'owner', 'admin']);
    $input = get_input();
    $propertyId = safe_int($input['property_id'] ?? 0, 0);
    if ($propertyId <= 0) send_error('property_id is required');

    // Validate property exists and is active
    $pStmt = $pdo->prepare('SELECT status FROM properties WHERE id=:id LIMIT 1');
    $pStmt->execute([':id' => $propertyId]);
    $status = $pStmt->fetch()['status'] ?? null;
    if (!$status) send_error('Property not found', 404);
    
    // Only renters can favorite active properties
    if ($user['role'] === 'renter' && $status !== 'active') send_error('Cannot favorite this property');

    // Insert favorite record (IGNORE prevents duplicates)
    $stmt = $pdo->prepare('INSERT IGNORE INTO favorites (user_id, property_id) VALUES (:uid, :pid)');
    $stmt->execute([':uid' => (int)$user['id'], ':pid' => $propertyId]);
    json_response(['ok' => true]);
    break;

  case 'remove':
    // Remove a property from user's favorites
    require_method('POST');
    $user = require_auth(['renter', 'owner', 'admin']);
    $input = get_input();
    $propertyId = safe_int($input['property_id'] ?? 0, 0);
    if ($propertyId <= 0) send_error('property_id is required');

    // Delete favorite record for user and property
    $stmt = $pdo->prepare('DELETE FROM favorites WHERE user_id=:uid AND property_id=:pid');
    $stmt->execute([':uid' => (int)$user['id'], ':pid' => $propertyId]);
    json_response(['ok' => true]);
    break;

  default:
    // Handle unknown favorites actions
    send_error('Unknown action', 400);
}

