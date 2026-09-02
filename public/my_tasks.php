<?php
/**
 * 我的任务页
 * My Tasks Page
 *
 * 显示分配给当前用户的所有任务,按截止日期排序,逾期/即将到期高亮
 * 任务状态可在此页快速更新
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';

requireLogin();

$user = getCurrentUser();

// 筛选
$filterStatus   = $_GET['status'] ?? '';
$filterPriority = $_GET['priority'] ?? '';
$filterProject  = (int)($_GET['project_id'] ?? 0);
$filterOverdue  = !empty($_GET['overdue']);

// 构造 WHERE
$where = ["t.assignee_id = ?"];
$params = [$user['id']];

if ($filterStatus) {
    $where[] = "t.status = ?";
    $params[] = $filterStatus;
}
if ($filterPriority) {
    $where[] = "t.priority = ?";
    $params[] = $filterPriority;
}
if ($filterProject) {
    $where[] = "t.project_id = ?";
    $params[] = $filterProject;
}

$sql = "SELECT t.*, p.name as project_name, p.archived_at,
               (SELECT COUNT(*) FROM task_comments WHERE task_id = t.id) as comment_count,
               (SELECT COUNT(*) FROM task_attachments WHERE task_id = t.id) as attachment_count,
               (SELECT COUNT(*) FROM task_dependencies WHERE task_id = t.id) as dep_count
        FROM tasks t
        JOIN projects p ON t.project_id = p.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY
          CASE WHEN t.status = 'done' THEN 2 ELSE 0 END ASC,
          CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END ASC,
          t.due_date ASC,
          t.priority DESC,
          t.id DESC";
$myTasks = queryDb($sql, $params);

// 排除已归档项目的任务(?)
$activeTasks = array_values(array_filter($myTasks, function($t) { return empty($t['archived_at']); }));

// 统计
$stats = [
    'total'    => count($activeTasks),
    'todo'     => 0,
    'in_progress' => 0,
    'blocked'  => 0,
    'done'     => 0,
    'overdue'  => 0,
    'due_today'=> 0,
    'due_week' => 0,
];
$today = date('Y-m-d');
$weekEnd = date('Y-m-d', strtotime('+7 days'));
foreach ($activeTasks as $t) {
    if (isset($stats[$t['status']])) $stats[$t['status']]++;
    if (isTaskOverdue($t)) $stats['overdue']++;
    if ($t['due_date'] === $today) $stats['due_today']++;
    if ($t['due_date'] && $t['due_date'] >= $today && $t['due_date'] <= $weekEnd && $t['status'] !== 'done') $stats['due_week']++;
}

// 用户参与的项目列表(用于筛选)
$myProjects = getUserProjects($user['id']);

$unreadNotifCount = getUnreadNotificationCount($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>我的任务 - PM 系统</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
</head>
<body>
<?php echo renderHeader('📋 我的任务', $user, $unreadNotifCount, 'my_tasks'); ?>

<div class="container">
    <!-- 统计卡片 -->
    <div class="stats-row">
        <div class="stat-card stat-total">
            <div class="stat-num"><?php echo $stats['total']; ?></div>
            <div class="stat-label">总任务</div>
        </div>
        <div class="stat-card stat-overdue">
            <div class="stat-num"><?php echo $stats['overdue']; ?></div>
            <div class="stat-label">已逾期</div>
        </div>
        <div class="stat-card stat-today">
            <div class="stat-num"><?php echo $stats['due_today']; ?></div>
            <div class="stat-label">今日到期</div>
        </div>
        <div class="stat-card stat-week">
            <div class="stat-num"><?php echo $stats['due_week']; ?></div>
            <div class="stat-label">7天内到期</div>
        </div>
        <div class="stat-card stat-progress">
            <div class="stat-num"><?php echo $stats['in_progress']; ?></div>
            <div class="stat-label">进行中</div>
        </div>
        <div class="stat-card stat-blocked">
            <div class="stat-num"><?php echo $stats['blocked']; ?></div>
            <div class="stat-label">阻塞</div>
        </div>
        <div class="stat-card stat-done">
            <div class="stat-num"><?php echo $stats['done']; ?></div>
            <div class="stat-label">已完成</div>
        </div>
    </div>

    <!-- 筛选 -->
    <div class="card">
        <form method="GET" class="filter-row">
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
                <label>项目</label>
                <select name="project_id">
                    <option value="0">全部项目</option>
                    <?php foreach ($myProjects as $p): ?>
                        <option value="<?php echo (int)$p['id']; ?>" <?php echo $filterProject===(int)$p['id']?'selected':''; ?>><?php echo htmlspecialchars($p['name']); ?><?php echo $p['archived_at']?' (已归档)':''; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0; min-width:120px;">
                <label>&nbsp;</label>
                <label style="font-weight:normal;">
                    <input type="checkbox" name="overdue" value="1" <?php echo $filterOverdue?'checked':''; ?>> 只看逾期
                </label>
            </div>
            <button type="submit" class="btn btn-primary">筛选</button>
            <a href="my_tasks.php" class="btn btn-danger">重置</a>
        </form>
    </div>

    <!-- 任务列表 -->
    <div class="card">
        <h3>📋 任务列表 (<?php echo count($activeTasks); ?>)</h3>
        <?php if (empty($activeTasks)): ?>
            <p style="color:#999;">没有任务。要么是过滤条件太严,要么没人给你分活儿,去找项目经理聊聊 👀</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>状态</th>
                        <th>任务</th>
                        <th>项目</th>
                        <th>优先级</th>
                        <th>截止</th>
                        <th>进度</th>
                        <th>评论/附件/依赖</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($activeTasks as $t):
                    $od = isTaskOverdue($t);
                    $isToday = ($t['due_date'] === $today);
                    $isSoon = $t['due_date'] && $t['due_date'] > $today && $t['due_date'] <= $weekEnd && $t['status'] !== 'done';
                    $rowClass = $od ? 'row-overdue' : ($isToday ? 'row-today' : ($isSoon ? 'row-soon' : ''));
                ?>
                    <tr class="<?php echo $rowClass; ?>">
                        <td>
                            <span class="status-badge status-<?php echo htmlspecialchars($t['status']); ?>"><?php echo getTaskStatusText($t['status']); ?></span>
                            <?php if ($od): ?><br><span class="badge-overdue">⚠️ 逾期</span><?php elseif ($isToday): ?><br><span class="badge-warn">⏰ 今日到期</span><?php elseif ($isSoon): ?><br><span class="badge-warn">📅 即将到期</span><?php endif; ?>
                        </td>
                        <td>
                            <a href="task_detail.php?task_id=<?php echo (int)$t['id']; ?>" class="task-link"><?php echo htmlspecialchars($t['title']); ?></a>
                            <?php if (!empty($t['description'])): ?>
                                <div style="font-size:11px; color:#999; margin-top:3px;"><?php echo htmlspecialchars(mb_substr($t['description'], 0, 60)); ?><?php echo mb_strlen($t['description']) > 60 ? '...' : ''; ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($t['project_name']); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($t['priority'])); ?></td>
                        <td>
                            <?php echo $t['due_date'] ? formatDate($t['due_date']) : '-'; ?>
                            <?php if ($od): ?>
                                <br><small style="color:#dc3545;">已逾期 <?php echo (int)((strtotime($today) - strtotime($t['due_date'])) / 86400); ?> 天</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="progress-bar" title="<?php echo (int)$t['progress']; ?>%">
                                <div class="progress-bar-fill" style="width:<?php echo (int)$t['progress']; ?>%;"></div>
                                <span class="progress-bar-text"><?php echo (int)$t['progress']; ?>%</span>
                            </div>
                        </td>
                        <td>
                            💬 <?php echo (int)$t['comment_count']; ?> |
                            📎 <?php echo (int)$t['attachment_count']; ?> |
                            🔗 <?php echo (int)$t['dep_count']; ?>
                        </td>
                        <td>
                            <a href="task_detail.php?task_id=<?php echo (int)$t['id']; ?>" class="btn btn-primary" style="padding:3px 8px; font-size:11px;">详情</a>
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
