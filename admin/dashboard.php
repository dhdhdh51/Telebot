<?php
/**
 * Admin Dashboard
 */

define('ADMIN_PAGE', true);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

Auth::requireAdmin();

$db = db();
$pageTitle = 'Dashboard';

// Get statistics
$stats = [
    'total_users' => $db->fetchOne("SELECT COUNT(*) as count FROM users")['count'],
    'active_users' => $db->fetchOne("SELECT COUNT(DISTINCT user_id) as count FROM video_views WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")['count'],
    'total_videos' => $db->fetchOne("SELECT COUNT(*) as count FROM videos WHERE status = 'PUBLISHED'")['count'],
    'total_views' => $db->fetchOne("SELECT SUM(views) as count FROM videos")['count'] ?? 0,
    'premium_users' => $db->fetchOne("SELECT COUNT(DISTINCT user_id) as count FROM subscriptions WHERE status = 'ACTIVE' AND end_date > NOW()")['count'],
    'today_revenue' => $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'SUCCESS' AND DATE(created_at) = CURDATE()")['total'],
    'today_ad_impressions' => $db->fetchOne("SELECT COUNT(*) as count FROM ad_impressions WHERE DATE(created_at) = CURDATE()")['count'],
    'pending_withdrawals' => $db->fetchOne("SELECT COUNT(*) as count FROM withdrawals WHERE status = 'PENDING'")['count'],
    'pending_withdrawal_amount' => $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM withdrawals WHERE status = 'PENDING'")['total']
];

// Recent videos
$recentVideos = $db->fetchAll(
    "SELECT v.*, c.name as category_name 
     FROM videos v 
     LEFT JOIN categories c ON v.category_id = c.id 
     ORDER BY v.created_at DESC 
     LIMIT 10"
);

// Recent users
$recentUsers = $db->fetchAll(
    "SELECT * FROM users ORDER BY created_at DESC LIMIT 10"
);

// Pending withdrawals
$pendingWithdrawals = $db->fetchAll(
    "SELECT w.*, u.username, u.first_name, u.last_name 
     FROM withdrawals w 
     JOIN users u ON w.user_id = u.id 
     WHERE w.status = 'PENDING' 
     ORDER BY w.created_at DESC 
     LIMIT 10"
);

include 'includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card">
        <h3>Total Users</h3>
        <div class="value"><?php echo number_format($stats['total_users']); ?></div>
    </div>
    
    <div class="stat-card">
        <h3>Active Users (24h)</h3>
        <div class="value"><?php echo number_format($stats['active_users']); ?></div>
    </div>
    
    <div class="stat-card">
        <h3>Total Videos</h3>
        <div class="value"><?php echo number_format($stats['total_videos']); ?></div>
    </div>
    
    <div class="stat-card">
        <h3>Total Views</h3>
        <div class="value"><?php echo number_format($stats['total_views']); ?></div>
    </div>
    
    <div class="stat-card">
        <h3>Premium Users</h3>
        <div class="value"><?php echo number_format($stats['premium_users']); ?></div>
    </div>
    
    <div class="stat-card">
        <h3>Today's Revenue</h3>
        <div class="value">₹<?php echo number_format($stats['today_revenue'], 2); ?></div>
    </div>
    
    <div class="stat-card">
        <h3>Today's Ad Impressions</h3>
        <div class="value"><?php echo number_format($stats['today_ad_impressions']); ?></div>
    </div>
    
    <div class="stat-card">
        <h3>Pending Withdrawals</h3>
        <div class="value"><?php echo number_format($stats['pending_withdrawals']); ?></div>
        <small>₹<?php echo number_format($stats['pending_withdrawal_amount'], 2); ?></small>
    </div>
</div>

<div class="content-box">
    <h2 style="margin-bottom: 20px;">Recent Videos</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Category</th>
                <th>Views</th>
                <th>Access</th>
                <th>Status</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentVideos as $video): ?>
            <tr>
                <td><?php echo $video['id']; ?></td>
                <td><?php echo htmlspecialchars($video['title']); ?></td>
                <td><?php echo htmlspecialchars($video['category_name']); ?></td>
                <td><?php echo number_format($video['views']); ?></td>
                <td><span class="badge badge-<?php echo $video['access_type'] === 'PREMIUM' ? 'warning' : 'info'; ?>"><?php echo $video['access_type']; ?></span></td>
                <td><span class="badge badge-<?php echo $video['status'] === 'PUBLISHED' ? 'success' : 'warning'; ?>"><?php echo $video['status']; ?></span></td>
                <td><?php echo timeAgo($video['created_at']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if (!empty($pendingWithdrawals)): ?>
<div class="content-box">
    <h2 style="margin-bottom: 20px;">Pending Withdrawals</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>User</th>
                <th>Amount</th>
                <th>Net Amount</th>
                <th>Method</th>
                <th>Requested</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pendingWithdrawals as $withdrawal): ?>
            <tr>
                <td><?php echo $withdrawal['id']; ?></td>
                <td><?php echo htmlspecialchars($withdrawal['first_name'] . ' ' . $withdrawal['last_name']); ?></td>
                <td>₹<?php echo number_format($withdrawal['amount'], 2); ?></td>
                <td>₹<?php echo number_format($withdrawal['net_amount'], 2); ?></td>
                <td><?php echo htmlspecialchars($withdrawal['method']); ?></td>
                <td><?php echo timeAgo($withdrawal['created_at']); ?></td>
                <td>
                    <a href="/admin/withdrawals.php?id=<?php echo $withdrawal['id']; ?>" class="btn btn-primary" style="font-size: 12px; padding: 4px 8px;">Process</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="content-box">
    <h2 style="margin-bottom: 20px;">Recent Users</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Username</th>
                <th>Status</th>
                <th>Premium</th>
                <th>Joined</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentUsers as $user): ?>
            <tr>
                <td><?php echo $user['id']; ?></td>
                <td><?php echo htmlspecialchars($user['first_name'] . ' ' . ($user['last_name'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars($user['username'] ?? '-'); ?></td>
                <td><span class="badge badge-<?php echo $user['status'] === 'ACTIVE' ? 'success' : 'danger'; ?>"><?php echo $user['status']; ?></span></td>
                <td><?php echo $user['is_premium'] ? '✅' : '-'; ?></td>
                <td><?php echo timeAgo($user['created_at']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include 'includes/footer.php'; ?>
