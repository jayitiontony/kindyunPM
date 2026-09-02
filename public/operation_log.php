<?php
/**
 * 操作日志查询页
 * Operation Log Query Page
 *
 * 入口 (URL 参数):
 *   1) 按任务查: ?target_type=task&target_id=123
 *   2) 按人查:   ?user_id=5
 *   3) 按项目查: ?target_type=project&target_id=2
 *   4) 全系统查 (仅管理员): 直接访问
 *
 * 过滤: action / target_type / keyword
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';

requireLogin();

$user = getCurrentUser();

// 三个互斥入口
$targetType = $_GET['target_type'] ?? null;
$targetId   = isset($_GET['target_id']) ? (int)$_GET['target_id'] : 0;
$userId     = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

$filters = [
    'action'      => $_GET['action'] ?? '',
    'target_type' => $_GET['target_type'] ?? '',
    'keyword'     => $_GET['keyword'] ?? '',
];

// 权限
$pageTitle = '操作日志';
$scopeDesc = '';
$targetObj = null;

if ($targetType === 'task' && $targetId > 0) {
    $targetObj = queryOneDb("SELECT t.*, p.name as project_name, p.manager_id as project_manager_id FROM tasks t JOIN projects p ON t.project_id = p.id WHERE t.id = ?", [$targetId]);
    if (!$targetObj) die('任务不存在');
    $isPM = ($targetObj['project_manager_id'] == $user['id']) || isAdmin();
    $isMember = isProjectMember($targetObj['project_id'], $user['id']);
    $isAssignee = ($targetObj['assignee_id'] == $user['id']);
    if (!$isPM && !$isMember && !$isAssignee) {
        die('权限不足:无权查看此任务的日志');
    }
    $pageTitle = '任务操作日志';
    $scopeDesc = '任务 [#' . $targetId . '] ' . htmlspecialchars($targetObj['title']);
    $logs = getOperationsByTarget('task', $targetId, 500);
} elseif ($targetType === 'project' && $targetId > 0) {
    $targetObj = queryOneDb("SELECT * FROM projects WHERE id = ?", [$targetId]);
    if (!$targetObj) die('项目不存在');
    $isPM = ($targetObj['manager_id'] == $user['id']) || isAdmin();
    $isMember = isProjectMember($targetId, $user['id']);
    if (!$isPM && !$isMember) {
        die('权限不足:无权查看此项目的日志');
    }
    $pageTitle = '项目操作日志';
    $scopeDesc = '项目 [' . htmlspecialchars($targetObj['name']) . ']';
    $logs = getOperationsByTarget('project', $targetId, 500);
} elseif ($userId > 0) {
    // 按人查: 只能查自己,管理员可查所有人
    if ($userId != $user['id'] && !isAdmin()) {
        die('权限不足:只能查看自己的操作记录');
    }
    $targetObj = getUserById($userId);
    if (!$targetObj) die('用户不存在');
    $pageTitle = '用户的操作记录';
    $scopeDesc = '用户 ' . htmlspecialchars($targetObj['name'] ?: $targetObj['username']) . ' (' . htmlspecialchars($targetObj['username']) . ')';
    $logs = getOperationsByUser($userId, 500, $filters['action'] ?: null);
} else {
    // 全部日志,仅管理员
    if (!isAdmin()) {
        die('权限不足:只有管理员可以查看全系统操作日志');
    }
    $pageTitle = '全系统操作日志';
    $scopeDesc = '全部';
    $logs = getAllOperations(500, $filters);
}

$allActions = getOperationActionList();
$allUsers   = isAdmin() ? getAllUsers() : [];
$unreadNotifCount = getUnreadNotificationCount($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
</head>
<body>
<?php
echo renderHeader('📋 ' . htmlspecialchars($pageTitle), $user, $unreadNotifCount, 'system_log', [], false);
?>

<div class="container">
    <div class="card">
        <h3>📋 <?php echo htmlspecialchars($scopeDesc); ?> <small style="font-size:13px; color:#999;">共 <?php echo count($logs); ?> 条</small></h3>

        <form method="GET" style="margin-bottom:15px;">
            <?php if ($targetType && $targetId): ?>
                <input type="hidden" name="target_type" value="<?php echo htmlspecialchars($targetType); ?>">
                <input type="hidden" name="target_id" value="<?php echo (int)$targetId; ?>">
            <?php endif; ?>
            <?php if ($userId): ?>
                <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
            <?php endif; ?>

            <?php if (isAdmin()): ?>
            <div class="form-row" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
                <div class="form-group" style="margin:0; min-width:160px;">
                    <label>按用户</label>
                    <select name="user_id">
                        <option value="0">-- 全部用户 --</option>
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>" <?php echo $userId===(int)$u['id']?'selected':''; ?>><?php echo htmlspecialchars($u['name'] ?: $u['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0; min-width:160px;">
                    <label>操作类型</label>
                    <select name="action">
                        <option value="">-- 全部 --</option>
                        <?php foreach ($allActions as $k => $v): ?>
                            <option value="<?php echo $k; ?>" <?php echo $filters['action']===$k?'selected':''; ?>><?php echo $v; ?> (<?php echo $k; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0; min-width:160px;">
                    <label>对象类型</label>
                    <select name="target_type">
                        <option value="">-- 全部 --</option>
                        <option value="task"    <?php echo $filters['target_type']==='task'?'selected':''; ?>>任务</option>
                        <option value="project" <?php echo $filters['target_type']==='project'?'selected':''; ?>>项目</option>
                        <option value="user"    <?php echo $filters['target_type']==='user'?'selected':''; ?>>用户</option>
                        <option value="role"    <?php echo $filters['target_type']==='role'?'selected':''; ?>>角色</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0; flex:1; min-width:200px;">
                    <label>关键词</label>
                    <input type="text" name="keyword" value="<?php echo htmlspecialchars($filters['keyword']); ?>" placeholder="搜 details 或用户名">
                </div>
                <div>
                    <button type="submit" class="btn btn-primary">筛选</button>
                </div>
            </div>
            <?php endif; ?>
        </form>

        <?php if (empty($logs)): ?>
            <p style="color:#999;">暂无日志记录。</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>时间</th>
                        <th>操作人</th>
                        <th>操作</th>
                        <th>对象</th>
                        <th>详情</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                        <td>
                            <?php if ($log['user_id']): ?>
                                <a href="operation_log.php?user_id=<?php echo (int)$log['user_id']; ?>"><?php echo htmlspecialchars($log['user_real_name'] ?: $log['username']); ?></a>
                            <?php else: ?>
                                <span style="color:#999;">(系统)</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="action-tag action-<?php echo htmlspecialchars($log['action']); ?>"><?php echo htmlspecialchars(($allActions[$log['action']] ?? $log['action'])); ?></span></td>
                        <td>
                            <?php echo htmlspecialchars($log['target_type']); ?>
                            <?php if ($log['target_id']): ?>
                                <a href="operation_log.php?target_type=<?php echo urlencode($log['target_type']); ?>&amp;target_id=<?php echo (int)$log['target_id']; ?>">#<?php echo (int)$log['target_id']; ?></a>
                            <?php endif; ?>
                        </td>
                        <td><pre class="log-details"><?php echo htmlspecialchars($log['details'] ?: '-'); ?></pre></td>
                        <td><?php echo htmlspecialchars($log['ip_address'] ?: '-'); ?></td>
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
