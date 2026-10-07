<?php
/**
 * Payment gateway webhook (server-to-server).
 * Razorpay Dashboard → Settings → Webhooks:
 *   URL:    https://<your-domain>/api/payment-webhook.php?gateway=razorpay
 *   Secret: same value as Admin → Settings → Payment → Webhook secret
 *   Events: payment.captured, order.paid
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/payments.php';

header('Content-Type: application/json');
Schema::migrate();

$gw = Payments::gatewayByName((string)($_GET['gateway'] ?? ''));
if (!$gw || !$gw->isConfigured()) {
    http_response_code(404);
    exit('{}');
}

$headers = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
if (!$headers) {
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
        }
    }
}

$result = $gw->handleWebhook(file_get_contents('php://input'), $headers);
if (!$result['ok']) {
    if (!empty($result['ignore'])) {
        exit('{"ok":true}');
    }
    error_log('Payment webhook rejected: ' . ($result['error'] ?? ''));
    http_response_code($result['status'] ?? 400);
    exit('{"ok":false}');
}

$done = Payments::complete($result);
// Unknown orders etc. are acknowledged (200) so the gateway stops retrying; errors are logged.
if (!$done['success']) {
    error_log('Payment webhook completion failed for ' . $result['order_id'] . ': ' . $done['error']);
    if ($done['error'] === 'Could not activate subscription') {
        http_response_code(500); // transient DB error → let the gateway retry
        exit('{"ok":false}');
    }
}
echo '{"ok":true}';
