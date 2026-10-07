<?php
/**
 * Chunked video upload endpoint (admin only, CSRF protected). See includes/chunk-upload.php.
 *   POST action=init   name, size
 *   POST action=chunk  upload_id, index, chunk (file)
 *   POST action=finish upload_id
 *   POST action=abort  upload_id
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/../includes/chunk-upload.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (!Auth::hasPermission('create')) {
    out(['success' => false, 'error' => 'No permission'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['success' => false, 'error' => 'POST only'], 405);
}
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    out(['success' => false, 'error' => 'Chunk is larger than this server accepts (post_max_size ' . ini_get('post_max_size') . ').'], 413);
}
if (!CSRF::validateRequest()) {
    out(['success' => false, 'error' => 'Session expired. Reload the page.'], 403);
}

$id = (string)($_POST['upload_id'] ?? '');
switch ($_POST['action'] ?? '') {
    case 'init':
        out(ChunkUpload::init((string)($_POST['name'] ?? ''), (int)($_POST['size'] ?? 0)));
    case 'chunk':
        out(ChunkUpload::chunk($id, (int)($_POST['index'] ?? -1), $_FILES['chunk'] ?? []));
    case 'finish':
        out(ChunkUpload::finish($id));
    case 'abort':
        ChunkUpload::abort($id);
        out(['success' => true]);
}
out(['success' => false, 'error' => 'Unknown action'], 400);
