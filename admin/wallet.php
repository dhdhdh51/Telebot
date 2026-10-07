<?php
/**
 * Admin → Wallet: manual credit/debit (ledger entry, audited) and the full ledger.
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';

$db = db();
$self = '/admin/wallet.php';
$admin = Auth::getCurrentAdmin();
$canAdjust = in_array($admin['role'], ['SUPER_ADMIN', 'ADMIN'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    if (!$canAdjust) {
        flash('error', 'Only ADMIN or SUPER_ADMIN can adjust wallets.');
        header('Location: ' . $self);
        exit;
    }
    $who = trim($_POST['user'] ?? '');
    $amount = trim($_POST['amount'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $user = ctype_digit($who)
        ? $db->fetchOne("SELECT id, first_name FROM users WHERE telegram_user_id = ? OR id = ? ORDER BY telegram_user_id = ? DESC LIMIT 1", [$who, $who, $who])
        : $db->fetchOne("SELECT id, first_name FROM users WHERE username = ?", [ltrim($who, '@')]);

    if (!$user) {
        flash('error', 'User not found. Use Telegram ID, user # or @username.');
    } elseif (!preg_match('/^-?\d{1,7}(\.\d{1,2})?$/', $amount) || Security::toPaise($amount) === 0) {
        flash('error', 'Amount like 50 or -20.50 (negative = deduct).');
    } elseif (mb_strlen($reason) < 3) {
        flash('error', 'Write a reason (shown to the user).');
    } else {
        $ref = 'ADJ_' . $admin['id'] . '_' . bin2hex(random_bytes(6));
        $r = addWalletTransaction((int)$user['id'], 'ADJUSTMENT', $amount, mb_substr($reason, 0, 200), $ref, 'admin', ['admin_id' => $admin['id']]);
        if ($r['success']) {
            logAudit('WALLET_ADJUSTMENT', "Adjusted wallet of user #{$user['id']} by ₹$amount: $reason", 'user', (int)$user['id'], ['balance' => $r['balance_before']], ['balance' => $r['balance_after']]);
            flash('success', "{$user['first_name']}: ₹{$r['balance_before']} → ₹{$r['balance_after']}");
        } else {
            flash('error', $r['error']);
        }
    }
    header('Location: ' . $self);
    exit;
}

$type = $_GET['type'] ?? '';
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$sql = "SELECT t.*, u.first_name, u.username, u.telegram_user_id FROM wallet_transactions t JOIN users u ON u.id = t.user_id WHERE 1=1";
$params = [];
if (in_array($type, ['REWARD', 'REFERRAL', 'WITHDRAWAL', 'ADJUSTMENT', 'REFUND'], true)) {
    $sql .= " AND t.type = ?";
    $params[] = $type;
}
if ($q !== '' && ctype_digit($q)) {
    $sql .= " AND u.telegram_user_id = ?";
    $params[] = $q;
}
$sql .= " ORDER BY t.id DESC";
$result = paginate($sql, $params, $page, 30);
$sum = $db->fetchOne("SELECT COALESCE(SUM(balance),0) bal, COALESCE(SUM(lifetime_earned),0) earned, COALESCE(SUM(pending_withdrawal),0) pend FROM wallets");

$pageTitle = 'Wallet';
include __DIR__ . '/includes/header.php';
?>

<?php renderFlash(); ?>

<div class="stats-grid">
    <div class="stat-card"><h3>Total user balances (liability)</h3><div class="value">₹<?= e($sum['bal']) ?></div></div>
    <div class="stat-card"><h3>Total ever earned</h3><div class="value">₹<?= e($sum['earned']) ?></div></div>
    <div class="stat-card"><h3>Held for withdrawals</h3><div class="value">₹<?= e($sum['pend']) ?></div></div>
</div>

<?php if ($canAdjust): ?>
<div class="content-box" style="max-width:820px">
    <h3 style="margin-bottom:12px">Manual adjustment</h3>
    <form method="POST" style="display:grid;grid-template-columns:1fr 120px 2fr auto;gap:8px;align-items:end">
        <?= CSRF::getInputField() ?>
        <div><label>User</label><input class="form-control" name="user" placeholder="Telegram ID / @username" required></div>
        <div><label>Amount ₹</label><input class="form-control" name="amount" placeholder="50 or -20" required></div>
        <div><label>Reason</label><input class="form-control" name="reason" placeholder="Contest prize / correction" required></div>
        <button class="btn btn-primary" onclick="return confirm('Apply this adjustment?')">Apply</button>
    </form>
</div>
<?php endif; ?>

<div class="content-box">
    <form method="GET" style="display:flex;gap:8px;margin-bottom:12px">
        <select class="form-control" name="type" style="width:180px"><option value="">All types</option>
            <?php foreach (['REWARD', 'REFERRAL', 'WITHDRAWAL', 'ADJUSTMENT', 'REFUND'] as $t): ?><option <?= $type === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select>
        <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Telegram ID" style="width:180px">
        <button class="btn btn-primary">Filter</button>
    </form>
    <?php if (!$result['data']): ?><p style="color:#7f8c8d">No transactions.</p><?php else: ?>
    <div style="overflow-x:auto"><table>
        <thead><tr><th>#</th><th>User</th><th>Type</th><th>Amount</th><th>Balance after</th><th>Description</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($result['data'] as $t): ?>
            <tr><td><?= (int)$t['id'] ?></td><td><?= e($t['first_name']) ?> <small><?= e($t['telegram_user_id']) ?></small></td><td><?= e($t['type']) ?></td>
                <td style="color:<?= $t['amount'] < 0 ? '#c0392b' : '#1e7e34' ?>;font-weight:700">₹<?= e($t['amount']) ?></td>
                <td>₹<?= e($t['balance_after']) ?></td><td><?= e($t['description']) ?></td><td><?= e($t['created_at']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php $p = $result['pagination']; if ($p['total_pages'] > 1): $qs = fn($n) => '?' . http_build_query(array_filter(['type' => $type, 'q' => $q, 'page' => $n])); ?>
        <div style="margin-top:12px"><?php if ($p['has_prev']): ?><a class="btn btn-primary" href="<?= e($qs($page - 1)) ?>">← Prev</a><?php endif; ?>
            Page <?= $p['current_page'] ?> / <?= $p['total_pages'] ?>
            <?php if ($p['has_next']): ?><a class="btn btn-primary" href="<?= e($qs($page + 1)) ?>">Next →</a><?php endif; ?></div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
