<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Watch Video - BharatPlay</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #000;
            color: #fff;
        }
        
        .video-container {
            position: relative;
            width: 100%;
            background: #000;
        }
        
        video {
            width: 100%;
            max-height: 300px;
            display: block;
        }
        
        .video-info {
            background: var(--tg-theme-bg-color, #ffffff);
            color: var(--tg-theme-text-color, #000000);
            padding: 16px;
        }
        
        .video-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .video-stats {
            font-size: 14px;
            color: var(--tg-theme-hint-color, #999);
            margin-bottom: 12px;
        }
        
        .video-description {
            font-size: 14px;
            line-height: 1.5;
            color: var(--tg-theme-text-color, #000000);
        }
        
        .premium-lock {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            background: rgba(0,0,0,0.9);
            padding: 24px;
            border-radius: 12px;
            z-index: 10;
        }
        
        .premium-lock h3 {
            font-size: 20px;
            margin-bottom: 12px;
        }
        
        .premium-lock p {
            margin-bottom: 20px;
            color: #ccc;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 24px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }
        
        .loading {
            padding: 40px 20px;
            text-align: center;
            color: var(--tg-theme-hint-color, #999);
        }
        
        .spinner {
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
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
            margin: 16px;
        }
        
        .back-button {
            position: absolute;
            top: 16px;
            left: 16px;
            background: rgba(0,0,0,0.6);
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 100;
            font-size: 20px;
            color: white;
        }
        
        .controls {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }
        
        .control-btn {
            flex: 1;
            padding: 10px;
            background: var(--tg-theme-button-color, #3390ec);
            color: var(--tg-theme-button-text-color, #ffffff);
            border: none;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
        }
        
        .ad-container {
            background: #f0f0f0;
            padding: 16px;
            margin: 16px;
            border-radius: 8px;
            text-align: center;
        }
        
        .ad-timer {
            font-weight: 700;
            font-size: 18px;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>
    <div class="video-container">
        <button class="back-button" onclick="goBack()">←</button>
        <div id="loadingContainer" class="loading">
            <div class="spinner"></div>
            <div>Loading video...</div>
        </div>
        <div id="videoPlayer" style="display:none;">
            <video id="video" controls playsinline></video>
        </div>
        <div id="premiumLock" style="display:none;"></div>
    </div>
    
    <div id="adContainer" class="ad-container" style="display:none;">
        <div class="ad-timer" id="adTimer"></div>
        <div id="adCreative"></div>
        <button class="control-btn" id="adSkip" style="display:none;margin-top:12px;" onclick="closeAd()">Continue to video</button>
    </div>

    <div id="errorContainer"></div>

    <div id="videoInfo" class="video-info" style="display:none;"></div>
    <div id="bannerAd" class="ad-slot" style="display:none;padding:0 16px;background:var(--tg-theme-bg-color,#fff)"></div>

    <script>var adsgramFailed = false;</script>
    <script src="https://sad.adsgram.ai/js/sad.min.js" async onerror="adsgramFailed = true"></script>
    <script src="/assets/js/app.js"></script>
    <script>
        const HAS_SUBSCRIPTION_PAGE = <?php echo file_exists(__DIR__ . '/subscription.php') ? 'true' : 'false'; ?>;
        const videoId = parseInt(new URLSearchParams(window.location.search).get('id'), 10);

        let progressTimer = null;
        let lastPosition = 0;
        let lastSent = -1;

        async function loadVideo() {
            if (!videoId) return fail('Invalid video ID');
            try {
                await BP.ensureAuth(); // also works when opened directly from a channel post
            } catch (e) {
                return fail(e.message);
            }

            const res = await BP.api('/api/videos.php?action=get&id=' + videoId);
            if (!res.success) return fail(res.error.message);

            const { video, can_access, stream_url, watch_history } = res.data;
            document.getElementById('loadingContainer').style.display = 'none';
            displayVideoInfo(video);

            BP.renderBanner('bannerAd');
            if (can_access && stream_url) {
                showVideoPlayer(stream_url, watch_history);
            } else {
                showPremiumLock();
            }
        }

        function fail(message) {
            document.getElementById('loadingContainer').style.display = 'none';
            BP.showError('errorContainer', message);
        }

        function displayVideoInfo(v) {
            const el = document.getElementById('videoInfo');
            el.innerHTML = `
                <div class="video-title">${BP.escapeHtml(v.title)}</div>
                <div class="video-stats">${BP.formatViews(v.views)} views • ${BP.escapeHtml(v.category_name || '')}</div>
                <div class="video-description">${BP.escapeHtml(v.description || '')}</div>`;
            el.style.display = 'block';
        }

        async function showVideoPlayer(streamUrl, history) {
            const video = document.getElementById('video');
            document.getElementById('videoPlayer').style.display = 'block';
            // The ad (if the server says one is due) plays BEFORE the video is loaded.
            video.controls = false;
            await maybeShowAd();
            video.controls = true;
            video.src = streamUrl;

            if (history && history.last_position > 0 && !Number(history.completed)) {
                video.addEventListener('loadedmetadata', () => {
                    if (history.last_position < video.duration - 5) video.currentTime = history.last_position;
                }, { once: true });
            }

            video.addEventListener('timeupdate', () => { lastPosition = Math.floor(video.currentTime); });
            video.addEventListener('pause', () => saveProgress());
            video.addEventListener('ended', () => saveProgress());
            progressTimer = setInterval(() => { if (!video.paused) saveProgress(); }, 10000);
            video.play().catch(() => {}); // may need a tap if the browser blocks autoplay
        }

        function showPremiumLock() {
            const lock = document.getElementById('premiumLock');
            lock.style.cssText = 'display:block;position:relative;min-height:260px;';
            lock.innerHTML = `
                <div class="premium-lock">
                    <h3>🔒 Premium Content</h3>
                    <p>This video is only available for premium members</p>
                    ${HAS_SUBSCRIPTION_PAGE ? '<a href="/app/subscription.php" class="btn">Get Premium</a>' : ''}
                </div>`;
        }

        // The server decides the watch-time credit; we only report the playhead.
        function saveProgress() {
            if (!videoId || lastPosition === lastSent) return;
            lastSent = lastPosition;
            const body = JSON.stringify({ action: 'update_progress', video_id: videoId, position: lastPosition });
            // sendBeacon survives page unload; fall back to fetch.
            if (document.visibilityState === 'hidden' && navigator.sendBeacon) {
                navigator.sendBeacon('/api/videos.php', new Blob([body], { type: 'application/json' }));
            } else {
                BP.post('/api/videos.php', JSON.parse(body));
            }
        }

        let adDone = null;

        /** Resolves when the ad is finished/closed, or immediately if no ad is due. */
        async function maybeShowAd() {
            // Server decides: premium users and the "every N videos" rule return no ad.
            const res = await BP.api('/api/videos.php?action=get_ad&type=INTERSTITIAL&video_id=' + videoId);
            if (!res.success || !res.data || !res.data.ad) return;
            const ad = res.data.ad;
            if (ad.provider === 'adsgram') return showAdsgram(ad);

            const creative = document.getElementById('adCreative');
            creative.innerHTML = '';
            creative.appendChild(BP.buildAdCreative(ad, 250));
            document.getElementById('adContainer').style.display = 'block';

            let countdown = ad.skip_after;
            const timerEl = document.getElementById('adTimer');
            const skip = document.getElementById('adSkip');
            const tick = () => {
                timerEl.textContent = countdown > 0 ? 'Advertisement · skip in ' + countdown + 's' : 'Advertisement';
                if (countdown <= 0) { skip.style.display = 'block'; return true; }
                countdown--;
                return false;
            };
            if (!tick()) {
                const t = setInterval(() => { if (tick()) clearInterval(t); }, 1000);
            }
            return new Promise(resolve => { adDone = resolve; });
        }

        function closeAd() {
            document.getElementById('adContainer').style.display = 'none';
            if (adDone) { adDone(); adDone = null; }
        }

        /** Adsgram full-screen interstitial. Any failure (no ad, blocked, error) just continues to the video. */
        async function showAdsgram(ad) {
            const loading = document.getElementById('loadingContainer');
            loading.style.display = 'block';
            loading.querySelector('div:last-child').textContent = 'Loading…';
            for (let i = 0; i < 30 && !window.Adsgram && !adsgramFailed; i++) {
                await new Promise(r => setTimeout(r, 100));
            }
            loading.style.display = 'none';
            if (!window.Adsgram) return;
            try {
                const ctrl = window.Adsgram.init({ blockId: String(ad.block_id) });
                const result = await ctrl.show();
                if (result && result.done) {
                    BP.post('/api/videos.php', { action: 'ad_complete', impression_id: ad.impression_id });
                }
            } catch (e) {
                // closed early / no ad available / error: nothing to do
            }
        }

        function goBack() {
            saveProgress();
            if (history.length > 1) history.back(); else window.location.href = '/app/index.php';
        }

        // Telegram's own back button
        if (BP.tg && BP.tg.BackButton) {
            BP.tg.BackButton.show();
            BP.tg.BackButton.onClick(goBack);
        }

        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') saveProgress(); });

        loadVideo();
    </script>
</body>
</html>
