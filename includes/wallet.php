<?php
/**
 * Wallet, rewards, referrals and withdrawals.
 *
 * Rules enforced here (all server-side):
 *  - Every balance change goes through addWalletTransaction() (ledger + balance in one tx).
 *  - Each money operation locks the user's wallet row first, so limits/cooldowns/balance
 *    checks cannot be raced by parallel requests.
 *  - Rewards are idempotent: the reference_id is derived from the provider's / domain's
 *    unique id and is UNIQUE in both reward_transactions and wallet_transactions.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/functions.php';

class Wallet {

    /**
     * Rewarded ads are only available when a provider with SERVER-SIDE verification
     * (signed server-to-server callback) is configured. No such provider adapter
     * ships with this codebase, so this returns false and the Earn button stays hidden.
     * See README → "Rewarded ads" for how to add one.
     */
    public static function rewardedAdsAvailable(): bool {
        return (bool)getSetting('ads', 'rewarded_ads_enabled', false)
            && getSetting('ads', 'rewarded_provider', '') !== ''
            && class_exists('RewardedAdProviderVerifier');
    }

    /**
     * Credit a rewarded-ad reward. MUST only be called from a provider callback endpoint
     * AFTER that endpoint has verified the provider's signature. Never call this from a
     * request initiated by the Mini App frontend.
     *
     * @param string $providerTxId  Unique transaction id issued by the ad provider.
     */
    public static function creditVerifiedAdReward(int $userId, string $provider, string $providerTxId, array $callbackData = []) {
        $db = db();
        $referenceId = 'AD_' . substr(hash('sha256', $provider . ':' . $providerTxId), 0, 40);

        $rule = $db->fetchOne("SELECT * FROM reward_rules WHERE reward_type = 'WATCH_AD' AND enabled = 1");
        if (!$rule) {
            return ['success' => false, 'error' => 'Reward not available'];
        }

        try {
            $db->beginTransaction();
            lockWallet($userId);

            // Idempotency: provider retries / replays of the same callback.
            if ($db->fetchOne("SELECT id FROM reward_transactions WHERE reference_id = ?", [$referenceId])) {
                $db->rollBack();
                return ['success' => true, 'duplicate' => true];
            }

            if ($rule['daily_limit'] > 0) {
                $today = $db->fetchOne(
                    "SELECT COUNT(*) AS c FROM reward_transactions
                     WHERE user_id = ? AND reward_type = 'WATCH_AD' AND status = 'COMPLETED'
                     AND created_at >= CURDATE()",
                    [$userId]
                );
                if ($today['c'] >= $rule['daily_limit']) {
                    $db->rollBack();
                    return ['success' => false, 'error' => 'Daily reward limit reached'];
                }
            }

            if ($rule['cooldown_seconds'] > 0) {
                $recent = $db->fetchOne(
                    "SELECT id FROM reward_transactions
                     WHERE user_id = ? AND reward_type = 'WATCH_AD' AND status = 'COMPLETED'
                     AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND) LIMIT 1",
                    [$userId, (int)$rule['cooldown_seconds']]
                );
                if ($recent) {
                    $db->rollBack();
                    return ['success' => false, 'error' => 'Please wait before claiming next reward'];
                }
            }

            // Amount always comes from the server-side rule, never from the request.
            $db->execute(
                "INSERT INTO reward_transactions (user_id, reward_type, amount, reference_id, reference_type, reference_data, status, verified, verified_at)
                 VALUES (?, 'WATCH_AD', ?, ?, 'ad_provider', ?, 'COMPLETED', 1, NOW())",
                [$userId, $rule['amount'], $referenceId, json_encode(['provider' => $provider, 'tx' => $providerTxId] + $callbackData)]
            );

            $result = addWalletTransaction($userId, 'REWARD', $rule['amount'], 'Rewarded ad', $referenceId, 'reward');
            if (!$result['success']) {
                $db->rollBack();
                return $result;
            }

            createNotification($userId, 'reward_earned', 'Reward Earned!', "You earned ₹{$rule['amount']}", ['amount' => $rule['amount']]);
            $db->commit();
            return ['success' => true, 'amount' => $rule['amount'], 'balance' => $result['balance_after']];

        } catch (Exception $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->rollBack();
            }
            error_log("Reward processing error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to process reward'];
        }
    }

    /**
     * Pay the referral bonus for one referred user, if eligible. Idempotent.
     * Called by cron/referral-rewards.php, never by the frontend.
     */
    public static function processReferralReward(int $referrerId, int $referredId) {
        $db = db();
        $referenceId = 'REFERRAL_' . $referredId; // one bonus per referred user, ever

        $rewardAmount = (string)getSetting('referral', 'reward_amount', '10.00');
        $minWatchTime = (int)getSetting('referral', 'min_referred_watch_time', 300);

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
    public static function processWithdrawal(int $withdrawalId, int $adminId, string $status, $transactionId = null, $notes = null) {
        $db = db();
        $allowed = ['PROCESSING', 'PAID', 'REJECTED', 'CANCELLED'];
        if (!in_array($status, $allowed, true)) {
            return ['success' => false, 'error' => 'Invalid status'];
        }

        try {
            $db->beginTransaction();

            $withdrawal = $db->fetchOne("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE", [$withdrawalId]);
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
