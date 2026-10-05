<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>BharatPlay - Watch Videos</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--tg-theme-bg-color, #ffffff);
            color: var(--tg-theme-text-color, #000000);
            overflow-x: hidden;
        }
        
        .app-container {
            max-width: 100%;
            margin: 0 auto;
        }
        
        .header {
            position: sticky;
            top: 0;
            background: var(--tg-theme-bg-color, #ffffff);
            padding: 16px;
            border-bottom: 1px solid var(--tg-theme-hint-color, #e5e5e5);
            z-index: 100;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            font-size: 20px;
            font-weight: 700;
            color: var(--tg-theme-button-color, #3390ec);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .premium-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--tg-theme-bg-color, #ffffff);
            border-top: 1px solid var(--tg-theme-hint-color, #e5e5e5);
            display: flex;
            justify-content: space-around;
            padding: 8px 0;
            z-index: 100;
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 8px 16px;
            text-decoration: none;
            color: var(--tg-theme-hint-color, #999);
            font-size: 12px;
            transition: color 0.3s;
        }
        
        .nav-item.active {
            color: var(--tg-theme-button-color, #3390ec);
        }
        
        .nav-item span {
            font-size: 20px;
            margin-bottom: 4px;
        }
        
        .content {
            padding: 16px;
            padding-bottom: 80px;
        }
        
        .section {
            margin-bottom: 24px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 700;
        }
        
        .see-all {
            font-size: 14px;
            color: var(--tg-theme-link-color, #3390ec);
            text-decoration: none;
        }
        
        .video-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 12px;
        }
        
        .video-card {
            background: var(--tg-theme-secondary-bg-color, #f0f0f0);
            border-radius: 12px;
            overflow: hidden;
            text-decoration: none;
            color: inherit;
            display: block;
            transition: transform 0.2s;
        }
        
        .video-card:active {
            transform: scale(0.98);
        }
        
        .video-thumbnail {
            width: 100%;
            aspect-ratio: 16/9;
            object-fit: cover;
            background: #ddd;
        }
        
        .video-info {
            padding: 8px;
        }
        
        .video-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 4px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .video-meta {
            font-size: 12px;
            color: var(--tg-theme-hint-color, #999);
        }
        
        .video-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            margin-top: 4px;
        }
        
        .badge-premium {
            background: #ffd700;
            color: #000;
        }
        
        .badge-free {
            background: #4caf50;
            color: white;
        }
        
        .loading {
            text-align: center;
            padding: 40px 20px;
            color: var(--tg-theme-hint-color, #999);
        }
        
        .spinner {
            border: 3px solid rgba(0,0,0,0.1);
            border-radius: 50%;
            border-top-color: var(--tg-theme-button-color, #3390ec);
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        
        .hero-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 24px;
        }
        
        .hero-banner h2 {
            font-size: 24px;
            margin-bottom: 8px;
        }
        
        .hero-banner p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .search-box {
            background: var(--tg-theme-secondary-bg-color, #f0f0f0);
            padding: 12px 16px;
            border-radius: 24px;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }
        
        .search-box input {
            border: none;
            background: none;
            flex: 1;
            font-size: 14px;
            outline: none;
            color: var(--tg-theme-text-color, #000000);
        }
    </style>
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
            </div>
            
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
        
        <div class="bottom-nav">
            <?php
            // Only show tabs whose pages exist (no dead links).
            $tabs = ['index' => ['🏠', 'Home'], 'search' => ['🔎', 'Search'], 'categories' => ['🎬', 'Categories'],
                     'earn' => ['💰', 'Earn'], 'profile' => ['👤', 'Profile']];
            foreach ($tabs as $page => [$icon, $label]):
                if (!file_exists(__DIR__ . "/{$page}.php")) continue; ?>
            <a href="/app/<?php echo $page; ?>.php" class="nav-item<?php echo $page === 'index' ? ' active' : ''; ?>">
                <span><?php echo $icon; ?></span>
                <span><?php echo $label; ?></span>
            </a>
            <?php endforeach; ?>
        </div>
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
