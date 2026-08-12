<?php
// Enable strict type checking for better code quality
declare(strict_types=1);

// Include database configuration and helper functions
require_once __DIR__ . '/config.php';

// Initialize database connection and get property ID
$pdo = db();
$propertyId = safe_int(($_GET['property_id'] ?? $_POST['property_id'] ?? 0), 0);
if ($propertyId <= 0) send_error('property_id is required');

// Authorization rules:
// - Renters must see only active properties
// - Owners/admin may see their own properties (including pending/rejected/rented)
$user = app_get_current_user();
$canSeeAllOwner = false;
if ($user && in_array($user['role'], ['owner', 'admin'], true)) {
  $canSeeAllOwner = true;
}

// Track views only for unique renter views (owners/admins don't count)
if ($user && $user['role'] === 'renter') {
  // Check if this renter has already viewed this property
  $checkView = $pdo->prepare('SELECT id FROM property_views WHERE property_id=:pid AND user_id=:uid LIMIT 1');
  $checkView->execute([':pid' => $propertyId, ':uid' => (int)$user['id']]);
  
  if (!$checkView->fetch()) {
    // First time this renter views this property - record the view and increment count
    $recordView = $pdo->prepare('INSERT INTO property_views (property_id, user_id) VALUES (:pid, :uid)');
    $recordView->execute([':pid' => $propertyId, ':uid' => (int)$user['id']]);
    
    // Increment property view count for active properties
    $incr = $pdo->prepare('UPDATE properties SET views_count = views_count + 1, updated_at=NOW() WHERE id=:id AND status=\'active\'');
    $incr->execute([':id' => $propertyId]);
  }
}

// Load property with different access rules for owners/admins vs renters
if ($user && $canSeeAllOwner) {
  // Owners/admins can see their own properties regardless of status
  $stmt = $pdo->prepare(
    "SELECT p.*, u.full_name AS owner_full_name
     FROM properties p
     JOIN users u ON u.id = p.owner_id
     WHERE p.id = :id AND (p.status='active' OR p.owner_id = :uid)"
  );
  $stmt->execute([':id' => $propertyId, ':uid' => (int)$user['id']]);
  $property = $stmt->fetch();
} else {
  // Renters can only see active properties
  $stmt = $pdo->prepare(
    "SELECT p.*, u.full_name AS owner_full_name
     FROM properties p
     JOIN users u ON u.id = p.owner_id
     WHERE p.id=:id AND p.status='active'"
  );
  $stmt->execute([':id' => $propertyId]);
  $property = $stmt->fetch();
}

if (!$property) send_error('Property not found', 404);

// Fetch property photos in order
$photosStmt = $pdo->prepare(
  'SELECT id, image_path, sort_order FROM property_photos WHERE property_id=:pid ORDER BY sort_order ASC, id ASC'
);
$photosStmt->execute([':pid' => $propertyId]);
$photos = $photosStmt->fetchAll();

// Check if property is in user's favorites
$isFavorite = false;
if ($user) {
  $favStmt = $pdo->prepare('SELECT 1 FROM favorites WHERE user_id=:uid AND property_id=:pid LIMIT 1');
  $favStmt->execute([':uid' => (int)$user['id'], ':pid' => $propertyId]);
  $isFavorite = (bool)$favStmt->fetch();
}

// Return complete property details with photos and favorite status
json_response([
  'ok' => true,
  'property' => [
    'id' => (int)$property['id'],
    'city' => (string)$property['city'],
    'subcity' => (string)$property['subcity'],
    'real_address' => (string)$property['real_address'],
    'property_type' => (string)$property['property_type'],
    'short_description' => $property['short_description'] !== null ? (string)$property['short_description'] : '',
    'description' => $property['description'] !== null ? (string)$property['description'] : '',
    'rules' => $property['rules'] !== null ? (string)$property['rules'] : '',
    'price' => (float)$property['price'],
    'status' => (string)$property['status'],
    'views_count' => (int)$property['views_count'],
    'created_at' => (string)$property['created_at'],
    'owner' => [
      'id' => (int)$property['owner_id'],
      'full_name' => (string)$property['owner_full_name'],
    ],
  ],
  'photos' => $photos,
  'is_favorite' => $isFavorite,
]);

