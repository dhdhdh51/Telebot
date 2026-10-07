<?php
/**
 * Checkout page, opened from the Mini App in the phone's browser (Telegram.WebApp.openLink)
 * so UPI app intents work. Identified by a signed order link, not by session.
 *
 * Razorpay: GET shows checkout.js; checkout POSTs JSON back here → verify → grant.
 * PayU:     GET asks for mobile → POST start → auto-submit form to PayU →
 *           PayU POSTs the result back here (surl/furl) → hash + verify_payment API → grant.
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
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$loadPayment = fn() => (preg_match('/^[A-Za-z0-9_]{6,64}$/', $orderId) && Payments::checkPayToken($orderId, $token))
    ? db()->fetchOne(
        "SELECT p.*, sp.name AS plan_name, sp.duration_days, u.first_name, u.telegram_user_id FROM payments p
         LEFT JOIN subscription_plans sp ON sp.id = p.plan_id JOIN users u ON u.id = p.user_id WHERE p.order_id = ?",
        [$orderId])
    : null;
$payment = $loadPayment();
$gw = $payment ? Payments::gatewayByName($payment['gateway']) : null;
$view = null;      // 'form' | 'success' | 'failed' | 'payu_redirect' | 'invalid'
$message = '';
$payuFields = null;

if (!$payment || !$gw || !$gw->isConfigured()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $payment && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        jsonResponse(false, ['code' => 'INVALID_ORDER'], 'Invalid order', 400);
    }
    $view = 'invalid';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $gw->name() === 'razorpay') {
    header('Content-Type: application/json');
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
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $gw->name() === 'payu') {
    if (($_POST['action'] ?? '') === 'start') {
        $phone = preg_replace('/\D/', '', (string)($_POST['phone'] ?? ''));
        if (strlen($phone) === 12 && strpos($phone, '91') === 0) $phone = substr($phone, 2);
        if (!preg_match('/^[6-9]\d{9}$/', $phone)) {
            $message = 'Enter your 10-digit mobile number.';
            $view = 'form';
        } elseif ($payment['status'] !== 'PENDING') {
            $view = $payment['status'] === 'SUCCESS' ? 'success' : 'invalid';
        } else {
            $host = parse_url(appUrl(), PHP_URL_HOST) ?: 'example.com';
            $payuFields = $gw->checkoutFields($payment, (string)$payment['first_name'],
                'user' . $payment['telegram_user_id'] . '@' . $host, $phone, Payments::payUrl($orderId));
            $view = 'payu_redirect';
        }
    } else {
        // PayU returning the result (surl / furl)
        if (($_POST['txnid'] ?? '') !== $orderId) {
            $view = 'invalid';
        } else {
            $verified = $gw->verifyCheckout($_POST);
            if ($verified['ok']) {
                $done = Payments::complete($verified);
                $view = $done['success'] ? 'success' : 'failed';
                $message = $done['success'] ? '' : 'Payment received but activation failed. Contact support with order ' . $orderId;
            } else {
                error_log('PayU return not verified for ' . $orderId . ': ' . $verified['error']);
                $view = 'failed';
                $message = !empty($verified['failed'])
                    ? 'Payment was not completed. You can try again.'
                    : 'We could not confirm the payment yet. If money was deducted, premium will activate automatically within a few minutes.';
            }
            $payment = $loadPayment();
        }
    }
}

if ($view === null) {
    $view = $payment['status'] === 'SUCCESS' ? 'success' : 'form';
}

$csp = ['script-src' => [], 'frame-src' => [], 'connect-src' => [], 'form-action' => []];
if ($gw && $gw->name() === 'razorpay') {
    $csp = ['script-src' => ['https://checkout.razorpay.com'], 'frame-src' => ['https://api.razorpay.com', 'https://checkout.razorpay.com'],
            'connect-src' => ['https://*.razorpay.com'], 'form-action' => ['https://api.razorpay.com']];
} elseif ($gw && $gw->name() === 'payu') {
    $csp['form-action'] = array_merge(['https://secure.payu.in', 'https://test.payu.in'], defined('PAYU_BASE') ? [PAYU_BASE] : []);
}
setCsp($csp, "'none'");
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
input{width:100%;box-sizing:border-box;padding:13px;border-radius:12px;border:1px solid #444;background:#111;color:#fff;font-size:17px;text-align:center;letter-spacing:1px}
</style>
</head>
<body>
<div class="card">
<?php if ($view === 'invalid'): ?>
    <h1>Invalid payment link</h1>
    <p class="muted">Please start again from the app.</p>
    <a class="btn back" href="<?= $e($backUrl) ?>">Back to Telegram</a>

<?php elseif ($view === 'success'): ?>
    <div class="ok">✔</div>
    <h1>Premium activated</h1>
    <p class="muted"><?= $e($payment['plan_name']) ?> · Order <?= $e($orderId) ?></p>
    <a class="btn pay" href="<?= $e($backUrl) ?>">Return to Telegram</a>

<?php elseif ($view === 'failed'): ?>
    <h1>Payment not completed</h1>
    <p class="muted"><?= $e($message) ?></p>
    <?php if ($payment && $payment['status'] === 'PENDING'): ?>
        <a class="btn pay" href="<?= $e(Payments::payUrl($orderId)) ?>">Try again</a>
    <?php endif; ?>
    <a class="btn back" href="<?= $e($backUrl) ?>">Back to Telegram</a>

<?php elseif ($view === 'payu_redirect'): ?>
    <h1>Redirecting to PayU…</h1>
    <p class="muted">Please wait.</p>
    <form id="payu" method="POST" action="<?= $e($gw->paymentUrl()) ?>">
        <?php foreach ($payuFields as $k => $v): ?><input type="hidden" name="<?= $e($k) ?>" value="<?= $e($v) ?>"><?php endforeach; ?>
        <button class="btn pay" type="submit">Continue to payment</button>
    </form>
    <script>document.getElementById('payu').submit();</script>

<?php elseif ($gw->name() === 'payu'): ?>
    <h1>💎 <?= $e($appName) ?> Premium</h1>
    <p class="muted"><?= $e($payment['plan_name']) ?> · <?= (int)$payment['duration_days'] ?> days</p>
    <div class="amount">₹<?= $e($payment['amount']) ?></div>
    <form method="POST" action="<?= $e(Payments::payUrl($orderId)) ?>">
        <input type="hidden" name="action" value="start">
        <input name="phone" inputmode="numeric" maxlength="13" placeholder="Mobile number" required value="<?= $e($_POST['phone'] ?? '') ?>">
        <?php if ($message): ?><div class="err"><?= $e($message) ?></div><?php endif; ?>
        <button class="btn pay" type="submit">Pay ₹<?= $e($payment['amount']) ?> with PayU</button>
    </form>
    <p class="muted" style="font-size:12px">UPI · Cards · Netbanking · Wallets</p>
    <a class="btn back" href="<?= $e($backUrl) ?>">Cancel</a>

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
