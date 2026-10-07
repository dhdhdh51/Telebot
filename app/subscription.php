<?php require_once __DIR__ . '/includes/layout.php'; appHead('Premium'); ?>

        <div class="page-title">💎 Go Premium</div>

        <div id="current" class="card" style="display:none"></div>

        <div class="card">
            <div style="font-weight:700;margin-bottom:6px">Premium benefits</div>
            <div class="muted" style="line-height:1.8">✅ No ads · ✅ All premium videos · ✅ Renew anytime — days are added on top</div>
        </div>

        <div id="plans"><div class="loading"><div class="spinner"></div>Loading plans…</div></div>

        <div id="waiting" class="card" style="display:none;text-align:center">
            <div class="spinner"></div>
            <div style="font-weight:700;margin-bottom:6px">Complete the payment in your browser</div>
            <div class="muted" style="margin-bottom:14px">This page updates automatically once the payment is confirmed.</div>
            <button class="btn-main btn-ghost" id="checkNow">I've paid — check now</button>
            <button class="btn-main btn-ghost" id="reopen" style="margin-top:8px">Open payment page again</button>
        </div>

<?php appFoot('subscription'); ?>
<script>
(function () {
    let orderId = null, payUrl = null, poll = null;

    function fmtDate(s) {
        const d = new Date(s.replace(' ', 'T'));
        return isNaN(d) ? s : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function showCurrent(sub) {
        const box = document.getElementById('current');
        if (!sub) { box.style.display = 'none'; return; }
        box.innerHTML = '<div style="font-weight:700">✅ You are Premium</div><div class="muted">'
            + BP.escapeHtml(sub.plan_name) + ' · valid till ' + BP.escapeHtml(fmtDate(sub.end_date))
            + '</div><div class="muted" style="margin-top:4px">Buying again adds days to your current plan.</div>';
        box.style.display = 'block';
        document.getElementById('premiumBadge').style.display = 'inline-block';
    }

    async function load() {
        try { await BP.ensureAuth(); } catch (e) { return BP.showError('errorContainer', e.message); }
        const [plans, status] = await Promise.all([
            BP.api('/api/subscription.php?action=plans'),
            BP.api('/api/subscription.php?action=status')
        ]);
        if (status.success) showCurrent(status.data.subscription);
        const box = document.getElementById('plans');
        if (!plans.success) { box.innerHTML = ''; return BP.showError('errorContainer', plans.error.message); }
        if (!plans.data.plans.length) {
            box.innerHTML = '<div class="empty"><div class="big">💎</div>No plans available right now.</div>';
            return;
        }
        box.innerHTML = '';
        plans.data.plans.forEach(p => {
            const card = document.createElement('div');
            card.className = 'card plan' + (p.is_popular ? ' popular' : '');
            card.innerHTML = (p.is_popular ? '<div class="tag">POPULAR</div>' : '')
                + '<div style="font-weight:700;font-size:17px">' + BP.escapeHtml(p.name) + '</div>'
                + '<div class="price">₹' + BP.escapeHtml(p.price) + ' <span class="muted">/ ' + Number(p.duration_days) + ' days</span></div>'
                + (p.description ? '<div class="muted">' + BP.escapeHtml(p.description) + '</div>' : '')
                + (p.features.length ? '<ul>' + p.features.map(f => '<li>' + BP.escapeHtml(f) + '</li>').join('') + '</ul>' : '');
            const btn = document.createElement('button');
            btn.className = 'btn-main btn-premium';
            btn.textContent = plans.data.payments_enabled ? 'Buy ' + p.name : 'Payments coming soon';
            btn.disabled = !plans.data.payments_enabled;
            btn.addEventListener('click', () => buy(p.id, btn));
            card.appendChild(btn);
            box.appendChild(card);
        });
        if (!plans.data.payments_enabled) {
            const n = document.createElement('div');
            n.className = 'muted'; n.style.textAlign = 'center';
            n.textContent = 'Online payment is not enabled yet. Contact the admin to activate premium.';
            box.appendChild(n);
        }
    }

    async function buy(planId, btn) {
        btn.disabled = true;
        const old = btn.textContent;
        btn.textContent = 'Please wait…';
        const res = await BP.post('/api/subscription.php', { action: 'create_order', plan_id: planId });
        btn.disabled = false;
        btn.textContent = old;
        if (!res.success) return BP.showError('errorContainer', res.error.message);
        orderId = res.data.order_id;
        payUrl = res.data.pay_url;
        openPayment();
        document.getElementById('waiting').style.display = 'block';
        document.getElementById('waiting').scrollIntoView({ behavior: 'smooth' });
        startPolling();
    }

    // Opens in the phone browser so UPI apps (GPay/PhonePe/Paytm) can be launched.
    function openPayment() {
        if (BP.tg && BP.tg.openLink) BP.tg.openLink(payUrl);
        else window.open(payUrl, '_blank');
    }

    async function check() {
        if (!orderId) return;
        const res = await BP.api('/api/subscription.php?action=status&order_id=' + encodeURIComponent(orderId));
        if (res.success && res.data.order && res.data.order.status === 'SUCCESS') {
            clearInterval(poll);
            document.getElementById('waiting').innerHTML = '<div style="font-size:42px">🎉</div><div style="font-weight:800;font-size:18px">Premium activated!</div><div class="muted">Enjoy ad-free premium videos.</div>';
            showCurrent(res.data.subscription);
            if (BP.tg && BP.tg.HapticFeedback) BP.tg.HapticFeedback.notificationOccurred('success');
        }
    }

    function startPolling() {
        clearInterval(poll);
        let n = 0;
        poll = setInterval(() => { if (++n > 200) clearInterval(poll); check(); }, 4000);
    }

    document.getElementById('checkNow').addEventListener('click', check);
    document.getElementById('reopen').addEventListener('click', openPayment);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') check(); });
    load();
})();
</script>
</body>
</html>
