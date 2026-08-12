<?php
// Enable strict type checking for better code quality
declare(strict_types=1);

// Include database configuration and helper functions
require_once __DIR__ . '/config.php';

// Initialize database connection
$pdo = db();
// Determine the action to perform (GET/POST parameter)
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

// Set default action to pending_listings if none specified
if ($action === '') $action = 'pending_listings';

// Ensure system_settings table exists for storing admin configuration
function ensure_system_settings_table(PDO $pdo): void {
  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS system_settings (
      id INT AUTO_INCREMENT PRIMARY KEY,
      setting_key VARCHAR(100) NOT NULL UNIQUE,
      setting_value TEXT NULL,
      updated_by INT NULL,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB"
  );
}

// Retrieve a system setting value by key with default fallback
function get_system_setting(PDO $pdo, string $key, string $default = ''): string {
  $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :k LIMIT 1');
  $stmt->execute([':k' => $key]);
  $row = $stmt->fetch();
  if (!$row) return $default;
  return (string)($row['setting_value'] ?? $default);
}

// Update or insert a system setting with audit trail
function set_system_setting(PDO $pdo, string $key, string $value, int $updatedBy): void {
  $stmt = $pdo->prepare(
    "INSERT INTO system_settings (setting_key, setting_value, updated_by)
     VALUES (:k, :v, :u)
     ON DUPLICATE KEY UPDATE
       setting_value = VALUES(setting_value),
       updated_by = VALUES(updated_by),
       updated_at = NOW()"
  );
  $stmt->execute([
    ':k' => $key,
    ':v' => $value,
    ':u' => $updatedBy,
  ]);
}

// Main router for admin panel actions
switch ($action) {
  case 'pending_listings':
    // Get pending property listings for admin approval
    require_method('GET');
    require_auth(['admin']);
    $stmt = $pdo->prepare(
      "SELECT
         p.id,
         p.owner_id,
         p.city,
         p.subcity,
         p.real_address,
         p.property_type,
         p.price,
         p.created_at,
         u.full_name AS owner_full_name,
         (
           SELECT pp.image_path FROM property_photos pp
           WHERE pp.property_id=p.id
           ORDER BY pp.sort_order ASC, pp.id ASC
           LIMIT 1
         ) AS cover_photo
       FROM properties p
       JOIN users u ON u.id=p.owner_id
       WHERE p.status='pending' AND EXISTS (
         SELECT 1 FROM payments pay
         WHERE pay.property_id = p.id AND pay.status='success'
       )
       ORDER BY p.created_at DESC
       LIMIT 100"
    );
    $stmt->execute();
    $items = $stmt->fetchAll();
    foreach ($items as &$row) {
      if (!empty($row['cover_photo'])) {
        $row['cover_photo'] = public_media_url((string)$row['cover_photo']);
      }
    }
    unset($row);
    json_response(['ok' => true, 'items' => $items]);
    break;

  case 'system_settings':
    // Retrieve admin system settings configuration
    require_method('GET');
    require_auth(['admin']);
    ensure_system_settings_table($pdo);

    $autoApprove = get_system_setting($pdo, 'auto_approve_pending_properties', '0');
    $autoRefreshSeconds = get_system_setting($pdo, 'admin_auto_refresh_seconds', '15');

    json_response([
      'ok' => true,
      'settings' => [
        'auto_approve_pending_properties' => $autoApprove === '1',
        'admin_auto_refresh_seconds' => max(5, (int)$autoRefreshSeconds),
      ],
    ]);
    break;

  case 'update_system_settings':
    // Update admin system settings with validation
    require_method('POST');
    $user = require_auth(['admin']);
    ensure_system_settings_table($pdo);
    $input = get_input();

    // Validate and sanitize input values
    $autoApprove = !empty($input['auto_approve_pending_properties']) ? '1' : '0';
    $autoRefreshSeconds = safe_int($input['admin_auto_refresh_seconds'] ?? 15, 15);
    if ($autoRefreshSeconds < 5) $autoRefreshSeconds = 5;
    if ($autoRefreshSeconds > 300) $autoRefreshSeconds = 300;

    // Update settings in database with audit trail
    $adminId = (int)$user['id'];
    set_system_setting($pdo, 'auto_approve_pending_properties', $autoApprove, $adminId);
    set_system_setting($pdo, 'admin_auto_refresh_seconds', (string)$autoRefreshSeconds, $adminId);

    json_response(['ok' => true, 'message' => 'System settings updated successfully']);
    break;

  case 'approve':
    // Approve a pending property listing and send notifications
    require_method('POST');
    require_auth(['admin']);
    $input = get_input();
    $propertyId = safe_int($input['property_id'] ?? 0, 0);
    if ($propertyId <= 0) send_error('property_id is required');
    
    // Get property details before updating for notifications
    $propStmt = $pdo->prepare('SELECT p.*, u.full_name as owner_name FROM properties p JOIN users u ON u.id = p.owner_id WHERE p.id = :id LIMIT 1');
    $propStmt->execute([':id' => $propertyId]);
    $property = $propStmt->fetch();
    if (!$property) send_error('Property not found', 404);
    
    // Update property status to active
    $stmt = $pdo->prepare('UPDATE properties SET status=\'active\', updated_at=NOW() WHERE id=:id AND status=\'pending\'');
    $stmt->execute([':id' => $propertyId]);
    
    // Create notifications for all renters about new property
    if ($stmt->rowCount() > 0) {
        // Get all renters who have opted in for new property alerts
        $renterStmt = $pdo->prepare(
            'SELECT u.id FROM users u 
             LEFT JOIN notification_preferences np ON u.id = np.user_id 
             WHERE u.role = \'renter\' AND u.is_banned = 0 
             AND (np.new_property_alerts = 1 OR np.new_property_alerts IS NULL)'
        );
        $renterStmt->execute();
        $renters = $renterStmt->fetchAll();
        
        // Create notification for each renter about new property
        $notifStmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, message, related_id, related_type)
             VALUES (:uid, \'new_property\', :title, :message, :rid, \'property\')'
        );
        
        foreach ($renters as $renter) {
            $notifStmt->execute([
                ':uid' => (int)$renter['id'],
                ':title' => 'New Property Available: ' . $property['city'] . ' - ' . $property['subcity'],
                ':message' => sprintf(
                    'A new %s property has been posted by %s in %s - %s. Price: %s ETB. Check it out now!',
                    $property['property_type'],
                    $property['owner_name'],
                    $property['city'],
                    $property['subcity'],
                    number_format($property['price'], 2)
                ),
                ':rid' => $propertyId
            ]);
        }
        
        // Also notify the property owner that their property was approved
        $ownerNotifStmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, message, related_id, related_type)
             VALUES (:uid, \'property_approved\', :title, :message, :rid, \'property\')'
        );
        $ownerNotifStmt->execute([
            ':uid' => (int)$property['owner_id'],
            ':title' => 'Property Approved',
            ':message' => sprintf(
                'Your property listing in %s - %s has been approved and is now live! Renters can now see and contact you about this property.',
                $property['city'],
                $property['subcity']
            ),
            ':rid' => $propertyId
        ]);
    }
    
    json_response(['ok' => true]);
    break;

  case 'reject':
    // Reject a pending property listing
    require_method('POST');
    require_auth(['admin']);
    $input = get_input();
    $propertyId = safe_int($input['property_id'] ?? 0, 0);
    if ($propertyId <= 0) send_error('property_id is required');
    $stmt = $pdo->prepare('UPDATE properties SET status=\'rejected\', updated_at=NOW() WHERE id=:id AND status=\'pending\'');
    $stmt->execute([':id' => $propertyId]);
    json_response(['ok' => true]);
    break;

  case 'transactions':
    // Get payment transactions for admin review
    require_method('GET');
    require_auth(['admin']);
    $stmt = $pdo->prepare(
      "SELECT
        pay.id,
        pay.tx_ref,
        pay.amount,
        pay.currency,
        pay.status,
        pay.created_at,
        u.full_name,
        pay.property_id
      FROM payments pay
      JOIN users u ON u.id=pay.user_id
      ORDER BY pay.created_at DESC
      LIMIT 200"
    );
    $stmt->execute();
    json_response(['ok' => true, 'items' => $stmt->fetchAll()]);
    break;

  case 'users':
    // Get all users for admin management
    require_method('GET');
    require_auth(['admin']);
    $stmt = $pdo->prepare('SELECT id, full_name, phone, role, is_banned, created_at FROM users ORDER BY id DESC LIMIT 200');
    $stmt->execute();
    json_response(['ok' => true, 'items' => $stmt->fetchAll()]);
    break;

  case 'ban_user':
    // Ban or unban a user account
    require_method('POST');
    require_auth(['admin']);
    $input = get_input();
    $userId = safe_int($input['user_id'] ?? 0, 0);
    $ban = (int)($input['ban'] ?? 1);
    if ($userId <= 0) send_error('user_id is required');
    $stmt = $pdo->prepare('UPDATE users SET is_banned=:b WHERE id=:id');
    $stmt->execute([':b' => $ban, ':id' => $userId]);
    json_response(['ok' => true]);
    break;

  case 'analytics':
    // Get comprehensive analytics dashboard data
    require_method('GET');
    require_auth(['admin']);
    
    // System overview stats calculation
    $totalUsers = $pdo->query('SELECT COUNT(*) as count FROM users')->fetch()['count'];
    $totalProperties = $pdo->query('SELECT COUNT(*) as count FROM properties')->fetch()['count'];
    $activeProperties = $pdo->query('SELECT COUNT(*) as count FROM properties WHERE status="active"')->fetch()['count'];
    $pendingProperties = $pdo->query('SELECT COUNT(*) as count FROM properties WHERE status="pending"')->fetch()['count'];
    $totalRevenue = $pdo->query('SELECT SUM(amount) as total FROM payments WHERE status="success"')->fetch()['total'] ?? 0;
    $totalViews = $pdo->query('SELECT SUM(views_count) as total FROM properties')->fetch()['total'] ?? 0;
    
    // Popular locations analysis
    $popularCities = $pdo->query(
      'SELECT city, COUNT(*) as property_count FROM properties 
       WHERE status="active" GROUP BY city ORDER BY property_count DESC LIMIT 10'
    )->fetchAll();
    
    // Recent user activity tracking
    $recentSignups = $pdo->query(
      'SELECT full_name, role, created_at FROM users 
       ORDER BY created_at DESC LIMIT 5'
    )->fetchAll();
    
    // Recent property submissions
    $recentProperties = $pdo->query(
      'SELECT p.city, p.subcity, u.full_name as owner, p.created_at 
       FROM properties p JOIN users u ON u.id = p.owner_id 
       ORDER BY p.created_at DESC LIMIT 5'
    )->fetchAll();
    
    // Return comprehensive analytics data
    json_response([
      'ok' => true,
      'overview' => [
        'total_users' => (int)$totalUsers,
        'total_properties' => (int)$totalProperties,
        'active_properties' => (int)$activeProperties,
        'pending_properties' => (int)$pendingProperties,
        'total_revenue' => (float)$totalRevenue,
        'total_views' => (int)$totalViews,
      ],
      'popular_cities' => $popularCities,
      'recent_signups' => $recentSignups,
      'recent_properties' => $recentProperties,
    ]);
    break;

  case 'revenue_analytics':
    // Get revenue analytics with customizable time periods
    require_method('GET');
    require_auth(['admin']);
    $period = (string)($_GET['period'] ?? 'daily'); // daily, weekly, monthly
    
    // Set date format based on selected period
    $dateFormat = match($period) {
      'daily' => '%Y-%m-%d',
      'weekly' => '%Y-%u',
      'monthly' => '%Y-%m',
      default => '%Y-%m-%d'
    };
    
    // Query revenue data grouped by time period
    $stmt = $pdo->prepare(
      "SELECT DATE_FORMAT(created_at, :fmt1) as period, 
              COUNT(*) as transaction_count, 
              SUM(amount) as revenue
       FROM payments 
       WHERE status='success' 
       GROUP BY DATE_FORMAT(created_at, :fmt2)
       ORDER BY period DESC
       LIMIT 30"
    );
    $stmt->execute([':fmt1' => $dateFormat, ':fmt2' => $dateFormat]);
    $revenueData = $stmt->fetchAll();
    
    json_response(['ok' => true, 'data' => $revenueData]);
    break;

  case 'traffic_analytics':
    // Get traffic and property distribution analytics
    require_method('GET');
    require_auth(['admin']);
    
    // Daily traffic analysis (views over last 30 days)
    $dailyViews = $pdo->query(
      'SELECT DATE(created_at) as date, SUM(views_count) as daily_views
       FROM properties 
       WHERE status="active" AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
       GROUP BY DATE(created_at)
       ORDER BY date DESC'
    )->fetchAll();
    
    // Property type distribution analysis
    $typeDistribution = $pdo->query(
      'SELECT property_type, COUNT(*) as count
       FROM properties 
       WHERE status="active"
       GROUP BY property_type'
    )->fetchAll();
    
    json_response([
      'ok' => true,
      'daily_views' => $dailyViews,
      'type_distribution' => $typeDistribution,
    ]);
    break;

  case 'price_settings':
    // Get admin price settings for different property types
    require_method('GET');
    require_auth(['admin']);
    $stmt = $pdo->prepare('SELECT property_type, listing_fee, currency FROM admin_price_settings ORDER BY property_type');
    $stmt->execute();
    json_response(['ok' => true, 'items' => $stmt->fetchAll()]);
    break;

  case 'property_details':
    // Get detailed property information for admin review
    require_method('GET');
    require_auth(['admin']);
    $propertyId = safe_int($_GET['property_id'] ?? 0, 0);
    if ($propertyId <= 0) send_error('property_id is required');
    
    // Get property details with owner information
    $stmt = $pdo->prepare(
      "SELECT p.*, u.full_name AS owner_full_name, u.phone AS owner_phone
       FROM properties p
       JOIN users u ON u.id = p.owner_id
       WHERE p.id = :id LIMIT 1"
    );
    $stmt->execute([':id' => $propertyId]);
    $property = $stmt->fetch();
    
    if (!$property) send_error('Property not found', 404);
    
    // Get property photos with sorting
    $photoStmt = $pdo->prepare(
      "SELECT id, image_path, sort_order 
       FROM property_photos 
       WHERE property_id = :id 
       ORDER BY sort_order ASC, id ASC"
    );
    $photoStmt->execute([':id' => $propertyId]);
    $photos = $photoStmt->fetchAll();
    foreach ($photos as &$photo) {
      if (!empty($photo['image_path'])) {
        $photo['image_path'] = public_media_url((string)$photo['image_path']);
      }
    }
    unset($photo);
    
    // Get payment transaction information
    $paymentStmt = $pdo->prepare(
      "SELECT tx_ref, amount, currency, status, created_at 
       FROM payments 
       WHERE property_id = :id 
       ORDER BY created_at DESC 
       LIMIT 1"
    );
    $paymentStmt->execute([':id' => $propertyId]);
    $payment = $paymentStmt->fetch();
    
    // Return comprehensive property details
    json_response([
      'ok' => true,
      'property' => $property,
      'photos' => $photos,
      'payment' => $payment
    ]);
    break;

  case 'update_price_settings':
    // Update admin price settings for property types
    require_method('POST');
    $adminUser = require_auth(['admin']);
    $input = get_input();
    
    // Extract and validate input parameters
    $propertyType = trim((string)($input['property_type'] ?? ''));
    $listingFee = safe_float($input['listing_fee'] ?? 0.0, 0.0);
    $currency = trim((string)($input['currency'] ?? 'ETB'));
    
    // Validate input parameters
    if ($propertyType === '') send_error('Property type is required');
    if ($listingFee <= 0) send_error('Fee percentage must be greater than 0');
    if ($listingFee > 100) send_error('Fee percentage must not exceed 100');
    
    // Validate property type against allowed values
    $validTypes = ['Residential', 'Shop for Rent', 'Event Hall'];
    if (!in_array($propertyType, $validTypes, true)) send_error('Invalid property type');
    
    // Update price settings in database with audit trail
    $stmt = $pdo->prepare('UPDATE admin_price_settings SET listing_fee = :fee, currency = :cur, updated_by = :uid, updated_at = NOW() WHERE property_type = :pt');
    $stmt->execute([
      ':fee' => $listingFee,
      ':cur' => $currency,
      ':uid' => (int)$adminUser['id'],
      ':pt' => $propertyType
    ]);
    
    json_response(['ok' => true, 'message' => 'Fee percentage settings updated successfully']);
    break;

  default:
    // Handle unknown admin actions
    send_error('Unknown action', 400);
}

