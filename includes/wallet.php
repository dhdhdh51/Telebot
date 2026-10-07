<?php
/**
 * Wallet, rewards, referrals and withdrawals.
 *
 * Rules enforced here (all server-side):
 *  - Every balance change goes through addWalletTransaction() (ledger + balance in one tx).
 *  - Each money operation locks the user's wallet row first, so limits/cooldowns/balance
 *    checks cannot be raced by parallel requests.
 *  - Rewards are idempotent: reference_id is UNIQUE in reward_transactions and wallet_transactions.
 *  - Ad rewards / check-in live in includes/rewards.php.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/functions.php';

class Wallet {

    /**
     * Pay the referral bonus for one referred user, if eligible. Idempotent.
     * Called by cron/referral-rewards.php, never by the frontend.
     */
    public static function processReferralReward(int $referrerId, int $referredId) {
        $db = db();
        $referenceId = 'REFERRAL_' . $referredId; // one bonus per referred user, ever

        $rewardAmount = (string)getSetting('referral', 'reward_amount', '10.00');
        $minWatchTime = (int)getSetting('referral', 'min_referred_watch_time', 300);
        if (Security::toPaise($rewardAmount) <= 0) {
            return ['success' => false, 'error' => 'Referral bonus is disabled'];
        }

        try {
            $db->beginTransaction();
            lockWallet($referrerId);

            $referral = $db->fetchOne(
                "SELECT * FROM referrals WHERE referrer_id = ? AND referred_id = ? FOR UPDATE",
                [$referrerId, $referredId]
            );
            if (!$referral || $referral['reward_paid']) {
                $db->rollBack();
                return ['success' => false, 'error' => 'No unpaid referral'];
            }

            $referred = $db->fetchOne("SELECT total_watch_time, status FROM users WHERE id = ?", [$referredId]);
            if (!$referred || $referred['status'] !== 'ACTIVE' || $referred['total_watch_time'] < $minWatchTime) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Eligibility criteria not met'];
            }

            $db->execute(
                "UPDATE referrals SET reward_earned = ?, reward_paid = 1, reward_paid_at = NOW() WHERE id = ?",
                [$rewardAmount, $referral['id']]
            );
            $db->execute(
                "INSERT INTO reward_transactions (user_id, reward_type, amount, reference_id, reference_type, reference_data, status, verified, verified_at)
                 VALUES (?, 'REFERRAL', ?, ?, 'referral', ?, 'COMPLETED', 1, NOW())",
                [$referrerId, $rewardAmount, $referenceId, json_encode(['referred_user_id' => $referredId])]
            );

            $result = addWalletTransaction($referrerId, 'REFERRAL', $rewardAmount, 'Referral bonus', $referenceId, 'referral');
            if (!$result['success']) {
                $db->rollBack();
                return $result;
            }

            createNotification($referrerId, 'referral_bonus', 'Referral Bonus!', "You earned ₹{$rewardAmount} referral bonus", ['amount' => $rewardAmount]);
            $db->commit();
            return ['success' => true, 'amount' => $rewardAmount, 'balance' => $result['balance_after']];

        } catch (Exception $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->rollBack();
            }
            error_log("Referral reward error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to process referral reward'];
        }
    }

    /**
     * Create a withdrawal request. The amount is debited immediately (held), and
     * refunded if an admin rejects/cancels it — so the balance is only ever reduced once.
     */
    public static function requestWithdrawal(int $userId, $amount, string $method, array $accountDetails) {
        $db = db();

        $allowedMethods = ['UPI', 'BANK_TRANSFER'];
        if (!in_array($method, $allowedMethods, true)) {
            return ['success' => false, 'error' => 'Invalid withdrawal method'];
        }

        try {
            $amountPaise = Security::toPaise($amount);
        } catch (InvalidArgumentException $e) {
            return ['success' => false, 'error' => 'Invalid amount'];
        }

        $minPaise = Security::toPaise(getSetting('wallet', 'min_withdrawal', '100.00'));
        $maxDailyPaise = Security::toPaise(getSetting('wallet', 'max_withdrawal_daily', '10000.00'));
        $feePercent = (string)getSetting('wallet', 'withdrawal_fee_percent', '0');
        $feeFixedPaise = Security::toPaise(getSetting('wallet', 'withdrawal_fee_fixed', '0.00'));

        if ($amountPaise < $minPaise) {
            return ['success' => false, 'error' => 'Minimum withdrawal amount is ₹' . Security::fromPaise($minPaise)];
        }

        // Fee in integer paise: percent is parsed as basis points (2.5% -> 250 bp), rounded up.
        $feeBp = Security::toPaise($feePercent); // "2.5" -> 250
        $feePaise = intdiv($amountPaise * $feeBp + 9999, 10000) + $feeFixedPaise;
        $netPaise = $amountPaise - $feePaise;
        if ($netPaise <= 0) {
            return ['success' => false, 'error' => 'Amount does not cover the withdrawal fee'];
        }

        try {
            $db->beginTransaction();
            $wallet = lockWallet($userId); // serialises concurrent requests for this user

            if (Security::toPaise($wallet['balance']) < $amountPaise) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Insufficient balance'];
            }

            $today = $db->fetchOne(
                "SELECT COALESCE(SUM(amount), 0) AS total FROM withdrawals
                 WHERE user_id = ? AND created_at >= CURDATE() AND status IN ('PENDING', 'PROCESSING', 'PAID')",
                [$userId]
            );
            if (Security::toPaise($today['total']) + $amountPaise > $maxDailyPaise) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Daily withdrawal limit exceeded'];
            }

            $db->execute(
                "INSERT INTO withdrawals (user_id, amount, fee, net_amount, method, account_details, status, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, 'PENDING', ?)",
                [
                    $userId,
                    Security::fromPaise($amountPaise),
                    Security::fromPaise($feePaise),
                    Security::fromPaise($netPaise),
                    $method,
                    Security::encrypt(json_encode($accountDetails)),
                    Security::getClientIP()
                ]
            );
            $withdrawalId = (int)$db->lastInsertId();

            $result = addWalletTransaction(
                $userId, 'WITHDRAWAL', Security::fromPaise(-$amountPaise),
                'Withdrawal request #' . $withdrawalId, 'WD_' . $withdrawalId, 'withdrawal'
            );
            if (!$result['success']) {
                $db->rollBack();
                return $result;
            }

            $db->execute(
                "UPDATE wallets SET pending_withdrawal = pending_withdrawal + ? WHERE user_id = ?",
                [Security::fromPaise($amountPaise), $userId]
            );

            createNotification($userId, 'withdrawal_requested', 'Withdrawal Request Submitted',
                'Your withdrawal request of ₹' . Security::fromPaise($amountPaise) . ' has been submitted',
                ['withdrawal_id' => $withdrawalId]);

            $db->commit();

            return [
                'success' => true,
                'withdrawal_id' => $withdrawalId,
                'amount' => Security::fromPaise($amountPaise),
                'fee' => Security::fromPaise($feePaise),
                'net_amount' => Security::fromPaise($netPaise),
                'balance' => $result['balance_after']
            ];

        } catch (Exception $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->rollBack();
            }
            error_log("Withdrawal request error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to process withdrawal request'];
        }
    }

    /**
     * Admin action. Transitions:
     *   PENDING    -> PROCESSING | PAID | REJECTED | CANCELLED
     *   PROCESSING -> PAID | REJECTED | CANCELLED
     * The row is locked so two admins clicking at once cannot refund twice.
     */
    public static function processWithdrawal(int $withdrawalId, ?int $adminId, string $status, $transactionId = null, $notes = null, ?int $onlyUserId = null) {
        $db = db();
        $allowed = ['PROCESSING', 'PAID', 'REJECTED', 'CANCELLED'];
        if (!in_array($status, $allowed, true)) {
            return ['success' => false, 'error' => 'Invalid status'];
        }

        try {
            $db->beginTransaction();

            $withdrawal = $db->fetchOne("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE", [$withdrawalId]);
            // User self-service: only their own, only CANCELLED, only while still PENDING.
            if ($onlyUserId !== null && (!$withdrawal || (int)$withdrawal['user_id'] !== $onlyUserId
                    || $status !== 'CANCELLED' || $withdrawal['status'] !== 'PENDING')) {
                $db->rollBack();
                return ['success' => false, 'error' => 'This withdrawal can no longer be cancelled'];
            }
            if (!$withdrawal || !in_array($withdrawal['status'], ['PENDING', 'PROCESSING'], true)
                || ($status === 'PROCESSING' && $withdrawal['status'] === 'PROCESSING')) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Withdrawal not found or already processed'];
            }

            $userId = (int)$withdrawal['user_id'];
            lockWallet($userId);

            $db->execute(
                "UPDATE withdrawals SET status = ?, processed_by = ?, processed_at = NOW(), transaction_id = ?, notes = ?,
                        rejection_reason = IF(? IN ('REJECTED','CANCELLED'), ?, rejection_reason)
                 WHERE id = ?",
                [$status, $adminId, $transactionId, $notes, $status, $notes, $withdrawalId]
            );

            if ($status !== 'PROCESSING') {
                // Final state: release the hold.
                $db->execute(
                    "UPDATE wallets SET pending_withdrawal = GREATEST(pending_withdrawal - ?, 0) WHERE user_id = ?",
                    [$withdrawal['amount'], $userId]
                );
            }

            if ($status === 'REJECTED' || $status === 'CANCELLED') {
                $refund = addWalletTransaction(
                    $userId, 'REFUND', $withdrawal['amount'], 'Withdrawal #' . $withdrawalId . ' refund',
                    'WDR_' . $withdrawalId, 'withdrawal_refund'
                );
                if (!$refund['success']) {
                    $db->rollBack();
                    return $refund;
                }
                createNotification($userId, 'withdrawal_rejected', 'Withdrawal Rejected',
                    'Your withdrawal request was rejected and the amount refunded to your wallet', ['withdrawal_id' => $withdrawalId]);
            } elseif ($status === 'PAID') {
                createNotification($userId, 'withdrawal_paid', 'Withdrawal Completed',
                    "Your withdrawal of ₹{$withdrawal['net_amount']} has been paid", ['withdrawal_id' => $withdrawalId]);
            }

            logAudit('WITHDRAWAL_PROCESSED', "Withdrawal #$withdrawalId -> $status", 'withdrawal', $withdrawalId,
                ['status' => $withdrawal['status']], ['status' => $status]);

            $db->commit();
            return ['success' => true];

        } catch (Exception $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->rollBack();
            }
            error_log("Withdrawal processing error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to process withdrawal'];
        }
    }
}
