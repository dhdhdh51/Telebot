/** Shared video card rendering for Mini App list pages. */
window.BPVideos = {
  render(videos, container, append) {
    const html = (videos || []).map(v => `
      <a href="/app/video.php?id=${encodeURIComponent(v.id)}" class="video-card">
        <img src="${BP.thumbUrl(v.thumbnail)}" alt="${BP.escapeHtml(v.title)}" class="video-thumbnail" loading="lazy">
        <div class="video-info">
          <div class="video-title">${BP.escapeHtml(v.title)}</div>
          <div class="video-meta">${BP.formatViews(v.views)} views</div>
          <span class="video-badge badge-${v.access_type === 'PREMIUM' ? 'premium' : 'free'}">${v.access_type === 'PREMIUM' ? 'PREMIUM' : 'FREE'}</span>
        </div>
      </a>`).join('');
    if (append) container.insertAdjacentHTML('beforeend', html);
    else container.innerHTML = html;
  }
};
