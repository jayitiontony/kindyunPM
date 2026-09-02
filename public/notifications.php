<?php
/**
 * 通知中心
 * Notifications Center
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';

requireLogin();

$user = getCurrentUser();
$error = '';
$success = '';

// 处理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'mark_read') {
            $nid = (int)($_POST['notif_id'] ?? 0);
            markNotificationRead($nid, $user['id']);
            $success = '已标记为已读';
        }
        elseif ($action === 'mark_all_read') {
            markAllNotificationsRead($user['id']);
            $success = '全部通知已标记为已读';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 过滤: unread / all
$filter = $_GET['filter'] ?? 'all';
$onlyUnread = ($filter === 'unread');
$notifs = getUserNotifications($user['id'], $onlyUnread, 200);
$unreadCount = getUnreadNotificationCount($user['id']);

$unreadNotifCount = $unreadCount;

// 通知类型映射
$typeLabels = [
    'task_status'  => ['icon' => '🔄', 'label' => '状态变更', 'color' => '#6f42c1'],
    'task_comment' => ['icon' => '💬', 'label' => '新评论',   'color' => '#17a2b8'],
    'request_assist'=>['icon' => '🆘', 'label' => '协助请求', 'color' => '#ffc107'],
    'task_assign'  => ['icon' => '📤', 'label' => '任务指派', 'color' => '#fd7e14'],
    'task_reassign'=> ['icon' => '🔁', 'label' => '重新指派', 'color' => '#e83e8c'],
    'project_add'  => ['icon' => '➕', 'label' => '加入项目', 'color' => '#007bff'],
    'block'        => ['icon' => '🚫', 'label' => '任务阻塞', 'color' => '#dc3545'],
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>通知中心 - PM 系统</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=5">
</head>
<body>
<?php echo renderHeader('🔔 通知中心', $user, $unreadNotifCount, 'notifications'); ?>

<div class="container">
    <?php if (!empty($error)) echo showError($error); ?>
    <?php if (!empty($success)) echo showSuccess($success); ?>

    <div class="card">
        <div style="display:flex; align-items:center; gap:15px;">
            <h3 style="flex:1; margin:0;">📬 我的通知 (未读 <strong style="color:#dc3545;"><?php echo $unreadCount; ?></strong>)</h3>
            <a href="?filter=all" class="btn <?php echo $filter==='all'?'':'btn-primary'; ?>" style="<?php echo $filter==='all'?'background:#6c757d;':''; ?>">全部</a>
            <a href="?filter=unread" class="btn <?php echo $filter==='unread'?'':'btn-primary'; ?>" style="<?php echo $filter==='unread'?'background:#dc3545;':''; ?>">未读</a>
            <form method="POST" style="display:inline;" onsubmit="return confirm('全部标记为已读?')">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn btn-success">✓ 全部已读</button>
            </form>
        </div>
    </div>

    <div class="card">
        <?php if (empty($notifs)): ?>
            <p style="color:#999; padding:30px; text-align:center;"><?php echo $onlyUnread ? '没有未读通知 ✨' : '还没有任何通知'; ?></p>
        <?php else: foreach ($notifs as $n):
            $type = $typeLabels[$n['type']] ?? ['icon' => '🔔', 'label' => $n['type'], 'color' => '#6c757d'];
            $link = '';
            if ($n['target_type'] === 'task' && $n['target_id']) {
                $link = 'task_detail.php?task_id=' . (int)$n['target_id'];
            } elseif ($n['target_type'] === 'project' && $n['target_id']) {
                $link = 'project_dashboard.php?project_id=' . (int)$n['target_id'];
            }
        ?>
            <div class="notif-item <?php echo $n['is_read'] ? 'is-read' : 'is-unread'; ?>">
                <div class="notif-icon" style="background:<?php echo $type['color']; ?>;"><?php echo $type['icon']; ?></div>
                <div class="notif-body">
                    <div class="notif-title">
                        <span class="notif-type"><?php echo $type['label']; ?></span>
                        <?php echo htmlspecialchars($n['title']); ?>
                    </div>
                    <div class="notif-text"><?php echo nl2br(htmlspecialchars($n['body'] ?: '')); ?></div>
                    <div class="notif-meta">
                        <span class="notif-time"><?php echo htmlspecialchars($n['created_at']); ?></span>
                        <?php if ($link): ?>
                            <a href="<?php echo $link; ?>" class="notif-link">查看 →</a>
                        <?php endif; ?>
                        <?php if (!$n['is_read']): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="notif_id" value="<?php echo (int)$n['id']; ?>">
                                <button type="submit" class="notif-mark">标为已读</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<?php echo renderFooter(); ?>
</body>
</html>
