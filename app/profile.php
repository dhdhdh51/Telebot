<?php require_once __DIR__ . '/includes/layout.php'; appHead('Profile'); ?>

        <div class="card" style="display:flex;gap:14px;align-items:center">
            <div id="avatar" class="avatar">?</div>
            <div>
                <div id="name" style="font-weight:800;font-size:18px">…</div>
                <div id="uname" class="muted"></div>
            </div>
        </div>

        <div id="premiumCard" class="card"></div>

        <div class="card">
            <div style="font-weight:700;margin-bottom:6px">🤝 Invite friends</div>
            <div class="muted" style="margin-bottom:10px">Share your link. You get a bonus when your friend joins and watches videos.</div>
            <input id="refLink" class="text-input" readonly>
            <div style="display:flex;gap:8px;margin-top:10px">
                <button class="btn-main" id="copyRef">Copy link</button>
                <button class="btn-main btn-ghost" id="shareRef">Share</button>
            </div>
        </div>

        <div class="card">
            <a class="list-item" href="/app/wallet.php"><span class="icon">👛</span><span class="grow">Wallet &amp; withdraw</span><span>›</span></a>
            <a class="list-item" href="/app/earn.php"><span class="icon">💰</span><span class="grow">Earn rewards</span><span>›</span></a>
            <a class="list-item" href="/app/subscription.php"><span class="icon">💎</span><span class="grow">Premium plans</span><span>›</span></a>
            <a class="list-item" href="/app/categories.php"><span class="icon">🎬</span><span class="grow">Categories</span><span>›</span></a>
            <a class="list-item" href="/app/search.php"><span class="icon">🔎</span><span class="grow">Search</span><span>›</span></a>
        </div>

<?php appFoot('profile'); ?>
<script>
(async function () {
    let user;
    try { user = await BP.ensureAuth(); } catch (e) { return BP.showError('errorContainer', e.message); }
    // Refresh (premium may have changed since login)
    const fresh = await BP.post('/api/auth.php', { action: 'check_auth' });
    if (fresh.success) user = fresh.data.user;

    document.getElementById('name').textContent = [user.first_name, user.last_name].filter(Boolean).join(' ');
    document.getElementById('uname').textContent = user.username ? '@' + user.username : '';
    const av = document.getElementById('avatar');
    if (user.photo_url && /^https:\/\//.test(user.photo_url)) {
        const img = document.createElement('img');
        img.src = user.photo_url; img.className = 'avatar'; img.alt = '';
        av.replaceWith(img);
    } else {
        av.textContent = (user.first_name || '?').charAt(0).toUpperCase();
    }
    BP.markPremium(user);

    const pc = document.getElementById('premiumCard');
    if (user.is_premium) {
        const d = new Date(String(user.premium_until).replace(' ', 'T'));
        pc.innerHTML = '<div style="font-weight:800">💎 PREMIUM</div><div class="muted">' + BP.escapeHtml(user.premium_plan || '')
            + ' · valid till ' + BP.escapeHtml(isNaN(d) ? user.premium_until : d.toLocaleDateString()) + '</div>'
            + '<a class="btn-main btn-ghost" style="margin-top:10px" href="/app/subscription.php">Extend premium</a>';
    } else {
        pc.innerHTML = '<div style="font-weight:700">Free plan</div><div class="muted" style="margin-bottom:10px">Watch premium videos without ads.</div>'
            + '<a class="btn-main btn-premium" href="/app/subscription.php">💎 Get Premium</a>';
    }

    const link = user.referral_link;
    document.getElementById('refLink').value = link;
    document.getElementById('copyRef').addEventListener('click', async (ev) => {
        try { await navigator.clipboard.writeText(link); } catch (e) {
            const i = document.getElementById('refLink'); i.select(); document.execCommand('copy');
        }
        ev.target.textContent = 'Copied ✔';
        setTimeout(() => { ev.target.textContent = 'Copy link'; }, 2000);
    });
    document.getElementById('shareRef').addEventListener('click', () => {
        const url = 'https://t.me/share/url?url=' + encodeURIComponent(link) + '&text=' + encodeURIComponent('Watch videos on BharatPlay 🎬');
        BP.openExternal(url);
    });
})();
</script>
</body>
</html>
