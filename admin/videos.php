<?php
/**
 * Admin: video list + actions (publish to Telegram, delete).
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/../includes/video.php';
require_once __DIR__ . '/../includes/telegram.php';

$db = db();

// ---- Actions (POST + CSRF only) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $id = (int)($_POST['id'] ?? 0);
    $video = $id ? Video::getById($id) : null;
    $action = $_POST['action'] ?? '';

    if (!$video) {
        flash('error', 'Video not found.');
    } elseif ($action === 'publish_telegram') {
        requirePermission('edit', '/admin/videos.php');
        if ($video['status'] !== 'PUBLISHED') {
            // Otherwise the WATCH button would open a video users can't see.
            flash('error', 'Set the video status to PUBLISHED before posting it to Telegram.');
        } else {
            $result = (new Telegram())->publishVideo($video);
            if (!empty($result['ok'])) {
                logAudit('VIDEO_PUBLISHED_TELEGRAM', "Posted video #$id to Telegram", 'video', $id);
                flash('success', 'Posted to Telegram channel.');
            } else {
                flash('error', 'Telegram error: ' . ($result['description'] ?? $result['error'] ?? 'unknown'));
            }
        }
    } elseif ($action === 'delete') {
        requirePermission('delete', '/admin/videos.php');
        Video::delete($id);
        flash('success', 'Video deleted.');
    } elseif ($action === 'set_status' && in_array($_POST['status'] ?? '', ['PUBLISHED', 'UNPUBLISHED', 'DRAFT'], true)) {
        requirePermission('edit', '/admin/videos.php');
        Video::update($id, ['status' => $_POST['status']]);
        flash('success', 'Status changed to ' . $_POST['status'] . '.');
    }

    $back = '/admin/videos.php' . (!empty($_POST['return_query']) ? '?' . preg_replace('/[^\w=&%-]/', '', $_POST['return_query']) : '');
    header('Location: ' . $back);
    exit;
}

// ---- List ----
$status = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

$sql = "SELECT v.id, v.title, v.thumbnail, v.access_type, v.status, v.views, v.duration, v.file_size, v.created_at,
               c.name AS category_name,
               (SELECT COUNT(*) FROM telegram_posts tp WHERE tp.video_id = v.id AND tp.status = 'SENT') AS tg_posts
        FROM videos v LEFT JOIN categories c ON c.id = v.category_id WHERE 1=1";
$params = [];
if (in_array($status, ['DRAFT', 'PUBLISHED', 'UNPUBLISHED', 'ARCHIVED'], true)) {
    $sql .= " AND v.status = ?";
    $params[] = $status;
}
if ($q !== '') {
    $sql .= " AND v.title LIKE ?";
    $params[] = '%' . addcslashes($q, '%_\\') . '%';
}
$sql .= " ORDER BY v.id DESC";
$result = paginate($sql, $params, $page, 20);
$returnQuery = http_build_query(array_filter(['status' => $status, 'q' => $q, 'page' => $page > 1 ? $page : null]));

$pageTitle = 'Videos';
include __DIR__ . '/includes/header.php';
?>

<?php renderFlash(); ?>

<div class="content-box">
    <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;">
            <input class="form-control" style="width:220px" name="q" placeholder="Search title..." value="<?= e($q) ?>">
            <select class="form-control" style="width:160px" name="status">
                <option value="">All statuses</option>
                <?php foreach (['PUBLISHED', 'DRAFT', 'UNPUBLISHED', 'ARCHIVED'] as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary" type="submit">Filter</button>
        </form>
        <?php if (Auth::hasPermission('create')): ?>
            <a href="/admin/video-add.php" class="btn btn-primary">+ Upload Video</a>
        <?php endif; ?>
    </div>

    <?php if (!$result['data']): ?>
        <p style="padding:40px;text-align:center;color:#7f8c8d;">
            No videos yet. <a href="/admin/video-add.php">Upload your first video</a>.
        </p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table>
        <thead>
            <tr><th></th><th>Title</th><th>Category</th><th>Access</th><th>Status</th><th>Views</th><th>Size</th><th>Telegram</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($result['data'] as $v): ?>
            <tr>
                <td><img src="/uploads/thumbnails/<?= e(rawurlencode($v['thumbnail'])) ?>" alt="" style="width:96px;aspect-ratio:16/9;object-fit:cover;border-radius:4px;background:#ddd"></td>
                <td><strong><?= e($v['title']) ?></strong><br><small>#<?= (int)$v['id'] ?> · <?= e(formatDuration((int)$v['duration'])) ?></small></td>
                <td><?= e($v['category_name']) ?></td>
                <td><span class="badge badge-<?= $v['access_type'] === 'PREMIUM' ? 'warning' : 'info' ?>"><?= e($v['access_type']) ?></span></td>
                <td><span class="badge badge-<?= $v['status'] === 'PUBLISHED' ? 'success' : 'warning' ?>"><?= e($v['status']) ?></span></td>
                <td><?= number_format((int)$v['views']) ?></td>
                <td><?= e(formatBytes((int)$v['file_size'])) ?></td>
                <td><?= $v['tg_posts'] ? '✅ ' . (int)$v['tg_posts'] : '—' ?></td>
                <td style="white-space:nowrap;">
                    <?php if (Auth::hasPermission('edit')): ?>
                        <a class="btn btn-primary" style="padding:4px 10px;font-size:12px" href="/admin/video-edit.php?id=<?= (int)$v['id'] ?>">Edit</a>
                        <form method="POST" style="display:inline">
                            <?= CSRF::getInputField() ?>
                            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                            <input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
                            <?php if ($v['status'] === 'PUBLISHED'): ?>
                                <input type="hidden" name="action" value="publish_telegram">
                                <button class="btn btn-primary" style="padding:4px 10px;font-size:12px;background:#229ED9"
                                    onclick="return confirm('Post this video to the Telegram channel?')">📱 Post to Telegram</button>
                            <?php else: ?>
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="status" value="PUBLISHED">
                                <button class="btn btn-primary" style="padding:4px 10px;font-size:12px;background:#27ae60">Publish</button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                    <?php if (Auth::hasPermission('delete')): ?>
                        <form method="POST" style="display:inline">
                            <?= CSRF::getInputField() ?>
                            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
                            <button class="btn btn-danger" style="padding:4px 10px;font-size:12px"
                                onclick="return confirm('Delete this video and its files permanently?')">Delete</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php $p = $result['pagination']; if ($p['total_pages'] > 1): ?>
        <div style="margin-top:16px;display:flex;gap:8px;align-items:center;">
            <?php $qs = fn($n) => '?' . http_build_query(array_filter(['status' => $status, 'q' => $q, 'page' => $n])); ?>
            <?php if ($p['has_prev']): ?><a class="btn btn-primary" href="<?= e($qs($page - 1)) ?>">← Prev</a><?php endif; ?>
            <span>Page <?= $p['current_page'] ?> of <?= $p['total_pages'] ?> (<?= $p['total'] ?> videos)</span>
            <?php if ($p['has_next']): ?><a class="btn btn-primary" href="<?= e($qs($page + 1)) ?>">Next →</a><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
