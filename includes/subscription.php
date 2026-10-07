<?php
/**
 * Subscriptions: grant / revoke / status. The only way a user becomes premium is
 * Subscription::grant(), called from a verified payment or an audited admin action.
 */

require_once __DIR__ . '/functions.php';

class Subscription {

    /** Active subscription (latest end date) with plan name, or null. */
    public static function active($userId) {
        return db()->fetchOne(
            "SELECT s.id, s.plan_id, s.start_date, s.end_date, sp.name AS plan_name
             FROM subscriptions s JOIN subscription_plans sp ON sp.id = s.plan_id
             WHERE s.user_id = ? AND s.status = 'ACTIVE' AND s.end_date > NOW()
             ORDER BY s.end_date DESC LIMIT 1",
            [$userId]
        ) ?: null;
    }

    /**
     * Add $days of premium. Renewals extend from the current end date, so buying
     * again before expiry never loses remaining days.
     * Works inside or outside a caller's transaction.
     */
    public static function grant($userId, $planId, $days, $paymentId = null) {
        $db = db();
        $pdo = $db->getConnection();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $db->beginTransaction();
        }
        try {
            // Serialise grants per user.
            $db->fetchOne("SELECT id FROM users WHERE id = ? FOR UPDATE", [$userId]);
            $row = $db->fetchOne(
                "SELECT GREATEST(NOW(), COALESCE(MAX(end_date), NOW())) AS base FROM subscriptions
                 WHERE user_id = ? AND status = 'ACTIVE' AND end_date > NOW()",
                [$userId]
            );
            $start = $row['base'];
            $db->execute(
                "INSERT INTO subscriptions (user_id, plan_id, payment_id, start_date, end_date, status)
                 VALUES (?, ?, ?, ?, DATE_ADD(?, INTERVAL ? DAY), 'ACTIVE')",
                [$userId, $planId, $paymentId, $start, $start, (int)$days]
            );
            $subId = (int)$db->lastInsertId();
            $db->execute("UPDATE users SET is_premium = 1 WHERE id = ?", [$userId]);
            if ($owns) {
                $db->commit();
            }
            return $subId;
        } catch (Exception $e) {
            if ($owns && $pdo->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** End all active subscriptions now (admin action). */
    public static function revoke($userId) {
        $db = db();
        $n = $db->execute(
            "UPDATE subscriptions SET status = 'CANCELLED', cancelled_at = NOW() WHERE user_id = ? AND status = 'ACTIVE'",
            [$userId]
        );
        $db->execute("UPDATE users SET is_premium = 0 WHERE id = ?", [$userId]);
        return $n;
    }

    /** Best-effort Telegram DM to the user (fails silently if they never started the bot). */
    public static function notify($userId, $text) {
        try {
            $u = db()->fetchOne("SELECT telegram_user_id FROM users WHERE id = ?", [$userId]);
            if ($u) {
                require_once __DIR__ . '/telegram.php';
                (new Telegram())->sendMessage($u['telegram_user_id'], $text);
            }
        } catch (Throwable $e) {
            error_log('Subscription notify failed: ' . $e->getMessage());
        }
    }
}
