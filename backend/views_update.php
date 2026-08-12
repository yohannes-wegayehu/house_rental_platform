<?php
// Enable strict type checking for better code quality
declare(strict_types=1);

// Include database configuration and helper functions
require_once __DIR__ . '/config.php';

// Initialize database connection and get action parameter
$pdo = db();
$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'increment');

// Main router for views update actions
switch ($action) {
  case 'increment':
    // Increment property view count
    require_method('POST');
    $input = get_input();
    $propertyId = safe_int($input['property_id'] ?? 0, 0);
    if ($propertyId <= 0) send_error('property_id is required');

    // Increment property view count and update timestamp
    $stmt = $pdo->prepare('UPDATE properties SET views_count = views_count + 1, updated_at=NOW() WHERE id=:id');
    $stmt->execute([':id' => $propertyId]);

    // Fetch updated view count for response
    $stmt = $pdo->prepare('SELECT views_count FROM properties WHERE id=:id');
    $stmt->execute([':id' => $propertyId]);
    $views = (int)($stmt->fetch()['views_count'] ?? 0);

    // Return updated view count
    json_response(['ok' => true, 'views_count' => $views]);
    break;

  default:
    // Handle unknown views update actions
    send_error('Unknown action', 400);
}

