<?php
/**
 * 项目任务列表页 (只读,带分页)
 * Project Task List Page
 *
 * 权限: 项目成员 / 项目负责人 / 管理员
 * 创建入口: 顶部"添加任务"按钮 → task_create.php
 * 操作列: 详情 / 编辑 / 删除(仅创建者 + 管理员)
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';

requireLogin();

$user      = getCurrentUser();
$projectId = (int)($_GET['project_id'] ?? 0);
$error     = '';

$project = queryOneDb(
    "SELECT p.*, u.username as manager_name, u.name as manager_real_name
     FROM projects p LEFT JOIN users u ON p.manager_id = u.id WHERE p.id = ?",
    [$projectId]
);
if (!$project) die('项目不存在');

$isProjectMember  = isProjectMember($projectId, $user['id']);
$isProjectManager = ((int)$project['manager_id'] === (int)$user['id']) || isAdmin();
if (!$isProjectMember && !$isProjectManager && !isAdmin()) {
    die('权限不足:您不是该项目成员或项目经理');
}

// 筛选
$filterStatus   = $_GET['status'] ?? '';
$filterPriority = $_GET['priority'] ?? '';
$filterAssignee = (int)($_GET['assignee_id'] ?? 0);
$filterKeyword  = trim($_GET['q'] ?? '');
$showSubtasks   = !empty($_GET['with_sub']);  // 默认顶级任务,勾选后显示子任务

$where = ["t.project_id = ?"];
$params = [$projectId];
if ($showSubtasks) {
    // 显示所有任务(含子任务)
} else {
    // 顶级任务: 历史库里 parent_task_id 可能是 NULL 也可能是 0,都算顶级
    $where[] = "(t.parent_task_id = 0 OR t.parent_task_id IS NULL)";
}
if ($filterStatus)   { $where[] = "t.status = ?";   $params[] = $filterStatus; }
if ($filterPriority) { $where[] = "t.priority = ?"; $params[] = $filterPriority; }
if ($filterAssignee) { $where[] = "t.assignee_id = ?"; $params[] = $filterAssignee; }
if ($filterKeyword)  { $where[] = "(t.title LIKE ? OR t.description LIKE ?)"; $kw='%'.$filterKeyword.'%'; $params[]=$kw; $params[]=$kw; }

$whereSql = 'WHERE ' . implode(' AND ', $where);

// 分页
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$totalRow = queryOneDb("SELECT COUNT(*) AS c FROM tasks t $whereSql", $params);
$total    = (int)($totalRow['c'] ?? 0);
$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

$tasks = queryDb(
    "SELECT t.*, u.username as assignee_name, u.name as assignee_real_name,
            cu.username as creator_username, cu.name as creator_real_name,
            (SELECT COUNT(*) FROM tasks WHERE parent_task_id = t.id) as subtask_count,
            (SELECT COUNT(*) FROM task_dependencies WHERE task_id = t.id) as dep_count
     FROM tasks t
     LEFT JOIN users u  ON t.assignee_id = u.id
     LEFT JOIN users cu ON t.created_by  = cu.id
     $whereSql
     ORDER BY
       CASE WHEN t.status = 'done' THEN 2 ELSE 0 END ASC,
       CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END ASC,
       t.due_date ASC,
       t.priority DESC,
       t.id DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

// 项目成员(负责人筛选下拉)
$projectMembers = queryDb(
    "SELECT u.id, u.username, u.name FROM project_members pm
     JOIN users u ON pm.user_id = u.id
     WHERE pm.project_id = ? AND pm.status = 'active'
     ORDER BY u.username",
    [$projectId]
);

$unreadNotifCount = getUnreadNotificationCount($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>任务列表 - <?php echo htmlspecialchars($project['name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
</head>
<body>
<?php
echo renderHeader('📋 任务列表 - ' . htmlspecialchars($project['name']), $user, $unreadNotifCount, 'projects', [
    'project_id'   => (int)$projectId,
    'project_name' => $project['name'] ?? '',
    'sub_active'   => 'tasks',
]);
?>

<div class="container">
    <?php if (!empty($error)) echo showError($error); ?>
    <?php if (!empty($_SESSION['success_message'])) {
        echo showSuccess($_SESSION['success_message']); unset($_SESSION['success_message']);
    } ?>

    <div class="card">
        <div class="section-header">
            <h3>
                📋 任务列表
                <small style="color:#888; font-weight:normal; font-size:12px;">
                    共 <?php echo $total; ?> 个
                </small>
            </h3>
            <div style="display:flex; gap:8px;">
                <a href="project_dashboard.php?project_id=<?php echo (int)$projectId; ?>" class="btn btn-sm">📊 仪表盘</a>
                <?php if ($isProjectManager): ?>
                    <a href="task_create.php?project_id=<?php echo (int)$projectId; ?>" class="btn btn-primary">➕ 添加任务</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- 筛选 -->
        <form method="GET" class="filter-row" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom: 12px;">
            <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
            <div class="form-group" style="margin:0; flex:1; min-width:180px;">
                <label>关键词</label>
                <input type="text" name="q" placeholder="搜索标题 / 描述" value="<?php echo htmlspecialchars($filterKeyword); ?>">
            </div>
            <div class="form-group" style="margin:0; min-width:120px;">
                <label>状态</label>
                <select name="status">
                    <option value="">全部</option>
                    <option value="todo"        <?php echo $filterStatus==='todo'?'selected':''; ?>>待处理</option>
                    <option value="in_progress" <?php echo $filterStatus==='in_progress'?'selected':''; ?>>进行中</option>
                    <option value="blocked"     <?php echo $filterStatus==='blocked'?'selected':''; ?>>阻塞</option>
                    <option value="done"        <?php echo $filterStatus==='done'?'selected':''; ?>>已完成</option>
                </select>
            </div>
            <div class="form-group" style="margin:0; min-width:110px;">
                <label>优先级</label>
                <select name="priority">
                    <option value="">全部</option>
                    <option value="high"   <?php echo $filterPriority==='high'?'selected':''; ?>>高</option>
                    <option value="medium" <?php echo $filterPriority==='medium'?'selected':''; ?>>中</option>
                    <option value="low"    <?php echo $filterPriority==='low'?'selected':''; ?>>低</option>
                </select>
            </div>
            <div class="form-group" style="margin:0; min-width:140px;">
                <label>负责人</label>
                <select name="assignee_id">
                    <option value="0">全部</option>
                    <?php foreach ($projectMembers as $pm): ?>
                        <option value="<?php echo (int)$pm['id']; ?>"
                            <?php echo $filterAssignee===(int)$pm['id']?'selected':''; ?>>
                            <?php echo htmlspecialchars($pm['name'] ?: $pm['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label>&nbsp;</label>
                <label style="font-weight:normal;">
                    <input type="checkbox" name="with_sub" value="1" <?php echo $showSubtasks?'checked':''; ?>>
                    包含子任务
                </label>
            </div>
            <button type="submit" class="btn btn-primary">🔍 筛选</button>
            <a href="tasks.php?project_id=<?php echo (int)$projectId; ?>" class="btn btn-danger">重置</a>
        </form>

        <?php if (empty($tasks)): ?>
            <p style="color:#999; padding: 24px; text-align:center;">
                <?php echo $total === 0 ? '该项目暂无任务' : '当前页没有数据'; ?>
                <?php if ($isProjectManager): ?>
                    <br><a href="task_create.php?project_id=<?php echo (int)$projectId; ?>" class="btn btn-primary" style="margin-top:12px;">➕ 创建第一个任务</a>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>任务标题</th>
                        <th>负责人</th>
                        <th>状态</th>
                        <th>进度</th>
                        <th>优先级</th>
                        <th>截止</th>
                        <th>依赖/子任务</th>
                        <th>创建者</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tasks as $task):
                    $readyInfo = checkTaskReady($task['id']);
                ?>
                    <tr>
                        <td>
                            <a href="task_detail.php?task_id=<?php echo (int)$task['id']; ?>" class="task-link" style="font-weight:500;">
                                <?php if ($task['subtask_count'] > 0): ?>
                                    📁 <?php echo htmlspecialchars($task['title']); ?>
                                    <small style="color:#888;">[+<?php echo (int)$task['subtask_count']; ?>子任务]</small>
                                <?php else: ?>
                                    📄 <?php echo htmlspecialchars($task['title']); ?>
                                <?php endif; ?>
                            </a>
                        </td>
                        <td>
                            <?php if ($task['assignee_real_name']): ?>
                                <?php echo htmlspecialchars($task['assignee_real_name']); ?>
                                <small style="color:#888;">(<?php echo htmlspecialchars($task['assignee_name']); ?>)</small>
                            <?php elseif ($task['assignee_name']): ?>
                                <?php echo htmlspecialchars($task['assignee_name']); ?>
                            <?php else: ?>
                                <span style="color:#999;">未分配</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo htmlspecialchars($task['status']); ?>">
                                <?php echo getTaskStatusText($task['status']); ?>
                            </span>
                            <?php if (!$readyInfo['ready'] && $task['status'] !== 'done'): ?>
                                <br><small class="badge-warn">⛔ <?php echo count($readyInfo['pending']); ?> 依赖未完成</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="progress-bar" title="<?php echo (int)$task['progress']; ?>%">
                                <div class="progress-bar-fill" style="width:<?php echo (int)$task['progress']; ?>%;"></div>
                                <span class="progress-bar-text"><?php echo (int)$task['progress']; ?>%</span>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars(ucfirst($task['priority'])); ?></td>
                        <td><?php echo formatDate($task['due_date']); ?></td>
                        <td style="font-size:12px;">
                            🔗 <?php echo (int)$task['dep_count']; ?><br>
                            📁 <?php echo (int)$task['subtask_count']; ?>
                        </td>
                        <td style="font-size:12px; color:#666;">
                            <?php echo htmlspecialchars($task['creator_real_name'] ?: $task['creator_username'] ?: '-'); ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <a href="task_detail.php?task_id=<?php echo (int)$task['id']; ?>"
                               class="btn btn-primary btn-sm">详情</a>
                            <?php if ($isProjectManager): ?>
                                <a href="task_edit.php?task_id=<?php echo (int)$task['id']; ?>"
                                   class="btn btn-warning btn-sm">编辑</a>
                            <?php endif; ?>
                            <?php if (canDeleteTask($task, $user)): ?>
                                <form method="POST" action="task_delete.php" style="display:inline;"
                                      onsubmit="return confirm('确认删除任务「<?php echo htmlspecialchars($task['title'], ENT_QUOTES); ?>」?\n所有子任务/评论/附件/工时都会被一并清理。')">
                                    <input type="hidden" name="task_id" value="<?php echo (int)$task['id']; ?>">
                                    <input type="hidden" name="back" value="list">
                                    <button type="submit" class="btn btn-danger btn-sm">删除</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php echo renderPagination($page, $totalPages, 'tasks.php', [
                'project_id'  => $projectId,
                'q'           => $filterKeyword,
                'status'      => $filterStatus,
                'priority'    => $filterPriority,
                'assignee_id' => $filterAssignee,
                'with_sub'    => $showSubtasks ? 1 : '',
            ]); ?>
        <?php endif; ?>
    </div>
</div>

<?php echo renderFooter(); ?>
</body>
</html>
