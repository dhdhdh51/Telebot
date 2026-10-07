<?php
/**
 * Admin → Withdrawals: review requests, see payout details, mark PROCESSING / PAID / REJECTED.
 * The amount was already held from the wallet at request time; REJECTED refunds it once.
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/subscription.php';

$db = db();
$self = '/admin/withdrawals.php';
$admin = Auth::getCurrentAdmin();
$canPay = in_array($admin['role'], ['SUPER_ADMIN', 'ADMIN'], true); // moderators can view only

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    if (!$canPay) {
        flash('error', 'Only ADMIN or SUPER_ADMIN can process withdrawals.');
        header('Location: ' . $self);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $utr = trim($_POST['transaction_id'] ?? '');
    $note = trim($_POST['notes'] ?? '');
    $back = $self . (!empty($_POST['return_query']) ? '?' . preg_replace('/[^\w=&%-]/', '', $_POST['return_query']) : '');

    if ($status === 'PAID' && $utr === '') {
        flash('error', 'Enter the UPI / bank transaction reference (UTR) before marking as paid.');
    } elseif (in_array($status, ['REJECTED'], true) && $note === '') {
        flash('error', 'Write a reason for rejecting (the user will see it).');
    } else {
        $w = $db->fetchOne("SELECT user_id, net_amount, amount FROM withdrawals WHERE id = ?", [$id]);
        $res = Wallet::processWithdrawal($id, (int)$admin['id'], $status, $utr !== '' ? mb_substr($utr, 0, 100) : null, $note !== '' ? mb_substr($note, 0, 500) : null);
        if ($res['success']) {
            $msgs = [
                'PAID' => "✅ Your withdrawal of ₹{$w['net_amount']} has been sent.\nRef: " . htmlspecialchars($utr),
                'REJECTED' => "❌ Your withdrawal of ₹{$w['amount']} was rejected and refunded to your wallet.\nReason: " . htmlspecialchars($note),
                'PROCESSING' => "⏳ Your withdrawal of ₹{$w['net_amount']} is being processed.",
            ];
            if (isset($msgs[$status])) {
                Subscription::notify((int)$w['user_id'], $msgs[$status]);
            }
            flash('success', "Withdrawal #$id marked $status.");
        } else {
            flash('error', $res['error']);
        }
    }
    header('Location: ' . $back);
    exit;
}

$status = $_GET['status'] ?? 'OPEN';
$page = max(1, (int)($_GET['page'] ?? 1));
$sql = "SELECT w.*, u.first_name, u.username, u.telegram_user_id, u.created_at AS user_since, u.total_watch_time,
               a.username AS admin_name
        FROM withdrawals w JOIN users u ON u.id = w.user_id LEFT JOIN admins a ON a.id = w.processed_by WHERE 1=1";
$params = [];
if ($status === 'OPEN') {
    $sql .= " AND w.status IN ('PENDING', 'PROCESSING')";
} elseif (in_array($status, ['PENDING', 'PROCESSING', 'PAID', 'REJECTED', 'CANCELLED'], true)) {
    $sql .= " AND w.status = ?";
    $params[] = $status;
}
$sql .= " ORDER BY (w.status = 'PENDING') DESC, w.id " . ($status === 'OPEN' ? 'ASC' : 'DESC');
$result = paginate($sql, $params, $page, 25);
$returnQuery = http_build_query(array_filter(['status' => $status, 'page' => $page > 1 ? $page : null]));
$totals = $db->fetchOne("SELECT
    COALESCE(SUM(CASE WHEN status='PENDING' THEN net_amount END),0) pending_amt, SUM(status='PENDING') pending_n,
    COALESCE(SUM(CASE WHEN status='PAID' AND processed_at > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN net_amount END),0) paid30
    FROM withdrawals");

$pageTitle = 'Withdrawals';
include __DIR__ . '/includes/header.php';
?>

<?php renderFlash(); ?>

<div class="stats-grid">
    <div class="stat-card"><h3>Pending requests</h3><div class="value"><?= (int)$totals['pending_n'] ?></div><small>₹<?= e($totals['pending_amt']) ?> to pay</small></div>
    <div class="stat-card"><h3>Paid (30 days)</h3><div class="value">₹<?= e($totals['paid30']) ?></div></div>
</div>

<div class="content-box">
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
        <?php foreach (['OPEN' => 'To do', 'PENDING' => 'Pending', 'PROCESSING' => 'Processing', 'PAID' => 'Paid', 'REJECTED' => 'Rejected', 'CANCELLED' => 'Cancelled', 'ALL' => 'All'] as $k => $l): ?>
            <a class="btn <?= $status === $k ? 'btn-primary' : '' ?>" style="<?= $status === $k ? '' : 'background:#ecf0f1' ?>" href="?status=<?= $k ?>"><?= $l ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (!$result['data']): ?><p style="padding:30px;text-align:center;color:#7f8c8d">Nothing here 🎉</p><?php else: ?>
    <div style="overflow-x:auto"><table>
        <thead><tr><th>#</th><th>User</th><th>Amount</th><th>Pay to</th><th>Status</th><th>Requested</th><th style="min-width:280px">Action</th></tr></thead>
        <tbody>
        <?php foreach ($result['data'] as $w):
            $d = json_decode((string)Security::decrypt($w['account_details']), true) ?: []; ?>
            <tr>
                <td><?= (int)$w['id'] ?></td>
                <td><strong><?= e($w['first_name']) ?></strong><?= $w['username'] ? ' @' . e($w['username']) : '' ?><br>
                    <small>TG <?= e($w['telegram_user_id']) ?> · joined <?= e(timeAgo($w['user_since'])) ?> · watched <?= round($w['total_watch_time'] / 60) ?> min</small></td>
                <td>₹<?= e($w['amount']) ?><br><small>fee ₹<?= e($w['fee']) ?> · <b>send ₹<?= e($w['net_amount']) ?></b></small></td>
                <td style="font-family:monospace;font-size:13px">
                    <?php if ($w['method'] === 'UPI'): ?>UPI: <b><?= e($d['upi_id'] ?? '?') ?></b>
                    <?php else: ?><?= e($d['account_name'] ?? '') ?><br>A/C <b><?= e($d['account_number'] ?? '') ?></b><br>IFSC <b><?= e($d['ifsc'] ?? '') ?></b><?php endif; ?>
                </td>
                <td><span class="badge badge-<?= ['PAID' => 'success', 'PENDING' => 'warning', 'PROCESSING' => 'info'][$w['status']] ?? 'danger' ?>"><?= e($w['status']) ?></span>
                    <?php if ($w['transaction_id']): ?><br><small>Ref <?= e($w['transaction_id']) ?></small><?php endif; ?>
                    <?php if ($w['rejection_reason']): ?><br><small><?= e($w['rejection_reason']) ?></small><?php endif; ?>
                    <?php if ($w['admin_name']): ?><br><small>by <?= e($w['admin_name']) ?></small><?php endif; ?></td>
                <td><?= e(timeAgo($w['created_at'])) ?></td>
                <td>
                <?php if ($canPay && in_array($w['status'], ['PENDING', 'PROCESSING'], true)): ?>
                    <form method="POST" style="display:flex;gap:4px;margin-bottom:6px">
                        <?= CSRF::getInputField() ?><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
                        <input type="hidden" name="status" value="PAID">
                        <input class="form-control" name="transaction_id" placeholder="UTR / ref no." style="padding:4px;font-size:12px" required>
                        <button class="btn btn-primary" style="padding:4px 8px;font-size:12px;background:#27ae60" onclick="return confirm('Did you send ₹<?= e($w['net_amount']) ?>? Mark as PAID?')">✔ Paid</button>
                    </form>
                    <form method="POST" style="display:flex;gap:4px">
                        <?= CSRF::getInputField() ?><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
                        <input class="form-control" name="notes" placeholder="Reason (for reject)" style="padding:4px;font-size:12px">
                        <?php if ($w['status'] === 'PENDING'): ?>
                            <button class="btn btn-primary" name="status" value="PROCESSING" style="padding:4px 8px;font-size:12px">⏳</button>
                        <?php endif; ?>
                        <button class="btn btn-danger" name="status" value="REJECTED" style="padding:4px 8px;font-size:12px" onclick="return confirm('Reject and refund?')">✘ Reject</button>
                    </form>
                <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php $p = $result['pagination']; if ($p['total_pages'] > 1): ?>
        <div style="margin-top:16px">
            <?php if ($p['has_prev']): ?><a class="btn btn-primary" href="?status=<?= e($status) ?>&page=<?= $page - 1 ?>">← Prev</a><?php endif; ?>
            Page <?= $p['current_page'] ?> / <?= $p['total_pages'] ?>
            <?php if ($p['has_next']): ?><a class="btn btn-primary" href="?status=<?= e($status) ?>&page=<?= $page + 1 ?>">Next →</a><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php endif; ?>
    <p style="color:#7f8c8d;font-size:13px;margin-top:12px">Send the money yourself (UPI app / net banking), then enter the UTR and click ✔ Paid. The user gets a Telegram message.</p>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
