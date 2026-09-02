<?php
/**
 * 创建任务 - 独立页面
 * Create Task Page
 *
 * 权限: 项目经理 (manager) 或 管理员
 * 由 tasks.php 列表页"添加任务"按钮跳入
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';

requireLogin();

$user      = getCurrentUser();
$projectId = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$error     = '';

$project = queryOneDb(
    "SELECT p.*, u.username as manager_name, u.name as manager_real_name
     FROM projects p LEFT JOIN users u ON p.manager_id = u.id WHERE p.id = ?",
    [$projectId]
);
if (!$project) die('项目不存在');

$isProjectManager = ((int)$project['manager_id'] === (int)$user['id']) || isAdmin();
if (!$isProjectManager) {
    die('权限不足:只有项目负责人或管理员可以创建任务');
}

// 处理创建
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_task'])) {
    $title        = trim($_POST['task_title'] ?? '');
    $description  = trim($_POST['task_description'] ?? '');
    $assigneeId   = !empty($_POST['assignee_id']) ? (int)$_POST['assignee_id'] : null;
    $parentId     = (int)($_POST['parent_task_id'] ?? 0);
    $priority     = $_POST['priority'] ?? 'medium';
    $dueDate      = $_POST['due_date'] ?: null;
    $startDate    = $_POST['start_date'] ?: null;
    $progress     = max(0, min(100, (int)($_POST['progress'] ?? 0)));
    $assignReason = trim($_POST['assign_reason'] ?? '');
    $depIds       = $_POST['depends_on'] ?? [];
    $depNote      = trim($_POST['depends_note'] ?? '');

    if ($title === '') {
        $error = '任务标题不能为空';
    } else {
        try {
            $pdo = getDbConnection();
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO tasks (project_id, parent_task_id, title, description, assignee_id, priority, start_date, due_date, progress, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'todo', ?)"
            );
            $stmt->execute([$projectId, $parentId ?: null, $title, $description, $assigneeId, $priority, $startDate, $dueDate, $progress, $user['id']]);
            $newTaskId = $pdo->lastInsertId();

            if (is_array($depIds)) {
                foreach ($depIds as $depId) {
                    $depId = (int)$depId;
                    if ($depId > 0 && $depId != $newTaskId) {
                        addTaskDependency($newTaskId, $depId, $depNote, $user['id']);
                        logOperation($user['id'], 'create', 'task_dependency', $newTaskId, [
                            'depends_on_task_id' => $depId,
                            'note' => $depNote,
                        ]);
                    }
                }
            }

            if ($assigneeId) {
                addTaskAssignment($newTaskId, $user['id'], null, $assigneeId, $assignReason);
                logOperation($user['id'], 'assign', 'task', $newTaskId, [
                    'assignee_id' => $assigneeId,
                    'reason'      => $assignReason,
                ]);
            }

            logOperation($user['id'], 'create', 'task', $newTaskId, [
                'project_id' => $projectId,
                'title'      => $title,
                'priority'   => $priority,
                'progress'   => $progress,
            ]);

            $pdo->commit();
            $_SESSION['success_message'] = '任务创建成功';
            redirect('task_detail.php?task_id=' . $newTaskId);
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $error = '创建失败: ' . $e->getMessage();
        }
    }
}

// 项目下顶级任务(供父任务 / 依赖选择)
$allProjectTasks = queryDb(
    "SELECT id, title, status, progress FROM tasks WHERE project_id = ? AND (parent_task_id = 0 OR parent_task_id IS NULL) ORDER BY id DESC",
    [$projectId]
);

$projectMembers = queryDb(
    "SELECT pm.*, u.username, u.id as user_id, u.name as user_name, u.expertise
     FROM project_members pm
     JOIN users u ON pm.user_id = u.id
     WHERE pm.project_id = ? AND pm.status = 'active'",
    [$projectId]
);

$unreadNotifCount = getUnreadNotificationCount($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>创建任务 - <?php echo htmlspecialchars($project['name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
</head>
<body>
<?php
echo renderHeader('➕ 创建任务 - ' . htmlspecialchars($project['name']), $user, $unreadNotifCount, 'projects', [
    'project_id'   => (int)$projectId,
    'project_name' => $project['name'] ?? '',
    'sub_active'   => 'tasks',
]);
?>

<div class="container">
    <?php if (!empty($error)) echo showError($error); ?>

    <div class="card" style="max-width: 920px;">
        <h3>📌 创建新任务 / 任务分解 / 任务下发</h3>
        <p style="color:#666; font-size:13px; margin-bottom: 16px;">
            创建后自动跳转到任务详情页,在那里可以继续维护评论、checklist、附件、阻塞信息等。
        </p>

        <form method="POST" action="task_create.php?project_id=<?php echo (int)$projectId; ?>" id="createTaskForm">
            <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">

            <div class="form-row" style="display:flex; gap:15px; margin-bottom:15px;">
                <div class="form-group" style="flex:2; margin-bottom:0;">
                    <label>任务标题 <span style="color:#e74c3c;">*</span></label>
                    <input type="text" name="task_title" required placeholder="请输入任务标题">
                </div>
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <label>优先级</label>
                    <select name="priority">
                        <option value="low">低</option>
                        <option value="medium" selected>中</option>
                        <option value="high">高</option>
                    </select>
                </div>
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <label>完成百分比</label>
                    <input type="number" name="progress" min="0" max="100" value="0" step="5">
                </div>
            </div>

            <div class="form-group">
                <label>任务描述</label>
                <textarea name="task_description" rows="3" placeholder="请详细描述任务内容..."></textarea>
            </div>

            <div class="form-row" style="display:flex; gap:15px; margin-bottom:15px;">
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <label>🔽 父任务 (任务分解,可空)</label>
                    <select name="parent_task_id">
                        <option value="0">无 (顶级任务)</option>
                        <?php foreach ($allProjectTasks as $t): ?>
                            <option value="<?php echo (int)$t['id']; ?>">└─ <?php echo htmlspecialchars($t['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <label>📤 负责人 (指派)</label>
                    <select name="assignee_id" id="assignee_id" onchange="toggleAssignReason()">
                        <option value="">未分配</option>
                        <?php foreach ($projectMembers as $member): ?>
                            <option value="<?php echo (int)$member['user_id']; ?>">
                                <?php 
                                    $realName = htmlspecialchars($member['user_name'] ?: '');
                                    $username = htmlspecialchars($member['username']);
                                    $displayUser = $realName ? ($realName . ' (' . $username . ')') : $username;
                                ?>
                                <?php echo $displayUser; ?> - <?php echo htmlspecialchars($member['custom_role']); ?> - <?php echo htmlspecialchars($member['expertise'] ?: '无专长'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group" id="assign_reason_group" style="display:none;">
                <label>📝 指派原因 <span style="color:#999; font-weight:normal;">(可选,新建任务时不强制;之后通过"重新指派"修改负责人时必填)</span></label>
                <textarea name="assign_reason" id="assign_reason" rows="2"
                          placeholder="例如:该同事负责 XXX 模块,对此业务最熟悉 / 紧急任务优先指派给此同事..."></textarea>
            </div>

            <div class="form-group">
                <label>🔗 依赖任务 (可多选,所选任务未完成前,本任务不能进入"进行中")</label>
                <select name="depends_on[]" multiple size="5" style="height:auto;">
                    <?php foreach ($allProjectTasks as $t): ?>
                        <option value="<?php echo (int)$t['id']; ?>">
                            [#<?php echo (int)$t['id']; ?>] <?php echo htmlspecialchars($t['title']); ?>
                            — 状态:<?php echo getTaskStatusText($t['status']); ?>
                            (<?php echo (int)$t['progress']; ?>%)
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color:#999;">按住 Ctrl / Cmd 多选。</small>
                <input type="text" name="depends_note" placeholder="依赖说明 (可选,例如:依赖 API 接口文档)"
                       style="margin-top:5px;">
            </div>

            <div class="form-row" style="display:flex; gap:15px; margin-bottom:15px;">
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <label>开始日期</label>
                    <input type="date" name="start_date">
                </div>
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <label>截止日期</label>
                    <input type="date" name="due_date">
                </div>
            </div>

            <div class="form-group" style="display:flex; gap:10px;">
                <button type="submit" name="create_task" class="btn btn-primary">✅ 创建任务</button>
                <a href="tasks.php?project_id=<?php echo (int)$projectId; ?>" class="btn btn-danger">取消</a>
            </div>
        </form>
    </div>
</div>

<script>
function toggleAssignReason() {
    var sel = document.getElementById('assignee_id');
    var grp = document.getElementById('assign_reason_group');
    if (sel.value) {
        grp.style.display = 'block';
    } else {
        grp.style.display = 'none';
    }
}
</script>

<?php echo renderFooter(); ?>
</body>
</html>