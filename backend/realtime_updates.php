<?php
// Enable strict type checking for better code quality
declare(strict_types=1);

// Include database configuration and helper functions
require_once __DIR__ . '/config.php';

// Initialize database connection and get action parameter
$pdo = db();
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

// Main router for realtime updates
switch ($action) {
  case 'check_property_updates':
    // Check for property updates since last check
    require_method('GET');
    $user = require_auth(['renter', 'owner', 'admin']);
    $lastCheck = (int)($_GET['last_check'] ?? 0);
    
    // Get newly approved properties since last check
    $stmt = $pdo->prepare(
      "SELECT p.id, p.city, p.subcity, p.property_type, p.price, p.short_description,
              (SELECT pp.image_path FROM property_photos pp 
               WHERE pp.property_id = p.id ORDER BY pp.sort_order ASC, pp.id ASC LIMIT 1) AS cover_photo
       FROM properties p 
       WHERE p.status = 'active' AND p.updated_at > FROM_UNIXTIME(:last_check)
       ORDER BY p.updated_at DESC LIMIT 10"
    );
    $stmt->execute([':last_check' => $lastCheck]);
    $newProperties = $stmt->fetchAll();
    
    // Get user's property updates if owner
    $myPropertyUpdates = [];
    if ($user['role'] === 'owner') {
      $stmt = $pdo->prepare(
        "SELECT p.id, p.status, p.updated_at
         FROM properties p 
         WHERE p.owner_id = :uid AND p.updated_at > FROM_UNIXTIME(:last_check)
         ORDER BY p.updated_at DESC LIMIT 5"
      );
      $stmt->execute([':uid' => (int)$user['id'], ':last_check' => $lastCheck]);
      $myPropertyUpdates = $stmt->fetchAll();
    }
    
    // Return updates with current timestamp
    json_response([
      'ok' => true,
      'timestamp' => time(),
      'new_properties' => $newProperties,
      'my_property_updates' => $myPropertyUpdates,
    ]);
    break;

  case 'get_pending_count':
    // Get count of pending properties for admin dashboard
    require_method('GET');
    $user = require_auth(['admin']);
    
    // Count pending properties that have been paid for
    $stmt = $pdo->prepare(
      "SELECT COUNT(*) as count 
       FROM properties p 
       WHERE p.status = 'pending' AND EXISTS (
         SELECT 1 FROM payments pay 
         WHERE pay.property_id = p.id AND pay.status = 'success'
       )"
    );
    $stmt->execute();
    $count = $stmt->fetch()['count'] ?? 0;
    
    // Return pending count for admin notifications
    json_response(['ok' => true, 'pending_count' => (int)$count]);
    break;

  default:
    // Handle unknown realtime update actions
    send_error('Unknown action', 400);
}
?>
