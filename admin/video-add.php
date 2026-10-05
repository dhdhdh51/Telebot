<?php
/**
 * Admin: upload a new video (video file + thumbnail + details).
 * Works as a normal form, and with JS it uploads via XHR with a progress bar.
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/../includes/video.php';

requirePermission('create', '/admin/videos.php');

$db = db();
$categories = $db->fetchAll("SELECT id, name FROM categories WHERE status = 'ACTIVE' ORDER BY display_order, name");
$limit = effectiveUploadLimit();
$isXhr = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

function respond($ok, $message, $redirect = null) {
    global $isXhr;
    if ($isXhr) {
        if ($ok) {
            flash('success', $message); // shown on the page we redirect to
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => $ok, 'message' => $message, 'redirect' => $redirect]);
        exit;
    }
    flash($ok ? 'success' : 'error', $message);
    header('Location: ' . ($redirect ?: '/admin/video-add.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // If the request exceeds post_max_size, PHP silently drops ALL fields (incl. the
    // CSRF token). Detect that and give a useful message instead of "invalid token".
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        respond(false, 'Upload is larger than the server limit (' . formatBytes($limit) . '). Increase post_max_size / upload_max_filesize in cPanel → MultiPHP INI Editor.');
    }
    if (!CSRF::validateRequest()) {
        respond(false, 'Your session expired. Reload the page and try again.');
    }

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $tags = trim($_POST['tags'] ?? '');
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $accessType = $_POST['access_type'] ?? 'FREE';
    $status = $_POST['status'] ?? 'DRAFT';
    $clientDuration = (int)($_POST['duration'] ?? 0);

    if ($title === '' || mb_strlen($title) > 255) {
        respond(false, 'Title is required (max 255 characters).');
    }
    if (mb_strlen($description) > 5000 || mb_strlen($tags) > 500) {
        respond(false, 'Description (max 5000) or tags (max 500) too long.');
    }
    if (!$db->fetchOne("SELECT id FROM categories WHERE id = ?", [$categoryId])) {
        respond(false, 'Please choose a category.');
    }
    if (!in_array($accessType, ['FREE', 'PREMIUM'], true) || !in_array($status, ['DRAFT', 'PUBLISHED'], true)) {
        respond(false, 'Invalid access type or status.');
    }
    if (empty($_FILES['video']) || empty($_FILES['thumbnail'])) {
        respond(false, 'Both a video file and a thumbnail are required.');
    }

    // Thumbnail first (small) so a bad image doesn't waste a large video move.
    $thumb = Video::uploadThumbnail($_FILES['thumbnail']);
    if (!$thumb['success']) {
        respond(false, 'Thumbnail: ' . $thumb['error']);
    }
    $vid = Video::uploadVideo($_FILES['video']);
    if (!$vid['success']) {
        @unlink($thumb['path']);
        error_log('Admin video upload failed: ' . $vid['error']);
        respond(false, 'Video: ' . $vid['error']);
    }

    // ffprobe is usually unavailable on shared hosting; fall back to the duration
    // the admin's browser read from the file (admin-only input, sanity-bounded).
    $duration = $vid['duration'] > 0 ? $vid['duration'] : max(0, min($clientDuration, 86400));

    try {
        $videoId = Video::create([
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'category_id' => $categoryId,
            'thumbnail' => $thumb['filename'],
            'video_path' => $vid['filename'],
            'duration' => $duration,
            'file_size' => $vid['size'],
            'access_type' => $accessType,
            'status' => $status,
            'tags' => $tags !== '' ? $tags : null,
        ]);
    } catch (Exception $ex) {
        @unlink($thumb['path']);
        @unlink($vid['path']);
        error_log('Video create failed: ' . $ex->getMessage());
        respond(false, 'Could not save the video. Check logs/php-errors.log.');
    }

    respond(true, 'Video uploaded' . ($status === 'PUBLISHED' ? ' and published. You can now post it to Telegram.' : ' as draft.'), '/admin/videos.php');
}

$pageTitle = 'Upload Video';
include __DIR__ . '/includes/header.php';
?>

<?php renderFlash(); ?>
<div id="ajaxAlert"></div>

<div class="content-box" style="max-width:820px;">
    <form id="uploadForm" method="POST" enctype="multipart/form-data">
        <?= CSRF::getInputField() ?>
        <input type="hidden" name="duration" id="duration" value="0">

        <div class="form-group">
            <label for="video">Video file * <small>(MP4 recommended · max <?= e(formatBytes($limit)) ?>)</small></label>
            <input class="form-control" type="file" id="video" name="video" accept="video/mp4,video/webm,video/quicktime,video/x-matroska,video/x-msvideo" required>
            <small id="videoInfo" style="color:#7f8c8d"></small>
        </div>

        <div class="form-group">
            <label for="thumbnail">Thumbnail * <small>(JPG/PNG/WEBP · 16:9 · max <?= e(formatBytes(MAX_THUMBNAIL_SIZE)) ?>)</small></label>
            <input class="form-control" type="file" id="thumbnail" name="thumbnail" accept="image/jpeg,image/png,image/webp" required>
            <img id="thumbPreview" alt="" style="display:none;margin-top:8px;max-width:320px;border-radius:6px">
        </div>

        <div class="form-group">
            <label for="title">Title *</label>
            <input class="form-control" id="title" name="title" maxlength="255" required>
        </div>

        <div class="form-group">
            <label for="description">Short description <small>(first 150 characters appear in the Telegram post)</small></label>
            <textarea class="form-control" id="description" name="description" maxlength="5000"></textarea>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;">
            <div class="form-group">
                <label for="category_id">Category *</label>
                <select class="form-control" id="category_id" name="category_id" required>
                    <option value="">Choose…</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="access_type">Access</label>
                <select class="form-control" id="access_type" name="access_type">
                    <option value="FREE">FREE (everyone, with ads)</option>
                    <option value="PREMIUM">PREMIUM (subscribers only)</option>
                </select>
            </div>
            <div class="form-group">
                <label for="status">Status</label>
                <select class="form-control" id="status" name="status">
                    <option value="PUBLISHED">PUBLISHED (visible in app)</option>
                    <option value="DRAFT">DRAFT (hidden)</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label for="tags">Tags <small>(comma separated, used in search)</small></label>
            <input class="form-control" id="tags" name="tags" maxlength="500" placeholder="comedy, hindi, 2026">
        </div>

        <div id="progressWrap" style="display:none;margin-bottom:16px;">
            <div style="background:#ecf0f1;border-radius:6px;overflow:hidden;height:22px;">
                <div id="progressBar" style="background:#3498db;height:100%;width:0;transition:width .2s;color:#fff;font-size:12px;line-height:22px;text-align:center;">0%</div>
            </div>
            <small id="progressText" style="color:#7f8c8d"></small>
        </div>

        <button type="submit" class="btn btn-primary" id="submitBtn">⬆ Upload Video</button>
        <a href="/admin/videos.php" class="btn" style="background:#ecf0f1">Cancel</a>
    </form>
</div>

<script>
(function () {
    const LIMIT = <?= (int)$limit ?>;
    const THUMB_LIMIT = <?= (int)MAX_THUMBNAIL_SIZE ?>;
    const form = document.getElementById('uploadForm');
    const videoInput = document.getElementById('video');
    const thumbInput = document.getElementById('thumbnail');
    const alertBox = document.getElementById('ajaxAlert');

    function fmt(b) { const u = ['B','KB','MB','GB']; let i = 0; while (b >= 1024 && i < 3) { b /= 1024; i++; } return b.toFixed(1) + ' ' + u[i]; }
    function showAlert(ok, msg) {
        alertBox.innerHTML = '';
        const d = document.createElement('div');
        d.className = 'alert ' + (ok ? 'alert-success' : 'alert-error');
        d.textContent = msg;
        alertBox.appendChild(d);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    videoInput.addEventListener('change', () => {
        const f = videoInput.files[0];
        const info = document.getElementById('videoInfo');
        document.getElementById('duration').value = 0;
        if (!f) { info.textContent = ''; return; }
        if (f.size > LIMIT) {
            info.textContent = '⚠ ' + fmt(f.size) + ' is larger than the server limit (' + fmt(LIMIT) + ').';
            info.style.color = '#c0392b';
            return;
        }
        info.style.color = '#7f8c8d';
        info.textContent = fmt(f.size) + ' · reading duration…';
        // Read duration in the browser (shared hosting rarely has ffprobe).
        const v = document.createElement('video');
        v.preload = 'metadata';
        v.onloadedmetadata = () => {
            const s = Math.round(v.duration) || 0;
            document.getElementById('duration').value = s;
            info.textContent = fmt(f.size) + ' · ' + Math.floor(s / 60) + 'm ' + (s % 60) + 's';
            URL.revokeObjectURL(v.src);
        };
        v.onerror = () => { info.textContent = fmt(f.size) + ' · duration unknown (format not playable in this browser)'; };
        v.src = URL.createObjectURL(f);
        if (!document.getElementById('title').value) {
            document.getElementById('title').value = f.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ');
        }
    });

    thumbInput.addEventListener('change', () => {
        const f = thumbInput.files[0];
        const img = document.getElementById('thumbPreview');
        if (!f) { img.style.display = 'none'; return; }
        if (f.size > THUMB_LIMIT) { showAlert(false, 'Thumbnail is larger than ' + fmt(THUMB_LIMIT) + '.'); }
        img.src = URL.createObjectURL(f);
        img.style.display = 'block';
    });

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const f = videoInput.files[0];
        if (f && f.size > LIMIT) { showAlert(false, 'Video is larger than the server limit (' + fmt(LIMIT) + ').'); return; }

        const btn = document.getElementById('submitBtn');
        const bar = document.getElementById('progressBar');
        const text = document.getElementById('progressText');
        btn.disabled = true;
        btn.textContent = 'Uploading…';
        document.getElementById('progressWrap').style.display = 'block';

        const xhr = new XMLHttpRequest();
        xhr.open('POST', window.location.pathname);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.onprogress = (ev) => {
            if (!ev.lengthComputable) return;
            const pct = Math.round(ev.loaded / ev.total * 100);
            bar.style.width = pct + '%';
            bar.textContent = pct + '%';
            text.textContent = fmt(ev.loaded) + ' / ' + fmt(ev.total) + (pct === 100 ? ' · processing on server…' : '');
        };
        xhr.onload = () => {
            let res = null;
            try { res = JSON.parse(xhr.responseText); } catch (err) {}
            if (res && res.success) {
                window.location.href = res.redirect || '/admin/videos.php';
                return;
            }
            showAlert(false, res ? res.message : 'Upload failed (HTTP ' + xhr.status + '). The file may exceed the server limit.');
            btn.disabled = false;
            btn.textContent = '⬆ Upload Video';
        };
        xhr.onerror = () => {
            showAlert(false, 'Network error during upload. Check your connection and try again.');
            btn.disabled = false;
            btn.textContent = '⬆ Upload Video';
        };
        xhr.send(new FormData(form));
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
