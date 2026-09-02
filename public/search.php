<?php
/**
 * 全局搜索
 * Global Search
 *
 * 搜任务(标题/描述/编号) + 搜评论
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';

requireLogin();

$user = getCurrentUser();
$q = trim($_GET['q'] ?? '');

$taskResults = [];
$commentResults = [];
if ($q !== '') {
    $taskResults = globalSearchTasks($q, $user['id'], 50);

    // 搜评论(只搜用户能看到的任务的评论)
    $kw = '%' . $q . '%';
    $commentResults = queryDb(
        "SELECT tc.*, t.title as task_title, t.id as task_id, p.name as project_name,
                u.username, u.name as user_real_name
         FROM task_comments tc
         JOIN tasks t ON tc.task_id = t.id
         JOIN projects p ON t.project_id = p.id
         LEFT JOIN users u ON tc.user_id = u.id
         WHERE tc.content LIKE ?
           AND (p.manager_id = ?
                OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = ? AND pm.status = 'active')
                OR t.assignee_id = ?)
         ORDER BY tc.created_at DESC
         LIMIT 30",
        [$kw, $user['id'], $user['id'], $user['id']]
    );

    logOperation($user['id'], 'search', 'system', null, ['q' => $q, 'task_hits' => count($taskResults), 'comment_hits' => count($commentResults)]);
}

$unreadNotifCount = getUnreadNotificationCount($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>搜索: <?php echo htmlspecialchars($q); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
</head>
<body>
<?php echo renderHeader('🔍 搜索', $user, $unreadNotifCount, null, [], false); ?>
<div class="container">
    <form method="GET" action="search.php" class="top-search">
        <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="🔍 搜索任务标题/描述/编号/评论" required autofocus>
        <button type="submit" class="btn btn-primary">搜索</button>
    </form>
</div>

<div class="container">
    <?php if ($q === ''): ?>
        <div class="card"><p style="color:#999;">输入关键词开始搜索。支持:<br>
        - 任务标题、描述、任务编号(如输入 <code>12</code> 搜任务 #12)<br>
        - 评论内容<br>
        - 仅搜索您能看到权限的任务/评论</p></div>
    <?php else: ?>
        <div class="card">
            <h3>📋 任务结果 (<?php echo count($taskResults); ?>)</h3>
            <?php if (empty($taskResults)): ?>
                <p style="color:#999;">没有匹配的任务。</p>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>编号</th><th>标题</th><th>项目</th><th>负责人</th><th>状态</th><th>进度</th><th>截止</th></tr></thead>
                    <tbody>
                    <?php foreach ($taskResults as $t): $od = isTaskOverdue($t); ?>
                        <tr class="<?php echo $od ? 'row-overdue' : ''; ?>">
                            <td>#<?php echo (int)$t['id']; ?></td>
                            <td><a href="task_detail.php?task_id=<?php echo (int)$t['id']; ?>" class="task-link"><?php echo htmlspecialchars($t['title']); ?></a>
                                <?php if ($t['description']): ?>
                                    <div style="font-size:11px; color:#999; margin-top:3px;"><?php echo htmlspecialchars(mb_substr($t['description'], 0, 80)); ?>...</div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($t['project_name']); ?></td>
                            <td><?php echo htmlspecialchars($t['assignee_name'] ?: '-'); ?></td>
                            <td><span class="status-badge status-<?php echo htmlspecialchars($t['status']); ?>"><?php echo getTaskStatusText($t['status']); ?></span></td>
                            <td><?php echo (int)$t['progress']; ?>%</td>
                            <td><?php echo $t['due_date'] ? formatDate($t['due_date']) : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>💬 评论结果 (<?php echo count($commentResults); ?>)</h3>
            <?php if (empty($commentResults)): ?>
                <p style="color:#999;">没有匹配的评论。</p>
            <?php else: ?>
                <ul class="dep-list">
                    <?php foreach ($commentResults as $c): ?>
                        <li>
                            <div>
                                <a href="task_detail.php?task_id=<?php echo (int)$c['task_id']; ?>#comment-<?php echo (int)$c['id']; ?>" class="task-link">
                                    [#<?php echo (int)$c['task_id']; ?>] <?php echo htmlspecialchars($c['task_title']); ?>
                                </a>
                                <small style="color:#999;"> — <?php echo htmlspecialchars($c['project_name']); ?></small>
                            </div>
                            <div style="font-size:12px; color:#666; margin-top:3px;">
                                <strong><?php echo htmlspecialchars($c['user_real_name'] ?: $c['username']); ?></strong>
                                <span style="color:#999;">@ <?php echo htmlspecialchars($c['created_at']); ?></span>:
                            </div>
                            <div style="background:#f8f9fa; padding:6px; border-radius:3px; margin-top:3px;">
                                <?php echo nl2br(htmlspecialchars($c['content'])); ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php echo renderFooter(); ?>
</body>
</html>
