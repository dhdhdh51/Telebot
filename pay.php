<?php
/**
 * Checkout page, opened from the Mini App in the phone's browser (Telegram.WebApp.openLink)
 * so that UPI app intents work. Identified by a signed order link, not by session.
 *
 * GET  pay.php?o=<order_id>&t=<hmac>          → shows the Razorpay checkout
 * POST pay.php?o=...&t=...  (JSON from checkout) → verifies signature + fetches payment → grants premium
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/payments.php';

Schema::migrate();
session_write_close();

$orderId = (string)($_GET['o'] ?? '');
$token = (string)($_GET['t'] ?? '');
$botUser = tgConf('bot_username');
$backUrl = $botUser !== '' ? 'https://t.me/' . rawurlencode($botUser) : appUrl();
$appName = (string)getSetting('general', 'app_name', APP_NAME);

$payment = (preg_match('/^[A-Za-z0-9_]{6,64}$/', $orderId) && Payments::checkPayToken($orderId, $token))
    ? db()->fetchOne(
        "SELECT p.*, sp.name AS plan_name, sp.duration_days FROM payments p
         LEFT JOIN subscription_plans sp ON sp.id = p.plan_id WHERE p.order_id = ?",
        [$orderId])
    : null;

$gw = $payment ? Payments::gatewayByName($payment['gateway']) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$payment || !$gw || !$gw->isConfigured()) {
        jsonResponse(false, ['code' => 'INVALID_ORDER'], 'Invalid order', 400);
    }
    $data = jsonInput();
    if (($data['razorpay_order_id'] ?? '') !== $orderId) {
        jsonResponse(false, ['code' => 'ORDER_MISMATCH'], 'Order mismatch', 400);
    }
    $verified = $gw->verifyCheckout($data);
    if (!$verified['ok']) {
        error_log('Checkout verification failed for ' . $orderId . ': ' . $verified['error']);
        jsonResponse(false, ['code' => 'VERIFY_FAILED'], 'Payment could not be verified. If money was deducted it will be confirmed automatically within a few minutes.');
    }
    $done = Payments::complete($verified);
    if (!$done['success']) {
        jsonResponse(false, ['code' => 'ACTIVATION_FAILED'], 'Payment received but activation failed. Contact support with order ' . $orderId);
    }
    jsonResponse(true, null, 'Premium activated');
}

setCsp([
    'script-src'  => ['https://checkout.razorpay.com'],
    'frame-src'   => ['https://api.razorpay.com', 'https://checkout.razorpay.com'],
    'connect-src' => ['https://*.razorpay.com'],
    'form-action' => ['https://api.razorpay.com'],
], "'none'");

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Payment – <?= $e($appName) ?></title>
<style>
body{font-family:-apple-system,system-ui,sans-serif;background:#0f0f14;color:#fff;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;box-sizing:border-box}
.card{background:#1c1c24;border-radius:16px;padding:28px;max-width:380px;width:100%;text-align:center}
h1{font-size:20px;margin:0 0 6px}.muted{color:#9aa0aa;font-size:14px}
.amount{font-size:36px;font-weight:800;margin:18px 0}
.btn{display:block;width:100%;padding:14px;border:0;border-radius:12px;font-size:16px;font-weight:700;cursor:pointer;margin-top:12px;text-decoration:none;box-sizing:border-box}
.pay{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff}.back{background:#2c2c36;color:#fff}
.ok{color:#2ecc71;font-size:48px}.err{color:#e74c3c;font-size:14px;margin-top:12px}
</style>
</head>
<body>
<div class="card">
<?php if (!$payment || !$gw): ?>
    <h1>Invalid payment link</h1>
    <p class="muted">Please start again from the app.</p>
    <a class="btn back" href="<?= $e($backUrl) ?>">Back to Telegram</a>
<?php elseif ($payment['status'] === 'SUCCESS'): ?>
    <div class="ok">✔</div>
    <h1>Premium activated</h1>
    <p class="muted"><?= $e($payment['plan_name']) ?> · Order <?= $e($orderId) ?></p>
    <a class="btn pay" href="<?= $e($backUrl) ?>">Return to Telegram</a>
<?php else: ?>
    <h1>💎 <?= $e($appName) ?> Premium</h1>
    <p class="muted"><?= $e($payment['plan_name']) ?> · <?= (int)$payment['duration_days'] ?> days</p>
    <div class="amount">₹<?= $e($payment['amount']) ?></div>
    <div id="state">
        <button class="btn pay" id="payBtn">Pay ₹<?= $e($payment['amount']) ?></button>
        <a class="btn back" href="<?= $e($backUrl) ?>">Cancel</a>
    </div>
    <div class="err" id="err"></div>
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <script>
    (function () {
        const cfg = <?= json_encode($gw->checkoutConfig() + [
            'order_id' => $orderId,
            'amount' => Security::toPaise($payment['amount']),
            'currency' => $payment['currency'],
            'name' => $appName,
            'description' => (string)$payment['plan_name'],
            'back' => $backUrl,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const err = document.getElementById('err');
        const state = document.getElementById('state');

        function done(ok, msg) {
            state.innerHTML = '';
            const h = document.createElement('h1');
            h.textContent = ok ? '✅ Premium activated' : 'Payment pending';
            const p = document.createElement('p');
            p.className = 'muted';
            p.textContent = msg;
            const a = document.createElement('a');
            a.className = 'btn pay';
            a.href = cfg.back;
            a.textContent = 'Return to Telegram';
            state.append(h, p, a);
        }

        function open() {
            err.textContent = '';
            const rzp = new Razorpay({
                key: cfg.key, order_id: cfg.order_id, amount: cfg.amount, currency: cfg.currency,
                name: cfg.name, description: cfg.description, theme: { color: '#667eea' },
                handler: function (resp) {
                    state.innerHTML = '<p class="muted">Verifying payment…</p>';
                    fetch(window.location.href, {
                        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(resp)
                    }).then(r => r.json()).then(j => {
                        done(j.success, j.success ? 'You can go back to Telegram and enjoy ad-free premium videos.' : (j.error ? j.error.message : 'Please wait a few minutes.'));
                    }).catch(() => done(false, 'Network problem. Your payment will be confirmed automatically if it succeeded.'));
                },
                modal: { ondismiss: function () { err.textContent = 'Payment cancelled. Tap Pay to try again.'; } }
            });
            rzp.on('payment.failed', function (r) {
                err.textContent = 'Payment failed: ' + ((r.error && r.error.description) || 'please try again');
            });
            rzp.open();
        }
        document.getElementById('payBtn').addEventListener('click', open);
        open();
    })();
    </script>
<?php endif; ?>
</div>
</body>
</html>
