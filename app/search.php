<?php require_once __DIR__ . '/includes/layout.php'; appHead('Search'); ?>

        <div class="page-title">🔎 Search</div>
        <input id="q" class="text-input" type="search" placeholder="Search videos, tags…" autocomplete="off" style="margin-bottom:16px">
        <div id="results" class="video-grid"></div>
        <div id="state" class="empty"><div class="big">🔎</div>Type to search</div>

<?php appFoot('search'); ?>
<script src="/assets/js/videos.js"></script>
<script>
(async function () {
    try { BP.markPremium(await BP.ensureAuth()); } catch (e) { return BP.showError('errorContainer', e.message); }
    const input = document.getElementById('q');
    const state = document.getElementById('state');
    const results = document.getElementById('results');
    let timer = null, seq = 0;

    const initial = new URLSearchParams(location.search).get('q');
    if (initial) { input.value = initial; run(); }
    input.focus();

    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 350); });

    async function run() {
        const q = input.value.trim();
        const my = ++seq;
        if (!q) { results.innerHTML = ''; state.innerHTML = '<div class="big">🔎</div>Type to search'; state.style.display = 'block'; return; }
        state.innerHTML = '<div class="spinner"></div>'; state.style.display = 'block';
        const res = await BP.api('/api/videos.php?action=search&per_page=30&q=' + encodeURIComponent(q.slice(0, 100)));
        if (my !== seq) return; // a newer search is running
        if (!res.success) { state.textContent = res.error.message; return; }
        const list = res.data.data;
        BPVideos.render(list, results);
        state.style.display = list.length ? 'none' : 'block';
        if (!list.length) state.innerHTML = '<div class="big">🤷</div>No videos found for “' + BP.escapeHtml(q) + '”';
    }
})();
</script>
</body>
</html>
