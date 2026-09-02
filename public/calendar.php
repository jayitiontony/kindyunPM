<?php
/**
 * 截止日历
 * Deadline Calendar
 *
 * 月历视图,显示当前用户能看到的任务,按 due_date 排进日历格子
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';

requireLogin();

$user = getCurrentUser();

// 月份参数
$monthStr = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $monthStr)) $monthStr = date('Y-m');
list($year, $month) = explode('-', $monthStr);
$year = (int)$year; $month = (int)$month;

// 上下月链接
$prevMonth = date('Y-m', strtotime($year . '-' . $month . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($year . '-' . $month . '-01 +1 month'));

// 月份起止
$firstDay = date('Y-m-01', strtotime($year . '-' . $month . '-01'));
$lastDay  = date('Y-m-t', strtotime($year . '-' . $month . '-01'));
$firstWeekday = (int)date('w', strtotime($firstDay)); // 0=Sun
$daysInMonth = (int)date('t', strtotime($firstDay));

// 查当月所有任务
$projectsFilter = '';
$params = [$firstDay, $lastDay];
$projectsList = getUserProjects($user['id']);
$projectIds = array_map(function($p) { return (int)$p['id']; }, $projectsList);
if (empty($projectIds)) $projectIds = [0]; // 防止 SQL 错
$projectIn = implode(',', array_fill(0, count($projectIds), '?'));

$tasks = queryDb(
    "SELECT t.*, p.name as project_name, u.username as assignee_name
     FROM tasks t
     JOIN projects p ON t.project_id = p.id
     LEFT JOIN users u ON t.assignee_id = u.id
     WHERE t.due_date BETWEEN ? AND ?
       AND t.project_id IN ($projectIn)
     ORDER BY t.due_date ASC, t.priority ASC",
    array_merge($params, $projectIds)
);

// 按天分桶
$tasksByDay = [];
foreach ($tasks as $t) {
    $day = substr($t['due_date'], 8, 2);
    if (!isset($tasksByDay[$day])) $tasksByDay[$day] = [];
    $tasksByDay[$day][] = $t;
}

$unreadNotifCount = getUnreadNotificationCount($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>截止日历 - PM 系统</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
    <style>
        .cal-nav { display:flex; align-items:center; gap:10px; justify-content:center; margin-bottom:10px; }
        .cal-nav h3 { margin:0; }
        .cal-grid { display:grid; grid-template-columns: repeat(7, 1fr); gap:1px; background:#dee2e6; border:1px solid #dee2e6; }
        .cal-cell-head { background:#f8f9fa; padding:8px; text-align:center; font-weight:bold; }
        .cal-cell { background:#fff; min-height:110px; padding:5px; vertical-align:top; }
        .cal-day-num { font-weight:bold; font-size:12px; color:#495057; }
        .cal-day-num.is-today { color:#fff; background:#dc3545; border-radius:50%; padding:2px 6px; display:inline-block; }
        .cal-day-num.is-sunday { color:#dc3545; }
        .cal-day-num.is-saturday { color:#007bff; }
        .cal-task { background:#f8f9fa; padding:2px 5px; margin-top:3px; border-radius:3px; font-size:11px; border-left:3px solid #3498db; cursor:pointer; }
        .cal-task.status-todo        { border-left-color:#6c757d; }
        .cal-task.status-in_progress { border-left-color:#3498db; }
        .cal-task.status-blocked     { border-left-color:#e74c3c; }
        .cal-task.status-done        { border-left-color:#28a745; opacity:0.6; text-decoration:line-through; }
        .cal-task.is-overdue         { background:#fdeaea; }
        .cal-other-month { background:#f8f9fa; opacity:0.4; }
    </style>
</head>
<body>
<?php echo renderHeader('📅 截止日历', $user, $unreadNotifCount, 'calendar'); ?>

<div class="container">
    <div class="card">
        <div class="cal-nav">
            <a href="?month=<?php echo $prevMonth; ?>" class="btn btn-primary">‹ 上月</a>
            <h3><?php echo $year; ?> 年 <?php echo $month; ?> 月</h3>
            <a href="?month=<?php echo $nextMonth; ?>" class="btn btn-primary">下月 ›</a>
            <a href="?month=<?php echo date('Y-m'); ?>" class="btn btn-success">今天</a>
        </div>

        <div class="cal-grid">
            <?php
            $weekHead = ['日', '一', '二', '三', '四', '五', '六'];
            foreach ($weekHead as $w): ?>
                <div class="cal-cell-head"><?php echo $w; ?></div>
            <?php endforeach; ?>

            <?php
            // 上月补位
            $prevDays = (int)date('t', strtotime($prevMonth . '-01'));
            for ($i = $firstWeekday - 1; $i >= 0; $i--) {
                $d = $prevDays - $i;
                echo '<div class="cal-cell cal-other-month"><div class="cal-day-num">' . $d . '</div></div>';
            }
            // 本月
            $todayDay = (int)date('j');
            $isThisMonth = ($year == (int)date('Y') && $month == (int)date('n'));
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $weekday = (int)date('w', strtotime($year . '-' . $month . '-' . $d));
                $dayKey = str_pad((string)$d, 2, '0', STR_PAD_LEFT);
                $dayTasks = $tasksByDay[$dayKey] ?? [];
                $isToday = $isThisMonth && $d == $todayDay;
                $numClass = 'cal-day-num';
                if ($isToday) $numClass .= ' is-today';
                if ($weekday === 0) $numClass .= ' is-sunday';
                if ($weekday === 6) $numClass .= ' is-saturday';
                ?>
                <div class="cal-cell">
                    <div class="<?php echo $numClass; ?>"><?php echo $d; ?></div>
                    <?php foreach (array_slice($dayTasks, 0, 4) as $t):
                        $od = isTaskOverdue($t);
                    ?>
                        <a href="task_detail.php?task_id=<?php echo (int)$t['id']; ?>" class="cal-task status-<?php echo htmlspecialchars($t['status']); ?> <?php echo $od ? 'is-overdue' : ''; ?>" title="<?php echo htmlspecialchars($t['title'] . ' (' . $t['project_name'] . ' / ' . ($t['assignee_name'] ?: '未分配') . ')'); ?>" style="display:block; text-decoration:none; color:#333;">
                            <?php echo htmlspecialchars(mb_substr($t['title'], 0, 16)); ?><?php echo mb_strlen($t['title']) > 16 ? '…' : ''; ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if (count($dayTasks) > 4): ?>
                        <div style="font-size:10px; color:#999; margin-top:2px;">还有 <?php echo count($dayTasks) - 4; ?> 个...</div>
                    <?php endif; ?>
                </div>
            <?php }
            // 下月补位
            $remain = (7 - (($firstWeekday + $daysInMonth) % 7)) % 7;
            for ($i = 1; $i <= $remain; $i++) {
                echo '<div class="cal-cell cal-other-month"><div class="cal-day-num">' . $i . '</div></div>';
            }
            ?>
        </div>

        <div style="margin-top:10px; font-size:12px; color:#666;">
            <span style="display:inline-block; width:12px; height:12px; background:#fdeaea; border:1px solid #f5c6cb; vertical-align:middle;"></span> 逾期任务背景标红 |
            共 <?php echo count($tasks); ?> 个任务在本月到期
        </div>
    </div>
</div>

<?php echo renderFooter(); ?>
</body>
</html>
