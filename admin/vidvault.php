<?php
/**
 * Admin → VidVault: browse the VidVault library and add videos to BharatPlay
 * without uploading them again. Videos stay private in VidVault; BharatPlay
 * streams them through short-lived signed URLs after its own access checks.
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/../includes/video.php';
require_once __DIR__ . '/../includes/telegram.php';
require_once __DIR__ . '/../includes/vidvault.php';

requirePermission('create', '/admin/videos.php');

$db = db();
$self = '/admin/vidvault.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $rid = strtolower(trim((string)($_POST['vv_id'] ?? '')));
    $title = trim($_POST['title'] ?? '');
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $access = ($_POST['access_type'] ?? '') === 'PREMIUM' ? 'PREMIUM' : 'FREE';
    $status = ($_POST['status'] ?? '') === 'DRAFT' ? 'DRAFT' : 'PUBLISHED';
    $back = $self . (!empty($_POST['return_query']) ? '?' . preg_replace('/[^\w=&%.+-]/', '', $_POST['return_query']) : '');

    $err = null;
    $remote = null;
    if (!VidVault::remoteId(VidVault::PREFIX . $rid)) {
        $err = 'Invalid VidVault video.';
    } elseif ($title === '' || mb_strlen($title) > 255) {
        $err = 'Title is required (max 255).';
    } elseif (!$db->fetchOne("SELECT id FROM categories WHERE id = ?", [$categoryId])) {
        $err = 'Choose a category.';
    } elseif ($db->fetchOne("SELECT id FROM videos WHERE video_path = ?", [VidVault::PREFIX . $rid])) {
        $err = 'This VidVault video is already in BharatPlay.';
    } else {
        $r = VidVault::getVideo($rid);
        $remote = $r['video'] ?? null;
        if (!$remote) {
            $err = 'VidVault: ' . ($r['error']['message'] ?? 'video not found');
        } elseif (!in_array($remote['status'], ['READY', 'PROCESSING'], true)) {
            $err = 'This video is not ready in VidVault yet (status ' . $remote['status'] . ').';
        }
    }

    $thumb = null;
    if (!$err) {
        if (!empty($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] !== UPLOAD_ERR_NO_FILE) {
            $up = Video::uploadThumbnail($_FILES['thumbnail']);
            $up['success'] ? $thumb = $up['filename'] : $err = 'Thumbnail: ' . $up['error'];
        } else {
            $thumb = VidVault::importThumbnail($rid);
            if (!$thumb) {
                $err = 'VidVault has no thumbnail for this video – please upload one.';
            }
        }
    }

    if ($err) {
        flash('error', $err);
        header('Location: ' . $back);
        exit;
    }

    $videoId = Video::create([
        'title' => $title,
        'description' => trim($_POST['description'] ?? '') ?: null,
        'category_id' => $categoryId,
        'thumbnail' => $thumb,
        'video_path' => VidVault::PREFIX . $rid,
        'duration' => (int)round((float)($remote['duration'] ?? 0)),
        'file_size' => (int)($remote['size'] ?? 0),
        'access_type' => $access,
        'status' => $status,
        'tags' => trim($_POST['tags'] ?? '') ?: null,
    ]);
    $msg = '“' . $title . '” added from VidVault.';
    if ($status === 'PUBLISHED' && !empty($_POST['post_telegram'])) {
        $tg = (new Telegram())->publishVideo(Video::getById($videoId));
        $msg .= !empty($tg['ok']) ? ' Posted to Telegram ✔' : ' Telegram post failed: ' . ($tg['description'] ?? 'unknown');
    }
    flash('success', $msg);
    header('Location: ' . $back);
    exit;
}

$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$configured = VidVault::configured();
$list = $configured ? VidVault::listVideos($q, $page) : null;
$stats = $configured && empty($list['error']) && $page === 1 ? VidVault::stats() : null;
$added = [];
foreach ($db->fetchAll("SELECT id, video_path FROM videos WHERE video_path LIKE 'vidvault:%'") as $r) {
    $added[substr($r['video_path'], 9)] = (int)$r['id'];
}
$categories = $db->fetchAll("SELECT id, name FROM categories WHERE status = 'ACTIVE' ORDER BY display_order, name");
$returnQuery = http_build_query(array_filter(['q' => $q, 'page' => $page > 1 ? $page : null]));

$pageTitle = 'VidVault';
include __DIR__ . '/includes/header.php';
?>

<?php renderFlash(); ?>

<?php if (!$configured): ?>
<div class="content-box" style="max-width:760px">
    <h3 style="margin-bottom:10px">Connect VidVault</h3>
    <ol style="line-height:1.9;padding-left:20px">
        <li>Open <a href="<?= e(VidVault::baseUrl()) ?>" target="_blank" rel="noopener"><?= e(VidVault::baseUrl()) ?></a> → <b>Settings → API keys</b> → <b>Create</b>.</li>
        <li>Copy the key (starts with <code>vv_</code>).</li>
        <li>Paste it in <a href="/admin/settings.php">Settings → Storage (VidVault)</a> and save.</li>
    </ol>
</div>
<?php elseif (!empty($list['error'])): ?>
<div class="alert alert-error">VidVault: <?= e($list['error']['message'] ?? 'error') ?></div>
<?php else: ?>

<?php if ($stats && empty($stats['error'])): ?>
<div class="stats-grid">
    <?php foreach (['videoCount' => 'Videos in VidVault', 'storageUsed' => 'Storage used', 'storageAvailable' => 'Storage free'] as $k => $l):
        if (!isset($stats[$k])) continue; ?>
        <div class="stat-card"><h3><?= e($l) ?></h3><div class="value"><?= $k === 'videoCount' ? (int)$stats[$k] : e(formatBytes((int)$stats[$k])) ?></div></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="content-box">
    <form method="GET" style="display:flex;gap:8px;margin-bottom:14px">
        <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search VidVault library…" style="max-width:320px">
        <button class="btn btn-primary">Search</button>
        <a class="btn" style="background:#ecf0f1" href="<?= e(VidVault::baseUrl()) ?>" target="_blank" rel="noopener">⬆ Upload in VidVault</a>
    </form>
    <p style="color:#7f8c8d;font-size:13px;margin-bottom:12px">Videos stay private in VidVault. BharatPlay checks login/premium, then plays them via short-lived signed links. No share link needed.</p>

    <?php $items = $list['items'] ?? []; if (!$items): ?>
        <p style="padding:30px;text-align:center;color:#7f8c8d">No videos found in VidVault.</p>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px">
    <?php foreach ($items as $v):
        $vid = (string)$v['id'];
        $name = preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', (string)$v['filename']);
        $playable = !empty($v['browserPlayable']); ?>
        <div style="border:1px solid #e3e6ea;border-radius:8px;overflow:hidden;background:#fff">
            <?php if (!empty($v['thumbnailUrl'])): ?>
                <img src="<?= e(VidVault::absolute($v['thumbnailUrl'])) ?>" alt="" referrerpolicy="no-referrer" style="width:100%;aspect-ratio:16/9;object-fit:cover;background:#ddd;display:block">
            <?php else: ?><div style="aspect-ratio:16/9;background:#2c3e50;color:#fff;display:flex;align-items:center;justify-content:center">🎬</div><?php endif; ?>
            <div style="padding:10px">
                <strong style="word-break:break-word"><?= e($v['filename']) ?></strong><br>
                <small style="color:#7f8c8d"><?= e(formatBytes((int)$v['size'])) ?><?= $v['duration'] ? ' · ' . e(formatDuration((int)$v['duration'])) : '' ?><?= $v['height'] ? ' · ' . (int)$v['height'] . 'p' : '' ?>
                    <?= $v['qualities'] ? ' · ' . e(implode('/', $v['qualities'])) : '' ?> · <?= e($v['status']) ?></small>
                <?php if (!$playable): ?><div style="color:#c0392b;font-size:12px;margin-top:4px">⚠ <?= e(strtoupper($v['format'])) ?> may not play in phones' browsers – MP4 is best.</div><?php endif; ?>

                <?php if (isset($added[$vid])): ?>
                    <div style="margin-top:8px"><span class="badge badge-success">✔ In BharatPlay</span> <a href="/admin/video-edit.php?id=<?= $added[$vid] ?>">Edit</a></div>
                <?php else: ?>
                <details style="margin-top:8px"><summary class="btn btn-primary" style="padding:6px 12px;font-size:13px;list-style:none;display:inline-block">+ Add to BharatPlay</summary>
                    <form method="POST" enctype="multipart/form-data" style="margin-top:10px">
                        <?= CSRF::getInputField() ?>
                        <input type="hidden" name="vv_id" value="<?= e($vid) ?>"><input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
                        <input class="form-control" name="title" value="<?= e(str_replace(['_', '-'], ' ', $name)) ?>" maxlength="255" required style="margin-bottom:6px">
                        <textarea class="form-control" name="description" placeholder="Short description" style="min-height:60px;margin-bottom:6px"></textarea>
                        <div style="display:flex;gap:6px;margin-bottom:6px">
                            <select class="form-control" name="category_id" required><option value="">Category…</option>
                                <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
                            <select class="form-control" name="access_type"><option>FREE</option><option>PREMIUM</option></select>
                            <select class="form-control" name="status"><option>PUBLISHED</option><option>DRAFT</option></select>
                        </div>
                        <input class="form-control" name="tags" placeholder="Tags (comma separated)" style="margin-bottom:6px">
                        <label style="font-size:12px;color:#7f8c8d">Thumbnail <?= !empty($v['thumbnailUrl']) ? '(optional – VidVault thumbnail is used)' : '(required)' ?></label>
                        <input class="form-control" type="file" name="thumbnail" accept="image/jpeg,image/png,image/webp" <?= empty($v['thumbnailUrl']) ? 'required' : '' ?> style="margin-bottom:6px">
                        <label style="font-weight:normal;font-size:13px"><input type="checkbox" name="post_telegram" value="1" checked> 📱 Post to Telegram</label>
                        <button class="btn btn-primary" style="width:100%;margin-top:6px">Add video</button>
                    </form>
                </details>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php $total = (int)($list['total'] ?? 0); $ps = (int)($list['pageSize'] ?? 24); $pages = max(1, (int)ceil($total / max(1, $ps))); if ($pages > 1):
        $qs = fn($n) => '?' . http_build_query(array_filter(['q' => $q, 'page' => $n])); ?>
        <div style="margin-top:16px;display:flex;gap:8px;align-items:center">
            <?php if ($page > 1): ?><a class="btn btn-primary" href="<?= e($qs($page - 1)) ?>">← Prev</a><?php endif; ?>
            <span>Page <?= $page ?> / <?= $pages ?> (<?= $total ?> videos)</span>
            <?php if ($page < $pages): ?><a class="btn btn-primary" href="<?= e($qs($page + 1)) ?>">Next →</a><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
