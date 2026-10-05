<?php
/**
 * Subscription Expiry Cron Job
 * Run every hour to expire subscriptions and update user premium status
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = db();

echo "Starting subscription expiry check...\n";

try {
    // Expire subscriptions that have ended
    $expired = $db->execute(
        "UPDATE subscriptions 
         SET status = 'EXPIRED' 
         WHERE status = 'ACTIVE' 
         AND end_date <= NOW()"
    );
    
    echo "Expired {$expired} subscriptions\n";
    
    // Update user premium status
    $db->execute(
        "UPDATE users u 
         SET is_premium = IF(
             EXISTS(
                 SELECT 1 FROM subscriptions s 
                 WHERE s.user_id = u.id 
                 AND s.status = 'ACTIVE' 
                 AND s.end_date > NOW()
             ), 
             1, 
             0
         )"
    );
    
    echo "Updated user premium status\n";
    
    echo "Subscription expiry check completed successfully\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
