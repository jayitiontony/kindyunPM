<?php
/**
 * 删除项目 - 端点
 * Delete Project Endpoint
 *
 * 权限: 管理员 OR 项目创建者 (projects.created_by)
 * 通过 POST 触发,带 ?id=<project_id> (从 projects.php 列表"删除"按钮提交)
 * 成功后跳回项目列表
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

$projectId = (int)($_POST['project_id'] ?? 0);
if ($projectId <= 0) {
    $_SESSION['error_message'] = '无效的项目 ID';
    redirect('projects.php');
}

$project = queryOneDb("SELECT * FROM projects WHERE id = ?", [$projectId]);
if (!$project) {
    $_SESSION['error_message'] = '项目不存在';
    redirect('projects.php');
}

if (!canDeleteProject($project, $user)) {
    http_response_code(403);
    die('权限不足:只有项目创建者或管理员可以删除项目');
}

try {
    $pdo = getDbConnection();
    $pdo->beginTransaction();

    // 收集要记日志用的元数据
    $taskCount = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE project_id = {$projectId}")->fetchColumn();
    $memberCount = (int)$pdo->query("SELECT COUNT(*) FROM project_members WHERE project_id = {$projectId}")->fetchColumn();

    // 删项目: 依赖 ON DELETE CASCADE 会自动删 tasks / task_dependencies / milestones / project_members / task_comments 等
    // 但 task_attachments 没有 CASCADE,我们手动清
    $pdo->prepare("DELETE FROM task_attachments WHERE task_id IN (SELECT id FROM tasks WHERE project_id = ?)")->execute([$projectId]);
    $pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$projectId]);

    logOperation($user['id'], 'delete', 'project', $projectId, [
        'name'         => $project['name'],
        'task_count'   => $taskCount,
        'member_count' => $memberCount,
    ]);

    $pdo->commit();
    $_SESSION['success_message'] = "项目「{$project['name']}」已删除(同时清理 {$taskCount} 个任务,{$memberCount} 个成员)";
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error_message'] = '删除失败: ' . $e->getMessage();
}

redirect('projects.php');
