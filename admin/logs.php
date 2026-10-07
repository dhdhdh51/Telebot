<?php
/**
 * Admin → Audit Logs: admin actions (filterable) + application error log (SUPER_ADMIN).
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';

$db = db();
$tab = $_GET['tab'] ?? 'audit';
$isSuper = isSuperAdmin();
if ($tab === 'errors' && !$isSuper) {
    $tab = 'audit';
}

$pageTitle = 'Audit Logs';
include __DIR__ . '/includes/header.php';
?>

<div class="content-box" style="display:flex;gap:8px">
    <a class="btn <?= $tab === 'audit' ? 'btn-primary' : '' ?>" style="<?= $tab === 'audit' ? '' : 'background:#ecf0f1' ?>" href="?tab=audit">📋 Admin actions</a>
    <?php if ($isSuper): ?><a class="btn <?= $tab === 'errors' ? 'btn-primary' : '' ?>" style="<?= $tab === 'errors' ? '' : 'background:#ecf0f1' ?>" href="?tab=errors">⚠️ Error log</a><?php endif; ?>
</div>

<?php if ($tab === 'audit'):
    $action = $_GET['action'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $sql = "SELECT l.*, a.username AS admin_name FROM audit_logs l LEFT JOIN admins a ON a.id = l.admin_id WHERE 1=1";
    $params = [];
    if ($action !== '' && preg_match('/^[A-Z_]{3,60}$/', $action)) {
        $sql .= " AND l.action = ?";
        $params[] = $action;
    }
    $sql .= " ORDER BY l.id DESC";
    $result = paginate($sql, $params, $page, 50);
    $actions = $db->fetchAll("SELECT action, COUNT(*) n FROM audit_logs GROUP BY action ORDER BY action");
?>
<div class="content-box">
    <form method="GET" style="display:flex;gap:8px;margin-bottom:12px"><input type="hidden" name="tab" value="audit">
        <select class="form-control" name="action" style="width:280px"><option value="">All actions</option>
            <?php foreach ($actions as $a): ?><option value="<?= e($a['action']) ?>" <?= $action === $a['action'] ? 'selected' : '' ?>><?= e($a['action']) ?> (<?= (int)$a['n'] ?>)</option><?php endforeach; ?>
        </select><button class="btn btn-primary">Filter</button></form>
    <?php if (!$result['data']): ?><p style="color:#7f8c8d">No entries.</p><?php else: ?>
    <div style="overflow-x:auto"><table><thead><tr><th>When</th><th>Admin</th><th>Action</th><th>Description</th><th>IP</th></tr></thead><tbody>
    <?php foreach ($result['data'] as $l): ?>
        <tr><td style="white-space:nowrap"><?= e($l['created_at']) ?></td><td><?= e($l['admin_name'] ?? ($l['user_id'] ? 'user #' . $l['user_id'] : 'system')) ?></td>
            <td><code><?= e($l['action']) ?></code></td><td><?= e($l['description']) ?></td><td><?= e($l['ip_address']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php $p = $result['pagination']; if ($p['total_pages'] > 1): $qs = fn($n) => '?' . http_build_query(['tab' => 'audit', 'action' => $action, 'page' => $n]); ?>
        <div style="margin-top:12px"><?php if ($p['has_prev']): ?><a class="btn btn-primary" href="<?= e($qs($page - 1)) ?>">← Prev</a><?php endif; ?>
            Page <?= $p['current_page'] ?> / <?= $p['total_pages'] ?>
            <?php if ($p['has_next']): ?><a class="btn btn-primary" href="<?= e($qs($page + 1)) ?>">Next →</a><?php endif; ?></div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php else:
    $file = __DIR__ . '/../logs/php-errors.log';
    $lines = [];
    if (is_file($file) && is_readable($file)) {
        // Read only the tail so huge logs don't exhaust memory.
        $fh = fopen($file, 'rb');
        $size = filesize($file);
        fseek($fh, max(0, $size - 200000));
        $chunk = stream_get_contents($fh);
        fclose($fh);
        $lines = array_slice(array_filter(explode("\n", $chunk)), -300);
        $lines = array_reverse($lines);
    }
    $filter = $_GET['f'] ?? '';
    $groups = ['telegram' => 'Telegram', 'razorpay|payment' => 'Payments', 'upload' => 'Uploads', 'reward|withdraw|wallet' => 'Wallet/Rewards', 'database' => 'Database'];
    if ($filter !== '' && isset($groups[$filter])) {
        $lines = array_values(array_filter($lines, fn($l) => preg_match('/' . $filter . '/i', $l)));
    }
?>
<div class="content-box">
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
        <a class="btn" style="background:#ecf0f1" href="?tab=errors">All</a>
        <?php foreach ($groups as $k => $l): ?><a class="btn" style="background:<?= $filter === $k ? '#3498db;color:#fff' : '#ecf0f1' ?>" href="?tab=errors&f=<?= e(urlencode($k)) ?>"><?= e($l) ?></a><?php endforeach; ?>
    </div>
    <p style="color:#7f8c8d;font-size:13px">Newest first · last 300 lines of <code>logs/php-errors.log</code>. Secrets (bot token, payment keys) are never written here.</p>
    <?php if (!$lines): ?><p style="padding:20px;color:#1e7e34">✔ No errors logged.</p><?php else: ?>
    <pre style="background:#2c3e50;color:#ecf0f1;padding:14px;border-radius:6px;overflow:auto;max-height:600px;font-size:12px;white-space:pre-wrap"><?php foreach ($lines as $l) echo e($l) . "\n"; ?></pre>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
