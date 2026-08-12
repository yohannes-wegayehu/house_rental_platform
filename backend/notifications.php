<?php
// Enable strict type checking for better code quality
declare(strict_types=1);

// Include database configuration and helper functions
require_once __DIR__ . '/config.php';

// Initialize database connection and get action parameter
$pdo = db();
$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');

// Main router for notifications system actions
switch ($action) {
  case 'list':
    // List user notifications with pagination and filtering
    require_method('GET');
    $user = require_auth(['renter', 'owner', 'admin']);
    $limit = safe_int($_GET['limit'] ?? 20, 20);
    $offset = safe_int($_GET['offset'] ?? 0, 0);
    $unReadOnly = (int)($_GET['unread_only'] ?? 0);
    
    // Build base query to get user notifications
    $sql = "
      SELECT 
        id, type, title, message, related_id, related_type, is_read, created_at
      FROM notifications 
      WHERE user_id = :uid
    ";
    $params = [':uid' => (int)$user['id']];

    // Filter to show only unread notifications if requested
    if ($unReadOnly) {
        $sql .= " AND is_read = 0";
    }
    
    // Add pagination and ordering
    $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    // Execute query with bound parameters
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $notifications = $stmt->fetchAll();
    
    // Get unread count for response
    $countStmt = $pdo->prepare('SELECT COUNT(*) as count FROM notifications WHERE user_id = :uid AND is_read = 0');
    $countStmt->execute([':uid' => (int)$user['id']]);
    $unreadCount = (int)$countStmt->fetch()['count'];
    
    // Return paginated notifications with unread count
    json_response(['ok' => true, 'items' => $notifications, 'unread_count' => $unreadCount]);
    break;

  case 'mark_read':
    // Mark notifications as read (single or by type)
    require_method('POST');
    $user = require_auth(['renter', 'owner', 'admin']);
    $input = get_input();
    
    // Extract and validate input parameters
    $notificationId = safe_int($input['notification_id'] ?? 0, 0);
    $markAll = (int)($input['mark_all'] ?? 0);
    $type = (string)($input['type'] ?? '');
    
    if ($type !== '') {
        // Validate notification type
        $validTypes = ['new_property', 'new_message', 'property_approved', 'property_rejected', 'payment_received', 'new_complaint', 'complaint_update'];
        if (!in_array($type, $validTypes, true)) send_error('Invalid notification type');
        
        // Mark all notifications of a specific type as read
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND type = :type AND is_read = 0');
        $stmt->execute([':uid' => (int)$user['id'], ':type' => $type]);
        $affected = $stmt->rowCount();
        json_response(['ok' => true, 'marked_count' => $affected]);
    } elseif ($markAll) {
        // Mark all notifications as read for user
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0');
        $stmt->execute([':uid' => (int)$user['id']]);
        $affected = $stmt->rowCount();
        json_response(['ok' => true, 'marked_count' => $affected]);
    } elseif ($notificationId > 0) {
        // Mark single notification as read
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $notificationId, ':uid' => (int)$user['id']]);
        json_response(['ok' => true, 'marked_count' => $stmt->rowCount()]);
    } else {
        send_error('notification_id, mark_all, or type is required');
    }
    break;

  case 'delete':
    // Delete a notification for authenticated user
    require_method('POST');
    $user = require_auth(['renter', 'owner', 'admin']);
    $input = get_input();
    
    // Extract and validate notification ID
    $notificationId = safe_int($input['notification_id'] ?? 0, 0);
    if ($notificationId <= 0) send_error('notification_id is required');
    
    // Delete notification record for user
    $stmt = $pdo->prepare('DELETE FROM notifications WHERE id = :id AND user_id = :uid');
    $stmt->execute([':id' => $notificationId, ':uid' => (int)$user['id']]);
    json_response(['ok' => true, 'deleted_count' => $stmt->rowCount()]);
    break;

  case 'create':
    // Create a new notification (admin/owner only)
    require_method('POST');
    $user = require_auth(['owner', 'admin']); // Only owners and admins can create notifications
    $input = get_input();
    
    // Extract and validate input parameters
    $userId = safe_int($input['user_id'] ?? 0, 0);
    $type = (string)($input['type'] ?? '');
    $title = trim((string)($input['title'] ?? ''));
    $message = trim((string)($input['message'] ?? ''));
    $relatedId = $input['related_id'] ?? null;
    $relatedId = $relatedId !== null && $relatedId !== '' ? safe_int($relatedId, 0) : null;
    $relatedType = (string)($input['related_type'] ?? '');
    
    // Validate required fields
    if ($userId <= 0) send_error('user_id is required');
    if ($type === '') send_error('type is required');
    if ($title === '') send_error('title is required');
    if ($message === '') send_error('message is required');
    
    // Validate notification type
    $validTypes = ['new_property', 'new_message', 'property_approved', 'property_rejected', 'payment_received', 'new_complaint', 'complaint_update'];
    if (!in_array($type, $validTypes, true)) send_error('Invalid notification type');
    
    // Validate related entity type
    $validRelatedTypes = ['property', 'message', 'payment', 'complaint'];
    if ($relatedType !== '' && !in_array($relatedType, $validRelatedTypes, true)) send_error('Invalid related_type');
    
    // Insert notification record
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, type, title, message, related_id, related_type)
         VALUES (:uid, :type, :title, :message, :rid, :rtype)'
    );
    $stmt->execute([
        ':uid' => $userId,
        ':type' => $type,
        ':title' => $title,
        ':message' => $message,
        ':rid' => $relatedId,
        ':rtype' => $relatedType ?: null
    ]);
    
    // Return newly created notification ID
    json_response(['ok' => true, 'notification_id' => (int)$pdo->lastInsertId()]);
    break;

  case 'preferences':
    // Get user notification preferences
    require_method('GET');
    $user = require_auth(['renter', 'owner', 'admin']);
    
    // Fetch user's notification preferences
    $stmt = $pdo->prepare('SELECT * FROM notification_preferences WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => (int)$user['id']]);
    $preferences = $stmt->fetch();
    
    // Create default preferences if none exist
    if (!$preferences) {
        $stmt = $pdo->prepare(
            'INSERT INTO notification_preferences (user_id) VALUES (:uid)'
        );
        $stmt->execute([':uid' => (int)$user['id']]);
        
        // Fetch the newly created preferences
        $stmt = $pdo->prepare('SELECT * FROM notification_preferences WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => (int)$user['id']]);
        $preferences = $stmt->fetch();
    }
    
    // Return user preferences
    json_response(['ok' => true, 'preferences' => $preferences]);
    break;

  case 'update_preferences':
    // Update user notification preferences
    require_method('POST');
    $user = require_auth(['renter', 'owner', 'admin']);
    $input = get_input();
    
    // Extract notification preference settings
    $emailNotifications = (int)($input['email_notifications'] ?? 1);
    $newPropertyAlerts = (int)($input['new_property_alerts'] ?? 1);
    $messageNotifications = (int)($input['message_notifications'] ?? 1);
    $propertyUpdates = (int)($input['property_updates'] ?? 1);
    $paymentNotifications = (int)($input['payment_notifications'] ?? 1);
    
    // Update user's notification preferences
    $stmt = $pdo->prepare(
        'UPDATE notification_preferences 
         SET email_notifications = :email, new_property_alerts = :new_prop, 
             message_notifications = :msg, property_updates = :prop_update, 
             payment_notifications = :payment, updated_at = NOW()
         WHERE user_id = :uid'
    );
    $stmt->execute([
        ':email' => $emailNotifications,
        ':new_prop' => $newPropertyAlerts,
        ':msg' => $messageNotifications,
        ':prop_update' => $propertyUpdates,
        ':payment' => $paymentNotifications,
        ':uid' => (int)$user['id']
    ]);
    
    // Return success response
    json_response(['ok' => true]);
    break;

  default:
    // Handle unknown notification actions
    send_error('Unknown action', 400);
}
