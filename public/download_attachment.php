<?php
/**
 * 附件下载
 * Attachment Download
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$user = getCurrentUser();
$attId = (int)($_GET['id'] ?? 0);

$att = queryOneDb("SELECT ta.*, t.project_id FROM task_attachments ta JOIN tasks t ON ta.task_id = t.id WHERE ta.id = ?", [$attId]);
if (!$att) { http_response_code(404); echo '附件不存在'; exit; }

// 权限: 任务所属项目成员/项目经理/系统管理员
$isPM = queryOneDb("SELECT id FROM projects WHERE id = ? AND manager_id = ?", [$att['project_id'], $user['id']]);
$isMember = isProjectMember($att['project_id'], $user['id']);
$isAssignee = queryOneDb("SELECT id FROM tasks WHERE id = ? AND assignee_id = ?", [$att['task_id'], $user['id']]);
if (!$isPM && !$isMember && !$isAssignee && !isAdmin()) {
    http_response_code(403);
    echo '权限不足';
    exit;
}

if (!file_exists($att['file_path'])) {
    http_response_code(404);
    echo '文件已丢失';
    exit;
}

// 记录下载
logOperation($user['id'], 'download', 'task_attachment', $attId, [
    'task_id' => (int)$att['task_id'], 'filename' => $att['original_name']
]);

$filename = $att['original_name'];
// RFC 5987 中文文件名
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$encoded = rawurlencode($filename);
if (preg_match('/MSIE|Trident/', $ua)) {
    $contentDisp = "attachment; filename=" . urlencode($filename);
} else {
    $contentDisp = "attachment; filename*=UTF-8''" . $encoded;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: ' . $contentDisp);
header('Content-Length: ' . filesize($att['file_path']));
header('Cache-Control: no-cache');
readfile($att['file_path']);
