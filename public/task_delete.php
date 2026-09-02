<?php
/**
 * 删除任务 - 端点
 * Delete Task Endpoint
 *
 * 权限: 管理员 OR 任务创建者 (tasks.created_by)
 * 成功后跳回上一页(列表或详情)
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();
$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('仅支持 POST');
}

$taskId = (int)($_POST['task_id'] ?? 0);
$back   = $_POST['back'] ?? '';  // 可选: 删除后跳回位置

if ($taskId <= 0) {
    $_SESSION['error_message'] = '无效的任务 ID';
    redirect('my_tasks.php');
}

$task = queryOneDb("SELECT * FROM tasks WHERE id = ?", [$taskId]);
if (!$task) {
    $_SESSION['error_message'] = '任务不存在';
    redirect('my_tasks.php');
}

if (!canDeleteTask($task, $user)) {
    http_response_code(403);
    die('权限不足:只有任务创建者或管理员可以删除任务');
}

$projectId = (int)$task['project_id'];

try {
    $pdo = getDbConnection();
    $pdo->beginTransaction();

    // 先记录子任务数(日志用)
    $subCount = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE parent_task_id = {$taskId}")->fetchColumn();

    // task_attachments 没 CASCADE,手动清
    $pdo->prepare("DELETE FROM task_attachments WHERE task_id = ?")->execute([$taskId]);
    // 删任务: 依赖/状态/阻塞/评论/checklist/time_logs/assignments 等都 CASCADE
    $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([$taskId]);

    logOperation($user['id'], 'delete', 'task', $taskId, [
        'title'            => $task['title'],
        'project_id'       => $projectId,
        'subtask_count'    => $subCount,
    ]);

    $pdo->commit();
    $_SESSION['success_message'] = "任务「{$task['title']}」已删除";
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error_message'] = '删除失败: ' . $e->getMessage();
}

// 跳回
if ($back === 'detail') {
    redirect('project_dashboard.php?project_id=' . $projectId);
} elseif ($back === 'list' && $projectId > 0) {
    redirect('tasks.php?project_id=' . $projectId);
} else {
    redirect('my_tasks.php');
}
