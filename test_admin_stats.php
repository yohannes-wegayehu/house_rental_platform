<?php
require_once 'backend/config.php';

try {
    $pdo = db();
    
    echo "=== Admin Statistics Test ===\n\n";
    
    // Test individual queries
    $totalUsers = $pdo->query('SELECT COUNT(*) as count FROM users')->fetch()['count'];
    echo "Total Users: $totalUsers\n";
    
    $totalProperties = $pdo->query('SELECT COUNT(*) as count FROM properties')->fetch()['count'];
    echo "Total Properties: $totalProperties\n";
    
    $activeProperties = $pdo->query('SELECT COUNT(*) as count FROM properties WHERE status="active"')->fetch()['count'];
    echo "Active Properties: $activeProperties\n";
    
    $pendingProperties = $pdo->query('SELECT COUNT(*) as count FROM properties WHERE status="pending"')->fetch()['count'];
    echo "Pending Properties: $pendingProperties\n";
    
    $totalRevenue = $pdo->query('SELECT SUM(amount) as total FROM payments WHERE status="success"')->fetch()['total'] ?? 0;
    echo "Total Revenue: $totalRevenue ETB\n";
    
    $totalViews = $pdo->query('SELECT SUM(views_count) as total FROM properties')->fetch()['total'] ?? 0;
    echo "Total Views: $totalViews\n";
    
    echo "\n=== Recent Properties Sample ===\n";
    $recentProps = $pdo->query('SELECT id, city, subcity, status, views_count FROM properties ORDER BY created_at DESC LIMIT 5')->fetchAll();
    foreach ($recentProps as $prop) {
        echo "ID: {$prop['id']}, City: {$prop['city']}, Status: {$prop['status']}, Views: {$prop['views_count']}\n";
    }
    
    echo "\n=== Payments Sample ===\n";
    $payments = $pdo->query('SELECT id, amount, status, created_at FROM payments ORDER BY created_at DESC LIMIT 5')->fetchAll();
    foreach ($payments as $payment) {
        echo "ID: {$payment['id']}, Amount: {$payment['amount']}, Status: {$payment['status']}, Date: {$payment['created_at']}\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
