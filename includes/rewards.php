<?php
/**
 * Earn system: daily check-in and rewarded ads (Adsgram).
 *
 * Verification modes (Admin → Rewards):
 *  - "server" (default, safest): money only when Adsgram's SERVER calls the reward URL.
 *    Adsgram enables this mainly for big publishers, so it may never arrive for small apps.
 *  - "sdk": the Adsgram SDK's "watched till the end" result, claimed via ad_claim. Still
 *    protected by: an intent created before the ad, minimum watch time measured on the
 *    server, one claim per intent, daily limit, cooldown, server-side amount. A scripted
 *    user could fake claims up to the daily limit, so keep the amount small.
 *
 * Server mode flow:
 *  1. User taps "Watch ad" → startAdIntent() creates a PENDING reward row (cooldown + daily limit checked).
 *  2. Adsgram shows the ad; when the user watched it fully, ADSGRAM'S SERVER calls
 *     /api/reward-callback.php?provider=adsgram&key=<secret>&userid=<telegram id>.
 *  3. completeAdIntent() turns the oldest open intent of that user (≤15 min old) into a
 *     ledger credit. One callback = one intent = one reward. No intent → nothing credited,
 *     so replayed / forged callbacks (without the secret) can't create money.
 */

require_once __DIR__ . '/functions.php';

class Rewards {
    const INTENT_TTL_MIN = 15;

    public static function rule($type) {
        return db()->fetchOne("SELECT * FROM reward_rules WHERE reward_type = ?", [$type]) ?: null;
    }

    public static function mode() {
        return getSetting('ads', 'reward_verification', 'server') === 'sdk' ? 'sdk' : 'server';
    }

    public static function minWatchSeconds() {
        return max(5, (int)getSetting('ads', 'reward_min_seconds', 15));
    }

    /** Why rewarded ads are (not) live – shown in Admin → Rewards. [[ok, text], ...] */
    public static function diagnose() {
        $rule = self::rule('WATCH_AD');
        $block = (string)getSetting('ads', 'adsgram_block_id', '');
        $out = [
            [(bool)getSetting('ads', 'rewarded_ads_enabled', false), '"Rewarded ads ON" is ticked'],
            [getSetting('ads', 'rewarded_provider', '') === 'adsgram', 'Provider is Adsgram'],
            [(bool)preg_match('/^\d+$/', $block), 'Adsgram Reward block ID is set (digits only, not int-… / task-…)'],
            [$rule && $rule['enabled'], '"Watch ad" rule enabled'],
            [$rule && Security::toPaise($rule['amount']) > 0, 'Reward amount is more than ₹0'],
        ];
        if (self::mode() === 'server') {
            $last = (int)getSetting('ads', 'last_callback_at', 0);
            $out[] = [$last > 0, $last ? 'Last reward callback from Adsgram: ' . date('d M Y H:i', $last)
                : 'No reward callback received from Adsgram yet. If users watch ads but get nothing, Adsgram is not calling your Reward URL → switch verification to "Adsgram SDK" below.'];
        }
        return $out;
    }

    /** Rewarded ads are offered only if fully configured. */
    public static function adsAvailable() {
        $rule = self::rule('WATCH_AD');
        return getSetting('ads', 'rewarded_ads_enabled', false)
            && getSetting('ads', 'rewarded_provider', '') === 'adsgram'
            && preg_match('/^\d+$/', (string)getSetting('ads', 'adsgram_block_id', ''))
            && (self::mode() === 'sdk' || (string)getSetting('ads', 'reward_callback_key', '') !== '')
            && $rule && $rule['enabled'] && Security::toPaise($rule['amount']) > 0;
    }

    public static function checkinAvailable() {
        $rule = self::rule('DAILY_CHECKIN');
        return $rule && $rule['enabled'] && Security::toPaise($rule['amount']) > 0;
    }

    /** Status for the Earn page. */
    public static function status($userId) {
        $db = db();
        $ad = self::rule('WATCH_AD');
        $chk = self::rule('DAILY_CHECKIN');
        $today = $db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) AS earned FROM reward_transactions
             WHERE user_id = ? AND status = 'COMPLETED' AND created_at >= CURDATE()",
            [$userId]
        );
        $adsToday = (int)$db->fetchOne(
            "SELECT COUNT(*) c FROM reward_transactions WHERE user_id = ? AND reward_type = 'WATCH_AD' AND status = 'COMPLETED' AND created_at >= CURDATE()",
            [$userId]
        )['c'];
        $last = $db->fetchOne(
            "SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) s FROM reward_transactions
             WHERE user_id = ? AND reward_type = 'WATCH_AD' AND status IN ('COMPLETED', 'PENDING')",
            [$userId]
        );
        $cooldown = $ad ? max(0, (int)$ad['cooldown_seconds'] - (int)($last['s'] ?? PHP_INT_MAX)) : 0;
        $checkedIn = (bool)$db->fetchOne(
            "SELECT id FROM reward_transactions WHERE reference_id = ?", ['CHK_' . $userId . '_' . date('Ymd')]
        );
        $wallet = $db->fetchOne("SELECT balance FROM wallets WHERE user_id = ?", [$userId]);

        $adsOk = self::adsAvailable();
        return [
            'balance' => $wallet['balance'] ?? '0.00',
            'earned_today' => $today['earned'],
            'ads' => $adsOk ? [
                'block_id' => (string)getSetting('ads', 'adsgram_block_id', ''),
                'amount' => $ad['amount'],
                'daily_limit' => (int)$ad['daily_limit'],
                'watched_today' => $adsToday,
                'remaining_today' => $ad['daily_limit'] > 0 ? max(0, (int)$ad['daily_limit'] - $adsToday) : null,
                'cooldown_seconds' => $last['s'] === null ? 0 : $cooldown,
                'mode' => self::mode(),
            ] : null,
            'checkin' => self::checkinAvailable() ? ['amount' => $chk['amount'], 'done_today' => $checkedIn] : null,
            'referral' => [
                'amount' => (string)getSetting('referral', 'reward_amount', '0.00'),
                'min_watch_minutes' => (int)ceil(getSetting('referral', 'min_referred_watch_time', 300) / 60),
            ],
        ];
    }

    /** Daily check-in: once per calendar day, idempotent by reference id. */
    public static function dailyCheckin($userId) {
        if (!self::checkinAvailable()) {
            return ['success' => false, 'error' => 'Daily check-in is not available'];
        }
        $rule = self::rule('DAILY_CHECKIN');
        $ref = 'CHK_' . $userId . '_' . date('Ymd');
        $db = db();
        try {
            $db->beginTransaction();
            lockWallet($userId);
            if ($db->fetchOne("SELECT id FROM reward_transactions WHERE reference_id = ?", [$ref])) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Already checked in today. Come back tomorrow!'];
            }
            $db->execute(
                "INSERT INTO reward_transactions (user_id, reward_type, amount, reference_id, reference_type, status, verified, verified_at)
                 VALUES (?, 'DAILY_CHECKIN', ?, ?, 'checkin', 'COMPLETED', 1, NOW())",
                [$userId, $rule['amount'], $ref]
            );
            $r = addWalletTransaction($userId, 'REWARD', $rule['amount'], 'Daily check-in', $ref, 'reward');
            if (!$r['success']) {
                $db->rollBack();
                return $r;
            }
            $db->commit();
            return ['success' => true, 'amount' => $rule['amount'], 'balance' => $r['balance_after']];
        } catch (Exception $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->rollBack();
            }
            error_log('Check-in error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Could not check in, please try again'];
        }
    }

    /** Step 1: user wants to watch an ad. */
    public static function startAdIntent($userId) {
        if (!self::adsAvailable()) {
            return ['success' => false, 'error' => 'Rewarded ads are not available right now'];
        }
        $rule = self::rule('WATCH_AD');
        $db = db();
        try {
            $db->beginTransaction();
            lockWallet($userId); // serialises intents per user

            if ($rule['daily_limit'] > 0) {
                $used = (int)$db->fetchOne(
                    "SELECT COUNT(*) c FROM reward_transactions WHERE user_id = ? AND reward_type = 'WATCH_AD'
                     AND created_at >= CURDATE()
                     AND (status = 'COMPLETED' OR (status = 'PENDING' AND created_at > DATE_SUB(NOW(), INTERVAL " . self::INTENT_TTL_MIN . " MINUTE)))",
                    [$userId]
                )['c'];
                if ($used >= $rule['daily_limit']) {
                    $db->rollBack();
                    return ['success' => false, 'error' => 'Daily limit reached. Come back tomorrow!'];
                }
            }
            if ($rule['cooldown_seconds'] > 0) {
                $recent = $db->fetchOne(
                    "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) s FROM reward_transactions
                     WHERE user_id = ? AND reward_type = 'WATCH_AD' AND status IN ('COMPLETED', 'PENDING')
                     AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND) ORDER BY id DESC LIMIT 1",
                    [$userId, (int)$rule['cooldown_seconds']]
                );
                if ($recent) {
                    $db->rollBack();
                    return ['success' => false, 'error' => 'Please wait ' . ((int)$rule['cooldown_seconds'] - (int)$recent['s']) . 's before the next ad',
                            'cooldown_seconds' => (int)$rule['cooldown_seconds'] - (int)$recent['s']];
                }
            }
            $ref = 'ADI_' . bin2hex(random_bytes(12));
            $db->execute(
                "INSERT INTO reward_transactions (user_id, reward_type, amount, reference_id, reference_type, status, verified)
                 VALUES (?, 'WATCH_AD', ?, ?, 'adsgram', 'PENDING', 0)",
                [$userId, $rule['amount'], $ref]
            );
            $db->commit();
            return ['success' => true, 'intent' => $ref];
        } catch (Exception $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->rollBack();
            }
            error_log('Ad intent error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Please try again'];
        }
    }

    /** Status of an intent owned by this user (for the Earn page to show "credited"). */
    public static function intentStatus($userId, $ref) {
        $row = db()->fetchOne(
            "SELECT status, amount, TIMESTAMPDIFF(MINUTE, created_at, NOW()) age FROM reward_transactions
             WHERE reference_id = ? AND user_id = ? AND reward_type = 'WATCH_AD'",
            [$ref, $userId]
        );
        if (!$row) {
            return null;
        }
        $status = $row['status'] === 'PENDING' && $row['age'] >= self::INTENT_TTL_MIN ? 'EXPIRED' : $row['status'];
        return ['status' => $status, 'amount' => $row['amount']];
    }

    /**
     * SDK mode: the app reports "watched till the end" for ITS OWN intent.
     * Server-side checks: intent belongs to user, still PENDING, not expired, and at least
     * minWatchSeconds() passed since it was created (measured with the DB clock).
     */
    public static function claimAdIntent($userId, $ref) {
        if (self::mode() !== 'sdk') {
            return ['success' => false, 'error' => 'Waiting for confirmation from the ad network', 'pending' => true];
        }
        $db = db();
        try {
            $db->beginTransaction();
            lockWallet($userId);
            $intent = $db->fetchOne(
                "SELECT *, TIMESTAMPDIFF(SECOND, created_at, NOW()) age FROM reward_transactions
                 WHERE reference_id = ? AND user_id = ? AND reward_type = 'WATCH_AD' FOR UPDATE",
                [(string)$ref, $userId]
            );
            if (!$intent || $intent['status'] !== 'PENDING') {
                $db->rollBack();
                return ['success' => false, 'error' => $intent && $intent['status'] === 'COMPLETED' ? 'Already credited' : 'Ad view not found'];
            }
            if ($intent['age'] > self::INTENT_TTL_MIN * 60) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Ad view expired, please watch again'];
            }
            if ($intent['age'] < self::minWatchSeconds()) {
                $db->execute("UPDATE reward_transactions SET status = 'FAILED' WHERE id = ?", [$intent['id']]);
                $db->commit();
                return ['success' => false, 'error' => 'Ad finished too quickly – no reward'];
            }
            $db->execute(
                "UPDATE reward_transactions SET status = 'COMPLETED', verified = 1, verified_at = NOW(), reference_data = ? WHERE id = ?",
                [json_encode(['provider' => 'adsgram', 'mode' => 'sdk', 'seconds' => (int)$intent['age'], 'ip' => Security::getClientIP()]), $intent['id']]
            );
            $r = addWalletTransaction($userId, 'REWARD', $intent['amount'], 'Watched rewarded ad', $intent['reference_id'], 'reward');
            if (!$r['success']) {
                $db->rollBack();
                return $r;
            }
            $db->commit();
            return ['success' => true, 'amount' => $intent['amount'], 'balance' => $r['balance_after']];
        } catch (Exception $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->rollBack();
            }
            error_log('Ad claim error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Please try again'];
        }
    }

    /** Step 3: called ONLY by the provider's server callback (after key check). */
    public static function completeAdIntent($telegramUserId) {
        $db = db();
        $user = $db->fetchOne("SELECT id, status FROM users WHERE telegram_user_id = ?", [$telegramUserId]);
        if (!$user || $user['status'] !== 'ACTIVE') {
            return ['success' => false, 'error' => 'Unknown or inactive user'];
        }
        $userId = (int)$user['id'];
        try {
            $db->beginTransaction();
            lockWallet($userId);
            $intent = $db->fetchOne(
                "SELECT * FROM reward_transactions WHERE user_id = ? AND reward_type = 'WATCH_AD' AND status = 'PENDING'
                 AND created_at > DATE_SUB(NOW(), INTERVAL " . self::INTENT_TTL_MIN . " MINUTE)
                 ORDER BY id ASC LIMIT 1 FOR UPDATE",
                [$userId]
            );
            if (!$intent) {
                $db->rollBack();
                return ['success' => false, 'error' => 'No open ad view for this user'];
            }
            $db->execute(
                "UPDATE reward_transactions SET status = 'COMPLETED', verified = 1, verified_at = NOW(), reference_data = ? WHERE id = ?",
                [json_encode(['provider' => 'adsgram', 'ip' => Security::getClientIP()]), $intent['id']]
            );
            $r = addWalletTransaction($userId, 'REWARD', $intent['amount'], 'Watched rewarded ad', $intent['reference_id'], 'reward');
            if (!$r['success']) {
                $db->rollBack();
                return $r;
            }
            $db->execute("UPDATE ad_impressions SET rewarded = 1 WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$userId]);
            $db->commit();
            return ['success' => true, 'amount' => $intent['amount'], 'balance' => $r['balance_after']];
        } catch (Exception $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->rollBack();
            }
            error_log('Ad reward completion error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Server error'];
        }
    }
}
