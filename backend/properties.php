<?php
// Enable strict type checking for better code quality
declare(strict_types=1);

// Include database configuration and helper functions
require_once __DIR__ . '/config.php';

// Initialize database connection and get action parameter
$pdo = db();
$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');

// Check if auto-approval is enabled for pending properties
function should_auto_approve_pending_properties(PDO $pdo): bool {
  $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'auto_approve_pending_properties' LIMIT 1");
  $stmt->execute();
  $row = $stmt->fetch();
  return (string)($row['setting_value'] ?? '0') === '1';
}

// Main router for properties system actions
switch ($action) {
  case 'city_autocomplete':
    // Provide city autocomplete suggestions
    require_method('GET');
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') json_response(['ok' => true, 'items' => []]);
    // Get distinct cities matching query
    $stmt = $pdo->prepare(
      "SELECT DISTINCT city FROM properties
       WHERE status='active' AND city LIKE :q
       ORDER BY city ASC LIMIT 10"
    );
    $stmt->execute([':q' => $q . '%']);
    $items = array_map(fn($r) => $r['city'], $stmt->fetchAll());
    json_response(['ok' => true, 'items' => $items]);
    break;

  case 'subcity_autocomplete':
    // Provide subcity autocomplete for selected city
    require_method('GET');
    $city = trim((string)($_GET['city'] ?? ''));
    if ($city === '') json_response(['ok' => true, 'items' => []]);
    // Get distinct subcities for the selected city
    $stmt = $pdo->prepare(
      "SELECT DISTINCT subcity FROM properties
       WHERE status='active' AND city = :city
       ORDER BY subcity ASC LIMIT 10"
    );
    $stmt->execute([':city' => $city]);
    $items = array_map(fn($r) => $r['subcity'], $stmt->fetchAll());
    json_response(['ok' => true, 'items' => $items]);
    break;

  case 'search':
    // Search properties with multiple filters
    require_method('GET');
    $city = trim((string)($_GET['city'] ?? ''));
    $subcity = trim((string)($_GET['subcity'] ?? ''));
    $propertyType = trim((string)($_GET['type'] ?? ''));
    $q = trim((string)($_GET['q'] ?? ''));

    // Build base search query with cover photo subquery
    $sql = "
      SELECT
        p.id,
        p.city,
        p.subcity,
        p.real_address,
        p.property_type,
        p.short_description,
        p.price,
        p.views_count,
        (
          SELECT pp.image_path FROM property_photos pp
          WHERE pp.property_id = p.id
          ORDER BY pp.sort_order ASC, pp.id ASC
          LIMIT 1
        ) AS cover_photo
      FROM properties p
      WHERE p.status='active'
    ";
    $params = [];
    // Add city filter if provided
    if ($city !== '') {
      $sql .= " AND p.city = :city ";
      $params[':city'] = $city;
    }
    // Add subcity filter if provided
    if ($subcity !== '') {
      $sql .= " AND p.subcity = :subcity ";
      $params[':subcity'] = $subcity;
    }
    // Add property type filter if provided
    if ($propertyType !== '') {
      $sql .= " AND p.property_type = :ptype ";
      $params[':ptype'] = $propertyType;
    }
    // Add text search filter if provided
    if ($q !== '') {
      $sql .= " AND (p.real_address LIKE :q OR p.short_description LIKE :q) ";
      $params[':q'] = '%' . $q . '%';
    }

    // Add ordering and limit
    $sql .= " ORDER BY p.created_at DESC LIMIT 50";

    // Execute search query
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response(['ok' => true, 'items' => $stmt->fetchAll()]);
    break;

  case 'my_properties':
    require_method('GET');
    $user = require_auth(['owner', 'admin']);
    $stmt = $pdo->prepare(
      "SELECT
         p.id,
         p.city,
         p.subcity,
         p.real_address,
         p.property_type,
         p.short_description,
         p.description,
         p.rules,
         p.price,
         p.views_count,
         p.status,
         p.created_at,
         (
           SELECT pp.image_path FROM property_photos pp
           WHERE pp.property_id = p.id
           ORDER BY pp.sort_order ASC, pp.id ASC
           LIMIT 1
         ) AS cover_photo
       FROM properties p
       WHERE p.owner_id = :uid 
       AND EXISTS (
         SELECT 1 FROM payments pay 
         WHERE pay.property_id = p.id AND pay.status = 'success'
       )
       ORDER BY p.created_at DESC
       LIMIT 50"
    );
    $stmt->execute([':uid' => (int)$user['id']]);
    json_response(['ok' => true, 'items' => $stmt->fetchAll()]);
    break;

  case 'owner_property_detail':
    // Get detailed property information for owner/admin
    require_method('GET');
    $user = require_auth(['owner', 'admin']);
    $propertyId = safe_int($_GET['property_id'] ?? 0, 0);
    if ($propertyId <= 0) send_error('property_id is required');

    // Fetch property details
    $pStmt = $pdo->prepare(
      "SELECT
        p.id,
        p.owner_id,
        p.city,
        p.subcity,
        p.real_address,
        p.property_type,
        p.short_description,
        p.description,
        p.rules,
        p.price,
        p.status
       FROM properties p
       WHERE p.id = :id
       LIMIT 1"
    );
    $pStmt->execute([':id' => $propertyId]);
    $property = $pStmt->fetch();
    if (!$property) send_error('Property not found', 404);
    // Verify ownership (owners can only access their own properties)
    if ($user['role'] === 'owner' && (int)$property['owner_id'] !== (int)$user['id']) send_error('Forbidden', 403);

    // Check if property has been paid for
    $hasPaidStmt = $pdo->prepare(
      "SELECT 1 FROM payments WHERE property_id = :pid AND status = 'success' LIMIT 1"
    );
    $hasPaidStmt->execute([':pid' => $propertyId]);
    if (!$hasPaidStmt->fetch()) send_error('Only paid listings can be managed from this dashboard', 403);

    // Fetch property photos
    $photosStmt = $pdo->prepare(
      "SELECT image_path FROM property_photos WHERE property_id = :pid ORDER BY sort_order ASC, id ASC"
    );
    $photosStmt->execute([':pid' => $propertyId]);
    $photoPaths = array_map(static fn($r) => (string)$r['image_path'], $photosStmt->fetchAll());

    // Remove owner_id from response and add photo paths
    unset($property['owner_id']);
    $property['photo_paths'] = $photoPaths;
    json_response(['ok' => true, 'item' => $property]);
    break;

  case 'set_rented':
    // Mark property as rented
    require_method('POST');
    $user = require_auth(['owner', 'admin']);
    $input = get_input();
    $propertyId = safe_int($input['property_id'] ?? 0, 0);
    if ($propertyId <= 0) send_error('property_id is required');
    
    // Verify property ownership
    $pStmt = $pdo->prepare('SELECT owner_id FROM properties WHERE id=:id LIMIT 1');
    $pStmt->execute([':id' => $propertyId]);
    $property = $pStmt->fetch();
    if (!$property) send_error('Property not found', 404);
    if ($user['role'] === 'owner' && (int)$property['owner_id'] !== (int)$user['id']) send_error('Forbidden', 403);
    
    // Update property status to rented
    $stmt = $pdo->prepare('UPDATE properties SET status=\'rented\', updated_at=NOW() WHERE id=:id');
    $stmt->execute([':id' => $propertyId]);
    json_response(['ok' => true]);
    break;

  case 'delete_mine':
    // Delete owner's property (rented/rejected only)
    require_method('POST');
    $user = require_auth(['owner', 'admin']);
    $input = get_input();
    $propertyId = safe_int($input['property_id'] ?? 0, 0);
    if ($propertyId <= 0) send_error('property_id is required');

    // Verify property ownership and get status
    $pStmt = $pdo->prepare('SELECT owner_id, status FROM properties WHERE id=:id LIMIT 1');
    $pStmt->execute([':id' => $propertyId]);
    $property = $pStmt->fetch();
    if (!$property) send_error('Property not found', 404);
    if ($user['role'] === 'owner' && (int)$property['owner_id'] !== (int)$user['id']) send_error('Forbidden', 403);

    // Only allow deletion of rented or rejected properties
    if (!in_array((string)$property['status'], ['rented', 'rejected'], true)) {
      send_error('Only rented or rejected listings can be deleted from this section', 400);
    }

    // Delete property record
    $delStmt = $pdo->prepare('DELETE FROM properties WHERE id = :id');
    $delStmt->execute([':id' => $propertyId]);
    json_response(['ok' => true]);
    break;

  case 'repost_update':
    // Update and repost existing property
    require_method('POST');
    $user = require_auth(['owner', 'admin']);
    $input = get_input();

    // Extract and validate input parameters
    $propertyId = safe_int($input['property_id'] ?? 0, 0);
    if ($propertyId <= 0) send_error('property_id is required');

    $city = trim((string)($input['city'] ?? ''));
    $subcity = trim((string)($input['subcity'] ?? ''));
    $realAddress = trim((string)($input['real_address'] ?? ''));
    $propertyType = trim((string)($input['property_type'] ?? 'Residential'));
    $shortDescription = trim((string)($input['short_description'] ?? ''));
    $description = (string)($input['description'] ?? '');
    $rules = (string)($input['rules'] ?? '');
    $price = safe_float($input['price'] ?? 0.0, 0.0);
    $photoPaths = $input['photo_paths'] ?? [];

    // Validate required fields
    if ($city === '' || $subcity === '' || $realAddress === '') send_error('City, subcity, and real address are required');
    if ($price <= 0) send_error('Price must be greater than 0');

    // Validate property type
    $validTypes = ['Residential', 'Shop for Rent', 'Event Hall'];
    if (!in_array($propertyType, $validTypes, true)) send_error('Invalid property type');
    if (!is_array($photoPaths)) send_error('photo_paths must be an array');
    if (count($photoPaths) === 0) send_error('At least one photo is required');
    if (count($photoPaths) > 3) send_error('You can upload up to 3 photos');

    // Verify property ownership and get current status
    $pStmt = $pdo->prepare('SELECT owner_id, status FROM properties WHERE id=:id LIMIT 1');
    $pStmt->execute([':id' => $propertyId]);
    $property = $pStmt->fetch();
    if (!$property) send_error('Property not found', 404);
    if ($user['role'] === 'owner' && (int)$property['owner_id'] !== (int)$user['id']) send_error('Forbidden', 403);

    // Check if property can be reposted
    $currentStatus = (string)$property['status'];
    if (!in_array($currentStatus, ['rented', 'rejected'], true)) {
      send_error('Only rented or rejected listings can be reposted from this section', 400);
    }

    // Determine next status based on current status
    $nextStatus = $currentStatus === 'rejected'
      ? (should_auto_approve_pending_properties($pdo) ? 'active' : 'pending')
      : 'rented';

    // Update property with new data
    $updStmt = $pdo->prepare(
      "UPDATE properties
       SET city=:c,
           subcity=:sc,
           real_address=:ra,
           property_type=:pt,
           short_description=:sd,
           description=:d,
           rules=:r,
           price=:p,
           status=:st,
           updated_at=NOW()
       WHERE id=:id"
    );
    $updStmt->execute([
      ':c' => $city,
      ':sc' => $subcity,
      ':ra' => $realAddress,
      ':pt' => $propertyType,
      ':sd' => $shortDescription !== '' ? $shortDescription : null,
      ':d' => $description !== '' ? $description : null,
      ':r' => $rules !== '' ? $rules : null,
      ':p' => $price,
      ':st' => $nextStatus,
      ':id' => $propertyId,
    ]);

    // Update property photos
    $pdo->prepare('DELETE FROM property_photos WHERE property_id = :pid')->execute([':pid' => $propertyId]);
    $photoStmt = $pdo->prepare(
      'INSERT INTO property_photos (property_id, image_path, sort_order) VALUES (:pid, :path, :so)'
    );
    $sort = 0;
    foreach ($photoPaths as $path) {
      $path = trim((string)$path);
      if ($path === '') continue;
      $photoStmt->execute([
        ':pid' => $propertyId,
        ':path' => $path,
        ':so' => $sort,
      ]);
      $sort++;
    }

    // Return response with payment requirements
    json_response([
      'ok' => true,
      'property_id' => $propertyId,
      'requires_payment' => $currentStatus === 'rented',
      'submitted_for_approval' => $currentStatus === 'rejected',
    ]);
    break;

  case 'list':
    // Backward-compatible default action
    // Returns an empty list of properties
    require_method('GET');
    json_response(['ok' => true, 'items' => []]);
    break;

  case 'create_pending':
    // Create new property with pending status
    // Allows owners and admins to create new properties
    require_method('POST');
    $user = require_auth(['owner', 'admin']);
    $input = get_input();

    // Extract and validate input parameters
    // Ensures required fields are present and valid
    $city = trim((string)($input['city'] ?? ''));
    $subcity = trim((string)($input['subcity'] ?? ''));
    $realAddress = trim((string)($input['real_address'] ?? ''));
    $propertyType = trim((string)($input['property_type'] ?? 'Residential'));
    $shortDescription = trim((string)($input['short_description'] ?? ''));
    $description = (string)($input['description'] ?? '');
    $rules = (string)($input['rules'] ?? '');
    $price = safe_float($input['price'] ?? 0.0, 0.0);
    $photoPaths = $input['photo_paths'] ?? [];

    // Validate required fields
    // Ensures city, subcity, and real address are provided
    if ($city === '' || $subcity === '' || $realAddress === '') send_error('City, subcity, and real address are required');
    if ($price <= 0) send_error('Price must be greater than 0');

    // Validate property type
    // Ensures property type is one of the allowed values
    $validTypes = ['Residential', 'Shop for Rent', 'Event Hall'];
    if (!in_array($propertyType, $validTypes, true)) send_error('Invalid property type');

    // Validate photo requirements
    // Ensures at least one photo is provided and not more than 3
    if (!is_array($photoPaths)) send_error('photo_paths must be an array');
    if (count($photoPaths) === 0) send_error('At least one photo is required');
    if (count($photoPaths) > 3) send_error('You can upload up to 3 photos');

    // Insert pending property record
    // Creates a new property record with pending status
    $stmt = $pdo->prepare(
      'INSERT INTO properties (owner_id, city, subcity, real_address, property_type, short_description, description, rules, price, status)
       VALUES (:oid, :c, :sc, :ra, :pt, :sd, :d, :r, :p, \'pending\')'
    );
    $stmt->execute([
      ':oid' => (int)$user['id'],
      ':c' => $city,
      ':sc' => $subcity,
      ':ra' => $realAddress,
      ':pt' => $propertyType,
      ':sd' => $shortDescription !== '' ? $shortDescription : null,
      ':d' => $description !== '' ? $description : null,
      ':r' => $rules !== '' ? $rules : null,
      ':p' => $price,
    ]);
    $propertyId = (int)$pdo->lastInsertId();

    // Store property photos with sort order
    // Stores the provided photos with a sort order
    $sort = 0;
    $photoStmt = $pdo->prepare(
      'INSERT INTO property_photos (property_id, image_path, sort_order) VALUES (:pid, :path, :so)'
    );
    foreach ($photoPaths as $path) {
      $path = (string)$path;
      if ($path === '') continue;
      $photoStmt->execute([
        ':pid' => $propertyId,
        ':path' => $path,
        ':so' => $sort,
      ]);
      $sort++;
    }

    // Return success response with new property ID
    // Returns a success response with the newly created property ID
    json_response(['ok' => true, 'property_id' => $propertyId]);
    break;

  default:
    // Handle unknown property actions
    // Returns an error response for unknown actions
    send_error('Unknown action', 400);
}
