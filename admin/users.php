<?php
/**
 * Admin → Users: search, ban/unban, grant or revoke premium manually.
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/../includes/subscription.php';

$db = db();
$self = '/admin/users.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    requirePermission('edit', $self);
    $id = (int)($_POST['id'] ?? 0);
    $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
    $action = $_POST['action'] ?? '';
    $back = $self . (!empty($_POST['return_query']) ? '?' . preg_replace('/[^\w=&%@.+-]/', '', $_POST['return_query']) : '');

    if (!$user) {
        flash('error', 'User not found.');
    } elseif ($action === 'ban' || $action === 'unban') {
        $status = $action === 'ban' ? 'BANNED' : 'ACTIVE';
        $db->execute("UPDATE users SET status = ? WHERE id = ?", [$status, $id]);
        logAudit($action === 'ban' ? 'USER_BANNED' : 'USER_UNBANNED', "User #$id {$user['first_name']} → $status", 'user', $id);
        flash('success', $user['first_name'] . ($action === 'ban' ? ' banned.' : ' unbanned.'));
    } elseif ($action === 'grant') {
        $plan = $db->fetchOne("SELECT * FROM subscription_plans WHERE id = ?", [(int)($_POST['plan_id'] ?? 0)]);
        $days = (int)($_POST['days'] ?? 0) ?: (int)($plan['duration_days'] ?? 0);
        if (!$plan || $days < 1 || $days > 3650) {
            flash('error', 'Choose a plan and 1–3650 days.');
        } else {
            Subscription::grant($id, (int)$plan['id'], $days);
            logAudit('SUBSCRIPTION_GRANTED', "Granted {$days} days ({$plan['name']}) to user #$id", 'user', $id, null, ['plan_id' => $plan['id'], 'days' => $days]);
            $sub = Subscription::active($id);
            Subscription::notify($id, "🎁 <b>Premium activated!</b>\nValid till: " . date('d M Y', strtotime($sub['end_date'])));
            flash('success', "Premium given to {$user['first_name']} for $days days.");
        }
    } elseif ($action === 'revoke') {
        Subscription::revoke($id);
        logAudit('SUBSCRIPTION_REVOKED', "Revoked premium of user #$id", 'user', $id);
        flash('success', 'Premium removed from ' . $user['first_name'] . '.');
    }
    header('Location: ' . $back);
    exit;
}

$q = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));

$sql = "SELECT u.*, w.balance,
          (SELECT MAX(s.end_date) FROM subscriptions s WHERE s.user_id = u.id AND s.status = 'ACTIVE' AND s.end_date > NOW()) AS premium_until
        FROM users u LEFT JOIN wallets w ON w.user_id = u.id WHERE 1=1";
$params = [];
if ($q !== '') {
    if (ctype_digit($q)) {
        $sql .= " AND (u.telegram_user_id = ? OR u.id = ?)";
        array_push($params, $q, $q);
    } else {
        $like = '%' . addcslashes(ltrim($q, '@'), '%_\\') . '%';
        $sql .= " AND (u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
        array_push($params, $like, $like, $like);
    }
}
if ($filter === 'premium') {
    $sql .= " AND EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = u.id AND s.status = 'ACTIVE' AND s.end_date > NOW())";
} elseif ($filter === 'banned') {
    $sql .= " AND u.status = 'BANNED'";
}
$sql .= " ORDER BY u.id DESC";
$result = paginate($sql, $params, $page, 25);
$plans = $db->fetchAll("SELECT id, name, duration_days FROM subscription_plans ORDER BY display_order, price");
$returnQuery = http_build_query(array_filter(['q' => $q, 'filter' => $filter, 'page' => $page > 1 ? $page : null]));
$total = $db->fetchOne("SELECT COUNT(*) c FROM users")['c'];

$pageTitle = 'Users';
include __DIR__ . '/includes/header.php';
?>

<?php renderFlash(); ?>

<div class="content-box">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
        <input class="form-control" style="width:260px" name="q" value="<?= e($q) ?>" placeholder="Name, @username or Telegram ID">
        <select class="form-control" style="width:160px" name="filter">
            <option value="">All users (<?= (int)$total ?>)</option>
            <option value="premium" <?= $filter === 'premium' ? 'selected' : '' ?>>Premium</option>
            <option value="banned" <?= $filter === 'banned' ? 'selected' : '' ?>>Banned</option>
        </select>
        <button class="btn btn-primary">Search</button>
    </form>

    <?php if (!$result['data']): ?><p style="color:#7f8c8d;padding:30px;text-align:center">No users found. Users appear here after they open the Mini App or press START in the bot.</p><?php else: ?>
    <div style="overflow-x:auto"><table>
        <thead><tr><th>User</th><th>Telegram ID</th><th>Premium</th><th>Balance</th><th>Watched</th><th>Joined</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($result['data'] as $u): ?>
        <tr>
            <td><strong><?= e(trim($u['first_name'] . ' ' . $u['last_name'])) ?></strong><?= $u['username'] ? '<br><small>@' . e($u['username']) . '</small>' : '' ?>
                <?= $u['status'] !== 'ACTIVE' ? ' <span class="badge badge-danger">' . e($u['status']) . '</span>' : '' ?></td>
            <td><?= e($u['telegram_user_id']) ?></td>
            <td><?= $u['premium_until'] ? '<span class="badge badge-warning">PREMIUM</span><br><small>till ' . e(date('d M Y', strtotime($u['premium_until']))) . '</small>' : '—' ?></td>
            <td>₹<?= e($u['balance'] ?? '0.00') ?></td>
            <td><?= (int)$u['total_videos_watched'] ?> videos<br><small><?= round($u['total_watch_time'] / 60) ?> min</small></td>
            <td><?= e(timeAgo($u['created_at'])) ?></td>
            <td style="min-width:260px">
                <?php if (Auth::hasPermission('edit')): ?>
                <form method="POST" style="display:flex;gap:4px;margin-bottom:6px">
                    <?= CSRF::getInputField() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="grant">
                    <input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
                    <select class="form-control" name="plan_id" style="padding:4px;font-size:12px">
                        <?php foreach ($plans as $pl): ?><option value="<?= (int)$pl['id'] ?>"><?= e($pl['name']) ?> (<?= (int)$pl['duration_days'] ?>d)</option><?php endforeach; ?>
                    </select>
                    <input class="form-control" name="days" type="number" min="1" placeholder="days" style="width:70px;padding:4px;font-size:12px">
                    <button class="btn btn-primary" style="padding:4px 8px;font-size:12px">💎 Give</button>
                </form>
                <div style="display:flex;gap:4px">
                <?php if ($u['premium_until']): ?>
                    <form method="POST"><?= CSRF::getInputField() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
                        <button class="btn btn-danger" style="padding:4px 8px;font-size:12px" onclick="return confirm('Remove premium?')">Remove premium</button></form>
                <?php endif; ?>
                    <form method="POST"><?= CSRF::getInputField() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
                        <input type="hidden" name="action" value="<?= $u['status'] === 'BANNED' ? 'unban' : 'ban' ?>">
                        <button class="btn <?= $u['status'] === 'BANNED' ? 'btn-primary' : 'btn-danger' ?>" style="padding:4px 8px;font-size:12px"
                            onclick="return confirm('Are you sure?')"><?= $u['status'] === 'BANNED' ? 'Unban' : 'Ban' ?></button></form>
                </div>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php $p = $result['pagination']; if ($p['total_pages'] > 1): $qs = fn($n) => '?' . http_build_query(array_filter(['q' => $q, 'filter' => $filter, 'page' => $n])); ?>
        <div style="margin-top:16px;display:flex;gap:8px;align-items:center">
            <?php if ($p['has_prev']): ?><a class="btn btn-primary" href="<?= e($qs($page - 1)) ?>">← Prev</a><?php endif; ?>
            <span>Page <?= $p['current_page'] ?> / <?= $p['total_pages'] ?></span>
            <?php if ($p['has_next']): ?><a class="btn btn-primary" href="<?= e($qs($page + 1)) ?>">Next →</a><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
