<?php
/**
 * Admin → Referrals: who invited whom, eligibility progress, bonuses paid, top referrers.
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';

$db = db();
$page = max(1, (int)($_GET['page'] ?? 1));
$minWatch = (int)getSetting('referral', 'min_referred_watch_time', 300);
$result = paginate(
    "SELECT r.*, a.first_name AS ref_name, a.username AS ref_user, a.telegram_user_id AS ref_tg,
            b.first_name AS new_name, b.username AS new_user, b.total_watch_time, b.status AS new_status
     FROM referrals r JOIN users a ON a.id = r.referrer_id JOIN users b ON b.id = r.referred_id
     ORDER BY r.id DESC", [], $page, 30);
$top = $db->fetchAll(
    "SELECT u.first_name, u.username, u.telegram_user_id, COUNT(*) invited, SUM(r.reward_paid) converted, COALESCE(SUM(r.reward_earned),0) earned
     FROM referrals r JOIN users u ON u.id = r.referrer_id GROUP BY r.referrer_id ORDER BY invited DESC LIMIT 10");
$tot = $db->fetchOne("SELECT COUNT(*) n, COALESCE(SUM(reward_paid),0) paid, COALESCE(SUM(reward_earned),0) amt FROM referrals");

$pageTitle = 'Referrals';
include __DIR__ . '/includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card"><h3>Referred users</h3><div class="value"><?= (int)$tot['n'] ?></div></div>
    <div class="stat-card"><h3>Converted (bonus paid)</h3><div class="value"><?= (int)$tot['paid'] ?></div></div>
    <div class="stat-card"><h3>Bonuses paid</h3><div class="value">₹<?= e($tot['amt']) ?></div></div>
</div>

<div class="content-box">
    <h3 style="margin-bottom:12px">Top referrers</h3>
    <?php if (!$top): ?><p style="color:#7f8c8d">No referrals yet. Users find their invite link in Profile.</p><?php else: ?>
    <table><thead><tr><th>User</th><th>Invited</th><th>Converted</th><th>Earned</th></tr></thead><tbody>
    <?php foreach ($top as $t): ?>
        <tr><td><?= e($t['first_name']) ?> <?= $t['username'] ? '@' . e($t['username']) : '' ?> <small><?= e($t['telegram_user_id']) ?></small></td>
            <td><?= (int)$t['invited'] ?></td><td><?= (int)$t['converted'] ?></td><td>₹<?= e($t['earned']) ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php endif; ?>
</div>

<div class="content-box">
    <h3 style="margin-bottom:12px">All referrals</h3>
    <?php if ($result['data']): ?>
    <table><thead><tr><th>Referrer</th><th>New user</th><th>Progress</th><th>Bonus</th><th>Joined</th></tr></thead><tbody>
    <?php foreach ($result['data'] as $r): $pct = $minWatch ? min(100, round($r['total_watch_time'] / $minWatch * 100)) : 100; ?>
        <tr><td><?= e($r['ref_name']) ?> <small><?= e($r['ref_tg']) ?></small></td>
            <td><?= e($r['new_name']) ?> <?= $r['new_user'] ? '@' . e($r['new_user']) : '' ?><?= $r['new_status'] !== 'ACTIVE' ? ' <span class="badge badge-danger">' . e($r['new_status']) . '</span>' : '' ?></td>
            <td><?= round($r['total_watch_time'] / 60) ?> / <?= round($minWatch / 60) ?> min (<?= $pct ?>%)</td>
            <td><?= $r['reward_paid'] ? '<span class="badge badge-success">₹' . e($r['reward_earned']) . ' paid</span>' : '<span class="badge badge-warning">waiting</span>' ?></td>
            <td><?= e(timeAgo($r['created_at'])) ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php $p = $result['pagination']; if ($p['total_pages'] > 1): ?>
        <div style="margin-top:12px"><?php if ($p['has_prev']): ?><a class="btn btn-primary" href="?page=<?= $page - 1 ?>">← Prev</a><?php endif; ?>
            Page <?= $p['current_page'] ?> / <?= $p['total_pages'] ?>
            <?php if ($p['has_next']): ?><a class="btn btn-primary" href="?page=<?= $page + 1 ?>">Next →</a><?php endif; ?></div>
    <?php endif; ?>
    <?php endif; ?>
    <p style="color:#7f8c8d;font-size:13px;margin-top:10px">Anti-fake rules: only brand-new users can be referred, no self-referral, bonus only after real (server-measured) watch time.</p>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
