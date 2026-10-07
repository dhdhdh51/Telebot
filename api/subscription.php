<?php
/**
 * Subscription API for the Mini App.
 *   GET  ?action=plans
 *   GET  ?action=status[&order_id=...]
 *   POST {action:"create_order", plan_id}
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/payments.php';

header('Content-Type: application/json');
Schema::migrate();

$userId = requireAppUser();
session_write_close();

$action = $_GET['action'] ?? jsonInput()['action'] ?? '';

switch ($action) {
    case 'plans':
        $plans = db()->fetchAll(
            "SELECT id, name, description, duration_days, price, currency, features, is_popular
             FROM subscription_plans WHERE status = 'ACTIVE' ORDER BY display_order, price"
        );
        foreach ($plans as &$p) {
            $p['features'] = json_decode((string)$p['features'], true) ?: [];
            $p['is_popular'] = (bool)$p['is_popular'];
        }
        jsonResponse(true, ['plans' => $plans, 'payments_enabled' => Payments::gateway() !== null]);
        break;

    case 'status':
        $sub = Subscription::active($userId);
        $order = null;
        if (!empty($_GET['order_id'])) {
            // Only the caller's own orders
            $order = db()->fetchOne(
                "SELECT order_id, status FROM payments WHERE order_id = ? AND user_id = ?",
                [(string)$_GET['order_id'], $userId]
            ) ?: null;
        }
        jsonResponse(true, [
            'is_premium' => $sub !== null,
            'subscription' => $sub ? ['plan_name' => $sub['plan_name'], 'end_date' => $sub['end_date']] : null,
            'order' => $order,
        ]);
        break;

    case 'create_order':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, ['code' => 'METHOD_NOT_ALLOWED'], 'Method not allowed', 405);
        }
        $rate = Security::checkRateLimit('order_' . $userId, 10, 3600);
        if (!$rate['allowed']) {
            jsonResponse(false, ['code' => 'RATE_LIMITED'], 'Too many attempts. Try again later.', 429);
        }
        $res = Payments::createOrder($userId, (int)(jsonInput()['plan_id'] ?? 0));
        if (!$res['success']) {
            jsonResponse(false, ['code' => 'ORDER_FAILED'], $res['error']);
        }
        jsonResponse(true, ['order_id' => $res['order_id'], 'pay_url' => $res['pay_url']]);
        break;

    default:
        jsonResponse(false, ['code' => 'INVALID_ACTION'], 'Invalid action');
}
