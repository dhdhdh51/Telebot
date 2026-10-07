<?php
/**
 * Admin → Ads: create banner / interstitial / video ads, schedule, pause, stats.
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/../includes/video.php';

$db = db();
$self = '/admin/ads.php';

function validHttpsUrl($u) {
    return $u === '' || (filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https://#i', $u));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('error', 'Upload too large.');
        header('Location: ' . $self);
        exit;
    }
    requirePostCsrf();
    requirePermission('edit', $self);
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $old = $id ? $db->fetchOne("SELECT * FROM ads WHERE id = ?", [$id]) : null;

    if ($action === 'save') {
        $type = $_POST['type'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $imageUrl = trim($_POST['image_url'] ?? '');
        $videoUrl = trim($_POST['video_url'] ?? '');
        $dest = trim($_POST['destination_url'] ?? '');
        // Third-party scripts: SUPER_ADMIN only (they run in a sandboxed frame, but still).
        $html = isSuperAdmin() ? trim($_POST['creative_html'] ?? '') : ($old['creative_html'] ?? '');
        $start = trim($_POST['start_date'] ?? '');
        $end = trim($_POST['end_date'] ?? '');
        $err = null;

        if (!empty($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $up = Video::uploadThumbnail($_FILES['image']);
            if ($up['success']) {
                $imageUrl = '/uploads/thumbnails/' . $up['filename'];
            } else {
                $err = 'Image: ' . $up['error'];
            }
        }
        if (!$err) {
            if ($name === '' || mb_strlen($name) > 100) {
                $err = 'Ad name is required.';
            } elseif (!in_array($type, ['BANNER', 'INTERSTITIAL', 'VIDEO'], true)) {
                $err = 'Choose an ad type.';
            } elseif ($imageUrl === '' && $videoUrl === '' && $html === '') {
                $err = 'Add an image, a video URL or ad network code.';
            } elseif ((strpos($imageUrl, '/uploads/thumbnails/') !== 0 && !validHttpsUrl($imageUrl)) || !validHttpsUrl($videoUrl) || !validHttpsUrl($dest)) {
                $err = 'Links must be full https:// URLs.';
            } elseif (($start !== '' && !strtotime($start)) || ($end !== '' && !strtotime($end))) {
                $err = 'Invalid start/end date.';
            }
        }
        if ($err) {
            flash('error', $err);
            header('Location: ' . $self . ($id ? '?edit=' . $id : '?new=1'));
            exit;
        }
        $data = [$name, $type, $html !== '' ? $html : null, $imageUrl ?: null, $videoUrl ?: null, $dest ?: null,
                 (int)($_POST['priority'] ?? 0), ($_POST['status'] ?? '') === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE',
                 $start !== '' ? date('Y-m-d H:i:s', strtotime($start)) : null, $end !== '' ? date('Y-m-d H:i:s', strtotime($end)) : null];
        if ($old) {
            $db->execute("UPDATE ads SET name=?, type=?, creative_html=?, image_url=?, video_url=?, destination_url=?, priority=?, status=?, start_date=?, end_date=? WHERE id=?", array_merge($data, [$id]));
            logAudit('AD_UPDATED', "Updated ad #$id $name", 'ad', $id);
        } else {
            $db->execute("INSERT INTO ads (name, type, creative_html, image_url, video_url, destination_url, priority, status, start_date, end_date) VALUES (?,?,?,?,?,?,?,?,?,?)", $data);
            logAudit('AD_CREATED', "Created ad $name", 'ad', (int)$db->lastInsertId());
        }
        flash('success', 'Ad saved.');
    } elseif ($action === 'toggle' && $old) {
        $db->execute("UPDATE ads SET status = ? WHERE id = ?", [$old['status'] === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE', $id]);
        flash('success', 'Ad ' . ($old['status'] === 'ACTIVE' ? 'paused.' : 'activated.'));
    } elseif ($action === 'delete' && $old) {
        requirePermission('delete', $self);
        $db->execute("DELETE FROM ads WHERE id = ?", [$id]);
        if ($old['image_url'] && strpos($old['image_url'], '/uploads/thumbnails/') === 0) {
            @unlink(THUMBNAIL_DIR . '/' . basename($old['image_url']));
        }
        logAudit('AD_DELETED', "Deleted ad #$id", 'ad', $id);
        flash('success', 'Ad deleted.');
    }
    header('Location: ' . $self);
    exit;
}

$ads = $db->fetchAll("SELECT * FROM ads ORDER BY status = 'ACTIVE' DESC, priority DESC, id DESC");
$editId = (int)($_GET['edit'] ?? 0);
$editing = $editId ? $db->fetchOne("SELECT * FROM ads WHERE id = ?", [$editId]) : null;
$showForm = $editing || isset($_GET['new']);
$adsOn = getSetting('ads', 'enabled', true);

$pageTitle = 'Ads';
include __DIR__ . '/includes/header.php';
?>

<?php renderFlash(); ?>

<?php if (!$adsOn): ?><div class="alert alert-error">Ads are switched OFF globally. <?php if (isSuperAdmin()): ?><a href="/admin/settings.php">Turn on in Settings → Ads</a><?php endif; ?></div><?php endif; ?>

<div class="content-box">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h3>Ads</h3>
        <?php if (Auth::hasPermission('edit')): ?><a class="btn btn-primary" href="?new=1#adForm">+ New ad</a><?php endif; ?>
    </div>
    <p style="color:#7f8c8d;font-size:13px;margin-bottom:12px">
        <b>BANNER</b>: Home + below the player · <b>INTERSTITIAL / VIDEO</b>: before a video (frequency &amp; skip time in Settings → Ads).
        Premium users never see ads. If several ads are active, the highest priority wins (ties rotate randomly).
    </p>
    <?php if (!$ads): ?><p style="padding:30px;text-align:center;color:#7f8c8d">No ads yet.</p><?php else: ?>
    <div style="overflow-x:auto"><table>
        <thead><tr><th>Ad</th><th>Type</th><th>Creative</th><th>Impressions</th><th>Clicks</th><th>CTR</th><th>Schedule</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($ads as $a): $ctr = $a['impressions'] ? round($a['clicks'] / $a['impressions'] * 100, 2) : 0; ?>
            <tr>
                <td><strong><?= e($a['name']) ?></strong><br><small>priority <?= (int)$a['priority'] ?></small></td>
                <td><?= e($a['type']) ?></td>
                <td><?php if ($a['image_url']): ?><img src="<?= e($a['image_url']) ?>" alt="" style="max-width:120px;max-height:60px;border-radius:4px"><?php elseif ($a['video_url']): ?>🎞 video<?php else: ?>📜 network code<?php endif; ?></td>
                <td><?= number_format((int)$a['impressions']) ?></td>
                <td><?= number_format((int)$a['clicks']) ?></td>
                <td><?= $ctr ?>%</td>
                <td style="font-size:12px"><?= $a['start_date'] ? 'from ' . e(date('d M', strtotime($a['start_date']))) : '' ?><?= $a['end_date'] ? '<br>to ' . e(date('d M', strtotime($a['end_date']))) : (!$a['start_date'] ? 'always' : '') ?></td>
                <td><span class="badge badge-<?= $a['status'] === 'ACTIVE' ? 'success' : 'danger' ?>"><?= e($a['status']) ?></span></td>
                <td style="white-space:nowrap">
                    <?php if (Auth::hasPermission('edit')): ?>
                    <a class="btn btn-primary" style="padding:4px 10px;font-size:12px" href="?edit=<?= (int)$a['id'] ?>#adForm">Edit</a>
                    <form method="POST" style="display:inline"><?= CSRF::getInputField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                        <button class="btn btn-primary" style="padding:4px 10px;font-size:12px;background:#7f8c8d"><?= $a['status'] === 'ACTIVE' ? 'Pause' : 'Activate' ?></button></form>
                    <?php endif; ?>
                    <?php if (Auth::hasPermission('delete')): ?>
                    <form method="POST" style="display:inline"><?= CSRF::getInputField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                        <button class="btn btn-danger" style="padding:4px 10px;font-size:12px" onclick="return confirm('Delete this ad?')">Delete</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php if ($showForm): $a = $editing ?: ['id' => 0, 'name' => '', 'type' => 'BANNER', 'image_url' => '', 'video_url' => '', 'destination_url' => '', 'creative_html' => '', 'priority' => 0, 'status' => 'ACTIVE', 'start_date' => null, 'end_date' => null]; ?>
<div class="content-box" style="max-width:760px" id="adForm">
    <h3 style="margin-bottom:16px"><?= $editing ? 'Edit ad' : 'New ad' ?></h3>
    <form method="POST" enctype="multipart/form-data">
        <?= CSRF::getInputField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px">
            <div class="form-group"><label>Name (internal)</label><input class="form-control" name="name" required maxlength="100" value="<?= e($a['name']) ?>"></div>
            <div class="form-group"><label>Type</label><select class="form-control" name="type">
                <?php foreach (['BANNER' => 'Banner', 'INTERSTITIAL' => 'Interstitial (before video)', 'VIDEO' => 'Video ad (before video)'] as $t => $l): ?>
                    <option value="<?= $t ?>" <?= $a['type'] === $t ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Priority</label><input class="form-control" type="number" name="priority" value="<?= (int)$a['priority'] ?>"></div>
        </div>

        <h4 style="margin:8px 0">Option A — your own ad</h4>
        <div class="form-group"><label>Image <small>(upload, banner ~1200×300 or square for interstitial)</small></label>
            <input class="form-control" type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
            <?php if ($a['image_url']): ?><img src="<?= e($a['image_url']) ?>" alt="" style="max-width:240px;margin-top:6px;border-radius:4px"><?php endif; ?></div>
        <div class="form-group"><label>…or image URL</label><input class="form-control" name="image_url" value="<?= e($a['image_url']) ?>" placeholder="https://…"></div>
        <div class="form-group"><label>Video URL <small>(MP4, for VIDEO ads)</small></label><input class="form-control" name="video_url" value="<?= e($a['video_url']) ?>" placeholder="https://…/ad.mp4"></div>
        <div class="form-group"><label>Click destination</label><input class="form-control" name="destination_url" value="<?= e($a['destination_url']) ?>" placeholder="https://advertiser.com or https://t.me/…"></div>

        <h4 style="margin:8px 0">Option B — ad network code</h4>
        <div class="form-group">
            <label>HTML / JS from your ad network <small>(e.g. Adsterra, Monetag banner code)</small></label>
            <?php if (isSuperAdmin()): ?>
                <textarea class="form-control" name="creative_html" style="font-family:monospace;min-height:120px" placeholder="<script ...></script>"><?= e($a['creative_html']) ?></textarea>
                <small style="color:#7f8c8d">Runs in an isolated (sandboxed) frame, so it can't access user sessions. Used instead of the image if both are set.</small>
            <?php else: ?>
                <p style="color:#7f8c8d">Only a SUPER_ADMIN can add ad network code.</p>
            <?php endif; ?>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
            <div class="form-group"><label>Start <small>(optional)</small></label><input class="form-control" type="datetime-local" name="start_date" value="<?= $a['start_date'] ? e(date('Y-m-d\TH:i', strtotime($a['start_date']))) : '' ?>"></div>
            <div class="form-group"><label>End <small>(optional)</small></label><input class="form-control" type="datetime-local" name="end_date" value="<?= $a['end_date'] ? e(date('Y-m-d\TH:i', strtotime($a['end_date']))) : '' ?>"></div>
            <div class="form-group"><label>&nbsp;</label><label style="font-weight:normal"><input type="checkbox" name="status" value="INACTIVE" <?= $a['status'] === 'INACTIVE' ? 'checked' : '' ?>> Paused</label></div>
        </div>
        <button class="btn btn-primary" type="submit">Save ad</button> <a class="btn" style="background:#ecf0f1" href="<?= $self ?>">Cancel</a>
    </form>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
