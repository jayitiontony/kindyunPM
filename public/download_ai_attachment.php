<?php
/**
 * 下载 AI 对话附件
 * Download AI Chat Attachment
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();
$user = getCurrentUser();

$file = basename($_GET['file'] ?? '');
if (!preg_match('/^[A-Za-z0-9_.-]+$/', $file)) {
    http_response_code(400);
    die('非法文件名');
}
$path = __DIR__ . '/../database/ai_attachments/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    die('文件不存在');
}

logOperation($user['id'], 'download', 'ai_attachment', null, ['file' => $file]);

// Content-Type 推断
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime = [
    'txt'=>'text/plain', 'md'=>'text/markdown', 'json'=>'application/json',
    'csv'=>'text/csv', 'pdf'=>'application/pdf', 'png'=>'image/png',
    'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'gif'=>'image/gif',
    'svg'=>'image/svg+xml', 'zip'=>'application/zip', 'log'=>'text/plain',
    'xml'=>'application/xml', 'html'=>'text/html', 'htm'=>'text/html',
];
header('Content-Type: ' . ($mime[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
