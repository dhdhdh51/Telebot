<?php
/**
 * Admin → Reports: daily charts and totals for any date range (live queries).
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/includes/charts.php';

$db = db();
$to = $_GET['to'] ?? date('Y-m-d');
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-29 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !strtotime($from)) $from = date('Y-m-d', strtotime('-29 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || !strtotime($to)) $to = date('Y-m-d');
if (strtotime($from) > strtotime($to)) [$from, $to] = [$to, $from];
if ((strtotime($to) - strtotime($from)) / 86400 > 366) $from = date('Y-m-d', strtotime($to . ' -366 days'));
$p = [$from . ' 00:00:00', $to . ' 23:59:59'];
$range = "BETWEEN ? AND ?";

$series = [
    'New users' => dailySeries("SELECT DATE(created_at) d, COUNT(*) v FROM users WHERE created_at $range GROUP BY d", $p, $from, $to),
    'Daily active users' => dailySeries("SELECT DATE(created_at) d, COUNT(DISTINCT user_id) v FROM video_views WHERE created_at $range GROUP BY d", $p, $from, $to),
    'Video views' => dailySeries("SELECT DATE(created_at) d, COUNT(*) v FROM video_views WHERE created_at $range GROUP BY d", $p, $from, $to),
    'Subscription revenue' => dailySeries("SELECT DATE(created_at) d, SUM(amount) v FROM payments WHERE status='SUCCESS' AND created_at $range GROUP BY d", $p, $from, $to),
    'Ad impressions' => dailySeries("SELECT DATE(created_at) d, COUNT(*) v FROM ad_impressions WHERE created_at $range GROUP BY d", $p, $from, $to),
    'Rewards paid' => dailySeries("SELECT DATE(created_at) d, SUM(amount) v FROM reward_transactions WHERE status='COMPLETED' AND created_at $range GROUP BY d", $p, $from, $to),
    'Withdrawals paid' => dailySeries("SELECT DATE(processed_at) d, SUM(net_amount) v FROM withdrawals WHERE status='PAID' AND processed_at $range GROUP BY d", $p, $from, $to),
];
$moneySeries = ['Subscription revenue', 'Rewards paid', 'Withdrawals paid'];
$colors = ['#3498db', '#9b59b6', '#16a085', '#27ae60', '#e67e22', '#f39c12', '#c0392b'];

$t = $db->fetchOne("SELECT
    (SELECT COUNT(DISTINCT user_id) FROM video_views WHERE created_at $range) AS unique_viewers,
    (SELECT COALESCE(SUM(watch_duration),0) FROM video_views WHERE created_at $range) AS watch_seconds,
    (SELECT COUNT(*) FROM video_views WHERE created_at $range) AS views,
    (SELECT COUNT(*) FROM video_views WHERE completed = 1 AND created_at $range) AS completed,
    (SELECT COUNT(*) FROM ad_clicks WHERE created_at $range) AS ad_clicks,
    (SELECT COUNT(*) FROM ad_impressions WHERE created_at $range) AS ad_impr,
    (SELECT COUNT(*) FROM payments WHERE status='SUCCESS' AND created_at $range) AS paid_orders,
    (SELECT COUNT(DISTINCT user_id) FROM payments WHERE status='SUCCESS' AND created_at $range) AS converted,
    (SELECT COUNT(*) FROM referrals WHERE created_at $range) AS referrals,
    (SELECT COUNT(*) FROM referrals WHERE reward_paid = 1 AND reward_paid_at $range) AS referrals_paid",
    array_merge($p, $p, $p, $p, $p, $p, $p, $p, $p, $p));
$revenue = array_sum($series['Subscription revenue']);
$rewards = array_sum($series['Rewards paid']);
$popular = $db->fetchAll(
    "SELECT v.id, v.title, v.access_type, COUNT(vv.id) views, COUNT(DISTINCT vv.user_id) viewers,
            COALESCE(SUM(vv.watch_duration),0) watch, SUM(vv.completed) completed
     FROM video_views vv JOIN videos v ON v.id = vv.video_id WHERE vv.created_at $range
     GROUP BY v.id ORDER BY views DESC LIMIT 10", $p);

$pageTitle = 'Reports';
include __DIR__ . '/includes/header.php';
?>

<div class="content-box">
    <form method="GET" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
        <div><label>From</label><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
        <div><label>To</label><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
        <button class="btn btn-primary">Show</button>
        <?php foreach (['7' => '7 days', '30' => '30 days', '90' => '90 days'] as $d => $l): ?>
            <a class="btn" style="background:#ecf0f1" href="?from=<?= date('Y-m-d', strtotime('-' . ($d - 1) . ' days')) ?>&to=<?= date('Y-m-d') ?>"><?= $l ?></a>
        <?php endforeach; ?>
    </form>
</div>

<div class="stats-grid">
    <div class="stat-card"><h3>Revenue</h3><div class="value">₹<?= number_format($revenue, 2) ?></div><small><?= (int)$t['paid_orders'] ?> payments · <?= (int)$t['converted'] ?> buyers</small></div>
    <div class="stat-card"><h3>Rewards paid</h3><div class="value">₹<?= number_format($rewards, 2) ?></div><small>Net: ₹<?= number_format($revenue - $rewards, 2) ?></small></div>
    <div class="stat-card"><h3>Unique viewers</h3><div class="value"><?= number_format($t['unique_viewers']) ?></div><small><?= number_format($t['views']) ?> views</small></div>
    <div class="stat-card"><h3>Watch time</h3><div class="value"><?= number_format($t['watch_seconds'] / 3600, 1) ?> h</div>
        <small>Completion <?= $t['views'] ? round($t['completed'] / $t['views'] * 100, 1) : 0 ?>%</small></div>
    <div class="stat-card"><h3>Ads</h3><div class="value"><?= number_format($t['ad_impr']) ?></div>
        <small><?= number_format($t['ad_clicks']) ?> clicks · CTR <?= $t['ad_impr'] ? round($t['ad_clicks'] / $t['ad_impr'] * 100, 2) : 0 ?>%</small></div>
    <div class="stat-card"><h3>Referrals</h3><div class="value"><?= (int)$t['referrals'] ?></div><small><?= (int)$t['referrals_paid'] ?> converted (bonus paid)</small></div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:0 20px">
<?php $i = 0; foreach ($series as $title => $data): ?>
    <?= svgBarChart($data, $title, in_array($title, $moneySeries, true), $colors[$i++ % count($colors)]) ?>
<?php endforeach; ?>
</div>

<div class="content-box">
    <h3 style="margin-bottom:12px">Popular videos in this period</h3>
    <?php if (!$popular): ?><p style="color:#7f8c8d">No views in this period.</p><?php else: ?>
    <table><thead><tr><th>Video</th><th>Views</th><th>Unique viewers</th><th>Watch time</th><th>Completion</th></tr></thead><tbody>
    <?php foreach ($popular as $v): ?>
        <tr><td><?= e($v['title']) ?> <span class="badge badge-<?= $v['access_type'] === 'PREMIUM' ? 'warning' : 'info' ?>"><?= e($v['access_type']) ?></span></td>
            <td><?= number_format($v['views']) ?></td><td><?= number_format($v['viewers']) ?></td>
            <td><?= round($v['watch'] / 60) ?> min</td><td><?= $v['views'] ? round($v['completed'] / $v['views'] * 100) : 0 ?>%</td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
