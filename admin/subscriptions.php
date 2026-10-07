<?php
/**
 * Admin → Subscriptions: plans (price, duration, features), subscribers, payments.
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/../includes/payments.php';

$db = db();
$self = '/admin/subscriptions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    requirePermission('edit', $self);
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'save_plan') {
        $name = trim($_POST['name'] ?? '');
        $price = trim($_POST['price'] ?? '');
        $days = (int)($_POST['duration_days'] ?? 0);
        $features = array_values(array_filter(array_map('trim', explode("\n", (string)($_POST['features'] ?? '')))));
        $err = null;
        if ($name === '' || mb_strlen($name) > 100) {
            $err = 'Plan name is required (max 100).';
        } elseif (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $price) || Security::toPaise($price) < 100) {
            $err = 'Price must be at least ₹1 (e.g. 49 or 149.00).';
        } elseif ($days < 1 || $days > 3650) {
            $err = 'Duration must be 1–3650 days.';
        }
        if ($err) {
            flash('error', $err);
            header('Location: ' . $self . ($id ? '?edit=' . $id : '?new=1'));
            exit;
        }
        $data = [$name, trim($_POST['description'] ?? ''), $days, $price, json_encode($features, JSON_UNESCAPED_UNICODE),
                 (int)($_POST['display_order'] ?? 0), !empty($_POST['is_popular']) ? 1 : 0, ($_POST['status'] ?? '') === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE'];
        if ($id) {
            $old = $db->fetchOne("SELECT * FROM subscription_plans WHERE id = ?", [$id]);
            $db->execute("UPDATE subscription_plans SET name=?, description=?, duration_days=?, price=?, features=?, display_order=?, is_popular=?, status=? WHERE id=?", array_merge($data, [$id]));
            logAudit('PLAN_UPDATED', "Updated plan #$id $name", 'subscription_plan', $id, $old, ['price' => $price, 'days' => $days]);
        } else {
            $slug = Security::createSlug($name) . '-' . bin2hex(random_bytes(2));
            $db->execute("INSERT INTO subscription_plans (name, description, duration_days, price, features, display_order, is_popular, status, slug, currency) VALUES (?,?,?,?,?,?,?,?,?, 'INR')", array_merge($data, [$slug]));
            logAudit('PLAN_CREATED', "Created plan $name", 'subscription_plan', (int)$db->lastInsertId());
        }
        flash('success', 'Plan saved.');
    } elseif ($action === 'delete_plan') {
        requirePermission('delete', $self);
        $used = $db->fetchOne("SELECT COUNT(*) c FROM subscriptions WHERE plan_id = ?", [$id])['c'];
        if ($used) {
            $db->execute("UPDATE subscription_plans SET status='INACTIVE' WHERE id=?", [$id]);
            flash('success', 'Plan has subscribers, so it was deactivated instead of deleted.');
        } else {
            $db->execute("DELETE FROM subscription_plans WHERE id=?", [$id]);
            flash('success', 'Plan deleted.');
        }
        logAudit('PLAN_DELETED', "Deleted/deactivated plan #$id", 'subscription_plan', $id);
    }
    header('Location: ' . $self);
    exit;
}

$plans = $db->fetchAll("SELECT p.*, (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id = p.id AND s.status='ACTIVE' AND s.end_date > NOW()) AS active_subs FROM subscription_plans p ORDER BY display_order, price");
$editId = (int)($_GET['edit'] ?? 0);
$editing = $editId ? $db->fetchOne("SELECT * FROM subscription_plans WHERE id = ?", [$editId]) : null;
$showForm = $editing || isset($_GET['new']);

$subs = $db->fetchAll(
    "SELECT s.id, s.start_date, s.end_date, s.payment_id, u.id AS user_id, u.first_name, u.username, u.telegram_user_id, sp.name AS plan_name
     FROM subscriptions s JOIN users u ON u.id = s.user_id JOIN subscription_plans sp ON sp.id = s.plan_id
     WHERE s.status = 'ACTIVE' AND s.end_date > NOW() ORDER BY s.id DESC LIMIT 50"
);
$payments = $db->fetchAll(
    "SELECT p.*, u.first_name, u.username, sp.name AS plan_name FROM payments p
     JOIN users u ON u.id = p.user_id LEFT JOIN subscription_plans sp ON sp.id = p.plan_id
     ORDER BY p.id DESC LIMIT 50"
);
$revenue = $db->fetchOne("SELECT COALESCE(SUM(amount),0) t30, COUNT(*) n30 FROM payments WHERE status='SUCCESS' AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
$gatewayOn = Payments::gateway() !== null;

$pageTitle = 'Subscriptions';
include __DIR__ . '/includes/header.php';
?>

<?php renderFlash(); ?>

<?php if (!$gatewayOn): ?>
<div class="alert alert-error">Online payments are <b>OFF</b>. Users see the plans but cannot pay.
    <?php if (isSuperAdmin()): ?><a href="/admin/settings.php">Enable Razorpay in Settings → Payment</a>.<?php endif; ?>
    You can still give premium manually from <a href="/admin/users.php">Users</a>.</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><h3>Active subscribers</h3><div class="value"><?= count($subs) >= 50 ? '50+' : count($subs) ?></div></div>
    <div class="stat-card"><h3>Revenue (30 days)</h3><div class="value">₹<?= e($revenue['t30']) ?></div><small><?= (int)$revenue['n30'] ?> payments</small></div>
</div>

<div class="content-box">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h3>Plans</h3>
        <?php if (Auth::hasPermission('edit')): ?><a class="btn btn-primary" href="?new=1">+ Add plan</a><?php endif; ?>
    </div>
    <table>
        <thead><tr><th>Name</th><th>Price</th><th>Days</th><th>Active subs</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($plans as $p): ?>
            <tr>
                <td><strong><?= e($p['name']) ?></strong><?= $p['is_popular'] ? ' <span class="badge badge-warning">POPULAR</span>' : '' ?><br><small><?= e($p['description']) ?></small></td>
                <td>₹<?= e($p['price']) ?></td>
                <td><?= (int)$p['duration_days'] ?></td>
                <td><?= (int)$p['active_subs'] ?></td>
                <td><span class="badge badge-<?= $p['status'] === 'ACTIVE' ? 'success' : 'danger' ?>"><?= e($p['status']) ?></span></td>
                <td style="white-space:nowrap">
                    <?php if (Auth::hasPermission('edit')): ?><a class="btn btn-primary" style="padding:4px 10px;font-size:12px" href="?edit=<?= (int)$p['id'] ?>">Edit</a><?php endif; ?>
                    <?php if (Auth::hasPermission('delete')): ?>
                    <form method="POST" style="display:inline"><?= CSRF::getInputField() ?><input type="hidden" name="action" value="delete_plan"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button class="btn btn-danger" style="padding:4px 10px;font-size:12px" onclick="return confirm('Delete this plan?')">Delete</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($showForm): $p = $editing ?: ['id' => 0, 'name' => '', 'description' => '', 'duration_days' => 30, 'price' => '', 'features' => '[]', 'display_order' => 0, 'is_popular' => 0, 'status' => 'ACTIVE']; ?>
<div class="content-box" style="max-width:640px" id="planForm">
    <h3 style="margin-bottom:16px"><?= $editing ? 'Edit plan' : 'New plan' ?></h3>
    <form method="POST">
        <?= CSRF::getInputField() ?>
        <input type="hidden" name="action" value="save_plan"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <div class="form-group"><label>Name</label><input class="form-control" name="name" required maxlength="100" value="<?= e($p['name']) ?>"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
            <div class="form-group"><label>Price (₹)</label><input class="form-control" name="price" required value="<?= e($p['price']) ?>"></div>
            <div class="form-group"><label>Duration (days)</label><input class="form-control" type="number" min="1" name="duration_days" required value="<?= (int)$p['duration_days'] ?>"></div>
            <div class="form-group"><label>Sort order</label><input class="form-control" type="number" name="display_order" value="<?= (int)$p['display_order'] ?>"></div>
        </div>
        <div class="form-group"><label>Description</label><input class="form-control" name="description" maxlength="255" value="<?= e($p['description']) ?>"></div>
        <div class="form-group"><label>Features <small>(one per line, shown on the plan card)</small></label>
            <textarea class="form-control" name="features"><?= e(implode("\n", json_decode((string)$p['features'], true) ?: [])) ?></textarea></div>
        <div class="form-group" style="display:flex;gap:20px">
            <label><input type="checkbox" name="is_popular" value="1" <?= $p['is_popular'] ? 'checked' : '' ?>> Mark as popular</label>
            <label><input type="checkbox" name="status" value="INACTIVE" <?= $p['status'] === 'INACTIVE' ? 'checked' : '' ?>> Hidden (inactive)</label>
        </div>
        <button class="btn btn-primary" type="submit">Save plan</button> <a class="btn" style="background:#ecf0f1" href="<?= $self ?>">Cancel</a>
    </form>
</div>
<?php endif; ?>

<div class="content-box">
    <h3 style="margin-bottom:12px">Active subscribers (latest 50)</h3>
    <?php if (!$subs): ?><p style="color:#7f8c8d">No active subscribers yet.</p><?php else: ?>
    <table><thead><tr><th>User</th><th>Plan</th><th>Valid till</th><th>Source</th></tr></thead><tbody>
    <?php foreach ($subs as $s): ?>
        <tr><td><a href="/admin/users.php?q=<?= (int)$s['telegram_user_id'] ?>"><?= e($s['first_name']) ?></a> <?= $s['username'] ? '@' . e($s['username']) : '' ?></td>
            <td><?= e($s['plan_name']) ?></td><td><?= e(date('d M Y H:i', strtotime($s['end_date']))) ?></td>
            <td><?= $s['payment_id'] ? 'Payment #' . (int)$s['payment_id'] : 'Admin grant' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
</div>

<div class="content-box">
    <h3 style="margin-bottom:12px">Payments (latest 50)</h3>
    <?php if (!$payments): ?><p style="color:#7f8c8d">No payments yet.</p><?php else: ?>
    <div style="overflow-x:auto"><table><thead><tr><th>#</th><th>User</th><th>Plan</th><th>Amount</th><th>Status</th><th>Order / Payment ID</th><th>When</th></tr></thead><tbody>
    <?php foreach ($payments as $pm): ?>
        <tr><td><?= (int)$pm['id'] ?></td><td><?= e($pm['first_name']) ?> <?= $pm['username'] ? '@' . e($pm['username']) : '' ?></td>
            <td><?= e($pm['plan_name']) ?></td><td>₹<?= e($pm['amount']) ?></td>
            <td><span class="badge badge-<?= ['SUCCESS' => 'success', 'PENDING' => 'warning'][$pm['status']] ?? 'danger' ?>"><?= e($pm['status']) ?></span></td>
            <td style="font-size:12px"><?= e($pm['order_id']) ?><br><?= e($pm['payment_id']) ?></td><td><?= e(timeAgo($pm['created_at'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
