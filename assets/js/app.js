/**
 * BharatPlay Mini App shared client.
 * Security note: nothing here is trusted by the server. The backend derives the
 * user from its session (created from signed initData) and re-checks access,
 * subscription and limits on every request.
 */
(function () {
  const tg = window.Telegram && window.Telegram.WebApp;
  if (tg) {
    tg.ready();
    tg.expand();
  }

  async function api(url, options = {}) {
    const res = await fetch(url, {
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      ...options
    });
    let body = null;
    try { body = await res.json(); } catch (e) { /* non-JSON */ }
    if (!body) {
      return { success: false, status: res.status, error: { code: 'BAD_RESPONSE', message: 'Server error (' + res.status + ')' } };
    }
    body.status = res.status;
    return body;
  }

  function post(url, data) {
    return api(url, { method: 'POST', body: JSON.stringify(data) });
  }

  let authPromise = null;
  /** Reuse the server session if present; otherwise exchange signed initData for one. */
  function ensureAuth() {
    if (authPromise) return authPromise;
    authPromise = (async () => {
      const existing = await post('/api/auth.php', { action: 'check_auth' });
      if (existing.success) return existing.data.user;

      if (!tg || !tg.initData) {
        throw new Error('Please open BharatPlay from Telegram.');
      }
      const res = await post('/api/auth.php', { action: 'telegram_auth', initData: tg.initData });
      if (!res.success) throw new Error(res.error ? res.error.message : 'Authentication failed');
      return res.data.user;
    })();
    authPromise.catch(() => { authPromise = null; });
    return authPromise;
  }

  /** start_param from a t.me/<bot>?startapp=... link. Used for navigation only. */
  function startParam() {
    return (tg && tg.initDataUnsafe && tg.initDataUnsafe.start_param) || '';
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
  }

  function formatViews(views) {
    views = Number(views) || 0;
    if (views >= 1e6) return (views / 1e6).toFixed(1) + 'M';
    if (views >= 1e3) return (views / 1e3).toFixed(1) + 'K';
    return String(views);
  }

  function thumbUrl(name) {
    // Thumbnail names are server-generated, but encode anyway.
    return '/uploads/thumbnails/' + encodeURIComponent(name || '');
  }

  function showError(containerId, message) {
    const el = document.getElementById(containerId);
    if (el) el.innerHTML = '<div class="error">' + escapeHtml(message) + '</div>';
  }

  window.BP = { tg, api, post, ensureAuth, startParam, escapeHtml, formatViews, thumbUrl, showError };
})();
