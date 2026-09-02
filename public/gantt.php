<?php
/**
 * 简易甘特图
 * Simple Gantt Chart
 *
 * 用 HTML/CSS 画的极简甘特图:任务列表 + 时间轴条 + 进度填充
 * 显示任务依赖(可视化连线由后端生成 SVG)
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';

requireLogin();

$user = getCurrentUser();
$projectId = (int)($_GET['project_id'] ?? 0);

// 查用户能看到的项目
$myProjects = getUserProjects($user['id']);
$validProjectIds = array_map(function($p) { return (int)$p['id']; }, $myProjects);
if (empty($validProjectIds)) {
    $validProjectIds = [0];
}

// 如果没传 project_id,默认第一个
if ($projectId === 0 && !empty($myProjects)) {
    $projectId = (int)$myProjects[0]['id'];
}
if (!in_array($projectId, $validProjectIds, true) && !isAdmin()) {
    die('权限不足:您没有该项目的访问权限');
}

$project = queryOneDb("SELECT * FROM projects WHERE id = ?", [$projectId]);
if (!$project) die('项目不存在');

// 查任务
$tasks = queryDb(
    "SELECT t.*, u.username as assignee_name, u.name as assignee_real_name
     FROM tasks t LEFT JOIN users u ON t.assignee_id = u.id
     WHERE t.project_id = ?
     ORDER BY t.id ASC",
    [$projectId]
);

// 没 start_date 的用 created_at,没 due_date 的用 created_at + 7 天
$today = strtotime(date('Y-m-d'));
$minTs = $today;
$maxTs = $today;
foreach ($tasks as &$t) {
    if (empty($t['start_date'])) $t['start_date'] = $t['created_at'];
    $t['_start_ts'] = strtotime($t['start_date']);
    $t['_due_ts']   = $t['due_date'] ? strtotime($t['due_date']) : $t['_start_ts'] + 7 * 86400;
    if ($t['_start_ts'] < $minTs) $minTs = $t['_start_ts'];
    if ($t['_due_ts'] > $maxTs)   $maxTs = $t['_due_ts'];
    $t['_progress'] = (int)$t['progress'];
}
unset($t);

// 头尾各留 2 天
$minTs -= 2 * 86400;
$maxTs += 2 * 86400;
$totalDays = max(1, (int)(($maxTs - $minTs) / 86400) + 1);

// 任务依赖
$deps = queryDb(
    "SELECT td.task_id, td.depends_on_task_id, t1.start_date as from_start, t1.due_date as from_due,
            t2.start_date as to_start, t2.due_date as to_due
     FROM task_dependencies td
     JOIN tasks t1 ON td.depends_on_task_id = t1.id
     JOIN tasks t2 ON td.task_id = t2.id
     WHERE t1.project_id = ? AND t2.project_id = ?",
    [$projectId, $projectId]
);

$unreadNotifCount = getUnreadNotificationCount($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>甘特图 - <?php echo htmlspecialchars($project['name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
    <style>
        .gantt-wrap { overflow-x: auto; background:#fff; border:1px solid #dee2e6; border-radius:4px; }
        .gantt-table { min-width: 100%; border-collapse: collapse; }
        .gantt-table th, .gantt-table td { padding:0; border-bottom:1px solid #f0f0f0; vertical-align: middle; }
        .gantt-name-col { position:sticky; left:0; background:#fff; z-index:2; padding:8px !important; min-width:240px; max-width:280px; border-right:2px solid #dee2e6; }
        .gantt-name-col a { color:#2c3e50; text-decoration:none; font-weight:500; }
        .gantt-name-col a:hover { text-decoration:underline; }
        .gantt-time-col { height:36px; min-width:50px; max-width:60px; text-align:center; font-size:11px; color:#666; background:#f8f9fa; position:relative; }
        .gantt-time-col.is-today { background:#fff3cd; color:#856404; font-weight:bold; }
        .gantt-time-col.is-weekend { background:#f1f3f5; }
        .gantt-row { height:36px; }
        .gantt-row:hover { background:#fafbfc; }
        .gantt-bar { position:absolute; height:22px; top:7px; border-radius:3px; padding:2px 6px; font-size:11px; color:#fff; line-height:18px; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,0.1); overflow:hidden; white-space:nowrap; text-overflow:ellipsis; }
        .gantt-bar.todo        { background:#6c757d; }
        .gantt-bar.in_progress { background:#3498db; }
        .gantt-bar.blocked     { background:#e74c3c; }
        .gantt-bar.done        { background:#28a745; opacity:0.7; }
        .gantt-bar.is-overdue  { box-shadow: 0 0 0 2px #dc3545; }
        .gantt-bar-progress { position:absolute; top:0; left:0; bottom:0; background: rgba(0,0,0,0.18); border-radius:3px 0 0 3px; }
        .gantt-deps { position:absolute; top:0; left:0; pointer-events:none; z-index:1; }
        .gantt-meta { font-size:11px; color:#999; }
        .gantt-month-head { text-align:center; font-weight:bold; background:#e9ecef; padding:4px; border-bottom:1px solid #dee2e6; }
    </style>
</head>
<body>
<?php
echo renderHeader('📊 甘特图 - ' . htmlspecialchars($project['name']), $user, $unreadNotifCount, 'gantt', [
    'project_id' => (int)$projectId,
    'project_name' => $project['name'] ?? '',
    'sub_active' => 'gantt',
]);
?>

<div class="container">
    <div class="card">
        <form method="GET" style="display:flex; gap:10px; align-items:end;">
            <div class="form-group" style="margin:0; min-width:240px;">
                <label>选择项目</label>
                <select name="project_id" onchange="this.form.submit()">
                    <?php foreach ($myProjects as $p): ?>
                        <option value="<?php echo (int)$p['id']; ?>" <?php echo $p['id']==$projectId?'selected':''; ?>><?php echo htmlspecialchars($p['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <a href="project_dashboard.php?project_id=<?php echo (int)$projectId; ?>" class="btn btn-success">📊 项目仪表盘</a>
                <a href="tasks.php?project_id=<?php echo (int)$projectId; ?>" class="btn btn-primary">📋 任务管理</a>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>📊 任务时间线 (<?php echo count($tasks); ?> 个任务 / <?php echo $totalDays; ?> 天)</h3>
        <p style="color:#999; font-size:12px;">
            <span style="display:inline-block; width:10px; height:10px; background:#6c757d; vertical-align:middle;"></span> 待处理
            <span style="display:inline-block; width:10px; height:10px; background:#3498db; vertical-align:middle; margin-left:8px;"></span> 进行中
            <span style="display:inline-block; width:10px; height:10px; background:#e74c3c; vertical-align:middle; margin-left:8px;"></span> 阻塞
            <span style="display:inline-block; width:10px; height:10px; background:#28a745; vertical-align:middle; margin-left:8px;"></span> 已完成
            <span style="display:inline-block; width:10px; height:3px; background:rgba(0,0,0,0.3); vertical-align:middle; margin-left:8px;"></span> 进度
            <span style="display:inline-block; width:10px; height:10px; box-shadow:0 0 0 2px #dc3545; background:#fff; vertical-align:middle; margin-left:8px;"></span> 逾期
        </p>

        <?php if (empty($tasks)): ?>
            <p style="color:#999;">此项目还没有任务,无法显示甘特图。</p>
        <?php else: ?>
        <div class="gantt-wrap">
            <table class="gantt-table">
                <thead>
                    <tr>
                        <th class="gantt-name-col" style="background:#e9ecef;">任务</th>
                        <?php
                        $currentMonth = '';
                        $rowSpan = 0;
                        for ($i = 0; $i < $totalDays; $i++) {
                            $ts = $minTs + $i * 86400;
                            $ym = date('Y-m', $ts);
                            if ($ym !== $currentMonth) {
                                if ($rowSpan > 0) echo '</th>';
                                // 算到下月为止的天数
                                $nextMonthTs = strtotime(date('Y-m-01', $ts) . ' +1 month');
                                $span = (int)(($nextMonthTs - $ts) / 86400);
                                $remain = $totalDays - $i;
                                $span = min($span, $remain);
                                echo '<th class="gantt-month-head" colspan="' . $span . '">' . date('Y-m', $ts) . '</th>';
                                $currentMonth = $ym;
                                $rowSpan = $span;
                            }
                        }
                        ?>
                    </tr>
                    <tr>
                        <th class="gantt-name-col" style="background:#f8f9fa;"></th>
                        <?php for ($i = 0; $i < $totalDays; $i++):
                            $ts = $minTs + $i * 86400;
                            $day = (int)date('j', $ts);
                            $wd  = (int)date('w', $ts);
                            $isToday = (date('Y-m-d', $ts) === date('Y-m-d'));
                            $isWeekend = ($wd === 0 || $wd === 6);
                            $cls = 'gantt-time-col';
                            if ($isToday) $cls .= ' is-today';
                            elseif ($isWeekend) $cls .= ' is-weekend';
                        ?>
                            <th class="<?php echo $cls; ?>"><?php echo $day; ?></th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tasks as $t):
                        $startOffsetDays = (int)(($t['_start_ts'] - $minTs) / 86400);
                        $durationDays    = max(1, (int)(($t['_due_ts'] - $t['_start_ts']) / 86400) + 1);
                        $left  = $startOffsetDays * 52; // 52px/天
                        $width = $durationDays * 52 - 2;
                        $overdue = isTaskOverdue($t);
                    ?>
                        <tr class="gantt-row">
                            <td class="gantt-name-col">
                                <a href="task_detail.php?task_id=<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['title']); ?></a>
                                <div class="gantt-meta"><?php echo htmlspecialchars($t['assignee_real_name'] ?: $t['assignee_name'] ?: '未分配'); ?> · <?php echo (int)$t['progress']; ?>%</div>
                            </td>
                            <?php for ($i = 0; $i < $totalDays; $i++): ?>
                                <td class="gantt-time-col" style="position:relative;">
                                    <?php if ($i === 0): ?>
                                        <a href="task_detail.php?task_id=<?php echo (int)$t['id']; ?>" class="gantt-bar <?php echo htmlspecialchars($t['status']); ?> <?php echo $overdue?'is-overdue':''; ?>" style="left:<?php echo $left; ?>px; width:<?php echo $width; ?>px;">
                                            <div class="gantt-bar-progress" style="width:<?php echo (int)$t['_progress']; ?>%;"></div>
                                            <?php echo htmlspecialchars(mb_substr($t['title'], 0, 30)); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="font-size:11px; color:#999; padding:8px;">
                💡 提示:每行第一个格子里是任务条,长度=起止天数,半透明深色=进度,红框=逾期。点击任务名/条进入详情。
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php echo renderFooter(); ?>
</body>
</html>
