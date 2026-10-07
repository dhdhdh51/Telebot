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
 * To add Cashfree: implement PaymentGatewayInterface and register it in Payments::gateway().
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

/**
 * PayU India (hosted checkout).
 * Request hash:  sha512(key|txnid|amount|productinfo|firstname|email|udf1..udf5||||||SALT)
 * Response hash: sha512([additional_charges|]SALT|status||||||udf5..udf1|email|firstname|productinfo|amount|txnid|key)
 * After the hash check we ALSO ask PayU's verify_payment API (the source of truth).
 */
class PayuGateway implements PaymentGatewayInterface {
    private $key;
    private $salt;
    private $live;

    public function __construct() {
        $this->key = trim((string)getSetting('payment', 'payu_key', ''));
        $this->salt = trim((string)getSetting('payment', 'payu_salt', ''));
        $this->live = getSetting('payment', 'payu_mode', 'test') === 'live';
    }

    public function name(): string { return 'payu'; }

    public function isConfigured(): bool { return $this->key !== '' && $this->salt !== ''; }

    public function paymentUrl() {
        if (defined('PAYU_BASE')) return PAYU_BASE . '/_payment';
        return $this->live ? 'https://secure.payu.in/_payment' : 'https://test.payu.in/_payment';
    }

    private function verifyUrl() {
        if (defined('PAYU_BASE')) return PAYU_BASE . '/merchant/postservice?form=2';
        return $this->live ? 'https://info.payu.in/merchant/postservice?form=2' : 'https://test.payu.in/merchant/postservice?form=2';
    }

    /** txnid is generated by us; PayU allows up to 25 alphanumeric chars. */
    public function createOrder(int $amountPaise, string $currency, string $receipt, array $notes): array {
        return ['order_id' => 'BP' . strtoupper(bin2hex(random_bytes(9)))];
    }

    /** Fields for the auto-submitted form to PayU (hash computed server-side; salt never leaves the server). */
    public function checkoutFields(array $payment, $firstname, $email, $phone, $returnUrl) {
        $f = [
            'key' => $this->key,
            'txnid' => $payment['order_id'],
            'amount' => Security::fromPaise(Security::toPaise($payment['amount'])),
            'productinfo' => preg_replace('/[^A-Za-z0-9 _-]/', '', (string)($payment['plan_name'] ?? 'Premium')) ?: 'Premium',
            'firstname' => preg_replace('/[^A-Za-z ]/', '', $firstname) ?: 'User',
            'email' => $email,
            'phone' => $phone,
            'udf1' => (string)$payment['user_id'], 'udf2' => '', 'udf3' => '', 'udf4' => '', 'udf5' => '',
            'surl' => $returnUrl,
            'furl' => $returnUrl,
        ];
        $f['hash'] = hash('sha512', implode('|', [$f['key'], $f['txnid'], $f['amount'], $f['productinfo'], $f['firstname'], $f['email'],
            $f['udf1'], $f['udf2'], $f['udf3'], $f['udf4'], $f['udf5'], '', '', '', '', '', $this->salt]));
        return $f;
    }

    private function reverseHash(array $d) {
        $parts = [$this->salt, $d['status'] ?? '', '', '', '', '', '',
            $d['udf5'] ?? '', $d['udf4'] ?? '', $d['udf3'] ?? '', $d['udf2'] ?? '', $d['udf1'] ?? '',
            $d['email'] ?? '', $d['firstname'] ?? '', $d['productinfo'] ?? '', $d['amount'] ?? '', $d['txnid'] ?? '', $this->key];
        if (isset($d['additional_charges']) && $d['additional_charges'] !== '') {
            array_unshift($parts, $d['additional_charges']);
        }
        return hash('sha512', implode('|', $parts));
    }

    /** Ask PayU for the real status of a txnid. */
    private function verifyApi($txnid) {
        $cmd = 'verify_payment';
        $ch = curl_init($this->verifyUrl());
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(['key' => $this->key, 'command' => $cmd, 'var1' => $txnid,
                'hash' => hash('sha512', $this->key . '|' . $cmd . '|' . $txnid . '|' . $this->salt)]),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        $json = is_string($res) ? json_decode($res, true) : null;
        if ($err || !is_array($json)) {
            error_log('PayU verify_payment failed: ' . ($err ?: 'bad response'));
            return null;
        }
        return $json['transaction_details'][$txnid] ?? null;
    }

    public function verifyCheckout(array $data): array {
        $txnid = (string)($data['txnid'] ?? '');
        if ($txnid === '' || empty($data['hash'])) {
            return ['ok' => false, 'error' => 'Missing payment data'];
        }
        if (!hash_equals($this->reverseHash($data), strtolower((string)$data['hash']))) {
            return ['ok' => false, 'error' => 'Invalid payment hash'];
        }
        if (($data['status'] ?? '') !== 'success') {
            return ['ok' => false, 'error' => 'Payment ' . ($data['status'] ?? 'failed') . ': ' . ($data['error_Message'] ?? ''), 'failed' => true];
        }
        $t = $this->verifyApi($txnid);
        if (!$t || ($t['status'] ?? '') !== 'success') {
            return ['ok' => false, 'error' => 'PayU has not confirmed this payment (' . ($t['status'] ?? 'unreachable') . ')'];
        }
        return ['ok' => true, 'order_id' => $txnid, 'payment_id' => (string)($t['mihpayid'] ?? $data['mihpayid'] ?? ''),
                'amount_paise' => Security::toPaise(number_format((float)$t['amt'], 2, '.', '')), 'method' => $t['mode'] ?? null, 'raw' => $t];
    }

    public function handleWebhook(string $rawBody, array $headers): array {
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            parse_str($rawBody, $data);
        }
        if (empty($data['txnid']) || empty($data['hash'])) {
            return ['ok' => false, 'error' => 'Bad webhook', 'status' => 400];
        }
        $r = $this->verifyCheckout($data);
        if (!$r['ok']) {
            return !empty($r['failed']) ? ['ok' => false, 'ignore' => true] : $r + ['status' => 401];
        }
        return $r;
    }

    public function checkoutConfig(): array {
        return ['action' => $this->paymentUrl()];
    }
}

class Payments {

    /** Active gateway selected in Admin → Settings → Payment, or null. */
    public static function gateway(): ?PaymentGatewayInterface {
        $gw = self::gatewayByName((string)getSetting('payment', 'gateway', ''));
        return ($gw && $gw->isConfigured()) ? $gw : null;
    }

    public static function gatewayByName($name): ?PaymentGatewayInterface {
        switch ($name) {
            case 'razorpay': return new RazorpayGateway();
            case 'payu': return new PayuGateway();
        }
        return null;
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
