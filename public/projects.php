<?php
/**
 * 项目管理页面 (列表 + 分页)
 * Project Management Page (List)
 *
 * - 列表 + 分页(每页 20 条)
 * - admin: 看到全部项目; 其他用户: 看到自己负责或参与的项目
 * - 创建入口: 右上角按钮跳 project_create.php
 * - 项目名点击 → 进项目仪表盘 project_dashboard.php
 * - 操作列: 任务列表 / 编辑 / 删除(仅创建者+管理员可见)
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';

requireLogin();
$user = getCurrentUser();

// 筛选
$filterKeyword  = trim($_GET['q'] ?? '');
$filterStatus   = $_GET['status'] ?? '';
$filterArchived = $_GET['archived'] ?? 'active';  // active / all / archived

// 构造 WHERE
$where = [];
$params = [];

if (!isAdmin()) {
    // 非管理员: 只能看自己参与或负责的项目
    $where[] = "(p.manager_id = ? OR p.created_by = ? OR p.id IN (SELECT project_id FROM project_members WHERE user_id = ? AND status = 'active'))";
    $params[] = $user['id'];
    $params[] = $user['id'];
    $params[] = $user['id'];
}
if ($filterStatus === 'active') {
    $where[] = "p.archived_at IS NULL";
} elseif ($filterStatus === 'archived') {
    $where[] = "p.archived_at IS NOT NULL";
}
if ($filterKeyword !== '') {
    $where[] = "(p.name LIKE ? OR p.description LIKE ?)";
    $kw = '%' . $filterKeyword . '%';
    $params[] = $kw;
    $params[] = $kw;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// 分页
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 20;
$offset   = ($page - 1) * $perPage;

// 统计总数
$totalRow  = queryOneDb("SELECT COUNT(*) AS c FROM projects p $whereSql", $params);
$total     = (int)($totalRow['c'] ?? 0);
$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

// 主查询
$sql = "SELECT p.*,
               u.username as manager_name,
               u.name as manager_real_name,
               cu.username as creator_username,
               cu.name as creator_real_name,
               (SELECT COUNT(*) FROM project_members WHERE project_id = p.id AND status = 'active') as member_count,
               (SELECT COUNT(*) FROM tasks WHERE project_id = p.id) as task_count
        FROM projects p
        LEFT JOIN users u  ON p.manager_id = u.id
        LEFT JOIN users cu ON p.created_by = cu.id
        $whereSql
        ORDER BY p.archived_at IS NOT NULL, p.id DESC
        LIMIT $perPage OFFSET $offset";
$projects = queryDb($sql, $params);

// 批量取本页所有项目的进度统计(一次 SQL, 避免 N+1)
$projectStats = [];  // [project_id] => stats array
if (!empty($projects)) {
    $ids = array_map(function($r) { return (int)$r['id']; }, $projects);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = queryDb(
        "SELECT project_id, status, progress, due_date
         FROM tasks
         WHERE project_id IN ($placeholders)",
        $ids
    );
    $today = date('Y-m-d');
    foreach ($ids as $pid) {
        $projectStats[$pid] = [
            'total' => 0, 'todo' => 0, 'in_progress' => 0, 'blocked' => 0, 'done' => 0,
            'overdue' => 0, 'avg_progress' => 0, 'done_rate' => 0,
        ];
    }
    // 聚合
    $sums = []; $counts = [];
    foreach ($rows as $r) {
        $pid = (int)$r['project_id'];
        $projectStats[$pid]['total']++;
        if (isset($projectStats[$pid][$r['status']])) {
            $projectStats[$pid][$r['status']]++;
        }
        // 逾期: 未 done 且 due_date < today 且非空
        if ($r['status'] !== 'done' && !empty($r['due_date']) && $r['due_date'] < $today) {
            $projectStats[$pid]['overdue']++;
        }
        $sums[$pid]   = ($sums[$pid]   ?? 0) + (int)$r['progress'];
        $counts[$pid] = ($counts[$pid] ?? 0) + 1;
    }
    foreach ($ids as $pid) {
        if (!empty($counts[$pid])) {
            $projectStats[$pid]['avg_progress'] = (int)($sums[$pid] / $counts[$pid]);
            $projectStats[$pid]['done_rate'] = $projectStats[$pid]['total'] > 0
                ? round($projectStats[$pid]['done'] * 100 / $projectStats[$pid]['total'], 1)
                : 0;
        }
    }
}

$unreadNotifCount = getUnreadNotificationCount($user['id']);
$canCreate = isProjectManager() || isAdmin();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>项目管理 - PM 系统</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
</head>
<body>
<?php echo renderHeader('📂 项目管理', $user, $unreadNotifCount, 'projects'); ?>

<div class="container">
    <?php if (!empty($_SESSION['error_message'])) {
        echo showError($_SESSION['error_message']); unset($_SESSION['error_message']);
    } ?>
    <?php if (!empty($_SESSION['success_message'])) {
        echo showSuccess($_SESSION['success_message']); unset($_SESSION['success_message']);
    } ?>

    <div class="card">
        <div class="section-header">
            <h3>
                <?php echo isAdmin() ? '🌐 全部项目' : '📁 我的项目'; ?>
                <small style="color:#888; font-weight:normal; font-size:12px;">
                    共 <?php echo $total; ?> 个
                </small>
            </h3>
            <?php if ($canCreate): ?>
                <a href="project_create.php" class="btn btn-primary">➕ 新建项目</a>
            <?php endif; ?>
        </div>

        <!-- 筛选 -->
        <form method="GET" class="filter-row" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom: 12px;">
            <div class="form-group" style="margin:0; flex:1; min-width:200px;">
                <label>关键词</label>
                <input type="text" name="q" placeholder="搜索项目名 / 描述" value="<?php echo htmlspecialchars($filterKeyword); ?>">
            </div>
            <div class="form-group" style="margin:0; min-width:140px;">
                <label>状态</label>
                <select name="status">
                    <option value="active"   <?php echo $filterStatus==='active'?'selected':'';   ?>>活跃</option>
                    <option value="archived" <?php echo $filterStatus==='archived'?'selected':''; ?>>已归档</option>
                    <option value="all"      <?php echo $filterStatus==='all'?'selected':'';      ?>>全部</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">🔍 筛选</button>
            <a href="projects.php" class="btn btn-danger">重置</a>
        </form>

        <?php if (empty($projects)): ?>
            <p style="color:#999; padding: 24px; text-align:center;">
                <?php echo $total === 0 ? '暂无项目' : '当前页没有数据'; ?>
                <?php if ($canCreate): ?>
                    <br><a href="project_create.php" class="btn btn-primary" style="margin-top:12px;">➕ 创建第一个项目</a>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>项目名称 / 描述</th>
                        <th>负责人</th>
                        <th>创建者</th>
                        <th>起止</th>
                        <th>成员 / 任务</th>
                        <th>状态</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($projects as $p):
                    $isMgr      = ((int)$p['manager_id'] === (int)$user['id']) || isAdmin();
                    $isMember   = isProjectMember((int)$p['id'], (int)$user['id']);
                    $canEnter   = $isMgr || $isMember || isAdmin();
                    $canDelete  = canDeleteProject($p, $user);
                    $canEdit    = $isMgr;  // 编辑需要负责人或管理员
                ?>
                    <tr>
                        <td>
                            <?php if ($canEnter): ?>
                                <a href="project_dashboard.php?project_id=<?php echo (int)$p['id']; ?>"
                                   style="font-weight:600; font-size:14px;">
                                    📂 <?php echo htmlspecialchars($p['name']); ?>
                                </a>
                            <?php else: ?>
                                <span style="font-weight:600; color:#999;">
                                    🔒 <?php echo htmlspecialchars($p['name']); ?>
                                    <small style="font-weight:normal; color:#999;">(非项目成员)</small>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($p['description'])): ?>
                                <div style="font-size:11px; color:#888; margin-top:3px;">
                                    <?php echo htmlspecialchars(mb_substr($p['description'], 0, 80)); ?>
                                    <?php echo mb_strlen($p['description']) > 80 ? '…' : ''; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($p['manager_real_name'] ?: $p['manager_name'] ?: '-'); ?>
                        </td>
                        <td style="font-size:12px; color:#666;">
                            <?php echo htmlspecialchars($p['creator_real_name'] ?: $p['creator_username'] ?: '-'); ?>
                        </td>
                        <td style="font-size:12px;">
                            <?php echo formatDate($p['start_date']); ?> ~<br>
                            <?php echo formatDate($p['end_date']); ?>
                        </td>
                        <td style="text-align:center;">
                            👥 <?php echo (int)$p['member_count']; ?><br>
                            📋 <?php echo (int)$p['task_count']; ?>
                        </td>
                        <td>
                            <?php if (!empty($p['archived_at'])): ?>
                                <span class="badge-warn">📦 已归档</span>
                            <?php else: ?>
                                <span class="status-badge status-in_progress">活跃</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php if ($canEnter): ?>
                                <a href="project_dashboard.php?project_id=<?php echo (int)$p['id']; ?>"
                                   class="btn btn-primary btn-sm">仪表盘</a>
                                <a href="tasks.php?project_id=<?php echo (int)$p['id']; ?>"
                                   class="btn btn-success btn-sm">任务</a>
                            <?php endif; ?>
                            <?php if ($canEdit): ?>
                                <a href="project_edit.php?project_id=<?php echo (int)$p['id']; ?>"
                                   class="btn btn-warning btn-sm">编辑</a>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <form method="POST" action="project_delete.php" style="display:inline;"
                                      onsubmit="return confirm('确认删除项目「<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>」?\n该操作不可恢复,所有任务/成员/评论/附件/工时都会被一并清理。')">
                                    <input type="hidden" name="project_id" value="<?php echo (int)$p['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">删除</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php
                        // 进度摘要行( colspan 覆盖全列, 让用户一眼看到进度)
                        $st = $projectStats[(int)$p['id']] ?? ['total'=>0,'todo'=>0,'in_progress'=>0,'blocked'=>0,'done'=>0,'overdue'=>0,'avg_progress'=>0,'done_rate'=>0];
                    ?>
                    <tr class="project-progress-row">
                        <td colspan="7">
                            <div class="project-progress-summary">
                                <?php if ($st['total'] === 0): ?>
                                    <span class="stat empty">📭 暂无任务</span>
                                <?php else: ?>
                                    <span class="stat"><span>📋</span> 总任务 <strong><?php echo (int)$st['total']; ?></strong></span>
                                    <span class="stat done"><span>✅</span> 已完成 <strong><?php echo (int)$st['done']; ?></strong> (<?php echo $st['done_rate']; ?>%)</span>
                                    <span class="stat in_progress"><span>🔄</span> 进行中 <strong><?php echo (int)$st['in_progress']; ?></strong></span>
                                    <span class="stat todo"><span>📌</span> 待处理 <strong><?php echo (int)$st['todo']; ?></strong></span>
                                    <?php if ((int)$st['blocked'] > 0): ?>
                                        <span class="stat blocked"><span>⛔</span> 阻塞 <strong><?php echo (int)$st['blocked']; ?></strong></span>
                                    <?php endif; ?>
                                    <?php if ((int)$st['overdue'] > 0): ?>
                                        <span class="stat overdue"><span>⚠️</span> 逾期 <strong><?php echo (int)$st['overdue']; ?></strong></span>
                                    <?php endif; ?>
                                    <span class="label">平均进度</span>
                                    <div class="project-progress-bar" title="平均进度 <?php echo (int)$st['avg_progress']; ?>%">
                                        <div class="fill" style="width: <?php echo (int)$st['avg_progress']; ?>%;"></div>
                                    </div>
                                    <strong style="min-width:32px; text-align:right;"><?php echo (int)$st['avg_progress']; ?>%</strong>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php echo renderPagination($page, $totalPages, 'projects.php', [
                'q'      => $filterKeyword,
                'status' => $filterStatus,
            ]); ?>
        <?php endif; ?>
    </div>
</div>

<?php echo renderFooter(); ?>
</body>
</html>
