<?php
/**
 * 项目仪表盘
 * Project Dashboard
 *
 * 一个项目的全景视图:
 *   - 项目基础信息 + 归档操作
 *   - 统计: 总任务/完成率/进行中/阻塞/逾期/平均进度
 *   - 状态分布条形图
 *   - 成员负载
 *   - 里程碑
 *   - 任务列表 (含筛选/排序)
 *   - 最近活动
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';

requireLogin();

$user = getCurrentUser();
$projectId = (int)($_GET['project_id'] ?? 0);
$error = '';
$success = '';

$project = queryOneDb(
    "SELECT p.*, u.username as manager_name, u.name as manager_real_name
     FROM projects p LEFT JOIN users u ON p.manager_id = u.id
     WHERE p.id = ?", [$projectId]);
if (!$project) die('项目不存在');

$isProjectManager = ($project['manager_id'] == $user['id']) || isAdmin();
$isMember = isProjectMember($projectId, $user['id']);
if (!$isProjectManager && !$isMember && !isAdmin()) die('权限不足');

// ========================================================================
// POST 处理: 归档/取消归档, 里程碑增删
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'archive_project') {
            if (!$isProjectManager) throw new Exception('权限不足');
            archiveProject($projectId);
            logOperation($user['id'], 'archive', 'project', $projectId, []);
            $success = '项目已归档';
        }
        elseif ($action === 'unarchive_project') {
            if (!$isProjectManager) throw new Exception('权限不足');
            unarchiveProject($projectId);
            logOperation($user['id'], 'unarchive', 'project', $projectId, []);
            $success = '项目已恢复为活跃';
        }
        elseif ($action === 'add_milestone') {
            if (!$isProjectManager) throw new Exception('权限不足');
            $name = trim($_POST['milestone_name'] ?? '');
            $desc = trim($_POST['milestone_description'] ?? '');
            $due  = $_POST['milestone_due_date'] ?: null;
            if (empty($name)) throw new Exception('里程碑名称不能为空');
            addMilestone($projectId, $name, $desc, $due, $user['id']);
            logOperation($user['id'], 'create', 'milestone', null, ['project_id' => $projectId, 'name' => $name]);
            $success = '里程碑已添加';
        }
        elseif ($action === 'update_milestone') {
            if (!$isProjectManager) throw new Exception('权限不足');
            $mid = (int)($_POST['milestone_id'] ?? 0);
            $name = trim($_POST['milestone_name'] ?? '');
            $desc = trim($_POST['milestone_description'] ?? '');
            $due  = $_POST['milestone_due_date'] ?: null;
            $st   = $_POST['milestone_status'] ?? 'pending';
            updateMilestone($mid, $name, $desc, $due, $st);
            logOperation($user['id'], 'update', 'milestone', $mid, []);
            $success = '里程碑已更新';
        }
        elseif ($action === 'delete_milestone') {
            if (!$isProjectManager) throw new Exception('权限不足');
            $mid = (int)($_POST['milestone_id'] ?? 0);
            deleteMilestone($mid);
            logOperation($user['id'], 'delete', 'milestone', $mid, []);
            $success = '里程碑已删除';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
    // 重读
    $project = queryOneDb(
        "SELECT p.*, u.username as manager_name, u.name as manager_real_name
         FROM projects p LEFT JOIN users u ON p.manager_id = u.id
         WHERE p.id = ?", [$projectId]);
}

// ========================================================================
// 数据
// ========================================================================
$stats = getProjectStats($projectId);

// 任务列表(支持筛选)
$filterStatus   = $_GET['status'] ?? '';
$filterAssignee = (int)($_GET['assignee_id'] ?? 0);
$filterPriority = $_GET['priority'] ?? '';
$sortBy = $_GET['sort'] ?? 'due_date';

$where = ["t.project_id = ?"];
$params = [$projectId];
if ($filterStatus)   { $where[] = "t.status = ?";   $params[] = $filterStatus; }
if ($filterAssignee) { $where[] = "t.assignee_id = ?"; $params[] = $filterAssignee; }
if ($filterPriority) { $where[] = "t.priority = ?"; $params[] = $filterPriority; }

$orderBy = "CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END ASC, t.due_date ASC";
switch ($sortBy) {
    case 'priority':   $orderBy = "CASE t.priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END ASC, t.due_date ASC"; break;
    case 'progress':   $orderBy = "t.progress DESC, t.due_date ASC"; break;
    case 'status':     $orderBy = "t.status ASC, t.due_date ASC"; break;
    case 'created_at': $orderBy = "t.created_at DESC"; break;
    case 'assignee':   $orderBy = "u.username ASC, t.due_date ASC"; break;
}
$tasks = queryDb(
    "SELECT t.*, u.username as assignee_name, u.name as assignee_real_name
     FROM tasks t LEFT JOIN users u ON t.assignee_id = u.id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY " . $orderBy, $params
);

// 成员负载
$memberLoads = queryDb(
    "SELECT u.id, u.username, u.name as real_name,
            COUNT(t.id) as total_tasks,
            SUM(CASE WHEN t.status = 'done' THEN 1 ELSE 0 END) as done_tasks,
            SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks,
            SUM(CASE WHEN t.status = 'blocked' THEN 1 ELSE 0 END) as blocked_tasks,
            SUM(CASE WHEN t.due_date IS NOT NULL AND t.due_date < DATE('now') AND t.status != 'done' THEN 1 ELSE 0 END) as overdue_tasks
     FROM project_members pm
     JOIN users u ON pm.user_id = u.id
     LEFT JOIN tasks t ON t.project_id = pm.project_id AND t.assignee_id = u.id
     WHERE pm.project_id = ? AND pm.status = 'active'
     GROUP BY u.id, u.username, u.name
     ORDER BY total_tasks DESC",
    [$projectId]
);

// 里程碑
$milestones = getProjectMilestones($projectId);

// 项目成员
$projectMembers = queryDb(
    "SELECT u.*, pm.custom_role FROM project_members pm JOIN users u ON pm.user_id = u.id
     WHERE pm.project_id = ? AND pm.status = 'active'",
    [$projectId]
);

// 最近活动
$recentOps = queryDb(
    "SELECT ol.*, u.username, u.name as user_real_name
     FROM operation_logs ol LEFT JOIN users u ON ol.user_id = u.id
     WHERE (ol.target_type = 'task' AND ol.target_id IN (SELECT id FROM tasks WHERE project_id = ?))
        OR (ol.target_type = 'project' AND ol.target_id = ?)
     ORDER BY ol.created_at DESC, ol.id DESC
     LIMIT 30", [$projectId, $projectId]
);

$unreadNotifCount = getUnreadNotificationCount($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($project['name']); ?> - 项目仪表盘</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
</head>
<body>
<?php
echo renderHeader('📊 ' . htmlspecialchars($project['name']), $user, $unreadNotifCount, 'projects', [
    'project_id' => (int)$projectId,
    'project_name' => $project['name'] ?? '',
    'sub_active' => 'project_dashboard',
]);
?>

<div class="container">
    <?php if (!empty($error)) echo showError($error); ?>
    <?php if (!empty($success)) echo showSuccess($success); ?>

    <!-- 项目信息 -->
    <div class="card">
        <h3>
            <?php echo htmlspecialchars($project['name']); ?>
            <?php if ($project['archived_at']): ?>
                <span class="badge-warn">📦 已归档</span>
            <?php elseif ($project['status'] === 'active'): ?>
                <span class="status-badge status-in_progress">活跃</span>
            <?php endif; ?>
        </h3>
        <div class="task-meta">
            <div><strong>项目经理:</strong> <?php echo htmlspecialchars($project['manager_real_name'] ?: $project['manager_name']); ?></div>
            <div><strong>起止:</strong> <?php echo formatDate($project['start_date']); ?> - <?php echo formatDate($project['end_date']); ?></div>
            <div><strong>描述:</strong> <?php echo nl2br(htmlspecialchars($project['description'] ?: '(无)')); ?></div>
        </div>
        <?php if ($isProjectManager): ?>
            <div style="margin-top:10px;">
                <?php if ($project['archived_at']): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('确认恢复此项目?')">
                        <input type="hidden" name="action" value="unarchive_project">
                        <button type="submit" class="btn btn-success">📤 恢复为活跃</button>
                    </form>
                <?php else: ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('确认归档此项目? 归档后任务仍保留,只是不再出现在活跃列表。')">
                        <input type="hidden" name="action" value="archive_project">
                        <button type="submit" class="btn btn-warning">📦 归档项目</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- 统计 -->
    <div class="stats-row">
        <div class="stat-card stat-total"><div class="stat-num"><?php echo $stats['total']; ?></div><div class="stat-label">总任务</div></div>
        <div class="stat-card stat-done"><div class="stat-num"><?php echo $stats['done']; ?></div><div class="stat-label">已完成 (<?php echo $stats['done_rate']; ?>%)</div></div>
        <div class="stat-card stat-progress"><div class="stat-num"><?php echo $stats['in_progress']; ?></div><div class="stat-label">进行中</div></div>
        <div class="stat-card stat-todo"><div class="stat-num"><?php echo $stats['todo']; ?></div><div class="stat-label">待处理</div></div>
        <div class="stat-card stat-blocked"><div class="stat-num"><?php echo $stats['blocked']; ?></div><div class="stat-label">阻塞</div></div>
        <div class="stat-card stat-overdue"><div class="stat-num"><?php echo $stats['overdue']; ?></div><div class="stat-label">逾期</div></div>
        <div class="stat-card stat-avg"><div class="stat-num"><?php echo $stats['avg_progress']; ?>%</div><div class="stat-label">平均进度</div></div>
    </div>

    <!-- 状态分布 + 成员负载 -->
    <div class="row-2col">
        <div class="card">
            <h3>📊 任务状态分布</h3>
            <?php if ($stats['total'] == 0): ?>
                <p style="color:#999;">还没有任务</p>
            <?php else: ?>
                <div class="status-bar">
                    <?php
                    $statusInfo = [
                        'todo' => ['label' => '待处理', 'color' => '#6c757d'],
                        'in_progress' => ['label' => '进行中', 'color' => '#3498db'],
                        'blocked' => ['label' => '阻塞', 'color' => '#e74c3c'],
                        'done' => ['label' => '已完成', 'color' => '#28a745'],
                    ];
                    foreach ($statusInfo as $k => $info):
                        $c = $stats[$k];
                        if ($c == 0) continue;
                        $pct = $c * 100 / $stats['total'];
                    ?>
                        <div class="status-bar-seg" style="width:<?php echo $pct; ?>%; background:<?php echo $info['color']; ?>;" title="<?php echo $info['label']; ?>: <?php echo $c; ?> (<?php echo number_format($pct, 1); ?>%)">
                            <?php echo $pct > 8 ? $info['label'] . ' ' . $c : ''; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <ul style="list-style:none; padding:0; margin-top:8px;">
                    <?php foreach ($statusInfo as $k => $info):
                        $c = $stats[$k]; $pct = $stats['total'] > 0 ? round($c * 100 / $stats['total'], 1) : 0; ?>
                        <li style="display:flex; align-items:center; gap:8px; padding:3px 0;">
                            <span style="display:inline-block; width:12px; height:12px; background:<?php echo $info['color']; ?>; border-radius:2px;"></span>
                            <span><?php echo $info['label']; ?></span>
                            <span style="margin-left:auto; color:#666;"><?php echo $c; ?> (<?php echo $pct; ?>%)</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>👥 成员负载</h3>
            <?php if (empty($memberLoads)): ?>
                <p style="color:#999;">还没有成员</p>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>成员</th><th>总</th><th>进行</th><th>阻塞</th><th>逾期</th><th>完成</th></tr></thead>
                    <tbody>
                    <?php foreach ($memberLoads as $m): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($m['real_name'] ?: $m['username']); ?></td>
                            <td><?php echo (int)$m['total_tasks']; ?></td>
                            <td><?php echo (int)$m['in_progress_tasks']; ?></td>
                            <td><?php if ((int)$m['blocked_tasks'] > 0): ?><span class="status-badge status-blocked"><?php echo (int)$m['blocked_tasks']; ?></span><?php else: echo '0'; endif; ?></td>
                            <td><?php if ((int)$m['overdue_tasks'] > 0): ?><span class="badge-overdue"><?php echo (int)$m['overdue_tasks']; ?></span><?php else: echo '0'; endif; ?></td>
                            <td><?php echo (int)$m['done_tasks']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- 里程碑 -->
    <div class="card">
        <h3>🎯 里程碑</h3>
        <?php if (empty($milestones)): ?>
            <p style="color:#999;">还没有里程碑</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>名称</th><th>描述</th><th>截止</th><th>状态</th><th>项目进度</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($milestones as $m):
                    $rate = $m['total_tasks'] > 0 ? round($m['done_tasks'] * 100 / $m['total_tasks'], 1) : 0;
                    $ms = isTaskOverdue(['due_date' => $m['due_date'], 'status' => $m['status']]) && $m['status'] !== 'done' && $m['status'] !== 'reached';
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($m['name']); ?></td>
                        <td><?php echo htmlspecialchars(mb_substr($m['description'] ?: '-', 0, 50)); ?></td>
                        <td>
                            <?php echo $m['due_date'] ? formatDate($m['due_date']) : '-'; ?>
                            <?php if ($ms): ?><br><span class="badge-overdue">逾期</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($m['status'] === 'reached'): ?><span class="status-badge status-done">已达成</span>
                            <?php else: ?><span class="status-badge status-todo">未达成</span><?php endif; ?>
                        </td>
                        <td>
                            <div class="progress-bar" style="width:120px; display:inline-block;" title="<?php echo $rate; ?>%">
                                <div class="progress-bar-fill" style="width:<?php echo $rate; ?>%;"></div>
                                <span class="progress-bar-text"><?php echo $rate; ?>%</span>
                            </div>
                        </td>
                        <td>
                            <?php if ($isProjectManager): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('删除里程碑?')">
                                    <input type="hidden" name="action" value="delete_milestone">
                                    <input type="hidden" name="milestone_id" value="<?php echo (int)$m['id']; ?>">
                                    <button type="submit" class="btn btn-danger" style="padding:2px 6px; font-size:11px;">删</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($isProjectManager): ?>
            <hr>
            <h4>添加里程碑</h4>
            <form method="POST" style="display:flex; gap:8px; flex-wrap:wrap; align-items:end;">
                <input type="hidden" name="action" value="add_milestone">
                <div class="form-group" style="flex:1; min-width:200px; margin:0;">
                    <label>名称</label>
                    <input type="text" name="milestone_name" required>
                </div>
                <div class="form-group" style="flex:2; min-width:200px; margin:0;">
                    <label>描述</label>
                    <input type="text" name="milestone_description">
                </div>
                <div class="form-group" style="margin:0;">
                    <label>截止日期</label>
                    <input type="date" name="milestone_due_date">
                </div>
                <button type="submit" class="btn btn-primary">+ 添加</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- 任务列表 -->
    <div class="card">
        <h3>📋 任务清单 (<?php echo count($tasks); ?>)</h3>
        <form method="GET" class="filter-row">
            <input type="hidden" name="project_id" value="<?php echo (int)$projectId; ?>">
            <div class="form-group" style="margin:0; min-width:120px;">
                <label>状态</label>
                <select name="status">
                    <option value="">全部</option>
                    <option value="todo" <?php echo $filterStatus==='todo'?'selected':''; ?>>待处理</option>
                    <option value="in_progress" <?php echo $filterStatus==='in_progress'?'selected':''; ?>>进行中</option>
                    <option value="blocked" <?php echo $filterStatus==='blocked'?'selected':''; ?>>阻塞</option>
                    <option value="done" <?php echo $filterStatus==='done'?'selected':''; ?>>已完成</option>
                </select>
            </div>
            <div class="form-group" style="margin:0; min-width:120px;">
                <label>优先级</label>
                <select name="priority">
                    <option value="">全部</option>
                    <option value="high" <?php echo $filterPriority==='high'?'selected':''; ?>>高</option>
                    <option value="medium" <?php echo $filterPriority==='medium'?'selected':''; ?>>中</option>
                    <option value="low" <?php echo $filterPriority==='low'?'selected':''; ?>>低</option>
                </select>
            </div>
            <div class="form-group" style="margin:0; min-width:160px;">
                <label>负责人</label>
                <select name="assignee_id">
                    <option value="0">全部</option>
                    <?php foreach ($projectMembers as $pm): ?>
                        <option value="<?php echo (int)$pm['id']; ?>" <?php echo $filterAssignee===(int)$pm['id']?'selected':''; ?>><?php echo htmlspecialchars($pm['name'] ?: $pm['username']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0; min-width:120px;">
                <label>排序</label>
                <select name="sort">
                    <option value="due_date"   <?php echo $sortBy==='due_date'?'selected':''; ?>>截止日期</option>
                    <option value="priority"   <?php echo $sortBy==='priority'?'selected':''; ?>>优先级</option>
                    <option value="progress"   <?php echo $sortBy==='progress'?'selected':''; ?>>进度</option>
                    <option value="status"     <?php echo $sortBy==='status'?'selected':''; ?>>状态</option>
                    <option value="created_at" <?php echo $sortBy==='created_at'?'selected':''; ?>>创建时间</option>
                    <option value="assignee"   <?php echo $sortBy==='assignee'?'selected':''; ?>>负责人</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">应用</button>
        </form>
        <table class="table" style="margin-top:10px;">
            <thead>
                <tr><th>状态</th><th>任务</th><th>负责人</th><th>优先级</th><th>截止</th><th>进度</th></tr>
            </thead>
            <tbody>
            <?php if (empty($tasks)): ?>
                <tr><td colspan="6" style="color:#999;">没有符合条件的任务</td></tr>
            <?php else: foreach ($tasks as $t):
                $od = isTaskOverdue($t);
            ?>
                <tr class="<?php echo $od ? 'row-overdue' : ''; ?>">
                    <td>
                        <span class="status-badge status-<?php echo htmlspecialchars($t['status']); ?>"><?php echo getTaskStatusText($t['status']); ?></span>
                        <?php if ($od): ?><br><span class="badge-overdue">逾期</span><?php endif; ?>
                    </td>
                    <td><a href="task_detail.php?task_id=<?php echo (int)$t['id']; ?>" class="task-link"><?php echo htmlspecialchars($t['title']); ?></a></td>
                    <td><?php echo htmlspecialchars($t['assignee_real_name'] ?: $t['assignee_name'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($t['priority'])); ?></td>
                    <td><?php echo $t['due_date'] ? formatDate($t['due_date']) : '-'; ?></td>
                    <td>
                        <div class="progress-bar" style="width:100px; display:inline-block;" title="<?php echo (int)$t['progress']; ?>%">
                            <div class="progress-bar-fill" style="width:<?php echo (int)$t['progress']; ?>%;"></div>
                            <span class="progress-bar-text"><?php echo (int)$t['progress']; ?>%</span>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- 最近活动 -->
    <div class="card">
        <h3>🕐 最近活动 (本项目相关,最新 30 条)</h3>
        <?php if (empty($recentOps)): ?>
            <p style="color:#999;">还没有活动</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>时间</th><th>操作人</th><th>操作</th><th>对象</th><th>详情</th></tr></thead>
                <tbody>
                <?php foreach ($recentOps as $op): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($op['created_at']); ?></td>
                        <td><?php echo htmlspecialchars($op['user_real_name'] ?: $op['username'] ?: '(系统)'); ?></td>
                        <td><span class="action-tag action-<?php echo htmlspecialchars($op['action']); ?>"><?php echo htmlspecialchars($op['action']); ?></span></td>
                        <td><?php echo htmlspecialchars($op['target_type']); ?><?php if ($op['target_id']): ?> #<?php echo (int)$op['target_id']; ?><?php endif; ?></td>
                        <td><pre class="log-details"><?php echo htmlspecialchars(mb_substr($op['details'] ?: '-', 0, 200)); ?></pre></td>
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
