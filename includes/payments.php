<?php
/**
 * Payment gateway abstraction.
 *
 * Flow: Mini App → api/subscription.php (create_order, amount from DB plan)
 *       → pay.php (gateway checkout, opened in the phone browser so UPI apps work)
 *       → gateway callback / webhook → signature verified + payment fetched server-side
 *       → Payments::complete() → Subscription::grant().
 * The frontend saying "paid" never activates anything.
 *
 * To add Cashfree/PayU: implement PaymentGatewayInterface and register it in Payments::gateway().
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/subscription.php';

interface PaymentGatewayInterface {
    public function name(): string;
    public function isConfigured(): bool;
    /** @return array ['order_id' => gateway order id] or ['error' => msg] */
    public function createOrder(int $amountPaise, string $currency, string $receipt, array $notes): array;
    /**
     * Verify a checkout callback and confirm with the gateway API that the money was captured.
     * @return array ['ok'=>true,'order_id','payment_id','amount_paise','raw'] or ['ok'=>false,'error']
     */
    public function verifyCheckout(array $data): array;
    /** Verify a webhook. @return array same shape as verifyCheckout, or ['ok'=>false,'ignore'=>true] */
    public function handleWebhook(string $rawBody, array $headers): array;
    /** Data the checkout page needs (public key etc.). Never include secrets. */
    public function checkoutConfig(): array;
}

class RazorpayGateway implements PaymentGatewayInterface {
    private $keyId;
    private $keySecret;
    private $webhookSecret;
    private $base;

    public function __construct() {
        $this->keyId = trim((string)getSetting('payment', 'razorpay_key_id', ''));
        $this->keySecret = trim((string)getSetting('payment', 'razorpay_key_secret', ''));
        $this->webhookSecret = trim((string)getSetting('payment', 'razorpay_webhook_secret', ''));
        $this->base = defined('RAZORPAY_API_BASE') ? RAZORPAY_API_BASE : 'https://api.razorpay.com/v1';
    }

    public function name(): string { return 'razorpay'; }

    public function isConfigured(): bool {
        return $this->keyId !== '' && $this->keySecret !== '';
    }

    private function call($method, $path, $body = null) {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => $this->keyId . ':' . $this->keySecret,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $json = is_string($res) ? json_decode($res, true) : null;
        if ($err || !is_array($json) || $code >= 400) {
            $msg = $err ?: ($json['error']['description'] ?? "HTTP $code");
            error_log("Razorpay $method $path failed: $msg");
            return ['__error' => $msg];
        }
        return $json;
    }

    public function createOrder(int $amountPaise, string $currency, string $receipt, array $notes): array {
        $r = $this->call('POST', '/orders', [
            'amount' => $amountPaise, 'currency' => $currency, 'receipt' => substr($receipt, 0, 40),
            'notes' => array_map('strval', $notes),
        ]);
        if (isset($r['__error'])) {
            return ['error' => $r['__error']];
        }
        return ['order_id' => $r['id']];
    }

    /** Fetch the payment from Razorpay and make sure it is captured for this order. */
    private function confirmPayment($paymentId, $orderId) {
        $p = $this->call('GET', '/payments/' . rawurlencode($paymentId));
        if (isset($p['__error'])) {
            return ['ok' => false, 'error' => 'Could not confirm payment: ' . $p['__error']];
        }
        if (($p['order_id'] ?? '') !== $orderId) {
            return ['ok' => false, 'error' => 'Payment does not belong to this order'];
        }
        if (($p['status'] ?? '') === 'authorized') {
            // Auto-capture is off in this Razorpay account: capture now.
            $p = $this->call('POST', '/payments/' . rawurlencode($paymentId) . '/capture',
                ['amount' => (int)$p['amount'], 'currency' => $p['currency']]);
            if (isset($p['__error'])) {
                return ['ok' => false, 'error' => 'Capture failed: ' . $p['__error']];
            }
        }
        if (($p['status'] ?? '') !== 'captured') {
            return ['ok' => false, 'error' => 'Payment status is ' . ($p['status'] ?? 'unknown')];
        }
        return ['ok' => true, 'order_id' => $orderId, 'payment_id' => $paymentId,
                'amount_paise' => (int)$p['amount'], 'method' => $p['method'] ?? null, 'raw' => $p];
    }

    public function verifyCheckout(array $data): array {
        $orderId = (string)($data['razorpay_order_id'] ?? '');
        $paymentId = (string)($data['razorpay_payment_id'] ?? '');
        $signature = (string)($data['razorpay_signature'] ?? '');
        if ($orderId === '' || $paymentId === '' || $signature === '') {
            return ['ok' => false, 'error' => 'Missing payment data'];
        }
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->keySecret);
        if (!hash_equals($expected, $signature)) {
            return ['ok' => false, 'error' => 'Invalid payment signature'];
        }
        return $this->confirmPayment($paymentId, $orderId);
    }

    public function handleWebhook(string $rawBody, array $headers): array {
        $sig = $headers['x-razorpay-signature'] ?? '';
        if ($this->webhookSecret === '' || $sig === ''
            || !hash_equals(hash_hmac('sha256', $rawBody, $this->webhookSecret), $sig)) {
            return ['ok' => false, 'error' => 'Invalid webhook signature', 'status' => 401];
        }
        $event = json_decode($rawBody, true);
        if (!in_array($event['event'] ?? '', ['payment.captured', 'order.paid'], true)) {
            return ['ok' => false, 'ignore' => true];
        }
        $p = $event['payload']['payment']['entity'] ?? null;
        if (!$p || ($p['status'] ?? '') !== 'captured' || empty($p['order_id'])) {
            return ['ok' => false, 'ignore' => true];
        }
        return ['ok' => true, 'order_id' => $p['order_id'], 'payment_id' => $p['id'],
                'amount_paise' => (int)$p['amount'], 'method' => $p['method'] ?? null, 'raw' => $p];
    }

    public function checkoutConfig(): array {
        return ['key' => $this->keyId];
    }
}

class Payments {

    /** Active gateway selected in Admin → Settings → Payment, or null. */
    public static function gateway(): ?PaymentGatewayInterface {
        $name = (string)getSetting('payment', 'gateway', '');
        $gw = null;
        if ($name === 'razorpay') {
            $gw = new RazorpayGateway();
        }
        return ($gw && $gw->isConfigured()) ? $gw : null;
    }

    public static function gatewayByName($name): ?PaymentGatewayInterface {
        return $name === 'razorpay' ? new RazorpayGateway() : null;
    }

    /** Signed link to the checkout page for an order (no session needed in the browser). */
    public static function payUrl($orderId) {
        $t = hash_hmac('sha256', 'pay|' . $orderId, SECRET_KEY);
        return appUrl() . '/pay.php?o=' . rawurlencode($orderId) . '&t=' . $t;
    }

    public static function checkPayToken($orderId, $token) {
        return is_string($token) && hash_equals(hash_hmac('sha256', 'pay|' . $orderId, SECRET_KEY), $token);
    }

    /** Create a payment row + gateway order for a plan. Price always comes from the DB. */
    public static function createOrder($userId, $planId) {
        $db = db();
        $gw = self::gateway();
        if (!$gw) {
            return ['success' => false, 'error' => 'Online payments are not enabled yet.'];
        }
        $plan = $db->fetchOne("SELECT * FROM subscription_plans WHERE id = ? AND status = 'ACTIVE'", [$planId]);
        if (!$plan) {
            return ['success' => false, 'error' => 'Plan not available'];
        }
        $amountPaise = Security::toPaise($plan['price']);
        if ($amountPaise < 100) {
            return ['success' => false, 'error' => 'Plan price must be at least ₹1'];
        }
        $receipt = 'BP' . $userId . 'T' . time();
        $order = $gw->createOrder($amountPaise, $plan['currency'] ?: 'INR', $receipt,
            ['user_id' => $userId, 'plan_id' => $plan['id']]);
        if (isset($order['error'])) {
            return ['success' => false, 'error' => 'Payment gateway error. Please try again later.'];
        }
        $db->execute(
            "INSERT INTO payments (user_id, plan_id, order_id, gateway, amount, currency, status, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, 'PENDING', ?, ?)",
            [$userId, $plan['id'], $order['order_id'], $gw->name(), $plan['price'], $plan['currency'] ?: 'INR',
             Security::getClientIP(), Security::getUserAgent()]
        );
        return ['success' => true, 'order_id' => $order['order_id'], 'pay_url' => self::payUrl($order['order_id'])];
    }

    /**
     * Mark a payment successful and grant the subscription. Idempotent: the checkout
     * callback and the webhook may both arrive; only the first one grants.
     */
    public static function complete(array $verified) {
        $db = db();
        try {
            $db->beginTransaction();
            $payment = $db->fetchOne("SELECT * FROM payments WHERE order_id = ? FOR UPDATE", [$verified['order_id']]);
            if (!$payment) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Unknown order'];
            }
            if ($payment['status'] === 'SUCCESS') {
                $db->commit();
                return ['success' => true, 'already' => true, 'user_id' => (int)$payment['user_id']];
            }
            if ((int)$verified['amount_paise'] !== Security::toPaise($payment['amount'])) {
                $db->execute("UPDATE payments SET status = 'FAILED', gateway_response = ? WHERE id = ?",
                    [json_encode(['error' => 'amount mismatch', 'raw' => $verified['raw'] ?? null]), $payment['id']]);
                $db->commit();
                error_log("Payment amount mismatch for order {$payment['order_id']}");
                return ['success' => false, 'error' => 'Amount mismatch'];
            }
            $plan = $db->fetchOne("SELECT * FROM subscription_plans WHERE id = ?", [$payment['plan_id']]);
            if (!$plan) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Plan not found'];
            }
            $db->execute(
                "UPDATE payments SET status = 'SUCCESS', payment_id = ?, payment_method = ?, verified = 1, verified_at = NOW(),
                        gateway_response = ? WHERE id = ?",
                [$verified['payment_id'], $verified['method'] ?? null, json_encode($verified['raw'] ?? null), $payment['id']]
            );
            Subscription::grant((int)$payment['user_id'], (int)$plan['id'], (int)$plan['duration_days'], (int)$payment['id']);
            $db->commit();
        } catch (Exception $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->rollBack();
            }
            error_log('Payment completion error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Could not activate subscription'];
        }

        $sub = Subscription::active((int)$payment['user_id']);
        Subscription::notify((int)$payment['user_id'],
            "✅ <b>Premium activated!</b>\nPlan: " . htmlspecialchars($plan['name']) .
            ($sub ? "\nValid till: " . date('d M Y', strtotime($sub['end_date'])) : ''));
        return ['success' => true, 'user_id' => (int)$payment['user_id']];
    }
}
