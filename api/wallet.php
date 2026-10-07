<?php
/**
 * Wallet API for the Mini App. Balances are read from the server ledger only.
 *   GET  ?action=summary
 *   GET  ?action=transactions&page=N
 *   GET  ?action=withdrawals
 *   POST {action:"withdraw", amount, method:"UPI"|"BANK_TRANSFER", upi_id | account_name, account_number, ifsc}
 *   POST {action:"cancel_withdrawal", id}
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/wallet.php';

header('Content-Type: application/json');

$userId = requireAppUser();
session_write_close();

$db = db();
$action = $_GET['action'] ?? jsonInput()['action'] ?? '';

function allowedMethods() {
    $m = getSetting('wallet', 'withdrawal_methods', ['UPI', 'BANK_TRANSFER']);
    return array_values(array_intersect(is_array($m) ? $m : ['UPI', 'BANK_TRANSFER'], ['UPI', 'BANK_TRANSFER']));
}

/** Mask account details for display (full details are only visible to admins). */
function maskDetails($method, $enc) {
    $d = json_decode((string)Security::decrypt($enc), true) ?: [];
    if ($method === 'UPI') {
        $u = $d['upi_id'] ?? '';
        $at = strpos($u, '@');
        return $at > 2 ? substr($u, 0, 2) . str_repeat('•', $at - 2) . substr($u, $at) : $u;
    }
    $acc = $d['account_number'] ?? '';
    return ($d['ifsc'] ?? '') . ' ••••' . substr($acc, -4);
}

switch ($action) {
    case 'summary':
        $w = $db->fetchOne("SELECT balance, lifetime_earned, lifetime_withdrawn, pending_withdrawal FROM wallets WHERE user_id = ?", [$userId])
            ?: ['balance' => '0.00', 'lifetime_earned' => '0.00', 'lifetime_withdrawn' => '0.00', 'pending_withdrawal' => '0.00'];
        jsonResponse(true, [
            'wallet' => $w,
            'withdrawal' => [
                'enabled' => (bool)getSetting('wallet', 'withdrawals_enabled', true) && allowedMethods(),
                'min' => (string)getSetting('wallet', 'min_withdrawal', '100.00'),
                'max_daily' => (string)getSetting('wallet', 'max_withdrawal_daily', '10000.00'),
                'fee_percent' => (string)getSetting('wallet', 'withdrawal_fee_percent', '0'),
                'fee_fixed' => (string)getSetting('wallet', 'withdrawal_fee_fixed', '0.00'),
                'methods' => allowedMethods(),
            ],
        ]);
        break;

    case 'transactions':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $r = paginate(
            "SELECT id, type, amount, balance_after, description, created_at FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC",
            [$userId], $page, 20
        );
        jsonResponse(true, $r);
        break;

    case 'withdrawals':
        $rows = $db->fetchAll(
            "SELECT id, amount, fee, net_amount, method, account_details, status, rejection_reason, transaction_id, created_at, processed_at
             FROM withdrawals WHERE user_id = ? ORDER BY id DESC LIMIT 30",
            [$userId]
        );
        foreach ($rows as &$r) {
            $r['account'] = maskDetails($r['method'], $r['account_details']);
            unset($r['account_details']);
        }
        jsonResponse(true, ['withdrawals' => $rows]);
        break;

    case 'withdraw':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, ['code' => 'METHOD_NOT_ALLOWED'], 'Method not allowed', 405);
        }
        if (!getSetting('wallet', 'withdrawals_enabled', true)) {
            jsonResponse(false, ['code' => 'DISABLED'], 'Withdrawals are paused right now.');
        }
        $rate = Security::checkRateLimit('withdraw_' . $userId, 10, 3600);
        if (!$rate['allowed']) {
            jsonResponse(false, ['code' => 'RATE_LIMITED'], 'Too many attempts. Try again later.', 429);
        }
        $in = jsonInput();
        $method = (string)($in['method'] ?? '');
        if (!in_array($method, allowedMethods(), true)) {
            jsonResponse(false, ['code' => 'INVALID_METHOD'], 'Choose a withdrawal method');
        }
        if ($method === 'UPI') {
            $upi = trim((string)($in['upi_id'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9._-]{2,256}@[A-Za-z]{2,64}$/', $upi)) {
                jsonResponse(false, ['code' => 'INVALID_UPI'], 'Enter a valid UPI ID (e.g. name@okaxis)');
            }
            $details = ['upi_id' => $upi];
        } else {
            $name = trim((string)($in['account_name'] ?? ''));
            $acc = preg_replace('/\s+/', '', (string)($in['account_number'] ?? ''));
            $ifsc = strtoupper(trim((string)($in['ifsc'] ?? '')));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || !preg_match('/^\d{9,18}$/', $acc) || !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
                jsonResponse(false, ['code' => 'INVALID_BANK'], 'Check account holder name, account number (9–18 digits) and IFSC (e.g. SBIN0001234)');
            }
            $details = ['account_name' => $name, 'account_number' => $acc, 'ifsc' => $ifsc];
        }
        $amount = trim((string)($in['amount'] ?? ''));
        if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $amount)) {
            jsonResponse(false, ['code' => 'INVALID_AMOUNT'], 'Enter a valid amount');
        }
        $res = Wallet::requestWithdrawal($userId, $amount, $method, $details);
        if (!$res['success']) {
            jsonResponse(false, ['code' => 'WITHDRAW_FAILED'], $res['error']);
        }
        jsonResponse(true, $res, 'Withdrawal request submitted');
        break;

    case 'cancel_withdrawal':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, ['code' => 'METHOD_NOT_ALLOWED'], 'Method not allowed', 405);
        }
        $res = Wallet::processWithdrawal((int)(jsonInput()['id'] ?? 0), null, 'CANCELLED', null, 'Cancelled by user', $userId);
        if (!$res['success']) {
            jsonResponse(false, ['code' => 'CANCEL_FAILED'], $res['error']);
        }
        jsonResponse(true, null, 'Withdrawal cancelled and amount returned to your wallet');
        break;

    default:
        jsonResponse(false, ['code' => 'INVALID_ACTION'], 'Invalid action');
}
