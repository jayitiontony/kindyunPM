<?php
/**
 * 任务详情页 (核心交互页)
 * Task Detail Page
 *
 * 全部功能:
 *   - 基础信息 / 进度
 *   - 状态变更 (可改状态 + 完成百分比 + 备注)
 *   - 阻塞: 填原因 + 请求谁协助 / 解除
 *   - 依赖关系 (本任务依赖 / 谁依赖本任务)
 *   - 标签
 *   - 子清单 checklist
 *   - 工时记录
 *   - 附件 (上传 / 删除)
 *   - 评论 / 讨论
 *   - 状态变更清单 / 阻塞历史 / 指派历史 / 操作日志
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';

requireLogin();

$user   = getCurrentUser();
$taskId = (int)($_GET['task_id'] ?? 0);
$error  = '';
$success = '';

// 取任务
$task = queryOneDb(
    "SELECT t.*, p.name as project_name, p.manager_id as project_manager_id,
            u.username as assignee_name, u.name as assignee_real_name,
            u.phone as assignee_phone, u.email as assignee_email
     FROM tasks t
     JOIN projects p ON t.project_id = p.id
     LEFT JOIN users u ON t.assignee_id = u.id
     WHERE t.id = ?",
    [$taskId]
);
if (!$task) die('任务不存在');

$project = queryOneDb("SELECT * FROM projects WHERE id = ?", [$task['project_id']]);
$isProjectManager = ($project['manager_id'] == $user['id']) || isAdmin();
$isAssignee = ($task['assignee_id'] == $user['id']);
$isProjectMember = isProjectMember($task['project_id'], $user['id']);
if (!$isProjectManager && !$isProjectMember && !isAdmin()) die('权限不足');

$canUpdateStatus = $isAssignee || $isProjectManager || isAdmin();
$canEdit         = $isProjectManager || isAdmin();
$canReassign     = $isProjectManager || isAdmin();
$canComment      = $isProjectMember || $isProjectManager || isAdmin();
$canTimeLog      = $isAssignee || $isProjectManager || isAdmin();
$overdue         = isTaskOverdue($task);

// ========================================================================
// 通用 POST 调度
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        // ------------------ 状态变更 ------------------
        if ($action === 'update_status') {
            if (!$canUpdateStatus) throw new Exception('权限不足');
            $newStatus   = $_POST['new_status'] ?? '';
            $newProgress = max(0, min(100, (int)($_POST['new_progress'] ?? $task['progress'])));
            $note        = trim($_POST['note'] ?? '');
            $validStatuses = ['todo', 'in_progress', 'blocked', 'done'];
            if (!in_array($newStatus, $validStatuses, true)) throw new Exception('无效状态');
            if ($newStatus === 'in_progress') {
                $ready = checkTaskReady($taskId);
                if (!$ready['ready']) {
                    $names = array_map(function($d) { return '[' . $d['id'] . '] ' . $d['dep_title']; }, $ready['pending']);
                    throw new Exception('任务有未完成依赖,无法进入"进行中": ' . implode(', ', $names));
                }
            }
            if ($newStatus === 'done') $newProgress = 100;
            $wasBlocked = ($task['status'] === 'blocked');
            $oldStatus   = $task['status'];
            $oldProgress = (int)$task['progress'];
            executeDb("UPDATE tasks SET status=?, progress=?, updated_at=CURRENT_TIMESTAMP WHERE id=?", [$newStatus, $newProgress, $taskId]);
            addTaskStatusChange($taskId, $user['id'], $oldStatus, $newStatus, $oldProgress, $newProgress, $note);
            logOperation($user['id'], 'status_change', 'task', $taskId, [
                'old_status' => $oldStatus, 'new_status' => $newStatus,
                'old_progress' => $oldProgress, 'new_progress' => $newProgress,
                'note' => $note,
            ]);
            if ($wasBlocked && $newStatus !== 'blocked') {
                $openBlocks = getTaskBlockedInfo($taskId, true);
                foreach ($openBlocks as $ob) {
                    resolveTaskBlockedInfo($ob['id'], $user['id'], '随状态变更新自动解除');
                    logOperation($user['id'], 'unblock', 'task', $taskId, ['blocked_info_id' => (int)$ob['id'], 'auto' => true]);
                }
            }
            // 通知负责人(如果不是自己改的)
            if ($task['assignee_id'] && $task['assignee_id'] != $user['id']) {
                addNotification($task['assignee_id'], 'task_status',
                    "任务状态变更: " . $task['title'],
                    $user['name'] . " 把任务状态从 " . getTaskStatusText($oldStatus) . " 改为 " . getTaskStatusText($newStatus) . " (进度 " . $oldProgress . "% → " . $newProgress . "%)",
                    'task', $taskId);
            }
            $success = '状态已更新';
        }

        // ------------------ 标记阻塞 ------------------
        elseif ($action === 'mark_blocked') {
            if (!$canUpdateStatus) throw new Exception('权限不足');
            $blockReason   = trim($_POST['block_reason'] ?? '');
            $assistUserId  = !empty($_POST['requested_assist_user_id']) ? (int)$_POST['requested_assist_user_id'] : null;
            $note          = trim($_POST['note'] ?? '');
            if (empty($blockReason)) throw new Exception('阻塞原因不能为空');
            addTaskBlockedInfo($taskId, $user['id'], $blockReason, $assistUserId);
            $oldStatus = $task['status'];
            $oldProgress = (int)$task['progress'];
            executeDb("UPDATE tasks SET status='blocked', updated_at=CURRENT_TIMESTAMP WHERE id=?", [$taskId]);
            addTaskStatusChange($taskId, $user['id'], $oldStatus, 'blocked', $oldProgress, $oldProgress, $note ?: ('阻塞: ' . $blockReason));
            logOperation($user['id'], 'block', 'task', $taskId, [
                'block_reason' => $blockReason, 'requested_assist_user_id' => $assistUserId, 'note' => $note,
            ]);
            if ($assistUserId && $assistUserId != $user['id']) {
                addNotification($assistUserId, 'request_assist',
                    "协助请求: " . $task['title'],
                    $user['name'] . " 在任务中遇到了困难,请求你的协助。\n原因: " . $blockReason,
                    'task', $taskId);
            }
            $success = '已标记为阻塞,并提交协助请求';
        }

        // ------------------ 解除阻塞 ------------------
        elseif ($action === 'resolve_blocked') {
            if (!$canUpdateStatus) throw new Exception('权限不足');
            $blockedInfoId = (int)($_POST['blocked_info_id'] ?? 0);
            $resolveNote   = trim($_POST['resolve_note'] ?? '');
            $newStatus     = $_POST['new_status_after_resolve'] ?? 'in_progress';
            $newProgress   = max(0, min(100, (int)($_POST['new_progress_after_resolve'] ?? $task['progress'])));
            if (!in_array($newStatus, ['todo', 'in_progress', 'done'], true)) $newStatus = 'in_progress';
            resolveTaskBlockedInfo($blockedInfoId, $user['id'], $resolveNote);
            $stillOpen = getTaskBlockedInfo($taskId, true);
            if (empty($stillOpen)) {
                $oldStatus = $task['status'];
                $oldProgress = (int)$task['progress'];
                executeDb("UPDATE tasks SET status=?, progress=?, updated_at=CURRENT_TIMESTAMP WHERE id=?", [$newStatus, $newProgress, $taskId]);
                addTaskStatusChange($taskId, $user['id'], $oldStatus, $newStatus, $oldProgress, $newProgress, '解除阻塞: ' . $resolveNote);
                logOperation($user['id'], 'status_change', 'task', $taskId, [
                    'old_status' => $oldStatus, 'new_status' => $newStatus, 'reason' => 'unblock',
                ]);
            }
            logOperation($user['id'], 'unblock', 'task', $taskId, ['blocked_info_id' => $blockedInfoId, 'resolve_note' => $resolveNote]);
            $success = '阻塞已解除';
        }

        // ------------------ 评论 ------------------
        elseif ($action === 'add_comment') {
            if (!$canComment) throw new Exception('权限不足:不能评论');
            $content = trim($_POST['comment_content'] ?? '');
            if (empty($content)) throw new Exception('评论内容不能为空');
            addTaskComment($taskId, $user['id'], $content);
            logOperation($user['id'], 'comment', 'task', $taskId, ['content_preview' => mb_substr($content, 0, 100)]);
            // 通知任务负责人(如果评论人不是负责人)
            if ($task['assignee_id'] && $task['assignee_id'] != $user['id']) {
                addNotification($task['assignee_id'], 'task_comment',
                    "新评论: " . $task['title'],
                    $user['name'] . " 评论了任务: " . mb_substr($content, 0, 50),
                    'task', $taskId);
            }
            // 通知项目经理(如果不是同一人)
            if ($project['manager_id'] != $user['id'] && $project['manager_id'] != $task['assignee_id']) {
                addNotification($project['manager_id'], 'task_comment',
                    "新评论: " . $task['title'],
                    $user['name'] . " 评论了任务: " . mb_substr($content, 0, 50),
                    'task', $taskId);
            }
            $success = '评论已发布';
        }
        elseif ($action === 'delete_comment') {
            $cmtId = (int)($_POST['comment_id'] ?? 0);
            $cmt = queryOneDb("SELECT * FROM task_comments WHERE id = ? AND task_id = ?", [$cmtId, $taskId]);
            if (!$cmt) throw new Exception('评论不存在');
            if ($cmt['user_id'] != $user['id'] && !isAdmin()) throw new Exception('权限不足:只能删除自己的评论');
            deleteTaskComment($cmtId);
            logOperation($user['id'], 'delete_comment', 'task', $taskId, ['comment_id' => $cmtId]);
            $success = '评论已删除';
        }

        // ------------------ 标签 ------------------
        elseif ($action === 'set_tags') {
            if (!$canEdit) throw new Exception('权限不足');
            $tagIds = $_POST['tag_ids'] ?? [];
            setTaskTags($taskId, $tagIds);
            logOperation($user['id'], 'update', 'task', $taskId, ['action_sub' => 'set_tags', 'tag_ids' => $tagIds]);
            $success = '标签已更新';
        }
        elseif ($action === 'add_tag') {
            if (!$canEdit) throw new Exception('权限不足');
            $name  = trim($_POST['new_tag_name'] ?? '');
            $color = $_POST['new_tag_color'] ?? '#3498db';
            if (empty($name)) throw new Exception('标签名不能为空');
            addTag($name, $color, $task['project_id'], $user['id']);
            logOperation($user['id'], 'create', 'tag', null, ['name' => $name, 'color' => $color, 'project_id' => $task['project_id']]);
            $success = '标签已创建';
        }

        // ------------------ checklist ------------------
        elseif ($action === 'add_checklist') {
            if (!$canEdit && !$canUpdateStatus) throw new Exception('权限不足');
            $content = trim($_POST['checklist_content'] ?? '');
            if (empty($content)) throw new Exception('子项内容不能为空');
            addChecklistItem($taskId, $content, $user['id']);
            logOperation($user['id'], 'create', 'task_checklist', null, ['task_id' => $taskId, 'content' => $content]);
            $success = '子项已添加';
        }
        elseif ($action === 'toggle_checklist') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $isDone = !empty($_POST['is_done']) ? 1 : 0;
            toggleChecklistItem($itemId, $isDone);
            logOperation($user['id'], 'update', 'task_checklist', $itemId, ['is_done' => $isDone]);
            $success = '子项已更新';
        }
        elseif ($action === 'delete_checklist') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            deleteChecklistItem($itemId);
            logOperation($user['id'], 'delete', 'task_checklist', $itemId, ['task_id' => $taskId]);
            $success = '子项已删除';
        }

        // ------------------ 工时 ------------------
        elseif ($action === 'add_time_log') {
            if (!$canTimeLog) throw new Exception('权限不足');
            $hours    = (float)($_POST['hours'] ?? 0);
            $workDate = $_POST['work_date'] ?: date('Y-m-d');
            $note     = trim($_POST['time_note'] ?? '');
            if ($hours <= 0) throw new Exception('工时必须 > 0');
            addTimeLog($taskId, $user['id'], $hours, $workDate, $note);
            logOperation($user['id'], 'add_time_log', 'task', $taskId, ['hours' => $hours, 'work_date' => $workDate, 'note' => $note]);
            $success = '工时已记录';
        }
        elseif ($action === 'delete_time_log') {
            $logId = (int)($_POST['log_id'] ?? 0);
            deleteTimeLog($logId);
            logOperation($user['id'], 'delete_time_log', 'task', $taskId, ['log_id' => $logId]);
            $success = '工时记录已删除';
        }

        // ------------------ 附件 ------------------
        elseif ($action === 'upload_attachment') {
            if (!$canComment) throw new Exception('权限不足');
            if (empty($_FILES['attachment'])) throw new Exception('请选择文件');
            $f = $_FILES['attachment'];
            if ($f['error'] !== UPLOAD_ERR_OK) throw new Exception('上传失败,错误码 ' . $f['error']);
            if ($f['size'] > 20 * 1024 * 1024) throw new Exception('文件过大 (>20MB)');
            $uploadsDir = __DIR__ . '/../uploads/tasks';
            if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);
            $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
            $safeName = 'task' . $taskId . '_' . date('YmdHis') . '_' . substr(md5($f['name'] . microtime(true)), 0, 8) . ($ext ? '.' . $ext : '');
            $target = $uploadsDir . '/' . $safeName;
            if (!move_uploaded_file($f['tmp_name'], $target)) throw new Exception('文件保存失败');
            $note = trim($_POST['attachment_note'] ?? '');
            addTaskAttachment($taskId, $user['id'], $safeName, $f['name'], $f['size'], $target, $note);
            logOperation($user['id'], 'upload', 'task_attachment', null, ['task_id' => $taskId, 'filename' => $f['name'], 'size' => $f['size']]);
            $success = '附件已上传';
        }
        elseif ($action === 'delete_attachment') {
            $attId = (int)($_POST['attachment_id'] ?? 0);
            deleteTaskAttachment($attId, $user['id']);
            logOperation($user['id'], 'delete', 'task_attachment', $attId, ['task_id' => $taskId]);
            $success = '附件已删除';
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }

    // 重读
    $task = queryOneDb(
        "SELECT t.*, p.name as project_name, p.manager_id as project_manager_id,
                u.username as assignee_name, u.name as assignee_real_name
         FROM tasks t JOIN projects p ON t.project_id = p.id
         LEFT JOIN users u ON t.assignee_id = u.id
         WHERE t.id = ?", [$taskId]);
    $overdue = isTaskOverdue($task);
}

// ========================================================================
// 数据准备
// ========================================================================
$statusChanges  = getTaskStatusChanges($taskId);
$dependencies   = getTaskDependencies($taskId);
$dependents     = getTaskDependents($taskId);
$readyInfo      = checkTaskReady($taskId);
$blockedInfo    = getTaskBlockedInfo($taskId);
$assignHist     = getTaskAssignmentHistory($taskId);
$taskOpLogs     = getOperationsByTarget('task', $taskId, 500);
$comments       = getTaskComments($taskId);
$taskTags       = getTaskTags($taskId);
$projectTags    = getProjectTags($task['project_id']);
$checklist      = getTaskChecklist($taskId);
$checklistProg  = getChecklistProgress($taskId);
$timeLogs       = getTaskTimeLogs($taskId);
$attachments    = getTaskAttachments($taskId);
$projectMemberOptions = queryDb(
    "SELECT u.id, u.username, u.name, u.expertise FROM project_members pm
     JOIN users u ON pm.user_id = u.id
     WHERE pm.project_id = ? AND pm.status = 'active'",
    [$task['project_id']]
);
$unreadNotifCount = getUnreadNotificationCount($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>任务详情 - <?php echo htmlspecialchars($task['title']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
</head>
<body>
<?php
echo renderHeader('📄 任务详情', $user, $unreadNotifCount, null, [
    'project_id' => (int)$task['project_id'],
    'task_id'    => (int)$taskId,
    'project_name' => $task['project_name'] ?? '',
    'sub_active' => 'task_detail',
]);
?>

<div class="container">
    <?php if (!empty($error)) echo showError($error); ?>
    <?php if (!empty($success)) echo showSuccess($success); ?>

    <!-- 任务基础信息 -->
    <div class="card">
        <div class="section-header">
            <h3 style="margin:0;">
                📄 <?php echo htmlspecialchars($task['title']); ?>
                <span class="status-badge status-<?php echo htmlspecialchars($task['status']); ?>" style="margin-left:8px;"><?php echo getTaskStatusText($task['status']); ?></span>
                <?php if ($overdue): ?>
                    <span class="badge-overdue">⚠️ 已逾期</span>
                <?php elseif ($task['due_date'] && $task['status'] !== 'done' && strtotime($task['due_date']) <= strtotime('+3 days')): ?>
                    <span class="badge-warn">⏰ 即将到期</span>
                <?php endif; ?>
            </h3>
            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                <a href="project_dashboard.php?project_id=<?php echo (int)$task['project_id']; ?>" class="btn btn-sm">📊 仪表盘</a>
                <a href="tasks.php?project_id=<?php echo (int)$task['project_id']; ?>" class="btn btn-sm">📋 任务列表</a>
                <?php if (canDeleteTask($task, $user)): ?>
                    <form method="POST" action="task_delete.php" style="display:inline;"
                          onsubmit="return confirm('确认删除任务「<?php echo htmlspecialchars($task['title'], ENT_QUOTES); ?>」?\n所有子任务/评论/附件/工时都会被一并清理。')">
                        <input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>">
                        <input type="hidden" name="back" value="detail">
                        <button type="submit" class="btn btn-danger btn-sm">🗑 删除任务</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <div class="task-meta">
            <div><strong>项目:</strong> <a href="project_dashboard.php?project_id=<?php echo (int)$task['project_id']; ?>"><?php echo htmlspecialchars($task['project_name']); ?></a></div>
            <div><strong>负责人:</strong>
                <?php if ($task['assignee_real_name']): ?>
                    <?php echo htmlspecialchars($task['assignee_real_name']); ?> (<?php echo htmlspecialchars($task['assignee_name']); ?>)
                <?php else: ?>
                    <span style="color:#999;">未分配</span>
                <?php endif; ?>
            </div>
            <div><strong>优先级:</strong> <?php echo htmlspecialchars(ucfirst($task['priority'])); ?></div>
            <div><strong>开始 / 截止:</strong> <?php echo formatDate($task['start_date']); ?> / <?php echo formatDate($task['due_date']); ?></div>
            <div><strong>创建时间:</strong> <?php echo htmlspecialchars($task['created_at']); ?> | <strong>最后更新:</strong> <?php echo htmlspecialchars($task['updated_at']); ?></div>
            <div>
                <strong>标签:</strong>
                <?php if (empty($taskTags)): ?>
                    <span style="color:#999;">无</span>
                <?php else: ?>
                    <?php foreach ($taskTags as $t): ?>
                        <span class="tag-pill" style="background:<?php echo htmlspecialchars($t['color']); ?>"><?php echo htmlspecialchars($t['name']); ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div><strong>工时:</strong> 预估 <?php echo number_format((float)$task['estimated_hours'], 1); ?>h / 实际 <?php echo number_format((float)$task['actual_hours'], 1); ?>h</div>
        </div>
        <div class="task-progress" style="margin-top:12px;">
            <strong>完成进度:</strong>
            <div class="progress-bar" style="width:300px; display:inline-block; vertical-align:middle; margin-left:8px;" title="<?php echo (int)$task['progress']; ?>%">
                <div class="progress-bar-fill" style="width:<?php echo (int)$task['progress']; ?>%;"></div>
                <span class="progress-bar-text"><?php echo (int)$task['progress']; ?>%</span>
            </div>
            <?php if ($checklistProg['total'] > 0): ?>
                <span style="margin-left:15px;"><strong>checklist:</strong> <?php echo $checklistProg['done']; ?> / <?php echo $checklistProg['total']; ?> (<?php echo $checklistProg['rate']; ?>%)</span>
            <?php endif; ?>
        </div>
        <div class="task-desc" style="margin-top:12px;">
            <strong>任务描述:</strong>
            <div style="white-space:pre-wrap; padding:8px; background:#f8f9fa; border-radius:4px; margin-top:5px;"><?php echo nl2br(htmlspecialchars($task['description'] ?: '(无)')); ?></div>
        </div>
        <div class="task-actions" style="margin-top:12px;">
            <?php if ($canEdit): ?><a href="task_edit.php?task_id=<?php echo (int)$taskId; ?>" class="btn btn-success">✏️ 编辑任务</a><?php endif; ?>
            <?php if ($canReassign): ?><a href="task_reassign.php?task_id=<?php echo (int)$taskId; ?>" class="btn btn-warning">📤 重新指派</a><?php endif; ?>
            <a href="project_dashboard.php?project_id=<?php echo (int)$task['project_id']; ?>" class="btn btn-primary">📊 项目仪表盘</a>
        </div>
    </div>

    <div class="row-2col">
        <!-- 状态变更 -->
        <div class="card">
            <h3>🔄 状态变更 (可同时调整进度)</h3>
            <?php if (!$canUpdateStatus): ?>
                <p style="color:#999;">您没有变更此任务状态的权限</p>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="update_status">
                    <div class="form-group">
                        <label>目标状态</label>
                        <select name="new_status" required>
                            <?php
                            $curStatus = $task['status'];
                            $optList = ['todo' => '待处理', 'in_progress' => '进行中', 'blocked' => '阻塞', 'done' => '已完成'];
                            foreach ($optList as $k => $v): ?>
                                <option value="<?php echo $k; ?>" <?php echo $curStatus === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$readyInfo['ready']): ?>
                            <small class="badge-warn" style="display:block; margin-top:4px;">⚠️ 当前有 <?php echo count($readyInfo['pending']); ?> 个依赖未完成,不能直接进入"进行中"</small>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>完成百分比</label>
                        <input type="number" name="new_progress" min="0" max="100" step="5" value="<?php echo (int)$task['progress']; ?>">
                    </div>
                    <div class="form-group">
                        <label>变更说明</label>
                        <textarea name="note" rows="2" placeholder="例如:完成了 XX 模块"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">保存状态变更</button>
                </form>
            <?php endif; ?>
        </div>

        <!-- 阻塞 -->
        <div class="card">
            <h3>🆘 阻塞管理</h3>
            <?php
            $openBlocks = array_filter($blockedInfo, function($b) { return $b['status'] === 'open'; });
            if (!empty($openBlocks)): ?>
                <div class="alert alert-error" style="background:#fff3cd; color:#856404; border-color:#ffeeba;">⚠️ 当前有 <?php echo count($openBlocks); ?> 个未解决的阻塞</div>
                <?php foreach ($openBlocks as $ob): ?>
                    <div style="border:1px solid #f5c6cb; background:#fdeaea; padding:8px; border-radius:4px; margin-bottom:8px;">
                        <div><strong>原因:</strong> <?php echo htmlspecialchars($ob['block_reason']); ?></div>
                        <div><strong>请求协助:</strong> <?php echo htmlspecialchars($ob['assist_real_name'] ?: $ob['assist_name'] ?: '未指定'); ?></div>
                        <div><strong>提交人:</strong> <?php echo htmlspecialchars($ob['requester_real_name'] ?: $ob['requester_name']); ?> @ <?php echo htmlspecialchars($ob['created_at']); ?></div>
                        <form method="POST" style="margin-top:6px;">
                            <input type="hidden" name="action" value="resolve_blocked">
                            <input type="hidden" name="blocked_info_id" value="<?php echo (int)$ob['id']; ?>">
                            <textarea name="resolve_note" rows="2" placeholder="解除说明" required></textarea>
                            <div style="display:flex; gap:8px; align-items:center; margin-top:5px;">
                                解除后状态:
                                <select name="new_status_after_resolve">
                                    <option value="in_progress">进行中</option>
                                    <option value="todo">待处理</option>
                                    <option value="done">已完成</option>
                                </select>
                                进度:
                                <input type="number" name="new_progress_after_resolve" min="0" max="100" step="5" value="<?php echo (int)$task['progress']; ?>" style="width:80px;">
                                <button type="submit" class="btn btn-success">解除</button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($canUpdateStatus): ?>
                <h4 style="margin-top:8px;">标记为阻塞</h4>
                <form method="POST">
                    <input type="hidden" name="action" value="mark_blocked">
                    <div class="form-group">
                        <label>阻塞原因 <span style="color:#e74c3c;">*</span></label>
                        <textarea name="block_reason" rows="2" required placeholder="说明卡在哪里,缺什么资源/信息/协助"></textarea>
                    </div>
                    <div class="form-group">
                        <label>请求谁协助</label>
                        <select name="requested_assist_user_id">
                            <option value="">不指定</option>
                            <?php foreach ($projectMemberOptions as $pm): ?>
                                <option value="<?php echo (int)$pm['id']; ?>"><?php echo htmlspecialchars(($pm['name'] ?: $pm['username']) . ' - ' . ($pm['expertise'] ?: '无专长')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>备注</label>
                        <input type="text" name="note">
                    </div>
                    <button type="submit" class="btn btn-danger" onclick="return confirm('确认将此任务标记为阻塞?')">🚫 标记阻塞</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- 依赖关系 -->
    <div class="card">
        <h3>🔗 依赖关系</h3>
        <?php if (empty($dependencies) && empty($dependents)): ?>
            <p style="color:#999;">此任务没有依赖,也没有任务依赖它。</p>
        <?php else: ?>
            <div class="row-2col">
                <div>
                    <h4>⏳ 本任务依赖 (这些任务未完成,本任务不能进入"进行中")</h4>
                    <?php if (empty($dependencies)): ?>
                        <p style="color:#999;">无</p>
                    <?php else: ?>
                        <ul class="dep-list">
                            <?php foreach ($dependencies as $d): ?>
                                <li>
                                    <a href="task_detail.php?task_id=<?php echo (int)$d['depends_on_task_id']; ?>">[#<?php echo (int)$d['depends_on_task_id']; ?>] <?php echo htmlspecialchars($d['dep_title']); ?></a>
                                    <span class="status-badge status-<?php echo htmlspecialchars($d['dep_status']); ?>"><?php echo getTaskStatusText($d['dep_status']); ?></span>
                                    <?php if ($d['dep_status'] !== 'done'): ?>
                                        <span class="badge-warn" style="font-size:11px;">未完成 (<?php echo (int)$d['dep_progress']; ?>%)</span>
                                    <?php else: ?>
                                        <span style="color:#28a745; font-size:11px;">✓ 已完成</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <div>
                    <h4>⏭️ 谁依赖了本任务</h4>
                    <?php if (empty($dependents)): ?>
                        <p style="color:#999;">无</p>
                    <?php else: ?>
                        <ul class="dep-list">
                            <?php foreach ($dependents as $dp): ?>
                                <li>
                                    <a href="task_detail.php?task_id=<?php echo (int)$dp['task_id']; ?>">[#<?php echo (int)$dp['task_id']; ?>] <?php echo htmlspecialchars($dp['task_title']); ?></a>
                                    <span class="status-badge status-<?php echo htmlspecialchars($dp['task_status']); ?>"><?php echo getTaskStatusText($dp['task_status']); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- 标签 + 子清单 + 工时 + 附件 (四列) -->
    <div class="row-2col">
        <!-- 标签 -->
        <div class="card">
            <h3>🏷️ 标签</h3>
            <?php if ($canEdit): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="set_tags">
                    <div class="form-group">
                        <label>选择标签 (多选)</label>
                        <select name="tag_ids[]" multiple size="5" style="height:auto;">
                            <?php
                            $currentTagIds = array_map(function($t) { return (int)$t['id']; }, $taskTags);
                            foreach ($projectTags as $tg): ?>
                                <option value="<?php echo (int)$tg['id']; ?>" <?php echo in_array((int)$tg['id'], $currentTagIds) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tg['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">保存标签</button>
                </form>
                <hr>
                <form method="POST" style="display:flex; gap:5px; align-items:end;">
                    <input type="hidden" name="action" value="add_tag">
                    <div class="form-group" style="flex:1; margin:0;">
                        <label>新标签名</label>
                        <input type="text" name="new_tag_name" required placeholder="如:bug / 紧急">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>颜色</label>
                        <input type="color" name="new_tag_color" value="#3498db" style="width:50px; height:30px; padding:0;">
                    </div>
                    <button type="submit" class="btn btn-success">+ 新建</button>
                </form>
            <?php else: ?>
                <div>
                    <?php if (empty($taskTags)): ?>
                        <p style="color:#999;">无标签</p>
                    <?php else: foreach ($taskTags as $t): ?>
                        <span class="tag-pill" style="background:<?php echo htmlspecialchars($t['color']); ?>"><?php echo htmlspecialchars($t['name']); ?></span>
                    <?php endforeach; endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- 子清单 -->
        <div class="card">
            <h3>☑️ 子清单 (checklist)</h3>
            <?php if ($checklistProg['total'] > 0): ?>
                <div class="progress-bar" style="margin-bottom:8px;" title="<?php echo $checklistProg['rate']; ?>%">
                    <div class="progress-bar-fill" style="width:<?php echo $checklistProg['rate']; ?>%;"></div>
                    <span class="progress-bar-text"><?php echo $checklistProg['done']; ?> / <?php echo $checklistProg['total']; ?> (<?php echo $checklistProg['rate']; ?>%)</span>
                </div>
            <?php endif; ?>
            <ul class="checklist">
                <?php foreach ($checklist as $ci): ?>
                    <li class="checklist-item <?php echo $ci['is_done'] ? 'is-done' : ''; ?>">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="toggle_checklist">
                            <input type="hidden" name="item_id" value="<?php echo (int)$ci['id']; ?>">
                            <input type="hidden" name="is_done" value="<?php echo $ci['is_done'] ? 0 : 1; ?>">
                            <button type="submit" class="check-btn" title="点击切换"><?php echo $ci['is_done'] ? '✅' : '⬜'; ?></button>
                        </form>
                        <span><?php echo htmlspecialchars($ci['content']); ?></span>
                        <?php if ($canEdit): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('删除子项?')">
                                <input type="hidden" name="action" value="delete_checklist">
                                <input type="hidden" name="item_id" value="<?php echo (int)$ci['id']; ?>">
                                <button type="submit" class="check-del" title="删除">✕</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($canEdit || $canUpdateStatus): ?>
                <form method="POST" style="margin-top:8px; display:flex; gap:5px;">
                    <input type="hidden" name="action" value="add_checklist">
                    <input type="text" name="checklist_content" placeholder="+ 添加子项" required style="flex:1;">
                    <button type="submit" class="btn btn-success">+</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="row-2col">
        <!-- 工时 -->
        <div class="card">
            <h3>⏱️ 工时记录 (估时 <?php echo number_format((float)$task['estimated_hours'], 1); ?>h / 实际 <?php echo number_format((float)$task['actual_hours'], 1); ?>h)</h3>
            <?php if ($canTimeLog): ?>
                <form method="POST" style="display:flex; gap:5px; align-items:end; margin-bottom:10px;">
                    <input type="hidden" name="action" value="add_time_log">
                    <div class="form-group" style="margin:0; width:90px;">
                        <label>小时</label>
                        <input type="number" name="hours" min="0.1" step="0.1" required placeholder="0.0">
                    </div>
                    <div class="form-group" style="margin:0; width:140px;">
                        <label>日期</label>
                        <input type="date" name="work_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group" style="margin:0; flex:1;">
                        <label>说明</label>
                        <input type="text" name="time_note" placeholder="今天做了啥">
                    </div>
                    <button type="submit" class="btn btn-primary">+ 记录</button>
                </form>
            <?php endif; ?>
            <table class="table">
                <thead><tr><th>日期</th><th>工时</th><th>说明</th><th>记录人</th><th>操作</th></tr></thead>
                <tbody>
                <?php if (empty($timeLogs)): ?>
                    <tr><td colspan="5" style="color:#999;">暂无工时记录</td></tr>
                <?php else: foreach ($timeLogs as $tl): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($tl['work_date']); ?></td>
                        <td><?php echo number_format((float)$tl['hours'], 1); ?>h</td>
                        <td><?php echo htmlspecialchars($tl['note'] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($tl['user_real_name'] ?: $tl['username']); ?></td>
                        <td>
                            <?php if ($tl['user_id'] == $user['id'] || isAdmin()): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('删除此工时记录?')">
                                    <input type="hidden" name="action" value="delete_time_log">
                                    <input type="hidden" name="log_id" value="<?php echo (int)$tl['id']; ?>">
                                    <button type="submit" class="btn btn-danger" style="padding:2px 6px; font-size:11px;">删</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 附件 -->
        <div class="card">
            <h3>📎 附件</h3>
            <?php if ($canComment): ?>
                <form method="POST" enctype="multipart/form-data" style="display:flex; gap:5px; align-items:end; margin-bottom:10px;">
                    <input type="hidden" name="action" value="upload_attachment">
                    <div class="form-group" style="margin:0; flex:1;">
                        <label>文件 (最大 20MB)</label>
                        <input type="file" name="attachment" required>
                    </div>
                    <div class="form-group" style="margin:0; flex:1;">
                        <label>说明</label>
                        <input type="text" name="attachment_note" placeholder="可选">
                    </div>
                    <button type="submit" class="btn btn-primary">上传</button>
                </form>
            <?php endif; ?>
            <table class="table">
                <thead><tr><th>文件名</th><th>大小</th><th>上传人</th><th>时间</th><th>操作</th></tr></thead>
                <tbody>
                <?php if (empty($attachments)): ?>
                    <tr><td colspan="5" style="color:#999;">暂无附件</td></tr>
                <?php else: foreach ($attachments as $a): ?>
                    <tr>
                        <td><a href="download_attachment.php?id=<?php echo (int)$a['id']; ?>" target="_blank"><?php echo htmlspecialchars($a['original_name']); ?></a></td>
                        <td><?php echo number_format($a['file_size'] / 1024, 1); ?> KB</td>
                        <td><?php echo htmlspecialchars($a['user_real_name'] ?: $a['username']); ?></td>
                        <td><?php echo htmlspecialchars($a['created_at']); ?></td>
                        <td>
                            <?php if ($a['user_id'] == $user['id'] || isAdmin()): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('删除附件?')">
                                    <input type="hidden" name="action" value="delete_attachment">
                                    <input type="hidden" name="attachment_id" value="<?php echo (int)$a['id']; ?>">
                                    <button type="submit" class="btn btn-danger" style="padding:2px 6px; font-size:11px;">删</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 评论 -->
    <div class="card">
        <h3>💬 讨论 (<?php echo count($comments); ?>)</h3>
        <?php if ($canComment): ?>
            <form method="POST">
                <input type="hidden" name="action" value="add_comment">
                <div class="form-group">
                    <textarea name="comment_content" rows="3" required placeholder="说点什么..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary">发表评论</button>
            </form>
        <?php endif; ?>
        <div class="comment-list">
            <?php if (empty($comments)): ?>
                <p style="color:#999;">还没有讨论,来发第一条吧~</p>
            <?php else: foreach ($comments as $c): ?>
                <div class="comment-item">
                    <div class="comment-avatar"><?php echo mb_substr($c['user_real_name'] ?: $c['username'], 0, 1); ?></div>
                    <div class="comment-content">
                        <div class="comment-meta">
                            <strong><?php echo htmlspecialchars($c['user_real_name'] ?: $c['username']); ?></strong>
                            <span class="comment-time"><?php echo htmlspecialchars($c['created_at']); ?></span>
                        </div>
                        <div class="comment-text"><?php echo nl2br(htmlspecialchars($c['content'])); ?></div>
                    </div>
                    <?php if ($c['user_id'] == $user['id'] || isAdmin()): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('删除评论?')">
                            <input type="hidden" name="action" value="delete_comment">
                            <input type="hidden" name="comment_id" value="<?php echo (int)$c['id']; ?>">
                            <button type="submit" class="btn btn-danger" style="padding:2px 6px; font-size:11px;">删</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- 阻塞历史 -->
    <div class="card">
        <h3>🆘 阻塞历史</h3>
        <?php if (empty($blockedInfo)): ?>
            <p style="color:#999;">此任务暂无阻塞记录。</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>状态</th><th>原因</th><th>请求协助</th><th>提交人</th><th>时间</th><th>解决人</th><th>解决说明</th></tr></thead>
                <tbody>
                <?php foreach ($blockedInfo as $b): ?>
                    <tr>
                        <td><?php if ($b['status'] === 'open'): ?><span class="status-badge status-blocked">未解决</span><?php else: ?><span class="status-badge status-done">已解决</span><?php endif; ?></td>
                        <td><?php echo nl2br(htmlspecialchars($b['block_reason'])); ?></td>
                        <td><?php echo htmlspecialchars($b['assist_real_name'] ?: $b['assist_name'] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($b['requester_real_name'] ?: $b['requester_name']); ?></td>
                        <td><?php echo htmlspecialchars($b['created_at']); ?></td>
                        <td><?php echo htmlspecialchars($b['resolver_real_name'] ?: $b['resolver_username'] ?: '-'); ?></td>
                        <td><?php echo nl2br(htmlspecialchars($b['resolve_note'] ?: '-')); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- 完整历史:状态变更清单 + 指派历史 + 操作日志(默认折叠) -->
    <div class="card">
        <h3>📚 完整历史 <small>状态变更 · 指派 · 操作日志</small></h3>

        <details style="margin-bottom: var(--space-4);">
            <summary style="cursor:pointer; padding: 8px 0; font-weight: 600; color: var(--color-text-soft);">📜 状态变更清单 (<?php echo count($statusChanges); ?>)</summary>
            <?php if (empty($statusChanges)): ?>
                <p style="color:#999; padding: 8px 0;">暂无状态变更记录。</p>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>时间</th><th>操作人</th><th>原状态</th><th>新状态</th><th>进度</th><th>说明</th></tr></thead>
                    <tbody>
                    <?php foreach ($statusChanges as $sc): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($sc['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($sc['user_real_name'] ?: $sc['username']); ?></td>
                            <td><?php if ($sc['old_status']): ?><span class="status-badge status-<?php echo htmlspecialchars($sc['old_status']); ?>"><?php echo getTaskStatusText($sc['old_status']); ?></span><?php else: ?>-<?php endif; ?></td>
                            <td><span class="status-badge status-<?php echo htmlspecialchars($sc['new_status']); ?>"><?php echo getTaskStatusText($sc['new_status']); ?></span></td>
                            <td><?php echo $sc['old_progress'] !== null ? (int)$sc['old_progress'] : '-'; ?>% → <?php echo $sc['new_progress'] !== null ? (int)$sc['new_progress'] : '-'; ?>%</td>
                            <td><?php echo nl2br(htmlspecialchars($sc['note'] ?: '-')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </details>

        <details style="margin-bottom: var(--space-4);">
            <summary style="cursor:pointer; padding: 8px 0; font-weight: 600; color: var(--color-text-soft);">📤 指派历史 (<?php echo count($assignHist); ?>)</summary>
            <?php if (empty($assignHist)): ?>
                <p style="color:#999; padding: 8px 0;">此任务没有指派记录。</p>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>时间</th><th>操作人</th><th>原负责人</th><th>新负责人</th><th>原因</th></tr></thead>
                    <tbody>
                    <?php foreach ($assignHist as $a): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($a['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($a['operator_real_name'] ?: $a['operator_name']); ?></td>
                            <td><?php echo htmlspecialchars($a['from_real_name'] ?: $a['from_name'] ?: '(首次指派)'); ?></td>
                            <td><?php echo htmlspecialchars($a['to_real_name'] ?: $a['to_name']); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($a['reason'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </details>

        <details>
            <summary style="cursor:pointer; padding: 8px 0; font-weight: 600; color: var(--color-text-soft);">📋 操作日志 (<?php echo count($taskOpLogs); ?>)</summary>
            <?php if (empty($taskOpLogs)): ?>
                <p style="color:#999; padding: 8px 0;">暂无操作记录。</p>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>时间</th><th>操作人</th><th>操作</th><th>详情</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($taskOpLogs, 0, 50) as $log): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($log['user_real_name'] ?: $log['username'] ?: '(系统)'); ?></td>
                            <td><span class="action-tag action-<?php echo htmlspecialchars($log['action']); ?>"><?php echo htmlspecialchars($log['action']); ?></span></td>
                            <td><pre class="log-details"><?php echo htmlspecialchars($log['details'] ?: '-'); ?></pre></td>
                            <td><?php echo htmlspecialchars($log['ip_address'] ?: '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (count($taskOpLogs) > 50): ?>
                    <p style="text-align:center; color: var(--color-text-mute); padding: 8px;">仅显示最新 50 条,完整日志见 <a href="operation_log.php?target_type=task&target_id=<?php echo (int)$taskId; ?>">操作日志页</a></p>
                <?php endif; ?>
            <?php endif; ?>
        </details>
    </div>
</div>

<?php echo renderFooter(); ?>
</body>
</html>
