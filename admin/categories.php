<?php
/**
 * Admin → Categories: add, rename, reorder, hide, delete (only when empty).
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';

$db = db();
$self = '/admin/categories.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    requirePermission('edit', $self);
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'save') {
        $name = trim($_POST['name'] ?? '');
        $icon = mb_substr(trim($_POST['icon'] ?? ''), 0, 8);
        $order = (int)($_POST['display_order'] ?? 0);
        $status = ($_POST['status'] ?? '') === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE';
        if ($name === '' || mb_strlen($name) > 100) {
            flash('error', 'Name is required (max 100).');
        } elseif ($id) {
            $db->execute("UPDATE categories SET name=?, icon=?, display_order=?, status=? WHERE id=?", [$name, $icon, $order, $status, $id]);
            flash('success', 'Category saved.');
        } else {
            $slug = Security::createSlug($name);
            if ($db->fetchOne("SELECT id FROM categories WHERE slug = ?", [$slug])) {
                $slug .= '-' . bin2hex(random_bytes(2));
            }
            $db->execute("INSERT INTO categories (name, slug, icon, display_order, status) VALUES (?,?,?,?,?)", [$name, $slug, $icon, $order, $status]);
            flash('success', 'Category added.');
        }
    } elseif ($action === 'delete') {
        requirePermission('delete', $self);
        $count = $db->fetchOne("SELECT COUNT(*) c FROM videos WHERE category_id = ?", [$id])['c'];
        if ($count) {
            flash('error', "This category has $count videos. Move them first, or hide the category.");
        } else {
            $db->execute("DELETE FROM categories WHERE id = ?", [$id]);
            flash('success', 'Category deleted.');
        }
    }
    header('Location: ' . $self);
    exit;
}

$cats = $db->fetchAll("SELECT c.*, (SELECT COUNT(*) FROM videos v WHERE v.category_id = c.id) AS videos FROM categories c ORDER BY display_order, name");

$pageTitle = 'Categories';
include __DIR__ . '/includes/header.php';
?>

<?php renderFlash(); ?>

<div class="content-box" style="max-width:900px">
    <?php // Forms live outside the table (a <form> can't wrap <td>s); inputs link via form="…". ?>
    <?php foreach ($cats as $c): ?>
        <form method="POST" id="cat<?= (int)$c['id'] ?>"><?= CSRF::getInputField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"></form>
        <form method="POST" id="del<?= (int)$c['id'] ?>"><?= CSRF::getInputField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"></form>
    <?php endforeach; ?>
    <form method="POST" id="catNew"><?= CSRF::getInputField() ?><input type="hidden" name="action" value="save"></form>

    <table>
        <thead><tr><th>Icon</th><th>Name</th><th>Order</th><th>Hidden</th><th>Videos</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cats as $c): $f = 'cat' . (int)$c['id']; ?>
        <tr>
            <td><input form="<?= $f ?>" class="form-control" name="icon" value="<?= e($c['icon']) ?>" style="width:60px"></td>
            <td><input form="<?= $f ?>" class="form-control" name="name" value="<?= e($c['name']) ?>" required></td>
            <td><input form="<?= $f ?>" class="form-control" type="number" name="display_order" value="<?= (int)$c['display_order'] ?>" style="width:80px"></td>
            <td><input form="<?= $f ?>" type="checkbox" name="status" value="INACTIVE" <?= $c['status'] === 'INACTIVE' ? 'checked' : '' ?>></td>
            <td><?= (int)$c['videos'] ?></td>
            <td style="white-space:nowrap">
                <button form="<?= $f ?>" class="btn btn-primary" style="padding:4px 10px;font-size:12px">Save</button>
                <?php if (Auth::hasPermission('delete') && !$c['videos']): ?>
                    <button form="del<?= (int)$c['id'] ?>" class="btn btn-danger" style="padding:4px 10px;font-size:12px" onclick="return confirm('Delete category?')">Delete</button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <tr>
            <td><input form="catNew" class="form-control" name="icon" placeholder="🎬" style="width:60px"></td>
            <td><input form="catNew" class="form-control" name="name" placeholder="New category" required></td>
            <td><input form="catNew" class="form-control" type="number" name="display_order" value="<?= count($cats) + 1 ?>" style="width:80px"></td>
            <td></td><td></td>
            <td><button form="catNew" class="btn btn-primary" style="padding:4px 10px;font-size:12px">+ Add</button></td>
        </tr>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
