<?php require_once __DIR__ . '/includes/layout.php'; appHead('Wallet'); ?>

        <div class="page-title">👛 Wallet</div>

        <div class="card" style="text-align:center">
            <div class="muted">Current balance</div>
            <div class="balance-big" id="bal">…</div>
            <div class="stat-row">
                <div><b id="earned">–</b><span class="muted">Earned</span></div>
                <div><b id="withdrawn">–</b><span class="muted">Withdrawn</span></div>
                <div><b id="pending">–</b><span class="muted">Pending</span></div>
            </div>
        </div>

        <div class="card" id="withdrawCard">
            <div style="font-weight:700;margin-bottom:10px">💸 Withdraw</div>
            <div id="wdOff" class="muted" style="display:none">Withdrawals are paused right now.</div>
            <form id="wdForm" style="display:none" autocomplete="off">
                <div class="seg" id="methods"></div>
                <div class="field"><label>Amount (₹)</label><input class="text-input" id="amount" inputmode="decimal" placeholder="100"></div>
                <div id="upiFields">
                    <div class="field"><label>UPI ID</label><input class="text-input" id="upi" placeholder="name@okaxis"></div>
                </div>
                <div id="bankFields" style="display:none">
                    <div class="field"><label>Account holder name</label><input class="text-input" id="accName"></div>
                    <div class="field"><label>Account number</label><input class="text-input" id="accNo" inputmode="numeric"></div>
                    <div class="field"><label>IFSC</label><input class="text-input" id="ifsc" placeholder="SBIN0001234" style="text-transform:uppercase"></div>
                </div>
                <div class="muted" id="wdRules" style="margin-bottom:10px"></div>
                <div class="muted" id="wdPreview" style="margin-bottom:10px"></div>
                <button class="btn-main" type="submit" id="wdBtn">Request withdrawal</button>
            </form>
        </div>

        <div class="card">
            <div style="font-weight:700;margin-bottom:6px">Withdrawal requests</div>
            <div id="wdList" class="muted">Loading…</div>
        </div>

        <div class="card">
            <div style="font-weight:700;margin-bottom:6px">Transactions</div>
            <div id="txList" class="muted">Loading…</div>
            <button class="btn-main btn-ghost" id="txMore" style="display:none;margin-top:10px">Load more</button>
        </div>

<?php appFoot('earn'); ?>
<script>
(function () {
    let cfg = null, method = 'UPI', txPage = 1;
    const $ = (id) => document.getElementById(id);
    const labels = { REWARD: '🎁 Reward', REFERRAL: '🤝 Referral bonus', WITHDRAWAL: '💸 Withdrawal', ADJUSTMENT: '🛠 Adjustment', REFUND: '↩️ Refund', REVERSAL: '↩️ Reversal' };

    async function loadSummary() {
        const r = await BP.api('/api/wallet.php?action=summary');
        if (!r.success) return BP.showError('errorContainer', r.error.message);
        const w = r.data.wallet;
        cfg = r.data.withdrawal;
        $('bal').textContent = BP.money(w.balance);
        $('earned').textContent = BP.money(w.lifetime_earned);
        $('withdrawn').textContent = BP.money(w.lifetime_withdrawn);
        $('pending').textContent = BP.money(w.pending_withdrawal);
        if (!cfg.enabled) { $('wdOff').style.display = 'block'; $('wdForm').style.display = 'none'; return; }
        $('wdForm').style.display = 'block';
        $('methods').innerHTML = '';
        cfg.methods.forEach(m => {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = m === 'UPI' ? 'UPI' : 'Bank transfer';
            b.className = m === method ? 'on' : '';
            b.onclick = () => { method = m; loadSummaryUi(); };
            $('methods').appendChild(b);
        });
        if (!cfg.methods.includes(method)) method = cfg.methods[0];
        loadSummaryUi();
        let fee = [];
        if (Number(cfg.fee_percent) > 0) fee.push(cfg.fee_percent + '%');
        if (Number(cfg.fee_fixed) > 0) fee.push(BP.money(cfg.fee_fixed));
        $('wdRules').textContent = 'Minimum ' + BP.money(cfg.min) + ' · max ' + BP.money(cfg.max_daily) + '/day · fee ' + (fee.join(' + ') || 'none');
    }

    function loadSummaryUi() {
        [...$('methods').children].forEach((b, i) => b.className = cfg.methods[i] === method ? 'on' : '');
        $('upiFields').style.display = method === 'UPI' ? 'block' : 'none';
        $('bankFields').style.display = method === 'UPI' ? 'none' : 'block';
    }

    $('amount').addEventListener('input', () => {
        const a = Number($('amount').value);
        if (!cfg || !a) { $('wdPreview').textContent = ''; return; }
        // Display only – the server computes the real fee.
        const fee = Math.ceil(a * Number(cfg.fee_percent)) / 100 + Number(cfg.fee_fixed);
        $('wdPreview').textContent = 'You will receive about ' + BP.money(Math.max(0, a - fee));
    });

    $('wdForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = { action: 'withdraw', method, amount: $('amount').value.trim() };
        if (method === 'UPI') body.upi_id = $('upi').value.trim();
        else Object.assign(body, { account_name: $('accName').value.trim(), account_number: $('accNo').value.trim(), ifsc: $('ifsc').value.trim() });
        $('wdBtn').disabled = true;
        const r = await BP.post('/api/wallet.php', body);
        $('wdBtn').disabled = false;
        if (!r.success) return BP.toast(r.error.message);
        BP.toast('✅ Request submitted. Money will be sent after review.');
        $('amount').value = ''; $('wdPreview').textContent = '';
        refresh();
    });

    async function loadWithdrawals() {
        const r = await BP.api('/api/wallet.php?action=withdrawals');
        const box = $('wdList');
        if (!r.success) { box.textContent = r.error.message; return; }
        if (!r.data.withdrawals.length) { box.textContent = 'No withdrawals yet.'; return; }
        box.innerHTML = '';
        r.data.withdrawals.forEach(w => {
            const row = document.createElement('div');
            row.className = 'tx';
            row.innerHTML = '<div><b>' + BP.money(w.amount) + '</b> <span class="pill ' + BP.escapeHtml(w.status) + '">' + BP.escapeHtml(w.status) + '</span><br>'
                + '<span class="muted">' + BP.escapeHtml(w.method === 'UPI' ? 'UPI' : 'Bank') + ' · ' + BP.escapeHtml(w.account) + ' · ' + BP.escapeHtml(w.created_at.slice(0, 10)) + '</span>'
                + (w.rejection_reason ? '<br><span class="muted">Reason: ' + BP.escapeHtml(w.rejection_reason) + '</span>' : '')
                + (w.transaction_id ? '<br><span class="muted">Ref: ' + BP.escapeHtml(w.transaction_id) + '</span>' : '') + '</div>';
            if (w.status === 'PENDING') {
                const c = document.createElement('button');
                c.className = 'btn-main btn-ghost'; c.style.width = 'auto'; c.style.padding = '6px 10px'; c.textContent = 'Cancel';
                c.onclick = async () => {
                    if (!confirm('Cancel this withdrawal?')) return;
                    const x = await BP.post('/api/wallet.php', { action: 'cancel_withdrawal', id: w.id });
                    BP.toast(x.success ? 'Cancelled, amount returned' : x.error.message);
                    refresh();
                };
                row.appendChild(c);
            }
            box.appendChild(row);
        });
    }

    async function loadTx(append) {
        const r = await BP.api('/api/wallet.php?action=transactions&page=' + txPage);
        const box = $('txList');
        if (!r.success) { box.textContent = r.error.message; return; }
        if (!append) box.innerHTML = '';
        if (!r.data.data.length && txPage === 1) { box.textContent = 'No transactions yet. Earn rewards on the Earn tab!'; }
        r.data.data.forEach(t => {
            const neg = Number(t.amount) < 0;
            const row = document.createElement('div');
            row.className = 'tx';
            row.innerHTML = '<div>' + BP.escapeHtml(labels[t.type] || t.type) + '<br><span class="muted">' + BP.escapeHtml(t.description) + ' · ' + BP.escapeHtml(t.created_at.slice(0, 16)) + '</span></div>'
                + '<div class="' + (neg ? 'minus' : 'plus') + '">' + (neg ? '−' : '+') + BP.money(Math.abs(t.amount)) + '</div>';
            box.appendChild(row);
        });
        $('txMore').style.display = r.data.pagination.has_next ? 'block' : 'none';
    }
    $('txMore').addEventListener('click', () => { txPage++; loadTx(true); });

    function refresh() { txPage = 1; loadSummary(); loadWithdrawals(); loadTx(false); }

    BP.ensureAuth().then(u => { BP.markPremium(u); refresh(); }).catch(e => BP.showError('errorContainer', e.message));
    if (BP.tg && BP.tg.BackButton) { BP.tg.BackButton.show(); BP.tg.BackButton.onClick(() => { location.href = '/app/earn.php'; }); }
})();
</script>
</body>
</html>
