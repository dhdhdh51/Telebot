<?php
/**
 * Admin: edit video details and optionally replace the thumbnail.
 * (Replacing the video file itself = delete + upload again.)
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/../includes/video.php';

requirePermission('edit', '/admin/videos.php');

$db = db();
$id = (int)($_GET['id'] ?? 0);
$video = $id ? Video::getById($id) : null;
if (!$video) {
    flash('error', 'Video not found.');
    header('Location: /admin/videos.php');
    exit;
}
$categories = $db->fetchAll("SELECT id, name FROM categories WHERE status = 'ACTIVE' ORDER BY display_order, name");
$self = '/admin/video-edit.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('error', 'Upload is larger than the server limit.');
        header('Location: ' . $self);
        exit;
    }
    if (!CSRF::validateRequest()) {
        flash('error', 'Your session expired. Please try again.');
        header('Location: ' . $self);
        exit;
    }

    $data = [
        'title' => trim($_POST['title'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'tags' => trim($_POST['tags'] ?? ''),
        'category_id' => (int)($_POST['category_id'] ?? 0),
        'access_type' => $_POST['access_type'] ?? '',
        'status' => $_POST['status'] ?? '',
    ];
    $error = null;
    if ($data['title'] === '' || mb_strlen($data['title']) > 255) {
        $error = 'Title is required (max 255 characters).';
    } elseif (mb_strlen($data['description']) > 5000 || mb_strlen($data['tags']) > 500) {
        $error = 'Description (max 5000) or tags (max 500) too long.';
    } elseif (!$db->fetchOne("SELECT id FROM categories WHERE id = ?", [$data['category_id']])) {
        $error = 'Please choose a category.';
    } elseif (!in_array($data['access_type'], ['FREE', 'PREMIUM'], true)
        || !in_array($data['status'], ['DRAFT', 'PUBLISHED', 'UNPUBLISHED', 'ARCHIVED'], true)) {
        $error = 'Invalid access type or status.';
    }

    $oldThumb = null;
    if (!$error && !empty($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] !== UPLOAD_ERR_NO_FILE) {
        $thumb = Video::uploadThumbnail($_FILES['thumbnail']);
        if ($thumb['success']) {
            $data['thumbnail'] = $thumb['filename'];
            $oldThumb = $video['thumbnail'];
        } else {
            $error = 'Thumbnail: ' . $thumb['error'];
        }
    }

    if ($error) {
        flash('error', $error);
        header('Location: ' . $self);
        exit;
    }

    Video::update($id, $data);
    if ($oldThumb) {
        @unlink(THUMBNAIL_DIR . '/' . basename($oldThumb));
    }
    flash('success', 'Video updated.');
    header('Location: /admin/videos.php');
    exit;
}

$pageTitle = 'Edit Video #' . $id;
include __DIR__ . '/includes/header.php';
?>

<?php renderFlash(); ?>

<div class="content-box" style="max-width:820px;">
    <form method="POST" enctype="multipart/form-data">
        <?= CSRF::getInputField() ?>

        <div class="form-group">
            <label>Current thumbnail</label>
            <img src="/uploads/thumbnails/<?= e(rawurlencode($video['thumbnail'])) ?>" alt="" style="max-width:320px;border-radius:6px;display:block">
            <small style="color:#7f8c8d"><?= e(formatBytes((int)$video['file_size'])) ?> · <?= e(formatDuration((int)$video['duration'])) ?> · <?= number_format((int)$video['views']) ?> views</small>
        </div>

        <div class="form-group">
            <label for="thumbnail">Replace thumbnail <small>(optional)</small></label>
            <input class="form-control" type="file" id="thumbnail" name="thumbnail" accept="image/jpeg,image/png,image/webp">
        </div>

        <div class="form-group">
            <label for="title">Title *</label>
            <input class="form-control" id="title" name="title" maxlength="255" required value="<?= e($video['title']) ?>">
        </div>

        <div class="form-group">
            <label for="description">Short description</label>
            <textarea class="form-control" id="description" name="description" maxlength="5000"><?= e($video['description']) ?></textarea>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;">
            <div class="form-group">
                <label for="category_id">Category *</label>
                <select class="form-control" id="category_id" name="category_id" required>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === (int)$video['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="access_type">Access</label>
                <select class="form-control" id="access_type" name="access_type">
                    <?php foreach (['FREE', 'PREMIUM'] as $a): ?>
                        <option value="<?= $a ?>" <?= $video['access_type'] === $a ? 'selected' : '' ?>><?= $a ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="status">Status</label>
                <select class="form-control" id="status" name="status">
                    <?php foreach (['PUBLISHED', 'DRAFT', 'UNPUBLISHED', 'ARCHIVED'] as $s): ?>
                        <option value="<?= $s ?>" <?= $video['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label for="tags">Tags</label>
            <input class="form-control" id="tags" name="tags" maxlength="500" value="<?= e($video['tags']) ?>">
        </div>

        <button type="submit" class="btn btn-primary">Save changes</button>
        <a href="/admin/videos.php" class="btn" style="background:#ecf0f1">Cancel</a>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
