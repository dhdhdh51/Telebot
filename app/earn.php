<?php
require_once __DIR__ . '/includes/layout.php';
// Adsgram SDK and its ad media/frames come from Adsgram's domains.
appHead('Earn', [
    'script-src' => ['https://sad.adsgram.ai'],
    'connect-src' => ['https:'],
    'frame-src' => ['https:'],
]);
?>

        <div class="page-title">💰 Earn Rewards</div>

        <div class="card" style="text-align:center">
            <div class="muted">Total balance</div>
            <div class="balance-big" id="bal">…</div>
            <div class="muted">Earned today: <b id="today">–</b></div>
            <a class="btn-main" href="/app/wallet.php" style="margin-top:12px">👛 Open wallet &amp; withdraw</a>
        </div>

        <div class="card earn-card" id="adCard" style="display:none">
            <div class="icon">📺</div>
            <div class="grow">
                <div style="font-weight:700">Watch ad &amp; earn <span id="adAmt"></span></div>
                <div class="muted" id="adInfo"></div>
            </div>
            <button class="btn-main btn-premium" id="adBtn">WATCH</button>
        </div>

        <div class="card earn-card" id="chkCard" style="display:none">
            <div class="icon">📅</div>
            <div class="grow">
                <div style="font-weight:700">Daily check-in <span id="chkAmt"></span></div>
                <div class="muted" id="chkInfo">Come back every day</div>
            </div>
            <button class="btn-main" id="chkBtn">CLAIM</button>
        </div>

        <div class="card earn-card">
            <div class="icon">🤝</div>
            <div class="grow">
                <div style="font-weight:700">Invite friends <span id="refAmt"></span></div>
                <div class="muted" id="refInfo"></div>
            </div>
            <a class="btn-main btn-ghost" href="/app/profile.php" style="width:auto;padding:10px 14px">Invite</a>
        </div>

        <div id="noEarn" class="empty" style="display:none"><div class="big">💤</div>No earning options are active right now.</div>

<?php appFoot('earn'); ?>
<script src="https://sad.adsgram.ai/js/sad.min.js" async></script>
<script>
(function () {
    const $ = (id) => document.getElementById(id);
    let st = null, cooldownTimer = null, adController = null;

    async function load() {
        const r = await BP.api('/api/rewards.php?action=status');
        if (!r.success) return BP.showError('errorContainer', r.error.message);
        st = r.data;
        $('bal').textContent = BP.money(st.balance);
        $('today').textContent = BP.money(st.earned_today);

        if (st.ads) {
            $('adCard').style.display = 'flex';
            $('adAmt').textContent = '+' + BP.money(st.ads.amount);
            updateAdButton(st.ads.cooldown_seconds);
        }
        if (st.checkin) {
            $('chkCard').style.display = 'flex';
            $('chkAmt').textContent = '+' + BP.money(st.checkin.amount);
            $('chkBtn').disabled = st.checkin.done_today;
            $('chkBtn').textContent = st.checkin.done_today ? 'DONE ✔' : 'CLAIM';
            $('chkInfo').textContent = st.checkin.done_today ? 'Come back tomorrow' : 'Claim your daily bonus';
        }
        $('refAmt').textContent = Number(st.referral.amount) > 0 ? '+' + BP.money(st.referral.amount) : '';
        $('refInfo').textContent = 'Invited ' + st.invited.total + ' · bonus after friend watches ' + st.referral.min_watch_minutes + ' min';
        $('noEarn').style.display = (!st.ads && !st.checkin) ? 'block' : 'none';
    }

    function updateAdButton(cooldown) {
        clearInterval(cooldownTimer);
        const btn = $('adBtn');
        const left = st.ads.remaining_today;
        if (left === 0) {
            btn.disabled = true; btn.textContent = 'DONE';
            $('adInfo').textContent = 'Daily limit reached. Come back tomorrow!';
            return;
        }
        $('adInfo').textContent = left === null ? 'Watch the full ad to earn' : left + ' left today';
        let c = cooldown;
        const tick = () => {
            if (c > 0) { btn.disabled = true; btn.textContent = c + 's'; c--; return false; }
            btn.disabled = false; btn.textContent = 'WATCH'; return true;
        };
        if (!tick()) cooldownTimer = setInterval(() => { if (tick()) clearInterval(cooldownTimer); }, 1000);
    }

    $('chkBtn').addEventListener('click', async () => {
        $('chkBtn').disabled = true;
        const r = await BP.post('/api/rewards.php', { action: 'checkin' });
        BP.toast(r.success ? '🎉 +' + BP.money(r.data.amount) + ' added' : r.error.message);
        load();
    });

    // Reward is credited ONLY when the ad network's server calls our reward URL.
    $('adBtn').addEventListener('click', async () => {
        const btn = $('adBtn');
        if (!window.Adsgram) return BP.toast('Ads are still loading, try again in a moment');
        btn.disabled = true; btn.textContent = '…';
        const start = await BP.post('/api/rewards.php', { action: 'ad_start' });
        if (!start.success) { BP.toast(start.error.message); return load(); }
        const intent = start.data.intent;
        adController = adController || window.Adsgram.init({ blockId: st.ads.block_id });
        try {
            await adController.show();
            BP.toast('Verifying your reward…');
            waitForCredit(intent, 0);
        } catch (res) {
            BP.toast(res && res.description ? 'Ad not completed: ' + res.description : 'Ad not completed, no reward');
            load();
        }
    });

    async function waitForCredit(intent, n) {
        const r = await BP.api('/api/rewards.php?action=ad_status&intent=' + encodeURIComponent(intent));
        if (r.success && r.data.status === 'COMPLETED') {
            BP.toast('🎉 +' + BP.money(r.data.amount) + ' added to your wallet');
            if (BP.tg && BP.tg.HapticFeedback) BP.tg.HapticFeedback.notificationOccurred('success');
            return load();
        }
        if (n < 10 && (!r.success || r.data.status === 'PENDING')) {
            return setTimeout(() => waitForCredit(intent, n + 1), 2000);
        }
        BP.toast('Reward is taking longer than usual. It will appear in your wallet once confirmed.');
        load();
    }

    BP.ensureAuth().then(u => { BP.markPremium(u); load(); }).catch(e => BP.showError('errorContainer', e.message));
})();
</script>
</body>
</html>
