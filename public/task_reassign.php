<?php
/**
 * 任务重新指派
 * Task Reassign Page
 *
 * 需求: 重新指派时必须填写指派原因
 * 记录: task_assignments (历史) + operation_logs (统一日志)
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';

requireLogin();

$user   = getCurrentUser();
$taskId = (int)($_GET['task_id'] ?? $_POST['task_id'] ?? 0);
$error  = '';
$success = '';

$task = queryOneDb(
    "SELECT t.*, p.manager_id as project_manager_id, u.username as cur_assignee_username, u.name as cur_assignee_realname
     FROM tasks t JOIN projects p ON t.project_id = p.id
     LEFT JOIN users u ON t.assignee_id = u.id
     WHERE t.id = ?", [$taskId]);
if (!$task) die('任务不存在');

$isProjectManager = ($task['project_manager_id'] == $user['id']) || isAdmin();
if (!$isProjectManager && !isAdmin()) {
    die('权限不足:只有项目经理或管理员可重新指派');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_reassign'])) {
    $newAssigneeId = !empty($_POST['new_assignee_id']) ? (int)$_POST['new_assignee_id'] : null;
    $reason        = trim($_POST['reason'] ?? '');
    $resetStatus   = $_POST['reset_status'] ?? 'keep';
    $resetProgress = $_POST['reset_progress'] ?? 'keep';

    // 必填校验
    if (empty($reason)) {
        $error = '指派原因不能为空 (需求:重新指派必须填写原因)';
    } elseif ($newAssigneeId === null) {
        $error = '请选择新负责人';
    } elseif ($newAssigneeId === (int)$task['assignee_id']) {
        $error = '新负责人与当前负责人相同,无需重新指派';
    } else {
        // 校验新负责人是项目成员
        $isMember = queryOneDb(
            "SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? AND status = 'active'",
            [$task['project_id'], $newAssigneeId]
        );
        if (!$isMember) {
            $error = '新负责人不是本项目成员';
        } else {
            try {
                $pdo = getDbConnection();
                $pdo->beginTransaction();

                $fromUserId = $task['assignee_id'] ? (int)$task['assignee_id'] : null;
                $oldStatus = $task['status'];
                $oldProgress = (int)$task['progress'];

                // 1) 写指派历史
                addTaskAssignment($taskId, $user['id'], $fromUserId, $newAssigneeId, $reason);

                // 2) 更新 tasks 表
                $newStatus   = $oldStatus;
                $newProgress = $oldProgress;
                if ($resetStatus === 'todo')        $newStatus = 'todo';
                elseif ($resetStatus === 'in_progress') $newStatus = 'in_progress';
                if ($resetProgress === 'reset_0')    $newProgress = 0;
                elseif ($resetProgress === 'reset_50') $newProgress = 50;
                elseif ($resetProgress === 'keep')   $newProgress = $oldProgress;
                if (is_numeric($resetProgress) && (int)$resetProgress >= 0 && (int)$resetProgress <= 100) {
                    $newProgress = (int)$resetProgress;
                }

                executeDb(
                    "UPDATE tasks SET assignee_id = ?, status = ?, progress = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                    [$newAssigneeId, $newStatus, $newProgress, $taskId]
                );

                // 3) 若状态/进度变化, 写一条状态变更记录
                if ($newStatus !== $oldStatus || $newProgress !== $oldProgress) {
                    addTaskStatusChange($taskId, $user['id'], $oldStatus, $newStatus, $oldProgress, $newProgress, '重新指派: ' . $reason);
                }

                // 4) 统一操作日志
                logOperation($user['id'], 'reassign', 'task', $taskId, [
                    'from_user_id'   => $fromUserId,
                    'to_user_id'     => $newAssigneeId,
                    'reason'         => $reason,
                    'reset_status'   => $resetStatus,
                    'reset_progress' => $resetProgress,
                ]);

                $pdo->commit();
                $success = '任务已重新指派';
                $task = queryOneDb(
                    "SELECT t.*, p.manager_id as project_manager_id, u.username as cur_assignee_username, u.name as cur_assignee_realname
                     FROM tasks t JOIN projects p ON t.project_id = p.id
                     LEFT JOIN users u ON t.assignee_id = u.id
                     WHERE t.id = ?", [$taskId]);
            } catch (Exception $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                $error = '重新指派失败: ' . $e->getMessage();
            }
        }
    }
}

// 候选新负责人:项目成员 - 当前负责人
$candidates = queryDb(
    "SELECT u.id, u.username, u.name, u.expertise FROM project_members pm
     JOIN users u ON pm.user_id = u.id
     WHERE pm.project_id = ? AND pm.status = 'active' AND u.id != ?
     ORDER BY u.username",
    [$task['project_id'], (int)$task['assignee_id']]
);

// 历史
$assignHist = getTaskAssignmentHistory($taskId);

$unreadNotifCount = getUnreadNotificationCount($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>重新指派 - <?php echo htmlspecialchars($task['title']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
</head>
<body>
<?php
echo renderHeader('📤 重新指派任务', $user, $unreadNotifCount, null, [
    'project_id' => (int)$task['project_id'],
    'task_id'    => (int)$taskId,
    'project_name' => $task['project_name'] ?? '',
    'sub_active' => 'task_reassign',
], false);
?>

<div class="container">
    <?php if (!empty($error)) echo showError($error); ?>
    <?php if (!empty($success)) echo showSuccess($success); ?>

    <div class="card">
        <h3>📄 <?php echo htmlspecialchars($task['title']); ?></h3>
        <div class="task-meta">
            <div><strong>当前负责人:</strong>
                <?php if ($task['assignee_id']): ?>
                    <?php echo htmlspecialchars($task['cur_assignee_realname'] ?: $task['cur_assignee_username']); ?>
                <?php else: ?>
                    <span style="color:#999;">未分配</span>
                <?php endif; ?>
            </div>
            <div><strong>当前状态:</strong> <?php echo getTaskStatusText($task['status']); ?> | <strong>进度:</strong> <?php echo (int)$task['progress']; ?>%</div>
        </div>
    </div>

    <div class="card">
        <h3>📤 重新指派</h3>
        <p style="color:#e74c3c; font-size:13px;">⚠️ 注意:重新指派必须填写指派原因,会记入操作日志和指派历史。</p>
        <form method="POST">
            <input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>">
            <div class="form-group">
                <label>新负责人 <span style="color:#e74c3c;">*</span></label>
                <select name="new_assignee_id" required>
                    <option value="">-- 请选择 --</option>
                    <?php foreach ($candidates as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars(($c['name'] ?: $c['username']) . ' - ' . ($c['expertise'] ?: '无专长')); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($candidates)): ?>
                    <small style="color:#e74c3c;">项目内没有其他可选成员。请先在"项目管理"页添加成员。</small>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>指派原因 <span style="color:#e74c3c;">*</span></label>
                <textarea name="reason" rows="3" required placeholder="例如:原负责人离职 / 此模块转由 XX 更合适 / 原负责人工作量饱和,需要分担"></textarea>
            </div>
            <div class="form-row" style="display:flex; gap:15px;">
                <div class="form-group" style="flex:1;">
                    <label>指派后是否重置状态</label>
                    <select name="reset_status">
                        <option value="keep">保持当前状态</option>
                        <option value="todo">重置为"待处理"</option>
                        <option value="in_progress">保持"进行中"</option>
                    </select>
                </div>
                <div class="form-group" style="flex:1;">
                    <label>指派后进度处理</label>
                    <select name="reset_progress">
                        <option value="keep">保持当前进度</option>
                        <option value="reset_0">重置为 0%</option>
                        <option value="reset_50">重置为 50%</option>
                    </select>
                </div>
            </div>
            <button type="submit" name="do_reassign" class="btn btn-warning" onclick="return confirm('确认重新指派?此操作会写入操作日志。')">📤 提交重新指派</button>
            <a href="task_detail.php?task_id=<?php echo (int)$taskId; ?>" class="btn btn-danger">取消</a>
        </form>
    </div>

    <div class="card">
        <h3>📜 指派历史</h3>
        <?php if (empty($assignHist)): ?>
            <p style="color:#999;">暂无指派记录。</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>时间</th><th>操作人</th><th>原负责人</th><th>新负责人</th><th>原因</th></tr></thead>
                <tbody>
                <?php foreach ($assignHist as $a): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($a['created_at']); ?></td>
                        <td><?php echo htmlspecialchars($a['operator_real_name'] ?: $a['operator_name']); ?></td>
                        <td><?php echo htmlspecialchars($a['from_real_name'] ?: $a['from_name'] ?: '(无,首次指派)'); ?></td>
                        <td><?php echo htmlspecialchars($a['to_real_name'] ?: $a['to_name']); ?></td>
                        <td><?php echo nl2br(htmlspecialchars($a['reason'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php echo renderFooter(); ?>
</body>
</html>
