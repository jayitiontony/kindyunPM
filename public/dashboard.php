<?php
/**
 * 仪表盘/看板
 * Dashboard
 *
 * 整体项目/任务看板
 *   - 我的任务 + 全部任务
 *   - 统计卡片(总数/完成/进行中/阻塞/逾期/今日到期)
 *   - 逾期任务 高亮
 *   - 状态快速变更(走 dashboard source 标记)
 *   - 协助申请
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';

requireLogin();

$user   = getCurrentUser();
$projects = getUserProjects($user['id']);

// 项目过滤
$filterProjectId = (int)($_GET['project_id'] ?? 0);

// 处理任务状态更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_task_status'])) {
    $taskId = (int)$_POST['task_id'];
    $newStatus = $_POST['new_status'];
    $note = $_POST['note'] ?? '';

    $task = queryOneDb("SELECT * FROM tasks WHERE id = ?", [$taskId]);
    if ($task) {
        $isAssignee = ($task['assignee_id'] == $user['id']);
        $isProjectManager = false;
        $projectMembers = queryDb("SELECT * FROM project_members WHERE project_id = ? AND user_id = ?", [$task['project_id'], $user['id']]);
        if (empty($projectMembers)) {
            $isProjectManagerQuery = queryOneDb("SELECT * FROM projects WHERE id = ? AND manager_id = ?", [$task['project_id'], $user['id']]);
            $isProjectManager = !empty($isProjectManagerQuery);
        } else {
            $isProjectManager = true;
        }

        if ($isAssignee || $isProjectManager || isAdmin()) {
            $oldStatus   = $task['status'];
            $oldProgress = (int)($task['progress'] ?? 0);
            $newProgress = $oldProgress;
            if ($newStatus === 'done') $newProgress = 100;

            // 校验依赖
            if ($newStatus === 'in_progress') {
                $ready = checkTaskReady($taskId);
                if (!$ready['ready']) {
                    $_SESSION['error_message'] = '任务有未完成的依赖,无法进入"进行中"';
                    redirect('dashboard.php');
                }
            }

            executeDb("UPDATE tasks SET status = ?, progress = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$newStatus, $newProgress, $taskId]);
            executeDb("INSERT INTO task_logs (task_id, user_id, old_status, new_status, note) VALUES (?, ?, ?, ?, ?)",
                [$taskId, $user['id'], $oldStatus, $newStatus, $note]);
            addTaskStatusChange($taskId, $user['id'], $oldStatus, $newStatus, $oldProgress, $newProgress, $note ?: '在看板快速更新');
            logOperation($user['id'], 'status_change', 'task', $taskId, [
                'old_status' => $oldStatus, 'new_status' => $newStatus,
                'old_progress' => $oldProgress, 'new_progress' => $newProgress,
                'note' => $note, 'source' => 'dashboard',
            ]);
            // 通知
            if ($task['assignee_id'] && $task['assignee_id'] != $user['id']) {
                addNotification($task['assignee_id'], 'task_status',
                    "任务状态变更: " . $task['title'],
                    $user['name'] . " 把状态从 " . getTaskStatusText($oldStatus) . " 改为 " . getTaskStatusText($newStatus),
                    'task', $taskId);
            }
            $_SESSION['success_message'] = '任务状态已更新';
            redirect('dashboard.php');
        }
    }
}

// 处理协助申请
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assistance'])) {
    $taskId = (int)$_POST['task_id'];
    $description = $_POST['description'];

    $task = queryOneDb("SELECT * FROM tasks WHERE id = ?", [$taskId]);
    if ($task) {
        $isAssignee = ($task['assignee_id'] == $user['id']);
        $isProjectManager = false;
        $projectMembers = queryDb("SELECT * FROM project_members WHERE project_id = ? AND user_id = ?", [$task['project_id'], $user['id']]);
        if (empty($projectMembers)) {
            $isProjectManagerQuery = queryOneDb("SELECT * FROM projects WHERE id = ? AND manager_id = ?", [$task['project_id'], $user['id']]);
            $isProjectManager = !empty($isProjectManagerQuery);
        } else {
            $isProjectManager = true;
        }

        if ($isAssignee || $isProjectManager || isAdmin()) {
            executeDb("INSERT INTO assistance_requests (task_id, requester_id, description, status) VALUES (?, ?, ?, 'pending')",
                [$taskId, $user['id'], $description]);
            $newReqId = getLastInsertId();
            logOperation($user['id'], 'request_assist', 'task', $taskId, [
                'assistance_request_id' => $newReqId, 'description' => $description,
            ]);
            // 通知项目所有成员和管理员
            $members = queryDb("SELECT user_id FROM project_members WHERE project_id = ? AND user_id != ? AND status = 'active'", [$task['project_id'], $user['id']]);
            foreach ($members as $m) {
                addNotification($m['user_id'], 'request_assist',
                    "协助请求: " . $task['title'],
                    $user['name'] . " 请求协助: " . mb_substr($description, 0, 50),
                    'task', $taskId);
            }
            $_SESSION['success_message'] = '协助申请已提交,已通知项目成员';
            redirect('dashboard.php');
        }
    }
}

// 处理协助申请处理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['handle_assistance'])) {
    $requestId = (int)$_POST['request_id'];
    $status = $_POST['assistance_status'];

    $req = queryOneDb("SELECT ar.*, t.project_id FROM assistance_requests ar JOIN tasks t ON ar.task_id = t.id WHERE ar.id = ?", [$requestId]);
    if ($req) {
        $isProjectManager = false;
        $projectMembers = queryDb("SELECT * FROM project_members WHERE project_id = ? AND user_id = ?", [$req['project_id'], $user['id']]);
        if (empty($projectMembers)) {
            $isProjectManagerQuery = queryOneDb("SELECT * FROM projects WHERE id = ? AND manager_id = ?", [$req['project_id'], $user['id']]);
            $isProjectManager = !empty($isProjectManagerQuery);
        } else {
            $isProjectManager = true;
        }

        if ($isProjectManager || isAdmin()) {
            executeDb("UPDATE assistance_requests SET status = ?, resolved_at = CURRENT_TIMESTAMP, resolver_id = ? WHERE id = ?",
                [$status, $user['id'], $requestId]);
            logOperation($user['id'], 'resolve_assist', 'task', $req['task_id'], [
                'assistance_request_id' => $requestId, 'new_status' => $status,
            ]);
            // 通知申请人
            addNotification($req['requester_id'], 'request_assist',
                "协助申请已处理",
                "你的协助申请被标记为" . getAssistanceStatusText($status) . " (" . $user['name'] . ")",
                'task', $req['task_id']);
            $_SESSION['success_message'] = '协助申请已处理';
            redirect('dashboard.php');
        }
    }
}

// 用户的任务
// 管理员: 全公司任务全局视图(所有未归档项目); 其他: 自己负责/参与的项目
$userTasks = [];
if (isAdmin()) {
    $whereClause = "p.archived_at IS NULL";
    $params = [];
    if ($filterProjectId > 0) {
        $whereClause .= " AND t.project_id = ?";
        $params[] = $filterProjectId;
    }
    $userTasks = queryDb(
        "SELECT t.*, p.name as project_name, u.username as assignee_name, u.name as assignee_real_name,
                (SELECT count(*) FROM tasks WHERE parent_task_id = t.id) as subtask_count
         FROM tasks t
         JOIN projects p ON t.project_id = p.id
         LEFT JOIN users u ON t.assignee_id = u.id
         WHERE $whereClause
         ORDER BY t.created_at DESC",
        $params
    );
} elseif (isProjectManager()) {
    $projectIds = [];
    foreach ($projects as $proj) {
        $projectIds[] = $proj['id'];
    }
    if (!empty($projectIds)) {
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $baseWhere = "t.project_id IN ($placeholders) AND p.archived_at IS NULL";
        $baseParams = $projectIds;
        if ($filterProjectId > 0) {
            // 如果指定了过滤项目，且该项目在用户的项目列表中，则只查询该项目
            if (in_array($filterProjectId, $projectIds)) {
                $baseWhere = "t.project_id = ? AND p.archived_at IS NULL";
                $baseParams = [$filterProjectId];
            } else {
                // 如果指定了过滤项目但不在用户的项目列表中，则不返回任何任务
                $userTasks = [];
            }
        }
        if (!empty($userTasks) || $filterProjectId == 0 || in_array($filterProjectId, $projectIds)) {
            $userTasks = queryDb("SELECT t.*, p.name as project_name, u.username as assignee_name, u.name as assignee_real_name,
                                 (SELECT count(*) FROM tasks WHERE parent_task_id = t.id) as subtask_count
                                 FROM tasks t
                                 JOIN projects p ON t.project_id = p.id
                                 LEFT JOIN users u ON t.assignee_id = u.id
                                 WHERE $baseWhere
                                 ORDER BY t.created_at DESC", $baseParams);
        }
    }
} else {
    $baseWhere = "t.assignee_id = ? AND p.archived_at IS NULL";
    $baseParams = [$user['id']];
    if ($filterProjectId > 0) {
        $baseWhere .= " AND t.project_id = ?";
        $baseParams[] = $filterProjectId;
    }
    $userTasks = queryDb("SELECT t.*, p.name as project_name, u.username as assignee_name, u.name as assignee_real_name,
                         (SELECT count(*) FROM tasks WHERE parent_task_id = t.id) as subtask_count
                         FROM tasks t
                         JOIN projects p ON t.project_id = p.id
                         LEFT JOIN users u ON t.assignee_id = u.id
                         WHERE $baseWhere
                         ORDER BY t.created_at DESC", $baseParams);
}

// 按状态分组
$tasksByStatus = ['todo' => [], 'in_progress' => [], 'blocked' => [], 'done' => []];
$stats = ['total' => 0, 'todo' => 0, 'in_progress' => 0, 'blocked' => 0, 'done' => 0, 'overdue' => 0, 'due_today' => 0];
$today = date('Y-m-d');
foreach ($userTasks as $task) {
    $tasksByStatus[$task['status']][] = $task;
    $stats['total']++;
    $stats[$task['status']]++;
    if (isTaskOverdue($task)) $stats['overdue']++;
    if ($task['due_date'] === $today && $task['status'] !== 'done') $stats['due_today']++;
}

// 协助申请
$assistanceRequests = queryDb("SELECT ar.*, t.title as task_title, p.name as project_name,
                              ru.username as requester_name, ru2.username as resolver_name
                              FROM assistance_requests ar
                              JOIN tasks t ON ar.task_id = t.id
                              JOIN projects p ON t.project_id = p.id
                              JOIN users ru ON ar.requester_id = ru.id
                              LEFT JOIN users ru2 ON ar.resolver_id = ru2.id
                              WHERE ar.requester_id = ? OR ar.resolver_id = ? OR EXISTS (
                                  SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = ? AND pm.status = 'active'
                              )
                              ORDER BY ar.created_at DESC", [$user['id'], $user['id'], $user['id']]);

$unreadNotifCount = getUnreadNotificationCount($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>任务看板 - PM 系统</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
</head>
<body>
<?php echo renderHeader('📊 任务看板', $user, $unreadNotifCount, 'dashboard'); ?>

<div class="container">
    <?php if (isset($_SESSION['error_message'])): ?>
        <?php echo showError($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['success_message'])): ?>
        <?php echo showSuccess($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <div class="stats-row">
        <div class="stat-card stat-total"><div class="stat-num"><?php echo $stats['total']; ?></div><div class="stat-label">总任务</div></div>
        <div class="stat-card stat-progress"><div class="stat-num"><?php echo $stats['in_progress']; ?></div><div class="stat-label">进行中</div></div>
        <div class="stat-card stat-todo"><div class="stat-num"><?php echo $stats['todo']; ?></div><div class="stat-label">待处理</div></div>
        <div class="stat-card stat-blocked"><div class="stat-num"><?php echo $stats['blocked']; ?></div><div class="stat-label">阻塞</div></div>
        <div class="stat-card stat-done"><div class="stat-num"><?php echo $stats['done']; ?></div><div class="stat-label">已完成</div></div>
        <div class="stat-card stat-overdue"><div class="stat-num"><?php echo $stats['overdue']; ?></div><div class="stat-label">已逾期</div></div>
        <div class="stat-card stat-today"><div class="stat-num"><?php echo $stats['due_today']; ?></div><div class="stat-label">今日到期</div></div>
    </div>

    <div class="card">
        <h3>
            📋 任务看板
            <?php if (isAdmin()): ?>
                <small style="color:#888; font-weight:normal; font-size:12px;">
                    🌐 管理员全局视图:包含所有未归档项目的任务
                </small>
            <?php elseif (isProjectManager()): ?>
                <small style="color:#888; font-weight:normal; font-size:12px;">
                    你负责或参与的项目
                </small>
            <?php else: ?>
                <small style="color:#888; font-weight:normal; font-size:12px;">
                    分配给你的任务
                </small>
            <?php endif; ?>
        </h3>
        
        <!-- 项目过滤 -->
        <form method="GET" class="filter-row" style="margin-bottom: 15px;">
            <input type="hidden" name="page" value="dashboard">
            <div class="form-group" style="margin:0; min-width:200px;">
                <label>项目过滤</label>
                <select name="project_id" onchange="this.form.submit()">
                    <option value="0">全部项目</option>
                    <?php foreach ($projects as $proj): ?>
                        <option value="<?php echo (int)$proj['id']; ?>" <?php echo $filterProjectId === (int)$proj['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($proj['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
        
        <div class="kanban-board">
            <?php foreach (['todo' => '待处理', 'in_progress' => '进行中', 'blocked' => '阻塞', 'done' => '已完成'] as $status => $statusText): ?>
                <div class="kanban-column">
                    <h4><?php echo $statusText; ?> (<?php echo count($tasksByStatus[$status]); ?>)</h4>
                    <?php foreach ($tasksByStatus[$status] as $task):
                        $od = isTaskOverdue($task);
                    ?>
                        <div class="kanban-card <?php echo $od ? 'is-overdue' : ''; ?>">
                            <div class="kanban-card-title">
                                <a href="task_detail.php?task_id=<?php echo (int)$task['id']; ?>" class="task-link">
                                <?php if ($task['subtask_count'] > 0): ?>
                                    📁 <?php echo htmlspecialchars($task['title']); ?>
                                <?php else: ?>
                                    📄 <?php echo htmlspecialchars($task['title']); ?>
                                <?php endif; ?>
                                </a>
                            </div>
                            <div class="kanban-card-meta">
                                项目: <?php echo htmlspecialchars($task['project_name']); ?><br>
                                <?php if ($task['assignee_real_name']): ?>
                                    负责人: <?php echo htmlspecialchars($task['assignee_real_name']); ?> (<?php echo htmlspecialchars($task['assignee_name']); ?>)<br>
                                <?php elseif ($task['assignee_name']): ?>
                                    负责人: <?php echo htmlspecialchars($task['assignee_name']); ?><br>
                                <?php endif; ?>
                                优先级: <?php echo htmlspecialchars(ucfirst($task['priority'])); ?><br>
                                进度: <?php echo (int)$task['progress']; ?>%<br>
                                <?php if ($task['due_date']): ?>
                                    截止: <?php echo formatDate($task['due_date']); ?>
                                    <?php if ($od): ?><span class="badge-overdue">逾期</span><?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div style="margin-top: 8px; display: flex; gap: 4px;">
                                <a href="task_detail.php?task_id=<?php echo (int)$task['id']; ?>" class="btn btn-primary btn-sm" style="flex: 1;">详情 / 改状态</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <h3>🆘 协助申请</h3>
        <?php if (empty($assistanceRequests)): ?>
            <p style="color:#999;">暂无协助申请记录。</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>任务</th><th>项目</th><th>描述</th><th>状态</th><th>提交时间</th><th>处理人</th><th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assistanceRequests as $req): ?>
                        <tr>
                            <td><a href="task_detail.php?task_id=<?php echo (int)$req['task_id']; ?>" class="task-link"><?php echo htmlspecialchars($req['task_title']); ?></a></td>
                            <td><?php echo htmlspecialchars($req['project_name']); ?></td>
                            <td><?php echo htmlspecialchars(substr($req['description'], 0, 50)); ?><?php echo strlen($req['description']) > 50 ? '...' : ''; ?></td>
                            <td><span class="status-badge status-<?php echo $req['status']; ?>"><?php echo getAssistanceStatusText($req['status']); ?></span></td>
                            <td><?php echo formatDate($req['created_at']); ?></td>
                            <td><?php echo $req['resolver_name'] ? htmlspecialchars($req['resolver_name']) : '-'; ?></td>
                            <td>
                                <?php if ($req['status'] === 'pending' && (isProjectManager() || isAdmin())): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                        <button type="submit" name="handle_assistance" value="resolved" class="btn btn-success" style="padding: 3px 8px; font-size: 12px;" onclick="return confirm('确认解决此协助申请?')">解决</button>
                                        <button type="submit" name="handle_assistance" value="rejected" class="btn btn-danger" style="padding: 3px 8px; font-size: 12px;" onclick="return confirm('确认拒绝?')">拒绝</button>
                                    </form>
                                <?php endif; ?>
                            </td>
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