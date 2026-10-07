<?php require_once __DIR__ . '/includes/layout.php'; appHead('Categories'); ?>

        <div class="page-title" id="title">🎬 Categories</div>
        <div id="chips" class="chip-row"></div>
        <div id="tiles" class="cat-grid"></div>
        <div id="grid" class="video-grid"></div>
        <div id="state" class="loading"><div class="spinner"></div></div>
        <button id="more" class="btn-main btn-ghost" style="display:none;margin-top:14px">Load more</button>

<?php appFoot('categories'); ?>
<script src="/assets/js/videos.js"></script>
<script>
(async function () {
    try { BP.markPremium(await BP.ensureAuth()); } catch (e) { return BP.showError('errorContainer', e.message); }
    const catId = parseInt(new URLSearchParams(location.search).get('id'), 10) || 0;
    const state = document.getElementById('state');
    const more = document.getElementById('more');

    const res = await BP.api('/api/videos.php?action=categories');
    if (!res.success) { state.innerHTML = ''; return BP.showError('errorContainer', res.error.message); }
    const cats = res.data.categories;

    if (!catId) {
        state.style.display = 'none';
        document.getElementById('tiles').innerHTML = cats.map(c =>
            `<a class="cat-tile" href="/app/categories.php?id=${encodeURIComponent(c.id)}"><span>${BP.escapeHtml(c.icon || '🎬')}</span>${BP.escapeHtml(c.name)}</a>`).join('')
            || '<div class="empty">No categories yet</div>';
        return;
    }

    const current = cats.find(c => Number(c.id) === catId);
    document.getElementById('title').textContent = current ? (current.icon || '🎬') + ' ' + current.name : 'Category';
    document.getElementById('chips').innerHTML = cats.map(c =>
        `<a class="chip${Number(c.id) === catId ? ' active' : ''}" href="/app/categories.php?id=${encodeURIComponent(c.id)}">${BP.escapeHtml(c.name)}</a>`).join('');
    if (BP.tg && BP.tg.BackButton) {
        BP.tg.BackButton.show();
        BP.tg.BackButton.onClick(() => { location.href = '/app/categories.php'; });
    }

    let page = 1;
    async function load() {
        more.disabled = true;
        const r = await BP.api('/api/videos.php?action=category_videos&per_page=20&category_id=' + catId + '&page=' + page);
        more.disabled = false;
        state.style.display = 'none';
        if (!r.success) return BP.showError('errorContainer', r.error.message);
        BPVideos.render(r.data.data, document.getElementById('grid'), page > 1);
        if (page === 1 && !r.data.data.length) {
            state.className = 'empty'; state.innerHTML = '<div class="big">📭</div>No videos in this category yet';
            state.style.display = 'block';
        }
        more.style.display = r.data.pagination.has_next ? 'block' : 'none';
        page++;
    }
    more.addEventListener('click', load);
    load();
})();
</script>
</body>
</html>
