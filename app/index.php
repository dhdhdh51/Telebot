<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>BharatPlay - Watch Videos</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <div class="app-container">
        <div class="header">
            <div class="logo">🎬 BharatPlay</div>
            <div class="user-info">
                <span id="userGreeting">Loading...</span>
                <span id="premiumBadge" style="display:none;" class="premium-badge">PREMIUM</span>
            </div>
        </div>
        
        <div class="content">
            <div id="errorContainer"></div>
            
            <div class="hero-banner">
                <h2>Welcome to BharatPlay</h2>
                <p>Watch unlimited videos anytime, anywhere</p>
                <a id="premiumCta" href="/app/subscription.php" class="btn-main btn-premium" style="display:none;margin-top:14px">💎 Go Premium – no ads</a>
            </div>
            
            <div id="bannerAd" class="ad-slot" style="display:none"></div>

            <?php if (file_exists(__DIR__ . '/search.php')): ?>
            <div class="search-box" onclick="window.location.href='/app/search.php'">
                <span>🔍</span>
                <input type="text" placeholder="Search videos..." readonly>
            </div>
            <?php endif; ?>
            
            <div id="continueWatchingSection" class="section" style="display:none;">
                <div class="section-header">
                    <div class="section-title">Continue Watching</div>
                </div>
                <div id="continueWatchingGrid" class="video-grid"></div>
            </div>
            
            <div class="section">
                <div class="section-header">
                    <div class="section-title">Trending Now</div>
                </div>
                <div id="trendingGrid" class="video-grid">
                    <div class="loading">
                        <div class="spinner"></div>
                        <div>Loading videos...</div>
                    </div>
                </div>
            </div>
            
            <div class="section">
                <div class="section-header">
                    <div class="section-title">Latest Videos</div>
                </div>
                <div id="latestGrid" class="video-grid"></div>
            </div>
        </div>
        
        <?php require_once __DIR__ . '/includes/layout.php'; appNav('index'); ?>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
        // Telegram's SDK sets --tg-theme-* CSS variables automatically (light/dark).

        async function init() {
            // Deep link from a channel post: t.me/<bot>?startapp=v123 -> video page.
            const sp = BP.startParam();
            const deepLinked = /^v(\d+)$/.exec(sp);
            if (deepLinked && !sessionStorage.getItem('bp_routed')) {
                sessionStorage.setItem('bp_routed', '1'); // only once, so Back returns home
                try { await BP.ensureAuth(); } catch (e) { /* video page will show the error */ }
                window.location.href = '/app/video.php?id=' + deepLinked[1];
                return;
            }

            let user;
            try {
                user = await BP.ensureAuth();
            } catch (e) {
                BP.showError('errorContainer', e.message);
                document.getElementById('trendingGrid').innerHTML = '';
                document.getElementById('userGreeting').textContent = '';
                return;
            }
            document.getElementById('userGreeting').textContent = 'Hi, ' + user.first_name;
            if (user.is_premium) {
                document.getElementById('premiumBadge').style.display = 'inline-block';
            }

            const [cw, trending, latest] = await Promise.all([
                BP.api('/api/videos.php?action=continue_watching'),
                BP.api('/api/videos.php?action=trending&limit=10'),
                BP.api('/api/videos.php?action=list&per_page=10')
            ]);

            if (cw.success && cw.data.videos.length) {
                document.getElementById('continueWatchingSection').style.display = 'block';
                renderVideos(cw.data.videos, 'continueWatchingGrid');
            }
            if (trending.success) renderVideos(trending.data.videos, 'trendingGrid');
            else BP.showError('trendingGrid', trending.error.message);
            if (latest.success) renderVideos(latest.data.data, 'latestGrid');
            if (!user.is_premium) {
                BP.renderBanner('bannerAd');
                document.getElementById('premiumCta').style.display = 'block';
            }
        }

        function renderVideos(videos, containerId) {
            const container = document.getElementById(containerId);
            if (!videos || videos.length === 0) {
                container.innerHTML = '<div class="loading">No videos yet</div>';
                return;
            }
            container.innerHTML = videos.map(v => `
                <a href="/app/video.php?id=${encodeURIComponent(v.id)}" class="video-card">
                    <img src="${BP.thumbUrl(v.thumbnail)}" alt="${BP.escapeHtml(v.title)}" class="video-thumbnail" loading="lazy">
                    <div class="video-info">
                        <div class="video-title">${BP.escapeHtml(v.title)}</div>
                        <div class="video-meta">${BP.formatViews(v.views)} views</div>
                        <span class="video-badge badge-${v.access_type === 'PREMIUM' ? 'premium' : 'free'}">${v.access_type === 'PREMIUM' ? 'PREMIUM' : 'FREE'}</span>
                    </div>
                </a>`).join('');
        }

        init();
    </script>
</body>
</html>
